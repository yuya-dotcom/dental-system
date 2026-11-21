<?php
// =============================================================
//  branches-records.php — Records › Branches (owner only)
//
//  List of clinic branches with status summary, today's open/closed
//  state, weekly schedule at a glance, search, filters, sorting and
//  pagination; View Details, Add / Edit and Schedule Exceptions modals.
//  Actions: api/branch_crud.php · Script: assets/js/branches.js
//  Styles:  assets/css/es-records.css (shared with Dentist Records)
// =============================================================
define('REQUIRED_ROLES', ['owner']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying
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
$searchCols   = ['branch_name', 'branch_code', 'address', 'contact_number'];
$filterStatus = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
// Open On: operating_days holds day numbers (0 = Sun … 6 = Sat)
$dayNames     = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];
$filterDay    = (isset($_GET['day']) && $_GET['day'] !== '' && isset($dayNames[(int)$_GET['day']])) ? (string)(int)$_GET['day'] : '';
// Location: the branch city (branch names are the city names used in the addresses)
// Wave 1 (parallel): location options + status counts (one light query) + navbar bell
$_locQ        = 'select=branch_name,status&order=branch_name.asc';
$_wave        = ['loc' => admin_q('branches', $_locQ)];
// Upcoming schedule exceptions (date overrides) for the "+N exceptions" note.
// The table may not exist yet (SQL file not run) — then no note is shown.
$_nowMnl      = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$_today       = $_nowMnl->format('Y-m-d');
$_todayDow    = (int)$_nowMnl->format('w');
$_excQ        = 'select=branch_id,exception_date,is_open,reason&exception_date=gte.' . $_today . '&order=exception_date.asc';
$_wave['exc'] = admin_q(BR_EXCEPTIONS_TABLE, $_excQ);
// Upcoming appointments still to be seen at each branch (for the per-branch counts)
$_apptQ       = 'select=branch_id&appointment_date=gte.' . $_today . '&status=not.in.(cancelled,completed,no_show)';
$_wave['appt'] = admin_q('appointments', $_apptQ);
if (($_GET['location'] ?? '') === '') {
    // No location filter requested → the list query doesn't depend on wave 1's options,
    // so fetch it in the same wave (same filters the code below builds)
    $_ex = listSearchFilter($search, $searchCols);
    if ($filterStatus) $_ex[] = 'status=eq.' . $filterStatus;
    if ($filterDay !== '') $_ex[] = 'operating_days=cs.' . rawurlencode('{' . $filterDay . '}');
    $_wave['list'] = admin_q('branches', implode('&', branchRecordsQuery($page, $sortOrder, $_ex)), ['Prefer: count=exact']);
}
admin_prefetch($_wave, ['navbar']);
$locRes       = admin_get('branches', $_locQ);
$_excRes      = admin_get(BR_EXCEPTIONS_TABLE, $_excQ);
$excReady     = !$_excRes['error'] && is_array($_excRes['data']);
$upcomingExc  = [];   // branch_id => list of upcoming override rows
foreach ($excReady ? $_excRes['data'] : [] as $_e) {
    $upcomingExc[(int)$_e['branch_id']][] = $_e;
}
$_apptRes     = admin_get('appointments', $_apptQ);
$upcoming     = null;   // branch_id => count (null when it can't be read)
if (!$_apptRes['error'] && is_array($_apptRes['data'])) {
    $upcoming = [];
    foreach ($_apptRes['data'] as $_a) { $k = (int)$_a['branch_id']; $upcoming[$k] = ($upcoming[$k] ?? 0) + 1; }
}
$locRows      = is_array($locRes['data']) ? $locRes['data'] : [];
$locations    = array_values(array_unique(array_filter(array_column($locRows, 'branch_name'))));
$filterLoc    = in_array($_GET['location'] ?? '', $locations, true) ? $_GET['location'] : '';

// Summary strip
$counts = ['all' => count($locRows), 'active' => 0, 'inactive' => 0];
foreach ($locRows as $r) {
    $s = strtolower((string)($r['status'] ?? 'active')) === 'active' ? 'active' : 'inactive';
    $counts[$s]++;
}
$excTotal = array_sum(array_map('count', $upcomingExc));

$extra        = listSearchFilter($search, $searchCols);
if ($filterStatus) $extra[] = 'status=eq.' . $filterStatus;
if ($filterDay !== '') $extra[] = 'operating_days=cs.' . rawurlencode('{' . $filterDay . '}');
if ($filterLoc)        $extra[] = 'branch_name=eq.' . rawurlencode($filterLoc);
// Sunday is day "0", which listActiveCount() would read as "no filter" → count it explicitly
$filtersOn    = listActiveCount([$search, $filterStatus, $filterDay !== '', $filterLoc, $sortKey !== 'name_asc']);

$data         = getBranchRecords($page, $sortOrder, $extra);
$branches_tbl = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];
$keep         = ['q' => $search, 'status' => $filterStatus, 'day' => $filterDay, 'location' => $filterLoc];
$baseUrl      = listUrl('branches-records.php', $keep + ['sort' => $sortKey === 'name_asc' ? '' : $sortKey]);
$sortBase     = listUrl('branches-records.php', $keep);
$statusUrl    = fn(string $st) => listUrl('branches-records.php', ['status' => $st] + $keep + ['sort' => $sortKey === 'name_asc' ? '' : $sortKey]);

$fmtTime = fn($t) => $t ? date('g:i A', strtotime($t)) : '';
$e       = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$dayShort = [1 => 'M', 2 => 'T', 3 => 'W', 4 => 'T', 5 => 'F', 6 => 'S', 0 => 'S'];
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
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
    <link rel="stylesheet" href="assets/css/es-admin.css?v=7">
    <link rel="stylesheet" href="assets/css/es-records.css?v=7">
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
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <button type="button" class="btn btn-primary btn-sm hdr-btn" onclick="openAddBranchModal()">
                                <i class="feather-plus me-1"></i> Add Branch
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
                                Branch Records <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> <?= $filtersOn ? 'found' : 'total' ?></span>
                                <span class="rec-card-sub">Clinic locations, opening hours and the days patients can book</span>
                            </h5>
                            <div class="card-header-action"><div class="card-header-btn">
                                <div data-bs-toggle="tooltip" title="Refresh"><a href="branches-records.php" class="avatar-text avatar-xs bg-warning"></a></div>
                                <div data-bs-toggle="tooltip" title="Maximize/Minimize"><a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a></div>
                            </div></div>
                        </div>

                        <!-- Summary — the status tiles filter the list -->
                        <nav class="rec-summary" aria-label="Branches summary">
                            <?php foreach ([
                                ['',         'All Branches', $counts['all'],      'feather-map',          ''],
                                ['active',   'Active',       $counts['active'],   'feather-check-circle', 'tone-green'],
                                ['inactive', 'Inactive',     $counts['inactive'], 'feather-slash',        'tone-red'],
                            ] as [$st, $label, $n, $icon, $tone]): $cur = $filterStatus === $st; ?>
                            <a href="<?= $e($statusUrl($st)) ?>" class="rec-stat <?= $tone ?><?= $cur ? ' is-current' : '' ?>"<?= $cur ? ' aria-current="true"' : '' ?>
                               title="<?= $st ? 'Show ' . strtolower($label) . ' branches only' : 'Show all statuses' ?>">
                                <span class="rec-stat-icon"><i class="<?= $icon ?>"></i></span>
                                <span class="rec-stat-body">
                                    <span class="rec-stat-value d-block"><?= number_format($n) ?></span>
                                    <span class="rec-stat-label d-block"><?= $label ?></span>
                                </span>
                            </a>
                            <?php endforeach; ?>
                            <div class="rec-stat tone-amber" title="Upcoming dates that differ from a branch's usual weekly schedule">
                                <span class="rec-stat-icon"><i class="feather-calendar"></i></span>
                                <span class="rec-stat-body">
                                    <span class="rec-stat-value d-block"><?= $excReady ? number_format($excTotal) : '—' ?></span>
                                    <span class="rec-stat-label d-block">Upcoming Exceptions</span>
                                </span>
                            </div>
                        </nav>

                        <div class="list-toolbar">
                            <?= listSearchControl($search, 'Name, code, address or contact') ?>
                            <?= listSelectControl('Open On', 'day', ['' => 'Any Day'] + $dayNames, $filterDay) ?>
                            <?= listSelectControl('Branches', 'location', ['' => 'All Branches'] + ($locations ? array_combine($locations, $locations) : []), $filterLoc) ?>
                            <?= listResetControl('branches-records.php', $filtersOn) ?>
                        </div>

                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive" style="overflow:visible;">
                                <table class="table rec-table">
                                    <thead><tr>
                                        <?= listTh('Code', 'code_asc', 'code_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Branch', 'name_asc', 'name_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Location & Contact', 'addr_asc', 'addr_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Weekly Schedule', 'open_asc', 'open_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Status', 'status_asc', 'status_desc', $sortKey, $sortBase) ?>
                                        <th class="rec-col-action"><span class="visually-hidden">Actions</span></th>
                                    </tr></thead>
                                    <tbody>
                                    <?php if (empty($branches_tbl)): ?>
                                        <tr><td colspan="6" class="p-0">
                                            <div class="rec-empty">
                                                <?php if ($filtersOn): ?>
                                                <div class="rec-empty-icon"><i class="feather-search"></i></div>
                                                <h6 class="rec-empty-title">No branches match your filters</h6>
                                                <p class="rec-empty-text">Try a different name or day, or clear the filters to see every branch.</p>
                                                <a href="branches-records.php" class="btn btn-light-brand btn-sm"><i class="feather-rotate-ccw me-1"></i> Clear Filters</a>
                                                <?php else: ?>
                                                <div class="rec-empty-icon"><i class="feather-map-pin"></i></div>
                                                <h6 class="rec-empty-title">No branches yet</h6>
                                                <p class="rec-empty-text">Add your first clinic location to start taking appointments there.</p>
                                                <button type="button" class="btn btn-primary btn-sm" onclick="openAddBranchModal()"><i class="feather-plus me-1"></i> Add Branch</button>
                                                <?php endif; ?>
                                            </div>
                                        </td></tr>
                                    <?php else: ?>
                                        <?php foreach ($branches_tbl as $b):
                                            $bid       = (int)$b['branch_id'];
                                            $o         = $b['open_time'] ?? null; $c = $b['close_time'] ?? null;
                                            $days      = branchOperatingDays($b);
                                            $daysLabel = formatOperatingDays($days);
                                            $hoursText = ($o && $c) ? $fmtTime($o) . ' – ' . $fmtTime($c) : '';
                                            $status    = strtolower((string)($b['status'] ?? 'active')) === 'active' ? 'active' : 'inactive';
                                            $ux        = $upcomingExc[$bid] ?? [];
                                            // Today: an exception for today wins over the weekly schedule
                                            $todayExc  = ($ux && $ux[0]['exception_date'] === $_today) ? $ux[0] : null;
                                            $openToday = $todayExc ? (bool)$todayExc['is_open'] : in_array($_todayDow, $days, true);
                                            if ($status !== 'active')  $todayText = 'Not accepting bookings';
                                            elseif ($openToday)        $todayText = 'Open today' . ($hoursText ? ' · ' . $hoursText : '');
                                            else                       $todayText = 'Closed today' . ($todayExc && !empty($todayExc['reason']) ? ' · ' . $todayExc['reason'] : '');
                                            // Short form for the table (hours are already in the Schedule column)
                                            $todayShort = $status !== 'active' ? 'Not accepting bookings' : ($openToday ? 'Open today' : 'Closed today');
                                            $row = [
                                                'branch_id'      => $bid,
                                                'branch_code'    => (string)($b['branch_code'] ?? ''),
                                                'branch_name'    => (string)($b['branch_name'] ?? ''),
                                                'address'        => (string)($b['address'] ?? ''),
                                                'street'         => (string)($b['street'] ?? ''),
                                                'province'       => (string)($b['province'] ?? ''),
                                                'city'           => (string)($b['city'] ?? ''),
                                                'barangay'       => (string)($b['barangay'] ?? ''),
                                                'zip_code'       => (string)($b['zip_code'] ?? ''),
                                                'contact_number' => (string)($b['contact_number'] ?? ''),
                                                'open_time'      => (string)($o ?? ''),
                                                'close_time'     => (string)($c ?? ''),
                                                'operating_days' => $days,
                                                'days_label'     => $daysLabel,
                                                'hours_label'    => $hoursText,
                                                'status'         => $status,
                                                'today_label'    => $todayText,
                                                'exc_ready'      => $excReady,
                                                'exceptions'     => array_map(fn($x) => ['date' => $x['exception_date'], 'is_open' => (bool)$x['is_open'], 'reason' => (string)($x['reason'] ?? '')], array_slice($ux, 0, 6)),
                                                'exc_count'      => count($ux),
                                                'upcoming'       => $upcoming === null ? null : ($upcoming[$bid] ?? 0),
                                                'created_at'     => $b['created_at'] ?? '',
                                                'updated_at'     => $b['updated_at'] ?? '',
                                            ];
                                        ?>
                                        <tr class="rec-row" data-branch="<?= $e(json_encode($row)) ?>">
                                            <td><span class="rec-code"><?= $e($row['branch_code'] ?: '—') ?></span></td>
                                            <td>
                                                <div class="rec-entity">
                                                    <span class="rec-tile<?= $status !== 'active' ? ' is-muted' : '' ?>" aria-hidden="true"><i class="feather-map-pin"></i></span>
                                                    <div class="rec-entity-text">
                                                        <button type="button" class="rec-entity-name" onclick="viewBranch(brnRow(this))"><?= $e($row['branch_name'] ?: '—') ?></button>
                                                        <span class="rec-entity-sub"><?= $e($todayShort) ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($row['address'] !== ''): ?><span class="rec-address" title="<?= $e($row['address']) ?>"><?= $e($row['address']) ?></span><?php else: ?><span class="rec-muted">No address set</span><?php endif; ?>
                                                <span class="rec-icon-text rec-sub-line"><i class="feather-phone"></i><?= $row['contact_number'] !== '' ? $e($row['contact_number']) : '<span class="rec-muted">No contact number</span>' ?></span>
                                            </td>
                                            <td>
                                                <span class="rec-days" role="img" aria-label="Open <?= $e($daysLabel) ?>" title="Open <?= $e($daysLabel) ?>">
                                                    <?php foreach ($dayShort as $dn => $dl): ?><span class="rec-day<?= in_array($dn, $days, true) ? ' on' : '' ?>"><?= $dl ?></span><?php endforeach; ?>
                                                </span>
                                                <span class="rec-sched-hours"><?= $hoursText ? $e($hoursText) : 'Hours not set' ?></span>
                                                <?php if ($ux): $_next = $ux[0]; ?>
                                                <span class="rec-exc-note" title="Dates that differ from the usual schedule">
                                                    <i class="feather-calendar"></i><?= count($ux) ?> exception<?= count($ux) === 1 ? '' : 's' ?> · next <?= date('M j', strtotime($_next['exception_date'])) ?> <?= $_next['is_open'] ? 'open' : 'closed' ?>
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="rec-status st-<?= $status ?>"><?= ucfirst($status) ?></span>
                                                <?php if (!empty($row['upcoming'])): ?><span class="rec-sub-line rec-upcoming<?= $status !== 'active' ? ' is-warn' : '' ?>" title="Upcoming appointments at this branch"><i class="feather-calendar me-1"></i><?= (int)$row['upcoming'] ?> upcoming</span><?php endif; ?>
                                            </td>
                                            <td class="rec-col-action">
                                                <div class="dropdown">
                                                    <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-label="Actions for <?= $e($row['branch_name']) ?>"><i class="feather-more-vertical"></i></a>
                                                    <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                        <a href="javascript:void(0);" class="dropdown-item" onclick="viewBranch(brnRow(this))"><i class="feather-eye"></i> View Details</a>
                                                        <a href="javascript:void(0);" class="dropdown-item" onclick="openEditBranchModal(brnRow(this))"><i class="feather-edit-2"></i> Edit</a>
                                                        <a href="javascript:void(0);" class="dropdown-item" onclick="openBranchExceptions(brnRow(this))"><i class="feather-calendar"></i> Schedule Exceptions</a>
                                                        <div class="dropdown-divider"></div>
                                                        <a href="javascript:void(0);" class="dropdown-item text-danger" onclick="deleteBranch(brnRow(this))"><i class="feather-trash-2"></i> Delete</a>
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
                                <?php if ($totalRecords): ?>Showing <strong><?= $from ?>–<?= $to ?></strong> of <strong><?= $totalRecords ?></strong> branch<?= $totalRecords === 1 ? '' : 'es' ?><?php else: ?>No branches to show<?php endif; ?>
                                <?php if ($filtersOn): ?>&nbsp;·&nbsp;<a href="branches-records.php" class="text-danger"><i class="feather-x-circle me-1"></i>Clear filters</a><?php endif; ?>
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
    <div class="modal fade es-detail-modal" id="branchViewModal" tabindex="-1" aria-labelledby="bvName" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-record-dialog rec-detail-dialog">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-map-pin"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="bvName">Branch</h5>
                            <div class="apt-record-meta"><span class="apt-record-code" id="bvCode">—</span> · <span id="bvToday">—</span></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-record-body p-0">
                    <div class="ar-view-body">
                        <div class="ar-summary rec-summary-4">
                            <div class="ar-sum-item ar-sum-status">
                                <div class="ar-sum-label">Status</div>
                                <div id="bvStatus"></div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-calendar"></i></span>
                                <div class="ar-sum-text"><div class="ar-sum-label">Open Days</div><div class="ar-sum-value" id="bvDays">—</div></div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-clock"></i></span>
                                <div class="ar-sum-text"><div class="ar-sum-label">Hours</div><div class="ar-sum-value" id="bvHours">—</div></div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-users"></i></span>
                                <div class="ar-sum-text"><div class="ar-sum-label">Upcoming Appointments</div><div class="ar-sum-value" id="bvUpcoming">—</div></div>
                            </div>
                        </div>
                        <div class="ar-cards-grid">
                            <section class="ar-card">
                                <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-home"></i></span><h6 class="ar-card-title">Location &amp; Contact</h6></div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Address</span><span class="ar-card-value" id="bvAddress">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Contact number</span><span class="ar-card-value" id="bvContact">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Branch code</span><span class="ar-card-value" id="bvCode2">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Added</span><span class="ar-card-value" id="bvCreated">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Last updated</span><span class="ar-card-value" id="bvUpdated">—</span></div>
                                </div>
                            </section>
                            <section class="ar-card">
                                <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-clock"></i></span><h6 class="ar-card-title">Weekly Schedule</h6></div>
                                <div class="ar-card-fields"><ul class="rec-week" id="bvWeek"></ul></div>
                            </section>
                            <section class="ar-card ar-card-wide">
                                <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-calendar"></i></span><h6 class="ar-card-title">Upcoming Schedule Exceptions</h6></div>
                                <div class="ar-card-fields"><ul class="rec-exc-list" id="bvExc"></ul></div>
                            </section>
                        </div>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer">
                    <button type="button" class="btn btn-primary" id="bvEditBtn"><i class="feather-edit-2 me-1"></i> Edit Branch</button>
                    <button type="button" class="btn btn-light-brand" id="bvExcBtn"><i class="feather-calendar me-1"></i> Schedule Exceptions</button>
                    <span class="rec-foot-spacer"></span>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ ADD / EDIT ══════════════════════════════════════════ -->
    <div class="modal fade es-detail-modal" id="branchModal" tabindex="-1" aria-labelledby="brnModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-form-dialog rec-form-dialog">
            <form class="modal-content apt-record-content" id="branchForm" novalidate onsubmit="event.preventDefault(); saveBranch();">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-map-pin"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="brnModalTitle">Add Branch</h5>
                            <div class="apt-record-meta" id="brnModalSub">A branch code is assigned automatically.</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-form-body">
                    <div id="brnModalError" class="apt-form-alert" role="alert" style="display:none;"></div>
                    <input type="hidden" id="brn_id">
                    <div class="rec-form-stack">
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-home"></i></span><h6 class="ar-card-title">Branch Details</h6></div>
                            <div class="apt-form-card-body">
                                <div class="apt-field">
                                    <label class="apt-label" for="brn_name">Branch Name <span class="apt-req" aria-hidden="true">*</span></label>
                                    <input type="text" id="brn_name" class="form-control" maxlength="100" autocomplete="off" placeholder="e.g. Caloocan" required>
                                    <div class="apt-field-err" data-err-for="brn_name"></div>
                                    <div class="rec-help">Patients see this name when they book. Use the city or area name.</div>
                                </div>
                                <div class="apt-field">
                                    <label class="apt-label" for="brn_street">Building No. / House No. &amp; Street <span class="apt-req" aria-hidden="true">*</span></label>
                                    <input type="text" id="brn_street" class="form-control" maxlength="150" autocomplete="off" placeholder="e.g. 132 Vista Verde Ave." required>
                                    <div class="apt-field-err" data-err-for="brn_street"></div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="brn_province">Province <span class="apt-req" aria-hidden="true">*</span></label>
                                            <select id="brn_province" class="form-select js-custom-select" data-cs-float data-cs-search>
                                                <option value="">Loading provinces…</option>
                                            </select>
                                            <div class="apt-field-err" data-err-for="brn_province"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="brn_city">City / Municipality <span class="apt-req" aria-hidden="true">*</span></label>
                                            <select id="brn_city" class="form-select js-custom-select" data-cs-float data-cs-search>
                                                <option value="">Select a province first</option>
                                            </select>
                                            <div class="apt-field-err" data-err-for="brn_city"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="brn_barangay">Barangay <span class="apt-req" aria-hidden="true">*</span></label>
                                            <select id="brn_barangay" class="form-select js-custom-select" data-cs-float data-cs-search>
                                                <option value="">Select a city first</option>
                                            </select>
                                            <div class="apt-field-err" data-err-for="brn_barangay"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="brn_zip">ZIP Code <span class="apt-req" aria-hidden="true">*</span></label>
                                            <input type="text" id="brn_zip" class="form-control" maxlength="4" inputmode="numeric" autocomplete="off" placeholder="e.g. 1400" required>
                                            <div class="apt-field-err" data-err-for="brn_zip"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="rec-help mb-3" id="brnOldAddress" style="display:none;"></div>
                                <div class="apt-field mt-3">
                                    <label class="apt-label" for="brn_contact">Contact Number <span class="apt-req" aria-hidden="true">*</span></label>
                                    <div class="rec-phone">
                                        <span class="rec-phone-prefix" aria-hidden="true">+63</span>
                                        <input type="tel" id="brn_contact" class="form-control js-rec-phone" inputmode="numeric" autocomplete="off" placeholder="9XX XXX XXXX" aria-label="Contact number after +63" required>
                                    </div>
                                    <div class="apt-field-err" data-err-for="brn_contact"></div>
                                    <div class="rec-help" id="brnOldContact" style="display:none;"></div>
                                </div>
                            </div>
                        </section>
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-clock"></i></span><h6 class="ar-card-title">Operating Schedule</h6></div>
                            <div class="apt-form-card-body">
                                <div class="row g-3">
                                    <div class="col-6">
                                        <div class="apt-field">
                                            <label class="apt-label" for="brn_open">Opens At <span class="apt-req" aria-hidden="true">*</span></label>
                                            <select id="brn_open" class="form-select js-custom-select" data-cs-float data-cs-search>
                                                <option value="">Select time</option>
                                                <?php for ($m = 5 * 60; $m <= 22 * 60; $m += 30): $tv = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); ?><option value="<?= $tv ?>"><?= date('g:i A', strtotime($tv)) ?></option><?php endfor; ?>
                                            </select>
                                            <div class="apt-field-err" data-err-for="brn_open"></div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="apt-field">
                                            <label class="apt-label" for="brn_close">Closes At <span class="apt-req" aria-hidden="true">*</span></label>
                                            <select id="brn_close" class="form-select js-custom-select" data-cs-float data-cs-search>
                                                <option value="">Select time</option>
                                                <?php for ($m = 5 * 60; $m <= 22 * 60; $m += 30): $tv = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); ?><option value="<?= $tv ?>"><?= date('g:i A', strtotime($tv)) ?></option><?php endfor; ?>
                                            </select>
                                            <div class="apt-field-err" data-err-for="brn_close"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="apt-field">
                                    <span class="apt-label" id="brnDaysLbl">Open Days <span class="apt-req" aria-hidden="true">*</span>
                                        <button type="button" class="rec-info" data-bs-title="Patients can only book on the days and hours set here. For a one-off change (a holiday, an extra Sunday), use Schedule Exceptions instead." aria-label="About open days: patients can only book on the days and hours set here. For a one-off change, use Schedule Exceptions instead."><i class="feather-info"></i></button>
                                    </span>
                                    <div class="rec-daypick" id="brn_days" role="group" aria-labelledby="brnDaysLbl">
                                        <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'] as $dNum => $dName): ?>
                                        <input type="checkbox" class="brn-day" id="brn_day_<?= $dNum ?>" value="<?= $dNum ?>" autocomplete="off">
                                        <label for="brn_day_<?= $dNum ?>"><?= $dName ?></label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="rec-presets" aria-label="Quick choices">
                                        <span>Quick pick:</span>
                                        <button type="button" data-days="1,2,3,4,5">Mon–Fri</button>
                                        <button type="button" data-days="1,2,3,4,5,6">Mon–Sat</button>
                                        <button type="button" data-days="0,1,2,3,4,5,6">Every day</button>
                                    </div>
                                    <div class="apt-field-err" data-err-for="brn_days"></div>
                                    <div class="rec-sched-preview" id="brnSchedPreview" aria-live="polite"><i class="feather-info"></i><span></span></div>
                                </div>
                            </div>
                        </section>
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-toggle-right"></i></span><h6 class="ar-card-title">Status</h6></div>
                            <div class="apt-form-card-body">
                                <div class="rec-seg" style="--rec-seg-cols:2" role="radiogroup" aria-label="Branch status">
                                    <input type="radio" name="brn_status" id="brn_st_active" value="active">
                                    <label for="brn_st_active" class="dot-green"><span class="rec-seg-title">Active</span><span class="rec-seg-desc">Open for appointments and online booking</span></label>
                                    <input type="radio" name="brn_status" id="brn_st_inactive" value="inactive">
                                    <label for="brn_st_inactive" class="dot-slate"><span class="rec-seg-title">Inactive</span><span class="rec-seg-desc">Not accepting online bookings; records are kept</span></label>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer apt-form-footer">
                    <span class="apt-form-hint"><span class="apt-req">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4" id="brnSaveBtn"><i class="feather-save me-1"></i> Save Branch</button>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ══ SCHEDULE EXCEPTIONS (dates that differ from the usual weekly schedule) ══ -->
    <div class="modal fade es-detail-modal" id="branchExcModal" tabindex="-1" aria-labelledby="bxTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-form-dialog rec-form-dialog">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-calendar"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="bxTitle">Schedule Exceptions — <span id="bxBranchName"></span></h5>
                            <div class="apt-record-meta">Usual schedule: <span id="bxUsual" class="fw-semibold"></span></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-form-body">
                    <div id="bxError" class="apt-form-alert" role="alert" style="display:none;"></div>
                    <input type="hidden" id="bx_branch_id">
                    <div class="rec-form-stack">
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-plus-circle"></i></span><h6 class="ar-card-title">Add an Exception</h6></div>
                            <div class="apt-form-card-body">
                                <p class="rec-help mt-0 mb-3">Add a date only when it differs from the usual schedule — for example a holiday closing, or opening on a Sunday.</p>
                                <div class="rec-exc-form">
                                    <div class="apt-field mb-0">
                                        <label class="apt-label" for="bx_date">Date <span class="apt-req" aria-hidden="true">*</span></label>
                                        <input type="date" id="bx_date" class="form-control js-date-picker" placeholder="Select date">
                                        <div class="rec-help" id="bxDateHint">&nbsp;</div>
                                    </div>
                                    <div class="apt-field mb-0">
                                        <span class="apt-label" id="bxOpenLbl">The branch will be <span class="apt-req" aria-hidden="true">*</span></span>
                                        <div class="rec-seg" role="radiogroup" aria-labelledby="bxOpenLbl">
                                            <input type="radio" name="bx_open" id="bx_open_1" value="1" autocomplete="off">
                                            <label for="bx_open_1" class="dot-green"><span class="rec-seg-title">Open</span></label>
                                            <input type="radio" name="bx_open" id="bx_open_0" value="0" autocomplete="off">
                                            <label for="bx_open_0" class="dot-red"><span class="rec-seg-title">Closed</span></label>
                                        </div>
                                    </div>
                                    <div class="apt-field mb-0 rec-exc-reason-field">
                                        <label class="apt-label" for="bx_reason">Reason <span class="apt-req" aria-hidden="true">*</span></label>
                                        <input type="text" id="bx_reason" class="form-control" maxlength="200" placeholder="e.g. Holiday, swapped with Sunday" required>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-list"></i></span><h6 class="ar-card-title">Exceptions</h6></div>
                            <div id="bxList"></div>
                        </section>
                    </div>
                    <div class="rec-help mt-2">Past dates are kept: the predictive analytics uses them to know which days the branch was really open.</div>
                </div>
                <div class="modal-footer apt-record-footer apt-form-footer">
                    <span class="apt-form-hint"><span class="apt-req">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary px-4" id="bxSaveBtn" onclick="saveBranchException()"><i class="feather-save me-1"></i> Save Exception</button>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </div>
            </div>
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
    <script>window.BRN_TODAY = <?= json_encode(['date' => $_today, 'dow' => $_todayDow]) ?>;</script>
    <script src="assets/js/es-records.js?v=3"></script>
    <script src="assets/js/ph-address.js?v=1"></script>
    <script src="assets/js/branches.js?v=6"></script>
    <script>
    function applyFilters() { window._applyFilters('branches-records.php', {}); }
    </script>
</body>
</html>
