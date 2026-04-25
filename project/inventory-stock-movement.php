<?php

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/inventory_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';

$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : 0;
$filterItem   = isset($_GET['item'])   ? (int)$_GET['item']   : 0;
$page         = max(1, (int)($_GET['page'] ?? 1));

$branches     = getAllBranches();
$data         = getStockMovements($page, $filterBranch, $filterItem ?: '');
$movements    = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];

$itemLabel = '';
if ($filterItem && !empty($movements)) {
    $first     = $movements[0]['inventory'] ?? [];
    $itemLabel = trim(($first['item_code'] ?? '') . ' — ' . ($first['item_name'] ?? ''));
}
$baseUrl = 'inventory-stock-movement.php?branch=' . $filterBranch . '&item=' . $filterItem;
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
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/admin.css">
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
                            <?php if (isOwner()): ?>
                            <select id="filterBranch" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:180px;">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>" <?= (int)$b['branch_id']===$filterBranch?'selected':'' ?>><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                            <?php if ($filterItem): ?>
                            <a href="inventory-stock-movement.php" class="btn btn-sm btn-light-brand">
                                <i class="feather-x me-1"></i>Clear item filter
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="main-content">
                <div class="row"><div class="col-lg-12">
                    <div class="card stretch stretch-full">
                        <div class="card-header">
                            <h5 class="card-title">
                                <?php if ($itemLabel): ?>
                                    Stock Movement &mdash; <span class="text-primary"><?= htmlspecialchars($itemLabel) ?></span>
                                <?php else: ?>
                                    Inventory Stock Movement
                                <?php endif; ?>
                                <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> records</span>
                            </h5>
                            <div class="card-header-action"><div class="card-header-btn">
                                <div data-bs-toggle="tooltip" title="Refresh"><a href="inventory-stock-movement.php" class="avatar-text avatar-xs bg-warning"></a></div>
                                <div data-bs-toggle="tooltip" title="Maximize/Minimize"><a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a></div>
                            </div></div>
                        </div>
                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead><tr>
                                        <th>Date</th><th>Item</th><th>Branch</th>
                                        <th>Change</th><th>Reason</th><th>Performed By</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php if (empty($movements)): ?>
                                        <tr><td colspan="6" class="text-center py-4 text-muted"><i class="feather-refresh-cw me-2"></i>No stock movements found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($movements as $mov): ?>
                                        <?php $change = (int)($mov['quantity_change'] ?? 0); ?>
                                        <tr>
                                            <td><?= formatMovDate($mov['movement_date'] ?? null) ?></td>
                                            <td>
                                                <?php $inv = $mov['inventory'] ?? []; ?>
                                                <div class="fw-semibold"><?= htmlspecialchars($inv['item_name'] ?? '—') ?></div>
                                                <div class="fs-12 text-muted"><?= htmlspecialchars($inv['item_code'] ?? '') ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($mov['branches']['branch_name'] ?? '—') ?></td>
                                            <td><span class="badge <?= movementBadge($change) ?>"><?= movementLabel($change) ?></span></td>
                                            <td><?= htmlspecialchars($mov['reason'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($mov['performed_by'] ?? '—') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <p class="text-muted small mb-0">
                                Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> record(s)
                                <?php if ($filterBranch): ?>
                                    &nbsp;·&nbsp;<a href="inventory-stock-movement.php<?= $filterItem?'?item='.$filterItem:'' ?>" class="text-danger"><i class="feather-x-circle me-1"></i>Clear branch filter</a>
                                <?php endif; ?>
                            </p>
                            <?php if ($totalPages > 1): ?>
                            <ul class="list-unstyled d-flex align-items-center gap-1 mb-0 pagination-common-style">
                                <li><?php if ($page>1): ?><a href="<?= $baseUrl ?>&page=<?= $page-1 ?>"><i class="bi bi-arrow-left"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-left"></i></a><?php endif; ?></li>
                                <?php $wS=max(1,$page-2);$wE=min($totalPages,$wS+4);$wS=max(1,$wE-4); ?>
                                <?php if ($wS>1): ?><li><a href="<?= $baseUrl ?>&page=1">1</a></li><?php if ($wS>2): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><?php endif; ?>
                                <?php for ($pg=$wS;$pg<=$wE;$pg++): ?><li><a href="<?= $baseUrl ?>&page=<?= $pg ?>" class="<?= $pg===$page?'active':'' ?>"><?= $pg ?></a></li><?php endfor; ?>
                                <?php if ($wE<$totalPages): ?><?php if ($wE<$totalPages-1): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><li><a href="<?= $baseUrl ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a></li><?php endif; ?>
                                <li><?php if ($page<$totalPages): ?><a href="<?= $baseUrl ?>&page=<?= $page+1 ?>"><i class="bi bi-arrow-right"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-right"></i></a><?php endif; ?></li>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div></div>
            </div>
        </div>
    </main>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/js/admin.js"></script>
    <script>
    function applyFilters() {
        const branch = document.getElementById('filterBranch')?.value ?? '0';
        const p = new URLSearchParams();
        if (branch !== '0') p.set('branch', branch);
        <?php if ($filterItem): ?>p.set('item', '<?= $filterItem ?>');<?php endif; ?>
        window.location.href = 'inventory-stock-movement.php' + (p.toString() ? '?' + p.toString() : '');
    }
    </script>
</body>
</html>