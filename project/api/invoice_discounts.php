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
require_once __DIR__ . '/../controllers/invoice_view_helper.php';   // idc_ok/idc_peso/idc_ineligible/idc_amount/idc_summary

header('Content-Type: application/json');
requireLogin();
requireStaffApi('owner', 'admin', 'dentist');   // staff only; non-owners are pinned to their branch

$method = $_SERVER['REQUEST_METHOD'];
// Read-only GET: release the session lock ($_SESSION stays readable) so the
// Add Payment wizard's invoice + voucher requests run side by side.
if ($method === 'GET' && session_status() === PHP_SESSION_ACTIVE) session_write_close();

function idc_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

function idc_invoice(int $invoiceId): array {
    $r = supabase_request('invoices', 'GET', [],
        'invoice_id=eq.' . $invoiceId .
        '&select=invoice_id,appointment_id,branch_id,total_amount,amount_paid,payment_status,subtotal_amount,discount_amount&limit=1');
    if (!idc_ok($r)) {
        idc_fail('Discounts are not set up yet — run database/2026-10-20_invoice_discounts.sql in Supabase.');
    }
    if (empty($r['data'][0])) idc_fail('Invoice not found.');
    $inv = $r['data'][0];
    // Every GET / apply / remove goes through here: check the invoice's real branch
    requireBranchAccess($inv['branch_id'] ?? null);
    // Before any discount, subtotal = total (also covers invoices created before the migration)
    return idc_amounts($inv);
}

function idc_applied(int $invoiceId): array {
    $r = supabase_request('invoice_discounts', 'GET', [],
        'invoice_id=eq.' . $invoiceId .
        '&select=invoice_discount_id,voucher_id,voucher_code,voucher_name,discount_type,discount_value,discount_amount,applied_at' .
        '&order=applied_at.asc');
    return idc_ok($r) && is_array($r['data']) ? $r['data'] : [];
}

// idc_ineligible() / idc_amount() / idc_summary(): controllers/invoice_view_helper.php

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
//  One wave: the invoice with its applied vouchers embedded + the active
//  vouchers with their usage counts (was 3 + one query per voucher, in a row).
if ($method === 'GET') {
    $invoiceId = (int)($_GET['invoice_id'] ?? 0);
    if (!$invoiceId) idc_fail('Invoice ID required.');
    $res = admin_sb_many([
        'inv' => admin_q('invoices', 'invoice_id=eq.' . $invoiceId .
            '&select=invoice_id,appointment_id,branch_id,total_amount,amount_paid,payment_status,subtotal_amount,discount_amount,' .
            'invoice_discounts(invoice_discount_id,voucher_id,voucher_code,voucher_name,discount_type,discount_value,discount_amount,applied_at)&limit=1'),
        'vouchers' => idc_voucher_spec(),
    ]);
    if (!idc_ok($res['inv'])) {
        idc_fail('Discounts are not set up yet — run database/2026-10-20_invoice_discounts.sql in Supabase.');
    }
    $inv = $res['inv']['data'][0] ?? null;
    if (!$inv) idc_fail('Invoice not found.');
    requireBranchAccess($inv['branch_id'] ?? null);   // the invoice's real branch, from the database
    $applied  = ivh_sort($inv['invoice_discounts'] ?? [], fn($d) => (string)($d['applied_at'] ?? ''));
    $vouchers = idc_ok($res['vouchers']) && is_array($res['vouchers']['data']) ? $res['vouchers']['data'] : [];

    echo json_encode(['success' => true] + idc_summary($inv, $applied, $vouchers));
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
        '&select=' . INVOICE_VOUCHER_SELECT . ',invoice_discounts(count)');
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
