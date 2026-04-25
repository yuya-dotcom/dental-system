<?php
// =============================================================
//  api/appointment_crud.php
//  Handles: add, edit, delete appointments
//  Called via fetch() from appointments-schedule.php
//
//  FIXED: Removed orphaned code that was outside functions
//         (the log_action calls after editAppointment and
//          deleteAppointment were unreachable dead code and
//          caused PHP parse errors).
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'add':    echo json_encode(addAppointment($input));    break;
    case 'edit':   echo json_encode(editAppointment($input));   break;
    case 'delete': echo json_encode(deleteAppointment($input)); break;
    default:       echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

// ─────────────────────────────────────────────────────────────
//  ADD
// ─────────────────────────────────────────────────────────────
function addAppointment(array $d): array
{
    $required = ['patient_id', 'branch_id', 'appointment_date', 'appointment_time', 'status'];
    foreach ($required as $f) {
        if (empty($d[$f])) return ['success' => false, 'message' => "Field '{$f}' is required."];
    }

    // Validate status value
    $allowedStatuses = ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled'];
    if (!in_array($d['status'], $allowedStatuses)) {
        return ['success' => false, 'message' => 'Invalid status value.'];
    }

    $payload = [
        'patient_id'       => (int)$d['patient_id'],
        'branch_id'        => (int)$d['branch_id'],
        'dentist_id'       => !empty($d['dentist_id']) ? (int)$d['dentist_id'] : null,
        'service_id'       => !empty($d['service_id']) ? (int)$d['service_id'] : null,
        'appointment_date' => $d['appointment_date'],
        'appointment_time' => $d['appointment_time'],
        'appointment_type' => $d['appointment_type'] ?? 'Consultation',
        'notes'            => !empty($d['notes']) ? $d['notes'] : null,
        'status'           => $d['status'],
        'payment_status'   => $d['payment_status'] ?? 'unpaid',
    ];

    $result = supabase_request('appointments', 'POST', $payload);

    if ($result['error']) {
        return ['success' => false, 'message' => $result['error']];
    }

    // Log the action if log_action() exists in this project
    if (function_exists('log_action')) {
        log_action('Add Appointment', 'Added appointment for patient_id: ' . $d['patient_id']);
    }

    return ['success' => true, 'message' => 'Appointment added successfully.'];
}

// ─────────────────────────────────────────────────────────────
//  EDIT
// ─────────────────────────────────────────────────────────────
function editAppointment(array $d): array
{
    if (empty($d['appointment_id'])) {
        return ['success' => false, 'message' => 'Missing appointment ID.'];
    }

    // Validate status value
    $allowedStatuses = ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled'];
    if (!empty($d['status']) && !in_array($d['status'], $allowedStatuses)) {
        return ['success' => false, 'message' => 'Invalid status value.'];
    }

    $payload = [
        'patient_id'       => (int)$d['patient_id'],
        'branch_id'        => (int)$d['branch_id'],
        'dentist_id'       => !empty($d['dentist_id']) ? (int)$d['dentist_id'] : null,
        'service_id'       => !empty($d['service_id']) ? (int)$d['service_id'] : null,
        'appointment_date' => $d['appointment_date'],
        'appointment_time' => $d['appointment_time'],
        'appointment_type' => $d['appointment_type'] ?? 'Consultation',
        'notes'            => !empty($d['notes']) ? $d['notes'] : null,
        'status'           => $d['status'],
        'payment_status'   => $d['payment_status'] ?? 'unpaid',
        'updated_at'       => date('c'),
    ];

    $result = supabase_request(
        'appointments', 'PATCH', $payload,
        'appointment_id=eq.' . (int)$d['appointment_id']
    );

    if ($result['error']) {
        return ['success' => false, 'message' => $result['error']];
    }

    // Log the action if log_action() exists in this project
    if (function_exists('log_action')) {
        log_action(
            'Update Appointment',
            'Updated appointment ID: ' . $d['appointment_id'] . ' to status: ' . $d['status']
        );
    }

    return ['success' => true, 'message' => 'Appointment updated successfully.'];
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

    // Log the action if log_action() exists in this project
    if (function_exists('log_action')) {
        log_action('Delete Appointment', 'Deleted appointment ID: ' . $aptId);
    }

    return ['success' => true, 'message' => 'Appointment deleted successfully.'];
}