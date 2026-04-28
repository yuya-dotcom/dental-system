<?php
// =============================================================
//  api/finish_treatment.php
//  POST — atomic finish treatment flow:
//    1. Update treatment record (procedure_notes, tooth_number, cost, status=completed)
//    2. Save treatment_materials
//    3. Deduct inventory + log movements
//    4. Create invoice (status=unpaid)
//    5. Update appointment status=completed
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

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Required fields ──────────────────────────────────────────
$treatmentId   = (int)($body['treatment_id']    ?? 0);
$appointmentId = (int)($body['appointment_id']  ?? 0);
$patientId     = (int)($body['patient_id']      ?? 0);
$branchId      = (int)($body['branch_id']       ?? 0);
$serviceId     = (int)($body['service_id']      ?? 0);
$dentistId     = (int)($body['dentist_id']      ?? 0);
$procedureNotes = trim($body['procedure_notes'] ?? '');
$toothNumber   = trim($body['tooth_number']     ?? '');
$cost          = (float)($body['cost']          ?? 0);
$currentStage  = trim($body['current_stage']    ?? '');
$materials     = $body['materials']             ?? []; // [{item_id, quantity_used, item_name}]
$performedBy   = trim($body['performed_by']     ?? 'System');

if (!$treatmentId) {
    echo json_encode(['success' => false, 'message' => 'Treatment ID is required.']);
    exit;
}

$errors = [];

// ── STEP 1: Update treatment record ─────────────────────────
$dentistId  = (int)($body['dentist_id'] ?? 0);

$trtPayload = [
    'procedure_notes' => $procedureNotes ?: null,
    'tooth_number'    => $toothNumber    ?: null,
    'cost'            => $cost > 0 ? $cost : null,
    'current_stage'   => $currentStage   ?: null,
    'status'          => 'completed',
    'updated_at'      => date('c'),
];
if ($dentistId) $trtPayload['dentist_id'] = $dentistId;

$trtRes = supabase_request('treatments', 'PATCH', $trtPayload,
    'treatment_id=eq.' . $treatmentId
);

if ($trtRes['status'] < 200 || $trtRes['status'] >= 300) {
    echo json_encode(['success' => false, 'message' => 'Failed to update treatment: ' . ($trtRes['error'] ?? 'Unknown error')]);
    exit;
}

// ── STEP 2: Save treatment_materials ────────────────────────
// Delete existing materials first (in case of re-submit)
supabase_request('treatment_materials', 'DELETE', [], 'treatment_id=eq.' . $treatmentId);

foreach ($materials as $mat) {
    $itemId = (int)($mat['item_id']      ?? 0);
    $qty    = (int)($mat['quantity_used'] ?? 1);
    if (!$itemId || $qty < 1) continue;

    supabase_request('treatment_materials', 'POST', [
        'treatment_id'  => $treatmentId,
        'item_id'       => $itemId,
        'quantity_used' => $qty,
    ]);
}

// ── STEP 3: Deduct inventory + log movements ─────────────────
$today = date('Y-m-d');
foreach ($materials as $mat) {
    $itemId = (int)($mat['item_id']      ?? 0);
    $qty    = (int)($mat['quantity_used'] ?? 1);
    if (!$itemId || $qty < 1) continue;

    // Fetch current stock
    $stockRes = supabase_request('inventory', 'GET', [],
        'item_id=eq.' . $itemId . '&select=item_id,stock_quantity,min_stock&limit=1'
    );
    $item       = $stockRes['data'][0] ?? null;
    if (!$item) continue;

    $currentQty = (int)$item['stock_quantity'];
    $minStock   = (int)($item['min_stock'] ?? 0);
    $newQty     = max(0, $currentQty - $qty);
    $newStatus  = $newQty === 0 ? 'out_of_stock' : ($newQty <= $minStock ? 'low_stock' : 'active');

    // Deduct stock
    supabase_request('inventory', 'PATCH',
        ['stock_quantity' => $newQty, 'status' => $newStatus, 'updated_at' => date('c')],
        'item_id=eq.' . $itemId
    );

    // Log movement
    supabase_request('inventory_movements', 'POST', [
        'item_id'         => $itemId,
        'branch_id'       => $branchId,
        'quantity_change' => -$qty,
        'reason'          => 'Used in treatment #' . $treatmentId,
        'performed_by'    => $performedBy,
        'movement_date'   => $today,
    ]);
}

// ── STEP 4: Create invoice ───────────────────────────────────
// Generate invoice code
$lastInv  = supabase_request('invoices', 'GET', [],
    'select=invoice_code&order=invoice_code.desc&limit=1'
);
$lastCode = $lastInv['data'][0]['invoice_code'] ?? 'INV-0000';
$lastNum  = (int)substr($lastCode, 4);
$invCode  = 'INV-' . str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);

$invRes = supabase_request('invoices', 'POST', [
    'invoice_code'   => $invCode,
    'treatment_id'   => $treatmentId,
    'appointment_id' => $appointmentId ?: null,
    'patient_id'     => $patientId     ?: null,
    'branch_id'      => $branchId      ?: null,
    'total_amount'   => $cost > 0 ? $cost : 0,
    'amount_paid'    => 0,
    'payment_status' => 'unpaid',
    'invoice_date'   => $today,
]);

if ($invRes['status'] < 200 || $invRes['status'] >= 300) {
    $errors[] = 'Invoice creation failed: ' . ($invRes['error'] ?? 'Unknown error');
}

// ── STEP 5: Update appointment status + payment_status ──────
if ($appointmentId) {
    supabase_request('appointments', 'PATCH',
        ['status' => 'completed', 'payment_status' => 'unpaid', 'updated_at' => date('c')],
        'appointment_id=eq.' . $appointmentId
    );
}

// ── Done ─────────────────────────────────────────────────────
if (!empty($errors)) {
    echo json_encode([
        'success'  => true,
        'warnings' => $errors,
        'message'  => 'Treatment finished with some warnings: ' . implode('; ', $errors),
    ]);
} else {
    echo json_encode([
        'success'      => true,
        'message'      => 'Treatment finished successfully.',
        'invoice_code' => $invCode ?? null,
    ]);
}