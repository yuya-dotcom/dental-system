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

    // Floor rule + compare-and-swap (controllers/stock_helper.php)
    require_once __DIR__ . '/../controllers/stock_helper.php';
    $r = stock_apply($id, -$deduct);
    echo json_encode($r['ok']
        ? ['success' => true, 'new_quantity' => $r['after']]
        : ['success' => false, 'message' => $r['message']]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed.']);