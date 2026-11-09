<?php
// =============================================================
//  controllers/inventory_controller.php
//  Business Process Controller — Inventory & Stock Movements
//
//  Covers:
//    - inventory-records.php        (all items, paginated)
//    - inventory-stock-movement.php (all movements, paginated)
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/appointment_controller.php'; // resolveCount + buildPageResult

const INV_PER_PAGE = 10;
const MOV_PER_PAGE = 10;

// ─────────────────────────────────────────────────────────────
//  INVENTORY QUERIES
// ─────────────────────────────────────────────────────────────

// $order: validated 'order=…' clause; $extra: prebuilt PostgREST filters (list_filters.php)
function getInventoryRecords(int $page, int $branchId, string $category, string $status, string $order = 'order=item_name.asc', array $extra = []): array
{
    $offset = ($page - 1) * INV_PER_PAGE;
    $q = inventoryRecordsQuery($page, $branchId, $category, $status, $order, $extra);

    // admin_get(): reuses the page's parallel prefetch (controllers/admin_batch.php) when present
    $result = (function_exists('admin_get') ? admin_get('inventory', implode('&', $q), ['Prefer: count=exact']) : supabase_request('inventory', 'GET', [], implode('&', $q), ['Prefer: count=exact']));
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'inventory', $q);

    return buildPageResult($rows, $total, $page, $offset, INV_PER_PAGE);
}

/** The PostgREST filter parts for getInventoryRecords() (also used to prefetch it). */
function inventoryRecordsQuery(int $page, int $branchId, string $category, string $status, string $order = 'order=item_name.asc', array $extra = []): array
{
    $offset = ($page - 1) * INV_PER_PAGE;

    $q = [
        'select=item_id,item_code,item_name,category,branch_id,stock_quantity,min_stock,status,created_at,branches(branch_name)',
        $order,
        'limit='  . INV_PER_PAGE,
        'offset=' . $offset,
        ...$extra,
    ];

    if ($branchId !== 0) $q[] = 'branch_id=eq.' . $branchId;   // -1 (no scope) matches nothing
    if ($category)     $q[] = 'category=eq.'  . urlencode($category);
    if ($status)       $q[] = 'status=eq.'    . urlencode($status);

    return $q;
}

const INV_CATEGORIES_QUERY = 'select=category&order=category.asc';

/** Fetch distinct categories for filter dropdown */
function getInventoryCategories(): array
{
    // admin_get(): reuses the page's parallel prefetch (controllers/admin_batch.php) when present
    $result = (function_exists('admin_get') ? admin_get('inventory', INV_CATEGORIES_QUERY, []) : supabase_request('inventory', 'GET', [], INV_CATEGORIES_QUERY, []));
    if (!is_array($result['data'])) return [];
    $cats = array_unique(array_column($result['data'], 'category'));
    sort($cats);
    return array_filter($cats);
}

// ─────────────────────────────────────────────────────────────
//  STOCK MOVEMENT QUERIES
// ─────────────────────────────────────────────────────────────

function getStockMovements(int $page, int $branchId, string $itemId): array
{
    $offset = ($page - 1) * MOV_PER_PAGE;
    $q = stockMovementsQuery($page, $branchId, $itemId);

    // admin_get(): reuses the page's parallel prefetch (controllers/admin_batch.php) when present
    $result = (function_exists('admin_get') ? admin_get('inventory_movements', implode('&', $q), ['Prefer: count=exact']) : supabase_request('inventory_movements', 'GET', [], implode('&', $q), ['Prefer: count=exact']));
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'inventory_movements', $q);

    return buildPageResult($rows, $total, $page, $offset, MOV_PER_PAGE);
}

/** The PostgREST filter parts for getStockMovements() (also used to prefetch it). */
function stockMovementsQuery(int $page, int $branchId, string $itemId): array
{
    $offset = ($page - 1) * MOV_PER_PAGE;

    $q = [
        'select=movement_id,movement_date,quantity_change,reason,performed_by,'
            . 'inventory(item_code,item_name),branches(branch_name)',
        'order=movement_date.desc,created_at.desc',
        'limit='  . MOV_PER_PAGE,
        'offset=' . $offset,
    ];

    if ($branchId !== 0) $q[] = 'branch_id=eq.' . $branchId;   // -1 (no scope) matches nothing
    if ($itemId)       $q[] = 'item_id=eq.'   . (int)$itemId;

    return $q;
}

// ─────────────────────────────────────────────────────────────
//  HELPERS
// ─────────────────────────────────────────────────────────────

function invStatusBadge(?string $status): string
{
    return match (strtolower($status ?? '')) {
        'in_stock', 'active' => 'bg-soft-success text-success',
        'low_stock'          => 'bg-soft-warning text-warning',
        'out_of_stock'       => 'bg-soft-danger text-danger',
        'inactive'           => 'bg-soft-secondary text-secondary',
        default              => 'bg-soft-secondary text-secondary',
    };
}

function invStatusLabel(?string $status): string
{
    return match (strtolower($status ?? '')) {
        'in_stock', 'active' => 'In Stock',
        'low_stock'          => 'Low Stock',
        'out_of_stock'       => 'Out of Stock',
        'inactive'           => 'Inactive',
        default              => ucfirst(str_replace('_', ' ', $status ?? '')),
    };
}

function movementBadge(int $change): string
{
    return $change >= 0
        ? 'bg-soft-success text-success'
        : 'bg-soft-danger text-danger';
}

function movementLabel(int $change): string
{
    return $change >= 0 ? '+' . $change : (string)$change;
}

function formatMovDate(?string $date): string
{
    return empty($date) ? '—' : date('M. d, Y', strtotime($date));
}