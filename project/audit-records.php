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
require_once __DIR__ . '/controllers/list_filters.php';

requireLogin();

const LOGS_PER_PAGE = 20;

$currentRole   = $_SESSION['role']    ?? '';
$currentUserId = $_SESSION['user_id'] ?? null;
$isOwner       = $currentRole === 'owner';

$filterModule = trim($_GET['module'] ?? '');
$filterAction = trim($_GET['action'] ?? '');
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

if ($filterModule) $q[] = 'module=eq.'  . urlencode($filterModule);
if ($filterAction) $q[] = 'action=eq.'  . urlencode(strtoupper($filterAction));
array_push($q, ...listSearchFilter($search, ['username', 'entity_name', 'description']));
array_push($q, ...listDateRange('created_at', $dateFrom, $dateTo, true));
$filtersOn  = listActiveCount([$filterModule, $filterAction, $search, $dateFrom, $dateTo, $sortKey !== 'newest']);
$pageUrl    = listUrl('audit-records.php', ['module' => $filterModule, 'action' => $filterAction, 'q' => $search, 'from' => $dateFrom, 'to' => $dateTo, 'sort' => $sortKey === 'newest' ? '' : $sortKey]);
$sortBase = listUrl('audit-records.php', ['module' => $filterModule, 'action' => $filterAction, 'q' => $search, 'from' => $dateFrom, 'to' => $dateTo]);

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
    'branches', 'dentists', 'services', 'service-records', 'patient-accounts',
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
    <link rel="stylesheet" href="assets/css/custom.css?v=5">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <style>
        /* ── Audit-specific overrides ── */
        .audit-entity-id  { font-family: monospace; font-size: .78rem; }
        .module-badge     { font-size: .72rem; letter-spacing: .3px; }
        .action-badge     { font-size: .78rem; font-weight: 700; letter-spacing: .5px; min-width: 68px; text-align: center; }
        .user-role-badge  { font-size: .68rem; }

        /* View Details modal: shared .es-detail-modal styles (assets/css/custom.css) */
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

                            <div class="list-toolbar">
                                <?= listSearchControl($search, 'User, entity or description') ?>
                                <?= listDateRangeControl($dateFrom, $dateTo) ?>
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
                                </select></div>
                                <?= listResetControl('audit-records.php', $filtersOn) ?>
                            </div>

                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0" id="auditTable">
                                        <thead class="table-light">
                                            <tr>
                                                <?= listTh('Timestamp', 'newest', 'oldest', $sortKey, $sortBase, 'style="min-width:110px;"') ?>
                                                <?= listTh('User', 'user_asc', 'user_desc', $sortKey, $sortBase, 'style="min-width:140px;"') ?>
                                                <?php if ($isOwner): ?>
                                                <?= listTh('Branch', 'branch_asc', 'branch_desc', $sortKey, $sortBase, 'style="min-width:110px;"') ?>
                                                <?php endif; ?>
                                                <?= listTh('Module', 'module_asc', 'module_desc', $sortKey, $sortBase, 'style="min-width:150px;"') ?>
                                                <?= listTh('Action Type', 'action_asc', 'action_desc', $sortKey, $sortBase, 'style="min-width:90px;"') ?>
                                                <?= listTh('Entity', 'entity_asc', 'entity_desc', $sortKey, $sortBase, 'style="min-width:160px;"') ?>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($logs)): ?>
                                                <tr>
                                                    <td colspan="<?= $isOwner ? 7 : 6 ?>" class="text-center py-5">
                                                        <img src="assets/images/empty.png" alt="" style="width:80px;" class="mb-3 opacity-50">
                                                        <p class="text-muted mb-0"><?php if ($filtersOn): ?>No logs match the selected filters. <a href="audit-records.php" class="fw-semibold">Reset filters</a><?php else: ?>No activity logs recorded yet.<?php endif; ?></p>
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
                                                    $moduleLabel = ucwords(str_replace('-', ' ', $module));

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
                                                    // Before/after data, only if the log row carries it
                                                    $detail['action_class'] = actionBadgeClass($action);
                                                    $detail['description']  = $detail['description'] ?? ($log['description_old'] ?? null);
                                                    foreach (['before_data', 'after_data', 'old_values', 'new_values', 'old_data', 'new_data', 'before', 'after', 'changes'] as $k) {
                                                        if (isset($log[$k]) && $log[$k] !== '') $detail['changes'][$k] = $log[$k];
                                                    }
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

                                                    <!-- Action -->
                                                    <td class="text-end">
                                                        <div class="dropdown">
                                                            <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-label="Actions"><i class="feather-more-vertical"></i></a>
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
                                                <a href="<?= $pageUrl ?>&page=<?= $page - 1 ?>">
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
                                                <a href="<?= $pageUrl ?>&page=<?= $i ?>"
                                                    class="<?= ($i === $page) ? 'active' : '' ?>">
                                                    <?= $i ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <li>
                                            <?php if ($page >= $totalPages): ?>
                                                <span class="text-muted" style="opacity:.4;"><i class="bi bi-arrow-right"></i></span>
                                            <?php else: ?>
                                                <a href="<?= $pageUrl ?>&page=<?= $page + 1 ?>">
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

    <!-- VIEW DETAILS MODAL (shared .es-detail-modal design) -->
    <div class="modal fade es-detail-modal" id="auditViewModal" tabindex="-1" aria-labelledby="auditViewTitle" aria-hidden="true">
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
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
    <script>
    function applyFilters() {
        window._applyFilters('audit-records.php', { module: 'filterModule', action: 'filterAction' });
    }
    function resetFilters() {
        window.location.href = 'audit-records.php';
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
        const parse = v => {
            if (typeof v !== 'string') return v;
            try { return JSON.parse(v); } catch (e) { return v; }
        };
        const isObj = v => v && typeof v === 'object' && !Array.isArray(v);
        const pretty = v => (v === null || v === undefined || v === '') ? '—'
            : (typeof v === 'object' ? JSON.stringify(v, null, 2) : String(v));

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

        // Before / after data (only what the log row carries)
        const ch = log.changes || {};
        const pairs = [['before_data', 'after_data'], ['old_values', 'new_values'], ['old_data', 'new_data'], ['before', 'after']];
        const used = new Set();
        const changes = document.createDocumentFragment();
        pairs.forEach(([bk, ak]) => {
            if (!(bk in ch) && !(ak in ch)) return;
            used.add(bk); used.add(ak);
            const before = parse(ch[bk]), after = parse(ch[ak]);
            if (isObj(before) && isObj(after)) {
                // Field-by-field comparison; changed rows highlighted
                const wrap = el('div', 'table-responsive es-detail-table-wrap');
                const table = el('table', 'table table-sm mb-0 es-detail-table');
                const thead = el('thead'), hr = el('tr');
                ['Field', 'Before', 'After'].forEach(h => hr.append(el('th', '', h)));
                thead.append(hr);
                const tb = el('tbody');
                new Set([...Object.keys(before), ...Object.keys(after)]).forEach(k => {
                    const o = before[k], n = after[k];
                    const tr = el('tr', JSON.stringify(o) !== JSON.stringify(n) ? 'table-warning' : '');
                    const td = v => { const c = el('td'); const t = pretty(v); t.includes('\n') ? c.append(el('pre', 'es-json mb-0', t)) : (c.textContent = t); return c; };
                    tr.append(el('th', '', label(k)), td(o), td(n));
                    tb.append(tr);
                });
                table.append(thead, tb);
                wrap.append(table);
                changes.append(wrap);
            } else {
                [['Before', before], ['After', after]].forEach(([t, v]) => {
                    changes.append(el('div', 'es-json-label', t));
                    changes.append(has(v) || isObj(v) || Array.isArray(v) ? el('pre', 'es-json', pretty(v)) : el('div', 'es-json-empty', 'No data recorded.'));
                });
            }
        });
        Object.keys(ch).filter(k => !used.has(k)).forEach(k => {
            changes.append(el('div', 'es-json-label', label(k)));
            changes.append(el('pre', 'es-json', pretty(parse(ch[k]))));
        });
        grid.append(card('feather-git-commit', 'Before & After Data',
            changes.childNodes.length ? changes : el('p', 'es-json-empty', 'No before/after data was recorded for this entry.'), true));

        body.append(grid);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('auditViewModal')).show();
    }
    </script>
</body>
</html>