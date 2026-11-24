<?php
// =============================================================
//  inventory-records.php — Inventory Records
//  One row per item per branch (the branch's balance). Shared item
//  details (unit, cost, supplier, expiry tracking) live in the item
//  master (inventory_items) after the 2026-11-18 migration.
//
//  Summary tiles double as quick filters (Low / Out / Expiring / Expired).
//  Details modal (assets/js/inventory.js → api/inventory_data.php):
//  overview, batches with expiry, recent movement history.
//  Export: api/inventory_export.php (Excel CSV / PDF) with the same filters.
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying
require_once __DIR__ . '/controllers/inventory_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/list_filters.php';

$v2             = inv_v2_ready();
$filterBranch   = isset($_GET['branch'])   ? (int)$_GET['branch']   : 0;
$filterCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
$filterStatus   = isset($_GET['status'])   ? trim($_GET['status'])   : '';
if (!in_array($filterStatus, ['', 'in_stock', 'low_stock', 'out_of_stock'], true)) $filterStatus = '';
$filterExpiry   = in_array($_GET['expiry'] ?? '', ['soon', 'expired'], true) && $v2 ? $_GET['expiry'] : '';
$viewArchived   = ($_GET['view'] ?? '') === 'archived';
$filterView     = $viewArchived ? 'archived' : '';
$page           = max(1, (int)($_GET['page'] ?? 1));
$qBranch        = scopedBranch($filterBranch);   // owner: as filtered; admin / dentist: own branch only
$canManage      = !isDentist();

$sortOptions  = [
    'name_asc'    => ['Name (A–Z)',              'item_name.asc,item_id.asc'],
    'name_desc'   => ['Name (Z–A)',              'item_name.desc,item_id.desc'],
    'code_asc'    => ['Code (A–Z)',              'item_code.asc,item_id.asc'],
    'code_desc'   => ['Code (Z–A)',              'item_code.desc,item_id.desc'],
    'category_asc'  => ['Category (A–Z)',        'category.asc.nullslast,item_name.asc'],
    'category_desc' => ['Category (Z–A)',        'category.desc.nullslast,item_name.asc'],
    'branch_asc'  => ['Branch (A–Z)',            'branches(branch_name).asc.nullslast,item_name.asc'],
    'branch_desc' => ['Branch (Z–A)',            'branches(branch_name).desc.nullslast,item_name.asc'],
    'qty_desc'    => ['Stock (High to Low)',     'stock_quantity.desc,item_name.asc'],
    'qty_asc'     => ['Stock (Low to High)',     'stock_quantity.asc,item_name.asc'],
    'min_desc'    => ['Min Stock (High to Low)', 'min_stock.desc.nullslast,item_name.asc'],
    'min_asc'     => ['Min Stock (Low to High)', 'min_stock.asc.nullslast,item_name.asc'],
    'status_asc'  => ['Status (A–Z)',            'status.asc,item_name.asc'],
    'status_desc' => ['Status (Z–A)',            'status.desc,item_name.asc'],
    'upd_desc'    => ['Last Updated (Newest)',   'updated_at.desc.nullslast,item_name.asc'],
    'upd_asc'     => ['Last Updated (Oldest)',   'updated_at.asc.nullslast,item_name.asc'],
];
[$sortKey, $sortOrder] = listSort($sortOptions, 'name_asc');
$search    = listSearch();
$filtersOn = listActiveCount([$filterBranch, $filterCategory, $filterStatus, $filterExpiry, $filterView, $search, $sortKey !== 'name_asc']);

$_invExtra = listSearchFilter($search, ['item_name', 'item_code']);
// Expiry data (tiles, Expiry column, Expiry filter) = ONE batch read, normally in the
// parallel wave below. Only the Expiry filter needs it first (the list depends on it).
$_batchSpec = $v2 ? inv_batch_scope_spec($qBranch) : null;
$_exp = ['soon' => [], 'expired' => [], 'by_item' => []];
if ($_batchSpec && $filterExpiry) {
    $_exp = inv_expiry_digest(admin_get($_batchSpec['table'], $_batchSpec['query'])['data'] ?? []);
    $ids  = $_exp[$filterExpiry];
    $_invExtra[] = 'item_id=in.(' . ($ids ? implode(',', $ids) : '-1') . ')';
}

// Summary: every active row in scope (small table) — status counts + stock value
$_bf = $qBranch !== 0 ? '&branch_id=eq.' . $qBranch : '';
$_sumQ = 'select=item_id,stock_quantity,min_stock' . ($v2 ? ',inventory_items(unit_cost)' : '') . '&archived_at=is.null' . $_bf . '&limit=2000';

$_pre = admin_prefetch([
    'list'       => admin_q('inventory', implode('&', inventoryRecordsQuery($page, $qBranch, $filterCategory, $filterStatus, $sortOrder, $_invExtra, $viewArchived)), ['Prefer: count=exact']),
    'categories' => admin_q('inventory', INV_CATEGORIES_QUERY),
    'summary'    => admin_q('inventory', $_sumQ),
] + ($_batchSpec && !$filterExpiry ? ['batches' => $_batchSpec] : []));
if (isset($_pre['batches'])) $_exp = inv_expiry_digest(is_array($_pre['batches']['data'] ?? null) ? $_pre['batches']['data'] : []);
$branches   = getAllBranches();
$categories = getInventoryCategories();
$data         = getInventoryRecords($page, $qBranch, $filterCategory, $filterStatus, $sortOrder, $_invExtra, $viewArchived);
$items        = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];

$sum = ['total' => 0, 'low' => 0, 'out' => 0, 'value' => 0.0, 'priced' => 0];
$_sumRows = admin_get('inventory', $_sumQ)['data'] ?? [];
foreach (is_array($_sumRows) ? $_sumRows : [] as $r) {
    $q = (int)$r['stock_quantity']; $min = (int)$r['min_stock'];
    $sum['total']++;
    if ($q <= 0) $sum['out']++; elseif ($q <= $min) $sum['low']++;
    if (isset($r['inventory_items']['unit_cost'])) { $sum['value'] += $q * (float)$r['inventory_items']['unit_cost']; $sum['priced']++; }
}
$expSoonIds = $_exp['soon'];
$expIds     = $_exp['expired'];
$expiry     = $_exp['by_item'];

$filterParams = ['branch' => $filterBranch, 'category' => $filterCategory, 'status' => $filterStatus, 'expiry' => $filterExpiry,
                 'view' => $filterView, 'q' => $search, 'sort' => $sortKey === 'name_asc' ? '' : $sortKey];
$baseUrl  = listUrl('inventory-records.php', $filterParams);
$sortBase = listUrl('inventory-records.php', array_diff_key($filterParams, ['sort' => 1]));
$tileUrl  = fn(array $p) => listUrl('inventory-records.php', ['branch' => $filterBranch] + $p);
$exportQs = http_build_query(array_filter(['branch' => $filterBranch, 'category' => $filterCategory, 'status' => $filterStatus,
                 'expiry' => $filterExpiry, 'view' => $filterView, 'q' => $search, 'sort' => $sortKey === 'name_asc' ? '' : $sortKey]));
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Inventory</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
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
                        <li class="breadcrumb-item">Records</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <div class="dropdown">
                                <button class="btn btn-light-brand btn-sm hdr-btn" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="feather-download me-1"></i> Export
                                </button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <a class="dropdown-item" href="api/inventory_export.php?format=csv&<?= $e($exportQs) ?>"><i class="feather-file-text me-2"></i>Excel (CSV)</a>
                                    <a class="dropdown-item" href="api/inventory_export.php?format=pdf&<?= $e($exportQs) ?>" target="_blank" rel="noopener"><i class="feather-file me-2"></i>PDF</a>
                                </div>
                            </div>
                            <?php if ($canManage): ?>
                            <button class="btn btn-primary btn-sm hdr-btn" onclick="openAddItemModal()">
                                <i class="feather-plus me-1"></i> Add Item
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-content">
                <?php if (!$v2): ?><?= inv_v2_notice() ?><?php endif; ?>
                <div class="row"><div class="col-lg-12">
                    <div class="card stretch stretch-full" data-es-region="list">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                Inventory Records <span class="badge bg-soft-primary text-primary ms-2"><?= number_format($totalRecords) ?> <?= $filtersOn ? 'found' : 'total' ?></span>
                                <span class="rec-card-sub">Stock on hand per branch. Click a tile to filter; click an item for its batches and history.</span>
                            </h5>
                            <div class="card-header-action"><div class="card-header-btn">
                                <div data-bs-toggle="tooltip" title="Refresh"><a href="inventory-records.php" class="avatar-text avatar-xs bg-warning"></a></div>
                                <div data-bs-toggle="tooltip" title="Maximize/Minimize"><a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a></div>
                            </div></div>
                        </div>

                        <nav class="rec-summary inv-summary" aria-label="Inventory summary">
                            <?php
                            $tiles = [
                                ['All Items',    $sum['total'],     'feather-package',        '',           $tileUrl([]),                        !$filterStatus && !$filterExpiry, 'Every active item in this view'],
                                ['Low Stock',    $sum['low'],       'feather-alert-triangle', 'tone-amber', $tileUrl(['status' => 'low_stock']),  $filterStatus === 'low_stock', 'At or below the minimum stock'],
                                ['Out of Stock', $sum['out'],       'feather-x-octagon',      'tone-red',   $tileUrl(['status' => 'out_of_stock']), $filterStatus === 'out_of_stock', 'No units left'],
                            ];
                            if ($v2) {
                                $tiles[] = ['Expiring Soon', count($expSoonIds), 'feather-clock',  'tone-amber', $tileUrl(['expiry' => 'soon']),    $filterExpiry === 'soon',    'Items with a batch expiring within ' . INV_NEAR_EXPIRY_DAYS . ' days'];
                                $tiles[] = ['Expired Stock', count($expIds),     'feather-slash',  'tone-red',   $tileUrl(['expiry' => 'expired']), $filterExpiry === 'expired', 'Items holding expired batches — write them off'];
                            }
                            foreach ($tiles as [$label, $n, $icon, $tone, $url, $cur, $tip]): ?>
                            <a href="<?= $e($url) ?>" class="rec-stat <?= $tone ?><?= $cur ? ' is-current' : '' ?>" title="<?= $e($tip) ?>"<?= $cur ? ' aria-current="true"' : '' ?>>
                                <span class="rec-stat-icon"><i class="<?= $icon ?>"></i></span>
                                <span class="rec-stat-body">
                                    <span class="rec-stat-value d-block"><?= number_format($n) ?></span>
                                    <span class="rec-stat-label d-block"><?= $label ?></span>
                                </span>
                            </a>
                            <?php endforeach; ?>
                            <?php if ($v2 && !isDentist()): ?>
                            <div class="rec-stat tone-green inv-stat-money" title="Stock × unit cost, for items with a cost (<?= $sum['priced'] ?> of <?= $sum['total'] ?>)">
                                <span class="rec-stat-icon"><i class="feather-dollar-sign"></i></span>
                                <span class="rec-stat-body">
                                    <span class="rec-stat-value d-block"><?= $sum['priced'] ? $e(inv_peso($sum['value'])) : '—' ?></span>
                                    <span class="rec-stat-label d-block">Stock Value</span>
                                </span>
                            </div>
                            <?php endif; ?>
                        </nav>

                        <div class="list-toolbar">
                            <?= listSearchControl($search, 'Item name or code') ?>
                            <div class="list-field"><label class="list-label" for="filterCategory">Category</label>
                            <select id="filterCategory" class="js-custom-select" data-cs-filter data-cs-search onchange="applyFilters()">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $e($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= $e($cat) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                            <div class="list-field"><label class="list-label" for="filterStatus">Stock Status</label>
                            <select id="filterStatus" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="">All Statuses</option>
                                <option value="in_stock"     <?= $filterStatus === 'in_stock'     ? 'selected' : '' ?>>In Stock</option>
                                <option value="low_stock"    <?= $filterStatus === 'low_stock'    ? 'selected' : '' ?>>Low Stock</option>
                                <option value="out_of_stock" <?= $filterStatus === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                            </select></div>
                            <?php if ($v2): ?>
                            <div class="list-field"><label class="list-label" for="filterExpiry">Expiry</label>
                            <select id="filterExpiry" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="">Any</option>
                                <option value="soon"    <?= $filterExpiry === 'soon'    ? 'selected' : '' ?>>Expiring within <?= INV_NEAR_EXPIRY_DAYS ?> days</option>
                                <option value="expired" <?= $filterExpiry === 'expired' ? 'selected' : '' ?>>Has expired stock</option>
                            </select></div>
                            <?php endif; ?>
                            <div class="list-field"><label class="list-label" for="filterView">Show</label>
                            <select id="filterView" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="">Active Items</option>
                                <option value="archived" <?= $viewArchived ? 'selected' : '' ?>>Archived Items</option>
                            </select></div>
                            <?php if (isOwner()): ?>
                            <div class="list-field"><label class="list-label" for="filterBranch">Branch</label>
                            <select id="filterBranch" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>" <?= (int)$b['branch_id'] === $filterBranch ? 'selected' : '' ?>><?= $e($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                            <?php endif; ?>
                            <?= listResetControl('inventory-records.php', $filtersOn) ?>
                        </div>

                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive">
                                <table class="table table-hover rec-table inv-table mb-0">
                                    <thead><tr>
                                        <?= listTh('Item', 'name_asc', 'name_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Category', 'category_asc', 'category_desc', $sortKey, $sortBase) ?>
                                        <?php if (isOwner()): ?><?= listTh('Branch', 'branch_asc', 'branch_desc', $sortKey, $sortBase) ?><?php endif; ?>
                                        <?= listTh('Stock', 'qty_desc', 'qty_asc', $sortKey, $sortBase, 'class="text-end"') ?>
                                        <?php if ($v2): ?><th class="text-end" title="How much to order when restocking">Reorder Qty</th><th>Expiry</th><?php endif; ?>
                                        <?= listTh('Status', 'status_asc', 'status_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Last Updated', 'upd_desc', 'upd_asc', $sortKey, $sortBase) ?>
                                        <th class="rec-col-action"><span class="visually-hidden">Actions</span></th>
                                    </tr></thead>
                                    <tbody>
                                    <?php $cols = 6 + (isOwner() ? 1 : 0) + ($v2 ? 2 : 0); ?>
                                    <?php if (empty($items)): ?>
                                        <?= inv_empty_row($cols, $filtersOn > 0, $viewArchived ? 'No archived items' : 'No inventory items yet',
                                                $viewArchived ? 'Items you delete that have stock history are kept here.' : 'Add the supplies this clinic keeps in stock.',
                                                'feather-package', 'inventory-records.php') ?>
                                    <?php else: ?>
                                        <?php foreach ($items as $item):
                                            $id       = (int)$item['item_id'];
                                            $stock    = (int)($item['stock_quantity'] ?? 0);
                                            $minStock = (int)($item['min_stock'] ?? 0);
                                            $unit     = $item['inventory_items']['unit'] ?? '';
                                            $stockClass = $stock <= 0 ? 'text-danger fw-bold' : ($stock <= $minStock ? 'text-warning fw-semibold' : 'fw-semibold');
                                            $ex       = $expiry[$id] ?? null;
                                            $archived = !empty($item['archived_at']);
                                            $nameJs   = $e(json_encode($item['item_name'] ?? ''));
                                        ?>
                                        <tr class="rec-row">
                                            <td>
                                                <a href="javascript:void(0);" class="rec-entity-name" onclick="viewItem(<?= $id ?>)"><?= $e($item['item_name'] ?? '—') ?></a>
                                                <span class="rec-entity-sub"><?= $e($item['item_code'] ?? '') ?><?= !empty($item['inventory_items']['track_expiry']) ? ' · <i class="feather-clock" title="Expiry tracked"></i>' : '' ?></span>
                                            </td>
                                            <td><?= !empty($item['category']) ? $e($item['category']) : '<span class="rec-muted">—</span>' ?></td>
                                            <?php if (isOwner()): ?><td><?= $e($item['branches']['branch_name'] ?? '—') ?></td><?php endif; ?>
                                            <td class="text-end rec-nowrap inv-num"><span class="<?= $stockClass ?>"><?= number_format($stock) ?></span> <span class="rec-muted small"><?= $e(inv_unit_label($unit, $stock)) ?></span></td>
                                            <?php if ($v2): ?>
                                            <td class="text-end inv-num"><?= (int)($item['reorder_qty'] ?? 0) ?: '<span class="rec-muted">—</span>' ?></td>
                                            <td class="rec-nowrap">
                                                <?php if ($ex && $ex['expired']): ?>
                                                    <span class="badge bg-soft-danger text-danger" title="<?= $ex['expired'] ?> unit(s) expired"><?= $ex['expired'] ?> expired</span>
                                                <?php elseif ($ex && $ex['soon']): ?>
                                                    <span class="badge bg-soft-warning text-warning" title="Earliest expiry <?= $e(date('M j, Y', strtotime($ex['next']))) ?>">Exp. <?= $e(date('M j', strtotime($ex['next']))) ?></span>
                                                <?php elseif ($ex && $ex['next']): ?>
                                                    <span class="rec-muted small"><?= $e(date('M j, Y', strtotime($ex['next']))) ?></span>
                                                <?php else: ?><span class="rec-muted">—</span><?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                            <td>
                                                <?= inv_stock_pill($item['status'] ?? '', $item['archived_at'] ?? null) ?>
                                            </td>
                                            <td class="rec-nowrap"><?php if (!empty($item['updated_at'])): ?><?= $e(date('M j, Y', strtotime($item['updated_at']))) ?><span class="rec-entity-sub"><?= $e(date('g:i A', strtotime($item['updated_at']))) ?></span><?php else: ?><span class="rec-muted">—</span><?php endif; ?></td>
                                            <td class="rec-col-action">
                                                <div class="dropdown">
                                                    <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-label="Actions"><i class="feather-more-vertical"></i></a>
                                                    <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                        <a href="javascript:void(0);" class="dropdown-item" onclick="viewItem(<?= $id ?>)"><i class="feather-eye"></i> View Details</a>
                                                        <a href="inventory-stock-movement.php?item=<?= $id ?>" class="dropdown-item"><i class="feather-refresh-cw"></i> Movement History</a>
                                                        <?php if ($canManage && $archived): ?>
                                                        <div class="dropdown-divider"></div>
                                                        <a href="javascript:void(0);" class="dropdown-item text-success" onclick="restoreItem(<?= $id ?>, <?= $nameJs ?>)"><i class="feather-rotate-ccw"></i> Restore</a>
                                                        <?php elseif ($canManage): ?>
                                                        <a href="javascript:void(0);" class="dropdown-item" onclick="openMovementModal(<?= $id ?>)"><i class="feather-plus-circle"></i> Log Movement</a>
                                                        <div class="dropdown-divider"></div>
                                                        <a href="javascript:void(0);" class="dropdown-item" onclick="openEditItemModal(<?= $id ?>)"><i class="feather-edit-2"></i> Edit</a>
                                                        <a href="javascript:void(0);" class="dropdown-item text-danger" onclick="deleteItem(<?= $id ?>, <?= $nameJs ?>)"><i class="feather-trash-2"></i> Delete</a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <p class="rec-foot-count mb-0">
                                <?php if ($totalRecords): ?>Showing <strong><?= $from ?>–<?= $to ?></strong> of <strong><?= number_format($totalRecords) ?></strong> item<?= $totalRecords === 1 ? '' : 's' ?><?php else: ?>No items to show<?php endif; ?>
                                <?php if ($filtersOn): ?>&nbsp;·&nbsp;<a href="inventory-records.php" class="text-danger"><i class="feather-x-circle me-1"></i>Clear filters</a><?php endif; ?>
                            </p>
                            <?= listPager($baseUrl, $page, $totalPages) ?>
                        </div>
                    </div>
                </div></div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/partials/inventory-modals.php'; ?>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/admin.js?v=7"></script>
    <script src="assets/js/es-swal.js?v=3"></script>
    <script src="assets/js/es-ajax.js?v=7"></script>
    <script src="assets/js/custom-select.js?v=3"></script>
    <script src="assets/js/es-records.js?v=3"></script>
    <script src="assets/js/inventory-ui.js?v=3"></script>
    <script>
    window.INV = {
        v2: <?= $v2 ? 'true' : 'false' ?>,
        canManage: <?= $canManage ? 'true' : 'false' ?>,
        isOwner: <?= isOwner() ? 'true' : 'false' ?>,
        branches: <?= json_encode(array_values(array_map(fn($b) => ['id' => (int)$b['branch_id'], 'name' => $b['branch_name']],
                        array_filter($branches, fn($b) => canAccessBranch($b['branch_id'])))), JSON_HEX_TAG) ?>,
        categories: <?= json_encode(array_values($categories), JSON_HEX_TAG) ?>,
        nearDays: <?= INV_NEAR_EXPIRY_DAYS ?>
    };
    function applyFilters() {
        window._applyFilters('inventory-records.php', {
            branch:   'filterBranch',
            category: 'filterCategory',
            status:   'filterStatus',
            expiry:   'filterExpiry',
            view:     'filterView'
        });
    }
    </script>
    <script src="assets/js/inventory.js?v=8"></script>
</body>
</html>
