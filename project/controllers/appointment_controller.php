<?php
require_once __DIR__ . '/notification_helper.php';   // patient portal notifications
// =============================================================
//  controllers/AppointmentController.php
//  Business Process Controller — Appointments
//
//  Contains ALL appointment-related:
//    - Supabase queries
//    - Pagination & count logic
//    - Validation rules
//    - Status update logic
//    - Badge class helpers
//    - Date / time formatters
//    - Auto-expiry (no_show) for overdue appointments
//
//  Zero HTML. Zero $_GET/$_POST reading.
//  UI files call these functions and render results.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';

const APT_PER_PAGE = 10;

// ─────────────────────────────────────────────────────────────
//  QUERY FUNCTIONS
// ─────────────────────────────────────────────────────────────

/**
 * Fetch paginated appointment schedule ordered by date + time.
 * Used by: appointments-schedule.php
 */
// $order: validated 'order=…' clause; $extra: prebuilt PostgREST filters (list_filters.php)
function getAppointmentSchedule(int $page, int $branchId, string $status, string $date, string $order = 'order=appointment_date.asc,appointment_time.asc', array $extra = []): array
{
    $offset = ($page - 1) * APT_PER_PAGE;
    $q = appointmentScheduleQuery($page, $branchId, $status, $date, $order, $extra);

    // admin_get(): reuses the page's parallel prefetch (controllers/admin_batch.php) when present
    $result = (function_exists('admin_get') ? admin_get('appointments', implode('&', $q), ['Prefer: count=exact']) : supabase_request('appointments', 'GET', [], implode('&', $q), ['Prefer: count=exact']));
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'appointments', $q);

    return buildPageResult($rows, $total, $page, $offset, APT_PER_PAGE);
}

/** The PostgREST filter parts for getAppointmentSchedule() (also used to prefetch it). */
function appointmentScheduleQuery(int $page, int $branchId, string $status, string $date, string $order = 'order=appointment_date.asc,appointment_time.asc', array $extra = []): array
{
    $offset = ($page - 1) * APT_PER_PAGE;

    $q = [
        'select=appointment_id,appointment_code,appointment_date,appointment_time,'
            . 'status,payment_status,patient_id,branch_id,notes,'
            . 'patients(patient_code,full_name,contact_number),branches(branch_name),services(service_name),'
            // every chosen service, embedded: no separate appointment_services round trip
            . 'appointment_services(sort_order,services(service_name))',
        // Default: soonest appointment first
        $order,
        'limit='  . APT_PER_PAGE,
        'offset=' . $offset,
        ...$extra,
    ];

    if ($branchId > 0) $q[] = 'branch_id=eq.' . $branchId;

    // Only show active/upcoming appointments — never completed or cancelled
    if ($status) {
        // Staff filtered by a specific status
        $q[] = 'status=eq.' . urlencode($status);
    } else {
        // Default — only pending, confirmed, checked_in
        $q[] = 'status=in.(pending,confirmed,checked_in)';
    }

    // Optional date filter
    if ($date) {
        $q[] = 'appointment_date=eq.' . urlencode($date);
    }

    return $q;
}

/**
 * Fetch paginated appointment records ordered by appointment_code.
 * Used by: appointments-records.php
 */
// $order: validated 'order=…' clause; $extra: prebuilt PostgREST filters (list_filters.php)
function getAppointmentRecords(int $page, int $branchId, string $status, string $order = 'order=appointment_date.asc,appointment_time.asc', array $extra = []): array
{
    $offset = ($page - 1) * APT_PER_PAGE;
    $q = appointmentRecordsQuery($page, $branchId, $status, $order, $extra);

    // admin_get(): reuses the page's parallel prefetch (controllers/admin_batch.php) when present
    $result = (function_exists('admin_get') ? admin_get('appointments', implode('&', $q), ['Prefer: count=exact']) : supabase_request('appointments', 'GET', [], implode('&', $q), ['Prefer: count=exact']));
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'appointments', $q);

    return buildPageResult($rows, $total, $page, $offset, APT_PER_PAGE);
}

/** The PostgREST filter parts for getAppointmentRecords() (also used to prefetch it). */
function appointmentRecordsQuery(int $page, int $branchId, string $status, string $order = 'order=appointment_date.asc,appointment_time.asc', array $extra = []): array
{
    $offset = ($page - 1) * APT_PER_PAGE;

    $q = [
        'select=appointment_id,appointment_code,appointment_date,appointment_time,'
            . 'status,payment_status,patient_id,patients(full_name,patient_code),branches(branch_name),services(service_name),'
            . 'appointment_services(sort_order,services(service_name))',   // embedded: no extra round trip
        // Default: soonest appointment first
        $order,
        'limit='  . APT_PER_PAGE,
        'offset=' . $offset,
        ...$extra,
    ];

    if ($branchId > 0) $q[] = 'branch_id=eq.' . $branchId;
    if ($status)       $q[] = 'status=eq.'    . urlencode($status);

    return $q;
}

/**
 * Update a single appointment's status.
 * Used by: api/update_appointment_status.php
 */
function updateAppointmentStatus(int $appointmentId, string $newStatus): array
{
    // Same rules as api/appointments.php (PATCH): only a pending appointment is
    // confirmed here. Completing goes through Active Treatments → Finish; no-show
    // is automatic (appointment_lifecycle.php); cancelling needs a reason and
    // goes through aptCancel() (api/update_appointment_status.php).
    $allowed = ['confirmed'];

    if ($appointmentId < 1 || !in_array($newStatus, $allowed, true)) {
        return ['success' => false, 'message' => 'Invalid input.'];
    }

    $before = notif_appt_snapshot($appointmentId);

    $result = supabase_request(
        'appointments',
        'PATCH',
        ['status' => $newStatus, 'updated_at' => date('c')],
        'appointment_id=eq.' . $appointmentId . '&status=in.(pending,confirmed)'
    );

    if ($result['error']) {
        return ['success' => false, 'message' => 'Update failed: ' . $result['error']];
    }
    if (empty($result['data'][0])) {
        return ['success' => false, 'message' => 'This appointment can no longer be confirmed. Please refresh the list.'];
    }

    notif_appointment($result['data'][0] ?? $appointmentId, $before);

    return ['success' => true, 'message' => 'Status updated to ' . $newStatus];
}

// ─────────────────────────────────────────────────────────────
//  AUTO-EXPIRY — NO SHOW
// ─────────────────────────────────────────────────────────────

/**
 * Mark overdue appointments as no_show automatically.
 *
 * An appointment is overdue when its scheduled date+time has already
 * passed and it is still in a pending or confirmed state — meaning
 * the patient never showed up and no staff action was taken.
 *
 * How it works:
 *   - Targets only status IN (pending, confirmed).
 *   - checked_in is intentionally excluded: patient arrived, so staff
 *     must resolve it manually (complete or cancel).
 *   - Runs a single Supabase PATCH (one DB round-trip, atomic).
 *   - Uses two OR conditions via PostgREST:
 *       (a) appointment_date < today        → entire past days
 *       (b) appointment_date = today
 *           AND appointment_time < now      → earlier slots today
 *   - Optionally scoped to one branch (for admin/dentist users).
 *
 * Called once at the top of appointments-schedule.php, before the
 * data fetch, so the page always shows a current list.
 *
 * Returns the number of appointments marked no_show (best-effort;
 * Supabase may not return a count — 0 is a safe default).
 */
function expireOverdueAppointments(int $branchId = 0): int
{
    // One rule for every caller (pages, APIs, portal, cron): see
    // controllers/appointment_lifecycle.php → aptSweepNoShows(). It covers all
    // branches, waits until the booked visit has ENDED (Asia/Manila), skips
    // appointments that were checked in, and is throttled + idempotent.
    require_once __DIR__ . '/appointment_lifecycle.php';
    return count(aptSweepNoShows());
}

// ─────────────────────────────────────────────────────────────
//  BADGE & FORMAT HELPERS
// ─────────────────────────────────────────────────────────────

if (!function_exists('aptStatusBadge')) {
    function aptStatusBadge(string $status): string
    {
        return match (strtolower($status)) {
            'confirmed' => 'bg-soft-success text-success',
            'pending'   => 'bg-soft-warning text-warning',
            'completed' => 'bg-soft-primary text-primary',
            'cancelled' => 'bg-soft-danger text-danger',
            'no_show'   => 'bg-soft-secondary text-secondary',
            default     => 'bg-soft-secondary text-secondary',
        };
    }
}

if (!function_exists('aptPaymentBadge')) {
    function aptPaymentBadge(?string $status): string
    {
        return match (strtolower($status ?? 'unpaid')) {
            'paid'    => 'bg-soft-success text-success',
            'partial' => 'bg-soft-warning text-warning',
            default   => 'bg-soft-danger text-danger',
        };
    }
}

if (!function_exists('formatAptDate')) {
    function formatAptDate(string $date): string
    {
        return date('M. d, Y', strtotime($date));
    }
}

if (!function_exists('formatAptTime')) {
    function formatAptTime(string $time): string
    {
        return date('g:i A', strtotime($time));
    }
}

// ─────────────────────────────────────────────────────────────
//  SHARED INTERNAL HELPERS
//  (also used by PatientController via require)
// ─────────────────────────────────────────────────────────────

/**
 * Resolve total record count from a Supabase response.
 *
 * Layer 1 — Content-Range header  (0 extra requests, fastest)
 * Layer 2 — Separate count query  (fallback if header missing)
 * Layer 3 — Array length          (last resort)
 */
if (!function_exists('resolveCount')) {
    function resolveCount(array $result, string $table, array $qParts): int
    {
        // Layer 1: Content-Range header e.g. "0-9/42"
        $cr = $result['headers']['content-range'] ?? '';
        if ($cr && str_contains($cr, '/')) {
            return (int) explode('/', $cr)[1];
        }

        // Layer 2: separate count-only query
        $idCol  = $table === 'patients' ? 'patient_id' : 'appointment_id';
        $countQ = ['select=' . $idCol];
        foreach ($qParts as $p) {
            $key = explode('=', $p, 2)[0];
            if (!in_array($key, ['select', 'limit', 'offset', 'order'], true)) {
                $countQ[] = $p;
            }
        }
        $countResult = supabase_request($table, 'GET', [], implode('&', $countQ), ['Prefer: count=exact']);
        $cr2 = $countResult['headers']['content-range'] ?? '';
        if ($cr2 && str_contains($cr2, '/')) {
            return (int) explode('/', $cr2)[1];
        }

        // Layer 3: count returned rows
        return is_array($countResult['data']) ? count($countResult['data']) : 0;
    }
}

if (!function_exists('buildPageResult')) {
    function buildPageResult(array $rows, int $total, int $page, int $offset, int $perPage): array
    {
        return [
            'rows'         => $rows,
            'totalRecords' => $total,
            'totalPages'   => max(1, (int) ceil($total / $perPage)),
            'from'         => $total === 0 ? 0 : $offset + 1,
            'to'           => min($offset + $perPage, $total),
        ];
    }
}

// ─────────────────────────────────────────────────────────────
//  SERVICES (used by api/get_services.php)
// ─────────────────────────────────────────────────────────────

/**
 * Fetch all active services for the booking dropdown.
 * Returns: [['service_id' => int, 'service_name' => string, 'price' => float], ...]
 */
function getServices(): array
{
    $result = supabase_request('services', 'GET', [], implode('&', [
        'select=service_id,service_name,base_price',
        'status=eq.active',
        'order=service_name.asc',
    ]));

    if ($result['error'] || !is_array($result['data'])) {
        return [];
    }

    return $result['data'];
}

/**
 * Fetch audit info for a single appointment (used by api/appointments.php).
 * Returns ['patient_name' => string, 'old_status' => string]
 */
if (!function_exists('getAppointmentInfoForAudit')) {
    function getAppointmentInfoForAudit(int $appointmentId): array
    {
        $res = supabase_request(
            'appointments', 'GET', [],
            'appointment_id=eq.' . $appointmentId
            . '&select=status,patients(full_name)&limit=1'
        );
        $row = $res['data'][0] ?? [];
        return [
            'patient_name' => $row['patients']['full_name'] ?? '',
            'old_status'   => $row['status'] ?? '',
        ];
    }
}