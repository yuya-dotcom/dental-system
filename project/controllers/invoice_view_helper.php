<?php
// =============================================================
//  controllers/invoice_view_helper.php — one invoice for Billing Records
//  (View Details + Add Payment) and the discount-voucher rules.
//
//  WHY: opening an invoice used to cost 8+ Supabase round trips one after
//  another, in two chained PHP requests:
//    api/billing.php           invoice → appointment services → procedure
//                              lines → payments                       (4)
//    api/invoice_discounts.php invoice → applied vouchers → active
//                              vouchers → one usage count PER voucher (3+N)
//  On the host (no curl_multi, ~0.5–1.5 s per round trip) that was ~14 s.
//
//  NOW: two independent reads in ONE wave (admin_sb_many: parallel where
//  curl_multi exists, sequential otherwise):
//    1. the invoice with everything it owns embedded (patient, branch,
//       treatment + its procedure lines, appointment + its services,
//       payments, applied vouchers)
//    2. the active vouchers, each with its usage count embedded
//  The response shape of every existing key is unchanged.
// =============================================================

require_once __DIR__ . '/admin_batch.php';

const INVOICE_VIEW_SELECT =
    'invoice_id,invoice_code,invoice_date,total_amount,amount_paid,balance,payment_status,' .
    'treatment_id,appointment_id,patient_id,branch_id,created_at,updated_at,subtotal_amount,discount_amount,' .
    'patients(full_name,patient_code,contact_number),branches(branch_name),' .
    'treatments(treatment_code,procedure_notes,tooth_number,treatment_date,services(service_name),dentists(full_name),' .
        'treatment_procedures(procedure_id,status,tooth_number,surfaces,unit_price,services(service_name))),' .
    'appointments!invoices_appointment_id_fkey(appointment_code,appointment_date,appointment_time,' .
        'appointment_services(sort_order,services(service_name))),' .
    'payments(payment_id,amount,payment_method,payment_date,notes,recorded_by,created_at),' .
    'invoice_discounts(invoice_discount_id,voucher_id,voucher_code,voucher_name,discount_type,discount_value,discount_amount,applied_at)';

const INVOICE_VOUCHER_SELECT =
    'voucher_id,voucher_name,voucher_code,discount_type,discount_value,min_bill_amount,usage_limit,start_date,expiration_date,status';

function idc_ok(array $r): bool { return ($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300; }
function idc_peso(float $n): string { return '₱' . number_format($n, 2); }

/** Times a voucher has been used: the embedded invoice_discounts(count), or a lookup. */
function idc_used_count(array $v): int
{
    if (isset($v['invoice_discounts'][0]['count'])) return (int)$v['invoice_discounts'][0]['count'];
    $used = supabase_request('invoice_discounts', 'GET', [],
        'voucher_id=eq.' . (int)$v['voucher_id'] . '&select=invoice_discount_id');
    return count(idc_ok($used) && is_array($used['data']) ? $used['data'] : []);
}

// Why a voucher can't be applied right now ('' = it can)
function idc_ineligible(array $v, float $subtotal, array $appliedIds): string
{
    $today = date('Y-m-d');
    if (($v['status'] ?? '') !== 'active')                                  return 'This voucher is inactive.';
    if (in_array((int)$v['voucher_id'], $appliedIds, true))                  return 'This voucher is already applied to this invoice.';
    if (!empty($v['start_date']) && $today < $v['start_date'])              return 'This voucher starts on ' . $v['start_date'] . '.';
    if (!empty($v['expiration_date']) && $today > $v['expiration_date'])    return 'This voucher expired on ' . $v['expiration_date'] . '.';
    if ($v['min_bill_amount'] !== null && $subtotal < (float)$v['min_bill_amount']) {
        return 'This voucher needs a bill of at least ' . idc_peso((float)$v['min_bill_amount']) . '.';
    }
    if ($v['usage_limit'] !== null && idc_used_count($v) >= (int)$v['usage_limit']) {
        return 'This voucher has reached its usage limit.';
    }
    return '';
}

function idc_amount(array $v, float $subtotal): float
{
    return $v['discount_type'] === 'percentage'
        ? round($subtotal * (float)$v['discount_value'] / 100, 2)
        : round((float)$v['discount_value'], 2);
}

/** Subtotal / paid of an invoice row (before any discount, subtotal = total). */
function idc_amounts(array $inv): array
{
    $inv['subtotal'] = round((float)($inv['subtotal_amount'] ?? $inv['total_amount']), 2);
    $inv['paid']     = round((float)($inv['amount_paid'] ?? 0), 2);
    return $inv;
}

/**
 * The voucher block of an invoice (same keys api/invoice_discounts.php GET
 * has always returned, minus 'success').
 *   $inv      invoice row (needs total_amount, amount_paid, subtotal_amount)
 *   $applied  its applied vouchers, oldest first
 *   $vouchers active vouchers (with invoice_discounts(count) embedded)
 */
function idc_summary(array $inv, array $applied, array $vouchers): array
{
    $inv        = idc_amounts($inv);
    $appliedIds = array_map(fn($a) => (int)$a['voucher_id'], $applied);
    $discount   = round(array_sum(array_map(fn($a) => (float)$a['discount_amount'], $applied)), 2);

    $available = [];
    foreach ($vouchers as $v) {
        if (idc_ineligible($v, $inv['subtotal'], $appliedIds) !== '') continue;
        $available[] = [
            'voucher_id'      => (int)$v['voucher_id'],
            'voucher_code'    => $v['voucher_code'],
            'voucher_name'    => $v['voucher_name'],
            'discount_type'   => $v['discount_type'],
            'discount_value'  => (float)$v['discount_value'],
            'preview_amount'  => min(idc_amount($v, $inv['subtotal']), max(0, $inv['subtotal'] - $discount)),
            // Details for the picker's preview
            'min_bill_amount' => $v['min_bill_amount'] !== null ? (float)$v['min_bill_amount'] : null,
            'expiration_date' => $v['expiration_date'] ?? null,
            'usage_limit'     => $v['usage_limit'] !== null ? (int)$v['usage_limit'] : null,
        ];
    }

    return [
        'can_edit'  => isOwner() || isAdmin(),
        'subtotal'  => $inv['subtotal'],
        'discount'  => $discount,
        'total'     => round($inv['subtotal'] - $discount, 2),
        'paid'      => $inv['paid'],
        'applied'   => $applied,
        'available' => $available,
    ];
}

/** Active vouchers with their usage counts (one query). */
function idc_voucher_spec(): array
{
    return admin_q('discount_vouchers',
        'status=eq.active&select=' . INVOICE_VOUCHER_SELECT . ',invoice_discounts(count)&order=voucher_name.asc');
}

/** Sort rows by a key, keeping the original order for ties. */
function ivh_sort(array $rows, callable $key): array
{
    $i = 0;
    foreach ($rows as &$r) $r['__i'] = $i++;
    unset($r);
    usort($rows, fn($a, $b) => [$key($a), $a['__i']] <=> [$key($b), $b['__i']]);
    return array_map(function ($r) { unset($r['__i']); return $r; }, $rows);
}

/**
 * Loads one invoice for the invoice view in a single wave.
 * Returns null when the combined query is not available (e.g. a database
 * without the discount tables) — the caller then uses the per-table reads.
 * Otherwise: ['row' => ?array, 'vouchers' => ?array]
 * ('row' null = invoice not found; 'vouchers' null = voucher read failed).
 */
function invoice_view_fetch(int $invoiceId): ?array
{
    $res = admin_sb_many([
        'inv'      => admin_q('invoices', 'invoice_id=eq.' . $invoiceId . '&select=' . INVOICE_VIEW_SELECT . '&limit=1'),
        'vouchers' => idc_voucher_spec(),
    ]);
    if (!idc_ok($res['inv']) || !is_array($res['inv']['data'])) return null;
    return [
        'row'      => $res['inv']['data'][0] ?? null,
        'vouchers' => idc_ok($res['vouchers']) && is_array($res['vouchers']['data']) ? $res['vouchers']['data'] : null,
    ];
}

/**
 * Splits the combined invoice row into the response api/billing.php has
 * always sent (data / service_names / lines / payments) plus 'discounts'.
 */
function invoice_view_payload(array $row, ?array $vouchers): array
{
    // Services of the appointment, in booking order; else the legacy single service
    $svc = ivh_sort($row['appointments']['appointment_services'] ?? [], fn($s) => (int)($s['sort_order'] ?? 0));
    $serviceNames = [];
    foreach ($svc as $s) {
        $name = $s['services']['service_name'] ?? null;
        if ($name) $serviceNames[] = $name;
    }
    if (!$serviceNames && !empty($row['treatments']['services']['service_name'])) {
        $serviceNames[] = $row['treatments']['services']['service_name'];
    }

    // Itemized procedure lines saved on finish (completed only, in entry order)
    $lines = [];
    foreach (ivh_sort($row['treatments']['treatment_procedures'] ?? [], fn($l) => (int)($l['procedure_id'] ?? 0)) as $l) {
        if (($l['status'] ?? '') !== 'completed') continue;
        $lines[] = [
            'tooth_number' => $l['tooth_number'] ?? null,
            'surfaces'     => $l['surfaces'] ?? null,
            'unit_price'   => $l['unit_price'] ?? null,
            'services'     => $l['services'] ?? null,
        ];
    }

    $payments = ivh_sort($row['payments'] ?? [], fn($p) => (string)($p['payment_date'] ?? ''));
    $applied  = ivh_sort($row['invoice_discounts'] ?? [], fn($d) => (string)($d['applied_at'] ?? ''));

    $discounts = $vouchers === null ? null : idc_summary($row, $applied, $vouchers);

    // 'data' keeps its old shape: the embedded child lists move to their own keys
    unset($row['payments'], $row['invoice_discounts']);
    if (is_array($row['treatments'] ?? null))   unset($row['treatments']['treatment_procedures']);
    if (is_array($row['appointments'] ?? null)) unset($row['appointments']['appointment_services']);

    return [
        'success'       => true,
        'data'          => $row,
        'service_names' => $serviceNames,
        'lines'         => $lines,
        'payments'      => $payments,
        'discounts'     => $discounts,
    ];
}
