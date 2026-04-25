<?php
// =============================================================
//  api/inventory_movements.php
//  POST — log a stock movement entry
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$itemId   = (int)($body['item_id']         ?? 0);
$branchId = (int)($body['branch_id']       ?? 0);
$change   = (int)($body['quantity_change'] ?? 0);
$reason   = trim($body['reason']           ?? '');
$perfBy   = trim($body['performed_by']     ?? '');
$date     = trim($body['movement_date']    ?? date('Y-m-d'));

if (!$itemId || !$branchId || $change === 0) {
    echo json_encode(['success' => false, 'message' => 'item_id, branch_id, and quantity_change are required.']);
    exit;
}

$res = supabase_request('inventory_movements', 'POST', [
    'item_id'         => $itemId,
    'branch_id'       => $branchId,
    'quantity_change' => $change,
    'reason'          => $reason  ?: 'Treatment completion',
    'performed_by'    => $perfBy  ?: 'System',
    'movement_date'   => $date,
]);

if ($res['status'] >= 200 && $res['status'] < 300) {
    echo json_encode(['success' => true, 'message' => 'Movement logged.']);
} else {
    echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to log movement.']);
}