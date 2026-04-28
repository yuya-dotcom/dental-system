<?php
// =============================================================
//  dashboard.php — FIXED
//  Changes:
//   1. Quick Summary rows are now fully clickable links
//   2. "Total Patients" arrow corrected: patients-list.php
//   3. All stat cards' arrow links verified
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/dashboard_controller.php';

$stats = getDashboardStats();

$aptCompletionRate = $stats['total_appointments'] > 0
    ? round(($stats['completed_appointments'] / $stats['total_appointments']) * 100)
    : 0;

$newPatPct = $stats['total_patients'] > 0
    ? round(($stats['new_patients_month'] / $stats['total_patients']) * 100)
    : 0;
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Dashboard</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        /*
         * Make every Quick Summary row behave like a clickable card.
         * The row itself is wrapped in an <a> tag so the entire strip
         * is tappable — not just a small arrow icon.
         */
        .summary-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1.25rem;        /* mirrors px-4 py-3 */
            text-decoration: none;
            color: inherit;
            transition: background-color .15s ease;
        }
        .summary-link:hover {
            background-color: rgba(0, 0, 0, .03);
            color: inherit;
            text-decoration: none;
        }
        .summary-link:hover .summary-arrow {
            opacity: 1;
            transform: translateX(3px);
        }
        .summary-arrow {
            opacity: 0;
            transition: opacity .15s ease, transform .15s ease;
            color: #6c757d;
            font-size: .75rem;
            margin-left: .5rem;
            flex-shrink: 0;
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
                    <div class="page-header-title"><h5 class="m-b-10">Dashboard</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Overview</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <span class="text-muted small">
                                <i class="feather-calendar me-1"></i><?= $stats['today_label'] ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-content">
                <div class="row">

                    <!-- ── STAT: Today's Appointments ── -->
                    <div class="col-xxl-3 col-md-6">
                        <div class="card stretch stretch-full">
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between mb-4">
                                    <div class="d-flex gap-4 align-items-center">
                                        <div class="avatar-text avatar-lg bg-gray-200">
                                            <i class="feather-calendar"></i>
                                        </div>
                                        <div>
                                            <div class="fs-4 fw-bold text-dark"><?= $stats['today_appointments'] ?></div>
                                            <h3 class="fs-13 fw-semibold text-truncate-1-line">Today's Appointments</h3>
                                        </div>
                                    </div>
                                    <a href="appointments-schedule.php" class="text-muted">
                                        <i class="feather-arrow-right"></i>
                                    </a>
                                </div>
                                <div class="pt-4">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="fs-12 fw-medium text-muted">All-time total</span>
                                        <div class="w-100 text-end">
                                            <span class="fs-12 text-dark"><?= $stats['total_appointments'] ?> total</span>
                                            <span class="fs-11 text-muted"> (<?= $aptCompletionRate ?>% done)</span>
                                        </div>
                                    </div>
                                    <div class="progress mt-2 ht-3">
                                        <div class="progress-bar bg-primary" style="width:<?= $aptCompletionRate ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── STAT: Patients ── -->
                    <div class="col-xxl-3 col-md-6">
                        <div class="card stretch stretch-full">
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between mb-4">
                                    <div class="d-flex gap-4 align-items-center">
                                        <div class="avatar-text avatar-lg bg-gray-200">
                                            <i class="feather-users"></i>
                                        </div>
                                        <div>
                                            <div class="fs-4 fw-bold text-dark"><?= $stats['total_patients'] ?></div>
                                            <h3 class="fs-13 fw-semibold text-truncate-1-line">Total Patients</h3>
                                        </div>
                                    </div>
                                    <!-- Fixed: was patients-records.php → patients-list.php -->
                                    <a href="patients-list.php" class="text-muted">
                                        <i class="feather-arrow-right"></i>
                                    </a>
                                </div>
                                <div class="pt-4">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="fs-12 fw-medium text-muted">New this month</span>
                                        <div class="w-100 text-end">
                                            <span class="fs-12 text-dark">+<?= $stats['new_patients_month'] ?></span>
                                            <span class="fs-11 text-muted"> (<?= $newPatPct ?>% of total)</span>
                                        </div>
                                    </div>
                                    <div class="progress mt-2 ht-3">
                                        <div class="progress-bar bg-success" style="width:<?= min(100, $newPatPct + 20) ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── STAT: Active Treatments ── -->
                    <div class="col-xxl-3 col-md-6">
                        <div class="card stretch stretch-full">
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between mb-4">
                                    <div class="d-flex gap-4 align-items-center">
                                        <div class="avatar-text avatar-lg bg-gray-200">
                                            <i class="feather-activity"></i>
                                        </div>
                                        <div>
                                            <div class="fs-4 fw-bold text-dark"><?= $stats['active_treatments'] ?></div>
                                            <h3 class="fs-13 fw-semibold text-truncate-1-line">Active Treatments</h3>
                                        </div>
                                    </div>
                                    <a href="treatments-active.php" class="text-muted">
                                        <i class="feather-arrow-right"></i>
                                    </a>
                                </div>
                                <div class="pt-4">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="fs-12 fw-medium text-muted">Completed all-time</span>
                                        <div class="w-100 text-end">
                                            <span class="fs-12 text-dark"><?= $stats['completed_treatments'] ?> done</span>
                                        </div>
                                    </div>
                                    <div class="progress mt-2 ht-3">
                                        <div class="progress-bar bg-warning" style="width:60%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── STAT: Revenue ── -->
                    <div class="col-xxl-3 col-md-6">
                        <div class="card stretch stretch-full">
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between mb-4">
                                    <div class="d-flex gap-4 align-items-center">
                                        <div class="avatar-text avatar-lg bg-gray-200">
                                            <i class="feather-dollar-sign"></i>
                                        </div>
                                        <div>
                                            <div class="fs-4 fw-bold text-dark">
                                                ₱<?= number_format($stats['month_revenue'], 0) ?>
                                            </div>
                                            <h3 class="fs-13 fw-semibold text-truncate-1-line">Revenue This Month</h3>
                                        </div>
                                    </div>
                                    <a href="billing.php" class="text-muted">
                                        <i class="feather-arrow-right"></i>
                                    </a>
                                </div>
                                <div class="pt-4">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="fs-12 fw-medium text-muted">Total collected</span>
                                        <div class="w-100 text-end">
                                            <span class="fs-12 text-dark">₱<?= number_format($stats['total_revenue'], 0) ?></span>
                                            <?php if ($stats['unpaid_balance'] > 0): ?>
                                            <span class="fs-11 text-danger">
                                                · ₱<?= number_format($stats['unpaid_balance'], 0) ?> unpaid
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="progress mt-2 ht-3">
                                        <div class="progress-bar bg-danger" style="width:65%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── TODAY'S SCHEDULE TABLE ── -->
                    <div class="col-xxl-8">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title">
                                    Today's Schedule
                                    <span class="badge bg-soft-primary text-primary ms-2">
                                        <?= $stats['today_appointments'] ?> appointments
                                    </span>
                                </h5>
                                <div class="card-header-action">
                                    <a href="appointments-schedule.php" class="btn btn-sm btn-light-brand">
                                        View All <i class="feather-arrow-right ms-1"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body custom-card-action p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Code</th>
                                                <th>Time</th>
                                                <th>Patient</th>
                                                <th>Service</th>
                                                <th>Branch</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($stats['today_list'])): ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted">
                                                    <i class="feather-calendar me-2"></i>No appointments scheduled for today.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($stats['today_list'] as $apt): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-semibold">
                                                        <?= htmlspecialchars($apt['appointment_code'] ?? '—') ?>
                                                    </span>
                                                </td>
                                                <td><?= date('g:i A', strtotime($apt['appointment_time'] ?? '00:00')) ?></td>
                                                <td>
                                                    <!-- Patient name in today's table also links correctly -->
                                                    <a href="patient-records.php?id=<?= (int)($apt['patient_id'] ?? 0) ?>"
                                                       class="text-dark text-decoration-none fw-semibold">
                                                        <?= htmlspecialchars($apt['patients']['full_name'] ?? '—') ?>
                                                    </a>
                                                </td>
                                                <td><?= htmlspecialchars($apt['services']['service_name'] ?? '—') ?></td>
                                                <td><?= htmlspecialchars($apt['branches']['branch_name'] ?? '—') ?></td>
                                                <td>
                                                    <span class="badge <?= aptStatusBadge($apt['status'] ?? '') ?>">
                                                        <?= ucfirst($apt['status'] ?? '—') ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── QUICK SUMMARY SIDEBAR ── -->
                    <div class="col-xxl-4">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title">Quick Summary</h5>
                            </div>
                            <div class="card-body p-0">

                                <!--
                                    Each row is now wrapped in an <a class="summary-link">
                                    making the ENTIRE strip clickable, not just a small icon.
                                    The .summary-arrow slides in on hover for a polished feel.
                                    border-bottom is moved to the <a> tag so it renders correctly.
                                -->

                                <!-- 1. Pending → appointments-schedule.php -->
                                <a href="appointments-schedule.php?status=pending"
                                   class="summary-link border-bottom">
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="avatar-text avatar-sm bg-soft-warning text-warning">
                                            <i class="feather-clock"></i>
                                        </span>
                                        <div>
                                            <div class="fw-semibold text-dark">Pending</div>
                                            <div class="fs-12 text-muted">Awaiting confirmation</div>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fs-5 fw-bold text-warning"><?= $stats['pending_appointments'] ?></span>
                                        <i class="feather-arrow-right summary-arrow"></i>
                                    </div>
                                </a>

                                <!-- 2. Completed Appointments → appointments-records.php -->
                                <a href="appointments-records.php?status=completed"
                                   class="summary-link border-bottom">
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="avatar-text avatar-sm bg-soft-success text-success">
                                            <i class="feather-check-circle"></i>
                                        </span>
                                        <div>
                                            <div class="fw-semibold text-dark">Completed Appointments</div>
                                            <div class="fs-12 text-muted">All time</div>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fs-5 fw-bold text-success"><?= $stats['completed_appointments'] ?></span>
                                        <i class="feather-arrow-right summary-arrow"></i>
                                    </div>
                                </a>

                                <!-- 3. New Patients → patients-list.php -->
                                <a href="patients-list.php"
                                   class="summary-link border-bottom">
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="avatar-text avatar-sm bg-soft-primary text-primary">
                                            <i class="feather-user-plus"></i>
                                        </span>
                                        <div>
                                            <div class="fw-semibold text-dark">New Patients</div>
                                            <div class="fs-12 text-muted"><?= $stats['month_label'] ?></div>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fs-5 fw-bold text-primary"><?= $stats['new_patients_month'] ?></span>
                                        <i class="feather-arrow-right summary-arrow"></i>
                                    </div>
                                </a>

                                <!-- 4. Active Treatments → treatments-active.php -->
                                <a href="treatments-active.php"
                                   class="summary-link border-bottom">
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="avatar-text avatar-sm bg-soft-info text-info">
                                            <i class="feather-activity"></i>
                                        </span>
                                        <div>
                                            <div class="fw-semibold text-dark">Active Treatments</div>
                                            <div class="fs-12 text-muted">Ongoing / In Progress</div>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fs-5 fw-bold text-info"><?= $stats['active_treatments'] ?></span>
                                        <i class="feather-arrow-right summary-arrow"></i>
                                    </div>
                                </a>

                                <!-- 5. Unpaid Balance → billing.php -->
                                <a href="billing-records.php?payment_status=unpaid"
                                   class="summary-link">
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="avatar-text avatar-sm bg-soft-danger text-danger">
                                            <i class="feather-alert-circle"></i>
                                        </span>
                                        <div>
                                            <div class="fw-semibold text-dark">Unpaid Balance</div>
                                            <div class="fs-12 text-muted">Outstanding invoices</div>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fs-5 fw-bold text-danger">
                                            ₱<?= number_format($stats['unpaid_balance'], 0) ?>
                                        </span>
                                        <i class="feather-arrow-right summary-arrow"></i>
                                    </div>
                                </a>

                            </div>
                        </div>
                    </div>

                </div><!-- /row -->
            </div>
        </div>

        <footer class="footer">
            <p class="fs-11 text-muted fw-medium text-uppercase mb-0 copyright">
                <span>Copyright © <?= date('Y') ?> EssenciaSmile</span>
            </p>
        </footer>
    </main>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
</body>
</html>