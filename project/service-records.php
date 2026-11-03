<?php
// =============================================================
//  service-records.php
//  One page for every service setting:
//    Details   — name, type, price, duration, description, status
//    Materials — inventory items deducted when the service is done
//  Replaces service-pricing.php + service-materials.php.
//
//  Owner: everything. Admin: materials, type, duration, description,
//  status (not name, price, add or delete). Enforced in api/service_crud.php.
// =============================================================
define('REQUIRED_ROLES', ['owner', 'admin']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/service_controller.php';

$canOwner  = isOwner();
$svcData   = getServiceRecords();
$inventory = getMaterialInventoryItems();

$pageData = [
    'services'       => $svcData['services'],
    'needsMigration' => $svcData['needs_migration'],
    'isOwner'        => $canOwner,
];
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Services</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=4">
    <link rel="stylesheet" href="assets/css/service-records.css?v=2">
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
                        <li class="breadcrumb-item">Services</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <?php if ($canOwner): ?>
                            <button class="btn btn-primary btn-sm" onclick="srNewService()">
                                <i class="feather-plus me-1"></i> Add Service
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-content">
                <?php if ($svcData['needs_migration']): ?>
                <div class="alert alert-warning d-flex align-items-start gap-2 small">
                    <i class="feather-alert-triangle mt-1"></i>
                    <div>
                        <strong>Durations aren't set up yet.</strong>
                        Run <code>sql/2026-10-service-durations-operating-days.sql</code> in Supabase → SQL Editor,
                        then refresh this page. Until then every service shows the 30-minute default and durations can't be saved.
                    </div>
                </div>
                <?php endif; ?>

                <div class="row">

                    <!-- ── LEFT: services list ─────────────────────── -->
                    <div class="col-lg-4">
                        <div class="card stretch stretch-full sr-list-card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    Services
                                    <span class="badge bg-soft-primary text-primary ms-2" id="srCount">0</span>
                                </h5>
                            </div>
                            <div class="sr-filters">
                                <div class="sr-search">
                                    <i class="feather-search"></i>
                                    <input type="search" id="srSearch" class="form-control form-control-sm"
                                           placeholder="Search services…" autocomplete="off" aria-label="Search services">
                                </div>
                                <div class="d-flex gap-2">
                                    <select id="srFilterType" class="js-custom-select" data-cs-filter data-cs-search aria-label="Filter by type">
                                        <option value="">All types</option>
                                        <?php foreach (SERVICE_TYPES as $t): ?>
                                        <option value="<?= $t ?>"><?= $t ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select id="srFilterStatus" class="js-custom-select" data-cs-filter aria-label="Filter by status">
                                        <option value="active" selected>Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="">All</option>
                                    </select>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="list-group list-group-flush sr-list" id="srList" role="listbox" aria-label="Services"></div>
                            </div>
                        </div>
                    </div>

                    <!-- ── RIGHT: selected service ──────────────────── -->
                    <div class="col-lg-8">
                        <div class="card stretch stretch-full" id="srPanelCard">

                            <!-- Nothing selected -->
                            <div id="srEmpty" class="card-body text-center py-5 text-muted">
                                <i class="feather-arrow-left fs-2 mb-2 d-block sr-empty-arrow"></i>
                                Select a service to view or edit its details and materials.
                            </div>

                            <!-- Selected / new service -->
                            <div id="srPanel" style="display:none;">
                                <div class="card-header sr-panel-head">
                                    <div class="d-flex align-items-center gap-3 min-w-0">
                                        <div class="avatar-text avatar-md bg-soft-primary text-primary rounded-circle flex-shrink-0">
                                            <i class="feather-activity"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <h5 class="card-title mb-0 text-truncate" id="srPanelTitle">—</h5>
                                            <div class="text-muted fs-12" id="srPanelSub"></div>
                                        </div>
                                    </div>
                                    <span class="badge" id="srPanelStatus"></span>
                                </div>

                                <ul class="nav nav-tabs sr-tabs px-3" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="srTabDetailsBtn" data-bs-toggle="tab"
                                                data-bs-target="#srTabDetails" type="button" role="tab">
                                            <i class="feather-file-text me-1"></i> Details
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="srTabMaterialsBtn" data-bs-toggle="tab"
                                                data-bs-target="#srTabMaterials" type="button" role="tab">
                                            <i class="feather-package me-1"></i> Materials
                                            <span class="badge bg-soft-secondary text-secondary ms-1" id="srMatCount">0</span>
                                        </button>
                                    </li>
                                </ul>

                                <div class="tab-content">

                                    <!-- Details -->
                                    <div class="tab-pane fade show active" id="srTabDetails" role="tabpanel">
                                        <div class="card-body">
                                            <div id="srFormError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                                            <div class="row g-3">
                                                <div class="col-12">
                                                    <label class="form-label fw-semibold small" for="sr_name">Service Name <span class="text-danger">*</span></label>
                                                    <input type="text" id="sr_name" class="form-control" maxlength="<?= SERVICE_NAME_MAX ?>"
                                                           placeholder="e.g. Tooth Extraction">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label fw-semibold small" for="sr_type">Type</label>
                                                    <select id="sr_type" class="form-select js-custom-select" data-cs-float data-cs-search>
                                                        <?php foreach (SERVICE_TYPES as $t): ?>
                                                        <option value="<?= $t ?>"><?= $t ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label fw-semibold small" for="sr_price">Base Price <span class="text-danger">*</span></label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">₱</span>
                                                        <input type="number" id="sr_price" class="form-control" min="0" step="0.01" placeholder="0.00">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label fw-semibold small" for="sr_duration">Duration <span class="text-danger">*</span></label>
                                                    <select id="sr_duration" class="form-select js-custom-select" data-cs-float <?= $svcData['needs_migration'] ? 'disabled' : '' ?>>
                                                        <?php foreach (serviceDurationOptions() as $m): ?>
                                                        <option value="<?= $m ?>"><?= formatServiceDuration($m) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-text mt-0" id="srOwnerNote" style="display:none;">
                                                        <i class="feather-lock me-1"></i>Only the owner can change the name and price.
                                                    </div>
                                                    <div class="form-text mt-0">
                                                        <i class="feather-clock me-1"></i>Duration is how long the service takes. Online booking uses it to make sure a visit fits before closing time.
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label fw-semibold small" for="sr_chart_outcome">Dental Chart Outcome</label>
                                                    <select id="sr_chart_outcome" class="form-select js-custom-select" data-cs-float data-cs-search>
                                                        <option value="">None — no chart suggestion</option>
                                                        <option value="missing">Missing (e.g. extraction)</option>
                                                        <option value="filled">Filled (e.g. filling / restoration)</option>
                                                        <option value="crown">Crown</option>
                                                        <option value="healthy">Healthy</option>
                                                        <option value="decay">Decay</option>
                                                        <option value="impacted">Impacted</option>
                                                        <option value="impacted_ne">Impacted (Not Erupted)</option>
                                                    </select>
                                                    <div class="form-text">
                                                        Suggested to the dentist when this procedure is completed on a tooth. The chart only changes if the dentist confirms it.
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label fw-semibold small" for="sr_description">Description</label>
                                                    <textarea id="sr_description" class="form-control" rows="3" maxlength="<?= SERVICE_DESC_MAX ?>"
                                                              placeholder="Optional short description…"></textarea>
                                                </div>
                                                <div class="col-md-4" id="srStatusRow">
                                                    <label class="form-label fw-semibold small" for="sr_status">Status</label>
                                                    <select id="sr_status" class="form-select js-custom-select" data-cs-float>
                                                        <option value="active">Active</option>
                                                        <option value="inactive">Inactive</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                                            <span class="text-muted small" id="srDirtyNote">No unsaved changes</span>
                                            <div class="d-flex gap-2">
                                                <?php if ($canOwner): ?>
                                                <button type="button" class="btn btn-outline-danger" id="srDeleteBtn" onclick="srDeleteService()">
                                                    <i class="feather-trash-2 me-1"></i> Delete
                                                </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-light" id="srCancelBtn" onclick="srCancelEdit()">Cancel</button>
                                                <button type="button" class="btn btn-primary px-4" id="srSaveBtn" onclick="srSaveService()">
                                                    <i class="feather-save me-1"></i> Save Changes
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Materials -->
                                    <div class="tab-pane fade" id="srTabMaterials" role="tabpanel">
                                        <div class="d-flex align-items-center justify-content-between gap-2 px-4 pt-3">
                                            <p class="text-muted small mb-0">Deducted from inventory when an appointment with this service is completed.</p>
                                            <button class="btn btn-primary btn-sm flex-shrink-0" onclick="srOpenAddMaterial()">
                                                <i class="feather-plus me-1"></i> Add Material
                                            </button>
                                        </div>
                                        <div id="srMatLoading" class="card-body text-center py-5 text-muted" style="display:none;">
                                            <span class="spinner-border spinner-border-sm me-2"></span> Loading materials…
                                        </div>
                                        <div id="srMatError" class="card-body text-center py-5 text-muted" style="display:none;">
                                            <i class="feather-alert-circle fs-2 mb-2 d-block text-danger"></i>Failed to load materials.
                                            <a href="javascript:void(0);" onclick="srLoadMaterials()">Try again</a>
                                        </div>
                                        <div id="srMatWrap" style="display:none;">
                                            <div class="card-body custom-card-action p-0 pt-2">
                                                <div class="table-responsive">
                                                    <table class="table table-hover mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th>Item</th>
                                                                <th>Category</th>
                                                                <th>Current Stock</th>
                                                                <th>Qty to Deduct</th>
                                                                <th class="text-end">Action</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody id="srMatBody"></tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div id="srMatNone" class="text-center py-4 text-muted" style="display:none;">
                                                <i class="feather-package me-2"></i>No materials configured yet.
                                                <a href="javascript:void(0);" onclick="srOpenAddMaterial()">Add the first one.</a>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </main>

    <!-- ADD MATERIAL MODAL -->
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
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small" for="addMat_item">Inventory Item</label>
                            <select id="addMat_item" class="form-select js-custom-select" data-cs-float data-cs-search>
                                <option value="">Select item</option>
                                <?php foreach ($inventory as $item): ?>
                                <option value="<?= (int)$item['item_id'] ?>" data-stock="<?= (int)$item['stock_quantity'] ?>">
                                    <?= htmlspecialchars($item['item_name']) ?>
                                    (<?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?>)
                                    — Stock: <?= (int)$item['stock_quantity'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" for="addMat_qty">Quantity to Deduct</label>
                            <input type="number" id="addMat_qty" class="form-control" value="1" min="1" placeholder="e.g. 2">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Current Stock</label>
                            <div class="form-control bg-light text-muted" id="addMat_stockPreview">—</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary px-4" id="addMatSaveBtn" onclick="srSaveMaterial()">
                        <i class="feather-save me-1"></i> Save
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.SR_DATA = <?= json_encode($pageData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
    <script src="assets/js/es-swal.js?v=2"></script>
    <script src="assets/js/service-records.js?v=3"></script>
</body>
</html>