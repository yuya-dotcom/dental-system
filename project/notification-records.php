<?php
// =============================================================
//  notification-records.php — Patient notifications (System › Notifications)
//
//  Views (?view=)
//    (default)      Delivery log: every email / SMS / push the system sent
//                   (notification_logs), with stats, filters, details, Retry.
//    templates      Owner: edit the text of each event + channel (notification_templates).
//    announcements  Owner: publish a patient announcement (portal bell + optional push).
//
//  Access: owner (all branches) and admin (own branch only). Rows without a
//  branch (SMS verification codes) are owner-only. Actions: api/notification_admin.php.
//  Sending itself: controllers/notification_service.php.
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();
require_once __DIR__ . '/dbconfig.php';
require_once __DIR__ . '/controllers/list_filters.php';
require_once __DIR__ . '/controllers/record_ui.php';   // Appointment Records cell markup

requireLogin();

const NR_PER_PAGE = 20;
$isOwner = ($_SESSION['role'] ?? '') === 'owner';
$view    = in_array($_GET['view'] ?? '', ['templates', 'announcements'], true) && $isOwner ? $_GET['view'] : 'log';

$typeLabels = [
    'appointment_confirmed'   => 'Appointment Confirmed',
    'appointment_cancelled'   => 'Appointment Cancelled',
    'appointment_rescheduled' => 'Appointment Rescheduled',
    'appointment_reminder'    => 'Appointment Reminder',
    'announcement'            => 'Announcement',
    'otp'                     => 'Verification Code',
];
$channelLabels = ['email' => 'Email', 'sms' => 'SMS', 'push' => 'Push'];
$channelIcons  = ['email' => 'feather-mail', 'sms' => 'feather-message-square', 'push' => 'feather-bell'];
$statusLabels  = ['sent' => 'Sent', 'failed' => 'Failed', 'skipped' => 'Skipped', 'sending' => 'In Progress'];
$statusClass   = ['sent' => 'bg-soft-success text-success', 'failed' => 'bg-soft-danger text-danger',
                  'skipped' => 'bg-soft-secondary text-secondary', 'sending' => 'bg-soft-warning text-warning'];

$tablesReady = true;
$branches = [];

if ($view === 'log') {
    // ── Filters ──
    $fChannel = array_key_exists($_GET['channel'] ?? '', $channelLabels) ? $_GET['channel'] : '';
    $fType    = array_key_exists($_GET['type'] ?? '', $typeLabels) ? $_GET['type'] : '';
    $fStatus  = array_key_exists($_GET['status'] ?? '', $statusLabels) ? $_GET['status'] : '';
    $fBranch  = scopedBranch((int)($_GET['branch'] ?? 0));          // admins: always their own branch
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $offset   = ($page - 1) * NR_PER_PAGE;
    $sortOptions = [
        'newest' => ['Newest First', 'created_at.desc,log_id.desc'],
        'oldest' => ['Oldest First', 'created_at.asc,log_id.asc'],
    ];
    [$sortKey, $sortOrder] = listSort($sortOptions, 'newest');
    $search   = listSearch();
    $dateFrom = listDate('from');
    $dateTo   = listDate('to');

    // Common filters (everything except status → also used for the stat cards)
    $base = [];
    if ($fBranch !== 0) $base[] = 'branch_id=eq.' . $fBranch;
    if ($fChannel) $base[] = 'channel=eq.' . $fChannel;
    if ($fType)    $base[] = 'notification_type=eq.' . $fType;
    array_push($base, ...listDateRange('created_at', $dateFrom, $dateTo, true));
    if ($search !== '') {
        // Recipient / subject text, or the patient's name (matching patient ids first)
        $like = '*' . str_replace(['*', ',', '(', ')'], ' ', $search) . '*';
        $or   = ['recipient.ilike.' . rawurlencode($like), 'subject.ilike.' . rawurlencode($like)];
        $p = supabase_request('patients', 'GET', [], 'full_name=ilike.' . rawurlencode($like) . '&select=patient_id&limit=100');
        $ids = array_map(fn($r) => (int)$r['patient_id'], is_array($p['data'] ?? null) && array_is_list($p['data']) ? $p['data'] : []);
        if ($ids) $or[] = 'patient_id.in.(' . implode(',', $ids) . ')';
        if (preg_match('/^[A-Z]{2,5}-?\d+/i', $search)) {
            $a = supabase_request('appointments', 'GET', [], 'appointment_code=ilike.' . rawurlencode($like) . '&select=appointment_id&limit=50');
            $aids = array_map(fn($r) => (int)$r['appointment_id'], is_array($a['data'] ?? null) && array_is_list($a['data']) ? $a['data'] : []);
            if ($aids) $or[] = 'appointment_id.in.(' . implode(',', $aids) . ')';
        }
        $base[] = 'or=(' . implode(',', $or) . ')';
    }
    $baseQ = $base ? '&' . implode('&', $base) : '';
    $listQ = 'select=log_id,created_at,notification_type,channel,recipient,subject,status,attempts,failure_reason,provider_status,'
           . 'appointment_id,branch_id,patients(full_name),appointments(appointment_code),branches(branch_name)'
           . $baseQ . ($fStatus ? '&status=eq.' . $fStatus : '') . '&' . $sortOrder . '&limit=' . NR_PER_PAGE . '&offset=' . $offset;

    $cnt = fn(string $extra = '') => admin_q('notification_logs', 'select=log_id&limit=1' . $baseQ . $extra, ['Prefer: count=exact']);
    $specs = ['list' => admin_q('notification_logs', $listQ, ['Prefer: count=exact']),
              'all' => $cnt(), 'sent' => $cnt('&status=eq.sent'), 'failed' => $cnt('&status=eq.failed'),
              'skipped' => $cnt('&status=eq.skipped'), 'sending' => $cnt('&status=eq.sending')];
    foreach ($channelLabels as $ch => $_) $specs['ch_' . $ch] = $cnt('&channel=eq.' . $ch . ($fStatus ? '&status=eq.' . $fStatus : ''));
    $res = admin_prefetch($specs, $isOwner ? ['navbar', 'branches'] : ['navbar']);
    if ($isOwner) { require_once __DIR__ . '/controllers/branch_controller.php'; $branches = getAllBranches(); }

    $list = $res['list'];
    $tablesReady = ($list['status'] ?? 0) !== 404;
    $listOk = ($list['status'] ?? 0) >= 200 && ($list['status'] ?? 0) < 300 && is_array($list['data']) && array_is_list($list['data']);
    $rows = $listOk ? $list['data'] : [];
    $total = $listOk ? admin_count($list) : 0;
    $stats = [];
    foreach (['all', 'sent', 'failed', 'skipped', 'sending', 'ch_email', 'ch_sms', 'ch_push'] as $k) $stats[$k] = $tablesReady ? admin_count($res[$k]) : 0;
    $totalPages = max(1, (int)ceil($total / NR_PER_PAGE));
    $from = $total > 0 ? $offset + 1 : 0;
    $to   = min($offset + NR_PER_PAGE, $total);

    $filtersOn  = listActiveCount([$fChannel, $fType, $fStatus, $isOwner ? $fBranch : 0, $search, $dateFrom, $dateTo, $sortKey !== 'newest']);
    $listParams = ['channel' => $fChannel, 'type' => $fType, 'status' => $fStatus, 'branch' => $isOwner ? $fBranch : 0, 'q' => $search, 'from' => $dateFrom, 'to' => $dateTo];
    $pageUrl    = listUrl('notification-records.php', $listParams + ['sort' => $sortKey === 'newest' ? '' : $sortKey]);
    $sortBase   = listUrl('notification-records.php', $listParams);
    $successRate = ($stats['sent'] + $stats['failed']) > 0 ? round(100 * $stats['sent'] / ($stats['sent'] + $stats['failed'])) : null;
} elseif ($view === 'templates') {
    $res = admin_prefetch(['tpl' => admin_q('notification_templates', 'select=*&order=notification_type.asc,channel.asc')], ['navbar']);
    $tablesReady = ($res['tpl']['status'] ?? 0) !== 404;
    $templates = is_array($res['tpl']['data'] ?? null) && array_is_list($res['tpl']['data']) ? $res['tpl']['data'] : [];
    $order = array_flip(array_keys($typeLabels));
    $chOrder = ['email' => 0, 'sms' => 1, 'push' => 2];
    usort($templates, fn($a, $b) => [($order[$a['notification_type']] ?? 99), $chOrder[$a['channel']] ?? 9] <=> [($order[$b['notification_type']] ?? 99), $chOrder[$b['channel']] ?? 9]);
} else {
    $res = admin_prefetch([
        'ann'  => admin_q('clinic_announcements', 'select=announcement_id,title,message,branch_id,is_active,starts_at,ends_at,created_at,branches(branch_name)&order=created_at.desc&limit=25'),
        'push' => admin_q('notification_preferences', 'select=account_id&push_enabled=eq.true&limit=1', ['Prefer: count=exact']),
    ], ['navbar', 'branches']);
    require_once __DIR__ . '/controllers/branch_controller.php';
    $branches = getAllBranches();
    $announcements = is_array($res['ann']['data'] ?? null) && array_is_list($res['ann']['data']) ? $res['ann']['data'] : [];
    $tablesReady = ($res['push']['status'] ?? 0) !== 404;
    $pushAccounts = $tablesReady ? admin_count($res['push']) : 0;
}

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
function nr_mask(?string $r): string
{
    $r = (string)$r;
    if ($r === '') return '—';
    if (str_contains($r, '@')) { [$u, $d] = explode('@', $r, 2); return mb_substr($u, 0, 2) . '•••@' . $d; }
    if (preg_match('/^\+?\d{10,13}$/', $r)) return substr($r, 0, 3) . '•••••' . substr($r, -4);
    return $r;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Notifications</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
    <style>
        .nr-views { display: inline-flex; gap: 4px; padding: 3px; border: 1.2px solid #e5e7eb; border-radius: 7px; background: #fbfcfe; flex-wrap: wrap; }
        .nr-views a { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 5px; font-size: .8rem; font-weight: 500; color: #6b7a90; white-space: nowrap; }
        .nr-views a:hover { color: #185fa5; }
        .nr-views a.active { background: #185fa5; color: #fff; }
        .nr-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; padding: 16px 20px 4px; }
        .nr-stat { border: 1px solid #e9eef5; border-radius: 10px; padding: 12px 14px; background: #fff; }
        .nr-stat-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .4px; color: #6b7a90; font-weight: 600; }
        .nr-stat-value { font-size: 1.4rem; font-weight: 700; color: #0b3a57; line-height: 1.3; }
        .nr-stat-sub { font-size: .75rem; color: #8a94a6; }
        .nr-stat.is-failed .nr-stat-value { color: #c0392b; }
        .nr-stat.is-sent .nr-stat-value { color: #1e8449; }
        .nr-ch { display: inline-flex; align-items: center; gap: 5px; font-size: .78rem; font-weight: 600; }
        .nr-recipient { font-family: monospace; font-size: .78rem; color: #6b7a90; }
        /* Status cell: pill (+ attempts) on one line, reason in a soft callout below */
        .nr-status { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; max-width: 300px; }
        .nr-status-top { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
        .nr-status-meta { display: inline-flex; align-items: center; gap: 4px; font-size: .74rem; color: #8a94a6; }
        .nr-status-meta i { font-size: .72rem; }
        .nr-reason { display: flex; align-items: flex-start; gap: 7px; width: 100%; padding: 7px 10px; border-radius: 8px; font-size: .74rem; line-height: 1.45; white-space: normal; }
        .nr-reason i { flex: 0 0 auto; font-size: .8rem; margin-top: 2px; }
        .nr-reason span { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .nr-reason.is-failed  { background: #fef3f2; border: 1px solid #fee4e2; color: #7a271a; }
        .nr-reason.is-failed i { color: #d92d20; }
        .nr-reason.is-skipped { background: #fffaeb; border: 1px solid #fef0c7; color: #7a4a0e; }
        .nr-reason.is-skipped i { color: #b54708; }
        .nr-sub { font-size: .76rem; color: #8a94a6; }
        .nr-setup { margin: 20px; }
        .nr-email-frame { width: 100%; height: 460px; border: 1px solid #e9eef5; border-radius: 8px; background: #fff; }
        .nr-msg { white-space: pre-wrap; background: #f7f9fc; border: 1px solid #e9eef5; border-radius: 8px; padding: 12px; font-size: .85rem; margin: 0; }
        .nr-tpl-group { padding: 6px 20px 2px; font-size: .74rem; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; color: #6b7a90; background: #f8fafc; }
        .nr-tpl-body { white-space: pre-wrap; font-size: .82rem; color: #3b4a5e; max-width: 520px; }
        .nr-counter { font-size: .75rem; color: #8a94a6; }
        .nr-counter.over { color: #c0392b; font-weight: 600; }
        .nr-ann-form .form-label { font-size: .8rem; font-weight: 600; }
        .nr-chips code { background: #eef4fb; color: #185fa5; border-radius: 4px; padding: 1px 5px; margin: 0 3px 3px 0; display: inline-block; font-size: .76rem; cursor: pointer; }
    </style>
    <link rel="stylesheet" href="assets/css/es-admin.css?v=8">
    <link rel="stylesheet" href="assets/css/es-records.css?v=7">
    <link rel="stylesheet" href="assets/css/analytics.css?v=8">
    <link rel="stylesheet" href="assets/css/appointments-records.css?v=10">
</head>

<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>

    <main class="nxl-container">
        <div class="nxl-content">

            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">Notifications</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                        <li class="breadcrumb-item">Notifications</li>
                    </ul>
                </div>
            </div>

            <div class="main-content an-page ar-page">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card stretch stretch-full" data-es-region="list">

                            <div class="card-header flex-wrap gap-2">
                                <h5 class="card-title mb-0">
                                    <?= $view === 'templates' ? 'Message Templates' : ($view === 'announcements' ? 'Patient Announcements' : ($isOwner ? 'Notification Log' : 'Notification Log — ' . $e($_SESSION['branch_name'] ?? 'My Branch'))) ?>
                                    <?php if ($view === 'log' && $tablesReady): ?><span class="an-pill info ms-2"><?= $total ?> <?= $filtersOn ? 'found' : 'total' ?></span><?php endif; ?>
                                    <span class="rec-card-sub"><?= $view === 'templates' ? 'The wording of each email, SMS and push message' : ($view === 'announcements' ? 'Short notices shown to patients in the Patient Portal' : 'Every email, SMS and push message the system sent') ?></span>
                                </h5>
                                <?php if ($isOwner): ?>
                                <div class="nr-views" role="tablist" aria-label="Notifications view">
                                    <a href="notification-records.php" role="tab" aria-selected="<?= $view === 'log' ? 'true' : 'false' ?>" class="<?= $view === 'log' ? 'active' : '' ?>"><i class="feather-list"></i> Delivery Log</a>
                                    <a href="notification-records.php?view=templates" role="tab" aria-selected="<?= $view === 'templates' ? 'true' : 'false' ?>" class="<?= $view === 'templates' ? 'active' : '' ?>"><i class="feather-edit-3"></i> Templates</a>
                                    <a href="notification-records.php?view=announcements" role="tab" aria-selected="<?= $view === 'announcements' ? 'true' : 'false' ?>" class="<?= $view === 'announcements' ? 'active' : '' ?>"><i class="feather-volume-2"></i> Announcements</a>
                                </div>
                                <?php endif; ?>
                            </div>

<?php if (!$tablesReady): ?>
                            <div class="alert alert-warning nr-setup mb-4">
                                <strong>Notifications are not set up yet.</strong>
                                The notification tables do not exist in the database. Run
                                <code>database/proposed/2026-11-13_notification_system.sql</code> in the Supabase SQL Editor, then reload this page.
                                Until then, appointment emails, SMS and push are not sent and SMS verification is unavailable.
                            </div>
<?php elseif ($view === 'log'): ?>
                            <div class="rec-summary" role="list" aria-label="Statistics for the selected filters">
                                <div class="rec-stat " role="listitem">
                                    <span class="rec-stat-icon"><i class="feather-send"></i></span>
                                    <span class="rec-stat-body">
                                        <span class="rec-stat-value d-block"><?= number_format($stats['all']) ?></span>
                                        <span class="rec-stat-label d-block">Total</span>
                                        <span class="rec-stat-hint d-block">Email <?= number_format($stats['ch_email']) ?> · SMS <?= number_format($stats['ch_sms']) ?> · Push <?= number_format($stats['ch_push']) ?><?= $fStatus ? ' (' . $statusLabels[$fStatus] . ')' : '' ?></span>
                                    </span>
                                </div>
                                <div class="rec-stat tone-green" role="listitem">
                                    <span class="rec-stat-icon"><i class="feather-check-circle"></i></span>
                                    <span class="rec-stat-body">
                                        <span class="rec-stat-value d-block"><?= number_format($stats['sent']) ?></span>
                                        <span class="rec-stat-label d-block">Sent</span>
                                        <span class="rec-stat-hint d-block"><?= $successRate === null ? 'No attempts yet' : $successRate . '% success rate' ?></span>
                                    </span>
                                </div>
                                <div class="rec-stat tone-red" role="listitem">
                                    <span class="rec-stat-icon"><i class="feather-alert-circle"></i></span>
                                    <span class="rec-stat-body">
                                        <span class="rec-stat-value d-block"><?= number_format($stats['failed']) ?></span>
                                        <span class="rec-stat-label d-block">Failed</span>
                                        <span class="rec-stat-hint d-block">Provider or carrier errors</span>
                                    </span>
                                </div>
                                <div class="rec-stat tone-amber" role="listitem">
                                    <span class="rec-stat-icon"><i class="feather-slash"></i></span>
                                    <span class="rec-stat-body">
                                        <span class="rec-stat-value d-block"><?= number_format($stats['skipped']) ?></span>
                                        <span class="rec-stat-label d-block">Skipped</span>
                                        <span class="rec-stat-hint d-block">No verified email / valid number<?= $stats['sending'] ? ' · ' . $stats['sending'] . ' in progress' : '' ?></span>
                                    </span>
                                </div>
                            </div>

                            <div class="list-toolbar">
                                <?= listSearchControl($search, 'Patient, recipient, subject or ref no.') ?>
                                <?= listDateRangeControl($dateFrom, $dateTo) ?>
                                <?= listSelectControl('Channel', 'channel', ['' => 'All Channels'] + $channelLabels, $fChannel) ?>
                                <?= listSelectControl('Type', 'type', ['' => 'All Types'] + ($isOwner ? $typeLabels : array_diff_key($typeLabels, ['otp' => 1])), $fType) ?>
                                <?= listSelectControl('Status', 'status', ['' => 'All Statuses'] + $statusLabels, $fStatus) ?>
                                <?php if ($isOwner): ?>
                                <div class="list-field"><label class="list-label" for="filterBranch">Branch</label>
                                <select id="filterBranch" class="js-custom-select" data-cs-filter data-list-param="branch" onchange="applyFilters()">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $b): ?>
                                    <option value="<?= (int)$b['branch_id'] ?>" <?= (int)$b['branch_id'] === $fBranch ? 'selected' : '' ?>><?= $e($b['branch_name']) ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                                <?php endif; ?>
                                <?= listResetControl('notification-records.php', $filtersOn) ?>
                            </div>

                            <div class="card-body custom-card-action p-0" style="overflow:visible;">
                                <!-- overflow:visible (same as appointments-schedule.php) so the ⋮ menu opens outside the table instead of being clipped -->
                                <div class="table-responsive" style="overflow:visible;">
                                    <table class="table table-hover align-middle mb-0 rec-table ar-table">
                                        <thead class="table-light">
                                            <tr>
                                                <?= listTh('Date & Time', 'newest', 'oldest', $sortKey, $sortBase, 'style="min-width:120px;"') ?>
                                                <th style="min-width:170px;">Type</th>
                                                <th style="min-width:160px;">Patient / Recipient</th>
                                                <th>Channel</th>
                                                <?php if ($isOwner): ?><th>Branch</th><?php endif; ?>
                                                <th style="min-width:130px;">Status</th>
                                                <th class="rec-col-action text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (!$rows): ?>
                                            <?= $listOk
                                                ? listEmpty($isOwner ? 7 : 6, $filtersOn > 0, 'No notifications yet', 'Appointment emails, SMS and push messages are listed here once they are sent.', 'feather-bell', 'notification-records.php')
                                                : listEmpty($isOwner ? 7 : 6, false, 'The notification log could not be loaded', 'Please check your connection and refresh the page.', 'danger:feather-alert-triangle') ?>
                                        <?php endif; ?>
                                        <?php foreach ($rows as $r):
                                            $ts = strtotime($r['created_at']);
                                            $st = (string)$r['status'];
                                            $canRetry = $st === 'failed' && (int)$r['attempts'] < 3 && $r['notification_type'] !== 'otp';
                                            $patient = $r['patients']['full_name'] ?? null;
                                        ?>
                                            <tr>
                                                <td><?= recWhen(date('M d, Y', $ts), date('h:i A', $ts)) ?></td>
                                                <td>
                                                    <span class="d-block rec-strong"><?= $e($typeLabels[$r['notification_type']] ?? $r['notification_type']) ?></span>
                                                    <?php if (!empty($r['appointments']['appointment_code'])): ?><span class="nr-sub">Ref <?= $e($r['appointments']['appointment_code']) ?></span><?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="d-block rec-strong"><?= $patient ? $e($patient) : '<span class="rec-muted">—</span>' ?></span>
                                                    <span class="nr-recipient"><?= $e(nr_mask($r['recipient'])) ?></span>
                                                </td>
                                                <td><span class="nr-ch"><i class="<?= $channelIcons[$r['channel']] ?? 'feather-send' ?>"></i><?= $e($channelLabels[$r['channel']] ?? $r['channel']) ?></span></td>
                                                <?php if ($isOwner): ?><td><?= recBranch($r['branches']['branch_name'] ?? '', $branches ?? []) ?></td><?php endif; ?>
                                                <td>
                                                    <!-- Status first; the reason sits under it in a soft callout (full text in View Details) -->
                                                    <div class="nr-status">
                                                        <div class="nr-status-top">
                                                            <?= recPill($statusLabels[$st] ?? $st, recToneFromBadge($statusClass[$st] ?? '')) ?>
                                                            <?php if ((int)$r['attempts'] > 1): ?><span class="nr-status-meta"><i class="feather-repeat"></i><?= (int)$r['attempts'] ?> attempts</span><?php endif; ?>
                                                        </div>
                                                        <?php if ($r['channel'] === 'sms' && $st === 'sent' && !empty($r['provider_status'])): ?><span class="nr-status-meta">Carrier: <?= $e($r['provider_status']) ?></span><?php endif; ?>
                                                        <?php if (in_array($st, ['failed', 'skipped'], true) && !empty($r['failure_reason'])): ?>
                                                            <div class="nr-reason is-<?= $st ?>" title="<?= $e($r['failure_reason']) ?>">
                                                                <i class="<?= $st === 'failed' ? 'feather-alert-circle' : 'feather-info' ?>" aria-hidden="true"></i>
                                                                <span><?= $e(mb_strimwidth($r['failure_reason'], 0, 140, '…')) ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="rec-col-action">
                                                    <div class="dropdown">
                                                        <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-label="Actions for this notification"><i class="feather-more-vertical"></i></a>
                                                        <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                            <a href="javascript:void(0);" class="dropdown-item" onclick="nrView(<?= (int)$r['log_id'] ?>)"><i class="feather-eye"></i> View Details</a>
                                                            <?php if ($canRetry): ?>
                                                            <a href="javascript:void(0);" class="dropdown-item" onclick="nrRetry(<?= (int)$r['log_id'] ?>)"><i class="feather-refresh-cw"></i> Retry Sending</a>
                                                            <?php endif; ?>
                                                            <?php if ($r['channel'] === 'sms' && $st === 'sent'): ?>
                                                            <a href="javascript:void(0);" class="dropdown-item" onclick="nrStatus(<?= (int)$r['log_id'] ?>)"><i class="feather-activity"></i> Check Delivery Status</a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="card-footer ar-foot d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <?= listFootCount($from, $to, $total, 'notification', 'notification-records.php', $filtersOn > 0) ?>
                                <?= listPager($pageUrl, $page, $totalPages) ?>
                            </div>

<?php elseif ($view === 'templates'): ?>
                            <div class="rec-note mx-4 mt-3 mb-2">
                                <i class="feather-info"></i>
                                <span>The text used for each event and channel. SMS and push never include treatments or other clinical details — only the placeholders below are allowed.
                                A switched-off template means that message is not sent on that channel.</span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0 rec-table ar-table">
                                        <thead class="table-light"><tr><th>Channel</th><th>Subject / Title</th><th>Message</th><th>Status</th><th>Last Updated</th><th class="rec-col-action text-end">Action</th></tr></thead>
                                        <tbody>
                                        <?php $lastType = null; foreach ($templates as $t):
                                            if ($t['notification_type'] !== $lastType): $lastType = $t['notification_type']; ?>
                                            <tr><td colspan="6" class="nr-tpl-group"><?= $e($typeLabels[$lastType] ?? $lastType) ?></td></tr>
                                        <?php endif; ?>
                                            <tr>
                                                <td><span class="nr-ch"><i class="<?= $channelIcons[$t['channel']] ?? 'feather-send' ?>"></i><?= $e($channelLabels[$t['channel']] ?? $t['channel']) ?></span></td>
                                                <td><?= $t['channel'] === 'sms' ? '<span class="rec-muted">—</span>' : '<span class="rec-strong">' . $e($t['subject']) . '</span>' ?></td>
                                                <td><div class="nr-tpl-body"><?= $e($t['message_body']) ?></div></td>
                                                <td><?= recPill($t['is_active'] ? 'On' : 'Off', $t['is_active'] ? 'up' : 'slate') ?></td>
                                                <td><?php $tu = strtotime($t['updated_at']); ?><?= recWhen(date('M d, Y', $tu), date('h:i A', $tu)) ?><?= !empty($t['updated_by']) ? '<span class="rec-date-sub d-block">by ' . $e($t['updated_by']) . '</span>' : '' ?></td>
                                                <td class="rec-col-action"><button type="button" class="btn btn-sm btn-light es-btn-compact" data-tpl="<?= $e(json_encode($t, JSON_UNESCAPED_UNICODE)) ?>" onclick="nrEditTpl(this)"><i class="feather-edit-2 me-1"></i>Edit</button></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (!$templates): ?><?= listEmpty(6, false, 'No templates saved', 'The built-in default texts are used until a template is edited.', 'feather-edit-3') ?><?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

<?php else: ?>
                            <div class="card-body">
                                <form class="nr-ann-form row g-3" id="nrAnnForm" onsubmit="return nrPublish(event)" novalidate>
                                    <div class="col-md-6">
                                        <label class="form-label" for="nrAnnTitle">Title</label>
                                        <input type="text" class="form-control" id="nrAnnTitle" maxlength="80" required placeholder="e.g. Holiday schedule">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label" for="nrAnnBranch">Show to patients of</label>
                                        <select class="form-select js-custom-select" data-cs-float id="nrAnnBranch">
                                            <option value="0">All branches</option>
                                            <?php foreach ($branches as $b): ?><option value="<?= (int)$b['branch_id'] ?>"><?= $e($b['branch_name']) ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label" for="nrAnnEnds">Show until (optional)</label>
                                        <input type="date" class="form-control js-date-picker" id="nrAnnEnds" min="<?= date('Y-m-d') ?>" placeholder="No end date">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="nrAnnMsg">Message <span class="nr-counter" id="nrAnnCount">0 / 500</span></label>
                                        <textarea class="form-control" id="nrAnnMsg" rows="3" maxlength="500" required placeholder="Do not include patient-specific or clinical information."></textarea>
                                    </div>
                                    <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="nrAnnPush">
                                            <label class="form-check-label" for="nrAnnPush">Also send as a push notification (<?= number_format($pushAccounts) ?> patient account<?= $pushAccounts === 1 ? '' : 's' ?> with push on)</label>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-primary es-btn-compact"><i class="feather-send me-1"></i>Publish Announcement</button>
                                    </div>
                                </form>
                            </div>
                            <div class="card-body p-0 border-top">
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0 rec-table ar-table">
                                        <thead class="table-light"><tr><th>Published</th><th>Title & Message</th><th>Audience</th><th>Status</th><th class="rec-col-action text-end">Action</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($announcements as $a):
                                            $expired = !empty($a['ends_at']) && strtotime($a['ends_at']) <= time();
                                            $live = $a['is_active'] && !$expired;
                                        ?>
                                            <tr>
                                                <td><?= recWhen(date('M d, Y', strtotime($a['created_at'])), date('h:i A', strtotime($a['created_at']))) ?></td>
                                                <td><span class="d-block rec-strong"><?= $e($a['title']) ?></span><div class="nr-tpl-body text-muted"><?= $e($a['message']) ?></div></td>
                                                <td><?= !empty($a['branches']['branch_name']) ? recBranch($a['branches']['branch_name'], $branches ?? []) : '<span class=\"ar-branch\">All branches</span>' ?></td>
                                                <td>
                                                    <?= recPill($live ? 'Active' : ($expired && $a['is_active'] ? 'Expired' : 'Ended'), $live ? 'up' : 'slate') ?>
                                                    <?php if (!empty($a['ends_at'])): ?><span class="nr-sub d-block">until <?= date('M d, Y', strtotime($a['ends_at'] . ' -1 second')) ?></span><?php endif; ?>
                                                </td>
                                                <td class="rec-col-action"><?php if ($live): ?><button type="button" class="btn btn-sm btn-light" onclick="nrEndAnn(<?= (int)$a['announcement_id'] ?>, this)"><i class="feather-x-circle me-1"></i>End</button><?php endif; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (!$announcements): ?><?= listEmpty(5, false, 'No announcements yet', 'Use the form above to publish a notice to patients.', 'feather-volume-2') ?><?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
<?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- DETAILS MODAL -->
    <div class="modal fade es-detail-modal ar-detail-modal" id="nrViewModal" tabindex="-1" aria-labelledby="nrViewTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down apt-record-dialog">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-bell"></i></div>
                        <div><h5 class="apt-record-title mb-0" id="nrViewTitle">Notification Details</h5><div class="apt-record-meta" id="nrViewMeta">—</div></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-record-body p-0"><div class="ar-view-body" id="nrViewBody"></div></div>
                <div class="modal-footer apt-record-footer" id="nrViewFooter">
                    <button type="button" class="btn btn-primary" id="nrViewRetry" hidden><i class="feather-refresh-cw me-1"></i>Retry Sending</button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- TEMPLATE EDIT MODAL (owner) -->
    <?php if ($isOwner): ?>
    <div class="modal fade es-detail-modal" id="nrTplModal" tabindex="-1" aria-labelledby="nrTplTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-form-dialog rec-form-dialog">
            <form class="modal-content apt-record-content" id="nrTplForm" onsubmit="return nrSaveTpl(event)" novalidate>
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-edit-3"></i></div>
                        <div class="min-w-0">
                            <h5 class="apt-record-title mb-0" id="nrTplTitle">Edit Template</h5>
                            <div class="apt-record-meta">Change the wording patients receive for this message.</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-form-body">
                    <input type="hidden" id="nrTplId">
                    <div class="apt-field" id="nrTplSubjectWrap">
                        <label class="apt-label" for="nrTplSubject" id="nrTplSubjectLabel">Subject</label>
                        <input type="text" class="form-control" id="nrTplSubject">
                    </div>
                    <div class="apt-field">
                        <label class="apt-label d-flex justify-content-between" for="nrTplBody"><span>Message</span><span class="nr-counter" id="nrTplCount"></span></label>
                        <textarea class="form-control" id="nrTplBody" rows="5"></textarea>
                        <div class="nr-chips rec-help" id="nrTplChips"></div>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="nrTplActive">
                        <label class="form-check-label" for="nrTplActive">Send this message</label>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer apt-form-footer">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4"><i class="feather-save me-1"></i> Save Template</button>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    </div>
                    <button type="button" class="btn btn-link text-danger" onclick="nrResetTpl()">Reset to default</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/js/admin.js?v=7"></script>
    <script src="assets/js/es-swal.js?v=3"></script>
    <script src="assets/js/es-ajax.js?v=7"></script>
    <script src="assets/js/custom-select.js?v=3"></script>
    <script src="assets/js/es-records.js?v=3"></script>
    <script>
    const NR_API = 'api/notification_admin.php';
    const NR_TYPES = <?= json_encode($typeLabels) ?>;
    const NR_CH = <?= json_encode($channelLabels) ?>;
    const NR_STATUS = <?= json_encode($statusLabels) ?>;

    function applyFilters() { window._applyFilters('notification-records.php', { branch: 'filterBranch' }); }

    const nrToast = (msg, type) => window.esToast ? esToast(msg, type) : alert(msg);
    function nrPost(body) {
        return fetch(NR_API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(r => r.json().catch(() => ({ success: false, message: 'Unexpected server response.' })))
            .catch(() => ({ success: false, message: 'Network error. Please try again.' }));
    }
    const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
    const fmt = (iso) => iso ? new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : '—';

    // ── View details (all text set with textContent; email HTML in a sandboxed iframe) ──
    function nrView(id) {
        const body = document.getElementById('nrViewBody');
        body.replaceChildren(el('div', 'p-4 text-center text-muted', 'Loading…'));
        document.getElementById('nrViewRetry').hidden = true;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('nrViewModal')).show();
        fetch(NR_API + '?log_id=' + encodeURIComponent(id), { credentials: 'same-origin' }).then(r => r.json()).then(res => {
            if (!res.success) { body.replaceChildren(el('div', 'p-4 text-danger', res.message || 'Could not load the notification.')); return; }
            const d = res.data;
            document.getElementById('nrViewMeta').textContent = (NR_TYPES[d.notification_type] || d.notification_type) + ' · ' + (NR_CH[d.channel] || d.channel);
            const rows = [
                ['Status', (NR_STATUS[d.status] || d.status) + (d.attempts > 1 ? ' (' + d.attempts + ' attempts)' : '')],
                ['Created', fmt(d.created_at)], ['Sent', fmt(d.sent_at)],
                ['Patient', d.patients ? d.patients.full_name : ''], ['Recipient', d.recipient],
                ['Branch', d.branches ? d.branches.branch_name : ''],
                ['Appointment', d.appointments ? d.appointments.appointment_code + ' · ' + d.appointments.appointment_date + ' ' + String(d.appointments.appointment_time || '').slice(0, 5) + ' · now ' + d.appointments.status : ''],
                ['Provider reference', d.provider_reference_id],
                ['Carrier status', d.provider_status ? d.provider_status + (d.status_checked_at ? ' (checked ' + fmt(d.status_checked_at) + ')' : '') : ''],
                ['Failure reason', d.failure_reason],
            ];
            const grid = el('div', 'ar-cards-grid');
            const card = (icon, title, content, wide) => {
                const s = el('section', 'ar-card' + (wide ? ' ar-card-wide' : '')); const h = el('div', 'ar-card-header');
                const ic = el('span', 'ar-card-icon'); ic.append(el('i', icon)); h.append(ic, el('h6', 'ar-card-title', title));
                const b = el('div', 'ar-card-fields'); b.append(content); s.append(h, b); return s;
            };
            const f = document.createDocumentFragment();
            rows.forEach(([k, v]) => { if (v === null || v === undefined || String(v).trim() === '' || v === '—') return; const r = el('div', 'ar-card-field'); r.append(el('span', 'ar-card-label', k), el('span', 'ar-card-value', String(v))); f.append(r); });
            grid.append(card('feather-info', 'Delivery', f, true));
            let content;
            if (d.channel === 'email' && d.message) {
                content = document.createDocumentFragment();
                if (d.subject) content.append(el('p', 'fw-semibold mb-2', 'Subject: ' + d.subject));
                const fr = el('iframe', 'nr-email-frame'); fr.setAttribute('sandbox', ''); fr.setAttribute('title', 'Email preview'); fr.srcdoc = d.message;
                content.append(fr);
            } else if (d.channel === 'push' && d.message) {
                let m = {}; try { m = JSON.parse(d.message); } catch (e) { m = { body: d.message }; }
                content = el('pre', 'nr-msg', (m.title ? m.title + '\n' : '') + (m.body || ''));
            } else {
                content = el('pre', 'nr-msg', d.message || 'No message stored.');
            }
            grid.append(card('feather-file-text', 'Message', content, true));
            body.replaceChildren(grid);
            const retry = document.getElementById('nrViewRetry');
            retry.hidden = !(d.status === 'failed' && d.attempts < 3 && d.notification_type !== 'otp');
            retry.onclick = () => nrRetry(d.log_id);
        }).catch(() => body.replaceChildren(el('div', 'p-4 text-danger', 'Network error. Please try again.')));
    }

    function nrRetry(id) {
        Swal.fire({
            icon: 'question', iconHtml: '<i class="feather-refresh-cw es-swal-glyph"></i>', title: 'Retry this notification?',
            html: '<p class="es-swal-text">It is sent again only if the appointment still matches the message. A message is never sent twice.</p>',
            showCancelButton: true, confirmButtonText: 'Retry Now', cancelButtonText: 'Go Back', buttonsStyling: false, customClass: ES_SWAL_CLASS,
        }).then(r => {
            if (!r.isConfirmed) return;
            nrPost({ action: 'retry', log_id: id }).then(res => { nrToast(res.message, res.success ? 'success' : 'error'); if (window.esRefresh) esRefresh(); });
        });
    }

    function nrStatus(id) {
        nrPost({ action: 'refresh_status', log_id: id }).then(res => { nrToast(res.message, res.success ? 'info' : 'error'); if (res.success && window.esRefresh) esRefresh(); });
    }

    // ── Templates (owner) ──
    const NR_PH = { appointment: ['first_name', 'date', 'time', 'branch', 'branch_phone', 'code', 'old_date', 'old_time'], announcement: ['title', 'message'] };
    const NR_LIMIT = { sms: 300, push: 240, email: 1500 };
    let nrTpl = null;
    function nrCount() {
        if (!nrTpl) return;
        const n = document.getElementById('nrTplBody').value.length, max = NR_LIMIT[nrTpl.channel] || 1500, c = document.getElementById('nrTplCount');
        c.textContent = n + ' / ' + max + (nrTpl.channel === 'sms' ? ' · one SMS is about 160 characters after placeholders are filled' : '');
        c.classList.toggle('over', n > max);
    }
    function nrEditTpl(btn) {
        nrTpl = JSON.parse(btn.dataset.tpl);
        document.getElementById('nrTplTitle').textContent = (NR_TYPES[nrTpl.notification_type] || nrTpl.notification_type) + ' — ' + (NR_CH[nrTpl.channel] || nrTpl.channel);
        document.getElementById('nrTplId').value = nrTpl.template_id;
        document.getElementById('nrTplSubjectWrap').hidden = nrTpl.channel === 'sms';
        document.getElementById('nrTplSubjectLabel').textContent = nrTpl.channel === 'push' ? 'Title' : 'Subject';
        document.getElementById('nrTplSubject').value = nrTpl.subject || '';
        document.getElementById('nrTplBody').value = nrTpl.message_body || '';
        document.getElementById('nrTplActive').checked = !!nrTpl.is_active;
        const chips = document.getElementById('nrTplChips'); chips.replaceChildren(document.createTextNode('Placeholders (click to insert): '));
        (nrTpl.notification_type === 'announcement' ? NR_PH.announcement : NR_PH.appointment).forEach(p => {
            const c = el('code', '', '{' + p + '}');
            c.onclick = () => { const t = document.getElementById('nrTplBody'); const s = t.selectionStart ?? t.value.length; t.value = t.value.slice(0, s) + '{' + p + '}' + t.value.slice(t.selectionEnd ?? s); t.focus(); nrCount(); };
            chips.append(c);
        });
        nrCount();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('nrTplModal')).show();
    }
    document.getElementById('nrTplBody')?.addEventListener('input', nrCount);
    function nrSaveTpl(ev) {
        ev.preventDefault();
        const btn = ev.submitter; if (btn) btn.disabled = true;
        nrPost({ action: 'save_template', template_id: +document.getElementById('nrTplId').value, subject: document.getElementById('nrTplSubject').value,
                 message_body: document.getElementById('nrTplBody').value, is_active: document.getElementById('nrTplActive').checked })
            .then(res => { if (btn) btn.disabled = false; nrToast(res.message, res.success ? 'success' : 'error'); if (res.success && window.esRefresh) esRefresh(); });
        return false;
    }
    function nrResetTpl() {
        Swal.fire({ icon: 'warning', title: 'Reset to the default text?', html: '<p class="es-swal-text">Your changes to this template will be replaced and it will be switched on.</p>',
            showCancelButton: true, confirmButtonText: 'Reset Template', cancelButtonText: 'Go Back', buttonsStyling: false, customClass: ES_SWAL_TONE('danger') })
        .then(r => { if (!r.isConfirmed) return; nrPost({ action: 'reset_template', template_id: +document.getElementById('nrTplId').value })
            .then(res => { nrToast(res.message, res.success ? 'success' : 'error'); if (res.success && window.esRefresh) esRefresh(); }); });
    }

    // ── Announcements (owner) ──
    document.getElementById('nrAnnMsg')?.addEventListener('input', (e) => { document.getElementById('nrAnnCount').textContent = e.target.value.length + ' / 500'; });
    function nrPublish(ev) {
        ev.preventDefault();
        const title = document.getElementById('nrAnnTitle').value.trim(), message = document.getElementById('nrAnnMsg').value.trim();
        if (!title || !message) { nrToast('Enter a title and a message.', 'error'); return false; }
        const push = document.getElementById('nrAnnPush').checked;
        Swal.fire({ icon: 'question', title: 'Publish this announcement?',
            html: '<p class="es-swal-text">Patients will see it in the Patient Portal' + (push ? ', and devices with push on will get a notification right away' : '') + '.</p>',
            showCancelButton: true, confirmButtonText: 'Publish', cancelButtonText: 'Go Back', buttonsStyling: false, customClass: ES_SWAL_CLASS })
        .then(r => {
            if (!r.isConfirmed) return;
            nrPost({ action: 'create_announcement', title, message, push, branch_id: +document.getElementById('nrAnnBranch').value, ends_at: document.getElementById('nrAnnEnds').value })
                .then(res => { nrToast(res.message, res.success ? 'success' : 'error'); if (res.success) { document.getElementById('nrAnnForm').reset(); if (window.esRefresh) esRefresh(); } });
        });
        return false;
    }
    function nrEndAnn(id, btn) {
        Swal.fire({ icon: 'warning', title: 'End this announcement?', html: '<p class="es-swal-text">It will stop appearing for patients who have not seen it yet.</p>',
            showCancelButton: true, confirmButtonText: 'End Announcement', cancelButtonText: 'Go Back', buttonsStyling: false, customClass: ES_SWAL_TONE('danger') })
        .then(r => { if (!r.isConfirmed) return; btn.disabled = true;
            nrPost({ action: 'end_announcement', announcement_id: id }).then(res => { nrToast(res.message, res.success ? 'success' : 'error'); if (window.esRefresh) esRefresh(); }); });
    }
    </script>
</body>
</html>
