<?php
define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/secure_controller.php';
require_once __DIR__ . '/../dbconfig.php';

header('Content-Type: application/json');

$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$patientId = (int)($_POST['patient_id'] ?? $_GET['patient_id'] ?? 0);

if (!$patientId) {
    echo json_encode(['success' => false, 'message' => 'Invalid patient.']); exit;
}

// ── GET WAIVERS ───────────────────────────────────────────────
if ($action === 'get_waivers') {
    $res = supabase_request('waivers', 'GET', [],
        'patient_id=eq.' . $patientId .
        '&order=waiver_id.desc' .
        '&select=waiver_id,waiver_type,file_path,status,signed_at,signed_by'
    );
    echo json_encode(['success' => true, 'waivers' => $res['data'] ?? []]);
    exit;
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
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $waiverId = (int)($input['waiver_id'] ?? $_POST['waiver_id'] ?? 0);

    if (!$waiverId) {
        echo json_encode(['success' => false, 'message' => 'Invalid waiver.']); exit;
    }

    $res    = supabase_request('waivers', 'GET', [],
        'waiver_id=eq.' . $waiverId . '&patient_id=eq.' . $patientId . '&select=file_path'
    );
    $waiver = $res['data'][0] ?? null;

    if ($waiver && !empty($waiver['file_path'])) {
        $fullPath = __DIR__ . '/../' . $waiver['file_path'];
        if (file_exists($fullPath)) @unlink($fullPath);
    }

    supabase_request('waivers', 'DELETE', [],
        'waiver_id=eq.' . $waiverId . '&patient_id=eq.' . $patientId
    );

    echo json_encode(['success' => true, 'message' => 'Waiver deleted.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);