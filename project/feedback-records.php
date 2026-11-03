<?php
// =============================================================
//  feedback-records.php — Patient feedback (Owner only, read-only)
//
//  Lists patient_feedback across all branches. Feedback is submitted by
//  patients from the portal (api/portal_feedback.php) and is never edited
//  here — the Owner can only review it.
//
//  Anonymity: the Owner must not be able to tell who wrote a feedback.
//  The query below never selects patient, account or appointment columns,
//  so they are not in the page, the HTML attributes or anything the browser
//  receives. The feedback's own ID is shown (View Details only) as a record
//  reference; the visit's date, code and services are left out, since
//  together they could point to one patient. The patient/appointment link
//  stays in the table for the one-feedback-per-appointment rule
//  (server-side only).
// =============================================================
define('REQUIRED_ROLES', ['owner']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/appointment_controller.php'; // resolveCount + buildPageResult
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/feedback_controller.php';
require_once __DIR__ . '/controllers/list_filters.php';

const FB_PER_PAGE   = 15;
const FB_MIN_SAMPLE = 5;   // fewer ratings than this → shown as a low sample
const FB_TREND_DAYS = 90;  // per-dentist trend: last N days vs the N days before

$page         = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($page - 1) * FB_PER_PAGE;
$branches     = getAllBranches();
$filterBranch = (int)($_GET['branch'] ?? 0);
$filterDentist= (int)($_GET['dentist'] ?? 0);
$filterRating = in_array((int)($_GET['rating'] ?? 0), [1, 2, 3, 4, 5], true) ? (int)$_GET['rating'] : 0;
$dateFrom     = listDate('from');
$dateTo       = listDate('to');

$sortOptions = [
    'newest'      => ['Newest First',          'created_at.desc,feedback_id.desc'],
    'oldest'      => ['Oldest First',          'created_at.asc,feedback_id.asc'],
    'rating_desc' => ['Rating (High → Low)',   'rating.desc,created_at.desc'],
    'rating_asc'  => ['Rating (Low → High)',   'rating.asc,created_at.desc'],
];
[$sortKey, $sortOrder] = listSort($sortOptions, 'newest');

$filters = [];
if ($filterBranch > 0) $filters[] = 'branch_id=eq.' . $filterBranch;
if ($filterDentist)    $filters[] = 'dentist_id=eq.' . $filterDentist;
if ($filterRating)     $filters[] = 'rating=eq.' . $filterRating;
array_push($filters, ...listDateRange('created_at', $dateFrom, $dateTo, true));

$q = array_merge([
    // Non-identifying columns only (see header)
    'select=feedback_id,category,rating,message,created_at,dentists(full_name),branches(branch_name)',
    $sortOrder,
    'limit='  . FB_PER_PAGE,
    'offset=' . $offset,
], $filters);

// Dentists for the filter (names only)
$dentistRes = supabase_request('dentists', 'GET', [], 'select=dentist_id,full_name&order=full_name.asc');
$dentists   = is_array($dentistRes['data'] ?? null) ? $dentistRes['data'] : [];

// ── Analytics source: every rating in the branch filter (no other filter),
//    non-identifying columns only. Date range is applied in PHP below so the
//    trend can also look at the period before it.
$anaRes  = supabase_request('patient_feedback', 'GET', [], implode('&', array_filter([
    'select=rating,category,dentist_id,created_at,dentists(full_name)', 'limit=10000',
    $filterBranch > 0 ? 'branch_id=eq.' . $filterBranch : null,
])));
$anaAll  = empty($anaRes['error']) && is_array($anaRes['data']) ? $anaRes['data'] : [];
$fbDay   = fn($r) => date('Y-m-d', strtotime($r['created_at']));
$inRange = fn($r) => (!$dateFrom || $fbDay($r) >= $dateFrom) && (!$dateTo || $fbDay($r) <= $dateTo);
$anaRows = array_values(array_filter($anaAll, $inRange));

// Summary cards: all list filters (same rows as the table)
$sumRows = array_values(array_filter($anaRows, fn($r) =>
    (!$filterDentist || (int)$r['dentist_id'] === $filterDentist) && (!$filterRating || (int)$r['rating'] === $filterRating)));
$sumAll  = count($sumRows);
$ratings = array_map(fn($r) => (int)$r['rating'], $sumRows);
$stats   = [
    'avg'      => $sumAll ? round(array_sum($ratings) / $sumAll, 1) : 0,
    'dentists' => count(array_unique(array_filter(array_map(fn($r) => (int)$r['dentist_id'], $sumRows)))),
    'positive' => count(array_filter($ratings, fn($n) => $n >= 4)),
    'low'      => count(array_filter($ratings, fn($n) => $n <= 2)),
];
$pct = fn(int $n): int => $sumAll ? (int)round($n * 100 / $sumAll) : 0;

// Star distribution (date range + branch)
$dist = array_fill(1, 5, 0);
foreach ($anaRows as $r) $dist[(int)$r['rating']]++;
$distTotal = array_sum($dist);

// Per dentist: count, average, distribution, last 90 days vs the 90 before
$cut1 = date('Y-m-d', strtotime('-' . FB_TREND_DAYS . ' days'));
$cut2 = date('Y-m-d', strtotime('-' . (2 * FB_TREND_DAYS) . ' days'));
$perDentist = [];
foreach ($anaAll as $r) {
    if (($r['category'] ?? '') !== 'dentist' || empty($r['dentist_id'])) continue;
    $id = (int)$r['dentist_id'];
    $perDentist[$id] ??= ['name' => $r['dentists']['full_name'] ?? 'Dentist #' . $id, 'ratings' => [], 'dist' => array_fill(1, 5, 0), 'recent' => [], 'before' => []];
    $day = $fbDay($r);
    if ($day >= $cut1) $perDentist[$id]['recent'][] = (int)$r['rating'];
    elseif ($day >= $cut2) $perDentist[$id]['before'][] = (int)$r['rating'];
    if (!$inRange($r)) continue;
    $perDentist[$id]['ratings'][] = (int)$r['rating'];
    $perDentist[$id]['dist'][(int)$r['rating']]++;
}
$perDentist = array_filter($perDentist, fn($d) => $d['ratings']);
$avgOf = fn(array $a) => $a ? round(array_sum($a) / count($a), 1) : null;
uasort($perDentist, fn($a, $b) => count($b['ratings']) <=> count($a['ratings']) ?: strcmp($a['name'], $b['name']));

$filtersOn = listActiveCount([$filterBranch, $filterDentist, $filterRating, $dateFrom, $dateTo, $sortKey !== 'newest']);

$result    = supabase_request('patient_feedback', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
$loadError = !empty($result['error']);
$rows      = is_array($result['data']) && !$loadError ? $result['data'] : [];
$total     = $loadError ? 0 : resolveCount($result, 'patient_feedback', $q);
$pager     = buildPageResult($rows, $total, $page, $offset, FB_PER_PAGE);
['totalRecords' => $totalRecords, 'totalPages' => $totalPages, 'from' => $from, 'to' => $to] = $pager;

$params   = ['branch' => $filterBranch, 'dentist' => $filterDentist ?: '', 'rating' => $filterRating ?: '', 'from' => $dateFrom, 'to' => $dateTo];
$baseUrl  = listUrl('feedback-records.php', $params + ['sort' => $sortKey === 'newest' ? '' : $sortKey]);

function fbStars(int $n): string
{
    return '<span class="fb-stars-ro" aria-label="' . $n . ' out of 5">' .
        str_repeat('<i class="bi bi-star-fill"></i>', $n) . str_repeat('<i class="bi bi-star"></i>', 5 - $n) . '</span>';
}
// Submission timestamp, same formats as the other admin lists
function fbTs(?string $iso): ?int
{
    $ts = $iso ? strtotime($iso) : false;
    return $ts ?: null;
}
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Patient Feedback</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=3">
    <style>
        .fb-stars-ro { color: #f5b301; white-space: nowrap; font-size: .8rem; letter-spacing: 1px; }
        .fb-stars-ro .bi-star { color: #d5dde7; }
        .fb-cat-badge { white-space: nowrap; }
        .fb-notice { font-size: .75rem; line-height: 1.4; }
        .fb-dist-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .fb-dist-label { width: 34px; font-size: .8rem; font-weight: 600; color: #475569; white-space: nowrap; }
        .fb-dist-label .bi { color: #f5b301; font-size: .7rem; }
        .fb-dist-count { width: 28px; text-align: end; font-size: .8rem; color: #64748b; }
        .fb-ana-table th { font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; color: #6b7a90; }
        /* View Details: shared .es-detail-modal styles (assets/css/custom.css) */
        #feedbackViewModal .ar-notes { padding: .75rem 0 .25rem; }
        #feedbackViewModal .fb-anon-note { margin: 1rem 0 0; font-size: .75rem; color: #6b7a90; }
    </style>
</head>
<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>
    <main class="nxl-container">
        <div class="nxl-content">
            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">Patient Feedback</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Feedback</li>
                    </ul>
                </div>
            </div>
            <div class="main-content">
                <?php if (!$loadError):
                    $cards = [
                        ['feather-star',           'Average Rating',     $sumAll ? number_format($stats['avg'], 1) . ' / 5' : '—', $stats['positive'] . ' rated 4–5 stars', $pct($stats['positive']), 'bg-success'],
                        ['feather-message-square', 'Total Feedback',     $sumAll, $stats['low'] . ' low ratings (1–2 stars)', $pct($stats['low']), 'bg-danger'],
                        ['feather-user-check',     'Dentists Reviewed',  $stats['dentists'], 'with at least one rating', $stats['dentists'] ? 100 : 0, 'bg-primary'],
                        ['feather-trending-up',    'Positive Share',     $pct($stats['positive']) . '%', 'of ' . $sumAll . ' rating' . ($sumAll === 1 ? '' : 's') . ($sumAll && $sumAll < FB_MIN_SAMPLE ? ' · low sample' : ''), $pct($stats['positive']), 'bg-success'],
                    ];
                ?>
                <div class="row">
                    <?php foreach ($cards as [$icon, $label, $value, $note, $bar, $barCls]): ?>
                    <div class="col-xxl-3 col-md-6">
                        <div class="card stretch stretch-full">
                            <div class="card-body">
                                <div class="d-flex gap-3 align-items-center">
                                    <div class="avatar-text avatar-lg bg-gray-200"><i class="<?= $icon ?>"></i></div>
                                    <div>
                                        <div class="fs-4 fw-bold text-dark"><?= htmlspecialchars((string)$value) ?></div>
                                        <h3 class="fs-13 fw-semibold text-truncate-1-line mb-0"><?= htmlspecialchars($label) ?></h3>
                                    </div>
                                </div>
                                <div class="pt-4">
                                    <span class="fs-12 fw-medium text-muted"><?= htmlspecialchars($note) ?></span>
                                    <div class="progress mt-2 ht-3"><div class="progress-bar <?= $barCls ?>" style="width:<?= (int)$bar ?>%"></div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Analytics: branch + date filters only, from the actual ratings; no submitter data -->
                <div class="row">
                    <div class="col-xxl-4 col-lg-5">
                        <div class="card stretch stretch-full">
                            <div class="card-header"><h5 class="card-title mb-0">Rating Distribution</h5></div>
                            <div class="card-body">
                                <?php for ($n = 5; $n >= 1; $n--): $w = $distTotal ? (int)round($dist[$n] * 100 / $distTotal) : 0; ?>
                                <div class="fb-dist-row">
                                    <span class="fb-dist-label"><?= $n ?> <i class="bi bi-star-fill"></i></span>
                                    <div class="progress ht-5 flex-grow-1"><div class="progress-bar <?= $n >= 4 ? 'bg-success' : ($n === 3 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= $w ?>%"></div></div>
                                    <span class="fb-dist-count"><?= $dist[$n] ?></span>
                                </div>
                                <?php endfor; ?>
                                <p class="fs-12 text-muted mb-0 mt-3">Based on <?= $distTotal ?> rating<?= $distTotal === 1 ? '' : 's' ?><?= $filterBranch || $dateFrom || $dateTo ? ' in the selected branch / dates' : '' ?>.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-8 col-lg-7">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title mb-1">Ratings by Dentist</h5>
                                    <p class="fs-12 text-muted mb-0">Averages from fewer than <?= FB_MIN_SAMPLE ?> ratings are marked as a low sample. Use alongside other information, not on their own, for staff decisions.</p>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table mb-0 fb-ana-table">
                                        <thead><tr><th>Dentist</th><th class="text-center">Ratings</th><th>Average</th><th>Last <?= FB_TREND_DAYS ?> days</th></tr></thead>
                                        <tbody>
                                        <?php if (!$perDentist): ?>
                                            <tr><td colspan="4" class="text-center text-muted py-4">No dentist ratings in this range yet.</td></tr>
                                        <?php else: foreach ($perDentist as $d):
                                            $n = count($d['ratings']); $avg = $avgOf($d['ratings']);
                                            $rec = $avgOf($d['recent']); $bef = $avgOf($d['before']);
                                        ?>
                                            <tr>
                                                <td class="fw-semibold text-dark"><?= htmlspecialchars($d['name']) ?></td>
                                                <td class="text-center"><?= $n ?><?php if ($n < FB_MIN_SAMPLE): ?> <span class="badge bg-soft-warning text-warning ms-1">Low sample</span><?php endif; ?></td>
                                                <td class="text-nowrap"><?= fbStars((int)round($avg)) ?> <span class="ms-1 fw-semibold"><?= number_format($avg, 1) ?></span></td>
                                                <td class="text-nowrap small">
                                                    <?php if ($rec === null): ?><span class="text-muted">No ratings</span>
                                                    <?php else: ?><?= number_format($rec, 1) ?> <span class="text-muted">(<?= count($d['recent']) ?>)</span>
                                                        <?php if ($bef !== null): $delta = round($rec - $bef, 1); ?>
                                                        <span class="<?= $delta > 0 ? 'text-success' : ($delta < 0 ? 'text-danger' : 'text-muted') ?> ms-1"><?= $delta > 0 ? '+' : '' ?><?= number_format($delta, 1) ?> vs previous <?= FB_TREND_DAYS ?> days</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="row"><div class="col-lg-12">
                    <div class="card stretch stretch-full">
                        <div class="card-header">
                            <div>
                                <h5 class="card-title mb-1">Feedback Records <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span></h5>
                                <p class="fb-notice text-muted mb-0"><i class="feather-eye-off me-1"></i>Patients' identities are hidden. Messages appear exactly as written and may mention details about their author.</p>
                            </div>
                            <div class="card-header-action"><div class="card-header-btn">
                                <div data-bs-toggle="tooltip" title="Refresh"><a href="feedback-records.php" class="avatar-text avatar-xs bg-warning"></a></div>
                                <div data-bs-toggle="tooltip" title="Maximize/Minimize"><a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a></div>
                            </div></div>
                        </div>
                        <div class="list-toolbar is-fluid">
                            <?= listSelectControl('Dentist', 'dentist', ['' => 'All Dentists'] + array_column($dentists, 'full_name', 'dentist_id'), (string)($filterDentist ?: '')) ?>
                            <?= listSelectControl('Rating', 'rating', ['' => 'All Ratings', '5' => '5 stars', '4' => '4 stars', '3' => '3 stars', '2' => '2 stars', '1' => '1 star'], (string)($filterRating ?: '')) ?>
                            <div class="list-field"><label class="list-label" for="filterBranch">Branch</label>
                            <select id="filterBranch" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= (int)$b['branch_id'] ?>" <?= (int)$b['branch_id'] === $filterBranch ? 'selected' : '' ?>><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                            <?= listDateRangeControl($dateFrom, $dateTo, 'Submitted') ?>
                            <?= listSortControl($sortOptions, $sortKey, 'newest') ?>
                            <?= listResetControl('feedback-records.php', $filtersOn) ?>
                        </div>
                        <div class="card-body custom-card-action p-0">
                            <div class="table-responsive" style="overflow:visible;">
                                <table class="table table-hover mb-0">
                                    <thead><tr>
                                        <th>Date Submitted</th><th>Dentist</th><th>Rating</th>
                                        <th>Branch</th><th class="text-end">Action</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php if ($loadError): ?>
                                        <tr><td colspan="5" class="text-center py-5">
                                            <i class="feather-alert-triangle fs-3 text-warning d-block mb-2"></i>
                                            <div class="fw-semibold text-dark">Feedback couldn't be loaded</div>
                                            <div class="text-muted small">If this is a new setup, run <code>database/2026-10-27_patient_feedback.sql</code> in the Supabase SQL Editor, then refresh.</div>
                                        </td></tr>
                                    <?php elseif (!$rows): ?>
                                        <?= listEmptyRow(5,$filtersOn > 0, 'No feedback has been submitted yet.', 'feather-message-square', 'feedback-records.php') ?>
                                    <?php else: foreach ($rows as $r):
                                        $isDentist = $r['category'] === 'dentist';
                                        $ts = fbTs($r['created_at'] ?? null);
                                        // Only the fields below reach the browser (no patient/account/appointment data)
                                        $detail = [
                                            // Display code for the feedback's own ID, e.g. FB-00001
                                            'id'        => !empty($r['feedback_id']) ? sprintf('FB-%05d', (int)$r['feedback_id']) : '',
                                            'category'  => FEEDBACK_CATEGORIES[$r['category']] ?? $r['category'],
                                            'isDentist' => $isDentist,
                                            'dentist'   => $isDentist ? ($r['dentists']['full_name'] ?? '') : '',
                                            'rating'    => (int)$r['rating'],
                                            'message'   => (string)($r['message'] ?? ''),
                                            'submitted' => $ts ? date('F d, Y · h:i A', $ts) : '',
                                            'branch'    => $r['branches']['branch_name'] ?? '',
                                        ];
                                    ?>
                                        <tr>
                                            <td class="text-nowrap">
                                                <?php if ($ts): ?>
                                                <span class="text-dark fw-medium d-block"><?= date('M d, Y', $ts) ?></span>
                                                <small class="text-muted"><?= date('h:i A', $ts) ?></small>
                                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                            </td>
                                            <td><?php if ($isDentist): ?><span class="fw-semibold text-dark"><?= htmlspecialchars($detail['dentist'] ?: '—') ?></span>
                                                <?php else: ?><span class="badge fb-cat-badge bg-soft-success text-success">Clinic &amp; Management</span><?php endif; ?></td>
                                            <td><?= fbStars($detail['rating']) ?></td>
                                            <td><?= htmlspecialchars($detail['branch'] ?: '—') ?></td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-label="Actions"><i class="feather-more-vertical"></i></a>
                                                    <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                        <a href="javascript:void(0);" class="dropdown-item" data-feedback="<?= htmlspecialchars(json_encode($detail), ENT_QUOTES) ?>" onclick="viewFeedback(this)">
                                                            <i class="feather-eye"></i> View Details
                                                        </a>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <p class="text-muted small mb-0">
                                Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> feedback
                                <?php if ($filtersOn): ?>&nbsp;·&nbsp;<a href="feedback-records.php" class="text-danger"><i class="feather-x-circle me-1"></i>Clear filters</a><?php endif; ?>
                            </p>
                            <?php if ($totalPages > 1): ?>
                            <ul class="list-unstyled d-flex align-items-center gap-1 mb-0 pagination-common-style">
                                <li><?php if ($page > 1): ?><a href="<?= $baseUrl ?>&page=<?= $page - 1 ?>"><i class="bi bi-arrow-left"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;"><i class="bi bi-arrow-left"></i></a><?php endif; ?></li>
                                <?php $wS = max(1, $page - 2); $wE = min($totalPages, $wS + 4); $wS = max(1, $wE - 4); ?>
                                <?php for ($pg = $wS; $pg <= $wE; $pg++): ?><li><a href="<?= $baseUrl ?>&page=<?= $pg ?>" class="<?= $pg === $page ? 'active' : '' ?>"><?= $pg ?></a></li><?php endfor; ?>
                                <li><?php if ($page < $totalPages): ?><a href="<?= $baseUrl ?>&page=<?= $page + 1 ?>"><i class="bi bi-arrow-right"></i></a><?php else: ?><a class="text-muted" style="pointer-events:none;"><i class="bi bi-arrow-right"></i></a><?php endif; ?></li>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div></div>
            </div>
        </div>
    </main>

    <!-- VIEW MODAL (read-only; filled with textContent so feedback text is never parsed as HTML) -->
    <div class="modal fade es-detail-modal" id="feedbackViewModal" tabindex="-1" aria-labelledby="fvTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-record-dialog">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-message-square"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="fvTitle">Feedback Details</h5>
                            <div class="apt-record-meta">Feedback ID <span class="apt-record-code" id="fv_id">—</span></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-record-body p-0">
                    <div class="ar-view-body">
                        <div class="ar-cards-grid">
                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-info"></i></span>
                                    <h6 class="ar-card-title">Feedback Information</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Category</span><span class="ar-card-value ar-strong" id="fv_category">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Rating</span><span class="ar-card-value"><span class="fb-stars-ro" id="fv_stars"></span> <span id="fv_rating" class="ms-1 text-muted"></span></span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Date Submitted</span><span class="ar-card-value" id="fv_submitted">—</span></div>
                                </div>
                            </section>
                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-map-pin"></i></span>
                                    <h6 class="ar-card-title">Branch &amp; Dentist</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Branch</span><span class="ar-card-value" id="fv_branch">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Dentist Reviewed</span><span class="ar-card-value" id="fv_dentist">—</span></div>
                                </div>
                            </section>
                            <section class="ar-card ar-card-wide">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-message-circle"></i></span>
                                    <h6 class="ar-card-title">Feedback Message</h6>
                                </div>
                                <p class="ar-notes" id="fv_message"></p>
                            </section>
                        </div>
                        <p class="fb-anon-note"><i class="feather-eye-off me-1"></i>Patients' identities are hidden. Messages appear exactly as written and may mention details about their author.</p>
                    </div>
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
    <script>
    function applyFilters() {
        window._applyFilters('feedback-records.php', { branch: 'filterBranch' });
    }

    function viewFeedback(btn) {
        let d;
        try { d = JSON.parse(btn.dataset.feedback); } catch (e) { return; }
        // textContent only, so feedback text is never parsed as HTML
        const set = (id, v, empty = '—') => {
            const n = document.getElementById(id);
            const has = v !== null && v !== undefined && String(v).trim() !== '';
            n.textContent = has ? v : empty;
            n.classList.toggle('ar-muted', !has && empty !== '—');
        };
        set('fv_id', d.id);
        set('fv_category', d.category);
        set('fv_dentist', d.dentist, d.isDentist ? 'Not recorded' : 'Not applicable (clinic feedback)');
        set('fv_branch', d.branch, 'Not recorded');
        const stars = document.getElementById('fv_stars');
        stars.replaceChildren(...Array.from({ length: 5 }, (_, i) => {
            const s = document.createElement('i');
            s.className = i < d.rating ? 'bi bi-star-fill' : 'bi bi-star';
            return s;
        }));
        set('fv_rating', `${d.rating} / 5`);
        set('fv_submitted', d.submitted);
        set('fv_message', d.message, 'No written message.');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('feedbackViewModal')).show();
    }
    </script>
</body>
</html>
