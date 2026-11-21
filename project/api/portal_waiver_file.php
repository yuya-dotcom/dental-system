<?php
// =============================================================
//  api/portal_waiver_file.php
//  GET ?waiver_id=N          → streams the waiver document (PDF / JPG / PNG)
//  GET ?waiver_id=N&check=1  → JSON { success } — used by the portal first,
//                              so a problem shows as a portal message instead
//                              of the web host's generic error page.
//
//  Patient Portal "View Document" for waivers. The patient must be signed in
//  and the waiver must belong to them. Files are read from uploads/waivers/
//  on this server (the stored path is never shown to the browser), with the
//  same three types the clinic can upload (api/patient_waivers.php).
//
//  Why: waiver files live on the disk of the server that received the
//  upload (uploads/ is not deployed with the code), while every server shares
//  one database. Opening the raw path for a file that isn't on this server
//  produced the host's "Something went wrong" error page.
// =============================================================

session_start();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../portal-session-guard.php';

function waiver_fail(int $status, string $code, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'code' => $code, 'message' => $message]);
    exit;
}

header('Content-Type: application/json');   // replaced below when a file is sent
portal_require_login_api();                 // 401 JSON when signed out / expired; releases the session lock

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/portal_loaders.php';

$accountId = (int)($_SESSION['portal_account_id'] ?? 0);
$waiverId  = (int)($_GET['waiver_id'] ?? 0);
if (!$waiverId) waiver_fail(400, 'BAD_REQUEST', 'Invalid document.');

$patientId = portal_run_one(pl_patient_id($accountId));
if (!$patientId) waiver_fail(404, 'NOT_FOUND', 'Document not found.');

// Ownership: the waiver must belong to the signed-in patient
$res = supabase_request('waivers', 'GET', [],
    'waiver_id=eq.' . $waiverId . '&patient_id=eq.' . (int)$patientId . '&select=waiver_type,file_path&limit=1');
if (!empty($res['error'])) waiver_fail(502, 'UNAVAILABLE', 'We could not load this document right now. Please try again.');
$w = $res['data'][0] ?? null;
if (!$w) waiver_fail(404, 'NOT_FOUND', 'Document not found.');

$missing = 'This document is not available on the portal right now. Please contact the clinic so they can upload it again.';

// Stored by api/patient_waivers.php as uploads/waivers/<patient_id>/<name>.<ext>
$rel = (string)($w['file_path'] ?? '');
if (!preg_match('#^uploads/waivers/(\d+)/([A-Za-z0-9_-]+\.(pdf|png|jpe?g))$#i', $rel, $m) || (int)$m[1] !== (int)$patientId) {
    waiver_fail(404, 'FILE_MISSING', $missing);
}
$base = realpath(__DIR__ . '/../uploads/waivers');
$file = realpath(__DIR__ . '/../' . $rel);
if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file) || !is_readable($file)) {
    waiver_fail(404, 'FILE_MISSING', $missing);
}

$types = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
$mime  = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: '';
if (!isset($types[$mime])) waiver_fail(415, 'FILE_MISSING', $missing);

if (!empty($_GET['check'])) {
    echo json_encode(['success' => true]);
    exit;
}

$name = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim((string)($w['waiver_type'] ?? ''))) ?: 'Waiver';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: inline; filename="' . trim($name, '-') . '.' . $types[$mime] . '"');
readfile($file);
