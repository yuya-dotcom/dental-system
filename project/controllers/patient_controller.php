<?php
// =============================================================
//  controllers/patient_controller.php
//  List / Pagination / Find-or-Create — Patients
//
//  FIX (root cause of "0 total" bug):
//    patBuildPageResult() now returns the keys that
//    patients-list.php actually reads:
//      totalRecords  (was: total)
//      totalPages    (unchanged)
//      from          (was: missing)
//      to            (was: missing)
//    The old keys are kept as aliases so nothing else breaks.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';

if (!defined('PAT_PER_PAGE')) {
    define('PAT_PER_PAGE', 10);
}

// ─────────────────────────────────────────────────────────────
//  PAGINATION HELPERS
// ─────────────────────────────────────────────────────────────

/**
 * Extract total row count from Supabase Content-Range header.
 * Supabase sends:  Content-Range: 0-9/42
 * We need the number after the slash.
 *
 * IMPORTANT: supabase_request() must pass the
 * 'Prefer: count=exact' header for this to work.
 * Falls back to counting returned rows when the header is absent.
 */
function patResolveCount(array $result): int
{
    $range = $result['headers']['content-range']
          ?? $result['headers']['Content-Range']
          ?? '';

    if ($range && str_contains($range, '/')) {
        $total = (int) explode('/', $range)[1];
        if ($total >= 0) return $total;
    }

    // Fallback: count rows in the current page (under-counts but safe)
    return is_array($result['data']) ? count($result['data']) : 0;
}

/**
 * Build a consistent pagination result array.
 *
 * Keys returned (all used by patients-list.php):
 *   rows         — the current page's rows
 *   totalRecords — total matching rows in the database
 *   totalPages   — total number of pages
 *   from         — 1-based start row on this page  (e.g. 1, 11, 21 …)
 *   to           — 1-based end row on this page    (e.g. 10, 20, 25 …)
 *   currentPage  — current page number
 *   perPage      — rows per page
 */
function patBuildPageResult(
    array $rows,
    int   $total,
    int   $page,
    int   $offset,
    int   $perPage
): array {
    $rowCount   = count($rows);
    $from       = $total > 0 ? $offset + 1           : 0;
    $to         = $total > 0 ? $offset + $rowCount   : 0;
    $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

    return [
        // Keys read by patients-list.php
        'rows'         => $rows,
        'totalRecords' => $total,
        'totalPages'   => $totalPages,
        'from'         => $from,
        'to'           => $to,
        // Extra keys (kept for any other callers)
        'total'        => $total,       // alias of totalRecords
        'currentPage'  => $page,
        'perPage'      => $perPage,
        'offset'       => $offset,
    ];
}

// ─────────────────────────────────────────────────────────────
//  QUERY FUNCTIONS
// ─────────────────────────────────────────────────────────────

/**
 * Fetch paginated patient records.
 * Used by: patients-list.php
 */
function getPatientRecords(int $page, int $branchId, string $status): array
{
    $perPage = PAT_PER_PAGE;
    $offset  = ($page - 1) * $perPage;

    $q = [
        'select=patient_id,patient_code,full_name,contact_number,gender,birthdate,last_visit,status,branch_id,branches(branch_name)',
        'order=created_at.asc',
        'limit='  . $perPage,
        'offset=' . $offset,
    ];

    if ($branchId > 0) $q[] = 'branch_id=eq.' . $branchId;
    if ($status)       $q[] = 'status=eq.'    . urlencode($status);

    // 'Prefer: count=exact' tells Supabase to include
    // Content-Range header with the total count
    $result = supabase_request(
        'patients',
        'GET',
        [],
        implode('&', $q),
        ['Prefer: count=exact']
    );

    $rows  = is_array($result['data']) ? $result['data'] : [];
    $total = patResolveCount($result);

    return patBuildPageResult($rows, $total, $page, $offset, $perPage);
}

/**
 * Find existing patient by phone, or create a new one.
 * Used by: api/book_appointment.php (online booking)
 */
function findOrCreatePatient(
    string $fullName,
    string $phone,
    string $birthdate,
    int    $branchId,
    string $appointmentDate
): array {
    $check = supabase_request('patients', 'GET', [], implode('&', [
        'contact_number=eq.' . urlencode($phone),
        'select=patient_id,full_name',
    ]));

    if ($check['error']) {
        return ['patient_id' => 0, 'is_new' => false, 'error' => 'Patient lookup failed.'];
    }

    if (!empty($check['data'])) {
        $patientId = (int) $check['data'][0]['patient_id'];
        supabase_request('patients', 'PATCH', [
            'last_visit' => $appointmentDate,
            'branch_id'  => $branchId,
            'updated_at' => date('c'),
        ], 'patient_id=eq.' . $patientId);

        return ['patient_id' => $patientId, 'is_new' => false, 'error' => null];
    }

    $result = supabase_request('patients', 'POST', [
        'full_name'      => $fullName,
        'contact_number' => $phone,
        'birthdate'      => $birthdate ?: null,
        'branch_id'      => $branchId,
        'last_visit'     => $appointmentDate,
        'status'         => 'active',
    ]);

    if ($result['error'] || empty($result['data'][0]['patient_id'])) {
        return ['patient_id' => 0, 'is_new' => true, 'error' => 'Could not save patient record.'];
    }

    return ['patient_id' => (int) $result['data'][0]['patient_id'], 'is_new' => true, 'error' => null];
}

// ─────────────────────────────────────────────────────────────
//  BADGE & FORMAT HELPERS
// ─────────────────────────────────────────────────────────────

function patStatusBadge(?string $status): string
{
    return match (strtolower($status ?? 'active')) {
        'active'   => 'bg-soft-success text-success',
        'inactive' => 'bg-soft-secondary text-secondary',
        default    => 'bg-soft-secondary text-secondary',
    };
}

function formatPatBirthdate(?string $birthdate): string
{
    if (empty($birthdate)) return '—';
    try {
        $age = date_diff(date_create($birthdate), date_create('today'))->y;
        return date('M. d, Y', strtotime($birthdate)) . ' (' . $age . ' yrs)';
    } catch (Exception $e) {
        return '—';
    }
}

function formatPatLastVisit(?string $lastVisit): string
{
    return empty($lastVisit) ? '—' : date('M. d, Y', strtotime($lastVisit));
}