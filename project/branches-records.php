<?php
define('REQUIRED_ROLES', ['owner']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/list_filters.php';

$page         = max(1, (int)($_GET['page'] ?? 1));

// Sort / search / status / date added (validated in list_filters.php)
$sortOptions  = [
    'name_asc'    => ['Name (A–Z)',              'branch_name.asc,branch_id.asc'],
    'name_desc'   => ['Name (Z–A)',              'branch_name.desc,branch_id.desc'],
    'code_asc'    => ['Code (A–Z)',              'branch_code.asc,branch_id.asc'],
    'code_desc'   => ['Code (Z–A)',              'branch_code.desc,branch_id.desc'],
    'addr_asc'    => ['Address (A–Z)',           'address.asc.nullslast,branch_name.asc'],
    'addr_desc'   => ['Address (Z–A)',           'address.desc.nullslast,branch_name.asc'],
    'open_asc'    => ['Opening Time (Earliest)', 'open_time.asc.nullslast,branch_name.asc'],
    'open_desc'   => ['Opening Time (Latest)',   'open_time.desc.nullslast,branch_name.asc'],
    'status_asc'  => ['Status (A–Z)',            'status.asc,branch_name.asc'],
    'status_desc' => ['Status (Z–A)',            'status.desc,branch_name.asc'],
];
[$sortKey, $sortOrder] = listSort($sortOptions, 'name_asc');
$search       = listSearch();
$filterStatus = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
// Open On: operating_days holds day numbers (0 = Sun … 6 = Sat)
$dayNames     = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];
$filterDay    = (isset($_GET['day']) && $_GET['day'] !== '' && isset($dayNames[(int)$_GET['day']])) ? (string)(int)$_GET['day'] : '';
// Location: the branch city (branch names are the city names used in the addresses)
$locRes       = supabase_request('branches', 'GET', [], 'select=branch_name&order=branch_name.asc');
$locations    = array_values(array_unique(array_filter(array_column(is_array($locRes['data']) ? $locRes['data'] : [], 'branch_name'))));
$filterLoc    = in_array($_GET['location'] ?? '', $locations, true) ? $_GET['location'] : '';
$extra        = listSearchFilter($search, ['branch_name', 'branch_code', 'address', 'contact_number']);
if ($filterStatus) $extra[] = 'status=eq.' . $filterStatus;
if ($filterDay !== '') $extra[] = 'operating_days=cs.' . rawurlencode('{' . $filterDay . '}');
if ($filterLoc)        $extra[] = 'branch_name=eq.' . rawurlencode($filterLoc);
$filtersOn    = listActiveCount([$search, $filterStatus, $filterDay, $filterLoc, $sortKey !== 'name_asc']);

$data         = getBranchRecords($page, $sortOrder, $extra);
$branches_tbl = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];
$baseUrl      = listUrl('branches-records.php', ['q' => $search, 'status' => $filterStatus, 'day' => $filterDay, 'location' => $filterLoc, 'sort' => $sortKey === 'name_asc' ? '' : $sortKey]);
$sortBase = listUrl('branches-records.php', ['q' => $search, 'status' => $filterStatus, 'day' => $filterDay, 'location' => $filterLoc]);
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Branches</title>
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
                    <div class="page-header-title"><h5 class="m-b-10">Branches</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Records</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <button class="btn btn-primary btn-sm" onclick="openAddBranchModal()">
                            <i class="feather-plus me-1"></i> Add Branch
                        </button>
                    </div>
                </div>
            </div>
            <div class="main-content">
                <div class="row"><div class="col-lg-12">
                    <div class="card stretch stretch-full">
                        <div class="card-header">
                            <h5 class="card-title">Branches Records <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span></h5>
                            <div class="card-header-action"><div class="card-header-btn">
                                <div data-bs-toggle="tooltip" title="Refresh"><a href="branches-records.php" class="avatar-text avatar-xs bg-warning"></a></div>
                                <div data-bs-toggle="tooltip" title="Maximize/Minimize"><a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a></div>
                            </div></div>
                        </div>
                        <div class="list-toolbar">
                            <?= listSearchControl($search, 'Name, code, address or contact') ?>
                            <?= listSelectControl('Status', 'status', ['' => 'All Statuses', 'active' => 'Active', 'inactive' => 'Inactive'], $filterStatus) ?>
                            <?= listSelectControl('Open On', 'day', ['' => 'Any Day'] + $dayNames, $filterDay) ?>
                            <?= listSelectControl('Location', 'location', ['' => 'All Locations'] + array_combine($locations, $locations), $filterLoc) ?>
                            <?= listResetControl('branches-records.php', $filtersOn) ?>
                        </div>
                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive" style="overflow:visible;">
                                <table class="table table-hover mb-0">
                                    <thead><tr>
                                        <?= listTh('Branch Code', 'code_asc', 'code_desc', $sortKey, $sortBase) ?><?= listTh('Branch Name', 'name_asc', 'name_desc', $sortKey, $sortBase) ?><?= listTh('Address', 'addr_asc', 'addr_desc', $sortKey, $sortBase) ?>
                                        <th>Contact</th><?= listTh('Operating Hours', 'open_asc', 'open_desc', $sortKey, $sortBase) ?><?= listTh('Status', 'status_asc', 'status_desc', $sortKey, $sortBase) ?>
                                        <th class="text-end">Action</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php if (empty($branches_tbl)): ?>
                                        <?= listEmptyRow(7, $filtersOn > 0, 'No branches found.', 'feather-map-pin', 'branches-records.php') ?>
                                    <?php else: ?>
                                        <?php foreach ($branches_tbl as $b): ?>
                                        <tr>
                                            <td><span class="fw-semibold"><?= htmlspecialchars($b['branch_code'] ?? '—') ?></span></td>
                                            <td><?= htmlspecialchars($b['branch_name'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($b['address'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($b['contact_number'] ?? '—') ?></td>
                                            <?php
                                                $o = $b['open_time'] ?? null; $c = $b['close_time'] ?? null;
                                                $days      = branchOperatingDays($b);
                                                $daysLabel = formatOperatingDays($days);
                                                $hoursText = ($o && $c) ? date('g:i A', strtotime($o)) . ' – ' . date('g:i A', strtotime($c)) : '—';
                                            ?>
                                            <td>
                                                <div class="fw-semibold small"><?= htmlspecialchars($daysLabel) ?></div>
                                                <div class="text-muted fs-12"><?= htmlspecialchars($hoursText) ?></div>
                                            </td>
                                            <td><span class="badge <?= branchStatusBadge($b['status']) ?>"><?= ucfirst($b['status'] ?? 'active') ?></span></td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed"><i class="feather-more-vertical"></i></a>
                                                    <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                           onclick='viewBranch(<?= json_encode(["branch_id"=>$b["branch_id"],"branch_code"=>$b["branch_code"]??"","branch_name"=>$b["branch_name"]??"","address"=>$b["address"]??"","contact_number"=>$b["contact_number"]??"","open_time"=>$b["open_time"]??"","close_time"=>$b["close_time"]??"","operating_days"=>$days,"days_label"=>$daysLabel,"status"=>$b["status"]??""],JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_APOS) ?>)'>
                                                           <i class="feather-eye"></i> View Details
                                                        </a>
                                                        <a href="javascript:void(0);" class="dropdown-item"
                                                           onclick='openEditBranchModal(<?= json_encode(["branch_id"=>$b["branch_id"],"branch_name"=>$b["branch_name"]??"","address"=>$b["address"]??"","contact_number"=>$b["contact_number"]??"","open_time"=>$b["open_time"]??"","close_time"=>$b["close_time"]??"","operating_days"=>$days,"status"=>$b["status"]??""],JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_APOS) ?>)'>
                                                           <i class="feather-edit-2"></i> Edit
                                                        </a>
                                                        <div class="dropdown-divider"></div>
                                                        <a href="javascript:void(0);" class="dropdown-item text-danger"
                                                           onclick="deleteBranch('<?= $b['branch_id'] ?>','<?= htmlspecialchars($b['branch_name']??'',ENT_QUOTES) ?>')">
                                                           <i class="feather-trash-2"></i> Delete
                                                        </a>
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
                            <p class="text-muted small mb-0">Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> branch(es)</p>
                            <?php if ($totalPages > 1): ?>
                            <ul class="list-unstyled d-flex align-items-center gap-1 mb-0 pagination-common-style">
                                <li><?php if ($page>1): ?><a href="<?= $baseUrl ?>&page=<?= $page-1 ?>"><i class="bi bi-arrow-left"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-left"></i></a><?php endif; ?></li>
                                <?php $wS=max(1,$page-2);$wE=min($totalPages,$wS+4);$wS=max(1,$wE-4);for($pg=$wS;$pg<=$wE;$pg++): ?><li><a href="<?= $baseUrl ?>&page=<?= $pg ?>" class="<?= $pg===$page?'active':'' ?>"><?= $pg ?></a></li><?php endfor; ?>
                                <li><?php if ($page<$totalPages): ?><a href="<?= $baseUrl ?>&page=<?= $page+1 ?>"><i class="bi bi-arrow-right"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-right"></i></a><?php endif; ?></li>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div></div>
            </div>
        </div>
    </main>

    <!-- VIEW MODAL -->
    <div class="modal fade" id="branchViewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold"><i class="feather-map-pin me-2 text-primary"></i>Branch Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-0">
                    <table class="table table-borderless mb-0 small">
                        <tr><th class="text-muted" style="width:140px;">Branch Code</th><td id="bv_code" class="fw-semibold"></td></tr>
                        <tr><th class="text-muted">Branch Name</th><td id="bv_name"></td></tr>
                        <tr><th class="text-muted">Address</th><td id="bv_address"></td></tr>
                        <tr><th class="text-muted">Contact</th><td id="bv_contact"></td></tr>
                        <tr><th class="text-muted">Open Days</th><td id="bv_days"></td></tr>
                        <tr><th class="text-muted">Hours</th><td id="bv_hours"></td></tr>
                        <tr><th class="text-muted">Status</th><td id="bv_status"></td></tr>
                    </table>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD/EDIT MODAL -->
    <div class="modal fade" id="branchModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="brnModalTitle">Add Branch</h5>
                        <p class="text-muted small mb-0">Fill in the branch details below.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="brnModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="brn_id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Branch Name <span class="text-danger">*</span></label>
                            <input type="text" id="brn_name" class="form-control" placeholder="e.g. Caloocan">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Address <span class="text-danger">*</span></label>
                            <input type="text" id="brn_address" class="form-control" placeholder="e.g. 132 Vista Verde Ave., Caloocan City, Metro Manila">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Contact Number <span class="text-danger">*</span></label>
                            <input type="text" id="brn_contact" class="form-control" placeholder="+63 2 XXXX XXXX">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Open Time <span class="text-danger">*</span></label>
                            <input type="time" id="brn_open" class="form-control" value="09:00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Close Time <span class="text-danger">*</span></label>
                            <input type="time" id="brn_close" class="form-control" value="17:00">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small mb-1">Operating Days <span class="text-danger">*</span></label>
                            <div class="d-flex flex-wrap gap-1" id="brn_days" role="group" aria-label="Operating days">
                                <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'] as $dNum => $dName): ?>
                                <input type="checkbox" class="btn-check brn-day" id="brn_day_<?= $dNum ?>" value="<?= $dNum ?>" autocomplete="off">
                                <label class="btn btn-sm btn-outline-primary px-3" for="brn_day_<?= $dNum ?>"><?= $dName ?></label>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">Patients can only book on the days selected here.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Status</label>
                            <select id="brn_status" class="form-select js-custom-select" data-cs-float>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <span class="text-muted small"><span class="text-danger">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary px-4" id="brnSaveBtn" onclick="saveBranch()">
                            <i class="feather-save me-1"></i> Save
                        </button>
                    </div>
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
    <script src="assets/js/branches.js"></script>
    <script>
    function applyFilters() { window._applyFilters('branches-records.php', {}); }
    </script>
</body>
</html>