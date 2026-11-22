<?php
// =============================================================
//  settings.php — Settings (Owner / Admin / Dentist)
//
//  One place for system-level settings, written for a clinic owner (not an
//  IT person): plain words first, technical details folded away. It REUSES
//  the existing modules instead of copying them: templates in Notification
//  Log, accounts in Account Records, history in Audit Records. (Branch and
//  clinic details live in Branch Records only — not repeated here.)
//
//  Pages (one at a time, switched by assets/js/es-settings.js):
//    backup    Backup & Data          Owner      structure / complete backup
//                                                (api/settings_backup.php) + live status
//    security  Password & Security    all staff  change own password (form opens on
//                                                its button), sign-ins, access
//    notify    Notifications          Owner/Admin channel status (yes/no only), reminders
//    system    System Status          all staff  connection, version (no secrets)
//    audit     Activity History       all staff  links to Audit Records
//
//  Not a sidebar module (every staff account has Settings); each section
//  is shown or hidden here AND its API checks the role again.
// =============================================================
define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
require_once __DIR__ . '/dbconfig.php';
require_once __DIR__ . '/controllers/settings_helper.php';

$me       = getCurrentUser();
$role     = $me['role'];
$isOwner  = $role === 'owner';
$isAdmin  = $role === 'admin';
$myId     = (int)$me['user_id'];
admin_release_session();

// ── My recent sign-ins (one request; also tells non-owners the database answered) ──
$res = admin_prefetch([
    'logins' => admin_q('audit_logs', 'select=created_at,action,after_data&module=eq.authentication&user_id=eq.' . $myId . '&order=created_at.desc&limit=5'),
], []);
// A successful result has 'error' => null, so test it with empty() (not ?? true)
$resOk     = fn(?array $r) => is_array($r) && empty($r['error']) && is_array($r['data'] ?? null) && array_is_list($r['data']);
$dbOk      = $resOk($res['logins'] ?? null);
$logins    = $dbOk ? $res['logins']['data'] : [];
$lastLogin = null;
foreach ($logins as $l) if (($l['action'] ?? '') === 'LOGIN_SUCCESS') { $lastLogin = $l; break; }

$e    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$when = fn(?string $t) => $t ? date('M j, Y · g:i A', strtotime($t)) : '—';

// What this account can open (the same rules as the sidebar)
require_once __DIR__ . '/controllers/module_access.php';
$myModules = [];
foreach (ES_MODULES as $key => $m) if (esCanUseModule($key)) $myModules[] = [$m[0], $m[1]];

// Notification channels: set up yes / no — never the values
$smsKey   = defined('UNISMS_API_KEY') ? (string)UNISMS_API_KEY : '';
$channels = [
    ['Email',         'feather-mail',           setSecretConfigured('smtp_username') && setSecretConfigured('smtp_app_password'),
        'Confirmations, reminders and changes sent to the patient\'s email.'],
    ['Text message',  'feather-message-circle', $smsKey !== '' && !str_starts_with($smsKey, 'sk_your'),
        'Short SMS updates and phone number verification codes.'],
    ['Phone alerts',  'feather-bell',           setSecretConfigured('vapid_public_key') && setSecretConfigured('vapid_private_key_pem'),
        'Pop-up alerts for patients who turned them on in the Patient Portal.'],
];

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$serverSw = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
$serverLabel = PHP_OS_FAMILY . (stripos($serverSw, 'apache') !== false ? ' · Apache' : (stripos($serverSw, 'nginx') !== false ? ' · nginx' : ''));

// [id, icon, menu label, one-line hint]
$sections = array_values(array_filter([
    $isOwner ? ['backup', 'feather-database', 'Backup & Data', 'Download a copy of your clinic data'] : null,
    ['security', 'feather-lock',     'Password & Security', 'Your password and sign-ins'],
    ($isOwner || $isAdmin) ? ['notify', 'feather-bell', 'Notifications', 'Messages sent to patients'] : null,
    ['system',   'feather-activity', 'System Status',       'Is everything working?'],
    ['audit',    'feather-clock',    'Activity History',    'Who did what, and when'],
]));
$zipAes = setZipAesAvailable();

/** Card header: icon + title + description (+ optional right-side badge). */
$cardHead = function (string $icon, string $tone, string $title, string $desc, string $badge = '') use ($e): string {
    return '<div class="set-card-head"><span class="set-icon set-tone-' . $tone . '"><i class="' . $icon . '"></i></span>'
         . '<div class="set-card-titles"><h3 class="set-card-title">' . $e($title) . $badge . '</h3>'
         . ($desc !== '' ? '<p class="set-card-desc">' . $desc . '</p>' : '') . '</div></div>';
};
/** Link row to another module. */
$linkRow = fn(string $href, string $icon, string $title, string $desc) =>
    '<a class="set-link" href="' . $e($href) . '"><span class="set-link-icon"><i class="' . $icon . '"></i></span>'
    . '<span class="set-link-text"><strong>' . $e($title) . '</strong><small>' . $e($desc) . '</small></span>'
    . '<i class="feather-chevron-right set-link-arrow"></i></a>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Settings</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=3">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
    <link rel="stylesheet" href="assets/css/es-admin.css?v=7">
    <link rel="stylesheet" href="assets/css/es-settings.css?v=5">
</head>
<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>
    <main class="nxl-container">
        <div class="nxl-content">
            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">Settings</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= $isOwner ? 'dashboard.php' : $e(esModuleHome() ?? 'dashboard.php') ?>">Home</a></li>
                        <li class="breadcrumb-item">Settings</li>
                    </ul>
                </div>
            </div>

            <div class="main-content set-page" id="setPage" data-owner="<?= $isOwner ? '1' : '0' ?>">
                <div class="row"><div class="col-lg-12">
                <!-- Same page container as the other admin pages (card + card-header) -->
                <div class="card stretch stretch-full set-shell">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            Settings
                            <span class="set-shell-sub"><?= $isOwner ? 'Back up your clinic data, check that the system is working, and manage your account.' : 'Manage your password and see your account details.' ?></span>
                        </h5>
                    </div>
                    <div class="card-body set-shell-body">

                <div class="set-layout">
                    <!-- Page menu (left on desktop, swipeable chips on phones) -->
                    <nav class="set-nav" aria-label="Settings pages">
                        <?php foreach ($sections as $i => [$id, $icon, $label, $hint]): ?>
                            <a href="#<?= $id ?>" class="set-nav-link<?= $i === 0 ? ' is-active' : '' ?>" data-set-nav="<?= $id ?>">
                                <span class="set-nav-icon"><i class="<?= $icon ?>"></i></span>
                                <span class="set-nav-text"><strong><?= $e($label) ?></strong><small><?= $e($hint) ?></small></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <div class="set-sections">

<?php if ($isOwner): ?>
                        <!-- ═════════ BACKUP & DATA (owner) ═════════ -->
                        <section class="set-section" id="backup" aria-labelledby="backupTitle">
                            <div class="set-section-head">
                                <h2 id="backupTitle">Backup &amp; Data</h2>
                                <p>Download a copy of your clinic's information so it can be restored if something goes wrong.</p>
                            </div>

                            <div class="set-alert set-alert-warn" id="setSetup" role="status" hidden>
                                <i class="feather-tool"></i>
                                <div>
                                    <strong>Backups need a one-time setup</strong>
                                    <span>Ask your IT support to finish setting up backups. The download buttons will work once that is done.</span>
                                    <details class="set-tech">
                                        <summary>Details for IT support</summary>
                                        <p>Run <code><?= $e(SET_BACKUP_SQL_FILE) ?></code> in Supabase → SQL Editor, then reload this page.</p>
                                    </details>
                                </div>
                            </div>

                            <!-- At a glance -->
                            <div class="set-stats" aria-live="polite">
                                <div class="set-stat">
                                    <span class="set-stat-label"><i class="feather-calendar"></i>Last backup</span>
                                    <strong class="set-stat-value" id="stLast"><span class="set-skel"></span></strong>
                                    <span class="set-stat-sub" id="stLastBy">&nbsp;</span>
                                </div>
                                <div class="set-stat">
                                    <span class="set-stat-label"><i class="feather-check-circle"></i>Backup status</span>
                                    <strong class="set-stat-value" id="stState"><span class="set-skel"></span></strong>
                                    <span class="set-stat-sub" id="stStateSub">&nbsp;</span>
                                </div>
                                <div class="set-stat">
                                    <span class="set-stat-label"><i class="feather-wifi"></i>Connection</span>
                                    <strong class="set-stat-value" id="stConn"><span class="set-skel"></span></strong>
                                    <span class="set-stat-sub" id="stConnSub">&nbsp;</span>
                                </div>
                                <div class="set-stat">
                                    <span class="set-stat-label"><i class="feather-hard-drive"></i>Data size</span>
                                    <strong class="set-stat-value" id="stSize"><span class="set-skel"></span></strong>
                                    <span class="set-stat-sub" id="stSizeSub">&nbsp;</span>
                                </div>
                            </div>

                            <!-- The two downloads -->
                            <div class="set-grid-2">
                                <article class="set-card set-card-feature">
                                    <?= $cardHead('feather-shield', 'blue', 'Download Complete Data Backup',
                                        'A full copy of <strong>everything</strong> in the system. Use it to bring the clinic\'s data back if it is ever lost.',
                                        '') ?>
                                    <div class="set-tags">
                                        <span class="set-tag set-tag-blue"><i class="feather-user"></i>Owner only</span>
                                        <span class="set-tag set-tag-red"><i class="feather-lock"></i>Confidential</span>
                                    </div>
                                    <div class="set-block">
                                        <span class="set-block-label">What's inside</span>
                                        <ul class="set-checks">
                                            <li><i class="feather-check"></i>Patient records, dental charts and treatment history</li>
                                            <li><i class="feather-check"></i>Appointments, billing and payments</li>
                                            <li><i class="feather-check"></i>Inventory, branches, staff accounts and activity history</li>
                                        </ul>
                                    </div>
                                    <div class="set-card-foot">
                                        <button type="button" class="btn btn-primary set-btn" id="btnBackup" disabled>
                                            <i class="feather-download"></i><span>Download Complete Backup</span>
                                        </button>
                                        <span class="set-foot-note"><i class="feather-key"></i>You'll confirm with your password</span>
                                    </div>
                                </article>

                                <article class="set-card">
                                    <?= $cardHead('feather-layers', 'violet', 'Download Database Structure',
                                        'A blueprint of how the system organizes its information. It has <strong>no patient records</strong>, so it is safe to share with your IT support or developer.') ?>
                                    <div class="set-tags">
                                        <span class="set-tag set-tag-green"><i class="feather-check-circle"></i>No patient data</span>
                                    </div>
                                    <div class="set-block">
                                        <span class="set-block-label">What's inside</span>
                                        <ul class="set-checks">
                                            <li><i class="feather-check"></i>The list of all data tables and their fields</li>
                                            <li><i class="feather-check"></i>How records are linked to each other</li>
                                            <li><i class="feather-check"></i>Built-in rules and access protections</li>
                                        </ul>
                                    </div>
                                    <div class="set-card-foot">
                                        <button type="button" class="btn btn-outline-primary set-btn" id="btnSchema" disabled>
                                            <i class="feather-download"></i><span>Download Structure</span>
                                        </button>
                                        <span class="set-foot-note">Last: <span id="stLastSchema">—</span></span>
                                    </div>
                                </article>
                            </div>

                            <!-- Good to know -->
                            <article class="set-card">
                                <?= $cardHead('feather-info', 'slate', 'Good to know', '') ?>
                                <ul class="set-tips">
                                    <li><i class="feather-repeat"></i><span><strong>Back up once a week</strong> and before any big change. The status above turns orange when a backup is due.</span></li>
                                    <li><i class="feather-lock"></i><span><strong>Keep backups private.</strong> Save them on a password-protected drive or private folder — never send them by email or chat.</span></li>
                                    <li><i class="feather-image"></i><span><strong>Uploaded files are not included.</strong> X-ray images, signed waivers and attachments are stored separately by the hosting service.</span></li>
                                    <li><i class="feather-check-square"></i><span><strong>Every backup is checked</strong> to be complete before it downloads. Each download is recorded in Activity History.</span></li>
                                    <li><i class="feather-life-buoy"></i><span><strong>Restoring a backup</strong> is done by your IT support. There is no restore button here, so a backup can never be loaded by accident.</span></li>
                                </ul>
                                <details class="set-tech">
                                    <summary>Technical details for IT support</summary>
                                    <dl class="set-dl">
                                        <div><dt>File format</dt><dd>PostgreSQL SQL script (<code>.sql</code>)<?= $zipAes ? ', optionally inside an AES-256 encrypted ZIP' : '' ?></dd></div>
                                        <div><dt>Scope</dt><dd>All <span id="stTables">—</span> tables of schema <code>public</code>: structure, keys, indexes, functions, triggers, row level security, grants, and (complete backup) every row, taken from one consistent snapshot</dd></div>
                                        <div><dt>Not included</dt><dd><code>auth</code> / <code>storage</code> schemas, storage files, Supabase project settings</dd></div>
                                        <div><dt>Last fingerprint</dt><dd><code class="set-hash" id="stHash">—</code></dd></div>
                                        <div><dt>Restore</dt><dd>Into an empty database: <code>psql -v ON_ERROR_STOP=1 -f backup.sql</code> (one transaction)</dd></div>
                                    </dl>
                                </details>
                            </article>
                        </section>
<?php endif; ?>

                        <!-- ═════════ PASSWORD & SECURITY ═════════ -->
                        <section class="set-section" id="security" aria-labelledby="securityTitle">
                            <div class="set-section-head">
                                <h2 id="securityTitle">Password &amp; Security</h2>
                                <p>Keep your account safe and check where it has been used.</p>
                            </div>

                            <!-- Password: closed until "Change Password" is clicked -->
                            <article class="set-card" id="pwCard">
                                <div class="set-card-row">
                                    <?= $cardHead('feather-key', 'orange', 'Password', 'Change the password you use to sign in. Use one you don\'t use anywhere else.') ?>
                                    <button type="button" class="btn btn-outline-primary set-btn" id="pwOpen" aria-expanded="false" aria-controls="pwPanel">
                                        <i class="feather-edit-2"></i><span>Change Password</span>
                                    </button>
                                </div>
                                <div class="set-collapse" id="pwPanel" hidden>
                                    <form id="pwForm" class="set-form" novalidate autocomplete="off">
                                        <input type="text" name="username" value="<?= $e($me['email']) ?>" autocomplete="username" hidden>
                                        <div class="set-form-grid">
                                            <label class="set-field">
                                                <span class="set-label">Current password</span>
                                                <input type="password" class="form-control" name="current_password" autocomplete="current-password" maxlength="200" required>
                                            </label>
                                            <label class="set-field">
                                                <span class="set-label">New password</span>
                                                <input type="password" class="form-control" name="new_password" autocomplete="new-password" maxlength="200" required>
                                                <span class="set-help">At least 8 characters, including a letter and a number.</span>
                                            </label>
                                            <label class="set-field">
                                                <span class="set-label">Type the new password again</span>
                                                <input type="password" class="form-control" name="confirm_password" autocomplete="new-password" maxlength="200" required>
                                            </label>
                                        </div>
                                        <div class="set-form-msg" id="pwMsg" role="alert" hidden></div>
                                        <div class="set-card-foot">
                                            <button type="submit" class="btn btn-primary set-btn" id="pwSave"><i class="feather-check"></i><span>Save New Password</span></button>
                                            <button type="button" class="btn btn-light set-btn" id="pwCancel">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                                <div class="set-form-msg is-ok" id="pwDone" role="status" hidden></div>
                            </article>

                            <div class="set-grid-2">
                                <article class="set-card">
                                    <?= $cardHead('feather-monitor', 'blue', 'Your sign-in', 'The account you are using right now.') ?>
                                    <dl class="set-dl">
                                        <div><dt>Name</dt><dd><?= $e($me['full_name']) ?> <span class="set-pill is-info"><?= $e(ucfirst($role)) ?></span></dd></div>
                                        <div><dt>Branch</dt><dd><?= $e($isOwner ? 'All branches' : ($me['branch_name'] ?? '—')) ?></dd></div>
                                        <div><dt>This device</dt><dd><?= $e(setBrowserLabel((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) ?></dd></div>
                                        <div><dt>Signed in</dt><dd><?= $e($lastLogin ? $when($lastLogin['created_at']) : '—') ?></dd></div>
                                        <div><dt>Auto sign-out</dt><dd>After <?= (int)(SESSION_TIMEOUT / 3600) ?> hours of no activity</dd></div>
                                    </dl>
                                </article>

                                <article class="set-card">
                                    <?= $cardHead('feather-log-in', 'teal', 'Recent sign-ins', 'The last 5 times someone signed in to your account.') ?>
                                    <?php if (!$logins): ?>
                                        <p class="set-muted">No sign-ins recorded yet.</p>
                                    <?php else: ?>
                                        <ul class="set-logins">
                                            <?php foreach ($logins as $l):
                                                $ok = ($l['action'] ?? '') === 'LOGIN_SUCCESS';
                                                $ad = is_array($l['after_data'] ?? null) ? $l['after_data'] : []; ?>
                                                <li>
                                                    <span class="set-dot <?= $ok ? 'is-ok' : 'is-bad' ?>" aria-hidden="true"></span>
                                                    <span class="set-login-main"><?= $ok ? 'Signed in' : 'Wrong password entered' ?><small><?= $e(setBrowserLabel((string)($ad['user_agent'] ?? ''))) ?></small></span>
                                                    <time><?= $e($when($l['created_at'])) ?></time>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <p class="set-muted">Don't recognize one of these? Change your password now<?= $isOwner ? '' : ' and tell the clinic owner' ?>.</p>
                                    <?php endif; ?>
                                </article>
                            </div>

                            <article class="set-card">
                                <?= $cardHead('feather-grid', 'slate', 'What you can access',
                                    $isOwner ? 'As the Owner, you can open every part of the system.' : 'These parts of the system are open to your account. Only the clinic owner can change this.') ?>
                                <?php if (!$isOwner): ?>
                                    <div class="set-chips">
                                        <?php foreach ($myModules as [$label, $icon]): ?>
                                            <span class="set-chip"><i class="<?= $e($icon) ?>"></i><?= $e($label) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (!$myModules): ?><span class="set-muted">No areas assigned yet.</span><?php endif; ?>
                                    </div>
                                    <p class="set-muted">You only see records of <?= $e($me['branch_name'] ?? 'your branch') ?>.</p>
                                <?php else: ?>
                                    <p class="set-muted">To choose what an admin or dentist can open, go to <a href="accounts-records.php">Account Records</a> and use <strong>Sidebar Access</strong> on their account.</p>
                                <?php endif; ?>
                            </article>
                        </section>

<?php if ($isOwner || $isAdmin): ?>
                        <!-- ═════════ NOTIFICATIONS ═════════ -->
                        <section class="set-section" id="notify" aria-labelledby="notifyTitle">
                            <div class="set-section-head">
                                <h2 id="notifyTitle">Notifications</h2>
                                <p>How the system keeps patients informed. Each patient chooses which messages they want in the Patient Portal.</p>
                            </div>

                            <div class="set-channels">
                                <?php foreach ($channels as [$name, $icon, $on, $desc]): ?>
                                    <article class="set-card set-channel">
                                        <span class="set-icon set-tone-<?= $on ? 'teal' : 'slate' ?>"><i class="<?= $icon ?>"></i></span>
                                        <div class="set-channel-body">
                                            <div class="set-channel-title">
                                                <h3 class="set-card-title"><?= $e($name) ?></h3>
                                                <span class="set-pill <?= $on ? 'is-ok' : 'is-warn' ?>"><?= $on ? 'Working' : 'Not set up' ?></span>
                                            </div>
                                            <p class="set-card-desc"><?= $e($desc) ?><?= $on ? '' : ' Ask your IT support to set this up.' ?></p>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <article class="set-card">
                                <?= $cardHead('feather-clock', 'violet', 'Appointment reminders', 'Sent automatically — nothing to do on your side.') ?>
                                <dl class="set-dl">
                                    <div><dt>When</dt><dd>Every day at 9:00 AM, for the next day's appointments</dd></div>
                                    <div><dt>How</dt><dd>By email, text message or phone alert — whichever the patient turned on</dd></div>
                                    <div><dt>No duplicates</dt><dd>Each patient gets one reminder; a moved appointment is reminded for its new time</dd></div>
                                </dl>
                            </article>

                            <div class="set-links">
                                <?= $linkRow('notification-records.php', 'feather-send', 'Notification Log', 'Every message sent and whether it arrived') ?>
                                <?php if ($isOwner): ?>
                                    <?= $linkRow('notification-records.php?view=templates', 'feather-edit-3', 'Message Wording', 'Change what confirmations and reminders say') ?>
                                    <?= $linkRow('notification-records.php?view=announcements', 'feather-volume-2', 'Announcements', 'Clinic news shown in the Patient Portal') ?>
                                <?php endif; ?>
                                <?= $linkRow('notification-center.php', 'feather-inbox', 'Your Notifications', 'Alerts for your own account') ?>
                            </div>
                        </section>
<?php endif; ?>

                        <!-- ═════════ SYSTEM STATUS ═════════ -->
                        <section class="set-section" id="system" aria-labelledby="systemTitle">
                            <div class="set-section-head">
                                <h2 id="systemTitle">System Status</h2>
                                <p>A quick health check. Share this page with your IT support if something isn't working.</p>
                            </div>

                            <article class="set-card">
                                <?= $cardHead('feather-activity', 'teal', 'Health check', 'Checked when this page opened.') ?>
                                <dl class="set-dl">
                                    <div><dt>Database connection</dt><dd id="sysConn">
                                        <?php if ($isOwner): ?>
                                            <span class="set-skel"></span>
                                        <?php else: ?>
                                            <span class="set-pill <?= $dbOk ? 'is-ok' : 'is-bad' ?>"><?= $dbOk ? 'Connected' : 'Not reachable' ?></span>
                                        <?php endif; ?>
                                    </dd></div>
                                    <div><dt>Secure connection</dt><dd><span class="set-pill <?= $https ? 'is-ok' : 'is-warn' ?>"><?= $https ? 'Secure (HTTPS)' : 'Not secure — local test copy' ?></span></dd></div>
                                    <div><dt>System version</dt><dd><?= $e(ES_APP_VERSION) ?></dd></div>
                                    <div><dt>Clinic time</dt><dd><?= $e(date('M j, Y · g:i A')) ?> (Philippine time)</dd></div>
                                </dl>
                                <?php if ($isOwner): ?>
                                    <div class="set-card-foot">
                                        <button type="button" class="btn btn-outline-primary set-btn" id="btnRecheck"><i class="feather-refresh-cw"></i><span>Check Again</span></button>
                                        <span class="set-foot-note" id="sysChecked"></span>
                                    </div>
                                <?php endif; ?>
                                <details class="set-tech">
                                    <summary>Technical details for IT support</summary>
                                    <dl class="set-dl">
                                        <div><dt>Application</dt><dd>EssenciaSmile Dental Management System <?= $e(ES_APP_VERSION) ?></dd></div>
                                        <div><dt>PHP</dt><dd><?= $e(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION) ?></dd></div>
                                        <div><dt>Server</dt><dd><?= $e($serverLabel) ?></dd></div>
                                        <div><dt>Database</dt><dd>Supabase (PostgreSQL)<span id="sysDbVer"></span></dd></div>
                                        <div><dt>Time zone</dt><dd><?= $e(date_default_timezone_get()) ?></dd></div>
                                    </dl>
                                </details>
                            </article>
                        </section>

                        <!-- ═════════ ACTIVITY HISTORY ═════════ -->
                        <section class="set-section" id="audit" aria-labelledby="auditTitle">
                            <div class="set-section-head">
                                <h2 id="auditTitle">Activity History</h2>
                                <p><?= $isOwner ? 'See who added, changed or removed records, and when — across every branch.' : 'See the changes you have made and when you signed in.' ?></p>
                            </div>
                            <div class="set-links">
                                <?= $linkRow('audit-records.php', 'feather-list', 'All Activity', $isOwner ? 'Every change, by person, branch and area' : 'Changes you made') ?>
                                <?= $linkRow('audit-records.php?view=logins', 'feather-log-in', 'Sign-in History', $isOwner ? 'Successful and failed sign-ins' : 'Your sign-ins') ?>
                                <?php if ($isOwner): ?>
                                    <?= $linkRow('audit-records.php?module=settings', 'feather-database', 'Backup History', 'Every backup and structure download') ?>
                                <?php endif; ?>
                            </div>
                        </section>

                    </div>
                </div>

                    </div><!-- /.card-body -->
                </div><!-- /.card.set-shell -->
                </div></div><!-- /.col /.row -->
            </div>
        </div>
    </main>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/admin.js?v=7"></script>
    <script src="assets/js/es-swal.js?v=3"></script>
    <script>window.ES_SETTINGS = { zipAes: <?= $zipAes ? 'true' : 'false' ?>, zipMin: <?= SET_ZIP_MIN_PASSWORD ?> };</script>
    <script src="assets/js/es-settings.js?v=5"></script>
</body>
</html>
