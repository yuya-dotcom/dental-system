<?php

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/treatment_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';

$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : getSessionBranch();
if (!isOwner() && $filterBranch !== getSessionBranch()) $filterBranch = getSessionBranch();
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$page         = max(1, (int)($_GET['page'] ?? 1));

$branches     = getAllBranches();
$data         = getTreatmentRecords($page, $filterBranch, $filterStatus);
$treatments   = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];
$baseUrl      = 'treatments-records.php?branch=' . $filterBranch . '&status=' . urlencode($filterStatus);
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Treatment Records</title>
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
                    <div class="page-header-title"><h5 class="m-b-10">Treatments</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Records</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <select id="filterStatus" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:150px;">
                                <option value="">All</option>
                                <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                            <?php if (isOwner()): ?>
                            <select id="filterBranch" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:180px;">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>" <?= (int)$b['branch_id'] === $filterBranch ? 'selected' : '' ?>>
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
                                    Treatment Records
                                    <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span>
                                </h5>
                                <div class="card-header-action">
                                    <div class="card-header-btn">
                                        <div data-bs-toggle="tooltip" title="Refresh">
                                            <a href="treatments-records.php" class="avatar-text avatar-xs bg-warning"></a>
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
                                                <th>Patient</th>
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
                                                    <td colspan="10" class="text-center py-4 text-muted">
                                                        <i class="feather-activity me-2"></i>No treatment records found.
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($treatments as $t): ?>
                                                <tr>
                                                    <td>
                                                        <span class="fw-semibold"><?= htmlspecialchars($t['treatment_code'] ?? '—') ?></span>
                                                    </td>
                                                    <td><?= formatTrtDate($t['treatment_date'] ?? null) ?></td>
                                                    <td>
                                                        <!-- Patient name → patient-records.php (correct path) -->
                                                        <a href="patient-records.php?id=<?= (int)($t['patient_id'] ?? 0) ?>"
                                                           class="text-primary fw-semibold">
                                                            <?= htmlspecialchars($t['patients']['full_name'] ?? '—') ?>
                                                        </a>
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
                                                            <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed">
                                                                <i class="feather-more-vertical"></i>
                                                            </a>
                                                            <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                                <a href="javascript:void(0);" class="dropdown-item"
                                                                    onclick='viewTreatment(<?= json_encode([
                                                                        "treatment_code" => $t["treatment_code"] ?? "",
                                                                        "treatment_date" => formatTrtDate($t["treatment_date"] ?? null),
                                                                        "patient_name"   => $t["patients"]["full_name"] ?? "—",
                                                                        "branch_name"    => $t["branches"]["branch_name"] ?? "—",
                                                                        "dentist_name"   => $t["dentists"]["full_name"] ?? "—",
                                                                        "service_name"   => $t["services"]["service_name"] ?? "—",
                                                                        "tooth_number"   => $t["tooth_number"] ?? "—",
                                                                        "cost"           => formatCost($t["cost"] ?? null),
                                                                        "current_stage"  => $t["current_stage"] ?? "—",
                                                                        "status"         => trtStatusLabel($t["status"]),
                                                                        "notes"          => $t["procedure_notes"] ?? "",
                                                                    ], JSON_HEX_QUOT | JSON_HEX_TAG) ?>)'>
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
                                    <?php if ($filterBranch || $filterStatus): ?>
                                        &nbsp;·&nbsp;<a href="treatments-records.php" class="text-danger">
                                            <i class="feather-x-circle me-1"></i>Clear filters
                                        </a>
                                    <?php endif; ?>
                                </p>
                                <?php if ($totalPages > 1): ?>
                                <ul class="list-unstyled d-flex align-items-center gap-1 mb-0 pagination-common-style">
                                    <li>
                                        <?php if ($page > 1): ?>
                                            <a href="<?= $baseUrl ?>&page=<?= $page - 1 ?>"><i class="bi bi-arrow-left"></i></a>
                                        <?php else: ?>
                                            <a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-left"></i></a>
                                        <?php endif; ?>
                                    </li>
                                    <?php $wS=max(1,$page-2);$wE=min($totalPages,$wS+4);$wS=max(1,$wE-4); ?>
                                    <?php if ($wS>1): ?><li><a href="<?= $baseUrl ?>&page=1">1</a></li><?php if ($wS>2): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><?php endif; ?>
                                    <?php for ($p=$wS;$p<=$wE;$p++): ?><li><a href="<?= $baseUrl ?>&page=<?= $p ?>" class="<?= $p===$page?'active':'' ?>"><?= $p ?></a></li><?php endfor; ?>
                                    <?php if ($wE<$totalPages): ?><?php if ($wE<$totalPages-1): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><li><a href="<?= $baseUrl ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a></li><?php endif; ?>
                                    <li>
                                        <?php if ($page < $totalPages): ?>
                                            <a href="<?= $baseUrl ?>&page=<?= $page + 1 ?>"><i class="bi bi-arrow-right"></i></a>
                                        <?php else: ?>
                                            <a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-right"></i></a>
                                        <?php endif; ?>
                                    </li>
                                </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- VIEW TREATMENT MODAL -->
    <div class="modal fade" id="treatmentViewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">
                        <i class="feather-activity me-2 text-primary"></i>Treatment Details
                    </h5>
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
                        <tr><th class="text-muted">Current Stage</th><td id="tv_stage"></td></tr>
                        <tr><th class="text-muted">Status</th><td id="tv_status"></td></tr>
                        <tr><th class="text-muted">Notes</th><td id="tv_notes" style="white-space:pre-wrap;"></td></tr>
                    </table>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/treatments.js"></script>
    <script>
    function applyFilters() {
        window._applyFilters('treatments-records.php', {
            'branch': 'filterBranch',
            'status': 'filterStatus'
        });
    }
    </script>
</body>
</html>