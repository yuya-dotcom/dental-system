<?php
// =============================================================
//  inventory-stock-movement.php — complete stock movement history
//  Every stock change (treatments, Stock In/Out, adjustments, write-offs,
//  transfers, purchase deliveries) with type, stock before → after,
//  reason, reference and the user responsible. Read-only by design: the
//  history is the audit trail and is never edited.
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying
require_once __DIR__ . '/controllers/inventory_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/list_filters.php';

$v2           = inv_v2_ready();
$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : 0;
$filterItem   = isset($_GET['item'])   ? (int)$_GET['item']   : 0;
$filterType   = $v2 && isset(INV_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$fromDate     = listDate('from');
$toDate       = listDate('to');
if ($fromDate && $toDate && $fromDate > $toDate) [$fromDate, $toDate] = [$toDate, $fromDate];
$page         = max(1, (int)($_GET['page'] ?? 1));
$qBranch      = scopedBranch($filterBranch);   // owner: as filtered; admin / dentist: own branch only

// Item filter: its label is read in the same parallel wave (used when it has no movements on this page)
$_itemQ = 'select=item_code,item_name,branch_id&item_id=eq.' . $filterItem;
admin_prefetch(['list' => admin_q('inventory_movements', implode('&', stockMovementsQuery($page, $qBranch, $filterItem ?: '', $filterType, $fromDate, $toDate)), ['Prefer: count=exact'])]
    + ($filterItem ? ['item' => admin_q('inventory', $_itemQ)] : []));
$branches     = getAllBranches();
$data         = getStockMovements($page, $qBranch, $filterItem ?: '', $filterType, $fromDate, $toDate);
$movements    = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];

$itemLabel = '';
if ($filterItem) {
    $first = $movements[0]['inventory'] ?? null;
    if (!$first) {
        $ir = admin_get('inventory', $_itemQ);
        $first = (isset($ir['data'][0]) && canAccessBranch($ir['data'][0]['branch_id'])) ? $ir['data'][0] : [];
    }
    $itemLabel = trim(($first['item_code'] ?? '') . ' — ' . ($first['item_name'] ?? ''), ' —');
}
$params    = ['branch' => $filterBranch, 'item' => $filterItem, 'type' => $filterType, 'from' => $fromDate, 'to' => $toDate];
$baseUrl   = listUrl('inventory-stock-movement.php', $params);
$filtersOn = listActiveCount(array_values($params));
$exportQs  = http_build_query(array_filter($params));
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Stock Movement</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
    <link rel="stylesheet" href="assets/css/es-admin.css?v=8">
    <link rel="stylesheet" href="assets/css/es-records.css?v=7">
    <link rel="stylesheet" href="assets/css/es-inventory.css?v=3">
</head>
<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>
    <main class="nxl-container">
        <div class="nxl-content">
            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">Inventory</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="inventory-records.php">Inventory</a></li>
                        <li class="breadcrumb-item">Stock Movement</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <div class="dropdown">
                                <button class="btn btn-light-brand btn-sm hdr-btn" data-bs-toggle="dropdown" aria-expanded="false"><i class="feather-download me-1"></i> Export</button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <a class="dropdown-item" href="api/inventory_export.php?what=movements&format=csv&<?= $e($exportQs) ?>"><i class="feather-file-text me-2"></i>Excel (CSV)</a>
                                    <a class="dropdown-item" href="api/inventory_export.php?what=movements&format=pdf&<?= $e($exportQs) ?>" target="_blank" rel="noopener"><i class="feather-file me-2"></i>PDF</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="main-content">
                <div class="row"><div class="col-lg-12">
                    <div class="card stretch stretch-full" data-es-region="list">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                Stock Movement<?php if ($itemLabel): ?> &mdash; <span class="text-primary"><?= $e($itemLabel) ?></span><?php endif; ?>
                                <span class="badge bg-soft-primary text-primary ms-2"><?= number_format($totalRecords) ?> <?= $filtersOn ? 'found' : 'total' ?></span>
                                <span class="rec-card-sub">Every stock change with its reason and who made it. This history is permanent and cannot be edited.</span>
                            </h5>
                            <div class="card-header-action"><div class="card-header-btn">
                                <div data-bs-toggle="tooltip" title="Refresh"><a href="inventory-stock-movement.php" class="avatar-text avatar-xs bg-warning"></a></div>
                                <div data-bs-toggle="tooltip" title="Maximize/Minimize"><a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a></div>
                            </div></div>
                        </div>
                        <div class="list-toolbar">
                            <input type="hidden" id="filterItem" value="<?= $filterItem ?: '' ?>">
                            <?php if ($v2): ?>
                            <?= listSelectControl('Type', 'type', ['' => 'All Types'] + array_map(fn($t) => $t[0], INV_TYPES), $filterType, 'filterType') ?>
                            <?php endif; ?>
                            <?= listDateRangeControl($fromDate, $toDate) ?>
                            <?php if (isOwner()): ?>
                            <div class="list-field"><label class="list-label" for="filterBranch">Branch</label>
                            <select id="filterBranch" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>" <?= (int)$b['branch_id'] === $filterBranch ? 'selected' : '' ?>><?= $e($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                            <?php endif; ?>
                            <?php if ($filterItem): ?>
                            <div class="list-field"><span class="list-label">Item</span>
                                <a href="<?= $e(listUrl('inventory-stock-movement.php', array_diff_key($params, ['item' => 1]))) ?>" class="list-reset" title="Show movements of all items">
                                    <i class="feather-x" aria-hidden="true"></i><?= $e($itemLabel ?: ('Item #' . $filterItem)) ?>
                                </a></div>
                            <?php endif; ?>
                            <?= listResetControl('inventory-stock-movement.php', $filtersOn) ?>
                        </div>
                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive">
                                <table class="table table-hover rec-table inv-table mb-0">
                                    <thead><tr>
                                        <th>Date &amp; Time</th><th>Item</th><?php if (isOwner()): ?><th>Branch</th><?php endif; ?>
                                        <th>Type</th><th class="text-end">Change</th><?php if ($v2): ?><th class="text-end" title="Stock before and after this change">Stock</th><?php endif; ?>
                                        <th>Reason / Reference</th><th>By</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php $cols = 6 + (isOwner() ? 1 : 0) + ($v2 ? 1 : 0); ?>
                                    <?php if (empty($movements)): ?>
                                        <?= inv_empty_row($cols, $filtersOn > 0, 'No stock movements yet', 'Stock In, usage in treatments, write-offs, transfers and deliveries appear here.', 'feather-refresh-cw', 'inventory-stock-movement.php') ?>
                                    <?php else: ?>
                                        <?php foreach ($movements as $mov):
                                            $change = (int)($mov['quantity_change'] ?? 0);
                                            $type   = $mov['movement_type'] ?? null;
                                            $inv    = $mov['inventory'] ?? [];
                                            $lots   = array_filter(array_map(fn($b) => $b['lot_number'] ?? null, (array)($mov['batch_detail'] ?? [])));
                                        ?>
                                        <tr class="rec-row">
                                            <td class="rec-nowrap">
                                                <?= formatMovDate($mov['movement_date'] ?? null) ?>
                                                <?php if (!empty($mov['created_at'])): ?><span class="rec-entity-sub"><?= $e(date('g:i A', strtotime($mov['created_at']))) ?></span><?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($mov['item_id']) && !$filterItem): ?>
                                                <a href="<?= $e(listUrl('inventory-stock-movement.php', ['item' => (int)$mov['item_id']] + $params)) ?>" class="rec-entity-name" title="Show this item's history only"><?= $e($inv['item_name'] ?? '—') ?></a>
                                                <?php else: ?>
                                                <span class="rec-entity-name inv-plain"><?= $e($inv['item_name'] ?? '—') ?></span>
                                                <?php endif; ?>
                                                <span class="rec-entity-sub"><?= $e($inv['item_code'] ?? '') ?></span>
                                            </td>
                                            <?php if (isOwner()): ?><td><?= $e($mov['branches']['branch_name'] ?? '—') ?></td><?php endif; ?>
                                            <td>
                                                <?php if ($type): ?>
                                                <span class="badge <?= inv_type_badge($type) ?>"><?= $e(inv_type_label($type)) ?></span>
                                                <?php else: ?>
                                                <span class="badge <?= movementBadge($change) ?>"><?= $change >= 0 ? 'Stock In' : 'Stock Out' ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-semibold inv-num <?= $change >= 0 ? 'text-success' : 'text-danger' ?>"><?= movementLabel($change) ?></td>
                                            <?php if ($v2): ?>
                                            <td class="text-end rec-nowrap inv-num"><?= isset($mov['stock_before']) ? (int)$mov['stock_before'] . ' → ' . (int)$mov['stock_after'] : '<span class="rec-muted" title="Recorded before stock levels were saved with each movement">—</span>' ?></td>
                                            <?php endif; ?>
                                            <td class="inv-col-reason">
                                                <span class="inv-clamp"><?= $e($mov['reason'] ?? '—') ?></span>
                                                <?php if (!empty($mov['reference_no']) || $lots): ?>
                                                <span class="rec-entity-sub"><?= !empty($mov['reference_no']) ? 'Ref ' . $e($mov['reference_no']) : '' ?><?= $lots ? (!empty($mov['reference_no']) ? ' · ' : '') . 'Lot ' . $e(implode(', ', $lots)) : '' ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $e($mov['performed_by'] ?? '—') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <p class="rec-foot-count mb-0">
                                <?php if ($totalRecords): ?>Showing <strong><?= $from ?>–<?= $to ?></strong> of <strong><?= number_format($totalRecords) ?></strong> movement<?= $totalRecords === 1 ? '' : 's' ?><?php else: ?>No movements to show<?php endif; ?>
                                <?php if ($filtersOn): ?>&nbsp;·&nbsp;<a href="inventory-stock-movement.php" class="text-danger"><i class="feather-x-circle me-1"></i>Clear filters</a><?php endif; ?>
                            </p>
                            <?= listPager($baseUrl, $page, $totalPages) ?>
                        </div>
                    </div>
                </div></div>
            </div>
        </div>
    </main>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <!-- SweetAlert2 v11 (self-hosted, same build as every other admin page): replaces the
         v9 bundled in vendors.min.js, whose result has no isConfirmed (Sign Out never ran) -->
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/js/admin.js?v=7"></script>
    <script src="assets/js/es-swal.js?v=3"></script>
    <script src="assets/js/es-ajax.js?v=7"></script>
    <script src="assets/js/custom-select.js?v=3"></script>
    <script>
    function applyFilters() {
        window._applyFilters('inventory-stock-movement.php', { branch: 'filterBranch', item: 'filterItem', type: 'filterType' });
    }
    </script>
</body>
</html>
