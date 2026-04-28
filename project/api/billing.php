<?php
// =============================================================
//  api/billing.php
//  GET    ?id=X         — fetch single invoice with payments
//  POST                 — create invoice manually
//  PATCH  ?id=X         — update invoice
//  DELETE ?id=X         — delete invoice (owner only)
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── GET single invoice with payments ────────────────────────
if ($method === 'GET') {
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Invoice ID required.']); exit; }

    $inv = supabase_request('invoices', 'GET', [],
        'invoice_id=eq.' . $id .
        '&select=invoice_id,invoice_code,invoice_date,total_amount,amount_paid,' .
        'balance,payment_status,treatment_id,appointment_id,patient_id,branch_id,' .
        'patients(full_name),branches(branch_name),treatments(treatment_code,procedure_notes)' .
        '&limit=1'
    );

    if (empty($inv['data'][0])) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found.']); exit;
    }

    // Fetch payments for this invoice
    $pmts = supabase_request('payments', 'GET', [],
        'invoice_id=eq.' . $id .
        '&select=payment_id,amount,payment_method,payment_date,notes,recorded_by' .
        '&order=payment_date.asc'
    );

    echo json_encode([
        'success'  => true,
        'data'     => $inv['data'][0],
        'payments' => $pmts['data'] ?? [],
    ]);
    exit;
}

// ── POST — create invoice manually ──────────────────────────
if ($method === 'POST') {
    if (!isOwner() && !isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($body['patient_id']))   { echo json_encode(['success' => false, 'message' => 'Patient is required.']);      exit; }
    if (empty($body['total_amount'])) { echo json_encode(['success' => false, 'message' => 'Total amount is required.']); exit; }

    // Generate invoice code
    $last     = supabase_request('invoices', 'GET', [], 'select=invoice_code&order=invoice_code.desc&limit=1');
    $lastCode = $last['data'][0]['invoice_code'] ?? 'INV-0000';
    $lastNum  = (int)preg_replace('/[^0-9]/', '', $lastCode);
    $invCode  = 'INV-' . str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);

    $total   = (float)$body['total_amount'];
    $paid    = (float)($body['amount_paid'] ?? 0);
    $balance = max(0, $total - $paid);
    $status  = $paid <= 0 ? 'unpaid' : ($balance <= 0 ? 'paid' : 'partial');

    $payload = [
        'invoice_code'   => $invCode,
        'patient_id'     => (int)$body['patient_id'],
        'branch_id'      => $body['branch_id']      ? (int)$body['branch_id']      : null,
        'treatment_id'   => $body['treatment_id']   ? (int)$body['treatment_id']   : null,
        'appointment_id' => $body['appointment_id'] ? (int)$body['appointment_id'] : null,
        'total_amount'   => $total,
        'amount_paid'    => $paid,
        'payment_status' => $status,
        'invoice_date'   => $body['invoice_date'] ?? date('Y-m-d'),
        'created_at'     => date('c'),
        'updated_at'     => date('c'),
    ];

    $res = supabase_request('invoices', 'POST', $payload);

    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'message' => 'Invoice created.', 'invoice_code' => $invCode]);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to create invoice.']);
    }
    exit;
}

// ── PATCH — update invoice ───────────────────────────────────
if ($method === 'PATCH') {
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Invoice ID required.']); exit; }

    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $payload = ['updated_at' => date('c')];

    if (array_key_exists('payment_status', $body)) $payload['payment_status'] = $body['payment_status'];
    if (array_key_exists('amount_paid', $body))    $payload['amount_paid']    = (float)$body['amount_paid'];
    if (array_key_exists('balance', $body))        $payload['balance']        = (float)$body['balance'];
    if (array_key_exists('total_amount', $body))   $payload['total_amount']   = (float)$body['total_amount'];

    $res = supabase_request('invoices', 'PATCH', $payload, 'invoice_id=eq.' . $id);

    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'message' => 'Invoice updated.']);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to update invoice.']);
    }
    exit;
}

// ── DELETE ───────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!isOwner()) { echo json_encode(['success' => false, 'message' => 'Only owners can delete invoices.']); exit; }
    if (!$id)       { echo json_encode(['success' => false, 'message' => 'Invoice ID required.']);             exit; }

    $res = supabase_request('invoices', 'DELETE', [], 'invoice_id=eq.' . $id);

    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'message' => 'Invoice deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to delete invoice.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed.']);