<?php
// =============================================================
//  receipt.php
//  Standalone print-friendly receipt page.
//  Usage: receipt.php?invoice_id=123
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/treatment_controller.php';

$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
if (!$invoiceId) { http_response_code(400); die('Missing invoice_id.'); }

// ── Step 1: Fetch invoice with patient + branch joins ────────
$invRes = supabase_request('invoices', 'GET', [], implode('&', [
    'select=invoice_id,invoice_code,invoice_date,total_amount,amount_paid,'
        . 'balance,payment_status,patient_id,branch_id,treatment_id,'
        . 'patients(full_name,contact_number),'
        . 'branches(branch_name,address,contact_number)',
    'invoice_id=eq.' . $invoiceId,
    'limit=1',
]));

$inv = null;
if (is_array($invRes['data'])) {
    $inv = $invRes['data'][0] ?? null;
}
if (!$inv) { http_response_code(404); die('Invoice not found.'); }

// ── Step 2: Fetch treatment separately using treatment_id ────
$treatment = [];
$dentist   = [];
$service   = [];
$treatmentId = (int)($inv['treatment_id'] ?? 0);
if ($treatmentId > 0) {
    $trtRes = supabase_request('treatments', 'GET', [], implode('&', [
        'select=treatment_code,tooth_number,procedure_notes,'
            . 'dentists(full_name),services(service_name)',
        'treatment_id=eq.' . $treatmentId,
        'limit=1',
    ]));
    if (is_array($trtRes['data']) && !empty($trtRes['data'])) {
        $treatment = $trtRes['data'][0];
        $dentist   = $treatment['dentists']  ?? [];
        $service   = $treatment['services']  ?? [];
    }
}

// ── Step 3: Fetch payment history ────────────────────────────
$payRes = supabase_request('payments', 'GET', [], implode('&', [
    'select=payment_date,amount,payment_method,recorded_by,notes',
    'invoice_id=eq.' . $invoiceId,
    'order=payment_date.asc',
]));
$payments = is_array($payRes['data']) ? $payRes['data'] : [];

// ── Helpers ──────────────────────────────────────────────────
$patient = $inv['patients'] ?? [];
$branch  = $inv['branches'] ?? [];

function fmt(?float $n): string {
    return $n !== null ? '₱' . number_format($n, 2) : '—';
}
function fmtDate(?string $d): string {
    return empty($d) ? '—' : date('F j, Y', strtotime($d));
}
function fmtMethod(?string $m): string {
    return match (strtolower($m ?? '')) {
        'gcash'         => 'GCash',
        'credit_card'   => 'Credit Card',
        'debit_card'    => 'Debit Card',
        'bank_transfer' => 'Bank Transfer',
        'cash'          => 'Cash',
        default         => ucfirst($m ?? '—'),
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt — <?= htmlspecialchars($inv['invoice_code'] ?? '') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 13px;
            color: #1e293b;
            background: #f1f5f9;
            padding: 32px 16px;
        }

        .receipt {
            background: #ffffff;
            max-width: 680px;
            margin: 0 auto;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,.10);
            overflow: hidden;
        }

        /* Header */
        .receipt-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
            color: #fff;
            padding: 28px 32px 20px;
        }
        .clinic-name { font-size: 22px; font-weight: 700; letter-spacing: .3px; margin-bottom: 2px; }
        .clinic-sub  { font-size: 11px; opacity: .8; margin-bottom: 12px; }
        .receipt-meta {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 8px;
            border-top: 1px solid rgba(255,255,255,.25);
            padding-top: 14px;
        }
        .receipt-meta .label { font-size: 10px; opacity: .7; text-transform: uppercase; letter-spacing: .5px; }
        .receipt-meta .value { font-size: 15px; font-weight: 700; }
        .status-pill {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            background: rgba(255,255,255,.18);
            color: #fff;
            border: 1px solid rgba(255,255,255,.35);
        }

        /* Body */
        .receipt-body { padding: 24px 32px; }

        .section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
            color: #64748b;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 24px;
            margin-bottom: 22px;
        }
        .info-row    { display: flex; flex-direction: column; gap: 2px; }
        .info-label  { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: .4px; }
        .info-value  { font-size: 13px; font-weight: 600; color: #1e293b; }

        /* Payment table */
        .pay-table { width: 100%; border-collapse: collapse; margin-bottom: 22px; font-size: 12px; }
        .pay-table thead tr { background: #f8fafc; }
        .pay-table th {
            text-align: left; padding: 8px 10px;
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .5px; color: #64748b; border-bottom: 1px solid #e2e8f0;
        }
        .pay-table td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; }
        .pay-table tbody tr:last-child td { border-bottom: none; }
        .pay-amount { color: #16a34a; font-weight: 700; }

        /* Totals */
        .totals-box { background: #f8fafc; border-radius: 8px; padding: 16px 20px; margin-bottom: 22px; }
        .totals-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 13px; color: #475569; }
        .totals-row.divider { border-top: 1px dashed #cbd5e1; margin-top: 6px; padding-top: 10px; }
        .totals-row.grand   { font-size: 15px; font-weight: 700; color: #1e293b; }
        .balance-val { color: #dc2626; font-weight: 700; }
        .paid-val    { color: #16a34a; font-weight: 700; }

        /* Footer */
        .receipt-footer {
            text-align: center; padding: 16px 32px 24px;
            font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9;
        }
        .receipt-footer strong { color: #64748b; }

        /* Controls */
        .print-controls {
            max-width: 680px; margin: 0 auto 20px;
            display: flex; gap: 10px; justify-content: flex-end;
        }
        .btn-ctrl {
            padding: 8px 18px; border-radius: 7px; border: none;
            font-size: 13px; font-weight: 600; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
        }
        .btn-print     { background: #1e3a5f; color: #fff; }
        .btn-pdf       { background: #2563eb; color: #fff; }
        .btn-close-tab { background: #f1f5f9; color: #475569; }

        @media print {
            @page { margin: 0; }
            body { background: #fff; padding: 16px; margin: 0; }
            .print-controls { display: none; }
            .receipt { box-shadow: none; border-radius: 0; max-width: 100%; }
        }
    </style>
</head>
<body>

<div class="print-controls">
    <button class="btn-ctrl btn-close-tab" onclick="window.close()">✕ Close</button>
    <a class="btn-ctrl btn-pdf" href="generate-receipt-pdf.php?invoice_id=<?= $invoiceId ?>">
        ⬇ Download PDF
    </a>
    <button class="btn-ctrl btn-print" onclick="window.print()">🖨 Print</button>
</div>

<div class="receipt">

    <div class="receipt-header">
        <div class="clinic-name">EssenciaSmile Dental Center</div>
        <div class="clinic-sub">
            <?= htmlspecialchars($branch['branch_name'] ?? '') ?>
            <?php if (!empty($branch['address'])): ?>&nbsp;·&nbsp;<?= htmlspecialchars($branch['address']) ?><?php endif; ?>
            <?php if (!empty($branch['contact_number'])): ?>&nbsp;·&nbsp;<?= htmlspecialchars($branch['contact_number']) ?><?php endif; ?>
        </div>
        <div class="receipt-meta">
            <div>
                <div class="label">Invoice No.</div>
                <div class="value"><?= htmlspecialchars($inv['invoice_code'] ?? '—') ?></div>
            </div>
            <div>
                <div class="label">Date Issued</div>
                <div class="value"><?= fmtDate($inv['invoice_date'] ?? null) ?></div>
            </div>
            <div>
                <div class="label">Payment Status</div>
                <div class="value"><span class="status-pill"><?= ucfirst($inv['payment_status'] ?? 'unpaid') ?></span></div>
            </div>
        </div>
    </div>

    <div class="receipt-body">

        <div class="section-title">Patient &amp; Treatment Information</div>
        <div class="info-grid">
            <div class="info-row">
                <span class="info-label">Patient Name</span>
                <span class="info-value"><?= htmlspecialchars($patient['full_name'] ?? '—') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Branch</span>
                <span class="info-value"><?= htmlspecialchars($branch['branch_name'] ?? '—') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Treatment Code</span>
                <span class="info-value"><?= htmlspecialchars($treatment['treatment_code'] ?? '—') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Service</span>
                <span class="info-value"><?= htmlspecialchars($service['service_name'] ?? '—') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Tooth Number</span>
                <span class="info-value"><?= htmlspecialchars((string)($treatment['tooth_number'] ?? '—')) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Attending Dentist</span>
                <span class="info-value"><?= htmlspecialchars($dentist['full_name'] ?? '—') ?></span>
            </div>
            <?php if (!empty($patient['contact_number'])): ?>
            <div class="info-row">
                <span class="info-label">Patient Contact</span>
                <span class="info-value"><?= htmlspecialchars($patient['contact_number']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($treatment['procedure_notes'])): ?>
            <div class="info-row" style="grid-column: span 2;">
                <span class="info-label">Procedure Notes</span>
                <span class="info-value" style="font-weight:400;"><?= htmlspecialchars($treatment['procedure_notes']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($payments)): ?>
        <div class="section-title">Payment History</div>
        <table class="pay-table">
            <thead>
                <tr>
                    <th>Date</th><th>Amount</th><th>Method</th><th>Recorded By</th><th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $pay): ?>
                <tr>
                    <td><?= fmtDate($pay['payment_date'] ?? null) ?></td>
                    <td class="pay-amount"><?= fmt((float)($pay['amount'] ?? 0)) ?></td>
                    <td><?= htmlspecialchars(fmtMethod($pay['payment_method'] ?? null)) ?></td>
                    <td><?= htmlspecialchars($pay['recorded_by'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($pay['notes'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="section-title">Summary</div>
        <div class="totals-box">
            <div class="totals-row">
                <span>Total Amount</span>
                <span><?= fmt((float)($inv['total_amount'] ?? 0)) ?></span>
            </div>
            <div class="totals-row">
                <span>Total Paid</span>
                <span class="paid-val"><?= fmt((float)($inv['amount_paid'] ?? 0)) ?></span>
            </div>
            <div class="totals-row divider grand">
                <span>Balance Remaining</span>
                <span class="balance-val"><?= fmt((float)($inv['balance'] ?? 0)) ?></span>
            </div>
        </div>

    </div>

    <div class="receipt-footer">
        <strong>EssenciaSmile Dental Center</strong> &nbsp;·&nbsp;
        Thank you for trusting us with your smile!<br>
        This is an official receipt. Please keep this for your records.
    </div>

</div>

<script>
    window.addEventListener('load', () => {
        setTimeout(() => window.print(), 600);
    });
</script>

</body>
</html>