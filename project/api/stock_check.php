<?php
// =============================================================
//  api/stock_check.php
//  POST {branch_id, materials: [{item_id, quantity}]} — read-only.
//  Checks the combined material requirement of a treatment plan against
//  the branch's current stock (floor rule: 1 unit must remain).
//  Used by Active Treatments before moving past Plan & Cost; the same
//  check runs again inside api/finish_treatment.php before anything is saved.
// =============================================================
session_start();
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/stock_helper.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$branchId  = (int)($body['branch_id'] ?? 0);
$materials = is_array($body['materials'] ?? null) ? $body['materials'] : [];
if (!isOwner() && getSessionBranch()) $branchId = (int)getSessionBranch(); // staff: own branch only

$r = stock_check($materials, $branchId);
echo json_encode([
    'success'   => true,
    'ok'        => $r['ok'],
    'floor'     => STOCK_FLOOR,
    'items'     => $r['items'],
    'shortages' => $r['shortages'],
    'message'   => $r['ok'] ? '' : stock_shortage_message($r['shortages']),
]);
