<?php
// =============================================================
//  appointments-records.php  — FIXED
//  Fixes applied:
//   1. Patient link: patient-record.php → patient-records.php
//   2. checked_in added to status filter + badge
//   3. deleteAppointment() JS properly wired to appointment_crud.php
//   4. aptStatusBadgeClass() / aptStatusDisplay() handle checked_in
//   5. Patient name normalised to title case (aptNameCase / _arNameCase)
//   6. no_show status added to badge helpers + filter dropdown
//   7. Edit action removed: Appointment Records is history-only;
//      editing stays on appointments-schedule.php.
//   8. Appointment Record Details modal: the four info sections
//      (Patient / Appointment / Treatment / Billing) are now cards
//      in a 2 x 2 grid. Same fields, same element IDs, same JS.
//      Card styles live in assets/css/custom.css (.ar-card*).
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/appointment_controller.php';
require_once __DIR__ . '/controllers/appointment_services_helper.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/dbconfig.php';
require_once __DIR__ . '/controllers/list_filters.php';

$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : getSessionBranch();
if (!isOwner() && $filterBranch !== getSessionBranch()) $filterBranch = getSessionBranch();
$branches     = getAllBranches();
$filterStatus = in_array($_GET['status'] ?? '', ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show'], true) ? $_GET['status'] : '';
$page         = max(1, (int)($_GET['page'] ?? 1));

// Sort (table headers) / search / appointment date (validated in list_filters.php)
$sortOptions  = [
    'date_asc'     => ['Appointment Date (Soonest)', 'appointment_date.asc,appointment_time.asc,appointment_id.asc'],
    'date_desc'    => ['Appointment Date (Latest)',  'appointment_date.desc,appointment_time.desc,appointment_id.desc'],
    'patient_asc'  => ['Patient (A–Z)', 'patients(full_name).asc.nullslast,appointment_id.asc'],
    'patient_desc' => ['Patient (Z–A)', 'patients(full_name).desc.nullslast,appointment_id.asc'],
    'status_asc'   => ['Status (A–Z)',               'status.asc,appointment_date.asc'],
    'status_desc'  => ['Status (Z–A)',               'status.desc,appointment_date.asc'],
    'code_asc'     => ['Appointment ID (A–Z)',       'appointment_code.asc'],
    'code_desc'    => ['Appointment ID (Z–A)',       'appointment_code.desc'],
    'branch_asc'   => ['Branch (A–Z)',               'branches(branch_name).asc.nullslast,appointment_date.asc'],
    'branch_desc'  => ['Branch (Z–A)',               'branches(branch_name).desc.nullslast,appointment_date.asc'],
];
[$sortKey, $sortOrder] = listSort($sortOptions, 'date_asc');
$search        = listSearch();
// Owner: From–To range. Admin/Dentist: a single Appointment Date (?date=).
$aptDate       = isOwner() ? '' : listDate('date');
$dateFrom      = isOwner() ? listDate('from') : $aptDate;
$dateTo        = isOwner() ? listDate('to')   : $aptDate;
$extra         = listSearchFilter($search, ['appointment_code']);
array_push($extra, ...listDateRange('appointment_date', $dateFrom, $dateTo));
$filtersOn     = listActiveCount([isOwner() ? $filterBranch : 0, $filterStatus, $search, $dateFrom, isOwner() ? $dateTo : '', $sortKey !== 'date_asc']);

$data         = getAppointmentRecords($page, $filterBranch, $filterStatus, $sortOrder, $extra);
$appointments = attachAppointmentServiceNames($data['rows']);   // all 1–3 services per row
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];

$dateParams = isOwner() ? ['from' => $dateFrom, 'to' => $dateTo] : ['date' => $aptDate];
$baseUrl = listUrl('appointments-records.php', ['branch' => isOwner() ? $filterBranch : 0, 'status' => $filterStatus, 'q' => $search] + $dateParams + ['sort' => $sortKey === 'date_asc' ? '' : $sortKey]);
$sortBase = listUrl('appointments-records.php', ['branch' => isOwner() ? $filterBranch : 0, 'status' => $filterStatus, 'q' => $search] + $dateParams);

// ── Local badge helpers (mirror appointment_controller values) ─
function aptStatusBadgeClass(string $s): string
{
    return match ($s) {
        'pending'    => 'bg-soft-warning text-warning',
        'confirmed'  => 'bg-soft-success text-success',
        'checked_in' => 'bg-soft-primary text-primary-emphasis',
        'completed'  => 'bg-soft-info text-info',
        'cancelled'  => 'bg-soft-danger text-danger',
        'no_show'    => 'bg-soft-secondary text-secondary',
        default      => 'bg-soft-secondary text-secondary',
    };
}
function aptStatusDisplay(string $s): string
{
    return match ($s) {
        'checked_in' => 'Checked In',
        'no_show'    => 'No Show',
        default      => ucfirst($s),
    };
}

// Display-only name cleanup: "JuAN  DeLa cruz" → "Juan Dela Cruz".
// Capitalises after spaces, hyphens and apostrophes (Mary-Ann, O'Brien).
function aptNameCase(?string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '') return '—';
    return preg_replace_callback(
        "/(^|[\\s\\-'’])(\\p{L})/u",
        fn($m) => $m[1] . mb_strtoupper($m[2], 'UTF-8'),
        mb_strtolower($name, 'UTF-8')
    );
}
?>
<!DOCTYPE html>
<html lang="zxx">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Appointment Records</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/select2.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/select2-theme.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css?v=5">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=4">
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
                        <h5 class="m-b-10">Appointments</h5>
                    </div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Records</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <a href="appointments-schedule.php" class="btn btn-outline-primary hdr-btn">
                                <i class="feather-calendar me-1"></i> Go to Schedule
                            </a>
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
                                    Appointment Records
                                    <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span>
                                </h5>
                                <div class="card-header-action">
                                    <div class="card-header-btn">
                                        <div data-bs-toggle="tooltip" title="Refresh">
                                            <a href="appointments-records.php" class="avatar-text avatar-xs bg-warning"></a>
                                        </div>
                                        <div data-bs-toggle="tooltip" title="Maximize/Minimize">
                                            <a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="list-toolbar">
                                <?= listSearchControl($search, 'Appointment code') ?>
                                <?php if (isOwner()): ?>
                                    <?= listDateRangeControl($dateFrom, $dateTo, 'Appointment Date') ?>
                                <?php else: ?>
                                    <?= listField('Appointment Date', '<input type="date" id="listDate" class="js-date-picker" data-cs-filter data-list-param="date" value="' . htmlspecialchars($aptDate) . '" placeholder="Select date" onchange="applyFilters()">', 'listDate') ?>
                                <?php endif; ?>
                            <div class="list-field"><label class="list-label" for="filterStatus">Status</label>                            <select id="filterStatus" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="">All Statuses</option>
                                <option value="pending" <?= $filterStatus === 'pending'    ? 'selected' : '' ?>>Pending</option>
                                <option value="confirmed" <?= $filterStatus === 'confirmed'  ? 'selected' : '' ?>>Confirmed</option>
                                <option value="checked_in" <?= $filterStatus === 'checked_in' ? 'selected' : '' ?>>Checked In</option>
                                <option value="completed" <?= $filterStatus === 'completed'  ? 'selected' : '' ?>>Completed</option>
                                <option value="cancelled" <?= $filterStatus === 'cancelled'  ? 'selected' : '' ?>>Cancelled</option>
                                <option value="no_show" <?= $filterStatus === 'no_show'    ? 'selected' : '' ?>>No Show</option>
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
                                <?= listResetControl('appointments-records.php', $filtersOn) ?>
                            </div>
                            <div class="card-body custom-card-action p-0">
                                <div class="table-responsive apt-table-wrapper">
                                    <table class="table table-hover mb-0 apt-records-table">
                                        <thead>
                                            <tr>
                                                <?= listTh('Date / Time', 'date_asc', 'date_desc', $sortKey, $sortBase) ?>
                                                <?= listTh('Patient Name', 'patient_asc', 'patient_desc', $sortKey, $sortBase) ?>
                                                <?php if (isOwner()): ?>
                                                    <?= listTh('Branch', 'branch_asc', 'branch_desc', $sortKey, $sortBase) ?>
                                                <?php else: ?>
                                                    <th>Service</th>
                                                <?php endif; ?>
                                                <?= listTh('Status', 'status_asc', 'status_desc', $sortKey, $sortBase) ?>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($appointments)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center py-4 text-muted">
                                                        <i class="feather-calendar me-2"></i><?= $filtersOn ? 'No appointments match the selected filters. <a href="appointments-records.php" class="fw-semibold">Reset filters</a>' : 'No records found.' ?>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($appointments as $apt): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="fw-semibold"><?= formatAptDate($apt['appointment_date']) ?></div>
                                                            <div class="text-muted small"><?= formatAptTime($apt['appointment_time']) ?></div>
                                                        </td>
                                                        <td>
                                                            <!-- ★ FIX 1: correct filename patient-records.php + cast to int -->
                                                            <!-- Patient name normalised to "Juan Dela Cruz" (aptNameCase) -->
                                                            <a href="patient-records.php?id=<?= (int)($apt['patient_id'] ?? 0) ?>"
                                                                class="text-primary fw-semibold">
                                                                <?= htmlspecialchars(aptNameCase($apt['patients']['full_name'] ?? '')) ?>
                                                            </a>
                                                        </td>
                                                        <?php if (isOwner()): ?>
                                                            <td><?= htmlspecialchars($apt['branches']['branch_name'] ?? '—') ?></td>
                                                        <?php else: ?>
                                                            <td><?= htmlspecialchars(appointmentServicesLabel($apt, 'General Consultation')) ?></td>
                                                        <?php endif; ?>
                                                        <!-- ★ FIX 2: checked_in badge -->
                                                        <td>
                                                            <span class="badge <?= aptStatusBadgeClass($apt['status'] ?? '') ?>">
                                                                <?= aptStatusDisplay($apt['status'] ?? '') ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-end">
                                                            <div class="dropdown">
                                                                <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed">
                                                                    <i class="feather-more-vertical"></i>
                                                                </a>
                                                                <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                                    <a href="javascript:void(0);" class="dropdown-item"
                                                                        onclick="openRecordModal(<?= (int)$apt['appointment_id'] ?>)">
                                                                        <i class="feather-eye"></i> View Details
                                                                    </a>
                                                                    <div class="dropdown-divider"></div>
                                                                    <?php if ($apt['status'] === 'pending'): ?>
                                                                        <a href="javascript:void(0);" class="dropdown-item text-success"
                                                                            onclick="updateStatus(<?= (int)$apt['appointment_id'] ?>, 'confirmed', '<?= htmlspecialchars($apt['appointment_code'], ENT_QUOTES) ?>')">
                                                                            <i class="feather-check-circle"></i> Confirm
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (in_array($apt['status'], ['pending', 'confirmed'])): ?>
                                                                        <a href="javascript:void(0);" class="dropdown-item text-primary"
                                                                            onclick="updateStatus(<?= (int)$apt['appointment_id'] ?>, 'completed', '<?= htmlspecialchars($apt['appointment_code'], ENT_QUOTES) ?>')">
                                                                            <i class="feather-check"></i> Mark Completed
                                                                        </a>
                                                                        <a href="javascript:void(0);" class="dropdown-item text-warning"
                                                                            onclick="updateStatus(<?= (int)$apt['appointment_id'] ?>, 'cancelled', '<?= htmlspecialchars($apt['appointment_code'], ENT_QUOTES) ?>')">
                                                                            <i class="feather-x-circle"></i> Cancel
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (!isDentist()): ?>
                                                                        <!-- ★ FIX 3: delete passes numeric id (no quotes needed) -->
                                                                        <a href="javascript:void(0);" class="dropdown-item text-danger"
                                                                            onclick="deleteAppointment(<?= (int)$apt['appointment_id'] ?>, '<?= htmlspecialchars($apt['appointment_code'], ENT_QUOTES) ?>')">
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
                                    Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> record(s)
                                    <?php if ($filtersOn): ?>
                                        &nbsp;·&nbsp;<a href="appointments-records.php" class="text-danger">
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
                                        <?php for ($p = $wStart; $p <= $wEnd; $p++): ?>
                                            <li><a href="<?= $baseUrl ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a></li>
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


    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/es-swal.js?v=2"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
    <script src="assets/js/appointments.js?v=6"></script>
    <script>
        function applyFilters() {
            window._applyFilters('appointments-records.php', {
                branch: 'filterBranch',
                status: 'filterStatus'
            });
        }

        // ★ FIX 3 — fully working delete with SweetAlert2 + fetch
        function deleteAppointment(appointmentId, code) {
            Swal.fire({
                title: 'Delete Appointment?',
                html: `Appointment <strong>${code}</strong> will be permanently removed.<br>
                         <span class="text-danger small">This cannot be undone.</span>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel',
            }).then(result => {
                if (!result.isConfirmed) return;

                fetch('api/appointment_crud.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'delete',
                            appointment_id: appointmentId
                        }),
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Deleted!',
                                text: res.message || 'Appointment removed.',
                                timer: 1600,
                                showConfirmButton: false,
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('Cannot Delete', res.message || 'An error occurred.', 'error');
                        }
                    })
                    .catch(() => Swal.fire('Error', 'Network error. Please try again.', 'error'));
            });
        }

        // ══════════════════════════════════════════════════
        //  APPOINTMENT RECORD DETAILS MODAL
        // ══════════════════════════════════════════════════
        let _arCurrentId = null;
        let _arCurrentApt = null;
        let _arModalInst = null;

        function openRecordModal(appointmentId) {
            _arCurrentId = appointmentId;
            _arCurrentApt = null;

            _arShow('arLoadingState');
            _arHide('arErrorState');
            _arHide('arContent');

            document.getElementById('arModalCode').textContent = '—';
            document.getElementById('arModalStatusBadge').textContent = '—';
            document.getElementById('arModalStatusBadge').className = 'badge';
            document.getElementById('arTimeline').innerHTML = '<div class="ar-timeline-empty text-muted"><i class="feather-clock me-1"></i> Loading…</div>';
            document.getElementById('arTreatmentTab').innerHTML = '<div class="ar-tab-empty text-muted"><i class="feather-activity me-1"></i> Loading…</div>';
            document.getElementById('arPaymentTab').innerHTML = '<div class="ar-tab-empty text-muted"><i class="feather-dollar-sign me-1"></i> Loading…</div>';
            document.getElementById('arAuditLog').innerHTML = '<div class="ar-tab-empty text-muted"><i class="feather-shield me-1"></i> Loading…</div>';

            if (!_arModalInst) {
                _arModalInst = new bootstrap.Modal(document.getElementById('aptRecordModal'), {
                    backdrop: 'static'
                });
            }
            _arModalInst.show();

            Promise.all([
                    fetch('api/appointments.php?id=' + appointmentId).then(r => r.json()),
                    fetch('api/billing.php?appointment_id=' + appointmentId).then(r => r.json()).catch(() => ({
                        success: false
                    })),
                    fetch('api/treatments.php?appointment_id=' + appointmentId).then(r => r.json()).catch(() => ({
                        success: false
                    })),
                ])
                .then(function(results) {
                    var aptRes = results[0];
                    var billRes = results[1];
                    var trtRes = results[2];
                    if (!aptRes.success) throw new Error(aptRes.message || 'Could not load appointment.');
                    _arCurrentApt = aptRes.data;
                    _arRenderModal(aptRes.data, billRes, trtRes);
                })
                .catch(function(err) {
                    document.getElementById('arErrorMsg').textContent = err.message || 'Failed to load appointment details.';
                    _arHide('arLoadingState');
                    _arShow('arErrorState');
                });
        }

        function _arRenderModal(apt, billRes, trtRes) {
            var code = apt.appointment_code || '—';
            var status = apt.status || '';
            document.getElementById('arModalCode').textContent = code;
            var badge = document.getElementById('arModalStatusBadge');
            badge.textContent = _arStatusLabel(status);
            badge.className = 'badge ' + _arStatusClass(status);

            // Summary row — same data as the cards below
            document.getElementById('arSumDate').textContent = apt.appointment_date ? _arFmtDate(apt.appointment_date) : '—';
            document.getElementById('arSumTime').textContent = apt.appointment_time ? _arFmtTime(apt.appointment_time) : '';
            var sumSvcs = (apt.appointment_services || []).map(function(s) {
                return (s.services && s.services.service_name) || '';
            }).filter(Boolean);
            if (!sumSvcs.length && apt.services && apt.services.service_name) sumSvcs = [apt.services.service_name];
            document.getElementById('arSumServices').innerHTML = sumSvcs.length ?
                sumSvcs.map(function(n) { return '<div>' + _arEsc(n) + '</div>'; }).join('') : '—';
            document.getElementById('arSumBranch').textContent = (apt.branches && apt.branches.branch_name) || '—';

            var pat = apt.patients || {};
            document.getElementById('arPatientName').textContent = _arNameCase(pat.full_name);
            document.getElementById('arPatientCode').textContent = pat.patient_code || '—';
            document.getElementById('arPatientContact').textContent = apt.resolved_contact || pat.contact_number || '—';
            document.getElementById('arPatientEmail').textContent = pat.email || '—';
            document.getElementById('arPatientDob').textContent = pat.date_of_birth ? _arFmtDate(pat.date_of_birth) : '—';
            document.getElementById('arPatientAddress').textContent = pat.address || '—';

            document.getElementById('arAptDate').textContent = apt.appointment_date ? _arFmtDate(apt.appointment_date) : '—';
            document.getElementById('arAptTime').textContent = apt.appointment_time ? _arFmtTime(apt.appointment_time) : '—';
            document.getElementById('arAptBranch').textContent = (apt.branches && apt.branches.branch_name) ? apt.branches.branch_name : '—';

            var aptSvcs = apt.appointment_services || [];
            if (aptSvcs.length > 0) {
                document.getElementById('arAptService').innerHTML =
                    aptSvcs.map(function(s) {
                        return '<span class="badge bg-soft-primary text-primary me-1">' + ((s.services && s.services.service_name) ? s.services.service_name : '—') + '</span>';
                    }).join('');
            } else {
                document.getElementById('arAptService').textContent = (apt.services && apt.services.service_name) ? apt.services.service_name : '—';
            }

            document.getElementById('arAptDentist').textContent = (apt.dentists && apt.dentists.full_name) ? apt.dentists.full_name : '—';
            document.getElementById('arAptStatus').innerHTML = '<span class="badge ' + _arStatusClass(status) + '">' + _arStatusLabel(status) + '</span>';
            document.getElementById('arAptType').textContent = apt.appointment_type || '—';

            var trt = null;
            if (trtRes && trtRes.success && trtRes.data) {
                trt = Array.isArray(trtRes.data) ? trtRes.data[0] : trtRes.data;
            }
            if (trt) {
                document.getElementById('arTrtService').textContent = (trt.services && trt.services.service_name) ? trt.services.service_name : ((apt.services && apt.services.service_name) ? apt.services.service_name : '—');
                document.getElementById('arTrtDesc').textContent = trt.description || '—';
                document.getElementById('arTrtDentist').textContent = (trt.dentists && trt.dentists.full_name) ? trt.dentists.full_name : ((apt.dentists && apt.dentists.full_name) ? apt.dentists.full_name : '—');
                document.getElementById('arTrtRemarks').textContent = trt.remarks || trt.notes || '—';
                document.getElementById('arTrtStatus').innerHTML = '<span class="badge ' + _arTrtStatusClass(trt.status || '') + '">' + _arTrtStatusLabel(trt.status || '') + '</span>';
            } else {
                ['arTrtService', 'arTrtDesc', 'arTrtDentist', 'arTrtRemarks'].forEach(function(id) {
                    document.getElementById(id).textContent = '—';
                });
                document.getElementById('arTrtStatus').innerHTML = '<span class="text-muted small">No treatment linked yet</span>';
            }

            var bill = null;
            if (billRes && billRes.success && billRes.data) {
                bill = Array.isArray(billRes.data) ? billRes.data[0] : billRes.data;
            }
            if (bill) {
                document.getElementById('arBillTotal').textContent = _arFmtMoney(bill.total_amount);
                document.getElementById('arBillDiscount').textContent = bill.discount_amount ? _arFmtMoney(bill.discount_amount) : '—';
                document.getElementById('arBillPaid').textContent = _arFmtMoney(bill.amount_paid);
                document.getElementById('arBillPayStatus').innerHTML = '<span class="badge ' + _arPayBadge(bill.payment_status) + '">' + _arCapFirst(bill.payment_status || 'unpaid') + '</span>';
                document.getElementById('arBillMethod').textContent = bill.payment_method || '—';
                document.getElementById('arBillTxn').textContent = bill.transaction_id || '—';
            } else {
                var payStatus = apt.payment_status || 'unpaid';
                document.getElementById('arBillTotal').textContent = '—';
                document.getElementById('arBillDiscount').textContent = '—';
                document.getElementById('arBillPaid').textContent = '—';
                document.getElementById('arBillPayStatus').innerHTML = '<span class="badge ' + _arPayBadge(payStatus) + '">' + _arCapFirst(payStatus) + '</span>';
                document.getElementById('arBillMethod').textContent = '—';
                document.getElementById('arBillTxn').textContent = '—';
            }

            _arBuildTimeline(apt, trt, bill);

            if (trt) {
                document.getElementById('arTreatmentTab').innerHTML = '<table class="ar-detail-table">' +
                    '<tr><th>Treatment Code</th><td>' + (trt.treatment_code || '—') + '</td></tr>' +
                    '<tr><th>Treatment Date</th><td>' + (trt.treatment_date ? _arFmtDate(trt.treatment_date) : '—') + '</td></tr>' +
                    '<tr><th>Service</th><td>' + ((trt.services && trt.services.service_name) ? trt.services.service_name : ((apt.services && apt.services.service_name) ? apt.services.service_name : '—')) + '</td></tr>' +
                    '<tr><th>Dentist</th><td>' + ((trt.dentists && trt.dentists.full_name) ? trt.dentists.full_name : '—') + '</td></tr>' +
                    '<tr><th>Status</th><td><span class="badge ' + _arTrtStatusClass(trt.status || '') + '">' + _arTrtStatusLabel(trt.status || '') + '</span></td></tr>' +
                    '<tr><th>Description</th><td>' + (trt.description || '—') + '</td></tr>' +
                    '<tr><th>Remarks</th><td>' + (trt.remarks || trt.notes || '—') + '</td></tr>' +
                    '</table>';
            } else {
                document.getElementById('arTreatmentTab').innerHTML = '<div class="ar-tab-empty text-muted"><i class="feather-activity me-1"></i> No treatment record linked to this appointment.</div>';
            }

            if (bill) {
                var balance = parseFloat(bill.total_amount || 0) - parseFloat(bill.amount_paid || 0);
                document.getElementById('arPaymentTab').innerHTML = '<table class="ar-detail-table">' +
                    '<tr><th>Invoice ID</th><td>' + (bill.bill_id || bill.billing_id || bill.invoice_id || '—') + '</td></tr>' +
                    '<tr><th>Bill Date</th><td>' + ((bill.bill_date || bill.created_at) ? _arFmtDate(bill.bill_date || bill.created_at) : '—') + '</td></tr>' +
                    '<tr><th>Total Amount</th><td class="fw-semibold">' + _arFmtMoney(bill.total_amount) + '</td></tr>' +
                    '<tr><th>Discount</th><td>' + (bill.discount_amount ? _arFmtMoney(bill.discount_amount) : '—') + '</td></tr>' +
                    '<tr><th>Amount Paid</th><td class="text-success fw-semibold">' + _arFmtMoney(bill.amount_paid) + '</td></tr>' +
                    '<tr><th>Balance</th><td class="' + (balance > 0 ? 'text-danger' : 'text-success') + '">' + _arFmtMoney(balance) + '</td></tr>' +
                    '<tr><th>Payment Status</th><td><span class="badge ' + _arPayBadge(bill.payment_status) + '">' + _arCapFirst(bill.payment_status || 'unpaid') + '</span></td></tr>' +
                    '<tr><th>Payment Method</th><td>' + (bill.payment_method || '—') + '</td></tr>' +
                    '<tr><th>Transaction ID</th><td class="text-muted">' + (bill.transaction_id || '—') + '</td></tr>' +
                    '</table>';
            } else {
                document.getElementById('arPaymentTab').innerHTML = '<div class="ar-tab-empty text-muted"><i class="feather-dollar-sign me-1"></i> No billing record found for this appointment.</div>';
            }

            var notes = (apt.notes || '').trim();
            document.getElementById('arNotes').innerHTML = notes ?
                '<p class="mb-0" style="white-space:pre-wrap;">' + _arEsc(notes) + '</p>' :
                '<span class="text-muted fst-italic">No notes recorded for this appointment.</span>';

            var created = apt.created_at || apt.appointment_date;
            document.getElementById('arAuditLog').innerHTML = '<table class="ar-detail-table">' +
                '<thead><tr><th>Event</th><th>Details</th><th>Date</th></tr></thead><tbody>' +
                '<tr><td><span class="badge bg-soft-primary text-primary">Created</span></td>' +
                '<td>Appointment ' + (apt.appointment_code || '') + ' created</td>' +
                '<td class="text-muted">' + (created ? _arFmtDate(created) : '—') + '</td></tr>' +
                (apt.updated_at && apt.updated_at !== apt.created_at ?
                    '<tr><td><span class="badge bg-soft-warning text-warning">Updated</span></td><td>Appointment last modified</td><td class="text-muted">' + _arFmtDate(apt.updated_at) + '</td></tr>' : '') +
                '</tbody></table>';

            _arHide('arLoadingState');
            _arHide('arErrorState');
            _arShow('arContent');
        }

        function _arBuildTimeline(apt, trt, bill) {
            var events = [];
            if (apt.created_at || apt.appointment_date) {
                events.push({
                    icon: 'fas fa-calendar-plus',
                    color: 'primary',
                    label: 'Appointment Created',
                    sub: apt.appointment_code || '',
                    date: apt.created_at || apt.appointment_date
                });
            }
            if (['confirmed', 'checked_in', 'completed'].indexOf(apt.status) !== -1) {
                events.push({
                    icon: 'fas fa-circle-check',
                    color: 'success',
                    label: 'Appointment Confirmed',
                    sub: '',
                    date: ''
                });
            }
            if (['checked_in', 'completed'].indexOf(apt.status) !== -1) {
                events.push({
                    icon: 'fas fa-user-check',
                    color: 'success',
                    label: 'Patient Checked In',
                    sub: '',
                    date: ''
                });
            }
            if (trt) {
                events.push({
                    icon: 'fas fa-file-medical',
                    color: 'primary',
                    label: 'Treatment Record Created',
                    sub: trt.treatment_code || '',
                    date: trt.created_at || ''
                });
            }
            if (bill) {
                events.push({
                    icon: 'fas fa-receipt',
                    color: 'success',
                    label: 'Billing Record Created',
                    sub: _arFmtMoney(bill.total_amount),
                    date: bill.created_at || ''
                });
            }
            if (apt.status === 'completed') {
                events.push({
                    icon: 'fas fa-flag-checkered',
                    color: 'success',
                    label: 'Appointment Completed',
                    sub: '',
                    date: apt.updated_at || ''
                });
            }
            if (apt.status === 'cancelled') {
                events.push({
                    icon: 'fas fa-circle-xmark',
                    color: 'danger',
                    label: 'Appointment Cancelled',
                    sub: '',
                    date: apt.updated_at || ''
                });
            }

            var el = document.getElementById('arTimeline');
            if (!events.length) {
                el.innerHTML = '<div class="ar-timeline-empty text-muted"><i class="feather-clock me-1"></i> No history available.</div>';
                return;
            }
            el.innerHTML = events.map(function(e, i) {
                return '<div class="ar-tl-item' + (i === events.length - 1 ? ' ar-tl-last' : '') + '">' +
                    '<div class="ar-tl-dot ar-tl-dot-' + e.color + '"><i class="' + e.icon + '"></i></div>' +
                    '<div class="ar-tl-body">' +
                    '<div class="ar-tl-label">' + e.label + '</div>' +
                    (e.sub ? '<div class="ar-tl-sub">' + e.sub + '</div>' : '') +
                    (e.date ? '<div class="ar-tl-date">' + _arFmtDate(e.date) + '</div>' : '') +
                    '</div></div>';
            }).join('');
        }

        function printAppointmentRecord() {
            if (!_arCurrentApt) return;
            var apt = _arCurrentApt;
            var pat = apt.patients || {};
            var wins = window.open('', '_blank', 'width=800,height=700');
            wins.document.write('<!DOCTYPE html><html><head><title>Appointment Record — ' + (apt.appointment_code || '') + '</title>' +
                '<style>body{font-family:Arial,sans-serif;font-size:13px;color:#111;padding:32px;}' +
                'h1{font-size:18px;margin-bottom:4px;}.sub{color:#6b7280;margin-bottom:24px;font-size:12px;}' +
                'table{width:100%;border-collapse:collapse;margin-bottom:20px;}' +
                'th,td{border:1px solid #e5e7eb;padding:7px 10px;text-align:left;}' +
                'th{background:#f9fafb;font-weight:600;color:#374151;width:38%;}' +
                'h2{font-size:13px;font-weight:700;color:#3454d1;margin:20px 0 8px;text-transform:uppercase;letter-spacing:.04em;}' +
                '@media print{button{display:none;}}</style></head><body>' +
                '<h1>Appointment Record</h1>' +
                '<div class="sub">' + (apt.appointment_code || '') + ' &nbsp;·&nbsp; Printed ' + new Date().toLocaleDateString('en-PH', {
                    dateStyle: 'long'
                }) + '</div>' +
                '<h2>Patient Information</h2>' +
                '<table>' +
                '<tr><th>Name</th><td>' + _arEsc(_arNameCase(pat.full_name)) + '</td></tr>' +
                '<tr><th>Patient ID</th><td>' + (pat.patient_code || '—') + '</td></tr>' +
                '<tr><th>Contact</th><td>' + (apt.resolved_contact || pat.contact_number || '—') + '</td></tr>' +
                '<tr><th>Email</th><td>' + (pat.email || '—') + '</td></tr>' +
                '</table>' +
                '<h2>Appointment Information</h2>' +
                '<table>' +
                '<tr><th>Date</th><td>' + (apt.appointment_date ? _arFmtDate(apt.appointment_date) : '—') + '</td></tr>' +
                '<tr><th>Time</th><td>' + (apt.appointment_time ? _arFmtTime(apt.appointment_time) : '—') + '</td></tr>' +
                '<tr><th>Branch</th><td>' + ((apt.branches && apt.branches.branch_name) ? apt.branches.branch_name : '—') + '</td></tr>' +
                '<tr><th>Status</th><td>' + _arStatusLabel(apt.status || '') + '</td></tr>' +
                '<tr><th>Type</th><td>' + (apt.appointment_type || '—') + '</td></tr>' +
                '</table>' +
                '<button onclick="window.print()">Print</button>' +
                '</body></html>');
            wins.document.close();
            wins.focus();
        }

        function _arShow(id) {
            var el = document.getElementById(id);
            if (el) el.classList.remove('d-none');
        }

        function _arHide(id) {
            var el = document.getElementById(id);
            if (el) el.classList.add('d-none');
        }

        function _arCapFirst(s) {
            return s ? s.charAt(0).toUpperCase() + s.slice(1) : (s || '');
        }

        // Same rule as PHP aptNameCase(): "JuAN DeLa" → "Juan Dela"
        function _arNameCase(name) {
            var s = String(name || '').replace(/\s+/g, ' ').trim();
            if (!s) return '—';
            return s.toLowerCase().replace(/(^|[\s\-'’])(\p{L})/gu, function(m, sep, ch) {
                return sep + ch.toUpperCase();
            });
        }

        function _arEsc(str) {
            var d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        function _arFmtDate(d) {
            if (!d) return '—';
            try {
                return new Date(d).toLocaleDateString('en-PH', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
            } catch (e) {
                return d;
            }
        }

        function _arFmtTime(t) {
            if (!t) return '—';
            try {
                return new Date('1970-01-01T' + t).toLocaleTimeString('en-PH', {
                    hour: 'numeric',
                    minute: '2-digit'
                });
            } catch (e) {
                return t;
            }
        }

        function _arFmtMoney(v) {
            var n = parseFloat(v);
            if (isNaN(n)) return '—';
            return '₱' + n.toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function _arStatusLabel(s) {
            return ({
                pending: 'Pending',
                confirmed: 'Confirmed',
                checked_in: 'Checked In',
                completed: 'Completed',
                cancelled: 'Cancelled'
            })[s] || _arCapFirst(s) || '—';
        }

        function _arStatusClass(s) {
            return ({
                pending: 'bg-soft-warning text-warning',
                confirmed: 'bg-soft-success text-success',
                checked_in: 'bg-soft-primary text-primary',
                completed: 'bg-soft-info text-info',
                cancelled: 'bg-soft-danger text-danger'
            })[s] || 'bg-soft-secondary text-secondary';
        }

        function _arTrtStatusLabel(s) {
            return ({
                pending: 'Pending',
                ongoing: 'Ongoing',
                completed: 'Completed',
                cancelled: 'Cancelled'
            })[s] || _arCapFirst(s) || '—';
        }

        function _arTrtStatusClass(s) {
            return ({
                pending: 'bg-soft-warning text-warning',
                ongoing: 'bg-soft-info text-info',
                completed: 'bg-soft-success text-success',
                cancelled: 'bg-soft-danger text-danger'
            })[s] || 'bg-soft-secondary text-secondary';
        }

        function _arPayBadge(s) {
            return ({
                paid: 'bg-soft-success text-success',
                partial: 'bg-soft-warning text-warning',
                unpaid: 'bg-soft-danger text-danger',
                refunded: 'bg-soft-secondary text-secondary'
            })[s] || 'bg-soft-secondary text-secondary';
        }
    </script>

    <!-- ══════════════════════════════════════════════════════════
         APPOINTMENT RECORD DETAILS MODAL
         Data: api/appointments.php?id=X  +  api/billing.php?appointment_id=X
               api/treatments.php?appointment_id=X
         ══════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="aptRecordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-record-dialog">
            <div class="modal-content apt-record-content">

                <!-- ── Header ── -->
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon">
                            <i class="feather-calendar"></i>
                        </div>
                        <div>
                            <h5 class="apt-record-title mb-0">Appointment Details</h5>
                            <div class="apt-record-meta">
                                Reference No. <span id="arModalCode" class="apt-record-code">—</span>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <!-- ── Body ── -->
                <div class="modal-body apt-record-body p-0" id="arModalBody">

                    <!-- Loading State -->
                    <div id="arLoadingState" class="apt-record-loading">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-3 text-muted">Loading appointment details…</p>
                    </div>

                    <!-- Error State -->
                    <div id="arErrorState" class="apt-record-error d-none">
                        <i class="feather-alert-circle text-danger fs-1 mb-3"></i>
                        <p class="text-muted" id="arErrorMsg">Failed to load appointment details.</p>
                        <button class="btn btn-sm btn-outline-primary" onclick="openRecordModal(_arCurrentId)">
                            <i class="feather-refresh-cw me-1"></i> Retry
                        </button>
                    </div>

                    <!-- Content (hidden until loaded) -->
                    <div id="arContent" class="d-none">

                        <!-- ★ FIX 8: §1–§4 as four information cards in a 2 × 2 grid.
                             Same fields and element IDs as before — only the wrappers changed.
                             Styles: assets/css/custom.css (.ar-card*). -->
                        <!-- Summary row (same layout as the patient portal's Appointment Details) -->
                        <div class="ar-summary">
                            <div class="ar-sum-item ar-sum-status">
                                <div class="ar-sum-label">Status</div>
                                <div><span id="arModalStatusBadge" class="badge">—</span></div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-calendar"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Date &amp; Time</div>
                                    <div class="ar-sum-value" id="arSumDate">—</div>
                                    <div class="ar-sum-value" id="arSumTime"></div>
                                </div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-layers"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Service(s)</div>
                                    <div class="ar-sum-value ar-sum-services" id="arSumServices">—</div>
                                </div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-map-pin"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Branch</div>
                                    <div class="ar-sum-value" id="arSumBranch">—</div>
                                </div>
                            </div>
                        </div>

                        <div class="ar-cards-grid">

                            <!-- §1 Patient Information -->
                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-user"></i></span>
                                    <h6 class="ar-card-title">Patient Information</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Patient Name</span>
                                        <span class="ar-card-value fw-semibold" id="arPatientName">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Patient ID</span>
                                        <span class="ar-card-value" id="arPatientCode">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Contact</span>
                                        <span class="ar-card-value" id="arPatientContact">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Email</span>
                                        <span class="ar-card-value" id="arPatientEmail">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Date of Birth</span>
                                        <span class="ar-card-value" id="arPatientDob">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Address</span>
                                        <span class="ar-card-value" id="arPatientAddress">—</span>
                                    </div>
                                </div>
                            </section>

                            <!-- §2 Appointment Information -->
                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-calendar"></i></span>
                                    <h6 class="ar-card-title">Appointment Information</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Date</span>
                                        <span class="ar-card-value" id="arAptDate">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Time</span>
                                        <span class="ar-card-value" id="arAptTime">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Branch</span>
                                        <span class="ar-card-value" id="arAptBranch">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Service(s)</span>
                                        <span class="ar-card-value" id="arAptService">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Dentist</span>
                                        <span class="ar-card-value" id="arAptDentist">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Status</span>
                                        <span class="ar-card-value" id="arAptStatus">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Type</span>
                                        <span class="ar-card-value" id="arAptType">—</span>
                                    </div>
                                </div>
                            </section>

                            <!-- §3 Treatment Details -->
                            <section class="ar-card" id="arTreatmentContent">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-activity"></i></span>
                                    <h6 class="ar-card-title">Treatment Details</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Service</span>
                                        <span class="ar-card-value" id="arTrtService">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Description</span>
                                        <span class="ar-card-value" id="arTrtDesc">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Dentist</span>
                                        <span class="ar-card-value" id="arTrtDentist">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Remarks</span>
                                        <span class="ar-card-value" id="arTrtRemarks">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Tx Status</span>
                                        <span class="ar-card-value" id="arTrtStatus">—</span>
                                    </div>
                                </div>
                            </section>

                            <!-- §4 Billing Information -->
                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-credit-card"></i></span>
                                    <h6 class="ar-card-title">Billing Information</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Total Amount</span>
                                        <span class="ar-card-value fw-semibold text-dark" id="arBillTotal">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Discount</span>
                                        <span class="ar-card-value" id="arBillDiscount">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Amount Paid</span>
                                        <span class="ar-card-value fw-semibold text-success" id="arBillPaid">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Payment Status</span>
                                        <span class="ar-card-value" id="arBillPayStatus">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Payment Method</span>
                                        <span class="ar-card-value" id="arBillMethod">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Transaction ID</span>
                                        <span class="ar-card-value text-muted" id="arBillTxn">—</span>
                                    </div>
                                </div>
                            </section>

                        </div><!-- /.ar-cards-grid -->

                        <!-- ── Bottom Tabs ── -->
                        <div class="apt-record-tabs-wrap">
                            <ul class="nav nav-tabs apt-record-tabs" id="arTabs" role="tablist">
                                <li class="nav-item">
                                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#arTabTimeline" type="button">
                                        <i class="feather-clock me-1"></i> Timeline
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#arTabTreatment" type="button">
                                        <i class="feather-activity me-1"></i> Treatment Record
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#arTabPayment" type="button">
                                        <i class="feather-dollar-sign me-1"></i> Payment Record
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#arTabNotes" type="button">
                                        <i class="feather-file-text me-1"></i> Notes
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#arTabAudit" type="button">
                                        <i class="feather-shield me-1"></i> Audit Log
                                    </button>
                                </li>
                            </ul>
                            <div class="tab-content apt-record-tab-content">

                                <!-- Timeline -->
                                <div class="tab-pane fade show active" id="arTabTimeline">
                                    <div class="ar-timeline" id="arTimeline">
                                        <div class="ar-timeline-empty text-muted">
                                            <i class="feather-clock me-1"></i> No history available.
                                        </div>
                                    </div>
                                </div>

                                <!-- Treatment Record -->
                                <div class="tab-pane fade" id="arTabTreatment">
                                    <div id="arTreatmentTab" class="ar-tab-inner">
                                        <div class="ar-tab-empty text-muted">
                                            <i class="feather-activity me-1"></i> No treatment record linked.
                                        </div>
                                    </div>
                                </div>

                                <!-- Payment Record -->
                                <div class="tab-pane fade" id="arTabPayment">
                                    <div id="arPaymentTab" class="ar-tab-inner">
                                        <div class="ar-tab-empty text-muted">
                                            <i class="feather-dollar-sign me-1"></i> No payment record found.
                                        </div>
                                    </div>
                                </div>

                                <!-- Notes -->
                                <div class="tab-pane fade" id="arTabNotes">
                                    <div class="ar-tab-inner">
                                        <div class="ar-notes-box" id="arNotes">
                                            <span class="text-muted fst-italic">No notes recorded for this appointment.</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Audit Log -->
                                <div class="tab-pane fade" id="arTabAudit">
                                    <div class="ar-tab-inner" id="arAuditLog">
                                        <div class="ar-tab-empty text-muted">
                                            <i class="feather-shield me-1"></i> No audit entries found.
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>

                    </div><!-- /#arContent -->
                </div><!-- /.modal-body -->

                <!-- ── Footer ── -->
                <div class="modal-footer apt-record-footer">
                    <button type="button" class="btn btn-outline-secondary " onclick="printAppointmentRecord()">
                        <i class="feather-printer me-1"></i> Print Record
                    </button>
                </div>

            </div><!-- /.modal-content -->
        </div><!-- /.modal-dialog -->
    </div><!-- /#aptRecordModal -->

</body>

</html>