<?php

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
// =============================================================
//  analytics.php — Analytics dashboard
//
//  Dashboard = what is happening today. Analytics = how the clinics did
//  over time, how they compare, and what the forecast expects.
//  Tabs: Overview · Appointments · Revenue · Inventory · Forecast
//
//  Data (both read-only, both loaded by JS after the page paints):
//    • api/dashboard_stats.php  — the Dashboard's own endpoint (monthly
//      trends, statuses, billing, stock, top services).
//      assets/js/analytics-history.js renders Overview / Appointments /
//      Revenue / Inventory from it.
//    • api/forecast_data.php    — stored forecast runs (Python job).
//      assets/js/forecast-charts.js renders the Forecast tab and shares
//      read-only summaries with the other tabs (window.ESForecast).
//  Both endpoints enforce branch scope themselves (owner: every branch,
//  admin / dentist: own branch only); this page only mirrors it in the UI.
//  Typography: the theme's Inter only (same as dashboard.php).
// =============================================================
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';

$scope = scopedBranch(0);   // 0 = owner (every branch), > 0 = own branch
admin_prefetch([], ['navbar', 'branches']);
admin_release_session();

// ?fsrc=simulated opens the clearly-labelled demo forecast; default is live data.
$fcSource   = ($_GET['fsrc'] ?? '') === 'simulated' ? 'simulated' : 'live';
$fcOwner    = isOwner();
$fcBranches = [];
foreach (getAllBranches() as $b) {   // came with the prefetch wave above
    if ($scope === 0 || (int)$b['branch_id'] === $scope) $fcBranches[(int)$b['branch_id']] = $b['branch_name'];
}
ksort($fcBranches);
$anMulti = $fcOwner && count($fcBranches) > 1;
$anConfig = [
    'owner'     => $fcOwner,
    'multi'     => $anMulti,
    'branches'  => (object)$fcBranches,        // id => name, in branch_id order (fixes each clinic's colour)
    'scopeName' => $anMulti ? null : (implode(', ', $fcBranches) ?: null),
];
$tabs = [
    'overview'     => ['feather-grid', 'Overview'],
    'appointments' => ['feather-calendar', 'Appointments'],
    'revenue'      => ['feather-credit-card', 'Revenue'],
    'inventory'    => ['feather-package', 'Inventory'],
    'forecast'     => ['feather-trending-up', 'Forecast'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Analytics</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/es-admin.css?v=7">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
    <link rel="stylesheet" href="assets/css/analytics.css?v=8">
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
                        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end page-header-right-items-wrapper">
                            <?php if ($anMulti): ?>
                            <!-- One clinic filter for every tab (history + forecast) -->
                            <select id="fcBranch" class="js-custom-select dash-branch" data-cs-filter aria-label="Clinic">
                                <option value="all">All clinics</option>
                                <?php foreach ($fcBranches as $bid => $bname): ?>
                                <option value="<?= (int)$bid ?>"><?= htmlspecialchars($bname) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <span class="dash-where"><i class="feather-map-pin" aria-hidden="true"></i><span class="dash-where-branch"><?= htmlspecialchars(implode(', ', $fcBranches) ?: '—') ?></span></span>
                            <?php endif; ?>
                            <button type="button" class="dash-refresh" id="anRefresh" title="Reload the figures"><i class="feather-refresh-cw"></i><span>Refresh</span></button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-content an-page">

                <!-- Section tabs + what the figures compare -->
                <div class="an-bar">
                    <div class="an-tabs" role="tablist" aria-label="Analytics sections">
                        <?php foreach ($tabs as $key => [$icon, $label]): ?>
                        <button type="button" class="an-tab" role="tab" id="anTab-<?= $key ?>" aria-controls="<?= $key === 'forecast' ? 'forecast' : 'anPane-' . $key ?>" data-an-tab="<?= $key ?>"><i class="<?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="an-context" id="anContext" aria-live="polite"></div>
                </div>

                <?php foreach (['overview', 'appointments', 'revenue', 'inventory'] as $key): ?>
                <div id="anPane-<?= $key ?>" class="an-pane" role="tabpanel" aria-labelledby="anTab-<?= $key ?>" hidden></div>
                <?php endforeach; ?>

                <!-- Forecast: rendered by assets/js/forecast-charts.js from api/forecast_data.php -->
                <div id="forecast" class="an-pane" role="tabpanel" aria-labelledby="anTab-forecast" hidden>
                    <div class="card an-toolbar-card">
                        <div class="card-body an-toolbar">
                            <select id="fcMetric" class="js-custom-select" data-cs-filter aria-label="What to forecast">
                                <option value="appointments">Appointments</option>
                                <option value="sales">Revenue</option>
                                <option value="inventory">Inventory</option>
                            </select>
                            <select id="fcPeriod" class="js-custom-select" data-cs-filter aria-label="Forecast period">
                                <?php foreach ([7, 14] as $p): /* replaced by the run's horizon once loaded */ ?>
                                <option value="<?= $p ?>"<?= $p === 14 ? ' selected' : '' ?>>Next <?= $p ?> days</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="an-seg" role="group" aria-label="Data source">
                                <button type="button" data-src="live" aria-pressed="<?= $fcSource === 'live' ? 'true' : 'false' ?>">Clinic records</button>
                                <button type="button" data-src="simulated" aria-pressed="<?= $fcSource === 'simulated' ? 'true' : 'false' ?>">Demo data</button>
                            </div>
                            <span class="an-status" id="fcStatus"></span>
                            <?php if ($fcOwner): ?>
                            <button type="button" class="dash-refresh is-primary" id="fcRunBtn" data-source="<?= $fcSource ?>"
                                    title="Retrain and re-check every model on the recorded history"><i class="feather-play"></i><span>Run forecast</span></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div id="fcNotice"></div>
                    <div id="fcApp"></div>
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
    <!-- SweetAlert2 v11 (self-hosted, same build as every other admin page): replaces the
         v9 bundled in vendors.min.js, whose result has no isConfirmed (Sign Out never ran) -->
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/es-swal.js?v=3"></script>
    <script src="assets/vendors/js/apexcharts.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/es-ajax.js?v=7"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/js/custom-select.js?v=3"></script>
    <script>
        window.ES_FORECAST_SOURCE = <?= json_encode($fcSource) ?>;
        window.ES_ANALYTICS = <?= json_encode($anConfig, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    </script>
    <script src="assets/js/forecast-charts.js?v=17"></script>
    <script src="assets/js/analytics-history.js?v=5"></script>
</body>
</html>
