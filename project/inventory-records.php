<?php
// =============================================================
//  inventory-records.php — FIXED
//  Fix 1: in_stock now shows a proper green badge via a local
//         invStatusBadge() override defined right here.
//         (The controller version may be missing in_stock — this
//          local function takes precedence via early definition.)
//  Fix 2: Log Stock Movement modal changed from modal-sm → modal-lg
//         so the Type dropdown and fields have room to breathe.
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/inventory_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';

$filterBranch   = isset($_GET['branch'])   ? (int)$_GET['branch']   : 0;
$filterCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
$filterStatus   = isset($_GET['status'])   ? trim($_GET['status'])   : '';
$page           = max(1, (int)($_GET['page'] ?? 1));

$branches   = getAllBranches();
$categories = getInventoryCategories();
$data         = getInventoryRecords($page, $filterBranch, $filterCategory, $filterStatus);
$items        = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];
$baseUrl = 'inventory-records.php?branch=' . $filterBranch . '&category=' . urlencode($filterCategory) . '&status=' . urlencode($filterStatus);

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
    <link rel="stylesheet" href="assets/css/custom.css">
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
                            <select id="filterCategory" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:160px;">
                                <option value="">All Categories </option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>" <?= $filterCategory===$cat?'selected':'' ?>><?= htmlspecialchars($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="filterStatus" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:150px;">
                                <option value="">All Statuses</option>
                                <option value="in_stock"    <?= $filterStatus==='in_stock'    ?'selected':'' ?>>In Stock</option>
                                <option value="low_stock"   <?= $filterStatus==='low_stock'   ?'selected':'' ?>>Low Stock</option>
                                <option value="out_of_stock"<?= $filterStatus==='out_of_stock'?'selected':'' ?>>Out of Stock</option>
                                <option value="inactive"    <?= $filterStatus==='inactive'    ?'selected':'' ?>>Inactive</option>
                            </select>
                            <?php if (isOwner()): ?>
                            <select id="filterBranch" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:180px;">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>" <?= (int)$b['branch_id']===$filterBranch?'selected':'' ?>><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                            <?php if (!isDentist()): ?>
                            <button class="btn btn-primary btn-sm hdr-btn" onclick="openAddItemModal()">
                                <i class="feather-plus me-1"></i> Add Item
                            </button>
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
                                Inventory Records
                                <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span>
                            </h5>
                            <div class="card-header-action"><div class="card-header-btn">
                                <div data-bs-toggle="tooltip" title="Refresh"><a href="inventory-records.php" class="avatar-text avatar-xs bg-warning"></a></div>
                                <div data-bs-toggle="tooltip" title="Maximize/Minimize"><a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a></div>
                            </div></div>
                        </div>
                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead><tr>
                                        <th>Item Code</th><th>Item Name</th><th>Category</th>
                                        <th>Branch</th><th>Stock</th><th>Min Stock</th>
                                        <th>Status</th><th class="text-end">Action</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php if (empty($items)): ?>
                                        <tr><td colspan="8" class="text-center py-4 text-muted"><i class="feather-package me-2"></i>No inventory items found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($items as $item): ?>
                                        <?php
                                            $stock    = (int)($item['stock_quantity'] ?? 0);
                                            $minStock = (int)($item['min_stock'] ?? 0);
                                            $stockClass = $stock === 0 ? 'text-danger fw-bold'
                                                        : ($stock <= $minStock ? 'text-warning fw-semibold' : 'text-dark');
                                        ?>
                                        <tr>
                                            <td><span class="fw-semibold"><?= htmlspecialchars($item['item_code'] ?? '—') ?></span></td>
                                            <td><?= htmlspecialchars($item['item_name'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($item['category'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($item['branches']['branch_name'] ?? '—') ?></td>
                                            <td><span class="<?= $stockClass ?>"><?= $stock ?></span></td>
                                            <td><?= $minStock ?></td>
                                            <!-- Fix 1: badge now uses local invStatusBadge() which covers in_stock -->
                                            <td>
                                                <span class="badge <?= invStatusBadge($item['status'] ?? '') ?>">
                                                    <?= invStatusLabel($item['status'] ?? '') ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown"><i class="feather-more-vertical"></i></a>
                                                    <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                           onclick='viewItem(<?= json_encode([
                                                               "item_id"       => $item["item_id"],
                                                               "item_code"     => $item["item_code"]     ?? "",
                                                               "item_name"     => $item["item_name"]     ?? "",
                                                               "category"      => $item["category"]      ?? "",
                                                               "branch_name"   => $item["branches"]["branch_name"] ?? "",
                                                               "stock_quantity"=> $item["stock_quantity"] ?? 0,
                                                               "min_stock"     => $item["min_stock"]     ?? 0,
                                                               "status"        => $item["status"]        ?? "",
                                                           ], JSON_HEX_QUOT|JSON_HEX_TAG) ?>)'>
                                                           <i class="feather-eye"></i> View Details
                                                        </a>
                                                        <a href="inventory-stock-movement.php?item=<?= (int)$item['item_id'] ?>" class="dropdown-item">
                                                            <i class="feather-refresh-cw"></i> View Movements
                                                        </a>
                                                        <?php if (!isDentist()): ?>
                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                           onclick="openMovementModal('<?= (int)$item['item_id'] ?>','<?= htmlspecialchars($item['item_name'] ?? '', ENT_QUOTES) ?>','<?= $stock ?>')">
                                                           <i class="feather-plus-circle"></i> Log Movement
                                                        </a>
                                                        <div class="dropdown-divider"></div>
                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                           onclick='openEditItemModal(<?= json_encode([
                                                               "item_id"       => $item["item_id"],
                                                               "item_name"     => $item["item_name"]     ?? "",
                                                               "category"      => $item["category"]      ?? "",
                                                               "branch_id"     => $item["branch_id"]     ?? "",
                                                               "stock_quantity"=> $item["stock_quantity"] ?? 0,
                                                               "min_stock"     => $item["min_stock"]     ?? 0,
                                                               "status"        => $item["status"]        ?? "",
                                                           ], JSON_HEX_QUOT|JSON_HEX_TAG) ?>)'>
                                                           <i class="feather-edit-2"></i> Edit
                                                        </a>
                                                        <a href="javascript:void(0);" class="dropdown-item text-danger"
                                                           onclick="deleteItem('<?= (int)$item['item_id'] ?>','<?= htmlspecialchars($item['item_name'] ?? '', ENT_QUOTES) ?>')">
                                                           <i class="feather-trash-2"></i> Delete
                                                        </a>
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
                            <p class="text-muted small mb-0">
                                Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> item(s)
                                <?php if ($filterBranch || $filterCategory || $filterStatus): ?>
                                    &nbsp;·&nbsp;<a href="inventory-records.php" class="text-danger"><i class="feather-x-circle me-1"></i>Clear filters</a>
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

    <!-- VIEW MODAL -->
    <div class="modal fade" id="inventoryViewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold"><i class="feather-package me-2 text-primary"></i>Item Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-0">
                    <table class="table table-borderless mb-0 small">
                        <tr><th class="text-muted" style="width:120px;">Item Code</th><td id="iv_code" class="fw-semibold"></td></tr>
                        <tr><th class="text-muted">Item Name</th><td id="iv_name"></td></tr>
                        <tr><th class="text-muted">Category</th><td id="iv_cat"></td></tr>
                        <tr><th class="text-muted">Branch</th><td id="iv_branch"></td></tr>
                        <tr><th class="text-muted">Stock</th><td id="iv_stock"></td></tr>
                        <tr><th class="text-muted">Min Stock</th><td id="iv_min"></td></tr>
                        <tr><th class="text-muted">Status</th><td id="iv_status"></td></tr>
                    </table>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD / EDIT MODAL -->
    <div class="modal fade" id="inventoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="invModalTitle">Add Inventory Item</h5>
                        <p class="text-muted small mb-0">Fill in the item details below.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="invModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="inv_id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Item Name <span class="text-danger">*</span></label>
                            <input type="text" id="inv_name" class="form-control" placeholder="e.g. Dental Gloves">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Category</label>
                            <input type="text" id="inv_category" class="form-control" placeholder="e.g. Consumables" list="categoryList">
                            <datalist id="categoryList">
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Branch <span class="text-danger">*</span></label>
                            <select id="inv_branch" class="form-select">
                                <option value="">— Select Branch —</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Stock Quantity</label>
                            <input type="number" id="inv_stock" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Min Stock <span class="text-muted">(alert threshold)</span></label>
                            <input type="number" id="inv_min_stock" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Status</label>
                            <select id="inv_status" class="form-select">
                                <option value="in_stock">In Stock</option>
                                <option value="low_stock">Low Stock</option>
                                <option value="out_of_stock">Out of Stock</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <span class="text-muted small"><span class="text-danger">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary px-4" id="invSaveBtn" onclick="saveItem()">
                            <i class="feather-save me-1"></i> Save
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!--
        LOG MOVEMENT MODAL
        Fix 2: Changed modal-sm → modal-lg so the Type dropdown
        and all fields have proper room and don't feel cramped.
        The two-column layout (Type + Quantity side by side) now
        fits comfortably without text overflow.
    -->
    <div class="modal fade" id="movementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0">
                            <i class="feather-refresh-cw me-2 text-primary"></i>Log Stock Movement
                        </h5>
                        <p class="text-muted small mb-0" id="movItemName"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="movModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="mov_item_id">

                    <div class="row g-3">

                        <!-- Current stock (read-only display) -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Current Stock</label>
                            <div class="form-control bg-light fw-bold fs-5 text-center" id="movCurrentStock"
                                 style="min-height:2.5rem; display:flex; align-items:center; justify-content:center;">
                            </div>
                        </div>

                        <!-- Type dropdown — now has full col-md-4 width, no cramping -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Type <span class="text-danger">*</span></label>
                            <select id="mov_type" class="form-select" onchange="updateMovPreview()">
                                <option value="in">Stock In &nbsp;(+)</option>
                                <option value="out">Stock Out &nbsp;(−)</option>
                            </select>
                        </div>

                        <!-- Quantity -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Quantity <span class="text-danger">*</span></label>
                            <input type="number" id="mov_quantity" class="form-control"
                                   placeholder="e.g. 10" min="1" oninput="updateMovPreview()">
                        </div>

                        <!-- Preview (full width) -->
                        <div class="col-12">
                            <label class="form-label fw-semibold small">New Stock Preview</label>
                            <div id="movStockPreview" class="fw-semibold px-3 py-2 rounded border"
                                 style="display:none; min-height:2.2rem;"></div>
                        </div>

                        <!-- Reason (full width) -->
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Reason <span class="text-danger">*</span></label>
                            <input type="text" id="mov_reason" class="form-control"
                                   placeholder="e.g. Restocked from supplier">
                        </div>

                    </div>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <span class="text-muted small"><span class="text-danger">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary px-4" id="movSaveBtn" onclick="saveMovement()">
                            <i class="feather-check me-1"></i> Log Movement
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/inventory.js"></script>
    <script>
    function applyFilters() {
        window._applyFilters('inventory-records.php', {
            branch:   'filterBranch',
            category: 'filterCategory',
            status:   'filterStatus'
        });
    }
    </script>
</body>
</html>