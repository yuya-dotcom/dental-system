<?php
// =============================================================
//  patients-list.php
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/patient_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';

$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : getSessionBranch();
if (!isOwner() && $filterBranch !== getSessionBranch()) $filterBranch = getSessionBranch();
$branches     = getAllBranches();
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$page         = max(1, (int)($_GET['page'] ?? 1));

// getPatientRecords() now returns: rows, totalRecords, totalPages, from, to
$data         = getPatientRecords($page, $filterBranch, $filterStatus);
$patients     = $data['rows']         ?? [];
$totalRecords = $data['totalRecords'] ?? 0;
$totalPages   = $data['totalPages']   ?? 1;
$from         = $data['from']         ?? 0;
$to           = $data['to']           ?? 0;

$baseUrl = 'patients-list.php?branch=' . $filterBranch . '&status=' . urlencode($filterStatus);
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Patients List</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>

    <main class="nxl-container">
        <div class="nxl-content">

            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">Patients</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">List</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
                            <select id="filterStatus" class="form-select hdr-control" onchange="applyFilters()">
                                <option value="">All Statuses</option>
                                <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                            <?php if (isOwner()): ?>
                            <select id="filterBranch" class="form-select hdr-control" onchange="applyFilters()">
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= (int)$b['branch_id'] ?>"
                                    <?= (int)$b['branch_id'] === $filterBranch ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                            <?php if (!isDentist()): ?>
                            <button class="btn btn-primary hdr-btn" onclick="openAddPatientModal()">
                                <i class="feather-plus me-1"></i> Add Patient
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-content">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card stretch stretch-full">

                            <div class="card-header">
                                <h5 class="card-title">
                                    Patients List
                                    <!-- Now correctly shows real total from DB -->
                                    <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span>
                                </h5>
                                <div class="card-header-action">
                                    <div class="card-header-btn">
                                        <div data-bs-toggle="tooltip" title="Refresh">
                                            <a href="patients-list.php" class="avatar-text avatar-xs bg-warning"></a>
                                        </div>
                                        <div data-bs-toggle="tooltip" title="Maximize/Minimize">
                                            <a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card-body custom-card-action p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Patient ID</th>
                                                <th>Full Name</th>
                                                <th>Contact</th>
                                                <th>Gender</th>
                                                <th>Birthdate</th>
                                                <th>Branch</th>
                                                <th>Last Visit</th>
                                                <th>Status</th>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($patients)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center py-4 text-muted">
                                                    <i class="feather-users me-2"></i>No patients found.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($patients as $p): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-semibold"><?= htmlspecialchars($p['patient_code'] ?? '—') ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="avatar-text avatar-sm bg-soft-primary text-primary rounded-circle">
                                                            <?= strtoupper(substr($p['full_name'] ?? 'P', 0, 1)) ?>
                                                        </div>
                                                        <a href="patient-records.php?id=<?= (int)($p['patient_id'] ?? 0) ?>"
                                                           class="text-dark fw-semibold text-decoration-none">
                                                            <?= htmlspecialchars($p['full_name'] ?? '—') ?>
                                                        </a>
                                                    </div>
                                                </td>
                                                <td><?= htmlspecialchars($p['contact_number'] ?? '—') ?></td>
                                                <td><?= htmlspecialchars(ucfirst($p['gender'] ?? '—')) ?></td>
                                                <td><?= formatPatBirthdate($p['birthdate'] ?? null) ?></td>
                                                <td><?= htmlspecialchars($p['branches']['branch_name'] ?? '—') ?></td>
                                                <td><?= formatPatLastVisit($p['last_visit'] ?? null) ?></td>
                                                <td>
                                                    <span class="badge <?= patStatusBadge($p['status'] ?? null) ?>">
                                                        <?= ucfirst($p['status'] ?? 'active') ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="dropdown">
                                                        <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto"
                                                           data-bs-toggle="dropdown">
                                                            <i class="feather-more-vertical"></i>
                                                        </a>
                                                        <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                            <a href="patient-records.php?id=<?= (int)($p['patient_id'] ?? 0) ?>"
                                                               class="dropdown-item">
                                                                <i class="feather-eye"></i> View Details
                                                            </a>
                                                            <a href="appointments-schedule.php?patient_id=<?= (int)($p['patient_id'] ?? 0) ?>"
                                                               class="dropdown-item">
                                                                <i class="feather-calendar"></i> View Appointments
                                                            </a>
                                                            <?php if (!isDentist()): ?>
                                                            <div class="dropdown-divider"></div>
                                                            <a href="javascript:void(0);" class="dropdown-item"
                                                               onclick='openEditPatientModal(<?= json_encode([
                                                                   "patient_id"     => $p["patient_id"],
                                                                   "full_name"      => $p["full_name"]      ?? "",
                                                                   "contact_number" => $p["contact_number"] ?? "",
                                                                   "gender"         => $p["gender"]         ?? "",
                                                                   "birthdate"      => $p["birthdate"]      ?? "",
                                                                   "branch_id"      => $p["branch_id"]      ?? "",
                                                                   "last_visit"     => $p["last_visit"]     ?? "",
                                                                   "status"         => $p["status"]         ?? "active",
                                                               ], JSON_HEX_QUOT | JSON_HEX_TAG) ?>)'>
                                                                <i class="feather-edit-2"></i> Edit
                                                            </a>
                                                            <a href="javascript:void(0);" class="dropdown-item text-danger"
                                                               onclick="deletePatient(<?= (int)($p['patient_id'] ?? 0) ?>, '<?= htmlspecialchars($p['full_name'] ?? '', ENT_QUOTES) ?>')">
                                                                <i class="feather-trash-2"></i> Delete
                                                            </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Pagination footer — $from/$to/$totalRecords now real values -->
                            <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <p class="text-muted small mb-0">
                                    Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> patient(s)
                                    <?php if ($filterBranch || $filterStatus): ?>
                                        &nbsp;·&nbsp;
                                        <a href="patients-list.php" class="text-danger">
                                            <i class="feather-x-circle me-1"></i>Clear filters
                                        </a>
                                    <?php endif; ?>
                                </p>
                                <?php if ($totalPages > 1): ?>
                                <ul class="list-unstyled d-flex align-items-center gap-1 mb-0 pagination-common-style">
                                    <li>
                                        <?php if ($page > 1): ?>
                                            <a href="<?= $baseUrl ?>&page=<?= $page - 1 ?>"><i class="bi bi-arrow-left"></i></a>
                                        <?php else: ?>
                                            <a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-left"></i></a>
                                        <?php endif; ?>
                                    </li>
                                    <?php
                                    $wStart = max(1, $page - 2);
                                    $wEnd   = min($totalPages, $wStart + 4);
                                    $wStart = max(1, $wEnd - 4);
                                    ?>
                                    <?php if ($wStart > 1): ?>
                                        <li><a href="<?= $baseUrl ?>&page=1">1</a></li>
                                        <?php if ($wStart > 2): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?>
                                    <?php endif; ?>
                                    <?php for ($pg = $wStart; $pg <= $wEnd; $pg++): ?>
                                        <li>
                                            <a href="<?= $baseUrl ?>&page=<?= $pg ?>"
                                               class="<?= $pg === $page ? 'active' : '' ?>">
                                                <?= $pg ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    <?php if ($wEnd < $totalPages): ?>
                                        <?php if ($wEnd < $totalPages - 1): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?>
                                        <li><a href="<?= $baseUrl ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a></li>
                                    <?php endif; ?>
                                    <li>
                                        <?php if ($page < $totalPages): ?>
                                            <a href="<?= $baseUrl ?>&page=<?= $page + 1 ?>"><i class="bi bi-arrow-right"></i></a>
                                        <?php else: ?>
                                            <a class="text-muted" style="pointer-events:none;opacity:.4;"><i class="bi bi-arrow-right"></i></a>
                                        <?php endif; ?>
                                    </li>
                                </ul>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ADD / EDIT PATIENT MODAL -->
    <div class="modal fade" id="patientModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="patModalTitle">Add Patient</h5>
                        <p class="text-muted small mb-0">Fill in the patient's information below.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="patModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="pat_id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
                            <input type="text" id="pat_name" class="form-control" placeholder="e.g. Juan Dela Cruz">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Contact Number</label>
                            <input type="text" id="pat_contact" class="form-control" placeholder="+63 9XX XXX XXXX">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Gender</label>
                            <select id="pat_gender" class="form-select">
                                <option value="">— Select —</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Prefer not to say">Prefer not to say</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Birthdate</label>
                            <input type="date" id="pat_birthdate" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Last Visit</label>
                            <input type="date" id="pat_last_visit" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Branch <span class="text-danger">*</span></label>
                            <select id="pat_branch" class="form-select">
                                <option value="">— Select Branch —</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= (int)$b['branch_id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Status</label>
                            <select id="pat_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <span class="text-muted small"><span class="text-danger">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary px-4" id="patSaveBtn" onclick="savePatient()">
                            <i class="feather-save me-1"></i> Save
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/patients.js"></script>
    <script>
    function applyFilters() {
        window._applyFilters('patients-list.php', {
            branch: 'filterBranch',
            status: 'filterStatus'
        });
    }
    </script>
</body>
</html>