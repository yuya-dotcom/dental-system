<?php
// =============================================================
//  controllers/dentist_controller.php
//  Business Process Controller — Dentists
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/appointment_controller.php'; // resolveCount + buildPageResult

const DENT_PER_PAGE = 10;
/** Columns added by database/proposed/2026-11-16_owner_records_eval2.sql (left out until it runs). */
const DENT_NEW_COLS = ',first_name,middle_name,last_name,suffix,age,gender,email';

// ─────────────────────────────────────────────────────────────
//  QUERIES
// ─────────────────────────────────────────────────────────────

// $order: validated 'order=…' clause; $extra: prebuilt PostgREST filters (list_filters.php)
// $fallbackExtra: the same filters without the new columns (used until the SQL file runs)
function getDentistRecords(int $page, int $branchId, string $status, string $order = 'order=full_name.asc', array $extra = [], ?array $fallbackExtra = null): array
{
    $offset = ($page - 1) * DENT_PER_PAGE;
    $q = dentistRecordsQuery($page, $branchId, $status, $order, $extra);

    // admin_get(): reuses the page's parallel prefetch (controllers/admin_batch.php) when present
    $result = (function_exists('admin_get') ? admin_get('dentists', implode('&', $q), ['Prefer: count=exact']) : supabase_request('dentists', 'GET', [], implode('&', $q), ['Prefer: count=exact']));
    if ($result['error']) {
        // New columns not in the database yet (2026-11-16 SQL file) → the older columns
        $q      = dentistRecordsQuery($page, $branchId, $status, $order, $fallbackExtra ?? $extra);
        $q[0]   = str_replace(DENT_NEW_COLS, '', $q[0]);
        $result = supabase_request('dentists', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
    }
    if ($result['error']) {
        // Linked-login embed unavailable → load the list without it (no username shown)
        $q[0]   = str_replace(',users(username,status)', '', $q[0]);
        $result = supabase_request('dentists', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
    }
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'dentists', $q);

    return buildPageResult($rows, $total, $page, $offset, DENT_PER_PAGE);
}

/** The PostgREST filter parts for getDentistRecords() (also used to prefetch it). */
function dentistRecordsQuery(int $page, int $branchId, string $status, string $order = 'order=full_name.asc', array $extra = []): array
{
    $offset = ($page - 1) * DENT_PER_PAGE;

    $q = [
        'select=dentist_id,full_name' . DENT_NEW_COLS . ',specialization,contact_number,status,branch_id,user_id,created_at,updated_at,branches(branch_name),users(username,status)',
        $order,
        'limit='  . DENT_PER_PAGE,
        'offset=' . $offset,
    ];

    if ($branchId > 0) $q[] = 'branch_id=eq.' . $branchId;
    if ($status)       $q[] = 'status=eq.'    . urlencode($status);
    array_push($q, ...$extra);

    return $q;
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

/** Status values a dentist record may hold (anything else is saved as 'active'). */
const DENT_STATUSES = ['active', 'on_leave', 'inactive'];

if (!function_exists('dentStatusLabel')) {
function dentStatusLabel(?string $status): string
{
    return match (strtolower($status ?? 'active')) {
        'on_leave' => 'On Leave',
        default    => ucfirst($status ?? 'Active'),
    };
}
}