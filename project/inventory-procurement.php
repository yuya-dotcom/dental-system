<?php
// =============================================================
//  inventory-procurement.php — Procurement (part of Inventory)
//  Tabs: Purchase Requests · Purchase Orders · Suppliers
//  A delivery received against a purchase order adds the stock to the
//  branch's inventory (with lot / expiry) — no separate stock entry.
//  Business rules: api/procurement.php. UI: assets/js/inventory-procurement.js
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();
require_once __DIR__ . '/controllers/inventory_v2.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/list_filters.php';

const PC_PER_PAGE = 10;
$v2     = inv_v2_ready();
$tab    = in_array($_GET['tab'] ?? '', ['requests', 'orders', 'suppliers'], true) ? $_GET['tab'] : 'requests';
$status = preg_match('/^[a-z_]{0,20}$/', $_GET['status'] ?? '') ? ($_GET['status'] ?? '') : '';
$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : 0;
$search = listSearch();
$page   = max(1, (int)($_GET['page'] ?? 1));
$scope  = scopedBranch($filterBranch);
$e  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$canEdit = !isDentist();

$PR_ST = ['pending' => 'Pending Approval', 'approved' => 'Approved', 'ordered' => 'Ordered', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
$PO_ST = ['ordered' => 'Ordered', 'partially_received' => 'Partially Received', 'received' => 'Received', 'cancelled' => 'Cancelled'];

$rows = []; $total = 0; $counts = ['pr_pending' => 0, 'pr_approved' => 0, 'po_open' => 0, 'low' => 0];
if ($v2) {
    $bf  = $scope !== 0 ? 'branch_id=eq.' . $scope : '';
    $off = ($page - 1) * PC_PER_PAGE;
    $sq  = str_replace(['*', ',', '(', ')'], '', $search);
    if ($tab === 'requests') {
        $q = ['select=pr_id,pr_no,branch_id,status,reason,needed_by,requested_by_name,requested_at,purchase_request_items(quantity,inventory_items(item_name,unit))',
              'order=requested_at.desc,pr_id.desc', 'limit=' . PC_PER_PAGE, 'offset=' . $off];
        if (isset($PR_ST[$status])) $q[] = 'status=eq.' . $status;
        if ($sq !== '') $q[] = 'pr_no=ilike.*' . rawurlencode($sq) . '*';
        $table = 'purchase_requests';
    } elseif ($tab === 'orders') {
        $q = ['select=po_id,po_no,branch_id,status,order_date,expected_date,created_by_name,suppliers(supplier_name),purchase_order_items(quantity_ordered,quantity_received,unit_cost,inventory_items(item_name))',
              'order=created_at.desc,po_id.desc', 'limit=' . PC_PER_PAGE, 'offset=' . $off];
        if ($status === 'open') $q[] = 'status=in.(ordered,partially_received)';
        elseif (isset($PO_ST[$status])) $q[] = 'status=eq.' . $status;
        if ($sq !== '') $q[] = 'po_no=ilike.*' . rawurlencode($sq) . '*';
        $table = 'purchase_orders';
    } else {
        $q = ['select=supplier_id,supplier_name,contact_person,phone,email,address,notes,is_active', 'order=is_active.desc,supplier_name.asc', 'limit=' . PC_PER_PAGE, 'offset=' . $off];
        if ($status === 'inactive') $q[] = 'is_active=is.false'; elseif ($status === 'active') $q[] = 'is_active=is.true';
        if ($sq !== '') $q[] = 'supplier_name=ilike.*' . rawurlencode($sq) . '*';
        $table = 'suppliers'; $bf = '';
    }
    if ($bf) $q[] = $bf;
    $cf = $scope !== 0 ? '&branch_id=eq.' . $scope : '';
    // One parallel wave: this page's queries + the navbar bell + the branch list
    $res = admin_prefetch([
        'list' => admin_q($table, implode('&', $q), ['Prefer: count=exact']),
        'prc'  => admin_q('purchase_requests', 'select=status&status=in.(pending,approved)' . $cf . '&limit=1000'),
        'poc'  => admin_q('purchase_orders', 'select=status&status=in.(ordered,partially_received)' . $cf . '&limit=1000'),
        'low'  => admin_q('inventory', 'select=item_id&archived_at=is.null&status=in.(low_stock,out_of_stock)' . $cf . '&limit=1', ['Prefer: count=exact']),
    ]);
    $rows  = is_array($res['list']['data'] ?? null) ? $res['list']['data'] : [];
    $total = admin_count($res['list']);
    foreach ((array)($res['prc']['data'] ?? []) as $x) $counts['pr_' . $x['status']]++;
    $counts['po_open'] = count((array)($res['poc']['data'] ?? []));
    $counts['low'] = admin_count($res['low']);
}
if (!$v2) admin_prefetch([]);        // bell + branch list, still in parallel
$branches = getAllBranches();      // from the wave above (remembered for the request)
$bn       = inv_branch_names();
$totalPages = max(1, (int)ceil($total / PC_PER_PAGE));
$params  = ['tab' => $tab === 'requests' ? '' : $tab, 'status' => $status, 'branch' => $filterBranch, 'q' => $search];
$baseUrl = listUrl('inventory-procurement.php', $params);
$tabUrl  = fn($t, $s = '') => listUrl('inventory-procurement.php', ['tab' => $t === 'requests' ? '' : $t, 'status' => $s, 'branch' => $filterBranch]);
$filtersOn = listActiveCount([$status, $filterBranch, $search]);
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Procurement</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
    <link rel="stylesheet" href="assets/css/es-admin.css?v=7">
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
                        <li class="breadcrumb-item">Procurement</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <?php if ($v2 && $canEdit): ?>
                                <?php /* Same buttons on every tab: the tabs reload only the list, never this header */ ?>
                                <button class="btn btn-light-brand btn-sm hdr-btn" onclick="openSupplierModal()"><i class="feather-briefcase me-1"></i> Add Supplier</button>
                                <button class="btn btn-light-brand btn-sm hdr-btn" onclick="openPrModal()"><i class="feather-file-plus me-1"></i> Request Supplies</button>
                                <?php if (isOwner()): ?><button class="btn btn-primary btn-sm hdr-btn" onclick="openPoModal()"><i class="feather-shopping-cart me-1"></i> New Purchase Order</button><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-content">
                <?php if (!$v2): ?><?= inv_v2_notice() ?><?php else: ?>
                <div class="row"><div class="col-lg-12">
                    <div class="card stretch stretch-full" data-es-region="list">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Procurement <span class="badge bg-soft-primary text-primary ms-2"><?= number_format($total) ?> <?= $filtersOn ? 'found' : 'total' ?></span>
                                <span class="rec-card-sub">Request supplies, order them from suppliers, and receive deliveries straight into inventory.</span>
                            </h5>
                        </div>
                        <nav class="rec-summary" aria-label="Procurement summary">
                            <a href="<?= $e($tabUrl('requests', 'pending')) ?>" class="rec-stat tone-amber<?= $tab === 'requests' && $status === 'pending' ? ' is-current' : '' ?>">
                                <span class="rec-stat-icon"><i class="feather-clock"></i></span>
                                <span class="rec-stat-body"><span class="rec-stat-value d-block"><?= $counts['pr_pending'] ?></span><span class="rec-stat-label d-block">Requests to Approve</span></span></a>
                            <a href="<?= $e($tabUrl('requests', 'approved')) ?>" class="rec-stat<?= $tab === 'requests' && $status === 'approved' ? ' is-current' : '' ?>">
                                <span class="rec-stat-icon"><i class="feather-check"></i></span>
                                <span class="rec-stat-body"><span class="rec-stat-value d-block"><?= $counts['pr_approved'] ?></span><span class="rec-stat-label d-block">Approved — To Order</span></span></a>
                            <a href="<?= $e($tabUrl('orders', 'open')) ?>" class="rec-stat<?= $tab === 'orders' && $status === 'open' ? ' is-current' : '' ?>">
                                <span class="rec-stat-icon"><i class="feather-truck"></i></span>
                                <span class="rec-stat-body"><span class="rec-stat-value d-block"><?= $counts['po_open'] ?></span><span class="rec-stat-label d-block">Orders Awaiting Delivery</span></span></a>
                            <a href="inventory-records.php?status=low_stock" class="rec-stat tone-red" title="Open Inventory Records filtered to low stock">
                                <span class="rec-stat-icon"><i class="feather-alert-triangle"></i></span>
                                <span class="rec-stat-body"><span class="rec-stat-value d-block"><?= $counts['low'] ?></span><span class="rec-stat-label d-block">Low / Out of Stock <i class="feather-arrow-up-right"></i></span></span></a>
                        </nav>
                        <ul class="nav nav-tabs inv-tabs" role="tablist">
                            <li class="nav-item"><a class="nav-link<?= $tab === 'requests' ? ' active' : '' ?>" href="<?= $e($tabUrl('requests')) ?>"><i class="feather-file-plus"></i> Purchase Requests<?= $counts['pr_pending'] ? ' <span class="inv-tab-count is-warn" title="Waiting for approval">' . $counts['pr_pending'] . '</span>' : '' ?></a></li>
                            <li class="nav-item"><a class="nav-link<?= $tab === 'orders' ? ' active' : '' ?>" href="<?= $e($tabUrl('orders')) ?>"><i class="feather-shopping-cart"></i> Purchase Orders<?= $counts['po_open'] ? ' <span class="inv-tab-count" title="Awaiting delivery">' . $counts['po_open'] . '</span>' : '' ?></a></li>
                            <li class="nav-item"><a class="nav-link<?= $tab === 'suppliers' ? ' active' : '' ?>" href="<?= $e($tabUrl('suppliers')) ?>"><i class="feather-briefcase"></i> Suppliers</a></li>
                        </ul>
                        <div class="list-toolbar">
                            <input type="hidden" id="pcTab" data-list-param="tab" value="<?= $tab === 'requests' ? '' : $e($tab) ?>">
                            <?= listSearchControl($search, $tab === 'requests' ? 'Request no., e.g. PR-00004' : ($tab === 'orders' ? 'Order no., e.g. PO-00002' : 'Supplier name')) ?>
                            <?php if ($tab === 'requests'): ?>
                                <?= listSelectControl('Status', 'status', ['' => 'All Statuses'] + $PR_ST, $status, 'pcStatus') ?>
                            <?php elseif ($tab === 'orders'): ?>
                                <?= listSelectControl('Status', 'status', ['' => 'All Statuses', 'open' => 'Awaiting delivery'] + $PO_ST, $status, 'pcStatus') ?>
                            <?php else: ?>
                                <?= listSelectControl('Status', 'status', ['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'], $status, 'pcStatus') ?>
                            <?php endif; ?>
                            <?php if (isOwner() && $tab !== 'suppliers'): ?>
                                <?= listSelectControl('Branch', 'branch', ['' => 'All Branches'] + $bn, (string)($filterBranch ?: ''), 'pcBranch') ?>
                            <?php endif; ?>
                            <?= listResetControl($tabUrl($tab), $filtersOn) ?>
                        </div>
                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive">
                                <table class="table table-hover rec-table inv-table mb-0">
                                <?php if ($tab === 'requests'): ?>
                                    <thead><tr><th>Request</th><th>Branch</th><th>Items</th><th>Reason</th><th>Needed By</th><th>Status</th><th class="rec-col-action"><span class="visually-hidden">Open</span></th></tr></thead>
                                    <tbody>
                                    <?php if (!$rows): ?><?= inv_empty_row(7, $filtersOn > 0, 'No purchase requests yet', 'Branches request supplies here. The owner approves and orders them.', 'feather-file-plus', $tabUrl('requests')) ?>
                                    <?php else: foreach ($rows as $r): $ls = $r['purchase_request_items'] ?? []; ?>
                                        <tr class="rec-row">
                                            <td class="rec-nowrap"><a href="javascript:void(0);" class="rec-entity-name" onclick="viewPr(<?= (int)$r['pr_id'] ?>)"><?= $e($r['pr_no']) ?></a>
                                                <span class="rec-entity-sub"><?= $e(date('M j, Y', strtotime($r['requested_at']))) ?> · <?= $e($r['requested_by_name']) ?></span></td>
                                            <td><?= $e($bn[(int)$r['branch_id']] ?? '—') ?></td>
                                            <td><?= $e($ls[0]['inventory_items']['item_name'] ?? '—') ?><?= count($ls) > 1 ? ' <span class="rec-muted">+' . (count($ls) - 1) . ' more</span>' : '' ?></td>
                                            <td class="inv-col-reason"><span class="inv-clamp"><?= $e($r['reason']) ?></span></td>
                                            <td class="rec-nowrap"><?= $r['needed_by'] ? $e(date('M j, Y', strtotime($r['needed_by']))) : '<span class="rec-muted">—</span>' ?></td>
                                            <td><span class="inv-flow f-<?= $e($r['status']) ?>"><?= $e($PR_ST[$r['status']] ?? $r['status']) ?></span></td>
                                            <td class="rec-col-action"><a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" onclick="viewPr(<?= (int)$r['pr_id'] ?>)" title="Open" aria-label="Open <?= $e($r['pr_no']) ?>"><i class="feather-chevron-right"></i></a></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                <?php elseif ($tab === 'orders'): ?>
                                    <thead><tr><th>Order</th><th>Supplier</th><th>Deliver To</th><th>Items</th><th class="text-end">Total</th><th>Expected</th><th>Status</th><th class="rec-col-action"><span class="visually-hidden">Open</span></th></tr></thead>
                                    <tbody>
                                    <?php if (!$rows): ?><?= inv_empty_row(8, $filtersOn > 0, 'No purchase orders yet', 'Orders sent to suppliers appear here until their delivery is received.', 'feather-shopping-cart', $tabUrl('orders')) ?>
                                    <?php else: foreach ($rows as $r): $ls = $r['purchase_order_items'] ?? [];
                                        $tot = array_sum(array_map(fn($l) => $l['quantity_ordered'] * (float)$l['unit_cost'], $ls));
                                        $ord = array_sum(array_column($ls, 'quantity_ordered')); $rec = array_sum(array_column($ls, 'quantity_received'));
                                        $late = in_array($r['status'], ['ordered', 'partially_received'], true) && $r['expected_date'] && $r['expected_date'] < date('Y-m-d'); ?>
                                        <tr class="rec-row">
                                            <td class="rec-nowrap"><a href="javascript:void(0);" class="rec-entity-name" onclick="viewPo(<?= (int)$r['po_id'] ?>)"><?= $e($r['po_no']) ?></a>
                                                <span class="rec-entity-sub"><?= $e(date('M j, Y', strtotime($r['order_date']))) ?></span></td>
                                            <td><?= $e($r['suppliers']['supplier_name'] ?? '—') ?></td>
                                            <td><?= $e($bn[(int)$r['branch_id']] ?? '—') ?></td>
                                            <td><?= $e($ls[0]['inventory_items']['item_name'] ?? '—') ?><?= count($ls) > 1 ? ' <span class="rec-muted">+' . (count($ls) - 1) . ' more</span>' : '' ?>
                                                <span class="rec-entity-sub"><?= $rec ?> of <?= $ord ?> received</span></td>
                                            <td class="text-end rec-nowrap inv-num fw-semibold"><?= $e(inv_peso($tot)) ?></td>
                                            <td class="rec-nowrap<?= $late ? ' text-danger fw-semibold' : '' ?>"><?= $r['expected_date'] ? $e(date('M j, Y', strtotime($r['expected_date']))) . ($late ? ' <i class="feather-alert-circle" title="Overdue"></i>' : '') : '<span class="rec-muted">—</span>' ?></td>
                                            <td><span class="inv-flow f-<?= $e($r['status']) ?>"><?= $e($PO_ST[$r['status']] ?? $r['status']) ?></span></td>
                                            <td class="rec-col-action"><a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" onclick="viewPo(<?= (int)$r['po_id'] ?>)" title="Open" aria-label="Open <?= $e($r['po_no']) ?>"><i class="feather-chevron-right"></i></a></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                <?php else: ?>
                                    <thead><tr><th>Supplier</th><th>Contact Person</th><th>Phone / Email</th><th>Address</th><th>Status</th><th class="rec-col-action"><span class="visually-hidden">Actions</span></th></tr></thead>
                                    <tbody>
                                    <?php if (!$rows): ?><?= inv_empty_row(6, $filtersOn > 0, 'No suppliers yet', 'Add the companies you buy supplies from.', 'feather-briefcase', $tabUrl('suppliers')) ?>
                                    <?php else: foreach ($rows as $r): ?>
                                        <tr class="rec-row">
                                            <td><span class="rec-entity-name inv-plain"><?= $e($r['supplier_name']) ?></span><?php if ($r['notes']): ?><span class="rec-entity-sub"><?= $e($r['notes']) ?></span><?php endif; ?></td>
                                            <td><?= $r['contact_person'] ? $e($r['contact_person']) : '<span class="rec-muted">—</span>' ?></td>
                                            <td><?php if ($r['phone']): ?><span class="rec-icon-text"><i class="feather-phone"></i><?= $e($r['phone']) ?></span><?php endif; ?><?php if ($r['email']): ?><span class="rec-sub-line rec-icon-text"><i class="feather-mail"></i><?= $e($r['email']) ?></span><?php endif; ?><?= !$r['phone'] && !$r['email'] ? '<span class="rec-muted">—</span>' : '' ?></td>
                                            <td><?= $r['address'] ? '<span class="rec-address">' . $e($r['address']) . '</span>' : '<span class="rec-muted">—</span>' ?></td>
                                            <td><span class="inv-flow f-<?= $r['is_active'] ? 'active' : 'inactive' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                                            <td class="rec-col-action">
                                                <?php if ($canEdit): ?>
                                                <div class="dropdown">
                                                    <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-label="Actions"><i class="feather-more-vertical"></i></a>
                                                    <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                        <a href="javascript:void(0);" class="dropdown-item" onclick='openSupplierModal(<?= json_encode($r, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>)'><i class="feather-edit-2"></i> Edit</a>
                                                        <a href="javascript:void(0);" class="dropdown-item <?= $r['is_active'] ? 'text-danger' : 'text-success' ?>" onclick="toggleSupplier(<?= (int)$r['supplier_id'] ?>, <?= $r['is_active'] ? 'false' : 'true' ?>, <?= $e(json_encode($r['supplier_name'])) ?>)">
                                                            <i class="feather-<?= $r['is_active'] ? 'slash' : 'rotate-ccw' ?>"></i> <?= $r['is_active'] ? 'Deactivate' : 'Reactivate' ?></a>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                <?php endif; ?>
                                </table>
                            </div>
                        </div>
                        <?php $pcFrom = $total ? ($page - 1) * PC_PER_PAGE + 1 : 0; $pcTo = min($total, $page * PC_PER_PAGE);
                              $pcNoun = ['requests' => ['request', 'requests'], 'orders' => ['order', 'orders'], 'suppliers' => ['supplier', 'suppliers']][$tab]; ?>
                        <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <p class="rec-foot-count mb-0">
                                <?php if ($total): ?>Showing <strong><?= $pcFrom ?>–<?= $pcTo ?></strong> of <strong><?= number_format($total) ?></strong> <?= $pcNoun[$total === 1 ? 0 : 1] ?><?php else: ?>No <?= $pcNoun[1] ?> to show<?php endif; ?>
                                <?php if ($filtersOn): ?>&nbsp;·&nbsp;<a href="<?= $e($tabUrl($tab)) ?>" class="text-danger"><i class="feather-x-circle me-1"></i>Clear filters</a><?php endif; ?>
                            </p>
                            <?= listPager($baseUrl, $page, $totalPages) ?>
                        </div>
                    </div>
                </div></div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- One shared shell for every procurement dialog; content is built by inventory-procurement.js -->
    <div class="modal fade es-detail-modal" id="pcModal" tabindex="-1" aria-labelledby="pcTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-record-dialog rec-detail-dialog inv-detail-dialog">
            <form class="modal-content apt-record-content" id="pcForm" novalidate>
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-shopping-cart" id="pcIcon"></i></div>
                        <div><h5 class="apt-record-title mb-0" id="pcTitle">—</h5><div class="apt-record-meta" id="pcSub"></div></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-record-body p-0" id="pcBody"></div>
                <div class="modal-footer apt-record-footer" id="pcFoot"></div>
            </form>
        </div>
    </div>

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
    window.PC = {
        isOwner: <?= isOwner() ? 'true' : 'false' ?>, canEdit: <?= $canEdit ? 'true' : 'false' ?>,
        myBranch: <?= isOwner() ? 0 : (int)scopedBranch(0) ?>,
        branches: <?= json_encode(array_values(array_map(fn($b) => ['id' => (int)$b['branch_id'], 'name' => $b['branch_name']], $branches)), JSON_HEX_TAG) ?>
    };
    function applyFilters() { window._applyFilters('inventory-procurement.php', {}); }
    </script>
    <script src="assets/js/inventory-procurement.js?v=4"></script>
</body>
</html>
