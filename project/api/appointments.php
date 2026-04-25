<?php
// =============================================================
//  api/appointments.php
//  GET  ?id=X   — fetch single appointment with service info
//  PATCH ?id=X  — update appointment status
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── GET ──────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Appointment ID required.']);
        exit;
    }
    $res = supabase_request('appointments', 'GET', [],
        'appointment_id=eq.' . $id .
        '&select=appointment_id,appointment_code,patient_id,branch_id,dentist_id,service_id,' .
        'appointment_date,appointment_time,appointment_type,notes,status,payment_status,' .
        'patients(full_name),branches(branch_name),services(service_id,service_name,base_price)' .
        '&limit=1'
    );
    if (!empty($res['data'][0])) {
        echo json_encode(['success' => true, 'data' => $res['data'][0]]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
    }
    exit;
}

// ── PATCH ────────────────────────────────────────────────────
if ($method === 'PATCH') {
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Appointment ID required.']);
        exit;
    }
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $status = trim($body['status'] ?? '');

    if (!in_array($status, ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid status.']);
        exit;
    }

    $res = supabase_request('appointments', 'PATCH',
        ['status' => $status, 'updated_at' => date('c')],
        'appointment_id=eq.' . $id
    );

    if ($res['status'] < 200 || $res['status'] >= 300) {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to update.']);
        exit;
    }

    // When checking in — auto-create treatment record if one doesn't exist yet
    if ($status === 'checked_in') {
        // Fetch the appointment details
        $apt = supabase_request('appointments', 'GET', [],
            'appointment_id=eq.' . $id .
            '&select=appointment_id,appointment_code,patient_id,branch_id,service_id,appointment_date&limit=1'
        );
        $aptData = $apt['data'][0] ?? null;

        if ($aptData) {
            // Check if a treatment already exists for this appointment
            $existing = supabase_request('treatments', 'GET', [],
                'appointment_id=eq.' . $id . '&select=treatment_id&limit=1'
            );

            if (empty($existing['data'])) {
                // Generate treatment code
                $lastTrt  = supabase_request('treatments', 'GET', [],
                    'select=treatment_code&order=treatment_code.desc&limit=1'
                );
                $lastCode = $lastTrt['data'][0]['treatment_code'] ?? 'TR-00000';
                $prefix   = str_contains($lastCode, 'TRT-') ? 'TRT-' : 'TR-';
                $padLen   = str_contains($lastCode, 'TRT-') ? 4 : 5;
                $lastNum  = (int)preg_replace('/[^0-9]/', '', $lastCode);
                $trtCode  = $prefix . str_pad($lastNum + 1, $padLen, '0', STR_PAD_LEFT);

                supabase_request('treatments', 'POST', [
                    'treatment_code'   => $trtCode,
                    'appointment_id'   => (int)$id,
                    'patient_id'       => (int)($aptData['patient_id']  ?? 0),
                    'branch_id'        => (int)($aptData['branch_id']   ?? 0),
                    'service_id'       => $aptData['service_id'] ? (int)$aptData['service_id'] : null,
                    'treatment_date'   => $aptData['appointment_date'] ?? date('Y-m-d'),
                    'status'           => 'pending',
                    'created_at'       => date('c'),
                    'updated_at'       => date('c'),
                ]);
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'Status updated successfully.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed.']);