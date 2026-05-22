<?php

// patient-records.php *This shows the patients specific record*

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
        .tab-loading {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #888;
            padding: 24px 0;
        }

        .tab-loading .spinner-border {
            width: 1.1rem;
            height: 1.1rem;
        }

        /* ── Summary cards ── */
        .summary-card {
            border-radius: 10px;
            padding: 16px 20px;
        }

        /* ── Patient header avatar ── */
        .patient-avatar {
            width: 56px;
            height: 56px;
            font-size: 22px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        /* ── Back link hover ── */
        .back-link {
            font-size: 13px;
            color: #888;
            text-decoration: none;
        }

        .back-link:hover {
            color: var(--bs-primary);
        }

        /* ── Treatment status badges (reuse soft palette) ── */
        .badge-ongoing {
            background: rgba(var(--bs-warning-rgb), .15);
            color: var(--bs-warning);
        }

        .badge-completed {
            background: rgba(var(--bs-success-rgb), .15);
            color: var(--bs-success);
        }

        .badge-pending {
            background: rgba(var(--bs-secondary-rgb), .15);
            color: var(--bs-secondary);
        }
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
                    <div class="page-header-title">
                        <h5 class="m-b-10">Patient Record</h5>
                    </div>
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
                            <li class="nav-item">
                                <a class="nav-link" id="tab-waivers" data-bs-toggle="tab" href="#pane-waivers" role="tab">
                                    <i class="feather-file-text me-1"></i>Waivers
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
                                                <th>Code</th>
                                                <th>Service</th>
                                                <th>Tooth</th>
                                                <th>Stage</th>
                                                <th>Status</th>
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
                                                <th>Date</th>
                                                <th>Time</th>
                                                <th>Service</th>
                                                <th>Dentist</th>
                                                <th>Status</th>
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

                        <!-- ══ WAIVERS TAB (AJAX) ══ -->
                        <div class="tab-pane fade" id="pane-waivers" role="tabpanel">
                            <div id="waivers-content">
                                <div class="tab-loading">
                                    <div class="spinner-border text-primary" role="status"></div>
                                    <span>Loading waivers…</span>
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
        const loaded = {
            appointments: false,
            treatments: false,
            billing: false,
            notes: false,
            waivers: false
        };

        document.querySelectorAll('#patientTabs .nav-link').forEach(tab => {
            tab.addEventListener('shown.bs.tab', function(e) {
                const target = e.target.getAttribute('href');
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
                if (target === '#pane-waivers' && !loaded.waivers) {
                    loaded.waivers = true;
                    loadWaivers();
                }
            });
        });

        function loadTab(containerId, action) {
            fetch(`api/patient_record_api.php?action=${action}&patient_id=${PATIENT_ID}`)
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

        function renderTab(containerId, action, data) {
            const el = document.getElementById(containerId);
            if (action === 'get_appointments') el.innerHTML = renderAppointments(data);
            if (action === 'get_treatments') el.innerHTML = renderTreatments(data);
            if (action === 'get_billing') el.innerHTML = renderBilling(data);
            if (action === 'get_notes') el.innerHTML = renderNotes(data);
        }

        // ── Waivers ──────────────────────────────────────────────────
        function loadWaivers() {
            fetch(`api/patient_waivers.php?action=get_waivers&patient_id=${PATIENT_ID}`)
                .then(r => r.json())
                .then(data => {
                    document.getElementById('waivers-content').innerHTML = renderWaivers(data.waivers ?? []);
                })
                .catch(() => {
                    document.getElementById('waivers-content').innerHTML =
                        `<p class="text-danger small"><i class="feather-alert-circle me-1"></i>Failed to load waivers.</p>`;
                });
        }

        function renderWaivers(waivers) {
            const waiverTypes = [
                'Orthodontic (Braces)', 'Tooth Extraction', 'Dental Implant',
                'Root Canal Treatment', 'Teeth Whitening', 'Oral Surgery',
                'Sedation / Anesthesia', 'General Consent', 'Other'
            ];
            const typeOptions = waiverTypes.map(t => `<option value="${t}">${t}</option>`).join('');

            const uploadForm = `
        <div class="border rounded p-3 mb-4 bg-light">
            <h6 class="fw-semibold mb-3 small text-uppercase" style="letter-spacing:.07em;color:#555;">
                <i class="feather-upload me-1"></i> Upload Waiver
            </h6>
            <div id="waiverUploadErr" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
            <div class="row g-2">
                <div class="col-md-5">
                    <label class="form-label fw-semibold small">Waiver Type <span class="text-danger">*</span></label>
                    <select id="waiverType" class="form-select form-select-sm">
                        <option value="">— Select type —</option>
                        ${typeOptions}
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold small">File <span class="text-danger">*</span> <span class="text-muted">(PDF, JPG, PNG · max 5MB)</span></label>
                    <input type="file" id="waiverFile" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary btn-sm w-100" onclick="uploadWaiver()">
                        <i class="feather-upload me-1"></i> Upload
                    </button>
                </div>
            </div>
        </div>`;

            if (!waivers.length) {
                return uploadForm + `<p class="text-muted small">No waivers uploaded yet.</p>`;
            }

            const rows = waivers.map(w => {
                const fileName = w.file_path ? w.file_path.split('/').pop() : '—';
                const signed = w.status === 'Signed';
                const signedAt = w.signed_at ?
                    new Date(w.signed_at).toLocaleDateString('en-PH', {
                        month: 'short',
                        day: '2-digit',
                        year: 'numeric'
                    }) :
                    '—';
                return `
            <tr>
                <td class="fw-semibold small">${esc(w.waiver_type ?? '—')}</td>
                <td class="small">${esc(fileName)}</td>
                <td>
                    <span class="badge ${signed ? 'bg-soft-success text-success' : 'bg-soft-warning text-warning'}">
                        ${signed ? 'Signed' : 'Pending'}
                    </span>
                </td>
                <td class="small text-muted">${signed ? esc(w.signed_by ?? '—') : '—'}</td>
                <td class="small text-muted">${signed ? signedAt : '—'}</td>
                <td class="text-end">
                    <a href="${esc(w.file_path)}" target="_blank" class="btn btn-light btn-sm me-1">
                        <i class="feather-eye" style="font-size:12px;"></i>
                    </a>
                    <button class="btn btn-light btn-sm text-danger" onclick="deleteWaiver(${w.waiver_id}, '${esc(w.waiver_type)}')">
                        <i class="feather-trash-2" style="font-size:12px;"></i>
                    </button>
                </td>
            </tr>`;
            }).join('');

            return uploadForm + `
        <div class="table-responsive">
            <table class="table table-hover small mb-0">
                <thead>
                    <tr>
                        <th>Type</th><th>File</th><th>Status</th>
                        <th>Signed By</th><th>Signed At</th><th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
        }

        function uploadWaiver() {
            const type = document.getElementById('waiverType').value;
            const file = document.getElementById('waiverFile').files[0];
            const notes = document.getElementById('waiverNotes').value;
            const errBox = document.getElementById('waiverUploadErr');

            errBox.style.display = 'none';
            if (!type) {
                errBox.textContent = 'Please select a waiver type.';
                errBox.style.display = 'block';
                return;
            }
            if (!file) {
                errBox.textContent = 'Please select a file.';
                errBox.style.display = 'block';
                return;
            }

            const fd = new FormData();
            fd.append('action', 'upload_waiver');
            fd.append('patient_id', PATIENT_ID);
            fd.append('waiver_type', type);
            fd.append('notes', notes);
            fd.append('waiver_file', file);

            fetch('api/patient_waivers.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        Swal.fire({
                                icon: 'success',
                                title: 'Uploaded!',
                                timer: 1400,
                                showConfirmButton: false
                            })
                            .then(() => {
                                loaded.waivers = false;
                                loadWaivers();
                            });
                    } else {
                        errBox.textContent = d.message;
                        errBox.style.display = 'block';
                    }
                })
                .catch(() => {
                    errBox.textContent = 'Upload failed.';
                    errBox.style.display = 'block';
                });
        }

        function deleteWaiver(waiverId, type) {
            Swal.fire({
                title: 'Delete Waiver?',
                text: `Remove "${type}"? This cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete',
                confirmButtonColor: '#dc3545',
            }).then(r => {
                if (!r.isConfirmed) return;
                fetch('api/patient_waivers.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'delete_waiver',
                            patient_id: PATIENT_ID,
                            waiver_id: waiverId
                        })
                    })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            loaded.waivers = false;
                            loadWaivers();
                        } else Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: d.message
                        });
                    });
            });
        }
        // ── Clinical Notes ───────────────────────────────────────────
function saveNote() {
    const noteDate  = document.getElementById('noteDate').value;
    const noteText  = document.getElementById('noteText').value.trim();
    const isPrivate = document.getElementById('notePrivate').checked ? '1' : '0';
    const errBox    = document.getElementById('noteErr');

    errBox.style.display = 'none';
    if (!noteDate) { errBox.textContent = 'Please select a date.';  errBox.style.display = 'block'; return; }
    if (!noteText) { errBox.textContent = 'Please enter a note.';   errBox.style.display = 'block'; return; }

    const fd = new FormData();
    fd.append('action',     'add_note');
    fd.append('patient_id', PATIENT_ID);
    fd.append('note_date',  noteDate);
    fd.append('note_text',  noteText);
    fd.append('is_private', isPrivate);

    fetch('api/patient_record_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                Swal.fire({ icon: 'success', title: 'Note saved!', timer: 1400, showConfirmButton: false })
                    .then(() => { loaded.notes = false; loadTab('notes-content', 'get_notes'); });
            } else {
                errBox.textContent   = d.message || 'Failed to save note.';
                errBox.style.display = 'block';
            }
        })
        .catch(() => { errBox.textContent = 'Network error.'; errBox.style.display = 'block'; });
}

function deleteNote(noteId) {
    Swal.fire({
        title: 'Delete Note?', text: 'This will be permanently removed.',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Yes, delete', confirmButtonColor: '#dc3545',
    }).then(r => {
        if (!r.isConfirmed) return;

        const fd = new FormData();
        fd.append('action',     'delete_note');
        fd.append('patient_id', PATIENT_ID);
        fd.append('note_id',    noteId);

        fetch('api/patient_record_api.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) { loaded.notes = false; loadTab('notes-content', 'get_notes'); }
                else Swal.fire({ icon: 'error', title: 'Error', text: d.message });
            });
    });
}
        // ── Existing tab renderers (unchanged) ───────────────────────
        function renderAppointments(data) {
            const upcoming = data.upcoming ?? [];
            const history = (data.history?.rows) ?? [];
            const upRows = upcoming.length === 0 ?
                '<tr><td colspan="5" class="text-center text-muted">No upcoming appointments.</td></tr>' :
                upcoming.map(a => `<tr>
            <td>${fmtDate(a.appointment_date)}</td>
            <td>${fmtTime(a.appointment_time)}</td>
            <td>${esc(a.services?.service_name ?? '—')}</td>
            <td>${esc(a.dentists?.full_name ?? '—')}</td>
            <td><span class="badge bg-soft-primary text-primary">${ucfirst(a.status)}</span></td>
          </tr>`).join('');
            const histRows = history.length === 0 ?
                '<tr><td colspan="6" class="text-center text-muted">No past appointments.</td></tr>' :
                history.map(a => `<tr>
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

        function renderTreatments(data) {
            const rows = data.treatments ?? [];
            if (!rows.length) return '<p class="text-muted small">No treatment records.</p>';
            const tr = rows.map(t => `<tr>
        <td class="fw-semibold">${esc(t.treatment_code)}</td>
        <td>${esc(t.services?.service_name ?? '—')}</td>
        <td>${esc(t.tooth_number ?? '—')}</td>
        <td>${esc(t.current_stage ?? '—')}</td>
        <td>${t.treatment_date ? fmtDate(t.treatment_date) : '—'}</td>
        <td>${t.cost != null ? '₱' + parseFloat(t.cost).toLocaleString('en-PH',{minimumFractionDigits:2}) : '—'}</td>
        <td>${esc(t.dentists?.full_name ?? '—')}</td>
        <td><span class="badge bg-soft-secondary text-secondary">${ucfirst(t.status)}</span></td>
      </tr>`).join('');
            return `<div class="table-responsive">
        <table class="table table-hover small mb-0">
            <thead><tr><th>Code</th><th>Service</th><th>Tooth</th><th>Stage</th><th>Date</th><th>Cost</th><th>Dentist</th><th>Status</th></tr></thead>
            <tbody>${tr}</tbody>
        </table></div>`;
        }

        function renderBilling(data) {
            const invoices = data.invoices ?? [];
            if (!invoices.length) return '<p class="text-muted small">No billing records.</p>';
            const tr = invoices.map(inv => `<tr>
        <td class="fw-semibold">${esc(inv.invoice_code)}</td>
        <td>${fmtDate(inv.invoice_date)}</td>
        <td>₱${fmt2(inv.total_amount)}</td>
        <td>₱${fmt2(inv.amount_paid)}</td>
        <td class="${parseFloat(inv.balance)>0?'text-danger fw-semibold':''}">₱${fmt2(inv.balance)}</td>
        <td><span class="badge bg-soft-primary text-primary">${ucfirst(inv.payment_status)}</span></td>
      </tr>`).join('');
            return `<div class="table-responsive">
        <table class="table table-hover small mb-0">
            <thead><tr><th>Invoice</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead>
            <tbody>${tr}</tbody>
        </table></div>`;
        }

        // REPLACE the entire renderNotes function with:

        function renderNotes(data) {
            const notes = data.notes ?? [];

            const form = `
        <div class="border rounded p-3 mb-4 bg-light">
            <h6 class="fw-semibold mb-3 small text-uppercase" style="letter-spacing:.07em;color:#555;">
                <i class="feather-edit-3 me-1"></i> Add Clinical Note
            </h6>
            <div id="noteErr" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Date <span class="text-danger">*</span></label>
                    <input type="date" id="noteDate" class="form-control form-control-sm"
                        value="${new Date().toISOString().slice(0,10)}">
                </div>
                <div class="col-md-9">
                    <label class="form-label fw-semibold small">
                        Note <span class="text-danger">*</span>
                    </label>
                    <textarea id="noteText" class="form-control form-control-sm" rows="3"
                        placeholder="Enter clinical observations, treatments discussed, patient complaints, etc."></textarea>
                </div>
                <div class="col-12 d-flex align-items-center justify-content-between">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="notePrivate">
                        <label class="form-check-label small text-muted" for="notePrivate">
                            Mark as private (only visible to dentists/admins)
                        </label>
                    </div>
                    <button class="btn btn-primary btn-sm px-4" onclick="saveNote()">
                        <i class="feather-save me-1"></i> Save Note
                    </button>
                </div>
            </div>
        </div>`;

            if (!notes.length) {
                return form + '<p class="text-muted small">No clinical notes on record.</p>';
            }

            return form + notes.map(n => `
        <div class="border rounded p-3 mb-2 small">
            <div class="d-flex justify-content-between align-items-start mb-1">
                <div>
                    <strong>${fmtDate(n.note_date)}</strong>
                    <span class="text-muted ms-2">${esc(n.dentists?.full_name ?? n.created_by ?? '—')}</span>
                    ${n.is_private
                        ? '<span class="badge bg-soft-secondary text-secondary ms-1">Private</span>'
                        : ''}
                </div>
                <button class="btn btn-light btn-sm text-danger" style="padding:2px 7px;"
                    onclick="deleteNote(${n.note_id})">
                    <i class="feather-trash-2" style="font-size:12px;"></i>
                </button>
            </div>
            <div style="white-space:pre-wrap;">${esc(n.note_text)}</div>
        </div>`).join('');
        }
    </script>
</body>

</html>