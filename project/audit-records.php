<?php
// =============================================================
//  audit-records.php  —  Universal Audit Trail
//
//  UPDATED: Shows new standardized columns:
//    Timestamp | User + Role | Branch | Module | Action |
//    Entity (ID + Name) | Description
//
//  Role access:
//    Owner  — sees ALL logs across all users and branches
//    Admin / Dentist — see only their own logs
// =============================================================

require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying
require_once __DIR__ . '/dbconfig.php';
require_once __DIR__ . '/controllers/list_filters.php';
require_once __DIR__ . '/controllers/record_ui.php';   // Appointment Records cell markup

requireLogin();

const LOGS_PER_PAGE = 20;

$currentRole   = $_SESSION['role']    ?? '';
$currentUserId = $_SESSION['user_id'] ?? null;
$isOwner       = $currentRole === 'owner';

$viewLogins   = ($_GET['view'] ?? '') === 'logins';      // Login Attempts view (module 'authentication')
$filterView   = $viewLogins ? 'logins' : '';
$filterModule = $viewLogins ? '' : trim($_GET['module'] ?? '');
$filterAction = $viewLogins ? '' : trim($_GET['action'] ?? '');
$filterLogin  = $viewLogins && in_array($_GET['login'] ?? '', ['success', 'failed'], true) ? $_GET['login'] : '';
// Branch / account filters: owner only (others already see only their own logs)
$filterBranch = $isOwner ? max(0, (int)($_GET['branch'] ?? 0)) : 0;
$filterUser   = $isOwner ? max(0, (int)($_GET['user'] ?? 0)) : 0;
$page         = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($page - 1) * LOGS_PER_PAGE;

// Sort (table headers) / search / date range
$sortOptions  = [
    'newest'      => ['Newest First',  'created_at.desc,log_id.desc'],
    'oldest'      => ['Oldest First',  'created_at.asc,log_id.asc'],
    'user_asc'    => ['User (A–Z)',    'username.asc.nullslast,created_at.desc'],
    'user_desc'   => ['User (Z–A)',    'username.desc.nullslast,created_at.desc'],
    'branch_asc'  => ['Branch (A–Z)',  'branch_name.asc.nullslast,created_at.desc'],
    'branch_desc' => ['Branch (Z–A)',  'branch_name.desc.nullslast,created_at.desc'],
    'module_asc'  => ['Module (A–Z)',  'module.asc.nullslast,created_at.desc'],
    'module_desc' => ['Module (Z–A)',  'module.desc.nullslast,created_at.desc'],
    'action_asc'  => ['Action (A–Z)',  'action.asc.nullslast,created_at.desc'],
    'action_desc' => ['Action (Z–A)',  'action.desc.nullslast,created_at.desc'],
    'entity_asc'  => ['Entity (A–Z)',  'entity_name.asc.nullslast,created_at.desc'],
    'entity_desc' => ['Entity (Z–A)',  'entity_name.desc.nullslast,created_at.desc'],
];
[$sortKey, $sortOrder] = listSort($sortOptions, 'newest');
$search       = listSearch();
$dateFrom     = listDate('from');
$dateTo       = listDate('to');
// ── Build Supabase query ─────────────────────────────────────
$q = [
    'select=*',
    $sortOrder,
    'limit='  . LOGS_PER_PAGE,
    'offset=' . $offset,
];

// Non-owners see only their own logs
if (!$isOwner && $currentUserId) {
    $q[] = 'user_id=eq.' . (int)$currentUserId;
}

if ($viewLogins) {
    $q[] = 'module=eq.authentication';
    // Status lives in after_data (works whatever action name the row was stored with)
    if ($filterLogin) $q[] = 'after_data->>status=eq.' . ($filterLogin === 'success' ? 'Successful' : 'Failed');
}
if ($filterModule) $q[] = 'module=eq.'  . urlencode($filterModule);
if ($filterAction) $q[] = 'action=eq.'  . urlencode(strtoupper($filterAction));
if ($filterBranch) $q[] = 'branch_id=eq.' . $filterBranch;
if ($filterUser)   $q[] = 'user_id=eq.' . $filterUser;
array_push($q, ...listSearchFilter($search, ['username', 'entity_name', 'description']));
array_push($q, ...listDateRange('created_at', $dateFrom, $dateTo, true));
$filtersOn  = listActiveCount([$filterModule, $filterAction, $filterLogin, $filterBranch, $filterUser, $search, $dateFrom, $dateTo, $sortKey !== 'newest']);
$listParams = ['view' => $filterView, 'module' => $filterModule, 'action' => $filterAction, 'login' => $filterLogin, 'branch' => $filterBranch, 'user' => $filterUser, 'q' => $search, 'from' => $dateFrom, 'to' => $dateTo];
$pageUrl    = listUrl('audit-records.php', $listParams + ['sort' => $sortKey === 'newest' ? '' : $sortKey]);
$sortBase   = listUrl('audit-records.php', $listParams);
$resetUrl   = $viewLogins ? 'audit-records.php?view=logins' : 'audit-records.php';

// One parallel wave: list + navbar bell (+ branch / account lists for the owner's filters)
$_auditSpecs = ['list' => admin_q('audit_logs', implode('&', $q), ['Prefer: count=exact'])];
if ($isOwner) $_auditSpecs['users'] = admin_q('users', 'select=user_id,full_name,username,role&order=full_name.asc');
$_auditRes   = admin_prefetch($_auditSpecs, $isOwner ? ['navbar', 'branches'] : ['navbar']);
$auditUsers  = $isOwner && is_array($_auditRes['users']['data'] ?? null) ? $_auditRes['users']['data'] : [];
$auditBranches = [];
if ($isOwner) { require_once __DIR__ . '/controllers/branch_controller.php'; $auditBranches = getAllBranches(); }
$result     = admin_get('audit_logs', implode('&', $q), ['Prefer: count=exact']);
$auditOk    = ($result['status'] ?? 0) >= 200 && ($result['status'] ?? 0) < 300 && is_array($result['data']) && array_is_list($result['data']);
$logs       = $auditOk ? $result['data'] : [];   // an error object (e.g. 416 past the last page) is never rendered as rows
$total      = (int)(explode('/', ($result['headers']['content-range'] ?? '0/0'))[1] ?? 0);
$totalPages = max(1, (int)ceil($total / LOGS_PER_PAGE));
$from       = $total > 0 ? $offset + 1 : 0;
$to         = min($offset + LOGS_PER_PAGE, $total);

// ── Helpers ──────────────────────────────────────────────────
function actionBadgeClass(string $action): string {
    return match (strtoupper($action)) {
        'INSERT' => 'bg-soft-success text-success',
        'UPDATE' => 'bg-soft-warning text-warning',
        'DELETE' => 'bg-soft-danger  text-danger',
        'LOGIN_SUCCESS' => 'bg-soft-success text-success',
        'LOGIN_FAILED'  => 'bg-soft-danger  text-danger',
        default  => 'bg-soft-secondary text-secondary',
    };
}

function roleBadgeClass(string $role): string {
    return match (strtolower($role)) {
        'owner'   => 'bg-soft-primary text-primary',
        'admin'   => 'bg-soft-info    text-info',
        'dentist' => 'bg-soft-success text-success',
        default   => 'bg-soft-secondary text-secondary',
    };
}

$allModules = [
    'appointment-schedules', 'appointment-records', 'patients',
    'treatments', 'billing', 'inventory', 'stock-transfers', 'procurement', 'accounts',
    'branches', 'dentists', 'services', 'service-records', 'patient-accounts', 'authentication', 'notifications',
    'settings',   // Settings › Database & Backup downloads
];
?>
<!DOCTYPE html>
<html lang="zxx">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Audit Logs</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <style>
        /* ── Audit-specific overrides ── */
        .audit-entity-id  { font-family: monospace; font-size: .78rem; }
        .module-badge     { font-size: .72rem; letter-spacing: .3px; }
        .action-badge     { font-size: .78rem; font-weight: 700; letter-spacing: .5px; min-width: 68px; text-align: center; }
        .user-role-badge  { font-size: .68rem; }
        .audit-views { display: inline-flex; gap: 4px; padding: 3px; border: 1.2px solid #e5e7eb; border-radius: 7px; background: #fbfcfe; }
        .audit-views a { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 5px; font-size: .8rem; font-weight: 500; color: #6b7a90; white-space: nowrap; }
        .audit-views a:hover { color: #185fa5; }
        .audit-views a.active { background: #185fa5; color: #fff; }
        .login-ip { font-family: monospace; font-size: .78rem; }

        /* View Details modal: shared .es-detail-modal styles (assets/css/custom.css) */
    </style>
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

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title">
                        <h5 class="m-b-10">Audit Trail</h5>
                    </div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                        <li class="breadcrumb-item">Audit Trail</li>
                    </ul>
                </div>

                            </div><!-- /page-header -->

            <div class="main-content an-page ar-page">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card stretch stretch-full">

                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <?= $viewLogins ? ($isOwner ? 'Login Attempts' : 'My Login Attempts') : ($isOwner ? 'All Activity Logs' : 'My Activity Logs') ?>
                                    <span class="an-pill info ms-2"><?= $total ?> <?= $filtersOn ? 'found' : 'total' ?></span>
                                    <span class="rec-card-sub"><?= $viewLogins ? 'Every sign-in to the staff portal, successful or not' : 'Who added, changed or removed records, and when' ?></span>
                                </h5>
                                <div class="audit-views" role="tablist" aria-label="Audit view">
                                    <a href="audit-records.php" role="tab" aria-selected="<?= $viewLogins ? 'false' : 'true' ?>" class="<?= $viewLogins ? '' : 'active' ?>"><i class="feather-activity"></i> All Activity</a>
                                    <a href="audit-records.php?view=logins" role="tab" aria-selected="<?= $viewLogins ? 'true' : 'false' ?>" class="<?= $viewLogins ? 'active' : '' ?>"><i class="feather-log-in"></i> Login Attempts</a>
                                </div>
                                <div class="card-header-action">
                                    <div class="card-header-btn">
                                        <div data-bs-toggle="tooltip" title="Refresh">
                                            <a href="<?= $resetUrl ?>" class="avatar-text avatar-xs bg-warning"></a>
                                        </div>
                                        <div data-bs-toggle="tooltip" title="Maximize/Minimize">
                                            <a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="list-toolbar">
                                <input type="hidden" id="filterView" value="<?= $filterView ?>">
                                <?= listSearchControl($search, $viewLogins ? 'Name or username tried' : 'User, entity or description') ?>
                                <?= listDateRangeControl($dateFrom, $dateTo) ?>
                                <?php if ($viewLogins): ?>
                                <div class="list-field"><label class="list-label" for="filterLogin">Login Status</label>
                                <select id="filterLogin" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                    <option value="">All Attempts</option>
                                    <option value="success" <?= $filterLogin === 'success' ? 'selected' : '' ?>>Successful</option>
                                    <option value="failed"  <?= $filterLogin === 'failed'  ? 'selected' : '' ?>>Failed</option>
                                </select></div>
                                <?php endif; ?>
                                <?php if ($isOwner): ?>
                                <div class="list-field"><label class="list-label" for="filterBranch">Branch</label>
                                <select id="filterBranch" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($auditBranches as $b): ?>
                                    <option value="<?= (int)$b['branch_id'] ?>" <?= (int)$b['branch_id'] === $filterBranch ? 'selected' : '' ?>><?= htmlspecialchars($b['branch_name']) ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                                <div class="list-field"><label class="list-label" for="filterUser">Account</label>
                                <select id="filterUser" class="js-custom-select" data-cs-filter="wide" data-cs-search onchange="applyFilters()">
                                    <option value="0">All Accounts</option>
                                    <?php foreach ($auditUsers as $u): ?>
                                    <option value="<?= (int)$u['user_id'] ?>" <?= (int)$u['user_id'] === $filterUser ? 'selected' : '' ?>><?= htmlspecialchars(($u['full_name'] ?? '') . ' (' . ($u['username'] ?? '') . ')') ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                                <?php endif; ?>
                                <?php if (!$viewLogins): ?>
                                <div class="list-field"><label class="list-label" for="filterModule">Module</label>
                                <select id="filterModule" class="js-custom-select" data-cs-filter="wide" data-cs-search onchange="applyFilters()">
                                    <option value="">All Modules</option>
                                    <?php foreach ($allModules as $m): ?>
                                    <option value="<?= htmlspecialchars($m) ?>" <?= $filterModule === $m ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('-', ' ', $m))) ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                                <div class="list-field"><label class="list-label" for="filterAction">Action</label>
                                <select id="filterAction" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                    <option value="">All Actions</option>
                                    <option value="INSERT" <?= $filterAction === 'INSERT' ? 'selected' : '' ?>>INSERT (Added)</option>
                                    <option value="UPDATE" <?= $filterAction === 'UPDATE' ? 'selected' : '' ?>>UPDATE (Modified)</option>
                                    <option value="DELETE" <?= $filterAction === 'DELETE' ? 'selected' : '' ?>>DELETE (Removed)</option>
                                    <option value="LOGIN_SUCCESS" <?= $filterAction === 'LOGIN_SUCCESS' ? 'selected' : '' ?>>LOGIN SUCCESS</option>
                                    <option value="LOGIN_FAILED"  <?= $filterAction === 'LOGIN_FAILED'  ? 'selected' : '' ?>>LOGIN FAILED</option>
                                </select></div>
                                <?php endif; ?>
                                <?= listResetControl($resetUrl, $filtersOn) ?>
                            </div>

                            <div class="card-body custom-card-action p-0" style="overflow:visible;">
                                <!-- overflow:visible (same as appointments-schedule.php) so the ⋮ menu opens outside the table instead of being clipped -->
                                <div class="table-responsive" style="overflow:visible;">
                                    <table class="table table-hover align-middle mb-0 rec-table ar-table" id="auditTable">
                                        <thead class="table-light">
                                            <tr>
                                                <?= listTh('Timestamp', 'newest', 'oldest', $sortKey, $sortBase, 'style="min-width:110px;"') ?>
                                                <?= listTh('User', 'user_asc', 'user_desc', $sortKey, $sortBase, 'style="min-width:140px;"') ?>
                                                <?php if ($isOwner): ?>
                                                <?= listTh('Branch', 'branch_asc', 'branch_desc', $sortKey, $sortBase, 'style="min-width:110px;"') ?>
                                                <?php endif; ?>
                                                <?php if ($viewLogins): ?>
                                                <?= listTh('Username Tried', 'entity_asc', 'entity_desc', $sortKey, $sortBase, 'style="min-width:140px;"') ?>
                                                <?= listTh('Status', 'action_asc', 'action_desc', $sortKey, $sortBase, 'style="min-width:100px;"') ?>
                                                <th style="min-width:180px;">Reason</th>
                                                <th>IP Address</th>
                                                <?php else: ?>
                                                <?= listTh('Module', 'module_asc', 'module_desc', $sortKey, $sortBase, 'style="min-width:150px;"') ?>
                                                <?= listTh('Action Type', 'action_asc', 'action_desc', $sortKey, $sortBase, 'style="min-width:90px;"') ?>
                                                <?= listTh('Entity', 'entity_asc', 'entity_desc', $sortKey, $sortBase, 'style="min-width:160px;"') ?>
                                                <?php endif; ?>
                                                <th class="rec-col-action text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($logs)):
                                                $auditCols = ($isOwner ? 7 : 6) + ($viewLogins ? 1 : 0);
                                                if (!$auditOk && $total === 0 && ($result['status'] ?? 0) >= 400 && ($result['status'] ?? 0) !== 416): ?>
                                                <?= listEmpty($auditCols, false, 'The logs could not be loaded', 'Please check your connection and refresh the page.', 'danger:feather-alert-triangle') ?>
                                                <?php else: ?>
                                                <?= listEmpty($auditCols, (bool)$filtersOn,
                                                        $viewLogins ? 'No login attempts yet' : 'No activity yet',
                                                        $viewLogins ? 'Sign-ins to the staff portal are recorded here.' : 'Changes made to records are listed here as they happen.',
                                                        $viewLogins ? 'feather-log-in' : 'feather-shield', $resetUrl) ?>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php foreach ($logs as $log): ?>
                                                <?php
                                                    $ts          = strtotime($log['created_at'] ?? 'now');
                                                    $username    = htmlspecialchars($log['username']    ?? '—');
                                                    $role        = htmlspecialchars($log['role']        ?? '');
                                                    $branchName  = htmlspecialchars($log['branch_name'] ?? '—');
                                                    $module      = htmlspecialchars($log['module']      ?? '—');
                                                    $action      = strtoupper($log['action']            ?? '');
                                                    $entityId    = htmlspecialchars($log['entity_id']   ?? '');
                                                    $entityName  = htmlspecialchars($log['entity_name'] ?? '—');
                                                    $moduleLabel = ucwords(str_replace('-', ' ', $module));
                                                    // Login attempts: status / reason / IP from after_data (decoded jsonb)
                                                    $ad          = is_array($log['after_data'] ?? null) ? $log['after_data'] : (json_decode((string)($log['after_data'] ?? ''), true) ?: []);
                                                    $loginOk     = ($ad['status'] ?? '') === 'Successful' || $action === 'LOGIN_SUCCESS';

                                                    // Everything the View Details modal shows (escaped again by JS on display)
                                                    $detail = [
                                                        'timestamp'   => date('F d, Y · h:i:s A', $ts),
                                                        'username'    => $log['username']    ?? null,
                                                        'role'        => $log['role']        ?? null,
                                                        'branch_name' => $log['branch_name'] ?? null,
                                                        'module'      => $moduleLabel,
                                                        'action'      => $action,
                                                        'entity_name' => $log['entity_name'] ?? null,
                                                        'entity_id'   => $log['entity_id']   ?? null,
                                                        'description' => $log['description'] ?? $log['details'] ?? null,
                                                    ];
                                                    $detail['action_class'] = actionBadgeClass($action);
                                                    $detail['description']  = $detail['description'] ?? ($log['description_old'] ?? null);
                                                    // Before / after data is not shown (and not sent to the browser); it stays in audit_logs.
                                                ?>
                                                <tr>
                                                    <!-- Timestamp -->
                                                    <td>
                                                        <?= recWhen(date('M d, Y', $ts), date('h:i A', $ts)) ?>
                                                    </td>

                                                    <!-- User + Role -->
                                                    <td>
                                                        <span class="d-block rec-strong"><?= $username ?></span>
                                                        <?php if ($role): ?>
                                                            <span class="badge user-role-badge <?= roleBadgeClass($role) ?> text-uppercase">
                                                                <?= $role ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>

                                                    <!-- Branch (owner only) -->
                                                    <?php if ($isOwner): ?>
                                                    <td>
                                                        <?= recBranch($log['branch_name'] ?? '', $auditBranches ?? []) ?>
                                                    </td>
                                                    <?php endif; ?>

                                                    <?php if ($viewLogins): ?>
                                                    <td><span class="rec-strong"><?= $entityName ?></span></td>
                                                    <td>
                                                        <?= recPill($loginOk ? 'Successful' : 'Failed', $loginOk ? 'up' : 'down') ?>
                                                    </td>
                                                    <td class="text-wrap"><small class="text-dark"><?= htmlspecialchars($ad['reason_text'] ?? ($log['description'] ?? '—')) ?></small></td>
                                                    <td><span class="login-ip text-muted"><?= htmlspecialchars($ad['ip'] ?? '—') ?></span></td>
                                                    <?php else: ?>
                                                    <!-- Module -->
                                                    <td>
                                                        <span class="badge module-badge bg-soft-info text-info text-uppercase">
                                                            <?= $moduleLabel ?>
                                                        </span>
                                                    </td>

                                                    <!-- Action -->
                                                    <td>
                                                        <span class="badge action-badge <?= actionBadgeClass($action) ?>">
                                                            <?= $action ?>
                                                        </span>
                                                    </td>

                                                    <!-- Entity ID + Name -->
                                                    <td>
                                                        <?php if ($entityName && $entityName !== '—'): ?>
                                                            <span class="d-block rec-strong"><?= $entityName ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($entityId): ?>
                                                            <span class="audit-entity-id text-muted">ID: <?= $entityId ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php endif; ?>

                                                    <!-- Action -->
                                                    <td class="rec-col-action">
                                                        <div class="dropdown">
                                                            <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-label="Actions for this log entry"><i class="feather-more-vertical"></i></a>
                                                            <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                                <a href="javascript:void(0);" class="dropdown-item" data-log="<?= htmlspecialchars(json_encode($detail, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>" onclick="viewAuditLog(this)">
                                                                    <i class="feather-eye"></i> View Details
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div><!-- /card-body -->

                            <!-- Pagination -->
                            <div class="card-footer ar-foot d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <?= listFootCount($from, $to, $total, $viewLogins ? 'login attempt' : 'log entry', $resetUrl, (bool)$filtersOn, $viewLogins ? 'login attempts' : 'log entries') ?>

                                <?= listPager($pageUrl, $page, $totalPages) ?>
                            </div>

                        </div><!-- /card -->
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- VIEW DETAILS MODAL (shared .es-detail-modal design) -->
    <div class="modal fade es-detail-modal ar-detail-modal" id="auditViewModal" tabindex="-1" aria-labelledby="auditViewTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-record-dialog">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-shield"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="auditViewTitle">Audit Log Details</h5>
                            <div class="apt-record-meta" id="auditViewTime">—</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-record-body p-0">
                    <div class="ar-view-body" id="auditViewBody"></div>
                </div>
                <div class="modal-footer apt-record-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <!-- SweetAlert2 v11 (self-hosted, same build as every other admin page): replaces the
         v9 bundled in vendors.min.js, whose result has no isConfirmed (Sign Out never ran) -->
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/js/admin.js?v=7"></script>
    <script src="assets/js/es-swal.js?v=3"></script>
    <script src="assets/js/es-ajax.js?v=7"></script>
    <script src="assets/js/custom-select.js?v=3"></script>
    <script src="assets/js/es-records.js?v=3"></script>
    <script>
    function applyFilters() {
        window._applyFilters('audit-records.php', { view: 'filterView', module: 'filterModule', action: 'filterAction', login: 'filterLogin', branch: 'filterBranch', user: 'filterUser' });
    }

    // ── View Details ─────────────────────────────────────────────
    // Read-only view of the stored log row. All values are set with
    // textContent, so log text is never parsed as HTML.
    function viewAuditLog(btn) {
        let log;
        try { log = JSON.parse(btn.dataset.log); } catch (e) { return; }

        const has = v => v !== null && v !== undefined && String(v).trim() !== '';
        const el = (tag, cls, text) => {
            const n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined) n.textContent = text;
            return n;
        };
        const label = k => String(k).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

        const card = (icon, title, content, wide) => {
            const sec = el('section', 'ar-card' + (wide ? ' ar-card-wide' : ''));
            const head = el('div', 'ar-card-header');
            const ic = el('span', 'ar-card-icon'); ic.append(el('i', icon));
            head.append(ic, el('h6', 'ar-card-title', title));
            const body = el('div', 'ar-card-fields');
            body.append(content);
            sec.append(head, body);
            return sec;
        };
        const fields = rows => {
            const frag = document.createDocumentFragment();
            rows.forEach(([k, v]) => {
                const row = el('div', 'ar-card-field');
                const val = el('span', 'ar-card-value' + (has(v) ? '' : ' ar-muted'), has(v) ? String(v) : '—');
                row.append(el('span', 'ar-card-label', k), val);
                frag.append(row);
            });
            return frag;
        };
        const sumItem = (icon, lbl, value) => {
            const item = el('div', 'ar-sum-item');
            const ic = el('span', 'ar-sum-icon'); ic.append(el('i', icon));
            const txt = el('div', 'ar-sum-text');
            txt.append(el('div', 'ar-sum-label', lbl), el('div', 'ar-sum-value', has(value) ? String(value) : '—'));
            item.append(ic, txt);
            return item;
        };

        const body = document.getElementById('auditViewBody');
        body.replaceChildren();
        document.getElementById('auditViewTime').textContent = log.timestamp || '—';

        // Summary: action · timestamp · module · branch
        const summary = el('div', 'ar-summary');
        const act = el('div', 'ar-sum-item ar-sum-status');
        act.append(el('div', 'ar-sum-label', 'Action'),
                   el('span', 'badge ' + (log.action_class || 'bg-soft-secondary text-secondary'), has(log.action) ? log.action : '—'));
        summary.append(act,
            sumItem('feather-clock', 'Timestamp', log.timestamp),
            sumItem('feather-layers', 'Module', log.module),
            sumItem('feather-map-pin', 'Branch', log.branch_name));
        body.append(summary);

        const grid = el('div', 'ar-cards-grid');
        grid.append(
            card('feather-user', 'Performed By', fields([
                ['User', log.username], ['Role', has(log.role) ? label(log.role) : '']])),
            card('feather-database', 'Affected Record', fields([
                ['Entity Type', log.module], ['Entity ID', log.entity_id], ['Entity Name', log.entity_name]])),
            card('feather-file-text', 'Description',
                has(log.description) ? el('p', 'ar-notes', log.description) : el('p', 'es-json-empty', 'No description recorded.'), true)
        );

        body.append(grid);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('auditViewModal')).show();
    }
    </script>
</body>
</html>