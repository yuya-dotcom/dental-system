<?php
// =============================================================
//  api/payments.php
//  GET  ?invoice_id=X   — list payments for an invoice
//  POST                 — add a payment (admin only)
//  DELETE ?id=X         — remove a payment (owner only)
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/notification_helper.php';
require_once __DIR__ . '/../controllers/audit_controller.php';

header('Content-Type: application/json');
requireLogin();

$method    = $_SERVER['REQUEST_METHOD'];
$id        = isset($_GET['id'])         ? (int)$_GET['id']         : 0;
$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;

// ── GET ──────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!$invoiceId) { echo json_encode([]); exit; }

    $res = supabase_request('payments', 'GET', [],
        'invoice_id=eq.' . $invoiceId .
        '&select=payment_id,invoice_id,amount,payment_method,payment_date,notes,recorded_by' .
        '&order=payment_date.asc,payment_id.asc'
    );
    echo json_encode($res['data'] ?? []);
    exit;
}

// ── POST — add payment ───────────────────────────────────────
if ($method === 'POST') {
    if (!isOwner() && !isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Only admin or owner can record payments.']); exit;
    }

    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $invoiceId = (int)($body['invoice_id'] ?? 0);
    $amount    = (float)($body['amount']   ?? 0);
    $method_   = trim($body['payment_method'] ?? '');
    $date      = trim($body['payment_date']   ?? date('Y-m-d'));
    $notes     = trim($body['notes']          ?? '');
    $recBy     = trim($body['recorded_by']    ?? '');

    if (!$invoiceId)   { echo json_encode(['success' => false, 'message' => 'Invoice ID is required.']);        exit; }
    if ($amount <= 0)  { echo json_encode(['success' => false, 'message' => 'Payment amount must be > 0.']);    exit; }

    // Fetch current invoice to check balance
    $inv = supabase_request('invoices', 'GET', [],
        'invoice_id=eq.' . $invoiceId .
        '&select=invoice_id,total_amount,amount_paid,balance,payment_status&limit=1'
    );

    if (empty($inv['data'][0])) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found.']); exit;
    }

    $invoice    = $inv['data'][0];
    $total      = (float)$invoice['total_amount'];
    $alreadyPaid = (float)$invoice['amount_paid'];
    $balance    = (float)$invoice['balance'];

    // ── Overpayment guard ────────────────────────────────────
    if ($amount > $balance) {
        echo json_encode([
            'success' => false,
            'message' => "Payment of ₱" . number_format($amount, 2) .
                         " exceeds the remaining balance of ₱" . number_format($balance, 2) . ".",
        ]);
        exit;
    }

    // Save payment record
    $res = supabase_request('payments', 'POST', [
        'invoice_id'     => $invoiceId,
        'amount'         => $amount,
        'payment_method' => $method_  ?: null,
        'payment_date'   => $date,
        'notes'          => $notes    ?: null,
        'recorded_by'    => $recBy    ?: null,
        'created_at'     => date('c'),
    ]);

    $pmLabel = auditPeso($amount) . ($method_ ? ' (' . $method_ . ')' : '');
    if ($res['status'] < 200 || $res['status'] >= 300) {
        logBillingActivity('INSERT', $invoiceId, getInvoiceInfoForAudit($invoiceId),
            'FAILED — payment of ' . $pmLabel . ' could not be saved: ' . ($res['error'] ?? 'Unknown error'));
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to record payment.']); exit;
    }

    // Update invoice totals
    $newPaid    = round($alreadyPaid + $amount, 2);
    $newBalance = round(max(0, $total - $newPaid), 2);
    $newStatus  = $newBalance <= 0 ? 'paid' : 'partial';

    $invUpd = supabase_request('invoices', 'PATCH', [
        'amount_paid'    => $newPaid,
        'payment_status' => $newStatus,
        'updated_at'     => date('c'),
    ], 'invoice_id=eq.' . $invoiceId);

    // Notify only once the payment row AND invoice totals are both saved
    $invSaved = $invUpd['status'] >= 200 && $invUpd['status'] < 300;
    if ($invSaved && !empty($res['data'][0]['payment_id'])) {
        notif_payment_recorded((int)$res['data'][0]['payment_id'], $invoiceId, $amount, $newBalance);
    }

    $pmRef = !empty($res['data'][0]['payment_id']) ? ' [payment #' . (int)$res['data'][0]['payment_id'] . ']' : '';
    logBillingActivity('INSERT', $invoiceId, getInvoiceInfoForAudit($invoiceId),
        'Payment of ' . $pmLabel . ' recorded' . $pmRef . ($date ? ' dated ' . $date : '') . '. ' .
        ($invSaved
            ? 'Paid ' . auditPeso($alreadyPaid) . ' → ' . auditPeso($newPaid) .
              '; balance ' . auditPeso($balance) . ' → ' . auditPeso($newBalance) .
              '; status ' . ($invoice['payment_status'] ?? 'unpaid') . ' → ' . $newStatus . '.'
            : 'FAILED — invoice totals could not be updated: ' . ($invUpd['error'] ?? 'Unknown error')));

    // Sync appointment payment_status if invoice is linked to one
    $invFull = supabase_request('invoices', 'GET', [],
        'invoice_id=eq.' . $invoiceId . '&select=appointment_id&limit=1'
    );
    $apptId = $invFull['data'][0]['appointment_id'] ?? null;
    if ($apptId) {
        supabase_request('appointments', 'PATCH',
            ['payment_status' => $newStatus, 'updated_at' => date('c')],
            'appointment_id=eq.' . (int)$apptId
        );
    }

    echo json_encode([
        'success'     => true,
        'message'     => 'Payment recorded successfully.',
        'new_balance' => $newBalance,
        'new_status'  => $newStatus,
        'new_paid'    => $newPaid,
    ]);
    exit;
}

// ── DELETE — remove payment ──────────────────────────────────
if ($method === 'DELETE') {
    if (!isOwner()) { echo json_encode(['success' => false, 'message' => 'Only owners can remove payments.']); exit; }
    if (!$id)       { echo json_encode(['success' => false, 'message' => 'Payment ID required.']);             exit; }

    // Fetch payment first to reverse the invoice totals
    $pmt = supabase_request('payments', 'GET', [],
        'payment_id=eq.' . $id . '&select=payment_id,invoice_id,amount&limit=1'
    );

    if (empty($pmt['data'][0])) {
        echo json_encode(['success' => false, 'message' => 'Payment not found.']); exit;
    }

    $payment   = $pmt['data'][0];
    $invId     = (int)$payment['invoice_id'];
    $pmtAmount = (float)$payment['amount'];
    $before    = getInvoiceInfoForAudit($invId);

    // Delete the payment
    $del = supabase_request('payments', 'DELETE', [], 'payment_id=eq.' . $id);
    if ($del['status'] < 200 || $del['status'] >= 300) {
        logBillingActivity('DELETE', $invId, $before,
            'FAILED — payment #' . $id . ' of ' . auditPeso($pmtAmount) . ' could not be removed: ' . ($del['error'] ?? 'Unknown error'));
        echo json_encode(['success' => false, 'message' => $del['error'] ?? 'Failed to delete payment.']); exit;
    }

    // Reverse invoice totals (appointment_id is needed to sync the appointment's payment status)
    $inv = supabase_request('invoices', 'GET', [],
        'invoice_id=eq.' . $invId . '&select=total_amount,amount_paid,balance,appointment_id&limit=1'
    );
    if (!empty($inv['data'][0])) {
        $total      = (float)$inv['data'][0]['total_amount'];
        $newPaid    = round(max(0, (float)$inv['data'][0]['amount_paid'] - $pmtAmount), 2);
        $newBalance = round(max(0, $total - $newPaid), 2);
        $newStatus  = $newPaid <= 0 ? 'unpaid' : ($newBalance <= 0 ? 'paid' : 'partial');

        $invUpd = supabase_request('invoices', 'PATCH', [
            'amount_paid'    => $newPaid,
            'payment_status' => $newStatus,
            'updated_at'     => date('c'),
        ], 'invoice_id=eq.' . $invId);

        logBillingActivity('DELETE', $invId, $before,
            'Payment #' . $id . ' of ' . auditPeso($pmtAmount) . ' removed (reversal). ' .
            ($invUpd['status'] >= 200 && $invUpd['status'] < 300
                ? 'Paid ' . auditPeso($inv['data'][0]['amount_paid']) . ' → ' . auditPeso($newPaid) .
                  '; balance ' . auditPeso($before['balance'] ?? ($total - (float)$inv['data'][0]['amount_paid'])) . ' → ' . auditPeso($newBalance) .
                  '; status ' . ($before['payment_status'] ?? '—') . ' → ' . $newStatus . '.'
                : 'FAILED — invoice totals could not be updated: ' . ($invUpd['error'] ?? 'Unknown error')));
    } else {
        logBillingActivity('DELETE', $invId, $before,
            'Payment #' . $id . ' of ' . auditPeso($pmtAmount) . ' removed; the invoice could not be re-read to reverse its totals.');

        // Sync appointment payment_status
        $apptId = $inv['data'][0]['appointment_id'] ?? null;
        if ($apptId) {
            supabase_request('appointments', 'PATCH',
                ['payment_status' => $newStatus, 'updated_at' => date('c')],
                'appointment_id=eq.' . (int)$apptId
            );
        }
    }

    echo json_encode(['success' => true, 'message' => 'Payment removed and invoice updated.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed.']);