<?php
// portal-dashboard.php
session_start();
require_once __DIR__ . '/portal-session-guard.php';
portal_require_login();

$API_PATH = 'api/portal_auth.php';

// Registration rule: a verified email OR phone (checked in the DB, enforced by the APIs too)
$contactStatus = portal_contact_status((int)$_SESSION['portal_account_id']);

// Fetch gender for the questionnaire modal's "women only" block
// We read it lazily — if session already has it, use it; else quick DB call
$portalGender = $_SESSION['portal_gender'] ?? '';
if (!$portalGender) {
    require_once __DIR__ . '/dbconfig.php';
    $accRes = supabase_request(
        'patient_accounts',
        'GET',
        [],
        'account_id=eq.' . (int)$_SESSION['portal_account_id'] . '&select=patient_id&limit=1'
    );
    $pid = $accRes['data'][0]['patient_id'] ?? null;
    if ($pid) {
        $pRes = supabase_request(
            'patients',
            'GET',
            [],
            'patient_id=eq.' . $pid . '&select=gender&limit=1'
        );
        $portalGender = $pRes['data'][0]['gender'] ?? '';
    }
    $_SESSION['portal_gender'] = $portalGender;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EssenciaSmile | My Portal</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/portal-login.css">
    <link rel="stylesheet" href="assets/css/odontogram.css?v=3">
    <link rel="stylesheet" href="assets/css/portal-dashboard.css?v=21">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=4">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
</head>

<body>

    <!-- Mobile overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar" aria-label="Portal navigation">
        <a class="sidebar-brand align-items-center" href="portal-landing-page.php">
            <img src="assets/images/essencia-full@3x.png" alt="EssenciaSmile">
        </a>
        <button type="button" class="sidebar-close" onclick="closeSidebar()" aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div class="sidebar-user">
            <div class="avatar-circle" id="sidebarInitial">—</div>
            <div class="sidebar-user-info">
                <div class="name" id="sidebarName">Loading…</div>
                <div class="badge-patient">Patient Portal</div>
            </div>
        </div>

        <nav class="sidebar-nav" aria-label="Main">
            <div class="nav-section-label">Overview</div>
            <a class="nav-link-item active" aria-current="page" href="#" onclick="showPage('dashboard',this)">
                <i class="fa-solid fa-house"></i> Dashboard
            </a>

            <div class="nav-section-label mt-2">My Health</div>
            <a class="nav-link-item" href="#" onclick="showPage('appointments',this)">
                <i class="fa-solid fa-calendar-check"></i> Appointments
            </a>
            <a class="nav-link-item" href="#" onclick="showPage('treatments',this)">
                <i class="fa-solid fa-tooth"></i> Treatment History
            </a>
            <a class="nav-link-item" href="#" onclick="showPage('dentalchart',this)">
                <i class="fa-solid fa-teeth-open"></i> Dental Chart
            </a>

            <div class="nav-section-label mt-2">Finance & Files</div>
            <a class="nav-link-item" href="#" onclick="showPage('billing',this)">
                <i class="fa-solid fa-file-invoice-dollar"></i> Billing & Payments
            </a>
            <a class="nav-link-item" href="#" onclick="showPage('files',this)">
                <i class="fa-solid fa-folder-open"></i> Files
            </a>
            <a class="nav-link-item" href="#" onclick="showPage('waivers',this)">
                <i class="fa-solid fa-pen-to-square"></i> Waivers
            </a>

            <div class="nav-section-label mt-2">Account</div>
            <a class="nav-link-item" href="#" onclick="showPage('profile',this)">
                <i class="fa-solid fa-user-circle"></i> Profile
            </a>
        </nav>

        <div class="sidebar-footer">
            <button class="logout-btn" onclick="doLogout()">
                <i class="fa-solid fa-right-from-bracket"></i> Sign Out
            </button>
        </div>
    </aside>

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mob-nav-toggle" type="button" onclick="openSidebar()" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="topbar-heading">
                <div class="topbar-title" id="topbarTitle">Dashboard</div>
                <div class="topbar-breadcrumb" id="topbarBreadcrumb">My Portal › Dashboard</div>
            </div>
        </div>
        <div class="topbar-actions">
            <!-- ── Notification Bell ── -->
            <div class="notif-bell-wrap" id="notifBellWrap">
                <button class="notif-bell-btn" id="notifBellBtn" onclick="toggleNotifPanel()"
                    type="button" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
                    <i class="fa-solid fa-bell"></i>
                    <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
                </button>

                <div class="notif-panel" id="notifPanel">
                    <div class="notif-panel-header">
                        <span>Notifications</span>
                        <button type="button" class="notif-mark-read" id="notifMarkAllRead" onclick="markAllNotifsRead()">
                            Mark all as read
                        </button>
                    </div>
                    <div class="notif-panel-body" id="notifPanelBody">
                        <div class="notif-empty">
                            <i class="fa-solid fa-bell-slash"></i>
                            <p>No notifications yet.</p>
                        </div>
                    </div>
                </div>
            </div>

            <button class="btn-book-appt" id="bookApptBtn" onclick="handleBookAppt()" type="button">
                <i class="fa-solid fa-plus" aria-hidden="true"></i><span class="bk-long">Book Appointment</span><span class="bk-short" aria-hidden="true">Book</span>
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">

        <!-- ── DASHBOARD PAGE ── -->
        <div class="page-body active" id="page-dashboard">
            <div class="profile-alert" id="profileIncompleteAlert" style="display:none;">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div>
                    <h6>Complete Your Profile</h6>
                    <p>Please complete your profile information before booking appointments.
                        <span class="text-link ms-1" style="cursor:pointer;color:#c2410c;font-weight:700;" onclick="showPage('profile', null)">Go to Profile →</span>
                    </p>
                </div>
            </div>

            <div style="margin-bottom:1.5rem;">
                <h4 style="font-weight:800;color:var(--text-dark);margin-bottom:.25rem;">
                    Good day, <span id="welcomeName">…</span>
                </h4>
                <p style="color:var(--text-muted);font-size:.9rem;">Here's an overview of your dental health.</p>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fa-solid fa-calendar-check"></i></div>
                        <div>
                            <div class="stat-label">Upcoming Appts</div>
                            <div class="stat-value" id="statUpcoming">—</div>
                            <div class="stat-sub">Scheduled</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fa-solid fa-check-circle"></i></div>
                        <div>
                            <div class="stat-label">Completed</div>
                            <div class="stat-value" id="statCompleted">—</div>
                            <div class="stat-sub">Visits done</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                        <div>
                            <div class="stat-label">Outstanding</div>
                            <div class="stat-value" id="statBalance">—</div>
                            <div class="stat-sub">Balance due</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fa-solid fa-tooth"></i></div>
                        <div>
                            <div class="stat-label">Treatments</div>
                            <div class="stat-value" id="statTreatments">—</div>
                            <div class="stat-sub">Total procedures</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="content-card">
                        <div class="card-header-bar">
                            <h6><i class="fa-solid fa-calendar-check me-2 text-primary"></i>Upcoming Appointments</h6>
                            <span class="text-link" style="font-size:.83rem;color:var(--primary);cursor:pointer;" onclick="showPage('appointments',null)">View all</span>
                        </div>
                        <div id="dashApptList">
                            <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i>
                                <p>No upcoming appointments.</p>
                                <button type="button" class="empty-action" onclick="handleBookAppt()"><i class="fa-solid fa-plus"></i>Book a visit</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="content-card">
                        <div class="card-header-bar">
                            <h6><i class="fa-solid fa-receipt me-2 text-primary"></i>Recent Bills</h6>
                            <span class="text-link" style="font-size:.83rem;color:var(--primary);cursor:pointer;" onclick="showPage('billing',null)">View all</span>
                        </div>
                        <div id="dashBillList">
                            <div class="empty-state"><i class="fa-solid fa-file-invoice"></i>
                                <p>No billing records yet.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── APPOINTMENTS PAGE ── -->
        <div class="page-body" id="page-appointments">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div>
                    <h5 style="font-weight:800;margin:0;">My Appointments</h5>
                    <p style="color:var(--text-muted);font-size:.85rem;margin:0;">Manage your upcoming and past visits</p>
                </div>
            </div>

            <div class="appt-filter-bar">
                <button class="appt-filter-btn active" data-status="all" onclick="filterAppts('all',this)">All</button>
                <button class="appt-filter-btn" data-status="pending" onclick="filterAppts('pending',this)">Pending</button>
                <button class="appt-filter-btn" data-status="confirmed" onclick="filterAppts('confirmed',this)">Confirmed</button>
                <button class="appt-filter-btn" data-status="completed" onclick="filterAppts('completed',this)">Completed</button>
                <button class="appt-filter-btn" data-status="cancelled" onclick="filterAppts('cancelled',this)">Cancelled</button>
            </div>

            <div class="content-card">
                <table class="portal-table pt-stack">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Branch</th>
                            <th>Service</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="apptTableBody">
                        <tr>
                            <td colspan="5" class="text-center py-4" style="color:var(--text-muted);">Loading…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── TREATMENTS PAGE ── -->
        <div class="page-body" id="page-treatments">
            <div class="mb-3">
                <h5 style="font-weight:800;margin:0;">My Treatment History</h5>
                <p style="color:var(--text-muted);font-size:.85rem;margin:0;">Read-only view of all your procedures</p>
            </div>
            <div class="content-card">
                <table class="portal-table pt-stack">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Procedure</th>
                            <th>Dentist</th>
                            <th>Status</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="treatTableBody">
                        <tr>
                            <td colspan="5" class="text-center py-4" style="color:var(--text-muted);">Loading…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── DENTAL CHART PAGE ── -->
        <div class="page-body" id="page-dentalchart">
            <div class="mb-3">
                <h5 style="font-weight:800;margin:0;">My Dental Chart</h5>
                <p style="color:var(--text-muted);font-size:.85rem;margin:0;">Read-only view of your teeth as recorded by your dentist. Select a tooth to see its details.</p>
            </div>

            <!-- Summary (counted from your dental chart records) -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-xl-3">
                    <div class="stat-card odo-stat">
                        <div class="stat-icon blue"><i class="fa-solid fa-clipboard-list"></i></div>
                        <div>
                            <div class="stat-label">Teeth Charted</div>
                            <div class="stat-value" id="odo_stat_charted">—</div>
                            <div class="stat-sub">of 32 adult teeth</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="stat-card odo-stat">
                        <div class="stat-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
                        <div>
                            <div class="stat-label">Decay</div>
                            <div class="stat-value" id="odo_stat_decay">—</div>
                            <div class="stat-sub">teeth with decay recorded</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="stat-card odo-stat">
                        <div class="stat-icon green"><i class="fa-solid fa-shield-halved"></i></div>
                        <div>
                            <div class="stat-label">Restored</div>
                            <div class="stat-value" id="odo_stat_restored">—</div>
                            <div class="stat-sub">filled or crowned teeth</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="stat-card odo-stat">
                        <div class="stat-icon orange"><i class="fa-solid fa-circle-minus"></i></div>
                        <div>
                            <div class="stat-label">Missing</div>
                            <div class="stat-value" id="odo_stat_missing">—</div>
                            <div class="stat-sub">teeth recorded as missing</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Shared SVG pattern for "Impacted (Not Erupted)" -->
            <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
                <defs>
                    <pattern id="odoHatch" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                        <rect width="5" height="5" fill="#ede9fe"></rect>
                        <line x1="0" y1="0" x2="0" y2="5" stroke="#8b5cf6" stroke-width="2"></line>
                    </pattern>
                </defs>
            </svg>

            <div class="odo-layout">
                <div class="content-card odo-card">
                    <div class="odo-legend" id="odoLegend" aria-label="Legend"></div><!-- filled by assets/js/odontogram.js -->

                    <div class="odo-body">
                        <div id="odoLoading" class="odo-state">
                            <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                            <span>Loading your dental chart…</span>
                        </div>
                        <div id="odoError" class="odo-state odo-state-error" style="display:none;"></div>

                        <div id="odoChart" class="odo-chart" style="display:none;">
                            <div class="odo-sides" aria-hidden="true">
                                <span><i class="fa-solid fa-arrow-left me-1"></i>Patient's Right</span>
                                <span>Patient's Left<i class="fa-solid fa-arrow-right ms-1"></i></span>
                            </div>
                            <div class="odo-arch-label">Upper</div>
                            <div class="odo-row odo-upper" id="odoUpper" role="group" aria-label="Upper teeth, 1 to 16"></div>
                            <div class="odo-occlusal" aria-hidden="true"></div>
                            <div class="odo-row odo-lower" id="odoLower" role="group" aria-label="Lower teeth, 17 to 32"></div>
                            <div class="odo-arch-label">Lower</div>
                            <p class="odo-extra" id="odoExtra" style="display:none;"></p>
                        </div>
                    </div>

                    <div class="chart-info-bar">
                        <i class="fa-solid fa-circle-info"></i>
                        Read-only. Your dentist updates this chart during your visits. Contact the clinic if something looks wrong.
                    </div>
                </div>

                <!-- Selected-tooth details (inline panel) -->
                <aside class="content-card odo-panel" id="odoPanel" aria-live="polite">
                    <div class="odo-panel-empty" id="odoPanelEmpty">
                        <span class="odo-panel-empty-icon"><i class="fa-solid fa-hand-pointer"></i></span>
                        <div class="odo-panel-empty-title">Select a tooth</div>
                        <p>Tap or click any tooth on the chart to see its condition, notes, history and treatments.</p>
                    </div>
                    <div id="odoPanelBody" style="display:none;"></div>
                </aside>
            </div>
        </div>

        <!-- ── BILLING PAGE ── -->
        <div class="page-body" id="page-billing">
            <div class="mb-3">
                <h5 style="font-weight:800;margin:0;">My Billing & Payments</h5>
                <p style="color:var(--text-muted);font-size:.85rem;margin:0;">Your invoices and payment history</p>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                        <div>
                            <div class="stat-label">Total Billed</div>
                            <div class="stat-value" id="billTotal">₱0.00</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fa-solid fa-circle-check"></i></div>
                        <div>
                            <div class="stat-label">Total Paid</div>
                            <div class="stat-value" id="billPaid">₱0.00</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fa-solid fa-triangle-exclamation"></i></div>
                        <div>
                            <div class="stat-label">Outstanding Balance</div>
                            <div class="stat-value" id="billBalance">₱0.00</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="content-card">
                <table class="portal-table pt-stack">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="billingTableBody">
                        <tr>
                            <td colspan="7" class="text-center py-4" style="color:var(--text-muted);">Loading…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── FILES PAGE ── -->
        <div class="page-body" id="page-files">
            <div class="mb-3">
                <h5 style="font-weight:800;margin:0;">My Files</h5>
                <p style="color:var(--text-muted);font-size:.85rem;margin:0;">X-rays, referrals, and medical certificates (view/download only)</p>
            </div>
            <div class="content-card">
                <table class="portal-table pt-stack">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Type</th>
                            <th>Uploaded By</th>
                            <th>Date</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="filesTableBody">
                        <tr>
                            <td colspan="5" class="text-center py-4" style="color:var(--text-muted);">Loading…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── WAIVERS PAGE ── -->
        <div class="page-body" id="page-waivers">
            <div class="mb-3">
                <h5 style="font-weight:800;margin:0;">My Waivers</h5>
                <p style="color:var(--text-muted);font-size:.85rem;margin:0;">View your consent and waiver documents</p>
            </div>
            <div class="content-card">
                <table class="portal-table pt-stack">
                    <thead>
                        <tr>
                            <th>Waiver</th>
                            <th>Document</th>
                            <th>Status</th>
                            <th>Signed</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="waiversTableBody">
                        <tr>
                            <td colspan="5" class="text-center py-4" style="color:var(--text-muted);">Loading…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             ── FEEDBACK PAGE ──
             Opened from Treatment History → Give Feedback (no sidebar link).
             Dentist feedback, one per completed appointment with a completed
             treatment; api/portal_feedback.php re-checks everything and
             resolves the dentist itself. The stars are a radio group.
        ══════════════════════════════════════════════════ -->
        <div class="page-body" id="page-feedback">
            <div class="mb-3 fb-head">
                <button type="button" class="fb-back" onclick="showPage('treatments', null)">
                    <i class="fa-solid fa-arrow-left"></i> Treatment History
                </button>
                <h5 style="font-weight:800;margin:0;">Give Feedback</h5>
                <p style="color:var(--text-muted);font-size:.85rem;margin:0;">Your feedback helps us improve our care and clinic services.</p>
            </div>

            <div class="fb-layout">
                <form class="content-card fb-form" id="fbForm" novalidate onsubmit="submitFeedback(event)">
                    <div class="fb-apt" id="fbApt" aria-live="polite"></div>

                    <div class="fb-group" id="fbDentistField">
                        <span class="pf-label">Dentist you are reviewing</span>
                        <div class="fb-dentist">
                            <span class="fb-dentist-icon" aria-hidden="true"><i class="fa-solid fa-user-doctor"></i></span>
                            <span><strong id="fbDentistName"></strong><small>The dentist who performed your treatment on this visit</small></span>
                        </div>
                    </div>

                    <fieldset class="fb-group">
                        <legend class="pf-label">How would you rate this dentist?</legend>
                        <div class="fb-stars" id="fbStars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <label class="fb-star" title="<?= $i ?> out of 5">
                                    <input type="radio" name="fb_rating" value="<?= $i ?>" onchange="fbRatingChanged()">
                                    <i class="fa-solid fa-star" aria-hidden="true"></i>
                                    <span class="visually-hidden"><?= $i ?> star<?= $i > 1 ? 's' : '' ?></span>
                                </label>
                            <?php endfor; ?>
                            <span class="fb-star-label" id="fbStarLabel">Tap a star to rate</span>
                        </div>
                    </fieldset>

                    <div class="fb-group">
                        <label class="pf-label" for="fb_message">Your feedback <span class="fb-optional">(optional)</span></label>
                        <textarea id="fb_message" class="pf-input fb-textarea" rows="5" maxlength="1000"
                            placeholder="How was the dentist's care, explanation and manner?" oninput="fbCountChars()"></textarea>
                        <div class="fb-hint fb-count" id="fbCount">0 / 1000</div>
                    </div>

                    <div class="pf-err fb-err" id="fbErr" role="alert"></div>
                    <div class="fb-actions">
                        <button type="submit" class="fb-submit" id="fbSubmit">
                            <i class="fa-solid fa-paper-plane"></i> Submit Feedback
                        </button>
                        <span class="fb-soon">You can send one feedback per appointment.</span>
                    </div>
                </form>

                <aside class="fb-side">
                    <div class="content-card fb-info">
                        <h6 class="fb-info-title"><i class="fa-solid fa-circle-question"></i> What is this feedback for?</h6>
                        <div class="fb-info-item">
                            <span class="fb-info-icon"><i class="fa-solid fa-user-doctor"></i></span>
                            <p>This feedback is about the <strong>dentist who performed your treatment</strong> — the care you received, how things were explained, and their chairside manner.</p>
                        </div>
                    </div>
                    <div class="content-card fb-privacy">
                        <span class="fb-privacy-icon"><i class="fa-solid fa-shield-heart"></i></span>
                        <p>Only the clinic owner can read your feedback. They see your rating, message, the dentist reviewed, the branch and the date — not your name, contact details or appointment. Anything you write in the message (such as your name or visit details) is shown exactly as written, so leave out details you'd rather keep private. Your feedback is not shared publicly.</p>
                    </div>
                </aside>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             ── PROFILE PAGE ──
             Left: summary card + section navigation (shared by view & edit)
             Right: view panel (read-only) or edit panel (form)
        ══════════════════════════════════════════════════ -->
        <div class="page-body" id="page-profile">
            <div class="prof-layout">

                <!-- ── LEFT: summary + navigation ── -->
                <aside class="prof-aside" aria-label="Profile summary">
                    <div class="prof-card prof-summary">
                        <div class="prof-id">
                            <div class="prof-avatar" id="profAvatar" aria-hidden="true">—</div>
                            <div class="prof-id-text">
                                <div class="prof-name" id="profName">—</div>
                                <ul class="prof-contact">
                                    <li><i class="fa-regular fa-envelope fa-fw" aria-hidden="true"></i><span id="profEmail">—</span></li>
                                    <li><i class="fa-solid fa-phone fa-fw" aria-hidden="true"></i><span id="profPhone">—</span></li>
                                </ul>
                            </div>
                        </div>

                        <div class="prof-complete" id="profComplete" aria-live="polite">
                            <div class="prof-complete-head">
                                <span class="prof-complete-label">Profile status</span>
                                <span class="prof-status" id="profStatus">—</span>
                            </div>
                            <div class="prof-progress" role="progressbar" aria-label="Profile completion"
                                aria-valuemin="0" aria-valuemax="3" aria-valuenow="0" id="profProgress">
                                <div class="prof-progress-bar" id="profProgressBar"></div>
                            </div>
                            <ul class="prof-steps" id="profSteps"></ul>
                        </div>

                        <button type="button" class="prof-btn prof-btn-primary prof-edit-btn" id="profEditBtn" onclick="enterEditMode()">
                            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Edit Profile
                        </button>
                        <button type="button" class="prof-btn prof-btn-light prof-pw-btn" onclick="openChangePassword()">
                            <i class="fa-solid fa-key" aria-hidden="true"></i> Change Password
                        </button>
                        <div class="prof-editing-note" id="profEditingNote" hidden>
                            <i class="fa-solid fa-pen" aria-hidden="true"></i> Editing — remember to save your changes
                        </div>
                    </div>

                    <nav class="prof-card prof-nav" id="profNav" aria-label="Profile sections">
                            <a class="prof-nav-link active" href="#" data-sec="basic" onclick="return profGoTo('basic')" aria-current="true">
                                <i class="fa-solid fa-user fa-fw" aria-hidden="true"></i><span>Basic Info</span>
                            </a>
                            <a class="prof-nav-link" href="#" data-sec="contact" onclick="return profGoTo('contact')">
                                <i class="fa-solid fa-address-book fa-fw" aria-hidden="true"></i><span>Contact Info</span>
                            </a>
                            <a class="prof-nav-link" href="#" data-sec="consult" onclick="return profGoTo('consult')">
                                <i class="fa-solid fa-stethoscope fa-fw" aria-hidden="true"></i><span>Consultation</span>
                            </a>
                            <a class="prof-nav-link" href="#" data-sec="dental" onclick="return profGoTo('dental')">
                                <i class="fa-solid fa-tooth fa-fw" aria-hidden="true"></i><span>Dental History</span>
                            </a>
                            <a class="prof-nav-link" href="#" data-sec="allergies" onclick="return profGoTo('allergies')">
                                <i class="fa-solid fa-triangle-exclamation fa-fw" aria-hidden="true"></i><span>Allergies</span>
                            </a>
                            <a class="prof-nav-link" href="#" data-sec="conditions" onclick="return profGoTo('conditions')">
                                <i class="fa-solid fa-file-medical fa-fw" aria-hidden="true"></i><span>Medical Conditions</span>
                            </a>
                    </nav>
                </aside>

                <!-- ── RIGHT: content ── -->
                <div class="prof-main">

                    <!-- ── VIEW PANEL ── -->
                    <div id="profileViewPanel">
                        <div class="prof-toolbar">
                            <span class="prof-toolbar-text">Your personal details and health information</span>
                            <div class="prof-toolbar-actions">
                                <button type="button" class="prof-link-btn" onclick="profSetAll(true)">Expand all</button>
                                <span class="prof-toolbar-sep" aria-hidden="true">·</span>
                                <button type="button" class="prof-link-btn" onclick="profSetAll(false)">Collapse all</button>
                            </div>
                        </div>
                        <div class="prof-sections">
                        <section class="prof-section" id="view-sec-basic" data-sec="basic">
                            <h3 class="prof-sec-h">
                                <button type="button" class="prof-section-header" onclick="toggleSection(this)"
                                    aria-expanded="true" aria-controls="view-sec-basic-body">
                                    <span class="prof-sec-icon blue" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
                                    <span class="prof-sec-text">
                                        <span class="prof-sec-title">Basic Information</span>
                                        <span class="prof-sec-sub">Name, birthdate, gender and civil status</span>
                                    </span>
                                        <span class="prof-sec-meta" data-meta></span>
                                    <i class="fa-solid fa-chevron-down prof-chevron" aria-hidden="true"></i>
                                </button>
                            </h3>
                            <div class="prof-collapse" id="view-sec-basic-body">
                                <div class="prof-collapse-inner">
                                    <div class="prof-section-body">
                                    <div class="row g-3 prof-fields">
                                        <div class="col-md-6">
                                            <div class="view-field-label">Full Name</div>
                                            <div class="view-field-value" id="vf_full_name">—</div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="view-field-label">Birthdate</div>
                                            <div class="view-field-value" id="vf_birthdate">—</div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="view-field-label">Age</div>
                                            <div class="view-field-value" id="vf_age_display">—</div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="view-field-label">Gender</div>
                                            <div class="view-field-value" id="vf_gender">—</div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="view-field-label">Civil Status</div>
                                            <div class="view-field-value" id="vf_civil_status_display">—</div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="view-field-label">Nationality</div>
                                            <div class="view-field-value" id="vf_nationality_display">—</div>
                                        </div>
                                        <div class="col-12" id="vg_guardian_row" style="display:none;">
                                            <div class="prof-guardian">
                                                <div class="prof-guardian-title">
                                                    <i class="fa-solid fa-child me-1"></i> Parent / Guardian Information
                                                </div>
                                                <div class="row g-2">
                                                    <div class="col-md-6">
                                                        <div class="view-field-label">Guardian Full Name</div>
                                                        <div class="view-field-value" id="vg_name">—</div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="view-field-label">Relationship</div>
                                                        <div class="view-field-value" id="vg_relation">—</div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="view-field-label">Phone Number</div>
                                                        <div class="view-field-value" id="vg_phone">—</div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="view-field-label">Address</div>
                                                        <div class="view-field-value" id="vg_address">—</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12" id="vf_google_auth_row" style="display:none;">
                                            <div class="view-field-label">Sign-in Method</div>
                                            <div class="prof-google-chip">
                                                <svg width="16" height="16" viewBox="0 0 48 48" aria-hidden="true">
                                                    <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
                                                    <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
                                                    <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
                                                    <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.35-8.16 2.35-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
                                                </svg>
                                                <span>Signed in with Google</span>
                                            </div>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <section class="prof-section" id="view-sec-contact" data-sec="contact">
                            <h3 class="prof-sec-h">
                                <button type="button" class="prof-section-header" onclick="toggleSection(this)"
                                    aria-expanded="true" aria-controls="view-sec-contact-body">
                                    <span class="prof-sec-icon green" aria-hidden="true"><i class="fa-solid fa-address-book"></i></span>
                                    <span class="prof-sec-text">
                                        <span class="prof-sec-title">Contact Information</span>
                                        <span class="prof-sec-sub">Email, phone and home address</span>
                                    </span>
                                        <span class="prof-sec-meta" data-meta></span>
                                    <i class="fa-solid fa-chevron-down prof-chevron" aria-hidden="true"></i>
                                </button>
                            </h3>
                            <div class="prof-collapse" id="view-sec-contact-body">
                                <div class="prof-collapse-inner">
                                    <div class="prof-section-body">
                                    <div class="row g-3 prof-fields">
                                        <div class="col-md-6">
                                            <div class="view-field-label">Email Address</div>
                                            <div class="prof-value-row">
                                                <div class="view-field-value" id="vf_email">—</div>
                                                <span id="emailVerifiedBadge" style="display:none;">
                                                    <span class="prof-badge prof-badge-ok">
                                                        <i class="fa-solid fa-circle-check"></i> Verified
                                                    </span>
                                                </span>
                                                <span id="emailUnverifiedBadge" style="display:none;">
                                                    <span class="prof-badge prof-badge-warn">
                                                        <i class="fa-solid fa-circle-exclamation"></i> Unverified
                                                    </span>
                                                </span>
                                            </div>
                                            <div id="emailVerifyBtnWrap" class="prof-verify-wrap" style="display:none;">
                                                <button type="button" class="prof-btn-verify" onclick="sendVerificationEmail(this)">
                                                    <i class="fa-solid fa-envelope"></i> Send Verification Email
                                                </button>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="view-field-label">Contact Number</div>
                                            <div class="prof-value-row">
                                                <div class="view-field-value" id="vf_contact">—</div>
                                                <span id="phoneVerifiedBadge" style="display:none;">
                                                    <span class="prof-badge prof-badge-ok">
                                                        <i class="fa-solid fa-circle-check"></i> Verified
                                                    </span>
                                                </span>
                                                <span id="phoneUnverifiedBadge" style="display:none;">
                                                    <span class="prof-badge prof-badge-warn">
                                                        <i class="fa-solid fa-circle-exclamation"></i> Unverified
                                                    </span>
                                                </span>
                                            </div>
                                            <div id="phoneVerifyBtnWrap" class="prof-verify-wrap" style="display:none;">
                                                <button type="button" class="prof-btn-verify" onclick="startPhoneVerification()">
                                                    <i class="fa-solid fa-mobile-screen"></i> Verify Phone Number
                                                </button>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <p class="contact-rule-note" id="contactRuleNote" style="display:none;"></p>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="view-field-label">Home Phone</div>
                                            <div class="view-field-value" id="vf_home_phone">—</div>
                                        </div>
                                        <div class="col-12">
                                            <div class="view-field-label">Home Address</div>
                                            <div class="view-field-value" id="vf_address">—</div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="view-field-label">Province</div>
                                            <div class="view-field-value" id="vf_province">—</div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="view-field-label">City / Municipality</div>
                                            <div class="view-field-value" id="vf_city">—</div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="view-field-label">Barangay</div>
                                            <div class="view-field-value" id="vf_barangay">—</div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="view-field-label">ZIP Code</div>
                                            <div class="view-field-value" id="vf_zip">—</div>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <section class="prof-section collapsed" id="view-sec-consult" data-sec="consult">
                            <h3 class="prof-sec-h">
                                <button type="button" class="prof-section-header" onclick="toggleSection(this)"
                                    aria-expanded="false" aria-controls="view-sec-consult-body">
                                    <span class="prof-sec-icon blue" aria-hidden="true"><i class="fa-solid fa-stethoscope"></i></span>
                                    <span class="prof-sec-text">
                                        <span class="prof-sec-title">Consultation</span>
                                        <span class="prof-sec-sub">Reason for visit and referral</span>
                                    </span>
                                        <span class="prof-sec-meta" data-meta></span>
                                    <i class="fa-solid fa-chevron-down prof-chevron" aria-hidden="true"></i>
                                </button>
                            </h3>
                            <div class="prof-collapse" id="view-sec-consult-body">
                                <div class="prof-collapse-inner">
                                    <div class="prof-section-body">
                                    <div class="row g-3 prof-fields">
                                        <div class="col-12">
                                            <div class="view-field-label">Chief Complaint / Reason for Visit</div>
                                            <div class="view-field-value" id="vf_chief_complaint">—</div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="view-field-label">How did you hear about us?</div>
                                            <div class="view-field-value" id="vf_referral_source">—</div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="view-field-label">Referred by</div>
                                            <div class="view-field-value" id="vf_referred_by">—</div>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <section class="prof-section collapsed" id="view-sec-dental" data-sec="dental">
                            <h3 class="prof-sec-h">
                                <button type="button" class="prof-section-header" onclick="toggleSection(this)"
                                    aria-expanded="false" aria-controls="view-sec-dental-body">
                                    <span class="prof-sec-icon teal" aria-hidden="true"><i class="fa-solid fa-tooth"></i></span>
                                    <span class="prof-sec-text">
                                        <span class="prof-sec-title">Dental History</span>
                                        <span class="prof-sec-sub">Previous dentist and last visit</span>
                                    </span>
                                        <span class="prof-sec-meta" data-meta></span>
                                    <i class="fa-solid fa-chevron-down prof-chevron" aria-hidden="true"></i>
                                </button>
                            </h3>
                            <div class="prof-collapse" id="view-sec-dental-body">
                                <div class="prof-collapse-inner">
                                    <div class="prof-section-body">
                                    <div class="row g-3 prof-fields">
                                        <div class="col-md-6">
                                            <div class="view-field-label">Previous Dentist</div>
                                            <div class="view-field-value" id="vf_prev_dentist">—</div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="view-field-label">Last Dental Visit</div>
                                            <div class="view-field-value" id="vf_last_visit">—</div>
                                        </div>
                                        <div class="col-12">
                                            <div class="view-field-label">Dental Concerns / Notes</div>
                                            <div class="view-field-value" id="vf_dental_notes">—</div>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <section class="prof-section collapsed" id="view-sec-allergies" data-sec="allergies">
                            <h3 class="prof-sec-h">
                                <button type="button" class="prof-section-header" onclick="toggleSection(this)"
                                    aria-expanded="false" aria-controls="view-sec-allergies-body">
                                    <span class="prof-sec-icon orange" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
                                    <span class="prof-sec-text">
                                        <span class="prof-sec-title">Allergies</span>
                                        <span class="prof-sec-sub">Drug, food and material allergies</span>
                                    </span>
                                        <span class="prof-sec-meta" data-meta></span>
                                    <i class="fa-solid fa-chevron-down prof-chevron" aria-hidden="true"></i>
                                </button>
                            </h3>
                            <div class="prof-collapse" id="view-sec-allergies-body">
                                <div class="prof-collapse-inner">
                                    <div class="prof-section-body">
                                    <div class="row g-3 prof-fields">
                                        <div class="col-12">
                                            <div class="view-field-label">Known Allergies</div>
                                            <div id="vf_allergies" class="view-tags-wrap"><span class="view-tag-empty">None recorded</span></div>
                                        </div>
                                        <div class="col-12">
                                            <div class="view-field-label">Other Allergies</div>
                                            <div class="view-field-value" id="vf_other_allergies">—</div>
                                        </div>
                                        <div class="col-12">
                                            <div class="view-field-label">Allergy Reaction</div>
                                            <div class="view-field-value" id="vf_allergy_reaction">—</div>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <section class="prof-section collapsed" id="view-sec-conditions" data-sec="conditions">
                            <h3 class="prof-sec-h">
                                <button type="button" class="prof-section-header" onclick="toggleSection(this)"
                                    aria-expanded="false" aria-controls="view-sec-conditions-body">
                                    <span class="prof-sec-icon red" aria-hidden="true"><i class="fa-solid fa-file-medical"></i></span>
                                    <span class="prof-sec-text">
                                        <span class="prof-sec-title">Medical Conditions</span>
                                        <span class="prof-sec-sub">Known conditions and other notes</span>
                                    </span>
                                        <span class="prof-sec-meta" data-meta></span>
                                    <i class="fa-solid fa-chevron-down prof-chevron" aria-hidden="true"></i>
                                </button>
                            </h3>
                            <div class="prof-collapse" id="view-sec-conditions-body">
                                <div class="prof-collapse-inner">
                                    <div class="prof-section-body">
                                    <div class="row g-3 prof-fields">
                                        <div class="col-12">
                                            <div class="view-field-label">Medical Conditions</div>
                                            <div id="vf_conditions" class="view-tags-wrap"><span class="view-tag-empty">None recorded</span></div>
                                        </div>
                                        <div class="col-12">
                                            <div class="view-field-label">Other Conditions</div>
                                            <div class="view-field-value" id="vf_other_conditions">—</div>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        </div>
                    </div><!-- end #profileViewPanel -->

                    <!-- ── EDIT PANEL ── -->
                    <div id="profileEditPanel" style="display:none;">
                        <div class="prof-edit-intro">
                            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                            <span>Fields marked <span class="req-star">*</span> are required. Your changes are saved only when you press <strong>Save Changes</strong>.</span>
                        </div>
                        <div class="prof-sections">
                        <section class="prof-section" id="sec-basic" data-sec="basic">
                            <h3 class="prof-sec-h">
                                <button type="button" class="prof-section-header" onclick="toggleSection(this)"
                                    aria-expanded="true" aria-controls="sec-basic-body">
                                    <span class="prof-sec-icon blue" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
                                    <span class="prof-sec-text">
                                        <span class="prof-sec-title">Basic Information</span>
                                        <span class="prof-sec-sub">Name, birthdate, gender and civil status</span>
                                    </span>
                                    <i class="fa-solid fa-chevron-down prof-chevron" aria-hidden="true"></i>
                                </button>
                            </h3>
                            <div class="prof-collapse" id="sec-basic-body">
                                <div class="prof-collapse-inner">
                                    <div class="prof-section-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="pf-label" for="pf_first">First Name <span class="req-star" aria-hidden="true">*</span></label>
                                            <input type="text" id="pf_first" class="pf-input" placeholder="Juan" onblur="normalizeNameInput(this)">
                                            <div class="pf-err" id="err_pf_first"></div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="pf-label" for="pf_middle">Middle Name <span class="pf-opt">(optional)</span></label>
                                            <input type="text" id="pf_middle" class="pf-input" placeholder="Santos" onblur="normalizeNameInput(this)">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="pf-label" for="pf_last">Last Name <span class="req-star" aria-hidden="true">*</span></label>
                                            <input type="text" id="pf_last" class="pf-input" placeholder="Dela Cruz" onblur="normalizeNameInput(this)">
                                            <div class="pf-err" id="err_pf_last"></div>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="pf-label" for="pf_suffix">Suffix <span class="pf-opt">(optional)</span></label>
                                            <select id="pf_suffix" class="pf-select js-custom-select" data-cs-search data-cs-no-placeholder>
                                                <option value="">None</option>
                                                <option>Jr.</option>
                                                <option>Sr.</option>
                                                <option>II</option>
                                                <option>III</option>
                                                <option>IV</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="pf-label" for="pf_birthdate">Birthdate <span class="req-star" aria-hidden="true">*</span></label>
                                            <input type="date" id="pf_birthdate" class="pf-input js-date-picker" data-dp-max="today" placeholder="Select birthdate" onchange="calcAge()">
                                            <div class="pf-err" id="err_pf_birthdate"></div>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="pf-label" for="pf_age">Age</label>
                                            <input type="text" id="pf_age" class="pf-input pf-readonly pf-age" readonly placeholder="—" tabindex="-1" aria-label="Age (calculated)">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="pf-label" for="pf_gender">Gender</label>
                                            <select id="pf_gender" class="pf-select js-custom-select" data-cs-search data-cs-no-placeholder>
                                                <option value="">— Select —</option>
                                                <option value="Male">Male</option>
                                                <option value="Female">Female</option>
                                                <option value="Non-binary">Non-binary</option>
                                                <option value="Another gender">Another gender</option>
                                                <option value="Prefer not to say">Prefer not to say</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="pf-label" for="pf_civil_status">Civil Status</label>
                                            <select id="pf_civil_status" class="pf-select js-custom-select" data-cs-search data-cs-no-placeholder>
                                                <option value="">— Select —</option>
                                                <option>Single</option>
                                                <option>Married</option>
                                                <option>Widowed</option>
                                                <option>Separated</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="pf-label" for="pf_nationality">Nationality</label>
                                            <!-- Options from assets/js/nationalities.js; searchable via custom-select.js -->
                                            <select id="pf_nationality" class="pf-select js-custom-select" data-cs-search data-cs-no-placeholder data-nationality-options>
                                                <option value="">— Select —</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="mt-3" id="guardianBlock" style="display:none;">
                                        <div class="prof-guardian">
                                            <div class="prof-guardian-title">
                                                <i class="fa-solid fa-child me-1"></i> Parent / Guardian Information
                                                <span class="prof-guardian-note">Required for patients under 18</span>
                                            </div>
                                            <div class="row g-2">
                                                <div class="col-md-6">
                                                    <label class="pf-label" for="pf_guardian_name">Guardian Full Name</label>
                                                    <input type="text" id="pf_guardian_name" class="pf-input" placeholder="Full name of parent or guardian">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="pf-label" for="pf_guardian_relation">Relationship to Patient</label>
                                                    <select id="pf_guardian_relation" class="pf-select js-custom-select">
                                                        <option value="">— Select —</option>
                                                        <option>Mother</option>
                                                        <option>Father</option>
                                                        <option>Legal Guardian</option>
                                                        <option>Grandparent</option>
                                                        <option>Sibling</option>
                                                        <option>Other</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="pf-label" for="pf_guardian_phone">Guardian Phone Number</label>
                                                    <div class="pf-phone">
                                                        <span class="pf-phone-prefix">+63</span>
                                                        <input type="tel" id="pf_guardian_phone" class="pf-input" placeholder="9XX XXX XXXX" maxlength="10" inputmode="numeric"
                                                            autocomplete="off" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                                    </div>
                                                    <div class="prof-hint">10-digit mobile number starting with 9</div>
                                                    <div class="pf-err" id="err_pf_guardian_phone"></div>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="pf-label" for="pf_guardian_address">Guardian Address</label>
                                                    <textarea id="pf_guardian_address" class="pf-input" rows="2" maxlength="255"
                                                        placeholder="House No., Street, Barangay, City, Province"></textarea>
                                                    <div class="prof-hint">Leave blank if same as the patient's address</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <section class="prof-section" id="sec-contact" data-sec="contact">
                            <h3 class="prof-sec-h">
                                <button type="button" class="prof-section-header" onclick="toggleSection(this)"
                                    aria-expanded="true" aria-controls="sec-contact-body">
                                    <span class="prof-sec-icon green" aria-hidden="true"><i class="fa-solid fa-address-book"></i></span>
                                    <span class="prof-sec-text">
                                        <span class="prof-sec-title">Contact Information</span>
                                        <span class="prof-sec-sub">Email, phone and home address</span>
                                    </span>
                                    <i class="fa-solid fa-chevron-down prof-chevron" aria-hidden="true"></i>
                                </button>
                            </h3>
                            <div class="prof-collapse" id="sec-contact-body">
                                <div class="prof-collapse-inner">
                                    <div class="prof-section-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="pf-label" for="pf_email">Email Address</label>
                                            <input type="email" id="pf_email" class="pf-input pf-readonly" readonly>
                                            <div class="prof-hint">Your sign-in email can’t be changed here.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="pf-label" for="pf_contact">Contact Number</label>
                                            <div class="pf-phone">
                                                <span class="pf-phone-prefix">+63</span>
                                                <input type="tel" id="pf_contact" class="pf-input" placeholder="9XX XXX XXXX" maxlength="10" inputmode="numeric"
                                                    oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                            </div>
                                            <div class="prof-hint">10-digit mobile number starting with 9</div>
                                            <div class="pf-err" id="err_pf_contact"></div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="pf-label" for="pf_home_phone">Home Phone</label>
                                            <input type="text" id="pf_home_phone" class="pf-input" placeholder="(02) XXXX-XXXX">
                                        </div>
                                        <div class="col-12">
                                            <label class="pf-label" for="pf_address">Home Address</label>
                                            <input type="text" id="pf_address" class="pf-input" placeholder="House No., Street" onblur="normalizeAddressInput(this)">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="pf-label">Province</label>
                                            <div class="modal-cs" id="pf_provinceSelect">
                                                <div class="modal-cs-trigger" onclick="toggleCustomSelect('pf_provinceSelect')">
                                                    <span class="modal-cs-value" id="pf_provinceValue">Select province</span>
                                                    <i class="fa-solid fa-chevron-down modal-cs-arrow"></i>
                                                </div>
                                                <div class="modal-cs-dropdown" id="pf_provinceDropdown">
                                                    <div class="modal-cs-search-wrap">
                                                        <i class="fa-solid fa-magnifying-glass"></i>
                                                        <input type="text" class="modal-cs-search" placeholder="Search province..." oninput="filterCustomSelect(this,'pf_provinceDropdown')">
                                                    </div>
                                                    <ul class="modal-cs-list" id="pf_provinceList"></ul>
                                                </div>
                                            </div>
                                            <input type="hidden" id="pf_province">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="pf-label">City / Municipality</label>
                                            <div class="modal-cs" id="pf_citySelect">
                                                <div class="modal-cs-trigger" onclick="toggleCustomSelect('pf_citySelect')">
                                                    <span class="modal-cs-value" id="pf_cityValue">Select province first</span>
                                                    <i class="fa-solid fa-chevron-down modal-cs-arrow"></i>
                                                </div>
                                                <div class="modal-cs-dropdown" id="pf_cityDropdown">
                                                    <div class="modal-cs-search-wrap">
                                                        <i class="fa-solid fa-magnifying-glass"></i>
                                                        <input type="text" class="modal-cs-search" placeholder="Search city..." oninput="filterCustomSelect(this,'pf_cityDropdown')">
                                                    </div>
                                                    <ul class="modal-cs-list" id="pf_cityList"></ul>
                                                </div>
                                            </div>
                                            <input type="hidden" id="pf_city">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="pf-label">Barangay</label>
                                            <div class="modal-cs" id="pf_barangaySelect">
                                                <div class="modal-cs-trigger" onclick="toggleCustomSelect('pf_barangaySelect')">
                                                    <span class="modal-cs-value" id="pf_barangayValue">Select city first</span>
                                                    <i class="fa-solid fa-chevron-down modal-cs-arrow"></i>
                                                </div>
                                                <div class="modal-cs-dropdown" id="pf_barangayDropdown">
                                                    <div class="modal-cs-search-wrap">
                                                        <i class="fa-solid fa-magnifying-glass"></i>
                                                        <input type="text" class="modal-cs-search" placeholder="Search barangay..." oninput="filterCustomSelect(this,'pf_barangayDropdown')">
                                                    </div>
                                                    <ul class="modal-cs-list" id="pf_barangayList"></ul>
                                                </div>
                                            </div>
                                            <input type="hidden" id="pf_barangay">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="pf-label" for="pf_zip">ZIP Code</label>
                                            <input type="text" id="pf_zip" class="pf-input" placeholder="1100" maxlength="6">
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <section class="prof-section" id="sec-consult" data-sec="consult">
                            <h3 class="prof-sec-h">
                                <button type="button" class="prof-section-header" onclick="toggleSection(this)"
                                    aria-expanded="true" aria-controls="sec-consult-body">
                                    <span class="prof-sec-icon blue" aria-hidden="true"><i class="fa-solid fa-stethoscope"></i></span>
                                    <span class="prof-sec-text">
                                        <span class="prof-sec-title">Consultation</span>
                                        <span class="prof-sec-sub">Reason for visit and referral</span>
                                    </span>
                                    <i class="fa-solid fa-chevron-down prof-chevron" aria-hidden="true"></i>
                                </button>
                            </h3>
                            <div class="prof-collapse" id="sec-consult-body">
                                <div class="prof-collapse-inner">
                                    <div class="prof-section-body">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="pf-label" for="pf_chief_complaint">Chief Complaint / Reason for Visit</label>
                                            <textarea id="pf_chief_complaint" class="pf-input" rows="2" placeholder="Describe your main dental concern…"></textarea>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="pf-label" for="pf_referral_source">How did you hear about us?</label>
                                            <select id="pf_referral_source" class="pf-select js-custom-select">
                                                <option value="">— Select —</option>
                                                <option>Friend / Family</option>
                                                <option>Social Media</option>
                                                <option>Google / Search</option>
                                                <option>Flyer / Poster</option>
                                                <option>Doctor Referral</option>
                                                <option>Other</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="pf-label" for="pf_referred_by">Referred by (name)</label>
                                            <input type="text" id="pf_referred_by" class="pf-input" placeholder="Name of referrer (if any)">
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <section class="prof-section" id="sec-dental" data-sec="dental">
                            <h3 class="prof-sec-h">
                                <button type="button" class="prof-section-header" onclick="toggleSection(this)"
                                    aria-expanded="true" aria-controls="sec-dental-body">
                                    <span class="prof-sec-icon teal" aria-hidden="true"><i class="fa-solid fa-tooth"></i></span>
                                    <span class="prof-sec-text">
                                        <span class="prof-sec-title">Dental History</span>
                                        <span class="prof-sec-sub">Previous dentist, last visit and concerns</span>
                                    </span>
                                    <i class="fa-solid fa-chevron-down prof-chevron" aria-hidden="true"></i>
                                </button>
                            </h3>
                            <div class="prof-collapse" id="sec-dental-body">
                                <div class="prof-collapse-inner">
                                    <div class="prof-section-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="pf-label" for="pf_prev_dentist">Previous Dentist</label>
                                            <input type="text" id="pf_prev_dentist" class="pf-input" placeholder="Dr. Name">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="pf-label" for="pf_last_visit">Last Dental Visit</label>
                                            <input type="date" id="pf_last_visit" class="pf-input js-date-picker" data-dp-max="today" placeholder="Select date">
                                        </div>
                                        <div class="col-12">
                                            <label class="pf-label" for="pf_dental_notes">Dental Concerns / Notes</label>
                                            <textarea id="pf_dental_notes" class="pf-input" rows="2" placeholder="Any specific dental concerns or notes…"></textarea>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <section class="prof-section" id="sec-allergies" data-sec="allergies">
                            <h3 class="prof-sec-h">
                                <button type="button" class="prof-section-header" onclick="toggleSection(this)"
                                    aria-expanded="true" aria-controls="sec-allergies-body">
                                    <span class="prof-sec-icon orange" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
                                    <span class="prof-sec-text">
                                        <span class="prof-sec-title">Allergies</span>
                                        <span class="prof-sec-sub">Drug, food and material allergies</span>
                                    </span>
                                    <i class="fa-solid fa-chevron-down prof-chevron" aria-hidden="true"></i>
                                </button>
                            </h3>
                            <div class="prof-collapse" id="sec-allergies-body">
                                <div class="prof-collapse-inner">
                                    <div class="prof-section-body">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="pf-label">Known Allergies</label>
                                            <div class="check-grid">
                                                <?php foreach (['Penicillin', 'Aspirin', 'Ibuprofen', 'Latex', 'Metals', 'Local Anesthetics', 'Codeine', 'Sulfa Drugs', 'Iodine', 'Contrast Dye'] as $allergy): ?>
                                                    <label class="check-item">
                                                        <input type="checkbox" class="pf-checkbox" name="allergies" value="<?= htmlspecialchars($allergy) ?>"> <?= htmlspecialchars($allergy) ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label class="pf-label" for="pf_other_allergies">Other Allergies (specify)</label>
                                            <input type="text" id="pf_other_allergies" class="pf-input" placeholder="List any other allergies not mentioned above…">
                                        </div>
                                        <div class="col-12">
                                            <label class="pf-label" for="pf_allergy_reaction">Allergy Reaction Description</label>
                                            <textarea id="pf_allergy_reaction" class="pf-input" rows="2" placeholder="Describe your typical allergic reaction…"></textarea>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <section class="prof-section" id="sec-conditions" data-sec="conditions">
                            <h3 class="prof-sec-h">
                                <button type="button" class="prof-section-header" onclick="toggleSection(this)"
                                    aria-expanded="true" aria-controls="sec-conditions-body">
                                    <span class="prof-sec-icon red" aria-hidden="true"><i class="fa-solid fa-file-medical"></i></span>
                                    <span class="prof-sec-text">
                                        <span class="prof-sec-title">Medical Conditions</span>
                                        <span class="prof-sec-sub">Check all that apply</span>
                                    </span>
                                    <i class="fa-solid fa-chevron-down prof-chevron" aria-hidden="true"></i>
                                </button>
                            </h3>
                            <div class="prof-collapse" id="sec-conditions-body">
                                <div class="prof-collapse-inner">
                                    <div class="prof-section-body">
                                    <div class="check-grid check-grid-wide">
                                        <?php
                                        $conditions = [
                                            'Hypertension',
                                            'Diabetes (Type 1)',
                                            'Diabetes (Type 2)',
                                            'Heart Disease',
                                            'Stroke',
                                            'Asthma',
                                            'Tuberculosis',
                                            'Hepatitis B',
                                            'Hepatitis C',
                                            'Kidney Disease',
                                            'Thyroid Disease',
                                            'Cancer',
                                            'Epilepsy / Seizures',
                                            'Arthritis',
                                            'Osteoporosis',
                                            'Anemia',
                                            'HIV / AIDS',
                                            'Lupus',
                                            'Psoriasis',
                                            'GERD / Acid Reflux',
                                            'Anxiety / Depression',
                                            "Alzheimer's",
                                            "Parkinson's",
                                        ];
                                        foreach ($conditions as $cond): ?>
                                            <label class="check-item">
                                                <input type="checkbox" class="pf-checkbox" name="conditions" value="<?= htmlspecialchars($cond) ?>"> <?= htmlspecialchars($cond) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="row g-3 mt-1">
                                        <div class="col-12">
                                            <label class="pf-label" for="pf_other_conditions">Other Conditions (specify)</label>
                                            <input type="text" id="pf_other_conditions" class="pf-input" placeholder="Any other medical conditions not listed above…">
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        </div>

                        <!-- Sticky save bar -->
                        <div class="prof-savebar">
                            <span class="prof-savebar-text" id="profDirtyNote">No unsaved changes</span>
                            <div class="prof-savebar-actions">
                                <button type="button" class="prof-btn prof-btn-light" onclick="cancelEditMode()">Cancel</button>
                                <button type="button" class="prof-btn prof-btn-primary" onclick="saveProfile()"><i class="fa-solid fa-floppy-disk me-1"></i> Save Changes</button>
                            </div>
                        </div>
                    </div><!-- end #profileEditPanel -->

                </div>
            </div>
        </div><!-- end page-profile -->

    </div><!-- end main-content -->

    <!-- ═══════════════════════════════════════════════════════
         MEDICAL QUESTIONNAIRE MODAL — first login only
    ═══════════════════════════════════════════════════════ -->
    <?php
    // Questions: [radio name, label, follow-up wrapper id, follow-up input id, placeholder]
    // Names / ids are read by submitQuestionnaire() and mqToggleFu() in portal-dashboard.js.
    $mqSections = [
        ['General Health', 'fa-heart-pulse', [
            ['mq_good_health', 'Are you in good health?'],
            ['mq_under_treatment', 'Are you under medical treatment now?', 'mqFuTreatment', 'mq_treatment_condition', 'What is the condition being treated?'],
        ]],
        ['Medical History', 'fa-file-medical', [
            ['mq_serious_illness', 'Have you ever had a serious illness or surgical operation?', 'mqFuIllness', 'mq_illness_detail', 'What illness or operation?'],
            ['mq_hospitalized', 'Have you ever been hospitalized?', 'mqFuHospitalized', 'mq_hospitalized_detail', 'When and why?'],
        ]],
        ['Medications & Lifestyle', 'fa-pills', [
            ['mq_taking_meds', 'Are you taking any prescription or non-prescription medication?', 'mqFuMeds', 'mq_meds_detail', 'Please specify medications'],
            ['mq_tobacco', 'Do you use tobacco products?'],
            ['mq_dangerous_drugs', 'Do you use alcohol, cocaine, or other dangerous drugs?'],
        ]],
    ];
    $mqWomen = [
        ['mq_pregnant', 'Are you pregnant?'],
        ['mq_nursing', 'Are you nursing?'],
        ['mq_birth_control', 'Are you taking birth control pills?'],
    ];
    $mqRow = function (array $q): string {
        [$name, $label] = $q;
        $fu  = $q[2] ?? null;
        $lid = $name . '_label';
        $on  = $fu ? ' onchange="mqToggleFu(\'' . $fu . '\',\'yes\',this)"' : '';
        $opt = function (string $val, string $text, string $icon) use ($name, $on) {
            return '<label class="mq-choice mq-choice-' . $val . '"><input type="radio" name="' . $name . '" value="' . $val . '"' . $on . '>'
                . '<span><i class="fa-solid ' . $icon . '" aria-hidden="true"></i> ' . $text . '</span></label>';
        };
        $html = '<div class="mq-q mq-yn-row">'
            . '<div class="mq-q-main"><span class="mq-q-label" id="' . $lid . '">' . htmlspecialchars($label) . '</span>'
            . '<div class="mq-choices" role="radiogroup" aria-labelledby="' . $lid . '">'
            . $opt('yes', 'Yes', 'fa-check') . $opt('no', 'No', 'fa-xmark') . '</div></div>';
        if ($fu) {
            $html .= '<div class="mq-fu-field" id="' . $fu . '" style="display:none;">'
                . '<label class="mq-fu-label" for="' . $q[3] . '"><i class="fa-solid fa-turn-up fa-rotate-90" aria-hidden="true"></i> Please tell us more</label>'
                . '<input type="text" id="' . $q[3] . '" class="mq-input pf-input" placeholder="' . htmlspecialchars($q[4]) . '"></div>';
        }
        return $html . '</div>';
    };
    ?>
    <div class="modal fade" id="questionnaireModal" tabindex="-1"
        aria-labelledby="mqModalLabel" aria-describedby="mqModalDesc" aria-modal="true" role="dialog"
        data-gender="<?= htmlspecialchars($portalGender) ?>">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable mq-dialog">
            <div class="modal-content mq-content">

                <div class="modal-header mq-header">
                    <div class="mq-header-icon" aria-hidden="true"><i class="fa-solid fa-clipboard-question"></i></div>
                    <div>
                        <h5 class="modal-title mq-title" id="mqModalLabel">Health Questionnaire</h5>
                        <p class="mq-subtitle" id="mqModalDesc">A one-time form that helps our dentists provide you with the safest care.</p>
                    </div>
                </div>

                <div class="modal-body mq-body">
                    <p class="mq-intro">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        <span>Please answer <strong>Yes</strong> or <strong>No</strong> to each question. If you answer Yes where
                            more detail helps, a short follow-up field will appear. It takes about 2 minutes.</span>
                    </p>
                    <div id="mqError" class="mq-error" role="alert" style="display:none;"></div>

                    <?php foreach ($mqSections as [$secTitle, $secIcon, $secQs]): ?>
                        <section class="mq-section">
                            <h6 class="mq-section-title"><i class="fa-solid <?= $secIcon ?>" aria-hidden="true"></i> <?= htmlspecialchars($secTitle) ?></h6>
                            <?php foreach ($secQs as $q) echo $mqRow($q); ?>
                        </section>
                    <?php endforeach; ?>

                    <!-- Women only block — shown/hidden via data-gender -->
                    <section id="mqWomenBlock" class="mq-section mq-section-women" style="display:none;">
                        <h6 class="mq-section-title"><i class="fa-solid fa-venus" aria-hidden="true"></i> For Women Only</h6>
                        <?php foreach ($mqWomen as $q) echo $mqRow($q); ?>
                    </section>
                </div>

                <div class="modal-footer mq-footer">
                    <p class="mq-privacy"><i class="fa-solid fa-lock" aria-hidden="true"></i> Your answers are private and stored securely.</p>
                    <button type="button" id="mqSubmitBtn" class="mq-submit" onclick="submitQuestionnaire()">
                        <i class="fa-solid fa-check me-1"></i> Submit & Continue
                    </button>
                </div>

            </div>
        </div>
    </div>

    <!-- Cancel Appointment Modal -->
    <div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius:14px;overflow:hidden;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-calendar-xmark text-danger me-2"></i>Cancel Appointment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p style="color:var(--text-muted);font-size:.9rem;">Are you sure you want to cancel appointment <strong id="cancelApptCode"></strong>? This action cannot be undone.</p>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button class="btn btn-light" data-bs-dismiss="modal">Keep It</button>
                    <button class="btn btn-danger px-4 text-white" onclick="confirmCancel()">Yes, Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Appointment Details Modal (opened from the Action menu in My Appointments) -->
    <div class="modal fade" id="apptDetailModal" tabindex="-1" aria-labelledby="adModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down adm-dialog">
            <div class="modal-content adm-content">

                <!-- Header (white) -->
                <div class="modal-header adm-header">
                    <span class="adm-header-icon" aria-hidden="true"><i class="fa-solid fa-calendar-check"></i></span>
                    <div class="adm-header-text">
                        <h5 class="modal-title adm-title" id="adModalTitle">Appointment Details</h5>
                        <div class="adm-ref">Reference No. <span id="ad_ref">—</span></div>
                    </div>
                    <button type="button" class="btn-close adm-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body adm-body">

                    <!-- Summary row -->
                    <div class="adm-summary">
                        <div class="adm-sum-item adm-sum-status">
                            <div class="adm-sum-label">Status</div>
                            <div id="ad_sum_status">—</div>
                        </div>
                        <div class="adm-sum-item">
                            <span class="adm-sum-icon" aria-hidden="true"><i class="fa-regular fa-calendar"></i></span>
                            <div class="adm-sum-text">
                                <div class="adm-sum-label">Date &amp; Time</div>
                                <div class="adm-sum-value" id="ad_sum_date">—</div>
                                <div class="adm-sum-value" id="ad_sum_time"></div>
                            </div>
                        </div>
                        <div class="adm-sum-item">
                            <span class="adm-sum-icon" aria-hidden="true"><i class="fa-solid fa-tooth"></i></span>
                            <div class="adm-sum-text">
                                <div class="adm-sum-label">Service(s)</div>
                                <div class="adm-sum-value adm-sum-services" id="ad_sum_services">—</div>
                            </div>
                        </div>
                        <div class="adm-sum-item">
                            <span class="adm-sum-icon" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span>
                            <div class="adm-sum-text">
                                <div class="adm-sum-label">Branch</div>
                                <div class="adm-sum-value" id="ad_sum_branch">—</div>
                                <div class="adm-sum-sub" id="ad_sum_address"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Information cards (2 × 2) -->
                    <div class="adm-grid">
                        <section class="adm-card" aria-labelledby="adPatientTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
                                <h6 id="adPatientTitle">Patient Information</h6>
                            </div>
                            <div class="adm-card-body" id="ad_patient"></div>
                        </section>

                        <section class="adm-card" aria-labelledby="adApptTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-calendar-days"></i></span>
                                <h6 id="adApptTitle">Appointment Information</h6>
                            </div>
                            <div class="adm-card-body" id="ad_appointment"></div>
                        </section>

                        <section class="adm-card" aria-labelledby="adPayTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-file-invoice-dollar"></i></span>
                                <h6 id="adPayTitle">Payment Information</h6>
                            </div>
                            <div class="adm-card-body" id="ad_payment"></div>
                        </section>

                        <section class="adm-card" aria-labelledby="adMoreTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-file-lines"></i></span>
                                <h6 id="adMoreTitle">Additional Details</h6>
                            </div>
                            <div class="adm-card-body" id="ad_additional"></div>
                        </section>
                    </div>
                </div>

                <!-- Footer: Close only -->
                <div class="modal-footer adm-footer">
                    <button type="button" class="adm-btn-close" data-bs-dismiss="modal">Close</button>
                </div>

            </div>
        </div>
    </div>

    <!-- Treatment Details Modal (Treatment History → Action → View Treatment History) -->
    <div class="modal fade" id="treatmentDetailModal" tabindex="-1" aria-labelledby="tdModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down adm-dialog">
            <div class="modal-content adm-content">
                <div class="modal-header adm-header">
                    <span class="adm-header-icon" aria-hidden="true"><i class="fa-solid fa-tooth"></i></span>
                    <div class="adm-header-text">
                        <h5 class="modal-title adm-title" id="tdModalTitle">Treatment Details</h5>
                        <div class="adm-ref">Reference No. <span id="td_ref">—</span></div>
                    </div>
                    <button type="button" class="btn-close adm-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body adm-body">
                    <div class="adm-summary">
                        <div class="adm-sum-item adm-sum-status">
                            <div class="adm-sum-label">Status</div>
                            <div id="td_sum_status">—</div>
                        </div>
                        <div class="adm-sum-item">
                            <span class="adm-sum-icon" aria-hidden="true"><i class="fa-regular fa-calendar"></i></span>
                            <div class="adm-sum-text">
                                <div class="adm-sum-label">Treatment Date</div>
                                <div class="adm-sum-value" id="td_sum_date">—</div>
                            </div>
                        </div>
                        <div class="adm-sum-item">
                            <span class="adm-sum-icon" aria-hidden="true"><i class="fa-solid fa-tooth"></i></span>
                            <div class="adm-sum-text">
                                <div class="adm-sum-label">Procedure</div>
                                <div class="adm-sum-value" id="td_sum_procedure">—</div>
                            </div>
                        </div>
                        <div class="adm-sum-item">
                            <span class="adm-sum-icon" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span>
                            <div class="adm-sum-text">
                                <div class="adm-sum-label">Branch</div>
                                <div class="adm-sum-value" id="td_sum_branch">—</div>
                                <div class="adm-sum-sub" id="td_sum_address"></div>
                            </div>
                        </div>
                    </div>
                    <div class="adm-grid">
                        <section class="adm-card" aria-labelledby="tdInfoTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-notes-medical"></i></span>
                                <h6 id="tdInfoTitle">Treatment Information</h6>
                            </div>
                            <div class="adm-card-body" id="td_info"></div>
                        </section>
                        <section class="adm-card" aria-labelledby="tdApptTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-calendar-days"></i></span>
                                <h6 id="tdApptTitle">Appointment</h6>
                            </div>
                            <div class="adm-card-body" id="td_appointment"></div>
                        </section>
                        <section class="adm-card" aria-labelledby="tdPayTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-file-invoice-dollar"></i></span>
                                <h6 id="tdPayTitle">Billing Information</h6>
                            </div>
                            <div class="adm-card-body" id="td_billing"></div>
                        </section>
                        <section class="adm-card" aria-labelledby="tdNotesTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-file-lines"></i></span>
                                <h6 id="tdNotesTitle">Procedure Notes</h6>
                            </div>
                            <div class="adm-card-body" id="td_notes"></div>
                        </section>
                    </div>
                </div>
                <div class="modal-footer adm-footer">
                    <button type="button" class="adm-btn-close" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Invoice Details Modal (Billing & Payments → Action → View Details) -->
    <div class="modal fade" id="invoiceDetailModal" tabindex="-1" aria-labelledby="ivModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down adm-dialog">
            <div class="modal-content adm-content">
                <div class="modal-header adm-header">
                    <span class="adm-header-icon" aria-hidden="true"><i class="fa-solid fa-file-invoice-dollar"></i></span>
                    <div class="adm-header-text">
                        <h5 class="modal-title adm-title" id="ivModalTitle">Invoice Details</h5>
                        <div class="adm-ref">Invoice No. <span id="iv_ref">—</span></div>
                    </div>
                    <button type="button" class="btn-close adm-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body adm-body">
                    <div class="adm-summary">
                        <div class="adm-sum-item adm-sum-status">
                            <div class="adm-sum-label">Payment Status</div>
                            <div id="iv_sum_status">—</div>
                        </div>
                        <div class="adm-sum-item">
                            <span class="adm-sum-icon" aria-hidden="true"><i class="fa-regular fa-calendar"></i></span>
                            <div class="adm-sum-text">
                                <div class="adm-sum-label">Invoice Date</div>
                                <div class="adm-sum-value" id="iv_sum_date">—</div>
                            </div>
                        </div>
                        <div class="adm-sum-item">
                            <span class="adm-sum-icon" aria-hidden="true"><i class="fa-solid fa-receipt"></i></span>
                            <div class="adm-sum-text">
                                <div class="adm-sum-label">Total Amount</div>
                                <div class="adm-sum-value" id="iv_sum_total">—</div>
                                <div class="adm-sum-sub" id="iv_sum_paid"></div>
                            </div>
                        </div>
                        <div class="adm-sum-item">
                            <span class="adm-sum-icon" aria-hidden="true"><i class="fa-solid fa-scale-balanced"></i></span>
                            <div class="adm-sum-text">
                                <div class="adm-sum-label">Balance Due</div>
                                <div class="adm-sum-value" id="iv_sum_balance">—</div>
                            </div>
                        </div>
                    </div>
                    <div class="adm-grid">
                        <section class="adm-card" aria-labelledby="ivInfoTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-file-invoice"></i></span>
                                <h6 id="ivInfoTitle">Invoice Information</h6>
                            </div>
                            <div class="adm-card-body" id="iv_info"></div>
                        </section>
                        <section class="adm-card" aria-labelledby="ivSvcTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-tooth"></i></span>
                                <h6 id="ivSvcTitle">Services Rendered</h6>
                            </div>
                            <div class="adm-card-body" id="iv_services"></div>
                        </section>
                        <section class="adm-card" aria-labelledby="ivVisitTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-calendar-days"></i></span>
                                <h6 id="ivVisitTitle">Visit</h6>
                            </div>
                            <div class="adm-card-body" id="iv_visit"></div>
                        </section>
                        <section class="adm-card" aria-labelledby="ivPayTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-money-bill-wave"></i></span>
                                <h6 id="ivPayTitle">Payment History</h6>
                            </div>
                            <div class="adm-card-body" id="iv_payments"></div>
                        </section>
                    </div>
                </div>
                <div class="modal-footer adm-footer">
                    <button type="button" class="adm-btn-close" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Medical Record Modal (same design as Appointment Details; all mr_* IDs unchanged) -->
    <div class="modal fade" id="medicalRecordModal" tabindex="-1" aria-labelledby="mrModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down adm-dialog">
            <div class="modal-content adm-content">

                <!-- Header (white) -->
                <div class="modal-header adm-header">
                    <span class="adm-header-icon" aria-hidden="true"><i class="fa-solid fa-file-waveform"></i></span>
                    <div class="adm-header-text">
                        <h5 class="modal-title adm-title" id="mrModalTitle">Medical Record</h5>
                        <div class="adm-ref" id="mrModalSubtitle">Appointment visit details</div>
                    </div>
                    <button type="button" class="btn-close adm-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- Loading state -->
                <div id="mrLoading" class="modal-body adm-body adm-state">
                    <div class="spinner-border text-primary" role="status" style="width:1.6rem;height:1.6rem;"></div>
                    <p>Loading medical record…</p>
                </div>

                <!-- Error state -->
                <div id="mrError" class="modal-body adm-body adm-state adm-state-error" style="display:none;">
                    <i class="fa-solid fa-circle-xmark"></i>
                    <p id="mrErrorText">Could not load record.</p>
                </div>

                <!-- Content -->
                <div id="mrContent" class="modal-body adm-body" style="display:none;">

                    <!-- Summary row -->
                    <div class="adm-summary">
                        <div class="adm-sum-item adm-sum-status">
                            <div class="adm-sum-label">Status</div>
                            <div id="mr_apt_status">—</div>
                        </div>
                        <div class="adm-sum-item">
                            <span class="adm-sum-icon" aria-hidden="true"><i class="fa-regular fa-calendar"></i></span>
                            <div class="adm-sum-text">
                                <div class="adm-sum-label">Date &amp; Time</div>
                                <div class="adm-sum-value" id="mr_apt_datetime">—</div>
                            </div>
                        </div>
                        <div class="adm-sum-item">
                            <span class="adm-sum-icon" aria-hidden="true"><i class="fa-solid fa-tooth"></i></span>
                            <div class="adm-sum-text">
                                <div class="adm-sum-label">Service</div>
                                <div class="adm-sum-value" id="mr_apt_service">—</div>
                            </div>
                        </div>
                        <div class="adm-sum-item">
                            <span class="adm-sum-icon" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span>
                            <div class="adm-sum-text">
                                <div class="adm-sum-label">Branch</div>
                                <div class="adm-sum-value" id="mr_apt_branch">—</div>
                            </div>
                        </div>
                    </div>

                    <div class="adm-grid">
                        <!-- Appointment Information -->
                        <section class="adm-card" aria-labelledby="mrApptTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-calendar-check"></i></span>
                                <h6 id="mrApptTitle">Appointment Information</h6>
                            </div>
                            <div class="adm-card-body">
                                    <div class="adm-row"><div class="adm-label">Appointment Code</div><div class="adm-colon" aria-hidden="true">:</div><div class="adm-value" id="mr_apt_code">—</div></div>
                                    <div class="adm-row"><div class="adm-label">Payment</div><div class="adm-colon" aria-hidden="true">:</div><div class="adm-value" id="mr_apt_payment">—</div></div>
                                    <div class="adm-row"><div class="adm-label">Appointment Notes</div><div class="adm-colon" aria-hidden="true">:</div><div class="adm-value pre" id="mr_apt_notes">—</div></div>
                            </div>
                        </section>

                        <!-- Vital Signs -->
                        <section class="adm-card" aria-labelledby="mrVitalsTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-heart-pulse"></i></span>
                                <h6 id="mrVitalsTitle">Vital Signs</h6>
                            </div>
                            <div class="adm-card-body">
                                <div id="mr_no_vitals" class="adm-empty" style="display:none;">
                                    <i class="fa-solid fa-circle-info me-1"></i>
                                    No vital signs were recorded for this visit.
                                </div>
                                <div id="mr_vitals_data">
                                    <div class="adm-vitals">
                                        <div class="adm-vital">
                                            <i class="fa-solid fa-heart" aria-hidden="true"></i>
                                            <div class="adm-vital-value" id="mr_blood_pressure">—</div>
                                            <div class="adm-vital-label">Blood Pressure <span>mmHg</span></div>
                                        </div>
                                        <div class="adm-vital">
                                            <i class="fa-solid fa-wave-square" aria-hidden="true"></i>
                                            <div class="adm-vital-value" id="mr_pulse_rate">—</div>
                                            <div class="adm-vital-label">Pulse Rate <span>bpm</span></div>
                                        </div>
                                        <div class="adm-vital">
                                            <i class="fa-solid fa-temperature-half" aria-hidden="true"></i>
                                            <div class="adm-vital-value" id="mr_temperature">—</div>
                                            <div class="adm-vital-label">Temperature <span>°C</span></div>
                                        </div>
                                    </div>
                                    <div class="adm-row"><div class="adm-label">Checked By</div><div class="adm-colon" aria-hidden="true">:</div><div class="adm-value" id="mr_checked_by">—</div></div>
                                    <div class="adm-row"><div class="adm-label">Checked At</div><div class="adm-colon" aria-hidden="true">:</div><div class="adm-value" id="mr_checked_at">—</div></div>
                                    <div class="adm-row"><div class="adm-label">Clinical Notes</div><div class="adm-colon" aria-hidden="true">:</div><div class="adm-value pre" id="mr_vital_notes">—</div></div>
                                </div>
                            </div>
                        </section>

                        <!-- Medical Profile (full width) -->
                        <section class="adm-card adm-card-full" aria-labelledby="mrProfileTitle">
                            <div class="adm-card-head">
                                <span class="adm-card-icon" aria-hidden="true"><i class="fa-solid fa-file-medical"></i></span>
                                <h6 id="mrProfileTitle">Medical Profile</h6>
                                <span class="adm-card-sub">Allergies &amp; conditions at time of visit</span>
                            </div>
                            <div class="adm-card-body adm-card-body-split">
                                <div>
                                    <div class="adm-row"><div class="adm-label">Known Allergies</div><div class="adm-colon" aria-hidden="true">:</div><div class="adm-value"><div id="mr_allergies" class="mr-tags-wrap"><span class="mr-tag-empty">None recorded</span></div></div></div>
                                    <div class="adm-row"><div class="adm-label">Other Allergies</div><div class="adm-colon" aria-hidden="true">:</div><div class="adm-value" id="mr_other_allergies">—</div></div>
                                    <div class="adm-row"><div class="adm-label">Allergy Reaction</div><div class="adm-colon" aria-hidden="true">:</div><div class="adm-value" id="mr_allergy_reaction">—</div></div>
                                </div>
                                <div>
                                    <div class="adm-row"><div class="adm-label">Medical Conditions</div><div class="adm-colon" aria-hidden="true">:</div><div class="adm-value"><div id="mr_conditions" class="mr-tags-wrap"><span class="mr-tag-empty">None recorded</span></div></div></div>
                                    <div class="adm-row"><div class="adm-label">Other Conditions</div><div class="adm-colon" aria-hidden="true">:</div><div class="adm-value" id="mr_other_conditions">—</div></div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <p class="adm-readonly"><i class="fa-solid fa-lock me-1"></i> This record is read-only.</p>
                </div><!-- /#mrContent -->

                <!-- Footer: Close only -->
                <div class="modal-footer adm-footer">
                    <button type="button" class="adm-btn-close" data-bs-dismiss="modal">Close</button>
                </div>

            </div>
        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/es-swal.js?v=2"></script>
    <script src="assets/js/odontogram.js?v=1"></script>
    <script src="assets/js/nationalities.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
    <script src="assets/js/signout-confirm.js?v=2"></script>

    <script>
        // ── Name capitalisation (same rules as controllers/name_helper.php) ──
        // Re-cases a word only when it is ALL CAPS, all lower-case, or starts
        // lower-case, or has two capitals in a row (KUPal); intentional mixed
        // case (DeVera, McArthur) is kept.
        // This is a live preview only — the server applies the same rules on save.
        function normalizeNamePart(part) {
            if (!part) return '';
            const upper = part.toUpperCase(), lower = part.toLowerCase();
            if (upper === lower) return part;
            const first = part.charAt(0);
            const startsLower = first === first.toLowerCase() && first !== first.toUpperCase();
            // Two capitals in a row inside a mixed-case word is a Shift/Caps slip (KUPal, JUan)
            // Two capitals in a row inside a mixed-case word is a Shift/Caps slip (KUPal, JUan)
            if (part !== upper && part !== lower && !startsLower && !/\p{Lu}{2}/u.test(part)) return part;
            let out = lower.charAt(0).toUpperCase() + lower.slice(1);
            out = out.replace(/^(\p{L})(['’])(\p{L})/u, (m, a, b, c) => a + b + c.toUpperCase());
            out = out.replace(/^(Mc)(\p{L})/u, (m, a, b) => a + b.toUpperCase());
            return out;
        }

        function normalizePersonName(name) {
            const clean = String(name || '').replace(/\s+/g, ' ').trim();
            if (!clean) return '';
            return clean.split(' ')
                .map(w => w.split('-').map(normalizeNamePart).join('-'))
                .join(' ');
        }

        function normalizeNameInput(el) {
            const fixed = normalizePersonName(el.value);
            if (fixed !== el.value) el.value = fixed;
        }

        // ── Address capitalisation (same rules as normalize_address_text()) ──
        // Only letters change; numbers, ZIP codes and punctuation stay as typed.
        const ADDRESS_KEEP_UPPER = ['NCR', 'BGC', 'CAR', 'ARMM', 'BARMM', 'CALABARZON', 'MIMAROPA', 'SOCCSKSARGEN',
            'EDSA', 'LRT', 'MRT', 'SM', 'UP', 'PUP', 'UST', 'FEU', 'DLSU', 'PO', 'PSC'
        ];
        const ADDRESS_LOWER_WORDS = ['of', 'and'];

        function normalizeAddressText(text) {
            const clean = String(text || '').replace(/\s+/g, ' ').trim();
            if (!clean) return '';
            let seenWord = false;
            return clean.replace(/[\p{L}\p{M}\p{N}'’]+/gu, function(w) {
                if (/\p{N}/u.test(w)) {
                    const o = w.match(/^(\d+)(st|nd|rd|th)$/i);
                    return o ? o[1] + o[2].toLowerCase() : w;
                }
                const first = !seenWord;
                seenWord = true;
                const upper = w.toUpperCase(), lower = w.toLowerCase();
                const uniform = (w === upper || w === lower);
                if (uniform && ADDRESS_KEEP_UPPER.includes(upper)) return upper;
                if (uniform && /^(I{1,3}|IV|VI{0,3}|IX|X{1,3})$/.test(upper)) return upper;
                if (uniform && !first && ADDRESS_LOWER_WORDS.includes(lower)) return lower;
                return normalizeNamePart(w);
            });
        }

        function normalizeAddressInput(el) {
            const fixed = normalizeAddressText(el.value);
            if (fixed !== el.value) el.value = fixed;
        }
    </script>

            <?php if (!$contactStatus['ok']): ?>
            <!-- Opened by Book Appointment while neither email nor phone is verified -->
            <div class="modal fade" id="contactGateModal" tabindex="-1" aria-labelledby="contactGateTitle" aria-modal="true" role="dialog">
            <div class="modal-dialog modal-dialog-centered">
            <section class="modal-content contact-gate-card" id="contactGate">
                <button type="button" class="btn-close cg-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="cg-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
                <h2 id="contactGateTitle">Verify at Least One Contact Method</h2>
                <p class="cg-text">To book an appointment, verify either your email address or your phone number. You only need to verify <strong>one</strong> of these methods to continue.</p>

                <ul class="cg-list">
                    <li>
                        <span class="cg-method"><i class="fa-solid fa-envelope" aria-hidden="true"></i> Email</span>
                        <span class="cg-status <?= $contactStatus['email'] ? 'is-ok' : '' ?>" data-cg-status="email"><?= $contactStatus['email'] ? 'Verified' : 'Not Verified' ?></span>
                        <button type="button" class="prof-btn-verify" data-cg-btn="email" onclick="sendVerificationEmail(this)">
                            <i class="fa-solid fa-envelope"></i> Send Verification Email
                        </button>
                    </li>
                    <li>
                        <span class="cg-method"><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i> Phone</span>
                        <span class="cg-status <?= $contactStatus['phone'] ? 'is-ok' : '' ?>" data-cg-status="phone"><?= $contactStatus['phone'] ? 'Verified' : 'Not Verified' ?></span>
                        <button type="button" class="prof-btn-verify" data-cg-btn="phone" onclick="startPhoneVerification()">
                            <i class="fa-solid fa-mobile-screen"></i> Verify Phone Number
                        </button>
                    </li>
                </ul>

                <p class="cg-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    <span>Your status updates automatically once verified. Not updating? <button type="button" class="cg-link" onclick="location.href='portal-dashboard.php?verify_contact=1'">Refresh status</button>.
                    Need to fix your phone number? Update it in <button type="button" class="cg-link" onclick="toggleContactGate(false); showPage('profile', null)">My Profile</button>.</span>
                </p>
            </section>
            </div>
            </div>
            <?php endif; ?>
    <!-- PHP → JS data bridge -->
    <script>
        const API = '<?= $API_PATH ?>';
        // false → no verified email or phone yet: Book Appointment opens the verification modal
        let CONTACT_OK = <?= json_encode((bool)$contactStatus['ok']) ?>;
    </script>
    <script>
        const PORTAL_IDLE_TIMEOUT_MS = <?= PORTAL_IDLE_TIMEOUT * 1000 ?>;
        const PORTAL_ABSOLUTE_TIMEOUT_MS = <?= PORTAL_ABSOLUTE_TIMEOUT * 1000 ?>;
        const PORTAL_SESSION_START_MS = <?= ($_SESSION['portal_login_time'] ?? time()) * 1000 ?>;
    </script>
    <script src="assets/js/portal-idle-timeout.js"></script>

    <script>
        // ── PH ADDRESS DATA (for Edit Profile → Contact Info dropdowns) ──
        let PH_DATA = (function() {
            try {
                return <?php
                        $attempts = [
                            __DIR__ . '/assets/json/cluster.json',
                            dirname(__DIR__) . '/assets/json/cluster.json',
                            $_SERVER['DOCUMENT_ROOT'] . '/assets/json/cluster.json',
                            realpath(dirname($_SERVER['SCRIPT_FILENAME'])) . '/assets/json/cluster.json',
                        ];
                        $found = null;
                        foreach ($attempts as $p) {
                            if (file_exists($p)) {
                                $found = $p;
                                break;
                            }
                        }
                        echo $found ? file_get_contents($found) : 'null';
                        ?>;
            } catch (e) {
                return null;
            }
        })();
    </script>
    <script src="assets/js/portal-dashboard.js?v=19"></script>

    <script>
        async function startPhoneVerification() {
            // Step 1 — send the code
            const rawPhone = (document.getElementById('pf_contact')?.value || '').replace(/\D/g, '');
            const maskedPhone = rawPhone.length >= 4 ?
                '+63 ' + rawPhone.slice(0, 1) + '•• ••• ' + rawPhone.slice(-4) :
                'your registered number';
            const sendResult = await Swal.fire({
                title: 'Verify Your Phone',
                iconHtml: '<span class="es-pv-icon"><i class="fa-solid fa-mobile-screen"></i><i class="fa-solid fa-shield-halved es-pv-shield"></i></span>',
                icon: 'info',
                html: '<p class="es-pv-text">We\'ll send a 6-digit verification code by SMS to your registered number.</p>' +
                    '<span class="es-pv-number"><i class="fa-solid fa-phone"></i> ' + maskedPhone + '</span>',
                confirmButtonText: '<i class="fa-solid fa-paper-plane"></i> Send Code',
                cancelButtonText: 'Cancel',
                buttonsStyling: false,
                customClass: {
                    popup: 'es-pv-popup',
                    icon: 'es-pv-icon-wrap',
                    title: 'es-pv-title',
                    htmlContainer: 'es-pv-html',
                    actions: 'es-pv-actions',
                    confirmButton: 'es-pv-btn es-pv-btn-primary',
                    cancelButton: 'es-pv-btn es-pv-btn-outline'
                },
                showCancelButton: true,
                showLoaderOnConfirm: true,
                preConfirm: async () => {
                    try {
                        const res = await fetch('controllers/verify_phone_controller.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: 'action=send_phone_otp',
                        }).then(r => r.json());

                        if (!res.success) {
                            Swal.showValidationMessage(res.message || 'Failed to send code.');
                            return false;
                        }
                        return true;
                    } catch (e) {
                        Swal.showValidationMessage('Network error. Please try again.');
                        return false;
                    }
                },
                allowOutsideClick: () => !Swal.isLoading(),
            });

            if (!sendResult.isConfirmed) return;

            // Step 2 — enter the code
            const otpBoxes = Array.from({ length: 6 }, (_, i) =>
                `<input type="text" class="es-otp-box" maxlength="1" inputmode="numeric" autocomplete="${i === 0 ? 'one-time-code' : 'off'}" aria-label="Digit ${i + 1}">`
            ).join('');
            const verifyResult = await Swal.fire({
                title: 'Enter Verification Code',
                iconHtml: '<span class="es-pv-icon"><i class="fa-solid fa-mobile-screen"></i><i class="fa-solid fa-shield-halved es-pv-shield"></i></span>',
                icon: 'info',
                html: '<p class="es-pv-text">Enter the 6-digit code sent to your phone number.</p>' +
                    '<span class="es-pv-number"><i class="fa-solid fa-phone"></i> ' + maskedPhone + '</span>' +
                    '<div class="es-otp" id="phoneOtpBoxes">' + otpBoxes + '</div>' +
                    '<p class="es-otp-resend">Didn\'t receive the code? <button type="button" id="phoneOtpResend" class="es-otp-resend-btn">Resend code</button></p>',
                confirmButtonText: '<i class="fa-solid fa-check"></i> Verify Code',
                cancelButtonText: 'Cancel',
                buttonsStyling: false,
                showCloseButton: true,
                customClass: {
                    popup: 'es-pv-popup',
                    icon: 'es-pv-icon-wrap',
                    title: 'es-pv-title',
                    htmlContainer: 'es-pv-html',
                    actions: 'es-pv-actions es-pv-actions-stack',
                    confirmButton: 'es-pv-btn es-pv-btn-primary',
                    cancelButton: 'es-pv-btn es-pv-btn-outline',
                    closeButton: 'es-pv-close'
                },
                showCancelButton: true,
                showLoaderOnConfirm: true,
                didOpen: (popup) => {
                    const boxes = [...popup.querySelectorAll('.es-otp-box')];
                    const fill = (digits, start) => {
                        digits.split('').forEach((d, k) => { if (boxes[start + k]) boxes[start + k].value = d; });
                        boxes[Math.min(start + digits.length, 5)].focus();
                    };
                    boxes.forEach((box, i) => {
                        box.addEventListener('input', () => {
                            const digits = box.value.replace(/\D/g, '');
                            box.value = '';
                            if (digits) fill(digits.slice(0, 6 - i), i);
                        });
                        box.addEventListener('keydown', (e) => {
                            if (e.key === 'Backspace' && !box.value && i > 0) {
                                boxes[i - 1].value = '';
                                boxes[i - 1].focus();
                                e.preventDefault();
                            } else if (e.key === 'ArrowLeft' && i > 0) boxes[i - 1].focus();
                            else if (e.key === 'ArrowRight' && i < 5) boxes[i + 1].focus();
                            else if (e.key === 'Enter') Swal.clickConfirm();
                        });
                        box.addEventListener('paste', (e) => {
                            const digits = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
                            if (!digits) return;
                            e.preventDefault();
                            fill(digits, digits.length === 6 ? 0 : i);
                        });
                    });
                    boxes[0].focus();

                    // Resend — reuses the existing send_phone_otp action, with a 30s cooldown
                    const resendBtn = popup.querySelector('#phoneOtpResend');
                    let timer = null;
                    const cooldown = (s) => {
                        resendBtn.disabled = true;
                        resendBtn.textContent = `Resend in ${s}s`;
                        timer = setInterval(() => {
                            if (--s <= 0) {
                                clearInterval(timer);
                                resendBtn.disabled = false;
                                resendBtn.textContent = 'Resend code';
                            } else resendBtn.textContent = `Resend in ${s}s`;
                        }, 1000);
                    };
                    cooldown(30);
                    resendBtn.addEventListener('click', async () => {
                        resendBtn.disabled = true;
                        resendBtn.textContent = 'Sending…';
                        Swal.resetValidationMessage();
                        try {
                            const res = await fetch('controllers/verify_phone_controller.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'action=send_phone_otp',
                            }).then(r => r.json());
                            if (!res.success) {
                                Swal.showValidationMessage(res.message || 'Failed to resend code.');
                                resendBtn.disabled = false;
                                resendBtn.textContent = 'Resend code';
                                return;
                            }
                            boxes.forEach(b => b.value = '');
                            boxes[0].focus();
                            cooldown(30);
                        } catch (e) {
                            Swal.showValidationMessage('Network error. Please try again.');
                            resendBtn.disabled = false;
                            resendBtn.textContent = 'Resend code';
                        }
                    });
                    popup._otpTimer = () => clearInterval(timer);
                },
                willClose: (popup) => popup._otpTimer && popup._otpTimer(),
                preConfirm: async () => {
                    const code = [...document.querySelectorAll('#phoneOtpBoxes .es-otp-box')].map(b => b.value).join('');
                    if (code.length !== 6) {
                        Swal.showValidationMessage('Please enter the full 6-digit code.');
                        return false;
                    }
                    try {
                        const res = await fetch('controllers/verify_phone_controller.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: 'action=verify_phone_otp&code=' + encodeURIComponent(code),
                        }).then(r => r.json());

                        if (!res.success) {
                            Swal.showValidationMessage(res.message || 'Verification failed.');
                            return false;
                        }
                        return res;
                    } catch (e) {
                        Swal.showValidationMessage('Network error. Please try again.');
                        return false;
                    }
                },
                allowOutsideClick: () => !Swal.isLoading(),
            });

            if (!verifyResult.isConfirmed) return;

            // Success — update every verification indicator without a page reload.
            // First verified contact method → booking is unlocked (the server re-checks it)
            const unlockedBooking = !CONTACT_OK;
            applyContactStatus(!!window._emailVerified, true);
            toggleContactGate(false);
            Swal.fire({
                icon: 'success',
                iconHtml: '<i class="fa-solid fa-circle-check es-swal-glyph"></i>',
                title: 'Phone Verified!',
                html: '<p class="es-swal-text">Your phone number has been verified.' +
                    (unlockedBooking ? ' You can now book an appointment.' : '') + '</p>',
                confirmButtonText: unlockedBooking ? '<i class="fa-solid fa-calendar-plus"></i> Book Appointment' : 'Got It',
                showCancelButton: unlockedBooking,
                cancelButtonText: 'Later',
                buttonsStyling: false,
                customClass: ES_SWAL_CLASS,
            }).then((r) => {
                if (unlockedBooking && r.isConfirmed) handleBookAppt();
            });
        }
    </script>

    <!-- ── My Profile: summary card, section navigation, view/edit modes ── -->
    <script>
        /* ── Mode switching ─────────────────────────────────────────── */
        let _profDirty = false;

        function _profSetMode(editing) {
            document.getElementById('profileViewPanel').style.display = editing ? 'none' : 'block';
            document.getElementById('profileEditPanel').style.display = editing ? 'block' : 'none';
            const btn = document.getElementById('profEditBtn');
            const note = document.getElementById('profEditingNote');
            if (btn) btn.hidden = editing;
            if (note) note.hidden = !editing;
            document.getElementById('page-profile').classList.toggle('is-editing', editing);
            _profSyncNav(true);
        }

        function enterEditMode() {
            // Every form section opens while editing
            document.querySelectorAll('#profileEditPanel .prof-section').forEach(function(sec) {
                toggleSection(sec.querySelector('.prof-section-header'), true);
            });
            _profSetMode(true);
            profMarkClean();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function exitEditMode() {
            _profSetMode(false);
            profMarkClean();
            loadProfile(); // discards unsaved edits and refreshes the view
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        async function cancelEditMode() {
            if (_profDirty) {
                const r = await Swal.fire({
                    icon: 'question',
                    title: 'Discard your changes?',
                    text: 'The changes you made have not been saved.',
                    showCancelButton: true,
                    confirmButtonText: 'Discard changes',
                    cancelButtonText: 'Keep editing',
                    confirmButtonColor: '#2563eb',
                    reverseButtons: true,
                });
                if (!r.isConfirmed) return;
            }
            exitEditMode();
        }

        /* ── Unsaved-changes indicator ──────────────────────────────── */
        function profMarkClean() {
            _profDirty = false;
            const n = document.getElementById('profDirtyNote');
            if (n) {
                n.textContent = 'No unsaved changes';
                n.classList.remove('is-dirty');
            }
        }

        function _profMarkDirty() {
            if (_profDirty) return;
            _profDirty = true;
            const n = document.getElementById('profDirtyNote');
            if (n) {
                n.textContent = 'You have unsaved changes';
                n.classList.add('is-dirty');
            }
        }
        (function() {
            const ep = document.getElementById('profileEditPanel');
            if (!ep) return;
            ep.addEventListener('input', _profMarkDirty);
            ep.addEventListener('change', _profMarkDirty);
            ep.addEventListener('click', function(e) {
                if (e.target.closest('.modal-cs-list li')) _profMarkDirty(); // address dropdowns
            });
            window.addEventListener('beforeunload', function(e) {
                if (_profDirty && isProfileEditing()) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        })();

        /* ── Section navigation + scroll sync ───────────────────────── */
        function _profSectionEl(key) {
            return document.getElementById((isProfileEditing() ? 'sec-' : 'view-sec-') + key);
        }

        let _profSpyLock = 0;

        function _profSetActive(key) {
            const nav = document.getElementById('profNav');
            if (!nav) return;
            nav.querySelectorAll('.prof-nav-link').forEach(function(l) {
                const on = l.dataset.sec === key;
                l.classList.toggle('active', on);
                if (on) {
                    l.setAttribute('aria-current', 'true');
                    // Horizontal nav on small screens: keep the active chip visible
                    if (nav.scrollWidth > nav.clientWidth + 2) {
                        const left = l.offsetLeft - (nav.clientWidth - l.offsetWidth) / 2;
                        nav.scrollTo({ left: Math.max(0, left), behavior: 'smooth' });
                    }
                } else {
                    l.removeAttribute('aria-current');
                }
            });
        }

        function profGoTo(key) {
            const sec = _profSectionEl(key);
            if (!sec) return false;
            const wasCollapsed = sec.classList.contains('collapsed');
            toggleSection(sec.querySelector('.prof-section-header'), true);
            _profSetActive(key);
            _profSpyLock = Date.now() + 1200; // don't let the scroll spy fight the click
            // Scroll once the section has finished opening, so the page is tall enough
            setTimeout(function() {
                sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, wasCollapsed ? 300 : 0);
            const h = sec.querySelector('.prof-section-header');
            if (h) setTimeout(function() { h.focus({ preventScroll: true }); }, 350);
            return false;
        }

        // Older code calls scrollToSection('view-sec-basic' | 'sec-basic', link)
        function scrollToSection(id) {
            if (window.event && window.event.preventDefault) window.event.preventDefault();
            return profGoTo(String(id).replace(/^(view-)?sec-/, ''));
        }

        function _profSyncNav(force) {
            const page = document.getElementById('page-profile');
            if (!page || !page.classList.contains('active')) return;
            if (!force && Date.now() < _profSpyLock) return;
            const panel = document.getElementById(isProfileEditing() ? 'profileEditPanel' : 'profileViewPanel');
            const secs = Array.from(panel.querySelectorAll('.prof-section'));
            if (!secs.length) return;
            const line = (window.innerWidth < 992 ? 140 : 96);
            let current = secs[0];
            secs.forEach(function(s) {
                if (s.getBoundingClientRect().top - line <= 0) current = s;
            });
            if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
                current = secs[secs.length - 1];
            }
            _profSetActive(current.dataset.sec);
        }
        (function() {
            let ticking = false;
            window.addEventListener('scroll', function() {
                if (ticking) return;
                ticking = true;
                requestAnimationFrame(function() {
                    ticking = false;
                    _profSyncNav(false);
                });
            }, { passive: true });
        })();

        function profSetAll(open) {
            document.querySelectorAll('#profileViewPanel .prof-section').forEach(function(sec) {
                toggleSection(sec.querySelector('.prof-section-header'), open);
            });
        }

        /* ── View panel helpers ─────────────────────────────────────── */
        function _vf(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            const v = (value === null || value === undefined) ? '' : String(value).trim();
            const empty = !v || v === '—';
            el.textContent = empty ? 'Not provided' : v;
            el.classList.toggle('is-empty', empty);
        }

        function _vfTags(id, items, colorClass) {
            const el = document.getElementById(id);
            if (!el) return;
            if (!items || items.length === 0) {
                el.innerHTML = '<span class="view-tag-empty">None recorded</span>';
                return;
            }
            el.innerHTML = items.map(function(i) {
                const t = String(i).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                return '<span class="view-tag ' + (colorClass || '') + '">' + t + '</span>';
            }).join('');
        }

        function _fmtDate(val) {
            if (!val) return '';
            try {
                return new Date(val + 'T00:00:00').toLocaleDateString('en-PH', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
            } catch (e) {
                return val;
            }
        }

        function _calcAge(val) {
            if (!val) return '';
            const b = new Date(val + 'T00:00:00'), now = new Date();
            if (isNaN(b)) return '';
            let age = now.getFullYear() - b.getFullYear();
            const m = now.getMonth() - b.getMonth();
            if (m < 0 || (m === 0 && now.getDate() < b.getDate())) age--;
            return age >= 0 ? age + (age === 1 ? ' year old' : ' years old') : '';
        }

        /* ── Summary card (name, contact, completion) ───────────────── */
        function _profVal(id) {
            return ((document.getElementById(id) || {}).value || '').trim();
        }

        function renderProfileSummary() {
            const first = _profVal('pf_first'), last = _profVal('pf_last');
            const middle = _profVal('pf_middle'), suffix = _profVal('pf_suffix');
            const name = [first, middle ? middle.charAt(0).toUpperCase() + '.' : '', last, suffix].filter(Boolean).join(' ');
            const phone = _profVal('pf_contact');

            document.getElementById('profAvatar').textContent = ((first[0] || '') + (last[0] || '')).toUpperCase() || '—';
            document.getElementById('profName').textContent = name || 'Your name';
            const emailEl = document.getElementById('profEmail');
            emailEl.textContent = _profVal('pf_email') || 'No email on file';
            emailEl.title = _profVal('pf_email'); // full address on hover when it is shortened
            document.getElementById('profPhone').textContent = phone ? '+63 ' + phone : 'No mobile number yet';

            // Same three requirements as computeProfileComplete() on the server
            const complete = !!window._profileComplete;
            const hasQ = (window._hasQuestionnaire === true) || complete ? true :
                (window._hasQuestionnaire === false ? false : null);
            const steps = [{
                    key: 'basic',
                    label: 'Basic info',
                    hint: 'Name, birthdate, gender',
                    done: !!(first && last && _profVal('pf_birthdate') && _profVal('pf_gender')) || complete
                },
                {
                    key: 'contact',
                    label: 'Home address',
                    hint: 'Address and city',
                    done: !!(_profVal('pf_address') && _profVal('pf_city')) || complete
                },
                {
                    key: 'questionnaire',
                    label: 'Health questionnaire',
                    hint: 'Not answered yet',
                    done: hasQ === true
                },
            ];
            const doneCount = steps.filter(function(s) { return s.done; }).length;

            const status = document.getElementById('profStatus');
            status.textContent = complete ? 'Complete' : 'Incomplete';
            status.className = 'prof-status ' + (complete ? 'is-complete' : 'is-incomplete');

            const pct = complete ? 100 : Math.round(doneCount / 3 * 100);
            document.getElementById('profProgressBar').style.width = pct + '%';
            const prog = document.getElementById('profProgress');
            prog.setAttribute('aria-valuenow', complete ? 3 : doneCount);
            prog.classList.toggle('is-complete', complete);

            const list = document.getElementById('profSteps');
            if (complete) {
                list.innerHTML = '<li class="prof-step-done-all"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>' +
                    '<span>All set — you can book appointments online.</span></li>';
                return;
            }
            list.innerHTML = steps.map(function(s) {
                let action = '';
                if (!s.done) {
                    action = s.key === 'questionnaire' ?
                        '<button type="button" class="prof-step-action" onclick="checkAndShowQuestionnaire()">Answer</button>' :
                        '<button type="button" class="prof-step-action" onclick="enterEditMode();setTimeout(function(){profGoTo(\'' + s.key + '\')},60)">Add</button>';
                }
                return '<li class="prof-step ' + (s.done ? 'is-done' : '') + '">' +
                    '<i class="fa-' + (s.done ? 'solid fa-circle-check' : 'regular fa-circle') + '" aria-hidden="true"></i>' +
                    '<span class="prof-step-text"><span class="prof-step-label">' + s.label + '</span>' +
                    '<span class="prof-step-hint">' + (s.done ? 'Done' : s.hint) + '</span></span>' + action + '</li>';
            }).join('');
        }
        window.renderProfileSummary = renderProfileSummary;

        /* ── Section header summaries ("3 of 4 filled") ─────────────── */
        function _profSectionMeta() {
            document.querySelectorAll('#profileViewPanel .prof-section').forEach(function(sec) {
                const meta = sec.querySelector('[data-meta]');
                if (!meta) return;
                const key = sec.dataset.sec;
                if (key === 'allergies' || key === 'conditions') {
                    const n = sec.querySelectorAll('.view-tag').length;
                    meta.textContent = n ? n + ' recorded' : 'None recorded';
                    meta.className = 'prof-sec-meta' + (n ? ' is-flag' : '');
                    return;
                }
                // Age is calculated, not a profile field
                const vals = Array.from(sec.querySelectorAll('.view-field-value[id^="vf_"]')).filter(function(v) {
                    return v.id !== 'vf_age_display';
                });
                const filled = vals.filter(function(v) { return !v.classList.contains('is-empty'); }).length;
                if (!vals.length) { meta.textContent = ''; return; }
                meta.textContent = filled === vals.length ? 'Complete' : filled + ' of ' + vals.length + ' filled';
                meta.className = 'prof-sec-meta' + (filled === vals.length ? ' is-complete' : '');
            });
        }

        function _populateViewPanel() {
            const g = _profVal;
            const first = g('pf_first'), last = g('pf_last'), middle = g('pf_middle'), suffix = g('pf_suffix');
            const bdate = g('pf_birthdate');
            const fullName = [first, middle ? middle.charAt(0).toUpperCase() + '.' : '', last, suffix].filter(Boolean).join(' ');

            _vf('vf_full_name', fullName);
            _vf('vf_birthdate', _fmtDate(bdate));
            _vf('vf_age_display', _calcAge(bdate));
            _vf('vf_gender', g('pf_gender'));
            _vf('vf_civil_status_display', g('pf_civil_status'));
            _vf('vf_nationality_display', g('pf_nationality'));

            // Guardian details: shown for patients under 18, or whenever some were saved
            const gPhone = g('pf_guardian_phone');
            const gVals = [g('pf_guardian_name'), g('pf_guardian_relation'), gPhone, g('pf_guardian_address')];
            const ageNum = parseInt(_calcAge(bdate), 10);
            const gRow = document.getElementById('vg_guardian_row');
            if (gRow) gRow.style.display = (ageNum >= 0 && ageNum < 18) || gVals.some(Boolean) ? '' : 'none';
            _vf('vg_name', gVals[0]);
            _vf('vg_relation', gVals[1]);
            _vf('vg_phone', gPhone ? '+63 ' + gPhone : '');
            _vf('vg_address', gVals[3]);

            _vf('vf_email', g('pf_email'));
            const rawContact = g('pf_contact');
            _vf('vf_contact', rawContact ? '+63 ' + rawContact : '');
            _vf('vf_home_phone', g('pf_home_phone'));
            _vf('vf_address', g('pf_address'));
            _vf('vf_province', g('pf_province'));
            _vf('vf_city', g('pf_city'));
            _vf('vf_barangay', g('pf_barangay'));
            _vf('vf_zip', g('pf_zip'));

            const isGoogleUser = window._authMethod === 'google';
            const googleRow = document.getElementById('vf_google_auth_row');
            if (googleRow) googleRow.style.display = isGoogleUser ? '' : 'none';

            const isPhoneVerified = !!window._phoneVerified;
            const hasPhone = !!rawContact;
            const phoneVerifiedBadge = document.getElementById('phoneVerifiedBadge');
            const phoneUnverifiedBadge = document.getElementById('phoneUnverifiedBadge');
            const phoneVerifyBtnWrap = document.getElementById('phoneVerifyBtnWrap');
            if (phoneVerifiedBadge) phoneVerifiedBadge.style.display = hasPhone && isPhoneVerified ? 'inline' : 'none';
            if (phoneUnverifiedBadge) phoneUnverifiedBadge.style.display = hasPhone && !isPhoneVerified ? 'inline' : 'none';
            if (phoneVerifyBtnWrap) phoneVerifyBtnWrap.style.display = hasPhone && !isPhoneVerified ? 'block' : 'none';

            _vf('vf_prev_dentist', g('pf_prev_dentist'));
            _vf('vf_last_visit', _fmtDate(g('pf_last_visit')));
            _vf('vf_dental_notes', g('pf_dental_notes'));
            _vf('vf_chief_complaint', g('pf_chief_complaint'));
            _vf('vf_referral_source', g('pf_referral_source'));
            _vf('vf_referred_by', g('pf_referred_by'));

            _vfTags('vf_allergies', Array.from(document.querySelectorAll('input[name="allergies"]:checked')).map(function(c) { return c.value; }), 'view-tag-red');
            _vf('vf_other_allergies', g('pf_other_allergies'));
            _vf('vf_allergy_reaction', g('pf_allergy_reaction'));
            _vfTags('vf_conditions', Array.from(document.querySelectorAll('input[name="conditions"]:checked')).map(function(c) { return c.value; }), 'view-tag-blue');
            _vf('vf_other_conditions', g('pf_other_conditions'));

            renderProfileSummary();
            _profSectionMeta();
        }
        window._populateViewPanel = _populateViewPanel;

        // Save → return to the view only when the save actually succeeded
        (function() {
            const _origSave = window.saveProfile;
            window.saveProfile = async function() {
                const ok = _origSave ? await _origSave.apply(this, arguments) : false;
                if (ok) {
                    profMarkClean();
                    setTimeout(exitEditMode, 300);
                }
                return ok;
            };
        })();
    </script>

    <!-- ── Dental Chart (odontogram) — read-only, Universal numbering 1–32 ── -->
    <script>
        (function() {
            'use strict';

            /* Tooth drawings, Universal 1–32 numbering, condition labels / colours, legend and
               surface diagram come from assets/js/odontogram.js — shared with the admin
               Start Treatment modal so both charts always match. */
            const O = window.Odontogram;
            const SURF_NAME = O.SURF_NAME, UPPER_ORDER = O.UPPER_ORDER, LOWER_ORDER = O.LOWER_ORDER;
            const esc = O.esc, condLabel = O.condLabel, badge = O.badge;
            const toothInfo = O.toothInfo, surfaceConds = O.surfaceConds, surfaceDiagram = O.surfaceDiagram, condKey = O.condKey;
            const SOURCE_LABEL = { consultation: 'Consultation', treatment_record: 'Treatment record', correction: 'Correction' };
            const legendEl = document.getElementById('odoLegend');
            if (legendEl) legendEl.innerHTML = O.legendHTML();

            /* ── State ── */
            let CHART = {};        // "14" → { condition, notes, updated_at, surfaces }
            let HISTORY = [];
            let TREATMENTS = [];
            let selected = null;   // tooth number (string)

            function fmtDay(d) {
                if (!d) return '—';
                const dt = new Date(String(d).length === 10 ? d + 'T00:00:00' : d);
                return isNaN(dt) ? '—' : dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
            }

            /* Chart notes saved when a treatment is finished carry the internal treatment
               code (e.g. "Composite Filling — Treatment TR-00009"). Patients see the note
               without it; the stored note and its links are left untouched. */
            const TRT_REF = /\s*(?:[—–\-·,:(]\s*)?(?:(?:updated|recorded|added|charted|changed)\s+(?:from|during|via|in|for|by)\s+)?(?:(?:(?:treatment|trt)\s*(?:id|code|no\.?|number|#)?\s*[:#]?\s*)?\bTRT?-\d+\b|(?:treatment|trt)\s*(?:id|no\.?|number|#)\s*[:#]?\s*\d+\b)\s*\)?/gi;
            function patientNote(text) {
                const raw = String(text || '').trim();
                if (!raw) return '';
                return raw.replace(TRT_REF, ' ')
                    .replace(/\(\s*\)/g, '')
                    .replace(/^[\s—–\-·,:;]+|[\s—–\-·,:;]+$/g, '')
                    .replace(/\s{2,}/g, ' ')
                    .trim();
            }
            // A note that only named the treatment still says something: it came from a visit
            function noteOrContext(text, fallback) {
                const cleaned = patientNote(text);
                return cleaned || (String(text || '').trim() ? fallback : '');
            }

            function renderChart() {
                O.renderArches(document.getElementById('odoUpper'), document.getElementById('odoLower'), CHART,
                    function(key) { return key === selected; });
            }

            /* ── Summary cards (only from recorded data) ── */
            function renderSummary() {
                let charted = 0, decay = 0, restored = 0, missing = 0;
                for (let n = 1; n <= 32; n++) {
                    const e = CHART[String(n)];
                    if (!e) continue;
                    charted++;
                    const t = condKey(e.condition), s = surfaceConds(e);
                    if (t === 'decay' || s.indexOf('decay') > -1) decay++;
                    if (t === 'filled' || t === 'crown' || s.indexOf('filled') > -1 || s.indexOf('crown') > -1) restored++;
                    if (t === 'missing') missing++;
                }
                document.getElementById('odo_stat_charted').textContent = charted;
                document.getElementById('odo_stat_decay').textContent = decay;
                document.getElementById('odo_stat_restored').textContent = restored;
                document.getElementById('odo_stat_missing').textContent = missing;
            }

            function treatmentsFor(n) {
                return TREATMENTS.filter(function(t) {
                    return String(t.tooth_number || '').split(',')
                        .map(function(x) { return x.trim(); })
                        .indexOf(String(n)) > -1;
                });
            }

            function renderPanel() {
                const empty = document.getElementById('odoPanelEmpty');
                const body = document.getElementById('odoPanelBody');
                if (!selected) { empty.style.display = ''; body.style.display = 'none'; return; }

                const n = parseInt(selected, 10);
                const info = toothInfo(n);
                const entry = CHART[selected] || null;
                let html = '<div class="odo-p-head">' +
                    '<div class="odo-p-num">' + n + '</div>' +
                    '<div class="odo-p-titles"><div class="odo-p-title">Tooth ' + n + '</div>' +
                    '<div class="odo-p-name">' + esc(info.name) + '</div></div>' +
                    '<div class="odo-p-badge">' + badge(entry && entry.condition) + '</div></div>';

                if (!entry) {
                    html += '<p class="odo-p-muted">No condition has been recorded for this tooth yet.</p>';
                } else {
                    // Surfaces
                    const recorded = Object.keys(entry.surfaces || {}).filter(function(k) {
                        return entry.surfaces[k] && entry.surfaces[k].condition;
                    });
                    html += '<div class="odo-p-section"><div class="odo-p-label">Surfaces</div>' +
                        '<div class="odo-p-surfaces">' + surfaceDiagram(entry) + '<div class="odo-p-surface-list">';
                    if (recorded.length) {
                        ['B', 'M', 'O', 'L', 'D'].filter(function(k) { return recorded.indexOf(k) > -1; }).forEach(function(k) {
                            const s = entry.surfaces[k];
                            const sNote = noteOrContext(s.notes, 'Surface updated after a treatment visit.');
                            html += '<div class="odo-p-surface"><span class="odo-p-skey">' + k + '</span>' +
                                '<div><div>' + esc(SURF_NAME[k]) + ' · ' + badge(s.condition) + '</div>' +
                                (sNote ? '<div class="odo-p-note">' + esc(sNote) + '</div>' : '') + '</div></div>';
                        });
                    } else {
                        html += '<p class="odo-p-muted">No surface-level findings. The whole tooth is recorded as ' +
                            esc(condLabel(entry.condition)) + '.</p>';
                    }
                    html += '</div></div></div>';

                    // Notes + last updated
                    const tNote = noteOrContext(entry.notes, 'Tooth condition updated after a treatment visit.');
                    html += '<div class="odo-p-section"><div class="odo-p-label">Dentist\'s Notes</div>' +
                        (tNote ?
                            '<p class="odo-p-text">' + esc(tNote) + '</p>' :
                            '<p class="odo-p-muted">No notes for this tooth.</p>') +
                        '<div class="odo-p-meta"><i class="fa-regular fa-clock me-1"></i>Last updated ' + esc(fmtDay(entry.updated_at)) + '</div></div>';
                }

                // Condition history
                const hist = HISTORY.filter(function(h) { return String(h.tooth_number).trim() === selected; });
                html += '<div class="odo-p-section"><div class="odo-p-label">Condition History</div>';
                if (hist.length) {
                    html += '<ol class="odo-p-history">' + hist.map(function(h) {
                        const hNote = patientNote(h.notes);   // the source label already says where it came from
                        const what =h.surface ? 'Surface ' + esc(h.surface) + ' (' + esc(SURF_NAME[String(h.surface).toUpperCase()] || h.surface) + ')' : 'Whole tooth';
                        return '<li><div class="odo-p-hdate">' + esc(fmtDay(h.changed_at)) +
                            (h.source ? ' · ' + esc(SOURCE_LABEL[h.source] || h.source) : '') + '</div>' +
                            '<div>' + what + ': ' + (h.old_condition ? badge(h.old_condition) + ' <span class="odo-p-arrow">→</span> ' : '') +
                            badge(h.new_condition) + '</div>' +
                            (hNote ? '<div class="odo-p-note">' + esc(hNote) + '</div>' : '') + '</li>';
                    }).join('') + '</ol>';
                } else {
                    html += '<p class="odo-p-muted">No changes recorded.</p>';
                }
                html += '</div>';

                // Treatments on this tooth
                const trts = treatmentsFor(n);
                html += '<div class="odo-p-section"><div class="odo-p-label">Treatments on this Tooth</div>';
                if (trts.length) {
                    html += '<div class="odo-p-trts">' + trts.map(function(t) {
                        const st = String(t.status || '');
                        const stLabel = st.split('_').map(function(w) { return w.charAt(0).toUpperCase() + w.slice(1); }).join(' ');
                        return '<button type="button" class="odo-p-trt" data-trt-code="' + esc(t.treatment_code) + '">' +
                            '<div class="odo-p-trt-main"><div class="odo-p-trt-name">' + esc(t.service_name && t.service_name !== '—' ? t.service_name : 'Treatment') + '</div>' +
                            '<div class="odo-p-trt-sub">' + esc(fmtDay(t.treatment_date)) + ' · ' + esc(t.treatment_code || '') + '</div></div>' +
                            (st ? '<span class="badge-status badge-' + esc(st) + '">' + esc(stLabel) + '</span>' : '') +
                            '<i class="fa-solid fa-chevron-right odo-p-trt-go" aria-hidden="true"></i></button>';
                    }).join('') + '</div>';
                } else {
                    html += '<p class="odo-p-muted">No treatments recorded for this tooth.</p>';
                }
                html += '</div>';

                body.innerHTML = html;
                empty.style.display = 'none';
                body.style.display = '';
            }

            function selectTooth(key, focusAfter) {
                selected = key;
                document.querySelectorAll('.odo-tooth').forEach(function(b) {
                    const on = b.dataset.tooth === key;
                    b.classList.toggle('is-selected', on);
                    b.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
                renderPanel();
                // On narrow layouts the panel sits below the chart — bring it into view
                if (window.innerWidth < 1200) {
                    const panel = document.getElementById('odoPanel');
                    const r = panel.getBoundingClientRect();
                    if (r.top > window.innerHeight - 80) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                if (focusAfter) {
                    const btn = document.querySelector('.odo-tooth[data-tooth="' + key + '"]');
                    if (btn) btn.focus();
                }
            }

            /* ── Events (delegated once) ── */
            document.addEventListener('click', function(e) {
                const tooth = e.target.closest('.odo-tooth');
                if (tooth) { selectTooth(tooth.dataset.tooth, false); return; }
                const trt = e.target.closest('.odo-p-trt');
                if (trt) {
                    const t = TREATMENTS.find(function(x) { return String(x.treatment_code) === trt.dataset.trtCode; });
                    if (t && typeof window.openTreatmentDetails === 'function') window.openTreatmentDetails(t);
                }
            });

            // Arrow keys move between teeth (← → along the row, ↑ ↓ between arches)
            document.addEventListener('keydown', function(e) {
                const btn = e.target.closest && e.target.closest('.odo-tooth');
                if (!btn || ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].indexOf(e.key) < 0) return;
                e.preventDefault();
                const jaw = btn.dataset.jaw, i = parseInt(btn.dataset.index, 10);
                let order = jaw === 'upper' ? UPPER_ORDER : LOWER_ORDER, j = i;
                if (e.key === 'ArrowLeft') j = Math.max(0, i - 1);
                if (e.key === 'ArrowRight') j = Math.min(15, i + 1);
                if (e.key === 'ArrowUp') order = UPPER_ORDER;
                if (e.key === 'ArrowDown') order = LOWER_ORDER;
                selectTooth(String(order[j]), true);
            });

            /* ── Load (called by showPage('dentalchart')) ── */
            window.loadDentalChart = async function() {
                const loading = document.getElementById('odoLoading');
                const error = document.getElementById('odoError');
                const chart = document.getElementById('odoChart');
                loading.style.display = '';
                error.style.display = 'none';

                try {
                    const results = await Promise.all([
                        php(API, { action: 'get_dental_chart' }),
                        php(API, { action: 'get_treatments' }).catch(function() { return {}; })
                    ]);
                    const data = results[0] || {};
                    if (!data.success) throw new Error(data.message || 'Could not load your dental chart.');

                    CHART = {};
                    const outside = [];
                    (data.chart || []).forEach(function(row) {
                        const key = String(row.tooth_number || '').trim();
                        const n = parseInt(key, 10);
                        if (String(n) === key && n >= 1 && n <= 32) CHART[key] = row;
                        else if (key) outside.push(key);
                    });
                    HISTORY = data.history || [];
                    TREATMENTS = (results[1] && results[1].treatments) || [];

                    renderChart();
                    renderSummary();
                    renderPanel();

                    const extra = document.getElementById('odoExtra');
                    if (outside.length) {
                        extra.textContent = outside.length + ' record(s) use a tooth number outside 1–32 (' +
                            outside.join(', ') + ') and are not shown on this chart.';
                        extra.style.display = '';
                    } else {
                        extra.style.display = 'none';
                    }

                    loading.style.display = 'none';
                    chart.style.display = '';
                } catch (err) {
                    loading.style.display = 'none';
                    chart.style.display = 'none';
                    error.innerHTML = '<i class="fa-solid fa-circle-xmark"></i><span>' + esc(err.message || 'Could not load your dental chart.') + '</span>';
                    error.style.display = '';
                }
            };
        })();
    </script>

    <script>
        (function() {
            'use strict';

            /* ── Helpers ──────────────────────────────────────────── */
            function fmtDate(d) {
                if (!d) return '—';
                return new Date(d + 'T00:00:00').toLocaleDateString('en-PH', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });
            }

            function fmtTime(t) {
                if (!t) return '';
                return new Date('1970-01-01T' + t).toLocaleTimeString('en-PH', {
                    hour: 'numeric',
                    minute: '2-digit'
                });
            }

            function statusBadge(s) {
                return '<span class="badge-status badge-' + s + '">' +
                    (s === 'checked_in' ? 'Checked In' : s.charAt(0).toUpperCase() + s.slice(1)) +
                    '</span>';
            }

            function paymentBadge(p) {
                return '<span class="badge-status badge-' + (p || 'unpaid') + '">' +
                    (p ? p.charAt(0).toUpperCase() + p.slice(1) : 'Unpaid') +
                    '</span>';
            }

            function escAttr(v) {
                return String(v == null ? '' : v)
                    .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
                    .replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            /* Existing cancellation rule: only pending / confirmed */
            function canCancelAppt(a) {
                return ['pending', 'confirmed'].includes(a.status);
            }

            /* ── Render service chips ─────────────────────────────── */
            function serviceChips(names) {
                if (!names || names.length === 0) {
                    return '<span style="color:var(--text-muted);font-size:.82rem;">General Consultation</span>';
                }
                return names.map(function(name, i) {
                    return '<span style="display:inline-flex;align-items:center;gap:3px;background:var(--primary-light);' +
                        'color:var(--primary-dark);border:1px solid #bfdbfe;border-radius:20px;' +
                        'padding:2px 9px 2px 6px;font-size:.74rem;font-weight:600;margin:1px 2px 1px 0;">' +
                        '<span style="opacity:.55;font-size:.65rem;">' + (i + 1) + '.</span> ' +
                        name + '</span>';
                }).join('');
            }

            /* ── Table cell: first service + clickable "+N more" badge ──
               The badge opens the existing View Details modal, which
               lists every service. */
            function serviceSummary(names, appointmentId) {
                const list = (names || []).filter(Boolean);
                if (!list.length) {
                    return '<span style="color:var(--text-muted);font-size:.82rem;">General Consultation</span>';
                }
                const extra = list.length - 1;
                return '<div class="appt-svc">' +
                    '<span class="appt-svc-name" title="' + escAttr(list[0]) + '">' + escAttr(list[0]) + '</span>' +
                    (extra > 0 ?
                        '<button type="button" class="appt-svc-more" data-appt-id="' + escAttr(appointmentId) + '" ' +
                        'title="View all ' + list.length + ' services" ' +
                        'aria-label="View all ' + list.length + ' services: ' + escAttr(list.join(', ')) + '">' +
                        '+' + extra + ' more</button>' :
                        '') +
                    '</div>';
            }

            /* ── Dashboard upcoming list (top 3) ─────────────────── */
            function renderDashAppts(appointments) {
                const el = document.getElementById('dashApptList');
                if (!el) return;
                const upcoming = appointments.filter(function(a) {
                    return ['pending', 'confirmed', 'checked_in'].includes(a.status);
                }).slice(0, 3);

                if (!upcoming.length) {
                    el.innerHTML = '<div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i><p>No upcoming appointments.</p>' +
                        '<button type="button" class="empty-action" onclick="handleBookAppt()"><i class="fa-solid fa-plus"></i>Book a visit</button></div>';
                    return;
                }

                el.innerHTML = upcoming.map(function(a) {
                    return '<div style="display:flex;align-items:flex-start;gap:.85rem;padding:.85rem 1.25rem;border-bottom:1px solid var(--border);">' +
                        '<div style="width:42px;height:42px;border-radius:10px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:800;flex-shrink:0;text-align:center;line-height:1.2;">' +
                        new Date((a.appointment_date || '') + 'T00:00:00').toLocaleDateString('en-PH', {
                            month: 'short',
                            day: 'numeric'
                        }).replace(' ', '<br>') +
                        '</div>' +
                        '<div style="flex:1;min-width:0;">' +
                        '<div style="font-weight:700;font-size:.88rem;color:var(--text-dark);">' + (a.appointment_code || '—') + '</div>' +
                        '<div style="font-size:.78rem;color:var(--text-muted);margin:.15rem 0;">' + fmtTime(a.appointment_time) + (a.branches ? ' · ' + a.branches.branch_name : '') + '</div>' +
                        '<div style="margin-top:.3rem;">' + serviceChips(a.service_names) + '</div>' +
                        '</div>' +
                        '<div>' + statusBadge(a.status) + '</div>' +
                        '</div>';
                }).join('');
            }

            /* ── Full appointments table ──────────────────────────── */
            let _allAppts = [];
            let _portalPatient = null;   // logged-in patient's details (for the details modal)
            let _curFilter = 'all';

            function renderApptTable(appointments) {
                const tbody = document.getElementById('apptTableBody');
                if (!tbody) return;

                const filtered = _curFilter === 'all' ?
                    appointments :
                    appointments.filter(function(a) {
                        return a.status === _curFilter;
                    });

                if (!filtered.length) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4" style="color:var(--text-muted);">No appointments found.</td></tr>';
                    return;
                }

                tbody.innerHTML = filtered.map(function(a) {
                    const canCancel = canCancelAppt(a);
                    const code = a.appointment_code || '—';
                    return '<tr>' +
                        '<td data-label="Date & Time"><div style="font-weight:600;font-size:.85rem;">' + fmtDate(a.appointment_date) + '</div>' +
                        '<div style="font-size:.78rem;color:var(--text-muted);">' + fmtTime(a.appointment_time) + '</div></td>' +
                        '<td data-label="Branch" style="font-size:.85rem;">' + (a.branches ? a.branches.branch_name : '—') + '</td>' +
                        '<td data-label="Service" class="appt-svc-cell">' + serviceSummary(a.service_names, a.appointment_id) + '</td>' +
                        '<td data-label="Status">' + statusBadge(a.status) + '</td>' +
                        '<td class="pt-actions">' +
                        '<div class="appt-action" data-appt-id="' + escAttr(a.appointment_id) + '">' +
                        '<button type="button" class="appt-action-btn" aria-haspopup="menu" aria-expanded="false" ' +
                        'aria-label="Actions for ' + escAttr(code) + '"><i class="fa-solid fa-ellipsis-vertical"></i></button>' +
                        '<div class="appt-action-menu" role="menu">' +
                        '<button type="button" class="appt-action-item" role="menuitem" data-action="view">' +
                        '<i class="fa-solid fa-eye"></i>View Details</button>' +
                        '<div class="appt-action-divider"></div>' +
                        // Always shown; greyed out + disabled when not eligible
                        (canCancel ?
                            '<button type="button" class="appt-action-item appt-action-item-danger" role="menuitem" data-action="cancel">' :
                            '<button type="button" class="appt-action-item appt-action-item-danger" role="menuitem" data-action="cancel" ' +
                            'disabled aria-disabled="true" title="Only pending or confirmed appointments can be cancelled">') +
                        '<i class="fa-solid fa-calendar-xmark"></i>Cancel Appointment</button>' +
                        '</div></div>' +
                        '</td>' +
                        '</tr>';
                }).join('');
            }

            /* ── Load from our new endpoint ───────────────────────── */
            async function loadPortalAppointments() {
                try {
                    const res = await fetch('api/portal_get_appointments.php');
                    const data = await res.json();
                    if (!data.success) throw new Error(data.message);
                    _allAppts = data.appointments || [];
                    _portalPatient = data.patient || null;
                    renderApptTable(_allAppts);
                    renderDashAppts(_allAppts);

                    // Update stat counter
                    const upcoming = _allAppts.filter(function(a) {
                        return ['pending', 'confirmed', 'checked_in'].includes(a.status);
                    }).length;
                    const upEl = document.getElementById('statUpcoming');
                    if (upEl) upEl.textContent = upcoming;

                    const compEl = document.getElementById('statCompleted');
                    if (compEl) compEl.textContent = _allAppts.filter(function(a) {
                        return a.status === 'completed';
                    }).length;

                } catch (e) {
                    console.warn('portal_get_appointments error:', e);
                }
            }

            /* ── Override filterAppts so our render is called ────── */
            window.filterAppts = function(status, btn) {
                _curFilter = status;
                // Update active button
                document.querySelectorAll('.appt-filter-btn').forEach(function(b) {
                    b.classList.toggle('active', b.dataset.status === status);
                });
                renderApptTable(_allAppts);
            };

            /* ── Cancel helper (delegates to existing modal) ──────── */
            // openCancelModal() (portal-dashboard.js) sets currentCancelId,
            // which is the ID confirmCancel() actually sends to the API.
            window.portalCancelAppt = function(id, code) {
                openCancelModal(id, code);
            };

            /* ── Action dropdown (custom, one open at a time) ─────── */
            function closeApptMenus() {
                document.querySelectorAll('.appt-action.open').forEach(function(w) {
                    w.classList.remove('open', 'drop-up');
                    const b = w.querySelector('.appt-action-btn');
                    if (b) b.setAttribute('aria-expanded', 'false');
                });
            }

            function openApptMenu(wrap) {
                closeApptMenus();
                wrap.classList.add('open');
                wrap.querySelector('.appt-action-btn').setAttribute('aria-expanded', 'true');
                // Treatment rows: start loading the Medical Record now, so it is
                // usually ready (no spinner) by the time it is clicked.
                if (wrap.classList.contains('trt-action') && typeof window.prefetchMedicalRecord === 'function') {
                    const trt = (window._portalTreatments || []).find(function(x) {
                        return String(x.treatment_code) === wrap.dataset.trtCode;
                    });
                    if (trt && trt.appointment_id) window.prefetchMedicalRecord(trt.appointment_id);
                }
                // Flip upward when there is not enough room below (e.g. last rows)
                const menu = wrap.querySelector('.appt-action-menu');
                const btnRect = wrap.getBoundingClientRect();
                if (btnRect.bottom + menu.offsetHeight + 12 > window.innerHeight &&
                    btnRect.top - menu.offsetHeight - 12 > 0) {
                    wrap.classList.add('drop-up');
                }
            }

            document.addEventListener('click', function(e) {
                // "+N more" service badge → existing View Details modal
                const more = e.target.closest('.appt-svc-more');
                if (more) {
                    closeApptMenus();
                    const appt = _allAppts.find(function(x) {
                        return String(x.appointment_id) === more.dataset.apptId;
                    });
                    if (appt) openApptDetails(appt);
                    return;
                }

                const toggle = e.target.closest('.appt-action-btn');
                if (toggle) {
                    const wrap = toggle.closest('.appt-action');
                    if (wrap.classList.contains('open')) closeApptMenus();
                    else openApptMenu(wrap);
                    return;
                }

                const item = e.target.closest('.appt-action-item');
                if (item && item.disabled) return; // greyed-out option: ignore, keep menu open

                // My Files rows (rendered by portal-dashboard.js → loadFiles)
                if (item && item.closest('.file-action')) {
                    const wrap = item.closest('.file-action');
                    closeApptMenus();
                    const f = (window._portalFiles || [])[parseInt(wrap.dataset.fileIndex, 10)];
                    if (!f) return;
                    if (item.dataset.action === 'file-view') openPortalFile(f, false);
                    else if (item.dataset.action === 'file-download') openPortalFile(f, true);
                    return;
                }

                // Waivers rows (rendered by portal-dashboard.js → loadWaivers)
                if (item && item.closest('.waiver-action')) {
                    const wrap = item.closest('.waiver-action');
                    closeApptMenus();
                    const w = (window._portalWaivers || []).find(function(x) {
                        return String(x.waiver_id) === wrap.dataset.waiverId;
                    });
                    if (!w) return;
                    if (item.dataset.action === 'waiver-view') openPortalFile({ file_path: w.file_path }, false);
                    else if (item.dataset.action === 'waiver-sign') signWaiver(w.waiver_id, w.waiver_type);
                    return;
                }

                // Billing & Payments rows (rendered by portal-dashboard.js → renderBilling)
                if (item && item.closest('.bill-action')) {
                    const wrap = item.closest('.bill-action');
                    closeApptMenus();
                    if (item.dataset.action === 'bill-view') {
                        const inv = (window._portalInvoices || []).find(function(x) {
                            return String(x.invoice_id) === wrap.dataset.invoiceId;
                        });
                        if (inv) openInvoiceDetails(inv);
                    } else if (item.dataset.action === 'bill-print') {
                        printReceipt(wrap.dataset.invoiceId);
                    } else if (item.dataset.action === 'bill-pdf') {
                        downloadReceiptPdf(wrap.dataset.invoiceId, wrap.dataset.invoiceCode);
                    }
                    return;
                }

                // Treatment History rows (rendered by portal-dashboard.js → loadTreatments)
                if (item && item.closest('.trt-action')) {
                    const trtCode = item.closest('.trt-action').dataset.trtCode;
                    closeApptMenus();
                    const trt = (window._portalTreatments || []).find(function(x) {
                        return String(x.treatment_code) === trtCode;
                    });
                    if (!trt) return;
                    if (item.dataset.action === 'trt-view') {
                        openTreatmentDetails(trt);
                    } else if (item.dataset.action === 'trt-medical' && trt.appointment_id) {
                        viewMedicalRecord(trt.appointment_id, trt.treatment_code || ''); // existing modal
                    } else if (item.dataset.action === 'trt-feedback' && !item.disabled && trt.appointment_id) {
                        openFeedback(trt.appointment_id); // portal-dashboard.js
                    }
                    return;
                }

                if (item) {
                    const id = item.closest('.appt-action').dataset.apptId;
                    closeApptMenus();
                    const appt = _allAppts.find(function(x) {
                        return String(x.appointment_id) === id;
                    });
                    if (!appt) return;
                    if (item.dataset.action === 'view') {
                        openApptDetails(appt);
                    } else if (item.dataset.action === 'cancel' && canCancelAppt(appt)) {
                        // Existing flow: confirmation modal → confirmCancel()
                        window.portalCancelAppt(appt.appointment_id, appt.appointment_code || '—');
                    }
                    return;
                }

                if (!e.target.closest('.appt-action-menu')) closeApptMenus();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeApptMenus();
            });
            window.addEventListener('resize', closeApptMenus);

            /* ── Billing receipts (Print Receipt / Download PDF) ──── */
            // Both use the existing receipt.php / generate-receipt-pdf.php, which
            // check on the server that the invoice belongs to this patient.
            function printReceipt(invoiceId) {
                const id = parseInt(invoiceId, 10);
                if (!id) return;
                const url = 'receipt.php?invoice_id=' + id + '&print=1';
                // New tab: receipt layout, then the browser's print dialog opens
                const win = window.open(url, '_blank');
                if (!win) window.location.href = url; // pop-up blocked → open here
            }

            async function downloadReceiptPdf(invoiceId, invoiceCode) {
                const id = parseInt(invoiceId, 10);
                if (!id) return;
                const hasSwal = typeof Swal !== 'undefined';
                if (hasSwal) {
                    Swal.fire({
                        title: 'Preparing your receipt…',
                        text: 'Your PDF will download in a moment.',
                        allowOutsideClick: false,
                        didOpen: function() { Swal.showLoading(); }
                    });
                }
                try {
                    const res = await fetch('generate-receipt-pdf.php?invoice_id=' + id, {
                        credentials: 'same-origin'
                    });
                    const type = res.headers.get('Content-Type') || '';
                    if (!res.ok || type.indexOf('application/pdf') === -1) {
                        // Show the server's own reason (receipt_fail() puts it in a <p>)
                        const body = await res.text().catch(function() { return ''; });
                        console.error('Receipt PDF response (' + res.status + '):', body);
                        const m = body.match(/<p[^>]*>([\s\S]*?)<\/p>/i);
                        const reason = m ? m[1].replace(/<[^>]+>/g, '').trim() : '';
                        throw Object.assign(new Error(reason ||
                            'The receipt could not be generated. Please try again.'), { fromServer: true });
                    }
                    const blob = await res.blob();
                    const safeCode = String(invoiceCode || id).replace(/[^A-Za-z0-9_-]/g, '');
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = 'Receipt-' + (safeCode || id) + '.pdf';
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    setTimeout(function() { URL.revokeObjectURL(link.href); }, 10000);
                    if (hasSwal) Swal.close();
                } catch (err) {
                    console.error('Receipt PDF failed:', err);
                    const msg = (err && err.fromServer) ? err.message :
                        'Could not reach the server. Please check your connection and try again.';
                    if (hasSwal) {
                        Swal.fire({ icon: 'error', title: 'Download failed', text: msg, confirmButtonColor: '#2563eb' });
                    } else {
                        alert(msg);
                    }
                }
            }

            /* ── Appointment Details modal ────────────────────────── */
            function fmtLongDate(d) {
                if (!d) return '—';
                const dt = new Date(String(d).length === 10 ? d + 'T00:00:00' : d);
                if (isNaN(dt)) return '—';
                return dt.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
            }

            function fmtStamp(ts) {
                if (!ts) return '';
                const dt = new Date(ts);
                if (isNaN(dt)) return '';
                return dt.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }) +
                    ' • ' + dt.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
            }

            function fmtPeso(v) {
                const n = parseFloat(v);
                if (isNaN(n)) return '—';
                return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function calcAgeYears(b) {
                if (!b) return null;
                const birth = new Date(b + 'T00:00:00'), now = new Date();
                if (isNaN(birth)) return null;
                let age = now.getFullYear() - birth.getFullYear();
                const m = now.getMonth() - birth.getMonth();
                if (m < 0 || (m === 0 && now.getDate() < birth.getDate())) age--;
                return age >= 0 ? age : null;
            }

            /* rows: [label, valueHtml, extraClass?] — falsy entries are skipped.
               Plain text values must go through escAttr() before being passed in. */
            function adRows(rows) {
                return rows.filter(Boolean).map(function(r) {
                    return '<div class="adm-row">' +
                        '<div class="adm-label">' + escAttr(r[0]) + '</div>' +
                        '<div class="adm-colon" aria-hidden="true">:</div>' +
                        '<div class="adm-value' + (r[2] ? ' ' + r[2] : '') + '">' + r[1] + '</div>' +
                        '</div>';
                }).join('');
            }

            function adText(v, fallback) {
                return (v !== null && v !== undefined && String(v).trim() !== '') ?
                    escAttr(v) : escAttr(fallback || '—');
            }

            function openApptDetails(a) {
                const P = _portalPatient || {};
                const code = a.appointment_code || '—';
                const isCancelled = a.status === 'cancelled';
                const names = a.service_names || [];
                const branch = a.branches || {};
                const inv = a.invoice || null;

                /* Header + summary */
                document.getElementById('ad_ref').textContent = code;
                document.getElementById('ad_sum_status').innerHTML =
                    a.status ? statusBadge(a.status).replace('badge-status', 'badge-status adm-badge-lg') : '—';
                document.getElementById('ad_sum_date').textContent = fmtLongDate(a.appointment_date);
                document.getElementById('ad_sum_time').textContent = fmtTime(a.appointment_time);
                document.getElementById('ad_sum_services').innerHTML = names.length ?
                    names.map(function(n) { return '<div>' + escAttr(n) + '</div>'; }).join('') :
                    'General Consultation';
                document.getElementById('ad_sum_branch').textContent = branch.branch_name || '—';
                document.getElementById('ad_sum_address').textContent = branch.address || '';

                /* Patient Information */
                const age = calcAgeYears(P.birthdate);
                document.getElementById('ad_patient').innerHTML = adRows([
                    ['Patient Name', adText(P.full_name), 'strong'],
                    ['Patient ID', adText(P.patient_code)],
                    ['Date of Birth', P.birthdate ? escAttr(fmtLongDate(P.birthdate)) : '—'],
                    ['Age', age !== null ? age + (age === 1 ? ' year old' : ' years old') : '—'],
                    ['Contact Number', adText(P.contact_number)],
                    ['Email Address', adText(P.email)],
                ]);

                /* Appointment Information */
                const dentist = a.dentists && a.dentists.full_name;
                const cancelledAt = (isCancelled && a.updated_at && a.updated_at !== a.created_at) ?
                    fmtStamp(a.updated_at) : '';
                document.getElementById('ad_appointment').innerHTML = adRows([
                    ['Appointment Code', escAttr(code)],
                    ['Dentist', dentist ? escAttr(dentist) : 'Not yet assigned', dentist ? '' : 'muted'],
                    ['Type', adText(a.appointment_type)],
                    ['Status', a.status ? statusBadge(a.status) : '—'],
                    isCancelled && ['Cancelled On', cancelledAt ? escAttr(cancelledAt) : 'Not recorded', cancelledAt ? '' : 'muted'],
                ]);

                /* Payment Information */
                const payStatus = (inv && inv.payment_status) || a.payment_status;
                const payRows = [['Payment Status', payStatus ? paymentBadge(payStatus) : '—']];
                if (inv) {
                    const balance = (inv.balance !== null && inv.balance !== undefined) ?
                        inv.balance : (parseFloat(inv.total_amount || 0) - parseFloat(inv.amount_paid || 0));
                    payRows.push(
                        ['Invoice No.', adText(inv.invoice_code)],
                        ['Total Amount', escAttr(fmtPeso(inv.total_amount))],
                        ['Amount Paid', escAttr(fmtPeso(inv.amount_paid))],
                        ['Amount Due', escAttr(fmtPeso(balance)), 'strong']
                    );
                } else {
                    const est = parseFloat(a.services_total || 0);
                    payRows.push(['Estimated Cost', est > 0 ? escAttr(fmtPeso(est)) : '—', est > 0 ? 'strong' : '']);
                }
                payRows.push(['Payment Method', adText(inv && inv.payment_method)]);
                document.getElementById('ad_payment').innerHTML = adRows(payRows);

                /* Additional Details */
                const notes = a.notes && String(a.notes).trim();
                const bookedOn = fmtStamp(a.created_at);
                document.getElementById('ad_additional').innerHTML = adRows([
                    ['Notes', notes ? escAttr(notes) : 'No notes provided.', notes ? 'pre' : 'muted'],
                    ['Booked On', bookedOn ? escAttr(bookedOn) : '—'],
                    !inv && parseFloat(a.services_total || 0) > 0 &&
                        ['Billing', 'Estimated from service prices. Final amount is set by the clinic.', 'muted'],
                ]);

                bootstrap.Modal.getOrCreateInstance(document.getElementById('apptDetailModal')).show();
            }

            /* ── Treatment Details modal (View Treatment History) ──── */
            function trtStatusBadge(s) {
                if (!s) return '—';
                const label = String(s).split('_').map(function(w) {
                    return w.charAt(0).toUpperCase() + w.slice(1);
                }).join(' ');
                return '<span class="badge-status badge-' + escAttr(s) + '">' + escAttr(label) + '</span>';
            }

            function openTreatmentDetails(t) {
                const code = t.treatment_code || '—';
                const inv = t.invoice || null;
                const dentist = t.dentist_name && t.dentist_name !== '—' ? t.dentist_name : '';
                const procedure = t.service_name && t.service_name !== '—' ? t.service_name : '';

                document.getElementById('td_ref').textContent = code;
                document.getElementById('td_sum_status').innerHTML =
                    trtStatusBadge(t.status).replace('badge-status', 'badge-status adm-badge-lg');
                document.getElementById('td_sum_date').textContent = fmtLongDate(t.treatment_date);
                document.getElementById('td_sum_procedure').textContent = procedure || '—';
                document.getElementById('td_sum_branch').textContent = t.branch_name || '—';
                document.getElementById('td_sum_address').textContent = t.branch_address || '';

                document.getElementById('td_info').innerHTML = adRows([
                    ['Treatment Code', escAttr(code), 'strong'],
                    ['Procedure', adText(procedure)],
                    ['Tooth Number', adText(t.tooth_number)],
                    ['Dentist', dentist ? escAttr(dentist) : 'Not recorded', dentist ? '' : 'muted'],
                    ['Stage', adText(t.current_stage)],
                    ['Status', trtStatusBadge(t.status)],
                ]);

                const aptWhen = t.appointment_date ?
                    fmtLongDate(t.appointment_date) + (t.appointment_time ? ' • ' + fmtTime(t.appointment_time) : '') : '';
                document.getElementById('td_appointment').innerHTML = adRows([
                    ['Appointment Code', adText(t.appointment_code)],
                    ['Date & Time', aptWhen ? escAttr(aptWhen) : '—'],
                    ['Branch', adText(t.branch_name)],
                ]);

                const billRows = [];
                if (inv) {
                    const balance = (inv.balance !== null && inv.balance !== undefined) ?
                        inv.balance : (parseFloat(inv.total_amount || 0) - parseFloat(inv.amount_paid || 0));
                    billRows.push(
                        ['Payment Status', inv.payment_status ? paymentBadge(inv.payment_status) : '—'],
                        ['Invoice No.', adText(inv.invoice_code)],
                        ['Total Amount', escAttr(fmtPeso(inv.total_amount))],
                        ['Amount Paid', escAttr(fmtPeso(inv.amount_paid))],
                        ['Balance', escAttr(fmtPeso(balance)), 'strong']
                    );
                } else {
                    billRows.push(
                        ['Treatment Cost', parseFloat(t.cost) > 0 ? escAttr(fmtPeso(t.cost)) : '—'],
                        ['Invoice', 'No invoice issued yet.', 'muted']
                    );
                }
                document.getElementById('td_billing').innerHTML = adRows(billRows);

                const notes = t.procedure_notes && String(t.procedure_notes).trim();
                document.getElementById('td_notes').innerHTML = adRows([
                    ['Notes', notes ? escAttr(notes) : 'No procedure notes recorded.', notes ? 'pre' : 'muted'],
                ]);

                bootstrap.Modal.getOrCreateInstance(document.getElementById('treatmentDetailModal')).show();
            }
            window.openTreatmentDetails = openTreatmentDetails;   // used by the Dental Chart panel

            /* ── Invoice Details modal (Billing & Payments → View Details) ── */
            // Uses the preloaded get_billing data, so it opens instantly.
            function fmtPayMethod(m) {
                const k = String(m || '').trim().toLowerCase();
                const map = {
                    gcash: 'GCash', maya: 'Maya', paymaya: 'Maya', cash: 'Cash',
                    credit_card: 'Credit Card', debit_card: 'Debit Card', bank_transfer: 'Bank Transfer'
                };
                if (!k) return '—';
                return map[k] || k.split('_').map(function(w) {
                    return w.charAt(0).toUpperCase() + w.slice(1);
                }).join(' ');
            }

            function payStatusLabel(p) {
                return p === 'partial' ? 'Partially Paid' : (p ? p.charAt(0).toUpperCase() + p.slice(1) : 'Unpaid');
            }

            function openInvoiceDetails(inv) {
                const total = parseFloat(inv.total_amount || 0);
                const paid = parseFloat(inv.amount_paid || 0);
                const balance = Math.max(0, (inv.balance !== null && inv.balance !== undefined && inv.balance !== '') ?
                    parseFloat(inv.balance) : total - paid);
                const status = String(inv.payment_status || 'unpaid').toLowerCase();
                const badge = '<span class="badge-status badge-' + escAttr(status) + '">' +
                    escAttr(payStatusLabel(status)) + '</span>';

                /* Header + summary */
                document.getElementById('iv_ref').textContent = inv.invoice_code || '—';
                document.getElementById('iv_sum_status').innerHTML = badge.replace('badge-status', 'badge-status adm-badge-lg');
                document.getElementById('iv_sum_date').textContent = fmtLongDate(inv.invoice_date);
                document.getElementById('iv_sum_total').textContent = fmtPeso(total);
                document.getElementById('iv_sum_paid').textContent = fmtPeso(paid) + ' paid';
                const balEl = document.getElementById('iv_sum_balance');
                balEl.textContent = fmtPeso(balance);
                balEl.classList.toggle('iv-due', balance > 0);
                balEl.classList.toggle('iv-clear', balance <= 0);

                /* Invoice Information */
                document.getElementById('iv_info').innerHTML = adRows([
                    ['Invoice No.', adText(inv.invoice_code), 'strong'],
                    ['Invoice Date', inv.invoice_date ? escAttr(fmtLongDate(inv.invoice_date)) : '—'],
                    ['Payment Status', badge],
                    ['Branch', adText(inv.branch_name)],
                    inv.branch_address && ['Address', escAttr(inv.branch_address)],
                    inv.branch_contact && ['Contact No.', escAttr(inv.branch_contact)],
                ]);

                /* Services Rendered */
                const names = Array.isArray(inv.service_names) ? inv.service_names : [];
                const dentist = inv.dentist_name && String(inv.dentist_name).trim();
                const notes = inv.procedure_notes && String(inv.procedure_notes).trim();
                document.getElementById('iv_services').innerHTML = adRows([
                    ['Service', names.length ?
                        names.map(function(n) { return '<div>' + escAttr(n) + '</div>'; }).join('') :
                        'Not specified', names.length ? 'strong' : 'muted'],
                    ['Tooth Number', adText(inv.tooth_number)],
                    ['Dentist', dentist ? escAttr(dentist) : 'Not recorded', dentist ? '' : 'muted'],
                    ['Procedure Notes', notes ? escAttr(notes) : 'No procedure notes recorded.', notes ? 'pre' : 'muted'],
                ]);

                /* Visit */
                const when = inv.appointment_date ?
                    fmtLongDate(inv.appointment_date) + (inv.appointment_time ? ' • ' + fmtTime(inv.appointment_time) : '') : '';
                document.getElementById('iv_visit').innerHTML = adRows([
                    ['Appointment Code', adText(inv.appointment_code)],
                    ['Date & Time', when ? escAttr(when) : '—'],
                    ['Treatment Code', adText(inv.treatment_code && inv.treatment_code !== '—' ? inv.treatment_code : '')],
                    inv.treatment_date && ['Treatment Date', escAttr(fmtLongDate(inv.treatment_date))],
                ]);

                /* Payment History */
                const pays = Array.isArray(inv.payments) ? inv.payments : [];
                const list = pays.length ?
                    '<ul class="iv-pay-list">' + pays.map(function(p) {
                        const by = p.recorded_by && String(p.recorded_by).trim();
                        const note = p.notes && String(p.notes).trim();
                        const meta = [escAttr(fmtPayMethod(p.payment_method))];
                        if (by) meta.push('Received by ' + escAttr(by));
                        return '<li class="iv-pay">' +
                            '<div class="iv-pay-main">' +
                            '<div class="iv-pay-date">' + escAttr(fmtLongDate(p.payment_date)) + '</div>' +
                            '<div class="iv-pay-meta">' + meta.join(' · ') + '</div>' +
                            (note ? '<div class="iv-pay-note">' + escAttr(note) + '</div>' : '') +
                            '</div>' +
                            '<div class="iv-pay-amt">' + escAttr(fmtPeso(p.amount)) + '</div>' +
                            '</li>';
                    }).join('') + '</ul>' :
                    '<p class="iv-pay-empty">No payments recorded yet.</p>';
                document.getElementById('iv_payments').innerHTML = list +
                    '<div class="iv-pay-totals">' + adRows([
                        ['Total Amount', escAttr(fmtPeso(total))],
                        ['Amount Paid', escAttr(fmtPeso(paid))],
                        ['Balance Due', escAttr(fmtPeso(balance)), 'strong ' + (balance > 0 ? 'iv-due' : 'iv-clear')],
                    ]) + '</div>';

                bootstrap.Modal.getOrCreateInstance(document.getElementById('invoiceDetailModal')).show();
            }
            window.openInvoiceDetails = openInvoiceDetails;

            // After confirmCancel() (portal-dashboard.js) runs, refresh the table again
            const _origConfirmCancel = window.confirmCancel;
            window.confirmCancel = function() {
                if (typeof _origConfirmCancel === 'function') {
                    _origConfirmCancel.apply(this, arguments);
                }
                // Reload after short delay to reflect cancellation
                setTimeout(loadPortalAppointments, 1800);
            };

            // Single renderer for the table — portal-dashboard.js's loadAppointments() calls this
            window.loadPortalAppointments = loadPortalAppointments;

            /* ── Boot: run after portal-dashboard.js has initialised ─ */
            // We use a small delay to ensure portal-dashboard.js's own
            // DOMContentLoaded / load handlers have already fired.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(loadPortalAppointments, 150);
                });
            } else {
                setTimeout(loadPortalAppointments, 150);
            }

            // Also re-run whenever the Appointments page tab is shown
            const _origShowPage = window.showPage;
            window.showPage = function(page, el) {
                if (typeof _origShowPage === 'function') _origShowPage(page, el);
                // 'appointments' is already loaded by showPage() → loadAppointments()
                if (page === 'dashboard') {
                    setTimeout(loadPortalAppointments, 100);
                }
            };

        })();
    </script>
    <script>
        (function() {
            'use strict';

            // 1. Toggle Dropdown and Focus Search
            window.toggleCustomSelect = function(id) {
                const wrap = document.getElementById(id);
                if (!wrap) return;

                // Close any other open dropdowns first
                document.querySelectorAll('.modal-cs.open').forEach(el => {
                    if (el !== wrap) el.classList.remove('open');
                });

                const isOpen = wrap.classList.contains('open');
                wrap.classList.toggle('open');

                // If opening, reset search and focus it
                if (!isOpen) {
                    const searchInput = wrap.querySelector('.modal-cs-search');
                    if (searchInput) {
                        searchInput.value = '';
                        filterCustomSelect(searchInput, id + 'Dropdown');
                        // Small delay to ensure the element is visible before focusing
                        setTimeout(() => searchInput.focus(), 50);
                    }
                }
            };

            // 2. Filter List Based on Search Input
            window.filterCustomSelect = function(input, dropdownId) {
                const q = input.value.toLowerCase();
                const listItems = document.querySelectorAll('#' + dropdownId + ' .modal-cs-list li');
                listItems.forEach(li => {
                    const text = li.textContent.toLowerCase();
                    li.style.display = text.includes(q) ? '' : 'none';
                });
            };

            // 3. Reset Dropdowns (Clears dependent fields)
            function resetPfSelect(selectId, valueId, hiddenId, placeholder) {
                const hidden = document.getElementById(hiddenId);
                if (hidden) hidden.value = '';

                const vEl = document.getElementById(valueId);
                if (vEl) {
                    vEl.textContent = placeholder;
                    vEl.classList.remove('cs-selected');
                }

                const ul = document.getElementById(selectId.replace('Select', 'List'));
                if (ul) ul.innerHTML = '';

                document.getElementById(selectId)?.classList.remove('open');
            }

            // Values come from cluster.json (in capitals). The hidden inputs keep the
            // exact cluster.json name; lists and labels show it nicely capitalised.
            // List items carry their values in data-* attributes (safe for names
            // with apostrophes) and are handled by one click listener per list.
            const _escA = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const _label = s => (typeof normalizeAddressText === 'function' ? normalizeAddressText(s) : String(s));
            const _key = s => String(s || '').toUpperCase().replace(/\s+/g, ' ').trim();

            function _setLabel(valueId, text, selected) {
                const vEl = document.getElementById(valueId);
                if (!vEl) return;
                vEl.textContent = text;
                vEl.classList.toggle('cs-selected', !!selected);
            }

            function _markActive(ul, li) {
                if (!ul) return;
                ul.querySelectorAll('li').forEach(l => l.classList.toggle('active', l === li));
            }

            // 4. Populate Provinces (Initial Load)
            function initPfProvinces() {
                if (!PH_DATA) return;
                const ul = document.getElementById('pf_provinceList');
                if (!ul || ul.children.length > 0) return;

                const provinceList = [];
                Object.entries(PH_DATA).forEach(([regionCode, region]) => {
                    Object.keys(region.province_list || {}).forEach(pName => {
                        provinceList.push({ name: pName, region: regionCode });
                    });
                });
                provinceList.sort((a, b) => a.name.localeCompare(b.name));

                ul.innerHTML = provinceList.map(p =>
                    `<li data-region="${_escA(p.region)}" data-province="${_escA(p.name)}">${_escA(_label(p.name))}</li>`
                ).join('');
            }

            function _fillCities(regionCode, provinceName) {
                const cities = Object.keys(PH_DATA?.[regionCode]?.province_list?.[provinceName]?.municipality_list || {}).sort();
                document.getElementById('pf_cityList').innerHTML = cities.map(c =>
                    `<li data-region="${_escA(regionCode)}" data-province="${_escA(provinceName)}" data-city="${_escA(c)}">${_escA(_label(c))}</li>`
                ).join('');
            }

            function _fillBarangays(regionCode, provinceName, cityName) {
                const brgys = (PH_DATA?.[regionCode]?.province_list?.[provinceName]?.municipality_list?.[cityName]?.barangay_list || []).slice().sort();
                document.getElementById('pf_barangayList').innerHTML = brgys.map(b =>
                    `<li data-barangay="${_escA(b)}">${_escA(_label(b))}</li>`
                ).join('');
            }

            // 5. Select Province -> Load Cities
            window.selectPfProvince = function(regionCode, name, li) {
                document.getElementById('pf_province').value = name;
                _setLabel('pf_provinceValue', _label(name), true);
                _markActive(li && li.closest('ul'), li);

                // Reset dependent fields
                resetPfSelect('pf_citySelect', 'pf_cityValue', 'pf_city', 'Select City');
                resetPfSelect('pf_barangaySelect', 'pf_barangayValue', 'pf_barangay', 'Select City First');
                _fillCities(regionCode, name);
                document.getElementById('pf_provinceSelect')?.classList.remove('open');
            };

            // 6. Select City -> Load Barangays
            window.selectPfCity = function(regionCode, provinceName, cityName, li) {
                document.getElementById('pf_city').value = cityName;
                _setLabel('pf_cityValue', _label(cityName), true);
                _markActive(li && li.closest('ul'), li);

                resetPfSelect('pf_barangaySelect', 'pf_barangayValue', 'pf_barangay', 'Select Barangay');
                _fillBarangays(regionCode, provinceName, cityName);
                document.getElementById('pf_citySelect')?.classList.remove('open');
            };

            // 7. Select Barangay
            window.selectPfBarangay = function(name, li) {
                document.getElementById('pf_barangay').value = name;
                _setLabel('pf_barangayValue', _label(name), true);
                _markActive(li && li.closest('ul'), li);
                document.getElementById('pf_barangaySelect')?.classList.remove('open');
            };

            // One listener per list (instead of inline onclick on every item)
            [
                ['pf_provinceList', li => selectPfProvince(li.dataset.region, li.dataset.province, li)],
                ['pf_cityList', li => selectPfCity(li.dataset.region, li.dataset.province, li.dataset.city, li)],
                ['pf_barangayList', li => selectPfBarangay(li.dataset.barangay, li)],
            ].forEach(([id, handler]) => {
                const ul = document.getElementById(id);
                if (ul) ul.addEventListener('click', e => {
                    const li = e.target.closest('li');
                    if (li && ul.contains(li)) handler(li);
                });
            });

            // 8. Show the saved province / city / barangay when the profile loads.
            //    Matches cluster.json names ignoring capitalisation, and fills the
            //    city and barangay lists so they can be changed straight away.
            window.restorePfAddress = function(province, city, barangay) {
                initPfProvinces();
                const prov = String(province || '').trim();
                const cty = String(city || '').trim();
                const brgy = String(barangay || '').trim();

                document.getElementById('pf_cityList').innerHTML = '';
                document.getElementById('pf_barangayList').innerHTML = '';
                _setLabel('pf_provinceValue', prov ? _label(prov) : 'Select province', !!prov);
                _setLabel('pf_cityValue', cty ? _label(cty) : 'Select province first', !!cty);
                _setLabel('pf_barangayValue', brgy ? _label(brgy) : 'Select city first', !!brgy);
                if (!prov || !PH_DATA) return;

                let region = null, provName = null;
                Object.entries(PH_DATA).some(([rc, r]) => {
                    const hit = Object.keys(r.province_list || {}).find(p => _key(p) === _key(prov));
                    if (hit) { region = rc; provName = hit; }
                    return !!hit;
                });
                if (!provName) return; // typed or older value — shown as saved
                _markActive(document.getElementById('pf_provinceList'),
                    document.querySelector('#pf_provinceList li[data-province="' + CSS.escape(provName) + '"]'));
                _fillCities(region, provName);
                if (!cty) {
                    _setLabel('pf_cityValue', 'Select City', false);
                    return;
                }

                const cityName = Object.keys(PH_DATA[region].province_list[provName].municipality_list || {})
                    .find(c => _key(c) === _key(cty));
                if (!cityName) return;
                _markActive(document.getElementById('pf_cityList'),
                    document.querySelector('#pf_cityList li[data-city="' + CSS.escape(cityName) + '"]'));
                _fillBarangays(region, provName, cityName);
                if (!brgy) _setLabel('pf_barangayValue', 'Select Barangay', false);
                else _markActive(document.getElementById('pf_barangayList'),
                    Array.from(document.querySelectorAll('#pf_barangayList li')).find(l => _key(l.dataset.barangay) === _key(brgy)));
            };

            // Close dropdowns if user clicks anywhere else
            document.addEventListener('click', e => {
                if (!e.target.closest('.modal-cs')) {
                    document.querySelectorAll('.modal-cs.open').forEach(el => el.classList.remove('open'));
                }
            });

            // Run on load
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPfProvinces);
            } else {
                initPfProvinces();
            }

        })();
    </script>

    <!-- ── Notification Bell — live data from api/portal_notifications.php
         (scoped server-side to the logged-in account; polls every 60s
         while the tab is visible). ── -->
    <script>
        (function() {
            'use strict';

            const wrap  = document.getElementById('notifBellWrap');
            const btn   = document.getElementById('notifBellBtn');
            const panel = document.getElementById('notifPanel');

            window.toggleNotifPanel = function() {
                const isOpen = wrap.classList.toggle('open');
                btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (isOpen) load(false);
            };

            const body    = document.getElementById('notifPanelBody');
            const badge   = document.getElementById('notifBadge');
            const markBtn = document.getElementById('notifMarkAllRead');
            const API     = 'api/portal_notifications.php';
            const CAT = {
                appointment:  { icon: 'fa-calendar-check', label: 'Appointment' },
                billing:      { icon: 'fa-receipt',        label: 'Billing' },
                treatment:    { icon: 'fa-tooth',          label: 'Treatment' },
                account:      { icon: 'fa-shield-halved',  label: 'Account' },
                announcement: { icon: 'fa-bullhorn',       label: 'Announcement' },
            };
            const PAGES = ['dashboard', 'appointments', 'treatments', 'dentalchart', 'billing', 'files', 'waivers', 'profile'];
            let items = [], hasMore = false, unread = 0, loading = false, poll = null;

            function esc(s) {
                return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            }

            function timeAgo(iso) {
                const d = new Date(iso);
                if (isNaN(d)) return '';
                const s = Math.max(0, (Date.now() - d.getTime()) / 1000);
                if (s < 60) return 'Just now';
                if (s < 3600) return Math.floor(s / 60) + 'm ago';
                if (s < 86400) return Math.floor(s / 3600) + 'h ago';
                if (s < 604800) return Math.floor(s / 86400) + 'd ago';
                return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
            }

            function setBadge(n) {
                unread = Math.max(0, n | 0);
                badge.textContent = unread > 99 ? '99+' : String(unread);
                badge.style.display = unread > 0 ? '' : 'none';
                btn.setAttribute('aria-label', unread ? `Notifications, ${unread} unread` : 'Notifications');
                markBtn.disabled = unread === 0;
            }

            function render() {
                if (!items.length) {
                    body.innerHTML = '<div class="notif-empty"><i class="fa-solid fa-bell-slash"></i>' +
                        '<p>You\'re all caught up.</p><p style="font-size:.76rem;margin-top:.25rem;">Updates about your appointments, billing and treatments will appear here.</p></div>';
                    return;
                }
                body.innerHTML = items.map(n => {
                    const c = CAT[n.category] || CAT.announcement;
                    return `<button type="button" class="notif-item${n.is_read ? '' : ' unread'}" data-id="${n.notification_id}">
                        <span class="notif-item-icon cat-${esc(n.category)}"><i class="fa-solid ${c.icon}"></i></span>
                        <span class="notif-item-text">
                            <span class="notif-item-title d-block">${esc(n.title)}</span>
                            <span class="notif-item-msg d-block">${esc(n.message)}</span>
                            <span class="notif-item-time d-block"><span class="notif-cat">${c.label}</span> · <time datetime="${esc(n.created_at)}" title="${esc(new Date(n.created_at).toLocaleString())}">${timeAgo(n.created_at)}</time></span>
                        </span>
                    </button>`;
                }).join('') + (hasMore ? '<div class="notif-panel-footer"><button type="button" class="notif-mark-read" id="notifLoadMore">Show older notifications</button></div>' : '');
            }

            async function load(append) {
                if (loading) return;
                loading = true;
                try {
                    const r = await fetch(`${API}?action=list&offset=${append ? items.length : 0}`, { credentials: 'same-origin' });
                    if (r.status === 401) { clearInterval(poll); return; }
                    const j = await r.json();
                    if (!j.success) throw new Error(j.message);
                    items = append ? items.concat(j.notifications) : j.notifications;
                    hasMore = !!j.has_more;
                    setBadge(j.unread);
                    render();
                } catch (e) {
                    if (!items.length) body.innerHTML = '<div class="notif-panel-error">Notifications are unavailable right now.</div>';
                } finally {
                    loading = false;
                }
            }

            function post(payload) {
                return fetch(API, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                }).then(r => r.json()).catch(() => ({ success: false }));
            }

            function closePanel() {
                wrap.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            }

            body.addEventListener('click', function(e) {
                if (e.target.closest('#notifLoadMore')) { e.stopPropagation(); load(true); return; }
                const el = e.target.closest('.notif-item');
                if (!el) return;
                const n = items.find(x => String(x.notification_id) === el.dataset.id);
                if (!n) return;
                if (!n.is_read) {
                    n.is_read = true;
                    el.classList.remove('unread');
                    setBadge(unread - 1);
                    post({ action: 'mark_read', notification_id: n.notification_id })
                        .then(j => { if (!j.success) load(false); });
                }
                if (n.link_page && PAGES.includes(n.link_page) && typeof showPage === 'function') {
                    closePanel();
                    showPage(n.link_page, null);
                }
            });

            window.markAllNotifsRead = async function() {
                if (!unread) return;
                const prev = unread;
                items.forEach(n => n.is_read = true);
                setBadge(0);
                render();
                const j = await post({ action: 'mark_all_read' });
                if (!j.success) { setBadge(prev); load(false); }
            };

            load(false);
            poll = setInterval(() => { if (!document.hidden) load(false); }, 60000);

            // Close on outside click — same pattern as the modal-cs dropdowns.
            document.addEventListener('click', function(e) {
                if (wrap && !e.target.closest('#notifBellWrap')) {
                    wrap.classList.remove('open');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });

            // Close on Escape.
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && wrap.classList.contains('open')) {
                    wrap.classList.remove('open');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        })();
    </script>
</body>

</html>