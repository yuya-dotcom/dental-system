<?php
define('REQUIRED_ROLES', ['owner', 'admin']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/treatment_controller.php';
require_once __DIR__ . '/controllers/branch_controller.php';

$filterBranch  = isset($_GET['branch'])  ? (int)$_GET['branch']    : 0;
$filterPayment = isset($_GET['payment']) ? trim($_GET['payment'])   : '';
$page          = max(1, (int)($_GET['page'] ?? 1));

$branches     = getAllBranches();
$data         = getInvoiceRecords($page, $filterBranch, $filterPayment);
$invoices     = $data['rows'];
$totalRecords = $data['totalRecords'];
$totalPages   = $data['totalPages'];
$from         = $data['from'];
$to           = $data['to'];

$baseUrl = 'billing-records.php?branch=' . $filterBranch . '&payment=' . urlencode($filterPayment);
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
                            <select id="filterPayment" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:150px;">
                                <option value="">All Payments</option>
                                <option value="paid" <?= $filterPayment === 'paid'    ? 'selected' : '' ?>>Paid</option>
                                <option value="partial" <?= $filterPayment === 'partial' ? 'selected' : '' ?>>Partial</option>
                                <option value="unpaid" <?= $filterPayment === 'unpaid'  ? 'selected' : '' ?>>Unpaid</option>
                            </select>
                            <?php if (isOwner()): ?>
                                <select id="filterBranch" class="form-select form-select-sm hdr-control" onchange="applyFilters()" style="max-width:180px;">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?= $b['branch_id'] ?>" <?= (int)$b['branch_id'] === $filterBranch ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($b['branch_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
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
                                    Billing Records
                                    <span class="badge bg-soft-primary text-primary ms-2"><?= $totalRecords ?> total</span>
                                </h5>
                                <div class="card-header-action">
                                    <div class="card-header-btn">
                                        <div data-bs-toggle="tooltip" title="Refresh">
                                            <a href="billing-records.php" class="avatar-text avatar-xs bg-warning"></a>
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
                                                <th>Invoice ID</th>
                                                <th>Date</th>
                                                <th>Patient Name</th>
                                                <th>Branch</th>
                                                <th>Treatment</th>
                                                <th>Total</th>
                                                <th>Paid</th>
                                                <th>Balance</th>
                                                <th>Payment Status</th>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($invoices)): ?>
                                                <tr>
                                                    <td colspan="11" class="text-center py-4 text-muted">
                                                        <i class="feather-file-text me-2"></i>No billing records found.
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($invoices as $inv): ?>
                                                    <tr>
                                                        <td><span class="fw-semibold"><?= htmlspecialchars($inv['invoice_code'] ?? '—') ?></span></td>
                                                        <td><?= formatTrtDate($inv['invoice_date'] ?? null) ?></td>
                                                        <td>
                                                            <a href="patient-record.php?id=<?= $t['patient_id'] ?>" class="text-primary fw-semibold">
                                                                <?= htmlspecialchars($t['patients']['full_name'] ?? '—') ?>
                                                            </a>
                                                        </td>
                                                        <td><?= htmlspecialchars($inv['branches']['branch_name'] ?? '—') ?></td>
                                                        <td><?= htmlspecialchars($inv['treatments']['treatment_code'] ?? '—') ?></td>
                                                        <td><?= formatCost($inv['total_amount'] ?? null) ?></td>
                                                        <td><?= formatCost($inv['amount_paid'] ?? null) ?></td>
                                                        <td><?= formatCost($inv['balance'] ?? null) ?></td>
                                                        <td>
                                                            <span class="badge <?= invPaymentBadge($inv['payment_status']) ?>">
                                                                <?= ucfirst($inv['payment_status'] ?? 'unpaid') ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-end">
                                                            <div class="dropdown">
                                                                <a href="javascript:void(0);" class="avatar-text avatar-md ms-auto" data-bs-toggle="dropdown" data-bs-strategy="fixed">
                                                                    <i class="feather-more-vertical"></i>
                                                                </a>
                                                                <div class="dropdown-menu dropdown-menu-end action-dropdown">
                                                                    <!-- View Details -->
                                                                    <a href="javascript:void(0);" class="dropdown-item"
                                                                        onclick="openBillingDetails('<?= $inv['invoice_id'] ?>')">
                                                                        <i class="feather-eye"></i> View Details
                                                                    </a>
                                                                    <?php if (in_array($inv['payment_status'] ?? 'unpaid', ['paid', 'partial'])): ?>
                                                                        <div class="dropdown-divider"></div>
                                                                        <a href="receipt.php?invoice_id=<?= $inv['invoice_id'] ?>"
                                                                            target="_blank"
                                                                            class="dropdown-item">
                                                                            <i class="feather-printer"></i> Print Receipt
                                                                        </a>
                                                                        <a href="generate-receipt-pdf.php?invoice_id=<?= $inv['invoice_id'] ?>"
                                                                            class="dropdown-item">
                                                                            <i class="feather-download"></i> Download PDF
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (!isDentist() && ($inv['payment_status'] ?? 'unpaid') !== 'paid'): ?>
                                                                        <!-- Add Payment — admin/owner only, unpaid/partial only -->
                                                                        <a href="javascript:void(0);" class="dropdown-item text-success"
                                                                            onclick="openAddPaymentModal('<?= $inv['invoice_id'] ?>','<?= htmlspecialchars($inv['invoice_code'] ?? '', ENT_QUOTES) ?>','<?= $inv['balance'] ?? 0 ?>','<?= $inv['total_amount'] ?? 0 ?>')">
                                                                            <i class="feather-credit-card"></i> Add Payment
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (isOwner()): ?>
                                                                        <div class="dropdown-divider"></div>
                                                                        <a href="javascript:void(0);" class="dropdown-item text-danger"
                                                                            onclick="deleteBilling('<?= $inv['invoice_id'] ?>','<?= htmlspecialchars($inv['invoice_code'] ?? '', ENT_QUOTES) ?>')">
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
                                    <?php if ($filterBranch || $filterPayment): ?>
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
                                        $wEnd = min($totalPages, $wStart + 4);
                                        $wStart = max(1, $wEnd - 4);
                                        ?>
                                        <?php if ($wStart > 1): ?><li><a href="<?= $baseUrl ?>&page=1">1</a></li><?php if ($wStart > 2): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><?php endif; ?>
                                                <?php for ($p = $wStart; $p <= $wEnd; $p++): ?>
                                                    <li><a href="<?= $baseUrl ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a></li>
                                                <?php endfor; ?>
                                                <?php if ($wEnd < $totalPages): ?><?php if ($wEnd < $totalPages - 1): ?><li><span class="px-1 text-muted">…</span></li><?php endif; ?><li><a href="<?= $baseUrl ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a></li><?php endif; ?>
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
         ADD / EDIT MODAL
    ============================================================ -->
    <div class="modal fade" id="billingModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="billingModalTitle">Add Billing</h5>
                        <p class="text-muted small mb-0">Fill in the billing information below.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="billingModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="billing_id">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Invoice Date <span class="text-danger">*</span></label>
                            <input type="date" id="billing_date" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Branch <span class="text-danger">*</span></label>
                            <select id="billing_branch" class="form-select" onchange="loadBillingPatients()">
                                <option value="">— Select Branch —</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?= $b['branch_id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Patient <span class="text-danger">*</span></label>
                            <select id="billing_patient" class="form-select" onchange="loadBillingTreatments()">
                                <option value="">— Select Patient —</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Treatment / Appointment <span class="text-danger">*</span></label>
                            <select id="billing_treatment" class="form-select" onchange="autoFillAmount()">
                                <option value="">— Select Treatment —</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Total Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" id="billing_total" class="form-control" placeholder="0.00" min="0" step="0.01" oninput="recalcBalance()">
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Amount Paid <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" id="billing_paid" class="form-control" placeholder="0.00" min="0" step="0.01" oninput="recalcBalance()">
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Balance</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" id="billing_balance" class="form-control" placeholder="0.00" readonly>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Payment Method</label>
                            <select id="billing_method" class="form-select">
                                <option value="">— Select —</option>
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="debit_card">Debit Card</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Payment Status <span class="text-danger">*</span></label>
                            <select id="billing_status" class="form-select">
                                <option value="unpaid">Unpaid</option>
                                <option value="partial">Partial</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold small">Notes</label>
                            <textarea id="billing_notes" class="form-control" rows="2" placeholder="Optional notes or remarks..."></textarea>
                        </div>

                    </div>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <span class="text-muted small"><span class="text-danger">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary px-4" id="billingSaveBtn" onclick="saveBilling()">
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
    <script src="assets/js/billing.js"></script>
    <script>
        window._isOwner = <?= isOwner()  ? 'true' : 'false' ?>;
        window._isAdmin = <?= isAdmin()  ? 'true' : 'false' ?>;
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
         VIEW DETAILS MODAL
    ============================================================ -->
    <div class="modal fade" id="billingViewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">
                        <i class="feather-file-text me-2 text-primary"></i>
                        Invoice Details — <span id="bv_code"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-0">
                    <!-- Invoice summary -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <table class="table table-borderless mb-0 small">
                                <tr>
                                    <th class="text-muted" style="width:110px;">Patient</th>
                                    <td id="bv_patient" class="fw-semibold"></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Branch</th>
                                    <td id="bv_branch"></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Treatment</th>
                                    <td id="bv_treatment"></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Date</th>
                                    <td id="bv_date"></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-borderless mb-0 small">
                                <tr>
                                    <th class="text-muted" style="width:110px;">Total</th>
                                    <td id="bv_total" class="fw-semibold"></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Amount Paid</th>
                                    <td id="bv_paid" class="text-success fw-semibold"></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Balance</th>
                                    <td id="bv_balance" class="text-danger fw-semibold"></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Status</th>
                                    <td id="bv_status"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    <!-- Payment history -->
                    <p class="fw-semibold small mb-2"><i class="feather-credit-card me-1 text-primary"></i>Payment History</p>
                    <div id="bv_payments_loading" class="text-center py-3 text-muted small">
                        <span class="spinner-border spinner-border-sm me-1"></span> Loading...
                    </div>
                    <div id="bv_payments_wrap" style="display:none;">
                        <div class="table-responsive border rounded">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
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
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <div id="bv_add_payment_btn_wrap">
                        <?php if (!isDentist()): ?>
                            <button type="button" class="btn btn-success btn-sm" id="bv_add_payment_btn" onclick="openAddPaymentFromView()">
                                <i class="feather-credit-card me-1"></i> Add Payment
                            </button>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         ADD PAYMENT MODAL
    ============================================================ -->
    <div class="modal fade" id="addPaymentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Add Payment</h5>
                        <p class="text-muted small mb-0">
                            Invoice: <strong id="pm_invoice_code"></strong> &nbsp;·&nbsp;
                            Balance: <strong id="pm_balance" class="text-danger"></strong>
                        </p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="pmModalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>
                    <input type="hidden" id="pm_invoice_id">
                    <input type="hidden" id="pm_max_balance">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" id="pm_amount" class="form-control" placeholder="0.00" min="0.01" step="0.01">
                            </div>
                            <div class="form-text text-muted" id="pm_amount_hint"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Payment Method</label>
                            <select id="pm_method" class="form-select">
                                <option value="">— Select —</option>
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="debit_card">Debit Card</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" id="pm_date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Recorded By</label>
                            <input type="text" id="pm_recorded_by" class="form-control bg-light" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Notes</label>
                            <input type="text" id="pm_notes" class="form-control" placeholder="Optional remarks">
                        </div>
                        <!-- Quick amount buttons -->
                        <div class="col-12">
                            <label class="form-label fw-semibold small text-muted">Quick fill</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setPaymentAmount('full')">Full Balance</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setPaymentAmount('half')">Half</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between">
                    <span class="text-muted small"><span class="text-danger">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-success px-4" id="pmSaveBtn" onclick="savePayment()">
                            <i class="feather-check me-1"></i> Record Payment
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>

</html>