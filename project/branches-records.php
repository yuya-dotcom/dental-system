<?php
define('REQUIRED_ROLES', ['owner']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';

$page         = max(1, (int)($_GET['page'] ?? 1));
$data         = getBranchRecords($page);
$branches_tbl = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];
$baseUrl      = 'branches-records.php';
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Branches</title>
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
                    <div class="page-header-title"><h5 class="m-b-10">Branches</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Records</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <button class="btn btn-primary btn-sm" onclick="openAddBranchModal()">
                            <i class="feather-plus me-1"></i> Add Branch
                        </button>
                    </div>
                </div>
            </div>
            <div class="main-content">
                <div class="row"><div class="col-lg-12">
                    <div class="card stretch stretch-full">
                        <div class="card-header">
                            <h5 class="card-title">Branches Records <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span></h5>
                            <div class="card-header-action"><div class="card-header-btn">
                                <div data-bs-toggle="tooltip" title="Refresh"><a href="branches-records.php" class="avatar-text avatar-xs bg-warning"></a></div>
                                <div data-bs-toggle="tooltip" title="Maximize/Minimize"><a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a></div>
                            </div></div>
                        </div>
                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive" style="overflow:visible;">
                                <table class="table table-hover mb-0">
                                    <thead><tr>
                                        <th>Branch Code</th><th>Branch Name</th><th>Address</th>
                                        <th>Contact</th><th>Operating Hours</th><th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php if (empty($branches_tbl)): ?>
                                        <tr><td colspan="7" class="text-center py-4 text-muted"><i class="feather-map-pin me-2"></i>No branches found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($branches_tbl as $b): ?>
                                        <tr>
                                            <td><span class="fw-semibold"><?= htmlspecialchars($b['branch_code'] ?? '—') ?></span></td>
                                            <td><?= htmlspecialchars($b['branch_name'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($b['address'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($b['contact_number'] ?? '—') ?></td>
                                            <td><?php
                                                $o = $b['open_time'] ?? null; $c = $b['close_time'] ?? null;
                                                echo ($o && $c) ? date('g:i A', strtotime($o)) . ' – ' . date('g:i A', strtotime($c)) : '—';
                                            ?></td>
                                            <td><span class="badge <?= branchStatusBadge($b['status']) ?>"><?= ucfirst($b['status'] ?? 'active') ?></span></td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed"><i class="feather-more-vertical"></i></a>
                                                    <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                           onclick='viewBranch(<?= json_encode(["branch_id"=>$b["branch_id"],"branch_code"=>$b["branch_code"]??"","branch_name"=>$b["branch_name"]??"","address"=>$b["address"]??"","contact_number"=>$b["contact_number"]??"","open_time"=>$b["open_time"]??"","close_time"=>$b["close_time"]??"","status"=>$b["status"]??""],JSON_HEX_QUOT|JSON_HEX_TAG) ?>)'>
                                                           <i class="feather-eye"></i> View Details
                                                        </a>
                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                           onclick='openEditBranchModal(<?= json_encode(["branch_id"=>$b["branch_id"],"branch_name"=>$b["branch_name"]??"","address"=>$b["address"]??"","contact_number"=>$b["contact_number"]??"","open_time"=>$b["open_time"]??"","close_time"=>$b["close_time"]??"","status"=>$b["status"]??""],JSON_HEX_QUOT|JSON_HEX_TAG) ?>)'>
                                                           <i class="feather-edit-2"></i> Edit
                                                        </a>
                                                        <div class="dropdown-divider"></div>
                                                        <a href="javascript:void(0);" class="dropdown-item text-danger"
                                                           onclick="deleteBranch('<?= $b['branch_id'] ?>','<?= htmlspecialchars($b['branch_name']??'',ENT_QUOTES) ?>')">
                                                           <i class="feather-trash-2"></i> Delete
                                                        </a>
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
                            <p class="text-muted small mb-0">Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> branch(es)</p>
                            <?php if ($totalPages > 1): ?>
                            <ul class="list-unstyled d-flex align-items-center gap-1 mb-0 pagination-common-style">
                                <li><?php if ($page>1): ?><a href="<?= $baseUrl ?>?page=<?= $page-1 ?>"><i class="bi bi-arrow-left"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-left"></i></a><?php endif; ?></li>
                                <?php $wS=max(1,$page-2);$wE=min($totalPages,$wS+4);$wS=max(1,$wE-4);for($pg=$wS;$pg<=$wE;$pg++): ?><li><a href="<?= $baseUrl ?>?page=<?= $pg ?>" class="<?= $pg===$page?'active':'' ?>"><?= $pg ?></a></li><?php endfor; ?>
                                <li><?php if ($page<$totalPages): ?><a href="<?= $baseUrl ?>?page=<?= $page+1 ?>"><i class="bi bi-arrow-right"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-right"></i></a><?php endif; ?></li>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div></div>
            </div>
        </div>
    </main>

    <!-- VIEW MODAL -->
    <div class="modal fade" id="branchViewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold"><i class="feather-map-pin me-2 text-primary"></i>Branch Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-0">
                    <table class="table table-borderless mb-0 small">
                        <tr><th class="text-muted" style="width:140px;">Branch Code</th><td id="bv_code" class="fw-semibold"></td></tr>
                        <tr><th class="text-muted">Branch Name</th><td id="bv_name"></td></tr>
                        <tr><th class="text-muted">Address</th><td id="bv_address"></td></tr>
                        <tr><th class="text-muted">Contact</th><td id="bv_contact"></td></tr>
                        <tr><th class="text-muted">Hours</th><td id="bv_hours"></td></tr>
                        <tr><th class="text-muted">Status</th><td id="bv_status"></td></tr>
                    </table>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD/EDIT MODAL -->
    <div class="modal fade" id="branchModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="brnModalTitle">Add Branch</h5>
                        <p class="text-muted small mb-0">Fill in the branch details below.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="brnModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="brn_id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Branch Name <span class="text-danger">*</span></label>
                            <input type="text" id="brn_name" class="form-control" placeholder="e.g. Main Branch">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Address</label>
                            <input type="text" id="brn_address" class="form-control" placeholder="Full address">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Contact Number</label>
                            <input type="text" id="brn_contact" class="form-control" placeholder="+63 2 XXXX XXXX">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Open Time</label>
                            <input type="time" id="brn_open" class="form-control" value="09:00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Close Time</label>
                            <input type="time" id="brn_close" class="form-control" value="17:00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Status</label>
                            <select id="brn_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <span class="text-muted small"><span class="text-danger">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary px-4" id="brnSaveBtn" onclick="saveBranch()">
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/branches.js"></script>
</body>
</html>