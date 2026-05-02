<?php
// =============================================================
//  treatments-active.php  — COMPLETE FIXED FILE
//  Fix: patient name column is now a clickable link to
//       patient-records.php?id={patient_id}
//  All modals (View Details, Work Modal, Finish Confirm) included.
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/treatment_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';

$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : getSessionBranch();
if (!isOwner() && $filterBranch !== getSessionBranch()) $filterBranch = getSessionBranch();
$page = max(1, (int)($_GET['page'] ?? 1));

$branches     = getAllBranches();
$data         = getActiveTreatments($page, $filterBranch);
$treatments   = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];

$baseUrl = 'treatments-active.php?branch=' . $filterBranch;
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Active Treatments</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        .tooth-btn {
            width: 28px; height: 28px; border-radius: 4px;
            border: 1.5px solid #ced4da; background: #fff;
            font-size: 10px; font-weight: 600; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all .15s; color: #555; line-height: 1; padding: 0;
        }
        .tooth-btn:hover  { border-color: #0d6efd; color: #0d6efd; background: #e8f0fe; }
        .tooth-btn.active { border-color: #0d6efd; background: #0d6efd; color: #fff; }
        #tooth_chart { background: #fafafa; }
    </style>
</head>
<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>

    <main class="nxl-container">
        <div class="nxl-content">

            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">Treatments</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Active</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <?php if (isOwner()): ?>
                            <select id="filterBranch" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:180px;">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>" <?= (int)$b['branch_id']===$filterBranch ? 'selected':'' ?>>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-content">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title">
                                    Active Treatments
                                    <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span>
                                </h5>
                                <div class="card-header-action">
                                    <div class="card-header-btn">
                                        <div data-bs-toggle="tooltip" title="Refresh">
                                            <a href="treatments-active.php" class="avatar-text avatar-xs bg-warning"></a>
                                        </div>
                                        <div data-bs-toggle="tooltip" title="Maximize/Minimize">
                                            <a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card-body custom-card-action p-0">
                                <div class="table-responsive" style="overflow:visible;">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Treatment ID</th>
                                                <th>Date</th>
                                                <th>Patient Name</th>
                                                <th>Branch</th>
                                                <th>Dentist</th>
                                                <th>Service</th>
                                                <th>Tooth No.</th>
                                                <th>Cost</th>
                                                <th>Status</th>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($treatments)): ?>
                                            <tr>
                                                <td colspan="10" class="text-center py-5 text-muted">
                                                    <i class="feather-activity me-2 fs-4 d-block mb-2"></i>
                                                    No active treatments at the moment.<br>
                                                    <small>Patients appear here after being checked in at the appointment desk.</small>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($treatments as $t): ?>
                                            <tr>
                                                <td><span class="fw-semibold"><?= htmlspecialchars($t['treatment_code'] ?? '—') ?></span></td>
                                                <td><?= formatTrtDate($t['treatment_date'] ?? null) ?></td>
                                                <td>
                                                    <!-- ★ FIX: patient name is now a clickable link -->
                                                    <div class="hstack gap-2">
                                                        <div class="avatar-text avatar-sm bg-soft-primary text-primary rounded-circle">
                                                            <?= strtoupper(substr($t['patients']['full_name'] ?? 'P', 0, 1)) ?>
                                                        </div>
                                                        <a href="patient-records.php?id=<?= (int)($t['patient_id'] ?? 0) ?>"
                                                           class="text-primary fw-semibold text-decoration-none">
                                                            <?= htmlspecialchars($t['patients']['full_name'] ?? '—') ?>
                                                        </a>
                                                    </div>
                                                </td>
                                                <td><?= htmlspecialchars($t['branches']['branch_name'] ?? '—') ?></td>
                                                <td><?= htmlspecialchars($t['dentists']['full_name'] ?? '—') ?></td>
                                                <td><?= htmlspecialchars($t['services']['service_name'] ?? '—') ?></td>
                                                <td><?= htmlspecialchars($t['tooth_number'] ?? '—') ?></td>
                                                <td><?= formatCost($t['cost'] ?? null) ?></td>
                                                <td>
                                                    <span class="badge <?= trtStatusBadge($t['status']) ?>">
                                                        <?= trtStatusLabel($t['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="dropdown">
                                                        <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" overflow:visible;">
                                                            <i class="feather-more-vertical"></i>
                                                        </a>
                                                        <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                            <a href="javascript:void(0);" class="dropdown-item text-primary"
                                                               onclick='openWorkModal(<?= json_encode([
                                                                   "treatment_id"    => $t["treatment_id"],
                                                                   "treatment_code"  => $t["treatment_code"] ?? "",
                                                                   "appointment_id"  => $t["appointment_id"] ?? null,
                                                                   "patient_id"      => $t["patient_id"] ?? "",
                                                                   "patient_name"    => $t["patients"]["full_name"] ?? "—",
                                                                   "branch_id"       => $t["branch_id"] ?? "",
                                                                   "branch_name"     => $t["branches"]["branch_name"] ?? "—",
                                                                   "dentist_id"      => $t["dentist_id"] ?? "",
                                                                   "dentist_name"    => $t["dentists"]["full_name"] ?? "—",
                                                                   "service_id"      => $t["service_id"] ?? "",
                                                                   "service_name"    => $t["services"]["service_name"] ?? "—",
                                                                   "tooth_number"    => $t["tooth_number"] ?? "",
                                                                   "procedure_notes" => $t["procedure_notes"] ?? "",
                                                                   "cost"            => $t["cost"] ?? "",
                                                                   "current_stage"   => $t["current_stage"] ?? "",
                                                                   "status"          => $t["status"] ?? "pending",
                                                               ], JSON_HEX_QUOT|JSON_HEX_TAG) ?>)'>
                                                               <i class="feather-edit-2"></i>
                                                               <?= in_array($t['status'], ['pending','ongoing']) ? 'Start Treatment' : 'Update Treatment' ?>
                                                            </a>
                                                            <a href="javascript:void(0);" class="dropdown-item"
                                                               onclick='viewTreatment(<?= json_encode([
                                                                   "treatment_code"  => $t["treatment_code"] ?? "",
                                                                   "treatment_date"  => formatTrtDate($t["treatment_date"] ?? null),
                                                                   "patient_name"    => $t["patients"]["full_name"] ?? "—",
                                                                   "branch_name"     => $t["branches"]["branch_name"] ?? "—",
                                                                   "dentist_name"    => $t["dentists"]["full_name"] ?? "—",
                                                                   "service_name"    => $t["services"]["service_name"] ?? "—",
                                                                   "tooth_number"    => $t["tooth_number"] ?? "—",
                                                                   "cost"            => formatCost($t["cost"] ?? null),
                                                                   "current_stage"   => $t["current_stage"] ?? "—",
                                                                   "procedure_notes" => $t["procedure_notes"] ?? "",
                                                                   "status"          => trtStatusLabel($t["status"]),
                                                               ], JSON_HEX_QUOT|JSON_HEX_TAG) ?>)'>
                                                               <i class="feather-eye"></i> View Details
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
                                    Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> record(s)
                                </p>
                                <?php if ($totalPages > 1): ?>
                                <ul class="list-unstyled d-flex align-items-center gap-1 mb-0 pagination-common-style">
                                    <li><?php if ($page>1): ?><a href="<?= $baseUrl ?>&page=<?= $page-1 ?>"><i class="bi bi-arrow-left"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-left"></i></a><?php endif; ?></li>
                                    <?php $wS=max(1,$page-2);$wE=min($totalPages,$wS+4);$wS=max(1,$wE-4); ?>
                                    <?php if ($wS>1): ?><li><a href="<?= $baseUrl ?>&page=1">1</a></li><?php if ($wS>2): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><?php endif; ?>
                                    <?php for ($p=$wS;$p<=$wE;$p++): ?><li><a href="<?= $baseUrl ?>&page=<?= $p ?>" class="<?= $p===$page?'active':'' ?>"><?= $p ?></a></li><?php endfor; ?>
                                    <?php if ($wE<$totalPages): ?><?php if ($wE<$totalPages-1): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><li><a href="<?= $baseUrl ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a></li><?php endif; ?>
                                    <li><?php if ($page<$totalPages): ?><a href="<?= $baseUrl ?>&page=<?= $page+1 ?>"><i class="bi bi-arrow-right"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-right"></i></a><?php endif; ?></li>
                                </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ============================================================
         VIEW DETAILS MODAL
    ============================================================ -->
    <div class="modal fade" id="treatmentViewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold"><i class="feather-activity me-2 text-primary"></i>Treatment Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-0">
                    <table class="table table-borderless mb-0 small">
                        <tr><th class="text-muted" style="width:130px;">Treatment ID</th><td id="tv_code" class="fw-semibold"></td></tr>
                        <tr><th class="text-muted">Date</th><td id="tv_date"></td></tr>
                        <tr><th class="text-muted">Patient</th><td id="tv_patient"></td></tr>
                        <tr><th class="text-muted">Branch</th><td id="tv_branch"></td></tr>
                        <tr><th class="text-muted">Dentist</th><td id="tv_dentist"></td></tr>
                        <tr><th class="text-muted">Service</th><td id="tv_service"></td></tr>
                        <tr><th class="text-muted">Tooth No.</th><td id="tv_tooth"></td></tr>
                        <tr><th class="text-muted">Cost</th><td id="tv_cost"></td></tr>
                        <tr><th class="text-muted">Stage</th><td id="tv_stage"></td></tr>
                        <tr><th class="text-muted">Status</th><td id="tv_status"></td></tr>
                        <tr><th class="text-muted">Notes</th><td id="tv_procedure"></td></tr>
                    </table>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         WORK ON TREATMENT MODAL
    ============================================================ -->
    <div class="modal fade" id="workModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="workModalTitle">Start Treatment</h5>
                        <p class="text-muted small mb-0" id="workModalSubtitle"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="workModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>

                    <!-- Hidden state fields -->
                    <input type="hidden" id="work_treatment_id">
                    <input type="hidden" id="work_appointment_id">
                    <input type="hidden" id="work_patient_id">
                    <input type="hidden" id="work_branch_id">
                    <input type="hidden" id="work_dentist_id">
                    <input type="hidden" id="work_service_id">
                    <input type="hidden" id="work_patient_name">
                    <input type="hidden" id="work_service_name">
                    <input type="hidden" id="work_dentist_name">
                    <input type="hidden" id="work_branch_name">

                    <div class="row g-3">

                        <!-- Service (read-only display) -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Service / Procedure</label>
                            <input type="text" id="work_service_display" class="form-control bg-light" readonly>
                        </div>

                        <!-- Dentist (required) -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Dentist <span class="text-danger">*</span></label>
                            <select id="work_dentist_select" class="form-select">
                                <option value="">— Select Dentist —</option>
                            </select>
                        </div>

                        <!-- Procedure Notes -->
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Procedure Notes <span class="text-danger">*</span></label>
                            <textarea id="work_procedure_notes" class="form-control" rows="2"
                                placeholder="Describe what was performed e.g. Extraction of lower left molar..."></textarea>
                        </div>

                        <!-- Tooth Chart -->
                        <div class="col-12">
                            <label class="form-label fw-semibold small">
                                Tooth Number(s)
                                <span class="text-muted fw-normal small">— click to select affected teeth</span>
                            </label>
                            <input type="hidden" id="work_tooth_number">
                            <div id="tooth_chart" class="border rounded p-2">
                                <div class="text-center text-muted small mb-1 fw-semibold">MAXILLA (Upper)</div>
                                <div class="d-flex justify-content-center gap-1 flex-wrap mb-1" id="upper_teeth"></div>
                                <div class="d-flex justify-content-center gap-1 flex-wrap mt-1" id="lower_teeth"></div>
                                <div class="text-center text-muted small mt-1 fw-semibold">MANDIBLE (Lower)</div>
                            </div>
                            <div class="mt-1">
                                <small class="text-muted" id="tooth_selected_label">No teeth selected</small>
                                <a href="javascript:void(0);" class="text-danger small ms-2" onclick="clearToothSelection()" id="tooth_clear_btn" style="display:none;">
                                    <i class="feather-x-circle me-1"></i>Clear
                                </a>
                            </div>
                        </div>

                        <!-- Cost + Stage -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">
                                Cost <span class="text-danger">*</span>
                                <span class="text-muted fw-normal">(auto-calculated, editable)</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" id="work_cost" class="form-control" placeholder="0.00" min="0" step="0.01">
                            </div>
                            <div class="form-text text-muted" id="work_cost_hint"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Current Stage</label>
                            <input type="text" id="work_current_stage" class="form-control" placeholder="e.g. In Progress">
                        </div>

                        <!-- Materials Section -->
                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="form-label fw-semibold small mb-0">
                                    <i class="feather-package me-1 text-primary"></i>
                                    Materials Used
                                    <span class="text-muted fw-normal">(auto-filled, editable)</span>
                                </label>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="openAddWorkMaterial()">
                                    <i class="feather-plus me-1"></i> Add Item
                                </button>
                            </div>
                            <div id="work_materials_loading" class="text-center py-2 text-muted small">
                                <span class="spinner-border spinner-border-sm me-1"></span> Loading materials...
                            </div>
                            <div class="table-responsive border rounded" id="work_materials_table" style="display:none;">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Item</th>
                                            <th>In Stock</th>
                                            <th>Qty Used</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="work_materials_body">
                                        <tr id="work_no_materials">
                                            <td colspan="4" class="text-center py-3 text-muted small">
                                                <i class="feather-info me-1"></i>No materials configured for this service.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div id="work_add_material_row" class="mt-2 p-2 border rounded bg-light" style="display:none;">
                                <div class="d-flex gap-2 align-items-center flex-wrap">
                                    <select id="work_add_item" class="form-select form-select-sm" style="max-width:280px;">
                                        <option value="">— Select Item —</option>
                                    </select>
                                    <input type="number" id="work_add_qty" class="form-control form-control-sm"
                                        placeholder="Qty" min="1" value="1" style="max-width:70px;">
                                    <button class="btn btn-sm btn-primary" onclick="addWorkMaterial()">
                                        <i class="feather-check"></i> Add
                                    </button>
                                    <button class="btn btn-sm btn-light" onclick="closeAddWorkMaterial()">
                                        <i class="feather-x"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <span class="text-muted small"><span class="text-danger">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-success px-4" id="finishTreatmentBtn" onclick="openFinishConfirmModal()">
                            <i class="feather-check-circle me-1"></i> Finish Treatment
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         FINISH TREATMENT CONFIRMATION MODAL
    ============================================================ -->
    <div class="modal fade" id="finishConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0 text-success">
                            <i class="feather-check-circle me-2"></i>Confirm Treatment Completion
                        </h5>
                        <p class="text-muted small mb-0">Please review before finalizing.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="finishConfirmError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>

                    <div class="card border-0 bg-light mb-3">
                        <div class="card-body p-3">
                            <table class="table table-borderless table-sm mb-0 small">
                                <tr><th class="text-muted" style="width:110px;">Patient</th><td id="fc_patient" class="fw-semibold"></td></tr>
                                <tr><th class="text-muted">Service</th><td id="fc_service"></td></tr>
                                <tr><th class="text-muted">Procedure Notes</th><td id="fc_procedure"></td></tr>
                                <tr><th class="text-muted">Tooth No.</th><td id="fc_tooth"></td></tr>
                                <tr><th class="text-muted">Dentist</th><td id="fc_dentist"></td></tr>
                                <tr><th class="text-muted">Branch</th><td id="fc_branch"></td></tr>
                                <tr>
                                    <th class="text-muted fw-bold">Total Cost</th>
                                    <td><span class="fw-bold text-success" id="fc_cost"></span></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <p class="fw-semibold small mb-2"><i class="feather-package me-1"></i>Materials to be deducted:</p>
                    <div id="fc_materials_list" class="small text-muted mb-2">—</div>

                    <div class="alert alert-warning small py-2 px-3 mb-0">
                        <i class="feather-alert-triangle me-1"></i>
                        After confirmation: inventory will be deducted, an invoice will be created (status: Unpaid), and this treatment will move to Treatment Records.
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" onclick="backToWorkModal()">
                        <i class="feather-arrow-left me-1"></i> Back
                    </button>
                    <button type="button" class="btn btn-success px-4" id="confirmFinishBtn" onclick="confirmFinishTreatment()">
                        <i class="feather-check-circle me-1"></i> Confirm &amp; Finish
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/treatments-active.js"></script>
    <script>
        window._sessionBranchId = '<?= getSessionBranch() ?>';
        window._currentUser     = '<?= htmlspecialchars($currentUser['full_name'] ?? '', ENT_QUOTES) ?>';
        function applyFilters() {
            window._applyFilters('treatments-active.php', { 'branch': 'filterBranch' });
        }
    </script>
</body>
</html>