<?php
define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/secure_controller.php';
require_once __DIR__ . '/../dbconfig.php';

header('Content-Type: application/json');

// The page sends form fields (FormData); a JSON body is accepted too. The
// "Invalid patient." on Delete Waiver came from here: the page posted JSON,
// which PHP does not put in $_POST, so action and patient_id were both empty.
$input     = str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
$action    = (string)($_POST['action'] ?? $input['action'] ?? $_GET['action'] ?? '');
$patientId = (int)($_POST['patient_id'] ?? $input['patient_id'] ?? $_GET['patient_id'] ?? 0);

if (!$patientId) {
    echo json_encode(['success' => false, 'message' => 'Invalid patient.']); exit;
}
requirePatientAccess($patientId);   // non-owners: only patients with an appointment at their branch

// ── GET WAIVERS ───────────────────────────────────────────────
if ($action === 'get_waivers') {
    pw_protect_dir();
    $res = supabase_request('waivers', 'GET', [],
        'patient_id=eq.' . $patientId .
        '&order=waiver_id.desc' .
        '&select=waiver_id,waiver_type,file_path,status,signed_at,signed_by'
    );
    echo json_encode(['success' => true, 'waivers' => $res['data'] ?? []]);
    exit;
}

/** A waiver of THIS patient (patient_id checked in the query), or null. */
function pw_row(int $patientId, int $waiverId): ?array
{
    if ($waiverId <= 0) return null;
    $r = supabase_request('waivers', 'GET', [],
        'waiver_id=eq.' . $waiverId . '&patient_id=eq.' . $patientId . '&select=waiver_id,waiver_type,file_path&limit=1');
    return $r['data'][0] ?? null;
}

/** Absolute path of a stored waiver file of this patient, or null (no path tricks). */
function pw_abs(int $patientId, string $rel): ?string
{
    if (!preg_match('#^uploads/waivers/(\d+)/([A-Za-z0-9_-]+\.(pdf|png|jpe?g))$#i', $rel, $m) || (int)$m[1] !== $patientId) return null;
    $base = realpath(__DIR__ . '/../uploads/waivers');
    $abs  = realpath(__DIR__ . '/../' . $rel);
    return ($base && $abs && str_starts_with($abs, $base . DIRECTORY_SEPARATOR) && is_file($abs)) ? $abs : null;
}

/** uploads/ is not deployed with the code: keep the folder closed to direct URLs. */
function pw_protect_dir(): void
{
    $dir = __DIR__ . '/../uploads/waivers';
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "# Waivers are served only through api/patient_waivers.php (staff) and
# api/portal_waiver_file.php (patient), which check who may see them.
Require all denied
");
    }
}

// ── VIEW / DOWNLOAD (stream; Patient Record preview modal) ─────
if ($action === 'view') {
    $w   = pw_row($patientId, (int)($_GET['waiver_id'] ?? 0));
    $abs = $w ? pw_abs($patientId, (string)$w['file_path']) : null;
    if (!$abs) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('This waiver file is not available on the server.');
    }
    $types = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    $mime  = (new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: '';
    if (!isset($types[$mime])) { http_response_code(415); header('Content-Type: text/plain; charset=utf-8'); exit('Unsupported file.'); }
    $name = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($w['waiver_type'] ?? '')), '-') ?: 'Waiver';
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '.' . $types[$mime] . '"');
    readfile($abs);
    exit;
}

if (in_array($action, ['upload_waiver', 'delete_waiver'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit;
}

// ── UPLOAD WAIVER ─────────────────────────────────────────────
if ($action === 'upload_waiver') {
    if (empty($_FILES['waiver_file']) || $_FILES['waiver_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file received.']); exit;
    }

    $file       = $_FILES['waiver_file'];
    $waiverType = trim($_POST['waiver_type'] ?? '');

    if (!$waiverType) {
        echo json_encode(['success' => false, 'message' => 'Waiver type is required.']); exit;
    }
    if (strlen($waiverType) > 50) {
        echo json_encode(['success' => false, 'message' => 'Waiver type too long (max 50 chars).']); exit;
    }

    // Type is detected from the file's content (the browser-sent type and the
    // original extension are not trusted); the saved extension comes from it.
    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    $mime    = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) {
        echo json_encode(['success' => false, 'message' => 'Only PDF, JPG, and PNG files are allowed.']); exit;
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File must be under 5MB.']); exit;
    }

    $uploadDir = __DIR__ . '/../uploads/waivers/' . $patientId . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    pw_protect_dir();

    $ext      = $allowed[$mime];
    $fileName = 'waiver_' . bin2hex(random_bytes(12)) . '.' . $ext; // unguessable name
    $filePath = 'uploads/waivers/' . $patientId . '/' . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save file on server.']); exit;
    }

    $res = supabase_request('waivers', 'POST', [
        'patient_id'  => $patientId,
        'waiver_type' => $waiverType,
        'file_path'   => $filePath,
        'status'      => 'Pending',
    ]);

    if ($res['status'] === 201 || !empty($res['data'])) {
        echo json_encode(['success' => true, 'message' => 'Waiver uploaded successfully.']);
    } else {
        @unlink($uploadDir . $fileName);
        echo json_encode(['success' => false, 'message' => 'Failed to save waiver record.']);
    }
    exit;
}

// ── DELETE WAIVER ─────────────────────────────────────────────
if ($action === 'delete_waiver') {
    $waiverId = (int)($_POST['waiver_id'] ?? $input['waiver_id'] ?? 0);
    if (!$waiverId) {
        echo json_encode(['success' => false, 'message' => 'Invalid waiver.']); exit;
    }

    // The waiver must belong to THIS patient (a waiver id of another patient is "not found")
    $waiver = pw_row($patientId, $waiverId);
    if (!$waiver) {
        echo json_encode(['success' => false, 'message' => 'Waiver not found for this patient.']); exit;
    }

    $del = supabase_request('waivers', 'DELETE', [],
        'waiver_id=eq.' . $waiverId . '&patient_id=eq.' . $patientId
    );
    if (($del['status'] ?? 0) < 200 || ($del['status'] ?? 0) >= 300) {
        echo json_encode(['success' => false, 'message' => 'Could not delete the waiver. Please try again.']); exit;
    }
    // Remove the stored file only after the record is gone
    if ($abs = pw_abs($patientId, (string)($waiver['file_path'] ?? ''))) @unlink($abs);

    echo json_encode(['success' => true, 'message' => 'Waiver deleted.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);