<?php
// =============================================================
//  api/portal_get_appointments.php
//  GET — fetch all appointments for the logged-in portal user,
//        including all services from appointment_services.
//  Used by portal-dashboard.php to render the appointments table
//  and the Appointment Details modal.
//
//  Extra data for the Appointment Details modal (each is a
//  separate, optional query — if one fails, the list still loads):
//    • appointment_type, created_at, updated_at, branch address
//    • services_total  — sum of services.base_price
//    • invoice         — latest invoice for the appointment, with
//                        the latest payment's payment_method
//    • patient         — the logged-in patient's details (top level)
// =============================================================

session_start();
header('Content-Type: application/json');

$accountId = $_SESSION['portal_account_id'] ?? null;
if (!$accountId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../dbconfig.php';

// Optional status filter
$status = trim($_GET['status'] ?? '');

$query = 'account_id=eq.' . (int)$accountId
    . '&select=appointment_id,appointment_code,appointment_date,appointment_time,'
    . 'appointment_type,status,payment_status,notes,service_id,created_at,updated_at,'
    . 'branches(branch_name,address),'
    . 'services(service_name,base_price),'
    . 'dentists(full_name)'
    . '&order=appointment_date.desc,appointment_time.desc'
    . '&limit=200';

if ($status && $status !== 'all') {
    $query .= '&status=eq.' . urlencode($status);
}

$res  = supabase_request('appointments', 'GET', [], $query);
$rows = is_array($res['data'] ?? null) ? $res['data'] : [];

if (empty($rows)) {
    echo json_encode(['success' => true, 'appointments' => [], 'patient' => null]);
    exit;
}

$aptIds = array_values(array_filter(array_map(fn($r) => (int)($r['appointment_id'] ?? 0), $rows)));
$idList = implode(',', $aptIds);

// ── Batch-fetch all services from junction table ──────────────
$svcMap   = [];   // appointment_id => [service_name, ...]
$svcTotal = [];   // appointment_id => sum of base_price

if ($aptIds) {
    $junctionRes = supabase_request(
        'appointment_services',
        'GET',
        [],
        'appointment_id=in.(' . $idList . ')'
        . '&select=appointment_id,sort_order,services(service_id,service_name,base_price)'
        . '&order=appointment_id.asc,sort_order.asc'
    );
    foreach (($junctionRes['data'] ?? []) as $row) {
        $aid  = (int)$row['appointment_id'];
        $name = $row['services']['service_name'] ?? null;
        if ($name) {
            $svcMap[$aid][]  = $name;
            $svcTotal[$aid]  = ($svcTotal[$aid] ?? 0) + (float)($row['services']['base_price'] ?? 0);
        }
    }
}

// ── Batch-fetch invoices (latest per appointment) ─────────────
$invMap = [];
if ($aptIds) {
    $invRes = supabase_request(
        'invoices',
        'GET',
        [],
        'appointment_id=in.(' . $idList . ')'
        . '&select=invoice_id,appointment_id,invoice_code,invoice_date,'
        . 'total_amount,amount_paid,balance,payment_status'
        . '&order=invoice_date.desc,invoice_id.desc'
    );
    foreach (($invRes['data'] ?? []) as $inv) {
        $aid = (int)($inv['appointment_id'] ?? 0);
        if ($aid && !isset($invMap[$aid])) {
            $inv['payment_method'] = null;
            $invMap[$aid] = $inv;
        }
    }
}

// ── Latest payment method per invoice ─────────────────────────
if ($invMap) {
    $invIds = array_map(fn($i) => (int)$i['invoice_id'], array_values($invMap));
    $payRes = supabase_request(
        'payments',
        'GET',
        [],
        'invoice_id=in.(' . implode(',', $invIds) . ')'
        . '&select=invoice_id,payment_method,payment_date'
        . '&order=payment_date.desc,payment_id.desc'
    );
    $methodByInvoice = [];
    foreach (($payRes['data'] ?? []) as $p) {
        $iid = (int)($p['invoice_id'] ?? 0);
        if ($iid && !isset($methodByInvoice[$iid]) && !empty($p['payment_method'])) {
            $methodByInvoice[$iid] = $p['payment_method'];
        }
    }
    foreach ($invMap as $aid => $inv) {
        $invMap[$aid]['payment_method'] = $methodByInvoice[(int)$inv['invoice_id']] ?? null;
    }
}

// ── Merge into each appointment row ───────────────────────────
foreach ($rows as &$apt) {
    $aid = (int)($apt['appointment_id'] ?? 0);

    // Prefer junction data; fall back to legacy service_id join
    if (!empty($svcMap[$aid])) {
        $apt['service_names']  = $svcMap[$aid];
        $apt['services_total'] = $svcTotal[$aid] ?? 0;
    } elseif (!empty($apt['services']['service_name'])) {
        $apt['service_names']  = [$apt['services']['service_name']];
        $apt['services_total'] = (float)($apt['services']['base_price'] ?? 0);
    } else {
        $apt['service_names']  = [];
        $apt['services_total'] = 0;
    }

    $apt['invoice'] = $invMap[$aid] ?? null;
}
unset($apt);

// ── Logged-in patient's details (for the details modal) ───────
$patient = null;
$accRes  = supabase_request(
    'patient_accounts',
    'GET',
    [],
    'account_id=eq.' . (int)$accountId
    . '&select=email,first_name,middle_name,last_name,suffix,birthdate,contact_number,patient_id&limit=1'
);
$acc = $accRes['data'][0] ?? null;

if ($acc) {
    $pat = [];
    if (!empty($acc['patient_id'])) {
        $pRes = supabase_request(
            'patients',
            'GET',
            [],
            'patient_id=eq.' . (int)$acc['patient_id']
            . '&select=patient_code,full_name,birthdate,contact_number&limit=1'
        );
        $pat = $pRes['data'][0] ?? [];
    }

    $contact = !empty($acc['contact_number']) ? $acc['contact_number'] : ($pat['contact_number'] ?? null);
    if ($contact && preg_match('/^(__PORTAL_\d+__|PORTAL-\d+)$/', $contact)) {
        $contact = null;   // placeholder, not a real number
    }

    $name = trim(implode(' ', array_filter([
        $acc['first_name']  ?? '',
        $acc['middle_name'] ?? '',
        $acc['last_name']   ?? '',
        $acc['suffix']      ?? '',
    ])));

    $patient = [
        'full_name'      => $name !== '' ? $name : ($pat['full_name'] ?? null),
        'patient_code'   => $pat['patient_code'] ?? null,
        'birthdate'      => !empty($acc['birthdate']) ? $acc['birthdate'] : ($pat['birthdate'] ?? null),
        'contact_number' => $contact,
        'email'          => $acc['email'] ?? null,
    ];
}

echo json_encode(['success' => true, 'appointments' => $rows, 'patient' => $patient]);