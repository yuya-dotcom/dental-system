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
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying

$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : getSessionBranch();
if (!isOwner() && $filterBranch !== getSessionBranch()) $filterBranch = getSessionBranch();
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

// Ended, never-checked-in appointments become No Show before the list is read
// (same shared, throttled rule as the Schedule — controllers/appointment_lifecycle.php)
expireOverdueAppointments();

// One parallel wave: the list + branch dropdown + navbar bell (controllers/admin_batch.php)
admin_prefetch(['list' => admin_q('appointments', implode('&', appointmentRecordsQuery($page, $filterBranch, $filterStatus, $sortOrder, $extra)), ['Prefer: count=exact'])]);
$branches     = getAllBranches();
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

// Cell helpers (aptInitials, aptAvatarTone, aptBranchColor, aptNameCase, aptStatusPillClass)
// are shared with the other records pages: controllers/record_ui.php
require_once __DIR__ . '/controllers/record_ui.php';
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
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/es-admin.css?v=7">
    <!-- Analytics design language + this page's list / modal styles -->
    <link rel="stylesheet" href="assets/css/analytics.css?v=8">
    <link rel="stylesheet" href="assets/css/appointments-records.css?v=8">
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

            <div class="main-content an-page ar-page">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card stretch stretch-full ar-list-card">
                            <div class="card-header">
                                <h5 class="card-title d-flex align-items-center gap-2">
                                    Appointment Records
                                    <span class="an-pill info"><?= number_format((int)$totalRecords) ?> total</span>
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

                            <!-- Quick status filter (sets the Status filter below and applies it) -->
                            <div class="ar-quick" role="group" aria-label="Show appointments by status">
                                <?php foreach (['' => ['All', ''], 'pending' => ['Pending', 'warn'], 'confirmed' => ['Confirmed', 'info'], 'checked_in' => ['Checked In', 'violet'],
                                                'completed' => ['Completed', 'up'], 'cancelled' => ['Cancelled', 'down'], 'no_show' => ['No Show', 'slate']] as $sv => [$sl, $tone]): ?>
                                <button type="button" class="ar-quick-btn<?= $filterStatus === $sv ? ' is-active' : '' ?>" aria-pressed="<?= $filterStatus === $sv ? 'true' : 'false' ?>"
                                        onclick="arQuickStatus('<?= $sv ?>')"><?php if ($tone): ?><span class="ar-qdot t-<?= $tone ?>"></span><?php endif; ?><?= $sl ?></button>
                                <?php endforeach; ?>
                            </div>

                            <!-- Status is chosen with the chips above (no Status dropdown in the filters) -->
                            <input type="hidden" id="filterStatus" value="<?= htmlspecialchars($filterStatus) ?>">

                            <div class="list-toolbar">
                                <?= listSearchControl($search, 'Appointment code') ?>
                                <?php if (isOwner()): ?>
                                    <?= listDateRangeControl($dateFrom, $dateTo, 'Appointment Date') ?>
                                <?php else: ?>
                                    <?= listField('Appointment Date', '<input type="date" id="listDate" class="js-date-picker" data-cs-filter data-list-param="date" value="' . htmlspecialchars($aptDate) . '" placeholder="Select date" onchange="applyFilters()">', 'listDate') ?>
                                <?php endif; ?>
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
                            <!-- overflow:visible (same as appointments-schedule.php) so the ⋮ menu pops out instead of being clipped -->
                            <div class="card-body custom-card-action p-0" style="overflow:visible;">
                                <div class="table-responsive" style="overflow:visible;">
                                    <table class="table table-hover mb-0 apt-records-table ar-table ar-cards-sm">
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
                                                    <td colspan="5" class="ar-empty">
                                                        <span class="an-ico t-slate"><i class="feather-calendar"></i></span>
                                                        <div class="ar-empty-title"><?= $filtersOn ? 'No appointments match the selected filters' : 'No appointments yet' ?></div>
                                                        <div class="ar-empty-text"><?= $filtersOn ? '<a href="appointments-records.php" class="fw-semibold">Reset filters</a> to see every appointment.' : 'Appointments booked at the clinic or through the patient portal appear here.' ?></div>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($appointments as $apt):
                                                    $aDate = (string)($apt['appointment_date'] ?? '');
                                                    $aTs   = $aDate !== '' ? strtotime($aDate) : false;
                                                    $aName = aptNameCase($apt['patients']['full_name'] ?? '');
                                                ?>
                                                    <tr class="<?= $aDate === date('Y-m-d') ? 'is-today' : '' ?>">
                                                        <td>
                                                            <div class="ar-when">
                                                                <div class="min-w-0">
                                                                    <div class="ar-when-date"><?= formatAptDate($aDate) ?><?php if ($aDate === date('Y-m-d')): ?> <span class="an-pill info ms-1">Today</span><?php endif; ?></div>
                                                                    <div class="ar-when-time"><i class="feather-clock"></i><?= $aTs ? date('D', $aTs) . ', ' : '' ?><?= formatAptTime($apt['appointment_time']) ?></div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <!-- Patient name normalised to "Juan Dela Cruz" (aptNameCase) -->
                                                            <div class="ar-who">
                                                                <span class="ar-avatar t-<?= aptAvatarTone((int)($apt['patient_id'] ?? 0)) ?>" aria-hidden="true"><?= htmlspecialchars(aptInitials($aName)) ?></span>
                                                                <div class="min-w-0">
                                                                    <a href="patient-records.php?id=<?= (int)($apt['patient_id'] ?? 0) ?>" class="ar-who-name"><?= htmlspecialchars($aName) ?></a>
                                                                    <div class="ar-who-code" title="Patient number"><?= htmlspecialchars(trim((string)($apt['patients']['patient_code'] ?? '')) ?: '—') ?></div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <?php if (isOwner()): ?>
                                                            <td><span class="ar-branch"><span class="an-dot" style="background:var(<?= aptBranchColor((string)($apt['branches']['branch_name'] ?? ''), $branches) ?>)"></span><?= htmlspecialchars($apt['branches']['branch_name'] ?? '—') ?></span></td>
                                                        <?php else: ?>
                                                            <td><span class="ar-service"><?= htmlspecialchars(appointmentServicesLabel($apt, 'General Consultation')) ?></span></td>
                                                        <?php endif; ?>
                                                        <td>
                                                            <span class="an-pill ar-status <?= aptStatusPillClass($apt['status'] ?? '') ?>"><span class="ar-sdot"></span><?= aptStatusDisplay($apt['status'] ?? '') ?></span>
                                                        </td>
                                                        <td class="text-end">
                                                            <div class="dropdown ar-actions">
                                                                <a href="javascript:void(0);" class="ar-act" data-bs-toggle="dropdown" data-bs-strategy="fixed" data-bs-offset="0,6"
                                                                    title="Actions" aria-label="Actions for <?= htmlspecialchars($apt['appointment_code'] ?? '', ENT_QUOTES) ?>" aria-expanded="false">
                                                                    <i class="feather-more-vertical"></i>
                                                                </a>
                                                                <div class="dropdown-menu dropdown-menu-end action-dropdown ar-menu">
                                                                    <a href="javascript:void(0);" class="dropdown-item ar-mi"
                                                                        data-apt-prefetch="<?= (int)$apt['appointment_id'] ?>" data-apt-records
                                                                        onclick="openRecordModal(<?= (int)$apt['appointment_id'] ?>)">
                                                                        <span class="ar-mi-ico t-blue"><i class="feather-eye"></i></span>
                                                                        <span class="ar-mi-txt"><span class="t">View Details</span><span class="d">Full record, timeline and billing</span></span>
                                                                    </a>
                                                                    <?php if ($apt['status'] === 'pending'): ?>
                                                                        <a href="javascript:void(0);" class="dropdown-item ar-mi"
                                                                            onclick="updateStatus(<?= (int)$apt['appointment_id'] ?>, 'confirmed', '<?= htmlspecialchars($apt['appointment_code'], ENT_QUOTES) ?>')">
                                                                            <span class="ar-mi-ico t-green"><i class="feather-check-circle"></i></span>
                                                                            <span class="ar-mi-txt"><span class="t">Confirm</span><span class="d">Mark as confirmed</span></span>
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if ($apt['status'] === 'checked_in'): ?>
                                                                        <!-- Completion goes through the treatment (Finish creates the invoice and deducts stock) -->
                                                                        <a href="treatments-active.php" class="dropdown-item ar-mi">
                                                                            <span class="ar-mi-ico t-violet"><i class="feather-activity"></i></span>
                                                                            <span class="ar-mi-txt"><span class="t">Go to Active Treatments</span><span class="d">Finish it to complete the visit</span></span>
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (in_array($apt['status'], ['pending', 'confirmed'])): ?>
                                                                        <a href="javascript:void(0);" class="dropdown-item ar-mi"
                                                                            onclick="updateStatus(<?= (int)$apt['appointment_id'] ?>, 'cancelled', '<?= htmlspecialchars($apt['appointment_code'], ENT_QUOTES) ?>')">
                                                                            <span class="ar-mi-ico t-amber"><i class="feather-x-circle"></i></span>
                                                                            <span class="ar-mi-txt"><span class="t">Cancel</span><span class="d">Cancel with a reason</span></span>
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (!isDentist()): ?>
                                                                        <div class="ar-menu-sep"></div>
                                                                        <a href="javascript:void(0);" class="dropdown-item ar-mi ar-mi-danger"
                                                                            onclick="deleteAppointment(<?= (int)$apt['appointment_id'] ?>, '<?= htmlspecialchars($apt['appointment_code'], ENT_QUOTES) ?>')">
                                                                            <span class="ar-mi-ico t-red"><i class="feather-trash-2"></i></span>
                                                                            <span class="ar-mi-txt"><span class="t">Delete</span><span class="d">Remove permanently</span></span>
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
                                <p class="ar-foot-count mb-0">
                                    <span><?php if ($totalRecords): ?>Showing <b><?= $from ?>–<?= $to ?></b> of <b><?= number_format((int)$totalRecords) ?></b> appointment<?= (int)$totalRecords === 1 ? '' : 's' ?><?php else: ?>No appointments to show<?php endif; ?></span>
                                    <?php if ($filtersOn): ?>
                                        <a href="appointments-records.php" class="ar-clear"><i class="feather-x-circle"></i>Clear filters</a>
                                    <?php endif; ?>
                                </p>
                                <?php if ($totalPages > 1): ?>
                                    <ul class="list-unstyled d-flex align-items-center gap-1 mb-0 pagination-common-style">
                                        <li>
                                            <?php if ($page > 1): ?>
                                                <a href="<?= $baseUrl ?>&page=<?= $page - 1 ?>" aria-label="Previous page"><i class="bi bi-arrow-left"></i></a>
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
                                            <li><a href="<?= $baseUrl ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>><?= $p ?></a></li>
                                        <?php endfor; ?>
                                        <?php if ($wEnd < $totalPages): ?>
                                            <?php if ($wEnd < $totalPages - 1): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?>
                                            <li><a href="<?= $baseUrl ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a></li>
                                        <?php endif; ?>
                                        <li>
                                            <?php if ($page < $totalPages): ?>
                                                <a href="<?= $baseUrl ?>&page=<?= $page + 1 ?>" aria-label="Next page"><i class="bi bi-arrow-right"></i></a>
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
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/es-swal.js?v=3"></script>
    <script src="assets/js/admin.js?v=7"></script>
    <script src="assets/js/es-ajax.js?v=7"></script>
    <script src="assets/js/apt-details.js?v=1"></script>
    <script src="assets/js/custom-select.js?v=3"></script>
    <script src="assets/js/appointments.js?v=13"></script>
    <script>
        function applyFilters() {
            window._applyFilters('appointments-records.php', {
                branch: 'filterBranch',
                status: 'filterStatus'
            });
        }

        /** Quick status chips above the filters: set the Status filter and apply it. */
        function arQuickStatus(value) {
            const sel = document.getElementById('filterStatus');
            if (!sel) return;
            sel.value = value;
            if (window.refreshCustomSelects) window.refreshCustomSelects();
            applyFilters();
        }

        // Delete — same EssenciaSmile dialog style as Confirm / Cancel (assets/css/es-swal.css)
        function deleteAppointment(appointmentId, code) {
            const safe = _esc(code);
            Swal.fire({
                icon: 'warning',
                iconHtml: '<i class="feather-trash-2 es-swal-glyph"></i>',
                title: 'Delete Appointment?',
                html: '<p class="es-swal-text">This appointment will be permanently removed. This cannot be undone.</p>' +
                      '<span class="es-swal-chip"><i class="feather-calendar"></i> ' + safe + '</span>',
                showCancelButton: true,
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Go Back',
                focusCancel: true,
                buttonsStyling: false,
                customClass: ES_SWAL_TONE('danger'),
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
                                iconHtml: '<i class="feather-check-circle es-swal-glyph"></i>',
                                title: 'Appointment Deleted',
                                html: '<p class="es-swal-text">' + _esc(res.message || 'Appointment removed.') + '</p>' +
                                      '<span class="es-swal-chip"><i class="feather-calendar"></i> ' + safe + '</span>',
                                confirmButtonText: 'Okay',
                                timer: 1600,
                                timerProgressBar: true,
                                buttonsStyling: false,
                                customClass: ES_SWAL_TONE('success'),
                            }).then(() => esRefresh());
                        } else {
                            _statusError(res.message || 'This appointment could not be deleted.', 'Cannot Delete');
                        }
                    })
                    .catch(() => _statusError('Network error. Please check your connection and try again.', 'Cannot Delete'));
            });
        }

        // ══════════════════════════════════════════════════
        //  APPOINTMENT RECORD DETAILS MODAL
        // ══════════════════════════════════════════════════
        let _arCurrentId = null;
        let _arCurrentApt = null;
        let _arCurrentTrt = null;    // for Print Record
        let _arCurrentBill = null;
        const AR_PRINTED_BY = <?= json_encode((string)($_SESSION['full_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        let _arModalInst = null;

        function openRecordModal(appointmentId) {
            _arCurrentId = appointmentId;
            _arCurrentApt = null;
            _arCurrentTrt = null;
            _arCurrentBill = null;

            if (!_arModalInst) {
                _arModalInst = new bootstrap.Modal(document.getElementById('aptRecordModal'), {
                    backdrop: 'static'
                });
            }

            // One request (appointment + services + treatment + invoice,
            // api/appointments.php?with=records), shared in-memory cache —
            // assets/js/apt-details.js. A cached appointment renders at once
            // with no loading state; the spinner shows only for a real fetch.
            var fromCache = AptDetails.open(appointmentId, {
                records: true,
                onData: function(apt) {
                    if (_arCurrentId !== appointmentId) return;
                    _arCurrentApt = apt;
                    _arRenderModal(apt, {
                        success: !!apt.invoice,
                        data: apt.invoice
                    }, {
                        success: !!apt.treatment,
                        data: apt.treatment
                    });
                },
                onError: function(err) {
                    if (_arCurrentId !== appointmentId) return;
                    document.getElementById('arErrorMsg').textContent = err.message || 'Failed to load appointment details.';
                    _arHide('arLoadingState');
                    _arHide('arContent');
                    _arShow('arErrorState');
                }
            });

            if (!fromCache) {
                _arShow('arLoadingState');
                _arHide('arErrorState');
                _arHide('arContent');
                document.getElementById('arModalCode').textContent = '—';
                document.getElementById('arModalStatusBadge').textContent = '—';
                document.getElementById('arModalStatusBadge').className = 'badge';
            }
            _arModalInst.show();
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
                        return '<span class="badge bg-soft-primary text-primary me-1">' + _arEsc((s.services && s.services.service_name) ? s.services.service_name : '—') + '</span>';
                    }).join('');
            } else {
                document.getElementById('arAptService').textContent = (apt.services && apt.services.service_name) ? apt.services.service_name : '—';
            }

            document.getElementById('arAptDentist').textContent = (apt.dentists && apt.dentists.full_name) ? apt.dentists.full_name : '—';
            document.getElementById('arAptStatus').innerHTML = '<span class="badge ' + _arStatusClass(status) + '">' + _arStatusLabel(status) + '</span>';
            document.getElementById('arAptType').textContent = apt.appointment_type || '—';

            _arRenderCancellation(apt);

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

            _arCurrentTrt = trt;
            _arCurrentBill = bill;
            _arBuildTimeline(apt, trt, bill);

            if (trt) {
                document.getElementById('arTreatmentTab').innerHTML = '<table class="ar-detail-table">' +
                    '<tr><th>Treatment Code</th><td>' + _arEsc(trt.treatment_code || '—') + '</td></tr>' +
                    '<tr><th>Treatment Date</th><td>' + (trt.treatment_date ? _arFmtDate(trt.treatment_date) : '—') + '</td></tr>' +
                    '<tr><th>Service</th><td>' + _arEsc((trt.services && trt.services.service_name) ? trt.services.service_name : ((apt.services && apt.services.service_name) ? apt.services.service_name : '—')) + '</td></tr>' +
                    '<tr><th>Dentist</th><td>' + _arEsc((trt.dentists && trt.dentists.full_name) ? trt.dentists.full_name : '—') + '</td></tr>' +
                    '<tr><th>Status</th><td><span class="badge ' + _arTrtStatusClass(trt.status || '') + '">' + _arTrtStatusLabel(trt.status || '') + '</span></td></tr>' +
                    '<tr><th>Description</th><td>' + _arEsc(trt.description || '—') + '</td></tr>' +
                    '<tr><th>Remarks</th><td style="white-space:pre-wrap;">' + _arEsc(trt.remarks || trt.notes || '—') + '</td></tr>' +
                    '</table>';
            } else {
                document.getElementById('arTreatmentTab').innerHTML = '<div class="ar-tab-empty text-muted"><i class="feather-activity me-1"></i> No treatment record linked to this appointment.</div>';
            }

            if (bill) {
                var balance = parseFloat(bill.total_amount || 0) - parseFloat(bill.amount_paid || 0);
                document.getElementById('arPaymentTab').innerHTML = '<table class="ar-detail-table">' +
                    '<tr><th>Invoice ID</th><td>' + _arEsc(bill.bill_id || bill.billing_id || bill.invoice_id || '—') + '</td></tr>' +
                    '<tr><th>Bill Date</th><td>' + ((bill.bill_date || bill.created_at) ? _arFmtDate(bill.bill_date || bill.created_at) : '—') + '</td></tr>' +
                    '<tr><th>Total Amount</th><td class="fw-semibold">' + _arFmtMoney(bill.total_amount) + '</td></tr>' +
                    '<tr><th>Discount</th><td>' + (bill.discount_amount ? _arFmtMoney(bill.discount_amount) : '—') + '</td></tr>' +
                    '<tr><th>Amount Paid</th><td class="text-success fw-semibold">' + _arFmtMoney(bill.amount_paid) + '</td></tr>' +
                    '<tr><th>Balance</th><td class="' + (balance > 0 ? 'text-danger' : 'text-success') + '">' + _arFmtMoney(balance) + '</td></tr>' +
                    '<tr><th>Payment Status</th><td><span class="badge ' + _arPayBadge(bill.payment_status) + '">' + _arCapFirst(bill.payment_status || 'unpaid') + '</span></td></tr>' +
                    '<tr><th>Payment Method</th><td>' + _arEsc(bill.payment_method || '—') + '</td></tr>' +
                    '<tr><th>Transaction ID</th><td class="text-muted">' + _arEsc(bill.transaction_id || '—') + '</td></tr>' +
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
                '<td>Appointment ' + _arEsc(apt.appointment_code || '') + ' created</td>' +
                '<td class="text-muted">' + (created ? _arFmtDate(created) : '—') + '</td></tr>' +
                (apt.updated_at && apt.updated_at !== apt.created_at ?
                    '<tr><td><span class="badge bg-soft-warning text-warning">Updated</span></td><td>Appointment last modified</td><td class="text-muted">' + _arFmtDate(apt.updated_at) + '</td></tr>' : '') +
                '</tbody></table>';

            _arHide('arLoadingState');
            _arHide('arErrorState');
            _arShow('arContent');
        }

        // Cancellation Information: only for a cancelled appointment. Patient
        // cancellations name the patient; staff cancellations name the staff
        // member (and role). Missing details (older records) → "Not recorded".
        function _arRenderCancellation(apt) {
            var card = document.getElementById('arCancelCard');
            var c = apt.status === 'cancelled' ? (apt.cancellation || { source: 'none' }) : null;
            card.classList.toggle('d-none', !c);
            if (!c) return;
            var nr = '<span class="ar-not-recorded">Not recorded</span>';
            var who = c.by ? _arEsc(c.by_type === 'patient' ? _arNameCase(c.by) : c.by) : '';
            var type = c.by_type === 'patient' ? 'Patient' : (c.by_type === 'staff' ? 'Staff' : '');
            document.getElementById('arCancelBy').innerHTML = who
                ? who + (type ? '<span class="ar-cancel-by-type">· ' + type + '</span>' : '')
                : (type ? _arEsc(type) : nr);
            document.getElementById('arCancelAt').innerHTML = c.at ? _arEsc(_arFmtDateTime(c.at)) : nr;
            document.getElementById('arCancelReason').innerHTML = c.reason ? _arEsc(c.reason) : nr;
        }

        function _arFmtDateTime(iso) {
            var d = new Date(iso);
            if (isNaN(d.getTime())) return String(iso);
            return d.toLocaleString('en-PH', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
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
                var cx = apt.cancellation || {};
                events.push({
                    icon: 'fas fa-circle-xmark',
                    color: 'danger',
                    label: 'Appointment Cancelled',
                    sub: cx.by ? 'By ' + (cx.by_type === 'patient' ? _arNameCase(cx.by) : cx.by) : '',
                    date: cx.at || apt.updated_at || ''
                });
            }
            if (apt.status === 'no_show') {
                events.push({
                    icon: 'fas fa-user-slash',
                    color: 'danger',
                    label: 'Marked No Show',
                    sub: 'Not checked in by the end of the appointment',
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
                    (e.sub ? '<div class="ar-tl-sub">' + _arEsc(e.sub) + '</div>' : '') +
                    (e.date ? '<div class="ar-tl-date">' + _arFmtDate(e.date) + '</div>' : '') +
                    '</div></div>';
            }).join('');
        }

        // ── Print Record ─────────────────────────────────────────────
        // Builds a clean, print-only document from the data already shown in
        // the modal (no navigation, buttons or modal controls) and prints it
        // from a hidden frame — the page itself is not changed and no pop-up
        // window is needed. A4 portrait; sections never split across pages.
        function _arPrintHtml() {
            var apt = _arCurrentApt || {};
            var pat = apt.patients || {};
            var br = apt.branches || {};
            var trt = _arCurrentTrt;
            var bill = _arCurrentBill;
            var e = _arEsc;
            var dash = function(v) { v = (v === null || v === undefined) ? '' : String(v).trim(); return v ? e(v) : '<span class="na">—</span>'; };
            var row = function(k, v) { return '<tr><th>' + k + '</th><td>' + v + '</td></tr>'; };
            var svcs = (apt.appointment_services || []).map(function(s) { return (s.services && s.services.service_name) || ''; }).filter(Boolean);
            if (!svcs.length && apt.services && apt.services.service_name) svcs = [apt.services.service_name];
            var notes = (apt.notes || '').trim();
            var printedAt = new Date().toLocaleString('en-PH', { dateStyle: 'long', timeStyle: 'short' });
            var abs = function(path) { return new URL(path, location.href).href; };
            var logo = abs('assets/images/EssenciaSmile-Original-Logo.svg');
            var tone = function(s) { return ({ pending: 'amber', confirmed: 'blue', checked_in: 'violet', completed: 'green', cancelled: 'red', paid: 'green', partial: 'amber', unpaid: 'red', ongoing: 'blue' })[s] || 'slate'; };
            var pill = function(label, s) { return '<span class="pill ' + tone(s) + '"><i></i>' + e(label) + '</span>'; };
            var payStatus = (bill && bill.payment_status) || apt.payment_status || 'unpaid';
            var dentist = apt.dentists && apt.dentists.full_name ? e(apt.dentists.full_name) : '<span class="na">Not assigned</span>';

            // A titled box; wide = full width
            var box = function(title, body, cls) { return '<section class="box' + (cls ? ' ' + cls : '') + '"><h2>' + title + '</h2><div class="box-body">' + body + '</div></section>'; };
            var html = '';
            html += box('Patient information', '<table>' +
                row('Patient name', '<strong>' + dash(_arNameCase(pat.full_name)) + '</strong>') +
                row('Patient ID', dash(pat.patient_code)) +
                row('Contact number', dash(apt.resolved_contact || pat.contact_number)) +
                (pat.email ? row('Email', dash(pat.email)) : '') +
                (pat.date_of_birth ? row('Date of birth', dash(_arFmtDate(pat.date_of_birth))) : '') +
                (pat.address ? row('Address', dash(pat.address)) : '') +
                '</table>');
            html += box('Appointment details', '<table>' +
                row('Appointment no.', '<strong>' + dash(apt.appointment_code) + '</strong>') +
                row('Type', dash(apt.appointment_type)) +
                row('Status', pill(_arStatusLabel(apt.status || ''), apt.status || '')) +
                row('Payment status', pill(_arCapFirst(payStatus), payStatus)) +
                '</table>');
            if (apt.status === 'cancelled') {
                var cx = apt.cancellation || {};
                var nrp = '<span class="na">Not recorded</span>';
                var cxType = cx.by_type === 'patient' ? ' (Patient)' : '';
                html += box('Cancellation information', '<table>' +
                    row('Cancelled by', cx.by ? e((cx.by_type === 'patient' ? _arNameCase(cx.by) : cx.by) + cxType) : nrp) +
                    row('Date &amp; time', cx.at ? e(_arFmtDateTime(cx.at)) : nrp) +
                    row('Reason', cx.reason ? e(cx.reason) : nrp) +
                    '</table>', 'wide cancel');
            }
            html += box('Services', svcs.length ?
                '<table class="list"><thead><tr><th class="n">No.</th><th>Service</th></tr></thead><tbody>' +
                svcs.map(function(n, i) { return '<tr><td class="n">' + (i + 1) + '</td><td>' + e(n) + '</td></tr>'; }).join('') + '</tbody></table>' :
                '<p class="na">No services recorded.</p>', 'wide');
            if (trt) {
                html += box('Treatment', '<table>' +
                    row('Treatment code', dash(trt.treatment_code)) +
                    row('Treatment date', dash(trt.treatment_date ? _arFmtDate(trt.treatment_date) : '')) +
                    row('Dentist', dash(trt.dentists && trt.dentists.full_name)) +
                    row('Status', pill(_arTrtStatusLabel(trt.status || ''), trt.status || '')) +
                    (trt.description ? row('Description', dash(trt.description)) : '') +
                    ((trt.remarks || trt.notes) ? row('Remarks', dash(trt.remarks || trt.notes)) : '') +
                    '</table>', 'wide');
            }
            if (bill) {
                var balance = parseFloat(bill.total_amount || 0) - parseFloat(bill.amount_paid || 0);
                var money = function(k, v, cls) { return '<tr' + (cls ? ' class="' + cls + '"' : '') + '><th>' + k + '</th><td class="amt">' + v + '</td></tr>'; };
                html += box('Billing summary', '<table class="bill">' +
                    money('Total amount', e(_arFmtMoney(bill.total_amount))) +
                    (bill.discount_amount ? money('Discount', '− ' + e(_arFmtMoney(bill.discount_amount))) : '') +
                    money('Amount paid', e(_arFmtMoney(bill.amount_paid))) +
                    money('Balance', e(_arFmtMoney(balance)), 'total') +
                    '</table>' +
                    '<div class="bill-meta"><span>Payment status ' + pill(_arCapFirst(bill.payment_status || 'unpaid'), bill.payment_status || 'unpaid') + '</span>' +
                    (bill.payment_method ? '<span>Payment method <strong>' + dash(bill.payment_method) + '</strong></span>' : '') + '</div>', 'wide');
            }
            html += box('Appointment notes', notes ? '<p class="notes">' + e(notes) + '</p>' : '<p class="na">No notes available.</p>', 'wide');

            var contact = [br.address, br.contact_number].filter(Boolean).map(e).join('<br>');
            var fact = function(label, value) { return '<div class="fact"><span>' + label + '</span><strong>' + value + '</strong></div>'; };
            var font = function(file, range) { return '@font-face{font-family:"Inter";font-style:normal;font-weight:100 900;src:url(' + abs('assets/fonts/inter/' + file) + ') format("woff2");unicode-range:' + range + ';}'; };
            return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">' +
                '<title>Appointment Record ' + e(apt.appointment_code || '') + '</title><style>' +
                font('UcC73FwrK3iLTeHuS_nVMrMxCp50SjIa1ZL7.woff2', 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD') +
                font('UcC73FwrK3iLTeHuS_nVMrMxCp50SjIa25L7SUc.woff2', 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+1E00-1E9F,U+20A0-20AB,U+20AD-20C4,U+2113,U+2C60-2C7F,U+A720-A7FF') +
                '@page{size:A4 portrait;margin:12mm 13mm 14mm;}' +
                '*{box-sizing:border-box;}html,body{margin:0;padding:0;}' +
                'body{font-family:"Inter","Segoe UI",Arial,sans-serif;font-size:10.5px;color:#1f2937;line-height:1.45;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
                '.bar{height:5px;border-radius:3px;background:linear-gradient(90deg,#3454d1,#185fa5 60%,#2a9d78);margin-bottom:12px;}' +
                '.head{display:flex;align-items:center;justify-content:space-between;gap:16px;}' +
                '.head img{height:36px;width:auto;}' +
                '.clinic{text-align:right;font-size:9.5px;color:#6b7280;line-height:1.5;}.clinic strong{display:block;font-size:12px;color:#111827;font-weight:700;}' +
                '.title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:16px 0 12px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc;}' +
                '.title h1{margin:0;font-size:18px;font-weight:700;color:#111827;letter-spacing:-.01em;}' +
                '.title .ref{margin-top:2px;font-size:10px;color:#6b7280;}.title .ref strong{color:#111827;font-weight:700;padding:1px 6px;border:1px solid #e5e7eb;border-radius:5px;background:#fff;}' +
                '.facts{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px;}' +
                '.fact{border:1px solid #e5e7eb;border-radius:8px;padding:7px 10px;}.fact span{display:block;font-size:8.5px;color:#6b7280;font-weight:600;margin-bottom:2px;}' +
                '.fact strong{font-size:11px;color:#111827;font-weight:700;}' +
                '.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}' +
                '.box{break-inside:avoid;page-break-inside:avoid;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}.box.wide{grid-column:1 / -1;}' +
                '.box h2{margin:0;padding:6px 10px;font-size:10.5px;font-weight:700;color:#111827;background:#f8fafc;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:6px;}' +
                '.box h2::before{content:"";width:6px;height:6px;border-radius:2px;background:#3454d1;}' +
                '.box.cancel{border-color:#f3c7c7;}.box.cancel h2{background:#fff5f5;border-color:#f3c7c7;}.box.cancel h2::before{background:#d23f3f;}' +
                '.box-body{padding:4px 10px 6px;}' +
                'table{width:100%;border-collapse:collapse;}th,td{padding:4px 0;vertical-align:top;text-align:left;border-bottom:1px solid #f1f3f6;}' +
                'tr:last-child th,tr:last-child td{border-bottom:0;}th{width:38%;font-weight:500;color:#6b7280;padding-right:10px;}td{color:#111827;}' +
                '.list thead th{font-size:9px;font-weight:600;color:#6b7280;border-bottom:1px solid #e5e7eb;width:auto;}.list .n{width:36px;color:#6b7280;}' +
                '.bill td.amt{text-align:right;font-variant-numeric:tabular-nums;}.bill th{width:auto;}' +
                '.bill tr.total th,.bill tr.total td{padding-top:6px;border-top:1px solid #e5e7eb;font-weight:700;color:#111827;font-size:11.5px;}' +
                '.bill-meta{display:flex;gap:18px;flex-wrap:wrap;margin-top:4px;padding-top:6px;border-top:1px dashed #e5e7eb;color:#6b7280;}.bill-meta strong{color:#111827;margin-left:4px;}' +
                '.pill{display:inline-flex;align-items:center;gap:5px;padding:1px 8px;border-radius:999px;font-size:9.5px;font-weight:600;margin-left:2px;}' +
                '.pill i{width:5px;height:5px;border-radius:50%;background:currentColor;}' +
                '.pill.green{background:#e3f6ec;color:#188a52;}.pill.amber{background:#fff1d9;color:#b26a00;}.pill.red{background:#fdeaea;color:#d23f3f;}' +
                '.pill.blue{background:#e8f0fb;color:#185fa5;}.pill.violet{background:#f1ebfa;color:#7246b5;}.pill.slate{background:#eef2f7;color:#475569;}' +
                '.notes{margin:2px 0;white-space:pre-wrap;overflow-wrap:anywhere;}.na{color:#9ca3af;font-style:italic;margin:0;}' +
                '.sign{display:grid;grid-template-columns:1fr 1fr;gap:48px;margin-top:36px;break-inside:avoid;}' +
                '.sign div{border-top:1px solid #9ca3af;padding-top:5px;text-align:center;font-size:9.5px;color:#6b7280;}' +
                '.foot{margin-top:18px;padding-top:7px;border-top:1px solid #e5e7eb;font-size:8.5px;color:#9ca3af;display:flex;justify-content:space-between;gap:12px;}' +
                '</style></head><body>' +
                '<div class="bar"></div>' +
                '<header class="head"><img src="' + e(logo) + '" alt="EssenciaSmile">' +
                '<div class="clinic"><strong>EssenciaSmile Dental Clinic</strong>' + (br.branch_name ? e(br.branch_name) + ' Branch' + (contact ? '<br>' : '') : '') + contact + '</div></header>' +
                '<div class="title"><div><h1>Appointment Record</h1><div class="ref">Reference no. <strong>' + e(apt.appointment_code || '—') + '</strong></div></div>' +
                pill(_arStatusLabel(apt.status || ''), apt.status || '') + '</div>' +
                '<div class="facts">' +
                fact('Date', apt.appointment_date ? e(_arFmtDate(apt.appointment_date)) : '—') +
                fact('Time', apt.appointment_time ? e(_arFmtTime(apt.appointment_time)) : '—') +
                fact('Branch', br.branch_name ? e(br.branch_name) : '—') +
                fact('Dentist', dentist) + '</div>' +
                '<main class="grid">' + html + '</main>' +
                '<div class="sign"><div>Attending dentist</div><div>Patient / guardian</div></div>' +
                '<footer class="foot"><span>Printed ' + e(printedAt) + (AR_PRINTED_BY ? ' by ' + e(AR_PRINTED_BY) : '') + '</span><span>EssenciaSmile Dental Management System &nbsp;|&nbsp; Confidential patient record</span></footer>' +
                '</body></html>';
        }

        function printAppointmentRecord() {
            if (!_arCurrentApt) return;
            var old = document.getElementById('arPrintFrame');
            if (old) old.remove();
            var frame = document.createElement('iframe');
            frame.id = 'arPrintFrame';
            frame.title = 'Appointment record (print)';
            frame.setAttribute('aria-hidden', 'true');
            frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
            document.body.appendChild(frame);
            var doc = frame.contentDocument;
            doc.open();
            doc.write(_arPrintHtml());
            doc.close();
            // Print once the logo has loaded (or after a short wait)
            var printed = false;
            var go = function() {
                if (printed) return;
                printed = true;
                try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (err) { console.error(err); }
            };
            // Print once the logo and the Inter font have loaded (or after a short wait)
            var img = doc.querySelector('img');
            var imgReady = new Promise(function(res) { if (!img || img.complete) res(); else { img.addEventListener('load', res); img.addEventListener('error', res); } });
            var fontReady = doc.fonts && doc.fonts.ready ? doc.fonts.ready : Promise.resolve();
            Promise.all([imgReady, fontReady]).then(function() { setTimeout(go, 50); });
            setTimeout(go, 2000);
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
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                        <button class="btn btn-sm btn-outline-primary" onclick="AptDetails.invalidate(_arCurrentId); openRecordModal(_arCurrentId)">
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

                            <!-- Cancellation Information — shown only for CANCELLED appointments.
                                 Data: api/appointments.php → data.cancellation (who / when / why,
                                 controllers/appointment_lifecycle.php). Never made up: anything
                                 that was not captured shows "Not recorded". -->
                            <section class="ar-card ar-cancel-card ar-tone-red d-none" id="arCancelCard" aria-labelledby="arCancelTitle">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-x-circle"></i></span>
                                    <h6 class="ar-card-title" id="arCancelTitle">Cancellation Information</h6>
                                </div>
                                <div class="ar-card-fields ar-cancel-fields">
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Cancelled By</span>
                                        <span class="ar-card-value" id="arCancelBy">—</span>
                                    </div>
                                    <div class="ar-card-field">
                                        <span class="ar-card-label">Cancellation Date &amp; Time</span>
                                        <span class="ar-card-value" id="arCancelAt">—</span>
                                    </div>
                                    <div class="ar-card-field ar-cancel-reason">
                                        <span class="ar-card-label">Reason for Cancellation</span>
                                        <span class="ar-card-value" id="arCancelReason">—</span>
                                    </div>
                                </div>
                            </section>

                            <!-- §1 Patient Information -->
                            <section class="ar-card ar-tone-blue">
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
                            <section class="ar-card ar-tone-violet">
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
                            <section class="ar-card ar-tone-teal" id="arTreatmentContent">
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
                            <section class="ar-card ar-tone-amber">
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
                    <span class="ar-foot-note"><i class="feather-info"></i>Opens a print-ready copy of this record.</span>
                    <button type="button" class="btn btn-primary ar-btn" onclick="printAppointmentRecord()">
                        <i class="feather-printer me-1"></i> Print Record
                    </button>
                    <button type="button" class="btn btn-light ar-btn ar-btn-ghost" data-bs-dismiss="modal">Close</button>
                </div>

            </div><!-- /.modal-content -->
        </div><!-- /.modal-dialog -->
    </div><!-- /#aptRecordModal -->

</body>

</html>