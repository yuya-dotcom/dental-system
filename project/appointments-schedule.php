<?php
define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/appointment_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/dbconfig.php';

$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : getSessionBranch();
if (!isOwner() && $filterBranch !== getSessionBranch()) $filterBranch = getSessionBranch();
$branches     = getAllBranches();
$filterStatus = in_array($_GET['status'] ?? '', ['pending', 'confirmed', 'checked_in'], true) ? $_GET['status'] : '';

// Sort / search / date range (validated in list_filters.php).
// The old single-day ?date= link still works: it becomes a one-day range.
require_once __DIR__ . '/controllers/list_filters.php';
$sortOptions  = [
    'date_asc'     => ['Appointment Date (Soonest)', 'appointment_date.asc,appointment_time.asc,appointment_id.asc'],
    'date_desc'    => ['Appointment Date (Latest)',  'appointment_date.desc,appointment_time.desc,appointment_id.desc'],
    'patient_asc'  => ['Patient (A–Z)', 'patients(full_name).asc.nullslast,appointment_id.asc'],
    'patient_desc' => ['Patient (Z–A)', 'patients(full_name).desc.nullslast,appointment_id.asc'],
    'status_asc'   => ['Status (A–Z)',               'status.asc,appointment_date.asc'],
    'status_desc'  => ['Status (Z–A)',               'status.desc,appointment_date.asc'],
];
[$sortKey, $sortOrder] = listSort($sortOptions, 'date_asc');
$search       = listSearch();
$dateFrom     = listDate('from') ?: listDate('date');
$dateTo       = listDate('to')   ?: listDate('date');
$filterDate   = '';
$page         = max(1, (int)($_GET['page'] ?? 1));

$patientsRes  = supabase_request('patients', 'GET', [], 'select=patient_id,full_name&order=full_name.asc&limit=500');
$dentistsRes  = supabase_request('dentists', 'GET', [], 'select=dentist_id,full_name&order=full_name.asc&limit=200');
$patientsList = is_array($patientsRes['data']) ? $patientsRes['data'] : [];
$dentistsList = is_array($dentistsRes['data']) ? $dentistsRes['data'] : [];

// ── Auto-expire overdue appointments ──────────────────────────────────────────
// Runs once per page load, before the schedule data is fetched.
// Any appointment whose scheduled date+time has already passed and is
// still pending or confirmed is marked no_show — it disappears from
// this Schedule view automatically and surfaces in Appointment Records.
// No database row is deleted; the record is fully preserved.
expireOverdueAppointments($filterBranch);

$extra        = listSearchFilter($search, ['appointment_code']);
array_push($extra, ...listDateRange('appointment_date', $dateFrom, $dateTo));
$filtersOn    = listActiveCount([isOwner() ? $filterBranch : 0, $filterStatus, $search, $dateFrom, $dateTo, $sortKey !== 'date_asc']);

$data         = getAppointmentSchedule($page, $filterBranch, $filterStatus, $filterDate, $sortOrder, $extra);
$appointments = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];

$baseUrl = listUrl('appointments-schedule.php', ['branch' => $filterBranch, 'status' => $filterStatus, 'q' => $search, 'from' => $dateFrom, 'to' => $dateTo, 'sort' => $sortKey === 'date_asc' ? '' : $sortKey]);
$sortBase = listUrl('appointments-schedule.php', ['branch' => $filterBranch, 'status' => $filterStatus, 'q' => $search, 'from' => $dateFrom, 'to' => $dateTo]);

// ── Batch-fetch all services for appointments on this page ────
// getAppointmentSchedule() only joins the legacy service_id column.
// We fetch appointment_services for all visible rows in one request
// and merge them in, so the table can show every service chosen.
if (!empty($appointments)) {
    $aptIds = array_filter(array_map(fn($a) => (int)($a['appointment_id'] ?? 0), $appointments));
    if ($aptIds) {
        $idList = implode(',', $aptIds);
        $svcJunctionRes = supabase_request(
            'appointment_services',
            'GET',
            [],
            'appointment_id=in.(' . $idList . ')' .
            '&select=appointment_id,sort_order,services(service_id,service_name)' .
            '&order=appointment_id.asc,sort_order.asc'
        );
        // Build lookup: appointment_id => [service_name, ...]
        $svcMap = [];
        foreach (($svcJunctionRes['data'] ?? []) as $row) {
            $aid  = (int)$row['appointment_id'];
            $name = $row['services']['service_name'] ?? null;
            if ($name) $svcMap[$aid][] = $name;
        }
        // Merge into appointments array
        foreach ($appointments as &$apt) {
            $aid = (int)($apt['appointment_id'] ?? 0);
            $apt['all_service_names'] = $svcMap[$aid] ?? [];
        }
        unset($apt);
    }
}

function schStatusBadgeClass(string $s): string
{
    return match ($s) {
        'pending'    => 'bg-soft-warning text-warning',
        'confirmed'  => 'bg-soft-success text-success',
        'checked_in' => 'bg-soft-primary text-primary',
        'completed'  => 'bg-soft-info text-info',
        'cancelled'  => 'bg-soft-danger text-danger',
        default      => 'bg-soft-secondary text-secondary',
    };
}
function schStatusDisplay(string $s): string
{
    return $s === 'checked_in' ? 'Checked In' : ucfirst($s);
}
?>
<!DOCTYPE html>
<html lang="zxx">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Appointment Schedules</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/daterangepicker.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/select2.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/select2-theme.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css?v=5">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=4">
    <link rel="stylesheet" href="assets/css/admin.css?v=5">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <style>
        /* ── Calendar slot styles ─────────────────────────── */
        #appointmentModal .slot-empty {
            font-size: 0.82rem;
            color: #6b7a8d;
            text-align: center;
            padding: 1.75rem 1rem;
            line-height: 1.7;
        }

        #appointmentModal .slot-loading {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.82rem;
            color: #6b7a8d;
            padding: 0.75rem 0;
        }

        #appointmentModal .slot-legend {
            display: flex;
            gap: 1rem;
            margin-bottom: 0.75rem;
            flex-wrap: wrap;
        }

        #appointmentModal .slot-legend span {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.72rem;
            color: #6b7a8d;
            font-weight: 500;
        }

        #appointmentModal .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 3px;
            flex-shrink: 0;
        }

        #appointmentModal .slot-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.4rem;
        }

        #appointmentModal .slot-btn {
            border-radius: 9px;
            font-size: 0.78rem;
            font-weight: 500;
            padding: 0.45rem 0.25rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.15s;
            border: 1.5px solid;
            width: 100%;
            display: block;
            font-family: inherit;
        }

        #appointmentModal .slot-btn-available {
            background: #e8f0fe;
            border-color: rgba(13, 110, 253, 0.3);
            color: #0a58ca;
        }

        #appointmentModal .slot-btn-available:hover:not(:disabled) {
            background: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(13, 110, 253, 0.22);
        }

        #appointmentModal .slot-btn-available.selected {
            background: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
            box-shadow: 0 3px 10px rgba(13, 110, 253, 0.22);
        }

        #appointmentModal .slot-btn-booked {
            background: #f1f4f8;
            border-color: #dee2e6;
            color: #b8c0cc;
            cursor: not-allowed;
            text-decoration: line-through;
        }

        #appointmentModal .slot-count {
            font-size: 0.72rem;
            color: #6b7a8d;
            text-align: center;
            margin-top: 0.6rem;
            margin-bottom: 0;
        }

        /* ── FullCalendar overrides ────────────────────────── */
        #adminCalendarWrap .fc .fc-toolbar-title {
            font-size: 0.9rem !important;
            font-weight: 700;
            color: #1c2b3a;
        }

        #adminCalendarWrap .fc .fc-button-primary {
            background: #fff !important;
            border: 1.5px solid #dee2e6 !important;
            color: #6b7a8d !important;
            border-radius: 8px !important;
            box-shadow: none !important;
            font-size: 0.75rem !important;
            padding: 0.25rem 0.55rem !important;
            transition: all 0.15s !important;
            font-family: inherit !important;
        }

        #adminCalendarWrap .fc .fc-button-primary:hover {
            background: #e8f0fe !important;
            border-color: #0d6efd !important;
            color: #0d6efd !important;
        }

        #adminCalendarWrap .fc .fc-button-primary:focus { box-shadow: none !important; }

        #adminCalendarWrap .fc .fc-col-header-cell-cushion {
            font-size: 0.67rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7a8d;
            text-decoration: none;
            padding: 5px 2px;
        }

        #adminCalendarWrap .fc .fc-daygrid-day-number {
            font-size: 0.78rem;
            font-weight: 500;
            color: #1c2b3a;
            text-decoration: none;
            padding: 4px 6px;
            border-radius: 50%;
            transition: background 0.15s, color 0.15s;
            min-width: 24px;
            text-align: center;
            display: inline-block;
        }

        #adminCalendarWrap .fc .fc-daygrid-day:not(.fc-day-disabled):not(.fc-day-fully-booked):hover .fc-daygrid-day-number {
            background: #e8f0fe;
            color: #0d6efd;
        }

        #adminCalendarWrap .fc .fc-day-today { background: transparent !important; }

        #adminCalendarWrap .fc .fc-day-today .fc-daygrid-day-number {
            background: #cfe2ff;
            color: #0a58ca;
            font-weight: 700;
        }

        #adminCalendarWrap .fc-day-selected { background-color: #e8f0fe !important; }

        #adminCalendarWrap .fc-day-selected .fc-daygrid-day-number {
            background: #0d6efd !important;
            color: #fff !important;
            font-weight: 700;
        }

        #adminCalendarWrap .fc-day-fully-booked {
            background-color: #fff5f5 !important;
            cursor: not-allowed !important;
            position: relative;
        }

        #adminCalendarWrap .fc-day-fully-booked .fc-daygrid-day-number { color: #ccc !important; text-decoration: line-through; }

        #adminCalendarWrap .fc-day-fully-booked::after {
            content: "Full";
            position: absolute;
            bottom: 3px;
            right: 5px;
            font-size: 8px;
            font-weight: 800;
            color: #dc3545;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        #adminCalendarWrap .fc .fc-scrollgrid { border: none !important; }
        #adminCalendarWrap .fc .fc-scrollgrid td,
        #adminCalendarWrap .fc .fc-scrollgrid th { border-color: #edf2f7 !important; }
        #adminCalendarWrap .fc .fc-toolbar { margin-bottom: 0.75rem !important; }
        #adminCalendarWrap .fc .fc-daygrid-day-frame { min-height: 36px; }
        #adminCalendarWrap .fc .fc-daygrid-day.fc-day-disabled { opacity: 0.35; }

        @media (min-width: 768px) {
            .border-end-md { border-right: 1px solid #edf2f7 !important; }
        }

        /* ── Multi-service picker (admin modal) ────────────── */
        .apt-svc-picker-row {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        /* Dropdowns use the shared .modal-cs design (assets/css/custom-select.css).
           Page-specific: sized to sit next to the Bootstrap fields in these modals. */
        .modal-cs { flex: 1; min-width: 0; }
        .modal-cs-trigger { height: 38px; font-size: 0.82rem; }

        .cs-item-price {
            font-size: 0.75rem;
            color: #6b7a8d;
            font-weight: 500;
            flex-shrink: 0;
        }

        #apt_svc_add_btn {
            flex-shrink: 0;
            border: 1.5px solid #0d6efd;
            background: #e8f0fe;
            color: #0d6efd;
            font-size: 0.8rem;
            font-weight: 600;
            border-radius: 8px;
            padding: 0.3rem 0.75rem;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
            height: 34px;
        }
        #apt_svc_add_btn:hover:not(:disabled) { background: #0d6efd; color: #fff; }
        #apt_svc_add_btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .svc-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #e8f0fe;
            border: 1.5px solid rgba(13,110,253,.25);
            border-radius: 20px;
            padding: 3px 10px 3px 5px;
            font-size: 0.76rem;
            color: #0a58ca;
        }

        .svc-chip-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            background: #0d6efd;
            color: #fff;
            border-radius: 50%;
            font-size: 0.65rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .svc-chip-name { font-weight: 500; }

        .svc-chip-price {
            font-size: 0.7rem;
            color: #6b7a8d;
            font-weight: 400;
        }

        .svc-chip-remove {
            background: none;
            border: none;
            color: #6b7a8d;
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
            padding: 0 0 0 2px;
            transition: color 0.12s;
        }
        .svc-chip-remove:hover { color: #dc3545; }
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
                        <h5 class="m-b-10">Appointments</h5>
                    </div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Schedules</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <?php if (!isDentist()): ?>
                                <button class="btn btn-primary hdr-btn" onclick="openAddModal()">
                                    <i class="feather-plus me-1"></i> Add Appointment
                                </button>
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
                                    Appointment Schedules
                                    <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span>
                                </h5>
                                <div class="card-header-action">
                                    <div class="card-header-btn">
                                        <div data-bs-toggle="tooltip" title="Refresh">
                                            <a href="appointments-schedule.php" class="avatar-text avatar-xs bg-warning"></a>
                                        </div>
                                        <div data-bs-toggle="tooltip" title="Maximize/Minimize">
                                            <a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="list-toolbar">
                                <?= listSearchControl($search, 'Appointment code') ?>
                                <?= listDateRangeControl($dateFrom, $dateTo, 'Appointment Date') ?>
                            <div class="list-field"><label class="list-label" for="filterStatus">Status</label>                            <select id="filterStatus" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="">All Active</option>
                                <option value="pending"    <?= $filterStatus === 'pending'    ? 'selected' : '' ?>>Pending</option>
                                <option value="confirmed"  <?= $filterStatus === 'confirmed'  ? 'selected' : '' ?>>Confirmed</option>
                                <option value="checked_in" <?= $filterStatus === 'checked_in' ? 'selected' : '' ?>>Checked In</option>
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
                                <?= listResetControl('appointments-schedule.php', $filtersOn) ?>
                            </div>
                            <div class="card-body custom-card-action p-0" style="overflow:visible;">
                                <div class="table-responsive" style="overflow:visible;">
                                    <table class="table table-hover mb-0" style="min-width:900px;">
                                        <thead>
                                            <tr>
                                                <?= listTh('Patient Name', 'patient_asc', 'patient_desc', $sortKey, $sortBase) ?>
                                                <?= listTh('Date / Time', 'date_asc', 'date_desc', $sortKey, $sortBase) ?>
                                                <th>Service</th>
                                                <th>Notes</th>
                                                <?= listTh('Status', 'status_asc', 'status_desc', $sortKey, $sortBase) ?>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($appointments)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center py-4 text-muted">
                                                        <i class="feather-calendar me-2"></i><?= $filtersOn ? 'No appointments match the selected filters. <a href="appointments-schedule.php" class="fw-semibold">Reset filters</a>' : 'No appointments found.' ?>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($appointments as $apt): ?>
                                                    <tr>
                                                        <td>
                                                            <a href="patient-records.php?id=<?= (int)($apt['patient_id'] ?? 0) ?>"
                                                                class="text-primary fw-semibold">
                                                                <?= htmlspecialchars($apt['patients']['full_name'] ?? '—') ?>
                                                            </a>
                                                        </td>
                                                        <td>
                                                            <div class="fw-semibold"><?= formatAptDate($apt['appointment_date']) ?></div>
                                                            <div class="text-muted small"><?= formatAptTime($apt['appointment_time']) ?></div>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $allSvcs = $apt['all_service_names'] ?? [];
                                                            if (empty($allSvcs) && !empty($apt['services']['service_name'])) {
                                                                $allSvcs = [$apt['services']['service_name']];
                                                            }
                                                            if (!empty($allSvcs)):
                                                                foreach ($allSvcs as $i => $svcName):
                                                            ?>
                                                                <span class="badge bg-soft-primary text-primary d-inline-flex align-items-center gap-1 mb-1" style="font-size:.74rem;font-weight:500;">
                                                                    <span style="opacity:.55;font-size:.65rem;"><?= $i + 1 ?>.</span>
                                                                    <?= htmlspecialchars($svcName) ?>
                                                                </span>
                                                            <?php
                                                                endforeach;
                                                            else:
                                                            ?>
                                                                <span class="text-muted">General Consultation</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-muted small"><?= htmlspecialchars($apt['notes'] ?? '—') ?></td>
                                                        <td>
                                                            <span class="badge <?= schStatusBadgeClass($apt['status'] ?? '') ?>">
                                                                <?= schStatusDisplay($apt['status'] ?? '') ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-end">
                                                            <div class="dropdown">
                                                                <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto"
                                                                    data-bs-toggle="dropdown" data-bs-strategy="fixed">
                                                                    <i class="feather-more-vertical"></i>
                                                                </a>
                                                                <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                                    <a href="javascript:void(0);" class="dropdown-item"
                                                                        onclick="viewAppointment(<?= (int)$apt['appointment_id'] ?>)">
                                                                        <i class="feather-eye"></i> View Appointment
                                                                    </a>
                                                                    <?php if (!isDentist() && in_array($apt['status'], ['pending', 'confirmed'])): ?>
                                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                                            onclick="openEditModal(<?= (int)$apt['appointment_id'] ?>)">
                                                                            <i class="feather-edit-2"></i> Edit Appointment
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (in_array($apt['status'], ['pending', 'confirmed', 'checked_in'])): ?>
                                                                        <div class="dropdown-divider"></div>
                                                                    <?php endif; ?>
                                                                    <?php if ($apt['status'] === 'pending'): ?>
                                                                        <a href="javascript:void(0);" class="dropdown-item text-success"
                                                                            onclick="updateStatus(<?= (int)$apt['appointment_id'] ?>, 'confirmed', '<?= htmlspecialchars($apt['appointment_code'], ENT_QUOTES) ?>')">
                                                                            <i class="feather-check-circle"></i> Confirm Appointment
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (in_array($apt['status'], ['pending', 'confirmed'])): ?>
                                                                        <a href="javascript:void(0);" class="dropdown-item text-warning ci-trigger"
                                                                            data-apt-id="<?= (int)$apt['appointment_id'] ?>"
                                                                            data-pat-id="<?= (int)($apt['patient_id'] ?? 0) ?>"
                                                                            data-apt-code="<?= htmlspecialchars($apt['appointment_code'] ?? '', ENT_QUOTES) ?>"
                                                                            data-pat-name="<?= htmlspecialchars($apt['patients']['full_name'] ?? '-', ENT_QUOTES) ?>">
                                                                            <i class="feather-user-check"></i> Check In Patient
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if ($apt['status'] === 'checked_in'): ?>
                                                                        <a href="treatments-active.php" class="dropdown-item text-primary">
                                                                            <i class="feather-activity"></i> Go to Active Treatments
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (in_array($apt['status'], ['pending', 'confirmed', 'checked_in'])): ?>
                                                                        <a href="javascript:void(0);" class="dropdown-item text-danger"
                                                                            onclick="updateStatus(<?= (int)$apt['appointment_id'] ?>, 'cancelled', '<?= htmlspecialchars($apt['appointment_code'], ENT_QUOTES) ?>')">
                                                                            <i class="feather-x-circle"></i> Cancel Appointment
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
                                    Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> appointment(s)
                                    <?php if ($filtersOn): ?>
                                        &nbsp;·&nbsp;<a href="appointments-schedule.php" class="text-danger">
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


    <!-- ══════════════════════════════════════════════════════════
         ADD / EDIT APPOINTMENT MODAL
         ══════════════════════════════════════════════════════════ -->
    <?php if (!isDentist()): ?>
        <div class="modal fade" id="appointmentModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-form-dialog">
                <div class="modal-content apt-record-content">
                    <div class="modal-header apt-record-header">
                        <div class="apt-record-title-wrap">
                            <div class="apt-record-icon" aria-hidden="true"><i class="feather-calendar"></i></div>
                            <div>
                                <h5 class="apt-record-title mb-0" id="modalTitle">Add Appointment</h5>
                                <div class="apt-record-meta" id="modalSubtitle">Fill in the details below to schedule an appointment.</div>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body apt-form-body">
                        <div id="modalError" class="apt-form-alert" role="alert" style="display:none;"></div>
                        <input type="hidden" id="apt_id">

                        <div class="apt-form-grid">
                            <!-- ── LEFT: Date & Time ──────────────────────── -->
                            <section class="ar-card apt-form-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-calendar"></i></span>
                                    <h6 class="ar-card-title">Date &amp; Time</h6>
                                </div>
                                <div class="apt-form-card-body">
                                    <div class="apt-field">
                                        <label class="apt-label" for="apt_branch">Branch <span class="apt-req" aria-hidden="true">*</span></label>
                                        <select id="apt_branch" class="form-select js-custom-select" data-cs-float data-cs-no-placeholder>
                                            <option value=""> Select Branch </option>
                                            <?php foreach ($branches as $b): ?>
                                                <option value="<?= $b['branch_id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="apt-field-err" data-err-for="apt_branch"></div>
                                    </div>
                                    <div class="apt-label">Date <span class="apt-req" aria-hidden="true">*</span></div>
                                    <div id="adminCalendarWrap" class="rounded-3 border">
                                        <div class="slot-empty">
                                            <svg width="30" height="30" viewBox="0 0 32 32" fill="none" style="margin-bottom:6px;opacity:0.3;">
                                                <rect x="3" y="5" width="26" height="22" rx="3" stroke="#6b7a8d" stroke-width="1.5" />
                                                <path d="M9 3v4M23 3v4M3 12h26" stroke="#6b7a8d" stroke-width="1.5" stroke-linecap="round" />
                                            </svg><br>
                                            Select a branch to load available dates.
                                        </div>
                                    </div>
                                    <div class="apt-field-err" data-err-for="apt_date"></div>
                                    <div id="adminTimeSlots" class="mt-3" style="display:none;">
                                        <div class="apt-label"><i class="feather-clock me-1"></i>Available Time Slots <span class="apt-req" aria-hidden="true">*</span></div>
                                        <div id="adminTimeSlotsGrid"></div>
                                    </div>
                                    <div class="apt-field-err" data-err-for="apt_time"></div>
                                    <input type="hidden" id="apt_date">
                                    <input type="hidden" id="apt_time">
                                    <div id="aptDateTimeSummary" class="apt-dt-summary" style="display:none;">
                                        <i class="feather-check-circle"></i>
                                        <span id="aptDateTimeText"></span>
                                    </div>
                                </div>
                            </section>

                            <!-- ── RIGHT: Appointment Details ──────────────── -->
                            <div class="apt-form-col">
                                <section class="ar-card apt-form-card">
                                    <div class="ar-card-header">
                                        <span class="ar-card-icon"><i class="feather-user"></i></span>
                                        <h6 class="ar-card-title">Patient &amp; Dentist</h6>
                                    </div>
                                    <div class="apt-form-card-body">
                                        <div class="apt-field">
                                            <label class="apt-label" for="apt_patient">Patient <span class="apt-req" aria-hidden="true">*</span></label>
                                            <select id="apt_patient" class="form-select js-custom-select" data-cs-float data-cs-search data-cs-no-placeholder>
                                                <option value=""> Select Patient </option>
                                                <?php foreach ($patientsList as $pt): ?>
                                                    <option value="<?= $pt['patient_id'] ?>"><?= htmlspecialchars($pt['full_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="apt-field-err" data-err-for="apt_patient"></div>
                                        </div>
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="apt_dentist">Dentist <span class="apt-opt">(optional)</span></label>
                                            <select id="apt_dentist" class="form-select js-custom-select" data-cs-float data-cs-search disabled>
                                                <option value=""> Select a branch first </option>
                                            </select>
                                        </div>
                                    </div>
                                </section>

                                <!-- ── Multi-service picker ───────────────── -->
                                <section class="ar-card apt-form-card apt-form-card-open">
                                    <div class="ar-card-header">
                                        <span class="ar-card-icon"><i class="feather-layers"></i></span>
                                        <h6 class="ar-card-title">Services <span class="apt-opt">(optional · up to 3)</span></h6>
                                    </div>
                                    <div class="apt-form-card-body">
                                        <div id="apt_svc_chips_wrap" style="display:none;margin-bottom:8px;">
                                            <div id="apt_svc_chips" class="d-flex flex-wrap gap-1"></div>
                                        </div>

                                        <div class="apt-svc-picker-row">
                                            <div class="modal-cs" id="apt_svc_select">
                                                <div class="modal-cs-trigger" onclick="toggleAptSvcSelect()">
                                                    <span class="modal-cs-value" id="apt_svc_value"> Add a service </span>
                                                    <svg class="modal-cs-arrow" width="11" height="11" viewBox="0 0 12 12" fill="none">
                                                        <path d="M2.5 4.5l3.5 3.5 3.5-3.5" stroke="currentColor" stroke-width="1.6"
                                                            stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                </div>
                                                <div class="modal-cs-dropdown" id="apt_svc_dropdown">
                                                    <div class="modal-cs-search-wrap">
                                                        <svg width="12" height="12" viewBox="0 0 14 14" fill="none">
                                                            <circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.4"/>
                                                            <path d="M9.5 9.5l2.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                                        </svg>
                                                        <input type="text" class="modal-cs-search" id="apt_svc_search"
                                                               placeholder="Search service…"
                                                               oninput="filterAptSvc(this.value)">
                                                    </div>
                                                    <ul class="modal-cs-list" id="apt_svc_list"></ul>
                                                </div>
                                            </div>
                                            <button type="button" id="apt_svc_add_btn" onclick="aptAddService()">+ Add</button>
                                        </div>

                                        <p id="apt_svc_cap_msg" class="text-muted small mt-1 mb-0" style="display:none;font-size:.72rem;">
                                            <em>Maximum of 3 services per booking reached.</em>
                                        </p>
                                    </div>
                                </section>

                                <!-- Add mode only — hidden in Edit (status/notes are managed elsewhere in the workflow) -->
                                <section class="ar-card apt-form-card" id="aptStatusNotesCard">
                                    <div class="ar-card-header">
                                        <span class="ar-card-icon"><i class="feather-clipboard"></i></span>
                                        <h6 class="ar-card-title">Status &amp; Notes</h6>
                                    </div>
                                    <div class="apt-form-card-body">
                                        <div class="row g-3">
                                            <div class="col-sm-6">
                                                <label class="apt-label" for="apt_status">Status</label>
                                                <select id="apt_status" class="form-select js-custom-select" data-cs-float>
                                                    <option value="pending">Pending</option>
                                                    <option value="confirmed">Confirmed</option>
                                                </select>
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="apt-label" for="apt_payment">Payment</label>
                                                <select id="apt_payment" class="form-select js-custom-select" data-cs-float>
                                                    <option value="unpaid">Unpaid</option>
                                                    <option value="paid">Paid</option>
                                                    <option value="partial">Partial</option>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="apt-label" for="apt_notes">Notes <span class="apt-opt">(optional)</span></label>
                                                <textarea id="apt_notes" class="form-control" rows="3" placeholder="Optional notes..."></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer apt-record-footer apt-form-footer">
                        <span class="apt-form-hint"><span class="apt-req">*</span> Required fields</span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary px-4" id="modalSaveBtn" onclick="saveAppointment()">
                                <i class="feather-save me-1"></i> Save Appointment
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>


    <!-- ══════════════════════════════════════════════════════════
         COMPLETION MODAL
         ══════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="completionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0">
                            <i class="feather-check-circle me-2 text-success"></i>Complete Appointment
                        </h5>
                        <p class="text-muted small mb-0">
                            Review and adjust materials to deduct for
                            <strong id="cmpl_appointment_code"></strong>
                        </p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="cmpl_error" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Item</th>
                                    <th>Current Stock</th>
                                    <th>Qty to Deduct</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="cmpl_materials_body"></tbody>
                        </table>
                        <div id="cmpl_no_materials" class="text-center py-3 text-muted small" style="display:none;">
                            <i class="feather-info me-1"></i>No materials configured. Add items manually or proceed without deduction.
                        </div>
                    </div>
                    <div class="card bg-light border-0 p-3">
                        <p class="fw-semibold small mb-2"><i class="feather-plus-circle me-1 text-primary"></i>Add Item Manually</p>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <select id="cmpl_add_item" class="form-select form-select-sm js-custom-select" data-cs-float data-cs-search>
                                <option value=""> Select Item </option>
                            </select>
                            <input type="number" id="cmpl_add_qty" class="form-control form-control-sm" placeholder="Qty" min="1" value="1" style="max-width:80px;">
                            <button class="btn btn-sm btn-primary" onclick="addMaterialRow()">
                                <i class="feather-plus me-1"></i> Add
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <p class="text-muted small mb-0">
                        <i class="feather-alert-circle me-1 text-warning"></i>
                        Stock will be deducted automatically upon confirmation.
                    </p>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-success px-4" id="cmpl_confirm_btn" onclick="confirmCompletion()">
                            <i class="feather-check-circle me-1"></i> Confirm &amp; Complete
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- ══════════════════════════════════════════════════════════
         CHECK-IN PATIENT MODAL
         ══════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="checkInModal" tabindex="-1" aria-labelledby="ciModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable ci-dialog">
            <div class="modal-content ci-modal">

                <div class="modal-header ci-header">
                    <div class="ci-header-main">
                        <span class="ci-header-icon" aria-hidden="true"><i class="feather-user-check"></i></span>
                        <div class="ci-header-text">
                            <h5 class="modal-title ci-title" id="ciModalTitle">Check In Patient</h5>
                            <p class="ci-subtitle">
                                <span class="ci-ref"><i class="feather-hash" aria-hidden="true"></i><span id="ci_apt_code"></span></span>
                                <span class="ci-patient" id="ci_patient_name"></span>
                            </p>
                        </div>
                    </div>
                    <button type="button" class="btn-close ci-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body ci-body">

                    <div id="ci_risk_banner" class="ci-risk" role="status" style="display:none;">
                        <i id="ci_risk_icon" aria-hidden="true"></i>
                        <div>
                            <div id="ci_risk_title" class="ci-risk-title"></div>
                            <div id="ci_risk_detail" class="ci-risk-detail"></div>
                        </div>
                    </div>

                    <div id="ci_medical_loading" class="ci-loading">
                        <div class="spinner-border spinner-border-sm" role="status"></div>
                        <span>Loading medical profile…</span>
                    </div>

                    <section id="ci_medical_summary" class="ci-section" style="display:none;" aria-labelledby="ciSummaryTitle">
                        <h6 class="ci-section-title" id="ciSummaryTitle"><i class="feather-clipboard" aria-hidden="true"></i> Medical Summary</h6>
                        <div class="ci-summary-grid">
                            <div class="ci-card">
                                <div class="ci-card-label"><i class="feather-heart" aria-hidden="true"></i> Conditions</div>
                                <div id="ci_conditions" class="ci-chips">—</div>
                            </div>
                            <div class="ci-card">
                                <div class="ci-card-label"><i class="feather-alert-triangle" aria-hidden="true"></i> Allergies</div>
                                <div id="ci_allergies" class="ci-chips">—</div>
                            </div>
                            <div class="ci-card ci-card--meds" id="ci_meds_row" style="display:none;">
                                <div class="ci-card-label"><i class="feather-alert-circle" aria-hidden="true"></i> Current Medications</div>
                                <div id="ci_meds" class="ci-meds"></div>
                            </div>
                        </div>
                    </section>

                    <section class="ci-section" aria-labelledby="ciVitalsTitle">
                        <h6 class="ci-section-title" id="ciVitalsTitle"><i class="feather-activity" aria-hidden="true"></i> Vital Signs</h6>

                        <input type="hidden" id="ci_appointment_id">
                        <input type="hidden" id="ci_patient_id">

                        <div class="ci-grid">
                            <div class="ci-field">
                                <label class="ci-label" for="ci_blood_pressure">Blood Pressure</label>
                                <div class="ci-input-unit">
                                    <input type="text" id="ci_blood_pressure" class="form-control ci-input" placeholder="e.g. 120/80">
                                    <span class="ci-unit">mmHg</span>
                                </div>
                            </div>
                            <div class="ci-field">
                                <label class="ci-label" for="ci_pulse_rate">Pulse Rate</label>
                                <div class="ci-input-unit">
                                    <input type="number" id="ci_pulse_rate" class="form-control ci-input" placeholder="e.g. 72" min="20" max="300">
                                    <span class="ci-unit">bpm</span>
                                </div>
                            </div>
                            <div class="ci-field">
                                <label class="ci-label" for="ci_temperature">Temperature</label>
                                <div class="ci-input-unit">
                                    <input type="number" id="ci_temperature" class="form-control ci-input" placeholder="e.g. 36.5" step="0.1" min="30" max="45">
                                    <span class="ci-unit">°C</span>
                                </div>
                            </div>
                            <div class="ci-field ci-span-half">
                                <label class="ci-label" for="ci_checked_by">Checked By</label>
                                <div class="ci-input-unit">
                                    <input type="text" id="ci_checked_by" class="form-control ci-input ci-readonly" readonly aria-readonly="true" tabindex="-1" title="Recorded as the staff member signed in">
                                    <i class="feather-lock ci-lock" aria-hidden="true"></i>
                                </div>
                            </div>
                            <div class="ci-field ci-span-half">
                                <label class="ci-label" for="ci_checked_at">Date &amp; Time</label>
                                <input type="datetime-local" id="ci_checked_at" class="form-control ci-input js-date-picker" data-dp-max="today" placeholder="Select date &amp; time">
                            </div>
                            <div class="ci-field ci-span-full">
                                <label class="ci-label" for="ci_notes">Notes <span class="ci-optional">(optional)</span></label>
                                <textarea id="ci_notes" class="form-control ci-input ci-textarea" rows="3" placeholder="Optional clinical notes…"></textarea>
                            </div>
                        </div>
                    </section>

                    <div id="ci_error" class="ci-error" role="alert" style="display:none;"></div>
                </div>

                <div class="modal-footer ci-footer">
                    <div class="ci-actions">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary px-4" id="ci_submit_btn" onclick="submitCheckIn()">
                            <i class="feather-user-check me-1"></i> Confirm Check-In
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- ══════════════════════════════════════════════════════════
         VIEW APPOINTMENT MODAL
         ══════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="viewAppointmentModal" tabindex="-1" aria-labelledby="viewAptTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-record-dialog">
            <div class="modal-content apt-record-content">

                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-calendar"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="viewAptTitle">Appointment Details</h5>
                            <div class="apt-record-meta">Reference No. <span class="apt-record-code" id="view_apt_code_sub">—</span></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body apt-record-body p-0">
                    <div id="view_loading" class="apt-record-loading">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-3 text-muted mb-0">Loading appointment…</p>
                    </div>

                    <div id="view_body" class="ar-view-body" style="display:none;">
                        <!-- Summary row -->
                        <div class="ar-summary">
                            <div class="ar-sum-item ar-sum-status">
                                <div class="ar-sum-label">Status</div>
                                <div id="view_status">—</div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-clock"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Date &amp; Time</div>
                                    <div class="ar-sum-value" id="view_date">—</div>
                                    <div class="ar-sum-value" id="view_time"></div>
                                </div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-map-pin"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Branch</div>
                                    <div class="ar-sum-value" id="view_branch">—</div>
                                </div>
                            </div>
                            <div class="ar-sum-item ar-sum-status">
                                <div class="ar-sum-label">Payment</div>
                                <div id="view_payment">—</div>
                            </div>
                        </div>

                        <div class="ar-cards-grid">
                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-user"></i></span>
                                    <h6 class="ar-card-title">Patient Information</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Patient Name</span><span class="ar-card-value ar-strong" id="view_patient">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Patient Code</span><span class="ar-card-value" id="view_patient_code">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Patient ID</span><span class="ar-card-value" id="view_patient_id">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Contact Number</span><span class="ar-card-value" id="view_contact">—</span></div>
                                </div>
                            </section>

                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-calendar"></i></span>
                                    <h6 class="ar-card-title">Appointment Information</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Appointment No.</span><span class="ar-card-value ar-strong" id="view_appt_code">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Schedule</span><span class="ar-card-value" id="view_datetime">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Type</span><span class="ar-card-value" id="view_type">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Dentist</span><span class="ar-card-value" id="view_dentist">—</span></div>
                                </div>
                            </section>

                            <section class="ar-card ar-card-wide">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-layers"></i></span>
                                    <h6 class="ar-card-title">Services</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <ol class="ar-svc-list" id="view_services"><li>—</li></ol>
                                </div>
                            </section>

                            <section class="ar-card ar-card-wide" id="view_notes_card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-file-text"></i></span>
                                    <h6 class="ar-card-title">Notes</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <p class="ar-notes" id="view_notes">—</p>
                                </div>
                            </section>
                        </div>
                    </div>

                    <div id="view_error" class="apt-record-error" role="alert" style="display:none;"></div>
                </div>

                <div class="modal-footer apt-record-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>


    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/vendors/js/daterangepicker.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/es-swal.js?v=2"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
    <script src="assets/js/appointments.js?v=6"></script>
    <script>
        const CURRENT_USER_NAME = <?= json_encode($_SESSION['full_name'] ?? $_SESSION['username'] ?? '', JSON_HEX_TAG | JSON_HEX_QUOT) ?>;

        function applyFilters() {
            window._applyFilters('appointments-schedule.php', {
                branch: 'filterBranch',
                status: 'filterStatus'
            });
        }

        function openAddModal() {
            document.getElementById('apt_id').value        = '';
            document.getElementById('modalTitle').textContent = 'Add Appointment';
            document.getElementById('modalSubtitle').textContent = 'Fill in the details below to schedule an appointment.';
            document.getElementById('modalError').style.display = 'none';
            if (typeof _aptClearFieldErrors === 'function') _aptClearFieldErrors();
            document.getElementById('aptStatusNotesCard').style.display = '';
            window._aptEditPreset = null;

            ['apt_branch', 'apt_patient', 'apt_notes'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });

            const dentistSel = document.getElementById('apt_dentist');
            dentistSel.innerHTML = '<option value=""> Select a branch first </option>';
            dentistSel.disabled  = true;

            document.getElementById('apt_status').value  = 'pending';
            document.getElementById('apt_payment').value = 'unpaid';
            if (window.refreshCustomSelects) refreshCustomSelects(); // custom dropdowns show the reset values
            document.getElementById('apt_date').value    = '';
            document.getElementById('apt_time').value    = '';

            const calWrap = document.getElementById('adminCalendarWrap');
            calWrap.className    = 'rounded-3 border';
            calWrap.style.cssText = '';
            calWrap.innerHTML    = `
                <div class="slot-empty">
                    <svg width="30" height="30" viewBox="0 0 32 32" fill="none" style="margin-bottom:6px;opacity:0.3;">
                        <rect x="3" y="5" width="26" height="22" rx="3" stroke="#6b7a8d" stroke-width="1.5"/>
                        <path d="M9 3v4M23 3v4M3 12h26" stroke="#6b7a8d" stroke-width="1.5" stroke-linecap="round"/>
                    </svg><br>Select a branch to load available dates.
                </div>`;

            document.getElementById('adminTimeSlots').style.display     = 'none';
            document.getElementById('aptDateTimeSummary').style.display = 'none';
            document.getElementById('modalSaveBtn').innerHTML = '<i class="feather-save me-1"></i> Save Appointment';

            if (typeof _aptResetServicePicker === 'function') _aptResetServicePicker();

            new bootstrap.Modal(document.getElementById('appointmentModal')).show();
        }

        function openEditModal(appointmentId) {
            document.getElementById('apt_id').value             = appointmentId;
            document.getElementById('modalTitle').textContent   = 'Edit Appointment';
            document.getElementById('modalSubtitle').textContent = 'Update the schedule, patient or services, then save your changes.';
            document.getElementById('modalError').style.display = 'none';
            if (typeof _aptClearFieldErrors === 'function') _aptClearFieldErrors();
            document.getElementById('aptStatusNotesCard').style.display = 'none';
            window._aptEditPreset = null;

            const calWrap = document.getElementById('adminCalendarWrap');
            calWrap.className    = 'rounded-3 border';
            calWrap.style.cssText = '';
            calWrap.innerHTML    = `
                <div class="slot-empty">
                    <svg width="30" height="30" viewBox="0 0 32 32" fill="none" style="margin-bottom:6px;opacity:0.3;">
                        <rect x="3" y="5" width="26" height="22" rx="3" stroke="#6b7a8d" stroke-width="1.5"/>
                        <path d="M9 3v4M23 3v4M3 12h26" stroke="#6b7a8d" stroke-width="1.5" stroke-linecap="round"/>
                    </svg><br>Select a branch to load available dates.
                </div>`;
            document.getElementById('adminTimeSlots').style.display     = 'none';
            document.getElementById('aptDateTimeSummary').style.display = 'none';
            document.getElementById('modalSaveBtn').innerHTML           = '<i class="feather-save me-1"></i> Save Changes';

            if (typeof _aptResetServicePicker === 'function') _aptResetServicePicker();

            new bootstrap.Modal(document.getElementById('appointmentModal')).show();

            fetch(`api/appointments.php?id=${appointmentId}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.message || 'Could not load appointment.');
                    const apt = data.data;

                    const branchSel  = document.getElementById('apt_branch');
                    const patientSel = document.getElementById('apt_patient');
                    branchSel.value  = apt.branch_id  || '';
                    patientSel.value = apt.patient_id || '';

                    if (window.refreshCustomSelects) refreshCustomSelects(); // custom dropdowns show the prefilled values

                    // Saved schedule — re-applied by the calendar after the branch
                    // change below resets the date/time fields (see appointments.js).
                    const presetDate = apt.appointment_date || '';
                    const presetTime = apt.appointment_time ? apt.appointment_time.slice(0, 5) : '';
                    window._aptEditPreset = (presetDate && presetTime) ? { date: presetDate, time: presetTime } : null;
                    document.getElementById('apt_date').value = presetDate;
                    document.getElementById('apt_time').value = presetTime;

                    if (apt.appointment_date && apt.appointment_time) {
                        const display = new Date(apt.appointment_date + 'T00:00:00')
                            .toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
                        const timeFmt = new Date('1970-01-01T' + apt.appointment_time)
                            .toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
                        document.getElementById('aptDateTimeText').textContent      = `${display} at ${timeFmt}`;
                        document.getElementById('aptDateTimeSummary').style.display = 'flex';
                    }

                    if (apt.branch_id) {
                        branchSel.dispatchEvent(new Event('change'));
                        const waitForDentists = setInterval(() => {
                            const sel = document.getElementById('apt_dentist');
                            if (sel && !sel.disabled && sel.options.length > 1) {
                                sel.value = apt.dentist_id || '';
                                if (sel._csRefresh) sel._csRefresh();
                                clearInterval(waitForDentists);
                            }
                        }, 100);
                        setTimeout(() => clearInterval(waitForDentists), 3000);
                    }

                    if (typeof _aptLoadExistingServices === 'function') {
                        _aptLoadExistingServices(apt.appointment_services || []);
                    }
                })
                .catch(err => {
                    document.getElementById('modalError').textContent   = err.message;
                    document.getElementById('modalError').style.display = 'block';
                });
        }

        document.addEventListener('click', function (e) {
            const el = e.target.closest('.ci-trigger');
            if (!el) return;
            openCheckInModal(
                el.dataset.aptId,
                el.dataset.patId,
                el.dataset.aptCode,
                el.dataset.patName
            );
        });

        function viewAppointment(appointmentId) {
            document.getElementById('view_loading').style.display  = 'block';
            document.getElementById('view_body').style.display     = 'none';
            document.getElementById('view_error').style.display    = 'none';
            document.getElementById('view_apt_code_sub').textContent = '—';

            new bootstrap.Modal(document.getElementById('viewAppointmentModal')).show();

            fetch(`api/appointments.php?id=${appointmentId}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.message || 'Failed to load.');

                    const apt = data.data;

                    const dateStr = apt.appointment_date
                        ? new Date(apt.appointment_date + 'T00:00:00')
                              .toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
                        : '—';
                    const timeStr = apt.appointment_time
                        ? new Date('1970-01-01T' + apt.appointment_time)
                              .toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
                        : '—';

                    const statusColors = {
                        pending:    'bg-soft-warning text-warning',
                        confirmed:  'bg-soft-success text-success',
                        checked_in: 'bg-soft-primary text-primary',
                        completed:  'bg-soft-info text-info',
                        cancelled:  'bg-soft-danger text-danger',
                        no_show:    'bg-soft-secondary text-secondary',
                    };
                    const statusLabel = apt.status === 'checked_in'
                        ? 'Checked In'
                        : apt.status === 'no_show'
                        ? 'No Show'
                        : (apt.status ? apt.status.charAt(0).toUpperCase() + apt.status.slice(1) : '—');
                    const statusClass = statusColors[apt.status] ?? 'bg-soft-secondary text-secondary';

                    const paymentColors = {
                        paid:    'bg-soft-success text-success',
                        partial: 'bg-soft-warning text-warning',
                        unpaid:  'bg-soft-danger text-danger',
                    };
                    const paymentClass = paymentColors[apt.payment_status] ?? 'bg-soft-secondary text-secondary';
                    const paymentLabel = apt.payment_status
                        ? apt.payment_status.charAt(0).toUpperCase() + apt.payment_status.slice(1)
                        : '—';

                    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
                    const aptServices = apt.appointment_services || [];
                    const svcNames = aptServices.length
                        ? aptServices.map(row => row.services?.service_name || '—')
                        : [apt.services?.service_name || 'General Consultation'];

                    const patientName   = apt.patients?.full_name    || '—';
                    const patientCode   = apt.patients?.patient_code  || '—';
                    const contactNumber = apt.resolved_contact        || '—';
                    const notes         = (apt.notes || '').trim();
                    const dentistName   = apt.dentists?.full_name || '';

                    document.getElementById('view_apt_code_sub').textContent    = apt.appointment_code || '—';
                    document.getElementById('view_appt_code').textContent       = apt.appointment_code || '—';
                    document.getElementById('view_patient_id').textContent      = apt.patient_id || '—';
                    document.getElementById('view_patient_code').textContent    = patientCode;
                    document.getElementById('view_patient').textContent         = patientName;
                    document.getElementById('view_contact').textContent         = contactNumber;
                    document.getElementById('view_date').textContent            = dateStr;
                    document.getElementById('view_time').textContent            = apt.appointment_time ? timeStr : '';
                    document.getElementById('view_datetime').textContent        = dateStr + '  ·  ' + timeStr;
                    document.getElementById('view_branch').textContent          = apt.branches?.branch_name || '—';
                    document.getElementById('view_type').textContent            = apt.appointment_type || '—';
                    const dentistEl = document.getElementById('view_dentist');
                    dentistEl.textContent = dentistName || 'Not yet assigned';
                    dentistEl.classList.toggle('ar-muted', !dentistName);
                    document.getElementById('view_services').innerHTML          = svcNames.map(n => `<li>${esc(n)}</li>`).join('');
                    document.getElementById('view_status').innerHTML            = `<span class="badge ar-dot ${statusClass}">${esc(statusLabel)}</span>`;
                    document.getElementById('view_payment').innerHTML           = `<span class="badge ar-dot ${paymentClass}">${esc(paymentLabel)}</span>`;
                    document.getElementById('view_notes').textContent           = notes;
                    document.getElementById('view_notes_card').style.display    = notes ? '' : 'none';

                    document.getElementById('view_loading').style.display = 'none';
                    document.getElementById('view_body').style.display    = 'block';
                })
                .catch(err => {
                    document.getElementById('view_loading').style.display = 'none';
                    document.getElementById('view_error').textContent     = err.message;
                    document.getElementById('view_error').style.display   = 'block';
                });
        }
    </script>
</body>

</html>