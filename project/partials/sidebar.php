<?php

require_once __DIR__ . '/../controllers/secure_controller.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
$currentUser = getCurrentUser();
$role = $currentUser['role'] ?? '';
$isOwner   = $role === 'owner';
$isAdmin   = $role === 'admin';
$isDentist = $role === 'dentist';
?>
<script>
        // ── Back-navigation / bfcache guard ──────────────────────────────
        // When a user logs out and presses Back, some browsers restore the
        // previous page from the bfcache (back-forward cache) without making
        // a new server request. Our Cache-Control: no-store headers stop this
        // in most cases, but the pageshow event is the JS safety net.
        //
        // e.persisted = true  →  page loaded from bfcache (no server request)
        //   → force a full reload so PHP's session check runs
        //   → if the session is gone, PHP redirects to login.php
        //
        // We also listen for 'focus' in case the user switches browser tabs
        // and returns to a stale protected page — again forces a reload.
        (function () {
            window.addEventListener('pageshow', function (e) {
                if (e.persisted) {
                    // bfcache hit — PHP never ran — force a fresh server request
                    window.location.reload();
                }
            });

            // Secondary guard: when the tab regains focus, verify the session
            // is still alive via a lightweight API ping.
            // Replace 'api/check_session.php' if you keep your API elsewhere.
            window.addEventListener('focus', function () {
                fetch('api/check_session.php', { method: 'POST', credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(d => { if (!d.valid) window.location.href = 'login.php'; })
                    .catch(() => { /* network error — don't boot the user */ });
            });
        })();
    </script>
<nav class="nxl-navigation">
    <div class="navbar-wrapper">
        <div class="m-header">
            <a href="<?= $isOwner ? 'dashboard.php' : 'appointments-schedule.php' ?>" class="b-brand">
                <img src="assets/images/essencia-full@3x.png" alt="" class="logo logo-lg">
                <img src="assets/images/Essencia-abbr.png" alt="" class="logo logo-sm">
            </a>
        </div>
        <div class="navbar-content">
            <ul class="nxl-navbar">

                <?php if ($isOwner): ?>
                    <li class="nxl-item nxl-caption">
                        <label>Overview</label>
                    </li>
                    <li class="nxl-item nxl-hasmenu">
                        <a href="javascript:void(0);" class="nxl-link">
                            <span class="nxl-micon"><i class="feather-airplay"></i></span>
                            <span class="nxl-mtext">Dashboards</span>
                            <span class="nxl-arrow"><i class="feather-chevron-right"></i></span>
                        </a>
                        <ul class="nxl-submenu">
                            <li class="nxl-item"><a class="nxl-link" href="dashboard.php">Overview</a></li>
                            <li class="nxl-item"><a class="nxl-link" href="analytics.php">Analytics</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <li class="nxl-item nxl-caption">
                    <label>Operations</label>
                </li>

                <li class="nxl-item nxl-hasmenu">
                    <a href="javascript:void(0);" class="nxl-link">
                        <span class="nxl-micon"><i class="feather-calendar"></i></span>
                        <span class="nxl-mtext">Appointments</span>
                        <span class="nxl-arrow"><i class="feather-chevron-right"></i></span>
                    </a>
                    <ul class="nxl-submenu">
                        <li class="nxl-item"><a class="nxl-link" href="appointments-records.php">Appointments Records</a></li>
                        <li class="nxl-item"><a class="nxl-link" href="appointments-schedule.php">Appointments Schedule</a></li>
                    </ul>
                </li>

                <li class="nxl-item nxl-hasmenu">
                    <a href="javascript:void(0);" class="nxl-link">
                        <span class="nxl-micon"><i class="feather-activity"></i></span>
                        <span class="nxl-mtext">Treatments</span>
                        <span class="nxl-arrow"><i class="feather-chevron-right"></i></span>
                    </a>
                    <ul class="nxl-submenu">
                        <li class="nxl-item"><a class="nxl-link" href="treatments-records.php">Treatment Records</a></li>
                        <li class="nxl-item"><a class="nxl-link" href="treatments-active.php">Active Treatments</a></li>
                    </ul>
                </li>

                <li class="nxl-item nxl-hasmenu">
                    <a href="javascript:void(0);" class="nxl-link">
                        <span class="nxl-micon"><i class="feather-clipboard"></i></span>
                        <span class="nxl-mtext">Patients</span>
                        <span class="nxl-arrow"><i class="feather-chevron-right"></i></span>
                    </a>
                    <ul class="nxl-submenu">
                        <!--
                            FIX: Was "patients-records.php" (the DETAIL page — requires ?id=N).
                            Changed to "patients-list.php" (the LIST page — no ID needed).
                            The detail page is only reached by clicking a patient row in the list.
                        -->
                        <li class="nxl-item"><a class="nxl-link" href="patients-list.php">Patients List</a></li>
                    </ul>
                </li>

                <?php if ($isOwner || $isAdmin): ?>
                    <li class="nxl-item nxl-caption">
                        <label>Financial</label>
                    </li>
                    <li class="nxl-item nxl-hasmenu">
                        <a href="javascript:void(0);" class="nxl-link">
                            <span class="nxl-micon"><i class="feather-file-text"></i></span>
                            <span class="nxl-mtext">Billing</span>
                            <span class="nxl-arrow"><i class="feather-chevron-right"></i></span>
                        </a>
                        <ul class="nxl-submenu">
                            <li class="nxl-item"><a class="nxl-link" href="billing-records.php">Billing Records</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <li class="nxl-item nxl-caption">
                    <label>Inventory</label>
                </li>

                <li class="nxl-item nxl-hasmenu">
                    <a href="javascript:void(0);" class="nxl-link">
                        <span class="nxl-micon"><i class="feather-package"></i></span>
                        <span class="nxl-mtext">Inventory</span>
                        <span class="nxl-arrow"><i class="feather-chevron-right"></i></span>
                    </a>
                    <ul class="nxl-submenu">
                        <li class="nxl-item"><a class="nxl-link" href="inventory-records.php">Inventory Records</a></li>
                        <li class="nxl-item"><a class="nxl-link" href="inventory-stock-movement.php">Stock Movement</a></li>
                    </ul>
                </li>

                <?php if ($isOwner || $isAdmin): ?>
                    <li class="nxl-item nxl-hasmenu">
                        <a href="javascript:void(0);" class="nxl-link">
                            <span class="nxl-micon"><i class="feather-tool"></i></span>
                            <span class="nxl-mtext">Materials Configuration</span>
                            <span class="nxl-arrow"><i class="feather-chevron-right"></i></span>
                        </a>
                        <ul class="nxl-submenu">
                            <li class="nxl-item"><a class="nxl-link" href="service-materials.php">Service Materials</a></li>
                            <li class="nxl-item"><a class="nxl-link" href="service-pricing.php">Service Pricing</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if ($isOwner || $isAdmin): ?>
                    <li class="nxl-item nxl-caption">
                        <label>Management</label>
                    </li>

                    <li class="nxl-item nxl-hasmenu">
                        <a href="javascript:void(0);" class="nxl-link">
                            <span class="nxl-micon"><i class="feather-user-check"></i></span>
                            <span class="nxl-mtext">Dentists</span>
                            <span class="nxl-arrow"><i class="feather-chevron-right"></i></span>
                        </a>
                        <ul class="nxl-submenu">
                            <li class="nxl-item"><a class="nxl-link" href="dentist-records.php">Dentist Records</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if ($isOwner): ?>
                    <li class="nxl-item nxl-hasmenu">
                        <a href="javascript:void(0);" class="nxl-link">
                            <span class="nxl-micon"><i class="feather-server"></i></span>
                            <span class="nxl-mtext">Branches</span>
                            <span class="nxl-arrow"><i class="feather-chevron-right"></i></span>
                        </a>
                        <ul class="nxl-submenu">
                            <li class="nxl-item"><a class="nxl-link" href="branches-records.php">Branch Records</a></li>
                        </ul>
                    </li>

                    <li class="nxl-item nxl-hasmenu">
                        <a href="javascript:void(0);" class="nxl-link">
                            <span class="nxl-micon"><i class="feather-users"></i></span>
                            <span class="nxl-mtext">Accounts</span>
                            <span class="nxl-arrow"><i class="feather-chevron-right"></i></span>
                        </a>
                        <ul class="nxl-submenu">
                            <li class="nxl-item"><a class="nxl-link" href="accounts-records.php">Accounts Records</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <li class="nxl-item nxl-caption">
                    <label>System</label>
                </li>

                <li class="nxl-item nxl-hasmenu">
                    <a href="javascript:void(0);" class="nxl-link">
                        <span class="nxl-micon"><i class="feather-shield"></i></span>
                        <span class="nxl-mtext">Audit Trail</span>
                        <span class="nxl-arrow"><i class="feather-chevron-right"></i></span>
                    </a>
                    <ul class="nxl-submenu">
                        <li class="nxl-item"><a class="nxl-link" href="audit-records.php">Audit Records</a></li>
                    </ul>
                </li>

                <li class="nxl-item">
                    <a href="logout.php" class="nxl-link">
                        <span class="nxl-micon"><i class="feather-log-out"></i></span>
                        <span class="nxl-mtext">Logout</span>
                    </a>
                </li>

            </ul>
        </div>
    </div>
</nav>