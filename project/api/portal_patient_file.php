<?php
// =============================================================
//  api/portal_patient_file.php  —  Patient Portal › My Files
//    GET ?file_id=N              → streams the file inline (View)
//    GET ?file_id=N&download=1   → as an attachment (Download)
//    GET ?file_id=N&check=1      → JSON { success } — the portal checks first, so a
//                                  missing file shows a portal message, not an error page
//
//  The patient must be signed in and the file must be theirs. Files are uploaded
//  by staff (api/patient_files.php) into uploads/patient_files/<patient_id>/,
//  a folder that denies direct web access.
// =============================================================

session_start();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../portal-session-guard.php';

function pfile_fail(int $status, string $code, string $message): void
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
$fileId    = (int)($_GET['file_id'] ?? 0);
if ($fileId <= 0) pfile_fail(400, 'BAD_REQUEST', 'Invalid file.');

$patientId = (int)portal_run_one(pl_patient_id($accountId));
if (!$patientId) pfile_fail(404, 'NOT_FOUND', 'File not found.');

// Ownership: the file must belong to the signed-in patient
$res = supabase_request('patient_files', 'GET', [],
    'file_id=eq.' . $fileId . '&patient_id=eq.' . $patientId . '&select=file_name,file_type,file_path&limit=1');
if (!empty($res['error'])) pfile_fail(502, 'UNAVAILABLE', 'We could not load this file right now. Please try again.');
$row = $res['data'][0] ?? null;
if (!$row) pfile_fail(404, 'NOT_FOUND', 'File not found.');

$missing = 'This file is not available on the portal right now. Please contact the clinic so they can upload it again.';
$rel = (string)($row['file_path'] ?? '');
if (!preg_match('#^uploads/patient_files/(\d+)/(pf_[a-f0-9]{24}\.(pdf|png|jpg))$#', $rel, $m) || (int)$m[1] !== $patientId) {
    pfile_fail(404, 'FILE_MISSING', $missing);
}
$base = realpath(__DIR__ . '/../uploads/patient_files');
$abs  = realpath(__DIR__ . '/../' . $rel);
if (!$base || !$abs || !str_starts_with($abs, $base . DIRECTORY_SEPARATOR) || !is_file($abs) || !is_readable($abs)) {
    pfile_fail(404, 'FILE_MISSING', $missing);
}
$types = ['application/pdf', 'image/jpeg', 'image/png'];
$mime  = (new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: '';
if (!in_array($mime, $types, true)) pfile_fail(415, 'FILE_MISSING', $missing);

if (!empty($_GET['check'])) {
    echo json_encode(['success' => true]);
    exit;
}

$name = preg_replace('/[^A-Za-z0-9 ._()-]+/', '-', (string)($row['file_name'] ?: ($row['file_type'] ?: 'file')));
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
readfile($abs);
