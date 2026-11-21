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
requireStaffApi('owner', 'admin', 'dentist');   // staff only; non-owners are pinned to their branch

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

requireAppointmentBranch($appointmentId);   // the appointment's real branch

// ── Fetch current state BEFORE update (for rich audit log) ──
$info = getAppointmentInfoForAudit($appointmentId);

// ── Cancel: same shared write as api/appointments.php (reason required;
//    who / when / why recorded — controllers/appointment_lifecycle.php) ──
if ($newStatus === 'cancelled') {
    require_once __DIR__ . '/../controllers/appointment_lifecycle.php';
    [$reason, $rErr] = aptCleanCancelReason($body['reason'] ?? '');
    if ($rErr) {
        echo json_encode(['success' => false, 'code' => $rErr, 'message' => 'Please enter the reason for cancelling this appointment (up to ' . APT_CANCEL_REASON_MAX . ' characters).']);
        exit;
    }
    $c = aptCancel($appointmentId, [
        'type' => 'staff', 'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'name' => (string)($_SESSION['full_name'] ?? 'Staff'), 'role' => (string)($_SESSION['role'] ?? ''),
    ], $reason, ['pending', 'confirmed', 'checked_in']);
    if ($c['ok']) {
        logActivity('appointment-schedules', 'UPDATE', $appointmentId, $info['patient_name'],
            'Appointment status changed from ' . ucfirst(str_replace('_', ' ', (string)$c['prev'])) . ' to Cancelled — Reason: ' . $reason);
    }
    echo json_encode($c['ok'] ? ['success' => true, 'message' => 'The appointment has been cancelled.']
                              : ['success' => false, 'code' => $c['code'], 'message' => $c['message']]);
    exit;
}

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