<?php
// =============================================================
//  api/discount_vouchers.php — used by discount-vouchers.php
//  POST           — create a voucher        (owner / admin)
//  PATCH  ?id=X   — update / toggle status  (owner / admin)
//  DELETE ?id=X   — delete an unused voucher (owner only)
//  Vouchers are applied to invoices in Billing (api/invoice_discounts.php).
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$userId = (int)($_SESSION['user_id'] ?? 0) ?: null;

function vch_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if (!isOwner() && !isAdmin()) vch_fail('Only owners and admins can manage vouchers.');

// Validates the editable fields present in $body; returns the clean payload.
// $partial = true (PATCH) only checks the fields that were sent.
function vch_payload(array $body, bool $partial, int $selfId = 0): array {
    $out = [];
    $has = fn($k) => array_key_exists($k, $body);

    if (!$partial || $has('voucher_name')) {
        $name = trim((string)($body['voucher_name'] ?? ''));
        if ($name === '') vch_fail('Voucher name is required.');
        $out['voucher_name'] = $name;
    }
    if (!$partial || $has('voucher_code')) {
        $code = strtoupper(trim((string)($body['voucher_code'] ?? '')));
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) {
            vch_fail('Voucher code must be 2–40 letters, numbers, dashes or underscores.');
        }
        // Codes are unique (case-insensitive)
        // (ilike narrows the rows; '_' is a LIKE wildcard, so compare exactly here)
        $dup = supabase_request('discount_vouchers', 'GET', [],
            'voucher_code=ilike.' . rawurlencode($code) . '&select=voucher_id,voucher_code');
        foreach ((is_array($dup['data'] ?? null) ? $dup['data'] : []) as $row) {
            if (strtoupper((string)($row['voucher_code'] ?? '')) === $code && (int)$row['voucher_id'] !== $selfId) {
                vch_fail('Another voucher already uses the code ' . $code . '.');
            }
        }
        $out['voucher_code'] = $code;
    }
    if (!$partial || $has('discount_type') || $has('discount_value')) {
        $type  = (string)($body['discount_type'] ?? '');
        $value = (float)($body['discount_value'] ?? 0);
        if (!in_array($type, ['percentage', 'fixed'], true)) vch_fail('Discount type must be percentage or fixed.');
        if ($value <= 0) vch_fail('Please enter a discount value greater than 0.');
        if ($type === 'percentage' && $value > 100) vch_fail('Percentage discount cannot exceed 100.');
        $out['discount_type']  = $type;
        $out['discount_value'] = round($value, 2);
    }
    $isDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    if (!$partial || $has('start_date') || $has('expiration_date')) {
        $start = trim((string)($body['start_date'] ?? ''));
        $end   = trim((string)($body['expiration_date'] ?? ''));
        if (!$isDate($start) || !$isDate($end)) vch_fail('Start date and expiration date are required.');
        if ($end < $start) vch_fail('Expiration date cannot be before the start date.');
        $out['start_date']      = $start;
        $out['expiration_date'] = $end;
    }
    if (!$partial || $has('min_bill_amount')) {
        $min = $body['min_bill_amount'] ?? null;
        if ($min !== null && $min !== '' && (float)$min < 0) vch_fail('Minimum bill cannot be negative.');
        $out['min_bill_amount'] = ($min === null || $min === '') ? null : round((float)$min, 2);
    }
    if (!$partial || $has('usage_limit')) {
        $lim = $body['usage_limit'] ?? null;
        if ($lim !== null && $lim !== '' && (int)$lim < 1) vch_fail('Usage limit must be at least 1 (leave empty for unlimited).');
        $out['usage_limit'] = ($lim === null || $lim === '') ? null : (int)$lim;
    }
    if ($has('status')) {
        if (!in_array($body['status'], ['active', 'inactive'], true)) vch_fail('Invalid status.');
        $out['status'] = $body['status'];
    }
    return $out;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── POST — create ────────────────────────────────────────────
if ($method === 'POST') {
    $payload = vch_payload($body, false) + ['status' => 'active', 'created_by' => $userId, 'updated_by' => $userId];
    $res = supabase_request('discount_vouchers', 'POST', $payload);
    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'message' => 'Voucher created.']);
    } else {
        vch_fail($res['error'] ?? 'Failed to create voucher.');
    }
    exit;
}

// ── PATCH — update / toggle status ───────────────────────────
if ($method === 'PATCH') {
    if (!$id) vch_fail('Voucher ID required.');
    $payload = vch_payload($body, true, $id);
    if (!$payload) vch_fail('Nothing to update.');
    $payload['updated_by'] = $userId;
    $payload['updated_at'] = date('c');
    $res = supabase_request('discount_vouchers', 'PATCH', $payload, 'voucher_id=eq.' . $id);
    if ($res['status'] >= 200 && $res['status'] < 300 && !empty($res['data'])) {
        echo json_encode(['success' => true, 'message' => isset($payload['voucher_name']) ? 'Voucher updated.' : 'Voucher status updated.']);
    } else {
        vch_fail($res['error'] ?? 'Voucher not found.');
    }
    exit;
}

// ── DELETE — only vouchers never applied to an invoice ───────
if ($method === 'DELETE') {
    if (!isOwner()) vch_fail('Only owners can delete vouchers.');
    if (!$id)       vch_fail('Voucher ID required.');
    $used = supabase_request('invoice_discounts', 'GET', [], 'voucher_id=eq.' . $id . '&select=invoice_discount_id&limit=1');
    if ($used['status'] >= 200 && $used['status'] < 300 && !empty($used['data'])) {
        vch_fail('This voucher has been used on an invoice, so it is kept for the billing history. Deactivate it instead.');
    }
    $res = supabase_request('discount_vouchers', 'DELETE', [], 'voucher_id=eq.' . $id);
    if ($res['status'] >= 200 && $res['status'] < 300) {
        echo json_encode(['success' => true, 'message' => 'Voucher deleted.']);
    } else {
        vch_fail($res['error'] ?? 'Failed to delete voucher.');
    }
    exit;
}

vch_fail('Method not allowed.');
