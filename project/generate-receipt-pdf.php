<?php
// =============================================================
//  generate-receipt-pdf.php
//  Streams a PDF receipt using Dompdf.
//  Usage: generate-receipt-pdf.php?invoice_id=123
//
//  Install Dompdf via Composer:
//    composer require dompdf/dompdf
// =============================================================

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/treatment_controller.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

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

// ── Step 2: Fetch treatment separately ──────────────────────
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
        $dentist   = $treatment['dentists'] ?? [];
        $service   = $treatment['services'] ?? [];
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
    return $n !== null ? '&#8369;' . number_format($n, 2) : '&mdash;';
}
function fmtDate(?string $d): string {
    return empty($d) ? '&mdash;' : date('F j, Y', strtotime($d));
}
function fmtMethod(?string $m): string {
    return match (strtolower($m ?? '')) {
        'gcash'         => 'GCash',
        'credit_card'   => 'Credit Card',
        'debit_card'    => 'Debit Card',
        'bank_transfer' => 'Bank Transfer',
        'cash'          => 'Cash',
        default         => ucfirst($m ?? '&mdash;'),
    };
}

// ── Build payment rows ────────────────────────────────────────
$payRows = '';
foreach ($payments as $pay) {
    $payRows .= '<tr>
        <td>' . fmtDate($pay['payment_date'] ?? null) . '</td>
        <td style="color:#16a34a;font-weight:bold;">' . fmt((float)($pay['amount'] ?? 0)) . '</td>
        <td>' . htmlspecialchars(fmtMethod($pay['payment_method'] ?? null)) . '</td>
        <td>' . htmlspecialchars($pay['recorded_by'] ?? '—') . '</td>
        <td>' . htmlspecialchars($pay['notes'] ?? '—') . '</td>
    </tr>';
}

$paySection = '';
if ($payRows) {
    $paySection = '
    <p class="section-title">Payment History</p>
    <table class="pay-table">
        <thead><tr>
            <th>Date</th><th>Amount</th><th>Method</th><th>Recorded By</th><th>Notes</th>
        </tr></thead>
        <tbody>' . $payRows . '</tbody>
    </table>';
}

// ── Procedure notes ──────────────────────────────────────────
$procNotes = '';
if (!empty($treatment['procedure_notes'])) {
    $procNotes = '<tr><td colspan="4" style="color:#94a3b8;font-size:10px;text-transform:uppercase;padding-top:8px;">Procedure Notes</td></tr>
    <tr><td colspan="4">' . htmlspecialchars($treatment['procedure_notes']) . '</td></tr>';
}

// ── Assemble variables ────────────────────────────────────────
$invoiceCode  = htmlspecialchars($inv['invoice_code']           ?? '—');
$invoiceDate  = fmtDate($inv['invoice_date']                    ?? null);
$payStatus    = ucfirst($inv['payment_status']                  ?? 'unpaid');
$patientName  = htmlspecialchars($patient['full_name']          ?? '—');
$patientPhone = htmlspecialchars($patient['contact_number']     ?? '');
$branchName   = htmlspecialchars($branch['branch_name']         ?? '—');
$branchAddr   = htmlspecialchars($branch['address']             ?? '');
$branchPhone  = htmlspecialchars($branch['contact_number']      ?? '');
$trtCode      = htmlspecialchars($treatment['treatment_code']   ?? '—');
$toothNo      = htmlspecialchars((string)($treatment['tooth_number'] ?? '—'));
$serviceName  = htmlspecialchars($service['service_name']       ?? '—');
$dentistName  = htmlspecialchars($dentist['full_name']          ?? '—');
$total        = fmt((float)($inv['total_amount'] ?? 0));
$paid         = fmt((float)($inv['amount_paid']  ?? 0));
$balance      = fmt((float)($inv['balance']      ?? 0));
$branchSub    = trim($branchName . ($branchAddr ? ' · ' . $branchAddr : '') . ($branchPhone ? ' · ' . $branchPhone : ''));

$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #1e293b; margin: 0; padding: 0; }

  .header { background: #1e3a5f; color: #fff; padding: 22px 28px 16px; }
  .clinic-name { font-size: 20px; font-weight: bold; margin-bottom: 3px; }
  .clinic-sub  { font-size: 10px; margin-bottom: 14px; opacity: .85; }

  .meta-table { width: 100%; border-top: 1px solid rgba(255,255,255,.3); padding-top: 12px; }
  .meta-label { font-size: 9px; text-transform: uppercase; letter-spacing: .5px; opacity: .7; color:#fff; }
  .meta-value { font-size: 13px; font-weight: bold; color: #fff; }
  .status-pill {
      display: inline; padding: 2px 10px; border-radius: 12px;
      border: 1px solid rgba(255,255,255,.5); font-size: 10px;
      font-weight: bold; text-transform: uppercase; color:#fff;
  }

  .body { padding: 20px 28px; }

  .section-title {
      font-size: 9px; font-weight: bold; text-transform: uppercase;
      letter-spacing: .8px; color: #64748b;
      border-bottom: 1px solid #e2e8f0; padding-bottom: 5px;
      margin-bottom: 10px; margin-top: 16px;
  }

  .info-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
  .info-table td { padding: 5px 6px; font-size: 12px; }
  .info-lbl { color: #94a3b8; font-size: 10px; text-transform: uppercase; letter-spacing: .3px; width: 130px; }

  .pay-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 11px; }
  .pay-table th {
      text-align: left; padding: 6px 8px;
      font-size: 9px; font-weight: bold; text-transform: uppercase;
      letter-spacing: .5px; color: #64748b; background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
  }
  .pay-table td { padding: 7px 8px; border-bottom: 1px solid #f1f5f9; }

  .totals-box { background: #f8fafc; border-radius: 6px; padding: 14px 18px; margin-bottom: 20px; }
  .t-row { width: 100%; margin-bottom: 5px; font-size: 12px; color: #475569; }
  .t-row-grand { width: 100%; font-size: 14px; font-weight: bold; color: #1e293b;
      border-top: 1px dashed #cbd5e1; padding-top: 8px; margin-top: 6px; }
  .t-right { float: right; }
  .paid-val    { color: #16a34a; font-weight: bold; }
  .balance-val { color: #dc2626; font-weight: bold; }

  .footer { text-align: center; padding: 14px 28px; font-size: 10px; color: #94a3b8; border-top: 1px solid #f1f5f9; }
</style>
</head>
<body>

<div class="header">
  <div class="clinic-name">EssenciaSmile Dental Center</div>
  <div class="clinic-sub">{$branchSub}</div>
  <table class="meta-table" cellpadding="0" cellspacing="0">
    <tr>
      <td style="width:33%;">
        <div class="meta-label">Invoice No.</div>
        <div class="meta-value">{$invoiceCode}</div>
      </td>
      <td style="width:33%;">
        <div class="meta-label">Date Issued</div>
        <div class="meta-value">{$invoiceDate}</div>
      </td>
      <td style="width:33%;">
        <div class="meta-label">Status</div>
        <div class="meta-value"><span class="status-pill">{$payStatus}</span></div>
      </td>
    </tr>
  </table>
</div>

<div class="body">
  <p class="section-title">Patient &amp; Treatment Information</p>
  <table class="info-table">
    <tr>
      <td class="info-lbl">Patient Name</td><td><strong>{$patientName}</strong></td>
      <td class="info-lbl">Branch</td><td>{$branchName}</td>
    </tr>
    <tr>
      <td class="info-lbl">Treatment Code</td><td>{$trtCode}</td>
      <td class="info-lbl">Service</td><td>{$serviceName}</td>
    </tr>
    <tr>
      <td class="info-lbl">Tooth Number</td><td>{$toothNo}</td>
      <td class="info-lbl">Attending Dentist</td><td>{$dentistName}</td>
    </tr>
    {$procNotes}
  </table>

  {$paySection}

  <p class="section-title">Summary</p>
  <div class="totals-box">
    <table width="100%" cellpadding="3" cellspacing="0">
      <tr><td style="color:#475569;">Total Amount</td><td align="right">{$total}</td></tr>
      <tr><td style="color:#475569;">Total Paid</td><td align="right" class="paid-val">{$paid}</td></tr>
      <tr>
        <td colspan="2"><hr style="border:none;border-top:1px dashed #cbd5e1;margin:6px 0;"></td>
      </tr>
      <tr>
        <td style="font-size:14px;font-weight:bold;color:#1e293b;">Balance Remaining</td>
        <td align="right" class="balance-val" style="font-size:14px;">{$balance}</td>
      </tr>
    </table>
  </div>
</div>

<div class="footer">
  <strong>EssenciaSmile Dental Center</strong> &nbsp;&middot;&nbsp;
  Thank you for trusting us with your smile!<br>
  This is an official receipt. Please keep this for your records.
</div>

</body>
</html>
HTML;

// ── Render with Dompdf ───────────────────────────────────────
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'Receipt-' . ($inv['invoice_code'] ?? $invoiceId) . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;