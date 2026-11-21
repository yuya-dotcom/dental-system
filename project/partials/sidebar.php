<?php

require_once __DIR__ . '/../controllers/secure_controller.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
$currentUser = getCurrentUser();
$role = $currentUser['role'] ?? '';
$isOwner   = $role === 'owner';
$isAdmin   = $role === 'admin';
$isDentist = $role === 'dentist';

// ── Role-based navigation ─────────────────────────────────────────
// Each link lists the roles that may use it; keep these in step with the
// page's own REQUIRED_ROLES guard (the server-side check is what actually
// protects the page — hiding a link is only a convenience).
// Groups / captions with no visible links are not rendered.
// Dashboard. The data is scoped on the SERVER from the session
// (api/dashboard_stats.php → scopedBranch): admins / dentists always get their
// own branch, the owner all branches (or one, via the page's selector).
//  - Owner: a "Dashboard" dropdown → Overview (dashboard.php) + Analytics.
//  - Admin / dentist: a direct "Dashboard" link to their branch dashboard.
$navSections = [
    'Overview' => [
        ['icon' => 'feather-airplay', 'label' => 'Dashboard', 'links' => [
            ['dashboard.php', 'Overview',  ['owner']],
            // Predictive analytics (forecast only; the descriptive figures are on the Dashboard)
            ['analytics.php', 'Analytics', ['owner']],
        ]],
        ['icon' => 'feather-airplay', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'roles' => ['admin', 'dentist']],
    ],
    'Operations' => [
        ['icon' => 'feather-calendar', 'label' => 'Appointments', 'links' => [
            ['appointments-records.php',  'Appointments Records',  ['owner', 'admin', 'dentist']],
            ['appointments-schedule.php', 'Appointments Schedule', ['owner', 'admin', 'dentist']],
        ]],
        ['icon' => 'feather-activity', 'label' => 'Treatments', 'links' => [
            ['treatments-records.php', 'Treatment Records',  ['owner', 'admin', 'dentist']],
            ['treatments-active.php',  'Active Treatments',  ['owner', 'admin', 'dentist']],
        ]],
        ['icon' => 'feather-clipboard', 'label' => 'Patients', 'links' => [
            ['patients-list.php', 'Patients List', ['owner', 'admin', 'dentist']],
        ]],
    ],
    'Financial' => [
        ['icon' => 'feather-file-text', 'label' => 'Billing', 'links' => [
            ['billing-records.php', 'Billing Records', ['owner', 'admin']],
        ]],
    ],
    'Inventory' => [
        ['icon' => 'feather-package', 'label' => 'Inventory', 'links' => [
            ['inventory-records.php',        'Inventory Records', ['owner', 'admin', 'dentist']],
            ['inventory-stock-movement.php', 'Stock Movement',    ['owner', 'admin', 'dentist']],
            ['inventory-transfers.php',      'Stock Transfers',   ['owner', 'admin', 'dentist']],
            ['inventory-procurement.php',    'Procurement',       ['owner', 'admin', 'dentist']],
        ]],
        ['icon' => 'feather-tool', 'label' => 'Clinic Configuration', 'links' => [
            ['service-records.php',   'Service Catalog',   ['owner', 'admin']],
            ['discount-vouchers.php', 'Discount Vouchers', ['owner', 'admin']],
        ]],
    ],
    'Management' => [
        ['icon' => 'feather-user-check', 'label' => 'Dentists', 'links' => [
            ['dentist-records.php', 'Dentist Records', ['owner']],
        ]],
        ['icon' => 'feather-server', 'label' => 'Branches', 'links' => [
            ['branches-records.php', 'Branch Records', ['owner']],
        ]],
        ['icon' => 'feather-users', 'label' => 'Accounts', 'links' => [
            ['accounts-records.php', 'Accounts Records', ['owner']],
        ]],
        ['icon' => 'feather-message-square', 'label' => 'Feedback', 'links' => [
            ['feedback-records.php', 'Patient Feedback', ['owner']],
        ]],
    ],
    'System' => [
        ['icon' => 'feather-shield', 'label' => 'Audit Trail', 'links' => [
            ['audit-records.php', 'Audit Records', ['owner', 'admin', 'dentist']],
        ]],
        // Staff Notification Center (every staff role) + the patient email / SMS /
        // push delivery log, templates and announcements
        ['icon' => 'feather-bell', 'label' => 'Notifications', 'links' => [
            ['notification-center.php', 'Notification Center', ['owner', 'admin', 'dentist']],
            ['notification-records.php', 'Notification Log', ['owner', 'admin']],
        ]],
    ],
];

// Pages reached from a list (not linked directly) highlight their parent link
$navCurrent = basename($_SERVER['SCRIPT_NAME'] ?? '');
$navCurrent = [
    'patient-records.php'   => 'patients-list.php',
    'service-materials.php' => 'service-records.php',
    'service-pricing.php'   => 'service-records.php',
][$navCurrent] ?? $navCurrent;
// Owner-assigned modules (Account Records → Sidebar Access): a restricted
// admin / dentist only sees its modules. The page guard enforces the same
// list (controllers/secure_controller.php), so this is not the only check.
require_once __DIR__ . '/../controllers/module_access.php';
$navAllowed = fn(string $href) => ($m = esPageModule(strtok($href, '#'))) === null || esCanUseModule($m);
foreach ($navSections as $caption => $groups) {
    foreach ($groups as $gi => $group) {
        if (isset($group['href'])) {          // direct link (no submenu)
            if (!in_array($role, $group['roles'], true) || !$navAllowed($group['href'])) unset($navSections[$caption][$gi]);
            continue;
        }
        $group['links'] = array_values(array_filter($group['links'], fn($l) => in_array($role, $l[2], true) && $navAllowed($l[0])));
        if ($group['links']) $navSections[$caption][$gi] = $group;
        else unset($navSections[$caption][$gi]);
    }
    if (!$navSections[$caption]) unset($navSections[$caption]);
}
?>
<script>
        // Staff interface marker: turns on the staff dropdown design in
        // assets/css/custom-select.css (the patient portal doesn't include this sidebar).
        document.documentElement.classList.add('es-staff');

        // Show / Hide Filters: filters are hidden by default. Apply that BEFORE
        // the toolbar is parsed (this script runs at the top of <body>), so the
        // filters never flash and then collapse. Only a page where the user
        // chose "Show Filters" starts open. See assets/js/es-ajax.js.
        var esFtOpen = false;
        try { esFtOpen = sessionStorage.getItem('esFilters:' + location.pathname) === '1'; } catch (e) { /* storage blocked: hidden */ }
        if (!esFtOpen) document.documentElement.classList.add('es-ft-hidden');

        // Restore the collapsed sidebar before it paints, so every page opens
        // at the width the user chose (the theme saves the choice under this
        // key but never reads it back). Desktop only — see assets/js/es-sidebar.js.
        try {
            if (window.innerWidth >= 1025 && localStorage.getItem('nexel-classic-dashboard-menu-mini-theme') === 'menu-mini-theme') {
                document.documentElement.classList.add('minimenu');
            }
        } catch (e) { /* storage blocked: keep the default (expanded) */ }

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
<link rel="stylesheet" href="assets/css/es-sidebar.css?v=1">
<script src="assets/js/es-sidebar.js?v=1" defer></script>
<!-- Row action button (⋮) + menu design for every module (from Appointment Records) -->
<link rel="stylesheet" href="assets/css/es-actions.css?v=1">
<script src="assets/js/es-actions.js?v=2"></script>
<!-- Phone card tables for every staff list (labels from the column headers) -->
<script src="assets/js/es-responsive.js?v=1" defer></script>
<nav class="nxl-navigation">
    <div class="navbar-wrapper">
        <div class="m-header">
            <a href="<?= $isOwner ? 'dashboard.php' : htmlspecialchars(esModuleHome() ?? 'unauthorized.php') ?>" class="b-brand">
                <img src="assets/images/EssenciaSmile-Original-Logo.svg" alt="EssenciaSmile" class="logo logo-lg" width="194" height="34" style="max-width:100%;height:auto;">
                <img src="assets/images/Essencia-abbr.png" alt="EssenciaSmile" class="logo logo-sm" width="42" height="32">

            </a>
        </div>
        <div class="navbar-content">
            <ul class="nxl-navbar">

                <?php foreach ($navSections as $caption => $groups): ?>
                    <li class="nxl-item nxl-caption">
                        <label><?= htmlspecialchars($caption) ?></label>
                    </li>
                    <?php foreach ($groups as $group):
                        if (isset($group['href'])):
                            $linkActive = $navCurrent === $group['href']; ?>
                        <li class="nxl-item<?= $linkActive ? ' active' : '' ?>">
                            <a href="<?= htmlspecialchars($group['href']) ?>" class="nxl-link"<?= $linkActive ? ' aria-current="page"' : '' ?>>
                                <span class="nxl-micon"><i class="<?= $group['icon'] ?>"></i></span>
                                <span class="nxl-mtext"><?= htmlspecialchars($group['label']) ?></span>
                            </a>
                        </li>
                        <?php continue; endif;
                        $groupActive = in_array($navCurrent, array_map(fn($h) => strtok($h, '#'), array_column($group['links'], 0)), true); ?>
                        <li class="nxl-item nxl-hasmenu<?= $groupActive ? ' active nxl-trigger' : '' ?>">
                            <a href="javascript:void(0);" class="nxl-link">
                                <span class="nxl-micon"><i class="<?= $group['icon'] ?>"></i></span>
                                <span class="nxl-mtext"><?= htmlspecialchars($group['label']) ?></span>
                                <span class="nxl-arrow"><i class="feather-chevron-right"></i></span>
                            </a>
                            <ul class="nxl-submenu">
                                <?php foreach ($group['links'] as [$href, $text]): ?>
                                    <li class="nxl-item<?= strtok($href, '#') === $navCurrent ? ' active' : '' ?>"><a class="nxl-link" href="<?= htmlspecialchars($href) ?>"><?= htmlspecialchars($text) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <li class="nxl-item">
                    <a href="logout.php" class="nxl-link" data-signout-confirm>
                        <span class="nxl-micon"><i class="feather-log-out"></i></span>
                        <span class="nxl-mtext">Logout</span>
                    </a>
                </li>

            </ul>
        </div>
    </div>
</nav>
