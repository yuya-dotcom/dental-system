<?php
// =============================================================
//  controllers/dashboard_controller.php
//  Fetches all live stats for dashboard.php and analytics.php
// =============================================================

require_once __DIR__ . '/../dbconfig.php';

/**
 * Returns all dashboard stats in one call.
 */
function getDashboardStats(): array
{
    $today     = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $monthEnd   = date('Y-m-t');

    // ── Appointments ─────────────────────────────────────────
    $allApts = supabase_request('appointments', 'GET', [], 'select=status', ['Prefer: count=exact']);
    $totalAppointments = _countFromHeader($allApts);

    $todayApts = supabase_request('appointments', 'GET', [],
        'select=status&appointment_date=eq.' . $today, ['Prefer: count=exact']);
    $todayCount = _countFromHeader($todayApts);

    $pendingApts = supabase_request('appointments', 'GET', [],
        'select=status&status=eq.pending', ['Prefer: count=exact']);
    $pendingCount = _countFromHeader($pendingApts);

    $completedApts = supabase_request('appointments', 'GET', [],
        'select=status&status=eq.completed', ['Prefer: count=exact']);
    $completedCount = _countFromHeader($completedApts);

    // ── Patients ──────────────────────────────────────────────
    $allPatients = supabase_request('patients', 'GET', [], 'select=patient_id', ['Prefer: count=exact']);
    $totalPatients = _countFromHeader($allPatients);

    $newThisMonth = supabase_request('patients', 'GET', [],
        'select=patient_id&created_at=gte.' . $monthStart . 'T00:00:00&created_at=lte.' . $monthEnd . 'T23:59:59',
        ['Prefer: count=exact']);
    $newPatientsMonth = _countFromHeader($newThisMonth);

    // ── Treatments ────────────────────────────────────────────
    $activeTrts = supabase_request('treatments', 'GET', [],
        'select=treatment_id&status=in.(ongoing,in_progress)', ['Prefer: count=exact']);
    $activeTreatments = _countFromHeader($activeTrts);

    $completedTrts = supabase_request('treatments', 'GET', [],
        'select=treatment_id&status=eq.completed', ['Prefer: count=exact']);
    $completedTreatments = _countFromHeader($completedTrts);

    // ── Revenue (invoices) ────────────────────────────────────
    $monthInvoices = supabase_request('invoices', 'GET', [],
        'select=total_amount,amount_paid,payment_status&invoice_date=gte.' . $monthStart . '&invoice_date=lte.' . $monthEnd);
    $monthRevenue   = 0;
    $unpaidBalance  = 0;
    $paidThisMonth  = 0;
    if (is_array($monthInvoices['data'])) {
        foreach ($monthInvoices['data'] as $inv) {
            $monthRevenue  += (float)($inv['amount_paid']  ?? 0);
            $unpaidBalance += (float)($inv['balance']      ?? 0);
            if (($inv['payment_status'] ?? '') === 'paid') $paidThisMonth++;
        }
    }

    $allInvoices = supabase_request('invoices', 'GET', [], 'select=amount_paid');
    $totalRevenue = 0;
    if (is_array($allInvoices['data'])) {
        foreach ($allInvoices['data'] as $inv) {
            $totalRevenue += (float)($inv['amount_paid'] ?? 0);
        }
    }

    // ── Today's appointments (list for table) ─────────────────
    $todayList = supabase_request('appointments', 'GET', [], implode('&', [
        'select=appointment_code,appointment_time,status,payment_status,'
            . 'patients(full_name),services(service_name),branches(branch_name)',
        'appointment_date=eq.' . $today,
        'order=appointment_time.asc',
        'limit=8',
    ]));
    $todayAppointments = is_array($todayList['data']) ? $todayList['data'] : [];

    return [
        // Appointments
        'total_appointments'   => $totalAppointments,
        'today_appointments'   => $todayCount,
        'pending_appointments' => $pendingCount,
        'completed_appointments' => $completedCount,
        // Patients
        'total_patients'       => $totalPatients,
        'new_patients_month'   => $newPatientsMonth,
        // Treatments
        'active_treatments'    => $activeTreatments,
        'completed_treatments' => $completedTreatments,
        // Revenue
        'total_revenue'        => $totalRevenue,
        'month_revenue'        => $monthRevenue,
        'unpaid_balance'       => $unpaidBalance,
        // Today's list
        'today_list'           => $todayAppointments,
        // Meta
        'month_label'          => date('F Y'),
        'today_label'          => date('F j, Y'),
    ];
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