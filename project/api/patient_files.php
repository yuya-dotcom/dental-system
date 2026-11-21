<?php
// =============================================================
//  api/patient_files.php  —  Patient Record › Files (staff)
//
//  Files uploaded here are the patient's documents (X-rays, referrals,
//  medical certificates…). They appear in the Patient Portal under
//  My Files (controllers/portal_loaders.php → pl_files, streamed to the
//  patient by api/portal_patient_file.php).
//
//    GET  ?action=list&patient_id=N
//    POST action=upload  patient_id, file_type, patient_file   (multipart)
//    POST action=delete  patient_id, file_id
//    GET  ?action=view&patient_id=N&file_id=M[&download=1]       (streams the file)
//
//  Storage: uploads/patient_files/<patient_id>/pf_<random>.<ext>. That folder
//  denies direct web access (.htaccess), so a file is only ever served through
//  this endpoint (staff) or the portal's ownership-checked endpoint.
// =============================================================
define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/secure_controller.php';
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/audit_controller.php';   // logActivity()

const PF_TYPES   = ['X-ray', 'Referral', 'Medical Certificate', 'Prescription', 'Lab Result', 'Treatment Plan', 'Other'];
const PF_MIME    = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
const PF_MAX_MB  = 10;
const PF_DIR     = 'uploads/patient_files';

function pf_json(array $out, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$patientId = (int)($_POST['patient_id'] ?? $_GET['patient_id'] ?? 0);
if ($patientId <= 0) pf_json(['success' => false, 'message' => 'Invalid patient.'], 400);
requirePatientAccess($patientId);   // non-owners: only patients with an appointment at their branch

/** One file row of this patient, or null. */
function pf_row(int $patientId, int $fileId): ?array
{
    if ($fileId <= 0) return null;
    $r = supabase_request('patient_files', 'GET', [],
        'file_id=eq.' . $fileId . '&patient_id=eq.' . $patientId . '&select=file_id,file_type,file_name,file_path&limit=1');
    return $r['data'][0] ?? null;
}

/** Absolute path of a stored file, only when it is inside this patient's folder. */
function pf_abs(int $patientId, string $rel): ?string
{
    if (!preg_match('#^' . PF_DIR . '/(\d+)/(pf_[a-f0-9]{24}\.(pdf|png|jpg))$#', $rel, $m) || (int)$m[1] !== $patientId) return null;
    $base = realpath(__DIR__ . '/../' . PF_DIR);
    $abs  = realpath(__DIR__ . '/../' . $rel);
    return ($base && $abs && str_starts_with($abs, $base . DIRECTORY_SEPARATOR) && is_file($abs)) ? $abs : null;
}

switch ($action) {
    // ── List ──────────────────────────────────────────────────
    case 'list':
        $r = supabase_request('patient_files', 'GET', [],
            'patient_id=eq.' . $patientId . '&select=file_id,file_type,file_name,uploaded_by,uploaded_at&order=uploaded_at.desc');
        if (!empty($r['error'])) pf_json(['success' => false, 'message' => 'Could not load files.'], 502);
        pf_json(['success' => true, 'files' => $r['data'] ?? [], 'types' => PF_TYPES, 'max_mb' => PF_MAX_MB]);

    // ── Upload ────────────────────────────────────────────────
    case 'upload':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pf_json(['success' => false, 'message' => 'Invalid request.'], 405);
        $type = trim((string)($_POST['file_type'] ?? ''));
        if (!in_array($type, PF_TYPES, true)) pf_json(['success' => false, 'message' => 'Please choose a file type.']);
        $f = $_FILES['patient_file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $tooBig = in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            pf_json(['success' => false, 'message' => $tooBig ? 'File must be under ' . PF_MAX_MB . ' MB.' : 'No file received.']);
        }
        if ($f['size'] > PF_MAX_MB * 1024 * 1024) pf_json(['success' => false, 'message' => 'File must be under ' . PF_MAX_MB . ' MB.']);
        // Type from the file's content, never from the browser or the original name
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
        if (!isset(PF_MIME[$mime])) pf_json(['success' => false, 'message' => 'Only PDF, JPG and PNG files are allowed.']);

        // Patient must exist (patients aren't branch-scoped: any staff role may attach files)
        $p = supabase_request('patients', 'GET', [], 'patient_id=eq.' . $patientId . '&select=patient_id&limit=1');
        if (empty($p['data'][0])) pf_json(['success' => false, 'message' => 'Patient not found.'], 404);

        $dir = __DIR__ . '/../' . PF_DIR . '/' . $patientId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) pf_json(['success' => false, 'message' => 'Could not save the file on the server.'], 500);
        // uploads/ is not in git, so make sure the folder's "no direct access" rule exists on every server
        $guard = __DIR__ . '/../' . PF_DIR . '/.htaccess';
        if (!is_file($guard)) @file_put_contents($guard, "# Private patient documents: served only through api/patient_files.php\n# and api/portal_patient_file.php (both check access).\nRequire all denied\n");
        $ext    = PF_MIME[$mime];
        $stored = 'pf_' . bin2hex(random_bytes(12)) . '.' . $ext;    // unguessable
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) pf_json(['success' => false, 'message' => 'Could not save the file on the server.'], 500);

        // Display name: the original name without its extension, cleaned
        $orig = pathinfo((string)($f['name'] ?? ''), PATHINFO_FILENAME);
        $name = trim(preg_replace('/[^\p{L}\p{N} ._()-]+/u', ' ', $orig) ?? '');
        $name = mb_substr(preg_replace('/\s+/', ' ', $name), 0, 120) ?: $type;
        $by   = mb_substr(trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Clinic staff')), 0, 20); // column is varchar(20)

        $res = supabase_request('patient_files', 'POST', [
            'patient_id'  => $patientId,
            'file_type'   => $type,
            'file_name'   => $name . '.' . $ext,
            'file_path'   => PF_DIR . '/' . $patientId . '/' . $stored,
            'uploaded_by' => $by,
        ]);
        if (!empty($res['error']) || (int)($res['status'] ?? 0) >= 300) {
            @unlink($dir . '/' . $stored);
            pf_json(['success' => false, 'message' => 'Could not save the file record. Please try again.'], 502);
        }
        logActivity('patients', 'upload_file', $patientId, $name . '.' . $ext, 'Uploaded ' . $type . ' "' . $name . '.' . $ext . '" to the patient\'s files');
        pf_json(['success' => true, 'message' => 'File uploaded. The patient can now see it in My Files.']);

    // ── Delete ────────────────────────────────────────────────
    case 'delete':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pf_json(['success' => false, 'message' => 'Invalid request.'], 405);
        $row = pf_row($patientId, (int)($_POST['file_id'] ?? 0));
        if (!$row) pf_json(['success' => false, 'message' => 'File not found.'], 404);
        $del = supabase_request('patient_files', 'DELETE', [], 'file_id=eq.' . (int)$row['file_id'] . '&patient_id=eq.' . $patientId);
        if (!empty($del['error'])) pf_json(['success' => false, 'message' => 'Could not delete the file. Please try again.'], 502);
        if ($abs = pf_abs($patientId, (string)$row['file_path'])) @unlink($abs);
        logActivity('patients', 'delete_file', $patientId, (string)$row['file_name'], 'Deleted ' . $row['file_type'] . ' "' . $row['file_name'] . '" from the patient\'s files');
        pf_json(['success' => true, 'message' => 'File deleted.']);

    // ── View / download (stream) ──────────────────────────────
    case 'view':
        $row = pf_row($patientId, (int)($_GET['file_id'] ?? 0));
        $abs = $row ? pf_abs($patientId, (string)$row['file_path']) : null;
        if (!$abs) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit('This file is not available on the server.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: 'application/octet-stream';
        $name = preg_replace('/[^A-Za-z0-9 ._()-]+/', '-', (string)($row['file_name'] ?: 'file'));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
        readfile($abs);
        exit;
}

pf_json(['success' => false, 'message' => 'Invalid action.'], 400);
