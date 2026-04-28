<?php
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/audit_controller.php';

header('Content-Type: application/json');
requireLogin();

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'add':    echo json_encode(addPatient($input));    break;
    case 'edit':   echo json_encode(editPatient($input));   break;
    case 'delete': echo json_encode(deletePatient($input)); break;
    default:       echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

function addPatient(array $d): array
{
    if (empty($d['full_name']))  return ['success' => false, 'message' => 'Full name is required.'];
    if (empty($d['branch_id']))  return ['success' => false, 'message' => 'Branch is required.'];

    $payload = [
        'full_name'      => trim($d['full_name']),
        'contact_number' => $d['contact_number'] ?? null,
        'gender'         => $d['gender']         ?? null,
        'birthdate'      => $d['birthdate']       ?: null,
        'branch_id'      => (int)$d['branch_id'],
        'last_visit'     => $d['last_visit']      ?: null,
        'status'         => $d['status']          ?? 'active',
    ];

    $result = supabase_request('patients', 'POST', $payload);
    if ($result['error']) return ['success' => false, 'message' => $result['error']];

    $newPatientId = $result['data'][0]['patient_id'] ?? null;
    logActivity(
        'patients',
        'INSERT',
        $newPatientId ?? 0,
        trim($d['full_name']),
        'New patient record created'
    );

    return ['success' => true, 'message' => 'Patient added successfully.'];
}

function editPatient(array $d): array
{
    if (empty($d['patient_id'])) return ['success' => false, 'message' => 'Missing patient ID.'];
    if (empty($d['full_name']))  return ['success' => false, 'message' => 'Full name is required.'];

    $payload = [
        'full_name'      => trim($d['full_name']),
        'contact_number' => $d['contact_number'] ?? null,
        'gender'         => $d['gender']         ?? null,
        'birthdate'      => $d['birthdate']       ?: null,
        'branch_id'      => (int)$d['branch_id'],
        'last_visit'     => $d['last_visit']      ?: null,
        'status'         => $d['status']          ?? 'active',
        'updated_at'     => date('c'),
    ];

    $result = supabase_request('patients', 'PATCH', $payload, 'patient_id=eq.' . (int)$d['patient_id']);
    if ($result['error']) return ['success' => false, 'message' => $result['error']];

    logActivity(
        'patients',
        'UPDATE',
        (int)$d['patient_id'],
        trim($d['full_name']),
        'Patient record updated'
    );

    return ['success' => true, 'message' => 'Patient updated successfully.'];
}

function deletePatient(array $d): array
{
    if (empty($d['patient_id'])) return ['success' => false, 'message' => 'Missing patient ID.'];

    $patientName = getPatientNameForAudit((int)$d['patient_id']);
    $result = supabase_request('patients', 'DELETE', [], 'patient_id=eq.' . (int)$d['patient_id']);
    if ($result['error']) return ['success' => false, 'message' => $result['error']];

    logActivity(
        'patients',
        'DELETE',
        (int)$d['patient_id'],
        $patientName,
        'Patient record permanently deleted'
    );

    return ['success' => true, 'message' => 'Patient deleted successfully.'];
}