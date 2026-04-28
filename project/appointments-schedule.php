<?php
// =============================================================
//  appointments-schedule.php  — FIXED
//  Fix: patient-record.php → patient-records.php (line ~167)
//  Fix: aptStatusBadge properly handles checked_in
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/appointment_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/dbconfig.php';

$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : getSessionBranch();
if (!isOwner() && $filterBranch !== getSessionBranch()) $filterBranch = getSessionBranch();
$branches     = getAllBranches();
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$filterDate   = isset($_GET['date'])   ? trim($_GET['date'])   : '';
$page         = max(1, (int)($_GET['page'] ?? 1));

$data         = getAppointmentSchedule($page, $filterBranch, $filterStatus, $filterDate);
$appointments = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];

$baseUrl = 'appointments-schedule.php?branch=' . $filterBranch
    . '&status=' . urlencode($filterStatus)
    . '&date='   . urlencode($filterDate);

// Fetch dropdown data for the Add Appointment modal
$patientsRes  = supabase_request('patients', 'GET', [], 'select=patient_id,full_name&order=full_name.asc&limit=500');
$dentistsRes  = supabase_request('dentists', 'GET', [], 'select=dentist_id,full_name&order=full_name.asc&limit=200');
$servicesRes  = supabase_request('services', 'GET', [], 'select=service_id,service_name,base_price&order=service_name.asc&limit=200&status=eq.active');
$patientsList = is_array($patientsRes['data']) ? $patientsRes['data'] : [];
$dentistsList = is_array($dentistsRes['data']) ? $dentistsRes['data'] : [];
$servicesList = is_array($servicesRes['data']) ? $servicesRes['data'] : [];

// ── Local badge helpers ────────────────────────────────────────
function schStatusBadgeClass(string $s): string {
    return match ($s) {
        'pending'    => 'bg-soft-warning text-warning',
        'confirmed'  => 'bg-soft-success text-success',
        'checked_in' => 'bg-soft-primary text-primary-emphasis',
        'completed'  => 'bg-soft-info text-info',
        'cancelled'  => 'bg-soft-danger text-danger',
        default      => 'bg-soft-secondary text-secondary',
    };
}
function schStatusDisplay(string $s): string {
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
                    <div class="page-header-title"><h5 class="m-b-10">Appointments</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Schedules</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <input type="date" id="filterDate" class="form-control hdr-control"
                                value="<?= htmlspecialchars($filterDate) ?>"
                                onchange="applyFilters()">
                            <select id="filterStatus" class="form-select hdr-control" onchange="applyFilters()">
                                <option value="">All Active</option>
                                <option value="pending"    <?= $filterStatus==='pending'    ? 'selected':'' ?>>Pending</option>
                                <option value="confirmed"  <?= $filterStatus==='confirmed'  ? 'selected':'' ?>>Confirmed</option>
                                <option value="checked_in" <?= $filterStatus==='checked_in' ? 'selected':'' ?>>Checked In</option>
                            </select>
                            <?php if (isOwner()): ?>
                            <select id="filterBranch" class="form-select hdr-control" onchange="applyFilters()">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>" <?= (int)$b['branch_id']===$filterBranch ? 'selected':'' ?>>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
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

                            <div class="card-body custom-card-action p-0">
                                <div class="table-responsive" style="overflow:visible;">
                                    <table class="table table-hover mb-0" style="min-width:900px;">
                                        <thead>
                                            <tr>
                                                <th>Appointment ID</th>
                                                <th>Date</th>
                                                <th>Time</th>
                                                <th>Patient Name</th>
                                                <th>Branch</th>
                                                <th>Service</th>
                                                <th>Payment</th>
                                                <th>Status</th>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($appointments)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center py-4 text-muted">
                                                    <i class="feather-calendar me-2"></i>No appointments found.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($appointments as $apt): ?>
                                            <tr>
                                                <td><span class="fw-semibold"><?= htmlspecialchars($apt['appointment_code'] ?? '—') ?></span></td>
                                                <td><?= formatAptDate($apt['appointment_date']) ?></td>
                                                <td><?= formatAptTime($apt['appointment_time']) ?></td>
                                                <td>
                                                    <div class="hstack gap-2">
                                                        <!-- ★ FIX: patient-records.php (with "s") + cast id to int -->
                                                        <a href="patient-records.php?id=<?= (int)($apt['patient_id'] ?? 0) ?>"
                                                           class="text-primary fw-semibold">
                                                            <?= htmlspecialchars($apt['patients']['full_name'] ?? '—') ?>
                                                        </a>
                                                    </div>
                                                </td>
                                                <td><?= htmlspecialchars($apt['branches']['branch_name'] ?? '—') ?></td>
                                                <td><?= htmlspecialchars($apt['services']['service_name'] ?? 'General Consultation') ?></td>
                                                <td>
                                                    <span class="badge <?= aptPaymentBadge($apt['payment_status']) ?>">
                                                        <?= ucfirst($apt['payment_status'] ?? 'Unpaid') ?>
                                                    </span>
                                                </td>
                                                <!-- ★ FIX: checked_in badge class + readable label -->
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
                                                            <?php if ($apt['status'] === 'pending'): ?>
                                                            <a href="javascript:void(0);" class="dropdown-item text-success"
                                                               onclick="updateStatus(<?= (int)$apt['appointment_id'] ?>, 'confirmed', '<?= htmlspecialchars($apt['appointment_code'], ENT_QUOTES) ?>')">
                                                               <i class="feather-check-circle"></i> Confirm Appointment
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if (in_array($apt['status'], ['pending', 'confirmed'])): ?>
                                                            <a href="javascript:void(0);" class="dropdown-item text-warning"
                                                               onclick="updateStatus(<?= (int)$apt['appointment_id'] ?>, 'checked_in', '<?= htmlspecialchars($apt['appointment_code'], ENT_QUOTES) ?>')">
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
                                                            <div class="dropdown-divider"></div>
                                                            <a href="javascript:void(0);" class="dropdown-item"><i class="feather-printer"></i> Print</a>
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
                                    <?php if ($filterBranch || $filterStatus || $filterDate): ?>
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

    <!-- ── ADD APPOINTMENT MODAL ──────────────────────────── -->
    <?php if (!isDentist()): ?>
    <div class="modal fade" id="appointmentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 pb-0 px-4 pt-4">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="modalTitle">Add Appointment</h5>
                        <p class="text-muted small mb-0">Fill in the details below to schedule an appointment.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3 pb-0">
                    <div id="modalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="apt_id">
                    <div class="row g-0">
                        <div class="col-lg-7 pe-lg-3 border-end-lg">
                            <p class="fw-semibold small text-uppercase text-muted mb-2 letter-spacing-1">
                                <i class="feather-calendar me-1"></i> Select Date &amp; Time
                            </p>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Branch <span class="text-danger">*</span></label>
                                <select id="apt_branch" class="form-select form-select-sm">
                                    <option value="">— Select Branch —</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?= $b['branch_id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="adminCalendarWrap" class="rounded-3 border bg-light d-flex align-items-center justify-content-center text-muted small" style="min-height:120px; padding:16px;">
                                <div class="text-center">
                                    <i class="feather-map-pin d-block mb-1" style="font-size:1.4rem;"></i>
                                    Select a branch to load available dates.
                                </div>
                            </div>
                            <div id="adminTimeSlots" class="mt-2" style="display:none;">
                                <p class="fw-semibold small text-muted mb-1 mt-2">
                                    <i class="feather-clock me-1"></i>Available Time Slots
                                </p>
                                <div id="adminTimeSlotsGrid" class="d-flex flex-wrap gap-1" style="max-height:150px; overflow-y:auto;"></div>
                            </div>
                            <input type="hidden" id="apt_date">
                            <input type="hidden" id="apt_time">
                            <div id="aptDateTimeSummary" class="mt-2 px-3 py-2 rounded-3 d-flex align-items-center gap-2" style="display:none;">
                                <i class="feather-check-circle text-success"></i>
                                <span class="small fw-semibold text-success" id="aptDateTimeText"></span>
                            </div>
                        </div>
                        <div class="col-lg-5 ps-lg-3 mt-3 mt-lg-0">
                            <p class="fw-semibold small text-uppercase text-muted mb-2 letter-spacing-1">
                                <i class="feather-clipboard me-1"></i> Appointment Details
                            </p>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label fw-semibold small mb-1">Patient <span class="text-danger">*</span></label>
                                    <select id="apt_patient" class="form-select form-select-sm">
                                        <option value="">— Select Patient —</option>
                                        <?php foreach ($patientsList as $pt): ?>
                                            <option value="<?= $pt['patient_id'] ?>"><?= htmlspecialchars($pt['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold small mb-1">Dentist</label>
                                    <select id="apt_dentist" class="form-select form-select-sm">
                                        <option value="">— Select Dentist —</option>
                                        <?php foreach ($dentistsList as $dt): ?>
                                            <option value="<?= $dt['dentist_id'] ?>"><?= htmlspecialchars($dt['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold small mb-1">Service</label>
                                    <select id="apt_service" class="form-select form-select-sm">
                                        <option value="">— Select Service —</option>
                                        <?php foreach ($servicesList as $sv): ?>
                                            <option value="<?= $sv['service_id'] ?>" data-price="<?= (float)($sv['base_price'] ?? 0) ?>">
                                                <?= htmlspecialchars($sv['service_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold small mb-1">Type</label>
                                    <select id="apt_type" class="form-select form-select-sm">
                                        <option value="Consultation">Consultation</option>
                                        <option value="Procedure">Procedure</option>
                                        <option value="Cosmetic">Cosmetic</option>
                                        <option value="Orthodontic">Orthodontic</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold small mb-1">Status <span class="text-danger">*</span></label>
                                    <select id="apt_status" class="form-select form-select-sm">
                                        <option value="pending">Pending</option>
                                        <option value="confirmed">Confirmed</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold small mb-1">Payment</label>
                                    <select id="apt_payment" class="form-select form-select-sm">
                                        <option value="unpaid">Unpaid</option>
                                        <option value="paid">Paid</option>
                                        <option value="partial">Partial</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold small mb-1">Notes</label>
                                    <textarea id="apt_notes" class="form-control form-control-sm" rows="3" placeholder="Optional notes..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4 pt-3 d-flex justify-content-between align-items-center">
                    <span class="text-muted small"><span class="text-danger">*</span> Required fields</span>
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

    <!-- ── COMPLETION MODAL ───────────────────────────────── -->
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
                                <tr><th>Item</th><th>Current Stock</th><th>Qty to Deduct</th><th></th></tr>
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
                            <select id="cmpl_add_item" class="form-select form-select-sm" style="max-width:300px;">
                                <option value="">— Select Item —</option>
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
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-success px-4" id="cmpl_confirm_btn" onclick="confirmCompletion()">
                            <i class="feather-check-circle me-1"></i> Confirm &amp; Complete
                        </button>
                    </div>
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
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/appointments.js"></script>
    <script>
    function applyFilters() {
        window._applyFilters('appointments-schedule.php', {
            branch: 'filterBranch',
            status: 'filterStatus',
            date:   'filterDate'
        });
    }
    function openAddModal() {
        document.getElementById('apt_id').value = '';
        document.getElementById('modalTitle').textContent = 'Add Appointment';
        document.getElementById('modalError').style.display = 'none';
        ['apt_branch','apt_patient','apt_dentist','apt_service','apt_notes'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        document.getElementById('apt_type').value    = 'Consultation';
        document.getElementById('apt_status').value  = 'pending';
        document.getElementById('apt_payment').value = 'unpaid';
        document.getElementById('apt_date').value    = '';
        document.getElementById('apt_time').value    = '';
        document.getElementById('adminCalendarWrap').innerHTML =
            '<div class="text-center"><i class="feather-map-pin d-block mb-1" style="font-size:1.4rem;"></i>Select a branch to load available dates.</div>';
        document.getElementById('adminTimeSlots').style.display     = 'none';
        document.getElementById('aptDateTimeSummary').style.display = 'none';
        document.getElementById('modalSaveBtn').innerHTML = '<i class="feather-save me-1"></i> Save Appointment';
        new bootstrap.Modal(document.getElementById('appointmentModal')).show();
    }
    </script>
</body>
</html>