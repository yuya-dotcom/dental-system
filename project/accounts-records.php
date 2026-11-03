<?php
// accounts-records.php

define('REQUIRED_ROLES', ['owner']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/dbconfig.php';
require_once __DIR__ . '/controllers/list_filters.php';

const USR_PER_PAGE = 10;

$filterRole   = in_array($_GET['role'] ?? '', ['owner', 'admin', 'dentist'], true) ? $_GET['role'] : '';
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
    'select=user_id,user_code,full_name,email,username,role,branch_id,status,created_at,branches(branch_name)',
    $sortOrder,
    'limit='  . USR_PER_PAGE,
    'offset=' . $offset,
];
if ($filterRole)   $q[] = 'role=eq.'   . urlencode($filterRole);
if ($filterStatus) $q[] = 'status=eq.' . urlencode($filterStatus);
if ($filterBranch) $q[] = 'branch_id=eq.' . $filterBranch;
array_push($q, ...listSearchFilter($search, ['full_name', 'email', 'username', 'user_code']));

$result       = supabase_request('users', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
$users        = is_array($result['data']) ? $result['data'] : [];
$cr           = $result['headers']['content-range'] ?? '';
$totalRecords = ($cr && str_contains($cr, '/')) ? (int)explode('/', $cr)[1] : count($users);
$totalPages   = max(1, (int)ceil($totalRecords / USR_PER_PAGE));
$from         = $totalRecords === 0 ? 0 : $offset + 1;
$to           = min($offset + USR_PER_PAGE, $totalRecords);
$baseUrl      = listUrl('accounts-records.php', ['role' => $filterRole, 'status' => $filterStatus, 'branch' => $filterBranch, 'q' => $search, 'sort' => $sortKey === 'code_asc' ? '' : $sortKey]);
$sortBase = listUrl('accounts-records.php', ['role' => $filterRole, 'status' => $filterStatus, 'branch' => $filterBranch, 'q' => $search]);
$branches     = getAllBranches();

function roleBadge(string $role): string {
    return match($role) {
        'owner'   => 'bg-soft-danger text-danger',
        'admin'   => 'bg-soft-primary text-primary',
        'dentist' => 'bg-soft-success text-success',
        default   => 'bg-soft-secondary text-secondary',
    };
}
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
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
</head>
<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>
    <main class="nxl-container">
        <div class="nxl-content">
            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">User Management</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Accounts</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <button class="btn btn-primary btn-sm hdr-btn" onclick="openAddUserModal()">
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
                            <h5 class="card-title">Accounts Records <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span></h5>
                            <div class="card-header-action"><div class="card-header-btn">
                                <div data-bs-toggle="tooltip" title="Refresh"><a href="accounts-records.php" class="avatar-text avatar-xs bg-warning"></a></div>
                                <div data-bs-toggle="tooltip" title="Maximize/Minimize"><a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a></div>
                            </div></div>
                        </div>
                        <div class="list-toolbar">
                            <?= listSearchControl($search, 'Name, email, username or code') ?>
                            <div class="list-field"><label class="list-label" for="filterRole">Role</label>
                            <select id="filterRole" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="">All Roles</option>
                                <option value="owner"   <?= $filterRole==='owner'   ? 'selected':'' ?>>Owner</option>
                                <option value="admin"   <?= $filterRole==='admin'   ? 'selected':'' ?>>Admin</option>
                                <option value="dentist" <?= $filterRole==='dentist' ? 'selected':'' ?>>Dentist</option>
                            </select></div>
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
                            <!-- acc-table-wrapper mirrors apt/trt/pat pattern -->
                            <div class="table-responsive acc-table-wrapper">
                                <table class="table table-hover mb-0 acc-accounts-table">
                                    <thead><tr>
                                        <?= listTh('Full Name', 'name_asc', 'name_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Username', 'user_asc', 'user_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Role', 'role_asc', 'role_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Branch', 'branch_asc', 'branch_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Status', 'status_asc', 'status_desc', $sortKey, $sortBase) ?>
                                        <th class="text-end">Action</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php if (empty($users)): ?>
                                        <?= listEmptyRow(6, $filtersOn > 0, 'No accounts found.', 'feather-users', 'accounts-records.php') ?>
                                    <?php else: ?>
                                        <?php foreach ($users as $u): ?>
                                        <?php $isSelf = ((int)($u['user_id'] ?? 0) === (int)($_SESSION['user_id'] ?? 0)); ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="avatar-text avatar-sm bg-soft-primary text-primary rounded-circle"><?= strtoupper(substr($u['full_name'] ?? 'U', 0, 1)) ?></div>
                                                    <span><?= htmlspecialchars($u['full_name'] ?? '—') ?></span>
                                                    <?php if ($isSelf): ?><span class="badge bg-soft-warning text-warning ms-1">You</span><?php endif; ?>
                                                </div>
                                            </td>
                                            <td><i class="feather-user me-1 text-muted fs-12"></i><?= htmlspecialchars($u['username'] ?? '—') ?></td>
                                            <td><span class="badge <?= roleBadge($u['role'] ?? '') ?>"><?= ucfirst($u['role'] ?? '—') ?></span></td>
                                            <td><?= htmlspecialchars($u['branches']['branch_name'] ?? ($u['role']==='owner' ? 'All Branches' : '—')) ?></td>
                                            <td><span class="badge <?= ($u['status']??'active')==='active' ? 'bg-soft-success text-success' : 'bg-soft-danger text-danger' ?>"><?= ucfirst($u['status'] ?? 'active') ?></span></td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed"><i class="feather-more-vertical"></i></a>
                                                    <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                           onclick='viewUserDetails(<?= json_encode(["user_id"=>$u["user_id"],"user_code"=>$u["user_code"]??"","full_name"=>$u["full_name"]??"","email"=>$u["email"]??"","username"=>$u["username"]??"","role"=>$u["role"]??"","branch_id"=>$u["branch_id"]??"","branch_name"=>$u["branches"]["branch_name"]??($u["role"]==="owner"?"All Branches":"—"),"status"=>$u["status"]??"active"],JSON_HEX_QUOT|JSON_HEX_TAG) ?>)'>
                                                           <i class="feather-eye"></i> View Details
                                                        </a>
                                                        <div class="dropdown-divider"></div>
                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                           onclick='openEditUserModal(<?= json_encode(["user_id"=>$u["user_id"],"full_name"=>$u["full_name"]??"","email"=>$u["email"]??"","username"=>$u["username"]??"","role"=>$u["role"]??"","branch_id"=>$u["branch_id"]??"","status"=>$u["status"]??"active"],JSON_HEX_QUOT|JSON_HEX_TAG) ?>)'>
                                                           <i class="feather-edit-2"></i> Edit
                                                        </a>
                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                           onclick="openResetPasswordModal('<?= $u['user_id'] ?>','<?= htmlspecialchars($u['full_name']??'',ENT_QUOTES) ?>')">
                                                           <i class="feather-key"></i> Reset Password
                                                        </a>
                                                        <?php if (!$isSelf): ?>
                                                        <div class="dropdown-divider"></div>
                                                        <a href="javascript:void(0);" class="dropdown-item text-danger"
                                                           onclick="deleteUser('<?= $u['user_id'] ?>','<?= htmlspecialchars($u['full_name']??'',ENT_QUOTES) ?>')">
                                                           <i class="feather-trash-2"></i> Delete
                                                        </a>
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
                            <p class="text-muted small mb-0">
                                Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> account(s)
                                <?php if ($filtersOn): ?>
                                    &nbsp;·&nbsp;<a href="accounts-records.php" class="text-danger"><i class="feather-x-circle me-1"></i>Clear filters</a>
                                <?php endif; ?>
                            </p>
                            <?php if ($totalPages > 1): ?>
                            <ul class="list-unstyled d-flex align-items-center gap-1 mb-0 pagination-common-style">
                                <li><?php if ($page>1): ?><a href="<?= $baseUrl ?>&page=<?= $page-1 ?>"><i class="bi bi-arrow-left"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-left"></i></a><?php endif; ?></li>
                                <?php $wS=max(1,$page-2);$wE=min($totalPages,$wS+4);$wS=max(1,$wE-4); ?>
                                <?php if ($wS>1): ?><li><a href="<?= $baseUrl ?>&page=1">1</a></li><?php if ($wS>2): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><?php endif; ?>
                                <?php for ($pg=$wS;$pg<=$wE;$pg++): ?><li><a href="<?= $baseUrl ?>&page=<?= $pg ?>" class="<?= $pg===$page?'active':'' ?>"><?= $pg ?></a></li><?php endfor; ?>
                                <?php if ($wE<$totalPages): ?><?php if ($wE<$totalPages-1): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><li><a href="<?= $baseUrl ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a></li><?php endif; ?>
                                <li><?php if ($page<$totalPages): ?><a href="<?= $baseUrl ?>&page=<?= $page+1 ?>"><i class="bi bi-arrow-right"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-right"></i></a><?php endif; ?></li>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div></div>
            </div>
        </div>
    </main>

<!-- ADD/EDIT MODAL -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">

            <div class="modal-header border-bottom px-4 py-3">
                <div>
                    <h5 class="modal-title fw-bold mb-0" id="usrModalTitle">Add Account</h5>
                    <p class="text-muted small mb-0 mt-1" id="usrModalSubtitle">Create a new system user.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body px-4 py-3">
                <div id="usrModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                <input type="hidden" id="usr_id">

                <!-- ── Name ── -->
                <p class="usr-section-label">Name</p>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label usr-field-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" id="usr_last_name" class="form-control form-control-sm" placeholder="e.g. Santos">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label usr-field-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" id="usr_first_name" class="form-control form-control-sm" placeholder="e.g. Maria">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label usr-field-label">Middle Name</label>
                        <input type="text" id="usr_middle_name" class="form-control form-control-sm" placeholder="e.g. Cruz">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label usr-field-label">Suffix</label>
                        <select id="usr_suffix" class="form-select form-select-sm js-custom-select" data-cs-float>
                            <option value="">None</option>
                            <option value="Jr.">Jr.</option>
                            <option value="Sr.">Sr.</option>
                            <option value="II">II</option>
                            <option value="III">III</option>
                            <option value="IV">IV</option>
                        </select>
                    </div>
                </div>

                <!-- ── Credentials ── -->
                <p class="usr-section-label">Credentials</p>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label usr-field-label">Username <span class="text-danger">*</span></label>
                        <input type="text" id="usr_username" class="form-control form-control-sm" placeholder="e.g. msantos">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label usr-field-label">Email</label>
                        <input type="email" id="usr_email" class="form-control form-control-sm" placeholder="e.g. m@clinic.com">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label usr-field-label">Status</label>
                        <select id="usr_status" class="form-select form-select-sm js-custom-select" data-cs-float>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="usrPasswordSection">
                        <label class="form-label usr-field-label">Password <span class="text-danger" id="usrPwRequired">*</span></label>
                        <div class="input-group input-group-sm">
                            <input type="password" id="usr_password" class="form-control form-control-sm" placeholder="Min. 6 characters">
                            <button type="button" class="btn btn-light border" onclick="togglePassword('usr_password', this)">
                                <i class="feather-eye" style="font-size:12px;"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6" id="usrConfirmPasswordSection">
                        <label class="form-label usr-field-label">Confirm Password <span class="text-danger" id="usrPwConfirmRequired">*</span></label>
                        <div class="input-group input-group-sm">
                            <input type="password" id="usr_confirm_password" class="form-control form-control-sm" placeholder="Re-enter password">
                            <button type="button" class="btn btn-light border" onclick="togglePassword('usr_confirm_password', this)">
                                <i class="feather-eye" style="font-size:12px;"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Role & Access ── -->
                <p class="usr-section-label">Role & Access</p>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label usr-field-label">Role <span class="text-danger">*</span></label>
                        <select id="usr_role" class="form-select form-select-sm js-custom-select" data-cs-float onchange="toggleBranchField()">
                            <option value="admin">Admin</option>
                            <option value="dentist">Dentist</option>
                            <option value="owner">Owner</option>
                        </select>
                    </div>
                    <div class="col-md-4" id="usrBranchRow">
                        <label class="form-label usr-field-label">Branch</label>
                        <select id="usr_branch" class="form-select form-select-sm js-custom-select" data-cs-float data-cs-search>
                            <option value=""> Select Branch </option>
                            <?php foreach ($branches as $b): ?>
                            <option value="<?= $b['branch_id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4" id="usrSpecializationRow" style="display:none;">
                        <label class="form-label usr-field-label">Specialization</label>
                        <select id="usr_specialization" class="form-select form-select-sm js-custom-select" data-cs-float data-cs-search>
                            <option value=""> Select Specialization </option>
                            <option value="General Dentistry">General Dentistry</option>
                            <option value="Orthodontics">Orthodontics</option>
                            <option value="Oral Surgery">Oral Surgery</option>
                            <option value="Periodontics">Periodontics</option>
                            <option value="Endodontics">Endodontics</option>
                            <option value="Prosthodontics">Prosthodontics</option>
                            <option value="Pediatric Dentistry">Pediatric Dentistry</option>
                            <option value="Oral Medicine">Oral Medicine</option>
                            <option value="Oral Pathology">Oral Pathology</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-top px-4 py-3 d-flex justify-content-between align-items-center">
                <span class="text-muted small"><span class="text-danger">*</span> Required</span>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm px-4" id="usrSaveBtn" onclick="saveUser()">
                        <i class="feather-save me-1"></i> Save Account
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- VIEW DETAILS MODAL -->
<div class="modal fade" id="userViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom px-4 py-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="avatar-text avatar-md bg-soft-primary text-primary rounded-circle fw-bold" id="uvModalAvatar">U</div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="uvModalName">—</h5>
                        <span class="badge mt-1" id="uvModalRoleBadge">—</span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <p class="usr-section-label">Account Information</p>
                <div class="usr-view-fields mb-3">
                    <div class="usr-view-row">
                        <span class="usr-view-label">User Code</span>
                        <span class="usr-view-value" id="uvUserCode">—</span>
                    </div>
                    <div class="usr-view-row">
                        <span class="usr-view-label">Full Name</span>
                        <span class="usr-view-value fw-semibold" id="uvFullName">—</span>
                    </div>
                    <div class="usr-view-row">
                        <span class="usr-view-label">Username</span>
                        <span class="usr-view-value" id="uvUsername">—</span>
                    </div>
                    <div class="usr-view-row">
                        <span class="usr-view-label">Email</span>
                        <span class="usr-view-value" id="uvEmail">—</span>
                    </div>
                </div>
                <p class="usr-section-label">Role & Access</p>
                <div class="usr-view-fields">
                    <div class="usr-view-row">
                        <span class="usr-view-label">Role</span>
                        <span class="usr-view-value" id="uvRole">—</span>
                    </div>
                    <div class="usr-view-row">
                        <span class="usr-view-label">Branch</span>
                        <span class="usr-view-value" id="uvBranch">—</span>
                    </div>
                    <div class="usr-view-row">
                        <span class="usr-view-label">Status</span>
                        <span class="usr-view-value" id="uvStatus">—</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top px-4 py-3">
                <button type="button" class="btn btn-light btn-sm px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

    <!-- RESET PASSWORD MODAL -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Reset Password</h5>
                        <p class="text-muted small mb-0">For: <strong id="resetPasswordFor"></strong></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="resetPwError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="reset_user_id">
                    <label class="form-label fw-semibold small">New Password</label>
                    <input type="password" id="reset_new_password" class="form-control" placeholder="Min. 6 characters">
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning px-4" id="resetPwSaveBtn" onclick="saveResetPassword()">
                        <i class="feather-key me-1"></i> Reset Password
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
    <script src="assets/js/accounts.js"></script>
    <script>
    function applyFilters() {
        window._applyFilters('accounts-records.php', { role: 'filterRole', status: 'filterStatus' });
    }

    // ── View Details ─────────────────────────────────────────────
    function viewUserDetails(u) {
        var name = u.full_name || '—';
        document.getElementById('uvModalAvatar').textContent  = name.charAt(0).toUpperCase();
        document.getElementById('uvModalName').textContent    = name;
        document.getElementById('uvUserCode').textContent     = u.user_code  || '—';
        document.getElementById('uvFullName').textContent     = name;
        document.getElementById('uvUsername').textContent     = u.username   || '—';
        document.getElementById('uvEmail').textContent        = u.email      || '—';
        document.getElementById('uvBranch').textContent       = u.branch_name || '—';

        var roleBadge = document.getElementById('uvModalRoleBadge');
        var roleClasses = {
            owner:   'bg-soft-danger text-danger',
            admin:   'bg-soft-primary text-primary',
            dentist: 'bg-soft-success text-success',
        };
        roleBadge.className = 'badge mt-1 ' + (roleClasses[u.role] || 'bg-soft-secondary text-secondary');
        roleBadge.textContent = u.role ? u.role.charAt(0).toUpperCase() + u.role.slice(1) : '—';
        document.getElementById('uvRole').innerHTML = roleBadge.outerHTML.replace(' mt-1', '');

        var st = u.status || 'active';
        document.getElementById('uvStatus').innerHTML =
            '<span class="badge ' + (st === 'active' ? 'bg-soft-success text-success' : 'bg-soft-danger text-danger') + '">' +
            st.charAt(0).toUpperCase() + st.slice(1) + '</span>';

        new bootstrap.Modal(document.getElementById('userViewModal')).show();
    }

    // ── Name helpers: split full_name into parts for edit modal ──
    function _splitName(full) {
        // Expect: "Last, First Middle Suffix" or plain "First Last"
        // We store names as entered; best-effort split on comma first.
        if (!full) return { last:'', first:'', middle:'', suffix:'' };
        var suffixes = ['Jr.','Sr.','II','III','IV'];
        var parts    = full.trim().split(/\s+/);
        var suffix   = '';
        if (parts.length > 1 && suffixes.includes(parts[parts.length - 1])) {
            suffix = parts.pop();
        }
        // If name contains comma → "Last, First Middle"
        var joined = parts.join(' ');
        if (joined.includes(',')) {
            var commaIdx = joined.indexOf(',');
            var last  = joined.slice(0, commaIdx).trim();
            var rest  = joined.slice(commaIdx + 1).trim().split(/\s+/);
            var first  = rest.shift() || '';
            var middle = rest.join(' ');
            return { last, first, middle, suffix };
        }
        // No comma → assume "First [Middle] Last"
        var last   = parts.pop() || '';
        var first  = parts.shift() || '';
        var middle = parts.join(' ');
        return { last, first, middle, suffix };
    }

    // ── Build full_name string from parts (called by saveUser in accounts.js) ──
    window._buildFullName = function() {
        var last   = document.getElementById('usr_last_name').value.trim();
        var first  = document.getElementById('usr_first_name').value.trim();
        var middle = document.getElementById('usr_middle_name').value.trim();
        var suffix = document.getElementById('usr_suffix').value.trim();
        var name   = (last + ', ' + first + (middle ? ' ' + middle : '') + (suffix ? ' ' + suffix : '')).trim();
        // Remove trailing comma if first is empty
        return name.replace(/^,\s*/, '').replace(/,\s*$/, '');
    };

    // ── Reset name fields on Add modal open ──────────────────────
    var _origOpenAddUserModal = window.openAddUserModal;
    window.openAddUserModal = function() {
        ['usr_last_name','usr_first_name','usr_middle_name'].forEach(function(id) {
            var el = document.getElementById(id); if (el) el.value = '';
        });
        var suf = document.getElementById('usr_suffix'); if (suf) suf.value = '';
        if (typeof _origOpenAddUserModal === 'function') _origOpenAddUserModal();
    };

    // ── Populate name fields on Edit modal open ──────────────────
    var _origOpenEditUserModal = window.openEditUserModal;
    window.openEditUserModal = function(u) {
        var parts = _splitName(u.full_name || '');
        var setVal = function(id, v) { var el = document.getElementById(id); if (el) el.value = v; };
        setVal('usr_last_name',   parts.last);
        setVal('usr_first_name',  parts.first);
        setVal('usr_middle_name', parts.middle);
        var suf = document.getElementById('usr_suffix');
        if (suf) suf.value = parts.suffix || '';
        if (typeof _origOpenEditUserModal === 'function') _origOpenEditUserModal(u);
    };
    </script>
</body>
</html>