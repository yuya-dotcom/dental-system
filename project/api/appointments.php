<?php
// =============================================================
//  api/appointments.php
//  GET  ?id=X   — fetch single appointment with service info
//  PATCH ?id=X  — update appointment status
//  POST         — create appointment (admin side)
//  PUT  ?id=X   — update appointment (admin side): owner / admin only, after
//                 re-entering their password (api/verify_staff_password.php)
//
//  POST and PUT check the time with the same rules as the Patient Portal
//  booking (controllers/branch_config.php → checkBookingSlot): branch hours
//  and open days, visit length from the services, no overlap with another
//  active booking (the appointment being edited doesn't block itself).
//
//  Branch: an admin's appointments always go to the admin's own branch (from
//  the session, never from the request); only the owner picks a branch.
//  Status: a new appointment is always CONFIRMED (payment stays 'unpaid' —
//  payments are recorded in Billing). PUT never changes status or payment.
//  Cancelling (PATCH status=cancelled) needs a reason and records who / when /
//  why (controllers/appointment_lifecycle.php → aptCancel).
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/audit_controller.php';
require_once __DIR__ . '/../controllers/notification_helper.php';
require_once __DIR__ . '/../controllers/branch_config.php';
require_once __DIR__ . '/../controllers/appointment_edit_grant.php';
require_once __DIR__ . '/../controllers/appointment_lifecycle.php';

header('Content-Type: application/json');
requireLogin();
requireStaffApi('owner', 'admin', 'dentist');   // staff only; non-owners are pinned to their branch

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Ended, never-checked-in appointments → no_show before anything is read or
// changed (throttled server-side; see appointment_lifecycle.php)
aptSweepNoShows();

// ── GET ──────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Appointment ID required.']);
        exit;
    }
    // Read-only from here: release the session lock so the page's other
    // requests (and a second modal open) are not queued behind this one.
    // $_SESSION stays readable for the branch check below.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    // ONE round trip: the services list (and, for Appointment Records'
    // ?with=records, the treatment and invoice) are embedded in the same
    // PostgREST query instead of being fetched one after another.
    $withRecords = ($_GET['with'] ?? '') === 'records';
    $select = 'appointment_id,appointment_code,patient_id,account_id,branch_id,dentist_id,service_id,' .
        'appointment_date,appointment_time,appointment_type,notes,status,payment_status,created_at,updated_at,' .
        'patients(patient_id,patient_code,full_name,contact_number' . ($withRecords ? ',date_of_birth:birthdate' : '') . '),' .
        'patient_accounts(account_id,contact_number' . ($withRecords ? ',email' : '') . '),' .
        'branches(branch_name,address,contact_number),services(service_name),dentists(full_name),' .
        'appointment_services(id,service_id,sort_order,services(service_id,service_name,base_price))';
    $query = '&appointment_services.order=sort_order.asc';
    if ($withRecords) {
        $select .= ',treatments(treatment_id,treatment_code,treatment_date,status,created_at,notes:procedure_notes,' .
                        'services(service_name),dentists(full_name))' .
                   ',invoices!invoices_appointment_id_fkey(invoice_id,invoice_code,invoice_date,created_at,' .
                        'total_amount,amount_paid,balance,payment_status,discount_amount,payments(payment_method,payment_date,payment_id))';
        $query .= '&treatments.order=created_at.desc&treatments.limit=1' .
                  '&invoices.order=created_at.desc&invoices.limit=1' .
                  '&invoices.payments.order=payment_date.desc,payment_id.desc&invoices.payments.limit=1';
    }
    // Cancellation details (Records → Cancellation Information). Before the
    // migration has run the columns don't exist: ask again without them.
    $withCancel = !aptCancelColsKnownMissing();
    $res = supabase_request('appointments', 'GET', [], 'appointment_id=eq.' . $id . '&select=' . $select .
        ($withCancel ? ',' . APT_CANCEL_COLS_SELECT : '') . $query . '&limit=1');
    if ($withCancel && aptIsMissingColumnError($res)) {
        aptCancelColsMarkMissing(true);
        $res = supabase_request('appointments', 'GET', [], 'appointment_id=eq.' . $id . '&select=' . $select . $query . '&limit=1');
    }

    if ($res['status'] < 200 || $res['status'] >= 300) {
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'Could not load the appointment. Please try again.']);
        exit;
    }
    if (!empty($res['data'][0])) {
        $apt = $res['data'][0];
        requireBranchAccess($apt['branch_id'] ?? null);   // the appointment's real branch

        // Resolve contact: prefer patients.contact_number, fallback to patient_accounts
        $contact = $apt['patients']['contact_number'] ?? null;
        // Strip placeholder contacts like __PORTAL_123__
        if (!$contact || preg_match('/^(__PORTAL_\d+__|PORTAL-\d+)$/', $contact)) {
            $contact = $apt['patient_accounts']['contact_number'] ?? '—';
        }
        $apt['resolved_contact'] = $contact;
        if (!is_array($apt['appointment_services'] ?? null)) $apt['appointment_services'] = [];

        if ($withRecords) {
            // The patient's e-mail lives on the portal account
            if (is_array($apt['patients'] ?? null)) $apt['patients']['email'] = $apt['patient_accounts']['email'] ?? null;
            // Latest treatment / invoice of this appointment (same choice as
            // api/billing.php ?appointment_id=): null when there is none yet
            $apt['treatment'] = $apt['treatments'][0] ?? null;
            $inv = $apt['invoices'][0] ?? null;
            if ($inv) {
                $pm = $inv['payments'][0]['payment_method'] ?? null;
                $inv['payment_method'] = $pm ? ucwords(str_replace('_', ' ', $pm)) : null;
                unset($inv['payments']);
            }
            $apt['invoice'] = $inv;
            unset($apt['treatments'], $apt['invoices']);
        }

        // Who / when / why — only for a cancelled appointment (else null)
        $apt['cancellation'] = aptCancellationInfo($apt);
        foreach (APT_CANCEL_COLS as $c) unset($apt[$c]);   // sent once, in 'cancellation'

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
    requireAppointmentBranch($id);
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $status = trim($body['status'] ?? '');

    if (!in_array($status, ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid status.']);
        exit;
    }
    // Completing goes through Active Treatments → Finish (api/finish_treatment.php),
    // which records the treatment, deducts stock and creates the invoice.
    if ($status === 'completed') {
        echo json_encode(['success' => false, 'message' => 'Appointments are completed by finishing the treatment in Active Treatments.']);
        exit;
    }

    // ── Cancel: reason required; who / when / why are recorded ──
    if ($status === 'cancelled') {
        [$reason, $rErr] = aptCleanCancelReason($body['reason'] ?? '');
        if ($rErr) {
            echo json_encode(['success' => false, 'code' => $rErr, 'message' => $rErr === 'REASON_REQUIRED'
                ? 'Please enter the reason for cancelling this appointment.'
                : 'Please keep the reason under ' . APT_CANCEL_REASON_MAX . ' characters.']);
            exit;
        }
        $info = getAppointmentInfoForAudit($id);
        $c = aptCancel($id, [
            'type'    => 'staff',
            'user_id' => (int)($_SESSION['user_id'] ?? 0),
            'name'    => (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Staff'),
            'role'    => (string)($_SESSION['role'] ?? ''),
        ], $reason, ['pending', 'confirmed', 'checked_in']);
        if (!$c['ok']) {
            echo json_encode(['success' => false, 'code' => $c['code'], 'message' => $c['message']]);
            exit;
        }
        $oldLabel = ucfirst(str_replace('_', ' ', $c['prev'] ?? $info['old_status']));
        logActivity('appointment-schedules', 'UPDATE', $id, $info['patient_name'],
            "Status changed from {$oldLabel} to Cancelled — Reason: {$reason}");
        echo json_encode(['success' => true, 'message' => 'The appointment has been cancelled.']);
        exit;
    }

    // Allowed moves (a cancelled, no-show or completed appointment is closed)
    $from = [
        'pending'    => ['pending', 'confirmed'],
        'confirmed'  => ['pending', 'confirmed'],
        'checked_in' => ['pending', 'confirmed', 'checked_in'],
    ][$status];

    $before = notif_appt_snapshot((int)$id);
    $res = supabase_request(
        'appointments',
        'PATCH',
        ['status' => $status, 'updated_at' => date('c')],
        'appointment_id=eq.' . $id . '&status=in.(' . implode(',', $from) . ')'
    );

    if ($res['status'] < 200 || $res['status'] >= 300) {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to update.']);
        exit;
    }
    if (empty($res['data'][0])) {
        $now = ucfirst(str_replace('_', ' ', (string)($before['status'] ?? 'changed')));
        echo json_encode(['success' => false, 'code' => 'NOT_ALLOWED',
            'message' => "This appointment is already {$now}, so its status can't be changed here. Please refresh the list."]);
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

                $newTrt = supabase_request('treatments', 'POST', [
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
                if (!empty($newTrt['data'][0]['treatment_id'])) {
                    require_once __DIR__ . '/../controllers/staff_notifications.php';
                    staffn_treatment((int)$newTrt['data'][0]['treatment_id'], null);   // staff bell: new treatment record
                }
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'Status updated successfully.']);
    exit;
}

// ── POST (admin create) ──────────────────────────────────────
if ($method === 'POST') {
    // Add Appointment is an owner / admin action (dentists don't get the button)
    if (!in_array($_SESSION['role'] ?? '', ['owner', 'admin'], true)) apiDeny(403, 'You are not allowed to add appointments.');
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
    // Edit Appointment is an owner / admin action (dentists don't get the menu item)
    if (!in_array($_SESSION['role'] ?? '', ['owner', 'admin'], true)) apiDeny(403, 'You are not allowed to edit appointments.');
    requireAppointmentBranch($id);   // may edit only an appointment of their own branch
    // Password re-entered for THIS appointment (not just a check in the browser)
    if (!aptEditHasGrant($id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'code' => 'REVERIFY',
            'message' => 'For your security, please confirm your password again before editing this appointment.']);
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

    // ── Branch: from the session for everyone but the owner ───
    // An admin never chooses (or switches) the branch: the request's branch_id
    // is ignored when absent and refused when it names another branch.
    if (isOwner()) {
        $branchId = (int)($body['branch_id'] ?? 0);
    } else {
        $branchId = getSessionBranch();
        if (isset($body['branch_id']) && $body['branch_id'] !== '' && $body['branch_id'] !== null
            && (int)$body['branch_id'] !== $branchId) {
            apiDeny(403, 'You can only manage appointments in your own branch.');
        }
    }

    // ── Build core payload ────────────────────────────────────
    $notes = trim((string)($body['notes'] ?? ''));
    $payload = [
        'branch_id'        => $branchId,
        'patient_id'       => (int)($body['patient_id']   ?? 0),
        'appointment_date' => trim($body['appointment_date'] ?? ''),
        'appointment_time' => trim($body['appointment_time'] ?? ''),
        'notes'            => $notes !== '' ? mb_substr($notes, 0, 1000) : null,
        'dentist_id'       => !empty($body['dentist_id'])  ? (int)$body['dentist_id']  : null,
        'service_id'       => $primaryServiceId,  // backward-compat column
        'updated_at'       => date('c'),
    ];

    // New appointments made by staff are CONFIRMED right away; payment is
    // recorded in Billing & Payments (never chosen here). Any status /
    // payment_status sent by the browser is ignored.
    if (!$appointmentId) {
        $payload['status']         = 'confirmed';
        $payload['payment_status'] = 'unpaid';
    }

    // Edit form doesn't send notes / dentist — keep the stored values instead
    // of resetting them (a dentist is never cleared by an edit that didn't ask
    // for it). Status and payment never change through an edit.
    if ($appointmentId) {
        foreach (['notes', 'dentist_id'] as $k) {
            if (!array_key_exists($k, $body)) unset($payload[$k]);
        }
    }

    // Validation
    $errors = [];
    if ($payload['branch_id'] < 1)                                             $errors[] = isOwner() ? 'Please select a branch.' : 'Your account has no branch assigned. Please contact the owner.';
    if ($payload['patient_id'] < 1)                                            $errors[] = 'Please select a patient.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['appointment_date']))   $errors[] = 'Invalid appointment date.';
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $payload['appointment_time'])) $errors[] = 'Invalid time format.';

    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        return;
    }

    // The (new) branch must be the user's own branch too (owner: any)
    if (!canAccessBranch($payload['branch_id'])) apiDeny(403, 'You can only manage appointments in your own branch.');

    // Normalise time to HH:MM:SS
    if (strlen($payload['appointment_time']) === 5) {
        $payload['appointment_time'] .= ':00';
    }

    // ── The stored row (edit) ─────────────────────────────────
    $current = null;
    $currentSvcs = [];
    if ($appointmentId) {
        $cur = supabase_request('appointments', 'GET', [], 'appointment_id=eq.' . $appointmentId .
            '&select=appointment_id,appointment_code,branch_id,patient_id,account_id,appointment_date,appointment_time,status,dentist_id,service_id&limit=1');
        $current = $cur['data'][0] ?? null;
        if (!$current) { echo json_encode(['success' => false, 'message' => 'Appointment not found.']); return; }
        if (!in_array($current['status'] ?? '', ['pending', 'confirmed'], true)) {
            echo json_encode(['success' => false, 'message' => 'Only pending or confirmed appointments can be edited.']);
            return;
        }
        $sv = supabase_request('appointment_services', 'GET', [], 'appointment_id=eq.' . $appointmentId .
            '&select=service_id,sort_order,services(service_name)&order=sort_order.asc');
        $currentSvcs = is_array($sv['data']) ? $sv['data'] : [];
    }

    // ── Availability: same rules as the Patient Portal booking ──
    // An edit that keeps its stored date and branch skips the "from tomorrow"
    // rule (it may already be today's appointment); the time must still fit.
    $keepsDay = $current
        && (int)$current['branch_id'] === $payload['branch_id']
        && (string)$current['appointment_date'] === $payload['appointment_date'];
    $slot = checkBookingSlot($payload['branch_id'], $payload['appointment_date'], $payload['appointment_time'],
        $serviceIds, (int)$appointmentId, !$keepsDay);
    if (!$slot['ok']) {
        echo json_encode(['success' => false, 'code' => 'SLOT_' . strtoupper($slot['code']), 'message' => $slot['message'],
            'field' => str_starts_with((string)$slot['code'], 'date') ? 'apt_date' : 'apt_time']);
        return;
    }
    $payload['duration_minutes'] = $slot['duration'];

    // ── Dentist (when one is chosen) must work at this branch ─
    if (!empty($payload['dentist_id'])) {
        $dr = supabase_request('dentists', 'GET', [], 'dentist_id=eq.' . (int)$payload['dentist_id'] . '&select=branch_id&limit=1');
        if ((int)($dr['data'][0]['branch_id'] ?? 0) !== $payload['branch_id']) {
            echo json_encode(['success' => false, 'message' => 'Please choose a dentist from this branch.', 'field' => 'apt_dentist']);
            return;
        }
    }

    // ── Patient Portal link ───────────────────────────────────
    // The portal shows a patient's appointments by patient (and its account):
    // store the patient's portal account on a new appointment, and move it
    // with the patient when an edit changes the patient, so the appointment is
    // visible to that patient only.
    if (!$current || (int)$current['patient_id'] !== $payload['patient_id']) {
        $payload['account_id'] = aptAccountForPatient($payload['patient_id']);
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

        $result = _aptWrite('POST', $payload);
        if (_aptIsSlotClash($result)) {
            echo json_encode(['success' => false, 'code' => 'SLOT_TAKEN', 'field' => 'apt_time', 'message' => 'Sorry, that time was just taken. Please pick another time.']);
            return;
        }
        if ($result['error'] || empty($result['data'][0]['appointment_id'])) {
            echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Failed to create appointment.']);
            return;
        }
        $appointmentId = (int)$result['data'][0]['appointment_id'];
        // Two overlapping bookings saved at the same moment: re-check, the earlier one keeps the time
        $re = checkBookingSlot($payload['branch_id'], $payload['appointment_date'], $payload['appointment_time'], $serviceIds, $appointmentId, false);
        if (!$re['ok'] && $re['code'] === 'taken') {
            supabase_request('appointments', 'DELETE', [], 'appointment_id=eq.' . $appointmentId);
            echo json_encode(['success' => false, 'code' => 'SLOT_TAKEN', 'field' => 'apt_time', 'message' => 'Sorry, that time was just taken. Please pick another time.']);
            return;
        }
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
        $result = _aptWrite('PATCH', $payload, 'appointment_id=eq.' . $appointmentId);
        if (_aptIsSlotClash($result)) {
            echo json_encode(['success' => false, 'code' => 'SLOT_TAKEN', 'field' => 'apt_time', 'message' => 'Sorry, that time was just taken. Please pick another time.']);
            return;
        }
        if ($result['status'] < 200 || $result['status'] >= 300) {
            echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Failed to update appointment.']);
            return;
        }
        // Someone booked an overlapping time at the same moment → put the old schedule back
        $re = checkBookingSlot($payload['branch_id'], $payload['appointment_date'], $payload['appointment_time'], $serviceIds, $appointmentId, false);
        if (!$re['ok'] && $re['code'] === 'taken') {
            supabase_request('appointments', 'PATCH', [
                'branch_id'        => (int)$current['branch_id'],
                'patient_id'       => (int)$current['patient_id'],
                'account_id'       => $current['account_id'] ?? null,
                'appointment_date' => $current['appointment_date'],
                'appointment_time' => $current['appointment_time'],
                'service_id'       => $current['service_id'],
                'updated_at'       => date('c'),
            ], 'appointment_id=eq.' . $appointmentId);
            echo json_encode(['success' => false, 'code' => 'SLOT_TAKEN', 'field' => 'apt_time', 'message' => 'Sorry, that time was just taken. Please pick another time.']);
            return;
        }
        notif_appointment($result['data'][0] ?? $appointmentId, $before);
        aptEditConsume($appointmentId);   // the next edit asks for the password again

        // Audit: who (from the session, see logActivity) and what changed
        if (function_exists('logActivity') && function_exists('getAppointmentInfoForAudit')) {
            $info = getAppointmentInfoForAudit($appointmentId);
            logActivity('appointment-schedules', 'UPDATE', $appointmentId, $info['patient_name'],
                _aptChangeSummary($current, $currentSvcs, $payload, $serviceIds));
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

// ── Write helpers ────────────────────────────────────────────
/** POST / PATCH an appointment; retries without duration_minutes if that column isn't there yet. */
function _aptWrite(string $method, array $payload, string $query = ''): array
{
    $res = supabase_request('appointments', $method, $payload, $query);
    $err = is_string($res['error'] ?? null) ? $res['error'] : json_encode($res['error'] ?? $res['data'] ?? '');
    if (($res['status'] ?? 0) >= 400 && str_contains((string)$err, 'duration_minutes')) {
        unset($payload['duration_minutes']);
        $res = supabase_request('appointments', $method, $payload, $query);
    }
    return $res;
}

/** The database's one-active-booking-per-start-time rule refused the write. */
function _aptIsSlotClash(array $res): bool
{
    if (($res['status'] ?? 0) < 400) return false;
    $err = is_string($res['error'] ?? null) ? $res['error'] : json_encode($res['error'] ?? $res['data'] ?? '');
    return str_contains((string)$err, '23505') || str_contains((string)$err, 'duplicate key') || str_contains((string)$err, 'unique constraint');
}

/** "Schedule: Nov 20, 2026 9:00 AM → Nov 21, 2026 10:00 AM · Services: A, B → A" (password re-verified). */
function _aptChangeSummary(array $cur, array $curSvcs, array $new, array $newSvcIds): string
{
    $fmt = function ($d, $t) {
        $ts = strtotime($d . ' ' . substr((string)$t, 0, 5));
        return $ts ? date('M j, Y g:i A', $ts) : trim($d . ' ' . $t);
    };
    $parts = [];
    $oldWhen = $fmt($cur['appointment_date'] ?? '', $cur['appointment_time'] ?? '');
    $newWhen = $fmt($new['appointment_date'], $new['appointment_time']);
    if ($oldWhen !== $newWhen) $parts[] = 'Schedule: ' . $oldWhen . ' → ' . $newWhen;

    if ((int)($cur['branch_id'] ?? 0) !== (int)$new['branch_id']) {
        $bn = fn($id) => getBookingBranch((int)$id)['branch_name'] ?? ('Branch #' . (int)$id);
        $parts[] = 'Branch: ' . $bn($cur['branch_id'] ?? 0) . ' → ' . $bn($new['branch_id']);
    }
    if ((int)($cur['patient_id'] ?? 0) !== (int)$new['patient_id']) {
        $pn = function ($id) {
            $r = supabase_request('patients', 'GET', [], 'patient_id=eq.' . (int)$id . '&select=full_name&limit=1');
            return $r['data'][0]['full_name'] ?? ('Patient #' . (int)$id);
        };
        $parts[] = 'Patient: ' . $pn($cur['patient_id'] ?? 0) . ' → ' . $pn($new['patient_id']);
    }
    $oldIds = array_map(fn($s) => (int)$s['service_id'], $curSvcs);
    if ($oldIds !== array_map('intval', $newSvcIds)) {
        $names = [];
        foreach ($curSvcs as $s) $names[(int)$s['service_id']] = $s['services']['service_name'] ?? ('#' . $s['service_id']);
        $missing = array_diff($newSvcIds, array_keys($names));
        if ($missing) {
            $r = supabase_request('services', 'GET', [], 'service_id=in.(' . implode(',', array_map('intval', $missing)) . ')&select=service_id,service_name');
            foreach (($r['data'] ?? []) as $s) $names[(int)$s['service_id']] = $s['service_name'];
        }
        $list = fn($ids) => $ids ? implode(', ', array_map(fn($i) => $names[$i] ?? ('#' . $i), $ids)) : 'none';
        $parts[] = 'Services: ' . $list($oldIds) . ' → ' . $list(array_map('intval', $newSvcIds));
    }
    $code = $cur['appointment_code'] ?? '';
    return 'Appointment ' . $code . ' updated' . ($parts ? ' — ' . implode(' · ', $parts) : ' (no schedule, patient or service change)')
        . ' (password re-verified)';
}
