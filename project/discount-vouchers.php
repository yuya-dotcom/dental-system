<?php
// =============================================================
//  discount-vouchers.php
//  Manage reusable discount vouchers (Owner & Admin only).
//  Uses the new `discount_vouchers` table.
//  The Billing Modal will later read only active vouchers from
//  here — this page owns voucher creation/editing exclusively.
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/dbconfig.php';

// Belt-and-suspenders: enforce the role gate directly, regardless of
// how secure_controller.php interprets REQUIRED_ROLES. No dentist
// access at all — not even read-only.
requireRole('owner', 'admin');

// ── Pagination / filter setup ────────────────────────────────
$filterType   = isset($_GET['type'])   ? trim($_GET['type'])   : '';
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : 'active';
$search       = isset($_GET['search']) ? trim($_GET['search']) : '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 15;

// Build Supabase query filters
$filters  = 'select=voucher_id,voucher_name,voucher_code,discount_type,discount_value,min_bill_amount,usage_limit,start_date,expiration_date,status,created_at';
$filters .= '&order=voucher_name.asc';

if ($filterStatus !== '') $filters .= '&status=eq.'        . urlencode($filterStatus);
if ($filterType   !== '') $filters .= '&discount_type=eq.' . urlencode($filterType);

// Fetch all matching (for count) then paginate
$allRes        = supabase_request('discount_vouchers', 'GET', [], $filters . '&limit=1000');
$allVouchers   = is_array($allRes['data']) ? $allRes['data'] : [];

// Client-side search filter (same approach as service-pricing.php)
if ($search !== '') {
    $allVouchers = array_values(array_filter($allVouchers, function ($v) use ($search) {
        return stripos($v['voucher_name'] ?? '', $search) !== false
            || stripos($v['voucher_code'] ?? '', $search) !== false;
    }));
}

$totalRecords = count($allVouchers);
$totalPages   = max(1, (int)ceil($totalRecords / $perPage));
$page         = min($page, $totalPages);
$from         = $totalRecords > 0 ? ($page - 1) * $perPage + 1 : 0;
$to           = min($page * $perPage, $totalRecords);
$vouchers     = array_slice($allVouchers, ($page - 1) * $perPage, $perPage);

$baseUrl = 'discount-vouchers.php?type=' . urlencode($filterType)
         . '&status=' . urlencode($filterStatus)
         . '&search=' . urlencode($search);

// ── Discount types for filter dropdown + modal ────────────────
$discountTypes = ['percentage' => 'Percentage', 'fixed' => 'Fixed Amount'];

function discountTypeBadge(string $type): string {
    return match ($type) {
        'percentage' => 'bg-soft-primary text-primary',
        'fixed'      => 'bg-soft-warning text-warning',
        default      => 'bg-soft-secondary text-secondary',
    };
}

function formatDiscountValue(string $type, $value): string {
    return $type === 'percentage'
        ? number_format((float)$value, 0) . '%'
        : '₱' . number_format((float)$value, 2);
}

function fmtVoucherDate(?string $date): string {
    if (!$date) return '—';
    return date('M d, Y', strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="zxx">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Discount Vouchers</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
</head>
<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>

    <main class="nxl-container">
        <div class="nxl-content">

            <!-- ── Page Header ──────────────────────────────── -->
            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title"><h5 class="m-b-10">Discount Vouchers</h5></div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Discount Vouchers</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">

                            <!-- Search -->
                            <input type="text" id="searchInput" class="form-control form-control-sm hdr-control"
                                placeholder="Search voucher…" value="<?= htmlspecialchars($search) ?>"
                                onkeydown="if(event.key==='Enter') applyFilters()"
                                style="max-width:180px;">

                            <!-- Discount type filter -->
                            <select id="filterType" class="js-custom-select" data-cs-filter onchange="applyFilters()" aria-label="Filter by type">
                                <option value="">All Types</option>
                                <?php foreach ($discountTypes as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= $filterType === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Status filter -->
                            <select id="filterStatus" class="js-custom-select" data-cs-filter onchange="applyFilters()" aria-label="Filter by status">
                                <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                <option value=""         <?= $filterStatus === ''         ? 'selected' : '' ?>>All</option>
                            </select>

                            <!-- Add button -->
                            <button class="btn btn-primary btn-sm hdr-btn" onclick="openAddModal()">
                                <i class="feather-plus me-1"></i> Create Voucher
                            </button>

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
                                    Discount Vouchers
                                    <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span>
                                </h5>
                                <div class="card-header-action">
                                    <div class="card-header-btn">
                                        <div data-bs-toggle="tooltip" title="Refresh">
                                            <a href="discount-vouchers.php" class="avatar-text avatar-xs bg-warning"></a>
                                        </div>
                                        <div data-bs-toggle="tooltip" title="Maximize/Minimize">
                                            <a href="javascript:void(0);" class="avatar-text avatar-xs bg-success" data-bs-toggle="expand"></a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card-body custom-card-action p-0">
                                <div class="table-responsive" style="overflow:visible;">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Voucher Name</th>
                                                <th>Voucher Code</th>
                                                <th>Discount Type</th>
                                                <th>Discount Value</th>
                                                <th>Start Date</th>
                                                <th>Expiration Date</th>
                                                <th>Status</th>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($vouchers)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4 text-muted">
                                                    <i class="feather-tag me-2"></i>No vouchers found.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($vouchers as $v): ?>
                                            <tr>
                                                <td><span class="fw-semibold"><?= htmlspecialchars($v['voucher_name']) ?></span></td>
                                                <td><span class="fw-semibold"><?= htmlspecialchars($v['voucher_code']) ?></span></td>
                                                <td>
                                                    <span class="badge <?= discountTypeBadge($v['discount_type']) ?>">
                                                        <?= htmlspecialchars($discountTypes[$v['discount_type']] ?? $v['discount_type']) ?>
                                                    </span>
                                                </td>
                                                <td class="fw-semibold text-success">
                                                    <?= formatDiscountValue($v['discount_type'], $v['discount_value']) ?>
                                                </td>
                                                <td><?= fmtVoucherDate($v['start_date']) ?></td>
                                                <td><?= fmtVoucherDate($v['expiration_date']) ?></td>
                                                <td>
                                                    <?php if ($v['status'] === 'active'): ?>
                                                        <span class="badge bg-soft-success text-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-soft-danger text-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="dropdown">
                                                        <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed">
                                                            <i class="feather-more-vertical"></i>
                                                        </a>
                                                        <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                            <a href="javascript:void(0);" class="dropdown-item"
                                                               onclick='openEditModal(<?= json_encode([
                                                                    "voucher_id"      => $v["voucher_id"],
                                                                    "voucher_name"    => $v["voucher_name"],
                                                                    "voucher_code"    => $v["voucher_code"],
                                                                    "discount_type"   => $v["discount_type"],
                                                                    "discount_value"  => $v["discount_value"],
                                                                    "min_bill_amount" => $v["min_bill_amount"],
                                                                    "usage_limit"     => $v["usage_limit"],
                                                                    "start_date"      => $v["start_date"],
                                                                    "expiration_date" => $v["expiration_date"],
                                                                    "status"          => $v["status"],
                                                               ]) ?>)'>
                                                                <i class="feather-edit"></i> Edit
                                                            </a>
                                                            <?php if ($v['status'] === 'active'): ?>
                                                            <a href="javascript:void(0);" class="dropdown-item text-warning"
                                                               onclick="toggleStatus(<?= $v['voucher_id'] ?>, 'inactive', '<?= htmlspecialchars($v['voucher_name'], ENT_QUOTES) ?>')">
                                                                <i class="feather-pause-circle"></i> Deactivate
                                                            </a>
                                                            <?php else: ?>
                                                            <a href="javascript:void(0);" class="dropdown-item text-success"
                                                               onclick="toggleStatus(<?= $v['voucher_id'] ?>, 'active', '<?= htmlspecialchars($v['voucher_name'], ENT_QUOTES) ?>')">
                                                                <i class="feather-play-circle"></i> Activate
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if (isOwner()): ?>
                                                            <div class="dropdown-divider"></div>
                                                            <a href="javascript:void(0);" class="dropdown-item text-danger"
                                                               onclick="deleteVoucher(<?= $v['voucher_id'] ?>, '<?= htmlspecialchars($v['voucher_name'], ENT_QUOTES) ?>')">
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

                            <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <p class="text-muted small mb-0">
                                    Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> voucher(s)
                                    <?php if ($filterType || $filterStatus !== 'active' || $search): ?>
                                        &nbsp;·&nbsp;<a href="discount-vouchers.php" class="text-danger">
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
         ADD / EDIT VOUCHER MODAL
    ============================================================ -->
    <div class="modal fade" id="voucherModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width:560px;">
            <div class="modal-content border-0 shadow-lg">

                <div class="modal-header border-0 pb-0 px-4 pt-4">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="vchModalTitle">Create Voucher</h5>
                        <p class="text-muted small mb-0" id="vchModalSubtitle">Define a new reusable discount voucher.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4 pt-3 pb-0">
                    <div id="vchModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="vch_id">

                    <div class="row g-3">
                        <!-- Voucher Name -->
                        <div class="col-md-7">
                            <label class="form-label fw-semibold small">Voucher Name </label>
                            <input type="text" id="vch_name" class="form-control form-control-sm"
                                placeholder="e.g. New Patient Discount" maxlength="120">
                        </div>

                        <!-- Voucher Code -->
                        <div class="col-md-5">
                            <label class="form-label fw-semibold small">Voucher Code </label>
                            <input type="text" id="vch_code" class="form-control form-control-sm"
                                placeholder="e.g. NEWPATIENT20" maxlength="40"
                                style="text-transform:uppercase;"
                                oninput="this.value = this.value.toUpperCase()">
                        </div>

                        <!-- Discount Type -->
                        <div class="col-md-5">
                            <label class="form-label fw-semibold small">Discount Type</label>
                            <select id="vch_type" class="form-select form-select-sm js-custom-select" data-cs-float onchange="updateValueSymbol()">
                                <?php foreach ($discountTypes as $val => $label): ?>
                                    <option value="<?= $val ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Discount Value -->
                        <div class="col-md-7">
                            <label class="form-label fw-semibold small">Discount Value </label>
                            <div class="input-group">
                                <span class="input-group-text" id="vch_valueSymbol">%</span>
                                <input type="number" id="vch_value" class="form-control"
                                    placeholder="0.00" min="0" step="0.01">
                            </div>
                        </div>

                        <!-- Start Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Start Date </label>
                            <input type="date" id="vch_start" class="form-control form-control-sm">
                        </div>

                        <!-- Expiration Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Expiration Date </label>
                            <input type="date" id="vch_expiration" class="form-control form-control-sm">
                        </div>

                        <!-- Minimum Bill Amount -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Minimum Bill Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" id="vch_minBill" class="form-control"
                                    placeholder="0.00" min="0" step="0.01">
                            </div>
                            <div class="form-text">Leave blank for no minimum.</div>
                        </div>

                        <!-- Usage Limit -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Usage Limit</label>
                            <input type="number" id="vch_usageLimit" class="form-control form-control-sm"
                                placeholder="e.g. 100" min="1" step="1">
                            <div class="form-text">Leave blank for unlimited uses.</div>
                        </div>

                        <!-- Status (edit mode only) -->
                        <div class="col-12" id="vchStatusRow" style="display:none;">
                            <label class="form-label fw-semibold small">Status</label>
                            <select id="vch_status" class="form-select form-select-sm js-custom-select" data-cs-float>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-0 px-4 pb-4 pt-3 d-flex justify-content-between align-items-center">
                    <span class="text-muted small"><span class="text-danger"></span></span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary px-4" id="vchSaveBtn" onclick="saveVoucher()">
                            <i class="feather-save me-1"></i> Save Voucher
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
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
        window.location.href = 'discount-vouchers.php?' + params.toString();
    }

    // ── Discount value ₱/% symbol swap ───────────────────────
    function updateValueSymbol() {
        const type = document.getElementById('vch_type').value;
        document.getElementById('vch_valueSymbol').textContent = type === 'percentage' ? '%' : '₱';
    }

    // ── Open ADD modal ───────────────────────────────────────
    function openAddModal() {
        document.getElementById('vch_id').value            = '';
        document.getElementById('vch_name').value           = '';
        document.getElementById('vch_code').value           = '';
        document.getElementById('vch_type').value           = 'percentage';
        document.getElementById('vch_value').value          = '';
        document.getElementById('vch_start').value          = '';
        document.getElementById('vch_expiration').value     = '';
        document.getElementById('vch_minBill').value        = '';
        document.getElementById('vch_usageLimit').value     = '';
        document.getElementById('vchStatusRow').style.display = 'none';
        document.getElementById('vchModalTitle').textContent    = 'Create Voucher';
        document.getElementById('vchModalSubtitle').textContent = 'Define a new reusable discount voucher.';
        document.getElementById('vchModalError').style.display  = 'none';
        document.getElementById('vchSaveBtn').innerHTML = '<i class="feather-save me-1"></i> Save Voucher';
        updateValueSymbol();
        new bootstrap.Modal(document.getElementById('voucherModal')).show();
    }

    // ── Open EDIT modal ──────────────────────────────────────
    function openEditModal(data) {
        document.getElementById('vch_id').value            = data.voucher_id;
        document.getElementById('vch_name').value           = data.voucher_name    ?? '';
        document.getElementById('vch_code').value           = data.voucher_code    ?? '';
        document.getElementById('vch_type').value           = data.discount_type   ?? 'percentage';
        document.getElementById('vch_value').value          = data.discount_value  ?? '';
        document.getElementById('vch_start').value          = data.start_date      ?? '';
        document.getElementById('vch_expiration').value     = data.expiration_date ?? '';
        document.getElementById('vch_minBill').value        = data.min_bill_amount ?? '';
        document.getElementById('vch_usageLimit').value     = data.usage_limit     ?? '';
        document.getElementById('vch_status').value         = data.status         ?? 'active';
        document.getElementById('vchStatusRow').style.display = '';
        document.getElementById('vchModalTitle').textContent    = 'Edit Voucher';
        document.getElementById('vchModalSubtitle').textContent = 'Update the voucher details below.';
        document.getElementById('vchModalError').style.display  = 'none';
        document.getElementById('vchSaveBtn').innerHTML = '<i class="feather-save me-1"></i> Update Voucher';
        updateValueSymbol();
        new bootstrap.Modal(document.getElementById('voucherModal')).show();
    }

    // ── Save (add or edit) ───────────────────────────────────
    function saveVoucher() {
        const errBox = document.getElementById('vchModalError');
        const btn    = document.getElementById('vchSaveBtn');

        const id        = document.getElementById('vch_id').value.trim();
        const name      = document.getElementById('vch_name').value.trim();
        const code      = document.getElementById('vch_code').value.trim();
        const type      = document.getElementById('vch_type').value;
        const value     = parseFloat(document.getElementById('vch_value').value);
        const start     = document.getElementById('vch_start').value;
        const expiry    = document.getElementById('vch_expiration').value;
        const minBillRaw    = document.getElementById('vch_minBill').value.trim();
        const usageLimitRaw = document.getElementById('vch_usageLimit').value.trim();
        const status    = document.getElementById('vch_status').value || 'active';

        errBox.style.display = 'none';

        if (!name)  { errBox.textContent = 'Voucher name is required.'; errBox.style.display = 'block'; return; }
        if (!code)  { errBox.textContent = 'Voucher code is required.'; errBox.style.display = 'block'; return; }
        if (isNaN(value) || value <= 0) {
            errBox.textContent = 'Please enter a discount value greater than 0.';
            errBox.style.display = 'block'; return;
        }
        if (type === 'percentage' && value > 100) {
            errBox.textContent = 'Percentage discount cannot exceed 100.';
            errBox.style.display = 'block'; return;
        }
        if (!start || !expiry) {
            errBox.textContent = 'Start date and expiration date are required.';
            errBox.style.display = 'block'; return;
        }
        if (expiry < start) {
            errBox.textContent = 'Expiration date cannot be before the start date.';
            errBox.style.display = 'block'; return;
        }

        const isEdit  = !!id;
        const url     = isEdit
            ? `api/discount_vouchers.php?id=${id}`
            : 'api/discount_vouchers.php';
        const method  = isEdit ? 'PATCH' : 'POST';

        const payload = {
            voucher_name:    name,
            voucher_code:    code,
            discount_type:   type,
            discount_value:  value,
            start_date:      start,
            expiration_date: expiry,
            min_bill_amount: minBillRaw    === '' ? null : parseFloat(minBillRaw),
            usage_limit:     usageLimitRaw === '' ? null : parseInt(usageLimitRaw, 10),
        };
        if (isEdit) payload.status = status;

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
            bootstrap.Modal.getInstance(document.getElementById('voucherModal'))?.hide();
            Swal.fire({
                icon: 'success', title: 'Saved!', text: res.message,
                timer: 1600, showConfirmButton: false,
            }).then(() => location.reload());
        })
        .catch(err => {
            btn.disabled  = false;
            btn.innerHTML = '<i class="feather-save me-1"></i> Save Voucher';
            errBox.textContent   = err.message || 'An error occurred.';
            errBox.style.display = 'block';
        });
    }

    // ── Toggle active / inactive ─────────────────────────────
    function toggleStatus(id, newStatus, name) {
        const label  = newStatus === 'active' ? 'Activate' : 'Deactivate';
        const color  = newStatus === 'active' ? '#198754' : '#f59e0b';
        Swal.fire({
            title: `${label} voucher?`,
            html:  `<strong>${name}</strong> will be marked as <em>${newStatus}</em>.`,
            icon:  'question',
            showCancelButton:   true,
            confirmButtonColor: color,
            cancelButtonColor:  '#6c757d',
            confirmButtonText:  `Yes, ${label}`,
        }).then(result => {
            if (!result.isConfirmed) return;
            fetch(`api/discount_vouchers.php?id=${id}`, {
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
    function deleteVoucher(id, name) {
        Swal.fire({
            title: 'Delete Voucher?',
            html:  `<strong>${name}</strong> will be permanently removed.<br><span class="text-danger small">This cannot be undone.</span>`,
            icon:  'warning',
            showCancelButton:   true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor:  '#6c757d',
            confirmButtonText:  'Yes, Delete',
        }).then(result => {
            if (!result.isConfirmed) return;
            fetch(`api/discount_vouchers.php?id=${id}`, { method: 'DELETE' })
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