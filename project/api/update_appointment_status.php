<?php
// =============================================================
//  api/update_appointment_status.php
//  POST — Change an appointment's status
//         Called from appointments-schedule.php via fetch()
//
//  FIXED:
//    - Added session_start() + requireLogin() (was missing!)
//    - Added logActivity() so every status change is audited
//    - Fetches old status + patient name before updating so the
//      audit description is meaningful (e.g. "Pending → Confirmed")
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/audit_controller.php';
require_once __DIR__ . '/../controllers/appointment_controller.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body          = json_decode(file_get_contents('php://input'), true) ?? [];
$appointmentId = (int)($body['appointment_id'] ?? 0);
$newStatus     = trim($body['status']          ?? '');

if ($appointmentId < 1 || empty($newStatus)) {
    echo json_encode(['success' => false, 'message' => 'Appointment ID and status are required.']);
    exit;
}

// ── Fetch current state BEFORE update (for rich audit log) ──
$info = getAppointmentInfoForAudit($appointmentId);

// ── Perform the update ───────────────────────────────────────
$result = updateAppointmentStatus($appointmentId, $newStatus);

// ── Log to audit trail only if update succeeded ──────────────
if ($result['success']) {
    $oldLabel = ucfirst(str_replace('_', ' ', $info['old_status']));
    $newLabel = ucfirst(str_replace('_', ' ', $newStatus));

    logActivity(
        'appointment-schedules',
        'UPDATE',
        $appointmentId,
        $info['patient_name'],
        "Appointment status changed from {$oldLabel} to {$newLabel}"
    );
}

echo json_encode($result);