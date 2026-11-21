<?php
// =============================================================
//  api/inventory_export.php — Inventory exports (staff)
//   ?what=items      (default)  Inventory Records with the page's filters
//   ?what=movements             Stock Movement history with its filters
//   &format=csv  Excel-compatible CSV (UTF-8 with BOM)
//   &format=pdf  printable PDF (Dompdf, landscape)
//  Branch scope is the same as the pages: admins / dentists only ever
//  export their own branch; the owner may filter by branch or export all.
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/inventory_v2.php';
require_once __DIR__ . '/../controllers/inventory_controller.php';
require_once __DIR__ . '/../controllers/list_filters.php';
require_once __DIR__ . '/../controllers/audit_controller.php';

requireLogin();
requireStaffApi('owner', 'admin', 'dentist');

$what   = ($_GET['what'] ?? '') === 'movements' ? 'movements' : 'items';
$format = ($_GET['format'] ?? '') === 'pdf' ? 'pdf' : 'csv';
$v2     = inv_v2_ready();
$branch = scopedBranch(isset($_GET['branch']) ? (int)$_GET['branch'] : 0);
$showCost = $v2 && !isDentist();
const EXPORT_MAX = 5000;

$bname  = $branch > 0 ? (inv_branch_names()[$branch] ?? 'Branch') : 'All Branches';
$header = []; $rows = []; $title = '';

if ($what === 'items') {
    $title = 'Inventory Records';
    $q = ['select=item_id,item_code,item_name,category,stock_quantity,min_stock,status,updated_at,archived_at,branches(branch_name)'
            . ($v2 ? ',reorder_qty,inventory_items(unit,unit_cost,track_expiry,suppliers(supplier_name))' : ''),
          'limit=' . EXPORT_MAX];
    $q[] = ($_GET['view'] ?? '') === 'archived' ? 'archived_at=not.is.null' : 'archived_at=is.null';
    if ($branch !== 0) $q[] = 'branch_id=eq.' . $branch;
    if (!empty($_GET['category'])) $q[] = 'category=eq.' . rawurlencode((string)$_GET['category']);
    if (in_array($_GET['status'] ?? '', ['in_stock', 'low_stock', 'out_of_stock'], true)) $q[] = 'status=eq.' . $_GET['status'];
    if (in_array($_GET['expiry'] ?? '', ['soon', 'expired'], true) && $v2) {
        $ids = inv_items_with_expiry($_GET['expiry'], $branch);
        $q[] = 'item_id=in.(' . ($ids ? implode(',', $ids) : '-1') . ')';
    }
    $q = array_merge($q, listSearchFilter(listSearch(), ['item_name', 'item_code']));
    $q[] = 'order=item_name.asc,branch_id.asc';
    $res = supabase_request('inventory', 'GET', [], implode('&', $q));
    $items = is_array($res['data']) ? $res['data'] : [];
    $exp = inv_expiry_by_item(array_column($items, 'item_id'));

    $header = ['Item Code', 'Item Name', 'Category', 'Branch', 'Stock', 'Unit', 'Min Stock'];
    if ($v2) array_push($header, 'Reorder Qty', 'Supplier', 'Next Expiry', 'Expired Units');
    if ($showCost) array_push($header, 'Unit Cost', 'Stock Value');
    array_push($header, 'Status', 'Last Updated');
    $totalValue = 0.0;
    foreach ($items as $it) {
        $m = $it['inventory_items'] ?? [];
        $r = [$it['item_code'], $it['item_name'], $it['category'] ?? '', $it['branches']['branch_name'] ?? '', (int)$it['stock_quantity'], inv_unit_label($m['unit'] ?? '', (int)$it['stock_quantity']), (int)$it['min_stock']];
        if ($v2) array_push($r, (int)($it['reorder_qty'] ?? 0), $m['suppliers']['supplier_name'] ?? '',
                            $exp[(int)$it['item_id']]['next'] ?? '', $exp[(int)$it['item_id']]['expired'] ?? 0);
        if ($showCost) {
            $val = isset($m['unit_cost']) ? round((float)$m['unit_cost'] * (int)$it['stock_quantity'], 2) : null;
            if ($val !== null) $totalValue += $val;
            array_push($r, isset($m['unit_cost']) ? number_format((float)$m['unit_cost'], 2, '.', '') : '', $val === null ? '' : number_format($val, 2, '.', ''));
        }
        array_push($r, !empty($it['archived_at']) ? 'Archived' : invStatusLabel($it['status']), !empty($it['updated_at']) ? date('Y-m-d H:i', strtotime($it['updated_at'])) : '');
        $rows[] = $r;
    }
    $foot = $showCost ? 'Total stock value (items with a unit cost): ' . inv_peso($totalValue) : '';
} else {
    $title = 'Stock Movement History';
    $q = ['select=movement_date,created_at,quantity_change,reason,performed_by'
            . ($v2 ? ',movement_type,stock_before,stock_after,reference_no,batch_detail' : '')
            . ',inventory(item_code,item_name),branches(branch_name)',
          'order=created_at.desc,movement_id.desc', 'limit=' . EXPORT_MAX];
    if ($branch !== 0) $q[] = 'branch_id=eq.' . $branch;
    if (!empty($_GET['item'])) $q[] = 'item_id=eq.' . (int)$_GET['item'];
    if ($v2 && isset(INV_TYPES[$_GET['type'] ?? ''])) $q[] = 'movement_type=eq.' . $_GET['type'];
    $from = listDate('from'); $to = listDate('to');
    if ($from) $q[] = 'movement_date=gte.' . $from;
    if ($to)   $q[] = 'movement_date=lte.' . $to;
    $res = supabase_request('inventory_movements', 'GET', [], implode('&', $q));
    $header = ['Date', 'Time', 'Item Code', 'Item Name', 'Branch', 'Type', 'Change'];
    if ($v2) array_push($header, 'Stock Before', 'Stock After', 'Reference', 'Lot(s)');
    array_push($header, 'Reason', 'By');
    foreach ((is_array($res['data']) ? $res['data'] : []) as $m) {
        $c = (int)$m['quantity_change'];
        $r = [$m['movement_date'], !empty($m['created_at']) ? date('H:i', strtotime($m['created_at'])) : '', $m['inventory']['item_code'] ?? '', $m['inventory']['item_name'] ?? '',
              $m['branches']['branch_name'] ?? '', !empty($m['movement_type']) ? inv_type_label($m['movement_type']) : ($c >= 0 ? 'Stock In' : 'Stock Out'), ($c > 0 ? '+' : '') . $c];
        if ($v2) array_push($r, $m['stock_before'] ?? '', $m['stock_after'] ?? '', $m['reference_no'] ?? '',
                            implode(', ', array_filter(array_map(fn($b) => $b['lot_number'] ?? null, (array)($m['batch_detail'] ?? [])))));
        array_push($r, $m['reason'] ?? '', $m['performed_by'] ?? '');
        $rows[] = $r;
    }
    $foot = '';
}

logActivity('inventory', 'EXPORT', '', $title, sprintf('Exported %s (%s, %d rows, %s)', $title, strtoupper($format), count($rows), $bname), $branch > 0 ? $branch : null);
$file = 'essenciasmile-' . ($what === 'items' ? 'inventory' : 'stock-movements') . '-' . date('Ymd-His');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM: Excel opens UTF-8 (₱, ñ) correctly
    // A cell starting with = + - @ is prefixed so Excel never runs it as a formula
    $safe = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !preg_match('/^[+-]\d+$/', $v) ? "'" . $v : $v;
    fputcsv($out, [$title . ' — ' . $bname . ' — exported ' . date('M j, Y g:i A')]);
    fputcsv($out, $header);
    foreach ($rows as $r) fputcsv($out, array_map($safe, $r));
    if ($foot) { fputcsv($out, []); fputcsv($out, [$foot]); }
    fclose($out);
    exit;
}

// ── PDF ──────────────────────────────────────────────────────
$autoload = null;
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../vendor/autoload.php'] as $p) if (is_file($p)) { $autoload = $p; break; }
if (!$autoload) { http_response_code(500); echo 'PDF library (Dompdf) not found.'; exit; }
require_once $autoload;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$html = '<html><head><meta charset="utf-8"><style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #1e293b; }
    h1 { font-size: 15px; margin: 0 0 2px; color: #185fa5; } .sub { color: #64748b; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; } th { background: #f1f5f9; text-align: left; padding: 4px; border-bottom: 1px solid #cbd5e1; font-size: 8px; }
    td { padding: 3.5px 4px; border-bottom: 1px solid #e2e8f0; vertical-align: top; } tr:nth-child(even) td { background: #fafbfc; }
    .foot { margin-top: 8px; font-weight: bold; }
</style></head><body>'
    . '<h1>EssenciaSmile — ' . $h($title) . '</h1>'
    . '<div class="sub">' . $h($bname) . ' · ' . count($rows) . ' row(s) · exported ' . $h(date('M j, Y g:i A')) . ' by ' . $h($_SESSION['full_name'] ?? '') . '</div>'
    . '<table><thead><tr>' . implode('', array_map(fn($c) => '<th>' . $h($c) . '</th>', $header)) . '</tr></thead><tbody>'
    . ($rows ? implode('', array_map(fn($r) => '<tr>' . implode('', array_map(fn($c) => '<td>' . $h($c) . '</td>', $r)) . '</tr>', $rows))
             : '<tr><td colspan="' . count($header) . '">No records.</td></tr>')
    . '</tbody></table>' . ($foot ? '<div class="foot">' . $h($foot) . '</div>' : '') . '</body></html>';

$opt = new \Dompdf\Options();
$opt->set('isRemoteEnabled', false);
$opt->set('defaultFont', 'DejaVu Sans');
$pdf = new \Dompdf\Dompdf($opt);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('A4', 'landscape');
$pdf->render();
$pdf->stream($file . '.pdf', ['Attachment' => false]);
