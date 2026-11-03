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
$navSections = [
    'Overview' => [
        ['icon' => 'feather-airplay', 'label' => 'Dashboards', 'links' => [
            ['dashboard.php', 'Overview',  ['owner']],
            ['analytics.php', 'Analytics', ['owner']],
        ]],
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
        ]],
        ['icon' => 'feather-tool', 'label' => 'Materials Configuration', 'links' => [
            ['service-records.php',   'Services',          ['owner', 'admin']],
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
    ],
];

// Pages reached from a list (not linked directly) highlight their parent link
$navCurrent = basename($_SERVER['SCRIPT_NAME'] ?? '');
$navCurrent = [
    'patient-records.php'   => 'patients-list.php',
    'service-materials.php' => 'service-records.php',
    'service-pricing.php'   => 'service-records.php',
][$navCurrent] ?? $navCurrent;

foreach ($navSections as $caption => $groups) {
    foreach ($groups as $gi => $group) {
        $group['links'] = array_values(array_filter($group['links'], fn($l) => in_array($role, $l[2], true)));
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
                <img src="assets/images/EssenciaSmile-Original-Logo.svg" alt="EssenciaSmile" class="logo logo-lg" width="194" height="34" style="max-width:100%;height:auto;">

            </a>
        </div>
        <div class="navbar-content">
            <ul class="nxl-navbar">

                <?php foreach ($navSections as $caption => $groups): ?>
                    <li class="nxl-item nxl-caption">
                        <label><?= htmlspecialchars($caption) ?></label>
                    </li>
                    <?php foreach ($groups as $group):
                        $groupActive = in_array($navCurrent, array_column($group['links'], 0), true); ?>
                        <li class="nxl-item nxl-hasmenu<?= $groupActive ? ' active nxl-trigger' : '' ?>">
                            <a href="javascript:void(0);" class="nxl-link">
                                <span class="nxl-micon"><i class="<?= $group['icon'] ?>"></i></span>
                                <span class="nxl-mtext"><?= htmlspecialchars($group['label']) ?></span>
                                <span class="nxl-arrow"><i class="feather-chevron-right"></i></span>
                            </a>
                            <ul class="nxl-submenu">
                                <?php foreach ($group['links'] as [$href, $text]): ?>
                                    <li class="nxl-item<?= $href === $navCurrent ? ' active' : '' ?>"><a class="nxl-link" href="<?= $href ?>"><?= htmlspecialchars($text) ?></a></li>
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
