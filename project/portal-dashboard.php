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

        <!-- Custom CSS -->
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

                <div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
                    <button class="appt-filter-btn active" data-status="all" onclick="filterAppts('all',this)">All</button>
                    <button class="appt-filter-btn" data-status="pending" onclick="filterAppts('pending',this)">Pending</button>
                    <button class="appt-filter-btn" data-status="confirmed" onclick="filterAppts('confirmed',this)">Confirmed</button>
                    <button class="appt-filter-btn" data-status="completed" onclick="filterAppts('completed',this)">Completed</button>
                    <button class="appt-filter-btn" data-status="cancelled" onclick="filterAppts('cancelled',this)">Cancelled</button>
                </div>
                <style>
                    .appt-filter-btn {
                        border: 1.5px solid var(--border);
                        background: #fff;
                        color: var(--text-muted);
                        font-family: inherit;
                        font-size: .8rem;
                        font-weight: 600;
                        padding: .4rem .9rem;
                        border-radius: 50px;
                        cursor: pointer;
                        transition: all .15s;
                    }

                    .appt-filter-btn:hover,
                    .appt-filter-btn.active {
                        background: var(--primary);
                        color: #fff;
                        border-color: var(--primary);
                    }
                </style>

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
                    <div class="card-body-pad">
                        <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem;">
                            <span style="font-size:.78rem;font-weight:600;color:var(--text-muted);">Legend:</span>
                            <span style="font-size:.78rem;display:flex;align-items:center;gap:.35rem;"><span style="width:12px;height:12px;border-radius:3px;background:#f0fdf4;border:1.5px solid #bbf7d0;display:inline-block;"></span>Healthy</span>
                            <span style="font-size:.78rem;display:flex;align-items:center;gap:.35rem;"><span style="width:12px;height:12px;border-radius:3px;background:#fffbeb;border:1.5px solid #fde68a;display:inline-block;"></span>Cavity</span>
                            <span style="font-size:.78rem;display:flex;align-items:center;gap:.35rem;"><span style="width:12px;height:12px;border-radius:3px;background:var(--primary-light);border:1.5px solid #bfdbfe;display:inline-block;"></span>Filled</span>
                            <span style="font-size:.78rem;display:flex;align-items:center;gap:.35rem;"><span style="width:12px;height:12px;border-radius:3px;background:var(--red-light);border:1.5px solid #fecaca;display:inline-block;"></span>Missing</span>
                            <span style="font-size:.78rem;display:flex;align-items:center;gap:.35rem;"><span style="width:12px;height:12px;border-radius:3px;background:#faf5ff;border:1.5px solid #e9d5ff;display:inline-block;"></span>Crown</span>
                            <span style="font-size:.78rem;display:flex;align-items:center;gap:.35rem;"><span style="width:12px;height:12px;border-radius:3px;background:#fff;border:1.5px solid var(--border);display:inline-block;"></span>No data</span>
                        </div>
                        <p class="tooth-chart-label">Upper Teeth (18 → 11 | 21 → 28)</p>
                        <div class="tooth-grid mb-3" id="upperTeeth"></div>
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

                                        <!-- Q1 -->
                                        <div class="yn-row">
                                            <span class="yn-label">Are you in good health?</span>
                                            <div class="yn-btns">
                                                <label class="yn-opt"><input type="radio" name="q_good_health" value="yes"> Yes</label>
                                                <label class="yn-opt"><input type="radio" name="q_good_health" value="no"> No</label>
                                            </div>
                                        </div>

                                        <!-- Q2 -->
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

                                        <!-- Q3 -->
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

                                        <!-- Q4 -->
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

                                        <!-- Q5 -->
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

                                        <!-- Q6 -->
                                        <div class="yn-row">
                                            <span class="yn-label">Do you use tobacco products?</span>
                                            <div class="yn-btns">
                                                <label class="yn-opt"><input type="radio" name="q_tobacco" value="yes"> Yes</label>
                                                <label class="yn-opt"><input type="radio" name="q_tobacco" value="no"> No</label>
                                            </div>
                                        </div>

                                        <!-- Q7 -->
                                        <div class="yn-row">
                                            <span class="yn-label">Do you use alcohol, cocaine, or other dangerous drugs?</span>
                                            <div class="yn-btns">
                                                <label class="yn-opt"><input type="radio" name="q_dangerous_drugs" value="yes"> Yes</label>
                                                <label class="yn-opt"><input type="radio" name="q_dangerous_drugs" value="no"> No</label>
                                            </div>
                                        </div>

                                        <!-- ── FOR WOMEN ONLY ── -->
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
                                            'Alzheimer\'s',
                                            'Parkinson\'s',
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
            <script>
                // ── Constants & state ─────────────────────────────────────────────────────────
                // ── Constants & state ─────────────────────────────────────────────────────────
                const API = '<?= $API_PATH ?>';

                let allAppointments = [];
                let currentCancelId = null;
                let accountData = {};
                let isProfileComplete = false; // single source of truth for the booking gate

                // Book-appointment gate — always clickable, but guarded
                function handleBookAppt() {
                    if (!isProfileComplete) {
                        Swal.fire({
                            icon: 'info',
                            title: 'Profile Incomplete',
                            html: `To book an appointment, please complete the following in <strong>My Profile</strong>:
                   <ul style="text-align:left;margin-top:.75rem;font-size:.9rem;line-height:2;">
                     <li><i class="fa-solid fa-user" style="width:18px;"></i> <strong>Basic Info</strong> — Name, Birthdate, Gender</li>
                     <li><i class="fa-solid fa-address-book" style="width:18px;"></i> <strong>Contact Info</strong> — Address &amp; City</li>
                     <li><i class="fa-solid fa-circle-question" style="width:18px;"></i> <strong>Medical Questions</strong> — Answer Yes/No questions</li>
                   </ul>`,
                            confirmButtonColor: '#2563eb',
                            confirmButtonText: 'Complete My Profile',
                            showCancelButton: true,
                            cancelButtonText: 'Later',
                        }).then(r => {
                            if (r.isConfirmed) showPage('profile', null);
                        });
                        return;
                    }
                    window.location.href = 'appointmentWeb-Scheduling.php';
                }

                function setBookBtnState(complete) {
                    ['bookApptBtn', 'bookApptBtnAlt'].forEach(id => {
                        const btn = document.getElementById(id);
                        if (!btn) return;
                        // Always keep pointer-events so the SweetAlert fires on click
                        btn.style.opacity = complete ? '1' : '0.55';
                        btn.style.cursor = complete ? 'pointer' : 'not-allowed';
                        btn.style.pointerEvents = 'auto';
                        btn.title = complete ? '' : 'Complete your profile first';
                    });
                }
                // Strips internal placeholder contact numbers from display
                function displayContact(val) {
                    if (!val || /^__PORTAL_\d+__$/.test(val) || /^PORTAL-\d+$/.test(val)) return '—';
                    return val;
                }

                // ── Navigation ────────────────────────────────────────────────────────────────
                function showPage(page, linkEl) {
                    document.querySelectorAll('.page-body').forEach(p => p.classList.remove('active'));
                    document.getElementById('page-' + page).classList.add('active');

                    document.querySelectorAll('.nav-link-item').forEach(l => l.classList.remove('active'));
                    if (linkEl) {
                        linkEl.classList.add('active');
                    } else {
                        const match = Array.from(document.querySelectorAll('.nav-link-item'))
                            .find(l => l.getAttribute('onclick')?.includes(`'${page}'`));
                        if (match) match.classList.add('active');
                    }

                    const titles = {
                        dashboard: 'Dashboard',
                        appointments: 'My Appointments',
                        treatments: 'Treatment History',
                        dentalchart: 'Dental Chart',
                        billing: 'Billing & Payments',
                        files: 'My Files',
                        waivers: 'Waivers',
                        profile: 'My Profile',
                    };
                    document.getElementById('topbarTitle').textContent = titles[page] || page;
                    document.getElementById('topbarBreadcrumb').textContent = `My Portal › ${titles[page] || page}`;

                    if (page === 'appointments') loadAppointments();
                    if (page === 'treatments') loadTreatments();
                    if (page === 'dentalchart') loadDentalChart();
                    if (page === 'billing') loadBilling();
                    if (page === 'files') loadFiles();
                    if (page === 'waivers') loadWaivers();
                    if (page === 'profile') loadProfile();
                    closeSidebar();
                }

                function openSidebar() {
                    document.getElementById('sidebar').classList.add('open');
                    document.getElementById('sidebarOverlay').classList.add('show');
                }

                function closeSidebar() {
                    document.getElementById('sidebar').classList.remove('open');
                    document.getElementById('sidebarOverlay').classList.remove('show');
                }

                // ── Helpers ───────────────────────────────────────────────────────────────────
                function php(path, body) {
                    return fetch(path, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(body),
                    }).then(r => r.json());
                }

                function peso(v) {
                    return '₱' + parseFloat(v || 0).toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                }

                function fmtDate(d) {
                    if (!d) return '—';
                    return new Date(d).toLocaleDateString('en-PH', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric'
                    });
                }

                function fmtTime(t) {
                    if (!t) return '';
                    const [h, m] = t.split(':');
                    const dt = new Date();
                    dt.setHours(+h, +m);
                    return dt.toLocaleTimeString('en-US', {
                        hour: 'numeric',
                        minute: '2-digit'
                    });
                }

                function statusBadge(s) {
                    return `<span class="badge-status badge-${s || 'pending'}">${(s || 'pending').replace('_', ' ')}</span>`;
                }

                function payBadge(s) {
                    return `<span class="badge-status badge-${s || 'unpaid'}">${s || 'unpaid'}</span>`;
                }
                // ── Smooth scroll for any anchor links ────────────────────────────────
                document.querySelectorAll('a[href^="#"]').forEach(link => {
                    link.addEventListener('click', function(e) {
                        const targetId = this.getAttribute('href').slice(1);
                        if (!targetId) return;
                        const target = document.getElementById(targetId);
                        if (!target) return;
                        e.preventDefault();
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    });
                });
                // ── DASHBOARD ─────────────────────────────────────────────────────────────────
                async function loadDashboard() {
                    const data = await php(API, {
                        action: 'dashboard_summary'
                    });
                    if (!data.success) return;

                    const {
                        account,
                        stats,
                        upcoming,
                        recent_bills,
                        is_profile_complete
                    } = data;
                    accountData = account;

                    // Sidebar & welcome — use registered first_name from patient_accounts
                    const firstName = account.first_name || 'Patient';
                    const lastName = account.last_name || '';
                    const fullName = [firstName, lastName].filter(Boolean).join(' ');
                    const initial = firstName.charAt(0).toUpperCase();

                    document.getElementById('sidebarInitial').textContent = initial;
                    document.getElementById('sidebarName').textContent = fullName;
                    document.getElementById('welcomeName').textContent = firstName;

                    // Stats
                    document.getElementById('statUpcoming').textContent = stats.upcoming || 0;
                    document.getElementById('statCompleted').textContent = stats.completed || 0;
                    document.getElementById('statBalance').textContent = peso(stats.balance);
                    document.getElementById('statTreatments').textContent = stats.treatments || 0;

                    // Profile incomplete warning
                    isProfileComplete = !!is_profile_complete;
                    document.getElementById('profileIncompleteAlert').style.display = is_profile_complete ? 'none' : 'flex';
                    setBookBtnState(is_profile_complete);

                    // Upcoming appointments widget
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

                    // Recent bills widget
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

                // ── APPOINTMENTS ──────────────────────────────────────────────────────────────
                async function loadAppointments() {
                    const tbody = document.getElementById('apptTableBody');
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">Loading…</td></tr>';
                    const data = await php(API, {
                        action: 'get_appointments'
                    });
                    allAppointments = data.appointments || [];
                    renderApptTable(allAppointments);
                }

                function renderApptTable(list) {
                    const tbody = document.getElementById('apptTableBody');
                    if (!list.length) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">No appointments found.</td></tr>';
                        return;
                    }
                    tbody.innerHTML = list.map(a => `
            <tr>
                <td><span style="font-weight:700;font-size:.8rem;">${a.appointment_code}</span></td>
                <td>${fmtDate(a.appointment_date)}<br><span style="font-size:.75rem;color:var(--text-muted);">${fmtTime(a.appointment_time)}</span></td>
                <td>${a.branch_name || '—'}</td>
                <td>${a.service_name || 'Consultation'}</td>
                <td>${statusBadge(a.status)}</td>
                <td>
                    ${['pending', 'confirmed'].includes(a.status)
                        ? `<button onclick="openCancelModal(${a.appointment_id},'${a.appointment_code}')" style="background:none;border:1.5px solid #fecaca;border-radius:7px;padding:.3rem .75rem;font-size:.78rem;font-weight:700;color:var(--red);cursor:pointer;font-family:inherit;">Cancel</button>`
                        : '<span style="color:var(--text-muted);font-size:.8rem;">—</span>'}
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
                    const data = await php(API, {
                        action: 'cancel_appointment',
                        appointment_id: currentCancelId
                    });
                    bootstrap.Modal.getInstance(document.getElementById('cancelModal')).hide();
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Cancelled',
                            text: 'Your appointment has been cancelled.',
                            confirmButtonColor: '#2563eb',
                            timer: 2000,
                            showConfirmButton: false
                        });
                        loadAppointments();
                        loadDashboard();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.message || 'Could not cancel.',
                            confirmButtonColor: '#2563eb'
                        });
                    }
                }

                // ── TREATMENTS ────────────────────────────────────────────────────────────────
                async function loadTreatments() {
                    const tbody = document.getElementById('treatTableBody');
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4">Loading…</td></tr>';
                    const data = await php(API, {
                        action: 'get_treatments'
                    });
                    const list = data.treatments || [];
                    if (!list.length) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">No treatments on record.</td></tr>';
                        return;
                    }
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

                // ── DENTAL CHART ──────────────────────────────────────────────────────────────
                async function loadDentalChart() {
                    const data = await php(API, {
                        action: 'get_dental_chart'
                    });
                    const chartMap = {};
                    (data.chart || []).forEach(c => {
                        chartMap[c.tooth_number] = c;
                    });

                    const condClass = {
                        healthy: 'healthy',
                        cavity: 'cavity',
                        filled: 'filled',
                        missing: 'missing',
                        crown: 'crown'
                    };
                    const condEmoji = {
                        healthy: '🦷',
                        cavity: '🟡',
                        filled: '🔵',
                        missing: '✖',
                        crown: '👑'
                    };

                    function buildTooth(num) {
                        const entry = chartMap[num];
                        const cond = entry?.condition?.toLowerCase() || '';
                        const cls = condClass[cond] || '';
                        const emoji = condEmoji[cond] || '🦷';
                        const tip = entry ?
                            `${num}: ${entry.condition || 'Healthy'}${entry.notes ? ' — ' + entry.notes : ''}` :
                            `${num}: No data`;
                        return `<div class="tooth-cell"><div class="tooth-icon ${cls}" title="${tip}">${emoji}</div><span>${num}</span></div>`;
                    }

                    const upper = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
                    const lower = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];
                    document.getElementById('upperTeeth').innerHTML = upper.map(buildTooth).join('');
                    document.getElementById('lowerTeeth').innerHTML = lower.map(buildTooth).join('');
                }

                // ── BILLING ───────────────────────────────────────────────────────────────────
                async function loadBilling() {
                    const tbody = document.getElementById('billingTableBody');
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4">Loading…</td></tr>';
                    const data = await php(API, {
                        action: 'get_billing'
                    });
                    const list = data.invoices || [];
                    let totalBilled = 0,
                        totalPaid = 0;
                    if (!list.length) {
                        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4" style="color:var(--text-muted);">No invoices found.</td></tr>';
                        return;
                    }
                    tbody.innerHTML = list.map(i => {
                        totalBilled += parseFloat(i.total_amount || 0);
                        totalPaid += parseFloat(i.amount_paid || 0);
                        const bal = parseFloat(i.total_amount || 0) - parseFloat(i.amount_paid || 0);
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
                    document.getElementById('billTotal').textContent = peso(totalBilled);
                    document.getElementById('billPaid').textContent = peso(totalPaid);
                    document.getElementById('billBalance').textContent = peso(totalBilled - totalPaid);
                }

                // ── FILES ─────────────────────────────────────────────────────────────────────
                async function loadFiles() {
                    const grid = document.getElementById('filesGrid');
                    grid.innerHTML = '<div class="col-12 text-center py-4" style="color:var(--text-muted);">Loading…</div>';
                    const data = await php(API, {
                        action: 'get_files'
                    });
                    const list = data.files || [];
                    if (!list.length) {
                        grid.innerHTML = '<div class="col-12"><div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>No files uploaded yet.</p></div></div>';
                        return;
                    }
                    const icons = {
                        'X-ray': 'fa-radiation',
                        'Referral': 'fa-file-medical',
                        'Certificate': 'fa-certificate'
                    };
                    grid.innerHTML = list.map(f => `
            <div class="col-md-6">
                <div class="file-card">
                    <div class="file-icon"><i class="fa-solid ${icons[f.file_type] || 'fa-file'}"></i></div>
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

                // ── WAIVERS ───────────────────────────────────────────────────────────────────
                async function loadWaivers() {
                    const listEl = document.getElementById('waiversList');
                    const data = await php(API, {
                        action: 'get_waivers'
                    });
                    const list = data.waivers || [];
                    if (!list.length) {
                        listEl.innerHTML = '<div class="col-12"><div class="empty-state"><i class="fa-solid fa-pen-to-square"></i><p>No waivers on file.</p></div></div>';
                        return;
                    }
                    listEl.innerHTML = list.map(w => `
            <div class="col-md-6">
                <div class="waiver-card d-flex align-items-center justify-content-between">
                    <div>
                        <div style="font-weight:700;font-size:.875rem;">${w.waiver_type || 'General Waiver'}</div>
                        <div style="font-size:.78rem;color:var(--text-muted);">${w.signed_at ? 'Signed ' + fmtDate(w.signed_at) : 'Not yet signed'}</div>
                    </div>
                    <div>
                        <span class="waiver-badge ${w.status === 'Signed' ? 'waiver-signed' : 'waiver-pending'}">${w.status || 'Pending'}</span>
                        ${w.file_path ? `<a href="${w.file_path}" target="_blank" class="file-download ms-2" title="View"><i class="fa-solid fa-eye"></i></a>` : ''}
                    </div>
                </div>
            </div>
        `).join('');
                }

                // ── PROFILE ───────────────────────────────────────────────────────────────────
                async function loadProfile() {
                    const data = await php(API, {
                        action: 'get_profile'
                    });
                    const p = data.account || {};
                    const ext = data.profile || {}; // ← patient_profiles row (was wrongly p.profile_data)

                    // ── Core account fields ───────────────────────────────────────────────────
                    document.getElementById('pf_first').value = p.first_name || '';
                    document.getElementById('pf_last').value = p.last_name || '';
                    document.getElementById('pf_middle').value = p.middle_name || '';
                    document.getElementById('pf_suffix').value = p.suffix || '';
                    document.getElementById('pf_birthdate').value = p.birthdate || '';
                    document.getElementById('pf_gender').value = p.gender || '';
                    document.getElementById('pf_contact').value = displayContact(p.contact_number);
                    document.getElementById('pf_email').value = p.email || '';

                    const setVal = (id, val) => {
                        const el = document.getElementById(id);
                        if (el) el.value = (val !== null && val !== undefined) ? val : '';
                    };

                    // ── patient_profiles fields ───────────────────────────────────────────────
                    setVal('pf_civil_status', ext.civil_status);
                    setVal('pf_nationality', ext.nationality);
                    setVal('pf_home_phone', ext.home_phone);
                    setVal('pf_address', ext.address);
                    setVal('pf_city', ext.city);
                    setVal('pf_province', ext.province);
                    setVal('pf_zip', ext.zip);
                    setVal('pf_chief_complaint', ext.chief_complaint);
                    setVal('pf_referral_source', ext.referral_source);
                    setVal('pf_referred_by', ext.referred_by);
                    setVal('pf_prev_dentist', ext.prev_dentist);
                    setVal('pf_last_visit', ext.last_dental_visit); // ← correct column name
                    setVal('pf_dental_notes', ext.dental_notes);
                    setVal('pf_physician', ext.physician);
                    setVal('pf_physician_specialty', ext.physician_specialty);
                    setVal('pf_physician_office_number', ext.physician_office_number);
                    setVal('pf_physician_office_address', ext.physician_office_address);
                    setVal('pf_other_allergies', ext.other_allergies);
                    setVal('pf_allergy_reaction', ext.allergy_reaction);
                    setVal('pf_blood_pressure', ext.blood_pressure);
                    setVal('pf_blood_sugar', ext.blood_sugar);
                    setVal('pf_pulse', ext.pulse_rate);
                    setVal('pf_emergency_name', ext.emergency_name);
                    setVal('pf_emergency_relation', ext.emergency_relation);
                    setVal('pf_emergency_phone', ext.emergency_phone);
                    setVal('pf_other_conditions', ext.other_conditions);

                    // ── Checkboxes (Supabase returns jsonb as array already) ─────────────────
                    const toArr = v => Array.isArray(v) ? v :
                        (typeof v === 'string' && v ? JSON.parse(v) : []);

                    const restoreChecks = (name, raw) => {
                        const list = toArr(raw);
                        document.querySelectorAll(`input[name="${name}"]`).forEach(cb => {
                            cb.checked = list.includes(cb.value);
                        });
                    };
                    restoreChecks('allergies', ext.allergies);
                    restoreChecks('conditions', ext.conditions);

                    // ── Yes/No radios — DB stores true/false, radios expect 'yes'/'no' ───────
                    ['q_good_health', 'q_under_treatment', 'q_serious_illness', 'q_hospitalized',
                        'q_taking_meds', 'q_tobacco', 'q_dangerous_drugs',
                        'q_pregnant', 'q_nursing', 'q_birth_control'
                    ].forEach(qn => {
                        const val = ext[qn];
                        if (val === null || val === undefined) return;
                        const strVal = (val === true || val === 1) ? 'yes' : 'no';
                        const el = document.querySelector(`input[name="${qn}"][value="${strVal}"]`);
                        if (el) el.checked = true;
                    });

                    // ── Restore follow-up field visibility ────────────────────────────────────
                    ({
                        fu_treatment: 'q_under_treatment',
                        fu_illness: 'q_serious_illness',
                        fu_hospitalized: 'q_hospitalized',
                        fu_meds: 'q_taking_meds'
                    }).entries && Object.entries({
                        fu_treatment: 'q_under_treatment',
                        fu_illness: 'q_serious_illness',
                        fu_hospitalized: 'q_hospitalized',
                        fu_meds: 'q_taking_meds'
                    }).forEach(([fuId, qn]) => {
                        const el = document.getElementById(fuId);
                        if (el) el.style.display = (ext[qn] === true || ext[qn] === 1) ? 'block' : 'none';
                    });

                    calcAge();
                    checkGenderConditions();

                    // ── Left panel summary card ───────────────────────────────────────────────
                    const fullName = [p.first_name, p.middle_name, p.last_name, p.suffix]
                        .filter(Boolean).join(' ') || '—';
                    document.getElementById('profileFullName').textContent = fullName;
                    document.getElementById('profileEmail').textContent = p.email || '—';
                    document.getElementById('profileContact').textContent = displayContact(p.contact_number);
                    document.getElementById('profileAvatar').textContent = (p.first_name || 'P').charAt(0).toUpperCase();
                    document.getElementById('profileComplete').innerHTML = p.is_profile_complete ?
                        '<span class="badge-status badge-completed">Profile Complete</span>' :
                        '<span class="badge-status badge-pending">Profile Incomplete</span>';
                }

                // ── SAVE PROFILE ─────────────────────────────────────────────────────────────
                async function saveProfile() {

                    // ── Loading state ─────────────────────────────────────────────────
                    const saveButtons = document.querySelectorAll('[onclick="saveProfile()"]');
                    const setLoading = (on) => {
                        saveButtons.forEach(btn => {
                            btn.disabled = on;
                            btn.innerHTML = on ?
                                '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Saving…' :
                                '<i class="fa-solid fa-floppy-disk me-1"></i> Save Changes';
                        });
                    };
                    setLoading(true);

                    // Validation
                    let ok = true;
                    const req = (id, errId, msg) => {
                        if (!document.getElementById(id)?.value.trim()) {
                            showPfErr(errId, msg);
                            ok = false;
                        } else {
                            hidePfErr(errId);
                        }
                    };
                    req('pf_first', 'err_pf_first', 'First name is required.');
                    req('pf_last', 'err_pf_last', 'Last name is required.');
                    req('pf_birthdate', 'err_pf_birthdate', 'Birthdate is required.');

                    if (!ok) {
                        setLoading(false);
                        Swal.fire({
                            icon: 'warning',
                            title: 'Required Fields Missing',
                            text: 'Please fill in all required fields.',
                            confirmButtonColor: '#2563eb'
                        });
                        document.getElementById('sec-basic').scrollIntoView({
                            behavior: 'smooth'
                        });
                        return;
                    }

                    const gv = id => document.getElementById(id)?.value?.trim() || null;
                    const gyn = name => document.querySelector(`input[name="${name}"]:checked`)?.value || null;
                    const getChecked = name => [...document.querySelectorAll(`input[name="${name}"]:checked`)].map(cb => cb.value);

                    const body = {
                        action: 'update_profile',

                        // patient_accounts fields
                        first_name: gv('pf_first'),
                        last_name: gv('pf_last'),
                        middle_name: gv('pf_middle'),
                        suffix: gv('pf_suffix'),
                        birthdate: gv('pf_birthdate'),
                        gender: gv('pf_gender'),

                        // patient_profiles fields
                        civil_status: gv('pf_civil_status'),
                        nationality: gv('pf_nationality'),
                        guardian_name: gv('pf_guardian_name'),
                        guardian_relation: gv('pf_guardian_relation'),
                        home_phone: gv('pf_home_phone'),
                        address: gv('pf_address'),
                        city: gv('pf_city'),
                        province: gv('pf_province'),
                        zip: gv('pf_zip'),
                        prev_dentist: gv('pf_prev_dentist'),
                        last_dental_visit: gv('pf_last_visit'),
                        dental_notes: gv('pf_dental_notes'),
                        chief_complaint: gv('pf_chief_complaint'),
                        referral_source: gv('pf_referral_source'),
                        referred_by: gv('pf_referred_by'),
                        physician: gv('pf_physician'),
                        physician_specialty: gv('pf_physician_specialty'),
                        physician_office_number: gv('pf_physician_office_number'),
                        physician_office_address: gv('pf_physician_office_address'),

                        // Medical questions (yes/no)
                        q_good_health: gyn('q_good_health'),
                        q_under_treatment: gyn('q_under_treatment'),
                        treatment_condition: gv('pf_treatment_condition'),
                        q_serious_illness: gyn('q_serious_illness'),
                        illness_detail: gv('pf_illness_detail'),
                        q_hospitalized: gyn('q_hospitalized'),
                        hospitalized_detail: gv('pf_hospitalized_detail'),
                        q_taking_meds: gyn('q_taking_meds'),
                        meds_detail: gv('pf_meds_detail'),
                        q_tobacco: gyn('q_tobacco'),
                        q_dangerous_drugs: gyn('q_dangerous_drugs'),
                        q_pregnant: gyn('q_pregnant'),
                        q_nursing: gyn('q_nursing'),
                        q_birth_control: gyn('q_birth_control'),

                        // Allergies
                        allergies: getChecked('allergies'),
                        other_allergies: gv('pf_other_allergies'),
                        allergy_reaction: gv('pf_allergy_reaction'),

                        // Vitals
                        blood_pressure: gv('pf_blood_pressure'),
                        blood_sugar: gv('pf_blood_sugar'),
                        pulse_rate: gv('pf_pulse'),

                        // Emergency contact
                        emergency_name: gv('pf_emergency_name'),
                        emergency_relation: gv('pf_emergency_relation'),
                        emergency_phone: gv('pf_emergency_phone'),

                        // Medical conditions
                        conditions: getChecked('conditions'),
                        other_conditions: gv('pf_other_conditions'),
                    };

                    const data = await php(API, body);
                    setLoading(false);

                    if (data.success) {
                        // Single declaration here — remove any other const savedComplete below
                        const savedComplete = !!data.is_profile_complete;
                        isProfileComplete = savedComplete;

                        const missingParts = [];
                        if (!gv('pf_first') || !gv('pf_last') || !gv('pf_birthdate') || !gv('pf_gender'))
                            missingParts.push('Basic Info (name, birthdate, gender)');
                        if (!gv('pf_address') || !gv('pf_city'))
                            missingParts.push('Contact Info (address & city)');
                        const mqAnswered = ['q_good_health', 'q_tobacco', 'q_dangerous_drugs']
                            .every(n => document.querySelector(`input[name="${n}"]:checked`));
                        if (!mqAnswered)
                            missingParts.push('Medical Questions (Yes/No answers)');

                        Swal.fire({
                            icon: savedComplete ? 'success' : 'info',
                            title: savedComplete ? 'Profile Saved!' : 'Progress Saved',
                            html: savedComplete ?
                                'Your health profile has been updated.' :
                                `Saved! Still needed to unlock booking:<br><strong>${missingParts.join(', ')}</strong>`,
                            timer: 3000,
                            showConfirmButton: !savedComplete,
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#2563eb',
                        });

                        const firstName = gv('pf_first') || 'Patient';
                        const lastName = gv('pf_last') || '';
                        const middle = gv('pf_middle') || '';
                        const suffix = gv('pf_suffix') || '';
                        const fullName = [firstName, middle, lastName, suffix].filter(Boolean).join(' ');

                        document.getElementById('profileFullName').textContent = fullName;
                        document.getElementById('profileEmail').textContent = document.getElementById('pf_email').value || '—';
                        document.getElementById('profileContact').textContent = displayContact(document.getElementById('pf_contact').value);
                        document.getElementById('profileAvatar').textContent = firstName.charAt(0).toUpperCase();
                        document.getElementById('profileComplete').innerHTML = savedComplete ?
                            '<span class="badge-status badge-completed">Profile Complete</span>' :
                            '<span class="badge-status badge-pending">Profile Incomplete</span>';
                        document.getElementById('sidebarInitial').textContent = firstName.charAt(0).toUpperCase();
                        document.getElementById('sidebarName').textContent = [firstName, lastName].filter(Boolean).join(' ');
                        document.getElementById('welcomeName').textContent = firstName;
                        setBookBtnState(savedComplete);
                        loadDashboard();

                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.message || 'Update failed.',
                            confirmButtonColor: '#2563eb'
                        });
                    }
                }

                function showPfErr(id, msg) {
                    const el = document.getElementById(id);
                    if (el) {
                        el.textContent = msg;
                        el.style.display = 'block';
                    }
                }

                function hidePfErr(id) {
                    const el = document.getElementById(id);
                    if (el) el.style.display = 'none';
                }

                function toggleFollowUp(fuId, triggerVal, radioEl) {
                    const el = document.getElementById(fuId);
                    if (!el) return;
                    const checked = radioEl.closest('.yn-row-wrap')
                        ?.querySelector(`input[name="${radioEl.name}"]:checked`)?.value;
                    el.style.display = checked === triggerVal ? 'block' : 'none';
                }

                function calcAge() {
                    const dob = document.getElementById('pf_birthdate').value;
                    const ageEl = document.getElementById('pf_age');
                    const guardianBlock = document.getElementById('guardianBlock');
                    if (!dob) {
                        if (ageEl) ageEl.value = '';
                        if (guardianBlock) guardianBlock.style.display = 'none';
                        return;
                    }
                    const today = new Date(),
                        birth = new Date(dob);
                    let age = today.getFullYear() - birth.getFullYear();
                    if (today.getMonth() - birth.getMonth() < 0 ||
                        (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())) age--;
                    if (ageEl) ageEl.value = age >= 0 ? age + ' yrs' : '';
                    if (guardianBlock) guardianBlock.style.display = (age >= 0 && age < 18) ? 'block' : 'none';
                }

                function checkGenderConditions() {
                    const gender = document.getElementById('pf_gender').value;
                    const womenBlock = document.getElementById('womenQuestionsBlock');
                    if (womenBlock) womenBlock.style.display = gender === 'Female' ? 'block' : 'none';
                }

                function toggleSection(header) {
                    header.closest('.prof-section').classList.toggle('collapsed');
                }

                function scrollToSection(id, linkEl) {
                    event.preventDefault();
                    document.getElementById(id)?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                    document.querySelectorAll('.prof-nav-link').forEach(l => l.classList.remove('active'));
                    if (linkEl) linkEl.classList.add('active');
                }

                // ── LOGOUT ────────────────────────────────────────────────────────────────────
                async function doLogout() {
                    const conf = await Swal.fire({
                        icon: 'question',
                        title: 'Sign Out?',
                        text: 'You will be logged out of your portal.',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, sign out',
                        confirmButtonColor: '#ef4444',
                        cancelButtonText: 'Stay',
                    });
                    if (conf.isConfirmed) {
                        window.location.href = 'portal-logout.php';
                    }
                }

                // ── Init ──────────────────────────────────────────────────────────────────────
                loadDashboard();
            </script>
    </body>

    </html>