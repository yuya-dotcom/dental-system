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

header('Content-Type: application/json');
requireLogin();

// Dentists cannot modify inventory
if (isDentist()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'add':          echo json_encode(addItem($input));         break;
    case 'edit':         echo json_encode(editItem($input));        break;
    case 'delete':       echo json_encode(deleteItem($input));      break;
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
    if (isAdmin() && (int)$d['branch_id'] !== getSessionBranch()) {
        return ['success' => false, 'message' => 'You can only add items to your own branch.'];
    }

    $payload = [
        'item_code'      => generateItemCode(),
        'item_name'      => trim($d['item_name']),
        'category'       => $d['category']       ?? null,
        'branch_id'      => (int)$d['branch_id'],
        'stock_quantity' => (int)($d['stock_quantity'] ?? 0),
        'min_stock'      => (int)($d['min_stock']      ?? 0),
        'status'         => $d['status']          ?? 'active',
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

    if (isAdmin() && (int)$d['branch_id'] !== getSessionBranch()) {
        return ['success' => false, 'message' => 'You can only manage items in your own branch.'];
    }

    $payload = [
        'item_name'      => trim($d['item_name']),
        'category'       => $d['category']       ?? null,
        'branch_id'      => (int)$d['branch_id'],
        'stock_quantity' => (int)($d['stock_quantity'] ?? 0),
        'min_stock'      => (int)($d['min_stock']      ?? 0),
        'status'         => $d['status']          ?? 'active',
        'updated_at'     => date('c'),
    ];

    $r = supabase_request('inventory', 'PATCH', $payload, 'item_id=eq.' . (int)$d['item_id']);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Item updated successfully.'];
}

// ── Delete Item ───────────────────────────────────────────────
function deleteItem(array $d): array {
    if (empty($d['item_id'])) return ['success' => false, 'message' => 'Missing item ID.'];

    $r = supabase_request('inventory', 'DELETE', [], 'item_id=eq.' . (int)$d['item_id']);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Item deleted successfully.'];
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

    // Fetch current stock
    $itemRes = supabase_request('inventory', 'GET', [], 'select=stock_quantity,branch_id&item_id=eq.' . $itemId);
    if (empty($itemRes['data'][0])) return ['success' => false, 'message' => 'Item not found.'];

    $currentStock = (int)$itemRes['data'][0]['stock_quantity'];
    $branchId     = (int)$itemRes['data'][0]['branch_id'];
    $newStock     = $currentStock + $change;

    if ($newStock < 0) {
        return ['success' => false, 'message' => "Cannot reduce stock below zero. Current stock: {$currentStock}."];
    }

    // Insert movement record
    $movPayload = [
        'item_id'         => $itemId,
        'branch_id'       => $branchId,
        'quantity_change' => $change,
        'reason'          => trim($d['reason']),
        'performed_by'    => $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System',
        'movement_date'   => date('Y-m-d'),
        'created_at'      => date('c'),
    ];

    $r1 = supabase_request('inventory_movements', 'POST', $movPayload);
    if ($r1['error']) return ['success' => false, 'message' => $r1['error']];

    // Update stock quantity on inventory item
    // Also auto-update status based on new stock vs min_stock
    $itemFull = supabase_request('inventory', 'GET', [], 'select=min_stock,status&item_id=eq.' . $itemId);
    $minStock  = (int)($itemFull['data'][0]['min_stock'] ?? 0);
    $newStatus = $newStock === 0 ? 'out_of_stock' : ($newStock <= $minStock ? 'low_stock' : 'active');

    $r2 = supabase_request('inventory', 'PATCH',
        ['stock_quantity' => $newStock, 'status' => $newStatus, 'updated_at' => date('c')],
        'item_id=eq.' . $itemId
    );
    if ($r2['error']) return ['success' => false, 'message' => 'Movement logged but stock update failed: ' . $r2['error']];

    return ['success' => true, 'message' => 'Stock movement logged. New stock: ' . $newStock . '.'];
}