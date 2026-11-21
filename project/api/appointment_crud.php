<?php
// =============================================================
//  api/appointment_crud.php
//  Handles: delete appointments (add / edit live in api/appointments.php)
//  Called via fetch() from appointments-schedule.php
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/audit_controller.php';
require_once __DIR__ . '/../controllers/notification_helper.php';

header('Content-Type: application/json');
requireLogin();
requireStaffApi('owner', 'admin', 'dentist');   // staff only; non-owners are pinned to their branch

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    // Add / edit live in ONE place: api/appointments.php (POST / PUT) — branch
    // from the session, CONFIRMED status, the Patient Portal's availability
    // rules, password re-check for edits. These old actions skipped all of that
    // and are no longer used by any page, so they are refused.
    case 'add':
    case 'edit':
        http_response_code(410);
        echo json_encode(['success' => false, 'message' => 'Please use Appointments → Add / Edit Appointment.']);
        break;
    case 'delete': echo json_encode(deleteAppointment($input)); break;
    default:       echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

// ─────────────────────────────────────────────────────────────
//  DELETE
// ─────────────────────────────────────────────────────────────
function deleteAppointment(array $d): array
{
    if (empty($d['appointment_id'])) {
        return ['success' => false, 'message' => 'Missing appointment ID.'];
    }

    $aptId = (int)$d['appointment_id'];
    // Deleting is an Owner/Admin action (the UI hides it from dentists), own branch only
    if (isDentist()) apiDeny(403, 'You are not allowed to do this.');
    requireAppointmentBranch($aptId);

    // Safety guard: do not delete if a treatment is already linked
    $linked = supabase_request('treatments', 'GET', [],
        'appointment_id=eq.' . $aptId . '&select=treatment_id&limit=1'
    );
    if (!empty($linked['data'])) {
        return [
            'success' => false,
            'message' => 'Cannot delete: a treatment record is linked to this appointment. Cancel it instead.',
        ];
    }

    $result = supabase_request(
        'appointments', 'DELETE', [],
        'appointment_id=eq.' . $aptId
    );

    if ($result['error']) {
        return ['success' => false, 'message' => $result['error']];
    }

    // Log to universal audit trail
    logActivity(
        'appointment-schedules',
        'DELETE',
        $aptId,
        'Appointment #' . $aptId,
        'Appointment record permanently deleted'
    );

    return ['success' => true, 'message' => 'Appointment deleted successfully.'];
}