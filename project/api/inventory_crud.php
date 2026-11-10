<?php
// =============================================================
//  api/inventory_crud.php
//  Handles: add, edit, delete inventory items
//           + log_movement (add stock movement + update stock)
//  Owner (view only via pages) — Admin can add/edit/delete
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/stock_helper.php';

header('Content-Type: application/json');
requireLogin();

// Only owners and admins modify inventory (dentists and anyone else: 403).
// Admins are pinned to their own branch; every check below uses the item's
// real branch from the database.
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

// ── Generate Item Code ────────────────────────────────────────
function generateItemCode(): string {
    $r    = supabase_request('inventory', 'GET', [], 'select=item_code&order=item_id.desc&limit=1');
    $last = $r['data'][0]['item_code'] ?? 'ITM-000';
    $num  = (int)substr($last, 4) + 1;
    return 'ITM-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

// ── Add Item ──────────────────────────────────────────────────
function addItem(array $d): array {
    if (empty($d['item_name']))  return ['success' => false, 'message' => 'Item name is required.'];
    if (empty($d['branch_id']))  return ['success' => false, 'message' => 'Branch is required.'];

    // Admins locked to their own branch
    if (!canAccessBranch((int)$d['branch_id'])) {
        http_response_code(403);
        return ['success' => false, 'message' => 'You can only add items to your own branch.'];
    }

    $payload = [
        'item_code'      => generateItemCode(),
        'item_name'      => trim($d['item_name']),
        'category'       => $d['category']       ?? null,
        'branch_id'      => (int)$d['branch_id'],
        'stock_quantity' => max(0, (int)($d['stock_quantity'] ?? 0)),
        'min_stock'      => (int)($d['min_stock']      ?? 0),
        // Status always follows the quantity (the database trigger enforces the same rule)
        'status'         => stock_status(max(0, (int)($d['stock_quantity'] ?? 0)), (int)($d['min_stock'] ?? 0)),
        'created_at'     => date('c'),
        'updated_at'     => date('c'),
    ];

    $r = supabase_request('inventory', 'POST', $payload);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Item added successfully.'];
}

// ── Edit Item ─────────────────────────────────────────────────
function editItem(array $d): array {
    if (empty($d['item_id']))   return ['success' => false, 'message' => 'Missing item ID.'];
    if (empty($d['item_name'])) return ['success' => false, 'message' => 'Item name is required.'];

    $cur = stock_rows([(int)$d['item_id']])[(int)$d['item_id']] ?? null;
    if (!$cur) return ['success' => false, 'message' => 'Item not found.'];
    if (!empty($cur['archived_at'])) return ['success' => false, 'message' => 'This item is archived. Restore it first.'];

    // SECURITY: the item's CURRENT branch (from the database) must be the user's,
    // and so must the branch it is saved under. Admins therefore can't edit
    // another branch's item or move an item to/from another branch; the owner can.
    // No branch in the request = keep the item's current branch.
    $curBranch    = (int)($cur['branch_id'] ?? 0);
    $targetBranch = !empty($d['branch_id']) ? (int)$d['branch_id'] : $curBranch;
    if (!canAccessBranch($curBranch) || !canAccessBranch($targetBranch)) {
        http_response_code(403);
        return ['success' => false, 'message' => 'You can only manage items in your own branch.'];
    }
    $newQty = (int)($d['stock_quantity'] ?? 0);
    $oldQty = (int)$cur['stock_quantity'];
    if ($newQty < 0) return ['success' => false, 'message' => 'Stock cannot be negative.'];
    // Floor rule: an edit may not lower stock below 1 (an item already at 0 may stay at 0)
    if ($newQty < $oldQty && $newQty < STOCK_FLOOR) {
        return ['success' => false, 'message' => 'Stock must keep at least ' . STOCK_FLOOR . ' unit. Use a stock movement with a reason to record usage or disposal.'];
    }
    $minStock = (int)($d['min_stock'] ?? 0);
    // Status always follows the quantity (the database trigger enforces the same rule)
    $status = stock_status($newQty, $minStock);

    $payload = [
        'item_name'      => trim($d['item_name']),
        'category'       => $d['category']       ?? null,
        'branch_id'      => $targetBranch,
        'stock_quantity' => $newQty,
        'min_stock'      => $minStock,
        'status'         => $status,
        'updated_at'     => date('c'),
    ];

    // Only saved if the quantity was not changed by someone else meanwhile
    $r = supabase_request('inventory', 'PATCH', $payload, 'item_id=eq.' . (int)$d['item_id'] . '&stock_quantity=eq.' . $oldQty);
    if (!$r['error'] && empty($r['data'])) return ['success' => false, 'message' => 'Stock changed while you were editing. Please reload and try again.'];
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Item updated successfully.'];
}

// ── Delete Item ───────────────────────────────────────────────
// An item with history (stock movements or materials used in treatments) is
// ARCHIVED instead: hidden from lists and dropdowns, history kept. An item
// without history is deleted for real. Response: archived = true|false.
function deleteItem(array $d): array {
    if (empty($d['item_id'])) return ['success' => false, 'message' => 'Missing item ID.'];
    $itemId = (int)$d['item_id'];

    // SECURITY: only items of the user's own branch (owner: any)
    $cur = stock_rows([$itemId])[$itemId] ?? null;
    if (!$cur) return ['success' => false, 'message' => 'Item not found.'];
    if (!canAccessBranch($cur['branch_id'] ?? null)) {
        http_response_code(403);
        return ['success' => false, 'message' => 'You can only manage items in your own branch.'];
    }
    if (!empty($cur['archived_at'])) return ['success' => false, 'message' => 'This item is already archived.'];

    if (!inv_has_history($itemId)) {
        // No history: drop its service-material links (configuration only), then the item
        supabase_request('service_materials', 'DELETE', [], 'item_id=eq.' . $itemId);
        $r = supabase_request('inventory', 'DELETE', [], 'item_id=eq.' . $itemId);
        if (!$r['error']) return ['success' => true, 'archived' => false, 'message' => 'Item deleted.'];
        // Still referenced (e.g. history added meanwhile) → archive below
        if (($r['data']['code'] ?? '') !== '23503') return ['success' => false, 'message' => $r['error']];
    }
    return archiveItem($itemId);
}

/** True when the item appears in stock movements or treatment materials. */
function inv_has_history(int $itemId): bool
{
    foreach (['inventory_movements', 'treatment_materials'] as $t) {
        $r = supabase_request($t, 'GET', [], 'item_id=eq.' . $itemId . '&select=item_id&limit=1');
        if ($r['error'] || !is_array($r['data'])) return true;   // unsure → keep history (archive)
        if ($r['data']) return true;
    }
    return false;
}

function archiveItem(int $itemId): array
{
    $r = supabase_request('inventory', 'PATCH', ['archived_at' => date('c'), 'updated_at' => date('c')],
        'item_id=eq.' . $itemId . '&archived_at=is.null');
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    // An archived item must not be pulled in as a service's default material anymore
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
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Item restored.'];
}

// ── Log Stock Movement ────────────────────────────────────────
// Inserts a movement record AND updates stock_quantity on the item
function logMovement(array $d): array {
    if (empty($d['item_id']))        return ['success' => false, 'message' => 'Item is required.'];
    if (empty($d['quantity_change'])) return ['success' => false, 'message' => 'Quantity change is required.'];
    if ((int)$d['quantity_change'] === 0) return ['success' => false, 'message' => 'Quantity change cannot be zero.'];
    if (empty($d['reason']))         return ['success' => false, 'message' => 'Reason is required.'];

    $itemId = (int)$d['item_id'];
    $change = (int)$d['quantity_change'];

    $itemRes = supabase_request('inventory', 'GET', [], 'select=stock_quantity,branch_id,archived_at&item_id=eq.' . $itemId);
    if (empty($itemRes['data'][0])) return ['success' => false, 'message' => 'Item not found.'];
    if (!empty($itemRes['data'][0]['archived_at'])) return ['success' => false, 'message' => 'This item is archived. Restore it first.'];
    $branchId = (int)$itemRes['data'][0]['branch_id'];
    if (!canAccessBranch($branchId)) {
        http_response_code(403);
        return ['success' => false, 'message' => 'You can only manage items in your own branch.'];
    }

    // Stock first (floor rule + compare-and-swap), then the movement record
    $applied = stock_apply($itemId, $change);
    if (!$applied['ok']) return ['success' => false, 'message' => $applied['message']];
    $newStock = $applied['after'];

    $r1 = supabase_request('inventory_movements', 'POST', [
        'item_id'         => $itemId,
        'branch_id'       => $branchId,
        'quantity_change' => $change,
        'reason'          => trim($d['reason']),
        'performed_by'    => $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System',
        'movement_date'   => date('Y-m-d'),
        'created_at'      => date('c'),
    ]);
    if ($r1['error']) {
        stock_apply($itemId, -$change, 1); // keep stock and history in step
        return ['success' => false, 'message' => 'Movement could not be logged: ' . $r1['error']];
    }

    return ['success' => true, 'message' => 'Stock movement logged. New stock: ' . $newStock . '.'];
}