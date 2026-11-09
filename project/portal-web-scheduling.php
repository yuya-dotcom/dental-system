<?php
// =============================================================
//  portal-web-scheduling.php
//  Patient booking wizard (replaces appointmentWeb-Scheduling.php)
//    1 Branch → 2 Services → 3 Date & Time → 4 Review & Confirm
//  All steps live on this page; assets/js/portal-web-scheduling.js
//  switches between them. Rules live in controllers/branch_config.php.
// =============================================================
session_start();
require_once __DIR__ . '/portal-session-guard.php';
portal_require_login();   // also applies the portal's idle / max-duration expiry

// Booking needs a verified email OR phone — the dashboard's modal explains how
if (!portal_contact_status((int)$_SESSION['portal_account_id'])['ok']) {
    header('Location: portal-dashboard.php?verify_contact=1');
    exit;
}

require_once __DIR__ . '/controllers/branch_config.php';

// Profile must be complete before booking (same rule as the dashboard's Book button)
$accRes = supabase_request('patient_accounts', 'GET', [],
    'account_id=eq.' . (int)$_SESSION['portal_account_id'] . '&select=first_name,is_profile_complete&limit=1'
);
$account         = $accRes['data'][0] ?? [];
$profileComplete = !empty($account['is_profile_complete']);
$firstName       = trim((string)($account['first_name'] ?? ''));

$bookingData = [
    'today'       => bookingToday(),
    'slotMinutes' => BOOKING_SLOT_MINUTES,
    'maxServices' => BOOKING_MAX_SERVICES,
    'branches'    => array_values(array_map(fn($b) => [
        'id'       => $b['branch_id'],
        'name'     => $b['branch_name'],
        'address'  => $b['address'],
        'days'     => $b['days'],
        'daysText' => $b['days_label'],
        'hours'    => $b['hours_label'],
        'openMin'  => $b['open_min'],
        'closeMin' => $b['close_min'],
        'active'   => $b['active'],
    ], getBookingBranches())),
    'services'    => $profileComplete ? getBookableServices() : [],
];

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Book an Appointment</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=4">
    <link rel="stylesheet" href="assets/css/portalLogin-navbar.css?v=4">
    <link rel="stylesheet" href="assets/css/portal-web-scheduling.css?v=4">
</head>
<body>

    <!-- ═══ TOP BAR (same navbar as portal-login.php) ═════════ -->
    <?php
        $navBrandHref = 'portal-dashboard.php';
        $navBackHref  = 'portal-dashboard.php';
        $navBackLabel = 'Back to My Portal';
        include __DIR__ . '/partials/portalLogin-navbar.php';
    ?>

    <!-- ═══ HERO ══════════════════════════════════════════════ -->
    <section class="bk-hero">
        <div class="bk-hero-inner">
            <span class="bk-hero-eyebrow"><i class="fa-solid fa-calendar-check"></i> Online booking</span>
            <h1>Book your dental visit<?= $firstName ? ', ' . e($firstName) : '' ?></h1>
            <p>Choose a branch, your services and a time that works for you. It only takes a minute.</p>
        </div>
    </section>

    <main class="bk-main">
    <?php if (!$profileComplete): ?>

        <!-- Profile incomplete — booking is blocked (the server checks this too) -->
        <section class="bk-card bk-blocked">
            <div class="bk-blocked-icon"><i class="fa-solid fa-user-pen"></i></div>
            <h2>Complete your profile first</h2>
            <p>We need your basic information, home address and health questionnaire before you can book online.</p>
            <a href="portal-dashboard.php" class="bk-btn bk-btn-primary">
                <i class="fa-solid fa-arrow-left"></i> Go to My Portal
            </a>
        </section>

    <?php else: ?>

        <!-- ═══ PROGRESS ════════════════════════════════════════ -->
        <nav class="bk-progress" aria-label="Booking steps">
            <ol id="bkProgress">
                <li data-step="1"><button type="button" class="bk-progress-btn"><span class="bk-progress-dot">1</span><span class="bk-progress-label">Branch</span></button></li>
                <li data-step="2"><button type="button" class="bk-progress-btn"><span class="bk-progress-dot">2</span><span class="bk-progress-label">Services</span></button></li>
                <li data-step="3"><button type="button" class="bk-progress-btn"><span class="bk-progress-dot">3</span><span class="bk-progress-label">Date &amp; Time</span></button></li>
                <li data-step="4"><button type="button" class="bk-progress-btn"><span class="bk-progress-dot">4</span><span class="bk-progress-label">Review</span></button></li>
            </ol>
        </nav>

        <div class="bk-layout">

            <!-- ═══ STEPS ═══════════════════════════════════════ -->
            <section class="bk-card bk-wizard" aria-live="polite">

                <!-- Step 1 · Branch -->
                <div class="bk-step" data-step="1">
                    <header class="bk-step-head">
                        <span class="bk-step-kicker">Step 1 of 4</span>
                        <h2>Choose a branch</h2>
                        <p>Pick the EssenciaSmile clinic you'd like to visit.</p>
                    </header>
                    <div class="bk-branch-grid" id="bkBranchList" role="radiogroup" aria-label="Branches"></div>
                </div>

                <!-- Step 2 · Services -->
                <div class="bk-step" data-step="2" hidden>
                    <header class="bk-step-head">
                        <span class="bk-step-kicker">Step 2 of 4</span>
                        <h2>Choose your services</h2>
                        <p>Select at least one and up to <?= BOOKING_MAX_SERVICES ?>. Not sure? Choose a consultation.</p>
                    </header>
                    <div class="bk-svc-toolbar">
                        <div class="bk-search">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="search" id="bkSvcSearch" placeholder="Search services…" autocomplete="off" aria-label="Search services">
                        </div>
                        <span class="bk-svc-count" id="bkSvcCount">0 of <?= BOOKING_MAX_SERVICES ?> selected</span>
                    </div>
                    <div class="bk-svc-grid" id="bkSvcList" role="group" aria-label="Services"></div>
                    <div class="bk-visit-bar" id="bkVisitBar" hidden>
                        <div><i class="fa-regular fa-clock"></i> Estimated visit: <strong id="bkVisitLength">—</strong></div>
                        <div>Estimated total: <strong id="bkVisitTotal">—</strong></div>
                    </div>
                    <div class="bk-inline-alert" id="bkSvcAlert" hidden></div>
                </div>

                <!-- Step 3 · Date & time -->
                <div class="bk-step" data-step="3" hidden>
                    <header class="bk-step-head">
                        <span class="bk-step-kicker">Step 3 of 4</span>
                        <h2>Pick a date and time</h2>
                        <p id="bkDateIntro">Choose an open day, then a start time.</p>
                    </header>
                    <div class="bk-datetime">
                        <div class="bk-cal-wrap">
                            <div id="bkCalendar"></div>
                            <ul class="bk-legend" aria-label="Calendar legend">
                                <li><span class="bk-dot bk-dot-open"></span>Available</li>
                                <li><span class="bk-dot bk-dot-sel"></span>Selected</li>
                                <li><span class="bk-dot bk-dot-full"></span>Fully booked</li>
                                <li><span class="bk-dot bk-dot-closed"></span>Closed</li>
                            </ul>
                        </div>
                        <div class="bk-times">
                            <div class="bk-times-head">
                                <i class="fa-regular fa-clock"></i>
                                <span id="bkTimesTitle">Available times</span>
                            </div>
                            <div id="bkTimes" class="bk-times-body">
                                <div class="bk-empty"><i class="fa-regular fa-calendar"></i>Select a date to see available times.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 4 · Review -->
                <div class="bk-step" data-step="4" hidden>
                    <header class="bk-step-head">
                        <span class="bk-step-kicker">Step 4 of 4</span>
                        <h2>Review and confirm</h2>
                        <p>Check your details before confirming.</p>
                    </header>
                    <div class="bk-review" id="bkReview"></div>
                    <div class="bk-field">
                        <label for="bkNotes">Notes for your dentist <span>(optional)</span></label>
                        <textarea id="bkNotes" rows="3" maxlength="1000" placeholder="Any concerns or requests…"></textarea>
                    </div>
                    <p class="bk-fineprint">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>Your booking starts as <strong>pending</strong> until the clinic confirms it. Prices are estimates; your dentist will confirm the final cost.</span>
                    </p>
                    <div class="bk-inline-alert" id="bkSubmitAlert" hidden></div>
                </div>

                <!-- Navigation -->
                <footer class="bk-nav">
                    <button type="button" class="bk-btn bk-btn-ghost" id="bkBack">
                        <i class="fa-solid fa-arrow-left"></i> Back
                    </button>
                    <span class="bk-nav-hint" id="bkHint"></span>
                    <button type="button" class="bk-btn bk-btn-primary" id="bkNext">
                        Next <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </footer>
            </section>

            <!-- ═══ SUMMARY ═════════════════════════════════════ -->
            <aside class="bk-card bk-summary" aria-label="Your booking">
                <h3>Your booking</h3>
                <dl id="bkSummary"></dl>
            </aside>

        </div>
    <?php endif; ?>
    </main>

    <?php if ($profileComplete): ?>
    <script>
        window.BOOKING = <?= json_encode($bookingData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.js"></script>
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/es-swal.js?v=2"></script>
    <script src="assets/js/portal-web-scheduling.js?v=3"></script>
    <?php endif; ?>
</body>
</html>