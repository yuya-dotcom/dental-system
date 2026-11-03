<?php
// =============================================================
//  api/billing.php
//  GET    ?id=X              — fetch single invoice with payments
//  GET    ?appointment_id=X  — the appointment's invoice (Appointment Records)
//  DELETE ?id=X              — delete invoice (owner only)
//  Invoices are created by api/finish_treatment.php (prices come from the
//  services); totals change only through payments and discount vouchers.
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/notification_helper.php';
require_once __DIR__ . '/../controllers/audit_controller.php';

header('Content-Type: application/json');
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── GET the invoice of an appointment (Appointment Records → Billing) ──
if ($method === 'GET' && !$id && isset($_GET['appointment_id'])) {
    $aptId = (int)$_GET['appointment_id'];
    if (!$aptId) { echo json_encode(['success' => false, 'message' => 'Appointment ID required.']); exit; }
    $cols = 'invoice_id,invoice_code,invoice_date,total_amount,amount_paid,balance,payment_status';
    $q    = 'appointment_id=eq.' . $aptId . '&order=created_at.desc&limit=1&select=';
    // discount_amount exists once database/2026-10-20_invoice_discounts.sql has been run
    $inv  = supabase_request('invoices', 'GET', [], $q . $cols . ',discount_amount');
    if ($inv['status'] < 200 || $inv['status'] >= 300) $inv = supabase_request('invoices', 'GET', [], $q . $cols);
    $row  = $inv['data'][0] ?? null;
    if (!$row) { echo json_encode(['success' => false, 'message' => 'No invoice for this appointment yet.']); exit; }
    // Method of the most recent payment, shown beside the payment status
    $pm = supabase_request('payments', 'GET', [],
        'invoice_id=eq.' . (int)$row['invoice_id'] . '&select=payment_method&order=payment_date.desc,payment_id.desc&limit=1');
    $method_ = $pm['data'][0]['payment_method'] ?? null;
    $row['payment_method'] = $method_ ? ucwords(str_replace('_', ' ', $method_)) : null;
    echo json_encode(['success' => true, 'data' => $row]);
    exit;
}

// ── GET single invoice with payments ────────────────────────
if ($method === 'GET') {
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Invoice ID required.']); exit; }

    $inv = supabase_request('invoices', 'GET', [],
        'invoice_id=eq.' . $id .
        '&select=invoice_id,invoice_code,invoice_date,total_amount,amount_paid,' .
        'balance,payment_status,treatment_id,appointment_id,patient_id,branch_id,created_at,updated_at,' .
        'patients(full_name,patient_code,contact_number),branches(branch_name),' .
        'treatments(treatment_code,procedure_notes,tooth_number,treatment_date,services(service_name),dentists(full_name)),' .
        'appointments!invoices_appointment_id_fkey(appointment_code,appointment_date,appointment_time)' .
        '&limit=1'
    );

    if (empty($inv['data'][0])) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found.']); exit;
    }

    $invoiceRow    = $inv['data'][0];
    $appointmentId = (int)($invoiceRow['appointment_id'] ?? 0);

    // Fetch all services for this appointment
    $serviceNames = [];
    if ($appointmentId) {
        $svcRes = supabase_request(
            'appointment_services', 'GET', [],
            'appointment_id=eq.' . $appointmentId .
            '&select=sort_order,services(service_name)' .
            '&order=sort_order.asc'
        );
        foreach (($svcRes['data'] ?? []) as $row) {
            $name = $row['services']['service_name'] ?? null;
            if ($name) $serviceNames[] = $name;
        }
    }

    // Fallback to legacy single service on treatment
    if (empty($serviceNames)) {
        $singleName = $invoiceRow['treatments']['services']['service_name'] ?? null;
        if ($singleName) $serviceNames[] = $singleName;
    }

    // Itemized procedure lines (saved on finish) for this invoice's treatment
    $lines = [];
    if (!empty($invoiceRow['treatment_id'])) {
        $lnRes = supabase_request('treatment_procedures', 'GET', [],
            'treatment_id=eq.' . (int)$invoiceRow['treatment_id'] . '&status=eq.completed'
            . '&select=tooth_number,surfaces,unit_price,services(service_name)&order=procedure_id.asc');
        $lines = is_array($lnRes['data']) ? $lnRes['data'] : [];
    }

    // Fetch payments for this invoice
    $pmts = supabase_request('payments', 'GET', [],
        'invoice_id=eq.' . $id .
        '&select=payment_id,amount,payment_method,payment_date,notes,recorded_by,created_at' .
        '&order=payment_date.asc'
    );

    // Single response — only one echo json_encode
    echo json_encode([
        'success'       => true,
        'data'          => $invoiceRow,
        'service_names' => $serviceNames,
        'lines'         => $lines,
        'payments'      => $pmts['data'] ?? [],
    ]);
    exit;
}

// ── DELETE ───────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!isOwner()) { echo json_encode(['success' => false, 'message' => 'Only owners can delete invoices.']); exit; }
    if (!$id)       { echo json_encode(['success' => false, 'message' => 'Invoice ID required.']);             exit; }

    $before = getInvoiceInfoForAudit($id); // read first: the row is gone afterwards
    $res = supabase_request('invoices', 'DELETE', [], 'invoice_id=eq.' . $id);
    $summary = $before
        ? 'Total ' . auditPeso($before['total_amount'] ?? 0) . ', paid ' . auditPeso($before['amount_paid'] ?? 0) .
          ', balance ' . auditPeso($before['balance'] ?? 0) . ', status ' . ($before['payment_status'] ?? '—') . '.'
        : '';

    if ($res['status'] >= 200 && $res['status'] < 300) {
        logBillingActivity('DELETE', $id, $before, trim('Invoice deleted. ' . $summary));
        echo json_encode(['success' => true, 'message' => 'Invoice deleted.']);
    } else {
        logBillingActivity('DELETE', $id, $before, 'FAILED — invoice could not be deleted: ' . ($res['error'] ?? 'Unknown error'));
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to delete invoice.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed.']);