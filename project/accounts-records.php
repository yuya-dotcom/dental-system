<?php

define('REQUIRED_ROLES', ['owner']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/dbconfig.php';

const USR_PER_PAGE = 10;

$filterRole   = isset($_GET['role'])   ? trim($_GET['role'])   : '';
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($page - 1) * USR_PER_PAGE;

$q = [
    'select=user_id,user_code,full_name,email,username,role,branch_id,status,branches(branch_name)',
    'order=user_code.asc',
    'limit='  . USR_PER_PAGE,
    'offset=' . $offset,
];
if ($filterRole)   $q[] = 'role=eq.'   . urlencode($filterRole);
if ($filterStatus) $q[] = 'status=eq.' . urlencode($filterStatus);

$result       = supabase_request('users', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
$users        = is_array($result['data']) ? $result['data'] : [];
$cr           = $result['headers']['content-range'] ?? '';
$totalRecords = ($cr && str_contains($cr, '/')) ? (int)explode('/', $cr)[1] : count($users);
$totalPages   = max(1, (int)ceil($totalRecords / USR_PER_PAGE));
$from         = $totalRecords === 0 ? 0 : $offset + 1;
$to           = min($offset + USR_PER_PAGE, $totalRecords);
$baseUrl      = 'accounts-records.php?role=' . urlencode($filterRole) . '&status=' . urlencode($filterStatus);
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
                            <select id="filterRole" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:150px;">
                                <option value="">All Roles</option>
                                <option value="owner"   <?= $filterRole==='owner'   ? 'selected':'' ?>>Owner</option>
                                <option value="admin"   <?= $filterRole==='admin'   ? 'selected':'' ?>>Admin</option>
                                <option value="dentist" <?= $filterRole==='dentist' ? 'selected':'' ?>>Dentist</option>
                            </select>
                            <select id="filterStatus" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:150px;">
                                <option value="">All Statuses</option>
                                <option value="active"   <?= $filterStatus==='active'   ? 'selected':'' ?>>Active</option>
                                <option value="inactive" <?= $filterStatus==='inactive' ? 'selected':'' ?>>Inactive</option>
                            </select>
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
                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead><tr>
                                        <th>User Code</th><th>Full Name</th><th>Username</th>
                                        <th>Email</th><th>Role</th><th>Branch</th><th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php if (empty($users)): ?>
                                        <tr><td colspan="8" class="text-center py-4 text-muted"><i class="feather-users me-2"></i>No accounts found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($users as $u): ?>
                                        <?php $isSelf = ((int)($u['user_id'] ?? 0) === (int)($_SESSION['user_id'] ?? 0)); ?>
                                        <tr>
                                            <td><span class="fw-semibold"><?= htmlspecialchars($u['user_code'] ?? '—') ?></span></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="avatar-text avatar-sm bg-soft-primary text-primary rounded-circle"><?= strtoupper(substr($u['full_name'] ?? 'U', 0, 1)) ?></div>
                                                    <span><?= htmlspecialchars($u['full_name'] ?? '—') ?></span>
                                                    <?php if ($isSelf): ?><span class="badge bg-soft-warning text-warning ms-1">You</span><?php endif; ?>
                                                </div>
                                            </td>
                                            <td><i class="feather-user me-1 text-muted fs-12"></i><?= htmlspecialchars($u['username'] ?? '—') ?></td>
                                            <td class="text-muted small"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                                            <td><span class="badge <?= roleBadge($u['role'] ?? '') ?>"><?= ucfirst($u['role'] ?? '—') ?></span></td>
                                            <td><?= htmlspecialchars($u['branches']['branch_name'] ?? ($u['role']==='owner' ? 'All Branches' : '—')) ?></td>
                                            <td><span class="badge <?= ($u['status']??'active')==='active' ? 'bg-soft-success text-success' : 'bg-soft-danger text-danger' ?>"><?= ucfirst($u['status'] ?? 'active') ?></span></td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown"><i class="feather-more-vertical"></i></a>
                                                    <div class="dropdown-menu dropdown-menu-end action-dropdown">
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
                                <?php if ($filterRole || $filterStatus): ?>
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
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="usrModalTitle">Add Account</h5>
                        <p class="text-muted small mb-0" id="usrModalSubtitle">Create a new system user.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="usrModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="usr_id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
                            <input type="text" id="usr_name" class="form-control" placeholder="e.g. Maria Santos">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Username <span class="text-danger">*</span></label>
                            <input type="text" id="usr_username" class="form-control" placeholder="e.g. msantos">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Email <span class="text-danger">*</span></label>
                            <input type="email" id="usr_email" class="form-control" placeholder="e.g. m@clinic.com">
                        </div>
                        <div class="col-12" id="usrPasswordSection">
                            <label class="form-label fw-semibold small">Password <span class="text-danger">*</span></label>
                            <input type="password" id="usr_password" class="form-control" placeholder="Min. 6 characters">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Role <span class="text-danger">*</span></label>
                            <select id="usr_role" class="form-select" onchange="toggleBranchField()">
                                <option value="admin">Admin</option>
                                <option value="dentist">Dentist</option>
                                <option value="owner">Owner</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Status</label>
                            <select id="usr_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-12" id="usrBranchRow">
                            <label class="form-label fw-semibold small">Branch</label>
                            <select id="usr_branch" class="form-select">
                                <option value="">— Select Branch —</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <span class="text-muted small"><span class="text-danger">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary px-4" id="usrSaveBtn" onclick="saveUser()">
                            <i class="feather-save me-1"></i> Save
                        </button>
                    </div>
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
    <script src="assets/js/accounts.js"></script>
    <script>
    function applyFilters() {
        window._applyFilters('accounts-records.php', { role: 'filterRole', status: 'filterStatus' });
    }
    </script>
</body>
</html> 