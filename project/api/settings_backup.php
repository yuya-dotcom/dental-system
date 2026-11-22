<?php
// =============================================================
//  api/settings_backup.php — Settings › Database & Backup (OWNER ONLY)
//
//  POST JSON
//    { "kind": "schema" }                                  structure only
//    { "kind": "full", "password": "...",                  structure + all rows
//      "protect": true, "zip_password": "..." }            (optional AES-256 ZIP)
//
//  Answers with the FILE (attachment) on success, or JSON
//  { success:false, message } on any failure — never a partial file.
//
//  Guards, in order:
//    • signed-in staff session with role owner (requireStaffApi)
//    • JSON body only (a cross-site form can't send one), POST only
//    • full backup: the owner re-enters THEIR password every time
//      (bcrypt check; shared wrong-password limit); the account must
//      still be an ACTIVE owner in the database, not just in the session
//    • one export per SET_EXPORT_COOLDOWN seconds per session
//  The export runs on Supabase with the server key (controllers/
//  settings_helper.php); the key and the database are never exposed.
//  The file is checked (header + "export complete" footer) before it is
//  sent, and every download is written to the audit trail with its
//  size, table / row counts and SHA-256 fingerprint.
//  Temporary files are deleted as soon as the response ends.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/audit_controller.php';
require_once __DIR__ . '/../controllers/settings_helper.php';

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
requireLogin();
requireStaffApi('owner');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiDeny(405, 'Method not allowed.');
if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) apiDeny(415, 'Unsupported request.');

$body    = json_decode(file_get_contents('php://input'), true);
$body    = is_array($body) ? $body : [];
$kind    = ($body['kind'] ?? '') === 'full' ? 'full' : (($body['kind'] ?? '') === 'schema' ? 'schema' : '');
if ($kind === '') apiDeny(400, 'Choose what to download.');
$full    = $kind === 'full';
$protect = $full && !empty($body['protect']);
$zipPw   = is_string($body['zip_password'] ?? null) ? $body['zip_password'] : '';

function setFail(int $status, string $message, array $extra = []): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'message' => $message] + $extra);
    exit;
}

// ── Too soon after the last export? ─────────────────────────
$since = time() - (int)($_SESSION['set_last_export'] ?? 0);
if ($since < SET_EXPORT_COOLDOWN) {
    setFail(429, 'Please wait ' . (SET_EXPORT_COOLDOWN - $since) . ' seconds before starting another download.');
}

// ── Re-check the account in the database ────────────────────
if ($full) {
    $fail = setCheckOwnPassword(is_string($body['password'] ?? null) ? $body['password'] : '', ['owner']);
    if ($fail) {
        if (($fail['code'] ?? '') === 'WRONG_PASSWORD' || ($fail['code'] ?? '') === 'LOCKED') {
            logActivity(SET_BACKUP_MODULE, SET_ACTION_DENIED, '', 'Full database backup',
                'Full backup refused: ' . (($fail['code'] ?? '') === 'LOCKED' ? 'too many incorrect passwords' : 'incorrect password'),
                null, null, null, null, ['ip' => auditClientIp()]);
        }
        setFail(($fail['code'] ?? '') === 'LOCKED' ? 429 : 200, $fail['message'], ['code' => $fail['code'] ?? 'DENIED', 'field' => 'password']);
    }
    if ($protect) {
        if (!setZipAesAvailable()) setFail(200, 'Password-protected ZIP files are not available on this server. Download the plain .sql file instead.', ['field' => 'protect']);
        if (strlen($zipPw) < SET_ZIP_MIN_PASSWORD) setFail(200, 'Use at least ' . SET_ZIP_MIN_PASSWORD . ' characters for the file password.', ['field' => 'zip_password']);
        if (strlen($zipPw) > 128) setFail(200, 'The file password is too long (128 characters at most).', ['field' => 'zip_password']);
    }
} else {
    $me = supabase_request('users', 'GET', [], 'user_id=eq.' . (int)$_SESSION['user_id'] . '&select=role,status&limit=1');
    $row = (!$me['error'] && is_array($me['data'])) ? ($me['data'][0] ?? null) : null;
    if ($me['error']) setFail(503, "We couldn't check your account right now. Please try again.");
    if (!$row || ($row['status'] ?? '') !== 'active' || ($row['role'] ?? '') !== 'owner') setFail(403, 'Only an active Owner account can download the database.');
}

$_SESSION['set_last_export'] = time();
session_write_close();          // the export can take a while: don't lock the owner's other tabs
@set_time_limit(SET_EXPORT_TIMEOUT + 30);
ignore_user_abort(true);        // finish and clean up even if the browser goes away

// ── Run the export into a private temp file ─────────────────
$tmpSql = tempnam(sys_get_temp_dir(), 'esb');
$tmpZip = null;
register_shutdown_function(function () use (&$tmpSql, &$tmpZip) {
    foreach ([$tmpSql, $tmpZip] as $f) if ($f && is_file($f)) @unlink($f);
});
if (!$tmpSql) setFail(500, 'The server could not create a temporary file.');

$label   = $full ? 'Full database backup' : 'Database schema';
$started = microtime(true);
$err     = setExportToFile($full, $tmpSql);
$check   = $err ? ['ok' => false, 'error' => $err] : setVerifyExport($tmpSql, $full);
if (!$check['ok']) {
    error_log('[settings backup] ' . $kind . ' failed: ' . $check['error']);
    logActivity(SET_BACKUP_MODULE, $full ? SET_ACTION_BACKUP . '_FAILED' : SET_ACTION_SCHEMA . '_FAILED', '', $label,
        $label . ' failed: ' . $check['error']);
    setFail(502, $check['error']);
}

$stamp    = date('Ymd-His');
$sqlName  = ($full ? 'essenciasmile-backup-' : 'essenciasmile-schema-') . $stamp . '.sql';
$sendPath = $tmpSql;
$sendName = $sqlName;
$sendType = 'application/sql; charset=utf-8';
$format   = 'SQL (.sql)';

if ($protect) {
    $tmpZip = tempnam(sys_get_temp_dir(), 'esz');
    $zerr   = $tmpZip ? setZipEncrypt($tmpSql, $sqlName, $tmpZip, $zipPw) : 'The server could not create a temporary file.';
    if ($zerr) {
        logActivity(SET_BACKUP_MODULE, SET_ACTION_BACKUP . '_FAILED', '', $label, $label . ' failed: ' . $zerr);
        setFail(500, $zerr . ' Nothing was downloaded.');
    }
    $sendPath = $tmpZip;
    $sendName = substr($sqlName, 0, -4) . '.zip';
    $sendType = 'application/zip';
    $format   = 'Encrypted ZIP (AES-256) containing .sql';
}
unset($zipPw, $body);

$seconds = round(microtime(true) - $started, 1);
$meta = [
    'kind'      => $kind,
    'file'      => $sendName,
    'format'    => $format,
    'encrypted' => $protect,
    'tables'    => $check['tables'],
    'rows'      => $check['rows'],
    'bytes'     => $check['bytes'],          // size of the .sql inside
    'sha256'    => $check['sha256'],         // fingerprint of the .sql inside
    'seconds'   => $seconds,
    'ip'        => auditClientIp(),
];
logActivity(SET_BACKUP_MODULE, $full ? SET_ACTION_BACKUP : SET_ACTION_SCHEMA, '', $label,
    sprintf('%s downloaded: %d tables%s, %s, %s', $label, $check['tables'],
        $full ? ', ' . number_format((int)$check['rows']) . ' rows' : '', setBytes($check['bytes']), $protect ? 'encrypted ZIP' : 'plain .sql'),
    null, null, null, null, $meta);

// ── Send the file ───────────────────────────────────────────
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . $sendType);
header('Content-Disposition: attachment; filename="' . $sendName . '"');
header('Content-Length: ' . filesize($sendPath));
header('X-Backup-Tables: ' . $check['tables']);
if ($full) header('X-Backup-Rows: ' . (int)$check['rows']);
header('X-Backup-Sha256: ' . $check['sha256']);
readfile($sendPath);
