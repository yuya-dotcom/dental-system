<?php
// portal-dashboard.php
session_start();
if (!isset($_SESSION['portal_account_id'])) {
    header("Location: portal-login.php");
    exit;
}

$API_PATH = 'api/portal_auth.php';
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
    <link rel="stylesheet" href="assets/css/portal-dashboard.css">
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
            <div class="avatar-circle" id="sidebarInitial">—</div>
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
            <button class="btn-book-appt" id="bookApptBtn" onclick="handleBookAppt()" type="button">
                <i class="fa-solid fa-plus"></i> Book Appointment
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
                <button class="btn-book-appt" id="bookApptBtnAlt" onclick="handleBookAppt()" type="button">
                    <i class="fa-solid fa-plus"></i> Book New
                </button>
            </div>

            <div class="appt-filter-bar">
                <button class="appt-filter-btn active" data-status="all" onclick="filterAppts('all',this)">All</button>
                <button class="appt-filter-btn" data-status="pending" onclick="filterAppts('pending',this)">Pending</button>
                <button class="appt-filter-btn" data-status="confirmed" onclick="filterAppts('confirmed',this)">Confirmed</button>
                <button class="appt-filter-btn" data-status="completed" onclick="filterAppts('completed',this)">Completed</button>
                <button class="appt-filter-btn" data-status="cancelled" onclick="filterAppts('cancelled',this)">Cancelled</button>
            </div>

            <div class="content-card">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Date & Time</th>
                            <th>Branch</th>
                            <th>Service</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="apptTableBody">
                        <tr>
                            <td colspan="6" class="text-center py-4" style="color:var(--text-muted);">Loading…</td>
                        </tr>
                    </tbody>
                </table>
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
                            <th>Code</th>
                            <th>Date</th>
                            <th>Procedure</th>
                            <th>Tooth</th>
                            <th>Dentist</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="treatTableBody">
                        <tr>
                            <td colspan="6" class="text-center py-4" style="color:var(--text-muted);">Loading…</td>
                        </tr>
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

                <!-- Legend + Surface Key -->
                <div class="chart-top-bar">
                    <div class="chart-legend-bar">
                        <div class="chart-legend-title">Tooth Status Legend</div>
                        <div class="chart-legend-items">
                            <span class="legend-item"><span class="legend-dot" style="background:#fff;border:1.5px solid #86efac;"></span>Healthy</span>
                            <span class="legend-item"><span class="legend-dot" style="background:#3b82f6;border-color:#2563eb;"></span>Filled</span>
                            <span class="legend-item"><span class="legend-dot" style="background:#ef4444;border-color:#dc2626;"></span>Decay</span>
                            <span class="legend-item"><span class="legend-dot" style="background:#a855f7;border-color:#9333ea;"></span>Impacted</span>
                            <span class="legend-item"><span class="legend-dot" style="background:#9ca3af;border-color:#6b7280;"></span>Missing</span>
                            <span class="legend-item"><span class="legend-dot" style="background:#eab308;border-color:#ca8a04;"></span>Crown</span>
                            <span class="legend-item"><span class="legend-dot" style="background:#22c55e;border-color:#16a34a;"></span>Impacted (Not Erupted)</span>
                        </div>
                    </div>
                    <div class="chart-surface-key">
                        <svg viewBox="0 0 80 80" width="62" height="62" style="flex-shrink:0;" xmlns="http://www.w3.org/2000/svg">
                            <path d="M 20.2,20.2 A 28,28 0 0,1 59.8,20.2 L 48.5,31.5 A 12,12 0 0,0 31.5,31.5 Z" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="1.5"/>
                            <path d="M 59.8,20.2 A 28,28 0 0,1 59.8,59.8 L 48.5,48.5 A 12,12 0 0,0 48.5,31.5 Z" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="1.5"/>
                            <path d="M 59.8,59.8 A 28,28 0 0,1 20.2,59.8 L 31.5,48.5 A 12,12 0 0,0 48.5,48.5 Z" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="1.5"/>
                            <path d="M 20.2,59.8 A 28,28 0 0,1 20.2,20.2 L 31.5,31.5 A 12,12 0 0,0 31.5,48.5 Z" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="1.5"/>
                            <circle cx="40" cy="40" r="12" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="1.5"/>
                            <text x="40" y="19" text-anchor="middle" dominant-baseline="middle" font-size="9" font-weight="700" fill="#475569" font-family="'Plus Jakarta Sans',sans-serif">B</text>
                            <text x="58" y="40" text-anchor="middle" dominant-baseline="middle" font-size="9" font-weight="700" fill="#475569" font-family="'Plus Jakarta Sans',sans-serif">M</text>
                            <text x="40" y="40" text-anchor="middle" dominant-baseline="middle" font-size="9" font-weight="700" fill="#475569" font-family="'Plus Jakarta Sans',sans-serif">O</text>
                            <text x="40" y="61" text-anchor="middle" dominant-baseline="middle" font-size="9" font-weight="700" fill="#475569" font-family="'Plus Jakarta Sans',sans-serif">L</text>
                            <text x="22" y="40" text-anchor="middle" dominant-baseline="middle" font-size="9" font-weight="700" fill="#475569" font-family="'Plus Jakarta Sans',sans-serif">D</text>
                        </svg>
                        <div class="surface-key-list">
                            <div class="surface-key-item"><strong>B</strong> – Buccal (Labial)</div>
                            <div class="surface-key-item"><strong>O</strong> – Occlusal</div>
                            <div class="surface-key-item"><strong>M</strong> – Mesial</div>
                            <div class="surface-key-item"><strong>L</strong> – Lingual</div>
                            <div class="surface-key-item"><strong>D</strong> – Distal</div>
                        </div>
                    </div>
                </div>

                <!-- Teeth grids -->
                <div class="card-body-pad">
                    <p class="tooth-section-label">Upper Teeth (1 – 16)</p>
                    <div class="tooth-grid-8 mb-4" id="upperTeeth"></div>
                    <p class="tooth-section-label">Lower Teeth (17 – 32)</p>
                    <div class="tooth-grid-8" id="lowerTeeth"></div>
                </div>

                <!-- Info note -->
                <div class="chart-info-bar">
                    <i class="fa-solid fa-circle-info"></i>
                    Visual overview of your tooth conditions. Contact your dentist for any updates.
                </div>

            </div>
        </div>

        <!-- ── BILLING PAGE ── -->
        <div class="page-body" id="page-billing">
            <div class="mb-3">
                <h5 style="font-weight:800;margin:0;">Billing & Payments</h5>
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
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Treatment</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
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
            <div id="filesGrid" class="row g-2">
                <div class="col-12">
                    <div class="empty-state"><i class="fa-solid fa-folder-open"></i>
                        <p>No files uploaded yet.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── WAIVERS PAGE ── -->
        <div class="page-body" id="page-waivers">
            <div class="mb-3">
                <h5 style="font-weight:800;margin:0;">Waivers</h5>
                <p style="color:var(--text-muted);font-size:.85rem;margin:0;">View your consent and waiver documents</p>
            </div>
            <div id="waiversList" class="row g-2">
                <div class="col-12">
                    <div class="empty-state"><i class="fa-solid fa-pen-to-square"></i>
                        <p>No waivers on file.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── PROFILE PAGE ── -->
        <div class="page-body" id="page-profile">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h5 style="font-weight:800;margin:0;">My Profile</h5>
                    <p style="color:var(--text-muted);font-size:.85rem;margin:0;">Keep your health information up to date</p>
                </div>
                <div style="display:flex;gap:.5rem;">
                    <button class="btn-cancel-sm" onclick="loadProfile()">Discard</button>
                    <button class="btn-primary-sm" onclick="saveProfile()">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                    </button>
                </div>
            </div>

            <div class="row g-3">

                <!-- ── LEFT PANEL ── -->
                <div class="col-lg-3">
                    <div class="content-card mb-3">
                        <div class="card-body-pad" style="text-align:center;">
                            <div class="avatar-circle mx-auto mb-3" style="width:72px;height:72px;font-size:1.75rem;" id="profileAvatar">—</div>
                            <div id="profileFullName" style="font-weight:800;font-size:1rem;color:var(--text-dark);">—</div>
                            <div id="profileEmail" style="font-size:.82rem;color:var(--text-muted);margin-top:.2rem;">—</div>
                            <div id="profileContact" style="font-size:.82rem;color:var(--text-muted);margin-top:.1rem;">—</div>
                            <div id="profileComplete" style="margin-top:.85rem;"></div>
                        </div>
                    </div>

                    <!-- Section nav -->
                    <div class="content-card">
                        <div style="padding:.5rem 0;">
                            <a class="prof-nav-link active" href="#sec-basic" onclick="scrollToSection('sec-basic',this)"><i class="fa-solid fa-user fa-fw me-2"></i>Basic Info</a>
                            <a class="prof-nav-link" href="#sec-contact" onclick="scrollToSection('sec-contact',this)"><i class="fa-solid fa-address-book fa-fw me-2"></i>Contact Info</a>
                            <a class="prof-nav-link" href="#sec-dental" onclick="scrollToSection('sec-dental',this)"><i class="fa-solid fa-tooth fa-fw me-2"></i>Dental History</a>
                            <a class="prof-nav-link" href="#sec-medical" onclick="scrollToSection('sec-medical',this)"><i class="fa-solid fa-heart-pulse fa-fw me-2"></i>Medical Info</a>
                            <a class="prof-nav-link" href="#sec-questions" onclick="scrollToSection('sec-questions',this)"><i class="fa-solid fa-circle-question fa-fw me-2"></i>Medical Questions</a>
                            <a class="prof-nav-link" href="#sec-allergies" onclick="scrollToSection('sec-allergies',this)"><i class="fa-solid fa-triangle-exclamation fa-fw me-2"></i>Allergies</a>
                            <a class="prof-nav-link" href="#sec-vitals" onclick="scrollToSection('sec-vitals',this)"><i class="fa-solid fa-chart-line fa-fw me-2"></i>Vitals & Health</a>
                            <a class="prof-nav-link" href="#sec-conditions" onclick="scrollToSection('sec-conditions',this)"><i class="fa-solid fa-file-medical fa-fw me-2"></i>Medical Conditions</a>
                        </div>
                    </div>
                </div>

                <!-- ── RIGHT FORM ── -->
                <div class="col-lg-9">
                    <div id="profileFormScroll" style="display:flex;flex-direction:column;gap:1rem;">

                        <!-- ── 1. BASIC INFO ── -->
                        <div class="prof-section content-card" id="sec-basic">
                            <div class="prof-section-header" onclick="toggleSection(this)">
                                <div style="display:flex;align-items:center;gap:.65rem;">
                                    <div class="prof-sec-icon blue"><i class="fa-solid fa-user"></i></div>
                                    <div>
                                        <div class="prof-sec-title">Basic Information</div>
                                        <div class="prof-sec-sub">Name, birthdate, gender, civil status</div>
                                    </div>
                                </div>
                                <i class="fa-solid fa-chevron-up prof-chevron"></i>
                            </div>
                            <div class="prof-section-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="pf-label">First Name <span class="req-star">*</span></label>
                                        <input type="text" id="pf_first" class="pf-input" placeholder="Juan">
                                        <div class="pf-err" id="err_pf_first"></div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="pf-label">Last Name <span class="req-star">*</span></label>
                                        <input type="text" id="pf_last" class="pf-input" placeholder="Dela Cruz">
                                        <div class="pf-err" id="err_pf_last"></div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="pf-label">Middle Name</label>
                                        <input type="text" id="pf_middle" class="pf-input" placeholder="Santos">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="pf-label">Suffix</label>
                                        <select id="pf_suffix" class="pf-select">
                                            <option value="">None</option>
                                            <option>Jr.</option>
                                            <option>Sr.</option>
                                            <option>II</option>
                                            <option>III</option>
                                            <option>IV</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="pf-label">Birthdate <span class="req-star">*</span></label>
                                        <input type="date" id="pf_birthdate" class="pf-input" onchange="calcAge()">
                                        <div class="pf-err" id="err_pf_birthdate"></div>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="pf-label">Age</label>
                                        <input type="text" id="pf_age" class="pf-input" readonly style="background:var(--bg);font-weight:700;color:var(--primary);" placeholder="—">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="pf-label">Gender</label>
                                        <select id="pf_gender" class="pf-select" onchange="checkGenderConditions()">
                                            <option value="">— Select —</option>
                                            <option>Male</option>
                                            <option>Female</option>
                                            <option>Prefer not to say</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="pf-label">Civil Status</label>
                                        <select id="pf_civil_status" class="pf-select">
                                            <option value="">— Select —</option>
                                            <option>Single</option>
                                            <option>Married</option>
                                            <option>Widowed</option>
                                            <option>Separated</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="pf-label">Nationality</label>
                                        <input type="text" id="pf_nationality" class="pf-input" placeholder="Filipino">
                                    </div>
                                </div>

                                <!-- ── For Minors (shown if age < 18) ── -->
                                <div class="col-12" id="guardianBlock" style="display:none;">
                                    <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:.85rem 1rem;">
                                        <div style="font-size:.82rem;font-weight:700;color:#92400e;margin-bottom:.75rem;">
                                            <i class="fa-solid fa-child me-1"></i> Parent / Guardian Information
                                            <span style="font-size:.72rem;font-weight:500;color:#b45309;margin-left:.4rem;">(Required for patients under 18)</span>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <label class="pf-label">Guardian Full Name</label>
                                                <input type="text" id="pf_guardian_name" class="pf-input" placeholder="Full name of parent or guardian">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="pf-label">Relationship to Patient</label>
                                                <select id="pf_guardian_relation" class="pf-select">
                                                    <option value="">— Select —</option>
                                                    <option>Mother</option>
                                                    <option>Father</option>
                                                    <option>Legal Guardian</option>
                                                    <option>Grandparent</option>
                                                    <option>Sibling</option>
                                                    <option>Other</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── 2. CONTACT INFO ── -->
                        <div class="prof-section content-card" id="sec-contact">
                            <div class="prof-section-header" onclick="toggleSection(this)">
                                <div style="display:flex;align-items:center;gap:.65rem;">
                                    <div class="prof-sec-icon green"><i class="fa-solid fa-address-book"></i></div>
                                    <div>
                                        <div class="prof-sec-title">Contact Information</div>
                                        <div class="prof-sec-sub">Email, phone, address</div>
                                    </div>
                                </div>
                                <i class="fa-solid fa-chevron-up prof-chevron"></i>
                            </div>
                            <div class="prof-section-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="pf-label">Email Address</label>
                                        <input type="email" id="pf_email" class="pf-input" readonly style="background:var(--bg);">
                                        <div style="font-size:.72rem;color:var(--text-muted);margin-top:.2rem;">Email cannot be changed here.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="pf-label">Contact Number</label>
                                        <input type="text" id="pf_contact" class="pf-input" readonly style="background:var(--bg);">
                                        <div style="font-size:.72rem;color:var(--text-muted);margin-top:.2rem;">Contact number cannot be changed here.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="pf-label">Home Phone</label>
                                        <input type="text" id="pf_home_phone" class="pf-input" placeholder="(02) XXXX-XXXX">
                                    </div>
                                    <div class="col-12">
                                        <label class="pf-label">Home Address</label>
                                        <input type="text" id="pf_address" class="pf-input" placeholder="Street, Barangay">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="pf-label">City / Municipality</label>
                                        <input type="text" id="pf_city" class="pf-input" placeholder="Quezon City">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="pf-label">Province</label>
                                        <input type="text" id="pf_province" class="pf-input" placeholder="Metro Manila">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="pf-label">ZIP Code</label>
                                        <input type="text" id="pf_zip" class="pf-input" placeholder="1100" maxlength="6">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── 4. CONSULTATION ── -->
                        <div class="prof-section content-card" id="sec-consult">
                            <div class="prof-section-header" onclick="toggleSection(this)">
                                <div style="display:flex;align-items:center;gap:.65rem;">
                                    <div class="prof-sec-icon blue"><i class="fa-solid fa-stethoscope"></i></div>
                                    <div>
                                        <div class="prof-sec-title">Consultation</div>
                                        <div class="prof-sec-sub">Reason for visit, referral source</div>
                                    </div>
                                </div>
                                <i class="fa-solid fa-chevron-up prof-chevron"></i>
                            </div>
                            <div class="prof-section-body">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="pf-label">Chief Complaint / Reason for Visit</label>
                                        <textarea id="pf_chief_complaint" class="pf-input" rows="2" placeholder="Describe your main dental concern…" style="resize:vertical;"></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="pf-label">How did you hear about us?</label>
                                        <select id="pf_referral_source" class="pf-select">
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
                                        <label class="pf-label">Referred by (name)</label>
                                        <input type="text" id="pf_referred_by" class="pf-input" placeholder="Name of referrer (if any)">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── 5. DENTAL HISTORY ── -->
                        <div class="prof-section content-card" id="sec-dental">
                            <div class="prof-section-header" onclick="toggleSection(this)">
                                <div style="display:flex;align-items:center;gap:.65rem;">
                                    <div class="prof-sec-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fa-solid fa-tooth"></i></div>
                                    <div>
                                        <div class="prof-sec-title">Dental History</div>
                                        <div class="prof-sec-sub">Previous dentist, last visit, dental concerns</div>
                                    </div>
                                </div>
                                <i class="fa-solid fa-chevron-up prof-chevron"></i>
                            </div>
                            <div class="prof-section-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="pf-label">Previous Dentist</label>
                                        <input type="text" id="pf_prev_dentist" class="pf-input" placeholder="Dr. Name">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="pf-label">Last Dental Visit</label>
                                        <input type="date" id="pf_last_visit" class="pf-input">
                                    </div>
                                    <div class="col-12">
                                        <label class="pf-label">Dental Concerns / Notes</label>
                                        <textarea id="pf_dental_notes" class="pf-input" rows="2" placeholder="Any specific dental concerns or notes…" style="resize:vertical;"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── 6. MEDICAL INFO ── -->
                        <div class="prof-section content-card" id="sec-medical">
                            <div class="prof-section-header" onclick="toggleSection(this)">
                                <div style="display:flex;align-items:center;gap:.65rem;">
                                    <div class="prof-sec-icon red"><i class="fa-solid fa-heart-pulse"></i></div>
                                    <div>
                                        <div class="prof-sec-title">Medical Information</div>
                                        <div class="prof-sec-sub">Physician, medications, blood type</div>
                                    </div>
                                </div>
                                <i class="fa-solid fa-chevron-up prof-chevron"></i>
                            </div>
                            <div class="prof-section-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="pf-label">Primary Physician</label>
                                        <input type="text" id="pf_physician" class="pf-input" placeholder="Dr. Name">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="pf-label">Specialty (if applicable)</label>
                                        <input type="text" id="pf_physician_specialty" class="pf-input" placeholder="e.g. Cardiologist, Internist">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="pf-label">Office Number</label>
                                        <input type="text" id="pf_physician_office_number" class="pf-input" placeholder="(02) XXXX-XXXX">
                                    </div>
                                    <div class="col-12">
                                        <label class="pf-label">Office Address</label>
                                        <input type="text" id="pf_physician_office_address" class="pf-input" placeholder="Clinic / Hospital address">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── 7. MEDICAL QUESTIONS ── -->
                        <div class="prof-section content-card" id="sec-questions">
                            <div class="prof-section-header" onclick="toggleSection(this)">
                                <div style="display:flex;align-items:center;gap:.65rem;">
                                    <div class="prof-sec-icon" style="background:#f0f9ff;color:#0369a1;"><i class="fa-solid fa-circle-question"></i></div>
                                    <div>
                                        <div class="prof-sec-title">Medical Questions</div>
                                        <div class="prof-sec-sub">Health history questionnaire</div>
                                    </div>
                                </div>
                                <i class="fa-solid fa-chevron-up prof-chevron"></i>
                            </div>
                            <div class="prof-section-body">
                                <div class="yn-grid">

                                    <div class="yn-row">
                                        <span class="yn-label">Are you in good health?</span>
                                        <div class="yn-btns">
                                            <label class="yn-opt"><input type="radio" name="q_good_health" value="yes"> Yes</label>
                                            <label class="yn-opt"><input type="radio" name="q_good_health" value="no"> No</label>
                                        </div>
                                    </div>

                                    <div class="yn-row yn-row-wrap">
                                        <div style="display:flex;align-items:center;justify-content:space-between;width:100%;gap:1rem;">
                                            <span class="yn-label">Are you under medical treatment now?</span>
                                            <div class="yn-btns">
                                                <label class="yn-opt"><input type="radio" name="q_under_treatment" value="yes" onchange="toggleFollowUp('fu_treatment','yes',this)"> Yes</label>
                                                <label class="yn-opt"><input type="radio" name="q_under_treatment" value="no" onchange="toggleFollowUp('fu_treatment','yes',this)"> No</label>
                                            </div>
                                        </div>
                                        <div class="fu-field" id="fu_treatment" style="display:none;width:100%;margin-top:.5rem;">
                                            <input type="text" id="pf_treatment_condition" class="pf-input" placeholder="What is the condition being treated?">
                                        </div>
                                    </div>

                                    <div class="yn-row yn-row-wrap">
                                        <div style="display:flex;align-items:center;justify-content:space-between;width:100%;gap:1rem;">
                                            <span class="yn-label">Have you ever had a serious illness or surgical operation?</span>
                                            <div class="yn-btns">
                                                <label class="yn-opt"><input type="radio" name="q_serious_illness" value="yes" onchange="toggleFollowUp('fu_illness','yes',this)"> Yes</label>
                                                <label class="yn-opt"><input type="radio" name="q_serious_illness" value="no" onchange="toggleFollowUp('fu_illness','yes',this)"> No</label>
                                            </div>
                                        </div>
                                        <div class="fu-field" id="fu_illness" style="display:none;width:100%;margin-top:.5rem;">
                                            <input type="text" id="pf_illness_detail" class="pf-input" placeholder="What illness or operation?">
                                        </div>
                                    </div>

                                    <div class="yn-row yn-row-wrap">
                                        <div style="display:flex;align-items:center;justify-content:space-between;width:100%;gap:1rem;">
                                            <span class="yn-label">Have you ever been hospitalized?</span>
                                            <div class="yn-btns">
                                                <label class="yn-opt"><input type="radio" name="q_hospitalized" value="yes" onchange="toggleFollowUp('fu_hospitalized','yes',this)"> Yes</label>
                                                <label class="yn-opt"><input type="radio" name="q_hospitalized" value="no" onchange="toggleFollowUp('fu_hospitalized','yes',this)"> No</label>
                                            </div>
                                        </div>
                                        <div class="fu-field" id="fu_hospitalized" style="display:none;width:100%;margin-top:.5rem;">
                                            <input type="text" id="pf_hospitalized_detail" class="pf-input" placeholder="When and why?">
                                        </div>
                                    </div>

                                    <div class="yn-row yn-row-wrap">
                                        <div style="display:flex;align-items:center;justify-content:space-between;width:100%;gap:1rem;">
                                            <span class="yn-label">Are you taking any prescription or non-prescription medication?</span>
                                            <div class="yn-btns">
                                                <label class="yn-opt"><input type="radio" name="q_taking_meds" value="yes" onchange="toggleFollowUp('fu_meds','yes',this)"> Yes</label>
                                                <label class="yn-opt"><input type="radio" name="q_taking_meds" value="no" onchange="toggleFollowUp('fu_meds','yes',this)"> No</label>
                                            </div>
                                        </div>
                                        <div class="fu-field" id="fu_meds" style="display:none;width:100%;margin-top:.5rem;">
                                            <input type="text" id="pf_meds_detail" class="pf-input" placeholder="Please specify medications">
                                        </div>
                                    </div>

                                    <div class="yn-row">
                                        <span class="yn-label">Do you use tobacco products?</span>
                                        <div class="yn-btns">
                                            <label class="yn-opt"><input type="radio" name="q_tobacco" value="yes"> Yes</label>
                                            <label class="yn-opt"><input type="radio" name="q_tobacco" value="no"> No</label>
                                        </div>
                                    </div>

                                    <div class="yn-row">
                                        <span class="yn-label">Do you use alcohol, cocaine, or other dangerous drugs?</span>
                                        <div class="yn-btns">
                                            <label class="yn-opt"><input type="radio" name="q_dangerous_drugs" value="yes"> Yes</label>
                                            <label class="yn-opt"><input type="radio" name="q_dangerous_drugs" value="no"> No</label>
                                        </div>
                                    </div>

                                    <div id="womenQuestionsBlock" style="display:none;width:100%;">
                                        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9333ea;padding:.75rem .25rem .35rem;border-top:1px solid var(--border);margin-top:.25rem;">
                                            <i class="fa-solid fa-venus me-1"></i> For Women Only
                                        </div>
                                        <div class="yn-row">
                                            <span class="yn-label">Are you pregnant?</span>
                                            <div class="yn-btns">
                                                <label class="yn-opt"><input type="radio" name="q_pregnant" value="yes"> Yes</label>
                                                <label class="yn-opt"><input type="radio" name="q_pregnant" value="no"> No</label>
                                            </div>
                                        </div>
                                        <div class="yn-row">
                                            <span class="yn-label">Are you nursing?</span>
                                            <div class="yn-btns">
                                                <label class="yn-opt"><input type="radio" name="q_nursing" value="yes"> Yes</label>
                                                <label class="yn-opt"><input type="radio" name="q_nursing" value="no"> No</label>
                                            </div>
                                        </div>
                                        <div class="yn-row" style="border-bottom:none;">
                                            <span class="yn-label">Are you taking birth control pills?</span>
                                            <div class="yn-btns">
                                                <label class="yn-opt"><input type="radio" name="q_birth_control" value="yes"> Yes</label>
                                                <label class="yn-opt"><input type="radio" name="q_birth_control" value="no"> No</label>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>

                        <!-- ── 8. ALLERGIES ── -->
                        <div class="prof-section content-card" id="sec-allergies">
                            <div class="prof-section-header" onclick="toggleSection(this)">
                                <div style="display:flex;align-items:center;gap:.65rem;">
                                    <div class="prof-sec-icon" style="background:#fff7ed;color:#c2410c;"><i class="fa-solid fa-triangle-exclamation"></i></div>
                                    <div>
                                        <div class="prof-sec-title">Allergies</div>
                                        <div class="prof-sec-sub">Drug, food, and material allergies</div>
                                    </div>
                                </div>
                                <i class="fa-solid fa-chevron-up prof-chevron"></i>
                            </div>
                            <div class="prof-section-body">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="pf-label">Known Allergies</label>
                                        <div class="check-grid">
                                            <?php foreach (['Penicillin', 'Aspirin', 'Ibuprofen', 'Latex', 'Metals', 'Local Anesthetics', 'Codeine', 'Sulfa Drugs', 'Iodine', 'Contrast Dye'] as $allergy): ?>
                                                <label class="check-item">
                                                    <input type="checkbox" class="pf-checkbox" name="allergies" value="<?= $allergy ?>"> <?= $allergy ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="pf-label">Other Allergies (specify)</label>
                                        <input type="text" id="pf_other_allergies" class="pf-input" placeholder="List any other allergies not mentioned above…">
                                    </div>
                                    <div class="col-12">
                                        <label class="pf-label">Allergy Reaction Description</label>
                                        <textarea id="pf_allergy_reaction" class="pf-input" rows="2" placeholder="Describe your typical allergic reaction…" style="resize:vertical;"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── 9. VITALS & HEALTH DATA ── -->
                        <div class="prof-section content-card" id="sec-vitals">
                            <div class="prof-section-header" onclick="toggleSection(this)">
                                <div style="display:flex;align-items:center;gap:.65rem;">
                                    <div class="prof-sec-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fa-solid fa-chart-line"></i></div>
                                    <div>
                                        <div class="prof-sec-title">Vitals & Additional Health Data</div>
                                        <div class="prof-sec-sub">Blood pressure, sugar levels, emergency contact</div>
                                    </div>
                                </div>
                                <i class="fa-solid fa-chevron-up prof-chevron"></i>
                            </div>
                            <div class="prof-section-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="pf-label">Blood Pressure</label>
                                        <input type="text" id="pf_blood_pressure" class="pf-input" placeholder="e.g. 120/80">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="pf-label">Blood Sugar (mg/dL)</label>
                                        <input type="number" id="pf_blood_sugar" class="pf-input" placeholder="e.g. 90">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="pf-label">Pulse Rate (bpm)</label>
                                        <input type="number" id="pf_pulse" class="pf-input" placeholder="e.g. 72">
                                    </div>
                                    <div class="col-12">
                                        <hr style="border-color:var(--border);margin:.25rem 0;">
                                    </div>
                                    <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:0 .75rem;">Emergency Contact</div>
                                    <div class="col-md-4">
                                        <label class="pf-label">Contact Name</label>
                                        <input type="text" id="pf_emergency_name" class="pf-input" placeholder="Full name">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="pf-label">Relationship</label>
                                        <input type="text" id="pf_emergency_relation" class="pf-input" placeholder="e.g. Spouse">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="pf-label">Emergency Phone</label>
                                        <input type="text" id="pf_emergency_phone" class="pf-input" placeholder="+63 XXX XXX XXXX">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── 10. MEDICAL CONDITIONS ── -->
                        <div class="prof-section content-card" id="sec-conditions">
                            <div class="prof-section-header" onclick="toggleSection(this)">
                                <div style="display:flex;align-items:center;gap:.65rem;">
                                    <div class="prof-sec-icon red"><i class="fa-solid fa-file-medical"></i></div>
                                    <div>
                                        <div class="prof-sec-title">Medical Conditions</div>
                                        <div class="prof-sec-sub">Check all that apply</div>
                                    </div>
                                </div>
                                <i class="fa-solid fa-chevron-up prof-chevron"></i>
                            </div>
                            <div class="prof-section-body">
                                <div class="check-grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));">
                                    <?php
                                    $conditions = [
                                        'Hypertension', 'Diabetes (Type 1)', 'Diabetes (Type 2)', 'Heart Disease',
                                        'Stroke', 'Asthma', 'Tuberculosis', 'Hepatitis B', 'Hepatitis C',
                                        'Kidney Disease', 'Thyroid Disease', 'Cancer', 'Epilepsy / Seizures',
                                        'Arthritis', 'Osteoporosis', 'Anemia', 'HIV / AIDS', 'Lupus',
                                        'Psoriasis', 'GERD / Acid Reflux', 'Anxiety / Depression',
                                        'Alzheimer\'s', 'Parkinson\'s',
                                    ];
                                    foreach ($conditions as $cond): ?>
                                        <label class="check-item">
                                            <input type="checkbox" class="pf-checkbox" name="conditions" value="<?= htmlspecialchars($cond) ?>"> <?= htmlspecialchars($cond) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="row g-3 mt-1">
                                    <div class="col-12">
                                        <label class="pf-label">Other Conditions (specify)</label>
                                        <input type="text" id="pf_other_conditions" class="pf-input" placeholder="Any other medical conditions not listed above…">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Save bar -->
                        <div style="display:flex;justify-content:flex-end;gap:.5rem;padding-bottom:.5rem;">
                            <button class="btn-cancel-sm" onclick="loadProfile()">Discard Changes</button>
                            <button class="btn-primary-sm" onclick="saveProfile()">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Save All Changes
                            </button>
                        </div>

                    </div>
                </div>
            </div>

        </div><!-- end page-profile -->

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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- PHP → JS data bridge -->
    <script>
        const API = '<?= $API_PATH ?>';
    </script>
    <script src="assets/js/portal-dashboard.js"></script>

    <!-- ── Surface-based Dental Chart Renderer
         Loaded AFTER portal-dashboard.js so it overwrites loadDentalChart()
         in the global scope — no MutationObserver needed.
    ── -->
    <script>
    (function () {

        /* ── Condition → fill / stroke / text colour ── */
        const COND = {
            healthy:     { fill: '#ffffff', stroke: '#86efac', text: '#64748b' },
            filled:      { fill: '#3b82f6', stroke: '#2563eb', text: '#ffffff' },
            decay:       { fill: '#ef4444', stroke: '#dc2626', text: '#ffffff' },
            cavity:      { fill: '#ef4444', stroke: '#dc2626', text: '#ffffff' }, // alias
            impacted:    { fill: '#a855f7', stroke: '#9333ea', text: '#ffffff' },
            missing:     { fill: '#9ca3af', stroke: '#6b7280', text: '#ffffff' },
            crown:       { fill: '#eab308', stroke: '#ca8a04', text: '#1e293b' },
            impacted_ne: { fill: '#22c55e', stroke: '#16a34a', text: '#ffffff' },
            none:        { fill: '#f8fafc', stroke: '#e2e8f0', text: '#94a3b8' },
        };

        /* ── 4 ring wedges (outer r=28, inner r=12, centre 40,40) ── */
        const RING = [
            { key:'b', label:'B', lx:40, ly:19,
              d:'M 20.2,20.2 A 28,28 0 0,1 59.8,20.2 L 48.5,31.5 A 12,12 0 0,0 31.5,31.5 Z' },
            { key:'m', label:'M', lx:57, ly:40,
              d:'M 59.8,20.2 A 28,28 0 0,1 59.8,59.8 L 48.5,48.5 A 12,12 0 0,0 48.5,31.5 Z' },
            { key:'l', label:'L', lx:40, ly:61,
              d:'M 59.8,59.8 A 28,28 0 0,1 20.2,59.8 L 31.5,48.5 A 12,12 0 0,0 48.5,48.5 Z' },
            { key:'d', label:'D', lx:23, ly:40,
              d:'M 20.2,59.8 A 28,28 0 0,1 20.2,20.2 L 31.5,31.5 A 12,12 0 0,0 31.5,48.5 Z' },
        ];

        /* ── FDI order — must match portal-dashboard.js ── */
        const FDI_UPPER = [1,2,3,4,5,6,7,8, 9,10,11,12,13,14,15,16];
        const FDI_LOWER = [17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32];

        const FF = "'Plus Jakarta Sans',sans-serif";

        function col(entry, key) {
            if (!entry) return COND.none;
            if (entry.surfaces && entry.surfaces[key]) return COND[entry.surfaces[key]] || COND.none;
            // Map portal-dashboard.js condition field → status key
            const raw = (entry.condition || entry.status || '').toLowerCase();
            return COND[raw] || COND.none;
        }

        function buildSVG(num, entry) {
            const wedges = RING.map(({key, label, lx, ly, d}) => {
                const c = col(entry, key);
                return `<path d="${d}" fill="${c.fill}" stroke="${c.stroke}" stroke-width="1.2"/>` +
                       `<text x="${lx}" y="${ly}" text-anchor="middle" dominant-baseline="middle" ` +
                       `fill="${c.text}" font-size="8" font-weight="600" font-family=${FF}>${label}</text>`;
            }).join('');
            const oc = col(entry, 'o');
            return `<div class="tooth-box">` +
                   `<svg class="tooth-svg" viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg">` +
                   wedges +
                   `<circle cx="40" cy="40" r="12" fill="${oc.fill}" stroke="${oc.stroke}" stroke-width="1.2"/>` +
                   `<text x="40" y="40" text-anchor="middle" dominant-baseline="middle" ` +
                   `fill="${oc.text}" font-size="8" font-weight="600" font-family=${FF}>O</text>` +
                   `</svg><div class="tooth-num">${num}</div></div>`;
        }

        function renderGrid(chartMap) {
            const upper = document.getElementById('upperTeeth');
            const lower = document.getElementById('lowerTeeth');
            if (upper) upper.innerHTML = FDI_UPPER.map(n => buildSVG(n, chartMap[n])).join('');
            if (lower) lower.innerHTML = FDI_LOWER.map(n => buildSVG(n, chartMap[n])).join('');
        }

        /* ── Override loadDentalChart() from portal-dashboard.js ──
           This script loads after portal-dashboard.js so assigning to
           window.loadDentalChart replaces the global reference that
           showPage() calls via:  if (page === 'dentalchart') loadDentalChart();
        ── */
        window.loadDentalChart = async function () {
            // Show blank SVG grid instantly (no flicker)
            renderGrid({});

            // Fetch from the same API endpoint the old function used
            const data = await php(API, { action: 'get_dental_chart' });
            const chartMap = {};
            (data.chart || []).forEach(c => {
                // portal_auth.php returns tooth_number + condition
                chartMap[c.tooth_number] = c;
            });
            renderGrid(chartMap);
        };

    })();
    </script>

</body>
</html>