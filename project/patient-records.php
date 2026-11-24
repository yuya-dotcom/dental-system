<?php

// patient-records.php

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/patient_control.php';
require_once __DIR__ . '/controllers/patient_controller.php';
require_once __DIR__ . '/dbconfig.php';

$patientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$patientId) {
    header('Location: patients-list.php');
    exit;
}
// Branch-scoped: a non-owner only opens patients with an appointment at their branch
if (!canAccessPatient($patientId)) {
    header('Location: unauthorized.php');
    exit;
}

// One request for the patient + Overview (controllers/patient_control.php);
// the individual reads are the fallback.
$bundle  = getPatientRecordBundle($patientId);
$patient = $bundle ? $bundle['patient'] : getPatientById($patientId);
if (!$patient) {
    header('Location: patients-list.php?error=patient_not_found');
    exit;
}

$summary  = $bundle ? $bundle['summary']  : getPatientFinancialSummary($patientId);
$active   = $bundle ? $bundle['active']   : getPatientActiveTreatments($patientId);
$upcoming = $bundle ? $bundle['upcoming'] : getPatientUpcomingAppointments($patientId);

$fullName = htmlspecialchars($patient['full_name'] ?? '—');

// Dental chart editing: the same staff roles that could use the old chart modal on this page
$canEditChart = in_array($_SESSION['role'] ?? '', ['owner', 'admin', 'dentist'], true);

// Header + Overview helpers (real patient data only)
$prDate   = fn(?string $d, string $fmt = 'M d, Y') => !empty($d) && strtotime($d) ? date($fmt, strtotime($d)) : null;
$status   = strtolower($patient['status'] ?? 'active');
$balance  = (float)($summary['total_balance'] ?? 0);
$nextAppt = $upcoming[0] ?? null;
function prApptPill(?string $status): string
{
    $s = strtolower($status ?? '');
    $cls = match ($s) {
        'confirmed', 'checked_in', 'in_progress' => 'pr-pill-blue',
        'completed'                              => 'pr-pill-green',
        'pending', 'rescheduled'                 => 'pr-pill-amber',
        'cancelled', 'declined'                  => 'pr-pill-red',
        'no_show'                                => 'pr-pill-noshow',
        default                                  => '',
    };
    return '<span class="pr-pill ' . $cls . '">' . htmlspecialchars(ucwords(str_replace('_', ' ', $s ?: '—'))) . '</span>';
}
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
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/treatments.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/odontogram.css?v=3">
    <link rel="stylesheet" href="assets/css/patient-record.css?v=8">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
    <link rel="stylesheet" href="assets/css/es-admin.css?v=8">
    <link rel="stylesheet" href="assets/css/es-records.css?v=7">
    <link rel="stylesheet" href="assets/css/analytics.css?v=8">
    <link rel="stylesheet" href="assets/css/appointments-records.css?v=10">
</head>

<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>

    <main class="nxl-container">
        <div class="nxl-content pr-page">

            <!-- ── Page Header ── -->
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
                    <a href="patients-list.php" class="btn btn-light btn-sm es-btn-compact">
                        <i class="feather-arrow-left me-1"></i> Back to List
                    </a>
                    <button type="button" class="btn btn-outline-secondary btn-sm es-btn-compact" id="prPrintBtn" aria-haspopup="dialog">
                        <i class="feather-printer me-1"></i> Print
                    </button>
                </div>
            </div>

            <div class="main-content an-page ar-page pr-page">

                <!-- ── Patient summary ── -->
                <!-- Name, Patient ID and status only; personal / contact details live in the
                     Patient Details tab (no branch: the branch belongs to each appointment) -->
                <div class="card mb-3">
                    <div class="pr-head is-compact">
                        <div class="pr-avatar" aria-hidden="true">
                            <?= htmlspecialchars(strtoupper(mb_substr($patient['full_name'] ?? 'P', 0, 1))) ?>
                        </div>
                        <div class="min-w-0">
                            <div class="pr-head-top">
                                <h4 class="pr-name"><?= $fullName ?></h4>
                                <span class="pr-code" title="Patient ID">
                                    <i class="feather-tag" aria-hidden="true"></i><?= htmlspecialchars($patient['patient_code'] ?? '—') ?>
                                </span>
                                <span class="badge pr-status <?= patStatusBadge($status) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Tabs ── -->
                <div class="card">
                    <div class="pr-tabs-wrap">
                        <ul class="nav nav-tabs pr-tabs" id="patientTabs" role="tablist">
                            <?php foreach ([
                                ['overview',     'pane-overview',     'feather-grid',      'Overview'],
                                ['details',      'pane-details',      'feather-user',      'Patient Details'],
                                ['appointments', 'pane-appointments', 'feather-calendar',  'Appointments'],
                                ['treatments',   'pane-treatments',   'feather-activity',  'Treatments'],
                                ['billing',      'pane-billing',      'feather-credit-card', 'Billing'],
                                ['notes',        'pane-notes',        'feather-edit-3',    'Clinical Notes'],
                                ['waivers',      'pane-waivers',      'feather-file-text', 'Waivers'],
                                ['files',        'pane-files',        'feather-folder',    'Files'],
                                ['vitals',       'pane-vitals',       'feather-heart',     'Vital Signs'],
                                ['dentalchart',  'pane-dentalchart',  'feather-layers',    'Dental Chart'],
                            ] as $i => [$id, $pane, $icon, $label]): ?>
                                <li class="nav-item" role="presentation">
                                    <a class="nav-link<?= $i === 0 ? ' active' : '' ?>" id="tab-<?= $id ?>" data-bs-toggle="tab" href="#<?= $pane ?>"
                                        role="tab" aria-controls="<?= $pane ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
                                        <i class="<?= $icon ?>" aria-hidden="true"></i><?= $label ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="tab-content pr-tab-body" id="patientTabContent">

                        <!-- ══ OVERVIEW TAB ══ -->
                        <div class="tab-pane fade show active" id="pane-overview" role="tabpanel" aria-labelledby="tab-overview">

                            <div class="pr-kpis">
                                <button type="button" class="pr-kpi" data-go-tab="appointments">
                                    <span class="pr-kpi-icon"><i class="feather-calendar"></i></span>
                                    <span class="min-w-0">
                                        <span class="pr-kpi-label d-block">Upcoming</span>
                                        <span class="pr-kpi-value d-block"><?= count($upcoming) ?></span>
                                        <span class="pr-kpi-sub d-block">
                                            <?= $nextAppt
                                                ? 'Next: ' . htmlspecialchars($prDate($nextAppt['appointment_date']) ?? '—')
                                                : 'No upcoming visits' ?>
                                        </span>
                                    </span>
                                </button>
                                <button type="button" class="pr-kpi" data-go-tab="treatments">
                                    <span class="pr-kpi-icon"><i class="feather-activity"></i></span>
                                    <span class="min-w-0">
                                        <span class="pr-kpi-label d-block">Active Treatments</span>
                                        <span class="pr-kpi-value d-block"><?= count($active) ?></span>
                                        <span class="pr-kpi-sub d-block"><?= $active ? 'In progress or pending' : 'None in progress' ?></span>
                                    </span>
                                </button>
                                <button type="button" class="pr-kpi <?= $balance > 0 ? 'is-alert' : 'is-ok' ?>" data-go-tab="billing">
                                    <span class="pr-kpi-icon"><i class="feather-credit-card"></i></span>
                                    <span class="min-w-0">
                                        <span class="pr-kpi-label d-block">Outstanding Balance</span>
                                        <span class="pr-kpi-value d-block">₱<?= number_format($balance, 2) ?></span>
                                        <span class="pr-kpi-sub d-block">
                                            <?= (int)($summary['invoice_count'] ?? 0)
                                                ? (int)$summary['invoice_count'] . ' invoice' . ((int)$summary['invoice_count'] === 1 ? '' : 's') . ' · ₱' . number_format((float)($summary['total_paid'] ?? 0), 2) . ' paid'
                                                : 'No invoices yet' ?>
                                        </span>
                                    </span>
                                </button>
                                <button type="button" class="pr-kpi" data-go-tab="appointments">
                                    <span class="pr-kpi-icon"><i class="feather-clock"></i></span>
                                    <span class="min-w-0">
                                        <span class="pr-kpi-label d-block">Most Recent Visit</span>
                                        <span class="pr-kpi-value d-block"><?= htmlspecialchars($prDate($patient['last_visit'] ?? null) ?? '—') ?></span>
                                        <span class="pr-kpi-sub d-block"><?= !empty($patient['last_visit']) ? 'See appointment history' : 'No visit recorded' ?></span>
                                    </span>
                                </button>
                            </div>

                            <div class="pr-ov-grid">
                                <section class="pr-section">
                                    <h6 class="pr-section-title">
                                        <span>Upcoming Appointments</span>
                                        <?php if ($upcoming): ?><button type="button" class="pr-link-btn" data-go-tab="appointments">View all</button><?php endif; ?>
                                    </h6>
                                    <?php if (empty($upcoming)): ?>
                                        <div class="pr-empty"><i class="feather-calendar"></i>No upcoming appointments scheduled.</div>
                                    <?php else: ?>
                                        <ul class="pr-list">
                                            <?php foreach (array_slice($upcoming, 0, 4) as $a): ?>
                                                <li>
                                                    <div class="pr-list-date">
                                                        <small><?= htmlspecialchars($prDate($a['appointment_date'], 'M') ?? '') ?></small>
                                                        <b><?= htmlspecialchars($prDate($a['appointment_date'], 'd') ?? '—') ?></b>
                                                    </div>
                                                    <div class="pr-list-main">
                                                        <div class="pr-list-title" title="<?= htmlspecialchars(appointmentServicesLabel($a)) ?>"><?= htmlspecialchars(appointmentServicesLabel($a)) ?></div>
                                                        <div class="pr-list-sub">
                                                            <?= !empty($a['appointment_time']) ? date('g:i A', strtotime($a['appointment_time'])) : '—' ?>
                                                            · <?= htmlspecialchars($a['dentists']['full_name'] ?? 'Dentist not assigned') ?>
                                                        </div>
                                                    </div>
                                                    <?= prApptPill($a['status'] ?? '') ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </section>

                                <section class="pr-section">
                                    <h6 class="pr-section-title">
                                        <span>Active Treatments</span>
                                        <?php if ($active): ?><button type="button" class="pr-link-btn" data-go-tab="treatments">View all</button><?php endif; ?>
                                    </h6>
                                    <?php if (empty($active)): ?>
                                        <div class="pr-empty"><i class="feather-activity"></i>No treatments in progress.</div>
                                    <?php else: ?>
                                        <ul class="pr-list">
                                            <?php foreach ($active as $t): ?>
                                                <li>
                                                    <div class="pr-list-main">
                                                        <div class="pr-list-title"><?= htmlspecialchars($t['services']['service_name'] ?? 'Treatment') ?></div>
                                                        <div class="pr-list-sub">
                                                            <?= htmlspecialchars($t['treatment_code'] ?? '—') ?>
                                                            <?php if (!empty($t['tooth_number'])): ?> · Tooth <?= htmlspecialchars($t['tooth_number']) ?><?php endif; ?>
                                                            <?php if (!empty($t['current_stage'])): ?> · <?= htmlspecialchars($t['current_stage']) ?><?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <span class="pr-pill pr-pill-amber"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $t['status'] ?? ''))) ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </section>
                            </div>
                        </div>
                        <!-- ══ END OVERVIEW ══ -->

                        <!-- ══ PATIENT DETAILS TAB (api/patient_record_api.php?action=get_details) ══ -->
                        <div class="tab-pane fade" id="pane-details" role="tabpanel" aria-labelledby="tab-details">
                            <div id="details-content">
                                <div class="tab-loading">
                                    <div class="spinner-border" role="status"></div>
                                    <span>Loading patient details…</span>
                                </div>
                            </div>
                        </div>

                        <!-- ══ APPOINTMENTS TAB ══ -->
                        <div class="tab-pane fade" id="pane-appointments" role="tabpanel" aria-labelledby="tab-appointments">
                            <div id="apt-content">
                                <div class="tab-loading">
                                    <div class="spinner-border" role="status"></div>
                                    <span>Loading appointments…</span>
                                </div>
                            </div>
                        </div>

                        <!-- ══ TREATMENTS TAB ══ -->
                        <div class="tab-pane fade" id="pane-treatments" role="tabpanel" aria-labelledby="tab-treatments">
                            <div id="trx-content">
                                <div class="tab-loading">
                                    <div class="spinner-border" role="status"></div>
                                    <span>Loading treatments…</span>
                                </div>
                            </div>
                        </div>

                        <!-- ══ BILLING TAB ══ -->
                        <div class="tab-pane fade" id="pane-billing" role="tabpanel" aria-labelledby="tab-billing">
                            <div id="bill-content">
                                <div class="tab-loading">
                                    <div class="spinner-border" role="status"></div>
                                    <span>Loading billing…</span>
                                </div>
                            </div>
                        </div>

                        <!-- ══ CLINICAL NOTES TAB ══ -->
                        <div class="tab-pane fade" id="pane-notes" role="tabpanel" aria-labelledby="tab-notes">
                            <div id="notes-content">
                                <div class="tab-loading">
                                    <div class="spinner-border" role="status"></div>
                                    <span>Loading notes…</span>
                                </div>
                            </div>
                        </div>

                        <!-- ══ WAIVERS TAB ══ -->
                        <div class="tab-pane fade" id="pane-waivers" role="tabpanel" aria-labelledby="tab-waivers">
                            <div id="waivers-content">
                                <div class="tab-loading">
                                    <div class="spinner-border" role="status"></div>
                                    <span>Loading waivers…</span>
                                </div>
                            </div>
                        </div>

                        <!-- ══ FILES TAB — shown to the patient in the portal's My Files (api/patient_files.php) ══ -->
                        <div class="tab-pane fade" id="pane-files" role="tabpanel" aria-labelledby="tab-files">
                            <div id="files-content">
                                <div class="tab-loading">
                                    <div class="spinner-border" role="status"></div>
                                    <span>Loading files…</span>
                                </div>
                            </div>
                        </div>

                        <!-- ══ VITAL SIGNS TAB ══ -->
                        <div class="tab-pane fade" id="pane-vitals" role="tabpanel" aria-labelledby="tab-vitals">
                            <div id="vitals-content">
                                <div class="tab-loading">
                                    <div class="spinner-border" role="status"></div>
                                    <span>Loading vital signs…</span>
                                </div>
                            </div>
                        </div>

                        <!-- ══ DENTAL CHART TAB — shared odontogram (assets/js/odontogram.js), same as
                             portal-dashboard.php → My Dental Chart and treatments-active.php ══ -->
                        <div class="tab-pane fade" id="pane-dentalchart" role="tabpanel" aria-labelledby="tab-dentalchart">
                            <h6 class="pr-section-title">
                                <span>Dental Chart · Universal numbering 1–32</span>
                                <span class="pr-hint">Patients see this chart read-only in their portal.</span>
                            </h6>

                            <div class="pr-odo-stats" aria-label="Chart summary">
                                <div class="pr-odo-stat"><span>Teeth charted</span><b id="pr_stat_charted">—</b></div>
                                <div class="pr-odo-stat"><span>With decay</span><b id="pr_stat_decay">—</b></div>
                                <div class="pr-odo-stat"><span>Restored</span><b id="pr_stat_restored">—</b></div>
                                <div class="pr-odo-stat"><span>Missing</span><b id="pr_stat_missing">—</b></div>
                            </div>

                            <div class="pr-odo-layout">
                                <div class="pr-odo-card">
                                    <div class="odo-legend" id="pr_odoLegend" aria-label="Chart legend"></div>
                                    <div class="pr-odo-body">
                                        <div id="pr_odoLoading" class="odo-state">
                                            <span class="spinner-border spinner-border-sm" role="status"></span>
                                            <span>Loading dental chart…</span>
                                        </div>
                                        <div id="pr_odoError" class="odo-state odo-state-error" style="display:none;"></div>
                                        <div id="pr_odoChart" class="odo-chart" style="display:none;">
                                            <div class="odo-sides" aria-hidden="true">
                                                <span><i class="feather-arrow-left me-1"></i>Patient's Right</span>
                                                <span>Patient's Left<i class="feather-arrow-right ms-1"></i></span>
                                            </div>
                                            <div class="odo-arch-label">Upper</div>
                                            <div class="odo-row odo-upper" id="pr_odoUpper" role="group" aria-label="Upper teeth, 1 to 16"></div>
                                            <div class="odo-occlusal" aria-hidden="true"></div>
                                            <div class="odo-row odo-lower" id="pr_odoLower" role="group" aria-label="Lower teeth, 17 to 32"></div>
                                            <div class="odo-arch-label">Lower</div>
                                            <p class="odo-extra" id="pr_odoExtra" style="display:none;"></p>
                                        </div>
                                    </div>
                                    <?php if ($canEditChart): ?>
                                        <div class="pr-odo-save" id="pr_chartSaveBar" style="display:none;">
                                            <span id="pr_chartPending" aria-live="polite">No unsaved changes</span>
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <label class="visually-hidden" for="pr_chartSource">Update type</label>
                                                <select class="form-select form-select-sm" id="pr_chartSource" title="Update type (recorded in chart history)">
                                                    <option value="consultation">Initial Consultation</option>
                                                    <option value="treatment_record">Treatment Record</option>
                                                    <option value="correction">Correction</option>
                                                </select>
                                                <button type="button" class="btn btn-primary btn-sm px-3" id="pr_chartSave" disabled>
                                                    <i class="feather-save me-1"></i> Save Changes
                                                </button>
                                                <button type="button" class="btn btn-light btn-sm" id="pr_chartDiscard" disabled>Discard</button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <aside class="odo-panel pr-odo-panel" id="pr_odoPanel" aria-live="polite">
                                    <div class="odo-panel-empty" id="pr_odoPanelEmpty">
                                        <span class="odo-panel-empty-icon"><i class="feather-mouse-pointer"></i></span>
                                        <div class="odo-panel-empty-title">Select a tooth</div>
                                        <p><?= $canEditChart
                                            ? 'Click any tooth to view its record and update its condition, surfaces or notes.'
                                            : 'Click any tooth to view its condition, surfaces, notes and history.' ?></p>
                                    </div>
                                    <div id="pr_odoPanelBody" style="display:none;"></div>
                                </aside>
                            </div>
                        </div>

                    </div><!-- /.tab-content -->
                </div><!-- /.card -->

            </div><!-- /.main-content -->
        </div>
    </main>

    <!-- ── File / waiver preview (Files + Waivers Eye icon). The document is
         streamed by api/patient_files.php / api/patient_waivers.php, which
         check that it belongs to this patient and that the user may see the
         patient; it is shown from a local blob URL (no file path exposed). ── -->
    <div class="modal fade es-detail-modal" id="prPreviewModal" tabindex="-1" aria-labelledby="prPreviewTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-sm-down">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap min-w-0">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-eye"></i></div>
                        <div class="min-w-0">
                            <h5 class="apt-record-title mb-0 text-truncate" id="prPreviewTitle">Preview</h5>
                            <div class="apt-record-meta">File preview</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="pr-preview-stage" id="prPreviewStage"></div>
                </div>
                <div class="modal-footer apt-record-footer">
                    <a class="btn btn-primary" id="prPreviewDownload" href="#"><i class="feather-download me-1"></i> Download</a>
                    <button type="button" class="btn btn-outline-primary" id="prPreviewPrint"><i class="feather-printer me-1"></i> Print</button>
                    <span class="rec-foot-spacer"></span>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Print preview (patient-record-print.php?embed=1 in a frame) ── -->
    <div class="modal fade es-detail-modal" id="prPrintModal" tabindex="-1" aria-labelledby="prPrintTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-printer"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="prPrintTitle">Print Patient Record</h5>
                            <div class="apt-record-meta">Check the preview, then print</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0 pr-print-body">
                    <div class="pr-print-loading" id="prPrintLoading"><span class="spinner-border spinner-border-sm" role="status"></span> Preparing the record…</div>
                    <iframe id="prPrintFrame" class="pr-print-frame" title="Patient record print preview"></iframe>
                </div>
                <div class="modal-footer apt-record-footer">
                    <button type="button" class="btn btn-primary" id="prPrintGo" disabled><i class="feather-printer me-1"></i> Print</button>
                    <span class="rec-foot-spacer"></span>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/custom-select.js?v=3"></script>
    <script src="assets/js/es-records.js?v=3"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/es-swal.js?v=3"></script>
    <script src="assets/js/admin.js?v=7"></script>
    <script src="assets/js/es-ajax.js?v=7"></script>
    <script src="assets/js/odontogram.js?v=2"></script>
    <script src="assets/js/patient-record-chart.js?v=1"></script>

    <script>
        // ── Patient ID from PHP ───────────────────────────────────────────────
        const PATIENT_ID = <?= $patientId ?>;

        // ── Dental chart: shared odontogram for THIS patient (assets/js/patient-record-chart.js) ──
        PatientChart.init({
            patientId: PATIENT_ID,
            patientName: <?= json_encode($patient['full_name'] ?? '—') ?>,
            canEdit: <?= $canEditChart ? 'true' : 'false' ?>,
        });

        // Overview summaries jump to their tab
        document.addEventListener('click', e => {
            const go = e.target.closest('[data-go-tab]');
            if (!go) return;
            const tab = document.getElementById('tab-' + go.dataset.goTab);
            if (tab) {
                bootstrap.Tab.getOrCreateInstance(tab).show();
                tab.scrollIntoView({ block: 'nearest', inline: 'center' });
            }
        });

        // Status pill (colour follows the status meaning; label is the stored status)
        function pill(status) {
            const s = String(status || '').toLowerCase();
            const map = {
                confirmed: 'blue', checked_in: 'blue', in_progress: 'blue', ongoing: 'blue',
                completed: 'green', paid: 'green', signed: 'green',
                pending: 'amber', rescheduled: 'amber', partial: 'amber', partially_paid: 'amber', unpaid: 'red',
                cancelled: 'red', no_show: 'noshow', declined: 'red', overdue: 'red',
            };
            return `<span class="pr-pill ${map[s] ? 'pr-pill-' + map[s] : ''}">${esc(ucwords(s))}</span>`;
        }

        function ucwords(str) {
            if (!str) return '—';
            return String(str).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        }

        function emptyState(icon, text) {
            return `<div class="pr-empty"><i class="feather-${icon}"></i>${text}</div>`;
        }

        function table(head, body, foot = '') {
            return `<div class="pr-table-wrap"><table class="table pr-table mb-0">
                <thead><tr>${head}</tr></thead><tbody>${body}</tbody>${foot}</table></div>`;
        }

        function serviceNames(a) {
            return (a.all_service_names && a.all_service_names.length) ? a.all_service_names.join(', ') : a.services?.service_name;
        }

        // ── Helper functions ─────────────────────────────────────────────────

        // Escape HTML to prevent XSS in rendered table content
        function esc(str) {
            if (str === null || str === undefined) return '—';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // Format a date string to "Jan 01, 2025"
        function fmtDate(val) {
            if (!val) return '—';
            try {
                return new Date(val + 'T00:00:00').toLocaleDateString('en-PH', {
                    year: 'numeric',
                    month: 'short',
                    day: '2-digit'
                });
            } catch (e) {
                return val;
            }
        }

        // Format a time string "HH:MM:SS" to "9:00 AM"
        function fmtTime(val) {
            if (!val) return '—';
            try {
                const [h, m] = val.split(':');
                const d = new Date();
                d.setHours(+h, +m);
                return d.toLocaleTimeString('en-US', {
                    hour: 'numeric',
                    minute: '2-digit'
                });
            } catch (e) {
                return val;
            }
        }

        // Format a number to 2 decimal places with comma separators
        function fmt2(val) {
            return parseFloat(val || 0).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Capitalise first letter of a string
        function ucfirst(str) {
            if (!str) return '—';
            const s = String(str).replace(/_/g, ' ');
            return s.charAt(0).toUpperCase() + s.slice(1);
        }

        // ── Tab lazy-load tracker ────────────────────────────────────────────
        const loaded = {
            details: false,
            files: false,
            appointments: false,
            treatments: false,
            billing: false,
            notes: false,
            waivers: false,
            vitals: false,
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
                if (target === '#pane-details' && !loaded.details) {
                    loaded.details = true;
                    loadDetails();
                }
                if (target === '#pane-files' && !loaded.files) {
                    loaded.files = true;
                    loadFiles();
                }
                if (target === '#pane-waivers' && !loaded.waivers) {
                    loaded.waivers = true;
                    loadWaivers();
                }
                if (target === '#pane-vitals' && !loaded.vitals) {
                    loaded.vitals = true;
                    loadVitalSigns();
                }
                if (target === '#pane-dentalchart') PatientChart.load();
            });
        });

        // ── Generic tab loader (GET request to patient_record_api.php) ───────
        function loadTab(containerId, action) {
            fetch(`api/patient_record_api.php?action=${action}&patient_id=${PATIENT_ID}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.message || 'Load failed.');
                    renderTab(containerId, action, data);
                })
                .catch(err => {
                    // try again the next time the tab is opened
                    const key = { get_appointments: 'appointments', get_treatments: 'treatments', get_billing: 'billing', get_notes: 'notes' }[action];
                    if (key) loaded[key] = false;
                    document.getElementById(containerId).innerHTML =
                        `<p class="text-danger small"><i class="feather-alert-circle me-1"></i>${esc(err.message || 'Could not load this tab.')}</p>`;
                });
        }

        function renderTab(containerId, action, data) {
            const el = document.getElementById(containerId);
            if (action === 'get_appointments') el.innerHTML = renderAppointments(data);
            if (action === 'get_treatments') el.innerHTML = renderTreatments(data);
            if (action === 'get_billing') el.innerHTML = renderBilling(data);
            if (action === 'get_notes') {
                el.innerHTML = renderNotes(data);
                if (window.enhanceDatePickers) window.enhanceDatePickers();
            }
        }

        // Today as YYYY-MM-DD in the browser's (clinic's) time zone — toISOString() is UTC,
        // which gave yesterday's date before 8:00 AM in the Philippines.
        function localToday() {
            const d = new Date();
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }

        // ── Render: Appointments ─────────────────────────────────────────────
        function renderAppointments(data) {
            const upcoming = data.upcoming ?? [];
            const history = (data.history?.rows) ?? [];

            const when = a => `<span class="pr-strong">${fmtDate(a.appointment_date)}</span>
                <span class="pr-sub num">${fmtTime(a.appointment_time)}</span>`;
            const svc = a => `<div class="pr-clamp" title="${esc(serviceNames(a))}">${esc(serviceNames(a))}</div>`;

            const upRows = upcoming.map(a => `<tr>
                    <td>${when(a)}</td>
                    <td>${svc(a)}</td>
                    <td>${esc(a.dentists?.full_name)}</td>
                    <td>${pill(a.status)}</td>
                  </tr>`).join('');

            const histRows = history.map(a => `<tr>
                    <td>${when(a)}</td>
                    <td>${svc(a)}</td>
                    <td>${esc(a.dentists?.full_name)}</td>
                    <td>${esc(a.branches?.branch_name)}</td>
                    <td>${pill(a.status)}</td>
                  </tr>`).join('');

            return `
                <section class="pr-section">
                    <h6 class="pr-section-title"><span>Upcoming</span></h6>
                    ${upcoming.length
                        ? table('<th>Date &amp; Time</th><th>Service</th><th>Dentist</th><th>Status</th>', upRows)
                        : emptyState('calendar', 'No upcoming appointments.')}
                </section>
                <section class="pr-section">
                    <h6 class="pr-section-title"><span>History</span>
                        ${(data.history?.total ?? 0) > history.length
                            ? `<span class="pr-hint">Latest ${history.length} of ${data.history.total}</span>`
                            : ''}</h6>
                    ${history.length
                        ? table('<th>Date &amp; Time</th><th>Service</th><th>Dentist</th><th>Branch</th><th>Status</th>', histRows)
                        : emptyState('clock', 'No past appointments.')}
                </section>`;
        }

        // ── Render: Treatments ───────────────────────────────────────────────
        function renderTreatments(data) {
            const rows = data.treatments ?? [];
            if (!rows.length) return emptyState('activity', 'No treatment records.');

            const tr = rows.map(t => `<tr>
                <td class="pr-strong">${esc(t.treatment_code)}</td>
                <td><div class="pr-clamp" title="${esc(t.services?.service_name)}">${esc(t.services?.service_name)}</div></td>
                <td class="num">${t.tooth_number ? esc(t.tooth_number) : '<span class="text-muted">—</span>'}</td>
                <td>${t.current_stage ? esc(t.current_stage) : '<span class="text-muted">—</span>'}</td>
                <td class="num">${t.treatment_date ? fmtDate(t.treatment_date) : '—'}</td>
                <td class="text-end num">${t.cost != null ? '₱' + fmt2(t.cost) : '—'}</td>
                <td>${esc(t.dentists?.full_name)}</td>
                <td>${pill(t.status)}</td>
              </tr>`).join('');

            return `<h6 class="pr-section-title"><span>Treatment Records</span></h6>` +
                table('<th>Code</th><th>Service</th><th>Tooth</th><th>Stage</th><th>Date</th><th class="text-end">Cost</th><th>Dentist</th><th>Status</th>', tr);
        }

        // ── Render: Billing ──────────────────────────────────────────────────
        function renderBilling(data) {
            const invoices = data.invoices ?? [];
            if (!invoices.length) return emptyState('credit-card', 'No billing records.');

            let total = 0, paid = 0, balance = 0;
            const tr = invoices.map(inv => {
                const bal = parseFloat(inv.balance) || 0;
                total += parseFloat(inv.total_amount) || 0;
                paid += parseFloat(inv.amount_paid) || 0;
                balance += bal;
                return `<tr>
                    <td class="pr-strong">${esc(inv.invoice_code)}</td>
                    <td class="num">${fmtDate(inv.invoice_date)}</td>
                    <td class="text-end num">₱${fmt2(inv.total_amount)}</td>
                    <td class="text-end num">₱${fmt2(inv.amount_paid)}</td>
                    <td class="text-end num ${bal > 0 ? 'pr-balance-due' : 'pr-balance-clear'}">₱${fmt2(inv.balance)}</td>
                    <td>${pill(inv.payment_status)}</td>
                  </tr>`;
            }).join('');

            const foot = `<tfoot><tr>
                    <td colspan="2">Total (${invoices.length} invoice${invoices.length === 1 ? '' : 's'})</td>
                    <td class="text-end num">₱${fmt2(total)}</td>
                    <td class="text-end num">₱${fmt2(paid)}</td>
                    <td class="text-end num ${balance > 0 ? 'pr-balance-due' : ''}">₱${fmt2(balance)}</td>
                    <td></td>
                </tr></tfoot>`;

            return `<h6 class="pr-section-title"><span>Invoices</span>
                    <span class="${balance > 0 ? 'pr-pill pr-pill-red' : 'pr-pill pr-pill-green'}">${balance > 0 ? 'Outstanding ₱' + fmt2(balance) : 'Fully paid'}</span></h6>` +
                table('<th>Invoice</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th>', tr, foot);
        }

        // ── Render: Clinical Notes ───────────────────────────────────────────
        function renderNotes(data) {
            const notes = data.notes ?? [];

            const form = `
                <div class="pr-form">
                    <h6 class="pr-section-title"><span><i class="feather-edit-3 me-1"></i> Add Clinical Note</span></h6>
                    <div id="noteErr" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="noteDate">Date <span class="text-danger">*</span></label>
                            <!-- Shared calendar picker (custom-select.js); clinic-local today, no future dates -->
                            <input type="date" id="noteDate" class="form-control js-date-picker" data-dp-max="today"
                                placeholder="Select date" value="${localToday()}">
                        </div>
                        <div class="col-md-9">
                            <label class="form-label" for="noteText">Note <span class="text-danger">*</span></label>
                            <textarea id="noteText" class="form-control" rows="3"
                                placeholder="Enter clinical observations, treatments discussed, patient complaints, etc."></textarea>
                        </div>
                    </div>
                    <div class="pr-form-foot">
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
                </div>`;

            if (!notes.length) return form + emptyState('file-text', 'No clinical notes on record.');

            return form + `<h6 class="pr-section-title"><span>Notes on Record</span></h6>` +
                notes.map(n => {
                    const created = n.created_at ? new Date(n.created_at).toLocaleString('en-PH', {
                        month: 'short', day: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit'
                    }) : '';
                    return `
                <article class="pr-note">
                    <div class="pr-note-head">
                        <div>
                            <div class="pr-note-date">${fmtDate(n.note_date)}
                                ${n.is_private ? '<span class="pr-pill pr-pill-sm"><i class="feather-lock me-1"></i>Private</span>' : ''}
                            </div>
                            <div class="pr-note-by">
                                <i class="feather-user me-1"></i>${esc(n.dentists?.full_name ?? n.created_by)}
                                ${created ? ' · Added ' + esc(created) : ''}
                            </div>
                        </div>
                        <button class="pr-icon-btn is-danger" title="Delete note" aria-label="Delete note"
                            onclick="deleteNote(${parseInt(n.note_id, 10)})">
                            <i class="feather-trash-2" style="font-size:13px;"></i>
                        </button>
                    </div>
                    <div class="pr-note-text">${esc(n.note_text)}</div>
                </article>`;
                }).join('');
        }

        // ── Patient Details ──────────────────────────────────────────────────
        function loadDetails() {
            fetch(`api/patient_record_api.php?action=get_details&patient_id=${PATIENT_ID}`)
                .then(r => r.json())
                .then(d => {
                    if (!d.success) throw new Error(d.message || 'Could not load the details.');
                    document.getElementById('details-content').innerHTML = renderDetails(d.details);
                })
                .catch(err => {
                    loaded.details = false;   // try again next time the tab opens
                    document.getElementById('details-content').innerHTML =
                        `<p class="text-danger small"><i class="feather-alert-circle me-1"></i>${esc(err.message || 'Failed to load patient details.')}</p>`;
                });
        }

        function renderDetails(d) {
            const row = (label, value) => `<div><dt>${label}</dt><dd class="${value ? '' : 'is-empty'}">${value ? esc(value) : 'Not recorded'}</dd></div>`;
            const noAccount = !d.has_account
                ? '<p class="pr-hint mt-2 mb-0"><i class="feather-info me-1"></i>No patient portal account is linked, so email and address come from the portal profile once the patient registers.</p>'
                : '';
            return `<div class="pr-ov-grid">
                <section class="pr-section">
                    <h6 class="pr-section-title"><span><i class="feather-user me-1"></i> Personal Information</span></h6>
                    <dl class="pr-details">
                        ${row('Full Name', d.full_name)}
                        ${row('Gender', d.gender)}
                        ${row('Birthdate', d.birthdate)}
                        ${row('Age', d.age)}
                    </dl>
                </section>
                <section class="pr-section">
                    <h6 class="pr-section-title"><span><i class="feather-phone me-1"></i> Contact Information</span></h6>
                    <dl class="pr-details">
                        ${row('Contact Number', d.contact_number)}
                        ${row('Email Address', d.email)}
                        ${row('Address', d.address)}
                    </dl>
                    ${noAccount}
                </section>
            </div>`;
        }

        // ── System alerts: the shared design (assets/js/es-swal.js + es-swal.css),
        //    same pattern as Billing / Appointments. Primary action LEFT, Cancel RIGHT.
        function prAlert(tone, glyph, title, text, chip) {
            return Swal.fire({
                icon: tone === 'success' ? 'success' : 'error',
                iconHtml: `<i class="feather-${glyph} es-swal-glyph"></i>`,
                title,
                html: `<p class="es-swal-text">${esc(text)}</p>` +
                    (chip ? `<span class="es-swal-chip"><i class="feather-${chip[0]}"></i> ${esc(chip[1])}</span>` : ''),
                confirmButtonText: 'Okay',
                buttonsStyling: false,
                customClass: ES_SWAL_TONE(tone === 'success' ? 'success' : 'warn'),
                ...(tone === 'success' ? { timer: 1800, timerProgressBar: true } : {}),
            });
        }
        function prConfirmDelete(title, text, chipIcon, name, confirmText) {
            return Swal.fire({
                icon: 'warning',
                iconHtml: '<i class="feather-trash-2 es-swal-glyph"></i>',
                title,
                html: `<p class="es-swal-text">${esc(text)}</p><span class="es-swal-chip"><i class="feather-${chipIcon}"></i> ${esc(name)}</span>`,
                showCancelButton: true,
                confirmButtonText: confirmText,   // LEFT (SweetAlert2 default order)
                cancelButtonText: 'Cancel',       // RIGHT
                focusCancel: true,
                buttonsStyling: false,
                customClass: ES_SWAL_TONE('danger'),
            });
        }

        // ── Preview modal (Files + Waivers): stays on this page ──────────────
        //   url: the access-checked stream endpoint (inline); dlUrl: same with download=1
        const prPreview = (function () {
            const modalEl = document.getElementById('prPreviewModal');
            const stage = document.getElementById('prPreviewStage');
            const title = document.getElementById('prPreviewTitle');
            const dl = document.getElementById('prPreviewDownload');
            const pr = document.getElementById('prPreviewPrint');
            let blobUrl = null, seq = 0, printable = null;
            const clear = () => { if (blobUrl) URL.revokeObjectURL(blobUrl); blobUrl = null; printable = null; stage.innerHTML = ''; };
            modalEl.addEventListener('hidden.bs.modal', () => { seq++; clear(); });
            pr.addEventListener('click', () => { if (printable) printable(); });
            const msg = (icon, text) => `<div class="pr-preview-msg"><i class="feather-${icon}"></i><p>${esc(text)}</p></div>`;

            function printImage(src, name) {
                const f = document.createElement('iframe');
                f.className = 'pr-preview-printframe';
                f.setAttribute('aria-hidden', 'true');
                document.body.appendChild(f);
                const d = f.contentDocument;
                d.open();
                d.write(`<!doctype html><title>${esc(name)}</title><style>html,body{margin:0}img{max-width:100%;}</style><img src="${src}">`);
                d.close();
                const img = d.querySelector('img');
                const go = () => { f.contentWindow.focus(); f.contentWindow.print(); setTimeout(() => f.remove(), 1000); };
                img.complete ? go() : img.addEventListener('load', go);
            }

            function open(name, url, dlUrl) {
                const mine = ++seq;
                clear();
                title.textContent = name;   // the header icon tile already shows the eye
                dl.href = dlUrl;
                dl.classList.remove('disabled');
                pr.disabled = true;
                stage.innerHTML = `<div class="pr-print-loading"><span class="spinner-border spinner-border-sm" role="status"></span> Loading the document…</div>`;
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
                fetch(url, { credentials: 'same-origin' })
                    .then(r => r.ok ? r.blob() : r.text().then(t => { throw new Error(t && t.length < 200 && !/</.test(t) ? t : 'This document is not available.'); }))
                    .then(blob => {
                        if (mine !== seq) return;
                        blobUrl = URL.createObjectURL(blob);
                        const type = blob.type || '';
                        if (type.startsWith('image/')) {
                            stage.innerHTML = `<img class="pr-preview-img" alt="${esc(name)}" src="${blobUrl}">`;
                            printable = () => printImage(blobUrl, name);
                        } else if (type === 'application/pdf') {
                            stage.innerHTML = `<iframe class="pr-preview-frame" title="${esc(name)}" src="${blobUrl}"></iframe>`;
                            const f = stage.querySelector('iframe');
                            printable = () => { try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) { window.open(blobUrl); } };
                        } else {
                            stage.innerHTML = msg('file', 'A preview is not available for this file type. Use Download to open it.');
                        }
                        pr.disabled = !printable;
                    })
                    .catch(err => {
                        if (mine !== seq) return;
                        stage.innerHTML = msg('alert-circle', err.message || 'This document is not available.');
                        dl.classList.add('disabled');
                    });
            }
            return { open };
        })();

        function previewPatientFile(fileId, name) {
            const url = `api/patient_files.php?action=view&patient_id=${PATIENT_ID}&file_id=${parseInt(fileId, 10)}`;
            prPreview.open(name, url, url + '&download=1');
        }
        function previewWaiver(waiverId, name) {
            const url = `api/patient_waivers.php?action=view&patient_id=${PATIENT_ID}&waiver_id=${parseInt(waiverId, 10)}`;
            prPreview.open(name, url, url + '&download=1');
        }

        // ── Files (shown to the patient in the portal's My Files) ────────────
        let pfTypes = [], pfMaxMb = 10;
        function loadFiles() {
            fetch(`api/patient_files.php?action=list&patient_id=${PATIENT_ID}`)
                .then(r => r.json())
                .then(d => {
                    if (!d.success) throw new Error(d.message || 'Failed to load files.');
                    pfTypes = d.types || [];
                    pfMaxMb = d.max_mb || 10;
                    document.getElementById('files-content').innerHTML = renderFiles(d.files || []);
                    if (window.enhanceCustomSelects) window.enhanceCustomSelects();
                })
                .catch(err => {
                    loaded.files = false;
                    document.getElementById('files-content').innerHTML =
                        `<p class="text-danger small"><i class="feather-alert-circle me-1"></i>${esc(err.message || 'Failed to load files.')}</p>`;
                });
        }

        function renderFiles(files) {
            const form = `
                <div class="pr-form">
                    <h6 class="pr-section-title"><span><i class="feather-upload me-1"></i> Upload File</span>
                        <span class="pr-hint">Appears in the patient's portal under My Files</span></h6>
                    <div id="pfUploadErr" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <div class="row g-3 align-items-end pr-upload-row">
                        <div class="col-md-4">
                            <label class="form-label" for="pfType">File Type <span class="text-danger">*</span></label>
                            <select id="pfType" class="form-select js-custom-select" data-cs-float>
                                <option value="">Select Type</option>
                                ${pfTypes.map(t => `<option value="${esc(t)}">${esc(t)}</option>`).join('')}
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="pfFile">File <span class="text-danger">*</span>
                                <span class="pr-hint">PDF, JPG or PNG · max ${pfMaxMb} MB</span>
                            </label>
                            <input type="file" id="pfFile" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-primary w-100" id="pfUploadBtn" onclick="uploadPatientFile()">
                                <i class="feather-upload me-1"></i> Upload
                            </button>
                        </div>
                    </div>
                </div>`;
            if (!files.length) return form + emptyState('folder', 'No files uploaded yet.');
            const fmt = v => v ? new Date(String(v).replace(' ', 'T')).toLocaleDateString('en-PH', { month: 'short', day: '2-digit', year: 'numeric' }) : '—';
            const rows = files.map(f => {
                const id = parseInt(f.file_id, 10);
                const url = `api/patient_files.php?action=view&patient_id=${PATIENT_ID}&file_id=${id}`;
                return `<tr>
                    <td><div class="pr-wrap pr-strong" title="${esc(f.file_name || '')}">${esc(f.file_name || f.file_type || 'File')}</div></td>
                    <td>${esc(f.file_type || '—')}</td>
                    <td>${esc(f.uploaded_by || '—')}</td>
                    <td class="num">${fmt(f.uploaded_at)}</td>
                    <td class="text-end">
                        <span class="pr-actions">
                            <button type="button" class="pr-icon-btn" title="View file" aria-label="View file"
                                onclick="previewPatientFile(${id}, ${esc(JSON.stringify(String(f.file_name || f.file_type || 'File')))})"><i class="feather-eye" style="font-size:13px;"></i></button>
                            <a href="${url}&download=1" class="pr-icon-btn" title="Download file" aria-label="Download file"><i class="feather-download" style="font-size:13px;"></i></a>
                            <button type="button" class="pr-icon-btn is-danger" title="Delete file" aria-label="Delete file"
                                onclick="deletePatientFile(${id}, ${esc(JSON.stringify(String(f.file_name || f.file_type || 'this file')))})">
                                <i class="feather-trash-2" style="font-size:13px;"></i>
                            </button>
                        </span>
                    </td>
                </tr>`;
            }).join('');
            return form +
                `<h6 class="pr-section-title"><span>Files on Record</span></h6>` +
                table('<th>File</th><th>Type</th><th>Uploaded By</th><th>Date</th><th class="text-end">Actions</th>', rows);
        }

        function uploadPatientFile() {
            const type = document.getElementById('pfType').value;
            const file = document.getElementById('pfFile').files[0];
            const errBox = document.getElementById('pfUploadErr');
            const btn = document.getElementById('pfUploadBtn');
            const fail = msg => { errBox.textContent = msg; errBox.style.display = 'block'; };
            errBox.style.display = 'none';
            if (!type) return fail('Please select a file type.');
            if (!file) return fail('Please choose a file to upload.');
            if (file.size > pfMaxMb * 1024 * 1024) return fail(`File must be under ${pfMaxMb} MB.`);

            const fd = new FormData();
            fd.append('action', 'upload');
            fd.append('patient_id', PATIENT_ID);
            fd.append('file_type', type);
            fd.append('patient_file', file);
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Uploading…';
            fetch('api/patient_files.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (!d.success) return fail(d.message || 'Upload failed.');
                    // Only after the server saved it; one alert, no toast
                    prAlert('success', 'check-circle', 'File Uploaded', 'The file was uploaded and now appears in the patient\'s My Files.', ['file', file.name]);
                    loadFiles();
                })
                .catch(() => fail('Upload failed. Please check your connection and try again.'))
                .finally(() => { if (btn.isConnected) { btn.disabled = false; btn.innerHTML = '<i class="feather-upload me-1"></i> Upload'; } });
        }

        function deletePatientFile(fileId, name) {
            prConfirmDelete('Delete File?', 'This file will be deleted and the patient will no longer see it in My Files. This cannot be undone.',
                'file', name, 'Delete File').then(r => {
                if (!r.isConfirmed) return;
                const fd = new FormData();
                fd.append('action', 'delete');
                fd.append('patient_id', PATIENT_ID);
                fd.append('file_id', fileId);
                fetch('api/patient_files.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(d => {
                        if (!d.success) return prAlert('warn', 'alert-circle', 'Delete Failed', d.message || 'Could not delete the file.');
                        // Only after the server confirmed the deletion
                        prAlert('success', 'check-circle', 'File Deleted', 'The file was deleted from the patient\'s record.', ['file', name]);
                        loadFiles();
                    })
                    .catch(() => prAlert('warn', 'alert-circle', 'Delete Failed', 'Could not reach the server. Please check your connection and try again.'));
            });
        }

        // ── Print: preview in a modal, print from the frame ──────────────────
        (function () {
            const modalEl = document.getElementById('prPrintModal');
            const frame = document.getElementById('prPrintFrame');
            const go = document.getElementById('prPrintGo');
            const wait = document.getElementById('prPrintLoading');
            const openBtn = document.getElementById('prPrintBtn');
            const openHtml = openBtn.innerHTML;
            let slowTimer = 0;
            // "Preparing…" only when it is actually slow (no flash on a quick load)
            const settle = () => {
                clearTimeout(slowTimer);
                wait.hidden = true;
                openBtn.disabled = false;
                openBtn.innerHTML = openHtml;
            };
            wait.hidden = true;
            openBtn.addEventListener('click', () => {
                if (openBtn.disabled) return;
                // Reload each time so the printout reflects any changes made on this page
                // (the print page now needs one database request instead of seven)
                go.disabled = true;
                openBtn.disabled = true;
                slowTimer = setTimeout(() => {
                    wait.hidden = false;
                    openBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Preparing…';
                }, 350);
                frame.src = `patient-record-print.php?id=${PATIENT_ID}&embed=1&t=${Date.now()}`;
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });
            frame.addEventListener('load', () => {
                if (!frame.getAttribute('src')) return;
                settle();
                go.disabled = false;
            });
            frame.addEventListener('error', settle);
            go.addEventListener('click', () => {
                const w = frame.contentWindow;
                if (!w) return;
                w.focus();
                w.print();
            });
            modalEl.addEventListener('hidden.bs.modal', () => { frame.removeAttribute('src'); settle(); });
        })();

        // ── Render: Waivers ──────────────────────────────────────────────────
        function loadWaivers() {
            fetch(`api/patient_waivers.php?action=get_waivers&patient_id=${PATIENT_ID}`)
                .then(r => r.json())
                .then(data => {
                    document.getElementById('waivers-content').innerHTML = renderWaivers(data.waivers ?? []);
                    if (window.enhanceCustomSelects) window.enhanceCustomSelects();   // Waiver Type → shared custom dropdown
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
                <div class="pr-form">
                    <h6 class="pr-section-title"><span><i class="feather-upload me-1"></i> Upload Waiver</span></h6>
                    <div id="waiverUploadErr" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <div class="row g-3 align-items-end pr-upload-row">
                        <div class="col-md-5">
                            <label class="form-label" for="waiverType">Waiver Type <span class="text-danger">*</span></label>
                            <select id="waiverType" class="form-select js-custom-select" data-cs-float>
                                <option value="">Select Type</option>
                                ${typeOptions}
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="waiverFile">File <span class="text-danger">*</span>
                                <span class="pr-hint">PDF, JPG or PNG · max 5MB</span>
                            </label>
                            <input type="file" id="waiverFile" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary w-100" onclick="uploadWaiver()">
                                <i class="feather-upload me-1"></i> Upload
                            </button>
                        </div>
                    </div>
                </div>`;

            if (!waivers.length) {
                return uploadForm + emptyState('file-text', 'No waivers uploaded yet.');
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
                return `<tr>
                    <td class="pr-strong">${esc(w.waiver_type)}</td>
                    <td><div class="pr-wrap text-muted" title="${esc(fileName)}">${esc(fileName)}</div></td>
                    <td>${pill(signed ? 'signed' : 'pending')}</td>
                    <td>${signed ? esc(w.signed_by) : '<span class="text-muted">—</span>'}</td>
                    <td class="num">${signed ? signedAt : '<span class="text-muted">—</span>'}</td>
                    <td class="text-end">
                        <span class="pr-actions">
                            <button type="button" class="pr-icon-btn" title="View waiver" aria-label="View waiver"
                                onclick="previewWaiver(${parseInt(w.waiver_id, 10)}, ${esc(JSON.stringify(String(w.waiver_type || 'Waiver')))})">
                                <i class="feather-eye" style="font-size:13px;"></i>
                            </button>
                            <button class="pr-icon-btn is-danger" title="Delete waiver" aria-label="Delete waiver"
                                onclick="deleteWaiver(${parseInt(w.waiver_id, 10)}, ${esc(JSON.stringify(String(w.waiver_type ?? '')))})">
                                <i class="feather-trash-2" style="font-size:13px;"></i>
                            </button>
                        </span>
                    </td>
                </tr>`;
            }).join('');

            return uploadForm +
                `<h6 class="pr-section-title"><span>Waivers on File</span></h6>` +
                table('<th>Type</th><th>File</th><th>Status</th><th>Signed By</th><th>Signed At</th><th class="text-end">Actions</th>', rows);
        }

        function uploadWaiver() {
            const type = document.getElementById('waiverType').value;
            const file = document.getElementById('waiverFile').files[0];
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
            fd.append('waiver_file', file);

            fetch('api/patient_waivers.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        prAlert('success', 'check-circle', 'Waiver Uploaded', 'The waiver was saved to the patient\'s record.', ['file-text', type]);
                        loaded.waivers = false;
                        loadWaivers();
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
            prConfirmDelete('Delete Waiver?', 'This waiver and its file will be deleted from the patient\'s record. This cannot be undone.',
                'file-text', type || 'Waiver', 'Delete Waiver').then(r => {
                if (!r.isConfirmed) return;
                // Form fields like every other action here (the old JSON body never
                // reached $_POST, so the API answered "Invalid patient.")
                const fd = new FormData();
                fd.append('action', 'delete_waiver');
                fd.append('patient_id', PATIENT_ID);
                fd.append('waiver_id', waiverId);
                fetch('api/patient_waivers.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(d => {
                        if (!d.success) return prAlert('warn', 'alert-circle', 'Delete Failed', d.message || 'Could not delete the waiver.');
                        prAlert('success', 'check-circle', 'Waiver Deleted', 'The waiver was deleted from the patient\'s record.', ['file-text', type || 'Waiver']);
                        loaded.waivers = false;
                        loadWaivers();
                    })
                    .catch(() => prAlert('warn', 'alert-circle', 'Delete Failed', 'Could not reach the server. Please check your connection and try again.'));
            });
        }

        // ── Clinical Notes: save / delete ────────────────────────────────────
        function saveNote() {
            const noteDate = document.getElementById('noteDate').value;
            const noteText = document.getElementById('noteText').value.trim();
            const isPrivate = document.getElementById('notePrivate').checked ? '1' : '0';
            const errBox = document.getElementById('noteErr');

            errBox.style.display = 'none';
            if (!noteDate) {
                errBox.textContent = 'Please select a date.';
                errBox.style.display = 'block';
                return;
            }
            if (!noteText) {
                errBox.textContent = 'Please enter a note.';
                errBox.style.display = 'block';
                return;
            }

            const fd = new FormData();
            fd.append('action', 'add_note');
            fd.append('patient_id', PATIENT_ID);
            fd.append('note_date', noteDate);
            fd.append('note_text', noteText);
            fd.append('is_private', isPrivate);

            fetch('api/patient_record_api.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        Swal.fire({
                                icon: 'success',
                                title: 'Note saved!',
                                timer: 1400,
                                showConfirmButton: false
                            })
                            .then(() => {
                                loaded.notes = false;
                                loadTab('notes-content', 'get_notes');
                            });
                    } else {
                        errBox.textContent = d.message || 'Failed to save note.';
                        errBox.style.display = 'block';
                    }
                })
                .catch(() => {
                    errBox.textContent = 'Network error.';
                    errBox.style.display = 'block';
                });
        }

        function deleteNote(noteId) {
            Swal.fire({
                title: 'Delete Note?',
                text: 'This will be permanently removed.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete',
                confirmButtonColor: '#dc3545',
            }).then(r => {
                if (!r.isConfirmed) return;
                const fd = new FormData();
                fd.append('action', 'delete_note');
                fd.append('patient_id', PATIENT_ID);
                fd.append('note_id', noteId);
                fetch('api/patient_record_api.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            loaded.notes = false;
                            loadTab('notes-content', 'get_notes');
                        } else Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: d.message
                        });
                    });
            });
        }

        // ── Vital Signs ──────────────────────────────────────────────────────
        function loadVitalSigns() {
            fetch('api/checkin_appointment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'get_vital_signs',
                        patient_id: PATIENT_ID
                    }),
                })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.message || 'Load failed.');
                    document.getElementById('vitals-content').innerHTML = renderVitalSigns(data.vitals ?? []);
                })
                .catch(err => {
                    document.getElementById('vitals-content').innerHTML =
                        `<p class="text-danger small"><i class="feather-alert-circle me-1"></i>${err.message}</p>`;
                });
        }

        function renderVitalSigns(rows) {
            if (!rows.length) {
                return emptyState('heart', 'No vital signs recorded yet. Vital signs are captured during patient check-in.');
            }

            // Reading flag — thresholds below are the existing rules, unchanged
            const TONE = { danger: 'red', warning: 'amber', success: 'green', secondary: '' };
            const tag = (tone, label) => `<span class="pr-pill pr-pill-sm ${TONE[tone] ? 'pr-pill-' + TONE[tone] : ''}">${label}</span>`;
            const reading = (value, unit, flag) => value
                ? `<span class="pr-strong num">${esc(String(value))}</span>${unit ? ` <span class="text-muted small">${unit}</span>` : ''}${flag}`
                : '<span class="text-muted">—</span>';

            const tableRows = rows.map(v => {

                // BP badge
                let bpBadge = '';
                if (v.blood_pressure) {
                    const parts = v.blood_pressure.split('/');
                    if (parts.length === 2) {
                        const sys = parseInt(parts[0]),
                            dia = parseInt(parts[1]);
                        if (sys >= 180 || dia >= 120) bpBadge = tag('danger', 'Crisis');
                        else if (sys >= 140 || dia >= 90) bpBadge = tag('warning', 'High');
                        else if (sys >= 130 || dia >= 80) bpBadge = tag('warning', 'Elevated');
                        else if (sys >= 90 && dia >= 60) bpBadge = tag('success', 'Normal');
                        else bpBadge = tag('secondary', 'Low');
                    }
                }

                // Pulse badge
                let pulseBadge = '';
                if (v.pulse_rate) {
                    const pr = parseInt(v.pulse_rate);
                    if (pr < 60) pulseBadge = tag('secondary', 'Low');
                    else if (pr <= 100) pulseBadge = tag('success', 'Normal');
                    else pulseBadge = tag('warning', 'High');
                }

                // Temperature badge
                let tempBadge = '';
                if (v.temperature) {
                    const temp = parseFloat(v.temperature);
                    if (temp >= 38.0) tempBadge = tag('danger', 'Fever');
                    else if (temp >= 37.5) tempBadge = tag('warning', 'Elevated');
                    else if (temp >= 36.0) tempBadge = tag('success', 'Normal');
                    else tempBadge = tag('secondary', 'Low');
                }

                const checkedAt = v.checked_at ?
                    new Date(v.checked_at).toLocaleString('en-PH', {
                        month: 'short',
                        day: '2-digit',
                        year: 'numeric',
                        hour: 'numeric',
                        minute: '2-digit'
                    }) :
                    '—';

                return `<tr>
                    <td class="num">${checkedAt}</td>
                    <td class="pr-strong">${esc(v.appointment_code)}</td>
                    <td>${reading(v.blood_pressure, 'mmHg', bpBadge)}</td>
                    <td>${reading(v.pulse_rate, 'bpm', pulseBadge)}</td>
                    <td>${reading(v.temperature, '°C', tempBadge)}</td>
                    <td>${esc(v.checked_by)}</td>
                    <td><div class="pr-clamp text-muted" title="${v.notes ? esc(v.notes) : ''}">${v.notes ? esc(v.notes) : '—'}</div></td>
                </tr>`;
            }).join('');

            return `<h6 class="pr-section-title"><span>Recorded at Check-in</span></h6>` +
                table('<th>Date &amp; Time</th><th>Appointment</th><th>Blood Pressure</th><th>Pulse Rate</th><th>Temperature</th><th>Checked By</th><th>Notes</th>', tableRows);
        }
    </script>
</body>

</html>