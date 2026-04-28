<?php
// =============================================================
//  appointments-records.php  — FIXED
//  Fixes applied:
//   1. Patient link: patient-record.php → patient-records.php
//   2. checked_in added to status filter + badge
//   3. deleteAppointment() JS properly wired to appointment_crud.php
//   4. aptStatusBadgeClass() / aptStatusDisplay() handle checked_in
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
$page         = max(1, (int)($_GET['page'] ?? 1));

$data         = getAppointmentRecords($page, $filterBranch, $filterStatus);
$appointments = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];

$baseUrl = 'appointments-records.php?branch=' . $filterBranch . '&status=' . urlencode($filterStatus);

// ── Local badge helpers (mirror appointment_controller values) ─
function aptStatusBadgeClass(string $s): string {
    return match ($s) {
        'pending'    => 'bg-soft-warning text-warning',
        'confirmed'  => 'bg-soft-success text-success',
        'checked_in' => 'bg-soft-primary text-primary-emphasis',
        'completed'  => 'bg-soft-info text-info',
        'cancelled'  => 'bg-soft-danger text-danger',
        default      => 'bg-soft-secondary text-secondary',
    };
}
function aptStatusDisplay(string $s): string {
    return $s === 'checked_in' ? 'Checked In' : ucfirst($s);
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
    <link rel="stylesheet" href="assets/css/custom.css">
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
                        <li class="breadcrumb-item">Records</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <select id="filterStatus" class="form-select hdr-control" onchange="applyFilters()">
                                <option value="">All Statuses</option>
                                <option value="pending"    <?= $filterStatus === 'pending'    ? 'selected' : '' ?>>Pending</option>
                                <option value="confirmed"  <?= $filterStatus === 'confirmed'  ? 'selected' : '' ?>>Confirmed</option>
                                <option value="checked_in" <?= $filterStatus === 'checked_in' ? 'selected' : '' ?>>Checked In</option>
                                <option value="completed"  <?= $filterStatus === 'completed'  ? 'selected' : '' ?>>Completed</option>
                                <option value="cancelled"  <?= $filterStatus === 'cancelled'  ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                            <?php if (isOwner()): ?>
                                <select id="filterBranch" class="form-select hdr-control" onchange="applyFilters()">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?= $b['branch_id'] ?>" <?= (int)$b['branch_id'] === $filterBranch ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($b['branch_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
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

                            <div class="card-body custom-card-action p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
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
                                                        <i class="feather-calendar me-2"></i>No records found.
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($appointments as $apt): ?>
                                                    <tr>
                                                        <td><span class="fw-semibold"><?= htmlspecialchars($apt['appointment_code'] ?? '—') ?></span></td>
                                                        <td><?= formatAptDate($apt['appointment_date']) ?></td>
                                                        <td><?= formatAptTime($apt['appointment_time']) ?></td>
                                                        <td>
                                                            <!-- ★ FIX 1: correct filename patient-records.php + cast to int -->
                                                            <a href="patient-records.php?id=<?= (int)($apt['patient_id'] ?? 0) ?>"
                                                               class="text-primary fw-semibold">
                                                                <?= htmlspecialchars($apt['patients']['full_name'] ?? '—') ?>
                                                            </a>
                                                        </td>
                                                        <td><?= htmlspecialchars($apt['branches']['branch_name'] ?? '—') ?></td>
                                                        <td><?= htmlspecialchars($apt['services']['service_name'] ?? 'General Consultation') ?></td>
                                                        <td>
                                                            <span class="badge <?= aptPaymentBadge($apt['payment_status']) ?>">
                                                                <?= ucfirst($apt['payment_status'] ?? 'unpaid') ?>
                                                            </span>
                                                        </td>
                                                        <!-- ★ FIX 2: checked_in badge -->
                                                        <td>
                                                            <span class="badge <?= aptStatusBadgeClass($apt['status'] ?? '') ?>">
                                                                <?= aptStatusDisplay($apt['status'] ?? '') ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-end">
                                                            <div class="dropdown">
                                                                <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown">
                                                                    <i class="feather-more-vertical"></i>
                                                                </a>
                                                                <div class="dropdown-menu dropdown-menu-end action-dropdown">
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
                                                                        <div class="dropdown-divider"></div>
                                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                                            onclick='openEditModal(<?= json_encode([
                                                                                "appointment_id"   => $apt["appointment_id"],
                                                                                "patient_id"       => $apt["patient_id"],
                                                                                "branch_id"        => $apt["branch_id"],
                                                                                "dentist_id"       => $apt["dentist_id"],
                                                                                "service_id"       => $apt["service_id"],
                                                                                "appointment_date" => $apt["appointment_date"],
                                                                                "appointment_time" => $apt["appointment_time"],
                                                                                "appointment_type" => $apt["appointment_type"],
                                                                                "notes"            => $apt["notes"],
                                                                                "status"           => $apt["status"],
                                                                                "payment_status"   => $apt["payment_status"],
                                                                            ], JSON_HEX_QUOT|JSON_HEX_TAG) ?>)'>
                                                                            <i class="feather-edit"></i> Edit
                                                                        </a>
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
                                    <?php if ($filterBranch || $filterStatus): ?>
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
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/appointments.js"></script>
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
                title:  'Delete Appointment?',
                html:   `Appointment <strong>${code}</strong> will be permanently removed.<br>
                         <span class="text-danger small">This cannot be undone.</span>`,
                icon:   'warning',
                showCancelButton:   true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor:  '#6c757d',
                confirmButtonText:  'Yes, Delete',
                cancelButtonText:   'Cancel',
            }).then(result => {
                if (!result.isConfirmed) return;

                fetch('api/appointment_crud.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ action: 'delete', appointment_id: appointmentId }),
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        Swal.fire({
                            icon: 'success', title: 'Deleted!',
                            text: res.message || 'Appointment removed.',
                            timer: 1600, showConfirmButton: false,
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Cannot Delete', res.message || 'An error occurred.', 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'Network error. Please try again.', 'error'));
            });
        }
    </script>
</body>
</html>