<?php
// =============================================================
//  api/service_materials.php
//  GET    ?service_id=X  — fetch materials for a service
//  POST                  — add a material mapping
//  PATCH  ?id=X          — update quantity of a material
//  DELETE ?id=X          — remove a material mapping
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────
if ($method === 'GET') {
    $serviceId = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
    if (!$serviceId) { echo json_encode([]); exit; }

    $res = supabase_request('service_materials', 'GET', [],
        'service_id=eq.' . $serviceId .
        '&select=material_id,service_id,item_id,quantity,' .
        'inventory(item_id,item_code,item_name,category,stock_quantity)' .
        '&order=material_id.asc'
    );
    echo json_encode($res['data'] ?? []);
    exit;
}

// ── POST — add mapping ───────────────────────────────────────
if ($method === 'POST') {
    if (!isOwner() && !isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
    }
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $serviceId = (int)($body['service_id'] ?? 0);
    $itemId    = (int)($body['item_id']    ?? 0);
    $quantity  = (int)($body['quantity']   ?? 1);

    if (!$serviceId || !$itemId || $quantity < 1) {
        echo json_encode(['success' => false, 'message' => 'service_id, item_id, and quantity are required.']); exit;
    }

    // Check for duplicate
    $check = supabase_request('service_materials', 'GET', [],
        'service_id=eq.' . $serviceId . '&item_id=eq.' . $itemId . '&select=material_id&limit=1'
    );
    if (!empty($check['data'])) {
        echo json_encode(['success' => false, 'message' => 'This item is already added to this service. Edit its quantity instead.']); exit;
    }

    $res = supabase_request('service_materials', 'POST', [
        'service_id' => $serviceId,
        'item_id'    => $itemId,
        'quantity'   => $quantity,
    ]);

    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'message' => 'Material added.']);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to add material.']);
    }
    exit;
}

// ── PATCH — update quantity ──────────────────────────────────
if ($method === 'PATCH') {
    if (!isOwner() && !isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
    }
    $id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $qty  = (int)($body['quantity'] ?? 0);

    if (!$id)     { echo json_encode(['success' => false, 'message' => 'Material ID required.']); exit; }
    if ($qty < 1) { echo json_encode(['success' => false, 'message' => 'Quantity must be at least 1.']); exit; }

    $res = supabase_request('service_materials', 'PATCH',
        ['quantity' => $qty],
        'material_id=eq.' . $id
    );

    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'message' => 'Quantity updated.']);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to update quantity.']);
    }
    exit;
}

// ── DELETE ───────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!isOwner() && !isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
    }
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Material ID required.']); exit; }

    $res = supabase_request('service_materials', 'DELETE', [], 'material_id=eq.' . $id);

    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'message' => 'Material removed.']);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to remove material.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed.']);