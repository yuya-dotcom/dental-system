<?php
// =============================================================
//  api/inventory_transfers.php — branch-to-branch stock transfers
//
//  Flow:  Pending ──(owner approves)──▶ Approved ──(source sends)──▶ In Transit
//         ──(destination receives)──▶ Received
//         Pending / Approved ──▶ Rejected (owner, with reason) | Cancelled
//  Stock moves ONLY when the transfer is sent (deducted at the source) and
//  received (added at the destination) — each in one database transaction
//  (inv_transfer_dispatch / inv_transfer_receive), so a transfer can never
//  be half-applied or applied twice.
//
//  Who:  create   owner, or an admin of the source OR destination branch
//                 (created by the owner = approved straight away)
//        approve / reject   owner
//        send     owner or an admin of the source branch
//        receive  owner or an admin of the destination branch
//        cancel   the requester or the owner, while pending / approved
//  GET ?id=N  details (lines, batches, timeline) for anyone who can see it.
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/inventory_v2.php';
require_once __DIR__ . '/../controllers/audit_controller.php';
require_once __DIR__ . '/../controllers/admin_batch.php';   // admin_prefetch(): parallel reads

header('Content-Type: application/json');
requireLogin();
requireStaffApi('owner', 'admin', 'dentist');
if (!inv_v2_ready()) apiDeny(503, 'Stock transfers need the inventory database update first.');

const TRF_SEL = 'transfer_id,transfer_no,from_branch_id,to_branch_id,status,reason,notes,requested_by,requested_by_name,requested_at,'
              . 'approved_by_name,approved_at,dispatched_by_name,dispatched_at,received_by_name,received_at,'
              . 'rejected_by_name,rejected_at,rejection_reason,cancelled_by_name,cancelled_at,updated_at';

function trf_get(int $id): ?array
{
    $r = supabase_request('stock_transfers', 'GET', [], 'select=' . TRF_SEL . '&transfer_id=eq.' . $id);
    return $r['data'][0] ?? null;
}
function trf_can_see(array $t): bool { return canAccessBranch($t['from_branch_id']) || canAccessBranch($t['to_branch_id']); }
function trf_bname(int $id): string { return inv_branch_names()[$id] ?? ('Branch #' . $id); }
function trf_out(array $x): void { echo json_encode($x); exit; }
function trf_log(array $t, string $what, string $action = 'UPDATE'): void
{
    $desc = sprintf('%s — %s → %s: %s', $t['transfer_no'], trf_bname((int)$t['from_branch_id']), trf_bname((int)$t['to_branch_id']), $what);
    logActivity('stock-transfers', $action, $t['transfer_id'], $t['transfer_no'], $desc, (int)$t['from_branch_id'], trf_bname((int)$t['from_branch_id']));
}

// ── GET details ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
    // Transfer, its lines and the branch names in ONE parallel wave (nothing is
    // sent back before the access check)
    $tid = (int)($_GET['id'] ?? 0);
    $res = admin_prefetch([
        't' => admin_q('stock_transfers', 'select=' . TRF_SEL . '&transfer_id=eq.' . $tid),
        'l' => admin_q('stock_transfer_items', 'select=line_id,master_id,from_item_id,to_item_id,quantity,batch_id,sent_detail,'
            . 'inventory_items(item_name,unit),inventory_batches(lot_number,expiry_date)&transfer_id=eq.' . $tid . '&order=line_id.asc'),
    ], ['branches']);
    $t = $res['t']['data'][0] ?? null;
    if (!$t || !trf_can_see($t)) apiDeny(404, 'Transfer not found.');
    $l = $res['l'];
    $lines = [];
    $srcOwn = canAccessBranch($t['from_branch_id']);
    $stock = [];
    if ($srcOwn && in_array($t['status'], ['pending', 'approved'], true)) {
        $ids = array_map(fn($x) => (int)$x['from_item_id'], $l['data'] ?? []);
        if ($ids) {
            $s = supabase_request('inventory', 'GET', [], 'select=item_id,stock_quantity&item_id=in.(' . implode(',', $ids) . ')');
            foreach (($s['data'] ?? []) as $x) $stock[(int)$x['item_id']] = (int)$x['stock_quantity'];
        }
    }
    foreach (($l['data'] ?? []) as $x) {
        $lines[] = [
            'item_name' => $x['inventory_items']['item_name'] ?? '', 'unit' => $x['inventory_items']['unit'] ?? 'pcs',
            'quantity' => (int)$x['quantity'],
            'batch' => $x['batch_id'] ? trim(($x['inventory_batches']['lot_number'] ?? 'Batch') . ' · exp. ' . ($x['inventory_batches']['expiry_date'] ?? '—')) : null,
            'sent_detail' => $x['sent_detail'],
            'source_stock' => $stock[(int)$x['from_item_id']] ?? null,
        ];
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $st  = $t['status'];
    trf_out(['success' => true, 'transfer' => $t + [
        'from_name' => trf_bname((int)$t['from_branch_id']), 'to_name' => trf_bname((int)$t['to_branch_id']),
    ], 'lines' => $lines, 'can' => [
        'approve' => isOwner() && $st === 'pending',
        'reject'  => isOwner() && in_array($st, ['pending', 'approved'], true),
        'send'    => !isDentist() && $st === 'approved' && canAccessBranch($t['from_branch_id']),
        'receive' => !isDentist() && $st === 'in_transit' && canAccessBranch($t['to_branch_id']),
        'cancel'  => !isDentist() && in_array($st, ['pending', 'approved'], true) && (isOwner() || (int)$t['requested_by'] === $uid),
    ]]);
}

// ── POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiDeny(405, 'Method not allowed.');
if (isDentist()) apiDeny(403, 'Dentists can view transfers but not change them.');
$in = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string)($in['action'] ?? '');
[$uid, $uname] = inv_actor();
$now = date('c');

if ($action === 'create') {
    $from = (int)($in['from_branch_id'] ?? 0);
    $to   = (int)($in['to_branch_id'] ?? 0);
    $reason = trim((string)($in['reason'] ?? ''));
    $notes  = trim((string)($in['notes'] ?? ''));
    $lines  = is_array($in['lines'] ?? null) ? $in['lines'] : [];
    $names = inv_branch_names();
    if (!isset($names[$from]) || !isset($names[$to])) trf_out(['success' => false, 'message' => 'Choose both branches.']);
    if ($from === $to)                                 trf_out(['success' => false, 'message' => 'Source and destination must be different branches.']);
    if (!canAccessBranch($from) && !canAccessBranch($to)) apiDeny(403, 'You can only create transfers to or from your own branch.');
    if ($reason === '')               trf_out(['success' => false, 'message' => 'Please give a reason for the transfer.']);
    if (mb_strlen($reason) > 250)     trf_out(['success' => false, 'message' => 'Reason is too long (250 characters max).']);
    if (!$lines)                      trf_out(['success' => false, 'message' => 'Add at least one item.']);
    if (count($lines) > 50)           trf_out(['success' => false, 'message' => 'Too many items in one transfer (50 max).']);

    // Lines: items of the SOURCE branch, one line per item
    $want = [];
    foreach ($lines as $ln) {
        $iid = (int)($ln['item_id'] ?? 0); $q = (int)($ln['quantity'] ?? 0);
        if (!$iid) continue;
        if ($q <= 0) trf_out(['success' => false, 'message' => 'Every item needs a quantity greater than 0.']);
        if (isset($want[$iid])) trf_out(['success' => false, 'message' => 'The same item is listed twice. Combine the quantities into one line.']);
        $want[$iid] = ['q' => $q, 'batch' => !empty($ln['batch_id']) ? (int)$ln['batch_id'] : null];
    }
    if (!$want) trf_out(['success' => false, 'message' => 'Add at least one item.']);
    $r = supabase_request('inventory', 'GET', [], 'select=item_id,item_name,branch_id,master_id,stock_quantity,archived_at&item_id=in.(' . implode(',', array_keys($want)) . ')');
    $rows = [];
    foreach (($r['data'] ?? []) as $x) $rows[(int)$x['item_id']] = $x;
    $srcOwn = canAccessBranch($from);
    foreach ($want as $iid => $w) {
        $x = $rows[$iid] ?? null;
        if (!$x || (int)$x['branch_id'] !== $from || !empty($x['archived_at'])) trf_out(['success' => false, 'message' => 'One of the items is not stocked at the source branch.']);
        // Quantities are checked again when the stock is sent; here only for the source's own staff
        if ($srcOwn && $w['q'] > stock_usable((int)$x['stock_quantity'])) {
            trf_out(['success' => false, 'message' => sprintf('"%s": only %d can be sent (1 unit must stay).', $x['item_name'], stock_usable((int)$x['stock_quantity']))]);
        }
        if ($w['batch']) {
            $b = supabase_request('inventory_batches', 'GET', [], 'select=batch_id,quantity_remaining&batch_id=eq.' . $w['batch'] . '&item_id=eq.' . $iid);
            if (empty($b['data'][0])) trf_out(['success' => false, 'message' => 'A selected batch does not belong to its item.']);
            if ((int)$b['data'][0]['quantity_remaining'] < $w['q']) trf_out(['success' => false, 'message' => sprintf('"%s": the selected batch has only %d left.', $x['item_name'], $b['data'][0]['quantity_remaining'])]);
        }
    }

    $key = inv_request_key($in['request_key'] ?? null, 'trf');
    if ($key) {
        $dup = supabase_request('stock_transfers', 'GET', [], 'select=transfer_id,transfer_no&request_key=eq.' . rawurlencode($key));
        if (!empty($dup['data'][0])) trf_out(['success' => true, 'message' => 'Transfer ' . $dup['data'][0]['transfer_no'] . ' was already created.', 'duplicate' => true]);
    }
    $auto = isOwner();   // the owner's own request needs no separate approval
    $t = supabase_request('stock_transfers', 'POST', array_filter([
        'from_branch_id' => $from, 'to_branch_id' => $to, 'reason' => $reason, 'notes' => $notes ?: null,
        'status' => $auto ? 'approved' : 'pending',
        'requested_by' => $uid, 'requested_by_name' => $uname, 'requested_at' => $now,
        'approved_by' => $auto ? $uid : null, 'approved_by_name' => $auto ? $uname : null, 'approved_at' => $auto ? $now : null,
        'request_key' => $key, 'updated_at' => $now,
    ], fn($v) => $v !== null));
    if ($t['error'] || empty($t['data'][0])) {
        if (($t['data']['code'] ?? '') === '23505') trf_out(['success' => true, 'message' => 'This transfer was already created.', 'duplicate' => true]);
        trf_out(['success' => false, 'message' => $t['error'] ?: 'Could not create the transfer.']);
    }
    $tr = $t['data'][0];
    $payload = [];
    foreach ($want as $iid => $w) {
        $payload[] = ['transfer_id' => (int)$tr['transfer_id'], 'master_id' => (int)$rows[$iid]['master_id'], 'from_item_id' => $iid,
                      'quantity' => $w['q'], 'batch_id' => $w['batch']];
    }
    $li = supabase_request('stock_transfer_items', 'POST', $payload);
    if ($li['error']) {
        supabase_request('stock_transfers', 'DELETE', [], 'transfer_id=eq.' . (int)$tr['transfer_id']);
        trf_out(['success' => false, 'message' => 'Could not save the items: ' . $li['error']]);
    }
    $items = implode(', ', array_map(fn($iid) => $rows[$iid]['item_name'] . ' ×' . $want[$iid]['q'], array_keys($want)));
    trf_log($tr, ($auto ? 'Created and approved' : 'Requested') . ". Items: {$items}. Reason: {$reason}", 'INSERT');
    trf_out(['success' => true, 'message' => 'Transfer ' . $tr['transfer_no'] . ($auto ? ' created and approved. The source branch can now send it.' : ' requested. The owner will review it.')]);
}

$t = trf_get((int)($in['transfer_id'] ?? 0));
if (!$t || !trf_can_see($t)) apiDeny(404, 'Transfer not found.');
$id = (int)$t['transfer_id'];

/** Status change guarded by the expected current status (no double approvals). */
function trf_set(int $id, array $expect, array $fields): bool
{
    $r = supabase_request('stock_transfers', 'PATCH', $fields + ['updated_at' => date('c')],
        'transfer_id=eq.' . $id . '&status=in.(' . implode(',', $expect) . ')');
    return !$r['error'] && !empty($r['data']);
}

switch ($action) {
    case 'approve':
        if (!isOwner()) apiDeny(403, 'Only the owner approves transfers.');
        if (!trf_set($id, ['pending'], ['status' => 'approved', 'approved_by' => $uid, 'approved_by_name' => $uname, 'approved_at' => $now]))
            trf_out(['success' => false, 'message' => 'This transfer is no longer pending.']);
        trf_log($t, 'Approved');
        trf_out(['success' => true, 'message' => 'Transfer approved. ' . trf_bname((int)$t['from_branch_id']) . ' can now send the stock.']);

    case 'reject':
        if (!isOwner()) apiDeny(403, 'Only the owner rejects transfers.');
        $why = trim((string)($in['reason'] ?? ''));
        if ($why === '') trf_out(['success' => false, 'message' => 'Please give a reason for rejecting.']);
        if (!trf_set($id, ['pending', 'approved'], ['status' => 'rejected', 'rejected_by' => $uid, 'rejected_by_name' => $uname, 'rejected_at' => $now, 'rejection_reason' => mb_substr($why, 0, 250)]))
            trf_out(['success' => false, 'message' => 'This transfer can no longer be rejected.']);
        trf_log($t, 'Rejected. Reason: ' . $why);
        trf_out(['success' => true, 'message' => 'Transfer rejected.']);

    case 'cancel':
        if (!isOwner() && (int)$t['requested_by'] !== (int)$uid) apiDeny(403, 'Only the person who requested it (or the owner) can cancel this transfer.');
        if (!trf_set($id, ['pending', 'approved'], ['status' => 'cancelled', 'cancelled_by' => $uid, 'cancelled_by_name' => $uname, 'cancelled_at' => $now]))
            trf_out(['success' => false, 'message' => 'This transfer can no longer be cancelled.']);
        trf_log($t, 'Cancelled');
        trf_out(['success' => true, 'message' => 'Transfer cancelled.']);

    case 'send':
        if (!canAccessBranch($t['from_branch_id'])) apiDeny(403, 'Only the source branch (or the owner) sends the stock.');
        $r = inv_rpc('inv_transfer_dispatch', ['p_transfer_id' => $id, 'p_user_id' => $uid, 'p_user_name' => $uname]);
        if (empty($r['ok'])) trf_out(['success' => false, 'message' => $r['message'] ?? 'Could not send the transfer.']);
        if (empty($r['duplicate'])) trf_log($t, 'Sent (stock deducted from ' . trf_bname((int)$t['from_branch_id']) . ')');
        if (empty($r['duplicate'])) staffn_later(fn() => staffn_inventory_sync(null));   // staff bell: stock levels changed
        trf_out(['success' => true, 'message' => 'Marked as In Transit. The stock was deducted from ' . trf_bname((int)$t['from_branch_id']) . '.']);

    case 'receive':
        if (!canAccessBranch($t['to_branch_id'])) apiDeny(403, 'Only the destination branch (or the owner) receives the stock.');
        $r = inv_rpc('inv_transfer_receive', ['p_transfer_id' => $id, 'p_user_id' => $uid, 'p_user_name' => $uname]);
        if (empty($r['ok'])) trf_out(['success' => false, 'message' => $r['message'] ?? 'Could not receive the transfer.']);
        if (empty($r['duplicate'])) trf_log($t, 'Received (stock added to ' . trf_bname((int)$t['to_branch_id']) . ')');
        if (empty($r['duplicate'])) staffn_later(fn() => staffn_inventory_sync(null));   // staff bell: stock levels changed
        trf_out(['success' => true, 'message' => 'Received. The stock was added to ' . trf_bname((int)$t['to_branch_id']) . '.']);
}
trf_out(['success' => false, 'message' => 'Invalid action.']);
