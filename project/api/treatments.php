<?php
// =============================================================
//  api/treatments.php
//  GET    ?id=X              — fetch single treatment
//  GET    ?patient_id=X      — fetch treatments for a patient
//  POST                      — create new treatment
//  PATCH  ?id=X              — update treatment
//  DELETE ?id=X              — delete treatment
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

$method    = $_SERVER['REQUEST_METHOD'];
$id        = isset($_GET['id'])         ? (int)$_GET['id']         : 0;
$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

// ── GET ──────────────────────────────────────────────────────
if ($method === 'GET') {
    if ($id) {
        $res = supabase_request('treatments', 'GET', [],
            'treatment_id=eq.' . $id .
            '&select=treatment_id,treatment_code,treatment_date,status,current_stage,' .
            'tooth_number,cost,procedure_notes,appointment_id,patient_id,' .
            'dentist_id,branch_id,service_id,' .
            'patients(full_name),branches(branch_name),' .
            'dentists(full_name),services(service_name,base_price)' .
            '&limit=1'
        );
        if (!empty($res['data'][0])) {
            echo json_encode(['success' => true, 'data' => $res['data'][0]]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Treatment not found.']);
        }
        exit;
    }

    if ($patientId) {
        $res = supabase_request('treatments', 'GET', [],
            'patient_id=eq.' . $patientId .
            '&select=treatment_id,treatment_code,treatment_date,cost,status,services(service_name)' .
            '&order=treatment_date.desc'
        );
        echo json_encode($res['data'] ?? []);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Missing id or patient_id.']);
    exit;
}

// ── POST — create ────────────────────────────────────────────
if ($method === 'POST') {
    if (!isOwner() && !isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($body['patient_id'])) { echo json_encode(['success' => false, 'message' => 'Patient is required.']);  exit; }
    if (empty($body['branch_id']))  { echo json_encode(['success' => false, 'message' => 'Branch is required.']);   exit; }
    if (empty($body['status']))     { echo json_encode(['success' => false, 'message' => 'Status is required.']);   exit; }

    // Generate treatment code matching existing format (TR-XXXXX or TRT-XXXX)
    $last     = supabase_request('treatments', 'GET', [], 'select=treatment_code&order=treatment_code.desc&limit=1');
    $lastCode = $last['data'][0]['treatment_code'] ?? 'TR-00000';
    $prefix   = str_contains($lastCode, 'TRT-') ? 'TRT-' : 'TR-';
    $padLen   = str_contains($lastCode, 'TRT-') ? 4 : 5;
    $lastNum  = (int)preg_replace('/[^0-9]/', '', $lastCode);
    $trtCode  = $prefix . str_pad($lastNum + 1, $padLen, '0', STR_PAD_LEFT);

    $payload = [
        'treatment_code' => $trtCode,
        'patient_id'     => (int)$body['patient_id'],
        'branch_id'      => (int)$body['branch_id'],
        'treatment_date' => $body['treatment_date'] ?? date('Y-m-d'),
        'status'         => $body['status']         ?? 'pending',
        'created_at'     => date('c'),
        'updated_at'     => date('c'),
    ];

    if (!empty($body['dentist_id']))     $payload['dentist_id']      = (int)$body['dentist_id'];
    if (!empty($body['service_id']))     $payload['service_id']      = (int)$body['service_id'];
    if (!empty($body['appointment_id'])) $payload['appointment_id']  = (int)$body['appointment_id'];
    if (isset($body['tooth_number']))    $payload['tooth_number']    = $body['tooth_number']    ?: null;
    if (isset($body['procedure_notes'])) $payload['procedure_notes'] = $body['procedure_notes'] ?: null;
    if (isset($body['cost']))            $payload['cost']            = $body['cost'] !== '' ? (float)$body['cost'] : null;
    if (isset($body['current_stage']))   $payload['current_stage']   = $body['current_stage']   ?: null;

    $res = supabase_request('treatments', 'POST', $payload);

    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'message' => 'Treatment added successfully.', 'treatment_code' => $trtCode]);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to create treatment.']);
    }
    exit;
}

// ── PATCH — update ───────────────────────────────────────────
if ($method === 'PATCH') {
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Treatment ID required.']); exit; }

    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $payload = ['updated_at' => date('c')];

    if (array_key_exists('dentist_id', $body))      $payload['dentist_id']      = $body['dentist_id']      ? (int)$body['dentist_id']  : null;
    if (array_key_exists('service_id', $body))      $payload['service_id']      = $body['service_id']      ? (int)$body['service_id']  : null;
    if (array_key_exists('tooth_number', $body))    $payload['tooth_number']    = $body['tooth_number']    ?: null;
    if (array_key_exists('procedure_notes', $body)) $payload['procedure_notes'] = $body['procedure_notes'] ?: null;
    if (array_key_exists('cost', $body))            $payload['cost']            = $body['cost'] !== ''     ? (float)$body['cost']       : null;
    if (array_key_exists('current_stage', $body))   $payload['current_stage']   = $body['current_stage']   ?: null;
    if (array_key_exists('status', $body))          $payload['status']          = $body['status'];
    if (array_key_exists('treatment_date', $body))  $payload['treatment_date']  = $body['treatment_date'];

    $res = supabase_request('treatments', 'PATCH', $payload, 'treatment_id=eq.' . $id);

    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'message' => 'Treatment updated successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to update treatment.']);
    }
    exit;
}

// ── DELETE ───────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!isOwner() && !isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
    }
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Treatment ID required.']); exit; }

    $res = supabase_request('treatments', 'DELETE', [], 'treatment_id=eq.' . $id);

    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'message' => 'Treatment deleted successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to delete treatment.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed.']);