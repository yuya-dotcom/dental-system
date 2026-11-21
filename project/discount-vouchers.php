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
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying
require_once __DIR__ . '/dbconfig.php';
require_once __DIR__ . '/controllers/list_filters.php';
require_once __DIR__ . '/controllers/record_ui.php';   // Appointment Records cell markup

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
// One parallel wave: vouchers + navbar bell (controllers/admin_batch.php)
admin_prefetch(['all' => admin_q('discount_vouchers', $filters . '&limit=1000')], ['navbar']);
$allRes        = admin_get('discount_vouchers', $filters . '&limit=1000');
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
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
    <link rel="stylesheet" href="assets/css/es-admin.css?v=7">
    <link rel="stylesheet" href="assets/css/es-records.css?v=7">
    <link rel="stylesheet" href="assets/css/analytics.css?v=8">
    <link rel="stylesheet" href="assets/css/appointments-records.css?v=9">
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

                            <!-- Add button -->
                            <button class="btn btn-primary btn-sm hdr-btn" onclick="openAddModal()">
                                <i class="feather-plus me-1"></i> Create Voucher
                            </button>

                        </div>
                    </div>
                </div>
            </div>
            <!-- ── /Page Header ─────────────────────────────── -->

            <div class="main-content an-page ar-page">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card stretch stretch-full">

                            <div class="card-header">
                                <?php $vchFiltersOn = listActiveCount([$filterType, $filterStatus !== 'active', $search]); ?>
                                <h5 class="card-title mb-0">
                                    Discount Vouchers
                                    <span class="an-pill info ms-2"><?= $totalRecords ?> <?= $vchFiltersOn ? 'found' : 'total' ?></span>
                                    <span class="rec-card-sub">Discount codes that can be applied to a patient's bill</span>
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

                            <div class="list-toolbar">
                                <div class="list-field list-field-search"><label class="list-label" for="searchInput">Search</label>
                                <div class="list-search"><i class="feather-search" aria-hidden="true"></i>
                                <input type="search" id="searchInput" placeholder="Voucher name or code" value="<?= htmlspecialchars($search) ?>" autocomplete="off"
                                    onkeydown="if(event.key==='Enter'){event.preventDefault();applyFilters();}" onsearch="if(!this.value)applyFilters()"></div></div>
                                <div class="list-field"><label class="list-label" for="filterType">Discount Type</label>
                                <select id="filterType" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                    <option value="">All Types</option>
                                    <?php foreach ($discountTypes as $val => $label): ?>
                                        <option value="<?= $val ?>" <?= $filterType === $val ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                                <div class="list-field"><label class="list-label" for="filterStatus">Status</label>
                                <select id="filterStatus" class="js-custom-select" data-cs-filter onchange="applyFilters()">
                                    <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    <option value=""         <?= $filterStatus === ''         ? 'selected' : '' ?>>All</option>
                                </select></div>
                                <?= listResetControl('discount-vouchers.php', $vchFiltersOn) ?>
                            </div>

                            <div class="card-body custom-card-action p-0">
                                <div class="table-responsive" style="overflow:visible;">
                                    <table class="table table-hover mb-0 rec-table ar-table">
                                        <thead>
                                            <tr>
                                                <th>Voucher Name</th>
                                                <th>Voucher Code</th>
                                                <th>Discount Type</th>
                                                <th>Discount Value</th>
                                                <th>Start Date</th>
                                                <th>Expiration Date</th>
                                                <th>Status</th>
                                                <th class="rec-col-action text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($vouchers)): ?>
                                                <?= listEmpty(8, (bool)$vchFiltersOn, 'No vouchers yet', 'Use Create Voucher at the top of the page to add a discount code.', 'feather-tag', 'discount-vouchers.php') ?>
                                        <?php else: ?>
                                            <?php foreach ($vouchers as $v): ?>
                                            <tr>
                                                <td>
                                                    <div class="rec-entity">
                                                        <span class="rec-tile<?= $v['status'] === 'active' ? '' : ' is-muted' ?>" aria-hidden="true"><i class="feather-tag"></i></span>
                                                        <span class="rec-strong"><?= htmlspecialchars($v['voucher_name']) ?></span>
                                                    </div>
                                                </td>
                                                <td><span class="rec-code"><?= htmlspecialchars($v['voucher_code']) ?></span></td>
                                                <td>
                                                    <span class="badge <?= discountTypeBadge($v['discount_type']) ?>">
                                                        <?= htmlspecialchars($discountTypes[$v['discount_type']] ?? $v['discount_type']) ?>
                                                    </span>
                                                </td>
                                                <td class="rec-amount text-success">
                                                    <?= formatDiscountValue($v['discount_type'], $v['discount_value']) ?>
                                                </td>
                                                <td class="rec-nowrap"><?= fmtVoucherDate($v['start_date']) ?></td>
                                                <td class="rec-nowrap"><?= fmtVoucherDate($v['expiration_date']) ?></td>
                                                <td>
                                                    <?php if ($v['status'] === 'active'): ?>
                                                        <?= recPill('Active', 'up') ?>
                                                    <?php else: ?>
                                                        <?= recPill('Inactive', 'slate') ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="rec-col-action">
                                                    <div class="dropdown">
                                                        <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-label="Actions for <?= htmlspecialchars($v['voucher_name'], ENT_QUOTES) ?>">
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
                                                                <i class="feather-edit-2"></i> Edit
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

                            <div class="card-footer ar-foot d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <?= listFootCount($from, $to, $totalRecords, 'voucher', 'discount-vouchers.php', (bool)$vchFiltersOn) ?>
                                <?= listPager($baseUrl, $page, $totalPages) ?>
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
    <div class="modal fade es-detail-modal" id="voucherModal" tabindex="-1" aria-labelledby="vchModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-form-dialog rec-form-dialog">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-tag"></i></div>
                        <div class="min-w-0">
                            <h5 class="apt-record-title mb-0" id="vchModalTitle">Create Voucher</h5>
                            <div class="apt-record-meta" id="vchModalSubtitle">Define a new reusable discount voucher.</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body apt-form-body">
                    <div id="vchModalError" class="apt-form-alert" role="alert" style="display:none;"></div>
                    <input type="hidden" id="vch_id">
                    <div class="rec-form-stack">
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-tag"></i></span><h6 class="ar-card-title">Voucher</h6></div>
                            <div class="apt-form-card-body">
                                <div class="row g-3">
                                    <div class="col-md-7">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="vch_name">Voucher Name <span class="apt-req" aria-hidden="true">*</span></label>
                                            <input type="text" id="vch_name" class="form-control" placeholder="e.g. New Patient Discount" maxlength="120" autocomplete="off">
                                            
                                        </div>
                                    </div>
                                    <div class="col-md-5">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="vch_code">Voucher Code <span class="apt-req" aria-hidden="true">*</span></label>
                                            <input type="text" id="vch_code" class="form-control" placeholder="e.g. NEWPATIENT20" maxlength="40" autocomplete="off" style="text-transform:uppercase;" oninput="this.value = this.value.toUpperCase()">
                                            
                                        </div>
                                    </div>
                                    <div class="col-md-5">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="vch_type">Discount Type <span class="apt-req" aria-hidden="true">*</span></label>
                                            <select id="vch_type" class="form-select js-custom-select" data-cs-float onchange="updateValueSymbol()">
                                                <?php foreach ($discountTypes as $val => $label): ?>
                                                    <option value="<?= $val ?>"><?= $label ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            
                                        </div>
                                    </div>
                                    <div class="col-md-7">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="vch_value">Discount Value <span class="apt-req" aria-hidden="true">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text" id="vch_valueSymbol">%</span>
                                                <input type="number" id="vch_value" class="form-control" placeholder="0.00" min="0" step="0.01">
                                            </div>
                                            
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-calendar"></i></span><h6 class="ar-card-title">Validity &amp; Limits</h6></div>
                            <div class="apt-form-card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="vch_start">Start Date <span class="apt-req" aria-hidden="true">*</span></label>
                                            <input type="date" id="vch_start" class="form-control js-date-picker" placeholder="Select date">
                                            
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="vch_expiration">Expiration Date <span class="apt-req" aria-hidden="true">*</span></label>
                                            <input type="date" id="vch_expiration" class="form-control js-date-picker" placeholder="Select date">
                                            
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="vch_minBill">Minimum Bill Amount <span class="apt-opt">(optional)</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text">₱</span>
                                                <input type="number" id="vch_minBill" class="form-control" placeholder="0.00" min="0" step="0.01">
                                            </div>
                                            <div class="rec-help">Leave blank for no minimum.</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="vch_usageLimit">Usage Limit <span class="apt-opt">(optional)</span></label>
                                            <input type="number" id="vch_usageLimit" class="form-control" placeholder="e.g. 100" min="1" step="1">
                                            <div class="rec-help">Leave blank for unlimited uses.</div>
                                        </div>
                                    </div>
                                    <div class="col-12" id="vchStatusRow" style="display:none;">
                                        <div class="apt-field mb-0">
                                            <label class="apt-label" for="vch_status">Status</label>
                                            <select id="vch_status" class="form-select js-custom-select" data-cs-float>
                                                <option value="active">Active</option>
                                                <option value="inactive">Inactive</option>
                                            </select>
                                            <div class="rec-help">Only <strong>Active</strong> vouchers can be applied to a bill.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="modal-footer apt-record-footer apt-form-footer">
                    <span class="apt-form-hint"><span class="apt-req">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary px-4" id="vchSaveBtn" onclick="saveVoucher()">
                            <i class="feather-save me-1"></i> Save Voucher
                        </button>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    </div>
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
    <script src="assets/js/admin.js?v=7"></script>
    <script src="assets/js/es-swal.js?v=3"></script>
    <script src="assets/js/es-ajax.js?v=7"></script>
    <script>
    // ── Filter helper ────────────────────────────────────────
    function applyFilters() {
        const params = new URLSearchParams({
            type:   document.getElementById('filterType')?.value   ?? '',
            status: document.getElementById('filterStatus')?.value ?? '',
            search: document.getElementById('searchInput')?.value  ?? '',
            page:   1,
        });
        esNavigate('discount-vouchers.php?' + params.toString());
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
            }).then(() => esRefresh());
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
                        .then(() => esRefresh());
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
                        .then(() => esRefresh());
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            });
        });
    }
    </script>
</body>
</html>