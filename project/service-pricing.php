<?php
// =============================================================
//  service-pricing.php
//  Manage service prices (Owner & Admin only).
//  Uses the existing `services` table — no new table needed.
//  Owners/Admins can view and edit base_price per service.
//  Dentists get a read-only view.
// =============================================================

define('REQUIRED_ROLES', ['owner']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/dbconfig.php';
require_once __DIR__ . '/controllers/auth_controller.php';

// ── Pagination / filter setup ────────────────────────────────
$filterType   = isset($_GET['type'])   ? trim($_GET['type'])   : '';
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : 'active';
$search       = isset($_GET['search']) ? trim($_GET['search']) : '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 15;

// Build Supabase query filters
$filters  = 'select=service_id,service_name,service_type,base_price,description,status,created_at';
$filters .= '&order=service_name.asc';

if ($filterStatus !== '') $filters .= '&status=eq.' . urlencode($filterStatus);
if ($filterType   !== '') $filters .= '&service_type=eq.' . urlencode($filterType);

// Fetch all matching (for count) then paginate
$allRes       = supabase_request('services', 'GET', [], $filters . '&limit=1000');
$allServices  = is_array($allRes['data']) ? $allRes['data'] : [];

// Client-side search filter (Supabase REST ilike would also work)
if ($search !== '') {
    $allServices = array_values(array_filter($allServices, function ($s) use ($search) {
        return stripos($s['service_name'] ?? '', $search) !== false
            || stripos($s['description'] ?? '', $search) !== false;
    }));
}

$totalRecords = count($allServices);
$totalPages   = max(1, (int)ceil($totalRecords / $perPage));
$page         = min($page, $totalPages);
$from         = $totalRecords > 0 ? ($page - 1) * $perPage + 1 : 0;
$to           = min($page * $perPage, $totalRecords);
$services     = array_slice($allServices, ($page - 1) * $perPage, $perPage);

$baseUrl = 'service-pricing.php?type='   . urlencode($filterType)
         . '&status=' . urlencode($filterStatus)
         . '&search=' . urlencode($search);

// ── Unique service types for filter dropdown ─────────────────
$serviceTypes = ['Procedure', 'Consultation', 'Cosmetic', 'Orthodontic', 'Other'];
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Service Pricing</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/select2.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/select2-theme.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>

    <main class="nxl-container">
        <div class="nxl-content">

            <!-- ── Page Header ──────────────────────────────── -->
            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">Services</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Service Pricing</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">

                            <!-- Search -->
                            <input type="text" id="searchInput" class="form-control form-control-sm hdr-control"
                                placeholder="Search service…" value="<?= htmlspecialchars($search) ?>"
                                onkeydown="if(event.key==='Enter') applyFilters()"
                                style="max-width:180px;">

                            <!-- Type filter -->
                            <select id="filterType" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:150px;">
                                <option value="">All Types</option>
                                <?php foreach ($serviceTypes as $t): ?>
                                    <option value="<?= $t ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Status filter -->
                            <select id="filterStatus" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:130px;">
                                <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                <option value=""         <?= $filterStatus === ''         ? 'selected' : '' ?>>All</option>
                            </select>

                            <!-- Add button (owner/admin only) -->
                            <?php if (!isDentist()): ?>
                            <button class="btn btn-primary btn-sm hdr-btn" onclick="openAddModal()">
                                <i class="feather-plus me-1"></i> Add Service
                            </button>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>
            <!-- ── /Page Header ─────────────────────────────── -->

            <div class="main-content">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card stretch stretch-full">

                            <div class="card-header">
                                <h5 class="card-title">
                                    Service Pricing
                                    <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span>
                                </h5>
                                <div class="card-header-action">
                                    <div class="card-header-btn">
                                        <div data-bs-toggle="tooltip" title="Refresh">
                                            <a href="service-pricing.php" class="avatar-text avatar-xs bg-warning"></a>
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
                                                <th style="width:40%">Service Name</th>
                                                <th>Type</th>
                                                <th>Base Price</th>
                                                <th>Status</th>
                                                <?php if (!isDentist()): ?>
                                                <th class="text-end">Action</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($services)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-muted">
                                                    <i class="feather-tag me-2"></i>No services found.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($services as $svc): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-semibold"><?= htmlspecialchars($svc['service_name']) ?></span>
                                                    <?php if (!empty($svc['description'])): ?>
                                                    <div class="text-muted small"><?= htmlspecialchars($svc['description']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-soft-info text-info">
                                                        <?= htmlspecialchars($svc['service_type']) ?>
                                                    </span>
                                                </td>
                                                <td class="fw-semibold text-success">
                                                    ₱<?= number_format((float)$svc['base_price'], 2) ?>
                                                </td>
                                                <td>
                                                    <?php if ($svc['status'] === 'active'): ?>
                                                        <span class="badge bg-soft-success text-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-soft-danger text-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if (!isDentist()): ?>
                                                <td class="text-end">
                                                    <div class="dropdown">
                                                        <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown">
                                                            <i class="feather-more-vertical"></i>
                                                        </a>
                                                        <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                            <a href="javascript:void(0);" class="dropdown-item"
                                                               onclick='openEditModal(<?= json_encode([
                                                                    "service_id"   => $svc["service_id"],
                                                                    "service_name" => $svc["service_name"],
                                                                    "service_type" => $svc["service_type"],
                                                                    "base_price"   => $svc["base_price"],
                                                                    "description"  => $svc["description"],
                                                                    "status"       => $svc["status"],
                                                               ]) ?>)'>
                                                                <i class="feather-edit"></i> Edit
                                                            </a>
                                                            <?php if ($svc['status'] === 'active'): ?>
                                                            <a href="javascript:void(0);" class="dropdown-item text-warning"
                                                               onclick="toggleStatus(<?= $svc['service_id'] ?>, 'inactive', '<?= htmlspecialchars($svc['service_name']) ?>')">
                                                                <i class="feather-pause-circle"></i> Deactivate
                                                            </a>
                                                            <?php else: ?>
                                                            <a href="javascript:void(0);" class="dropdown-item text-success"
                                                               onclick="toggleStatus(<?= $svc['service_id'] ?>, 'active', '<?= htmlspecialchars($svc['service_name']) ?>')">
                                                                <i class="feather-play-circle"></i> Activate
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if (isOwner()): ?>
                                                            <div class="dropdown-divider"></div>
                                                            <a href="javascript:void(0);" class="dropdown-item text-danger"
                                                               onclick="deleteService(<?= $svc['service_id'] ?>, '<?= htmlspecialchars($svc['service_name']) ?>')">
                                                                <i class="feather-trash-2"></i> Delete
                                                            </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <p class="text-muted small mb-0">
                                    Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> service(s)
                                    <?php if ($filterType || $filterStatus !== 'active' || $search): ?>
                                        &nbsp;·&nbsp;<a href="service-pricing.php" class="text-danger">
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
                                    <?php for ($p = $wStart; $p <= $wEnd; $p++): ?>
                                        <li><a href="<?= $baseUrl ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a></li>
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

    <!-- ============================================================
         ADD / EDIT SERVICE MODAL
    ============================================================ -->
    <?php if (!isDentist()): ?>
    <div class="modal fade" id="serviceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
            <div class="modal-content border-0 shadow-lg">

                <div class="modal-header border-0 pb-0 px-4 pt-4">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="svcModalTitle">Add Service</h5>
                        <p class="text-muted small mb-0" id="svcModalSubtitle">Define a new service and its base price.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4 pt-3 pb-0">
                    <div id="svcModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="svc_id">

                    <div class="row g-3">
                        <!-- Service Name -->
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Service Name <span class="text-danger">*</span></label>
                            <input type="text" id="svc_name" class="form-control form-control-sm"
                                placeholder="e.g. Tooth Extraction" maxlength="120">
                        </div>

                        <!-- Type -->
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Type <span class="text-danger">*</span></label>
                            <select id="svc_type" class="form-select form-select-sm">
                                <?php foreach ($serviceTypes as $t): ?>
                                    <option value="<?= $t ?>"><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Base Price -->
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Base Price (₱) <span class="text-danger">*</span></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">₱</span>
                                <input type="number" id="svc_price" class="form-control form-control-sm"
                                    placeholder="0.00" min="0" step="0.01">
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Description</label>
                            <textarea id="svc_description" class="form-control form-control-sm"
                                rows="2" placeholder="Optional short description…"></textarea>
                        </div>

                        <!-- Status (edit mode only) -->
                        <div class="col-12" id="svcStatusRow" style="display:none;">
                            <label class="form-label fw-semibold small">Status</label>
                            <select id="svc_status" class="form-select form-select-sm">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-0 px-4 pb-4 pt-3 d-flex justify-content-between align-items-center">
                    <span class="text-muted small"><span class="text-danger">*</span> Required fields</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary px-4" id="svcSaveBtn" onclick="saveService()">
                            <i class="feather-save me-1"></i> Save Service
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/admin.js"></script>
    <script>
    // ── Filter helper ────────────────────────────────────────
    function applyFilters() {
        const params = new URLSearchParams({
            type:   document.getElementById('filterType')?.value   ?? '',
            status: document.getElementById('filterStatus')?.value ?? '',
            search: document.getElementById('searchInput')?.value  ?? '',
            page:   1,
        });
        window.location.href = 'service-pricing.php?' + params.toString();
    }

    // ── Open ADD modal ───────────────────────────────────────
    function openAddModal() {
        document.getElementById('svc_id').value          = '';
        document.getElementById('svc_name').value        = '';
        document.getElementById('svc_type').value        = 'Consultation';
        document.getElementById('svc_price').value       = '';
        document.getElementById('svc_description').value = '';
        document.getElementById('svcStatusRow').style.display = 'none';
        document.getElementById('svcModalTitle').textContent    = 'Add Service';
        document.getElementById('svcModalSubtitle').textContent = 'Define a new service and its base price.';
        document.getElementById('svcModalError').style.display  = 'none';
        document.getElementById('svcSaveBtn').innerHTML = '<i class="feather-save me-1"></i> Save Service';
        new bootstrap.Modal(document.getElementById('serviceModal')).show();
    }

    // ── Open EDIT modal ──────────────────────────────────────
    function openEditModal(data) {
        document.getElementById('svc_id').value          = data.service_id;
        document.getElementById('svc_name').value        = data.service_name  ?? '';
        document.getElementById('svc_type').value        = data.service_type  ?? 'Consultation';
        document.getElementById('svc_price').value       = data.base_price    ?? '';
        document.getElementById('svc_description').value = data.description   ?? '';
        document.getElementById('svc_status').value      = data.status        ?? 'active';
        document.getElementById('svcStatusRow').style.display = '';
        document.getElementById('svcModalTitle').textContent    = 'Edit Service';
        document.getElementById('svcModalSubtitle').textContent = 'Update name, type, or base price.';
        document.getElementById('svcModalError').style.display  = 'none';
        document.getElementById('svcSaveBtn').innerHTML = '<i class="feather-save me-1"></i> Update Service';
        new bootstrap.Modal(document.getElementById('serviceModal')).show();
    }

    // ── Save (add or edit) ───────────────────────────────────
    function saveService() {
        const errBox = document.getElementById('svcModalError');
        const btn    = document.getElementById('svcSaveBtn');

        const id    = document.getElementById('svc_id').value.trim();
        const name  = document.getElementById('svc_name').value.trim();
        const type  = document.getElementById('svc_type').value;
        const price = parseFloat(document.getElementById('svc_price').value);
        const desc  = document.getElementById('svc_description').value.trim();
        const stat  = document.getElementById('svc_status').value || 'active';

        errBox.style.display = 'none';

        if (!name) {
            errBox.textContent = 'Service name is required.';
            errBox.style.display = 'block'; return;
        }
        if (isNaN(price) || price < 0) {
            errBox.textContent = 'Please enter a valid base price (0 or more).';
            errBox.style.display = 'block'; return;
        }

        const isEdit  = !!id;
        const url     = isEdit
            ? `api/service_pricing.php?id=${id}`
            : 'api/service_pricing.php';
        const method  = isEdit ? 'PATCH' : 'POST';

        const payload = { service_name: name, service_type: type, base_price: price, description: desc };
        if (isEdit) payload.status = stat;

        btn.disabled  = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

        fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message);
            bootstrap.Modal.getInstance(document.getElementById('serviceModal'))?.hide();
            Swal.fire({
                icon: 'success', title: 'Saved!', text: res.message,
                timer: 1600, showConfirmButton: false,
            }).then(() => location.reload());
        })
        .catch(err => {
            btn.disabled  = false;
            btn.innerHTML = '<i class="feather-save me-1"></i> Save Service';
            errBox.textContent   = err.message || 'An error occurred.';
            errBox.style.display = 'block';
        });
    }

    // ── Toggle active / inactive ─────────────────────────────
    function toggleStatus(id, newStatus, name) {
        const label  = newStatus === 'active' ? 'Activate' : 'Deactivate';
        const color  = newStatus === 'active' ? '#198754' : '#f59e0b';
        Swal.fire({
            title: `${label} service?`,
            html:  `<strong>${name}</strong> will be marked as <em>${newStatus}</em>.`,
            icon:  'question',
            showCancelButton:   true,
            confirmButtonColor: color,
            cancelButtonColor:  '#6c757d',
            confirmButtonText:  `Yes, ${label}`,
        }).then(result => {
            if (!result.isConfirmed) return;
            fetch(`api/service_pricing.php?id=${id}`, {
                method:  'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ status: newStatus }),
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Updated!', text: res.message, timer: 1500, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            });
        });
    }

    // ── Delete (owner only) ──────────────────────────────────
    function deleteService(id, name) {
        Swal.fire({
            title: 'Delete Service?',
            html:  `<strong>${name}</strong> will be permanently removed.<br><span class="text-danger small">This cannot be undone.</span>`,
            icon:  'warning',
            showCancelButton:   true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor:  '#6c757d',
            confirmButtonText:  'Yes, Delete',
        }).then(result => {
            if (!result.isConfirmed) return;
            fetch(`api/service_pricing.php?id=${id}`, { method: 'DELETE' })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1500, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            });
        });
    }
    </script>
</body>
</html>