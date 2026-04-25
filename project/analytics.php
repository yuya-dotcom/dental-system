<?php

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
// =============================================================
//  analytics.php — Dental Analytics
//  Business logic: controllers/dashboard_controller.php
// =============================================================
require_once __DIR__ . '/controllers/dashboard_controller.php';

$stats = getDashboardStats();

// ── Appointments per month (last 6 months) ───────────────────
require_once __DIR__ . '/dbconfig.php';
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $start = date('Y-m-01', strtotime("-$i months"));
    $end   = date('Y-m-t',  strtotime("-$i months"));
    $label = date('M Y',    strtotime("-$i months"));
    $res   = supabase_request('appointments', 'GET', [],
        'select=status&appointment_date=gte.' . $start . '&appointment_date=lte.' . $end,
        ['Prefer: count=exact']);
    $cr    = $res['headers']['content-range'] ?? '';
    $count = ($cr && str_contains($cr, '/')) ? (int)explode('/', $cr)[1] : count($res['data'] ?? []);

    // completed count for that month
    $resC  = supabase_request('appointments', 'GET', [],
        'select=status&status=eq.completed&appointment_date=gte.' . $start . '&appointment_date=lte.' . $end,
        ['Prefer: count=exact']);
    $crC   = $resC['headers']['content-range'] ?? '';
    $comp  = ($crC && str_contains($crC, '/')) ? (int)explode('/', $crC)[1] : count($resC['data'] ?? []);

    $monthlyData[] = ['label' => $label, 'total' => $count, 'completed' => $comp];
}

// ── Top services ─────────────────────────────────────────────
$svcRes = supabase_request('appointments', 'GET', [],
    'select=services(service_name)&service_id=not.is.null&limit=200');
$svcCounts = [];
if (is_array($svcRes['data'])) {
    foreach ($svcRes['data'] as $row) {
        $name = $row['services']['service_name'] ?? 'Unknown';
        $svcCounts[$name] = ($svcCounts[$name] ?? 0) + 1;
    }
}
arsort($svcCounts);
$topServices = array_slice($svcCounts, 0, 6, true);

// ── Per-branch appointment counts ────────────────────────────
require_once __DIR__ . '/controllers/branch_controller.php';
$branches    = getAllBranches();
$branchStats = [];
foreach ($branches as $b) {
    $bRes  = supabase_request('appointments', 'GET', [],
        'select=appointment_id&branch_id=eq.' . $b['branch_id'],
        ['Prefer: count=exact']);
    $bCr   = $bRes['headers']['content-range'] ?? '';
    $bCount = ($bCr && str_contains($bCr, '/')) ? (int)explode('/', $bCr)[1] : count($bRes['data'] ?? []);
    $branchStats[] = ['name' => $b['branch_name'], 'count' => $bCount];
}
$maxBranch = max(array_column($branchStats, 'count') ?: [1]);

// JSON for ApexCharts
$chartLabels    = json_encode(array_column($monthlyData, 'label'));
$chartTotal     = json_encode(array_column($monthlyData, 'total'));
$chartCompleted = json_encode(array_column($monthlyData, 'completed'));
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Analytics</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
</head>
<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>

    <main class="nxl-container">
        <div class="nxl-content">

            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">Analytics</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Analytics</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <span class="text-muted small"><i class="feather-calendar me-1"></i><?= $stats['today_label'] ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-content">
                <div class="row">

                    <!-- ── STAT CARDS ROW ── -->
                    <div class="col-xxl-3 col-md-6">
                        <div class="card stretch stretch-full">
                            <div class="card-body text-center py-4">
                                <div class="fs-1 fw-bold text-primary mb-1"><?= $stats['total_appointments'] ?></div>
                                <div class="fs-13 fw-semibold text-dark">Total Appointments</div>
                                <div class="fs-12 text-muted mt-1"><?= $stats['completed_appointments'] ?> completed · <?= $stats['pending_appointments'] ?> pending</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-3 col-md-6">
                        <div class="card stretch stretch-full">
                            <div class="card-body text-center py-4">
                                <div class="fs-1 fw-bold text-success mb-1"><?= $stats['total_patients'] ?></div>
                                <div class="fs-13 fw-semibold text-dark">Total Patients</div>
                                <div class="fs-12 text-muted mt-1">+<?= $stats['new_patients_month'] ?> new in <?= $stats['month_label'] ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-3 col-md-6">
                        <div class="card stretch stretch-full">
                            <div class="card-body text-center py-4">
                                <div class="fs-1 fw-bold text-warning mb-1"><?= $stats['active_treatments'] ?></div>
                                <div class="fs-13 fw-semibold text-dark">Active Treatments</div>
                                <div class="fs-12 text-muted mt-1"><?= $stats['completed_treatments'] ?> completed all-time</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-3 col-md-6">
                        <div class="card stretch stretch-full">
                            <div class="card-body text-center py-4">
                                <div class="fs-1 fw-bold text-danger mb-1">₱<?= number_format($stats['total_revenue'], 0) ?></div>
                                <div class="fs-13 fw-semibold text-dark">Total Revenue</div>
                                <div class="fs-12 text-muted mt-1">₱<?= number_format($stats['month_revenue'], 0) ?> this month</div>
                            </div>
                        </div>
                    </div>

                    <!-- ── APPOINTMENTS TREND CHART ── -->
                    <div class="col-xxl-8">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title">Appointments — Last 6 Months</h5>
                                <div class="card-header-action">
                                    <div class="card-header-btn">
                                        <div data-bs-toggle="tooltip" title="Refresh">
                                            <a href="analytics.php" class="avatar-text avatar-xs bg-warning"></a>
                                        </div>
                                        <div data-bs-toggle="tooltip" title="Maximize/Minimize">
                                            <a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div id="apt-trend-chart"></div>
                            </div>
                        </div>
                    </div>

                    <!-- ── TOP SERVICES ── -->
                    <div class="col-xxl-4">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title">Top Services Booked</h5>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($topServices)): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="feather-bar-chart-2 fs-3 mb-2 d-block"></i>
                                        No booking data yet.
                                    </div>
                                <?php else: ?>
                                    <?php $maxSvc = max($topServices); ?>
                                    <?php foreach ($topServices as $svcName => $svcCount): ?>
                                    <div class="px-4 py-3 border-bottom">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="fs-13 fw-semibold text-dark"><?= htmlspecialchars($svcName) ?></span>
                                            <span class="fs-12 text-muted"><?= $svcCount ?> booking<?= $svcCount !== 1 ? 's' : '' ?></span>
                                        </div>
                                        <div class="progress ht-4 rounded">
                                            <div class="progress-bar bg-primary"
                                                style="width:<?= round(($svcCount / $maxSvc) * 100) ?>%"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ── PER-BRANCH BREAKDOWN ── -->
                    <div class="col-xxl-6">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title">Appointments by Branch</h5>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($branchStats)): ?>
                                    <div class="text-center py-5 text-muted">No branch data.</div>
                                <?php else: ?>
                                    <?php foreach ($branchStats as $b): ?>
                                    <div class="px-4 py-3 border-bottom">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="fs-13 fw-semibold text-dark"><?= htmlspecialchars($b['name']) ?></span>
                                            <span class="fs-12 text-muted"><?= $b['count'] ?> appointment<?= $b['count'] !== 1 ? 's' : '' ?></span>
                                        </div>
                                        <div class="progress ht-4 rounded">
                                            <div class="progress-bar bg-success"
                                                style="width:<?= $maxBranch > 0 ? round(($b['count'] / $maxBranch) * 100) : 0 ?>%"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ── INVOICE SUMMARY ── -->
                    <div class="col-xxl-6">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title">Revenue Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-6">
                                        <div class="p-3 border border-dashed rounded text-center">
                                            <div class="fs-12 text-muted mb-1">This Month</div>
                                            <div class="fs-5 fw-bold text-success">₱<?= number_format($stats['month_revenue'], 0) ?></div>
                                            <div class="progress mt-2 ht-3">
                                                <div class="progress-bar bg-success" style="width:70%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-3 border border-dashed rounded text-center">
                                            <div class="fs-12 text-muted mb-1">Total Collected</div>
                                            <div class="fs-5 fw-bold text-primary">₱<?= number_format($stats['total_revenue'], 0) ?></div>
                                            <div class="progress mt-2 ht-3">
                                                <div class="progress-bar bg-primary" style="width:85%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-3 border border-dashed rounded text-center">
                                            <div class="fs-12 text-muted mb-1">Outstanding Balance</div>
                                            <div class="fs-5 fw-bold text-danger">₱<?= number_format($stats['unpaid_balance'], 0) ?></div>
                                            <div class="progress mt-2 ht-3">
                                                <div class="progress-bar bg-danger" style="width:<?= $stats['total_revenue'] > 0 ? min(100, round(($stats['unpaid_balance'] / ($stats['total_revenue'] + $stats['unpaid_balance'])) * 100)) : 0 ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-3 border border-dashed rounded text-center">
                                            <div class="fs-12 text-muted mb-1">Completion Rate</div>
                                            <div class="fs-5 fw-bold text-warning"><?= $stats['total_appointments'] > 0 ? round(($stats['completed_appointments'] / $stats['total_appointments']) * 100) : 0 ?>%</div>
                                            <div class="progress mt-2 ht-3">
                                                <div class="progress-bar bg-warning" style="width:<?= $stats['total_appointments'] > 0 ? round(($stats['completed_appointments'] / $stats['total_appointments']) * 100) : 0 ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <footer class="footer">
            <p class="fs-11 text-muted fw-medium text-uppercase mb-0 copyright">
                <span>Copyright © <?= date('Y') ?> EssenciaSmile</span>
            </p>
        </footer>
    </main>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/vendors/js/apexcharts.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script>
    // ── Appointments trend chart ──────────────────────────────
    const aptChart = new ApexCharts(document.querySelector('#apt-trend-chart'), {
        chart:  { type: 'bar', height: 280, toolbar: { show: false }, fontFamily: 'inherit' },
        colors: ['#3454d1', '#28a745'],
        series: [
            { name: 'Total',     data: <?= $chartTotal ?> },
            { name: 'Completed', data: <?= $chartCompleted ?> },
        ],
        xaxis: { categories: <?= $chartLabels ?> },
        yaxis: { labels: { formatter: v => Math.round(v) } },
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 4 } },
        dataLabels: { enabled: false },
        legend: { position: 'top' },
        grid: { borderColor: '#f0f0f0' },
    });
    aptChart.render();
    </script>
</body>
</html>