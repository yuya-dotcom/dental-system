<?php

// treatments-records.php

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying
require_once __DIR__ . '/controllers/treatment_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/list_filters.php';

$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : getSessionBranch();
if (!isOwner() && $filterBranch !== getSessionBranch()) $filterBranch = getSessionBranch();
$filterStatus = in_array($_GET['status'] ?? '', ['completed', 'cancelled'], true) ? $_GET['status'] : '';
$page         = max(1, (int)($_GET['page'] ?? 1));


// Sort (table headers) / search / treatment date (validated in list_filters.php)
$sortOptions  = [
    'code_asc'     => ['Treatment No. (A–Z)', 'treatment_code.asc'],
    'code_desc'    => ['Treatment No. (Z–A)', 'treatment_code.desc'],
    'newest'       => ['Newest First',        'treatment_date.desc.nullslast,treatment_id.desc'],
    'oldest'       => ['Oldest First',        'treatment_date.asc.nullslast,treatment_id.asc'],
    'patient_asc'  => ['Patient (A–Z)', 'patients(full_name).asc.nullslast,treatment_id.asc'],
    'patient_desc' => ['Patient (Z–A)', 'patients(full_name).desc.nullslast,treatment_id.asc'],
    'branch_asc'   => ['Branch (A–Z)',        'branches(branch_name).asc.nullslast,treatment_id.asc'],
    'branch_desc'  => ['Branch (Z–A)',        'branches(branch_name).desc.nullslast,treatment_id.asc'],
    'dentist_asc'  => ['Dentist (A–Z)',       'dentists(full_name).asc.nullslast,treatment_id.asc'],
    'dentist_desc' => ['Dentist (Z–A)',       'dentists(full_name).desc.nullslast,treatment_id.asc'],
    'service_asc'  => ['Service (A–Z)',       'services(service_name).asc.nullslast,treatment_id.asc'],
    'service_desc' => ['Service (Z–A)',       'services(service_name).desc.nullslast,treatment_id.asc'],
    'status_asc'   => ['Status (A–Z)',        'status.asc,treatment_id.asc'],
    'status_desc'  => ['Status (Z–A)',        'status.desc,treatment_id.asc'],
];
[$sortKey, $sortOrder] = listSort($sortOptions, 'code_asc');
$search        = listSearch();
$dateFrom      = listDate('from');
$dateTo        = listDate('to');
$extra         = listSearchFilter($search, ['treatment_code', 'tooth_number']);
array_push($extra, ...listDateRange('treatment_date', $dateFrom, $dateTo));
$filtersOn     = listActiveCount([isOwner() ? $filterBranch : 0, $filterStatus, $search, $dateFrom, $dateTo, $sortKey !== 'code_asc']);

// One parallel wave: the list + branch dropdown + navbar bell (controllers/admin_batch.php)
admin_prefetch(['list' => admin_q('treatments', implode('&', treatmentRecordsQuery($page, $filterBranch, $filterStatus, $sortOrder, $extra)), ['Prefer: count=exact'])]);
$branches     = getAllBranches();
$data         = getTreatmentRecords($page, $filterBranch, $filterStatus, $sortOrder, $extra);
$treatments   = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];
$baseUrl      = listUrl('treatments-records.php', ['branch' => $filterBranch, 'status' => $filterStatus, 'q' => $search, 'from' => $dateFrom, 'to' => $dateTo, 'sort' => $sortKey === 'code_asc' ? '' : $sortKey]);
$sortBase = listUrl('treatments-records.php', ['branch' => $filterBranch, 'status' => $filterStatus, 'q' => $search, 'from' => $dateFrom, 'to' => $dateTo]);
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
    <link rel="stylesheet" href="assets/css/custom.css?v=5">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/treatments.css">
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

                            <div class="list-toolbar">
                                <?= listSearchControl($search, 'Treatment no. or tooth') ?>
                                <?= listDateRangeControl($dateFrom, $dateTo, 'Treatment Date') ?>
                            <div class="list-field"><label class="list-label" for="filterStatus">Status</label>                            <select id="filterStatus" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="">All Statuses</option>
                                <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select></div>
                            <?php if (isOwner()): ?>
                            <div class="list-field"><label class="list-label" for="filterBranch">Branch</label>
                            <select id="filterBranch" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>" <?= (int)$b['branch_id'] === $filterBranch ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select></div>
                            <?php endif; ?>
                                <?= listResetControl('treatments-records.php', $filtersOn) ?>
                            </div>
                            <div class="card-body custom-card-action p-0">
                                <!-- trt-table-wrapper mirrors apt-table-wrapper: horizontal scroll inside card only -->
                                <div class="table-responsive trt-table-wrapper">
                                    <table class="table table-hover mb-0 trt-records-table">
                                        <thead>
                                            <tr>
                                                <?= listTh('Date / Time', 'newest', 'oldest', $sortKey, $sortBase) ?>
                                                <?= listTh('Patient Name', 'patient_asc', 'patient_desc', $sortKey, $sortBase) ?>
                                                <?php if (isOwner()): ?>
                                                    <?= listTh('Branch', 'branch_asc', 'branch_desc', $sortKey, $sortBase) ?>
                                                <?php else: ?>
                                                    <?= listTh('Dentist', 'dentist_asc', 'dentist_desc', $sortKey, $sortBase) ?>
                                                <?php endif; ?>
                                                <?= listTh('Status', 'status_asc', 'status_desc', $sortKey, $sortBase) ?>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($treatments)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center py-4 text-muted">
                                                        <i class="feather-activity me-2"></i><?= $filtersOn ? 'No treatment records match the selected filters. <a href="treatments-records.php" class="fw-semibold">Reset filters</a>' : 'No treatment records found.' ?>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($treatments as $t): ?>
                                                <tr>
                                                    <?php $trtTime = $t['appointments']['appointment_time'] ?? null; ?>
                                                    <td>
                                                        <div class="fw-semibold"><?= formatTrtDate($t['treatment_date'] ?? null) ?></div>
                                                        <?php if ($trtTime): ?>
                                                            <div class="text-muted small"><?= date('g:i A', strtotime($trtTime)) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="patient-records.php?id=<?= (int)($t['patient_id'] ?? 0) ?>"
                                                           class="text-primary fw-semibold">
                                                            <?= htmlspecialchars($t['patients']['full_name'] ?? '—') ?>
                                                        </a>
                                                    </td>
                                                    <?php if (isOwner()): ?>
                                                        <td><?= htmlspecialchars($t['branches']['branch_name'] ?? '—') ?></td>
                                                    <?php else: ?>
                                                        <td><?= htmlspecialchars($t['dentists']['full_name'] ?? '—') ?></td>
                                                    <?php endif; ?>
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
                                                                <!-- View Details -->
                                                                <a href="javascript:void(0);" class="dropdown-item"
                                                                    onclick='viewTreatment(<?= json_encode([
                                                                        "treatment_code"   => $t["treatment_code"] ?? "",
                                                                        "treatment_date"   => formatTrtDate($t["treatment_date"] ?? null),
                                                                        "treatment_time"   => $trtTime ? date("g:i A", strtotime($trtTime)) : "",
                                                                        "appointment_code" => $t["appointments"]["appointment_code"] ?? "",
                                                                        "patient_id"       => (int)($t["patient_id"] ?? 0),
                                                                        "patient_name"     => $t["patients"]["full_name"] ?? "",
                                                                        "branch_name"      => $t["branches"]["branch_name"] ?? "",
                                                                        "dentist_name"     => $t["dentists"]["full_name"] ?? "",
                                                                        "service_name"     => $t["services"]["service_name"] ?? "",
                                                                        "tooth_number"     => $t["tooth_number"] ?? "",
                                                                        "cost"             => formatCost(isset($t["cost"]) ? (float)$t["cost"] : null),
                                                                        "current_stage"    => $t["current_stage"] ?? "",
                                                                        "status"           => trtStatusLabel($t["status"]),
                                                                        "status_class"     => trtStatusBadge($t["status"]),
                                                                        "notes"            => $t["procedure_notes"] ?? "",
                                                                        "procedures"       => array_values(array_map(fn($p) => [
                                                                            "tooth"    => $p["tooth_number"] ?? "",
                                                                            "surfaces" => $p["surfaces"] ?? "",
                                                                            "service"  => $p["services"]["service_name"] ?? "—",
                                                                            "price"    => formatCost(isset($p["unit_price"]) ? (float)$p["unit_price"] : null),
                                                                        ], array_filter($t["treatment_procedures"] ?? [], fn($p) => ($p["status"] ?? "") === "completed"))),
                                                                    ], JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>)'>
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
                                    <?php if ($filtersOn): ?>
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

    <!-- ============================================================
         VIEW TREATMENT MODAL
    ============================================================ -->
    <div class="modal fade es-detail-modal" id="treatmentViewModal" tabindex="-1" aria-labelledby="tvTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-record-dialog">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-activity"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="tvTitle">Treatment Details</h5>
                            <div class="apt-record-meta">Treatment No. <span class="apt-record-code" id="tv_code_sub">—</span></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body apt-record-body p-0">
                    <div class="ar-view-body">
                        <div class="ar-summary">
                            <div class="ar-sum-item ar-sum-status">
                                <div class="ar-sum-label">Status</div>
                                <div id="tv_status">—</div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-clock"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Date &amp; Time</div>
                                    <div class="ar-sum-value" id="tv_date">—</div>
                                    <div class="ar-sum-value" id="tv_time"></div>
                                </div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-map-pin"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Branch</div>
                                    <div class="ar-sum-value" id="tv_branch">—</div>
                                </div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-credit-card"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Treatment Cost</div>
                                    <div class="ar-sum-value" id="tv_cost">—</div>
                                </div>
                            </div>
                        </div>

                        <div class="ar-cards-grid">
                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-user"></i></span>
                                    <h6 class="ar-card-title">Patient Information</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Patient Name</span><span class="ar-card-value ar-strong" id="tv_patient">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Patient Record</span><span class="ar-card-value" id="tv_patient_link">—</span></div>
                                </div>
                            </section>

                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-clipboard"></i></span>
                                    <h6 class="ar-card-title">Treatment Information</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Treatment No.</span><span class="ar-card-value ar-strong" id="tv_code">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Appointment No.</span><span class="ar-card-value" id="tv_appointment">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Service</span><span class="ar-card-value" id="tv_service">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Dentist</span><span class="ar-card-value" id="tv_dentist">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Current Stage</span><span class="ar-card-value" id="tv_stage">—</span></div>
                                </div>
                            </section>

                            <section class="ar-card ar-card-wide" id="tv_proc_card" style="display:none;">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-list"></i></span>
                                    <h6 class="ar-card-title">Procedures Performed</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="table-responsive es-detail-table-wrap">
                                        <table class="table table-sm mb-0 es-detail-table">
                                            <thead><tr><th>Tooth</th><th>Procedure</th><th>Surfaces</th><th class="text-end">Price</th></tr></thead>
                                            <tbody id="tv_procs"></tbody>
                                        </table>
                                    </div>
                                </div>
                            </section>

                            <section class="ar-card ar-card-wide">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-grid"></i></span>
                                    <h6 class="ar-card-title">Clinical Details</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Tooth No.</span><span class="ar-card-value" id="tv_tooth">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Procedure Notes</span><span class="ar-card-value ar-notes" id="tv_notes">—</span></div>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>

                <div class="modal-footer apt-record-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
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
    <script src="assets/js/treatments.js?v=3"></script>
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