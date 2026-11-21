<?php
// =============================================================
//  controllers/dashboard_overview.php
//  Live figures for the admin Dashboard (api/dashboard_stats.php).
//
//  Every number comes from the database — nothing is hardcoded:
//    appointments  status + appointment_date of every appointment in scope
//    patients      created_at of every patient in scope
//    invoices      billed / collected / outstanding amounts
//    payments      the payment ledger (revenue = money actually received,
//                  dated by payment_date; branch via the paid invoice)
//    inventory     live stock vs. each item's own min_stock (same rule as
//                  the navbar bell; archived items excluded)
//    treatments    status of every treatment in scope
//    services      main service of every appointment (Top Services Booked)
//    branches      names, for Appointments by Branch (all-branches view only)
//
//  Requests: the base queries run in ONE parallel wave (admin_sb_many) with
//  only the columns needed; tables larger than one PostgREST page (1000
//  rows) are fetched in a second wave. On hosts without curl_multi they run
//  one after another, still ~8 requests in total.
//
//  Scope: $branch = 0 → all branches (owner only); N → that branch.
//  The caller must pass a value from scopedBranch(), never raw input.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/admin_batch.php';
require_once __DIR__ . '/appointment_services_helper.php';

const DASH_PAGE      = 1000;   // PostgREST max rows per request on Supabase
const DASH_MAX_PAGES = 20;     // safety cap: 20,000 rows per table
const DASH_MONTHS    = 6;      // trend window (this month + 5 before)

/** Local (Asia/Manila) Y-m-d of a date or timestamptz value; '' when empty. */
function dash_day($v): string
{
    if (!$v) return '';
    $s = (string)$v;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;   // plain date column
    $t = strtotime($s);
    return $t ? date('Y-m-d', $t) : '';
}

/**
 * The base queries for one scope. Each returns rows; 'today' is the only
 * list that is shown as-is.
 */
function dash_specs(int $branch, bool $split = false): array
{
    // $split (all branches only): also select each row's branch so dashboardOverviewSplit()
    // can compute every branch from the same rows. Without it the queries are unchanged.
    $split   = $split && $branch === 0;
    $bc      = $split ? ',branch_id' : '';
    $bf      = $branch === 0 ? '' : '&branch_id=eq.' . $branch;
    $today   = date('Y-m-d');
    $trendLo = date('Y-m-01', strtotime('first day of -' . (DASH_MONTHS - 1) . ' months'));
    $count   = ['Prefer: count=exact'];

    // Payments carry no branch: scope them through the invoice they paid
    $paySel = $branch === 0
        ? 'select=payment_id,amount,payment_date,created_at' . ($split ? ',invoices(branch_id)' : '')
        : 'select=payment_id,amount,payment_date,created_at,invoices!inner(branch_id)&invoices.branch_id=eq.' . $branch;

    $specs = [
        'appointments' => admin_q('appointments', 'select=appointment_id,status,appointment_date,branch_id,services(service_name)' . $bf . '&order=appointment_id.asc', $count),
        'patients'     => admin_q('patients', 'select=patient_id,created_at' . $bc . $bf . '&order=patient_id.asc', $count),
        'invoices'     => admin_q('invoices', 'select=invoice_id,total_amount,amount_paid,balance,payment_status' . $bc . $bf . '&order=invoice_id.asc', $count),
        'payments'     => admin_q('payments', $paySel . '&payment_date=gte.' . $trendLo . '&order=payment_id.asc', $count),
        'inventory'    => admin_q('inventory', 'select=item_id,item_code,item_name,stock_quantity,min_stock,branches(branch_name)' . $bc . '&archived_at=is.null' . $bf . '&order=item_id.asc', $count),
        'treatments'   => admin_q('treatments', 'select=treatment_id,status' . $bc . $bf . '&order=treatment_id.asc', $count),
        'today'        => admin_q('appointments', implode('&', [
            'select=appointment_id,patient_id,appointment_code,appointment_time,status,payment_status,'
                . 'patients(full_name),services(service_name),branches(branch_name)'
                // split: every branch's list comes from these rows, so take all of today's
                // (10 per branch after splitting) with their services embedded (no extra request)
                . ($split ? ',branch_id,appointment_services(sort_order,services(service_name))' : ''),
            'appointment_date=eq.' . $today . $bf,
            'status=neq.cancelled',
            'order=appointment_time.asc',
            'limit=' . ($split ? DASH_PAGE : 10),
        ])),
    ];
    // All-branches view: branch names for "Appointments by Branch"
    if ($branch === 0) $specs['branches'] = admin_q('branches', 'select=branch_id,branch_name&order=branch_id.asc', $count);

    // Inventory enhancement (after its migration): expiry, stock changes, replenishment
    require_once __DIR__ . '/inventory_v2.php';
    if (inv_v2_ready()) {
        $since = date('Y-m-d', strtotime('-29 days'));
        $specs['inv_batches'] = admin_q('inventory_batches', 'select=item_id,expiry_date,quantity_remaining,inventory!inner(item_name,archived_at,branches(branch_name))' . $bc
            . '&quantity_remaining=gt.0&expiry_date=lte.' . date('Y-m-d', strtotime('+' . INV_NEAR_EXPIRY_DAYS . ' days'))
            . '&inventory.archived_at=is.null' . $bf . '&order=expiry_date.asc', $count);
        $specs['inv_moves']   = admin_q('inventory_movements', 'select=movement_type,quantity_change' . $bc . '&movement_type=in.(purchase,transfer_in,transfer_out,adjustment,damaged,expired,wastage,stock_in,stock_out)'
            . '&movement_date=gte.' . $since . $bf . '&order=movement_id.asc', $count);
        $specs['inv_pos']     = admin_q('purchase_orders', 'select=po_id,status,expected_date,purchase_order_items(quantity_ordered,quantity_received)' . $bc . '&status=in.(ordered,partially_received)' . $bf . '&order=po_id.asc', $count);
        $trfScope = $branch === 0 ? '' : '&or=(from_branch_id.eq.' . $branch . ',to_branch_id.eq.' . $branch . ')';
        $specs['inv_trf']     = admin_q('stock_transfers', 'select=transfer_id,status' . ($split ? ',from_branch_id,to_branch_id' : '') . '&status=in.(pending,approved,in_transit)' . $trfScope . '&order=transfer_id.asc', $count);
    }
    return $specs;
}

/**
 * Runs the specs and returns [key => ['rows' => [...], 'error' => ?string]].
 * Paged tables get their remaining pages in a second parallel wave.
 */
function dash_fetch(array $specs): array
{
    $first = [];
    foreach ($specs as $k => $s) {
        $first[$k] = $k === 'today' ? $s : admin_q($s['table'], $s['query'] . '&limit=' . DASH_PAGE . '&offset=0', $s['headers']);
    }
    $res = admin_sb_many($first);

    $out = []; $more = [];
    foreach ($res as $k => $r) {
        $ok = empty($r['error']) && ($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300 && is_array($r['data']);
        $out[$k] = ['rows' => $ok ? $r['data'] : [], 'error' => $ok ? null : ('Query failed (' . ($r['status'] ?? 0) . ')')];
        if (!$ok || $k === 'today') continue;
        $total = admin_count($r);
        for ($p = 1; $p < DASH_MAX_PAGES && $p * DASH_PAGE < $total; $p++) {
            $s = $specs[$k];
            $more[$k . '#' . $p] = admin_q($s['table'], $s['query'] . '&limit=' . DASH_PAGE . '&offset=' . ($p * DASH_PAGE));
        }
    }
    if ($more) {
        foreach (admin_sb_many($more) as $key => $r) {
            [$k] = explode('#', $key);
            if (!empty($r['error']) || !is_array($r['data'])) { $out[$k]['error'] = 'Query failed (' . ($r['status'] ?? 0) . ')'; continue; }
            array_push($out[$k]['rows'], ...$r['data']);
        }
    }
    return $out;
}

/** All dashboard figures for one branch scope (0 = all branches). */
function dashboardOverview(int $branch): array
{
    return dash_compute(dash_fetch(dash_specs($branch)), $branch);
}

/**
 * Figures for ALL branches AND each branch in $branchIds from ONE set of
 * queries (Analytics compares the clinics: ~12 requests instead of ~12 per
 * clinic). Each branch gets exactly the rows its own query would return —
 * the same filter, applied in PHP to the all-branches rows:
 *   branch_id = N  (payments: the paid invoice's branch; transfers: from OR to N)
 * $branchIds null = every branch. Returns [0 => all branches, N => branch N, ...];
 * each branch's meta.branch_name comes from the same branch list.
 */
function dashboardOverviewSplit(?array $branchIds = null): array
{
    $all = dash_fetch(dash_specs(0, true));
    $names = [];
    foreach ($all['branches']['rows'] ?? [] as $br) $names[(int)$br['branch_id']] = (string)($br['branch_name'] ?? '');
    $branchIds ??= array_keys($names);
    $top = $all;
    $top['today']['rows'] = array_slice($top['today']['rows'], 0, 10);   // all-branches list: first 10, like its own query
    $out = [0 => dash_compute($top, 0)];
    foreach ($branchIds as $b) {
        $b = (int)$b;
        if ($b <= 0) continue;
        $r = [];
        foreach ($all as $k => $set) {
            if ($k === 'branches') continue;   // branch names: all-branches view only
            $r[$k] = ['rows' => array_values(array_filter($set['rows'], fn($row) => dash_row_in_branch($k, $row, $b))), 'error' => $set['error']];
        }
        $r['today']['rows'] = array_slice($r['today']['rows'], 0, 10);
        $out[$b] = dash_compute($r, $b);
        $out[$b]['meta']['branch_name'] = $names[$b] ?? ('Branch #' . $b);
    }
    return $out;
}

/** Does row $row of result set $k belong to branch $b (the same rule as that set's branch filter)? */
function dash_row_in_branch(string $k, $row, int $b): bool
{
    if (!is_array($row)) return false;
    if ($k === 'payments') return (int)($row['invoices']['branch_id'] ?? 0) === $b;   // invoices!inner(branch_id)
    if ($k === 'inv_trf')  return (int)($row['from_branch_id'] ?? 0) === $b || (int)($row['to_branch_id'] ?? 0) === $b;
    return (int)($row['branch_id'] ?? 0) === $b;
}

/** The figures for one scope, from fetched rows (dash_fetch() shape). */
function dash_compute(array $r, int $branch): array
{

    $today      = date('Y-m-d');
    $weekStart  = date('Y-m-d', strtotime('monday this week'));
    $monthStart = date('Y-m-01');
    $months = [];
    for ($i = DASH_MONTHS - 1; $i >= 0; $i--) {
        $ts = strtotime('first day of -' . $i . ' months');
        $months[date('Y-m', $ts)] = date('M Y', $ts);
    }

    // ── Appointments ─────────────────────────────────────────
    $apt = ['total' => 0, 'today' => 0, 'upcoming' => 0, 'pending' => 0, 'confirmed' => 0,
            'checked_in' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0];
    $aptTrend = array_fill_keys(array_keys($months), ['total' => 0, 'completed' => 0, 'cancelled' => 0]);
    $svcCounts = []; $perBranch = [];
    foreach ($r['appointments']['rows'] as $a) {
        $st = strtolower((string)($a['status'] ?? ''));
        $d  = (string)($a['appointment_date'] ?? '');
        $apt['total']++;
        $svc = $a['services']['service_name'] ?? null;   // the appointment's main service
        if ($svc !== null && $svc !== '') $svcCounts[$svc] = ($svcCounts[$svc] ?? 0) + 1;
        $bid = (int)($a['branch_id'] ?? 0);
        $perBranch[$bid] = ($perBranch[$bid] ?? 0) + 1;
        if (isset($apt[$st]) && $st !== 'total') $apt[$st]++;
        if ($d === $today && $st !== 'cancelled') $apt['today']++;
        if ($d >= $today && in_array($st, ['pending', 'confirmed'], true)) $apt['upcoming']++;
        $m = substr($d, 0, 7);
        if (isset($aptTrend[$m])) {
            $aptTrend[$m]['total']++;
            if ($st === 'completed') $aptTrend[$m]['completed']++;
            if ($st === 'cancelled') $aptTrend[$m]['cancelled']++;
        }
    }

    arsort($svcCounts);
    $topServices = [];
    foreach (array_slice($svcCounts, 0, 6, true) as $name => $n) $topServices[] = ['name' => (string)$name, 'count' => $n];

    // ── Appointments by branch (all-branches view only) ──────
    $byBranch = [];
    if ($branch === 0) {
        foreach ($r['branches']['rows'] ?? [] as $b) {
            $byBranch[] = ['name' => (string)($b['branch_name'] ?? ''), 'count' => $perBranch[(int)$b['branch_id']] ?? 0];
        }
        usort($byBranch, fn($x, $y) => $y['count'] <=> $x['count']);
    }

    // ── Patients ─────────────────────────────────────────────
    $pat = ['total' => 0, 'new_month' => 0, 'new_week' => 0, 'new_today' => 0];
    $patTrend = array_fill_keys(array_keys($months), 0);
    foreach ($r['patients']['rows'] as $p) {
        $d = dash_day($p['created_at'] ?? null);
        $pat['total']++;
        if ($d >= $monthStart) $pat['new_month']++;
        if ($d >= $weekStart)  $pat['new_week']++;
        if ($d === $today)     $pat['new_today']++;
        $m = substr($d, 0, 7);
        if (isset($patTrend[$m])) $patTrend[$m]++;
    }

    // ── Sales (invoices) ─────────────────────────────────────
    $sales = ['invoices' => 0, 'billed' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0,
              'paid' => 0, 'partial' => 0, 'unpaid' => 0];
    foreach ($r['invoices']['rows'] as $inv) {
        $total = (float)($inv['total_amount'] ?? 0);
        $paid  = (float)($inv['amount_paid'] ?? 0);
        $bal   = isset($inv['balance']) ? (float)$inv['balance'] : max(0, $total - $paid);
        $sales['invoices']++;
        $sales['billed']      += $total;
        $sales['collected']   += $paid;
        $sales['outstanding'] += max(0, $bal);
        $ps = strtolower((string)($inv['payment_status'] ?? 'unpaid'));
        if (isset($sales[$ps]) && in_array($ps, ['paid', 'partial', 'unpaid'], true)) $sales[$ps]++;
    }

    // ── Revenue (payments received) ──────────────────────────
    $rev = ['today' => 0.0, 'week' => 0.0, 'month' => 0.0, 'payments_month' => 0];
    $revTrend = array_fill_keys(array_keys($months), 0.0);
    foreach ($r['payments']['rows'] as $pm) {
        $amt = (float)($pm['amount'] ?? 0);
        $d   = dash_day($pm['payment_date'] ?? null) ?: dash_day($pm['created_at'] ?? null);
        if ($d === $today)     $rev['today'] += $amt;
        if ($d >= $weekStart)  $rev['week']  += $amt;
        if ($d >= $monthStart) { $rev['month'] += $amt; $rev['payments_month']++; }
        $m = substr($d, 0, 7);
        if (isset($revTrend[$m])) $revTrend[$m] += $amt;
    }

    // ── Inventory (live quantity vs. min_stock) ──────────────
    $inv = ['total' => 0, 'in_stock' => 0, 'low' => 0, 'out' => 0];
    $alerts = [];
    foreach ($r['inventory']['rows'] as $it) {
        $q = (int)($it['stock_quantity'] ?? 0); $min = (int)($it['min_stock'] ?? 0);
        $inv['total']++;
        if ($q <= 0)        { $inv['out']++; $lvl = 'out'; }
        elseif ($q <= $min) { $inv['low']++; $lvl = 'low'; }
        else                { $inv['in_stock']++; continue; }
        $alerts[] = [
            'item_id' => (int)$it['item_id'], 'code' => (string)($it['item_code'] ?? ''), 'name' => (string)($it['item_name'] ?? ''),
            'qty' => $q, 'min' => $min, 'level' => $lvl, 'branch' => (string)($it['branches']['branch_name'] ?? ''),
        ];
    }
    usort($alerts, fn($a, $b) => [$a['level'] === 'out' ? 0 : 1, $a['qty'], $a['name']] <=> [$b['level'] === 'out' ? 0 : 1, $b['qty'], $b['name']]);

    // ── Inventory: expiry, stock changes (30 days), replenishment ─
    // null when the inventory enhancement migration has not run yet.
    $invExtra = null;
    if (isset($r['inv_batches'])) {
        $today = date('Y-m-d');
        $exp = ['expired_units' => 0, 'soon_units' => 0, 'items' => []];
        foreach ($r['inv_batches']['rows'] as $b) {
            $id = (int)$b['item_id']; $q = (int)$b['quantity_remaining']; $isExp = $b['expiry_date'] < $today;
            $exp[$isExp ? 'expired_units' : 'soon_units'] += $q;
            $exp['items'][$id] ??= ['name' => (string)($b['inventory']['item_name'] ?? ''), 'branch' => (string)($b['inventory']['branches']['branch_name'] ?? ''),
                                    'expired' => 0, 'soon' => 0, 'next' => $b['expiry_date']];
            $exp['items'][$id][$isExp ? 'expired' : 'soon'] += $q;
        }
        $expItems = array_values($exp['items']);
        usort($expItems, fn($a, $b) => [$b['expired'] > 0, $a['next']] <=> [$a['expired'] > 0, $b['next']]);
        $chg = ['purchased' => 0, 'transfer_in' => 0, 'transfer_out' => 0, 'written_off' => 0, 'adjusted' => 0, 'manual_in' => 0, 'manual_out' => 0,
                'by_type' => ['damaged' => 0, 'expired' => 0, 'wastage' => 0]];
        foreach ($r['inv_moves']['rows'] as $m) {
            $q = (int)$m['quantity_change'];
            switch ($m['movement_type']) {
                case 'purchase':     $chg['purchased'] += $q; break;
                case 'transfer_in':  $chg['transfer_in'] += $q; break;
                case 'transfer_out': $chg['transfer_out'] += -$q; break;
                case 'adjustment':   $chg['adjusted'] += $q; break;
                case 'stock_in':     $chg['manual_in'] += $q; break;
                case 'stock_out':    $chg['manual_out'] += -$q; break;
                default:             $chg['written_off'] += -$q; $chg['by_type'][$m['movement_type']] += -$q;
            }
        }
        $po = ['open' => 0, 'units_due' => 0, 'overdue' => 0];
        foreach ($r['inv_pos']['rows'] as $o) {
            $po['open']++;
            foreach ($o['purchase_order_items'] ?? [] as $l) $po['units_due'] += max(0, (int)$l['quantity_ordered'] - (int)$l['quantity_received']);
            if (!empty($o['expected_date']) && $o['expected_date'] < $today) $po['overdue']++;
        }
        $trf = ['pending' => 0, 'approved' => 0, 'in_transit' => 0];
        foreach ($r['inv_trf']['rows'] as $t) $trf[$t['status']] = ($trf[$t['status']] ?? 0) + 1;
        $invExtra = [
            'expiry' => ['expired_units' => $exp['expired_units'], 'soon_units' => $exp['soon_units'], 'items' => array_slice($expItems, 0, 8),
                         'item_count' => count($expItems), 'days' => INV_NEAR_EXPIRY_DAYS],
            'changes' => $chg, 'orders' => $po, 'transfers' => $trf,
        ];
        foreach (['inv_batches', 'inv_moves', 'inv_pos', 'inv_trf'] as $k) if (!empty($r[$k]['error'])) $invExtra['error'] = true;
    }

    // ── Treatments ───────────────────────────────────────────
    $trt = ['active' => 0, 'completed' => 0];
    foreach ($r['treatments']['rows'] as $t) {
        $st = strtolower((string)($t['status'] ?? ''));
        if (in_array($st, ['ongoing', 'in_progress'], true)) $trt['active']++;
        elseif ($st === 'completed') $trt['completed']++;
    }

    // ── Today's schedule ─────────────────────────────────────
    $todayList = [];
    if (!$r['today']['error']) {
        foreach (attachAppointmentServiceNames($r['today']['rows']) as $a) {
            $todayList[] = [
                'appointment_id' => (int)($a['appointment_id'] ?? 0),
                'patient_id'     => (int)($a['patient_id'] ?? 0),
                'code'           => (string)($a['appointment_code'] ?? ''),
                'time'           => !empty($a['appointment_time']) ? date('g:i A', strtotime($a['appointment_time'])) : '',
                'patient'        => (string)($a['patients']['full_name'] ?? ''),
                'services'       => appointmentServicesLabel($a, ''),
                'branch'         => (string)($a['branches']['branch_name'] ?? ''),
                'status'         => (string)($a['status'] ?? ''),
            ];
        }
    }

    $round = fn(array $a) => array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $a);
    $errors = [];
    foreach (['appointments', 'patients', 'invoices', 'payments', 'inventory', 'treatments', 'today', 'branches'] as $k) {
        if (!empty($r[$k]['error'])) $errors[$k] = $r[$k]['error'];
    }

    return [
        'appointments' => $apt,
        'patients'     => $pat,
        'sales'        => $round($sales),
        'revenue'      => $round($rev),
        'inventory'    => $inv,
        'stock_alerts' => array_slice($alerts, 0, 8),
        'inventory_extra' => $invExtra,
        'treatments'   => $trt,
        'today_list'   => $todayList,
        'top_services' => $topServices,
        'by_branch'    => $byBranch,
        'trend'        => [
            'labels'       => array_values($months),
            'appointments' => array_values(array_map(fn($m) => $m['total'], $aptTrend)),
            'completed'    => array_values(array_map(fn($m) => $m['completed'], $aptTrend)),
            'cancelled'    => array_values(array_map(fn($m) => $m['cancelled'], $aptTrend)),
            'revenue'      => array_values(array_map(fn($v) => round($v, 2), $revTrend)),
            'new_patients' => array_values($patTrend),
        ],
        'meta' => [
            'today'       => date('F j, Y'),
            'week_label'  => date('M j', strtotime($weekStart)) . ' – ' . date('M j', strtotime($weekStart . ' +6 days')),
            'month_label' => date('F Y'),
            'generated'   => date('g:i A'),
        ],
        'errors' => $errors,
    ];
}
