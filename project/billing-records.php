<?php
define('REQUIRED_ROLES', ['owner', 'admin']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/admin_batch.php';
admin_release_session();   // read-only page render: don't hold the session lock while querying
require_once __DIR__ . '/controllers/treatment_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';
require_once __DIR__ . '/controllers/list_filters.php';
require_once __DIR__ . '/controllers/record_ui.php';   // Appointment Records cell markup

$filterBranch  = isset($_GET['branch'])  ? (int)$_GET['branch']  : 0;
$filterPayment = in_array($_GET['payment'] ?? '', ['paid', 'partial', 'unpaid'], true) ? $_GET['payment'] : '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$qBranch       = scopedBranch($filterBranch);   // owner: as filtered; admin: own branch only


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
admin_prefetch(['list' => admin_q('invoices', implode('&', invoiceRecordsQuery($page, $qBranch, $filterPayment, $sortOrder, $extra)), ['Prefer: count=exact'])]);
$branches     = getAllBranches();
$data         = getInvoiceRecords($page, $qBranch, $filterPayment, $sortOrder, $extra);
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
    <link rel="stylesheet" href="assets/css/es-swal.css?v=10">
    <link rel="stylesheet" href="assets/css/custom.css?v=18">
    <link rel="stylesheet" href="assets/css/admin.css">

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

            <div class="main-content an-page ar-page">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card stretch stretch-full">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    Billing Records
                                    <span class="an-pill info ms-2"><?= $totalRecords ?> <?= $filtersOn ? 'found' : 'total' ?></span>
                                    <span class="rec-card-sub">Patient invoices, payments and remaining balances</span>
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
                                    <table class="table table-hover mb-0 bil-records-table rec-table ar-table">
                                        <thead>
                                            <tr>
                                                <?= listTh('Date / Time', 'newest', 'oldest', $sortKey, $sortBase) ?>
                                                <?= listTh('Patient', 'patient_asc', 'patient_desc', $sortKey, $sortBase) ?>
                                                <?= listTh('Total', 'amount_desc', 'amount_asc', $sortKey, $sortBase) ?>
                                                <?= listTh('Paid', 'paid_desc', 'paid_asc', $sortKey, $sortBase) ?>
                                                <?= listTh('Balance', 'balance_desc', 'balance_asc', $sortKey, $sortBase) ?>
                                                <?= listTh('Status', 'pay_asc', 'pay_desc', $sortKey, $sortBase) ?>
                                                <th class="rec-col-action text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($invoices)): ?>
                                                <?= listEmpty(7, (bool)$filtersOn, 'No billing records yet', 'An invoice is created automatically when a treatment is finished.', 'feather-file-text', 'billing-records.php') ?>
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
                                                            <?= recWhen(formatTrtDate($inv['invoice_date'] ?? null), (string)$invTime) ?>
                                                        </td>
                                                        <td>
                                                            <?= recWho($inv['patients']['full_name'] ?? '', (int)$inv['patient_id'], 'patient-records.php?id=' . (int)$inv['patient_id'], (string)($inv['patients']['patient_code'] ?? '')) ?>
                                                        </td>
                                                        <td class="bil-amt rec-amount"><?= formatCost(isset($inv['total_amount']) ? (float)$inv['total_amount'] : null) ?></td>
                                                        <td class="bil-amt"><?= formatCost(isset($inv['amount_paid'])  ? (float)$inv['amount_paid']  : null) ?></td>
                                                        <td class="bil-amt"><?= formatCost(isset($inv['balance'])      ? (float)$inv['balance']      : null) ?></td>
                                                        <td>
                                                            <?= recPill(ucfirst($inv['payment_status'] ?? 'unpaid'), recToneFromBadge(invPaymentBadge($inv['payment_status']))) ?>
                                                        </td>
                                                        <td class="rec-col-action">
                                                            <div class="dropdown">
                                                                <a href="javascript:void(0);"
                                                                    class="avatar-text avatar-md ms-auto"
                                                                    data-bs-toggle="dropdown"
                                                                    data-bs-strategy="fixed"
                                                                    aria-label="Actions for <?= htmlspecialchars($inv['invoice_code'] ?? 'this invoice', ENT_QUOTES) ?>">
                                                                    <i class="feather-more-vertical"></i>
                                                                </a>
                                                                <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                                    <a href="javascript:void(0);" class="dropdown-item"
                                                                        onclick="openBillingDetails('<?= $inv['invoice_id'] ?>')">
                                                                        <i class="feather-eye"></i> View Details
                                                                    </a>
                                                                    <?php if (in_array($inv['payment_status'] ?? 'unpaid', ['paid', 'partial'])): ?>
                                                                        <div class="dropdown-divider"></div>
                                                                        <?php /* Print Receipt opens the receipt in a modal and Download PDF
                                                                                 shows its progress in the button (assets/js/billing.js). The
                                                                                 hrefs stay as the no-JavaScript fallback. */ ?>
                                                                        <a href="receipt.php?invoice_id=<?= (int)$inv['invoice_id'] ?>"
                                                                            target="_blank" class="dropdown-item"
                                                                            data-receipt-modal="<?= (int)$inv['invoice_id'] ?>"
                                                                            data-invoice-code="<?= htmlspecialchars($inv['invoice_code'] ?? '', ENT_QUOTES) ?>">
                                                                            <i class="feather-printer"></i> Print Receipt
                                                                        </a>
                                                                        <a href="generate-receipt-pdf.php?invoice_id=<?= (int)$inv['invoice_id'] ?>"
                                                                            class="dropdown-item"
                                                                            data-receipt-pdf="<?= (int)$inv['invoice_id'] ?>"
                                                                            data-invoice-code="<?= htmlspecialchars($inv['invoice_code'] ?? '', ENT_QUOTES) ?>">
                                                                            <i class="feather-download"></i> <span class="rc-pdf-label">Download PDF</span>
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
                                <?= listFootCount($from, $to, $totalRecords, 'invoice', 'billing-records.php', (bool)$filtersOn) ?>
                                <?= listPager($baseUrl, $page, $totalPages) ?>
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
    <script src="assets/js/admin.js?v=7"></script>
    <script src="assets/js/es-ajax.js?v=7"></script>
    <script src="assets/js/custom-select.js?v=3"></script>
    <script src="assets/js/es-records.js?v=3"></script>
    <script src="assets/js/es-swal.js?v=3"></script>
    <script src="assets/js/billing.js?v=21"></script>
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
    <div class="modal fade es-detail-modal ar-detail-modal" id="billingViewModal" tabindex="-1" aria-labelledby="bvTitle" aria-hidden="true">
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
         ADD PAYMENT — 4-step wizard (shared .es-detail-modal design)
         1 Invoice Details (read-only) → 2 Adjustments (discount vouchers,
         api/invoice_discounts.php, applied immediately as before) →
         3 Payment Information → 4 Review & Confirm → Record Payment
         (savePayment → api/payments.php: same request, server-side balance
         check, status update and audit log). Steering: assets/js/billing.js.
         Footer: Cancel left; Back + Next / Record Payment right.
    ============================================================ -->
    <div class="modal fade es-detail-modal" id="addPaymentModal" tabindex="-1" aria-labelledby="pmTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl modal-fullscreen-md-down pm-wiz-dialog">
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
                    <div class="pm-wiz">
                        <!-- Vertical stepper (horizontal on small screens) -->
                        <nav class="pm-steps" aria-label="Add payment steps">
                            <ol>
                                <li class="pm-step" data-step="1"><button type="button" class="pm-step-btn" data-goto="1"><span class="pm-step-dot"><span class="pm-step-num">1</span><i class="feather-check"></i></span><span class="pm-step-text"><span class="pm-step-title">Invoice Details</span><span class="pm-step-sub">Review the invoice</span></span></button></li>
                                <li class="pm-step" data-step="2"><button type="button" class="pm-step-btn" data-goto="2"><span class="pm-step-dot"><span class="pm-step-num">2</span><i class="feather-check"></i></span><span class="pm-step-text"><span class="pm-step-title">Adjustments</span><span class="pm-step-sub">Discount vouchers</span></span></button></li>
                                <li class="pm-step" data-step="3"><button type="button" class="pm-step-btn" data-goto="3"><span class="pm-step-dot"><span class="pm-step-num">3</span><i class="feather-check"></i></span><span class="pm-step-text"><span class="pm-step-title">Payment Information</span><span class="pm-step-sub">Amount, date and method</span></span></button></li>
                                <li class="pm-step" data-step="4"><button type="button" class="pm-step-btn" data-goto="4"><span class="pm-step-dot"><span class="pm-step-num">4</span><i class="feather-check"></i></span><span class="pm-step-text"><span class="pm-step-title">Review &amp; Confirm</span><span class="pm-step-sub">Check, then record</span></span></button></li>
                            </ol>
                        </nav>

                        <div class="pm-wiz-main">
                            <div id="pmModalError" class="alert alert-danger small py-2 px-3 mb-3" role="alert" style="display:none;"></div>
                            <input type="hidden" id="pm_invoice_id">
                            <input type="hidden" id="pm_max_balance">

                            <!-- STEP 1 — Invoice Details (read-only) -->
                            <section class="pm-pane" data-pane="1" aria-labelledby="pm_h1">
                                <h6 class="pm-pane-title" id="pm_h1">Invoice Details</h6>
                                <p class="pm-pane-lead">Check that this is the right invoice before adding a payment.</p>
                                <div class="ar-card">
                                    <div class="ar-card-header">
                                        <span class="ar-card-icon"><i class="feather-file-text"></i></span>
                                        <h6 class="ar-card-title">Invoice</h6>
                                    </div>
                                    <div class="ar-card-fields pm-info-grid">
                                        <div class="ar-card-field"><span class="ar-card-label">Invoice Number</span><span class="ar-card-value fw-semibold" id="pm_s1_code">—</span></div>
                                        <div class="ar-card-field"><span class="ar-card-label">Patient Name</span><span class="ar-card-value" id="pm_s1_patient">—</span></div>
                                        <div class="ar-card-field"><span class="ar-card-label">Branch</span><span class="ar-card-value" id="pm_s1_branch">—</span></div>
                                        <div class="ar-card-field"><span class="ar-card-label">Invoice Date</span><span class="ar-card-value" id="pm_s1_date">—</span></div>
                                        <div class="ar-card-field"><span class="ar-card-label">Due Date</span><span class="ar-card-value ar-muted" id="pm_s1_due" title="Invoices have no due date on record">Not set</span></div>
                                        <div class="ar-card-field"><span class="ar-card-label">Invoice Status</span><span class="ar-card-value" id="pm_s1_status">—</span></div>
                                    </div>
                                </div>
                                <div class="ar-summary pm-summary">
                                    <div class="ar-sum-item">
                                        <span class="ar-sum-icon"><i class="feather-file"></i></span>
                                        <div class="ar-sum-text"><div class="ar-sum-label">Invoice Total</div><div class="ar-sum-value" id="pm_total">—</div></div>
                                    </div>
                                    <div class="ar-sum-item">
                                        <span class="ar-sum-icon"><i class="feather-check-circle"></i></span>
                                        <div class="ar-sum-text"><div class="ar-sum-label">Payments Received</div><div class="ar-sum-value text-success" id="pm_paid">—</div></div>
                                    </div>
                                    <div class="ar-sum-item">
                                        <span class="ar-sum-icon"><i class="feather-alert-circle"></i></span>
                                        <div class="ar-sum-text"><div class="ar-sum-label">Outstanding Balance</div><div class="ar-sum-value text-danger" id="pm_balance">—</div></div>
                                    </div>
                                </div>
                                <div class="pm-loading" id="pm_s1_loading" role="status"><span class="spinner-border spinner-border-sm text-primary"></span> Loading the invoice…</div>
                            </section>

                            <!-- STEP 2 — Adjustments (discount vouchers) -->
                            <section class="pm-pane" data-pane="2" aria-labelledby="pm_h2" hidden>
                                <h6 class="pm-pane-title" id="pm_h2">Adjustments</h6>
                                <p class="pm-pane-lead">Apply an eligible discount voucher, if any. This step is optional.</p>
                                <section class="ar-card" id="pm_discount_card">
                                    <div class="ar-card-header">
                                        <span class="ar-card-icon"><i class="feather-tag"></i></span>
                                        <h6 class="ar-card-title">Discount Vouchers</h6>
                                    </div>
                                    <div class="ar-card-fields">
                                        <div class="ar-card-field"><span class="ar-card-label">Original Charge</span><span class="ar-card-value" id="pm_subtotal">—</span></div>
                                        <div class="ar-card-field"><span class="ar-card-label">Applied</span><div class="ar-card-value ar-muted" id="pm_discount">None</div></div>
                                        <div class="ar-card-field pm-adjusted-row"><span class="ar-card-label">Adjusted Amount</span><span class="ar-card-value fw-bold" id="pm_adjusted">—</span></div>
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
                                <div class="pm-loading" id="pm_discount_loading" role="status" hidden><span class="spinner-border spinner-border-sm text-primary"></span> Loading discount vouchers…</div>
                                <div class="pm-empty-note" id="pm_discount_unavailable" hidden>
                                    <i class="feather-info"></i> <span>Discount vouchers aren't available for this invoice. Continue to the next step.</span>
                                </div>
                            </section>

                            <!-- STEP 3 — Payment Information -->
                            <section class="pm-pane" data-pane="3" aria-labelledby="pm_h3" hidden>
                                <h6 class="pm-pane-title" id="pm_h3">Payment Information</h6>
                                <p class="pm-pane-lead">Enter the full balance or a partial amount, then choose how the patient paid.</p>
                                <div class="pm-form">
                                    <div class="pm-field">
                                        <label class="pm-label" for="pm_amount">Amount to Pay <span class="text-danger">*</span></label>
                                        <div class="pm-input-prefix">
                                            <span aria-hidden="true">₱</span>
                                            <input type="number" id="pm_amount" class="form-control" placeholder="0.00" min="0.01" step="0.01" inputmode="decimal">
                                        </div>
                                        <div class="pm-quick">
                                            <button type="button" class="pm-quick-btn" onclick="setPaymentAmount('full')">Full balance</button>
                                            <button type="button" class="pm-quick-btn" onclick="setPaymentAmount('half')">Half</button>
                                            <span class="pm-hint" id="pm_amount_hint"></span>
                                        </div>
                                    </div>
                                    <div class="pm-field">
                                        <label class="pm-label" for="pm_date">Payment Date <span class="text-danger">*</span></label>
                                        <input type="date" id="pm_date" class="form-control js-date-picker" data-dp-max="today" placeholder="Select date">
                                    </div>

                                    <!-- Payment method: 4 category cards; the chosen category reveals
                                         its own options below. UI only for now: Record Payment still
                                         sends just the method value it always did (hidden #pm_method:
                                         cash / gcash / credit_card / debit_card / bank_transfer).
                                         Cash needs nothing more than the Amount to Pay. Reference
                                         numbers, bank details etc. are NOT
                                         saved or sent anywhere yet (assets/js/billing.js). -->
                                    <div class="pm-field pm-span">
                                        <span class="pm-label" id="pm_cat_label">Payment Method <span class="text-danger">*</span></span>
                                        <input type="hidden" id="pm_method" value="">
                                        <div class="pm-cats" role="radiogroup" aria-labelledby="pm_cat_label">
                                            <label class="pm-cat">
                                                <input type="radio" name="pm_cat" value="cash">
                                                <span class="pm-cat-icon" aria-hidden="true"><i class="feather-dollar-sign"></i></span>
                                                <span class="pm-cat-text"><span class="pm-cat-name">Cash</span><span class="pm-cat-desc">Paid in person at the clinic</span></span>
                                            </label>
                                            <label class="pm-cat">
                                                <input type="radio" name="pm_cat" value="ewallet">
                                                <span class="pm-cat-icon" aria-hidden="true"><i class="feather-smartphone"></i></span>
                                                <span class="pm-cat-text"><span class="pm-cat-name">E-Wallet</span><span class="pm-cat-desc">GCash, Maya or GrabPay</span></span>
                                            </label>
                                            <label class="pm-cat">
                                                <input type="radio" name="pm_cat" value="card">
                                                <span class="pm-cat-icon" aria-hidden="true"><i class="feather-credit-card"></i></span>
                                                <span class="pm-cat-text"><span class="pm-cat-name">Card</span><span class="pm-cat-desc">Credit or debit via the clinic MPOS</span></span>
                                            </label>
                                            <label class="pm-cat">
                                                <input type="radio" name="pm_cat" value="bank">
                                                <span class="pm-cat-icon" aria-hidden="true"><i class="feather-repeat"></i></span>
                                                <span class="pm-cat-text"><span class="pm-cat-name">Bank Transfer</span><span class="pm-cat-desc">Online or over-the-counter transfer</span></span>
                                            </label>
                                        </div>

                                        <!-- E-Wallet (official logos: assets/images/payments, same as the Patient Portal) -->
                                        <div class="pm-panel" data-pm-panel="ewallet" hidden>
                                            <span class="pm-label" id="pm_ew_label">E-Wallet Provider <span class="text-danger">*</span></span>
                                            <div class="pm-opts pm-opts-3" role="radiogroup" aria-labelledby="pm_ew_label">
                                                <label class="pm-opt"><input type="radio" name="pm_ewallet" value="gcash"><img src="assets/images/payments/gcash.svg" alt="" class="pm-opt-logo" width="76" height="18"><span class="visually-hidden">GCash</span></label>
                                                <label class="pm-opt"><input type="radio" name="pm_ewallet" value="maya"><img src="assets/images/payments/maya.svg" alt="" class="pm-opt-logo" width="62" height="18"><span class="visually-hidden">Maya</span></label>
                                                <label class="pm-opt"><input type="radio" name="pm_ewallet" value="grabpay"><img src="assets/images/payments/grabpay.svg" alt="" class="pm-opt-logo" width="71" height="18"><span class="visually-hidden">GrabPay</span></label>
                                            </div>
                                            <div class="pm-reveal" data-pm-reveal="ewallet" hidden>
                                                <label class="pm-label" for="pm_ew_ref">Reference Number</label>
                                                <input type="text" id="pm_ew_ref" class="form-control" placeholder="e.g. 1234 567 890123" maxlength="40" autocomplete="off">
                                            </div>
                                        </div>

                                        <!-- Card: the clinic MPOS terminal takes the card. Never ask for
                                             the card number, CVV or PIN. -->
                                        <div class="pm-panel" data-pm-panel="card" hidden>
                                            <span class="pm-label" id="pm_card_label">Card Type <span class="text-danger">*</span></span>
                                            <div class="pm-opts pm-opts-2" role="radiogroup" aria-labelledby="pm_card_label">
                                                <label class="pm-opt"><input type="radio" name="pm_card" value="credit_card"><i class="feather-credit-card" aria-hidden="true"></i><span>Credit Card</span></label>
                                                <label class="pm-opt"><input type="radio" name="pm_card" value="debit_card"><i class="feather-credit-card" aria-hidden="true"></i><span>Debit Card</span></label>
                                            </div>
                                            <div class="pm-reveal" data-pm-reveal="card" hidden>
                                                <label class="pm-label" for="pm_card_ref">MPOS Reference / Approval Code</label>
                                                <input type="text" id="pm_card_ref" class="form-control" placeholder="From the MPOS receipt" maxlength="40" autocomplete="off">
                                                <div class="pm-sub-hint"><i class="feather-shield"></i> Processed on the clinic MPOS terminal. Never enter the card number, CVV or PIN here.</div>
                                            </div>
                                        </div>

                                        <!-- Bank Transfer -->
                                        <div class="pm-panel" data-pm-panel="bank" hidden>
                                            <div class="pm-panel-grid">
                                                <div class="pm-field">
                                                    <label class="pm-label" for="pm_bank_name">Bank Name</label>
                                                    <select id="pm_bank_name" class="form-select js-custom-select" data-cs-float data-cs-search>
                                                        <option value="">Select bank</option>
                                                        <option>BDO Unibank</option>
                                                        <option>Bank of the Philippine Islands (BPI)</option>
                                                        <option>Metrobank</option>
                                                        <option>Land Bank of the Philippines</option>
                                                        <option>Philippine National Bank (PNB)</option>
                                                        <option>Security Bank</option>
                                                        <option>UnionBank of the Philippines</option>
                                                        <option>China Banking Corporation</option>
                                                        <option>RCBC</option>
                                                        <option>EastWest Bank</option>
                                                        <option>Other</option>
                                                    </select>
                                                </div>
                                                <div class="pm-field">
                                                    <label class="pm-label" for="pm_bank_ref">Reference Number</label>
                                                    <input type="text" id="pm_bank_ref" class="form-control" placeholder="Transaction reference" maxlength="40" autocomplete="off">
                                                </div>
                                                <div class="pm-field">
                                                    <label class="pm-label" for="pm_bank_sender">Account Holder / Sender Name</label>
                                                    <input type="text" id="pm_bank_sender" class="form-control" placeholder="Name on the sending account" maxlength="120" autocomplete="off">
                                                </div>
                                                <div class="pm-field">
                                                    <label class="pm-label" for="pm_bank_date">Transfer Date</label>
                                                    <input type="date" id="pm_bank_date" class="form-control js-date-picker" data-dp-max="today" placeholder="Select date">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="pm-method-note" id="pm_method_note" role="status" hidden></div>
                                    </div>

                                    <div class="pm-field">
                                        <label class="pm-label" for="pm_recorded_by">Recorded By</label>
                                        <input type="text" id="pm_recorded_by" class="form-control pm-readonly" readonly tabindex="-1">
                                    </div>
                                    <div class="pm-field">
                                        <label class="pm-label" for="pm_notes">Notes <span class="pm-optional">(optional)</span></label>
                                        <input type="text" id="pm_notes" class="form-control" placeholder="e.g. Paid at front desk" maxlength="250">
                                    </div>
                                </div>
                            </section>

                            <!-- STEP 4 — Review & Confirm (filled by _pmFillReview) -->
                            <section class="pm-pane" data-pane="4" aria-labelledby="pm_h4" hidden>
                                <h6 class="pm-pane-title" id="pm_h4">Review &amp; Confirm</h6>
                                <p class="pm-pane-lead">Check everything below. Use Back, or click a step on the left, to make changes.</p>
                                <div class="pm-review">
                                    <div class="ar-card">
                                        <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-file-text"></i></span><h6 class="ar-card-title">Invoice</h6><button type="button" class="pm-edit-link" data-goto="1">View</button></div>
                                        <div class="ar-card-fields">
                                            <div class="ar-card-field"><span class="ar-card-label">Invoice Number</span><span class="ar-card-value" id="pm_rv_code">—</span></div>
                                            <div class="ar-card-field"><span class="ar-card-label">Patient</span><span class="ar-card-value" id="pm_rv_patient">—</span></div>
                                            <div class="ar-card-field"><span class="ar-card-label">Branch</span><span class="ar-card-value" id="pm_rv_branch">—</span></div>
                                        </div>
                                    </div>
                                    <div class="ar-card">
                                        <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-dollar-sign"></i></span><h6 class="ar-card-title">Payment</h6><button type="button" class="pm-edit-link" data-goto="3">Edit</button></div>
                                        <div class="ar-card-fields">
                                            <div class="ar-card-field"><span class="ar-card-label">Payment Date</span><span class="ar-card-value" id="pm_rv_date">—</span></div>
                                            <div class="ar-card-field"><span class="ar-card-label">Payment Method</span><span class="ar-card-value" id="pm_rv_method">—</span></div>
                                            <div class="ar-card-field"><span class="ar-card-label">Reference No.</span><span class="ar-card-value" id="pm_rv_ref">—</span></div>
                                            <div class="ar-card-field" id="pm_rv_notes_row"><span class="ar-card-label">Notes</span><span class="ar-card-value" id="pm_rv_notes">—</span></div>
                                        </div>
                                    </div>
                                    <div class="ar-card pm-review-amounts">
                                        <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-percent"></i></span><h6 class="ar-card-title">Amounts</h6></div>
                                        <div class="ar-card-fields">
                                            <div class="ar-card-field"><span class="ar-card-label">Original Invoice Amount</span><span class="ar-card-value" id="pm_rv_original">—</span></div>
                                            <div class="ar-card-field"><span class="ar-card-label">Discount Applied</span><span class="ar-card-value" id="pm_rv_discount">—</span></div>
                                            <div class="ar-card-field"><span class="ar-card-label">Adjusted Invoice Amount</span><span class="ar-card-value fw-semibold" id="pm_rv_adjusted">—</span></div>
                                            <div class="ar-card-field"><span class="ar-card-label">Previously Received</span><span class="ar-card-value" id="pm_rv_paid">—</span></div>
                                            <div class="ar-card-field pm-rv-current"><span class="ar-card-label">This Payment</span><span class="ar-card-value" id="pm_rv_amount">—</span></div>
                                            <div class="ar-card-field pm-rv-remaining"><span class="ar-card-label">Remaining Balance</span><span class="ar-card-value" id="pm_rv_remaining">—</span></div>
                                        </div>
                                    </div>
                                </div>
                                <p class="pm-review-foot" id="pm_rv_status"></p>
                            </section>
                        </div>
                    </div>
                </div>

                <div class="modal-footer apt-record-footer pm-wiz-footer">
                    <div class="pm-wiz-nav">
                        <button type="button" class="btn btn-primary px-4" id="pmNextBtn">Next <i class="feather-arrow-right ms-1"></i></button>
                        <button type="button" class="btn btn-success px-4" id="pmSaveBtn" onclick="savePayment()" hidden>
                            <i class="feather-check me-1"></i> Record Payment
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="pmBackBtn"><i class="feather-arrow-left me-1"></i> Back</button>
                    </div>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         RECEIPT PREVIEW MODAL (Billing Records → Action → Print Receipt)
         Shows receipt.php?embed=1 — the same receipt template, data and
         branch-access check as the standalone page — in a same-site iframe
         (the Patient Portal uses the same embed mode). Print prints only the
         receipt frame. Footer: primary (Print Receipt) left, Close right.
    ============================================================ -->
    <div class="modal fade es-detail-modal" id="receiptPreviewModal" tabindex="-1" aria-labelledby="rcpTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-printer"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="rcpTitle">Official Receipt</h5>
                            <div class="apt-record-meta">Invoice No. <span class="apt-record-code" id="rcp_code">—</span></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-record-body rcp-body">
                    <div class="rcp-loading" id="rcpLoading" role="status">
                        <span class="spinner-border spinner-border-sm text-primary" aria-hidden="true"></span> Loading receipt…
                    </div>
                    <iframe class="rcp-frame" id="rcpFrame" title="Official receipt" tabindex="0"></iframe>
                </div>
                <div class="modal-footer apt-record-footer">
                    <button type="button" class="btn btn-primary px-4" id="rcpPrintBtn" disabled>
                        <i class="feather-printer me-1"></i> Print Receipt
                    </button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

</body>

</html>
