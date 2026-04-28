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
require_once __DIR__ . '/dbconfig.php';

requireLogin();

const LOGS_PER_PAGE = 20;

$currentRole   = $_SESSION['role']    ?? '';
$currentUserId = $_SESSION['user_id'] ?? null;
$isOwner       = $currentRole === 'owner';

$filterModule = trim($_GET['module'] ?? '');
$filterAction = trim($_GET['action'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($page - 1) * LOGS_PER_PAGE;

// ── Build Supabase query ─────────────────────────────────────
$q = [
    'select=*',
    'order=created_at.desc',
    'limit='  . LOGS_PER_PAGE,
    'offset=' . $offset,
];

// Non-owners see only their own logs
if (!$isOwner && $currentUserId) {
    $q[] = 'user_id=eq.' . (int)$currentUserId;
}

if ($filterModule) $q[] = 'module=eq.'  . urlencode($filterModule);
if ($filterAction) $q[] = 'action=eq.'  . urlencode(strtoupper($filterAction));

$result     = supabase_request('audit_logs', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
$logs       = is_array($result['data']) ? $result['data'] : [];
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
    'treatments', 'billing', 'inventory', 'accounts',
    'branches', 'dentists', 'services',
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
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        /* ── Audit-specific overrides ── */
        .audit-entity-id  { font-family: monospace; font-size: .78rem; }
        .audit-desc       { max-width: 240px; white-space: normal; line-height: 1.3; }
        .module-badge     { font-size: .72rem; letter-spacing: .3px; }
        .action-badge     { font-size: .78rem; font-weight: 700; letter-spacing: .5px; min-width: 68px; text-align: center; }
        .user-role-badge  { font-size: .68rem; }
    </style>
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

                <!-- Filters -->
                <div class="page-header-right ms-auto">
                    <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
                        <!-- Module filter -->
                        <select name="module" class="form-select form-control hdr-control" style="min-width:180px;" onchange="this.form.submit()">
                            <option value="">All Modules</option>
                            <?php foreach ($allModules as $m): ?>
                                <option value="<?= htmlspecialchars($m) ?>" <?= $filterModule === $m ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucwords(str_replace('-', ' ', $m))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Action filter -->
                        <select name="action" class="form-select form-control hdr-control" style="min-width:130px;" onchange="this.form.submit()">
                            <option value="">All Actions</option>
                            <option value="INSERT" <?= $filterAction === 'INSERT' ? 'selected' : '' ?>>INSERT (Added)</option>
                            <option value="UPDATE" <?= $filterAction === 'UPDATE' ? 'selected' : '' ?>>UPDATE (Modified)</option>
                            <option value="DELETE" <?= $filterAction === 'DELETE' ? 'selected' : '' ?>>DELETE (Removed)</option>
                        </select>

                        <?php if ($filterModule || $filterAction): ?>
                            <a href="audit-records.php" class="btn btn-light-brand">
                                <i class="bi bi-x-circle me-1"></i>Reset
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div><!-- /page-header -->

            <div class="main-content">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card stretch stretch-full">

                            <div class="card-header">
                                <h5 class="card-title">
                                    <?= $isOwner ? 'All Activity Logs' : 'My Activity Logs' ?>
                                    <span class="badge bg-soft-primary text-primary ms-2"><?= $total ?> total</span>
                                </h5>
                                <div class="card-header-action">
                                    <div class="card-header-btn">
                                        <div data-bs-toggle="tooltip" title="Refresh">
                                            <a href="audit-records.php" class="avatar-text avatar-xs bg-warning"></a>
                                        </div>
                                        <div data-bs-toggle="tooltip" title="Maximize/Minimize">
                                            <a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0" id="auditTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="min-width:110px;">Timestamp</th>
                                                <th style="min-width:140px;">User</th>
                                                <?php if ($isOwner): ?>
                                                <th style="min-width:110px;">Branch</th>
                                                <?php endif; ?>
                                                <th style="min-width:150px;">Module</th>
                                                <th style="min-width:90px;">Action</th>
                                                <th style="min-width:160px;">Entity</th>
                                                <th>Description</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($logs)): ?>
                                                <tr>
                                                    <td colspan="<?= $isOwner ? 7 : 6 ?>" class="text-center py-5">
                                                        <img src="assets/images/empty.png" alt="" style="width:80px;" class="mb-3 opacity-50">
                                                        <p class="text-muted mb-0">No activity logs recorded yet.</p>
                                                    </td>
                                                </tr>
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
                                                    $description = htmlspecialchars(
                                                        $log['description'] ?? $log['details'] ?? ''
                                                    );
                                                    $moduleLabel = ucwords(str_replace('-', ' ', $module));
                                                ?>
                                                <tr>
                                                    <!-- Timestamp -->
                                                    <td>
                                                        <span class="text-dark fw-medium d-block"><?= date('M d, Y', $ts) ?></span>
                                                        <small class="text-muted"><?= date('h:i A', $ts) ?></small>
                                                    </td>

                                                    <!-- User + Role -->
                                                    <td>
                                                        <span class="d-block fw-medium text-dark"><?= $username ?></span>
                                                        <?php if ($role): ?>
                                                            <span class="badge user-role-badge <?= roleBadgeClass($role) ?> text-uppercase">
                                                                <?= $role ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>

                                                    <!-- Branch (owner only) -->
                                                    <?php if ($isOwner): ?>
                                                    <td>
                                                        <small class="text-dark"><?= $branchName ?></small>
                                                    </td>
                                                    <?php endif; ?>

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
                                                            <span class="d-block text-dark fw-medium"><?= $entityName ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($entityId): ?>
                                                            <span class="audit-entity-id text-muted">ID: <?= $entityId ?></span>
                                                        <?php endif; ?>
                                                    </td>

                                                    <!-- Description -->
                                                    <td class="audit-desc">
                                                        <small class="text-muted"><?= $description ?: '<em class="text-muted">—</em>' ?></small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div><!-- /card-body -->

                            <!-- Pagination -->
                            <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <p class="text-muted small mb-0">
                                    Showing <?= $from ?>–<?= $to ?> of <?= $total ?> log(s)
                                </p>

                                <?php if ($totalPages > 1): ?>
                                    <ul class="list-unstyled d-flex align-items-center gap-1 mb-0 pagination-common-style">
                                        <li>
                                            <?php if ($page <= 1): ?>
                                                <span class="text-muted" style="opacity:.4;"><i class="bi bi-arrow-left"></i></span>
                                            <?php else: ?>
                                                <a href="?page=<?= $page - 1 ?>&module=<?= urlencode($filterModule) ?>&action=<?= urlencode($filterAction) ?>">
                                                    <i class="bi bi-arrow-left"></i>
                                                </a>
                                            <?php endif; ?>
                                        </li>

                                        <?php
                                        // Show max 7 page links around current page
                                        $start = max(1, $page - 3);
                                        $end   = min($totalPages, $page + 3);
                                        for ($i = $start; $i <= $end; $i++):
                                        ?>
                                            <li>
                                                <a href="?page=<?= $i ?>&module=<?= urlencode($filterModule) ?>&action=<?= urlencode($filterAction) ?>"
                                                    class="<?= ($i === $page) ? 'active' : '' ?>">
                                                    <?= $i ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <li>
                                            <?php if ($page >= $totalPages): ?>
                                                <span class="text-muted" style="opacity:.4;"><i class="bi bi-arrow-right"></i></span>
                                            <?php else: ?>
                                                <a href="?page=<?= $page + 1 ?>&module=<?= urlencode($filterModule) ?>&action=<?= urlencode($filterAction) ?>">
                                                    <i class="bi bi-arrow-right"></i>
                                                </a>
                                            <?php endif; ?>
                                        </li>
                                    </ul>
                                <?php endif; ?>
                            </div>

                        </div><!-- /card -->
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
</body>
</html>