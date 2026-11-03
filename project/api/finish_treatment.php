<?php
// =============================================================
//  api/finish_treatment.php
//  POST — atomic finish treatment flow:
//    1. Update treatment record (procedure_notes, tooth_number, cost, status=completed)
//    2. Save treatment_materials
//    3. Deduct inventory + log movements (stock_helper: floor of 1 unit,
//       branch-resolved, all-or-nothing — done BEFORE step 1 so a shortage
//       leaves the treatment untouched)
//    4. Create invoice (status=unpaid)
//    5. Update appointment status=completed
//    Procedure lines (tooth + service + surfaces) are saved to
//    treatment_procedures with the configured base_price at this moment.
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/notification_helper.php';
require_once __DIR__ . '/../controllers/audit_controller.php';
require_once __DIR__ . '/../controllers/stock_helper.php';

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
// Cost is NOT read from the request: it is the sum of the configured service
// prices of the procedure lines (computed in STEP 0). Discounts are applied in Billing.
$currentStage  = trim($body['current_stage']    ?? '');
$materials     = $body['materials']             ?? []; // [{item_id, quantity_used, item_name}]
$procedures    = is_array($body['procedures'] ?? null) ? $body['procedures'] : []; // [{service_id, tooth_number, surfaces}]
$performedBy   = trim($body['performed_by']     ?? 'System');

if (!$treatmentId) {
    echo json_encode(['success' => false, 'message' => 'Treatment ID is required.']);
    exit;
}

$errors = [];

// ── STEP 0: Validate procedure lines + compute the cost ─────
// Validated + de-duplicated (one line per service + tooth); unit_price is
// read from services.base_price so the stored price is the configured one.
$procRows = [];
if ($procedures) {
    $svcIds = array_values(array_unique(array_filter(array_map(fn($p) => (int)($p['service_id'] ?? 0), $procedures))));
    $svcMap = [];
    if ($svcIds) {
        $svcRes = supabase_request('services', 'GET', [],
            'service_id=in.(' . implode(',', $svcIds) . ')&select=service_id,service_name,base_price,requires_tooth_selection,chart_outcome');
        foreach (($svcRes['data'] ?? []) as $sv) $svcMap[(int)$sv['service_id']] = $sv;
    }
    $seen = [];
    foreach ($procedures as $p) {
        $sid = (int)($p['service_id'] ?? 0);
        if (!isset($svcMap[$sid])) continue;
        $tooth = trim((string)($p['tooth_number'] ?? ''));
        if ($tooth !== '' && !(ctype_digit($tooth) && (int)$tooth >= 1 && (int)$tooth <= 32)) continue;
        if (!empty($svcMap[$sid]['requires_tooth_selection']) && $tooth === '') continue;
        $surf = implode('', array_intersect(['B', 'M', 'O', 'L', 'D'], str_split(strtoupper((string)($p['surfaces'] ?? '')))));
        // Whole-tooth procedures (extraction, crown, …) never store surfaces —
        // same rule as _surfacesAllowed() in assets/js/treatments-active.js
        $outcome = (string)($svcMap[$sid]['chart_outcome'] ?? '');
        $wholeTooth = $outcome !== ''
            ? in_array($outcome, ['missing', 'crown', 'impacted', 'impacted_ne'], true)
            : (bool)preg_match('/extract|exodont/i', (string)($svcMap[$sid]['service_name'] ?? ''));
        if ($tooth === '' || $wholeTooth) $surf = '';
        $key  = $sid . '|' . $tooth;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $procRows[] = [
            'treatment_id' => $treatmentId,
            'service_id'   => $sid,
            'tooth_number' => $tooth !== '' ? $tooth : null,
            'surfaces'     => $surf !== '' ? $surf : null,
            'unit_price'   => round((float)($svcMap[$sid]['base_price'] ?? 0), 2),
            'status'       => 'completed',
            'created_by'   => (int)($_SESSION['user_id'] ?? 0) ?: null,
        ];
    }
}
if (!$procRows) {
    echo json_encode(['success' => false, 'message' => 'Add at least one valid procedure before finishing the treatment.']);
    exit;
}
$cost = round(array_sum(array_column($procRows, 'unit_price')), 2);

// ── STEP 0b: Stock — check + deduct before anything is saved ─
// Combined requirement of all materials, checked against the treatment's
// branch. A shortage stops here: nothing is written.
$branchItems = [];
if ($materials) {
    $deduct = stock_deduct($materials, $branchId, 'Used in treatment #' . $treatmentId, $performedBy);
    if (!$deduct['ok']) {
        echo json_encode(['success' => false, 'message' => $deduct['message'], 'shortages' => $deduct['shortages']]);
        exit;
    }
    $branchItems = $deduct['deducted']; // branch item_id => qty
}
// Puts the stock back if the treatment itself could not be saved
$restoreStock = function (string $why) use (&$branchItems, $branchId, $treatmentId, $performedBy) {
    foreach ($branchItems as $id => $q) {
        if (stock_apply($id, $q)['ok']) {
            supabase_request('inventory_movements', 'POST', [
                'item_id' => $id, 'branch_id' => $branchId ?: null, 'quantity_change' => $q,
                'reason' => 'Reversed: treatment #' . $treatmentId . ' ' . $why, 'performed_by' => $performedBy,
                'movement_date' => date('Y-m-d'),
            ]);
        }
    }
    $branchItems = [];
};

// ── STEP 1: Update treatment record ─────────────────────────
$dentistId  = (int)($body['dentist_id'] ?? 0);

$trtPayload = [
    'procedure_notes' => $procedureNotes ?: null,
    'tooth_number'    => $toothNumber    ?: null,
    'cost'            => $cost > 0 ? $cost : null,
    'status'          => 'completed',
    'updated_at'      => date('c'),
];
if ($dentistId) $trtPayload['dentist_id'] = $dentistId;
// Current stage is read-only in the Active Treatments form; only a valid stage
// value (per the treatments.current_stage CHECK) is ever written here.
if (in_array($currentStage, ['In Treatment', 'Completed', 'Follow-up Required'], true)) {
    $trtPayload['current_stage'] = $currentStage;
}

$trtRes = supabase_request('treatments', 'PATCH', $trtPayload,
    'treatment_id=eq.' . $treatmentId
);

if ($trtRes['status'] < 200 || $trtRes['status'] >= 300) {
    $restoreStock('could not be saved');
    echo json_encode(['success' => false, 'message' => 'Failed to update treatment: ' . ($trtRes['error'] ?? 'Unknown error')]);
    exit;
}

// ── STEP 1b: Save procedure lines (validated in STEP 0) ─────
// Replace any lines from an earlier, interrupted submit (no duplicates)
supabase_request('treatment_procedures', 'DELETE', [], 'treatment_id=eq.' . $treatmentId);
if ($procRows) {
    $procRes = supabase_request('treatment_procedures', 'POST', $procRows);
    if ($procRes['status'] < 200 || $procRes['status'] >= 300) {
        $errors[] = 'Procedure lines could not be saved: ' . ($procRes['error'] ?? 'Unknown error');
    }
}

// ── STEP 2: Save treatment_materials ────────────────────────
// Saved as the branch items actually deducted in STEP 0b (combined per item)
supabase_request('treatment_materials', 'DELETE', [], 'treatment_id=eq.' . $treatmentId);
foreach ($branchItems as $itemId => $qty) {
    supabase_request('treatment_materials', 'POST', [
        'treatment_id'  => $treatmentId,
        'item_id'       => $itemId,
        'quantity_used' => $qty,
    ]);
}

// STEP 3 (inventory deduction + movements) ran in STEP 0b.
$today = date('Y-m-d');

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
    logActivity('billing', 'INSERT', 'treatment #' . $treatmentId, getPatientNameForAudit($patientId),
        'FAILED — invoice ' . $invCode . ' (' . auditPeso($cost) . ') could not be created for treatment #' . $treatmentId .
        ': ' . ($invRes['error'] ?? 'Unknown error'), $branchId ?: null);
} elseif (!empty($invRes['data'][0]['invoice_id'])) {
    $newInvId = (int)$invRes['data'][0]['invoice_id'];
    notif_invoice_created($newInvId);
    logBillingActivity('INSERT', $newInvId, getInvoiceInfoForAudit($newInvId),
        'Invoice ' . $invCode . ' created from treatment #' . $treatmentId . ': ' . count($procRows) .
        ' procedure line(s), total ' . auditPeso($cost) . ', status unpaid.');
}

// Treatment PATCH (step 1) succeeded → status is now completed
notif_treatment($treatmentId, ['status' => '']);

// ── STEP 5: Update appointment status + payment_status ──────
if ($appointmentId) {
    $aptDone = supabase_request('appointments', 'PATCH',
        ['status' => 'completed', 'payment_status' => 'unpaid', 'updated_at' => date('c')],
        'appointment_id=eq.' . $appointmentId
    );
    if (!empty($aptDone['data'][0])) {
        notif_appointment($aptDone['data'][0], ['status' => 'checked_in'] + $aptDone['data'][0]);
    }
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