<?php
// =============================================================
//  controllers/branch_controller.php
//  Business Process Controller — Branches
//
//  Covers:
//    - branches-records.php   (all branches, paginated)
//    - Dynamic branch dropdowns across all admin pages
//    - api/get_branches.php   (JSON for dropdowns)
//    - Operating-day helpers  (branches.operating_days, 0 = Sun … 6 = Sat)
//
//  Zero HTML. Zero $_GET reading.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/appointment_controller.php'; // for resolveCount() + buildPageResult()

const BR_PER_PAGE = 10;

/** Used when a branch row has no operating_days yet (Mon–Sat). */
const BR_DEFAULT_OPERATING_DAYS = [1, 2, 3, 4, 5, 6];

// ─────────────────────────────────────────────────────────────
//  QUERY FUNCTIONS
// ─────────────────────────────────────────────────────────────

/**
 * All branches paginated, ordered by branch_name.
 * Used by: branches-records.php
 */
// $order: validated 'order=…' clause; $extra: prebuilt PostgREST filters (list_filters.php)
function getBranchRecords(int $page, string $order = 'order=branch_name.asc', array $extra = []): array
{
    $offset = ($page - 1) * BR_PER_PAGE;

    $q = [
        'select=branch_id,branch_code,branch_name,address,contact_number,open_time,close_time,operating_days,status,created_at',
        $order,
        'limit='  . BR_PER_PAGE,
        'offset=' . $offset,
        ...$extra,
    ];

    $result = supabase_request('branches', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
    if ($result['error']) {
        // operating_days not added yet (SQL file not run) — load without it, Mon–Sat is shown
        $q[0]   = 'select=branch_id,branch_code,branch_name,address,contact_number,open_time,close_time,status,created_at';
        $result = supabase_request('branches', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
    }
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'branches', $q);

    return buildPageResult($rows, $total, $page, $offset, BR_PER_PAGE);
}

/**
 * Fetch all branches for dropdown population.
 * Used by: api/get_branches.php + any PHP page that needs branch options.
 * Returns: [['branch_id' => int, 'branch_name' => string], ...]
 */
function getAllBranches(): array
{
    $result = supabase_request('branches', 'GET', [], implode('&', [
        'select=branch_id,branch_name',
        'order=branch_name.asc',
    ]));

    if ($result['error'] || !is_array($result['data'])) {
        return [];
    }

    return $result['data'];
}

// ─────────────────────────────────────────────────────────────
//  OPERATING DAYS
// ─────────────────────────────────────────────────────────────

/**
 * Clean any operating_days value (array from Supabase, or input from a form)
 * into a sorted list of unique weekday numbers 0–6.
 * Returns [] when nothing valid was given.
 */
function normalizeOperatingDays(mixed $raw): array
{
    if (is_string($raw)) {
        // Postgres text form "{1,2,3}" or "1,2,3"
        $raw = preg_split('/\s*,\s*/', trim($raw, "{} \t\n\r"), -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($raw)) return [];

    $days = [];
    foreach ($raw as $d) {
        if (is_int($d) || (is_string($d) && ctype_digit($d))) {
            $n = (int)$d;
            if ($n >= 0 && $n <= 6) $days[$n] = $n;
        }
    }
    sort($days);
    return array_values($days);
}

/** Operating days of a branch row, falling back to Mon–Sat. */
function branchOperatingDays(array $branch): array
{
    $days = normalizeOperatingDays($branch['operating_days'] ?? null);
    return $days ?: BR_DEFAULT_OPERATING_DAYS;
}

/**
 * Short label for a set of days, Monday first:
 *   [1,2,3,4,5,6] → "Mon–Sat"   [0..6] → "Every day"
 *   [1,3,5]       → "Mon, Wed, Fri"   [5,6,0] → "Fri–Sun"
 */
function formatOperatingDays(array $days): string
{
    $days = normalizeOperatingDays($days);
    if (!$days)            return '—';
    if (count($days) === 7) return 'Every day';

    $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'];
    $order = [1, 2, 3, 4, 5, 6, 0]; // Monday first, so Fri–Sun reads as one run

    $parts = [];
    $run   = [];
    foreach ($order as $d) {
        if (in_array($d, $days, true)) {
            $run[] = $d;
            continue;
        }
        if ($run) { $parts[] = _branchDayRun($run, $names); $run = []; }
    }
    if ($run) $parts[] = _branchDayRun($run, $names);

    return implode(', ', $parts);
}

function _branchDayRun(array $run, array $names): string
{
    if (count($run) >= 3) return $names[$run[0]] . '–' . $names[end($run)];
    return implode(', ', array_map(fn($d) => $names[$d], $run));
}

// ─────────────────────────────────────────────────────────────
//  HELPERS
// ─────────────────────────────────────────────────────────────

function branchStatusBadge(?string $status): string
{
    return match (strtolower($status ?? 'active')) {
        'active'   => 'bg-soft-success text-success',
        'inactive',
        'closed'   => 'bg-soft-danger text-danger',
        default    => 'bg-soft-secondary text-secondary',
    };
}

/**
 * Render a <select> branch filter with options from the DB.
 * Pass in the current selected branch_id and the page filename for the URL.
 */
function renderBranchSelect(array $branches, int $selected, string $page): string
{
    $html  = '<select id="filterBranch" class="form-select form-select-sm" ';
    $html .= 'onchange="applyFilters()" style="max-width:180px;">';
    $html .= '<option value="0">All Branches</option>';
    foreach ($branches as $b) {
        $sel   = (int)$b['branch_id'] === $selected ? ' selected' : '';
        $html .= '<option value="' . (int)$b['branch_id'] . '"' . $sel . '>'
               . htmlspecialchars($b['branch_name']) . '</option>';
    }
    $html .= '</select>';
    return $html;
}