<?php
// =============================================================
//  accounts-records.php — Records › Accounts (owner only)
//
//  Staff sign-in accounts (owner / admin / dentist) with a role
//  summary, search, filters, sorting and pagination; View Details,
//  Add/Edit and Reset Password modals.
//  A "Dentist" account also creates/updates its dentist record
//  (api/user_crud.php → dentists table).
//  Actions: api/user_crud.php · Script: assets/js/accounts.js
//  Styles:  assets/css/es-records.css (shared with Dentist/Branch Records)
// =============================================================
define('REQUIRED_ROLES', ['owner']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/dbconfig.php';
require_once __DIR__ . '/controllers/list_filters.php';
require_once __DIR__ . '/controllers/module_access.php';
require_once __DIR__ . '/controllers/staff_names.php';   // STAFF_GENDERS

const USR_PER_PAGE = 10;
const USR_ROLES    = ['owner', 'admin', 'dentist'];

$filterRole   = in_array($_GET['role'] ?? '', USR_ROLES, true) ? $_GET['role'] : '';
$filterStatus = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$filterBranch = max(0, (int)($_GET['branch'] ?? 0));
$search       = listSearch();
$sortOptions  = [
    'code_asc'    => ['User Code (Default)', 'user_code.asc'],
    'name_asc'    => ['Name (A–Z)',          'full_name.asc,user_id.asc'],
    'name_desc'   => ['Name (Z–A)',          'full_name.desc,user_id.desc'],
    'user_asc'    => ['Username (A–Z)',      'username.asc.nullslast,user_id.asc'],
    'user_desc'   => ['Username (Z–A)',      'username.desc.nullslast,user_id.desc'],
    'role_asc'    => ['Role (A–Z)',          'role.asc,full_name.asc'],
    'role_desc'   => ['Role (Z–A)',          'role.desc,full_name.asc'],
    'branch_asc'  => ['Branch (A–Z)',        'branches(branch_name).asc.nullslast,full_name.asc'],
    'branch_desc' => ['Branch (Z–A)',        'branches(branch_name).desc.nullslast,full_name.asc'],
    'status_asc'  => ['Status (A–Z)',        'status.asc,full_name.asc'],
    'status_desc' => ['Status (Z–A)',        'status.desc,full_name.asc'],
];
[$sortKey, $sortOrder] = listSort($sortOptions, 'code_asc');
$filtersOn    = listActiveCount([$filterRole, $filterStatus, $filterBranch, $search, $sortKey !== 'code_asc']);
$page         = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($page - 1) * USR_PER_PAGE;

$q = [
    'select=user_id,user_code,full_name,email,username,contact_number,gender,allowed_modules,role,branch_id,status,created_at,updated_at,branches(branch_name)',
    $sortOrder,
    'limit='  . USR_PER_PAGE,
    'offset=' . $offset,
];
if ($filterRole)   $q[] = 'role=eq.'   . urlencode($filterRole);
if ($filterStatus) $q[] = 'status=eq.' . urlencode($filterStatus);
if ($filterBranch) $q[] = 'branch_id=eq.' . $filterBranch;
array_push($q, ...listSearchFilter($search, ['full_name', 'email', 'username', 'user_code']));

// One parallel wave: list + role/status counts + branch dropdown + navbar bell
$_metaQ = 'select=role,status,branch_id';
admin_prefetch([
    'list' => admin_q('users', implode('&', $q), ['Prefer: count=exact']),
    'meta' => admin_q('users', $_metaQ),
]);
$result       = admin_get('users', implode('&', $q), ['Prefer: count=exact']);
if ($result['error']) {
    // contact_number / allowed_modules not in the database yet (SQL file not run) → list without them
    $q[0]   = str_replace(['contact_number,gender,allowed_modules,', ',contact_number,allowed_modules'], ['', ''], $q[0]);
    $result = supabase_request('users', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
}
$users        = is_array($result['data']) ? $result['data'] : [];
$cr           = $result['headers']['content-range'] ?? '';
$totalRecords = ($cr && str_contains($cr, '/')) ? (int)explode('/', $cr)[1] : count($users);
$totalPages   = max(1, (int)ceil($totalRecords / USR_PER_PAGE));
$from         = $totalRecords === 0 ? 0 : $offset + 1;
$to           = min($offset + USR_PER_PAGE, $totalRecords);
$keep         = ['role' => $filterRole, 'status' => $filterStatus, 'branch' => $filterBranch, 'q' => $search];
$sortParam    = ['sort' => $sortKey === 'code_asc' ? '' : $sortKey];
$baseUrl      = listUrl('accounts-records.php', $keep + $sortParam);
$sortBase     = listUrl('accounts-records.php', $keep);
$branches     = getAllBranches();
$branchName   = '';
foreach ($branches as $b) if ((int)$b['branch_id'] === $filterBranch) $branchName = $b['branch_name'];

// Role counts for the summary strip (follow the branch + status filters, not the role)
$metaRes = admin_get('users', $_metaQ);
$counts  = ['' => 0, 'owner' => 0, 'admin' => 0, 'dentist' => 0];
$inactiveByRole = ['' => 0, 'owner' => 0, 'admin' => 0, 'dentist' => 0];
foreach ((is_array($metaRes['data']) ? $metaRes['data'] : []) as $r) {
    if ($filterBranch && (int)($r['branch_id'] ?? 0) !== $filterBranch) continue;
    $st = ($r['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    if ($filterStatus && $st !== $filterStatus) continue;
    $role = (string)($r['role'] ?? '');
    $counts['']++;
    if (isset($counts[$role])) $counts[$role]++;
    if ($st === 'inactive') { $inactiveByRole['']++; if (isset($inactiveByRole[$role])) $inactiveByRole[$role]++; }
}

/** Summary tile link: same filters, a different role, back to page 1. */
$roleUrl = fn(string $role) => listUrl('accounts-records.php', ['role' => $role] + $keep + $sortParam);

const USR_ROLE_INFO = [
    'owner'   => ['Owner',   'feather-shield'],
    'admin'   => ['Admin',   'feather-briefcase'],
    'dentist' => ['Dentist', 'feather-activity'],
];

/** "Santos, Maria Cruz" or "Maria Santos" → "MS" */
function accInitials(string $name): string {
    $name = trim($name);
    if ($name === '') return 'U';
    if (str_contains($name, ',')) {
        [$last, $rest] = array_map('trim', explode(',', $name, 2));
        $first = preg_split('/\s+/', $rest, -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
        return mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1)) ?: 'U';
    }
    $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    $out   = mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '');
    return mb_strtoupper($out);
}
/** Stable avatar colour per name (c1–c6). */
function accTone(string $name): string { return 'c' . (crc32(mb_strtolower($name)) % 6 + 1); }
$e      = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$selfId = (int)($_SESSION['user_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Accounts</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
    <link rel="stylesheet" href="assets/css/es-admin.css?v=8">
    <link rel="stylesheet" href="assets/css/es-records.css?v=7">
</head>
<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>
    <main class="nxl-container">
        <div class="nxl-content">
            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">Accounts</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Records</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <button type="button" class="btn btn-primary btn-sm hdr-btn" onclick="openAddUserModal()">
                                <i class="feather-plus me-1"></i> Add Account
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="main-content">
                <div class="row"><div class="col-lg-12">
                    <div class="card stretch stretch-full">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                Account Records <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> <?= $filtersOn ? 'found' : 'total' ?></span>
                                <span class="rec-card-sub">Staff who can sign in to the admin portal<?= $branchName ? ' · ' . $e($branchName) : '' ?></span>
                            </h5>
                            <div class="card-header-action"><div class="card-header-btn">
                                <div data-bs-toggle="tooltip" title="Refresh"><a href="accounts-records.php" class="avatar-text avatar-xs bg-warning"></a></div>
                                <div data-bs-toggle="tooltip" title="Maximize/Minimize"><a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a></div>
                            </div></div>
                        </div>

                        <!-- Role summary — each tile filters the list -->
                        <nav class="rec-summary" aria-label="Accounts by role">
                            <?php foreach ([
                                ['',        'All Accounts', 'feather-users',     ''],
                                ['owner',   'Owners',       'feather-shield',    'tone-red'],
                                ['admin',   'Admins',       'feather-briefcase', ''],
                                ['dentist', 'Dentists',     'feather-activity',  'tone-green'],
                            ] as [$rl, $label, $icon, $tone]): $cur = $filterRole === $rl; ?>
                            <a href="<?= $e($roleUrl($rl)) ?>" class="rec-stat <?= $tone ?><?= $cur ? ' is-current' : '' ?>"<?= $cur ? ' aria-current="true"' : '' ?>
                               title="<?= $rl ? 'Show ' . $e(strtolower($label)) . ' only' : 'Show every role' ?>">
                                <span class="rec-stat-icon"><i class="<?= $icon ?>"></i></span>
                                <span class="rec-stat-body">
                                    <span class="rec-stat-value d-block"><?= number_format($counts[$rl]) ?></span>
                                    <span class="rec-stat-label d-block"><?= $label ?><?php if ($inactiveByRole[$rl] && $filterStatus !== 'inactive'): ?><span class="rec-stat-note"> · <?= $inactiveByRole[$rl] ?> inactive</span><?php endif; ?></span>
                                </span>
                            </a>
                            <?php endforeach; ?>
                        </nav>

                        <div class="list-toolbar">
                            <?= listSearchControl($search, 'Name, email, username or code') ?>
                            <input type="hidden" id="filterRole" value="<?= $e($filterRole) ?>">
                            <div class="list-field"><label class="list-label" for="filterStatus">Status</label>
                            <select id="filterStatus" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="">All Statuses</option>
                                <option value="active"   <?= $filterStatus==='active'   ? 'selected':'' ?>>Active</option>
                                <option value="inactive" <?= $filterStatus==='inactive' ? 'selected':'' ?>>Inactive</option>
                            </select></div>
                            <?= listSelectControl('Branch', 'branch', ['' => 'All Branches'] + array_column($branches, 'branch_name', 'branch_id'), (string)($filterBranch ?: '')) ?>
                            <?= listResetControl('accounts-records.php', $filtersOn) ?>
                        </div>

                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive" style="overflow:visible;">
                                <table class="table rec-table">
                                    <thead><tr>
                                        <?= listTh('Account', 'name_asc', 'name_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Username', 'user_asc', 'user_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Role', 'role_asc', 'role_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Branch', 'branch_asc', 'branch_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Status', 'status_asc', 'status_desc', $sortKey, $sortBase) ?>
                                        <th class="rec-col-action"><span class="visually-hidden">Actions</span></th>
                                    </tr></thead>
                                    <tbody>
                                    <?php if (empty($users)): ?>
                                        <tr><td colspan="6" class="p-0">
                                            <div class="rec-empty">
                                                <?php if ($filtersOn): ?>
                                                <div class="rec-empty-icon"><i class="feather-search"></i></div>
                                                <h6 class="rec-empty-title">No accounts match your filters</h6>
                                                <p class="rec-empty-text">Try a different name, username or role, or clear the filters to see every account.</p>
                                                <a href="accounts-records.php" class="btn btn-light-brand btn-sm"><i class="feather-rotate-ccw me-1"></i> Clear Filters</a>
                                                <?php else: ?>
                                                <div class="rec-empty-icon"><i class="feather-user-plus"></i></div>
                                                <h6 class="rec-empty-title">No accounts yet</h6>
                                                <p class="rec-empty-text">Create a sign-in for each admin and dentist who uses the portal.</p>
                                                <button type="button" class="btn btn-primary btn-sm" onclick="openAddUserModal()"><i class="feather-plus me-1"></i> Add Account</button>
                                                <?php endif; ?>
                                            </div>
                                        </td></tr>
                                    <?php else: ?>
                                        <?php foreach ($users as $u):
                                            $name   = (string)($u['full_name'] ?? '');
                                            $role   = in_array($u['role'] ?? '', USR_ROLES, true) ? $u['role'] : '';
                                            $status = ($u['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
                                            $row    = [
                                                'user_id'     => (int)$u['user_id'],
                                                'user_code'   => (string)($u['user_code'] ?? ''),
                                                'full_name'   => $name,
                                                'initials'    => accInitials($name),
                                                'tone'        => accTone($name),
                                                'email'       => (string)($u['email'] ?? ''),
                                                'contact_number' => (string)($u['contact_number'] ?? ''),
                                                'gender'         => (string)($u['gender'] ?? ''),
                                                'allowed_modules' => esParseModules($u['allowed_modules'] ?? null),
                                                'username'    => (string)($u['username'] ?? ''),
                                                'role'        => $role,
                                                'branch_id'   => $u['branch_id'] ?? '',
                                                'branch_name' => (string)($u['branches']['branch_name'] ?? ''),
                                                'status'      => $status,
                                                'is_self'     => (int)$u['user_id'] === $selfId,
                                                'created_at'  => $u['created_at'] ?? '',
                                                'updated_at'  => $u['updated_at'] ?? '',
                                            ];
                                            [$roleLabel, $roleIcon] = USR_ROLE_INFO[$role] ?? ['Unknown', 'feather-help-circle'];
                                        ?>
                                        <tr class="rec-row" data-account="<?= $e(json_encode($row)) ?>">
                                            <td>
                                                <div class="rec-entity">
                                                    <span class="rec-avatar <?= $row['tone'] ?><?= $status === 'inactive' ? ' is-muted' : '' ?>" aria-hidden="true"><?= $e($row['initials']) ?></span>
                                                    <div class="rec-entity-text">
                                                        <span class="d-inline-flex align-items-center gap-2 mw-100">
                                                            <button type="button" class="rec-entity-name" onclick="viewUserDetails(accRow(this))"><?= $e($name ?: '—') ?></button>
                                                            <?php if ($row['is_self']): ?><span class="rec-you">You</span><?php endif; ?>
                                                        </span>
                                                        <span class="rec-entity-sub"><?= $row['email'] !== '' ? '<i class="feather-mail me-1"></i>' . $e($row['email']) : 'No email on file' ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($row['username'] !== ''): ?><span class="rec-icon-text"><i class="feather-at-sign"></i><?= $e($row['username']) ?></span><?php else: ?><span class="rec-muted">Not set</span><?php endif; ?>
                                                <?php if ($row['user_code'] !== ''): ?><span class="rec-sub-line"><span class="rec-code"><?= $e($row['user_code']) ?></span></span><?php endif; ?>
                                            </td>
                                            <td><span class="rec-role role-<?= $e($role ?: 'unknown') ?>"><i class="<?= $roleIcon ?>"></i><?= $roleLabel ?></span></td>
                                            <td>
                                                <?php if ($role === 'owner'): ?><span class="rec-icon-text"><i class="feather-globe"></i>All Branches</span>
                                                <?php elseif ($row['branch_name'] !== ''): ?><span class="rec-icon-text"><i class="feather-map-pin"></i><?= $e($row['branch_name']) ?></span>
                                                <?php else: ?><span class="rec-muted rec-warn-text"><i class="feather-alert-circle me-1"></i>No branch</span><?php endif; ?>
                                            </td>
                                            <td><span class="rec-status st-<?= $status ?>"><?= $status === 'active' ? 'Active' : 'Inactive' ?></span></td>
                                            <td class="rec-col-action">
                                                <div class="dropdown">
                                                    <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-label="Actions for <?= $e($name) ?>"><i class="feather-more-vertical"></i></a>
                                                    <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                        <a href="javascript:void(0);" class="dropdown-item" onclick="viewUserDetails(accRow(this))"><i class="feather-eye"></i> View Details</a>
                                                        <a href="javascript:void(0);" class="dropdown-item" onclick="openEditUserModal(accRow(this))"><i class="feather-edit-2"></i> Edit</a>
                                                        <a href="javascript:void(0);" class="dropdown-item" onclick="openResetPasswordModal(accRow(this))"><i class="feather-key"></i> Reset Password</a>
                                                        <?php if (!$row['is_self']): ?>
                                                        <div class="dropdown-divider"></div>
                                                        <a href="javascript:void(0);" class="dropdown-item text-danger" onclick="deleteUser(accRow(this))"><i class="feather-trash-2"></i> Delete</a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <p class="rec-foot-count mb-0">
                                <?php if ($totalRecords): ?>Showing <strong><?= $from ?>–<?= $to ?></strong> of <strong><?= $totalRecords ?></strong> account<?= $totalRecords === 1 ? '' : 's' ?><?php else: ?>No accounts to show<?php endif; ?>
                                <?php if ($filtersOn): ?>&nbsp;·&nbsp;<a href="accounts-records.php" class="text-danger"><i class="feather-x-circle me-1"></i>Clear filters</a><?php endif; ?>
                            </p>
                            <?php if ($totalPages > 1): ?>
                            <ul class="list-unstyled d-flex align-items-center gap-1 mb-0 pagination-common-style" aria-label="Pages">
                                <li><?php if ($page>1): ?><a href="<?= $e($baseUrl) ?>&page=<?= $page-1 ?>" aria-label="Previous page"><i class="bi bi-arrow-left"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;" aria-disabled="true"><i class="bi bi-arrow-left"></i></a><?php endif; ?></li>
                                <?php $wS=max(1,$page-2);$wE=min($totalPages,$wS+4);$wS=max(1,$wE-4); ?>
                                <?php if ($wS>1): ?><li><a href="<?= $e($baseUrl) ?>&page=1">1</a></li><?php if ($wS>2): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><?php endif; ?>
                                <?php for ($pg=$wS;$pg<=$wE;$pg++): ?><li><a href="<?= $e($baseUrl) ?>&page=<?= $pg ?>" class="<?= $pg===$page?'active':'' ?>"<?= $pg===$page?' aria-current="page"':'' ?>><?= $pg ?></a></li><?php endfor; ?>
                                <?php if ($wE<$totalPages): ?><?php if ($wE<$totalPages-1): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><li><a href="<?= $e($baseUrl) ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a></li><?php endif; ?>
                                <li><?php if ($page<$totalPages): ?><a href="<?= $e($baseUrl) ?>&page=<?= $page+1 ?>" aria-label="Next page"><i class="bi bi-arrow-right"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;" aria-disabled="true"><i class="bi bi-arrow-right"></i></a><?php endif; ?></li>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div></div>
            </div>
        </div>
    </main>

    <!-- ══ VIEW DETAILS ════════════════════════════════════════ -->
    <div class="modal fade es-detail-modal" id="userViewModal" tabindex="-1" aria-labelledby="uvName" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-record-dialog rec-detail-dialog">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <span class="rec-avatar rec-head-avatar" id="uvAvatar" aria-hidden="true">U</span>
                        <div class="min-w-0">
                            <h5 class="apt-record-title mb-0" id="uvName">Account</h5>
                            <div class="apt-record-meta" id="uvMeta">—</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-record-body p-0">
                    <div class="ar-view-body">
                        <div class="ar-summary rec-summary-4">
                            <div class="ar-sum-item ar-sum-status">
                                <div class="ar-sum-label">Status</div>
                                <div id="uvStatus"></div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-shield"></i></span>
                                <div class="ar-sum-text"><div class="ar-sum-label">Role</div><div class="ar-sum-value" id="uvRole">—</div></div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-map-pin"></i></span>
                                <div class="ar-sum-text"><div class="ar-sum-label">Branch</div><div class="ar-sum-value" id="uvBranch">—</div></div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-hash"></i></span>
                                <div class="ar-sum-text"><div class="ar-sum-label">User Code</div><div class="ar-sum-value" id="uvCode">—</div></div>
                            </div>
                        </div>
                        <div class="ar-cards-grid">
                            <section class="ar-card">
                                <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-user"></i></span><h6 class="ar-card-title">Profile</h6></div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Full name</span><span class="ar-card-value ar-strong" id="uvFull">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Gender</span><span class="ar-card-value" id="uvGender">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Email</span><span class="ar-card-value" id="uvEmail">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Contact number</span><span class="ar-card-value" id="uvContact">—</span></div>
                                </div>
                            </section>
                            <section class="ar-card">
                                <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-key"></i></span><h6 class="ar-card-title">Sign-in</h6></div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Username</span><span class="ar-card-value" id="uvUsername">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Added</span><span class="ar-card-value" id="uvCreated">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Last updated</span><span class="ar-card-value" id="uvUpdated">—</span></div>
                                </div>
                            </section>
                        </div>
                        <div id="uvDentistNote" class="rec-note mx-3 mb-3" style="display:none;">
                            <i class="feather-info"></i>
                            <span>This account has a dentist record. Specialization, contact number and On Leave status are managed in <a href="dentist-records.php" data-es-full>Dentist Records</a>.</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer">
                    <button type="button" class="btn btn-primary" id="uvEditBtn"><i class="feather-edit-2 me-1"></i> Edit Account</button>
                    <button type="button" class="btn btn-light-brand" id="uvResetBtn"><i class="feather-key me-1"></i> Reset Password</button>
                    <span class="rec-foot-spacer"></span>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ ADD / EDIT ══════════════════════════════════════════ -->
    <div class="modal fade es-detail-modal" id="userModal" tabindex="-1" aria-labelledby="usrModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-form-dialog rec-form-dialog">
            <form class="modal-content apt-record-content" id="userForm" novalidate autocomplete="off" onsubmit="event.preventDefault(); saveUser();">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-user-plus" id="usrModalIcon"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="usrModalTitle">Add Account</h5>
                            <div class="apt-record-meta" id="usrModalSubtitle">Create a sign-in for a staff member.</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-form-body">
                    <div id="usrModalError" class="apt-form-alert" role="alert" style="display:none;"></div>
                    <div id="usrSelfNote" class="rec-note" style="display:none;">
                        <i class="feather-lock"></i>
                        <span>This is <strong>your</strong> account. You can't change your own role or deactivate yourself — ask another owner.</span>
                    </div>
                    <input type="hidden" id="usr_id">
                    <div class="rec-form-stack">
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-user"></i></span><h6 class="ar-card-title">Personal Details</h6></div>
                            <div class="apt-form-card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="usr_first_name">First Name <span class="apt-req" aria-hidden="true">*</span></label>
                                            <input type="text" id="usr_first_name" class="form-control" maxlength="50" placeholder="e.g. Maria" required>
                                            <div class="apt-field-err" data-err-for="usr_first_name"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="usr_middle_name">Middle Name <span class="apt-opt">(optional)</span></label>
                                            <input type="text" id="usr_middle_name" class="form-control" maxlength="50" placeholder="e.g. Cruz">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="usr_last_name">Last Name <span class="apt-req" aria-hidden="true">*</span></label>
                                            <input type="text" id="usr_last_name" class="form-control" maxlength="50" placeholder="e.g. Santos" required>
                                            <div class="apt-field-err" data-err-for="usr_last_name"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="usr_suffix">Suffix <span class="apt-opt">(optional)</span></label>
                                            <select id="usr_suffix" class="form-select js-custom-select" data-cs-float>
                                                <option value="">None</option>
                                                <option value="Jr.">Jr.</option>
                                                <option value="Sr.">Sr.</option>
                                                <option value="II">II</option>
                                                <option value="III">III</option>
                                                <option value="IV">IV</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="usr_gender">Gender <span class="apt-req" aria-hidden="true">*</span></label>
                                            <select id="usr_gender" class="form-select js-custom-select" data-cs-float>
                                                <option value="">Select Gender</option>
                                                <?php foreach (STAFF_GENDERS as $gv => $gl): ?><option value="<?= $e($gv) ?>"><?= $e($gl) ?></option><?php endforeach; ?>
                                            </select>
                                            <div class="apt-field-err" data-err-for="usr_gender"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-key"></i></span><h6 class="ar-card-title">Sign-in Details</h6></div>
                            <div class="apt-form-card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="usr_username">Username <span class="apt-req" aria-hidden="true">*</span></label>
                                            <input type="text" id="usr_username" class="form-control" maxlength="50" autocapitalize="off" spellcheck="false" placeholder="e.g. msantos" required>
                                            <div class="apt-field-err" data-err-for="usr_username"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="usr_email">Email <span class="apt-req" aria-hidden="true">*</span></label>
                                            <input type="email" id="usr_email" class="form-control" maxlength="120" placeholder="e.g. maria@clinic.com" required>
                                            <div class="apt-field-err" data-err-for="usr_email"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="usr_contact">Contact Number <span class="apt-req" aria-hidden="true">*</span></label>
                                            <div class="rec-phone">
                                                <span class="rec-phone-prefix" aria-hidden="true">+63</span>
                                                <input type="tel" id="usr_contact" class="form-control js-rec-phone" inputmode="numeric" autocomplete="off" placeholder="9XX XXX XXXX" aria-label="Contact number after +63" required>
                                            </div>
                                            <div class="apt-field-err" data-err-for="usr_contact"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6" id="usrBranchRow">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="usr_branch">Branch <span class="apt-req" aria-hidden="true">*</span></label>
                                            <select id="usr_branch" class="form-select js-custom-select" data-cs-float data-cs-search>
                                                <option value="">Select a branch</option>
                                                <?php foreach ($branches as $b): ?>
                                                <option value="<?= (int)$b['branch_id'] ?>"><?= $e($b['branch_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="apt-field-err" data-err-for="usr_branch"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6" id="usrOwnerHint" style="display:none;">
                                        <span class="apt-label">Branch</span>
                                        <div class="rec-help mt-0"><i class="feather-globe me-1"></i>Owners aren't tied to a branch — they can switch between all branches.</div>
                                    </div>
                                    <div class="col-md-6 usr-pw-field">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="usr_password">Password <span class="apt-req" aria-hidden="true">*</span></label>
                                            <div class="rec-pw">
                                                <input type="password" id="usr_password" class="form-control" autocomplete="new-password" placeholder="At least 6 characters">
                                                <button type="button" class="rec-pw-toggle" onclick="togglePassword('usr_password', this)" aria-label="Show password"><i class="feather-eye"></i></button>
                                            </div>
                                            <div class="apt-field-err" data-err-for="usr_password"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 usr-pw-field">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="usr_confirm_password">Confirm Password <span class="apt-req" aria-hidden="true">*</span></label>
                                            <div class="rec-pw">
                                                <input type="password" id="usr_confirm_password" class="form-control" autocomplete="new-password" placeholder="Re-enter password">
                                                <button type="button" class="rec-pw-toggle" onclick="togglePassword('usr_confirm_password', this)" aria-label="Show password"><i class="feather-eye"></i></button>
                                            </div>
                                            <div class="apt-field-err" data-err-for="usr_confirm_password"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="rec-help mt-3" id="usrPwEditHint" style="display:none;">To change this account's password, use <strong>Reset Password</strong> from the row menu.</div>
                            </div>
                        </section>

                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-shield"></i></span><h6 class="ar-card-title">Role &amp; Access</h6></div>
                            <div class="apt-form-card-body">
                                <div class="apt-field">
                                    <span class="apt-label" id="usrRoleLbl">Role <span class="apt-req" aria-hidden="true">*</span></span>
                                    <div class="rec-seg" role="radiogroup" aria-labelledby="usrRoleLbl">
                                        <input type="radio" name="usr_role" id="usr_role_admin" value="admin">
                                        <label for="usr_role_admin" class="dot-blue"><span class="rec-seg-title">Admin</span><span class="rec-seg-desc">Runs the front desk of one branch</span></label>
                                        <input type="radio" name="usr_role" id="usr_role_dentist" value="dentist">
                                        <label for="usr_role_dentist" class="dot-green"><span class="rec-seg-title">Dentist</span><span class="rec-seg-desc">Sees their branch's patients and treatments</span></label>
                                        <input type="radio" name="usr_role" id="usr_role_owner" value="owner">
                                        <label for="usr_role_owner" class="dot-red"><span class="rec-seg-title">Owner</span><span class="rec-seg-desc">Full access to every branch and setting</span></label>
                                    </div>
                                </div>
                                <div class="apt-field" id="usrSpecializationRow" style="display:none;">
                                    <label class="apt-label" for="usr_specialization">Specialization <span class="apt-opt">(optional)</span></label>
                                    <input type="text" id="usr_specialization" class="form-control" maxlength="100" list="usrSpecList" placeholder="Choose or type">
                                    <datalist id="usrSpecList">
                                        <?php foreach (['General Dentistry', 'Orthodontics', 'Oral Surgery', 'Periodontics', 'Endodontics', 'Prosthodontics', 'Pediatric Dentistry', 'Oral Medicine', 'Oral Pathology'] as $s): ?>
                                        <option value="<?= $e($s) ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div id="usrDentistNote" class="rec-note" style="display:none;">
                                    <i class="feather-info"></i>
                                    <span id="usrDentistNoteText"></span>
                                </div>
                                <div class="apt-field mb-0">
                                    <span class="apt-label" id="usrStatusLbl">Status</span>
                                    <div class="rec-seg" style="--rec-seg-cols:2" role="radiogroup" aria-labelledby="usrStatusLbl">
                                        <input type="radio" name="usr_status" id="usr_st_active" value="active">
                                        <label for="usr_st_active" class="dot-green"><span class="rec-seg-title">Active</span><span class="rec-seg-desc">Can sign in</span></label>
                                        <input type="radio" name="usr_status" id="usr_st_inactive" value="inactive">
                                        <label for="usr_st_inactive" class="dot-slate"><span class="rec-seg-title">Inactive</span><span class="rec-seg-desc">Sign-in is blocked; history is kept</span></label>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Page access (admin / dentist): only ticked modules are shown in the sidebar and can be opened -->
                        <section class="ar-card" id="usrModulesCard" style="display:none;">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-sidebar"></i></span><h6 class="ar-card-title">Page Access</h6></div>
                            <div class="apt-form-card-body">
                                <p class="rec-help mt-0 mb-3">Tick the pages this account can see in the sidebar. Unticked pages are hidden <strong>and</strong> blocked if opened by link.</p>
                                <div class="rec-modpick" id="usr_modules" role="group" aria-label="Pages this account can open">
                                    <?php foreach (ES_MODULES as $mKey => [$mLabel, $mIcon, $mRoles]): ?>
                                    <input type="checkbox" class="usr-module" id="usr_mod_<?= $mKey ?>" value="<?= $mKey ?>" data-roles="<?= $e(implode(',', $mRoles)) ?>" autocomplete="off">
                                    <label for="usr_mod_<?= $mKey ?>"><i class="<?= $mIcon ?>"></i><span><?= $e($mLabel) ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="rec-presets"><button type="button" data-mods="all">Select all</button><button type="button" data-mods="none">Clear</button></div>
                                <div class="apt-field-err" data-err-for="usr_modules"></div>
                            </div>
                        </section>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer apt-form-footer">
                    <span class="apt-form-hint"><span class="apt-req">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4" id="usrSaveBtn"><i class="feather-save me-1"></i> Add Account</button>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ══ RESET PASSWORD ══════════════════════════════════════ -->
    <div class="modal fade es-detail-modal" id="resetPasswordModal" tabindex="-1" aria-labelledby="rpTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down apt-form-dialog rec-form-dialog rec-form-narrow">
            <form class="modal-content apt-record-content" novalidate onsubmit="event.preventDefault(); saveResetPassword();">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-key"></i></div>
                        <div class="min-w-0">
                            <h5 class="apt-record-title mb-0" id="rpTitle">Reset Password</h5>
                            <div class="apt-record-meta">For <strong id="resetPasswordFor"></strong></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-form-body">
                    <div id="resetPwError" class="apt-form-alert" role="alert" style="display:none;"></div>
                    <input type="hidden" id="reset_user_id">
                    <!-- Own account only: your current password (another account: the owner confirmed their password before this opened) -->
                    <div class="apt-field" id="rpCurrentRow" style="display:none;">
                        <label class="apt-label" for="reset_current_password">Current Password <span class="apt-req" aria-hidden="true">*</span></label>
                        <div class="rec-pw">
                            <input type="password" id="reset_current_password" class="form-control" autocomplete="current-password" placeholder="Your current password">
                            <button type="button" class="rec-pw-toggle" onclick="togglePassword('reset_current_password', this)" aria-label="Show password"><i class="feather-eye"></i></button>
                        </div>
                        <div class="apt-field-err" data-err-for="reset_current_password"></div>
                    </div>
                    <div class="apt-field">
                        <label class="apt-label" for="reset_new_password">New Password <span class="apt-req" aria-hidden="true">*</span></label>
                        <div class="rec-pw">
                            <input type="password" id="reset_new_password" class="form-control" autocomplete="new-password" placeholder="At least 6 characters">
                            <button type="button" class="rec-pw-toggle" onclick="togglePassword('reset_new_password', this)" aria-label="Show password"><i class="feather-eye"></i></button>
                        </div>
                        <div class="apt-field-err" data-err-for="reset_new_password"></div>
                    </div>
                    <div class="apt-field mb-0">
                        <label class="apt-label" for="reset_confirm_password">Confirm New Password <span class="apt-req" aria-hidden="true">*</span></label>
                        <div class="rec-pw">
                            <input type="password" id="reset_confirm_password" class="form-control" autocomplete="new-password" placeholder="Re-enter password">
                            <button type="button" class="rec-pw-toggle" onclick="togglePassword('reset_confirm_password', this)" aria-label="Show password"><i class="feather-eye"></i></button>
                        </div>
                        <div class="apt-field-err" data-err-for="reset_confirm_password"></div>
                        <div class="rec-help" id="rpHint">Share the new password with them privately. They can sign in with it right away.</div>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer apt-form-footer justify-content-end">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4" id="resetPwSaveBtn"><i class="feather-key me-1"></i> Reset Password</button>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/admin.js?v=7"></script>
    <script src="assets/js/es-swal.js?v=3"></script>
    <script src="assets/js/es-ajax.js?v=7"></script>
    <script src="assets/js/custom-select.js?v=3"></script>
    <script src="assets/js/es-records.js?v=3"></script>
    <script src="assets/js/accounts.js?v=7"></script>
    <script>
    function applyFilters() {
        window._applyFilters('accounts-records.php', { role: 'filterRole', status: 'filterStatus' });
    }
    </script>
</body>
</html>
