<?php
define('REQUIRED_ROLES', ['owner', 'admin']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying
require_once __DIR__ . '/controllers/treatment_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/list_filters.php';

$filterBranch  = isset($_GET['branch'])  ? (int)$_GET['branch']  : 0;
$filterPayment = in_array($_GET['payment'] ?? '', ['paid', 'partial', 'unpaid'], true) ? $_GET['payment'] : '';
$page          = max(1, (int)($_GET['page'] ?? 1));


// Sort (table headers) / search / invoice date (validated in list_filters.php)
$sortOptions  = [
    'code_asc'     => ['Invoice No. (Default)', 'invoice_code.asc'],
    'patient_asc'  => ['Patient (A–Z)', 'patients(full_name).asc.nullslast,invoice_id.asc'],
    'patient_desc' => ['Patient (Z–A)', 'patients(full_name).desc.nullslast,invoice_id.asc'],
    'newest'       => ['Newest First',          'invoice_date.desc.nullslast,invoice_id.desc'],
    'oldest'       => ['Oldest First',          'invoice_date.asc.nullslast,invoice_id.asc'],
    'trt_asc'      => ['Treatment (A–Z)',       'treatments(treatment_code).asc.nullslast,invoice_id.asc'],
    'trt_desc'     => ['Treatment (Z–A)',       'treatments(treatment_code).desc.nullslast,invoice_id.asc'],
    'amount_desc'  => ['Total (High to Low)',   'total_amount.desc.nullslast,invoice_id.desc'],
    'amount_asc'   => ['Total (Low to High)',   'total_amount.asc.nullslast,invoice_id.asc'],
    'paid_desc'    => ['Paid (High to Low)',    'amount_paid.desc.nullslast,invoice_id.desc'],
    'paid_asc'     => ['Paid (Low to High)',    'amount_paid.asc.nullslast,invoice_id.asc'],
    'balance_desc' => ['Balance (High to Low)', 'balance.desc.nullslast,invoice_id.desc'],
    'balance_asc'  => ['Balance (Low to High)', 'balance.asc.nullslast,invoice_id.asc'],
    'pay_asc'      => ['Status (A–Z)',          'payment_status.asc,invoice_id.asc'],
    'pay_desc'     => ['Status (Z–A)',          'payment_status.desc,invoice_id.asc'],
];
[$sortKey, $sortOrder] = listSort($sortOptions, 'code_asc');
$search        = listSearch();
$dateFrom      = listDate('from');
$dateTo        = listDate('to');
$extra         = listSearchFilter($search, ['invoice_code']);
array_push($extra, ...listDateRange('invoice_date', $dateFrom, $dateTo));
$filtersOn     = listActiveCount([isOwner() ? $filterBranch : 0, $filterPayment, $search, $dateFrom, $dateTo, $sortKey !== 'code_asc']);

// One parallel wave: the list + branch dropdown + navbar bell (controllers/admin_batch.php)
admin_prefetch(['list' => admin_q('invoices', implode('&', invoiceRecordsQuery($page, $filterBranch, $filterPayment, $sortOrder, $extra)), ['Prefer: count=exact'])]);
$branches     = getAllBranches();
$data         = getInvoiceRecords($page, $filterBranch, $filterPayment, $sortOrder, $extra);
$invoices     = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];

$baseUrl = listUrl('billing-records.php', ['branch' => $filterBranch, 'payment' => $filterPayment, 'q' => $search, 'from' => $dateFrom, 'to' => $dateTo, 'sort' => $sortKey === 'code_asc' ? '' : $sortKey]);
$sortBase = listUrl('billing-records.php', ['branch' => $filterBranch, 'payment' => $filterPayment, 'q' => $search, 'from' => $dateFrom, 'to' => $dateTo]);
?>
<!DOCTYPE html>
<html lang="zxx">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Billing Records</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=4">
    <link rel="stylesheet" href="assets/css/custom.css?v=12">
    <link rel="stylesheet" href="assets/css/admin.css">

</head>

<body>
    <?php include("partials/sidebar.php") ?>
    <?php include("partials/navbar.php") ?>

    <main class="nxl-container">
        <div class="nxl-content">

            <div class="page-header">
                <div class="page-header-left d-flex align-items-center">
                    <div class="page-header-title">
                        <h5 class="m-b-10">Billing</h5>
                    </div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item">Records</li>
                    </ul>
                </div>
                <div class="page-header-right ms-auto">
                    <div class="page-header-right-items">
                        <div class="d-flex align-items-center gap-2 page-header-right-items-wrapper">
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
                                    Billing Records
                                    <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span>
                                </h5>
                                <div class="card-header-action">
                                    <div class="card-header-btn">
                                        <div data-bs-toggle="tooltip" title="Refresh">
                                            <a href="billing-records.php" class="avatar-text avatar-xs bg-warning"></a>
                                        </div>
                                        <div data-bs-toggle="tooltip" title="Maximize/Minimize">
                                            <a href="javascript:void(0);" class="avatar-text avatar-xs bg-success"
                                                data-bs-toggle="expand"></a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="list-toolbar">
                                <?= listSearchControl($search, 'Invoice number') ?>
                                <?= listDateRangeControl($dateFrom, $dateTo, 'Invoice Date') ?>
                            <div class="list-field"><label class="list-label" for="filterPayment">Payment Status</label>                            <select id="filterPayment" class="js-custom-select" data-cs-filter
                                onchange="applyFilters()">
                                <option value="">All Payments</option>
                                <option value="paid" <?= $filterPayment === 'paid'    ? 'selected' : '' ?>>Paid</option>
                                <option value="partial" <?= $filterPayment === 'partial' ? 'selected' : '' ?>>Partial</option>
                                <option value="unpaid" <?= $filterPayment === 'unpaid'  ? 'selected' : '' ?>>Unpaid</option>
                            </select></div>
                            <?php if (isOwner()): ?>
                                <div class="list-field"><label class="list-label" for="filterBranch">Branch</label>
                                <select id="filterBranch" class="js-custom-select" data-cs-filter
                                    onchange="applyFilters()">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?= $b['branch_id'] ?>"
                                            <?= (int)$b['branch_id'] === $filterBranch ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($b['branch_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select></div>
                            <?php endif; ?>
                                <?= listResetControl('billing-records.php', $filtersOn) ?>
                            </div>
                            <div class="card-body custom-card-action p-0">
                                <!-- Scrolls horizontally inside the card only; open action menus are moved to <body> by billing.js so they are not clipped -->
                                <div class="table-responsive bil-table-wrapper">
                                    <table class="table table-hover mb-0 bil-records-table">
                                        <thead>
                                            <tr>
                                                <?= listTh('Date / Time', 'newest', 'oldest', $sortKey, $sortBase) ?>
                                                <th>Patient ID</th>
                                                <?= listTh('Patient Name', 'patient_asc', 'patient_desc', $sortKey, $sortBase) ?>
                                                <?= listTh('Total', 'amount_desc', 'amount_asc', $sortKey, $sortBase) ?>
                                                <?= listTh('Paid', 'paid_desc', 'paid_asc', $sortKey, $sortBase) ?>
                                                <?= listTh('Balance', 'balance_desc', 'balance_asc', $sortKey, $sortBase) ?>
                                                <?= listTh('Status', 'pay_asc', 'pay_desc', $sortKey, $sortBase) ?>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($invoices)): ?>
                                                <tr>
                                                    <td colspan="8" class="text-center py-4 text-muted">
                                                        <i class="feather-file-text me-2"></i><?= $filtersOn ? 'No billing records match the selected filters. <a href="billing-records.php" class="fw-semibold">Reset filters</a>' : 'No billing records found.' ?>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($invoices as $inv):
                                                    // Time = when the invoice was created (only when it is the same day as invoice_date)
                                                    $invTime = '';
                                                    if (!empty($inv['created_at']) && !empty($inv['invoice_date'])) {
                                                        $cTs = strtotime($inv['created_at']);
                                                        if ($cTs && date('Y-m-d', $cTs) === $inv['invoice_date']) $invTime = date('g:i A', $cTs);
                                                    }
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <div class="fw-semibold"><?= formatTrtDate($inv['invoice_date'] ?? null) ?></div>
                                                            <?php if ($invTime): ?><div class="text-muted small"><?= $invTime ?></div><?php endif; ?>
                                                        </td>
                                                        <td><span class="fw-semibold"><?= htmlspecialchars($inv['patients']['patient_code'] ?? '—') ?></span></td>
                                                        <td>
                                                            <a href="patient-records.php?id=<?= (int)$inv['patient_id'] ?>"
                                                                class="text-primary fw-semibold">
                                                                <?= htmlspecialchars($inv['patients']['full_name'] ?? '—') ?>
                                                            </a>
                                                        </td>
                                                        <td class="bil-amt"><?= formatCost(isset($inv['total_amount']) ? (float)$inv['total_amount'] : null) ?></td>
                                                        <td class="bil-amt"><?= formatCost(isset($inv['amount_paid'])  ? (float)$inv['amount_paid']  : null) ?></td>
                                                        <td class="bil-amt"><?= formatCost(isset($inv['balance'])      ? (float)$inv['balance']      : null) ?></td>
                                                        <td>
                                                            <span class="badge <?= invPaymentBadge($inv['payment_status']) ?>">
                                                                <?= ucfirst($inv['payment_status'] ?? 'unpaid') ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-end">
                                                            <div class="dropdown">
                                                                <a href="javascript:void(0);"
                                                                    class="avatar-text avatar-md ms-auto"
                                                                    data-bs-toggle="dropdown"
                                                                    data-bs-strategy="fixed">
                                                                    <i class="feather-more-vertical"></i>
                                                                </a>
                                                                <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                                    <a href="javascript:void(0);" class="dropdown-item"
                                                                        onclick="openBillingDetails('<?= $inv['invoice_id'] ?>')">
                                                                        <i class="feather-eye"></i> View Details
                                                                    </a>
                                                                    <?php if (in_array($inv['payment_status'] ?? 'unpaid', ['paid', 'partial'])): ?>
                                                                        <div class="dropdown-divider"></div>
                                                                        <a href="receipt.php?invoice_id=<?= $inv['invoice_id'] ?>"
                                                                            target="_blank" class="dropdown-item">
                                                                            <i class="feather-printer"></i> Print Receipt
                                                                        </a>
                                                                        <a href="generate-receipt-pdf.php?invoice_id=<?= $inv['invoice_id'] ?>"
                                                                            class="dropdown-item">
                                                                            <i class="feather-download"></i> Download PDF
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (!isDentist() && ($inv['payment_status'] ?? 'unpaid') !== 'paid'): ?>
                                                                        <a href="javascript:void(0);"
                                                                            class="dropdown-item text-success"
                                                                            onclick="openAddPaymentModal(
                                                                                '<?= $inv['invoice_id'] ?>',
                                                                                '<?= htmlspecialchars($inv['invoice_code'] ?? '', ENT_QUOTES) ?>',
                                                                                '<?= $inv['balance'] ?? 0 ?>',
                                                                                '<?= $inv['total_amount'] ?? 0 ?>')">
                                                                            <i class="feather-credit-card"></i> Add Payment
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (isOwner()): ?>
                                                                        <div class="dropdown-divider"></div>
                                                                        <a href="javascript:void(0);"
                                                                            class="dropdown-item text-danger"
                                                                            onclick="deleteBilling(
                                                                                '<?= $inv['invoice_id'] ?>',
                                                                                '<?= htmlspecialchars($inv['invoice_code'] ?? '', ENT_QUOTES) ?>')">
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
                                    Showing <?= $from ?>–<?= $to ?> of <?= $totalRecords ?> record(s)
                                    <?php if ($filtersOn): ?>
                                        &nbsp;·&nbsp;<a href="billing-records.php" class="text-danger">
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
                                            <li>
                                                <a href="<?= $baseUrl ?>&page=<?= $p ?>"
                                                    class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
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


    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
    <script src="assets/js/es-swal.js?v=2"></script>
    <script src="assets/js/billing.js?v=10"></script>
    <script>
        window._isOwner = <?= isOwner() ? 'true' : 'false' ?>;
        window._isAdmin = <?= isAdmin() ? 'true' : 'false' ?>;
        window._currentUser = '<?= htmlspecialchars($currentUser['full_name'] ?? '', ENT_QUOTES) ?>';
    </script>
    <script>
        function applyFilters() {
            window._applyFilters('billing-records.php', {
                'branch': 'filterBranch',
                'payment': 'filterPayment'
            });
        }
    </script>

    <!-- ============================================================
         VIEW DETAILS MODAL (shared .es-detail-modal design)
    ============================================================ -->
    <div class="modal fade es-detail-modal" id="billingViewModal" tabindex="-1" aria-labelledby="bvTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-record-dialog">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-file-text"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="bvTitle">Invoice Details</h5>
                            <div class="apt-record-meta">Invoice No. <span class="apt-record-code" id="bv_code">—</span></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body apt-record-body p-0">
                    <div class="ar-view-body">
                        <div class="ar-summary">
                            <div class="ar-sum-item ar-sum-status">
                                <div class="ar-sum-label">Payment Status</div>
                                <div id="bv_status">—</div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-file"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Invoice Total</div>
                                    <div class="ar-sum-value" id="bv_total">—</div>
                                </div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-check-circle"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Payments Received</div>
                                    <div class="ar-sum-value text-success" id="bv_paid">—</div>
                                </div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-alert-circle"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Outstanding Balance</div>
                                    <div class="ar-sum-value" id="bv_balance">—</div>
                                </div>
                            </div>
                        </div>

                        <div class="ar-cards-grid">
                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-file-text"></i></span>
                                    <h6 class="ar-card-title">Invoice Information</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Invoice No.</span><span class="ar-card-value ar-strong" id="bv_invoice_no">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Invoice Date</span><span class="ar-card-value" id="bv_date">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Branch</span><span class="ar-card-value" id="bv_branch">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Last Updated</span><span class="ar-card-value" id="bv_updated">—</span></div>
                                </div>
                            </section>

                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-user"></i></span>
                                    <h6 class="ar-card-title">Patient Information</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Patient Name</span><span class="ar-card-value ar-strong" id="bv_patient">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Patient ID</span><span class="ar-card-value" id="bv_patient_code">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Contact Number</span><span class="ar-card-value" id="bv_contact">—</span></div>
                                </div>
                            </section>

                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-activity"></i></span>
                                    <h6 class="ar-card-title">Appointment &amp; Treatment</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Appointment No.</span><span class="ar-card-value" id="bv_appointment">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Schedule</span><span class="ar-card-value" id="bv_schedule">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Treatment No.</span><span class="ar-card-value" id="bv_treatment">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Dentist</span><span class="ar-card-value" id="bv_dentist">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Tooth No.</span><span class="ar-card-value" id="bv_tooth">—</span></div>
                                </div>
                            </section>

                            <section class="ar-card">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-layers"></i></span>
                                    <h6 class="ar-card-title">Charges</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Services</span><span class="ar-card-value" id="bv_procedure">—</span></div>
                                    <div class="ar-card-field" id="bv_lines_row" style="display:none;"><span class="ar-card-label">Itemized</span><span class="ar-card-value" id="bv_lines">—</span></div>
                                    <div class="ar-card-field" id="bv_adjust_row" style="display:none;"><span class="ar-card-label">Adjustment</span><span class="ar-card-value" id="bv_adjust">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Treatment Charge</span><span class="ar-card-value" id="bv_subtotal">—</span></div>
                                    <!-- Applied vouchers, read-only (filled by _loadInvoiceDiscounts in billing.js).
                                         Vouchers are applied/removed from Add Payment, not here. -->
                                    <div class="ar-card-field"><span class="ar-card-label">Discount</span><div class="ar-card-value ar-muted" id="bv_discount">None recorded</div></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Total</span><span class="ar-card-value ar-strong" id="bv_total_line">—</span></div>
                                </div>
                            </section>

                            <section class="ar-card ar-card-wide">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-credit-card"></i></span>
                                    <h6 class="ar-card-title">Payment History</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div id="bv_payments_loading" class="text-center py-3 text-muted small">
                                        <span class="spinner-border spinner-border-sm me-1"></span> Loading...
                                    </div>
                                    <div id="bv_payments_wrap" style="display:none;">
                                        <div class="table-responsive es-detail-table-wrap">
                                            <table class="table table-sm mb-0 es-detail-table">
                                                <thead>
                                                    <tr>
                                                        <th>Date</th>
                                                        <th class="text-end">Amount</th>
                                                        <th>Method</th>
                                                        <th>Recorded By</th>
                                                        <th>Notes</th>
                                                        <?php if (isOwner()): ?><th></th><?php endif; ?>
                                                    </tr>
                                                </thead>
                                                <tbody id="bv_payments_body"></tbody>
                                            </table>
                                        </div>
                                        <div id="bv_no_payments" class="text-center py-3 text-muted small" style="display:none;">
                                            <i class="feather-info me-1"></i>No payments recorded yet.
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>

                <div class="modal-footer apt-record-footer d-flex justify-content-between">
                    <div id="bv_add_payment_btn_wrap" class="d-flex gap-2">
                        <?php if (!isDentist()): ?>
                            <button type="button" class="btn btn-success" id="bv_add_payment_btn"
                                onclick="openAddPaymentFromView()">
                                <i class="feather-credit-card me-1"></i> Add Payment
                            </button>
                        <?php endif; ?>
                        <a href="#" target="_blank" class="btn btn-light" id="bv_receipt_btn" style="display:none;">
                            <i class="feather-printer me-1"></i> Receipt
                        </a>
                    </div>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         ADD PAYMENT MODAL (shared .es-detail-modal design)
         Vouchers: api/invoice_discounts.php · Payment: api/payments.php
    ============================================================ -->
    <div class="modal fade es-detail-modal" id="addPaymentModal" tabindex="-1" aria-labelledby="pmTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-credit-card"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="pmTitle">Add Payment</h5>
                            <div class="apt-record-meta">Invoice No. <span class="apt-record-code" id="pm_invoice_code">—</span></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body apt-record-body p-0">
                    <div class="ar-view-body">
                        <div id="pmModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                        <input type="hidden" id="pm_invoice_id">
                        <input type="hidden" id="pm_max_balance">

                        <div class="ar-summary pm-summary">
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-file"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Invoice Total</div>
                                    <div class="ar-sum-value" id="pm_total">—</div>
                                </div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-check-circle"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Payments Received</div>
                                    <div class="ar-sum-value text-success" id="pm_paid">—</div>
                                </div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-alert-circle"></i></span>
                                <div class="ar-sum-text">
                                    <div class="ar-sum-label">Outstanding Balance</div>
                                    <div class="ar-sum-value text-danger" id="pm_balance">—</div>
                                </div>
                            </div>
                        </div>

                        <div class="ar-cards-grid">
                            <!-- Discount vouchers (hidden until the discounts SQL has been run) -->
                            <section class="ar-card ar-card-wide" id="pm_discount_card" style="display:none;">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-tag"></i></span>
                                    <h6 class="ar-card-title">Discount Vouchers</h6>
                                </div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Charge</span><span class="ar-card-value" id="pm_subtotal">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Applied</span><div class="ar-card-value ar-muted" id="pm_discount">None</div></div>
                                    <div class="bv-voucher-apply" id="pm_voucher_apply" style="display:none;">
                                        <label class="ar-card-label" for="pm_voucher_select">Apply a voucher</label>
                                        <div class="bv-voucher-row">
                                            <select id="pm_voucher_select" class="form-select js-custom-select" data-cs-float data-cs-search>
                                                <option value="">Choose a voucher</option>
                                            </select>
                                            <button type="button" class="btn btn-primary" id="pm_voucher_btn" onclick="applyInvoiceVoucher('pm')">Apply</button>
                                        </div>
                                        <!-- Selected voucher details (filled by _renderVoucherPreview in billing.js) -->
                                        <div class="bv-voucher-preview" id="pm_voucher_preview" aria-live="polite" hidden></div>
                                        <div class="bv-voucher-msg" id="pm_voucher_msg"></div>
                                    </div>
                                </div>
                            </section>

                            <!-- Payment details -->
                            <section class="ar-card ar-card-wide">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-dollar-sign"></i></span>
                                    <h6 class="ar-card-title">Payment Details</h6>
                                </div>
                                <div class="pm-form">
                                    <div class="pm-field">
                                        <label class="pm-label" for="pm_amount">Amount <span class="text-danger">*</span></label>
                                        <div class="pm-input-prefix">
                                            <span aria-hidden="true">₱</span>
                                            <input type="number" id="pm_amount" class="form-control" placeholder="0.00" min="0.01" step="0.01">
                                        </div>
                                        <div class="pm-quick">
                                            <button type="button" class="pm-quick-btn" onclick="setPaymentAmount('full')">Full balance</button>
                                            <button type="button" class="pm-quick-btn" onclick="setPaymentAmount('half')">Half</button>
                                            <span class="pm-hint" id="pm_amount_hint"></span>
                                        </div>
                                    </div>
                                    <div class="pm-field">
                                        <label class="pm-label" for="pm_method">Payment Method <span class="text-danger">*</span></label>
                                        <select id="pm_method" class="form-select js-custom-select" data-cs-float data-cs-search>
                                            <option value="">Select method</option>
                                            <option value="cash">Cash</option>
                                            <option value="gcash">GCash</option>
                                            <option value="credit_card">Credit Card</option>
                                            <option value="debit_card">Debit Card</option>
                                            <option value="bank_transfer">Bank Transfer</option>
                                        </select>
                                    </div>
                                    <div class="pm-field">
                                        <label class="pm-label" for="pm_date">Payment Date <span class="text-danger">*</span></label>
                                        <input type="date" id="pm_date" class="form-control js-date-picker" data-dp-max="today" placeholder="Select date">
                                    </div>
                                    <div class="pm-field">
                                        <label class="pm-label" for="pm_recorded_by">Recorded By</label>
                                        <input type="text" id="pm_recorded_by" class="form-control pm-readonly" readonly tabindex="-1">
                                    </div>
                                    <div class="pm-field pm-span">
                                        <label class="pm-label" for="pm_notes">Notes <span class="pm-optional">(optional)</span></label>
                                        <input type="text" id="pm_notes" class="form-control" placeholder="e.g. Paid at front desk" maxlength="250">
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>

                <div class="modal-footer apt-record-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success px-4" id="pmSaveBtn" onclick="savePayment()">
                        <i class="feather-check me-1"></i> Record Payment
                    </button>
                </div>
            </div>
        </div>
    </div>

</body>

</html>
