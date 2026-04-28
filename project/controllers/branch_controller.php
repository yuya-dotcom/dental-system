<?php
// =============================================================
//  controllers/branch_controller.php
//  Business Process Controller — Branches
//
//  Covers:
//    - branches-records.php   (all branches, paginated)
//    - Dynamic branch dropdowns across all admin pages
//    - api/get_branches.php   (JSON for dropdowns)
//
//  Zero HTML. Zero $_GET reading.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/appointment_controller.php'; // for resolveCount() + buildPageResult()

const BR_PER_PAGE = 10;

// ─────────────────────────────────────────────────────────────
//  QUERY FUNCTIONS
// ─────────────────────────────────────────────────────────────

/**
 * All branches paginated, ordered by branch_name.
 * Used by: branches-records.php
 */
function getBranchRecords(int $page): array
{
    $offset = ($page - 1) * BR_PER_PAGE;

    $q = [
        'select=branch_id,branch_code,branch_name,address,contact_number,open_time,close_time,status',
        'order=branch_name.asc',
        'limit='  . BR_PER_PAGE,
        'offset=' . $offset,
    ];

    $result = supabase_request('branches', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
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