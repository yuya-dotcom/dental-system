<?php
// =============================================================
//  patients-list.php
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/patient_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/list_filters.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying

$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : (isOwner() || isAdmin() ? 0 : getSessionBranch());
if (!isOwner() && !isAdmin() && $filterBranch !== getSessionBranch()) $filterBranch = getSessionBranch();
$filterStatus = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$page         = max(1, (int)($_GET['page'] ?? 1));


// Sort (table headers) / search / registration date (validated in list_filters.php)
$sortOptions  = [
    'oldest'      => ['Oldest Registered',        'created_at.asc,patient_id.asc'],
    'code_asc'    => ['Patient ID (A–Z)',         'patient_code.asc,patient_id.asc'],
    'code_desc'   => ['Patient ID (Z–A)',         'patient_code.desc,patient_id.desc'],
    'name_asc'    => ['Name (A–Z)',               'full_name.asc,patient_id.asc'],
    'name_desc'   => ['Name (Z–A)',               'full_name.desc,patient_id.desc'],
    'branch_asc'  => ['Branch (A–Z)',             'branches(branch_name).asc.nullslast,full_name.asc'],
    'branch_desc' => ['Branch (Z–A)',             'branches(branch_name).desc.nullslast,full_name.asc'],
    'birth_asc'   => ['Birthdate (Oldest)',       'birthdate.asc.nullslast,full_name.asc'],
    'birth_desc'  => ['Birthdate (Newest)',       'birthdate.desc.nullslast,full_name.asc'],
    'visit_desc'  => ['Last Visit (Most Recent)', 'last_visit.desc.nullslast,full_name.asc'],
    'visit_asc'   => ['Last Visit (Oldest)',      'last_visit.asc.nullslast,full_name.asc'],
    'status_asc'  => ['Status (A–Z)',             'status.asc,full_name.asc'],
    'status_desc' => ['Status (Z–A)',             'status.desc,full_name.asc'],
];
[$sortKey, $sortOrder] = listSort($sortOptions, 'oldest');
$search       = listSearch();
$dateFrom     = listDate('from');
$dateTo       = listDate('to');
$extra        = listSearchFilter($search, ['full_name', 'patient_code', 'contact_number']);
array_push($extra, ...listDateRange('created_at', $dateFrom, $dateTo, true));
$filtersOn    = listActiveCount([isOwner() ? $filterBranch : 0, $filterStatus, $search, $dateFrom, $dateTo, $sortKey !== 'oldest']);

// One parallel wave: the list + branch dropdown + navbar bell (controllers/admin_batch.php)
admin_prefetch(['list' => admin_q('patients', implode('&', patientRecordsQuery($page, $filterBranch, $filterStatus, $sortOrder, $extra)), ['Prefer: count=exact'])]);
$branches     = getAllBranches();
$data         = getPatientRecords($page, $filterBranch, $filterStatus, $sortOrder, $extra);
$patients     = $data['rows']         ?? [];
$totalRecords = $data['totalRecords'] ?? 0;
$totalPages   = $data['totalPages']   ?? 1;
$from         = $data['from']         ?? 0;
$to           = $data['to']           ?? 0;

// Admin/Dentist "Last Visit (Date/Time)": patients.last_visit only stores a date.
// A time is shown only when the patient's latest completed appointment falls on
// that same date (so the time is real, never guessed).
$lastVisitTimes = [];
if (!isOwner() && $patients) {
    $ids = implode(',', array_filter(array_map(fn($p) => (int)($p['patient_id'] ?? 0), $patients)));
    if ($ids !== '') {
        $lvRes = supabase_request('appointments', 'GET', [],
            'select=patient_id,appointment_date,appointment_time&status=eq.completed'
            . '&patient_id=in.(' . $ids . ')&order=appointment_date.desc,appointment_time.desc');
        foreach (($lvRes['data'] ?? []) as $r) {
            $pid = (int)$r['patient_id'];
            if (!isset($lastVisitTimes[$pid])) $lastVisitTimes[$pid] = $r;   // first row = latest
        }
    }
}

$baseUrl = listUrl('patients-list.php', ['branch' => $filterBranch, 'status' => $filterStatus, 'q' => $search, 'from' => $dateFrom, 'to' => $dateTo, 'sort' => $sortKey === 'oldest' ? '' : $sortKey]);
$sortBase = listUrl('patients-list.php', ['branch' => $filterBranch, 'status' => $filterStatus, 'q' => $search, 'from' => $dateFrom, 'to' => $dateTo]);

?>
<!DOCTYPE html>
<html lang="zxx">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Patients List</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css?v=5">
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
                    <div class="page-header-title">
                        <h5 class="m-b-10">Patients</h5>
                    </div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">List</li>
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
                                    Patients List
                                    <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span>
                                </h5>
                                <div class="card-header-action">
                                    <div class="card-header-btn">
                                        <div data-bs-toggle="tooltip" title="Refresh">
                                            <a href="patients-list.php" class="avatar-text avatar-xs bg-warning"></a>
                                        </div>
                                        <div data-bs-toggle="tooltip" title="Maximize/Minimize">
                                            <a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="list-toolbar">
                                <?= listSearchControl($search, 'Name, patient code or contact') ?>
                            <div class="list-field"><label class="list-label" for="filterStatus">Status</label>                            <select id="filterStatus" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="">All Statuses</option>
                                <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select></div>
                            <?php if (isOwner()): ?>
                                <div class="list-field"><label class="list-label" for="filterBranch">Branch</label>
                                <select id="filterBranch" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?= (int)$b['branch_id'] ?>"
                                            <?= (int)$b['branch_id'] === $filterBranch ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($b['branch_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select></div>
                            <?php endif; ?>
                                <?= listDateRangeControl($dateFrom, $dateTo, 'Registration Date') ?>
                                <?= listResetControl('patients-list.php', $filtersOn) ?>
                            </div>
                            <div class="card-body custom-card-action p-0">
                                <!-- pat-table-wrapper mirrors apt-table-wrapper / trt-table-wrapper -->
                                <div class="table-responsive pat-table-wrapper">
                                    <table class="table table-hover mb-0 pat-patients-table">
                                        <thead>
                                            <tr>
                                                <?= listTh('Patient ID', 'code_asc', 'code_desc', $sortKey, $sortBase) ?>
                                                <?= listTh('Patient Name', 'name_asc', 'name_desc', $sortKey, $sortBase) ?>
                                                <?php if (isOwner()): ?>
                                                    <?= listTh('Branch', 'branch_asc', 'branch_desc', $sortKey, $sortBase) ?>
                                                    <?= listTh('Last Visit', 'visit_desc', 'visit_asc', $sortKey, $sortBase) ?>
                                                <?php else: ?>
                                                    <?= listTh('Last Visit (Date/Time)', 'visit_desc', 'visit_asc', $sortKey, $sortBase) ?>
                                                <?php endif; ?>
                                                <?= listTh('Status', 'status_asc', 'status_desc', $sortKey, $sortBase) ?>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($patients)): ?>
                                                <tr>
                                                    <td colspan="<?= isOwner() ? 6 : 5 ?>" class="text-center py-4 text-muted">
                                                        <i class="feather-users me-2"></i><?= $filtersOn ? 'No patients match the selected filters. <a href="patients-list.php" class="fw-semibold">Reset filters</a>' : 'No patients found.' ?>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($patients as $p): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="fw-semibold"><?= htmlspecialchars($p['patient_code'] ?? '—') ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <div class="avatar-text avatar-sm bg-soft-primary text-primary rounded-circle">
                                                                    <?= strtoupper(substr($p['full_name'] ?? 'P', 0, 1)) ?>
                                                                </div>
                                                                <a href="patient-records.php?id=<?= (int)($p['patient_id'] ?? 0) ?>"
                                                                    class="text-dark fw-semibold text-decoration-none">
                                                                    <?= htmlspecialchars($p['full_name'] ?? '—') ?>
                                                                </a>
                                                            </div>
                                                        </td>
                                                        <?php if (isOwner()): ?>
                                                            <td><?= htmlspecialchars($p['branches']['branch_name'] ?? '—') ?></td>
                                                            <td><?= formatPatLastVisit($p['last_visit'] ?? null) ?></td>
                                                        <?php else: ?>
                                                            <?php
                                                            $lv   = $lastVisitTimes[(int)($p['patient_id'] ?? 0)] ?? null;
                                                            $lvTm = ($lv && !empty($p['last_visit']) && $lv['appointment_date'] === $p['last_visit'] && !empty($lv['appointment_time']))
                                                                ? date('g:i A', strtotime($lv['appointment_time'])) : '';
                                                            ?>
                                                            <td>
                                                                <div class="pat-bd-date"><?= formatPatLastVisit($p['last_visit'] ?? null) ?></div>
                                                                <?php if ($lvTm): ?><div class="pat-bd-age"><?= $lvTm ?></div><?php endif; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <td>
                                                            <span class="badge <?= patStatusBadge($p['status'] ?? null) ?>">
                                                                <?= ucfirst($p['status'] ?? 'active') ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-end">
                                                            <div class="dropdown">
                                                                <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto"
                                                                    data-bs-toggle="dropdown" data-bs-strategy="fixed">
                                                                    <i class="feather-more-vertical"></i>
                                                                </a>
                                                                <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                                    <a href="patient-records.php?id=<?= (int)($p['patient_id'] ?? 0) ?>"
                                                                        class="dropdown-item">
                                                                        <i class="feather-eye"></i> View Details
                                                                    </a>
                                                                    <?php if (isOwner()): ?>
                                                                    <a href="javascript:void(0);" class="dropdown-item text-primary"
                                                                        onclick='editPatient(<?= json_encode([
                                                                            "patient_id"     => $p["patient_id"],
                                                                            "full_name"      => $p["full_name"] ?? "",
                                                                            "contact_number" => $p["contact_number"] ?? "",
                                                                            "gender"         => $p["gender"] ?? "",
                                                                            "birthdate"      => $p["birthdate"] ?? "",
                                                                            "last_visit"     => $p["last_visit"] ?? "",
                                                                            "branch_id"      => $p["branch_id"] ?? "",
                                                                            "status"         => $p["status"] ?? "active",
                                                                        ], JSON_HEX_QUOT | JSON_HEX_TAG) ?>)'>
                                                                        <i class="feather-edit-2"></i> Edit
                                                                    </a>
                                                                    <a href="appointments-schedule.php?patient_id=<?= (int)($p['patient_id'] ?? 0) ?>"
                                                                        class="dropdown-item">
                                                                        <i class="feather-calendar"></i> View Appointments
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
                                    Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> patient(s)
                                    <?php if ($filtersOn): ?>
                                        &nbsp;·&nbsp;
                                        <a href="patients-list.php" class="text-danger">
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
                                        <?php
                                        $wStart = max(1, $page - 2);
                                        $wEnd   = min($totalPages, $wStart + 4);
                                        $wStart = max(1, $wEnd - 4);
                                        ?>
                                        <?php if ($wStart > 1): ?>
                                            <li><a href="<?= $baseUrl ?>&page=1">1</a></li>
                                            <?php if ($wStart > 2): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?>
                                        <?php endif; ?>
                                        <?php for ($pg = $wStart; $pg <= $wEnd; $pg++): ?>
                                            <li>
                                                <a href="<?= $baseUrl ?>&page=<?= $pg ?>"
                                                    class="<?= $pg === $page ? 'active' : '' ?>">
                                                    <?= $pg ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        <?php if ($wEnd < $totalPages): ?>
                                            <?php if ($wEnd < $totalPages - 1): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?>
                                            <li><a href="<?= $baseUrl ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a></li>
                                        <?php endif; ?>
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

    <!-- ADD / EDIT PATIENT MODAL (Owner: Edit action) -->
    <?php if (isOwner()): ?>
    <div class="modal fade" id="patientModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="patModalTitle">Add Patient</h5>
                        <p class="text-muted small mb-0">Fill in the patient's information below.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="patModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="pat_id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
                            <input type="text" id="pat_name" class="form-control" placeholder="e.g. Juan Dela Cruz">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Contact Number</label>
                            <input type="text" id="pat_contact" class="form-control" placeholder="+63 9XX XXX XXXX">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Gender</label>
                            <select id="pat_gender" class="form-select js-custom-select" data-cs-float>
                                <option value="">— Select —</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Non-binary">Non-binary</option>
                                <option value="Another gender">Another gender</option>
                                <option value="Prefer not to say">Prefer not to say</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Birthdate</label>
                            <input type="date" id="pat_birthdate" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Last Visit</label>
                            <input type="date" id="pat_last_visit" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Branch <span class="text-danger">*</span></label>
                            <select id="pat_branch" class="form-select js-custom-select" data-cs-float data-cs-search>
                                <option value=""> Select Branch </option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?= (int)$b['branch_id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Status</label>
                            <select id="pat_status" class="form-select js-custom-select" data-cs-float>
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
                        <button type="button" class="btn btn-primary px-4" id="patSaveBtn" onclick="savePatient()">
                            <i class="feather-save me-1"></i> Save
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
    <script src="assets/js/patients.js"></script>
    <script>
        function applyFilters() {
            window._applyFilters('patients-list.php', {
                branch: 'filterBranch',
                status: 'filterStatus'
            });
        }
    </script>
</body>

</html>