<?php
// =============================================================
//  api/treatment_materials.php
//  GET    ?treatment_id=X  — fetch materials for a treatment
//  POST                    — add a material to a treatment
//  DELETE ?id=X            — remove a material from a treatment
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();
requireStaffApi('owner', 'admin', 'dentist');   // staff only; non-owners are pinned to their branch

/** Stop unless the treatment exists and belongs to the user's branch (owner: any). */
function tm_require_treatment(int $treatmentId): void
{
    $r = supabase_request('treatments', 'GET', [], 'treatment_id=eq.' . $treatmentId . '&select=branch_id&limit=1');
    if (empty($r['data'][0])) apiDeny(404, 'Treatment not found.');
    requireBranchAccess($r['data'][0]['branch_id'] ?? null);
}
/** Same check, starting from a treatment_materials row id. */
function tm_require_material(int $materialId): void
{
    $r = supabase_request('treatment_materials', 'GET', [], 'material_id=eq.' . $materialId . '&select=treatment_id&limit=1');
    if (empty($r['data'][0])) apiDeny(404, 'Material not found.');
    tm_require_treatment((int)$r['data'][0]['treatment_id']);
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────
if ($method === 'GET') {
    $treatmentId = isset($_GET['treatment_id']) ? (int)$_GET['treatment_id'] : 0;
    if (!$treatmentId) { echo json_encode([]); exit; }
    tm_require_treatment($treatmentId);

    $res = supabase_request('treatment_materials', 'GET', [],
        'treatment_id=eq.' . $treatmentId .
        '&select=material_id,treatment_id,item_id,quantity_used,' .
        'inventory(item_id,item_code,item_name,stock_quantity,category)' .
        '&order=material_id.asc'
    );
    echo json_encode($res['data'] ?? []);
    exit;
}

// ── POST — add material ──────────────────────────────────────
if ($method === 'POST') {
    $body        = json_decode(file_get_contents('php://input'), true) ?? [];
    $treatmentId = (int)($body['treatment_id'] ?? 0);
    $itemId      = (int)($body['item_id']      ?? 0);
    $qty         = (int)($body['quantity_used'] ?? 1);

    if (!$treatmentId || !$itemId || $qty < 1) {
        echo json_encode(['success' => false, 'message' => 'treatment_id, item_id, and quantity_used are required.']);
        exit;
    }
    tm_require_treatment($treatmentId);
    $itm = supabase_request('inventory', 'GET', [], 'item_id=eq.' . $itemId . '&select=branch_id&limit=1');
    if (empty($itm['data'][0])) apiDeny(404, 'Item not found.');
    requireBranchAccess($itm['data'][0]['branch_id'] ?? null);

    // Check duplicate
    $check = supabase_request('treatment_materials', 'GET', [],
        'treatment_id=eq.' . $treatmentId . '&item_id=eq.' . $itemId . '&select=material_id&limit=1'
    );
    if (!empty($check['data'])) {
        echo json_encode(['success' => false, 'message' => 'This item is already added. Edit its quantity instead.']);
        exit;
    }

    $res = supabase_request('treatment_materials', 'POST', [
        'treatment_id'  => $treatmentId,
        'item_id'       => $itemId,
        'quantity_used' => $qty,
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
    $id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $qty  = (int)($body['quantity_used'] ?? 0);

    if (!$id || $qty < 1) {
        echo json_encode(['success' => false, 'message' => 'Valid material_id and quantity required.']);
        exit;
    }
    tm_require_material($id);

    $res = supabase_request('treatment_materials', 'PATCH',
        ['quantity_used' => $qty],
        'material_id=eq.' . $id
    );

    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'message' => 'Quantity updated.']);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to update.']);
    }
    exit;
}

// ── DELETE ───────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Material ID required.']);
        exit;
    }
    tm_require_material($id);

    $res = supabase_request('treatment_materials', 'DELETE', [], 'material_id=eq.' . $id);

    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'message' => 'Material removed.']);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to remove.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed.']);