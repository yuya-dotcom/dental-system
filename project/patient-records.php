<?php

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/patient_control.php';   // single-record functions
require_once __DIR__ . '/controllers/patient_controller.php'; // badge/format helpers
require_once __DIR__ . '/dbconfig.php';

// ── Guard: require a valid ?id= ──────────────────────────────
$patientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$patientId) {
    header('Location: patients-list.php');
    exit;
}

// ── Fetch patient (server-side, always available) ────────────
$patient = getPatientById($patientId);
if (!$patient) {
    // Patient not found — redirect back to list with a notice
    header('Location: patients-list.php?error=patient_not_found');
    exit;
}

// ── Pre-load summary for the Overview tab ───────────────────
$summary  = getPatientFinancialSummary($patientId);
$active   = getPatientActiveTreatments($patientId);
$upcoming = getPatientUpcomingAppointments($patientId);

// ── Helpers ──────────────────────────────────────────────────
$age      = calculateAge($patient['birthdate'] ?? null);
$fullName = htmlspecialchars($patient['full_name'] ?? '—');
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | <?= $fullName ?></title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        /* ── Tab content loading state ── */
        .tab-loading { display: flex; align-items: center; gap: 8px; color: #888; padding: 24px 0; }
        .tab-loading .spinner-border { width: 1.1rem; height: 1.1rem; }

        /* ── Summary cards ── */
        .summary-card { border-radius: 10px; padding: 16px 20px; }

        /* ── Patient header avatar ── */
        .patient-avatar {
            width: 56px; height: 56px; font-size: 22px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%;
        }

        /* ── Back link hover ── */
        .back-link { font-size: 13px; color: #888; text-decoration: none; }
        .back-link:hover { color: var(--bs-primary); }

        /* ── Treatment status badges (reuse soft palette) ── */
        .badge-ongoing   { background: rgba(var(--bs-warning-rgb),.15); color: var(--bs-warning); }
        .badge-completed { background: rgba(var(--bs-success-rgb),.15); color: var(--bs-success); }
        .badge-pending   { background: rgba(var(--bs-secondary-rgb),.15); color: var(--bs-secondary); }
    </style>
</head>
<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>

    <main class="nxl-container">
        <div class="nxl-content">

            <!-- ── Page Header ─────────────────────────────── -->
            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">Patient Record</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="patients-list.php">Patients</a></li>
                        <li class="breadcrumb-item"><?= $fullName ?></li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto d-flex align-items-center gap-2">
                    <a href="patients-list.php" class="btn btn-light btn-sm">
                        <i class="feather-arrow-left me-1"></i> Back to List
                    </a>
                    <a href="patient-record-print.php?id=<?= $patientId ?>" target="_blank"
                       class="btn btn-outline-secondary btn-sm">
                        <i class="feather-printer me-1"></i> Print
                    </a>
                </div>
            </div>

            <!-- ── Patient Identity Card ───────────────────── -->
            <div class="main-content">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body d-flex align-items-center gap-3 flex-wrap">

                                <!-- Avatar -->
                                <div class="patient-avatar bg-soft-primary text-primary">
                                    <?= strtoupper(substr($patient['full_name'] ?? 'P', 0, 1)) ?>
                                </div>

                                <!-- Core info -->
                                <div class="flex-grow-1">
                                    <h5 class="mb-1 fw-bold"><?= $fullName ?></h5>
                                    <div class="text-muted small d-flex flex-wrap gap-3">
                                        <span><i class="feather-tag me-1"></i><?= htmlspecialchars($patient['patient_code'] ?? '—') ?></span>
                                        <span><i class="feather-user me-1"></i><?= ucfirst($patient['gender'] ?? '—') ?>, <?= $age ?></span>
                                        <span><i class="feather-phone me-1"></i><?= htmlspecialchars($patient['contact_number'] ?? '—') ?></span>
                                        <span><i class="feather-map-pin me-1"></i><?= htmlspecialchars($patient['branches']['branch_name'] ?? '—') ?></span>
                                        <?php if (!empty($patient['birthdate'])): ?>
                                        <span><i class="feather-calendar me-1"></i>DOB: <?= date('M d, Y', strtotime($patient['birthdate'])) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($patient['last_visit'])): ?>
                                        <span><i class="feather-clock me-1"></i>Last visit: <?= date('M d, Y', strtotime($patient['last_visit'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Status badge -->
                                <div>
                                    <span class="badge <?= patStatusBadge($patient['status'] ?? 'active') ?> px-3 py-2 fs-6 rounded-4">
                                        <?= ucfirst($patient['status'] ?? 'active') ?>
                                    </span>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Tabs ────────────────────────────────── -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header border-bottom">
                        <ul class="nav nav-tabs card-header-tabs" id="patientTabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" id="tab-overview" data-bs-toggle="tab" href="#pane-overview" role="tab">
                                    <i class="feather-grid me-1"></i>Overview
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="tab-appointments" data-bs-toggle="tab" href="#pane-appointments" role="tab">
                                    <i class="feather-calendar me-1"></i>Appointments
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="tab-treatments" data-bs-toggle="tab" href="#pane-treatments" role="tab">
                                    <i class="feather-activity me-1"></i>Treatments
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="tab-billing" data-bs-toggle="tab" href="#pane-billing" role="tab">
                                    <i class="feather-file-text me-1"></i>Billing
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="tab-notes" data-bs-toggle="tab" href="#pane-notes" role="tab">
                                    <i class="feather-edit-3 me-1"></i>Clinical Notes
                                </a>
                            </li>
                        </ul>
                    </div>

                    <div class="card-body tab-content" id="patientTabContent">

                        <!-- ══ OVERVIEW TAB (server-side, no AJAX) ══ -->
                        <div class="tab-pane fade show active" id="pane-overview" role="tabpanel">

                            <!-- Active Treatments -->
                            <h6 class="fw-semibold text-muted text-uppercase mb-3 mt-1" style="font-size:11px;letter-spacing:.07em;">
                                Active Treatment Plans
                            </h6>
                            <?php if (empty($active)): ?>
                                <p class="text-muted small">No active treatments.</p>
                            <?php else: ?>
                                <div class="table-responsive mb-4">
                                    <table class="table table-hover small mb-0">
                                        <thead>
                                            <tr>
                                                <th>Code</th><th>Service</th><th>Tooth</th><th>Stage</th><th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($active as $t): ?>
                                        <tr>
                                            <td class="fw-semibold"><?= htmlspecialchars($t['treatment_code']) ?></td>
                                            <td><?= htmlspecialchars($t['services']['service_name'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($t['tooth_number'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($t['current_stage'] ?? '—') ?></td>
                                            <td><span class="badge bg-soft-warning text-warning"><?= ucfirst($t['status']) ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <!-- Upcoming Appointments -->
                            <h6 class="fw-semibold text-muted text-uppercase mb-3" style="font-size:11px;letter-spacing:.07em;">
                                Upcoming Appointments
                            </h6>
                            <?php if (empty($upcoming)): ?>
                                <p class="text-muted small">No upcoming appointments.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover small mb-0">
                                        <thead>
                                            <tr>
                                                <th>Date</th><th>Time</th><th>Service</th><th>Dentist</th><th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($upcoming as $a): ?>
                                        <tr>
                                            <td><?= date('M d, Y', strtotime($a['appointment_date'])) ?></td>
                                            <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
                                            <td><?= htmlspecialchars($a['services']['service_name'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($a['dentists']['full_name'] ?? '—') ?></td>
                                            <td>
                                                <span class="badge bg-soft-primary text-primary"><?= ucfirst($a['status']) ?></span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- ══ END OVERVIEW ══ -->


                        <!-- ══ APPOINTMENTS TAB (AJAX) ══ -->
                        <div class="tab-pane fade" id="pane-appointments" role="tabpanel">
                            <div id="apt-content">
                                <div class="tab-loading">
                                    <div class="spinner-border text-primary" role="status"></div>
                                    <span>Loading appointments…</span>
                                </div>
                            </div>
                        </div>

                        <!-- ══ TREATMENTS TAB (AJAX) ══ -->
                        <div class="tab-pane fade" id="pane-treatments" role="tabpanel">
                            <div id="trx-content">
                                <div class="tab-loading">
                                    <div class="spinner-border text-primary" role="status"></div>
                                    <span>Loading treatments…</span>
                                </div>
                            </div>
                        </div>

                        <!-- ══ BILLING TAB (AJAX) ══ -->
                        <div class="tab-pane fade" id="pane-billing" role="tabpanel">
                            <div id="bill-content">
                                <div class="tab-loading">
                                    <div class="spinner-border text-primary" role="status"></div>
                                    <span>Loading billing…</span>
                                </div>
                            </div>
                        </div>

                        <!-- ══ CLINICAL NOTES TAB (AJAX) ══ -->
                        <div class="tab-pane fade" id="pane-notes" role="tabpanel">
                            <div id="notes-content">
                                <div class="tab-loading">
                                    <div class="spinner-border text-primary" role="status"></div>
                                    <span>Loading notes…</span>
                                </div>
                            </div>
                        </div>

                    </div><!-- /.tab-content -->
                </div><!-- /.card -->

            </div><!-- /.main-content -->
        </div>
    </main>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/admin.js"></script>
    <script>
    // ── Patient ID passed from PHP ───────────────────────────
    const PATIENT_ID = <?= $patientId ?>;

    // ── Track which tabs have already been loaded ────────────
    const loaded = { appointments: false, treatments: false, billing: false, notes: false };

    // ── Lazy-load tabs on first click ────────────────────────
    document.querySelectorAll('#patientTabs .nav-link').forEach(tab => {
        tab.addEventListener('shown.bs.tab', function (e) {
            const target = e.target.getAttribute('href'); // e.g. "#pane-appointments"

            if (target === '#pane-appointments' && !loaded.appointments) {
                loaded.appointments = true;
                loadTab('apt-content', 'get_appointments');
            }
            if (target === '#pane-treatments' && !loaded.treatments) {
                loaded.treatments = true;
                loadTab('trx-content', 'get_treatments');
            }
            if (target === '#pane-billing' && !loaded.billing) {
                loaded.billing = true;
                loadTab('bill-content', 'get_billing');
            }
            if (target === '#pane-notes' && !loaded.notes) {
                loaded.notes = true;
                loadTab('notes-content', 'get_notes');
            }
        });
    });

    // ── Generic tab loader ───────────────────────────────────
    function loadTab(containerId, action) {
        const url = `api/patient_record_api.php?action=${action}&patient_id=${PATIENT_ID}`;
        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Load failed.');
                renderTab(containerId, action, data);
            })
            .catch(err => {
                document.getElementById(containerId).innerHTML =
                    `<p class="text-danger small"><i class="feather-alert-circle me-1"></i>${err.message}</p>`;
            });
    }

    // ── Tab renderers ────────────────────────────────────────
    function renderTab(containerId, action, data) {
        const el = document.getElementById(containerId);
        if (action === 'get_appointments') el.innerHTML = renderAppointments(data);
        if (action === 'get_treatments')   el.innerHTML = renderTreatments(data);
        if (action === 'get_billing')      el.innerHTML = renderBilling(data);
        if (action === 'get_notes')        el.innerHTML = renderNotes(data);
    }

    // ── Appointments renderer ────────────────────────────────
    function renderAppointments(data) {
        const upcoming  = data.upcoming  ?? [];
        const history   = (data.history?.rows) ?? [];

        const upRows = upcoming.length === 0
            ? '<tr><td colspan="5" class="text-center text-muted">No upcoming appointments.</td></tr>'
            : upcoming.map(a => `
                <tr>
                    <td>${fmtDate(a.appointment_date)}</td>
                    <td>${fmtTime(a.appointment_time)}</td>
                    <td>${esc(a.services?.service_name ?? '—')}</td>
                    <td>${esc(a.dentists?.full_name ?? '—')}</td>
                    <td><span class="badge bg-soft-primary text-primary">${ucfirst(a.status)}</span></td>
                </tr>`).join('');

        const histRows = history.length === 0
            ? '<tr><td colspan="6" class="text-center text-muted">No past appointments.</td></tr>'
            : history.map(a => `
                <tr>
                    <td>${fmtDate(a.appointment_date)}</td>
                    <td>${fmtTime(a.appointment_time)}</td>
                    <td>${esc(a.services?.service_name ?? '—')}</td>
                    <td>${esc(a.dentists?.full_name ?? '—')}</td>
                    <td>${esc(a.branches?.branch_name ?? '—')}</td>
                    <td><span class="badge bg-soft-secondary text-secondary">${ucfirst(a.status)}</span></td>
                </tr>`).join('');

        return `
            <h6 class="fw-semibold text-muted text-uppercase mb-3" style="font-size:11px;">Upcoming</h6>
            <div class="table-responsive mb-4">
                <table class="table table-hover small mb-0">
                    <thead><tr><th>Date</th><th>Time</th><th>Service</th><th>Dentist</th><th>Status</th></tr></thead>
                    <tbody>${upRows}</tbody>
                </table>
            </div>
            <h6 class="fw-semibold text-muted text-uppercase mb-3" style="font-size:11px;">History</h6>
            <div class="table-responsive">
                <table class="table table-hover small mb-0">
                    <thead><tr><th>Date</th><th>Time</th><th>Service</th><th>Dentist</th><th>Branch</th><th>Status</th></tr></thead>
                    <tbody>${histRows}</tbody>
                </table>
            </div>`;
    }

    // ── Treatments renderer ──────────────────────────────────
    function renderTreatments(data) {
        const rows = data.treatments ?? [];
        if (!rows.length) return '<p class="text-muted small">No treatment records.</p>';
        const tr = rows.map(t => `
            <tr>
                <td class="fw-semibold">${esc(t.treatment_code)}</td>
                <td>${esc(t.services?.service_name ?? '—')}</td>
                <td>${esc(t.tooth_number ?? '—')}</td>
                <td>${esc(t.current_stage ?? '—')}</td>
                <td>${t.treatment_date ? fmtDate(t.treatment_date) : '—'}</td>
                <td>${t.cost != null ? '₱' + parseFloat(t.cost).toLocaleString('en-PH', {minimumFractionDigits:2}) : '—'}</td>
                <td>${esc(t.dentists?.full_name ?? '—')}</td>
                <td><span class="badge bg-soft-secondary text-secondary">${ucfirst(t.status)}</span></td>
            </tr>`).join('');
        return `
            <div class="table-responsive">
                <table class="table table-hover small mb-0">
                    <thead><tr>
                        <th>Code</th><th>Service</th><th>Tooth</th><th>Stage</th>
                        <th>Date</th><th>Cost</th><th>Dentist</th><th>Status</th>
                    </tr></thead>
                    <tbody>${tr}</tbody>
                </table>
            </div>`;
    }

    // ── Billing renderer ─────────────────────────────────────
    function renderBilling(data) {
        const invoices = data.invoices ?? [];
        if (!invoices.length) return '<p class="text-muted small">No billing records.</p>';
        const tr = invoices.map(inv => `
            <tr>
                <td class="fw-semibold">${esc(inv.invoice_code)}</td>
                <td>${fmtDate(inv.invoice_date)}</td>
                <td>₱${fmt2(inv.total_amount)}</td>
                <td>₱${fmt2(inv.amount_paid)}</td>
                <td class="${parseFloat(inv.balance)>0?'text-danger fw-semibold':''}">₱${fmt2(inv.balance)}</td>
                <td><span class="badge bg-soft-primary text-primary">${ucfirst(inv.payment_status)}</span></td>
            </tr>`).join('');
        return `
            <div class="table-responsive">
                <table class="table table-hover small mb-0">
                    <thead><tr>
                        <th>Invoice</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th>
                    </tr></thead>
                    <tbody>${tr}</tbody>
                </table>
            </div>`;
    }

    // ── Notes renderer ───────────────────────────────────────
    function renderNotes(data) {
        const notes = data.notes ?? [];
        if (!notes.length) return '<p class="text-muted small">No clinical notes on record.</p>';
        return notes.map(n => `
            <div class="border rounded p-3 mb-2 small">
                <div class="d-flex justify-content-between mb-1">
                    <strong>${fmtDate(n.note_date)}</strong>
                    <span class="text-muted">${esc(n.dentists?.full_name ?? n.created_by ?? '—')}
                        ${n.is_private ? '<span class="badge bg-soft-secondary text-secondary ms-1">Private</span>' : ''}
                    </span>
                </div>
                <div style="white-space:pre-wrap;">${esc(n.note_text)}</div>
            </div>`).join('');
    }

    // ── Utility helpers ──────────────────────────────────────
    function fmtDate(d)   { if (!d) return '—'; return new Date(d + 'T00:00:00').toLocaleDateString('en-PH', {month:'short',day:'2-digit',year:'numeric'}); }
    function fmtTime(t)   { if (!t) return '—'; const [h,m] = t.split(':'); const d=new Date(); d.setHours(h,m); return d.toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit'}); }
    function ucfirst(s)   { s = s ?? '—'; return s.charAt(0).toUpperCase() + s.slice(1); }
    function fmt2(v)      { return parseFloat(v||0).toLocaleString('en-PH', {minimumFractionDigits:2}); }
    function esc(s)       { const d=document.createElement('div'); d.textContent=s??'—'; return d.innerHTML; }
    </script>
</body>
</html>