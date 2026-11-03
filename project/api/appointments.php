<?php
// =============================================================
//  api/appointments.php
//  GET  ?id=X   — fetch single appointment with service info
//  PATCH ?id=X  — update appointment status
//  POST         — create appointment (admin side)
//  PUT  ?id=X   — update appointment (admin side)
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/audit_controller.php';
require_once __DIR__ . '/../controllers/notification_helper.php';

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
    $res = supabase_request(
        'appointments',
        'GET',
        [],
        'appointment_id=eq.' . $id .
            '&select=appointment_id,appointment_code,patient_id,account_id,branch_id,dentist_id,service_id,' .
            'appointment_date,appointment_time,appointment_type,notes,status,payment_status,' .
            'patients(patient_id,patient_code,full_name,contact_number),' .
            'patient_accounts(account_id,contact_number),' .
            'branches(branch_name),services(service_name),dentists(full_name)' .
            '&limit=1'
    );
    if (!empty($res['data'][0])) {
        $apt = $res['data'][0];

        // Resolve contact: prefer patients.contact_number, fallback to patient_accounts
        $contact = $apt['patients']['contact_number'] ?? null;
        // Strip placeholder contacts like __PORTAL_123__
        if (!$contact || preg_match('/^(__PORTAL_\d+__|PORTAL-\d+)$/', $contact)) {
            $contact = $apt['patient_accounts']['contact_number'] ?? '—';
        }
        $apt['resolved_contact'] = $contact;

        // Fetch all services from appointment_services junction table
        $svcRes = supabase_request(
            'appointment_services',
            'GET',
            [],
            'appointment_id=eq.' . $id .
                '&select=id,service_id,sort_order,services(service_id,service_name,base_price)' .
                '&order=sort_order.asc'
        );
        $apt['appointment_services'] = is_array($svcRes['data']) ? $svcRes['data'] : [];

        echo json_encode(['success' => true, 'data' => $apt]);
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

    $before = notif_appt_snapshot((int)$id);
    $res = supabase_request(
        'appointments',
        'PATCH',
        ['status' => $status, 'updated_at' => date('c')],
        'appointment_id=eq.' . $id
    );

    if ($res['status'] < 200 || $res['status'] >= 300) {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to update.']);
        exit;
    }
    notif_appointment($res['data'][0] ?? (int)$id, $before);

    // ── Audit log ────────────────────────────────────────────
    $info     = getAppointmentInfoForAudit($id);
    $oldLabel = ucfirst(str_replace('_', ' ', $info['old_status']));
    $newLabel = ucfirst(str_replace('_', ' ', $status));
    logActivity(
        'appointment-schedules',
        'UPDATE',
        $id,
        $info['patient_name'],
        "Status changed from {$oldLabel} to {$newLabel}"
    );

    // When checking in — auto-create treatment record if one doesn't exist yet
    if ($status === 'checked_in') {
        $apt = supabase_request(
            'appointments',
            'GET',
            [],
            'appointment_id=eq.' . $id .
                '&select=appointment_id,appointment_code,patient_id,branch_id,service_id,appointment_date&limit=1'
        );
        $aptData = $apt['data'][0] ?? null;

        if ($aptData) {
            $existing = supabase_request(
                'treatments',
                'GET',
                [],
                'appointment_id=eq.' . $id . '&select=treatment_id&limit=1'
            );

            if (empty($existing['data'])) {
                $lastTrt  = supabase_request('treatments', 'GET', [], 'select=treatment_code&order=treatment_code.desc&limit=1');
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

// ── POST (admin create) ──────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    _saveAppointment(null, $body);
    exit;
}

// ── PUT (admin update) ───────────────────────────────────────
if ($method === 'PUT') {
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Appointment ID required.']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    _saveAppointment($id, $body);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed.']);

// ── Shared create/update logic ───────────────────────────────
function _saveAppointment(?int $appointmentId, array $body): void
{
    // ── Parse & sanitise service_ids ─────────────────────────
    $rawServices = $body['service_ids'] ?? [];
    if (!is_array($rawServices)) {
        $rawServices = isset($body['service_id']) && $body['service_id'] !== ''
            ? [(int)$body['service_id']] : [];
    }
    $serviceIds = array_values(array_unique(
        array_filter(array_map('intval', $rawServices))
    ));
    if (count($serviceIds) > 3) $serviceIds = array_slice($serviceIds, 0, 3);

    $primaryServiceId = !empty($serviceIds) ? $serviceIds[0] : null;

    // ── Build core payload ────────────────────────────────────
    $payload = [
        'branch_id'        => (int)($body['branch_id']    ?? 0),
        'patient_id'       => (int)($body['patient_id']   ?? 0),
        'appointment_date' => trim($body['appointment_date'] ?? ''),
        'appointment_time' => trim($body['appointment_time'] ?? ''),
        'status'           => $body['status']           ?? 'pending',
        'payment_status'   => $body['payment_status']   ?? 'unpaid',
        'notes'            => $body['notes']            ?? null,
        'dentist_id'       => !empty($body['dentist_id'])  ? (int)$body['dentist_id']  : null,
        'service_id'       => $primaryServiceId,  // backward-compat column
        'updated_at'       => date('c'),
    ];

    // Edit form no longer sends status / payment / notes — keep the stored
    // values instead of resetting them to the create-time defaults.
    if ($appointmentId) {
        foreach (['status', 'payment_status', 'notes'] as $k) {
            if (!array_key_exists($k, $body)) unset($payload[$k]);
        }
    }

    // Validation
    $errors = [];
    if ($payload['branch_id'] < 1)                                             $errors[] = 'Please select a branch.';
    if ($payload['patient_id'] < 1)                                            $errors[] = 'Please select a patient.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['appointment_date']))   $errors[] = 'Invalid appointment date.';
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $payload['appointment_time'])) $errors[] = 'Invalid time format.';

    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        return;
    }

    // Normalise time to HH:MM:SS
    if (strlen($payload['appointment_time']) === 5) {
        $payload['appointment_time'] .= ':00';
    }

    // ── INSERT ────────────────────────────────────────────────
    if ($appointmentId === null) {
        // Generate code
        $lastRes  = supabase_request('appointments', 'GET', [], 'select=appointment_code&order=appointment_id.desc&limit=1');
        $lastCode = $lastRes['data'][0]['appointment_code'] ?? 'APT-00000';
        $lastNum  = (int)preg_replace('/[^0-9]/', '', $lastCode);
        $aptCode  = 'APT-' . str_pad($lastNum + 1, 5, '0', STR_PAD_LEFT);
        $payload['appointment_code'] = $aptCode;
        $payload['appointment_type'] = 'Consultation';

        $result = supabase_request('appointments', 'POST', $payload);
        if ($result['error'] || empty($result['data'][0]['appointment_id'])) {
            echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Failed to create appointment.']);
            return;
        }
        $appointmentId = (int)$result['data'][0]['appointment_id'];
        notif_appointment($result['data'][0]);

        // Audit
        if (function_exists('logActivity')) {
            // Resolve patient name for log
            $patNameRes  = supabase_request('patients', 'GET', [], 'patient_id=eq.' . (int)$payload['patient_id'] . '&select=full_name&limit=1');
            $patientName = $patNameRes['data'][0]['full_name'] ?? '';
            logActivity('appointment-schedules', 'INSERT', $appointmentId, $patientName, "Appointment {$aptCode} created");
        }
        $message = 'Appointment created successfully.';

    // ── UPDATE ────────────────────────────────────────────────
    } else {
        $before = notif_appt_snapshot($appointmentId);
        $result = supabase_request('appointments', 'PATCH', $payload, 'appointment_id=eq.' . $appointmentId);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Failed to update appointment.']);
            return;
        }
        notif_appointment($result['data'][0] ?? $appointmentId, $before);

        // Audit
        if (function_exists('logActivity') && function_exists('getAppointmentInfoForAudit')) {
            $info = getAppointmentInfoForAudit($appointmentId);
            logActivity('appointment-schedules', 'UPDATE', $appointmentId, $info['patient_name'], 'Appointment details updated');
        }
        $message = 'Appointment updated successfully.';
    }

    // ── Sync appointment_services ─────────────────────────────
    // Delete existing rows then re-insert (clean replace)
    supabase_request('appointment_services', 'DELETE', [], 'appointment_id=eq.' . $appointmentId);

    foreach ($serviceIds as $order => $sid) {
        supabase_request('appointment_services', 'POST', [
            'appointment_id' => $appointmentId,
            'service_id'     => $sid,
            'sort_order'     => $order + 1,
        ]);
    }

    echo json_encode([
        'success'          => true,
        'appointment_id'   => $appointmentId,
        'message'          => $message,
    ]);
}