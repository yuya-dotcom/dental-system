<?php
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
function getAppointmentSchedule(int $page, int $branchId, string $status, string $date): array
{
    $offset = ($page - 1) * APT_PER_PAGE;

    $q = [
        'select=appointment_id,appointment_code,appointment_date,appointment_time,'
            . 'status,payment_status,patient_id,patients(full_name),branches(branch_name),services(service_name)',
        // Soonest appointment first
        'order=appointment_date.asc,appointment_time.asc',
        'limit='  . APT_PER_PAGE,
        'offset=' . $offset,
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

    $result = supabase_request('appointments', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'appointments', $q);

    return buildPageResult($rows, $total, $page, $offset, APT_PER_PAGE);
}

/**
 * Fetch paginated appointment records ordered by appointment_code.
 * Used by: appointments-records.php
 */
function getAppointmentRecords(int $page, int $branchId, string $status): array
{
    $offset = ($page - 1) * APT_PER_PAGE;

    $q = [
        'select=appointment_id,appointment_code,appointment_date,appointment_time,'
            . 'status,payment_status,patient_id,patients(full_name),branches(branch_name),services(service_name)',
        // Soonest appointment first
        'order=appointment_date.asc,appointment_time.asc',
        'limit='  . APT_PER_PAGE,
        'offset=' . $offset,
    ];

    if ($branchId > 0) $q[] = 'branch_id=eq.' . $branchId;
    if ($status)       $q[] = 'status=eq.'    . urlencode($status);

    $result = supabase_request('appointments', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'appointments', $q);

    return buildPageResult($rows, $total, $page, $offset, APT_PER_PAGE);
}

/**
 * Update a single appointment's status.
 * Used by: api/update_appointment_status.php
 */
function updateAppointmentStatus(int $appointmentId, string $newStatus): array
{
    $allowed = ['confirmed', 'completed', 'cancelled', 'no_show'];

    if ($appointmentId < 1 || !in_array($newStatus, $allowed, true)) {
        return ['success' => false, 'message' => 'Invalid input.'];
    }

    $result = supabase_request(
        'appointments',
        'PATCH',
        ['status' => $newStatus, 'updated_at' => date('c')],
        'appointment_id=eq.' . $appointmentId
    );

    if ($result['error']) {
        return ['success' => false, 'message' => 'Update failed: ' . $result['error']];
    }

    return ['success' => true, 'message' => 'Status updated to ' . $newStatus];
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
//  BOOKING FUNCTIONS (used by api/book_appointment.php)
// ─────────────────────────────────────────────────────────────

/**
 * Check if a time slot is still available at a branch on a date.
 * Returns true if slot is free, false if taken.
 */
function isSlotAvailable(string $date, string $time, int $branchId): bool
{
    $result = supabase_request('appointments', 'GET', [], implode('&', [
        'appointment_date=eq.' . $date,
        'appointment_time=eq.' . $time,
        'branch_id=eq.'        . $branchId,
        'status=neq.cancelled',
        'status=neq.no_show',
        'select=appointment_id',
    ]));

    // If query errored, treat as unavailable to be safe
    if ($result['error']) return false;

    return empty($result['data']);
}

/**
 * Create a new appointment row.
 * Returns ['appointment_code' => string, 'error' => string|null]
 */
function createAppointment(int $patientId, int $branchId, string $date, string $time, ?int $serviceId): array
{
    $payload = [
        'patient_id'       => $patientId,
        'branch_id'        => $branchId,
        'appointment_date' => $date,
        'appointment_time' => $time,
        'appointment_type' => 'Consultation',
        'status'           => 'pending',
        'payment_status'   => 'unpaid',
    ];
    if ($serviceId) $payload['service_id'] = $serviceId;

    $result = supabase_request('appointments', 'POST', $payload);

    if ($result['status'] === 409) {
        return ['appointment_code' => null, 'error' => 'That time slot was just taken. Please choose another time.'];
    }

    if ($result['error'] || empty($result['data'][0])) {
        return ['appointment_code' => null, 'error' => 'Could not save appointment. Please try again.'];
    }

    return ['appointment_code' => $result['data'][0]['appointment_code'], 'error' => null];
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