<?php
// =============================================================
//  api/invoice_discounts.php — discount vouchers on an invoice (Billing)
//  GET    ?invoice_id=X  — applied vouchers, vouchers that can still be
//                          applied, and the invoice amounts
//  POST                  — apply a voucher {invoice_id, voucher_code}  (owner / admin)
//  DELETE ?id=X          — remove an applied voucher                   (owner / admin)
//
//  Several vouchers can be applied to one invoice (each voucher once).
//  Every discount is calculated on the invoice SUBTOTAL (the charge before
//  discounts); the total discount can't exceed the subtotal, and the new
//  total can't drop below what the patient has already paid.
//  Needs database/2026-10-20_invoice_discounts.sql.
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/audit_controller.php';

header('Content-Type: application/json');
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];

function idc_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}
function idc_ok(array $r): bool { return ($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300; }
function idc_peso(float $n): string { return '₱' . number_format($n, 2); }

function idc_invoice(int $invoiceId): array {
    $r = supabase_request('invoices', 'GET', [],
        'invoice_id=eq.' . $invoiceId .
        '&select=invoice_id,appointment_id,total_amount,amount_paid,payment_status,subtotal_amount,discount_amount&limit=1');
    if (!idc_ok($r)) {
        idc_fail('Discounts are not set up yet — run database/2026-10-20_invoice_discounts.sql in Supabase.');
    }
    if (empty($r['data'][0])) idc_fail('Invoice not found.');
    $inv = $r['data'][0];
    // Before any discount, subtotal = total (also covers invoices created before the migration)
    $inv['subtotal'] = round((float)($inv['subtotal_amount'] ?? $inv['total_amount']), 2);
    $inv['paid']     = round((float)($inv['amount_paid'] ?? 0), 2);
    return $inv;
}

function idc_applied(int $invoiceId): array {
    $r = supabase_request('invoice_discounts', 'GET', [],
        'invoice_id=eq.' . $invoiceId .
        '&select=invoice_discount_id,voucher_id,voucher_code,voucher_name,discount_type,discount_value,discount_amount,applied_at' .
        '&order=applied_at.asc');
    return idc_ok($r) && is_array($r['data']) ? $r['data'] : [];
}

// Why a voucher can't be applied right now ('' = it can)
function idc_ineligible(array $v, float $subtotal, array $appliedIds): string {
    $today = date('Y-m-d');
    if (($v['status'] ?? '') !== 'active')                                  return 'This voucher is inactive.';
    if (in_array((int)$v['voucher_id'], $appliedIds, true))                  return 'This voucher is already applied to this invoice.';
    if (!empty($v['start_date']) && $today < $v['start_date'])              return 'This voucher starts on ' . $v['start_date'] . '.';
    if (!empty($v['expiration_date']) && $today > $v['expiration_date'])    return 'This voucher expired on ' . $v['expiration_date'] . '.';
    if ($v['min_bill_amount'] !== null && $subtotal < (float)$v['min_bill_amount']) {
        return 'This voucher needs a bill of at least ' . idc_peso((float)$v['min_bill_amount']) . '.';
    }
    if ($v['usage_limit'] !== null) {
        $used = supabase_request('invoice_discounts', 'GET', [],
            'voucher_id=eq.' . (int)$v['voucher_id'] . '&select=invoice_discount_id');
        if (count(idc_ok($used) && is_array($used['data']) ? $used['data'] : []) >= (int)$v['usage_limit']) {
            return 'This voucher has reached its usage limit.';
        }
    }
    return '';
}

function idc_amount(array $v, float $subtotal): float {
    return $v['discount_type'] === 'percentage'
        ? round($subtotal * (float)$v['discount_value'] / 100, 2)
        : round((float)$v['discount_value'], 2);
}

// Re-total the invoice from its applied vouchers and sync statuses
function idc_retotal(array $inv): array {
    $applied  = idc_applied((int)$inv['invoice_id']);
    $discount = round(min($inv['subtotal'], array_sum(array_map(fn($a) => (float)$a['discount_amount'], $applied))), 2);
    $total    = round($inv['subtotal'] - $discount, 2);
    $paid     = $inv['paid'];
    $status   = $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');

    supabase_request('invoices', 'PATCH', [
        'subtotal_amount' => $inv['subtotal'],
        'discount_amount' => $discount,
        'total_amount'    => $total,
        'payment_status'  => $status,
        'updated_at'      => date('c'),
    ], 'invoice_id=eq.' . (int)$inv['invoice_id']);

    if (!empty($inv['appointment_id'])) {
        supabase_request('appointments', 'PATCH',
            ['payment_status' => $status, 'updated_at' => date('c')],
            'appointment_id=eq.' . (int)$inv['appointment_id']);
    }
    return ['subtotal' => $inv['subtotal'], 'discount' => $discount, 'total' => $total,
            'paid' => $paid, 'balance' => round(max(0, $total - $paid), 2), 'payment_status' => $status];
}

// ── GET ──────────────────────────────────────────────────────
if ($method === 'GET') {
    $invoiceId = (int)($_GET['invoice_id'] ?? 0);
    if (!$invoiceId) idc_fail('Invoice ID required.');
    $inv     = idc_invoice($invoiceId);
    $applied = idc_applied($invoiceId);
    $appliedIds = array_map(fn($a) => (int)$a['voucher_id'], $applied);
    $discount = round(array_sum(array_map(fn($a) => (float)$a['discount_amount'], $applied)), 2);

    // Active vouchers this invoice could still use (for the picker)
    $available = [];
    $vr = supabase_request('discount_vouchers', 'GET', [],
        'status=eq.active&select=voucher_id,voucher_name,voucher_code,discount_type,discount_value,min_bill_amount,usage_limit,start_date,expiration_date,status&order=voucher_name.asc');
    foreach ((idc_ok($vr) && is_array($vr['data']) ? $vr['data'] : []) as $v) {
        if (idc_ineligible($v, $inv['subtotal'], $appliedIds) !== '') continue;
        $available[] = [
            'voucher_id'     => (int)$v['voucher_id'],
            'voucher_code'   => $v['voucher_code'],
            'voucher_name'   => $v['voucher_name'],
            'discount_type'  => $v['discount_type'],
            'discount_value' => (float)$v['discount_value'],
            'preview_amount' => min(idc_amount($v, $inv['subtotal']), max(0, $inv['subtotal'] - $discount)),
            // Details for the picker's preview
            'min_bill_amount' => $v['min_bill_amount'] !== null ? (float)$v['min_bill_amount'] : null,
            'expiration_date' => $v['expiration_date'] ?? null,
            'usage_limit'     => $v['usage_limit'] !== null ? (int)$v['usage_limit'] : null,
        ];
    }

    echo json_encode([
        'success'   => true,
        'can_edit'  => isOwner() || isAdmin(),
        'subtotal'  => $inv['subtotal'],
        'discount'  => $discount,
        'total'     => round($inv['subtotal'] - $discount, 2),
        'paid'      => $inv['paid'],
        'applied'   => $applied,
        'available' => $available,
    ]);
    exit;
}

if (!isOwner() && !isAdmin()) idc_fail('Only owners and admins can apply or remove discounts.');

// ── POST — apply a voucher ───────────────────────────────────
if ($method === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $invoiceId = (int)($body['invoice_id'] ?? 0);
    $code      = strtoupper(trim((string)($body['voucher_code'] ?? '')));
    if (!$invoiceId) idc_fail('Invoice ID required.');
    if ($code === '') idc_fail('Enter or choose a voucher code.');

    $inv = idc_invoice($invoiceId);
    if ($inv['subtotal'] <= 0) idc_fail('This invoice has no charge to discount.');

    $vr = supabase_request('discount_vouchers', 'GET', [],
        'voucher_code=ilike.' . rawurlencode($code) .
        '&select=voucher_id,voucher_name,voucher_code,discount_type,discount_value,min_bill_amount,usage_limit,start_date,expiration_date,status');
    $voucher = null;
    foreach ((idc_ok($vr) && is_array($vr['data']) ? $vr['data'] : []) as $v) {
        if (strtoupper((string)$v['voucher_code']) === $code) { $voucher = $v; break; }
    }
    if (!$voucher) idc_fail('No voucher found with the code ' . $code . '.');

    $applied    = idc_applied($invoiceId);
    $appliedIds = array_map(fn($a) => (int)$a['voucher_id'], $applied);
    $why = idc_ineligible($voucher, $inv['subtotal'], $appliedIds);
    if ($why !== '') idc_fail($why);

    $already   = round(array_sum(array_map(fn($a) => (float)$a['discount_amount'], $applied)), 2);
    $remaining = round($inv['subtotal'] - $already, 2);
    if ($remaining <= 0) idc_fail('This invoice is already fully discounted.');
    $amount = min(idc_amount($voucher, $inv['subtotal']), $remaining);
    if ($amount <= 0) idc_fail('This voucher gives no discount on this invoice.');
    if ($inv['subtotal'] - $already - $amount < $inv['paid'] - 0.005) {
        idc_fail('This discount would make the total lower than the ' . idc_peso($inv['paid']) . ' already paid.');
    }

    $ins = supabase_request('invoice_discounts', 'POST', [
        'invoice_id'      => $invoiceId,
        'voucher_id'      => (int)$voucher['voucher_id'],
        'voucher_code'    => $voucher['voucher_code'],
        'voucher_name'    => $voucher['voucher_name'],
        'discount_type'   => $voucher['discount_type'],
        'discount_value'  => (float)$voucher['discount_value'],
        'discount_amount' => $amount,
        'applied_by'      => (int)($_SESSION['user_id'] ?? 0) ?: null,
    ]);
    $info = getInvoiceInfoForAudit($invoiceId);
    if (!idc_ok($ins)) {
        logBillingActivity('INSERT', $invoiceId, $info,
            'FAILED — voucher ' . $voucher['voucher_code'] . ' (−' . idc_peso($amount) . ') could not be applied: ' . ($ins['error'] ?? 'Unknown error'));
        idc_fail('Could not apply the voucher: ' . ($ins['error'] ?? 'Unknown error'));
    }

    $after = idc_retotal($inv);
    logBillingActivity('INSERT', $invoiceId, $info,
        'Voucher ' . $voucher['voucher_code'] . ' (' . ($voucher['voucher_name'] ?? '') . ') applied: −' . idc_peso($amount) . '. ' .
        'Total ' . idc_peso((float)$inv['total_amount']) . ' → ' . idc_peso($after['total']) .
        '; balance ' . idc_peso(max(0, (float)$inv['total_amount'] - $inv['paid'])) . ' → ' . idc_peso($after['balance']) .
        '; status ' . ($inv['payment_status'] ?? '—') . ' → ' . $after['payment_status'] . '.');

    echo json_encode(['success' => true, 'message' => $voucher['voucher_code'] . ' applied: −' . idc_peso($amount) . '.']
        + $after);
    exit;
}

// ── DELETE — remove an applied voucher ───────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) idc_fail('Discount ID required.');
    $row = supabase_request('invoice_discounts', 'GET', [], 'invoice_discount_id=eq.' . $id . '&select=invoice_id,voucher_code,discount_amount&limit=1');
    if (!idc_ok($row) || empty($row['data'][0])) idc_fail('Discount not found.');
    $d   = $row['data'][0];
    $inv = idc_invoice((int)$d['invoice_id']);
    $info = getInvoiceInfoForAudit((int)$d['invoice_id']);

    $del = supabase_request('invoice_discounts', 'DELETE', [], 'invoice_discount_id=eq.' . $id);
    if (!idc_ok($del)) {
        logBillingActivity('DELETE', (int)$d['invoice_id'], $info,
            'FAILED — voucher ' . $d['voucher_code'] . ' could not be removed: ' . ($del['error'] ?? 'Unknown error'));
        idc_fail('Could not remove the voucher: ' . ($del['error'] ?? 'Unknown error'));
    }

    $after = idc_retotal($inv);
    logBillingActivity('DELETE', (int)$d['invoice_id'], $info,
        'Voucher ' . $d['voucher_code'] . ' removed (+' . idc_peso((float)$d['discount_amount']) . '). ' .
        'Total ' . idc_peso((float)$inv['total_amount']) . ' → ' . idc_peso($after['total']) .
        '; balance ' . idc_peso(max(0, (float)$inv['total_amount'] - $inv['paid'])) . ' → ' . idc_peso($after['balance']) .
        '; status ' . ($inv['payment_status'] ?? '—') . ' → ' . $after['payment_status'] . '.');

    echo json_encode(['success' => true, 'message' => $d['voucher_code'] . ' removed.'] + $after);
    exit;
}

idc_fail('Method not allowed.');
