<?php
// =============================================================
//  api/inventory.php
//  GET  ?branch_id=X           — list inventory for a branch
//  PATCH ?id=X                 — deduct stock quantity
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];

// ── GET — list items for branch ──────────────────────────────
if ($method === 'GET') {
    $branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
    $q = 'select=item_id,item_code,item_name,category,stock_quantity,min_stock,status&status=neq.inactive&order=item_name.asc';
    if ($branchId) $q .= '&branch_id=eq.' . $branchId;

    $res = supabase_request('inventory', 'GET', [], $q);
    echo json_encode($res['data'] ?? []);
    exit;
}

// ── PATCH — deduct stock ─────────────────────────────────────
if ($method === 'PATCH') {
    $id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Item ID required.']);
        exit;
    }

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $deduct   = (int)($body['deduct'] ?? 0);

    if ($deduct <= 0) {
        echo json_encode(['success' => false, 'message' => 'Deduction amount must be greater than 0.']);
        exit;
    }

    // Fetch current stock first
    $current = supabase_request('inventory', 'GET', [],
        'item_id=eq.' . $id . '&select=item_id,stock_quantity&limit=1'
    );

    if (empty($current['data'][0])) {
        echo json_encode(['success' => false, 'message' => 'Item not found.']);
        exit;
    }

    $currentQty = (int)$current['data'][0]['stock_quantity'];
    $newQty     = max(0, $currentQty - $deduct);

    // Determine new status
    $statusRes  = supabase_request('inventory', 'GET', [],
        'item_id=eq.' . $id . '&select=min_stock,status&limit=1'
    );
    $minStock   = (int)($statusRes['data'][0]['min_stock'] ?? 0);
    $newStatus  = $newQty === 0 ? 'out_of_stock' : ($newQty <= $minStock ? 'low_stock' : 'active');

    $res = supabase_request('inventory', 'PATCH',
        ['stock_quantity' => $newQty, 'status' => $newStatus, 'updated_at' => date('c')],
        'item_id=eq.' . $id
    );

    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'new_quantity' => $newQty]);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to update stock.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed.']);