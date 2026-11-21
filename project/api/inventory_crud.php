<?php
// =============================================================
//  api/inventory_crud.php
//  Handles: add, edit, delete (archive), restore inventory items
//           + log_movement (Stock In / Out / Adjustment / Damaged /
//             Expired / Wastage — stock and movement saved together)
//  Owner + admin only (dentists: view only). Admins are pinned to their
//  own branch; every check uses the item's real branch from the database.
//
//  Data model (after database/proposed/2026-11-18_inventory_enhancement.sql):
//    inventory_items  master: name, category, unit, unit cost, supplier,
//                     expiry tracking — shared by all branches
//    inventory        one balance row per branch: stock, min stock,
//                     reorder quantity (existing table, existing ids)
//  Before that migration runs, the previous behaviour is kept.
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/stock_helper.php';
require_once __DIR__ . '/../controllers/inventory_v2.php';
require_once __DIR__ . '/../controllers/audit_controller.php';

header('Content-Type: application/json');
requireLogin();
requireStaffApi('owner', 'admin');

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'add':          echo json_encode(addItem($input));         break;
    case 'edit':         echo json_encode(editItem($input));        break;
    case 'delete':       echo json_encode(deleteItem($input));      break;
    case 'restore':      echo json_encode(restoreItem($input));     break;
    case 'log_movement': echo json_encode(logMovement($input));     break;
    default:             echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

// ── Shared field cleaning ─────────────────────────────────────
function inv_int($v, int $min = 0): ?int
{
    if ($v === '' || $v === null) return null;
    if (!is_numeric($v) || (string)(int)$v !== trim((string)$v)) return null;
    return (int)$v >= $min ? (int)$v : null;
}

/** Master fields from the form (validated). Returns [fields, error]. */
function inv_master_fields(array $d): array
{
    $name = trim((string)($d['item_name'] ?? ''));
    if ($name === '')            return [null, 'Item name is required.'];
    if (mb_strlen($name) > 120)  return [null, 'Item name is too long (120 characters max).'];
    $unit = trim((string)($d['unit'] ?? 'pcs')) ?: 'pcs';
    if (mb_strlen($unit) > 20)   return [null, 'Unit is too long.'];
    $cost = $d['unit_cost'] ?? '';
    if ($cost !== '' && $cost !== null && (!is_numeric($cost) || (float)$cost < 0)) return [null, 'Unit cost must be 0 or more.'];
    $sup = !empty($d['supplier_id']) ? (int)$d['supplier_id'] : null;
    if ($sup) {
        $s = supabase_request('suppliers', 'GET', [], 'select=supplier_id&supplier_id=eq.' . $sup . '&limit=1');
        if (empty($s['data'][0])) return [null, 'Selected supplier was not found.'];
    }
    $cat = trim((string)($d['category'] ?? ''));
    return [[
        'item_name'    => $name,
        'category'     => $cat !== '' ? mb_substr($cat, 0, 60) : null,
        'unit'         => $unit,
        'unit_cost'    => ($cost === '' || $cost === null) ? null : round((float)$cost, 2),
        'supplier_id'  => $sup,
        'track_expiry' => !empty($d['track_expiry']),
    ], null];
}

/** The master whose name matches (case/space-insensitive), or null. */
function inv_find_master(string $name): ?array
{
    $key = mb_strtolower(trim($name));
    $r = supabase_request('inventory_items', 'GET', [], 'select=*&order=master_id.asc&limit=2000');
    foreach (($r['data'] ?? []) as $m) if (mb_strtolower(trim($m['item_name'])) === $key) return $m;
    return null;
}

function inv_branch_name(?int $id): string { return $id ? (inv_branch_names()[$id] ?? ('Branch #' . $id)) : ''; }

// ── Add Item ──────────────────────────────────────────────────
function addItem(array $d): array {
    $branchId = (int)($d['branch_id'] ?? 0);
    if (!$branchId) return ['success' => false, 'message' => 'Branch is required.'];
    if (!canAccessBranch($branchId)) {
        http_response_code(403);
        return ['success' => false, 'message' => 'You can only add items to your own branch.'];
    }
    $qty = inv_int($d['stock_quantity'] ?? 0) ?? -1;
    $min = inv_int($d['min_stock'] ?? 0);
    $reo = inv_int($d['reorder_qty'] ?? 0);
    if ($qty < 0)         return ['success' => false, 'message' => 'Opening stock must be a whole number, 0 or more.'];
    if ($min === null)    return ['success' => false, 'message' => 'Minimum stock must be a whole number, 0 or more.'];
    if ($reo === null)    return ['success' => false, 'message' => 'Reorder quantity must be a whole number, 0 or more.'];

    [$mf, $err] = inv_master_fields($d);
    if ($err) return ['success' => false, 'message' => $err];

    // ── Before the migration: previous behaviour (with the code-format fix) ──
    if (!inv_v2_ready()) {
        $r = supabase_request('inventory', 'POST', [
            'item_code' => inv_next_item_code(), 'item_name' => $mf['item_name'], 'category' => $mf['category'],
            'branch_id' => $branchId, 'stock_quantity' => $qty, 'min_stock' => $min,
            'status' => stock_status($qty, $min), 'created_at' => date('c'), 'updated_at' => date('c'),
        ]);
        if ($r['error']) return ['success' => false, 'message' => $r['error']];
        logActivity('inventory', 'INSERT', $r['data'][0]['item_id'] ?? '', $mf['item_name'],
            'Added item to ' . inv_branch_name($branchId) . " (stock {$qty}, minimum {$min})", $branchId, inv_branch_name($branchId));
        return ['success' => true, 'message' => 'Item added successfully.'];
    }

    // Opening batch (lot / expiry) — required for expiry-tracked items with stock
    $lot = trim((string)($d['lot_number'] ?? ''));
    $exp = inv_date($d['expiry_date'] ?? '');
    $mfd = inv_date($d['manufactured_date'] ?? '');
    if (($d['expiry_date'] ?? '') !== '' && !$exp)      return ['success' => false, 'message' => 'Expiry date is not a valid date.'];
    if (($d['manufactured_date'] ?? '') !== '' && !$mfd) return ['success' => false, 'message' => 'Manufacturing date is not a valid date.'];
    if ($mfd && $exp && $mfd > $exp)                     return ['success' => false, 'message' => 'Manufacturing date must be before the expiry date.'];

    $master = inv_find_master($mf['item_name']);
    if ($master) {
        // Already a known item: this adds it to the branch. Shared details stay as they are.
        $dupe = supabase_request('inventory', 'GET', [],
            'select=item_id&archived_at=is.null&branch_id=eq.' . $branchId . '&master_id=eq.' . (int)$master['master_id'] . '&limit=1');
        if (!empty($dupe['data'][0])) {
            return ['success' => false, 'message' => '"' . $master['item_name'] . '" is already in ' . inv_branch_name($branchId)
                . '\'s inventory. Use Log Movement to add stock.'];
        }
        $track = !empty($master['track_expiry']);
    } else {
        $mr = supabase_request('inventory_items', 'POST', $mf + ['created_at' => date('c'), 'updated_at' => date('c')]);
        if ($mr['error'] || empty($mr['data'][0])) return ['success' => false, 'message' => $mr['error'] ?: 'Could not save the item.'];
        $master = $mr['data'][0];
        $track  = $mf['track_expiry'];
    }
    if ($track && $qty > 0 && !$exp) return ['success' => false, 'message' => 'This item tracks expiry dates — enter the expiry date of the opening stock.'];

    $r = supabase_request('inventory', 'POST', [
        'item_code' => inv_next_item_code(), 'item_name' => $master['item_name'], 'category' => $master['category'],
        'branch_id' => $branchId, 'master_id' => (int)$master['master_id'], 'stock_quantity' => 0,
        'min_stock' => $min, 'reorder_qty' => $reo, 'status' => 'out_of_stock',
        'created_at' => date('c'), 'updated_at' => date('c'),
    ]);
    if ($r['error'] || empty($r['data'][0])) {
        $msg = ($r['data']['code'] ?? '') === '23505' ? 'This item is already in that branch\'s inventory.' : ($r['error'] ?: 'Could not save the item.');
        return ['success' => false, 'message' => $msg];
    }
    $itemId = (int)$r['data'][0]['item_id'];

    $note = '';
    if ($qty > 0) {
        $mv = inv_move($itemId, $qty, 'stock_in', [
            'reason'      => 'Opening stock',
            'batches'     => ($lot !== '' || $exp) ? [['lot_number' => $lot, 'expiry_date' => $exp, 'manufactured_date' => $mfd, 'quantity' => $qty, 'source' => 'opening']] : null,
            'request_key' => 'open:' . $itemId,
        ]);
        if (!$mv['ok']) $note = ' The opening stock could not be recorded (' . $mv['message'] . '); add it with Log Movement.';
    }
    logActivity('inventory', 'INSERT', $itemId, $master['item_name'],
        'Added item to ' . inv_branch_name($branchId) . " (opening stock {$qty} " . inv_unit_label($master['unit'], $qty) . ", minimum {$min}, reorder {$reo})",
        $branchId, inv_branch_name($branchId));
    return ['success' => true, 'message' => 'Item added successfully.' . $note];
}

// ── Edit Item ─────────────────────────────────────────────────
function editItem(array $d): array {
    $itemId = (int)($d['item_id'] ?? 0);
    if (!$itemId) return ['success' => false, 'message' => 'Missing item ID.'];

    $cur = stock_rows([$itemId])[$itemId] ?? null;
    if (!$cur) return ['success' => false, 'message' => 'Item not found.'];
    if (!empty($cur['archived_at'])) return ['success' => false, 'message' => 'This item is archived. Restore it first.'];
    $curBranch = (int)($cur['branch_id'] ?? 0);
    if (!canAccessBranch($curBranch)) {
        http_response_code(403);
        return ['success' => false, 'message' => 'You can only manage items in your own branch.'];
    }
    $min = inv_int($d['min_stock'] ?? 0);
    if ($min === null) return ['success' => false, 'message' => 'Minimum stock must be a whole number, 0 or more.'];
    [$mf, $err] = inv_master_fields($d);
    if ($err) return ['success' => false, 'message' => $err];
    $oldQty = (int)$cur['stock_quantity'];

    // ── Before the migration: previous behaviour ──
    if (!inv_v2_ready()) {
        $targetBranch = !empty($d['branch_id']) ? (int)$d['branch_id'] : $curBranch;
        if (!canAccessBranch($targetBranch)) { http_response_code(403); return ['success' => false, 'message' => 'You can only manage items in your own branch.']; }
        $newQty = inv_int($d['stock_quantity'] ?? $oldQty) ?? -1;
        if ($newQty < 0) return ['success' => false, 'message' => 'Stock cannot be negative.'];
        if ($newQty < $oldQty && $newQty < STOCK_FLOOR) return ['success' => false, 'message' => 'Stock must keep at least ' . STOCK_FLOOR . ' unit. Use a stock movement with a reason to record usage or disposal.'];
        $r = supabase_request('inventory', 'PATCH', [
            'item_name' => $mf['item_name'], 'category' => $mf['category'], 'branch_id' => $targetBranch,
            'stock_quantity' => $newQty, 'min_stock' => $min, 'status' => stock_status($newQty, $min), 'updated_at' => date('c'),
        ], 'item_id=eq.' . $itemId . '&stock_quantity=eq.' . $oldQty);
        if (!$r['error'] && empty($r['data'])) return ['success' => false, 'message' => 'Stock changed while you were editing. Please reload and try again.'];
        if ($r['error']) return ['success' => false, 'message' => $r['error']];
        staffn_stock_touched($itemId);   // stock / minimum changed → re-check low-stock alert
        if ($newQty !== $oldQty) {   // keep the history complete
            supabase_request('inventory_movements', 'POST', ['item_id' => $itemId, 'branch_id' => $targetBranch,
                'quantity_change' => $newQty - $oldQty, 'reason' => 'Stock corrected in item details',
                'performed_by' => inv_actor()[1], 'movement_date' => date('Y-m-d'), 'created_at' => date('c')]);
        }
        logActivity('inventory', 'UPDATE', $itemId, $mf['item_name'], 'Edited item details', $targetBranch, inv_branch_name($targetBranch));
        return ['success' => true, 'message' => 'Item updated successfully.'];
    }

    $reo = inv_int($d['reorder_qty'] ?? 0);
    if ($reo === null) return ['success' => false, 'message' => 'Reorder quantity must be a whole number, 0 or more.'];
    // Stock is never edited here: every change needs a movement with a reason
    if (isset($d['stock_quantity']) && $d['stock_quantity'] !== '' && (int)$d['stock_quantity'] !== $oldQty) {
        return ['success' => false, 'message' => 'Stock is changed with Log Movement (Stock In, Stock Adjustment, …) so every change has a reason.'];
    }
    if (!empty($d['branch_id']) && (int)$d['branch_id'] !== $curBranch) {
        return ['success' => false, 'message' => 'To move stock to another branch, use Stock Transfers.'];
    }

    $full = supabase_request('inventory', 'GET', [], 'select=item_id,master_id,min_stock,reorder_qty,inventory_items(*)&item_id=eq.' . $itemId);
    $row  = $full['data'][0] ?? null;
    $m    = $row['inventory_items'] ?? null;
    if (!$row || !$m) return ['success' => false, 'message' => 'Item details could not be read. Please reload.'];
    $masterId = (int)$m['master_id'];

    // Shared details (name, unit, cost, supplier, expiry tracking) apply to every branch.
    $sharedNew = $mf;
    $sharedOld = array_intersect_key($m, $sharedNew);
    $sharedChanged = [];
    foreach ($sharedNew as $k => $v) {
        $o = $sharedOld[$k] ?? null;
        $same = $k === 'unit_cost' ? (($o === null) === ($v === null) && round((float)$o, 2) === round((float)$v, 2))
              : ($k === 'track_expiry' ? (bool)$o === (bool)$v : (string)$o === (string)$v);
        if (!$same) $sharedChanged[$k] = $v;
    }
    if ($sharedChanged && !isOwner()) {
        // An admin may change them only when no other branch stocks the item
        $others = supabase_request('inventory', 'GET', [],
            'select=item_id&archived_at=is.null&master_id=eq.' . $masterId . '&branch_id=neq.' . $curBranch . '&limit=1');
        if (!empty($others['data'][0])) {
            return ['success' => false, 'message' => 'Name, category, unit, cost, supplier and expiry tracking are shared by all branches. Ask the owner to change them.'];
        }
    }
    if ($sharedChanged) {
        if (isset($sharedChanged['item_name'])) {
            $other = inv_find_master($sharedChanged['item_name']);
            if ($other && (int)$other['master_id'] !== $masterId) return ['success' => false, 'message' => 'Another item is already called "' . $other['item_name'] . '".'];
        }
        $mu = supabase_request('inventory_items', 'PATCH', $sharedChanged + ['updated_at' => date('c')], 'master_id=eq.' . $masterId);
        if ($mu['error']) return ['success' => false, 'message' => $mu['error']];
    }

    // Branch details: minimum and reorder quantity (status follows the minimum)
    $r = supabase_request('inventory', 'PATCH', [
        'min_stock' => $min, 'reorder_qty' => $reo, 'status' => stock_status($oldQty, $min), 'updated_at' => date('c'),
    ], 'item_id=eq.' . $itemId . '&stock_quantity=eq.' . $oldQty);
    if (!$r['error'] && empty($r['data'])) return ['success' => false, 'message' => 'Stock changed while you were editing. Please reload and try again.'];
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    staffn_stock_touched($itemId);   // minimum changed → re-check low-stock alert

    $before = $sharedOld + ['min_stock' => (int)$row['min_stock'], 'reorder_qty' => (int)$row['reorder_qty']];
    $after  = $sharedNew + ['min_stock' => $min, 'reorder_qty' => $reo];
    $changed = array_keys(array_filter($after, fn($v, $k) => (string)($before[$k] ?? '') !== (string)$v, ARRAY_FILTER_USE_BOTH));
    logActivity('inventory', 'UPDATE', $itemId, $mf['item_name'],
        $changed ? 'Changed ' . implode(', ', str_replace('_', ' ', $changed)) : 'Saved item details (no changes)',
        $curBranch, inv_branch_name($curBranch),
        null, array_intersect_key($before, array_flip($changed)) ?: null, array_intersect_key($after, array_flip($changed)) ?: null);
    return ['success' => true, 'message' => 'Item updated successfully.'];
}

// ── Delete Item ───────────────────────────────────────────────
// An item with history (stock movements or materials used in treatments) is
// ARCHIVED instead: hidden from lists and dropdowns, history kept. An item
// without history is deleted for real. Response: archived = true|false.
function deleteItem(array $d): array {
    if (empty($d['item_id'])) return ['success' => false, 'message' => 'Missing item ID.'];
    $itemId = (int)$d['item_id'];

    $cur = stock_rows([$itemId])[$itemId] ?? null;
    if (!$cur) return ['success' => false, 'message' => 'Item not found.'];
    if (!canAccessBranch($cur['branch_id'] ?? null)) {
        http_response_code(403);
        return ['success' => false, 'message' => 'You can only manage items in your own branch.'];
    }
    if (!empty($cur['archived_at'])) return ['success' => false, 'message' => 'This item is already archived.'];
    $bid = (int)$cur['branch_id'];

    if (!inv_has_history($itemId)) {
        supabase_request('service_materials', 'DELETE', [], 'item_id=eq.' . $itemId);
        $r = supabase_request('inventory', 'DELETE', [], 'item_id=eq.' . $itemId);
        if (!$r['error']) {
            logActivity('inventory', 'DELETE', $itemId, $cur['item_name'], 'Deleted item (no stock history)', $bid, inv_branch_name($bid));
            return ['success' => true, 'archived' => false, 'message' => 'Item deleted.'];
        }
        if (($r['data']['code'] ?? '') !== '23503') return ['success' => false, 'message' => $r['error']];
    }
    $res = archiveItem($itemId);
    if ($res['success']) logActivity('inventory', 'UPDATE', $itemId, $cur['item_name'], 'Archived item (stock history kept)', $bid, inv_branch_name($bid));
    return $res;
}

/** True when the item appears in stock movements, treatment materials, batches, transfers or orders. */
function inv_has_history(int $itemId): bool
{
    $tables = ['inventory_movements', 'treatment_materials'];
    if (inv_v2_ready()) array_push($tables, 'inventory_batches', 'purchase_receipt_items');
    foreach ($tables as $t) {
        $r = supabase_request($t, 'GET', [], 'item_id=eq.' . $itemId . '&select=item_id&limit=1');
        if ($r['error'] || !is_array($r['data'])) return true;
        if ($r['data']) return true;
    }
    if (inv_v2_ready()) {
        $r = supabase_request('stock_transfer_items', 'GET', [], 'or=(from_item_id.eq.' . $itemId . ',to_item_id.eq.' . $itemId . ')&select=line_id&limit=1');
        if ($r['error'] || !empty($r['data'])) return true;
    }
    return false;
}

function archiveItem(int $itemId): array
{
    $r = supabase_request('inventory', 'PATCH', ['archived_at' => date('c'), 'updated_at' => date('c')],
        'item_id=eq.' . $itemId . '&archived_at=is.null');
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    staffn_stock_touched($itemId);   // archived → its stock alerts close
    supabase_request('service_materials', 'DELETE', [], 'item_id=eq.' . $itemId);
    return ['success' => true, 'archived' => true,
            'message' => 'Item archived. Its stock history is kept; you can restore it from the Archived view.'];
}

// ── Restore an archived item ─────────────────────────────────
function restoreItem(array $d): array {
    if (empty($d['item_id'])) return ['success' => false, 'message' => 'Missing item ID.'];
    $itemId = (int)$d['item_id'];
    $cur = stock_rows([$itemId])[$itemId] ?? null;
    if (!$cur) return ['success' => false, 'message' => 'Item not found.'];
    if (!canAccessBranch($cur['branch_id'] ?? null)) {
        http_response_code(403);
        return ['success' => false, 'message' => 'You can only manage items in your own branch.'];
    }
    if (empty($cur['archived_at'])) return ['success' => false, 'message' => 'This item is not archived.'];

    $r = supabase_request('inventory', 'PATCH', ['archived_at' => null, 'updated_at' => date('c')], 'item_id=eq.' . $itemId);
    if ($r['error']) {
        $msg = ($r['data']['code'] ?? '') === '23505' ? 'This branch already has an active "' . $cur['item_name'] . '". Archive that one first.' : $r['error'];
        return ['success' => false, 'message' => $msg];
    }
    staffn_stock_touched($itemId);   // restored → low / out-of-stock alert again if still short
    $bid = (int)$cur['branch_id'];
    logActivity('inventory', 'UPDATE', $itemId, $cur['item_name'], 'Restored archived item', $bid, inv_branch_name($bid));
    return ['success' => true, 'message' => 'Item restored.'];
}

// ── Log Stock Movement ────────────────────────────────────────
// type: stock_in | stock_out | adjustment | damaged | expired | wastage
//   quantity        units moved (> 0); for adjustment: the COUNTED stock (>= 0)
//   reason          required
//   reference_no    optional (delivery receipt, incident no., …)
//   stock_in:       lot_number / expiry_date / manufactured_date (optional; expiry
//                   required for expiry-tracked items)
//   decreases:      batch_id (optional — otherwise first-expiry-first)
//   request_key     client-made id: a repeated submission is not logged twice
function logMovement(array $d): array {
    $itemId = (int)($d['item_id'] ?? 0);
    $type   = (string)($d['movement_type'] ?? '');
    $reason = trim((string)($d['reason'] ?? ''));
    if (!$itemId) return ['success' => false, 'message' => 'Item is required.'];

    // Old clients send only a signed quantity_change
    if ($type === '' && isset($d['quantity_change'])) {
        $qc = (int)$d['quantity_change'];
        $type = $qc >= 0 ? 'stock_in' : 'stock_out';
        $d['quantity'] = abs($qc);
    }
    if (!isset(INV_TYPES[$type]) || !INV_TYPES[$type][3]) return ['success' => false, 'message' => 'Choose a movement type.'];
    if ($reason === '')               return ['success' => false, 'message' => 'Reason is required.'];
    if (mb_strlen($reason) > 250)     return ['success' => false, 'message' => 'Reason is too long (250 characters max).'];
    $ref = trim((string)($d['reference_no'] ?? ''));
    if (mb_strlen($ref) > 60)         return ['success' => false, 'message' => 'Reference number is too long.'];

    $qty = inv_int($d['quantity'] ?? null, $type === 'adjustment' ? 0 : 1);
    if ($qty === null) return ['success' => false, 'message' => $type === 'adjustment' ? 'Enter the counted stock (0 or more).' : 'Enter a quantity greater than 0.'];

    $cur = stock_rows([$itemId])[$itemId] ?? null;
    if (!$cur) return ['success' => false, 'message' => 'Item not found.'];
    if (!empty($cur['archived_at'])) return ['success' => false, 'message' => 'This item is archived. Restore it first.'];
    $branchId = (int)$cur['branch_id'];
    if (!canAccessBranch($branchId)) {
        http_response_code(403);
        return ['success' => false, 'message' => 'You can only manage items in your own branch.'];
    }

    $change = match ($type) {
        'stock_in'   => $qty,
        'adjustment' => $qty - (int)$cur['stock_quantity'],
        default      => -$qty,
    };
    if ($change === 0) return ['success' => false, 'message' => 'The counted stock is the same as the current stock — nothing to adjust.'];

    $opts = ['reason' => $reason, 'reference' => $ref ?: null, 'request_key' => inv_request_key($d['request_key'] ?? null)];
    if (inv_v2_ready()) {
        if ($change > 0) {
            $lot = trim((string)($d['lot_number'] ?? ''));
            $exp = inv_date($d['expiry_date'] ?? '');
            $mfd = inv_date($d['manufactured_date'] ?? '');
            if (($d['expiry_date'] ?? '') !== '' && !$exp)       return ['success' => false, 'message' => 'Expiry date is not a valid date.'];
            if (($d['manufactured_date'] ?? '') !== '' && !$mfd) return ['success' => false, 'message' => 'Manufacturing date is not a valid date.'];
            if ($mfd && $exp && $mfd > $exp)                      return ['success' => false, 'message' => 'Manufacturing date must be before the expiry date.'];
            if ($type === 'stock_in') {
                $mt = supabase_request('inventory', 'GET', [], 'select=inventory_items(track_expiry)&item_id=eq.' . $itemId);
                if (!empty($mt['data'][0]['inventory_items']['track_expiry']) && !$exp) return ['success' => false, 'message' => 'This item tracks expiry dates — enter the expiry date of this stock.'];
            }
            if ($lot !== '' || $exp) {
                $opts['batches'] = [['lot_number' => mb_substr($lot, 0, 60), 'expiry_date' => $exp, 'manufactured_date' => $mfd,
                                     'quantity' => $change, 'source' => 'manual']];
            }
        } elseif (!empty($d['batch_id'])) {
            $opts['batch_id'] = (int)$d['batch_id'];
        }
    }

    $r = inv_move($itemId, $change, $type, $opts);
    if (!$r['ok']) return ['success' => false, 'message' => $r['message'] ?: 'Could not save the movement.'];
    if (!$r['duplicate']) {
        logActivity('inventory', 'UPDATE', $itemId, $cur['item_name'],
            sprintf('%s: %+d (%d → %d). Reason: %s%s', inv_type_label($type), $change, $r['before'] ?? 0, $r['after'] ?? 0, $reason, $ref ? " · Ref {$ref}" : ''),
            $branchId, inv_branch_name($branchId), null,
            ['stock_quantity' => $r['before']], ['stock_quantity' => $r['after'], 'movement_type' => $type]);
        // Staff bell: manual corrections only (adjustment / stock out / damaged / expired / wastage)
        staffn_stock_adjusted($itemId, (string)$cur['item_name'], $branchId, inv_type_label($type), $type, $change,
            $r['before'] ?? null, $r['after'] ?? null, (string)$reason, $r['movement_id'] ?? null);
    }
    return ['success' => true, 'message' => inv_type_label($type) . ' saved. New stock: ' . $r['after'] . '.', 'new_stock' => $r['after']];
}
