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

function getInventoryRecords(int $page, int $branchId, string $category, string $status): array
{
    $offset = ($page - 1) * INV_PER_PAGE;

    $q = [
        'select=item_id,item_code,item_name,category,stock_quantity,min_stock,status,branches(branch_name)',
        'order=item_name.asc',
        'limit='  . INV_PER_PAGE,
        'offset=' . $offset,
    ];

    if ($branchId > 0) $q[] = 'branch_id=eq.' . $branchId;
    if ($category)     $q[] = 'category=eq.'  . urlencode($category);
    if ($status)       $q[] = 'status=eq.'    . urlencode($status);

    $result = supabase_request('inventory', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'inventory', $q);

    return buildPageResult($rows, $total, $page, $offset, INV_PER_PAGE);
}

/** Fetch distinct categories for filter dropdown */
function getInventoryCategories(): array
{
    $result = supabase_request('inventory', 'GET', [], 'select=category&order=category.asc');
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

    $q = [
        'select=movement_id,movement_date,quantity_change,reason,performed_by,'
            . 'inventory(item_code,item_name),branches(branch_name)',
        'order=movement_date.desc,created_at.desc',
        'limit='  . MOV_PER_PAGE,
        'offset=' . $offset,
    ];

    if ($branchId > 0) $q[] = 'branch_id=eq.' . $branchId;
    if ($itemId)       $q[] = 'item_id=eq.'   . (int)$itemId;

    $result = supabase_request('inventory_movements', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'inventory_movements', $q);

    return buildPageResult($rows, $total, $page, $offset, MOV_PER_PAGE);
}

// ─────────────────────────────────────────────────────────────
//  HELPERS
// ─────────────────────────────────────────────────────────────

function invStatusBadge(?string $status): string
{
    return match (strtolower($status ?? 'active')) {
        'active'      => 'bg-soft-success text-success',
        'low_stock'   => 'bg-soft-warning text-warning',
        'out_of_stock'=> 'bg-soft-danger text-danger',
        'inactive'    => 'bg-soft-secondary text-secondary',
        default       => 'bg-soft-secondary text-secondary',
    };
}

function invStatusLabel(?string $status): string
{
    return match (strtolower($status ?? 'active')) {
        'low_stock'    => 'Low Stock',
        'out_of_stock' => 'Out of Stock',
        default        => ucfirst($status ?? 'Active'),
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