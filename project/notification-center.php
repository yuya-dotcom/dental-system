<?php
// =============================================================
//  notification-center.php — View All Notifications (Owner / Admin / Dentist)
//
//  The staff Notification Center's full list: status (All / Unread / Read),
//  category, priority and (owner) branch filters, paging, Mark as Read and
//  Mark All as Read. Rows are loaded by assets/js/es-notifications.js from
//  api/staff_notifications.php, which applies the role / branch / module
//  rules on the server. Not a sidebar module: every staff account has it,
//  and each sees only what it is allowed to.
// =============================================================
define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/list_filters.php';
require_once __DIR__ . '/controllers/staff_notifications.php';

$viewer   = staffn_viewer();
admin_release_session();
$isOwner  = ($viewer['role'] ?? '') === 'owner';
$cats     = $viewer ? staffn_category_options($viewer) : [];
$branches = $isOwner ? getAllBranches() : [];
$me       = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Notifications</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=3">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
    <link rel="stylesheet" href="assets/css/es-admin.css?v=8">
    <link rel="stylesheet" href="assets/css/es-records.css?v=7">
    <link rel="stylesheet" href="assets/css/analytics.css?v=8">
    <link rel="stylesheet" href="assets/css/appointments-records.css?v=10">
</head>
<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>
    <main class="nxl-container">
        <div class="nxl-content">
            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">Notifications</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Notifications</li>
                    </ul>
                </div>
            </div>
            <div class="main-content ar-page">
                <div class="row"><div class="col-lg-12">
                    <div class="card stretch stretch-full esn-page" id="esnCenter" data-user="<?= (int)($me['user_id'] ?? 0) ?>">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                All Notifications <span class="an-pill info ms-2" id="esnPageUnread">0 unread</span>
                                <span class="rec-card-sub">
                                    <?= $isOwner ? 'Clinic activity across every branch.' : 'Activity at ' . htmlspecialchars($me['branch_name'] ?? 'your branch') . ' that your account can access.' ?>
                                    Critical and important items are marked; unread ones are highlighted.
                                </span>
                            </h5>
                            <div class="card-header-action">
                                <button type="button" class="btn btn-sm btn-primary es-btn-compact" id="esnPageMarkAll" data-esn-markall disabled>
                                    <i class="feather-check-circle me-1"></i>Mark all as read
                                </button>
                            </div>
                        </div>
                        <div class="list-toolbar is-fluid">
                            <?= listField('Status', '<select id="esnFState" class="js-custom-select" data-cs-filter>'
                                . '<option value="all">All</option><option value="unread">Unread</option><option value="read">Read</option></select>', 'esnFState') ?>
                            <?php
                            $catHtml = '<select id="esnFCategory" class="js-custom-select" data-cs-filter><option value="">All Categories</option>';
                            foreach ($cats as $k => $label) $catHtml .= '<option value="' . htmlspecialchars($k) . '">' . htmlspecialchars($label) . '</option>';
                            echo listField('Category', $catHtml . '</select>', 'esnFCategory');
                            $prHtml = '<select id="esnFPriority" class="js-custom-select" data-cs-filter><option value="">All Priorities</option>';
                            foreach (STAFFN_PRIORITIES as $k => $label) $prHtml .= '<option value="' . $k . '">' . $label . '</option>';
                            echo listField('Priority', $prHtml . '</select>', 'esnFPriority');
                            if ($isOwner) {
                                $brHtml = '<select id="esnFBranch" class="js-custom-select" data-cs-filter><option value="">All Branches</option>';
                                foreach ($branches as $b) $brHtml .= '<option value="' . (int)$b['branch_id'] . '">' . htmlspecialchars($b['branch_name']) . '</option>';
                                echo listField('Branch', $brHtml . '</select>', 'esnFBranch');
                            }
                            ?>
                            <div class="list-field list-field-reset">
                                <button type="button" class="list-reset" id="esnPageReset" title="Clear all filters"><i class="feather-rotate-ccw me-1"></i>Reset</button>
                            </div>
                        </div>
                        <div class="esn-list" id="esnPageList" aria-live="polite"></div>
                        <div class="esn-page-foot" id="esnPageFoot" hidden></div>
                    </div>
                </div></div>
            </div>
        </div>
    </main>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/admin.js?v=7"></script>
    <script src="assets/js/es-swal.js?v=3"></script>
    <script src="assets/js/es-ajax.js?v=7"></script>   <!-- Show / Hide Filters toggle (same as the other list pages) -->
    <script src="assets/js/custom-select.js?v=3"></script>
</body>
</html>
