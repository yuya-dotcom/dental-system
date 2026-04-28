<?php
// =============================================================
//  controllers/dentist_controller.php
//  Business Process Controller — Dentists
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/appointment_controller.php'; // resolveCount + buildPageResult

const DENT_PER_PAGE = 10;

// ─────────────────────────────────────────────────────────────
//  QUERIES
// ─────────────────────────────────────────────────────────────

function getDentistRecords(int $page, int $branchId, string $status): array
{
    $offset = ($page - 1) * DENT_PER_PAGE;

    $q = [
        'select=dentist_id,full_name,specialization,contact_number,status,branches(branch_name)',
        'order=full_name.asc',
        'limit='  . DENT_PER_PAGE,
        'offset=' . $offset,
    ];

    if ($branchId > 0) $q[] = 'branch_id=eq.' . $branchId;
    if ($status)       $q[] = 'status=eq.'    . urlencode($status);

    $result = supabase_request('dentists', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'dentists', $q);

    return buildPageResult($rows, $total, $page, $offset, DENT_PER_PAGE);
}

// ─────────────────────────────────────────────────────────────
//  HELPERS
// ─────────────────────────────────────────────────────────────

if (!function_exists('dentStatusBadge')) {
function dentStatusBadge(?string $status): string
{
    return match (strtolower($status ?? 'active')) {
        'active'   => 'bg-soft-success text-success',
        'inactive' => 'bg-soft-danger text-danger',
        'on_leave' => 'bg-soft-warning text-warning',
        default    => 'bg-soft-secondary text-secondary',
    };
}
}

if (!function_exists('dentStatusLabel')) {
function dentStatusLabel(?string $status): string
{
    return match (strtolower($status ?? 'active')) {
        'on_leave' => 'On Leave',
        default    => ucfirst($status ?? 'Active'),
    };
}
}