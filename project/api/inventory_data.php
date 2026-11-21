<?php
// =============================================================
//  api/inventory_data.php — read-only inventory lookups (staff)
//   GET ?action=item&id=N            item details: master, branch balance,
//                                    batches, recent movements, stock value,
//                                    other branches' stock (owner only)
//   GET ?action=lookup               suppliers + item masters (form dropdowns)
//   GET ?action=branch_items&branch_id=N
//                                    active items of a branch (transfer / request forms)
//  Branch scope: admins / dentists only ever see their own branch.
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/inventory_v2.php';
require_once __DIR__ . '/../controllers/admin_batch.php';   // admin_sb_many(): parallel reads

header('Content-Type: application/json');
requireLogin();
requireStaffApi('owner', 'admin', 'dentist');
session_write_close();   // read-only

$action = $_GET['action'] ?? '';

if ($action === 'item') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) apiDeny(400, 'Item ID required.');
    $v2 = inv_v2_ready();
    // Owner: the same item at the other branches comes embedded through the item master
    $othersSel = $v2 && isOwner() ? ',inventory(item_id,branch_id,stock_quantity,min_stock,status,archived_at,branches(branch_name))' : '';
    $sel = 'item_id,item_code,item_name,category,branch_id,stock_quantity,min_stock,status,created_at,updated_at,archived_at,branches(branch_name)'
         . ($v2 ? ',master_id,reorder_qty,inventory_items(master_id,unit,unit_cost,track_expiry,supplier_id,suppliers(supplier_name,contact_person,phone)' . $othersSel . ')' : '');
    $movSel = 'movement_id,movement_date,created_at,quantity_change,reason,performed_by'
            . ($v2 ? ',movement_type,stock_before,stock_after,reference_no,batch_detail' : '');

    // Every read is keyed by the item id, so they run in ONE parallel wave.
    // Nothing is sent back before the branch-access check below.
    $q = [
        'item' => admin_q('inventory', 'select=' . $sel . '&item_id=eq.' . $id),
        'mov'  => admin_q('inventory_movements', 'select=' . $movSel . '&item_id=eq.' . $id . '&order=created_at.desc,movement_id.desc&limit=15'),
    ];
    if ($v2) {
        $q['batches'] = admin_q('inventory_batches',
            'select=batch_id,lot_number,expiry_date,manufactured_date,quantity_received,quantity_remaining,source,reference_no,received_at'
            . '&item_id=eq.' . $id . '&quantity_remaining=gt.0&order=expiry_date.asc.nullslast,batch_id.asc');
        $q['alert'] = admin_q('inventory_stock_alerts', 'select=level,opened_at&item_id=eq.' . $id . '&resolved_at=is.null&limit=1');
    }
    $res = admin_sb_many($q);

    $item = $res['item']['data'][0] ?? null;
    if (!$item) apiDeny(404, 'Item not found.');
    requireBranchAccess($item['branch_id'] ?? null);

    $mov = $res['mov'];
    $batches = []; $others = []; $alert = null;
    if ($v2) {
        foreach ((array)($res['batches']['data'] ?? []) as $x) { $x['state'] = inv_expiry_state($x['expiry_date']); $batches[] = $x; }
        if ($othersSel) {
            $others = array_values(array_filter((array)($item['inventory_items']['inventory'] ?? []),
                fn($o) => (int)$o['item_id'] !== $id && empty($o['archived_at'])));
            usort($others, fn($a, $b) => (int)$a['branch_id'] <=> (int)$b['branch_id']);
        }
        $alert = $res['alert']['data'][0] ?? null;
    }
    $m = $item['inventory_items'] ?? [];
    $inBatches = array_sum(array_map(fn($x) => (int)$x['quantity_remaining'], $batches));
    $movements = array_map(function ($x) {
        $x['type_label'] = inv_type_label($x['movement_type'] ?? null);
        $x['type_badge'] = inv_type_badge($x['movement_type'] ?? null);
        return $x;
    }, $mov['data'] ?? []);
    echo json_encode([
        'success'   => true,
        'v2'        => $v2,
        'item'      => [
            'item_id'      => (int)$item['item_id'],
            'item_code'    => $item['item_code'],
            'item_name'    => $item['item_name'],
            'category'     => $item['category'],
            'branch_id'    => (int)$item['branch_id'],
            'branch_name'  => $item['branches']['branch_name'] ?? '',
            'stock'        => (int)$item['stock_quantity'],
            'min_stock'    => (int)$item['min_stock'],
            'reorder_qty'  => (int)($item['reorder_qty'] ?? 0),
            'status'       => $item['status'],
            'archived_at'  => $item['archived_at'],
            'updated_at'   => $item['updated_at'] ?? $item['created_at'],
            'unit'         => $m['unit'] ?? 'pcs',
            'unit_cost'    => isset($m['unit_cost']) ? (float)$m['unit_cost'] : null,
            'stock_value'  => isset($m['unit_cost']) ? round((float)$m['unit_cost'] * (int)$item['stock_quantity'], 2) : null,
            'track_expiry' => !empty($m['track_expiry']),
            'supplier_id'  => $m['supplier_id'] ?? null,
            'supplier'     => $m['suppliers'] ?? null,
            'no_batch_qty' => max(0, (int)$item['stock_quantity'] - $inBatches),
        ],
        'batches'   => $batches,
        'movements' => $movements,
        'others'    => array_map(fn($o) => ['branch' => $o['branches']['branch_name'] ?? '', 'stock' => (int)$o['stock_quantity'],
                                            'min' => (int)$o['min_stock'], 'status' => $o['status']], $others),
        'alert'     => $alert,
        'near_days' => INV_NEAR_EXPIRY_DAYS,
    ]);
    exit;
}

if ($action === 'lookup') {
    if (!inv_v2_ready()) { echo json_encode(['success' => true, 'v2' => false, 'suppliers' => [], 'masters' => []]); exit; }
    $res = admin_sb_many([
        's' => admin_q('suppliers', 'select=supplier_id,supplier_name,is_active&order=supplier_name.asc'),
        'm' => admin_q('inventory_items', 'select=master_id,item_name,category,unit,unit_cost,supplier_id,track_expiry&order=item_name.asc'),
    ]);
    echo json_encode(['success' => true, 'v2' => true, 'suppliers' => $res['s']['data'] ?? [], 'masters' => $res['m']['data'] ?? [], 'units' => INV_UNITS]);
    exit;
}

if ($action === 'branch_items') {
    $bid = (int)($_GET['branch_id'] ?? 0);
    if (!$bid) apiDeny(400, 'Branch required.');
    // Owner: any branch. Others: their own branch, or another branch's item NAMES only
    // (needed to request stock from it) — never its quantities.
    $own = canAccessBranch($bid);
    if (!inv_v2_ready()) { echo json_encode(['success' => true, 'items' => []]); exit; }
    $r = supabase_request('inventory', 'GET', [],
        'select=item_id,master_id,item_name,stock_quantity,min_stock,inventory_items(unit,track_expiry)&archived_at=is.null'
        . '&branch_id=eq.' . $bid . '&order=item_name.asc');
    $items = array_map(fn($x) => [
        'item_id' => (int)$x['item_id'], 'master_id' => (int)$x['master_id'], 'item_name' => $x['item_name'],
        'unit' => $x['inventory_items']['unit'] ?? 'pcs',
        'stock' => $own ? (int)$x['stock_quantity'] : null, 'usable' => $own ? stock_usable((int)$x['stock_quantity']) : null,
    ], $r['data'] ?? []);
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

apiDeny(400, 'Unknown action.');
