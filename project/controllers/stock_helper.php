<?php
// =============================================================
//  controllers/stock_helper.php — shared stock rules
//
//  Every stock deduction goes through stock_deduct()/stock_adjust() so the
//  same rules apply everywhere (Active Treatments, manual movements, edits):
//
//  • Stock floor: a normal deduction must leave at least STOCK_FLOOR unit(s).
//    Items already at 0 are left as they are (no invented stock) and are
//    treated as unavailable.
//  • Branch: a material configured on a service points at one branch's
//    inventory row; it is resolved to the same-named item of the branch
//    where the treatment happens.
//  • Concurrency: each update is a compare-and-swap on the quantity that
//    was read (PATCH … &stock_quantity=eq.<read>). If another request changed
//    the row first, nothing is written and the check is retried on fresh
//    data, so two users cannot both consume the last usable units.
//  • All-or-nothing: if one item fails, the items already deducted in the
//    same call are put back before the error is returned.
// =============================================================
require_once __DIR__ . '/../dbconfig.php';

const STOCK_FLOOR = 1;

// Status values used by the inventory table (see invStatusLabel)
function stock_status(int $qty, int $min): string
{
    if ($qty <= 0) return 'out_of_stock';
    return $qty <= $min ? 'low_stock' : 'in_stock';
}

/** Units that can be used without going below the floor. */
function stock_usable(int $qty): int
{
    return max(0, $qty - STOCK_FLOOR);
}

/** item_id => row for the given ids (fresh read). */
function stock_rows(array $itemIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
    if (!$ids) return [];
    $res = supabase_request('inventory', 'GET', [],
        'item_id=in.(' . implode(',', $ids) . ')&select=item_id,item_code,item_name,branch_id,stock_quantity,min_stock,status');
    $out = [];
    foreach (($res['data'] ?? []) as $r) $out[(int)$r['item_id']] = $r;
    return $out;
}

/**
 * Maps item ids to the matching item in $branchId (same item name).
 * Returns [given_id => branch_item_row|null]. Ids already in the branch map to themselves.
 */
function stock_resolve_branch(array $itemIds, int $branchId): array
{
    $given = stock_rows($itemIds);
    $map   = [];
    $needNames = [];
    foreach ($itemIds as $id) {
        $id = (int)$id;
        $row = $given[$id] ?? null;
        if ($row && (!$branchId || (int)$row['branch_id'] === $branchId)) { $map[$id] = $row; continue; }
        $map[$id] = null;
        if ($row) $needNames[$id] = strtolower(trim($row['item_name']));
    }
    if ($needNames && $branchId) {
        $res = supabase_request('inventory', 'GET', [],
            'branch_id=eq.' . $branchId . '&status=neq.inactive&select=item_id,item_code,item_name,branch_id,stock_quantity,min_stock,status');
        $byName = [];
        foreach (($res['data'] ?? []) as $r) $byName[strtolower(trim($r['item_name']))] = $r;
        foreach ($needNames as $id => $name) $map[$id] = $byName[$name] ?? null;
    }
    return $map;
}

/**
 * Combines a material list into one requirement per branch item and checks it.
 * $materials: [{item_id, quantity|quantity_used|qty}]  (same item may repeat — summed once)
 * Returns ['ok' => bool, 'items' => [...], 'shortages' => [...]]
 */
function stock_check(array $materials, int $branchId): array
{
    $want = [];
    foreach ($materials as $m) {
        $id  = (int)($m['item_id'] ?? 0);
        $qty = (int)($m['quantity_used'] ?? $m['quantity'] ?? $m['qty'] ?? 0);
        if ($id && $qty > 0) $want[$id] = ($want[$id] ?? 0) + $qty;
    }
    $resolved = stock_resolve_branch(array_keys($want), $branchId);

    $need = []; // branch item_id => combined requirement
    $missing = [];
    foreach ($want as $id => $qty) {
        $row = $resolved[$id] ?? null;
        if (!$row) { $missing[$id] = $qty; continue; }
        $bid = (int)$row['item_id'];
        $need[$bid] = ($need[$bid] ?? 0) + $qty;
    }
    $rows  = stock_rows(array_keys($need));
    $items = [];
    $short = [];
    foreach ($need as $bid => $qty) {
        $r     = $rows[$bid];
        $avail = (int)$r['stock_quantity'];
        $item  = [
            'item_id'   => $bid,
            'item_name' => $r['item_name'],
            'item_code' => $r['item_code'],
            'available' => $avail,
            'required'  => $qty,
            'usable'    => stock_usable($avail),
            'shortage'  => max(0, $qty - stock_usable($avail)),
            'source_ids'=> array_keys(array_filter($resolved, fn($x) => $x && (int)$x['item_id'] === $bid)),
        ];
        $items[] = $item;
        if ($item['shortage'] > 0) $short[] = $item;
    }
    foreach ($missing as $id => $qty) {
        $short[] = ['item_id' => $id, 'item_name' => stock_rows([$id])[$id]['item_name'] ?? ('Item #' . $id),
                    'item_code' => '', 'available' => 0, 'required' => $qty, 'usable' => 0, 'shortage' => $qty,
                    'not_in_branch' => true, 'source_ids' => [$id]];
    }
    return ['ok' => !$short, 'items' => $items, 'shortages' => $short];
}

/** Human-readable message for a shortage list. */
function stock_shortage_message(array $shortages): string
{
    $parts = array_map(function ($s) {
        if (!empty($s['not_in_branch'])) return $s['item_name'] . ' (not stocked at this branch)';
        return sprintf('%s (available %d, required %d, short %d)', $s['item_name'], $s['available'], $s['required'], $s['shortage']);
    }, $shortages);
    return 'Not enough stock — every material must keep at least ' . STOCK_FLOOR . ' unit in stock: ' . implode('; ', $parts) . '.';
}

/** One compare-and-swap write. Returns the updated row or null on conflict/error. */
function stock_cas(array $row, int $newQty): ?array
{
    $res = supabase_request('inventory', 'PATCH',
        ['stock_quantity' => $newQty, 'status' => stock_status($newQty, (int)$row['min_stock']), 'updated_at' => date('c')],
        'item_id=eq.' . (int)$row['item_id'] . '&stock_quantity=eq.' . (int)$row['stock_quantity']);
    return ($res['status'] >= 200 && $res['status'] < 300 && !empty($res['data'][0])) ? $res['data'][0] : null;
}

/**
 * Applies a signed change to one item with the floor rule and CAS retries.
 * Returns ['ok' => bool, 'message' => string, 'before' => int, 'after' => int].
 */
function stock_apply(int $itemId, int $change, int $tries = 3): array
{
    for ($i = 0; $i < $tries; $i++) {
        $row = stock_rows([$itemId])[$itemId] ?? null;
        if (!$row) return ['ok' => false, 'message' => 'Item not found.'];
        $cur = (int)$row['stock_quantity'];
        $new = $cur + $change;
        if ($change < 0 && $new < STOCK_FLOOR) {
            return ['ok' => false, 'before' => $cur,
                'message' => sprintf('"%s" must keep at least %d unit in stock. Available: %d, requested: %d, usable now: %d.',
                    $row['item_name'], STOCK_FLOOR, $cur, -$change, stock_usable($cur))];
        }
        if (stock_cas($row, $new)) return ['ok' => true, 'message' => '', 'before' => $cur, 'after' => $new];
        usleep(80000); // another request changed it — re-read and re-check
    }
    return ['ok' => false, 'message' => 'Stock changed while saving. Please try again.'];
}

/**
 * Deducts a combined material list from $branchId, all-or-nothing, and logs movements.
 * Returns ['ok' => bool, 'message' => string, 'shortages' => [...], 'deducted' => [branch_item_id => qty]]
 */
function stock_deduct(array $materials, int $branchId, string $reason, string $performedBy): array
{
    $check = stock_check($materials, $branchId);
    if (!$check['ok']) return ['ok' => false, 'message' => stock_shortage_message($check['shortages']), 'shortages' => $check['shortages'], 'deducted' => []];

    $done = [];
    foreach ($check['items'] as $it) {
        $r = stock_apply((int)$it['item_id'], -(int)$it['required']);
        if (!$r['ok']) {
            foreach ($done as $id => $q) stock_apply($id, $q); // put back what this call already took
            return ['ok' => false, 'message' => $r['message'], 'shortages' => [$it], 'deducted' => []];
        }
        $done[(int)$it['item_id']] = (int)$it['required'];
    }
    $today = date('Y-m-d');
    foreach ($done as $id => $q) {
        supabase_request('inventory_movements', 'POST', [
            'item_id' => $id, 'branch_id' => $branchId ?: null, 'quantity_change' => -$q,
            'reason' => $reason, 'performed_by' => $performedBy, 'movement_date' => $today,
        ]);
    }
    return ['ok' => true, 'message' => '', 'shortages' => [], 'deducted' => $done, 'items' => $check['items']];
}
