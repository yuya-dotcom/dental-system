<?php
// =============================================================
//  dashboard.php — Admin Dashboard
//  Every figure is live: the page renders its layout, then loads the
//  numbers from api/dashboard_stats.php (controllers/dashboard_overview.php)
//  with loading, empty and error states per card (assets/js/dashboard.js).
//  Scope: owner — all branches or one (switcher); admin / dentist — own branch.
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
require_once __DIR__ . '/controllers/branch_controller.php';
admin_release_session();   // read-only page: don't hold the session lock while querying

admin_prefetch([], isOwner() ? ['navbar', 'branches'] : ['navbar']);   // navbar bell (+ branch list for the owner's switcher)
$branches   = isOwner() ? getAllBranches() : [];
$initBranch = isOwner() ? max(0, (int)($_GET['branch'] ?? 0)) : 0;
// A branch dashboard link for a branch that doesn't exist → the consolidated one
if ($initBranch && !in_array($initBranch, array_map(fn($b) => (int)$b['branch_id'], $branches), true)) $initBranch = 0;
$initBranchName = 'All Branches';
foreach ($branches as $b) if ((int)$b['branch_id'] === $initBranch) $initBranchName = (string)$b['branch_name'];
// Header: "Dashboard | Home > Dashboard    <branch> – Updated 8:21 am  [Refresh]".
// The branch shown here is a first paint only: assets/js/dashboard.js replaces
// it with the branch the SERVER scoped the figures to (api/dashboard_stats.php).
$dashBranchLabel = isOwner()
    ? $initBranchName
    : (trim((string)($_SESSION['branch_name'] ?? '')) !== '' ? trim((string)$_SESSION['branch_name']) : 'My Branch');
$canBilling = !isDentist();
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
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <!-- Same design language as analytics.php (cards, KPI tiles, pills, colours) -->
    <link rel="stylesheet" href="assets/css/analytics.css?v=8">
    <style>
        /* Dashboard chart series: recorded total, completed, cancelled (the Analytics colours) */
        :root { --dash-c1: var(--an-hist); --dash-c2: var(--an-c2); --dash-c3: var(--an-cancel); }

        /* Clickable status rows (Appointment Overview) */
        .summary-link { display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.7rem 1.25rem; text-decoration:none; color:inherit;
            border-bottom:1px solid var(--an-line-soft); transition:background-color .15s ease; }
        .summary-link:hover { background:var(--an-tint); color:inherit; text-decoration:none; }
        .summary-link .t { font-size:13px; font-weight:600; color:var(--an-ink); line-height:1.3; }
        .summary-link .d { font-size:12px; color:var(--an-muted); }
        .summary-link .n { font-size:16px; font-weight:700; color:var(--an-ink); font-variant-numeric:tabular-nums; }
        .summary-arrow { opacity:0; transition:opacity .15s ease, transform .15s ease; color:var(--an-muted); font-size:.75rem; margin-left:.35rem; flex-shrink:0; }
        .summary-link:hover .summary-arrow { opacity:1; transform:translateX(3px); }

        /* Charts + states (filled by assets/js/dashboard.js) */
        .dash-chart { min-height:300px; position:relative; }
        .dash-chart-state { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; color:var(--an-muted); font-size:.84rem; text-align:center; padding:16px; }
        .dash-error { color:var(--an-bad); font-size:.8125rem; display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .dash-updated { font-size:.75rem; color:var(--an-muted); }
        .dash-updated:empty { display:none; }
        .dash-refresh.es-btn-busy i { animation:an-rot 1s linear infinite; }

        /* KPI tiles: the icon chip is the link to the matching page */
        a.an-ico { transition:transform .15s ease, filter .15s ease; }
        a.an-ico:hover { transform:translateY(-1px); filter:brightness(.96); }
        .an-kpi-foot { font-size:11.5px; color:var(--an-muted); margin-top:.55rem; line-height:1.45; }
        .an-kpi .progress-bar { transition:width .4s ease; }

        /* Stock alert rows + bar lists */
        .dash-alert-row { display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.65rem 1.25rem; border-bottom:1px solid var(--an-line-soft);
            text-decoration:none; color:inherit; font-size:12.5px; line-height:1.4; }
        .dash-alert-row:hover { background:var(--an-tint); color:inherit; }
        .dash-alert-row:last-child { border-bottom:0; }
        .dash-alert-row .who { display:flex; align-items:center; gap:.7rem; min-width:0; }
        a.dash-kv:hover { background:var(--an-tint); color:inherit; }
        .dash-sec { font-size:13px; font-weight:700; color:var(--an-ink); padding:1rem 1.25rem .35rem; }

        /* Patients & treatments: six stat tiles with hairline separators */
        .dash-stats { display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:1px; background:var(--an-line-soft); }
        .dash-stat { display:flex; align-items:center; gap:.85rem; padding:1.15rem 1.25rem; background:var(--an-surface); min-width:0; }
        .dash-stat .v { font-size:20px; font-weight:700; color:var(--an-ink); line-height:1.2; font-variant-numeric:tabular-nums; }
        .dash-stat .l { font-size:12px; color:var(--an-muted); }
        @media (max-width:1399.98px) { .dash-stats { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
        @media (max-width:575.98px) { .dash-stats { grid-template-columns:repeat(2, minmax(0, 1fr)); } .dash-stat { padding:1rem; } .dash-chart { min-height:250px; } }
        @media (prefers-reduced-motion: reduce) { .summary-arrow, a.an-ico, .an-kpi .progress-bar { transition:none; } }
    </style>
    <link rel="stylesheet" href="assets/css/es-admin.css?v=8">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
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
                        <li class="breadcrumb-item">Dashboard</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <span class="dash-where">
                                <i class="feather-map-pin" aria-hidden="true"></i>
                                <span class="dash-where-branch" id="dashBranchName"><?= htmlspecialchars($dashBranchLabel) ?></span>
                                <span class="dash-updated" id="dashUpdated" aria-live="polite"></span>
                            </span>
                            <?php if (isOwner()): ?>
                            <select id="dashBranch" class="js-custom-select dash-branch" data-cs-filter aria-label="Branch">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= (int)$b['branch_id'] ?>" <?= (int)$b['branch_id'] === $initBranch ? 'selected' : '' ?>><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                            <button type="button" class="dash-refresh" id="dashRefresh" title="Reload the figures"><i class="feather-refresh-cw"></i><span>Refresh</span></button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-content an-page">
                <div id="dashGlobalError" class="alert alert-danger d-none" role="alert"></div>

                <!-- Today, and where the figures come from -->
                <div class="an-bar">
                    <div class="an-context"><i class="feather-sun" aria-hidden="true"></i><span>Today is <b data-v="meta.today">…</b>, showing <b data-v="meta.scope"><?= htmlspecialchars($dashBranchLabel) ?></b></span></div>
                    <?php if (isOwner()): ?><a class="an-link" href="analytics.php">Trends and forecasts in Analytics <i class="feather-chevron-right"></i></a><?php endif; ?>
                </div>

                <!-- ── Headline cards ─────────────────────────── -->
                <div class="row">
                    <?php
                    $cards = [
                        ['today',    'feather-calendar',       't-blue',   "Today's Appointments", 'appointments-schedule.php', 'Upcoming (pending / confirmed)', '--an-hist'],
                        ['patients', 'feather-users',          't-teal',   'Total Patients',       'patients-list.php',         'New this month',                 '--an-c2'],
                        ['revenue',  'feather-credit-card',    't-violet', 'Revenue This Month',   $canBilling ? 'billing-records.php' : '', 'Collected this week', '--an-c3'],
                        ['stock',    'feather-alert-triangle', 't-amber',  'Inventory Alerts',     'inventory-records.php',     'Low / out of stock items',       '--an-warn'],
                    ];
                    foreach ($cards as [$key, $icon, $tone, $label, $href, $note, $bar]): ?>
                    <div class="col-xxl-3 col-md-6">
                        <div class="card stretch stretch-full an-kpi">
                            <div class="card-body">
                                <div class="an-kpi-top">
                                    <h3 class="an-kpi-label mb-0"><?= $label ?></h3>
                                    <?php if ($href): ?>
                                    <a href="<?= $href ?>" class="an-ico <?= $tone ?>" aria-label="Open <?= htmlspecialchars($label) ?>" title="Open <?= htmlspecialchars($label) ?>"><i class="<?= $icon ?>"></i></a>
                                    <?php else: ?>
                                    <span class="an-ico <?= $tone ?>"><i class="<?= $icon ?>"></i></span>
                                    <?php endif; ?>
                                </div>
                                <div class="an-kpi-value" data-v="<?= $key ?>.main"><span class="dash-skel"></span></div>
                                <div class="an-kpi-sub"><span><?= $note ?></span><span class="an-pill info" data-v="<?= $key ?>.sub"><span class="dash-skel"></span></span></div>
                                <div class="progress ht-3"><div class="progress-bar" data-v="<?= $key ?>.bar" style="width:0%;background:var(<?= $bar ?>)"></div></div>
                                <div class="an-kpi-foot" data-v="<?= $key ?>.foot">&nbsp;</div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- ── Appointment trend + overview ───────────── -->
                <div class="row">
                    <div class="col-xxl-8">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title">Appointment Trends <small>last 6 months</small></h5>
                                <div class="card-header-action">
                                    <div class="dash-legend">
                                        <span><i style="background:var(--dash-c1)"></i>Total</span>
                                        <span><i style="background:var(--dash-c2)"></i>Completed</span>
                                        <span><i style="background:var(--dash-c3)"></i>Cancelled</span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body pb-2">
                                <div class="dash-chart" id="chartAppointments" role="img" aria-label="Appointments per month: total, completed and cancelled">
                                    <div class="sk-chart" aria-hidden="true"><?php foreach ([46, 62, 38, 74, 58, 84] as $skH): ?><span class="sk" style="height:<?= $skH ?>%"></span><?php endforeach; ?></div>
                                </div>
                            </div>
                            <details class="dash-table"><summary>Show as table</summary><div class="table-responsive px-3 pb-3" id="tableAppointments"></div></details>
                        </div>
                    </div>
                    <div class="col-xxl-4">
                        <div class="card stretch stretch-full">
                            <div class="card-header"><h5 class="card-title">Appointment Overview <small>all time</small></h5></div>
                            <div class="card-body p-0">
                                <div class="px-4 pt-3 pb-3 border-bottom">
                                    <div class="an-prop" data-v="apt.prop" role="img" aria-label="Appointments by status"></div>
                                </div>
                                <?php foreach ([
                                    ['total',     'All Appointments', 'All time',               'feather-calendar',     't-blue',   'appointments-records.php'],
                                    ['pending',   'Pending',          'Awaiting confirmation',  'feather-clock',        't-amber',  'appointments-records.php?status=pending'],
                                    ['confirmed', 'Confirmed',        'Scheduled & confirmed',  'feather-check',        't-violet', 'appointments-records.php?status=confirmed'],
                                    ['completed', 'Completed',        'Treatment finished',     'feather-check-circle', 't-teal',   'appointments-records.php?status=completed'],
                                    ['cancelled', 'Cancelled',        'Cancelled appointments', 'feather-x-circle',     't-red',    'appointments-records.php?status=cancelled'],
                                ] as [$k, $t, $d, $ic, $tone, $href]): ?>
                                <a href="<?= $href ?>" class="summary-link">
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="an-ico sm <?= $tone ?>"><i class="<?= $ic ?>"></i></span>
                                        <div><div class="t"><?= $t ?></div><div class="d"><?= $d ?></div></div>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <span class="n" data-v="apt.<?= $k ?>"><span class="dash-skel"></span></span>
                                        <i class="feather-chevron-right summary-arrow"></i>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                                <div class="px-4 py-3">
                                    <div class="d-flex justify-content-between fs-12"><span class="text-muted">Completion rate (completed ÷ all)</span><span class="fw-semibold text-dark" data-v="apt.rate">—</span></div>
                                    <div class="progress mt-2 ht-3"><div class="progress-bar" data-v="apt.ratebar" style="width:0%;background:var(--an-c2)"></div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Top services + appointments by branch ──── -->
                <div class="row">
                    <div class="<?= isOwner() ? 'col-xxl-6' : 'col-12' ?>">
                        <div class="card stretch stretch-full">
                            <div class="card-header"><h5 class="card-title">Top Services Booked <small>all time</small></h5></div>
                            <div class="card-body p-0" id="topServices"><div class="sk-list" aria-hidden="true"><?php for ($skI = 0; $skI < 5; $skI++): ?><div><div class="sk-who"><span class="sk c" style="width:32px;height:32px"></span><div style="display:flex;flex-direction:column;gap:8px"><span class="sk" style="width:<?= [62, 48, 70, 55, 40][$skI] ?>%"></span><span class="sk" style="width:<?= [34, 28, 40, 30, 25][$skI] ?>%;height:10px"></span></div></div><span class="sk" style="width:60px;height:14px"></span></div><?php endfor; ?></div></div>
                        </div>
                    </div>
                    <?php if (isOwner()): ?>
                    <div class="col-xxl-6">
                        <div class="card stretch stretch-full">
                            <div class="card-header"><h5 class="card-title">Appointments by Branch <small>all time</small></h5></div>
                            <div class="card-body p-0" id="byBranch"><div class="sk-list" aria-hidden="true"><?php for ($skI = 0; $skI < 5; $skI++): ?><div><div class="sk-who"><span class="sk c" style="width:32px;height:32px"></span><div style="display:flex;flex-direction:column;gap:8px"><span class="sk" style="width:<?= [62, 48, 70, 55, 40][$skI] ?>%"></span><span class="sk" style="width:<?= [34, 28, 40, 30, 25][$skI] ?>%;height:10px"></span></div></div><span class="sk" style="width:60px;height:14px"></span></div><?php endfor; ?></div></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ── Revenue trend + sales ──────────────────── -->
                <div class="row">
                    <div class="col-xxl-8">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title">Revenue Trend <small>payments received, last 6 months</small></h5>
                                <div class="card-header-action"><div class="dash-legend"><span><i style="width:16px;height:0;border-radius:0;border-top:2.5px solid var(--an-hist)"></i>Payments received</span></div></div>
                            </div>
                            <div class="card-body pb-2">
                                <div class="dash-chart" id="chartRevenue" role="img" aria-label="Payments received per month">
                                    <div class="sk-chart" aria-hidden="true"><?php foreach ([46, 62, 38, 74, 58, 84] as $skH): ?><span class="sk" style="height:<?= $skH ?>%"></span><?php endforeach; ?></div>
                                </div>
                            </div>
                            <details class="dash-table"><summary>Show as table</summary><div class="table-responsive px-3 pb-3" id="tableRevenue"></div></details>
                        </div>
                    </div>
                    <div class="col-xxl-4">
                        <div class="card stretch stretch-full">
                            <div class="card-header"><h5 class="card-title">Sales &amp; Revenue</h5></div>
                            <div class="card-body p-0">
                                <div class="dash-kv"><span class="text-muted">Collected today</span><span class="v" data-v="rev.today"><span class="dash-skel"></span></span></div>
                                <div class="dash-kv"><span class="text-muted">Collected this week <span class="fs-11" data-v="meta.week"></span></span><span class="v" data-v="rev.week"><span class="dash-skel"></span></span></div>
                                <div class="dash-kv"><span class="text-muted">Collected this month</span><span class="v" data-v="rev.month"><span class="dash-skel"></span></span></div>
                                <div class="dash-kv"><span class="text-muted">Payments recorded this month</span><span class="v" data-v="rev.count"><span class="dash-skel"></span></span></div>
                                <div class="dash-kv"><span class="text-muted">Total sales (billed, all time)</span><span class="v" data-v="sales.billed"><span class="dash-skel"></span></span></div>
                                <div class="dash-kv"><span class="text-muted">Total collected (all time)</span><span class="v" data-v="sales.collected"><span class="dash-skel"></span></span></div>
                                <?php if ($canBilling): ?>
                                <a href="billing-records.php?payment=unpaid" class="dash-kv text-decoration-none"><span class="text-muted">Outstanding balance</span><span class="v text-danger" data-v="sales.outstanding"><span class="dash-skel"></span></span></a>
                                <?php else: ?>
                                <div class="dash-kv"><span class="text-muted">Outstanding balance</span><span class="v text-danger" data-v="sales.outstanding"><span class="dash-skel"></span></span></div>
                                <?php endif; ?>
                                <div class="dash-kv"><span class="text-muted">Invoices: paid / partial / unpaid</span><span class="v" data-v="sales.split"><span class="dash-skel"></span></span></div>
                                <div class="px-4 py-3">
                                    <div class="d-flex justify-content-between fs-12"><span class="text-muted">Collected of billed (all time)</span><span class="fw-semibold text-dark" data-v="sales.rate">—</span></div>
                                    <div class="progress mt-2 ht-3"><div class="progress-bar" data-v="sales.ratebar" style="width:0%;background:var(--an-good)"></div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Today's schedule + inventory ───────────── -->
                <div class="row">
                    <div class="col-xxl-8">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title">Today's Schedule <span class="an-pill info ms-2" data-v="todaybadge">…</span></h5>
                                <div class="card-header-action"><a href="appointments-schedule.php" class="an-link">View all <i class="feather-chevron-right"></i></a></div>
                            </div>
                            <div class="card-body custom-card-action p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0 an-table">
                                        <thead><tr><th>Code</th><th>Time</th><th>Patient</th><th>Service</th><th>Branch</th><th>Status</th></tr></thead>
                                        <tbody id="todayBody"><?php for ($skI = 0; $skI < 4; $skI++): ?><tr aria-hidden="true"><?php foreach ([60, 50, 75, 65, 55] as $skW): ?><td><span class="sk" style="width:<?= $skW ?>%"></span></td><?php endforeach; ?><td><span class="sk pill" style="width:78px;height:22px"></span></td></tr><?php endfor; ?></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-4">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title">Inventory Overview</h5>
                                <div class="card-header-action"><a href="inventory-records.php" class="an-link">Inventory <i class="feather-chevron-right"></i></a></div>
                            </div>
                            <div class="card-body p-0">
                                <div class="px-4 pt-3 pb-3 border-bottom">
                                    <div class="an-prop" data-v="inv.prop" role="img" aria-label="Items by stock level"></div>
                                </div>
                                <div class="dash-kv"><span class="text-muted"><span class="an-key me-2" style="background:var(--an-good)"></span>Total items (active)</span><span class="v" data-v="inv.total"><span class="dash-skel"></span></span></div>
                                <a href="inventory-records.php?status=low_stock" class="dash-kv text-decoration-none"><span class="text-muted"><span class="an-key me-2" style="background:var(--an-warn)"></span>Low stock</span><span class="v text-warning" data-v="inv.low"><span class="dash-skel"></span></span></a>
                                <a href="inventory-records.php?status=out_of_stock" class="dash-kv text-decoration-none"><span class="text-muted"><span class="an-key me-2" style="background:var(--an-bad)"></span>Out of stock</span><span class="v text-danger" data-v="inv.out"><span class="dash-skel"></span></span></a>
                                <div class="dash-sec border-top">Stock alerts</div>
                                <div id="stockAlerts"><div class="sk-list" aria-hidden="true"><?php for ($skI = 0; $skI < 3; $skI++): ?><div><div class="sk-who"><span class="sk c" style="width:32px;height:32px"></span><div style="display:flex;flex-direction:column;gap:8px"><span class="sk" style="width:<?= [62, 48, 70, 55, 40][$skI] ?>%"></span><span class="sk" style="width:<?= [34, 28, 40, 30, 25][$skI] ?>%;height:10px"></span></div></div><span class="sk" style="width:64px;height:14px"></span></div><?php endfor; ?></div></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Patients & treatments ──────────────────── -->
                <div class="row">
                    <div class="col-12">
                        <div class="card stretch stretch-full overflow-hidden">
                            <div class="card-header"><h5 class="card-title">Patients &amp; Treatments</h5></div>
                            <div class="dash-stats">
                                <?php foreach ([
                                    ['pat.total', 'Registered patients', 'feather-users', 't-blue'],
                                    ['pat.month', 'New this month', 'feather-user-plus', 't-teal'],
                                    ['pat.week', 'New this week', 'feather-user-plus', 't-teal'],
                                    ['pat.today', 'New today', 'feather-user-check', 't-teal'],
                                    ['trt.active', 'Active treatments', 'feather-activity', 't-violet'],
                                    ['trt.completed', 'Completed treatments', 'feather-check-circle', 't-green'],
                                ] as [$k, $l, $ic, $tone]): ?>
                                <div class="dash-stat">
                                    <span class="an-ico <?= $tone ?>"><i class="<?= $ic ?>"></i></span>
                                    <div class="min-w-0"><div class="v" data-v="<?= $k ?>"><span class="dash-skel"></span></div><div class="l"><?= $l ?></div></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <footer class="footer">
            <p class="fs-11 text-muted fw-medium text-uppercase mb-0 copyright"><span>Copyright © <?= date('Y') ?> EssenciaSmile</span></p>
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
    <script>window.ES_DASH = <?= json_encode(['branches' => (object)array_column(array_map(fn($b) => ['id' => (int)$b['branch_id'], 'name' => (string)$b['branch_name']], $branches), 'name', 'id')], JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
    <script src="assets/js/dashboard.js?v=7"></script>
</body>
</html>
