<?php
// =============================================================
//  treatments-active.php
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/treatment_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/list_filters.php';

$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : getSessionBranch();
if (!isOwner() && $filterBranch !== getSessionBranch()) $filterBranch = getSessionBranch();
$page = max(1, (int)($_GET['page'] ?? 1));

$branches   = getAllBranches();

// Sort (table headers) / search / treatment date / status (validated in list_filters.php)
$sortOptions  = [
    'oldest'       => ['Oldest First',  'treatment_date.asc.nullslast,treatment_id.asc'],
    'newest'       => ['Newest First',  'treatment_date.desc.nullslast,treatment_id.desc'],
    'patient_asc'  => ['Patient (A–Z)', 'patients(full_name).asc.nullslast,treatment_id.asc'],
    'patient_desc' => ['Patient (Z–A)', 'patients(full_name).desc.nullslast,treatment_id.asc'],
    'status_asc'   => ['Status (A–Z)',  'status.asc,treatment_id.asc'],
    'status_desc'  => ['Status (Z–A)',  'status.desc,treatment_id.asc'],
];
[$sortKey, $sortOrder] = listSort($sortOptions, 'oldest');
$search        = listSearch();
$dateFrom      = listDate('from');
$dateTo        = listDate('to');
$filterStatus  = in_array($_GET['status'] ?? '', ['pending', 'ongoing', 'in_progress'], true) ? $_GET['status'] : '';
$extra         = listSearchFilter($search, ['treatment_code', 'tooth_number']);
if ($filterStatus)  $extra[] = 'status=eq.' . $filterStatus;
array_push($extra, ...listDateRange('treatment_date', $dateFrom, $dateTo));
$filtersOn     = listActiveCount([isOwner() ? $filterBranch : 0, $search, $filterStatus, $dateFrom, $dateTo, $sortKey !== 'oldest']);

$data       = getActiveTreatments($page, $filterBranch, $sortOrder, $extra);
$treatments = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];

$baseUrl = listUrl('treatments-active.php', ['branch' => $filterBranch, 'status' => $filterStatus, 'from' => $dateFrom, 'to' => $dateTo, 'q' => $search, 'sort' => $sortKey === 'oldest' ? '' : $sortKey]);
$sortBase = listUrl('treatments-active.php', ['branch' => $filterBranch, 'status' => $filterStatus, 'from' => $dateFrom, 'to' => $dateTo, 'q' => $search]);

// ── Batch-fetch all services for treatments on this page ──────
// getActiveTreatments() only joins the legacy service_id column.
// We collect the appointment_ids and fetch appointment_services
// in one request, then merge per treatment row.
if (!empty($treatments)) {
    $aptIds = array_filter(array_unique(
        array_map(fn($t) => (int)($t['appointment_id'] ?? 0), $treatments)
    ));

    $svcMapByApt      = []; // appointment_id => [service_name, ...]
    $toothMapByApt    = []; // appointment_id => bool (any service requires tooth selection)

    if ($aptIds) {
        $idList  = implode(',', $aptIds);
        $juncRes = supabase_request(
            'appointment_services',
            'GET',
            [],
            'appointment_id=in.(' . $idList . ')' .
                '&select=appointment_id,sort_order,services(service_id,service_name,base_price,requires_tooth_selection)' .
                '&order=appointment_id.asc,sort_order.asc'
        );
        foreach (($juncRes['data'] ?? []) as $row) {
            $aid  = (int)$row['appointment_id'];
            $name = $row['services']['service_name'] ?? null;
            if ($name) $svcMapByApt[$aid][] = $name;
            // If ANY service in this appointment requires tooth selection, flag it
            if (!empty($row['services']['requires_tooth_selection'])) {
                $toothMapByApt[$aid] = true;
            }
        }
    }

    // Merge into each treatment row
    foreach ($treatments as &$t) {
        $aid = (int)($t['appointment_id'] ?? 0);
        $t['all_service_names'] = $svcMapByApt[$aid] ?? [];
        // Fallback to legacy service_id join
        if (empty($t['all_service_names']) && !empty($t['services']['service_name'])) {
            $t['all_service_names'] = [$t['services']['service_name']];
        }
        // requires_tooth_selection: true if any service in this appointment needs it
        $t['requires_tooth_selection'] = $toothMapByApt[$aid]
            ?? !empty($t['services']['requires_tooth_selection']);
    }
    unset($t);
}
// DEBUG — remove after confirming
foreach ($treatments as $t) {
    error_log("TRT ID: " . ($t['treatment_id'] ?? 'none') . " | APT ID: " . ($t['appointment_id'] ?? 'none') . " | APT CODE: " . ($t['appointment_code'] ?? 'MISSING'));
}
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
    <link rel="stylesheet" href="assets/css/custom.css?v=5">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/odontogram.css?v=3">
    <link rel="stylesheet" href="assets/css/treatments.css?v=7">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&display=swap">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=4">
    <style>
        /* ── Add Material blur effect ── */
        body.add-material-open>* {
            filter: blur(3px) brightness(0.55);
            transition: filter 0.25s ease;
            transform: scale(1.02);
            overflow: hidden;
        }

        body.add-material-open>#addMaterialModal {
            filter: none !important;
            transform: none !important;
            transition: none;
        }

        #addMaterialModal {
            z-index: 1070 !important;
        }

        /* ── Inventory Item custom dropdown (mirrors .modal-cs from booking page) ── */
        /* Dropdowns use the shared .modal-cs design (assets/css/custom-select.css). */
        .modal-cs-trigger { height: 38px; }
        .modal-cs-list li .cs-item-stock {
            font-size: 0.76rem;
            font-weight: 500;
            opacity: 0.75;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .modal-cs-list li.active .cs-item-stock {
            opacity: 0.85;
        }
    </style>
</head>

<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>

    <main class="nxl-container">
        <div class="nxl-content">

            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title">
                        <h5 class="m-b-10">Treatments</h5>
                    </div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Active</li>
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

                            <div class="list-toolbar">
                                <?= listSearchControl($search, 'Treatment no. or tooth') ?>
                                <?= listDateRangeControl($dateFrom, $dateTo, 'Treatment Date') ?>
                                <?= listSelectControl('Status', 'status', ['' => 'All Statuses', 'pending' => 'Pending', 'ongoing' => 'Ongoing', 'in_progress' => 'In Progress'], $filterStatus) ?>
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
                                <?= listResetControl('treatments-active.php', $filtersOn) ?>
                            </div>
                            <div class="card-body custom-card-action p-0">
                                <div class="table-responsive" style="overflow:visible;">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <?= listTh('Patient Name', 'patient_asc', 'patient_desc', $sortKey, $sortBase) ?>
                                                <?= listTh('Date', 'oldest', 'newest', $sortKey, $sortBase) ?>
                                                <th>Service(s)</th>
                                                <th>Estimated Price</th>
                                                <?= listTh('Status', 'status_asc', 'status_desc', $sortKey, $sortBase) ?>
                                                <th class="text-end">Action</th>
                                            </tr>

                                        </thead>
                                        <tbody>
                                            <?php if (empty($treatments)): ?>
                                                <tr>
                                                    <td colspan="10" class="text-center py-5 text-muted">
                                                        <i class="feather-activity me-2 fs-4 d-block mb-2"></i>
                                                        <?php if ($filtersOn): ?>
                                                        No active treatments match the selected filters.<br>
                                                        <a href="treatments-active.php" class="fw-semibold small">Reset filters</a>
                                                        <?php else: ?>
                                                        No active treatments at the moment.<br>
                                                        <small>Patients appear here after being checked in at the appointment desk.</small>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($treatments as $t): ?>
                                                    <?php
                                                    $allSvcs = $t['all_service_names'] ?? [];
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <a href="patient-records.php?id=<?= (int)($t['patient_id'] ?? 0) ?>"
                                                                class="text-primary fw-semibold text-decoration-none">
                                                                <?= htmlspecialchars($t['patients']['full_name'] ?? '—') ?>
                                                            </a>
                                                        </td>
                                                        <td><?= formatTrtDate($t['treatment_date'] ?? null) ?></td>

                                                        <!-- ── All services as numbered badges ── -->
                                                        <td>
                                                            <?php if (!empty($allSvcs)): ?>
                                                                <?php foreach ($allSvcs as $i => $svcName): ?>
                                                                    <span class="badge bg-soft-primary text-primary d-inline-flex align-items-center gap-1 mb-1"
                                                                        style="font-size:.74rem;font-weight:500;">
                                                                        <span style="opacity:.55;font-size:.65rem;"><?= $i + 1 ?>.</span>
                                                                        <?= htmlspecialchars($svcName) ?>
                                                                    </span>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">—</span>
                                                            <?php endif; ?>
                                                        </td>

                                                        <td><?= formatCost($t['cost'] ?? null) ?></td>
                                                        <td>
                                                            <span class="badge <?= trtStatusBadge($t['status']) ?>">
                                                                <?= trtStatusLabel($t['status']) ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-end">
                                                            <div class="dropdown">
                                                                <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" style="overflow:visible;">
                                                                    <i class="feather-more-vertical"></i>
                                                                </a>
                                                                <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                                    <a href="javascript:void(0);" class="dropdown-item"
                                                                        onclick='viewTreatment(<?= json_encode([
                                                                                                    "treatment_code"   => $t["treatment_code"] ?? "",
                                                                                                    "appointment_code" => $t["appointment_code"] ?? ($t["appointments"]["appointment_code"] ?? ""),
                                                                                                    "treatment_date"   => formatTrtDate($t["treatment_date"] ?? null),
                                                                                                    "services"         => !empty($t["all_service_names"]) ? array_values($t["all_service_names"]) : [],
                                                                                                    "cost"             => isset($t["cost"]) ? formatCost((float)$t["cost"]) : "",
                                                                                                    "status"           => trtStatusLabel($t["status"]),
                                                                                                    "status_class"     => trtStatusBadge($t["status"]),
                                                                                                    "current_stage"    => $t["current_stage"] ?? "",
                                                                                                    "tooth_number"     => $t["tooth_number"] ?? "",
                                                                                                    "procedure_notes"  => $t["procedure_notes"] ?? "",
                                                                                                    "patient_id"       => (int)($t["patient_id"] ?? 0),
                                                                                                    "patient_name"     => $t["patients"]["full_name"] ?? "",
                                                                                                    "dentist_name"     => $t["dentists"]["full_name"] ?? "",
                                                                                                    "branch_name"      => $t["branches"]["branch_name"] ?? "",
                                                                                                ], JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>)'>
                                                                        <i class="feather-eye"></i> View Details
                                                                    </a>
                                                                    <div class="dropdown-divider"></div>
                                                                    <a href="javascript:void(0);" class="dropdown-item text-primary"
                                                                        onclick='openWorkModal(<?= json_encode([
                                                                                                    "treatment_id"             => (int)($t["treatment_id"] ?? 0),
                                                                                                    "appointment_id"           => (int)($t["appointment_id"] ?? 0),
                                                                                                    "patient_id"               => (int)($t["patient_id"] ?? 0),
                                                                                                    "branch_id"                => (int)($t["branch_id"] ?? 0),
                                                                                                    "dentist_id"               => (int)($t["dentist_id"] ?? 0),
                                                                                                    "service_id"               => (int)($t["service_id"] ?? 0),
                                                                                                    "treatment_code"           => $t["treatment_code"] ?? "",
                                                                                                    "treatment_date"           => formatTrtDate($t["treatment_date"] ?? null),
                                                                                                    "patient_name"             => $t["patients"]["full_name"] ?? "—",
                                                                                                    "branch_name"              => $t["branches"]["branch_name"] ?? "—",
                                                                                                    "dentist_name"             => $t["dentists"]["full_name"] ?? "—",
                                                                                                    "service_name"             => !empty($t["all_service_names"])
                                                                                                        ? implode(", ", $t["all_service_names"])
                                                                                                        : ($t["services"]["service_name"] ?? "—"),
                                                                                                    "requires_tooth_selection" => !empty($t["requires_tooth_selection"]),
                                                                                                    "tooth_number"             => $t["tooth_number"] ?? "",
                                                                                                    "cost"                     => $t["cost"] ?? null,
                                                                                                    "current_stage"            => $t["current_stage"] ?? "",
                                                                                                    "procedure_notes"          => $t["procedure_notes"] ?? "",
                                                                                                    "status"                   => $t["status"] ?? "",
                                                                                                ], JSON_HEX_QUOT | JSON_HEX_TAG) ?>)'>
                                                                        <i class="feather-edit-2"></i>
                                                                        <?= in_array($t['status'], ['pending', 'ongoing']) ? 'Start Treatment' : 'Update Treatment' ?>
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
                                        <li><?php if ($page > 1): ?><a href="<?= $baseUrl ?>&page=<?= $page - 1 ?>"><i class="bi bi-arrow-left"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-left"></i></a><?php endif; ?></li>
                                        <?php $wS = max(1, $page - 2);
                                        $wE = min($totalPages, $wS + 4);
                                        $wS = max(1, $wE - 4); ?>
                                        <?php if ($wS > 1): ?><li><a href="<?= $baseUrl ?>&page=1">1</a></li><?php if ($wS > 2): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><?php endif; ?>
                                                <?php for ($p = $wS; $p <= $wE; $p++): ?><li><a href="<?= $baseUrl ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a></li><?php endfor; ?>
                                                <?php if ($wE < $totalPages): ?><?php if ($wE < $totalPages - 1): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><li><a href="<?= $baseUrl ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a></li><?php endif; ?>
                                        <li><?php if ($page < $totalPages): ?><a href="<?= $baseUrl ?>&page=<?= $page + 1 ?>"><i class="bi bi-arrow-right"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-right"></i></a><?php endif; ?></li>
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
    <div class="modal fade es-detail-modal" id="treatmentViewModal" tabindex="-1" aria-labelledby="tvTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-record-dialog">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-activity"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="tvTitle">Active Treatment Details</h5>
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
                                    <div class="ar-sum-label">Treatment Date</div>
                                    <div class="ar-sum-value" id="tv_date">—</div>
                                </div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-flag"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Current Stage</div>
                                    <div class="ar-sum-value" id="tv_stage">—</div>
                                </div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-credit-card"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Estimated Cost</div>
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
                                    <h6 class="ar-card-title">Treatment &amp; Appointment</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Treatment No.</span><span class="ar-card-value ar-strong" id="tv_code">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Appointment No.</span><span class="ar-card-value" id="tv_appointment_code">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Dentist</span><span class="ar-card-value" id="tv_dentist">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Branch</span><span class="ar-card-value" id="tv_branch">—</span></div>
                                </div>
                            </section>

                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-layers"></i></span>
                                    <h6 class="ar-card-title">Services</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <ol class="ar-svc-list" id="tv_service"><li>—</li></ol>
                                </div>
                            </section>

                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-grid"></i></span>
                                    <h6 class="ar-card-title">Clinical Details</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Tooth No.</span><span class="ar-card-value" id="tv_tooth">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Procedure Notes</span><span class="ar-card-value ar-notes" id="tv_procedure">—</span></div>
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


    <!-- ============================================================
         WORK ON TREATMENT MODAL
    ============================================================ -->
    <div class="modal fade" id="workModal" tabindex="-1" aria-labelledby="workModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable tw-dialog">
            <div class="modal-content tw-modal">
                <div class="modal-header tw-header">
                    <div class="tw-header-main">
                        <span class="tw-header-icon" aria-hidden="true"><i class="feather-activity"></i></span>
                        <div class="tw-header-text">
                            <h5 class="modal-title tw-title" id="workModalTitle">Start Treatment</h5>
                            <!-- Patient · treatment · appointment · branch (filled by openWorkModal) -->
                            <p class="tw-subtitle" id="workModalSubtitle"></p>
                        </div>
                    </div>
                    <button type="button" class="btn-close tw-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- Step bar: each step shows one section below (twGoStep in treatments-active.js) -->
                <nav class="tw-stepbar" aria-label="Treatment steps">
                    <ol class="tw-steps">
                        <li><button type="button" class="tw-step" data-step="1" onclick="twGoStep(1)"><span class="tw-step-num">1</span><span class="tw-step-label">Details</span></button></li>
                        <li><button type="button" class="tw-step" data-step="2" onclick="twGoStep(2)"><span class="tw-step-num">2</span><span class="tw-step-label">Teeth &amp; Procedures</span></button></li>
                        <li><button type="button" class="tw-step" data-step="3" onclick="twGoStep(3)"><span class="tw-step-num">3</span><span class="tw-step-label">Plan &amp; Cost</span></button></li>
                        <li><button type="button" class="tw-step" data-step="4" onclick="twGoStep(4)"><span class="tw-step-num">4</span><span class="tw-step-label">Materials</span></button></li>
                    </ol>
                </nav>

                <div class="modal-body tw-body">
                    <div id="workModalError" class="tw-error" role="alert" style="display:none;"></div>
                    <!-- Stock check of the plan's combined materials (api/stock_check.php); re-rendered on every check -->
                    <div id="twStockAlert" class="tw-error tw-stock-alert" role="alert" aria-live="polite" hidden></div>

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

                    <!-- ── Treatment details ── -->
                    <section class="tw-section tw-pane" data-pane="1" aria-labelledby="twDetailsTitle">
                        <h6 class="tw-section-title" id="twDetailsTitle"><i class="feather-clipboard" aria-hidden="true"></i> Treatment Details</h6>
                        <div class="tw-grid">
                            <div class="tw-field">
                                <span class="tw-label" id="twServiceLabel">Booked Services</span>
                                <div id="work_service_display" class="tw-services" aria-labelledby="twServiceLabel">
                                    <!-- chips rendered by _renderServiceDisplay() -->
                                </div>
                            </div>
                            <div class="tw-field">
                                <label class="tw-label" for="work_dentist_select">Dentist <span class="tw-req" aria-hidden="true">*</span></label>
                                <select id="work_dentist_select" class="form-select js-custom-select" data-cs-float data-cs-search>
                                    <option value="">Select Dentist</option>
                                </select>
                            </div>
                            <div class="tw-field tw-span-full">
                                <label class="tw-label" for="work_procedure_notes">Procedure Notes <span class="tw-req" aria-hidden="true">*</span></label>
                                <textarea id="work_procedure_notes" class="form-control tw-input tw-textarea" rows="3"
                                    placeholder="Describe what was performed e.g. Extraction of lower left molar..."></textarea>
                            </div>
                        </div>
                    </section>

                    <!-- ── Procedures by tooth: the shared dental chart (assets/js/odontogram.js) + tooth panel ── -->
                    <section class="tw-section tw-pane" data-pane="2" id="tooth_chart_section" aria-labelledby="twChartTitle">
                        <div class="tw-section-head">
                            <h6 class="tw-section-title" id="twChartTitle"><i class="feather-grid" aria-hidden="true"></i> Procedures by Tooth</h6>
                            <span class="tw-section-hint">Click a tooth, then choose the procedure performed. Colors show the patient's current chart.</span>
                        </div>
                        <div class="tw-planner">
                            <div class="tw-chart-card">
                                <div class="odo-legend tw-legend" id="wm_legend" aria-label="Chart legend"></div>
                                <div id="wm_chart_loading" class="tw-chart-loading" style="display:none;">
                                    <span class="spinner-border spinner-border-sm" role="status"></span>
                                    Loading patient chart…
                                </div>
                                <div class="tw-chart-scroll">
                                    <div id="wm_chart_grids" class="odo-chart tw-chart">
                                        <div class="odo-sides" aria-hidden="true">
                                            <span><i class="feather-arrow-left me-1"></i>Patient's Right</span>
                                            <span>Patient's Left<i class="feather-arrow-right ms-1"></i></span>
                                        </div>
                                        <div class="odo-arch-label">Upper</div>
                                        <div class="odo-row odo-upper" id="wm_upperTeeth" role="group" aria-label="Upper teeth, 1 to 16"></div>
                                        <div class="odo-occlusal" aria-hidden="true"></div>
                                        <div class="odo-row odo-lower" id="wm_lowerTeeth" role="group" aria-label="Lower teeth, 17 to 32"></div>
                                        <div class="odo-arch-label">Lower</div>
                                    </div>
                                </div>
                            </div>
                            <!-- Focused tooth: current condition, procedure cards, surfaces, optional finding (rendered by treatments-active.js) -->
                            <aside class="tw-tooth-panel" id="wm_tooth_panel" aria-live="polite" aria-label="Selected tooth"></aside>
                        </div>
                    </section>

                    <!-- ── Treatment plan & cost ── -->
                    <section class="tw-section tw-pane" data-pane="3" aria-labelledby="twPlanTitle">
                        <div class="tw-section-head">
                            <h6 class="tw-section-title" id="twPlanTitle"><i class="feather-list" aria-hidden="true"></i> Treatment Plan &amp; Cost</h6>
                            <span class="tw-section-hint">Planned until you finish the treatment.</span>
                            <button type="button" class="btn btn-sm btn-outline-primary tw-add-btn" onclick="twTogglePicker('general')">
                                <i class="feather-plus me-1"></i> General procedure
                            </button>
                        </div>
                        <div id="tw_general_picker" class="tw-general-picker" style="display:none;"></div>
                        <div class="table-responsive tw-table">
                            <table class="table table-sm mb-0 tw-plan-table">
                                <thead>
                                    <tr>
                                        <th>Tooth</th>
                                        <th>Procedure</th>
                                        <th>Surfaces</th>
                                        <th class="text-end">Price</th>
                                        <th class="text-end"><span class="visually-hidden">Remove</span></th>
                                    </tr>
                                </thead>
                                <tbody id="tw_plan_body"></tbody>
                            </table>
                        </div>
                        <div class="tw-plan-note" id="tw_plan_note" style="display:none;"></div>
                        <input type="hidden" id="work_tooth_number">
                        <div class="tw-grid mt-3">
                            <div class="tw-field">
                                <label class="tw-label" for="work_cost">Cost <span class="tw-optional">(calculated from procedures)</span></label>
                                <div class="tw-input-prefix">
                                    <span class="tw-prefix" aria-hidden="true">₱</span>
                                    <input type="number" id="work_cost" class="form-control tw-input tw-readonly" placeholder="0.00" readonly tabindex="-1" aria-readonly="true">
                                </div>
                                <div class="tw-hint" id="work_cost_hint"></div>
                                <div class="tw-cost-breakdown" id="work_cost_breakdown" style="display:none;"></div>
                            </div>
                            <div class="tw-field">
                                <label class="tw-label" for="work_current_stage">Current Stage <span class="tw-optional">(read-only)</span></label>
                                <input type="text" id="work_current_stage" class="form-control tw-input tw-readonly" readonly tabindex="-1" aria-readonly="true">
                                <div class="tw-hint">Managed by the treatment workflow — not changed by this form.</div>
                            </div>
                        </div>
                    </section>

                    <!-- ── Materials ── -->
                    <section class="tw-section tw-pane" data-pane="4" aria-labelledby="twMatTitle">
                        <div class="tw-section-head">
                            <h6 class="tw-section-title" id="twMatTitle"><i class="feather-package" aria-hidden="true"></i> Materials Used</h6>
                            <span class="tw-section-hint">Auto-filled from all services, editable.</span>
                            <button type="button" class="btn btn-sm btn-outline-primary tw-add-btn" onclick="openAddWorkMaterial()">
                                <i class="feather-plus me-1"></i> Add Item
                            </button>
                        </div>
                        <div id="work_materials_loading" class="tw-chart-loading">
                            <span class="spinner-border spinner-border-sm" role="status"></span> Loading materials...
                        </div>
                        <div class="table-responsive tw-table" id="work_materials_table" style="display:none;">
                            <table class="table table-sm mb-0">
                                <thead>
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
                    </section>
                </div>

                <div class="modal-footer tw-footer">
                    <!-- Running total, visible on every step (filled by _renderStepSummary) -->
                    <div class="tw-summary" id="tw_summary" aria-live="polite"></div>
                    <div class="tw-actions">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-outline-primary px-4" id="twBackBtn" onclick="twGoStep(_twStep - 1)">
                            <i class="feather-arrow-left me-1"></i> Back
                        </button>
                        <button type="button" class="btn btn-primary px-4" id="twNextBtn" onclick="twGoStep(_twStep + 1)">
                            Next <i class="feather-arrow-right ms-1"></i>
                        </button>
                        <button type="button" class="btn btn-primary px-4" id="finishTreatmentBtn" onclick="openFinishConfirmModal()">
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
    <div class="modal fade es-detail-modal" id="finishConfirmModal" tabindex="-1" aria-labelledby="fcTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-check-circle"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="fcTitle">Confirm Treatment Completion</h5>
                            <div class="apt-record-meta">Please review before finalizing.</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-record-body p-0">
                    <div class="ar-view-body">
                        <div id="finishConfirmError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                        <div class="ar-cards-grid">
                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-user"></i></span>
                                    <h6 class="ar-card-title">Treatment</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Patient</span><span class="ar-card-value ar-strong" id="fc_patient">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Dentist</span><span class="ar-card-value" id="fc_dentist">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Branch</span><span class="ar-card-value" id="fc_branch">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Procedure Notes</span><span class="ar-card-value ar-notes" id="fc_procedure">—</span></div>
                                </div>
                            </section>
                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-package"></i></span>
                                    <h6 class="ar-card-title">Materials to Deduct</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div id="fc_materials_list" class="small py-2">—</div>
                                </div>
                            </section>
                            <section class="ar-card ar-card-wide">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-list"></i></span>
                                    <h6 class="ar-card-title">Procedures</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="table-responsive es-detail-table-wrap">
                                        <table class="table table-sm mb-0 es-detail-table">
                                            <thead><tr><th>Tooth</th><th>Procedure</th><th class="text-end">Price</th></tr></thead>
                                            <tbody id="fc_plan"></tbody>
                                            <tfoot><tr class="fc-total-row"><th colspan="2">Total Cost</th><th class="text-end" id="fc_cost"></th></tr></tfoot>
                                        </table>
                                    </div>
                                    <div class="small text-warning pb-2" id="fc_plan_warn" style="display:none;"></div>
                                </div>
                            </section>
                            <section class="ar-card ar-card-wide">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-grid"></i></span>
                                    <h6 class="ar-card-title">Update Dental Chart?</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <p class="fc-chart-hint">Tick only what was actually done or found. Unticked items leave the chart unchanged; every change is kept in the tooth's history.</p>
                                    <div id="fc_chart" class="fc-chart"></div>
                                </div>
                            </section>
                        </div>
                        <div class="alert alert-warning small py-2 px-3 mt-3 mb-0">
                            <i class="feather-alert-triangle me-1"></i>
                            After confirmation: inventory will be deducted, an invoice will be created (status: Unpaid), and this treatment will move to Treatment Records.
                        </div>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer">
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
    <!-- ============================================================
         ADD MATERIAL MODAL
    ============================================================ -->
    <div class="modal fade" id="addMaterialModal" tabindex="-1" aria-hidden="true" style="z-index:1056;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0">
                            <i class="feather-package me-2 text-primary"></i>Add Material
                        </h5>
                        <p class="text-muted small mb-0">Select an inventory item to add to this treatment.</p>
                    </div>
                    <button type="button" class="btn-close" onclick="closeAddWorkMaterial()"></button>
                </div>
                <div class="modal-body">
                    <div id="addMat_error" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Inventory Item <span class="text-danger">*</span></label>
                            <div class="modal-cs" id="invItemCs">
                                <div class="modal-cs-trigger" onclick="toggleInvItemCs()">
                                    <span class="modal-cs-value" id="invItemCsValue"> Select Item </span>
                                    <svg class="modal-cs-arrow" width="11" height="11" viewBox="0 0 12 12" fill="none">
                                        <path d="M2.5 4.5l3.5 3.5 3.5-3.5" stroke="currentColor" stroke-width="1.6"
                                            stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </div>
                                <div class="modal-cs-dropdown" id="invItemCsDropdown">
                                    <div class="modal-cs-search-wrap">
                                        <svg width="12" height="12" viewBox="0 0 14 14" fill="none">
                                            <circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.4" />
                                            <path d="M9.5 9.5l2.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
                                        </svg>
                                        <input type="text" class="modal-cs-search" id="invItemCsSearch"
                                            placeholder="Search item…"
                                            oninput="filterInvItemCs(this.value)">
                                    </div>
                                    <ul class="modal-cs-list" id="invItemCsList"></ul>
                                </div>
                            </div>
                            <!-- hidden real value read by addWorkMaterial() -->
                            <select id="work_add_item" style="display:none;"></select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Quantity to Use <span class="text-danger">*</span></label>
                            <input type="number" id="work_add_qty" class="form-control"
                                placeholder="e.g. 2" min="1" value="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Current Stock</label>
                            <div class="form-control bg-light text-muted" id="addMat_stock_preview">—</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <span class="text-muted small"><span class="text-danger">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" onclick="closeAddWorkMaterial()">Cancel</button>
                        <button type="button" class="btn btn-primary px-4" onclick="addWorkMaterial()">
                            <i class="feather-plus me-1"></i> Add Item
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
    <script src="assets/js/es-swal.js?v=2"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
    <script src="assets/js/odontogram.js?v=2"></script>
    <script src="assets/js/treatments-active-chart.js?v=4"></script>
    <script src="assets/js/treatments-active.js?v=8"></script>
    <script>
        window._sessionBranchId = '<?= getSessionBranch() ?>';
        window._currentUser = '<?= htmlspecialchars($currentUser['full_name'] ?? '', ENT_QUOTES) ?>';

        function applyFilters() {
            window._applyFilters('treatments-active.php', {
                'branch': 'filterBranch'
            });
        }
    </script>
</body>

</html>