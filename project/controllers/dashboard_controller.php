<?php
// =============================================================
//  controllers/dashboard_controller.php
//  Fetches all live stats for dashboard.php and analytics.php
//
//  Performance: the stat queries are independent, so they run in ONE
//  parallel wave (controllers/admin_batch.php) together with the navbar and
//  branch queries, instead of 11+ sequential round trips. Count queries ask
//  for the count only (limit=0 + count=exact) instead of downloading every
//  row just to read the Content-Range total. Results are unchanged.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/admin_batch.php';
require_once __DIR__ . '/appointment_services_helper.php';

/**
 * The independent stat queries, keyed for dashboardStatsFrom().
 * $withTodayList adds today's appointment list (dashboard.php only).
 */
function dashboardStatsSpecs(bool $withTodayList = true): array
{
    $today      = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $monthEnd   = date('Y-m-t');
    $count      = ['Prefer: count=exact'];

    $specs = [
        // ── Appointments ─────────────────────────────────────
        'st_apt_all'       => admin_q('appointments', 'select=status&limit=0', $count),
        'st_apt_today'     => admin_q('appointments', 'select=status&appointment_date=eq.' . $today . '&limit=0', $count),
        'st_apt_pending'   => admin_q('appointments', 'select=status&status=eq.pending&limit=0', $count),
        'st_apt_completed' => admin_q('appointments', 'select=status&status=eq.completed&limit=0', $count),
        // ── Patients ─────────────────────────────────────────
        'st_pat_all'       => admin_q('patients', 'select=patient_id&limit=0', $count),
        'st_pat_month'     => admin_q('patients',
            'select=patient_id&created_at=gte.' . $monthStart . 'T00:00:00&created_at=lte.' . $monthEnd . 'T23:59:59&limit=0', $count),
        // ── Treatments ───────────────────────────────────────
        'st_trt_active'    => admin_q('treatments', 'select=treatment_id&status=in.(ongoing,in_progress)&limit=0', $count),
        'st_trt_completed' => admin_q('treatments', 'select=treatment_id&status=eq.completed&limit=0', $count),
        // ── Revenue (invoices) — the amounts are summed in PHP ──
        'st_inv_month'     => admin_q('invoices',
            'select=total_amount,amount_paid,payment_status&invoice_date=gte.' . $monthStart . '&invoice_date=lte.' . $monthEnd),
        'st_inv_all'       => admin_q('invoices', 'select=amount_paid'),
    ];

    if ($withTodayList) {
        // ── Today's appointments (list for table) ──────────
        $specs['st_today_list'] = admin_q('appointments', implode('&', [
            'select=appointment_id,patient_id,appointment_code,appointment_time,status,payment_status,'
                . 'patients(full_name),services(service_name),branches(branch_name)',
            'appointment_date=eq.' . $today,
            'order=appointment_time.asc',
            'limit=8',
        ]));
    }
    return $specs;
}

/** Builds the stats array from the results of dashboardStatsSpecs(). */
function dashboardStatsFrom(array $r): array
{
    $monthRevenue  = 0;
    $unpaidBalance = 0;
    $paidThisMonth = 0;
    if (is_array($r['st_inv_month']['data'] ?? null)) {
        foreach ($r['st_inv_month']['data'] as $inv) {
            $monthRevenue  += (float)($inv['amount_paid']  ?? 0);
            $unpaidBalance += (float)($inv['balance']      ?? 0);
            if (($inv['payment_status'] ?? '') === 'paid') $paidThisMonth++;
        }
    }

    $totalRevenue = 0;
    if (is_array($r['st_inv_all']['data'] ?? null)) {
        foreach ($r['st_inv_all']['data'] as $inv) {
            $totalRevenue += (float)($inv['amount_paid'] ?? 0);
        }
    }

    // Today's list: attach all 1–3 services per row (one extra request, reuses the connection)
    $todayAppointments = [];
    if (isset($r['st_today_list'])) {
        $todayAppointments = attachAppointmentServiceNames(
            is_array($r['st_today_list']['data']) ? $r['st_today_list']['data'] : []);
    }

    return [
        // Appointments
        'total_appointments'     => _countFromHeader($r['st_apt_all']),
        'today_appointments'     => _countFromHeader($r['st_apt_today']),
        'pending_appointments'   => _countFromHeader($r['st_apt_pending']),
        'completed_appointments' => _countFromHeader($r['st_apt_completed']),
        // Patients
        'total_patients'         => _countFromHeader($r['st_pat_all']),
        'new_patients_month'     => _countFromHeader($r['st_pat_month']),
        // Treatments
        'active_treatments'      => _countFromHeader($r['st_trt_active']),
        'completed_treatments'   => _countFromHeader($r['st_trt_completed']),
        // Revenue
        'total_revenue'          => $totalRevenue,
        'month_revenue'          => $monthRevenue,
        'unpaid_balance'         => $unpaidBalance,
        // Today's list
        'today_list'             => $todayAppointments,
        // Meta
        'month_label'            => date('F Y'),
        'today_label'            => date('F j, Y'),
    ];
}

/**
 * Returns all dashboard stats in one call.
 * The stat queries run in parallel with the navbar's (admin_prefetch).
 */
function getDashboardStats(bool $withTodayList = true): array
{
    return dashboardStatsFrom(admin_prefetch(dashboardStatsSpecs($withTodayList), ['navbar']));
}

/** Extract count from content-range header, fallback to array count */
function _countFromHeader(array $result): int
{
    $cr = $result['headers']['content-range'] ?? '';
    if ($cr && str_contains($cr, '/')) {
        return (int) explode('/', $cr)[1];
    }
    return is_array($result['data']) ? count($result['data']) : 0;
}

// ─────────────────────────────────────────────────────────────
//  Badge helper (used by dashboard.php)
// ─────────────────────────────────────────────────────────────
function aptStatusBadge(string $status): string
{
    return match (strtolower($status)) {
        'confirmed'  => 'bg-soft-primary text-primary',
        'completed'  => 'bg-soft-success text-success',
        'cancelled'  => 'bg-soft-danger text-danger',
        'no_show'    => 'bg-soft-dark text-dark',
        default      => 'bg-soft-warning text-warning', // pending
    };
}
