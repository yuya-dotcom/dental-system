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
require_once __DIR__ . '/controllers/record_ui.php';   // Appointment Records cell markup
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying

// Branch scope. Patients have no permanent branch: a patient belongs to a
// branch's list when they have an appointment there (patient_controller.php).
// Admin / Dentist: always the branch of their session — a ?branch= in the URL
// is ignored. Owner: every branch, or the one picked in the Branch filter.
$filterBranch = scopedBranch(isOwner() ? (int)($_GET['branch'] ?? 0) : 0);
$filterStatus = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$page         = max(1, (int)($_GET['page'] ?? 1));


// Sort (table headers) / search / registration date (validated in list_filters.php)
$sortOptions  = [
    'oldest'      => ['Oldest Registered',        'created_at.asc,patient_id.asc'],
    'code_asc'    => ['Patient ID (A–Z)',         'patient_code.asc,patient_id.asc'],
    'code_desc'   => ['Patient ID (Z–A)',         'patient_code.desc,patient_id.desc'],
    'name_asc'    => ['Name (A–Z)',               'full_name.asc,patient_id.asc'],
    'name_desc'   => ['Name (Z–A)',               'full_name.desc,patient_id.desc'],
    // Latest-visit sorts (and its branch) are computed by getPatientRecords();
    // the order clause here is only a placeholder for them.
    'branch_asc'  => ['Branch (A–Z)',             'patient_id.asc'],
    'branch_desc' => ['Branch (Z–A)',             'patient_id.asc'],
    'visit_desc'  => ['Last Visit (Most Recent)', 'patient_id.asc'],
    'visit_asc'   => ['Last Visit (Oldest)',      'patient_id.asc'],
    'status_asc'  => ['Status (A–Z)',             'status.asc,full_name.asc'],
    'status_desc' => ['Status (Z–A)',             'status.desc,full_name.asc'],
];
if (!isOwner()) unset($sortOptions['branch_asc'], $sortOptions['branch_desc']);   // no Branch column
[$sortKey, $sortOrder] = listSort($sortOptions, 'oldest');
$search       = listSearch();
$dateFrom     = listDate('from');
$dateTo       = listDate('to');
$extra        = listSearchFilter($search, ['full_name', 'patient_code', 'contact_number']);
array_push($extra, ...listDateRange('created_at', $dateFrom, $dateTo, true));
$filtersOn    = listActiveCount([isOwner() ? $filterBranch : 0, $filterStatus, $search, $dateFrom, $dateTo, $sortKey !== 'oldest']);

// One parallel wave: the list (with each patient's latest visit embedded) +
// branch dropdown + navbar bell (controllers/admin_batch.php)
admin_prefetch(['list' => patDerivedSort($sortKey)
    ? admin_q('patients', implode('&', patientRecordsAllQuery($filterBranch, $filterStatus, $extra)) . '&limit=1000&offset=0')
    : admin_q('patients', implode('&', patientRecordsQuery($page, $filterBranch, $filterStatus, $sortOrder, $extra)), ['Prefer: count=exact'])]);
$branches     = getAllBranches();
$data         = getPatientRecords($page, $filterBranch, $filterStatus, $sortOrder, $extra, $sortKey);
$patients     = $data['rows']         ?? [];
$totalRecords = $data['totalRecords'] ?? 0;
$totalPages   = $data['totalPages']   ?? 1;
$from         = $data['from']         ?? 0;
$to           = $data['to']           ?? 0;

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
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
    <link rel="stylesheet" href="assets/css/es-admin.css?v=8">
    <link rel="stylesheet" href="assets/css/es-records.css?v=7">
    <link rel="stylesheet" href="assets/css/analytics.css?v=8">
    <link rel="stylesheet" href="assets/css/appointments-records.css?v=10">
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

            <div class="main-content an-page ar-page">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card stretch stretch-full">

                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    Patients List
                                    <span class="an-pill info ms-2"><?= $totalRecords ?> <?= $filtersOn ? 'found' : 'total' ?></span>
                                    <span class="rec-card-sub">Every registered patient<?= isOwner() ? '' : ' seen at your branch' ?> · open a name to see their full record</span>
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
                                    <table class="table table-hover mb-0 pat-patients-table rec-table ar-table">
                                        <thead>
                                            <tr>
                                                <?php /* Owner: Last Visit · Patient (name + ID) · Branch · Status · Action
                                                         Admin/Dentist: the same without Branch (their own branch only) */ ?>
                                                <?= listTh('Last Visit Date/Time', 'visit_desc', 'visit_asc', $sortKey, $sortBase) ?>
                                                <?= listTh('Patient', 'name_asc', 'name_desc', $sortKey, $sortBase) ?>
                                                <?php if (isOwner()): ?>
                                                    <?= listTh('Branch', 'branch_asc', 'branch_desc', $sortKey, $sortBase) ?>
                                                <?php endif; ?>
                                                <?= listTh('Status', 'status_asc', 'status_desc', $sortKey, $sortBase) ?>
                                                <th class="rec-col-action text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($patients)): ?>
                                                <?= listEmpty(isOwner() ? 5 : 4, (bool)$filtersOn, 'No patients yet', 'Patients appear here once they register in the Patient Portal or are booked at the clinic.', 'feather-users', 'patients-list.php') ?>
                                            <?php else: ?>
                                                <?php foreach ($patients as $p): ?>
                                                    <tr>
                                                        <td>
                                                            <?php $lvTm = formatPatVisitTime($p['last_visit_time'] ?? null); ?>
                                                            <?= recWhen(strip_tags((string)formatPatLastVisit($p['last_visit_date'] ?? null)), ($lvTm && !empty($p['last_visit_date'])) ? (string)$lvTm : '') ?>
                                                        </td>
                                                        <td>
                                                            <?php $pn = (string)($p['full_name'] ?? ''); ?>
                                                            <?= recWho($pn, (int)($p['patient_id'] ?? 0), 'patient-records.php?id=' . (int)($p['patient_id'] ?? 0), (string)($p['patient_code'] ?? '')) ?>
                                                        </td>
                                                        <?php if (isOwner()): ?>
                                                            <td><?= recBranch($p['last_visit_branch'] ?? '', $branches ?? []) ?></td>
                                                        <?php endif; ?>
                                                        <td>
                                                            <?php $pst = strtolower((string)($p['status'] ?? 'active')) === 'inactive' ? 'inactive' : 'active'; ?>
                                                            <?= recPill(ucfirst($pst), $pst === 'active' ? 'up' : 'slate') ?>
                                                        </td>
                                                        <td class="rec-col-action">
                                                            <div class="dropdown">
                                                                <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto"
                                                                    data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-label="Actions for <?= htmlspecialchars($pn) ?>">
                                                                    <i class="feather-more-vertical"></i>
                                                                </a>
                                                                <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                                    <a href="patient-records.php?id=<?= (int)($p['patient_id'] ?? 0) ?>"
                                                                        class="dropdown-item">
                                                                        <i class="feather-eye"></i> View Details
                                                                    </a>
                                                                    <?php if (isOwner()): ?>
                                                                    <?php /* This patient's Appointment Records; no records → notice (patCheckApts) */ ?>
                                                                    <a href="appointments-records.php?patient=<?= (int)($p['patient_id'] ?? 0) ?>"
                                                                        class="dropdown-item" data-es-full
                                                                        data-has-apts="<?= ($p['has_appointments'] ?? null) === false ? '0' : '1' ?>"
                                                                        data-patient-name="<?= htmlspecialchars($pn) ?>"
                                                                        onclick="return patCheckApts(this, event)">
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

                            <div class="card-footer ar-foot d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <?= listFootCount($from, $to, $totalRecords, 'patient', 'patients-list.php', (bool)$filtersOn) ?>
                                <?= listPager($baseUrl, $page, $totalPages) ?>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/admin.js?v=7"></script>
    <script src="assets/js/es-swal.js?v=3"></script>
    <script src="assets/js/es-ajax.js?v=7"></script>
    <script src="assets/js/custom-select.js?v=3"></script>
    <script src="assets/js/es-records.js?v=3"></script>
    <script>
        function applyFilters() {
            window._applyFilters('patients-list.php', {
                branch: 'filterBranch',
                status: 'filterStatus'
            });
        }

        // View Appointments → this patient's Appointment Records. A patient with no
        // appointment at all gets a notice instead of an empty list.
        function patCheckApts(link, ev) {
            if (link.dataset.hasApts !== '0') return true;          // follow the link
            ev.preventDefault();
            const name = link.dataset.patientName || 'This patient';
            Swal.fire({
                icon: 'info',
                iconHtml: '<i class="feather-calendar es-swal-glyph"></i>',
                title: 'No Appointment Records Yet',
                html: '<p class="es-swal-text"><strong>' + _esc(name) + '</strong> has no appointment records yet. '
                    + 'Their appointments will be listed here once one is booked in the Patient Portal or added at the clinic.</p>',
                confirmButtonText: 'OK',
                buttonsStyling: false,
                customClass: window.ES_SWAL_CLASS,
            });
            return false;
        }
        function _esc(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }
    </script>
</body>

</html>