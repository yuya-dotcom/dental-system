<?php
// =============================================================
//  inventory-transfers.php — Branch-to-Branch Stock Transfers
//  List + "New Transfer" form + details with the next step for the
//  signed-in user (approve / send / receive / reject / cancel).
//  Business rules: api/inventory_transfers.php.
//  Scope: owner sees every branch; admins / dentists see transfers
//  from or to their own branch.
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();
require_once __DIR__ . '/controllers/inventory_v2.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/appointment_controller.php';   // resolveCount + buildPageResult
require_once __DIR__ . '/controllers/list_filters.php';

const TRF_PER_PAGE = 10;
$v2           = inv_v2_ready();
$filterStatus = in_array($_GET['status'] ?? '', ['pending', 'approved', 'in_transit', 'received', 'rejected', 'cancelled', 'open'], true) ? $_GET['status'] : '';
$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : 0;
$search       = listSearch();
$page         = max(1, (int)($_GET['page'] ?? 1));
$scope        = scopedBranch($filterBranch);   // owner: filter (0 = all); others: own branch

$rows = []; $totalRecords = 0; $counts = ['pending' => 0, 'approved' => 0, 'in_transit' => 0];
if ($v2) {
    $base = [];
    if ($scope !== 0) $base[] = 'or=(from_branch_id.eq.' . $scope . ',to_branch_id.eq.' . $scope . ')';
    $q = array_merge([
        'select=transfer_id,transfer_no,from_branch_id,to_branch_id,status,reason,requested_by_name,requested_at,updated_at,'
            . 'stock_transfer_items(quantity,inventory_items(item_name,unit))',
        'order=requested_at.desc,transfer_id.desc',
        'limit=' . TRF_PER_PAGE, 'offset=' . (($page - 1) * TRF_PER_PAGE),
    ], $base);
    if ($filterStatus === 'open') $q[] = 'status=in.(pending,approved,in_transit)';
    elseif ($filterStatus)        $q[] = 'status=eq.' . $filterStatus;
    if ($search !== '')           $q[] = 'transfer_no=ilike.*' . rawurlencode(str_replace(['*', ',', '(', ')'], '', $search)) . '*';
    // One parallel wave: this page's queries + the navbar bell + the branch list
    $res = admin_prefetch([
        'list'   => admin_q('stock_transfers', implode('&', $q), ['Prefer: count=exact']),
        'counts' => admin_q('stock_transfers', implode('&', array_merge(['select=status', 'status=in.(pending,approved,in_transit)', 'limit=1000'], $base))),
    ]);
    $rows = is_array($res['list']['data'] ?? null) ? $res['list']['data'] : [];
    $totalRecords = admin_count($res['list']);
    foreach ((array)($res['counts']['data'] ?? []) as $c) $counts[$c['status']] = ($counts[$c['status']] ?? 0) + 1;
}
if (!$v2) admin_prefetch([]);        // bell + branch list, still in parallel
$branches = getAllBranches();      // from the wave above (remembered for the request)
$bn       = inv_branch_names();
$totalPages = max(1, (int)ceil($totalRecords / TRF_PER_PAGE));
$from = $totalRecords ? ($page - 1) * TRF_PER_PAGE + 1 : 0;
$to   = min($totalRecords, $page * TRF_PER_PAGE);
$params    = ['status' => $filterStatus, 'branch' => $filterBranch, 'q' => $search];
$baseUrl   = listUrl('inventory-transfers.php', $params);
$filtersOn = listActiveCount(array_values($params));
$tileUrl   = fn($s) => listUrl('inventory-transfers.php', ['status' => $s, 'branch' => $filterBranch]);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$statusLabel = ['pending' => 'Pending Approval', 'approved' => 'Approved', 'in_transit' => 'In Transit', 'received' => 'Received', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Stock Transfers</title>
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
                        <li class="breadcrumb-item">Stock Transfers</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <?php if ($v2 && !isDentist()): ?>
                            <button class="btn btn-primary btn-sm hdr-btn" onclick="openTransferModal()"><i class="feather-plus me-1"></i> New Transfer</button>
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
                            <h5 class="card-title mb-0">Stock Transfers <span class="badge bg-soft-primary text-primary ms-2"><?= number_format($totalRecords) ?> <?= $filtersOn ? 'found' : 'total' ?></span>
                                <span class="rec-card-sub">Move stock between branches. Stock leaves the source when it is sent and is added when the destination receives it.</span>
                            </h5>
                        </div>
                        <nav class="rec-summary" aria-label="Transfers summary">
                            <?php foreach ([['pending', 'Waiting for Approval', 'feather-clock', 'tone-amber'], ['approved', 'Approved — To Send', 'feather-check', ''], ['in_transit', 'In Transit — To Receive', 'feather-truck', ''], ['', 'All Transfers', 'feather-list', '']] as [$st, $label, $icon, $tone]):
                                $cur = $filterStatus === $st; ?>
                            <a href="<?= $e($tileUrl($st)) ?>" class="rec-stat <?= $tone ?><?= $cur ? ' is-current' : '' ?>">
                                <span class="rec-stat-icon"><i class="<?= $icon ?>"></i></span>
                                <span class="rec-stat-body">
                                    <span class="rec-stat-value d-block"><?= $st ? number_format($counts[$st]) : ($filterStatus ? '—' : number_format($totalRecords)) ?></span>
                                    <span class="rec-stat-label d-block"><?= $label ?></span>
                                </span>
                            </a>
                            <?php endforeach; ?>
                        </nav>
                        <div class="list-toolbar">
                            <?= listSearchControl($search, 'Transfer no., e.g. TRF-00012') ?>
                            <?= listSelectControl('Status', 'status', ['' => 'All Statuses', 'open' => 'Open (not finished)'] + $statusLabel, $filterStatus, 'filterStatus') ?>
                            <?php if (isOwner()): ?>
                            <?= listSelectControl('Branch', 'branch', ['' => 'All Branches'] + $bn, (string)($filterBranch ?: ''), 'filterBranch') ?>
                            <?php endif; ?>
                            <?= listResetControl('inventory-transfers.php', $filtersOn) ?>
                        </div>
                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive">
                                <table class="table table-hover rec-table inv-table mb-0">
                                    <thead><tr><th>Transfer</th><th>From → To</th><th>Items</th><th>Reason</th><th>Status</th><th>Requested By</th><th class="rec-col-action"><span class="visually-hidden">Open</span></th></tr></thead>
                                    <tbody>
                                    <?php if (!$rows): ?>
                                        <?= inv_empty_row(7, $filtersOn > 0, 'No transfers yet', 'Move stock from a branch that has extra to one that is running low.', 'feather-truck', 'inventory-transfers.php') ?>
                                    <?php else: foreach ($rows as $t):
                                        $lines = $t['stock_transfer_items'] ?? [];
                                        $first = $lines[0]['inventory_items']['item_name'] ?? '';
                                        $mine  = ($t['status'] === 'approved' && canAccessBranch($t['from_branch_id']) && !isDentist())
                                              || ($t['status'] === 'in_transit' && canAccessBranch($t['to_branch_id']) && !isDentist())
                                              || ($t['status'] === 'pending' && isOwner()); ?>
                                        <tr class="rec-row">
                                            <td class="rec-nowrap">
                                                <a href="javascript:void(0);" class="rec-entity-name" onclick="viewTransfer(<?= (int)$t['transfer_id'] ?>)"><?= $e($t['transfer_no']) ?></a>
                                                <span class="rec-entity-sub"><?= $e(date('M j, Y', strtotime($t['requested_at']))) ?></span>
                                            </td>
                                            <td class="rec-nowrap"><span class="inv-route"><?= $e($bn[(int)$t['from_branch_id']] ?? '—') ?><i class="feather-arrow-right" aria-label="to"></i><?= $e($bn[(int)$t['to_branch_id']] ?? '—') ?></span></td>
                                            <td><?= $e($first) ?><?= count($lines) > 1 ? ' <span class="rec-muted">+' . (count($lines) - 1) . ' more</span>' : '' ?>
                                                <span class="rec-entity-sub"><?= array_sum(array_map(fn($l) => (int)$l['quantity'], $lines)) ?> unit(s)</span></td>
                                            <td class="inv-col-reason"><span class="inv-clamp"><?= $e($t['reason']) ?></span></td>
                                            <td><span class="inv-flow f-<?= $e($t['status']) ?>"><?= $e($statusLabel[$t['status']] ?? $t['status']) ?></span>
                                                <?php if ($mine): ?><span class="inv-needs"><i class="feather-alert-circle"></i> Needs your action</span><?php endif; ?></td>
                                            <td class="rec-nowrap"><?= $e($t['requested_by_name'] ?? '—') ?></td>
                                            <td class="rec-col-action"><a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" onclick="viewTransfer(<?= (int)$t['transfer_id'] ?>)" title="Open" aria-label="Open <?= $e($t['transfer_no']) ?>"><i class="feather-chevron-right"></i></a></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <p class="rec-foot-count mb-0">
                                <?php if ($totalRecords): ?>Showing <strong><?= $from ?>–<?= $to ?></strong> of <strong><?= number_format($totalRecords) ?></strong> transfer<?= $totalRecords === 1 ? '' : 's' ?><?php else: ?>No transfers to show<?php endif; ?>
                                <?php if ($filtersOn): ?>&nbsp;·&nbsp;<a href="inventory-transfers.php" class="text-danger"><i class="feather-x-circle me-1"></i>Clear filters</a><?php endif; ?>
                            </p>
                            <?= listPager($baseUrl, $page, $totalPages) ?>
                        </div>
                    </div>
                </div></div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- ══ DETAILS ══ -->
    <div class="modal fade es-detail-modal" id="trfViewModal" tabindex="-1" aria-labelledby="tvNo" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-record-dialog rec-detail-dialog">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-truck"></i></div>
                        <div><h5 class="apt-record-title mb-0" id="tvNo">Transfer</h5><div class="apt-record-meta" id="tvRoute">—</div></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-record-body p-0">
                    <div class="inv-loading" id="tvLoading"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>
                    <div class="ar-view-body" id="tvBody" hidden>
                        <div id="tvAlert"></div>
                        <div class="ar-cards-grid">
                            <section class="ar-card ar-card-wide">
                                <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-package"></i></span><h6 class="ar-card-title">Items</h6></div>
                                <div class="ar-card-fields p-0"><div class="table-responsive"><table class="table inv-mini-table mb-0">
                                    <thead><tr><th>Item</th><th class="text-end">Quantity</th><th>Batch</th><th class="text-end" id="tvStockTh">At Source Now</th></tr></thead>
                                    <tbody id="tvLines"></tbody></table></div></div>
                            </section>
                            <section class="ar-card">
                                <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-file-text"></i></span><h6 class="ar-card-title">Reason</h6></div>
                                <div class="ar-card-fields"><p class="inv-text mb-0" id="tvReason"></p><p class="inv-text inv-text-muted mt-2 mb-0" id="tvNotes"></p></div>
                            </section>
                            <section class="ar-card">
                                <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-clock"></i></span><h6 class="ar-card-title">History</h6></div>
                                <div class="ar-card-fields"><ul class="inv-steps" id="tvSteps"></ul></div>
                            </section>
                        </div>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer" id="tvFoot">
                    <span class="rec-foot-spacer"></span>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($v2 && !isDentist()): ?>
    <!-- ══ NEW TRANSFER ══ -->
    <div class="modal fade es-detail-modal" id="trfModal" tabindex="-1" aria-labelledby="trfModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-form-dialog rec-form-dialog">
            <form class="modal-content apt-record-content" id="trfForm" novalidate onsubmit="event.preventDefault(); saveTransfer();">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-truck"></i></div>
                        <div><h5 class="apt-record-title mb-0" id="trfModalTitle">New Stock Transfer</h5>
                        <div class="apt-record-meta"><?= isOwner() ? 'Created by the owner = approved right away.' : 'The owner approves it before the stock is sent.' ?></div></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-form-body">
                    <div id="trfError" class="apt-form-alert" role="alert" style="display:none;"></div>
                    <div class="rec-form-stack">
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-map-pin"></i></span><h6 class="ar-card-title">Branches</h6></div>
                            <div class="apt-form-card-body"><div class="row g-3">
                                <div class="col-md-6"><div class="apt-field mb-0">
                                    <label class="apt-label" for="trf_from">From <span class="apt-opt">(sends the stock)</span> <span class="apt-req">*</span></label>
                                    <select id="trf_from" class="form-select js-custom-select" data-cs-float onchange="trfLoadItems()"><option value="">Select branch</option>
                                        <?php foreach ($branches as $b): ?><option value="<?= (int)$b['branch_id'] ?>"><?= $e($b['branch_name']) ?></option><?php endforeach; ?></select>
                                </div></div>
                                <div class="col-md-6"><div class="apt-field mb-0">
                                    <label class="apt-label" for="trf_to">To <span class="apt-opt">(receives the stock)</span> <span class="apt-req">*</span></label>
                                    <select id="trf_to" class="form-select js-custom-select" data-cs-float><option value="">Select branch</option>
                                        <?php foreach ($branches as $b): ?><option value="<?= (int)$b['branch_id'] ?>"><?= $e($b['branch_name']) ?></option><?php endforeach; ?></select>
                                </div></div>
                                <?php if (!isOwner()): ?><div class="col-12"><div class="rec-help">One of the two must be your branch.</div></div><?php endif; ?>
                            </div></div>
                        </section>
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-package"></i></span><h6 class="ar-card-title">Items</h6></div>
                            <div class="apt-form-card-body">
                                <table class="inv-lines"><thead><tr><th>Item (from the sending branch)</th><th>Quantity</th><th><span class="visually-hidden">Remove</span></th></tr></thead><tbody id="trfLines"></tbody></table>
                                <div class="inv-lines-foot"><button type="button" class="btn btn-light-brand btn-sm" onclick="trfAddLine()"><i class="feather-plus me-1"></i> Add Item</button></div>
                                <div class="rec-help">Each item shows how much can be sent — 1 unit always stays at the sending branch.</div>
                            </div>
                        </section>
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-file-text"></i></span><h6 class="ar-card-title">Reason</h6></div>
                            <div class="apt-form-card-body">
                                <div class="apt-field"><label class="apt-label" for="trf_reason">Reason <span class="apt-req">*</span></label>
                                    <input type="text" id="trf_reason" class="form-control" maxlength="250" placeholder="e.g. Caloocan is out of gloves; Bulacan has extra"></div>
                                <div class="apt-field mb-0"><label class="apt-label" for="trf_notes">Notes</label>
                                    <textarea id="trf_notes" class="form-control" rows="2" maxlength="500" placeholder="Optional"></textarea></div>
                            </div>
                        </section>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer apt-form-footer">
                    <span class="apt-form-hint"><span class="apt-req">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4" id="trfSaveBtn"><i class="feather-send me-1"></i> <?= isOwner() ? 'Create Transfer' : 'Submit Request' ?></button>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

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
    window.TRF = { isOwner: <?= isOwner() ? 'true' : 'false' ?>, myBranch: <?= isOwner() ? 0 : (int)scopedBranch(0) ?> };
    function applyFilters() { window._applyFilters('inventory-transfers.php', {}); }
    </script>
    <script src="assets/js/inventory-transfers.js?v=2"></script>
</body>
</html>
