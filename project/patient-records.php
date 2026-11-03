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

$patient = getPatientById($patientId);
if (!$patient) {
    header('Location: patients-list.php?error=patient_not_found');
    exit;
}

$summary  = getPatientFinancialSummary($patientId);
$active   = getPatientActiveTreatments($patientId);
$upcoming = getPatientUpcomingAppointments($patientId);

$age      = calculateAge($patient['birthdate'] ?? null);
$fullName = htmlspecialchars($patient['full_name'] ?? '—');

// Dental chart editing: the same staff roles that could use the old chart modal on this page
$canEditChart = in_array($_SESSION['role'] ?? '', ['owner', 'admin', 'dentist'], true);

// Header + Overview helpers (real patient data only)
$prDate   = fn(?string $d, string $fmt = 'M d, Y') => !empty($d) && strtotime($d) ? date($fmt, strtotime($d)) : null;
$status   = strtolower($patient['status'] ?? 'active');
$balance  = (float)($summary['total_balance'] ?? 0);
$nextAppt = $upcoming[0] ?? null;
$meta = [
    ['feather-phone',    'Contact',    $patient['contact_number'] ?? null],
    ['feather-user',     'Gender',     !empty($patient['gender']) ? ucfirst($patient['gender']) : null],
    ['feather-hash',     'Age',        ($age && $age !== '—') ? $age : null],
    ['feather-map-pin',  'Branch',     $patient['branches']['branch_name'] ?? null],
    ['feather-calendar', 'Birthdate',  $prDate($patient['birthdate'] ?? null)],
    ['feather-clock',    'Last Visit', $prDate($patient['last_visit'] ?? null)],
];
function prApptPill(?string $status): string
{
    $s = strtolower($status ?? '');
    $cls = match ($s) {
        'confirmed', 'checked_in', 'in_progress' => 'pr-pill-blue',
        'completed'                              => 'pr-pill-green',
        'pending', 'rescheduled'                 => 'pr-pill-amber',
        'cancelled', 'no_show', 'declined'       => 'pr-pill-red',
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
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/treatments.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/odontogram.css?v=3">
    <link rel="stylesheet" href="assets/css/patient-record.css?v=2">
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
                    <a href="patients-list.php" class="btn btn-light btn-sm">
                        <i class="feather-arrow-left me-1"></i> Back to List
                    </a>
                    <a href="patient-record-print.php?id=<?= $patientId ?>" target="_blank"
                        class="btn btn-outline-secondary btn-sm">
                        <i class="feather-printer me-1"></i> Print
                    </a>
                </div>
            </div>

            <div class="main-content">

                <!-- ── Patient summary ── -->
                <div class="card mb-3">
                    <div class="pr-head">
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
                            <dl class="pr-meta">
                                <?php foreach ($meta as [$icon, $label, $value]): ?>
                                    <div>
                                        <dt><i class="<?= $icon ?>" aria-hidden="true"></i><?= $label ?></dt>
                                        <dd class="<?= $value === null || $value === '' ? 'is-empty' : '' ?>"><?= htmlspecialchars($value === null || $value === '' ? 'Not recorded' : (string)$value) ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                        </div>
                    </div>
                </div>

                <!-- ── Tabs ── -->
                <div class="card">
                    <div class="pr-tabs-wrap">
                        <ul class="nav nav-tabs pr-tabs" id="patientTabs" role="tablist">
                            <?php foreach ([
                                ['overview',     'pane-overview',     'feather-grid',      'Overview'],
                                ['appointments', 'pane-appointments', 'feather-calendar',  'Appointments'],
                                ['treatments',   'pane-treatments',   'feather-activity',  'Treatments'],
                                ['billing',      'pane-billing',      'feather-credit-card', 'Billing'],
                                ['notes',        'pane-notes',        'feather-edit-3',    'Clinical Notes'],
                                ['waivers',      'pane-waivers',      'feather-file-text', 'Waivers'],
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
                                                <button type="button" class="btn btn-light btn-sm" id="pr_chartDiscard" disabled>Discard</button>
                                                <button type="button" class="btn btn-primary btn-sm px-3" id="pr_chartSave" disabled>
                                                    <i class="feather-save me-1"></i> Save changes
                                                </button>
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

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/admin.js"></script>
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
                cancelled: 'red', no_show: 'red', declined: 'red', overdue: 'red',
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
                    document.getElementById(containerId).innerHTML =
                        `<p class="text-danger small"><i class="feather-alert-circle me-1"></i>${esc(err.message)}</p>`;
                });
        }

        function renderTab(containerId, action, data) {
            const el = document.getElementById(containerId);
            if (action === 'get_appointments') el.innerHTML = renderAppointments(data);
            if (action === 'get_treatments') el.innerHTML = renderTreatments(data);
            if (action === 'get_billing') el.innerHTML = renderBilling(data);
            if (action === 'get_notes') el.innerHTML = renderNotes(data);
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
                    <h6 class="pr-section-title"><span>Upcoming</span><span class="pr-count">${upcoming.length}</span></h6>
                    ${upcoming.length
                        ? table('<th>Date &amp; Time</th><th>Service</th><th>Dentist</th><th>Status</th>', upRows)
                        : emptyState('calendar', 'No upcoming appointments.')}
                </section>
                <section class="pr-section">
                    <h6 class="pr-section-title"><span>History</span>
                        ${(data.history?.total ?? 0) > history.length
                            ? `<span class="pr-hint">Latest ${history.length} of ${data.history.total}</span>`
                            : `<span class="pr-count">${history.length}</span>`}</h6>
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

            return `<h6 class="pr-section-title"><span>Treatment Records</span><span class="pr-count">${rows.length}</span></h6>` +
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
                            <input type="date" id="noteDate" class="form-control"
                                value="${new Date().toISOString().slice(0, 10)}">
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

            return form + `<h6 class="pr-section-title"><span>Notes on Record</span><span class="pr-count">${notes.length}</span></h6>` +
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

        // ── Render: Waivers ──────────────────────────────────────────────────
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
                <div class="pr-form">
                    <h6 class="pr-section-title"><span><i class="feather-upload me-1"></i> Upload Waiver</span></h6>
                    <div id="waiverUploadErr" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label" for="waiverType">Waiver Type <span class="text-danger">*</span></label>
                            <select id="waiverType" class="form-select js-custom-select" data-cs-float>
                                <option value="">— Select type —</option>
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
                            <a href="${esc(w.file_path)}" target="_blank" rel="noopener" class="pr-icon-btn" title="View file" aria-label="View file">
                                <i class="feather-eye" style="font-size:13px;"></i>
                            </a>
                            <button class="pr-icon-btn is-danger" title="Delete waiver" aria-label="Delete waiver"
                                onclick="deleteWaiver(${parseInt(w.waiver_id, 10)}, ${esc(JSON.stringify(String(w.waiver_type ?? '')))})">
                                <i class="feather-trash-2" style="font-size:13px;"></i>
                            </button>
                        </span>
                    </td>
                </tr>`;
            }).join('');

            return uploadForm +
                `<h6 class="pr-section-title"><span>Waivers on File</span><span class="pr-count">${waivers.length}</span></h6>` +
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
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: d.message
                            });
                        }
                    });
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

            return `<h6 class="pr-section-title"><span>Recorded at Check-in</span><span class="pr-count">${rows.length}</span></h6>` +
                table('<th>Date &amp; Time</th><th>Appointment</th><th>Blood Pressure</th><th>Pulse Rate</th><th>Temperature</th><th>Checked By</th><th>Notes</th>', tableRows);
        }
    </script>
</body>

</html>