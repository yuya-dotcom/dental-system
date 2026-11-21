<?php
// =============================================================
//  dentist-records.php — Records › Dentists (owner only)
//
//  List of dentist records with status summary, search, filters,
//  sorting and pagination; View Details and Edit modals.
//  New dentists are created from Accounts (a "Dentist" account adds
//  its dentist record automatically — api/user_crud.php); there is no
//  Add Dentist here.
//  Name parts / age / gender / email need
//  database/proposed/2026-11-16_owner_records_eval2.sql — until it runs the
//  list falls back to the older columns (Email shows "Not set").
//  Actions: api/dentist_crud.php · Script: assets/js/dentists.js
//  Styles:  assets/css/es-records.css (shared with Branch Records)
// =============================================================
define('REQUIRED_ROLES', ['owner']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying
require_once __DIR__ . '/controllers/dentist_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/list_filters.php';
require_once __DIR__ . '/controllers/staff_names.php';

$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : getSessionBranch();
if (!isOwner() && $filterBranch !== getSessionBranch()) $filterBranch = getSessionBranch();
$filterStatus = in_array($_GET['status'] ?? '', DENT_STATUSES, true) ? $_GET['status'] : '';
$page         = max(1, (int)($_GET['page'] ?? 1));

// Sort / search / specialization (validated in list_filters.php)
$sortOptions  = [
    'name_asc'    => ['Name (A–Z)',           'full_name.asc,dentist_id.asc'],
    'name_desc'   => ['Name (Z–A)',           'full_name.desc,dentist_id.desc'],
    'branch_asc'  => ['Branch (A–Z)',         'branches(branch_name).asc.nullslast,full_name.asc'],
    'branch_desc' => ['Branch (Z–A)',         'branches(branch_name).desc.nullslast,full_name.asc'],
    'status_asc'  => ['Status (A–Z)',         'status.asc,full_name.asc'],
    'status_desc' => ['Status (Z–A)',         'status.desc,full_name.asc'],
];
[$sortKey, $sortOrder] = listSort($sortOptions, 'name_asc');
$search       = listSearch();
$searchCols   = ['full_name', 'email', 'contact_number'];

// Wave 1 (parallel): one light query over every dentist (status counts for the
// summary strip) + branch dropdown + navbar bell
$_metaQ       = 'select=status,branch_id';
$_wave        = ['meta' => admin_q('dentists', $_metaQ)];
// Upcoming appointments that still need a dentist (for the per-dentist counts)
$_today       = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$_apptQ       = 'select=dentist_id&dentist_id=not.is.null&appointment_date=gte.' . $_today . '&status=not.in.(cancelled,completed,no_show)';
$_wave['appt'] = admin_q('appointments', $_apptQ);
// The list query doesn't depend on wave 1, so fetch it in the same wave
$_wave['list'] = admin_q('dentists', implode('&', dentistRecordsQuery($page, $filterBranch, $filterStatus, $sortOrder, listSearchFilter($search, $searchCols))), ['Prefer: count=exact']);
admin_prefetch($_wave);
$branches     = getAllBranches();
$metaRes      = admin_get('dentists', $_metaQ);
$metaRows     = is_array($metaRes['data']) ? $metaRes['data'] : [];

// Status counts for the summary strip (follow the branch filter only)
$counts = ['all' => 0, 'active' => 0, 'on_leave' => 0, 'inactive' => 0];
foreach ($metaRows as $r) {
    if ($filterBranch > 0 && (int)($r['branch_id'] ?? 0) !== $filterBranch) continue;
    $s = strtolower((string)($r['status'] ?? 'active'));
    $counts['all']++;
    if (isset($counts[$s])) $counts[$s]++;
}

// dentist_id => upcoming appointment count (null map when it can't be read)
$_apptRes     = admin_get('appointments', $_apptQ);
$upcoming     = null;
if (!$_apptRes['error'] && is_array($_apptRes['data'])) {
    $upcoming = [];
    foreach ($_apptRes['data'] as $_a) { $k = (int)$_a['dentist_id']; $upcoming[$k] = ($upcoming[$k] ?? 0) + 1; }
}

$filtersOn    = listActiveCount([$filterStatus, isOwner() ? $filterBranch : 0, $search, $sortKey !== 'name_asc']);

// The email search column is new (SQL file) — without it, search the older columns
$data         = getDentistRecords($page, $filterBranch, $filterStatus, $sortOrder, listSearchFilter($search, $searchCols),
                                  listSearchFilter($search, ['full_name', 'contact_number']));
$dentists     = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];
$keep         = ['branch' => $filterBranch, 'status' => $filterStatus, 'q' => $search];
$baseUrl      = listUrl('dentist-records.php', $keep + ['sort' => $sortKey === 'name_asc' ? '' : $sortKey]);
$sortBase     = listUrl('dentist-records.php', $keep);
$branchName   = '';
foreach ($branches as $b) if ((int)$b['branch_id'] === $filterBranch) $branchName = $b['branch_name'];

/** Summary tile link: same filters, a different status, back to page 1. */
$statusUrl = fn(string $st) => listUrl('dentist-records.php', ['status' => $st] + $keep + ['sort' => $sortKey === 'name_asc' ? '' : $sortKey]);

/** "Dr. Maria Santos" → "MS" */
function dentInitials(string $name): string {
    $name  = trim(preg_replace('/^(dr|dra|doc)\.?\s+/i', '', $name));
    $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return 'D';
    $first = mb_substr($parts[0], 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}
/** Stable avatar colour per name (c1–c6). */
function dentTone(string $name): string { return 'c' . (crc32(mb_strtolower($name)) % 6 + 1); }
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Dentists</title>
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
                    <div class="page-header-title"><h5 class="m-b-10">Dentists</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Records</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <a href="accounts-records.php?role=dentist" class="btn btn-light-brand btn-sm hdr-btn"
                               data-bs-toggle="tooltip" title="Dentists who need to sign in are added as Dentist accounts in Accounts">
                                <i class="feather-key me-1"></i> Dentist Accounts
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="main-content">
                <div class="row"><div class="col-lg-12">
                    <div class="card stretch stretch-full">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                Dentist Records <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> <?= $filtersOn ? 'found' : 'total' ?></span>
                                <span class="rec-card-sub">Dentists who can be assigned to appointments and treatments<?= $branchName ? ' · ' . $e($branchName) : '' ?></span>
                            </h5>
                            <div class="card-header-action"><div class="card-header-btn">
                                <div data-bs-toggle="tooltip" title="Refresh"><a href="dentist-records.php" class="avatar-text avatar-xs bg-warning"></a></div>
                                <div data-bs-toggle="tooltip" title="Maximize/Minimize"><a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a></div>
                            </div></div>
                        </div>

                        <!-- Status summary — each tile filters the list -->
                        <nav class="rec-summary" aria-label="Dentists by status">
                            <?php foreach ([
                                ['',         'All Dentists', $counts['all'],      'feather-users',       ''],
                                ['active',   'Active',       $counts['active'],   'feather-user-check',  'tone-green'],
                                ['on_leave', 'On Leave',     $counts['on_leave'], 'feather-coffee',      'tone-amber'],
                                ['inactive', 'Inactive',     $counts['inactive'], 'feather-user-x',      'tone-red'],
                            ] as [$st, $label, $n, $icon, $tone]): $cur = $filterStatus === $st; ?>
                            <a href="<?= $e($statusUrl($st)) ?>" class="rec-stat <?= $tone ?><?= $cur ? ' is-current' : '' ?>"<?= $cur ? ' aria-current="true"' : '' ?>
                               title="<?= $st ? 'Show ' . $e(strtolower($label)) . ' dentists only' : 'Show all statuses' ?>">
                                <span class="rec-stat-icon"><i class="<?= $icon ?>"></i></span>
                                <span class="rec-stat-body">
                                    <span class="rec-stat-value d-block"><?= number_format($n) ?></span>
                                    <span class="rec-stat-label d-block"><?= $label ?></span>
                                </span>
                            </a>
                            <?php endforeach; ?>
                        </nav>

                        <div class="list-toolbar">
                            <?= listSearchControl($search, 'Name, email or contact') ?>
                            <?php if (isOwner()): ?>
                            <div class="list-field"><label class="list-label" for="filterBranch">Branch</label>
                            <select id="filterBranch" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= (int)$b['branch_id'] ?>" <?= (int)$b['branch_id']===$filterBranch?'selected':'' ?>><?= $e($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                            <?php endif; ?>
                            <?= listResetControl('dentist-records.php', $filtersOn) ?>
                        </div>

                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive" style="overflow:visible;">
                                <table class="table rec-table">
                                    <thead><tr>
                                        <?= listTh('Dentist', 'name_asc', 'name_desc', $sortKey, $sortBase) ?>
                                        <th>Email</th>
                                        <th>Contact Number</th>
                                        <?= listTh('Branch', 'branch_asc', 'branch_desc', $sortKey, $sortBase) ?>
                                        <?= listTh('Status', 'status_asc', 'status_desc', $sortKey, $sortBase) ?>
                                        <th class="rec-col-action"><span class="visually-hidden">Actions</span></th>
                                    </tr></thead>
                                    <tbody>
                                    <?php if (empty($dentists)): ?>
                                        <tr><td colspan="6" class="p-0">
                                            <div class="rec-empty">
                                                <?php if ($filtersOn): ?>
                                                <div class="rec-empty-icon"><i class="feather-search"></i></div>
                                                <h6 class="rec-empty-title">No dentists match your filters</h6>
                                                <p class="rec-empty-text">Try a different name, email or contact number, or clear the filters to see every dentist.</p>
                                                <a href="dentist-records.php" class="btn btn-light-brand btn-sm"><i class="feather-rotate-ccw me-1"></i> Clear Filters</a>
                                                <?php else: ?>
                                                <div class="rec-empty-icon"><i class="feather-user-plus"></i></div>
                                                <h6 class="rec-empty-title">No dentists yet</h6>
                                                <p class="rec-empty-text">Dentists are added in Accounts: create a staff account with the <strong>Dentist</strong> role and their dentist record is made automatically.</p>
                                                <a href="accounts-records.php?role=dentist" class="btn btn-primary btn-sm" data-es-full><i class="feather-key me-1"></i> Go to Accounts</a>
                                                <?php endif; ?>
                                            </div>
                                        </td></tr>
                                    <?php else: ?>
                                        <?php foreach ($dentists as $d):
                                            $name   = (string)($d['full_name'] ?? '');
                                            $status = strtolower((string)($d['status'] ?? 'active'));
                                            // Name parts: stored ones, else split from full_name (older records)
                                            $parts  = !empty($d['first_name']) || !empty($d['last_name'])
                                                ? ['first_name' => (string)($d['first_name'] ?? ''), 'middle_name' => (string)($d['middle_name'] ?? ''), 'last_name' => (string)($d['last_name'] ?? ''), 'suffix' => (string)($d['suffix'] ?? '')]
                                                : staffSplitName($name);
                                            $row    = [
                                                'dentist_id'     => (int)$d['dentist_id'],
                                                'full_name'      => $name,
                                                'first_name'     => $parts['first_name'],
                                                'middle_name'    => $parts['middle_name'],
                                                'last_name'      => $parts['last_name'],
                                                'suffix'         => $parts['suffix'],
                                                'age'            => isset($d['age']) && $d['age'] !== null ? (int)$d['age'] : '',
                                                'gender'         => (string)($d['gender'] ?? ''),
                                                'email'          => (string)($d['email'] ?? ''),
                                                'initials'       => dentInitials($name ?: 'D'),
                                                'tone'           => dentTone($name),
                                                'specialization' => (string)($d['specialization'] ?? ''),
                                                'contact_number' => (string)($d['contact_number'] ?? ''),
                                                'branch_id'      => $d['branch_id'] ?? '',
                                                'branch_name'    => (string)($d['branches']['branch_name'] ?? ''),
                                                'status'         => $status,
                                                'linked'         => !empty($d['user_id']),
                                                'username'       => (string)($d['users']['username'] ?? ''),
                                                'login_status'   => (string)($d['users']['status'] ?? ''),
                                                'upcoming'       => $upcoming === null ? null : ($upcoming[(int)$d['dentist_id']] ?? 0),
                                                'created_at'     => $d['created_at'] ?? '',
                                                'updated_at'     => $d['updated_at'] ?? '',
                                            ];
                                        ?>
                                        <tr class="rec-row" data-dentist="<?= $e(json_encode($row)) ?>">
                                            <td>
                                                <div class="rec-entity">
                                                    <span class="rec-avatar <?= $row['tone'] ?><?= $status === 'inactive' ? ' is-muted' : '' ?>" aria-hidden="true"><?= $e($row['initials']) ?></span>
                                                    <div class="rec-entity-text">
                                                        <button type="button" class="rec-entity-name" onclick="viewDentist(dntRow(this))"><?= $e($name ?: '—') ?></button>
                                                        <span class="rec-entity-sub">
                                                            <?php if ($row['linked']): ?><i class="feather-key me-1"></i><?= $row['username'] !== '' ? 'Login: ' . $e($row['username']) : 'Has a staff login' ?><?php else: ?>No staff login<?php endif; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php if ($row['email'] !== ''): ?><span class="rec-icon-text"><i class="feather-mail"></i><?= $e($row['email']) ?></span><?php else: ?><span class="rec-muted">Not set</span><?php endif; ?></td>
                                            <td><?php if ($row['contact_number'] !== ''): ?><span class="rec-icon-text"><i class="feather-phone"></i><?= $e($row['contact_number']) ?></span><?php else: ?><span class="rec-muted">Not set</span><?php endif; ?></td>
                                            <td><?php if ($row['branch_name'] !== ''): ?><span class="rec-icon-text"><i class="feather-map-pin"></i><?= $e($row['branch_name']) ?></span><?php else: ?><span class="rec-muted">Unassigned</span><?php endif; ?></td>
                                            <td>
                                                <span class="rec-status st-<?= $e(in_array($status, DENT_STATUSES, true) ? $status : 'inactive') ?>"><?= $e(dentStatusLabel($status)) ?></span>
                                                <?php if (!empty($row['upcoming'])): ?><span class="rec-sub-line rec-upcoming<?= $status !== 'active' ? ' is-warn' : '' ?>" title="Upcoming appointments assigned to this dentist"><i class="feather-calendar me-1"></i><?= (int)$row['upcoming'] ?> upcoming</span><?php endif; ?>
                                            </td>
                                            <td class="rec-col-action">
                                                <div class="dropdown">
                                                    <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-label="Actions for <?= $e($name) ?>"><i class="feather-more-vertical"></i></a>
                                                    <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                        <a href="javascript:void(0);" class="dropdown-item" onclick="viewDentist(dntRow(this))"><i class="feather-eye"></i> View Details</a>
                                                        <a href="javascript:void(0);" class="dropdown-item" onclick="openEditDentistModal(dntRow(this))"><i class="feather-edit-2"></i> Edit</a>
                                                        <div class="dropdown-divider"></div>
                                                        <a href="javascript:void(0);" class="dropdown-item text-danger" onclick="deleteDentist(dntRow(this))"><i class="feather-trash-2"></i> Delete</a>
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
                                <?php if ($totalRecords): ?>Showing <strong><?= $from ?>–<?= $to ?></strong> of <strong><?= $totalRecords ?></strong> dentist<?= $totalRecords === 1 ? '' : 's' ?><?php else: ?>No dentists to show<?php endif; ?>
                                <?php if ($filtersOn): ?>&nbsp;·&nbsp;<a href="dentist-records.php" class="text-danger"><i class="feather-x-circle me-1"></i>Clear filters</a><?php endif; ?>
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
    <div class="modal fade es-detail-modal" id="dentistViewModal" tabindex="-1" aria-labelledby="dvName" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-record-dialog rec-detail-dialog">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <span class="rec-avatar rec-head-avatar" id="dvAvatar" aria-hidden="true">D</span>
                        <div class="min-w-0">
                            <h5 class="apt-record-title mb-0" id="dvName">Dentist</h5>
                            <div class="apt-record-meta" id="dvMeta">—</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-record-body p-0">
                    <div class="ar-view-body">
                        <div class="ar-summary rec-summary-4">
                            <div class="ar-sum-item ar-sum-status">
                                <div class="ar-sum-label">Status</div>
                                <div id="dvStatus"></div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-map-pin"></i></span>
                                <div class="ar-sum-text"><div class="ar-sum-label">Branch</div><div class="ar-sum-value" id="dvBranch">—</div></div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-mail"></i></span>
                                <div class="ar-sum-text"><div class="ar-sum-label">Email</div><div class="ar-sum-value" id="dvEmail">—</div></div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-calendar"></i></span>
                                <div class="ar-sum-text"><div class="ar-sum-label">Upcoming Appointments</div><div class="ar-sum-value" id="dvUpcoming">—</div></div>
                            </div>
                        </div>
                        <div class="ar-cards-grid">
                            <section class="ar-card">
                                <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-user"></i></span><h6 class="ar-card-title">Profile</h6></div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Full name</span><span class="ar-card-value ar-strong" id="dvFull">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Email</span><span class="ar-card-value" id="dvEmail2">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Contact number</span><span class="ar-card-value" id="dvContact">—</span></div>
                                </div>
                            </section>
                            <section class="ar-card">
                                <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-file-text"></i></span><h6 class="ar-card-title">Record</h6></div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Staff login</span><span class="ar-card-value" id="dvLogin">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Added</span><span class="ar-card-value" id="dvCreated">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Last updated</span><span class="ar-card-value" id="dvUpdated">—</span></div>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer">
                    <button type="button" class="btn btn-primary" id="dvEditBtn"><i class="feather-edit-2 me-1"></i> Edit Dentist</button>
                    <span class="rec-foot-spacer"></span>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ ADD / EDIT ══════════════════════════════════════════ -->
    <div class="modal fade es-detail-modal" id="dentistModal" tabindex="-1" aria-labelledby="dntModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-form-dialog rec-form-dialog">
            <form class="modal-content apt-record-content" id="dentistForm" novalidate onsubmit="event.preventDefault(); saveDentist();">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-user"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="dntModalTitle">Edit Dentist</h5>
                            <div class="apt-record-meta" id="dntModalSub">Update the dentist's details below.</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-form-body">
                    <div id="dntModalError" class="apt-form-alert" role="alert" style="display:none;"></div>
                    <div id="dntLinkedNote" class="rec-note" style="display:none;">
                        <i class="feather-key"></i>
                        <span>This dentist has a staff login<span id="dntLinkedUser"></span>. Name and branch changes are applied to that login too.</span>
                    </div>
                    <input type="hidden" id="dnt_id">
                    <div class="rec-form-stack">
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-user"></i></span><h6 class="ar-card-title">Personal Details</h6></div>
                            <div class="apt-form-card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="dnt_first">First Name <span class="apt-req" aria-hidden="true">*</span></label>
                                            <input type="text" id="dnt_first" class="form-control" maxlength="50" autocomplete="off" placeholder="e.g. Juan" required>
                                            <div class="apt-field-err" data-err-for="dnt_first"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="dnt_middle">Middle Name <span class="apt-opt">(optional)</span></label>
                                            <input type="text" id="dnt_middle" class="form-control" maxlength="50" autocomplete="off" placeholder="e.g. Santos">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="dnt_last">Last Name <span class="apt-req" aria-hidden="true">*</span></label>
                                            <input type="text" id="dnt_last" class="form-control" maxlength="50" autocomplete="off" placeholder="e.g. Dela Cruz" required>
                                            <div class="apt-field-err" data-err-for="dnt_last"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="dnt_suffix">Suffix <span class="apt-opt">(optional)</span></label>
                                            <select id="dnt_suffix" class="form-select js-custom-select" data-cs-float>
                                                <option value="">None</option>
                                                <?php foreach (STAFF_SUFFIXES as $sx): ?><option value="<?= $e($sx) ?>"><?= $e($sx) ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="dnt_age">Age <span class="apt-req" aria-hidden="true">*</span></label>
                                            <input type="text" id="dnt_age" class="form-control" maxlength="3" inputmode="numeric" autocomplete="off" placeholder="e.g. 35" required>
                                            <div class="apt-field-err" data-err-for="dnt_age"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="dnt_gender">Gender <span class="apt-req" aria-hidden="true">*</span></label>
                                            <select id="dnt_gender" class="form-select js-custom-select" data-cs-float>
                                                <option value="">Select Gender</option>
                                                <?php foreach (STAFF_GENDERS as $gv => $gl): ?><option value="<?= $e($gv) ?>"><?= $e($gl) ?></option><?php endforeach; ?>
                                            </select>
                                            <div class="apt-field-err" data-err-for="dnt_gender"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="dnt_email">Email <span class="apt-req" aria-hidden="true">*</span></label>
                                            <input type="email" id="dnt_email" class="form-control" maxlength="120" autocomplete="off" placeholder="e.g. juan@clinic.com" required>
                                            <div class="apt-field-err" data-err-for="dnt_email"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="dnt_contact">Contact Number <span class="apt-req" aria-hidden="true">*</span></label>
                                            <div class="rec-phone">
                                                <span class="rec-phone-prefix" aria-hidden="true">+63</span>
                                                <input type="tel" id="dnt_contact" class="form-control js-rec-phone" inputmode="numeric" autocomplete="off" placeholder="9XX XXX XXXX" aria-label="Contact number after +63" required>
                                            </div>
                                            <div class="apt-field-err" data-err-for="dnt_contact"></div>
                                            <div class="rec-help" id="dntOldContact" style="display:none;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-map-pin"></i></span><h6 class="ar-card-title">Clinic Assignment</h6></div>
                            <div class="apt-form-card-body">
                                <div class="apt-field">
                                    <label class="apt-label" for="dnt_branch">Branch <span class="apt-req" aria-hidden="true">*</span></label>
                                    <select id="dnt_branch" class="form-select js-custom-select" data-cs-float data-cs-search>
                                        <option value="">Select a branch</option>
                                        <?php foreach ($branches as $b): ?>
                                        <option value="<?= (int)$b['branch_id'] ?>"><?= $e($b['branch_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="apt-field-err" data-err-for="dnt_branch"></div>
                                </div>
                                <div class="apt-field">
                                    <span class="apt-label" id="dntStatusLbl">Status</span>
                                    <div class="rec-seg" role="radiogroup" aria-labelledby="dntStatusLbl">
                                        <input type="radio" name="dnt_status" id="dnt_st_active" value="active">
                                        <label for="dnt_st_active" class="dot-green"><span class="rec-seg-title">Active</span><span class="rec-seg-desc">Can be booked and assigned</span></label>
                                        <input type="radio" name="dnt_status" id="dnt_st_on_leave" value="on_leave">
                                        <label for="dnt_st_on_leave" class="dot-amber"><span class="rec-seg-title">On Leave</span><span class="rec-seg-desc">Temporarily unavailable</span></label>
                                        <input type="radio" name="dnt_status" id="dnt_st_inactive" value="inactive">
                                        <label for="dnt_st_inactive" class="dot-slate"><span class="rec-seg-title">Inactive</span><span class="rec-seg-desc">No longer practising here</span></label>
                                    </div>
                                    <div class="rec-help">Only <strong>Active</strong> dentists appear when booking or assigning appointments.</div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer apt-form-footer">
                    <span class="apt-form-hint"><span class="apt-req">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4" id="dntSaveBtn"><i class="feather-save me-1"></i> Save Changes</button>
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
    <script src="assets/js/dentists.js?v=6"></script>
    <script>
    function applyFilters() {
        window._applyFilters('dentist-records.php', { branch: 'filterBranch', status: 'filterStatus' });
    }
    </script>
</body>
</html>
