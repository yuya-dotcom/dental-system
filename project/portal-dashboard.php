<?php
// portal/portal-dashboard.php

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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #2563eb;
            --primary-light: #eff6ff;
            --primary-dark: #1d4ed8;
            --green: #22c55e;
            --green-light: #f0fdf4;
            --orange: #f97316;
            --orange-light: #fff7ed;
            --red: #ef4444;
            --red-light: #fef2f2;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --sidebar-w: 260px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: var(--text-dark); }

        /* ── Sidebar ── */
        .sidebar {
            position: fixed; left: 0; top: 0; bottom: 0;
            width: var(--sidebar-w); background: #fff;
            border-right: 1px solid var(--border);
            display: flex; flex-direction: column; z-index: 100;
            overflow-y: auto;
        }
        .sidebar-brand {
            display: flex; align-items: center; gap: .6rem;
            padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border);
            text-decoration: none;
        }
        .sidebar-brand img { height: 36px; }
        .sidebar-brand span { font-weight: 800; font-size: 1.05rem; color: var(--primary); letter-spacing: -.3px; }

        .sidebar-user {
            padding: 1rem 1.5rem 0;
            display: flex; align-items: center; gap: .75rem;
        }
        .avatar-circle {
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--primary-light); color: var(--primary);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 1rem; flex-shrink: 0;
        }
        .sidebar-user-info .name { font-weight: 700; font-size: .9rem; color: var(--text-dark); }
        .sidebar-user-info .badge-patient {
            display: inline-block; font-size: .7rem; font-weight: 700;
            background: var(--primary-light); color: var(--primary);
            padding: .15rem .55rem; border-radius: 50px; letter-spacing: .3px;
        }

        .sidebar-nav { padding: 1rem 0; flex: 1; }
        .nav-section-label {
            font-size: .7rem; font-weight: 700; color: var(--text-muted);
            letter-spacing: .8px; text-transform: uppercase;
            padding: .5rem 1.5rem .3rem;
        }
        .nav-link-item {
            display: flex; align-items: center; gap: .75rem;
            padding: .6rem 1.5rem; text-decoration: none;
            color: var(--text-muted); font-size: .88rem; font-weight: 500;
            border-left: 3px solid transparent; transition: all .15s;
        }
        .nav-link-item i { width: 18px; text-align: center; }
        .nav-link-item:hover { color: var(--primary); background: var(--primary-light); }
        .nav-link-item.active {
            color: var(--primary); background: var(--primary-light);
            border-left-color: var(--primary); font-weight: 700;
        }

        .sidebar-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
        }
        .logout-btn {
            display: flex; align-items: center; gap: .65rem;
            color: var(--red); font-size: .88rem; font-weight: 600;
            background: none; border: none; cursor: pointer;
            font-family: inherit; padding: 0;
        }
        .logout-btn:hover { opacity: .75; }

        /* ── Top Bar ── */
        .topbar {
            position: fixed; top: 0; left: var(--sidebar-w); right: 0;
            height: 60px; background: #fff; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 1.75rem; z-index: 50;
        }
        .topbar-title { font-size: 1rem; font-weight: 700; color: var(--text-dark); }
        .topbar-breadcrumb { font-size: .8rem; color: var(--text-muted); margin-top: .1rem; }
        .topbar-actions { display: flex; align-items: center; gap: .75rem; }
        .btn-book-appt {
            background: var(--primary); color: #fff; border: none;
            padding: .5rem 1.1rem; border-radius: 8px; font-size: .85rem;
            font-weight: 700; font-family: inherit; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: .4rem;
            transition: background .2s;
        }
        .btn-book-appt:hover { background: var(--primary-dark); color: #fff; }

        /* ── Main Content ── */
        .main-content {
            margin-left: var(--sidebar-w);
            padding-top: 60px;
            min-height: 100vh;
        }
        .page-body { padding: 1.75rem; display: none; }
        .page-body.active { display: block; }

        /* ── Cards ── */
        .stat-card {
            background: #fff; border: 1px solid var(--border); border-radius: 12px;
            padding: 1.25rem; display: flex; align-items: flex-start; gap: 1rem;
        }
        .stat-icon {
            width: 48px; height: 48px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.15rem; flex-shrink: 0;
        }
        .stat-icon.blue { background: var(--primary-light); color: var(--primary); }
        .stat-icon.green { background: var(--green-light); color: var(--green); }
        .stat-icon.orange { background: var(--orange-light); color: var(--orange); }
        .stat-icon.red { background: var(--red-light); color: var(--red); }
        .stat-label { font-size: .8rem; color: var(--text-muted); font-weight: 500; }
        .stat-value { font-size: 1.6rem; font-weight: 800; color: var(--text-dark); line-height: 1.1; }
        .stat-sub { font-size: .75rem; color: var(--text-muted); margin-top: .15rem; }

        /* ── Content Card ── */
        .content-card {
            background: #fff; border: 1px solid var(--border); border-radius: 12px;
            overflow: hidden;
        }
        .card-header-bar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1rem 1.25rem; border-bottom: 1px solid var(--border);
        }
        .card-header-bar h6 { font-weight: 700; font-size: .95rem; margin: 0; }
        .card-body-pad { padding: 1.25rem; }

        /* ── Table ── */
        .portal-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .portal-table thead th {
            font-size: .75rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .5px; color: var(--text-muted);
            padding: .65rem 1rem; background: var(--bg);
            border-bottom: 1px solid var(--border); white-space: nowrap;
        }
        .portal-table tbody td { padding: .8rem 1rem; border-bottom: 1px solid var(--border); color: var(--text-dark); }
        .portal-table tbody tr:last-child td { border-bottom: none; }
        .portal-table tbody tr:hover td { background: #fafbfc; }

        /* ── Badges ── */
        .badge-status {
            display: inline-flex; align-items: center; gap: .3rem;
            font-size: .75rem; font-weight: 700; padding: .25rem .65rem;
            border-radius: 50px; letter-spacing: .2px;
        }
        .badge-status::before { content:''; width:6px; height:6px; border-radius:50%; display:inline-block; }
        .badge-pending { background: #fef9c3; color: #854d0e; } .badge-pending::before { background: #ca8a04; }
        .badge-confirmed { background: var(--primary-light); color: var(--primary-dark); } .badge-confirmed::before { background: var(--primary); }
        .badge-completed { background: var(--green-light); color: #166534; } .badge-completed::before { background: var(--green); }
        .badge-cancelled { background: var(--red-light); color: #991b1b; } .badge-cancelled::before { background: var(--red); }
        .badge-checked_in { background: #f3e8ff; color: #6b21a8; } .badge-checked_in::before { background: #a855f7; }
        .badge-paid { background: var(--green-light); color: #166534; } .badge-paid::before { background: var(--green); }
        .badge-partial { background: var(--orange-light); color: #9a3412; } .badge-partial::before { background: var(--orange); }
        .badge-unpaid { background: var(--red-light); color: #991b1b; } .badge-unpaid::before { background: var(--red); }

        /* ── Profile incomplete warning ── */
        .profile-alert {
            background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px;
            padding: 1rem 1.25rem; display: flex; align-items: flex-start; gap: .85rem;
            margin-bottom: 1.5rem;
        }
        .profile-alert i { color: var(--orange); font-size: 1.1rem; margin-top: .1rem; }
        .profile-alert h6 { font-weight: 700; margin-bottom: .2rem; color: #9a3412; font-size: .9rem; }
        .profile-alert p { color: #c2410c; font-size: .83rem; margin: 0; }

        /* ── Dental Chart ── */
        .tooth-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 6px; }
        .tooth-cell {
            display: flex; flex-direction: column; align-items: center;
            gap: 3px; font-size: .7rem; color: var(--text-muted); font-weight: 500;
        }
        .tooth-icon {
            width: 36px; height: 36px; border-radius: 8px; border: 1.5px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; cursor: pointer; transition: all .15s;
            background: #fff;
        }
        .tooth-icon:hover { border-color: var(--primary); background: var(--primary-light); }
        .tooth-icon.healthy { border-color: #bbf7d0; background: #f0fdf4; }
        .tooth-icon.cavity { border-color: #fde68a; background: #fffbeb; }
        .tooth-icon.filled { border-color: #bfdbfe; background: var(--primary-light); }
        .tooth-icon.missing { border-color: #fecaca; background: var(--red-light); opacity: .6; }
        .tooth-icon.crown { border-color: #e9d5ff; background: #faf5ff; }
        .tooth-chart-label { text-align: center; font-size: .75rem; font-weight: 700; color: var(--text-muted); letter-spacing: .5px; text-transform: uppercase; margin-bottom: .5rem; }

        /* ── File card ── */
        .file-card {
            display: flex; align-items: center; gap: .85rem;
            padding: .85rem 1rem; border: 1px solid var(--border);
            border-radius: 10px; background: #fff; transition: box-shadow .15s;
        }
        .file-card:hover { box-shadow: 0 2px 10px rgba(0,0,0,.06); }
        .file-icon { width: 40px; height: 40px; border-radius: 8px; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
        .file-name { font-weight: 600; font-size: .875rem; }
        .file-meta { font-size: .75rem; color: var(--text-muted); }
        .file-download { margin-left: auto; color: var(--primary); font-size: .875rem; text-decoration: none; }

        /* ── Empty state ── */
        .empty-state { text-align: center; padding: 3rem 1rem; }
        .empty-state i { font-size: 2.5rem; color: #cbd5e1; margin-bottom: .75rem; display: block; }
        .empty-state p { color: var(--text-muted); font-size: .9rem; }

        /* ── Profile form ── */
        .profile-section-title {
            font-size: .75rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .6px; color: var(--text-muted); margin-bottom: .75rem; display: block;
        }
        .form-label { font-size: .85rem; font-weight: 600; color: var(--text-dark); margin-bottom: .35rem; }
        .form-control, .form-select {
            border: 1.5px solid var(--border); border-radius: 9px;
            padding: .55rem .85rem; font-size: .9rem; font-family: inherit;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.1); outline: none;
        }
        .btn-primary-sm {
            background: var(--primary); color: #fff; border: none;
            padding: .6rem 1.5rem; border-radius: 8px; font-size: .875rem;
            font-weight: 700; font-family: inherit; cursor: pointer;
        }
        .btn-primary-sm:hover { background: var(--primary-dark); }
        .btn-cancel-sm {
            background: none; color: var(--text-muted); border: 1.5px solid var(--border);
            padding: .6rem 1.2rem; border-radius: 8px; font-size: .875rem;
            font-weight: 600; font-family: inherit; cursor: pointer;
        }

        /* ── Waiver ── */
        .waiver-card { padding: .85rem 1rem; border: 1px solid var(--border); border-radius: 10px; }
        .waiver-badge { font-size: .75rem; font-weight: 700; padding: .2rem .6rem; border-radius: 50px; }
        .waiver-pending { background: #fef9c3; color: #854d0e; }
        .waiver-signed { background: var(--green-light); color: #166534; }

        /* ── Mobile ── */
        .mob-nav-toggle { display: none; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); transition: transform .25s; }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .topbar { left: 0; }
            .mob-nav-toggle { display: flex; }
            .sidebar-overlay { position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:99;display:none; }
            .sidebar-overlay.show { display:block; }
        }
    </style>
</head>
<body>

<!-- Mobile overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <a class="sidebar-brand align-items-center" href="appointmentWeb-Landing.php">
        <img src="assets/images/essencia-full@3x.png" alt="EssenciaSmile">
    </a>

    <div class="sidebar-user">
        <div class="avatar-circle" id="sidebarInitial">P</div>
        <div class="sidebar-user-info">
            <div class="name" id="sidebarName">Loading…</div>
            <div class="badge-patient">Patient Portal</div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Overview</div>
        <a class="nav-link-item active" href="#" onclick="showPage('dashboard',this)">
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
            <i class="fa-solid fa-folder-open"></i> My Files
        </a>
        <a class="nav-link-item" href="#" onclick="showPage('waivers',this)">
            <i class="fa-solid fa-pen-to-square"></i> Waivers
        </a>

        <div class="nav-section-label mt-2">Account</div>
        <a class="nav-link-item" href="#" onclick="showPage('profile',this)">
            <i class="fa-solid fa-user-circle"></i> My Profile
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
        <button class="mob-nav-toggle" onclick="openSidebar()" style="background:none;border:none;font-size:1.25rem;color:var(--text-dark);cursor:pointer;">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div>
            <div class="topbar-title" id="topbarTitle">Dashboard</div>
            <div class="topbar-breadcrumb" id="topbarBreadcrumb">My Portal › Dashboard</div>
        </div>
    </div>
    <div class="topbar-actions">
        <a href="appointmentWeb-Scheduling.php" class="btn-book-appt" id="bookApptBtn">
            <i class="fa-solid fa-plus"></i> Book Appointment
        </a>
    </div>
</div>

<!-- Main Content -->
<div class="main-content">

    <!-- ── DASHBOARD PAGE ── -->
    <div class="page-body active" id="page-dashboard">
        <!-- Profile incomplete warning (shown if needed) -->
        <div class="profile-alert" id="profileIncompleteAlert" style="display:none;">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div>
                <h6>Complete Your Profile</h6>
                <p>Please complete your profile information before booking appointments.
                    <span class="text-link ms-1" style="cursor:pointer;color:#c2410c;font-weight:700;" onclick="showPage('profile', null)">Go to Profile →</span>
                </p>
            </div>
        </div>

        <!-- Welcome banner -->
        <div style="margin-bottom:1.5rem;">
            <h4 style="font-weight:800;color:var(--text-dark);margin-bottom:.25rem;">
                Good day, <span id="welcomeName">Patient</span>
            </h4>
            <p style="color:var(--text-muted);font-size:.9rem;">Here's an overview of your dental health.</p>
        </div>

        <!-- Stats -->
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
            <!-- Upcoming Appointments -->
            <div class="col-lg-7">
                <div class="content-card">
                    <div class="card-header-bar">
                        <h6><i class="fa-solid fa-calendar-check me-2 text-primary"></i>Upcoming Appointments</h6>
                        <span class="text-link" style="font-size:.83rem;color:var(--primary);cursor:pointer;" onclick="showPage('appointments',null)">View all</span>
                    </div>
                    <div id="dashApptList">
                        <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i><p>No upcoming appointments.</p></div>
                    </div>
                </div>
            </div>
            <!-- Recent Bills -->
            <div class="col-lg-5">
                <div class="content-card">
                    <div class="card-header-bar">
                        <h6><i class="fa-solid fa-receipt me-2 text-primary"></i>Recent Bills</h6>
                        <span class="text-link" style="font-size:.83rem;color:var(--primary);cursor:pointer;" onclick="showPage('billing',null)">View all</span>
                    </div>
                    <div id="dashBillList">
                        <div class="empty-state"><i class="fa-solid fa-file-invoice"></i><p>No billing records yet.</p></div>
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
            <a href="appointmentWeb-Scheduling.php" class="btn-book-appt">
                <i class="fa-solid fa-plus"></i> Book New
            </a>
        </div>

        <!-- Filter tabs -->
        <div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
            <button class="appt-filter-btn active" data-status="all" onclick="filterAppts('all',this)">All</button>
            <button class="appt-filter-btn" data-status="pending" onclick="filterAppts('pending',this)">Pending</button>
            <button class="appt-filter-btn" data-status="confirmed" onclick="filterAppts('confirmed',this)">Confirmed</button>
            <button class="appt-filter-btn" data-status="completed" onclick="filterAppts('completed',this)">Completed</button>
            <button class="appt-filter-btn" data-status="cancelled" onclick="filterAppts('cancelled',this)">Cancelled</button>
        </div>
        <style>
            .appt-filter-btn { border: 1.5px solid var(--border); background: #fff; color: var(--text-muted); font-family: inherit; font-size: .8rem; font-weight: 600; padding: .4rem .9rem; border-radius: 50px; cursor: pointer; transition: all .15s; }
            .appt-filter-btn:hover, .appt-filter-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        </style>

        <div class="content-card">
            <div id="apptTableWrap">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Code</th><th>Date & Time</th><th>Branch</th>
                            <th>Service</th><th>Status</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="apptTableBody">
                        <tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── TREATMENTS PAGE ── -->
    <div class="page-body" id="page-treatments">
        <div class="mb-3">
            <h5 style="font-weight:800;margin:0;">Treatment History</h5>
            <p style="color:var(--text-muted);font-size:.85rem;margin:0;">Read-only view of all your procedures</p>
        </div>
        <div class="content-card">
            <table class="portal-table">
                <thead>
                    <tr>
                        <th>Code</th><th>Date</th><th>Procedure</th>
                        <th>Tooth</th><th>Dentist</th><th>Status</th>
                    </tr>
                </thead>
                <tbody id="treatTableBody">
                    <tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── DENTAL CHART PAGE ── -->
    <div class="page-body" id="page-dentalchart">
        <div class="mb-3">
            <h5 style="font-weight:800;margin:0;">My Dental Chart</h5>
            <p style="color:var(--text-muted);font-size:.85rem;margin:0;">Read-only visual overview of your tooth conditions</p>
        </div>
        <div class="content-card">
            <div class="card-body-pad">
                <!-- Legend -->
                <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem;">
                    <span style="font-size:.78rem;font-weight:600;color:var(--text-muted);">Legend:</span>
                    <span style="font-size:.78rem;display:flex;align-items:center;gap:.35rem;"><span style="width:12px;height:12px;border-radius:3px;background:#f0fdf4;border:1.5px solid #bbf7d0;display:inline-block;"></span>Healthy</span>
                    <span style="font-size:.78rem;display:flex;align-items:center;gap:.35rem;"><span style="width:12px;height:12px;border-radius:3px;background:#fffbeb;border:1.5px solid #fde68a;display:inline-block;"></span>Cavity</span>
                    <span style="font-size:.78rem;display:flex;align-items:center;gap:.35rem;"><span style="width:12px;height:12px;border-radius:3px;background:var(--primary-light);border:1.5px solid #bfdbfe;display:inline-block;"></span>Filled</span>
                    <span style="font-size:.78rem;display:flex;align-items:center;gap:.35rem;"><span style="width:12px;height:12px;border-radius:3px;background:var(--red-light);border:1.5px solid #fecaca;display:inline-block;"></span>Missing</span>
                    <span style="font-size:.78rem;display:flex;align-items:center;gap:.35rem;"><span style="width:12px;height:12px;border-radius:3px;background:#faf5ff;border:1.5px solid #e9d5ff;display:inline-block;"></span>Crown</span>
                    <span style="font-size:.78rem;display:flex;align-items:center;gap:.35rem;"><span style="width:12px;height:12px;border-radius:3px;background:#fff;border:1.5px solid var(--border);display:inline-block;"></span>No data</span>
                </div>

                <!-- Upper teeth -->
                <p class="tooth-chart-label">Upper Teeth (18 → 11 | 21 → 28)</p>
                <div class="tooth-grid mb-3" id="upperTeeth"></div>

                <!-- Lower teeth -->
                <p class="tooth-chart-label">Lower Teeth (48 → 41 | 31 → 38)</p>
                <div class="tooth-grid" id="lowerTeeth"></div>
            </div>
        </div>
    </div>

    <!-- ── BILLING PAGE ── -->
    <div class="page-body" id="page-billing">
        <div class="mb-3">
            <h5 style="font-weight:800;margin:0;">Billing & Payments</h5>
            <p style="color:var(--text-muted);font-size:.85rem;margin:0;">Your invoices and payment history</p>
        </div>

        <!-- Summary cards -->
        <div class="row g-3 mb-3" id="billingSummary">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                    <div><div class="stat-label">Total Billed</div><div class="stat-value" id="billTotal">₱0.00</div></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fa-solid fa-circle-check"></i></div>
                    <div><div class="stat-label">Total Paid</div><div class="stat-value" id="billPaid">₱0.00</div></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div><div class="stat-label">Outstanding Balance</div><div class="stat-value" id="billBalance">₱0.00</div></div>
                </div>
            </div>
        </div>

        <div class="content-card">
            <table class="portal-table">
                <thead>
                    <tr><th>Invoice</th><th>Date</th><th>Treatment</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr>
                </thead>
                <tbody id="billingTableBody">
                    <tr><td colspan="7" class="text-center py-4" style="color:var(--text-muted);">Loading…</td></tr>
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
        <div id="filesGrid" class="row g-2">
            <div class="col-12"><div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>No files uploaded yet.</p></div></div>
        </div>
    </div>

    <!-- ── WAIVERS PAGE ── -->
    <div class="page-body" id="page-waivers">
        <div class="mb-3">
            <h5 style="font-weight:800;margin:0;">Waivers</h5>
            <p style="color:var(--text-muted);font-size:.85rem;margin:0;">View your consent and waiver documents</p>
        </div>
        <div id="waiversList" class="row g-2">
            <div class="col-12"><div class="empty-state"><i class="fa-solid fa-pen-to-square"></i><p>No waivers on file.</p></div></div>
        </div>
    </div>

    <!-- ── PROFILE PAGE ── -->
    <div class="page-body" id="page-profile">
        <div class="mb-3">
            <h5 style="font-weight:800;margin:0;">My Profile</h5>
            <p style="color:var(--text-muted);font-size:.85rem;margin:0;">Keep your information up to date</p>
        </div>

        <div class="row g-3">
            <!-- Account info card -->
            <div class="col-lg-4">
                <div class="content-card">
                    <div class="card-body-pad" style="text-align:center;">
                        <div class="avatar-circle mx-auto mb-3" style="width:72px;height:72px;font-size:1.75rem;" id="profileAvatar">P</div>
                        <div id="profileFullName" style="font-weight:800;font-size:1.1rem;color:var(--text-dark);">—</div>
                        <div id="profileEmail" style="font-size:.85rem;color:var(--text-muted);margin-top:.2rem;">—</div>
                        <div id="profileContact" style="font-size:.85rem;color:var(--text-muted);margin-top:.1rem;">—</div>
                        <div id="profileComplete" style="margin-top:.85rem;"></div>
                    </div>
                </div>
            </div>

            <!-- Edit form -->
            <div class="col-lg-8">
                <div class="content-card">
                    <div class="card-header-bar">
                        <h6>Edit Profile</h6>
                        <div id="profileSaveStatus"></div>
                    </div>
                    <div class="card-body-pad">
                        <span class="profile-section-title">Personal Information</span>
                        <div class="row g-3 mb-3">
                            <div class="col-md-5">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" id="pf_first" class="form-control">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" id="pf_last" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Suffix</label>
                                <select id="pf_suffix" class="form-select">
                                    <option value="">—</option>
                                    <option>Jr.</option><option>Sr.</option>
                                    <option>II</option><option>III</option><option>IV</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Middle Name</label>
                                <input type="text" id="pf_middle" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Birthdate</label>
                                <input type="date" id="pf_birthdate" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Gender</label>
                                <select id="pf_gender" class="form-select">
                                    <option value="">— Select —</option>
                                    <option>Male</option><option>Female</option>
                                    <option>Prefer not to say</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contact Number</label>
                                <input type="text" id="pf_contact" class="form-control" readonly style="background:var(--bg);">
                                <div style="font-size:.75rem;color:var(--text-muted);margin-top:.2rem;">Contact number cannot be changed here.</div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 justify-content-end">
                            <button class="btn-cancel-sm" onclick="loadProfile()">Cancel</button>
                            <button class="btn-primary-sm" onclick="saveProfile()">Save Changes</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div><!-- end main-content -->

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
                <button class="btn btn-danger px-4" onclick="confirmCancel()">Yes, Cancel</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/vendors/js/vendors.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>

// ── Navigation ──
function showPage(page, linkEl) {
    document.querySelectorAll('.page-body').forEach(p => p.classList.remove('active'));
    document.getElementById('page-' + page).classList.add('active');

    document.querySelectorAll('.nav-link-item').forEach(l => l.classList.remove('active'));
    if (linkEl) linkEl.classList.add('active');
    else {
        const match = Array.from(document.querySelectorAll('.nav-link-item')).find(l => l.getAttribute('onclick')?.includes(`'${page}'`));
        if (match) match.classList.add('active');
    }

    const titles = { dashboard:'Dashboard', appointments:'My Appointments', treatments:'Treatment History', dentalchart:'Dental Chart', billing:'Billing & Payments', files:'My Files', waivers:'Waivers', profile:'My Profile' };
    document.getElementById('topbarTitle').textContent = titles[page] || page;
    document.getElementById('topbarBreadcrumb').textContent = `My Portal › ${titles[page] || page}`;

    // Lazy load pages
    if (page === 'appointments') loadAppointments();
    if (page === 'treatments') loadTreatments();
    if (page === 'dentalchart') loadDentalChart();
    if (page === 'billing') loadBilling();
    if (page === 'files') loadFiles();
    if (page === 'waivers') loadWaivers();
    if (page === 'profile') loadProfile();
    closeSidebar();
}

function openSidebar()  { document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('show'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('show'); }

// ── Helpers ──
function php(path, body) {
    return fetch(path, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) }).then(r=>r.json());
}
function peso(v) { return '₱' + parseFloat(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(d) { if (!d) return '—'; return new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'}); }
function fmtTime(t) { if (!t) return ''; const [h,m]=t.split(':'); const dt=new Date(); dt.setHours(+h,+m); return dt.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'}); }
function statusBadge(s) { return `<span class="badge-status badge-${s||'pending'}">${(s||'pending').replace('_',' ')}</span>`; }
function payBadge(s) { return `<span class="badge-status badge-${s||'unpaid'}">${s||'unpaid'}</span>`; }

// ── Load Dashboard ──
async function loadDashboard() {
    const data = await php(API, { action: 'dashboard_summary' });
    if (!data.success) return;
    const { account, stats, upcoming, recent_bills, is_profile_complete } = data;
    accountData = account;

    // Update sidebar & welcome
    const name = account.first_name || 'Patient';
    document.getElementById('sidebarInitial').textContent = name.charAt(0).toUpperCase();
    document.getElementById('sidebarName').textContent = (account.first_name || '') + ' ' + (account.last_name || '');
    document.getElementById('welcomeName').textContent = name;

    // Stats
    document.getElementById('statUpcoming').textContent = stats.upcoming || 0;
    document.getElementById('statCompleted').textContent = stats.completed || 0;
    document.getElementById('statBalance').textContent = peso(stats.balance);
    document.getElementById('statTreatments').textContent = stats.treatments || 0;

    // Profile warning
    if (!is_profile_complete) {
        document.getElementById('profileIncompleteAlert').style.display = 'flex';
        document.getElementById('bookApptBtn').style.opacity = '.6';
        document.getElementById('bookApptBtn').title = 'Complete profile first';
    }

    // Upcoming appointments mini list
    if (upcoming && upcoming.length > 0) {
        document.getElementById('dashApptList').innerHTML = upcoming.map(a => `
            <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.85rem;">
                <div style="width:42px;height:42px;border-radius:10px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;flex-shrink:0;">
                    ${new Date(a.appointment_date).getDate()}
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:.875rem;">${a.service_name || 'Consultation'}</div>
                    <div style="font-size:.78rem;color:var(--text-muted);">${fmtDate(a.appointment_date)} · ${fmtTime(a.appointment_time)} · ${a.branch_name || '—'}</div>
                </div>
                ${statusBadge(a.status)}
            </div>
        `).join('');
    }

    // Recent bills
    if (recent_bills && recent_bills.length > 0) {
        document.getElementById('dashBillList').innerHTML = recent_bills.map(b => `
            <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <div style="font-weight:700;font-size:.875rem;">${b.invoice_code}</div>
                    <div style="font-size:.78rem;color:var(--text-muted);">${fmtDate(b.invoice_date)}</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-weight:700;">${peso(b.total_amount)}</div>
                    ${payBadge(b.payment_status)}
                </div>
            </div>
        `).join('');
    }
}

// ── Load Appointments ──
async function loadAppointments() {
    const tbody = document.getElementById('apptTableBody');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">Loading…</td></tr>';
    const data = await php(API, { action: 'get_appointments' });
    allAppointments = data.appointments || [];
    renderApptTable(allAppointments);
}
function renderApptTable(list) {
    const tbody = document.getElementById('apptTableBody');
    if (!list.length) { tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">No appointments found.</td></tr>'; return; }
    tbody.innerHTML = list.map(a => `
        <tr>
            <td><span style="font-weight:700;font-size:.8rem;">${a.appointment_code}</span></td>
            <td>${fmtDate(a.appointment_date)}<br><span style="font-size:.75rem;color:var(--text-muted);">${fmtTime(a.appointment_time)}</span></td>
            <td>${a.branch_name || '—'}</td>
            <td>${a.service_name || 'Consultation'}</td>
            <td>${statusBadge(a.status)}</td>
            <td>
                ${['pending','confirmed'].includes(a.status) ? `<button onclick="openCancelModal(${a.appointment_id},'${a.appointment_code}')" style="background:none;border:1.5px solid #fecaca;border-radius:7px;padding:.3rem .75rem;font-size:.78rem;font-weight:700;color:var(--red);cursor:pointer;font-family:inherit;">Cancel</button>` : '<span style="color:var(--text-muted);font-size:.8rem;">—</span>'}
            </td>
        </tr>
    `).join('');
}
function filterAppts(status, btn) {
    document.querySelectorAll('.appt-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    renderApptTable(status === 'all' ? allAppointments : allAppointments.filter(a => a.status === status));
}
function openCancelModal(id, code) {
    currentCancelId = id;
    document.getElementById('cancelApptCode').textContent = code;
    new bootstrap.Modal(document.getElementById('cancelModal')).show();
}
async function confirmCancel() {
    const data = await php(API, { action: 'cancel_appointment', appointment_id: currentCancelId });
    bootstrap.Modal.getInstance(document.getElementById('cancelModal')).hide();
    if (data.success) {
        Swal.fire({ icon:'success', title:'Cancelled', text:'Your appointment has been cancelled.', confirmButtonColor:'#2563eb', timer:2000, showConfirmButton:false });
        loadAppointments();
        loadDashboard();
    } else {
        Swal.fire({ icon:'error', title:'Error', text: data.message || 'Could not cancel.', confirmButtonColor:'#2563eb' });
    }
}

// ── Load Treatments ──
async function loadTreatments() {
    const tbody = document.getElementById('treatTableBody');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4">Loading…</td></tr>';
    const data = await php(API, { action: 'get_treatments' });
    const list = data.treatments || [];
    if (!list.length) { tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">No treatments on record.</td></tr>'; return; }
    tbody.innerHTML = list.map(t => `
        <tr>
            <td><span style="font-weight:700;font-size:.8rem;">${t.treatment_code}</span></td>
            <td>${fmtDate(t.treatment_date)}</td>
            <td>${t.service_name || '—'}</td>
            <td>${t.tooth_number || '—'}</td>
            <td>${t.dentist_name || '—'}</td>
            <td>${statusBadge(t.status)}</td>
        </tr>
    `).join('');
}

// ── Load Dental Chart ──
async function loadDentalChart() {
    const data = await php(API, { action: 'get_dental_chart' });
    const chartMap = {};
    (data.chart || []).forEach(c => { chartMap[c.tooth_number] = c; });

    const condClass = { healthy:'healthy', cavity:'cavity', filled:'filled', missing:'missing', crown:'crown' };
    const condEmoji = { healthy:'🦷', cavity:'🟡', filled:'🔵', missing:'✖', crown:'👑' };

    function buildTooth(num) {
        const entry = chartMap[num];
        const cond = entry?.condition?.toLowerCase() || '';
        const cls = condClass[cond] || '';
        const emoji = condEmoji[cond] || '🦷';
        const tip = entry ? `${num}: ${entry.condition || 'Healthy'}${entry.notes ? ' — '+entry.notes : ''}` : `${num}: No data`;
        return `<div class="tooth-cell"><div class="tooth-icon ${cls}" title="${tip}">${emoji}</div><span>${num}</span></div>`;
    }

    const upper = [18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28];
    const lower = [48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38];
    document.getElementById('upperTeeth').innerHTML = upper.map(buildTooth).join('');
    document.getElementById('lowerTeeth').innerHTML = lower.map(buildTooth).join('');
}

// ── Load Billing ──
async function loadBilling() {
    const tbody = document.getElementById('billingTableBody');
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4">Loading…</td></tr>';
    const data = await php(API, { action: 'get_billing' });
    const list = data.invoices || [];
    let totalBilled=0, totalPaid=0;
    if (!list.length) { tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4" style="color:var(--text-muted);">No invoices found.</td></tr>'; return; }
    tbody.innerHTML = list.map(i => {
        totalBilled += parseFloat(i.total_amount||0);
        totalPaid   += parseFloat(i.amount_paid||0);
        const bal = parseFloat(i.total_amount||0) - parseFloat(i.amount_paid||0);
        return `<tr>
            <td><span style="font-weight:700;font-size:.8rem;">${i.invoice_code}</span></td>
            <td>${fmtDate(i.invoice_date)}</td>
            <td>${i.treatment_code || '—'}</td>
            <td>${peso(i.total_amount)}</td>
            <td>${peso(i.amount_paid)}</td>
            <td>${peso(bal)}</td>
            <td>${payBadge(i.payment_status)}</td>
        </tr>`;
    }).join('');
    document.getElementById('billTotal').textContent   = peso(totalBilled);
    document.getElementById('billPaid').textContent    = peso(totalPaid);
    document.getElementById('billBalance').textContent = peso(totalBilled - totalPaid);
}

// ── Load Files ──
async function loadFiles() {
    const grid = document.getElementById('filesGrid');
    grid.innerHTML = '<div class="col-12 text-center py-4" style="color:var(--text-muted);">Loading…</div>';
    const data = await php(API, { action: 'get_files' });
    const list = data.files || [];
    if (!list.length) { grid.innerHTML = '<div class="col-12"><div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>No files uploaded yet.</p></div></div>'; return; }
    const icons = { 'X-ray':'fa-radiation', 'Referral':'fa-file-medical', 'Certificate':'fa-certificate' };
    grid.innerHTML = list.map(f => `
        <div class="col-md-6">
            <div class="file-card">
                <div class="file-icon"><i class="fa-solid ${icons[f.file_type]||'fa-file'}"></i></div>
                <div>
                    <div class="file-name">${f.file_name || 'Document'}</div>
                    <div class="file-meta">${f.file_type} · ${fmtDate(f.uploaded_at)}</div>
                </div>
                <a href="${f.file_path}" target="_blank" class="file-download" title="Download/View">
                    <i class="fa-solid fa-arrow-down-to-line"></i>
                </a>
            </div>
        </div>
    `).join('');
}

// ── Load Waivers ──
async function loadWaivers() {
    const list_el = document.getElementById('waiversList');
    const data = await php(API, { action: 'get_waivers' });
    const list = data.waivers || [];
    if (!list.length) { list_el.innerHTML = '<div class="col-12"><div class="empty-state"><i class="fa-solid fa-pen-to-square"></i><p>No waivers on file.</p></div></div>'; return; }
    list_el.innerHTML = list.map(w => `
        <div class="col-md-6">
            <div class="waiver-card d-flex align-items-center justify-content-between">
                <div>
                    <div style="font-weight:700;font-size:.875rem;">${w.waiver_type || 'General Waiver'}</div>
                    <div style="font-size:.78rem;color:var(--text-muted);">${w.signed_at ? 'Signed '+fmtDate(w.signed_at) : 'Not yet signed'}</div>
                </div>
                <div>
                    <span class="waiver-badge ${w.status==='Signed'?'waiver-signed':'waiver-pending'}">${w.status||'Pending'}</span>
                    ${w.file_path ? `<a href="${w.file_path}" target="_blank" class="file-download ms-2" title="View"><i class="fa-solid fa-eye"></i></a>` : ''}
                </div>
            </div>
        </div>
    `).join('');
}

// ── Load Profile ──
async function loadProfile() {
    const data = await php(API, { action: 'get_profile' });
    const p = data.account || {};
    document.getElementById('pf_first').value     = p.first_name || '';
    document.getElementById('pf_last').value      = p.last_name || '';
    document.getElementById('pf_middle').value    = p.middle_name || '';
    document.getElementById('pf_suffix').value    = p.suffix || '';
    document.getElementById('pf_birthdate').value = p.birthdate || '';
    document.getElementById('pf_gender').value    = p.gender || '';
    document.getElementById('pf_contact').value   = p.contact_number || '';
    document.getElementById('profileFullName').textContent = [p.first_name, p.middle_name, p.last_name, p.suffix].filter(Boolean).join(' ') || '—';
    document.getElementById('profileEmail').textContent    = p.email || '—';
    document.getElementById('profileContact').textContent  = p.contact_number || '—';
    document.getElementById('profileAvatar').textContent   = (p.first_name || 'P').charAt(0).toUpperCase();
    document.getElementById('profileComplete').innerHTML   = p.is_profile_complete
        ? '<span class="badge-status badge-completed">Profile Complete</span>'
        : '<span class="badge-status badge-pending">Profile Incomplete</span>';
}
async function saveProfile() {
    const body = {
        action: 'update_profile',
        first_name:  document.getElementById('pf_first').value.trim(),
        last_name:   document.getElementById('pf_last').value.trim(),
        middle_name: document.getElementById('pf_middle').value.trim(),
        suffix:      document.getElementById('pf_suffix').value,
        birthdate:   document.getElementById('pf_birthdate').value,
        gender:      document.getElementById('pf_gender').value,
    };
    if (!body.first_name || !body.last_name) {
        Swal.fire({ icon:'warning', title:'Required Fields', text:'First and last name are required.', confirmButtonColor:'#2563eb' }); return;
    }
    const data = await php(API, body);
    if (data.success) {
        Swal.fire({ icon:'success', title:'Profile Updated!', timer:1800, showConfirmButton:false });
        loadProfile(); loadDashboard();
    } else {
        Swal.fire({ icon:'error', title:'Error', text: data.message || 'Update failed.', confirmButtonColor:'#2563eb' });
    }
}

// ── Logout ──
async function doLogout() {
    const conf = await Swal.fire({ icon:'question', title:'Sign Out?', text:'You will be logged out of your portal.', showCancelButton:true, confirmButtonText:'Yes, sign out', confirmButtonColor:'#ef4444', cancelButtonText:'Stay' });
    if (conf.isConfirmed) {
        await fetch('api/portal_auth.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'logout'}) });
        window.location.href = 'portal-login.php';
    }
}

// ── Init ──
loadDashboard();
</script>
</body>
</html>