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

// ─────────────────────────────────────────────────────────────
//  PATIENT LIST (patients-list.php)
//
//  Patients are not assigned to a branch: they pick one each time they
//  book. So the list is scoped through the APPOINTMENTS table:
//    • a branch scope (an admin's / dentist's own branch from the session,
//      or the owner's Branch filter) keeps only patients with at least one
//      appointment at that branch (any status) — an inner-joined embed, so
//      the database does the filtering, the count and the paging; a patient
//      with appointments at several branches is still one row;
//    • "Last Visit" is the patient's latest qualifying visit: an appointment
//      that was checked in or completed (PAT_VISIT_STATUSES), within the
//      same branch scope. Its branch is the Owner's Branch column.
//  The stored patients.branch_id / patients.last_visit are not used here:
//  they are set when a patient books, not when they visit.
// ─────────────────────────────────────────────────────────────

const PAT_VISIT_STATUSES = 'checked_in,completed';
const PAT_LIST_COLS      = 'patient_id,patient_code,full_name,contact_number,status,created_at';

/** Sort keys computed from the latest visit (sorted in PHP, see getPatientRecords). */
function patDerivedSort(string $sortKey): bool
{
    return in_array($sortKey, ['visit_desc', 'visit_asc', 'branch_asc', 'branch_desc'], true);
}

/**
 * PostgREST parts for the patient list (no order/limit).
 *   $branchId  branch scope (0 = every branch: owner only — callers pass the
 *              session branch for everyone else)
 *   $cols      patient columns to select
 */
function patientListParts(int $branchId, string $status, array $extra, string $cols = PAT_LIST_COLS): array
{
    $sel = $cols . ',lv:appointments(appointment_date,appointment_time,branch_id,branches(branch_name))';
    // Any non-zero scope filters (a -1 "no valid branch" scope matches nobody: fail closed)
    if ($branchId !== 0) $sel .= ',vis:appointments!inner(appointment_id)';
    $q = [
        'select=' . $sel,
        'lv.status=in.(' . PAT_VISIT_STATUSES . ')',
        'lv.order=appointment_date.desc,appointment_time.desc.nullslast',
        'lv.limit=1',
    ];
    if ($branchId !== 0) {
        $q[] = 'lv.branch_id=eq.' . $branchId;
        $q[] = 'vis.branch_id=eq.' . $branchId;
        $q[] = 'vis.limit=1';
    }
    if ($status) $q[] = 'status=eq.' . urlencode($status);
    return array_merge($q, $extra);
}

/** The page query for a database-side sort (also used to prefetch it). */
function patientRecordsQuery(int $page, int $branchId, string $status, string $order = 'order=created_at.asc', array $extra = []): array
{
    return array_merge(patientListParts($branchId, $status, $extra), [
        $order,
        'limit='  . PAT_PER_PAGE,
        'offset=' . (($page - 1) * PAT_PER_PAGE),
    ]);
}

/** Every matching patient, minimal columns (for the visit/branch sorts). */
function patientRecordsAllQuery(int $branchId, string $status, array $extra = []): array
{
    return array_merge(patientListParts($branchId, $status, $extra, 'patient_id,full_name'),
        ['order=patient_id.asc']);
}

/** Adds last_visit_date / last_visit_time / last_visit_branch from the 'lv' embed. */
function patWithLastVisit(array $row): array
{
    $lv = $row['lv'][0] ?? null;
    $row['last_visit_date']   = $lv['appointment_date'] ?? null;
    $row['last_visit_time']   = $lv['appointment_time'] ?? null;
    $row['last_visit_branch'] = $lv['branches']['branch_name'] ?? null;
    unset($row['lv'], $row['vis']);
    return $row;
}

/** Order rows by latest visit or its branch; patients without a visit last, then by name. */
function patSortDerived(array $rows, string $sortKey): array
{
    $desc    = str_ends_with($sortKey, '_desc');
    $byVisit = str_starts_with($sortKey, 'visit_');
    $key = fn(array $r) => $byVisit
        ? trim(($r['last_visit_date'] ?? '') . ' ' . ($r['last_visit_time'] ?? ''))
        : strtolower((string)($r['last_visit_branch'] ?? ''));
    usort($rows, function ($a, $b) use ($desc, $key) {
        $ka = $key($a);
        $kb = $key($b);
        if (($ka === '') !== ($kb === '')) return $ka === '' ? 1 : -1;          // no visit → last
        if ($ka !== $kb) return $desc ? strcmp($kb, $ka) : strcmp($ka, $kb);
        return [strtolower((string)$a['full_name']), (int)$a['patient_id']] <=> [strtolower((string)$b['full_name']), (int)$b['patient_id']];
    });
    return $rows;
}

/**
 * Fetch one page of the patient list.
 *   $branchId  branch scope (see patientListParts) — never taken from the
 *              request for a non-owner (patients-list.php passes the session branch)
 *   $order     validated 'order=…' clause for database-side sorts
 *   $sortKey   when it is a visit/branch sort, the order is computed here
 */
function getPatientRecords(int $page, int $branchId, string $status, string $order = 'order=created_at.asc', array $extra = [], string $sortKey = ''): array
{
    $perPage = PAT_PER_PAGE;
    $offset  = ($page - 1) * $perPage;
    $get = fn(string $q, array $h = []) => function_exists('admin_get') ? admin_get('patients', $q, $h) : supabase_request('patients', 'GET', [], $q, $h);
    $list = fn(array $res) => is_array($res['data']) && array_is_list($res['data']) ? $res['data'] : [];

    if (!patDerivedSort($sortKey)) {
        // admin_get(): reuses the page's parallel prefetch (controllers/admin_batch.php)
        $result = $get(implode('&', patientRecordsQuery($page, $branchId, $status, $order, $extra)), ['Prefer: count=exact']);
        return patBuildPageResult(array_map('patWithLastVisit', $list($result)), patResolveCount($result), $page, $offset, $perPage);
    }

    // Visit / branch sort: order every matching patient (id, name and latest
    // visit only; paged past the 1000-row API cap), then load just this page.
    $all  = [];
    $base = implode('&', patientRecordsAllQuery($branchId, $status, $extra));
    for ($from = 0; ; $from += 1000) {
        $data = $list($get($base . '&limit=1000&offset=' . $from));
        foreach ($data as $r) $all[] = patWithLastVisit($r);
        if (count($data) < 1000) break;
    }
    $all   = patSortDerived($all, $sortKey);
    $slice = array_slice($all, $offset, $perPage);
    $rows  = [];
    if ($slice) {
        $ids  = implode(',', array_map(fn($r) => (int)$r['patient_id'], $slice));
        $byId = [];
        foreach ($list($get(implode('&', array_merge(patientListParts($branchId, $status, []), ['patient_id=in.(' . $ids . ')'])))) as $r) {
            $byId[(int)$r['patient_id']] = patWithLastVisit($r);
        }
        foreach ($slice as $r) if (isset($byId[(int)$r['patient_id']])) $rows[] = $byId[(int)$r['patient_id']];
    }
    return patBuildPageResult($rows, count($all), $page, $offset, $perPage);
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

function formatPatLastVisit(?string $lastVisit): string
{
    return empty($lastVisit) ? '—' : date('M. d, Y', strtotime($lastVisit));
}

/** '9:00 AM' from an appointment time, or '' when there is none. */
function formatPatVisitTime(?string $time): string
{
    return empty($time) || !strtotime($time) ? '' : date('g:i A', strtotime($time));
}