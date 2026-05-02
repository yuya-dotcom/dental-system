<?php
define('REQUIRED_ROLES', ['owner']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/dentist_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';

$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : getSessionBranch();
if (!isOwner() && $filterBranch !== getSessionBranch()) $filterBranch = getSessionBranch();
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$branches     = getAllBranches();
$data         = getDentistRecords($page, $filterBranch, $filterStatus);
$dentists     = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];
$baseUrl      = 'dentist-records.php?branch=' . $filterBranch . '&status=' . urlencode($filterStatus);
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Dentists</title>
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
                    <div class="page-header-title"><h5 class="m-b-10">Dentists</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Records</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <select id="filterStatus" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:150px;">
                                <option value="">All Statuses</option>
                                <option value="active"   <?= $filterStatus==='active'   ? 'selected':'' ?>>Active</option>
                                <option value="inactive" <?= $filterStatus==='inactive' ? 'selected':'' ?>>Inactive</option>
                                <option value="on_leave" <?= $filterStatus==='on_leave' ? 'selected':'' ?>>On Leave</option>
                            </select>
                            <?php if (isOwner()): ?>
                            <select id="filterBranch" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:180px;">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>" <?= (int)$b['branch_id']===$filterBranch?'selected':'' ?>><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                            <button class="btn btn-primary btn-sm hdr-btn" onclick="openAddDentistModal()">
                                <i class="feather-plus me-1"></i> Add Dentist
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="main-content">
                <div class="row"><div class="col-lg-12">
                    <div class="card stretch stretch-full">
                        <div class="card-header">
                            <h5 class="card-title">Dentists Records <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span></h5>
                            <div class="card-header-action"><div class="card-header-btn">
                                <div data-bs-toggle="tooltip" title="Refresh"><a href="dentist-records.php" class="avatar-text avatar-xs bg-warning"></a></div>
                                <div data-bs-toggle="tooltip" title="Maximize/Minimize"><a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a></div>
                            </div></div>
                        </div>
                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive" style="overflow:visible;">
                                <table class="table table-hover mb-0">
                                    <thead><tr>
                                        <th>#</th><th>Full Name</th><th>Specialization</th>
                                        <th>Contact</th><th>Branch</th><th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php if (empty($dentists)): ?>
                                        <tr><td colspan="7" class="text-center py-4 text-muted"><i class="feather-user me-2"></i>No dentist records found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($dentists as $i => $d): ?>
                                        <tr>
                                            <td class="text-muted"><?= $from + $i ?></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="avatar-text avatar-sm bg-soft-primary text-primary"><?= strtoupper(substr($d['full_name']??'D',0,1)) ?></div>
                                                    <span class="fw-semibold"><?= htmlspecialchars($d['full_name'] ?? '—') ?></span>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars($d['specialization'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($d['contact_number'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($d['branches']['branch_name'] ?? '—') ?></td>
                                            <td><span class="badge <?= dentStatusBadge($d['status']) ?>"><?= dentStatusLabel($d['status']) ?></span></td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed"><i class="feather-more-vertical"></i></a>
                                                    <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                           onclick='viewDentist(<?= json_encode(["dentist_id"=>$d["dentist_id"],"full_name"=>$d["full_name"]??"","specialization"=>$d["specialization"]??"","contact_number"=>$d["contact_number"]??"","branch_name"=>$d["branches"]["branch_name"]??"","status"=>$d["status"]??""],JSON_HEX_QUOT|JSON_HEX_TAG) ?>)'>
                                                           <i class="feather-eye"></i> View Details
                                                        </a>
                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                           onclick='openEditDentistModal(<?= json_encode(["dentist_id"=>$d["dentist_id"],"full_name"=>$d["full_name"]??"","specialization"=>$d["specialization"]??"","contact_number"=>$d["contact_number"]??"","branch_id"=>$d["branch_id"]??"","status"=>$d["status"]??""],JSON_HEX_QUOT|JSON_HEX_TAG) ?>)'>
                                                           <i class="feather-edit-2"></i> Edit
                                                        </a>
                                                        <div class="dropdown-divider"></div>
                                                        <a href="javascript:void(0);" class="dropdown-item text-danger"
                                                           onclick="deleteDentist('<?= $d['dentist_id'] ?>','<?= htmlspecialchars($d['full_name']??'',ENT_QUOTES) ?>')">
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
                            <p class="text-muted small mb-0">
                                Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> dentist(s)
                                <?php if ($filterBranch || $filterStatus): ?>&nbsp;·&nbsp;<a href="dentist-records.php" class="text-danger"><i class="feather-x-circle me-1"></i>Clear filters</a><?php endif; ?>
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
    <div class="modal fade" id="dentistViewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold"><i class="feather-user me-2 text-primary"></i>Dentist Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-0">
                    <table class="table table-borderless mb-0 small">
                        <tr><th class="text-muted" style="width:130px;">Full Name</th><td id="dv_name" class="fw-semibold"></td></tr>
                        <tr><th class="text-muted">Specialization</th><td id="dv_spec"></td></tr>
                        <tr><th class="text-muted">Contact</th><td id="dv_contact"></td></tr>
                        <tr><th class="text-muted">Branch</th><td id="dv_branch"></td></tr>
                        <tr><th class="text-muted">Status</th><td id="dv_status"></td></tr>
                    </table>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD/EDIT MODAL -->
    <div class="modal fade" id="dentistModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="dntModalTitle">Add Dentist</h5>
                        <p class="text-muted small mb-0">Fill in the dentist's information below.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="dntModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="dnt_id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
                            <input type="text" id="dnt_name" class="form-control" placeholder="e.g. Dr. Juan Dela Cruz">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Specialization</label>
                            <input type="text" id="dnt_spec" class="form-control" placeholder="e.g. Orthodontics">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Contact Number</label>
                            <input type="text" id="dnt_contact" class="form-control" placeholder="+63 9XX XXX XXXX">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Branch <span class="text-danger">*</span></label>
                            <select id="dnt_branch" class="form-select">
                                <option value="">— Select Branch —</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Status</label>
                            <select id="dnt_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="on_leave">On Leave</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <span class="text-muted small"><span class="text-danger">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary px-4" id="dntSaveBtn" onclick="saveDentist()">
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
    <script src="assets/js/dentists.js"></script>
    <script>
    function applyFilters() {
        window._applyFilters('dentist-records.php', { branch: 'filterBranch', status: 'filterStatus' });
    }
    </script>
</body>
</html>