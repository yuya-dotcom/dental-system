<?php
// =============================================================
//  api/procurement.php — Suppliers, Purchase Requests, Purchase Orders,
//  Receiving. Integrated with Inventory: receiving a delivery adds the
//  stock (with lot / expiry) to the ordering branch in ONE database
//  transaction (inv_po_receive) and records a "Purchase Delivery"
//  movement; the item's unit cost and supplier are updated from the order.
//
//  Flow:  Purchase Request (branch) ── owner approves ──▶ Purchase Order
//         (owner, to a supplier) ── delivery ──▶ Partially Received / Received
//
//  Who:  suppliers           owner + admins (shared list; deactivate, never delete)
//        request create      owner, or an admin for their own branch
//        request approve/reject   owner          cancel   requester / owner
//        order create/cancel owner
//        receive delivery    owner, or an admin of the order's branch
//        dentists            view only
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/inventory_v2.php';
require_once __DIR__ . '/../controllers/audit_controller.php';

header('Content-Type: application/json');
requireLogin();
requireStaffApi('owner', 'admin', 'dentist');
if (!inv_v2_ready()) apiDeny(503, 'Procurement needs the inventory database update first.');

function pc_out(array $x): void { echo json_encode($x); exit; }
function pc_bname(?int $id): string { return $id ? (inv_branch_names()[$id] ?? ('Branch #' . $id)) : ''; }
function pc_log(string $action, $id, string $name, string $desc, ?int $branch = null): void
{
    logActivity('procurement', $action, $id, $name, $desc, $branch, $branch ? pc_bname($branch) : null);
}
function pc_masters(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $r = supabase_request('inventory_items', 'GET', [], 'select=master_id,item_name,unit,unit_cost,track_expiry,supplier_id&master_id=in.(' . implode(',', $ids) . ')');
    $out = [];
    foreach (($r['data'] ?? []) as $m) $out[(int)$m['master_id']] = $m;
    return $out;
}
/** Branch stock rows of the given masters: master_id => row. */
function pc_branch_stock(int $branch, array $masterIds): array
{
    if (!$masterIds) return [];
    $r = supabase_request('inventory', 'GET', [], 'select=item_id,master_id,stock_quantity,min_stock,reorder_qty&archived_at=is.null&branch_id=eq.'
        . $branch . '&master_id=in.(' . implode(',', array_map('intval', $masterIds)) . ')');
    $out = [];
    foreach (($r['data'] ?? []) as $x) $out[(int)$x['master_id']] = $x;
    return $out;
}
/** Clean [{master_id, quantity, unit_cost?}] lines. Returns [lines, error]. */
function pc_lines($lines, bool $withCost): array
{
    if (!is_array($lines) || !$lines) return [null, 'Add at least one item.'];
    if (count($lines) > 60) return [null, 'Too many items (60 max).'];
    $out = [];
    foreach ($lines as $l) {
        $m = (int)($l['master_id'] ?? 0); $q = (int)($l['quantity'] ?? 0);
        if (!$m && !$q) continue;
        if (!$m) return [null, 'Choose an item on every line.'];
        if ($q <= 0) return [null, 'Every item needs a quantity greater than 0.'];
        if (isset($out[$m])) return [null, 'The same item is listed twice. Combine the quantities.'];
        $row = ['master_id' => $m, 'quantity' => $q];
        if ($withCost) {
            $c = $l['unit_cost'] ?? '';
            if ($c === '' || !is_numeric($c) || (float)$c < 0) return [null, 'Every ordered item needs a unit cost (0 or more).'];
            $row['unit_cost'] = round((float)$c, 2);
        }
        $out[$m] = $row;
    }
    if (!$out) return [null, 'Add at least one item.'];
    $known = pc_masters(array_keys($out));
    foreach ($out as $m => $_) if (!isset($known[$m])) return [null, 'One of the items was not found.'];
    return [array_values($out), null];
}
function pc_set(string $table, string $pk, int $id, array $expect, array $fields): bool
{
    $r = supabase_request($table, 'PATCH', $fields + ['updated_at' => date('c')], $pk . '=eq.' . $id . '&status=in.(' . implode(',', $expect) . ')');
    return !$r['error'] && !empty($r['data']);
}

$uidName = inv_actor();
[$uid, $uname] = $uidName;
$now = date('c');

// ═════════════════════════ GET ═════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
    $what = $_GET['what'] ?? '';

    if ($what === 'pr') {
        $r = supabase_request('purchase_requests', 'GET', [], 'select=*,purchase_request_items(line_id,master_id,quantity,notes)&pr_id=eq.' . (int)($_GET['id'] ?? 0));
        $pr = $r['data'][0] ?? null;
        if (!$pr || !canAccessBranch($pr['branch_id'])) apiDeny(404, 'Request not found.');
        $lines = $pr['purchase_request_items'] ?? []; unset($pr['purchase_request_items']);
        $mids = array_column($lines, 'master_id');
        $ms = pc_masters($mids); $st = pc_branch_stock((int)$pr['branch_id'], $mids);
        $pr['branch_name'] = pc_bname((int)$pr['branch_id']);
        $out = array_map(fn($l) => $l + ['item_name' => $ms[$l['master_id']]['item_name'] ?? '', 'unit' => $ms[$l['master_id']]['unit'] ?? 'pcs',
            'unit_cost' => $ms[$l['master_id']]['unit_cost'] ?? null, 'supplier_id' => $ms[$l['master_id']]['supplier_id'] ?? null,
            'stock' => isset($st[$l['master_id']]) ? (int)$st[$l['master_id']]['stock_quantity'] : null], $lines);
        $s = $pr['status'];
        pc_out(['success' => true, 'pr' => $pr, 'lines' => $out, 'can' => [
            'approve' => isOwner() && $s === 'pending',
            'reject'  => isOwner() && in_array($s, ['pending', 'approved'], true),
            'order'   => isOwner() && $s === 'approved',
            'cancel'  => !isDentist() && in_array($s, ['pending', 'approved'], true) && (isOwner() || (int)$pr['requested_by'] === (int)$uid),
        ]]);
    }

    if ($what === 'po') {
        $r = supabase_request('purchase_orders', 'GET', [], 'select=*,suppliers(supplier_name,contact_person,phone,email),purchase_order_items(line_id,master_id,quantity_ordered,quantity_received,unit_cost),purchase_requests!purchase_orders_pr_id_fkey(pr_no)&po_id=eq.' . (int)($_GET['id'] ?? 0));
        if (($r['status'] ?? 0) >= 300) $r = supabase_request('purchase_orders', 'GET', [], 'select=*,suppliers(supplier_name,contact_person,phone,email),purchase_order_items(line_id,master_id,quantity_ordered,quantity_received,unit_cost)&po_id=eq.' . (int)($_GET['id'] ?? 0));
        $po = $r['data'][0] ?? null;
        if (!$po || !canAccessBranch($po['branch_id'])) apiDeny(404, 'Order not found.');
        $lines = $po['purchase_order_items'] ?? []; unset($po['purchase_order_items']);
        $ms = pc_masters(array_column($lines, 'master_id'));
        $po['branch_name'] = pc_bname((int)$po['branch_id']);
        $total = 0.0;
        $out = array_map(function ($l) use ($ms, &$total) {
            $total += $l['quantity_ordered'] * (float)$l['unit_cost'];
            return $l + ['item_name' => $ms[$l['master_id']]['item_name'] ?? '', 'unit' => $ms[$l['master_id']]['unit'] ?? 'pcs',
                         'track_expiry' => !empty($ms[$l['master_id']]['track_expiry'])];
        }, $lines);
        usort($out, fn($a, $b) => $a['line_id'] <=> $b['line_id']);
        $rc = supabase_request('purchase_receipts', 'GET', [], 'select=receipt_no,delivery_ref,notes,received_by_name,received_at,purchase_receipt_items(quantity,lot_number,expiry_date,line_id)&po_id=eq.' . (int)$po['po_id'] . '&order=received_at.asc');
        $s = $po['status'];
        pc_out(['success' => true, 'po' => $po + ['total' => round($total, 2)], 'lines' => $out, 'receipts' => $rc['data'] ?? [], 'can' => [
            'receive' => !isDentist() && in_array($s, ['ordered', 'partially_received'], true),
            'cancel'  => isOwner() && in_array($s, ['ordered', 'partially_received'], true),
        ]]);
    }

    if ($what === 'suggest') {
        // Items at or below their minimum in a branch, with the quantity to order
        $b = scopedBranch((int)($_GET['branch_id'] ?? 0));
        if ($b <= 0) apiDeny(400, 'Choose a branch.');
        $r = supabase_request('inventory', 'GET', [], 'select=master_id,item_name,stock_quantity,min_stock,reorder_qty,inventory_items(unit)&archived_at=is.null&branch_id=eq.' . $b . '&order=item_name.asc');
        $out = [];
        foreach (($r['data'] ?? []) as $x) {
            $q = (int)$x['stock_quantity']; $min = (int)$x['min_stock'];
            if ($q > $min) continue;
            $sug = (int)$x['reorder_qty'] ?: max(1, $min * 2 - $q);
            $out[] = ['master_id' => (int)$x['master_id'], 'item_name' => $x['item_name'], 'unit' => $x['inventory_items']['unit'] ?? 'pcs',
                      'stock' => $q, 'min' => $min, 'suggested' => $sug];
        }
        pc_out(['success' => true, 'items' => $out]);
    }
    apiDeny(400, 'Unknown request.');
}

// ═════════════════════════ POST ════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiDeny(405, 'Method not allowed.');
if (isDentist()) apiDeny(403, 'Dentists can view procurement but not change it.');
$in = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string)($in['action'] ?? '');

switch ($action) {

// ── Suppliers ────────────────────────────────────────────────
case 'supplier_save': {
    $id = (int)($in['supplier_id'] ?? 0);
    $name = trim((string)($in['supplier_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) pc_out(['success' => false, 'message' => 'Supplier name is required (120 characters max).']);
    $email = trim((string)($in['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) pc_out(['success' => false, 'message' => 'Email address is not valid.']);
    $phone = trim((string)($in['phone'] ?? ''));
    if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $phone)) pc_out(['success' => false, 'message' => 'Phone number is not valid.']);
    $f = ['supplier_name' => $name, 'contact_person' => mb_substr(trim((string)($in['contact_person'] ?? '')), 0, 100) ?: null,
          'phone' => $phone ?: null, 'email' => $email ?: null, 'address' => mb_substr(trim((string)($in['address'] ?? '')), 0, 250) ?: null,
          'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 500) ?: null, 'updated_at' => $now];
    $r = $id ? supabase_request('suppliers', 'PATCH', $f, 'supplier_id=eq.' . $id)
             : supabase_request('suppliers', 'POST', $f + ['created_at' => $now]);
    if ($r['error']) pc_out(['success' => false, 'message' => ($r['data']['code'] ?? '') === '23505' ? 'A supplier with this name already exists.' : $r['error']]);
    if ($id && empty($r['data'])) pc_out(['success' => false, 'message' => 'Supplier not found.']);
    $sid = $id ?: (int)($r['data'][0]['supplier_id'] ?? 0);
    pc_log($id ? 'UPDATE' : 'INSERT', $sid, $name, $id ? 'Edited supplier' : 'Added supplier');
    pc_out(['success' => true, 'message' => $id ? 'Supplier updated.' : 'Supplier added.', 'supplier_id' => $sid]);
}
case 'supplier_toggle': {
    $id = (int)($in['supplier_id'] ?? 0); $active = !empty($in['is_active']);
    $r = supabase_request('suppliers', 'PATCH', ['is_active' => $active, 'updated_at' => $now], 'supplier_id=eq.' . $id);
    if ($r['error'] || empty($r['data'])) pc_out(['success' => false, 'message' => $r['error'] ?: 'Supplier not found.']);
    pc_log('UPDATE', $id, $r['data'][0]['supplier_name'], $active ? 'Reactivated supplier' : 'Deactivated supplier');
    pc_out(['success' => true, 'message' => $active ? 'Supplier is active again.' : 'Supplier deactivated. Past orders keep its name.']);
}

// ── Purchase requests ───────────────────────────────────────
case 'pr_create': {
    $b = (int)($in['branch_id'] ?? 0);
    if (!$b || !isset(inv_branch_names()[$b])) pc_out(['success' => false, 'message' => 'Choose a branch.']);
    if (!canAccessBranch($b)) apiDeny(403, 'You can only request supplies for your own branch.');
    $reason = trim((string)($in['reason'] ?? ''));
    if ($reason === '' || mb_strlen($reason) > 250) pc_out(['success' => false, 'message' => 'Please give a reason (250 characters max).']);
    $need = inv_date($in['needed_by'] ?? '');
    if (($in['needed_by'] ?? '') !== '' && (!$need || $need < date('Y-m-d'))) pc_out(['success' => false, 'message' => 'Needed-by date must be today or later.']);
    [$lines, $err] = pc_lines($in['lines'] ?? null, false);
    if ($err) pc_out(['success' => false, 'message' => $err]);
    $key = inv_request_key($in['request_key'] ?? null, 'pr');
    $auto = isOwner();
    $r = supabase_request('purchase_requests', 'POST', array_filter([
        'branch_id' => $b, 'reason' => $reason, 'needed_by' => $need, 'status' => $auto ? 'approved' : 'pending',
        'requested_by' => $uid, 'requested_by_name' => $uname, 'requested_at' => $now,
        'approved_by' => $auto ? $uid : null, 'approved_by_name' => $auto ? $uname : null, 'approved_at' => $auto ? $now : null,
        'request_key' => $key, 'updated_at' => $now,
    ], fn($v) => $v !== null));
    if ($r['error'] || empty($r['data'][0])) {
        if (($r['data']['code'] ?? '') === '23505') pc_out(['success' => true, 'message' => 'This request was already submitted.', 'duplicate' => true]);
        pc_out(['success' => false, 'message' => $r['error'] ?: 'Could not save the request.']);
    }
    $pr = $r['data'][0];
    $li = supabase_request('purchase_request_items', 'POST', array_map(fn($l) => ['pr_id' => (int)$pr['pr_id']] + $l, $lines));
    if ($li['error']) { supabase_request('purchase_requests', 'DELETE', [], 'pr_id=eq.' . (int)$pr['pr_id']); pc_out(['success' => false, 'message' => 'Could not save the items: ' . $li['error']]); }
    $ms = pc_masters(array_column($lines, 'master_id'));
    pc_log('INSERT', $pr['pr_id'], $pr['pr_no'], ($auto ? 'Created (approved)' : 'Requested') . ' for ' . pc_bname($b) . ': '
        . implode(', ', array_map(fn($l) => $ms[$l['master_id']]['item_name'] . ' ×' . $l['quantity'], $lines)) . '. Reason: ' . $reason, $b);
    pc_out(['success' => true, 'message' => 'Purchase request ' . $pr['pr_no'] . ($auto ? ' created. You can now turn it into a purchase order.' : ' submitted. The owner will review it.')]);
}
case 'pr_approve': case 'pr_reject': case 'pr_cancel': {
    $r = supabase_request('purchase_requests', 'GET', [], 'select=pr_id,pr_no,branch_id,status,requested_by&pr_id=eq.' . (int)($in['pr_id'] ?? 0));
    $pr = $r['data'][0] ?? null;
    if (!$pr || !canAccessBranch($pr['branch_id'])) apiDeny(404, 'Request not found.');
    $id = (int)$pr['pr_id']; $b = (int)$pr['branch_id'];
    if ($action === 'pr_approve') {
        if (!isOwner()) apiDeny(403, 'Only the owner approves purchase requests.');
        if (!pc_set('purchase_requests', 'pr_id', $id, ['pending'], ['status' => 'approved', 'approved_by' => $uid, 'approved_by_name' => $uname, 'approved_at' => $now]))
            pc_out(['success' => false, 'message' => 'This request is no longer pending.']);
        pc_log('UPDATE', $id, $pr['pr_no'], 'Approved purchase request', $b);
        pc_out(['success' => true, 'message' => 'Request approved. Create a purchase order when you place the order with the supplier.']);
    }
    if ($action === 'pr_reject') {
        if (!isOwner()) apiDeny(403, 'Only the owner rejects purchase requests.');
        $why = trim((string)($in['reason'] ?? ''));
        if ($why === '') pc_out(['success' => false, 'message' => 'Please give a reason for rejecting.']);
        if (!pc_set('purchase_requests', 'pr_id', $id, ['pending', 'approved'], ['status' => 'rejected', 'rejected_by' => $uid, 'rejected_by_name' => $uname, 'rejected_at' => $now, 'rejection_reason' => mb_substr($why, 0, 250)]))
            pc_out(['success' => false, 'message' => 'This request can no longer be rejected.']);
        pc_log('UPDATE', $id, $pr['pr_no'], 'Rejected purchase request. Reason: ' . $why, $b);
        pc_out(['success' => true, 'message' => 'Request rejected.']);
    }
    if (!isOwner() && (int)$pr['requested_by'] !== (int)$uid) apiDeny(403, 'Only the person who requested it (or the owner) can cancel it.');
    if (!pc_set('purchase_requests', 'pr_id', $id, ['pending', 'approved'], ['status' => 'cancelled', 'cancelled_by' => $uid, 'cancelled_by_name' => $uname, 'cancelled_at' => $now]))
        pc_out(['success' => false, 'message' => 'This request can no longer be cancelled.']);
    pc_log('UPDATE', $id, $pr['pr_no'], 'Cancelled purchase request', $b);
    pc_out(['success' => true, 'message' => 'Request cancelled.']);
}

// ── Purchase orders ─────────────────────────────────────────
case 'po_create': {
    if (!isOwner()) apiDeny(403, 'Only the owner creates purchase orders.');
    $sup = (int)($in['supplier_id'] ?? 0);
    $s = supabase_request('suppliers', 'GET', [], 'select=supplier_id,supplier_name,is_active&supplier_id=eq.' . $sup);
    if (empty($s['data'][0])) pc_out(['success' => false, 'message' => 'Choose a supplier.']);
    if (empty($s['data'][0]['is_active'])) pc_out(['success' => false, 'message' => 'That supplier is inactive.']);
    $b = (int)($in['branch_id'] ?? 0);
    if (!$b || !isset(inv_branch_names()[$b])) pc_out(['success' => false, 'message' => 'Choose the branch the delivery goes to.']);
    $exp = inv_date($in['expected_date'] ?? '');
    if (($in['expected_date'] ?? '') !== '' && !$exp) pc_out(['success' => false, 'message' => 'Expected delivery date is not valid.']);
    [$lines, $err] = pc_lines($in['lines'] ?? null, true);
    if ($err) pc_out(['success' => false, 'message' => $err]);
    $prId = (int)($in['pr_id'] ?? 0) ?: null;
    if ($prId) {
        $p = supabase_request('purchase_requests', 'GET', [], 'select=pr_id,pr_no,status,branch_id&pr_id=eq.' . $prId);
        $pr = $p['data'][0] ?? null;
        if (!$pr || $pr['status'] !== 'approved') pc_out(['success' => false, 'message' => 'The purchase request must be approved (and not yet ordered).']);
        if ((int)$pr['branch_id'] !== $b) pc_out(['success' => false, 'message' => 'The delivery branch must match the request\'s branch.']);
    }
    $key = inv_request_key($in['request_key'] ?? null, 'po');
    $r = supabase_request('purchase_orders', 'POST', array_filter([
        'supplier_id' => $sup, 'branch_id' => $b, 'pr_id' => $prId, 'status' => 'ordered',
        'order_date' => inv_date($in['order_date'] ?? '') ?: date('Y-m-d'), 'expected_date' => $exp,
        'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 500) ?: null,
        'created_by' => $uid, 'created_by_name' => $uname, 'created_at' => $now, 'updated_at' => $now, 'request_key' => $key,
    ], fn($v) => $v !== null));
    if ($r['error'] || empty($r['data'][0])) {
        if (($r['data']['code'] ?? '') === '23505') pc_out(['success' => true, 'message' => 'This order was already created.', 'duplicate' => true]);
        pc_out(['success' => false, 'message' => $r['error'] ?: 'Could not save the order.']);
    }
    $po = $r['data'][0];
    $li = supabase_request('purchase_order_items', 'POST', array_map(fn($l) => ['po_id' => (int)$po['po_id'], 'master_id' => $l['master_id'],
        'quantity_ordered' => $l['quantity'], 'unit_cost' => $l['unit_cost']], $lines));
    if ($li['error']) { supabase_request('purchase_orders', 'DELETE', [], 'po_id=eq.' . (int)$po['po_id']); pc_out(['success' => false, 'message' => 'Could not save the items: ' . $li['error']]); }
    if ($prId) pc_set('purchase_requests', 'pr_id', $prId, ['approved'], ['status' => 'ordered', 'po_id' => (int)$po['po_id']]);
    $total = array_sum(array_map(fn($l) => $l['quantity'] * $l['unit_cost'], $lines));
    pc_log('INSERT', $po['po_id'], $po['po_no'], 'Ordered from ' . $s['data'][0]['supplier_name'] . ' for ' . pc_bname($b) . ' — ' . count($lines) . ' item(s), total ' . inv_peso($total)
        . ($prId ? ' (from ' . $pr['pr_no'] . ')' : ''), $b);
    pc_out(['success' => true, 'message' => 'Purchase order ' . $po['po_no'] . ' created. Receive it here when the delivery arrives.']);
}
case 'po_cancel': {
    if (!isOwner()) apiDeny(403, 'Only the owner cancels purchase orders.');
    $r = supabase_request('purchase_orders', 'GET', [], 'select=po_id,po_no,branch_id,status&po_id=eq.' . (int)($in['po_id'] ?? 0));
    $po = $r['data'][0] ?? null;
    if (!$po) apiDeny(404, 'Order not found.');
    $why = trim((string)($in['reason'] ?? ''));
    if ($why === '') pc_out(['success' => false, 'message' => 'Please give a reason.']);
    if (!pc_set('purchase_orders', 'po_id', (int)$po['po_id'], ['ordered', 'partially_received'], ['status' => 'cancelled', 'cancelled_by' => $uid, 'cancelled_by_name' => $uname, 'cancelled_at' => $now, 'cancel_reason' => mb_substr($why, 0, 250)]))
        pc_out(['success' => false, 'message' => 'This order can no longer be cancelled.']);
    pc_log('UPDATE', $po['po_id'], $po['po_no'], ($po['status'] === 'partially_received' ? 'Cancelled the undelivered rest of the order' : 'Cancelled order') . '. Reason: ' . $why, (int)$po['branch_id']);
    pc_out(['success' => true, 'message' => $po['status'] === 'partially_received' ? 'The rest of the order was cancelled. Delivered stock stays in inventory.' : 'Order cancelled.']);
}
case 'po_receive': {
    $r = supabase_request('purchase_orders', 'GET', [], 'select=po_id,po_no,branch_id,status,purchase_order_items(line_id,master_id,quantity_ordered,quantity_received)&po_id=eq.' . (int)($in['po_id'] ?? 0));
    $po = $r['data'][0] ?? null;
    if (!$po || !canAccessBranch($po['branch_id'])) apiDeny(404, 'Order not found.');
    // The same submission again (double click, retry): already recorded, nothing to do
    $rkey = inv_request_key($in['request_key'] ?? null, 'rcv');
    if ($rkey) {
        $seen = supabase_request('purchase_receipts', 'GET', [], 'select=receipt_no&po_id=eq.' . (int)$po['po_id'] . '&request_key=eq.' . rawurlencode($rkey));
        if (!empty($seen['data'][0])) pc_out(['success' => true, 'duplicate' => true, 'message' => 'This delivery was already recorded (' . $seen['data'][0]['receipt_no'] . ').']);
    }
    $byLine = [];
    foreach ($po['purchase_order_items'] ?? [] as $l) $byLine[(int)$l['line_id']] = $l;
    $ms = pc_masters(array_column($byLine, 'master_id'));
    $send = []; $sum = [];
    foreach ((array)($in['lines'] ?? []) as $l) {
        $lid = (int)($l['line_id'] ?? 0); $q = (int)($l['quantity'] ?? 0);
        if ($q <= 0) continue;
        $line = $byLine[$lid] ?? null;
        if (!$line) pc_out(['success' => false, 'message' => 'A delivered line does not belong to this order.']);
        $left = (int)$line['quantity_ordered'] - (int)$line['quantity_received'];
        $name = $ms[(int)$line['master_id']]['item_name'] ?? 'Item';
        if ($q > $left) pc_out(['success' => false, 'message' => "\"{$name}\": only {$left} still due."]);
        $exp = inv_date($l['expiry_date'] ?? ''); $mfd = inv_date($l['manufactured_date'] ?? '');
        if (($l['expiry_date'] ?? '') !== '' && !$exp) pc_out(['success' => false, 'message' => "\"{$name}\": expiry date is not valid."]);
        if (!empty($ms[(int)$line['master_id']]['track_expiry']) && !$exp) pc_out(['success' => false, 'message' => "\"{$name}\" tracks expiry dates — enter the expiry date."]);
        if ($mfd && $exp && $mfd > $exp) pc_out(['success' => false, 'message' => "\"{$name}\": manufacturing date must be before the expiry date."]);
        $send[] = ['line_id' => $lid, 'quantity' => $q, 'lot_number' => mb_substr(trim((string)($l['lot_number'] ?? '')), 0, 60), 'expiry_date' => $exp, 'manufactured_date' => $mfd];
        $sum[] = "{$name} ×{$q}";
    }
    if (!$send) pc_out(['success' => false, 'message' => 'Enter the quantity delivered for at least one item.']);
    $ref = mb_substr(trim((string)($in['delivery_ref'] ?? '')), 0, 60);
    $res = inv_rpc('inv_po_receive', [
        'p_po_id' => (int)$po['po_id'], 'p_lines' => $send, 'p_delivery_ref' => $ref, 'p_notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 500),
        'p_user_id' => $uid, 'p_user_name' => $uname, 'p_request_key' => $rkey,
    ]);
    if (empty($res['ok'])) pc_out(['success' => false, 'message' => $res['message'] ?? 'Could not receive the delivery.']);
    if (empty($res['duplicate'])) {
        pc_log('UPDATE', $po['po_id'], $po['po_no'], 'Received delivery ' . ($res['receipt_no'] ?? '') . ($ref ? " (ref {$ref})" : '') . ': ' . implode(', ', $sum)
            . ' — stock added to ' . pc_bname((int)$po['branch_id']), (int)$po['branch_id']);
        staffn_delivery_received((int)$po['po_id'], (string)($po['po_no'] ?? ''), (int)$po['branch_id'],
            (string)($res['receipt_no'] ?? ''), $sum, pc_bname((int)$po['branch_id']));
    }
    pc_out(['success' => true, 'message' => 'Delivery recorded and stock added to ' . pc_bname((int)$po['branch_id']) . '.'
        . (($res['status'] ?? '') === 'partially_received' ? ' Some items are still due.' : ' The order is complete.')]);
}
}
pc_out(['success' => false, 'message' => 'Invalid action.']);
