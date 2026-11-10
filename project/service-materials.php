<?php
define('REQUIRED_ROLES', ['owner', 'admin']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying
require_once __DIR__ . '/dbconfig.php';

// One parallel wave: services + inventory items + material links + navbar bell
$_svcQ = 'select=service_id,service_name,service_type,base_price,status&status=neq.inactive&order=service_name.asc';
$_invQ = 'select=item_id,item_code,item_name,category,stock_quantity&status=neq.inactive&archived_at=is.null&order=item_name.asc';
// Add-material dropdown: admins see only their own branch's items (owner: all)
if (!isOwner()) $_invQ .= '&branch_id=eq.' . scopedBranch(0);
$_smQ  = 'select=service_id,item_id';
admin_prefetch(['svc' => admin_q('services', $_svcQ), 'inv' => admin_q('inventory', $_invQ), 'sm' => admin_q('service_materials', $_smQ)], ['navbar']);

// ── Fetch all services ───────────────────────────────────────
$svcRes   = admin_get('services', $_svcQ);
$services = is_array($svcRes['data']) ? $svcRes['data'] : [];

// ── Fetch all inventory items (for the add-material dropdown) ─
$invRes = admin_get('inventory', $_invQ);
$inventoryItems = is_array($invRes['data']) ? $invRes['data'] : [];

// ── Materials configured per service (for the list filters) ──
$smRes     = admin_get('service_materials', $_smQ);
$smRows    = is_array($smRes['data']) ? $smRes['data'] : [];
$matCounts = array_count_values(array_map('intval', array_column($smRows, 'service_id')));
$svcItems  = []; // service_id => [item_id, ...]  (Material / Item filter)
foreach ($smRows as $r) $svcItems[(int)$r['service_id']][] = (int)$r['item_id'];
$svcTypes  = array_values(array_unique(array_filter(array_map(fn($s) => trim((string)($s['service_type'] ?? '')), $services))));
sort($svcTypes);
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Service Materials</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
</head>
<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>

    <main class="nxl-container">
        <div class="nxl-content">

            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">Services</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Service Materials</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <span class="text-muted small">
                                <i class="feather-info me-1"></i>
                                Configure which inventory items are consumed per service
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-content">
                <div class="row">

                    <!-- LEFT: Services list -->
                    <div class="col-lg-4">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title">
                                    Services
                                    <span class="badge bg-soft-primary text-primary ms-2"><?= count($services) ?></span>
                                </h5>
                                <div class="card-header-action"><div class="card-header-btn">
                                    <div data-bs-toggle="tooltip" title="Refresh">
                                        <a href="service-materials.php" class="avatar-text avatar-xs bg-warning"></a>
                                    </div>
                                </div></div>
                            </div>
                            <div class="list-toolbar is-compact">
                                <div class="list-field list-field-search">
                                    <label class="list-label" for="smSearch">Search</label>
                                    <div class="list-search"><i class="feather-search" aria-hidden="true"></i>
                                        <input type="search" id="smSearch" placeholder="Service name" autocomplete="off" oninput="filterServiceList()"></div>
                                </div>
                                <div class="list-field">
                                    <label class="list-label" for="smType">Service Type</label>
                                    <select id="smType" class="js-custom-select" data-cs-filter onchange="filterServiceList()">
                                        <option value="">All Types</option>
                                        <?php foreach ($svcTypes as $t): ?><option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="list-field">
                                    <label class="list-label" for="smItem">Material / Item</label>
                                    <select id="smItem" class="js-custom-select" data-cs-filter data-cs-search onchange="filterServiceList()">
                                        <option value="">All Items</option>
                                        <?php foreach ($inventoryItems as $it): ?><option value="<?= (int)$it['item_id'] ?>"><?= htmlspecialchars($it['item_name'] . ($it['item_code'] ? ' (' . $it['item_code'] . ')' : '')) ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="list-field">
                                    <label class="list-label" for="smMats">Materials Setup</label>
                                    <select id="smMats" class="js-custom-select" data-cs-filter onchange="filterServiceList()">
                                        <option value="">All Services</option>
                                        <option value="with">Configured</option>
                                        <option value="without">Not Configured</option>
                                    </select>
                                </div>
                                <div class="list-field list-field-reset">
                                    <a href="javascript:void(0);" id="smReset" class="list-reset disabled" onclick="resetServiceFilters()"><i class="feather-rotate-ccw" aria-hidden="true"></i> Reset</a>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($services)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="feather-activity fs-2 mb-2 d-block"></i>No services found.
                                </div>
                                <?php else: ?>
                                <div class="list-group list-group-flush" id="serviceList">
                                    <?php foreach ($services as $svc): ?>
                                    <a href="javascript:void(0);"
                                       class="list-group-item list-group-item-action px-3 py-3 service-item"
                                       data-service-id="<?= $svc['service_id'] ?>"
                                       data-service-name="<?= htmlspecialchars($svc['service_name']) ?>"
                                       data-type="<?= htmlspecialchars(trim((string)($svc['service_type'] ?? ''))) ?>"
                                       data-price="<?= (float)($svc['base_price'] ?? 0) ?>"
                                       data-mats="<?= $matCounts[(int)$svc['service_id']] ?? 0 ?>"
                                       data-items="<?= implode(',', $svcItems[(int)$svc['service_id']] ?? []) ?>"
                                       onclick="loadServiceMaterials(<?= $svc['service_id'] ?>, '<?= htmlspecialchars($svc['service_name'], ENT_QUOTES) ?>')">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar-text avatar-sm bg-soft-primary text-primary rounded-circle flex-shrink-0">
                                                <i class="feather-activity fs-12"></i>
                                            </div>
                                            <div class="overflow-hidden">
                                                <div class="fw-semibold small text-truncate"><?= htmlspecialchars($svc['service_name']) ?></div>
                                                <div class="text-muted fs-12">
                                                    <?= htmlspecialchars($svc['service_type'] ?? '—') ?>
                                                    <?php if ($svc['base_price']): ?>
                                                    &nbsp;·&nbsp; ₱<?= number_format((float)$svc['base_price'], 2) ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <i class="feather-chevron-right ms-auto text-muted"></i>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                                <div id="serviceListEmpty" class="text-center py-4 text-muted small" style="display:none;">
                                    <i class="feather-search me-1"></i>No services match the selected filters.
                                    <a href="javascript:void(0);" onclick="resetServiceFilters()" class="fw-semibold">Reset filters</a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: Materials panel -->
                    <div class="col-lg-8">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title" id="materialsTitle">
                                    <i class="feather-package me-2 text-muted"></i>
                                    Select a service to manage its materials
                                </h5>
                                <div class="card-header-action">
                                    <button class="btn btn-primary btn-sm" id="addMaterialBtn"
                                            onclick="openAddMaterialModal()" style="display:none;">
                                        <i class="feather-plus me-1"></i> Add Material
                                    </button>
                                </div>
                            </div>

                            <!-- Empty state -->
                            <div id="materialsEmpty" class="card-body text-center py-5 text-muted">
                                <i class="feather-arrow-left fs-2 mb-2 d-block"></i>
                                Click a service on the left to view and manage its materials.
                            </div>

                            <!-- Loading state -->
                            <div id="materialsLoading" class="card-body text-center py-5 text-muted" style="display:none;">
                                <span class="spinner-border spinner-border-sm me-2"></span> Loading materials...
                            </div>

                            <!-- Materials table -->
                            <div id="materialsTableWrap" style="display:none;">
                                <div class="card-body custom-card-action p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th><a href="javascript:void(0);" class="th-sort" data-mat-sort="name" onclick="sortMaterials('name')">Item<i class="bi bi-arrow-down-up th-sort-idle" aria-hidden="true"></i></a></th>
                                                    <th><a href="javascript:void(0);" class="th-sort" data-mat-sort="category" onclick="sortMaterials('category')">Category<i class="bi bi-arrow-down-up th-sort-idle" aria-hidden="true"></i></a></th>
                                                    <th><a href="javascript:void(0);" class="th-sort" data-mat-sort="stock" onclick="sortMaterials('stock')">Current Stock<i class="bi bi-arrow-down-up th-sort-idle" aria-hidden="true"></i></a></th>
                                                    <th><a href="javascript:void(0);" class="th-sort" data-mat-sort="qty" onclick="sortMaterials('qty')">Qty to Deduct<i class="bi bi-arrow-down-up th-sort-idle" aria-hidden="true"></i></a></th>
                                                    <th class="text-end">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="materialsTableBody"></tbody>
                                        </table>
                                    </div>
                                </div>
                                <div id="materialsNoItems" class="text-center py-4 text-muted" style="display:none;">
                                    <i class="feather-package me-2"></i>No materials configured yet.
                                    <a href="javascript:void(0);" onclick="openAddMaterialModal()">Add the first one.</a>
                                </div>
                                <div class="card-footer">
                                    <p class="text-muted small mb-0">
                                        <i class="feather-info me-1 text-warning"></i>
                                        These materials will be auto-deducted from inventory when an appointment using this service is marked as completed.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </main>

    <!-- ============================================================
         ADD MATERIAL MODAL
    ============================================================ -->
    <div class="modal fade" id="addMaterialModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Add Material</h5>
                        <p class="text-muted small mb-0">Add an inventory item to <strong id="addMat_serviceName"></strong></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="addMatError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="addMat_serviceId">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Inventory Item </label>
                            <select id="addMat_item" class="form-select js-custom-select" data-cs-float data-cs-search>
                                <option value=""> Select Item </option>
                                <?php foreach ($inventoryItems as $item): ?>
                                <option value="<?= $item['item_id'] ?>"
                                        data-stock="<?= (int)$item['stock_quantity'] ?>"
                                        data-category="<?= htmlspecialchars($item['category'] ?? '') ?>">
                                    <?= htmlspecialchars($item['item_name']) ?>
                                    (<?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?>)
                                    — Stock: <?= (int)$item['stock_quantity'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Quantity to Deduct </label>
                            <input type="number" id="addMat_qty" class="form-control" value="1" min="1" placeholder="e.g. 2">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Current Stock</label>
                            <div class="form-control bg-light text-muted" id="addMat_stockPreview">—</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <span class="text-muted small"><span class="text-danger"></span></span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary px-4" id="addMatSaveBtn" onclick="saveMaterial()">
                            <i class="feather-save me-1"></i> Save
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
    <script src="assets/js/service-materials.js"></script>
</body>
</html>