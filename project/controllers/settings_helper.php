<?php
// =============================================================
//  controllers/settings_helper.php — Settings page (settings.php)
//
//  Shared by settings.php, api/settings_api.php and
//  api/settings_backup.php. Zero HTML.
//
//  DATABASE & BACKUP — how it works
//    The live host reaches Supabase over HTTPS only (no pg_dump, no
//    shell, no Postgres port), and the REST API can't read table
//    definitions. So the SQL script is written INSIDE Postgres by
//    public.es_admin_export_sql() (database/proposed/
//    2026-11-22_admin_database_backup.sql) in ONE read-only snapshot,
//    and PHP only downloads it with the server key. The key never
//    leaves the server; the browser only ever gets the finished file.
//
//    Every file is checked before it is handed out: it must start with
//    the export header and end with the "export complete" line, so a
//    cut-off download is reported as a failure, never saved as a backup.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';

/** Shown under Settings › System Information. Bump with each release. */
const ES_APP_VERSION = '2026.11.22';

const SET_BACKUP_SQL_FILE  = 'database/proposed/2026-11-22_admin_database_backup.sql';
const SET_BACKUP_MODULE    = 'settings';
const SET_ACTION_BACKUP    = 'BACKUP';          // audit_logs.action for a full backup download
const SET_ACTION_SCHEMA    = 'EXPORT_SCHEMA';   // … for a schema download
const SET_ACTION_DENIED    = 'BACKUP_DENIED';   // wrong password / refused
const SET_BACKUP_DUE_DAYS  = 7;                 // "Backup due" after a week without one
const SET_EXPORT_COOLDOWN  = 15;                // seconds between two exports in one session
const SET_EXPORT_TIMEOUT   = 170;               // seconds the export request may take
const SET_ZIP_MIN_PASSWORD = 8;
const SET_END_MARKER       = '-- EssenciaSmile export complete:';

// ─────────────────────────────────────────────────────────────
//  Supabase calls
// ─────────────────────────────────────────────────────────────

/**
 * Database summary from es_admin_backup_info(), plus round-trip time.
 * ['ok' => bool, 'installed' => bool, 'ms' => int, 'info' => array|null, 'error' => ?string]
 * installed = false when the SQL file has not been run yet (PostgREST: function not found).
 */
function setBackupInfo(): array
{
    $t0 = microtime(true);
    $r  = supabase_request('rpc/es_admin_backup_info', 'GET');
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $status = (int)($r['status'] ?? 0);
    if ($status === 200 && is_array($r['data'])) {
        return ['ok' => true, 'installed' => true, 'ms' => $ms, 'info' => $r['data'], 'error' => null];
    }
    $code = is_array($r['data']) ? (string)($r['data']['code'] ?? '') : '';
    if ($status === 404 || $code === 'PGRST202') {
        // The database answered — the backup function just isn't installed yet
        return ['ok' => true, 'installed' => false, 'ms' => $ms, 'info' => null, 'error' => null];
    }
    return ['ok' => false, 'installed' => false, 'ms' => $ms, 'info' => null,
            'error' => $status === 0 ? 'The database could not be reached.' : 'The database returned an error (' . $status . ').'];
}

/**
 * Runs es_admin_export_sql() and streams the script straight into $path
 * (constant memory, whatever the size). Returns null on success or an
 * error message that is safe to show (no keys, no internal detail).
 */
function setExportToFile(bool $withData, string $path): ?string
{
    $fh = fopen($path, 'wb');
    if (!$fh) return 'The server could not create a temporary file.';

    $url = SUPABASE_URL . '/rest/v1/rpc/es_admin_export_sql?p_include_data=' . ($withData ? 'true' : 'false');
    $headers = [];
    $run = function () use ($url, $fh, &$headers) {
        $headers = [];
        ftruncate($fh, 0); rewind($fh);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_FILE           => $fh,     // body goes to disk, not memory
            CURLOPT_TIMEOUT        => SET_EXPORT_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_ENCODING       => '',       // accept gzip from the gateway
            CURLOPT_HTTPHEADER     => [
                'apikey: ' . SUPABASE_KEY,      // server key: this request is made by PHP only
                'Accept: text/plain',           // the function returns text → raw SQL, not a JSON string
            ],
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$headers) {
                $p = explode(':', $h, 2);
                if (count($p) === 2) $headers[strtolower(trim($p[0]))] = trim($p[1]);
                return strlen($h);
            },
        ] + supabase_tls_options());
        curl_exec($ch);
        $res = [curl_errno($ch), (int)curl_getinfo($ch, CURLINFO_HTTP_CODE)];
        curl_close($ch);
        return $res;
    };

    [$errno, $status] = $run();
    if ($errno && supabase_tls_fallback($errno)) [$errno, $status] = $run();
    fflush($fh);
    fclose($fh);

    if ($errno === 28) return 'The database took too long to answer. Please try again in a few minutes.';
    if ($errno)        return 'The database could not be reached. Please check the connection and try again.';

    if ($status !== 200) {
        $body = (string)@file_get_contents($path, false, null, 0, 4000);
        $j    = json_decode($body, true);
        $code = is_array($j) ? (string)($j['code'] ?? '') : '';
        error_log('[settings backup] export failed: HTTP ' . $status . ' ' . substr($body, 0, 500));
        if ($status === 404 || $code === 'PGRST202') {
            // Plain words for the owner; the file name for IT support is on the page (Details for IT support)
            return 'Backups are not set up yet. Ask your IT support to finish the one-time setup, then try again.';
        }
        if (is_array($j) && str_starts_with((string)($j['message'] ?? ''), 'Export stopped:')) {
            return (string)$j['message'];   // our own explicit refusal (unsupported objects)
        }
        return 'The database could not create the export (error ' . $status . '). Nothing was downloaded.';
    }

    // Older gateways may wrap the text in a JSON string: unwrap it once
    $ctype = strtolower($headers['content-type'] ?? '');
    if (str_contains($ctype, 'json')) {
        $s = json_decode((string)file_get_contents($path), true);
        if (!is_string($s)) return 'The database returned an unexpected answer. Nothing was downloaded.';
        file_put_contents($path, $s);
    }
    return null;
}

/**
 * Integrity check of a finished export file. Returns
 * ['ok' => true, 'tables' => int, 'rows' => ?int, 'bytes' => int, 'sha256' => string]
 * or ['ok' => false, 'error' => string].
 */
function setVerifyExport(string $path, bool $withData): array
{
    $bytes = (int)@filesize($path);
    if ($bytes < 200) return ['ok' => false, 'error' => 'The export came back empty. Nothing was downloaded.'];

    $head = (string)file_get_contents($path, false, null, 0, 400);
    $fh   = fopen($path, 'rb');
    fseek($fh, max(0, $bytes - 400));
    $tail = (string)stream_get_contents($fh);
    fclose($fh);

    $wantTitle = $withData ? 'FULL DATABASE BACKUP' : 'DATABASE SCHEMA';
    if (!str_starts_with($head, '-- ====') || !str_contains($head, $wantTitle)) {
        return ['ok' => false, 'error' => 'The export did not start as expected. Nothing was downloaded.'];
    }
    if (!preg_match('/' . preg_quote(SET_END_MARKER, '/') . ' (\d+) tables(?:, (\d+) rows)?/', $tail, $m)
        || !str_contains($tail, "COMMIT;")) {
        return ['ok' => false, 'error' => 'The export was cut off before the end, so it is incomplete. Nothing was downloaded — please try again.'];
    }
    if ($withData && !isset($m[2])) {
        return ['ok' => false, 'error' => 'The export has no row data. Nothing was downloaded.'];
    }
    return ['ok' => true, 'tables' => (int)$m[1], 'rows' => isset($m[2]) ? (int)$m[2] : null,
            'bytes' => $bytes, 'sha256' => hash_file('sha256', $path)];
}

/** True when this PHP can write AES-256 password-protected ZIP files. */
function setZipAesAvailable(): bool
{
    return class_exists('ZipArchive') && defined('ZipArchive::EM_AES_256')
        && method_exists('ZipArchive', 'setEncryptionName')
        && (!method_exists('ZipArchive', 'isEncryptionMethodSupported') || ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256));
}

/** Wraps $sqlPath into an AES-256 encrypted ZIP at $zipPath. Returns null or an error. */
function setZipEncrypt(string $sqlPath, string $entryName, string $zipPath, string $password): ?string
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return 'The server could not create the ZIP file.';
    $ok = $zip->addFile($sqlPath, $entryName)
       && $zip->setEncryptionName($entryName, ZipArchive::EM_AES_256, $password)
       && $zip->setCompressionName($entryName, ZipArchive::CM_DEFLATE);
    $closed = $zip->close();
    if (!$ok || !$closed) return 'The server could not encrypt the ZIP file.';

    // Read it back: the entry must be encrypted and the right size
    $chk = new ZipArchive();
    if ($chk->open($zipPath) !== true) return 'The encrypted ZIP could not be checked.';
    $st = $chk->statName($entryName);
    $chk->close();
    if (!$st || (int)$st['size'] !== (int)filesize($sqlPath) || (int)($st['encryption_method'] ?? 0) !== ZipArchive::EM_AES_256) {
        return 'The encrypted ZIP did not pass its check.';
    }
    return null;
}

// ─────────────────────────────────────────────────────────────
//  Audit: last downloads (stored in audit_logs, no extra table)
// ─────────────────────────────────────────────────────────────

/** Latest successful download of each kind: ['full' => row|null, 'schema' => row|null, 'ok' => bool]. */
function setLastExports(): array
{
    $sel = 'select=created_at,username,action,after_data&module=eq.' . SET_BACKUP_MODULE . '&order=created_at.desc&limit=1';
    $full   = supabase_request('audit_logs', 'GET', [], $sel . '&action=eq.' . SET_ACTION_BACKUP);
    $schema = supabase_request('audit_logs', 'GET', [], $sel . '&action=eq.' . SET_ACTION_SCHEMA);
    $pick = fn($r) => (!$r['error'] && is_array($r['data']) && isset($r['data'][0])) ? $r['data'][0] : null;
    return ['full' => $pick($full), 'schema' => $pick($schema), 'ok' => !$full['error'] && !$schema['error']];
}

/** Backup health for the summary: ['state' => never|due|ok|unknown, 'label', 'days'] */
function setBackupState(?array $last, bool $known = true): array
{
    if (!$known) return ['state' => 'unknown', 'label' => 'Unknown', 'days' => null];
    if (!$last)  return ['state' => 'never', 'label' => 'No backup yet', 'days' => null];
    $days = (int)floor((time() - strtotime($last['created_at'])) / 86400);
    return $days >= SET_BACKUP_DUE_DAYS
        ? ['state' => 'due', 'label' => 'Backup due', 'days' => $days]
        : ['state' => 'ok', 'label' => 'Up to date', 'days' => $days];
}

// ─────────────────────────────────────────────────────────────
//  Passwords (the signed-in staff member's OWN password)
// ─────────────────────────────────────────────────────────────

/**
 * Checks the signed-in user's own password (bcrypt, like the sign-in) and
 * that the account is still active with one of $roles. Wrong passwords share
 * the per-session limit used by the other password checks
 * (controllers/appointment_edit_grant.php). Returns null when correct.
 */
function setCheckOwnPassword(string $password, array $roles): ?array
{
    require_once __DIR__ . '/appointment_edit_grant.php';
    $wait = aptEditLockSeconds();
    if ($wait > 0) {
        return ['success' => false, 'code' => 'LOCKED',
                'message' => 'Too many incorrect attempts. Please wait ' . aptEditWaitLabel($wait) . ' and try again.'];
    }
    if ($password === '') return ['success' => false, 'code' => 'EMPTY', 'message' => 'Please enter your password.'];

    $res = supabase_request('users', 'GET', [],
        'user_id=eq.' . (int)$_SESSION['user_id'] . '&select=password,status,role&limit=1');
    if ($res['error'] || !is_array($res['data'])) {
        return ['success' => false, 'code' => 'UNAVAILABLE', 'message' => "We couldn't check your password right now. Please try again."];
    }
    $me = $res['data'][0] ?? null;
    if (!$me || ($me['status'] ?? '') !== 'active' || !in_array($me['role'] ?? '', $roles, true)) {
        return ['success' => false, 'code' => 'DENIED', 'message' => 'Your account is not allowed to do this. Please sign in again.'];
    }
    if (!is_string($me['password'] ?? null) || !password_verify($password, $me['password'])) {
        $left = aptEditRecordFailure();
        $msg  = 'Incorrect password. Please try again.';
        if ($left === 0)    $msg = 'Incorrect password. Too many attempts — please wait ' . aptEditWaitLabel(aptEditLockSeconds()) . ' and try again.';
        elseif ($left <= 2) $msg .= ' ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.';
        return ['success' => false, 'code' => 'WRONG_PASSWORD', 'message' => $msg];
    }
    unset($_SESSION['apt_edit_fails']);
    return null;
}

/** Rules for a new staff password; returns an error message or null. */
function setNewPasswordError(string $new, string $confirm, string $current): ?string
{
    if ($new === '')                      return 'Please enter a new password.';
    if (strlen($new) < 8)                 return 'Use at least 8 characters.';
    if (strlen($new) > 200)               return 'That password is too long.';
    if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) return 'Use at least one letter and one number.';
    if ($new !== $confirm)                return 'The two new passwords do not match.';
    if ($current !== '' && hash_equals($current, $new)) return 'Choose a password different from your current one.';
    return null;
}

// ─────────────────────────────────────────────────────────────
//  Small display helpers
// ─────────────────────────────────────────────────────────────

function setBytes(?int $b): string
{
    if ($b === null) return '—';
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return number_format($b / 1024, 1) . ' KB';
    if ($b < 1073741824) return number_format($b / 1048576, 1) . ' MB';
    return number_format($b / 1073741824, 2) . ' GB';
}

/** Is a secret configured? Only ever answers yes / no — never the value. */
function setSecretConfigured(string $key): bool
{
    static $s = null;
    if ($s === null) {
        $s = is_file(__DIR__ . '/../config/secrets.php') ? (require __DIR__ . '/../config/secrets.php') : [];
        if (!is_array($s)) $s = [];
    }
    $v = $s[$key] ?? '';
    return is_string($v) && trim($v) !== '';
}

/** Simple, non-identifying browser label from a user-agent string. */
function setBrowserLabel(string $ua): string
{
    $b = match (true) {
        str_contains($ua, 'Edg/')     => 'Edge',
        str_contains($ua, 'OPR/')     => 'Opera',
        str_contains($ua, 'Chrome/')  => 'Chrome',
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Safari/')  => 'Safari',
        default                       => 'Browser',
    };
    $os = match (true) {
        str_contains($ua, 'Windows')                                => 'Windows',
        str_contains($ua, 'Android')                                => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')    => 'iOS',
        str_contains($ua, 'Mac OS')                                 => 'macOS',
        str_contains($ua, 'Linux')                                  => 'Linux',
        default                                                     => '',
    };
    return $os ? "$b on $os" : $b;
}
