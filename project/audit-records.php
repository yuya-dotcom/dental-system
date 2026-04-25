<?php

require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/dbconfig.php';

// All roles can access, but non-owners only see their own logs
requireLogin();

const LOGS_PER_PAGE = 20;

$currentRole   = $_SESSION['role']    ?? '';
$currentUserId = $_SESSION['user_id'] ?? null;
$isOwner       = $currentRole === 'owner';

$filterModule = isset($_GET['module']) ? trim($_GET['module']) : '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($page - 1) * LOGS_PER_PAGE;

// Build the query
$q = [
    'select=*',
    'order=created_at.desc',
    'limit=' . LOGS_PER_PAGE,
    'offset=' . $offset,
];

// Owners see all logs; admins and dentists only see their own
if (!$isOwner && $currentUserId) {
    $q[] = 'user_id=eq.' . (int)$currentUserId;
}

if ($filterModule) {
    $q[] = 'module=eq.' . urlencode($filterModule);
}

$result = supabase_request('audit_logs', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
$logs   = is_array($result['data']) ? $result['data'] : [];
$total  = explode('/', ($result['headers']['content-range'] ?? '0/0'))[1] ?? 0;
$totalPages = ceil($total / LOGS_PER_PAGE);

// For the pagination range text (e.g., "Showing 1 to 20 of 100")
$from = $total > 0 ? $offset + 1 : 0;
$to   = min($offset + LOGS_PER_PAGE, $total);
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
</head>

<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>

    <main class="nxl-container">
        <div class="nxl-content">
            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title">
                        <h5 class="m-b-10">Audit Logs</h5>
                    </div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                        <li class="breadcrumb-item">Audit Trail</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2">
                            <form method="GET" class="d-flex gap-2">
                                <select name="module" class="form-select form-control hdr-control" onchange="this.form.submit()">
                                    <option value="">All Modules</option>
                                    <?php
                                    $modules = ['Appointments', 'Treatments', 'Billing', 'Inventory', 'Patients', 'Dentists', 'Branches', 'Accounts'];
                                    foreach ($modules as $m): ?>
                                        <option value="<?= $m ?>" <?= $filterModule == $m ? 'selected' : '' ?>><?= $m ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($filterModule): ?>
                                    <a href="audit-records.php" class="btn btn-light-brand">Reset</a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

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
                                    <table class="table table-hover" id="auditTable">
                                        <thead>
                                            <tr>
                                                <th>Timestamp</th>
                                                <th>User</th>
                                                <th>Module</th>
                                                <th>Action</th>
                                                <th class="text-end">Details</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($logs)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center py-5">
                                                        <img src="assets/images/empty.png" alt="" style="width: 80px;" class="mb-3 opacity-50">
                                                        <p class="text-muted">No activities recorded yet.</p>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php foreach ($logs as $log): ?>
                                                <tr>
                                                    <td>
                                                        <span class="text-dark fw-medium"><?= date('M d, Y', strtotime($log['created_at'])) ?></span>
                                                        <div class="small text-muted"><?= date('h:i A', strtotime($log['created_at'])) ?></div>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div>
                                                                <a href="javascript:void(0);" class="d-block font-weight-bold text-dark"><?= htmlspecialchars($log['username']) ?></a>
                                                                <small class="text-muted">ID: <?= $log['user_id'] ?></small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-soft-info text-info text-uppercase"><?= htmlspecialchars($log['module']) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="fw-bold text-dark"><?= htmlspecialchars($log['action']) ?></span>
                                                    </td>
                                                    <td class="text-wrap" style="max-width: 250px;">
                                                        <small class="text-muted"><?= htmlspecialchars($log['details']) ?></small>
                                                    </td>
                                                    <td class="text-end">
                                                        <code class="small text-muted"><?= htmlspecialchars($log['ip_address'] ?? 'N/A') ?></code>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <p class="text-muted small mb-0">
                                    Showing <?= $from ?>–<?= $to ?> of <?= $total ?> activity log(s)
                                </p>

                                <?php if ($total > 10): ?>
                                    <ul class="list-unstyled d-flex align-items-center gap-1 mb-0 pagination-common-style">
                                        <li>
                                            <?php if ($page <= 1): ?>
                                                <a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-left"></i></a>
                                            <?php else: ?>
                                                <a href="?page=<?= $page - 1 ?>&module=<?= urlencode($filterModule) ?>"><i class="bi bi-arrow-left"></i></a>
                                            <?php endif; ?>
                                        </li>

                                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                            <li>
                                                <a href="?page=<?= $i ?>&module=<?= urlencode($filterModule) ?>"
                                                    class="<?= ($i == $page) ? 'active' : '' ?>">
                                                    <?= $i ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <li>
                                            <?php if ($page >= $totalPages): ?>
                                                <a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-right"></i></a>
                                            <?php else: ?>
                                                <a href="?page=<?= $page + 1 ?>&module=<?= urlencode($filterModule) ?>"><i class="bi bi-arrow-right"></i></a>
                                            <?php endif; ?>
                                        </li>
                                    </ul>
                                <?php endif; ?>
                            </div>

                        </div>
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