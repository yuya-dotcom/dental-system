<?php
// =============================================================
//  controllers/receipt_helper.php
//  Shared by receipt.php (print view) and generate-receipt-pdf.php (PDF).
//
//    receipt_require_access()   → who is asking (staff or the patient)
//    receipt_load($invoiceId)   → invoice + patient + branch + service
//                                 + payments, with an ownership check
//    receipt_html($r, $forPdf)  → one receipt layout for both outputs
//
//  The layout uses tables only, so Dompdf and the browser render the same
//  design. Zero $_GET reading here — the pages pass the invoice ID in.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';

/** Stop with a plain message and the right HTTP status. */
function receipt_fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Receipt unavailable</title></head>'
        . '<body style="font-family:Arial,sans-serif;color:#1e293b;padding:40px;text-align:center;">'
        . '<h3 style="margin:0 0 8px;">Receipt unavailable</h3>'
        . '<p style="color:#64748b;margin:0;">' . htmlspecialchars($message) . '</p></body></html>';
    exit;
}

/**
 * Staff (owner/admin/dentist) can open any receipt.
 * A portal patient can open only their own — checked in receipt_load().
 * Returns ['staff' => bool, 'patient_id' => ?int].
 */
function receipt_require_access(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    $isStaff = isset($_SESSION['user_id'])
        && in_array($_SESSION['role'] ?? '', ['owner', 'admin', 'dentist'], true);
    if ($isStaff) {
        return ['staff' => true, 'patient_id' => null];
    }

    if (isset($_SESSION['portal_account_id'])) {
        $accRes = supabase_request(
            'patient_accounts',
            'GET',
            [],
            'account_id=eq.' . (int)$_SESSION['portal_account_id'] . '&select=patient_id&limit=1'
        );
        $pid = $accRes['data'][0]['patient_id'] ?? null;
        if ($pid) {
            return ['staff' => false, 'patient_id' => (int)$pid];
        }
    }

    header('Location: portal-login.php');
    exit;
}

/**
 * Read and validate ?invoice_id= from the page that calls this.
 * @param mixed $raw
 */
function receipt_invoice_id($raw): int
{
    $id = (int)($raw ?? 0);
    if ($id <= 0) receipt_fail(400, 'Missing or invalid invoice.');
    return $id;
}

/**
 * Everything the receipt shows, for one invoice.
 * Patients get 403 for an invoice that is not theirs.
 */
function receipt_load(int $invoiceId, array $access): array
{
    // ── Invoice + patient + branch ───────────────────────────
    $invRes = supabase_request('invoices', 'GET', [], implode('&', [
        'select=invoice_id,invoice_code,invoice_date,total_amount,amount_paid,'
            . 'balance,payment_status,patient_id,branch_id,treatment_id,appointment_id,'
            . 'patients(full_name,contact_number),'
            . 'branches(branch_name,address,contact_number)',
        'invoice_id=eq.' . $invoiceId,
        'limit=1',
    ]));
    $inv = (is_array($invRes['data'] ?? null) && !empty($invRes['data'])) ? $invRes['data'][0] : null;
    if (!$inv) receipt_fail(404, 'This invoice could not be found.');

    // SECURITY: a patient may only see their own receipts
    if (!$access['staff'] && (int)$inv['patient_id'] !== (int)$access['patient_id']) {
        receipt_fail(403, 'You do not have access to this receipt.');
    }

    // ── Treatment (service, tooth, dentist) ──────────────────
    $treatment = [];
    $trtId = (int)($inv['treatment_id'] ?? 0);
    if ($trtId > 0) {
        $trtRes = supabase_request('treatments', 'GET', [], implode('&', [
            'select=treatment_code,tooth_number,procedure_notes,treatment_date,'
                . 'dentists(full_name),services(service_name)',
            'treatment_id=eq.' . $trtId,
            'limit=1',
        ]));
        if (is_array($trtRes['data'] ?? null) && !empty($trtRes['data'])) {
            $treatment = $trtRes['data'][0];
        }
    }

    // Services: the treatment's service, or the booked services on the visit
    $services = [];
    if (!empty($treatment['services']['service_name'])) {
        $services[] = $treatment['services']['service_name'];
    } elseif (!empty($inv['appointment_id'])) {
        $svcRes = supabase_request('appointment_services', 'GET', [], implode('&', [
            'select=sort_order,services(service_name)',
            'appointment_id=eq.' . (int)$inv['appointment_id'],
            'order=sort_order.asc',
        ]));
        foreach (($svcRes['data'] ?? []) as $row) {
            if (!empty($row['services']['service_name'])) $services[] = $row['services']['service_name'];
        }
    }

    // ── Payments ─────────────────────────────────────────────
    $payRes = supabase_request('payments', 'GET', [], implode('&', [
        'select=payment_id,payment_date,amount,payment_method,recorded_by,notes',
        'invoice_id=eq.' . $invoiceId,
        'order=payment_date.asc,payment_id.asc',
    ]));
    $payments = is_array($payRes['data'] ?? null) ? $payRes['data'] : [];

    // ── Discount vouchers (empty if none, or before the discounts migration) ──
    $discRes   = supabase_request('invoice_discounts', 'GET', [], implode('&', [
        'select=voucher_code,voucher_name,discount_amount',
        'invoice_id=eq.' . $invoiceId,
        'order=applied_at.asc',
    ]));
    $discounts = ($discRes['status'] ?? 0) >= 200 && ($discRes['status'] ?? 0) < 300 && is_array($discRes['data'] ?? null)
        ? $discRes['data'] : [];

    $total   = (float)($inv['total_amount'] ?? 0);
    $paid    = (float)($inv['amount_paid'] ?? 0);
    $balance = isset($inv['balance']) ? (float)$inv['balance'] : $total - $paid;
    // Charge before discounts = amount owed + every discount applied
    $subtotal = round($total + array_sum(array_map(fn($d) => (float)$d['discount_amount'], $discounts)), 2);

    // Portal accounts without a phone get a placeholder number — never print it
    $patientContact = (string)($inv['patients']['contact_number'] ?? '');
    if (str_starts_with($patientContact, '__')) $patientContact = '';

    return [
        'invoice_id'      => (int)$inv['invoice_id'],
        'invoice_code'    => (string)($inv['invoice_code'] ?? ''),
        'invoice_date'    => $inv['invoice_date'] ?? null,
        'status'          => strtolower((string)($inv['payment_status'] ?? 'unpaid')),
        'patient_name'    => (string)($inv['patients']['full_name'] ?? ''),
        'patient_contact' => $patientContact,
        'branch_name'     => (string)($inv['branches']['branch_name'] ?? ''),
        'branch_address'  => (string)($inv['branches']['address'] ?? ''),
        'branch_contact'  => (string)($inv['branches']['contact_number'] ?? ''),
        'services'        => $services,
        'treatment_code'  => (string)($treatment['treatment_code'] ?? ''),
        'tooth_number'    => (string)($treatment['tooth_number'] ?? ''),
        'dentist_name'    => (string)($treatment['dentists']['full_name'] ?? ''),
        'procedure_notes' => (string)($treatment['procedure_notes'] ?? ''),
        'payments'        => $payments,
        'discounts'       => $discounts,
        'subtotal'        => $subtotal,
        'total'           => $total,
        'paid'            => $paid,
        'balance'         => max(0, $balance),
    ];
}

// ── Formatting ──────────────────────────────────────────────
function rcpt_e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rcpt_money(float $n): string
{
    return '&#8369;' . number_format($n, 2);
}

function rcpt_date(?string $d): string
{
    if (empty($d)) return '&mdash;';
    $ts = strtotime($d);
    return $ts ? date('F j, Y', $ts) : '&mdash;';
}

function rcpt_method(?string $m): string
{
    return match (strtolower(trim((string)$m))) {
        'gcash'         => 'GCash',
        'maya', 'paymaya' => 'Maya',
        'credit_card'   => 'Credit Card',
        'debit_card'    => 'Debit Card',
        'bank_transfer' => 'Bank Transfer',
        'cash'          => 'Cash',
        ''              => '—',
        default         => ucwords(str_replace('_', ' ', (string)$m)),
    };
}

function rcpt_status(string $s): array
{
    return match ($s) {
        'paid'    => ['Paid', '#15803d', '#dcfce7'],
        'partial' => ['Partially Paid', '#b45309', '#fef3c7'],
        default   => ['Unpaid', '#475569', '#e2e8f0'],
    };
}

/**
 * The logo as a file path (browser) or a data URI (Dompdf).
 *
 * Dompdf needs PHP's GD extension to draw PNG images. XAMPP ships with GD
 * switched off, and without it Dompdf stops with a fatal error — so the PDF
 * only gets the logo when GD is available (otherwise the name is shown as
 * text). With GD, the logo is resized and placed on white first, which keeps
 * the PDF small and fast to build.
 */
function rcpt_logo(bool $forPdf, bool $allowPdfLogo = true): string
{
    $rel  = 'assets/images/essencia-full@3x.png';
    $file = dirname(__DIR__) . '/' . $rel;
    if (!is_file($file)) return '';
    if (!$forPdf) return $rel;

    if (!$allowPdfLogo || !extension_loaded('gd') || !function_exists('imagecreatefrompng')) return '';

    $src = @imagecreatefrompng($file);
    if (!$src) return '';
    $w = imagesx($src);
    $h = imagesy($src);
    $newH = min(132, $h);                         // 3 × the 44px it is shown at
    $newW = max(1, (int)round($w * $newH / max(1, $h)));

    $dst = imagecreatetruecolor($newW, $newH);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

    ob_start();
    imagepng($dst, null, 9);
    $png = ob_get_clean();
    unset($src, $dst);   // GD images are freed automatically (imagedestroy is deprecated)

    return $png ? 'data:image/png;base64,' . base64_encode($png) : '';
}

/**
 * The receipt. $forPdf = true for Dompdf (no screen extras),
 * false for the browser / print view.
 */
function receipt_html(array $r, bool $forPdf, bool $autoPrint = false, bool $withLogo = true): string
{
    $this_code     = rcpt_e($r['invoice_code'] !== '' ? $r['invoice_code'] : '—');
    $this_date     = rcpt_date($r['invoice_date']);
    $this_patient  = rcpt_e($r['patient_name'] !== '' ? $r['patient_name'] : '—');
    $this_services = rcpt_e($r['services'] ? implode(', ', $r['services']) : 'Dental services');
    $this_total    = rcpt_money($r['total']);
    $this_subtotal = rcpt_money($r['subtotal'] ?? $r['total']);
    // Subtotal + one line per voucher, only when the invoice has discounts
    $discountRows = '';
    if (!empty($r['discounts'])) {
        $discountRows = '<tr><td>Subtotal</td><td class="num">' . $this_subtotal . '</td></tr>';
        foreach ($r['discounts'] as $d) {
            $discountRows .= '<tr><td>Discount · ' . rcpt_e($d['voucher_code'] ?? '')
                . ($d['voucher_name'] ?? '' ? ' (' . rcpt_e($d['voucher_name']) . ')' : '')
                . '</td><td class="num paid">−' . rcpt_money((float)$d['discount_amount']) . '</td></tr>';
        }
    }
    $this_paid     = rcpt_money($r['paid']);
    $this_balance  = rcpt_money($r['balance']);

    [$statusLabel, $statusFg, $statusBg] = rcpt_status($r['status']);
    $logo = rcpt_logo($forPdf, $withLogo);

    $brand = $logo
        ? '<img src="' . rcpt_e($logo) . '" alt="EssenciaSmile" class="logo">'
        : '<div class="brand-text">EssenciaSmile</div>';

    $branchLines = [];
    if ($r['branch_name'] !== '')    $branchLines[] = '<strong>' . rcpt_e($r['branch_name']) . '</strong>';
    if ($r['branch_address'] !== '') $branchLines[] = rcpt_e($r['branch_address']);
    if ($r['branch_contact'] !== '') $branchLines[] = 'Tel. ' . rcpt_e($r['branch_contact']);
    $branchBlock = implode('<br>', $branchLines);

    // Service details under the description
    $svcMeta = [];
    if ($r['tooth_number'] !== '') $svcMeta[] = 'Tooth ' . rcpt_e($r['tooth_number']);
    if ($r['dentist_name'] !== '') $svcMeta[] = rcpt_e($r['dentist_name']);
    if ($r['treatment_code'] !== '') $svcMeta[] = 'Ref. ' . rcpt_e($r['treatment_code']);
    $svcMetaHtml = $svcMeta ? '<div class="muted small">' . implode(' &nbsp;&middot;&nbsp; ', $svcMeta) . '</div>' : '';
    $notesHtml = $r['procedure_notes'] !== ''
        ? '<div class="muted small notes">' . nl2br(rcpt_e($r['procedure_notes'])) . '</div>' : '';

    // Payment rows
    $payRows = '';
    foreach ($r['payments'] as $p) {
        $note = trim((string)($p['notes'] ?? ''));
        $payRows .= '<tr>'
            . '<td>' . rcpt_date($p['payment_date'] ?? null) . '</td>'
            . '<td>' . rcpt_e(rcpt_method($p['payment_method'] ?? null))
            . ($note !== '' ? '<div class="muted small">' . rcpt_e($note) . '</div>' : '') . '</td>'
            . '<td>' . rcpt_e(($p['recorded_by'] ?? '') !== '' ? $p['recorded_by'] : '—') . '</td>'
            . '<td class="num paid">' . rcpt_money((float)($p['amount'] ?? 0)) . '</td>'
            . '</tr>';
    }
    if ($payRows === '') {
        $payRows = '<tr><td colspan="4" class="muted center">No payments recorded yet.</td></tr>';
    }

    $balanceClass = $r['balance'] > 0 ? 'due' : 'clear';
    $patientContact = $r['patient_contact'] !== ''
        ? '<div class="muted">' . rcpt_e($r['patient_contact']) . '</div>' : '';
    $generated = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('F j, Y \a\t g:i A');

    $fontFamily = $forPdf
        ? "'DejaVu Sans', Arial, sans-serif"
        : "'DM Sans', 'Segoe UI', Arial, sans-serif";

    // Screen-only rules (Dompdf would not use these)
    $screenCss = $forPdf ? '' : '
  body { background: #f1f5f9; padding: 24px 16px 40px; }
  .sheet { max-width: 760px; margin: 0 auto; border-radius: 14px; box-shadow: 0 4px 24px rgba(15,23,42,.08); }
  .toolbar { max-width: 760px; margin: 0 auto 14px; text-align: right; }
  .toolbar a, .toolbar button { display: inline-block; margin-left: 8px; padding: 9px 16px; border-radius: 8px;
    font: 600 13px/1 ' . $fontFamily . '; cursor: pointer; text-decoration: none; border: 1px solid #e2e8f0;
    background: #fff; color: #334155; }
  .toolbar .primary { background: #2563eb; border-color: #2563eb; color: #fff; }
  .toolbar .primary:hover { background: #1d4ed8; }
  @media (max-width: 600px) {
    body { padding: 12px 8px 24px; }
    .sheet-inner { padding: 22px 18px; }
    .head td { display: block; width: 100% !important; text-align: left !important; }
    .head .doc { padding-top: 14px; }
    .parties td { display: block; width: 100% !important; padding: 0 0 14px !important; }
    .logo { height: 36px; }
    .toolbar { text-align: center; }
    .toolbar a, .toolbar button { margin: 0 4px 6px; }
  }
  @media print {
    @page { size: A4; margin: 14mm; }
    body { background: #fff; padding: 0; }
    .toolbar { display: none !important; }
    .sheet { box-shadow: none; border-radius: 0; max-width: none; }
    .sheet-inner { padding: 0; }
  }';

    $pdfCss = $forPdf ? '@page { margin: 34px 38px; }' : '';

    $toolbar = $forPdf ? '' : '
<div class="toolbar">
  <button type="button" onclick="window.close()">Close</button>
  <a href="generate-receipt-pdf.php?invoice_id=' . (int)$r['invoice_id'] . '">Download PDF</a>
  <button type="button" class="primary" onclick="window.print()">Print Receipt</button>
</div>';

    $fontLink = $forPdf ? '' :
        '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">';

    $title = 'Receipt ' . $this_code;

    // Opened from the portal's "Print Receipt": show the print dialog once
    // the logo and fonts have loaded.
    $autoPrint = (!$forPdf && $autoPrint) ? '<script>
window.addEventListener("load", function () {
  var go = function () { setTimeout(function () { window.print(); }, 250); };
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(go, go); else go();
});
</script>' : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} — EssenciaSmile</title>
<link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
{$fontLink}
<style>
  {$pdfCss}
  * { box-sizing: border-box; }
  body { margin: 0; font-family: {$fontFamily}; font-size: 12px; color: #1e293b; line-height: 1.45;
         -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  table { border-collapse: collapse; width: 100%; }
  .sheet { background: #fff; overflow: hidden; }
  .accent { height: 6px; background: #2563eb; }
  .sheet-inner { padding: 30px 36px 26px; }
  .logo { height: 44px; }
  .brand-text { font-size: 22px; font-weight: 700; color: #2563eb; }
  .head td { vertical-align: top; }
  .doc { text-align: right; }
  .doc-title { font-size: 18px; font-weight: 700; letter-spacing: 1.5px; color: #0f172a; }
  .doc-meta { margin-top: 6px; color: #475569; font-size: 11.5px; }
  .doc-meta strong { color: #0f172a; }
  .pill { display: inline-block; margin-top: 8px; padding: 3px 12px; border-radius: 999px;
          font-size: 10.5px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase;
          color: {$statusFg}; background: {$statusBg}; }
  .rule { border-top: 1px solid #e2e8f0; margin: 22px 0; height: 0; }
  .label { font-size: 9.5px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
           color: #64748b; margin-bottom: 5px; }
  .parties td { vertical-align: top; width: 50%; }
  .party-name { font-size: 13.5px; font-weight: 700; color: #0f172a; }
  .muted { color: #64748b; }
  .small { font-size: 10.5px; margin-top: 2px; }
  .notes { margin-top: 6px; }
  .center { text-align: center; }
  .section { margin-top: 24px; }
  .lines th { text-align: left; font-size: 9.5px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase;
              color: #64748b; background: #f8fafc; padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
  .lines td { padding: 10px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  .lines .num { text-align: right; white-space: nowrap; }
  .lines .paid { color: #15803d; font-weight: 700; }
  .svc { font-weight: 600; color: #0f172a; }
  .totals { width: 280px; margin-left: auto; margin-top: 18px; }
  .totals td { padding: 6px 0; }
  .totals .num { text-align: right; white-space: nowrap; }
  .totals .paid { color: #15803d; font-weight: 600; }
  .totals .grand td { border-top: 1.5px solid #0f172a; padding-top: 10px; font-size: 14px; font-weight: 700; }
  .totals .due { color: #dc2626; }
  .totals .clear { color: #15803d; }
  .foot { margin-top: 30px; padding-top: 16px; border-top: 1px dashed #cbd5e1; text-align: center;
          color: #64748b; font-size: 10.5px; }
  .foot .thanks { color: #2563eb; font-weight: 700; font-size: 12px; margin-bottom: 4px; }
  {$screenCss}
</style>
</head>
<body>
{$toolbar}
<div class="sheet">
  <div class="accent"></div>
  <div class="sheet-inner">

    <table class="head"><tr>
      <td style="width:55%;">{$brand}</td>
      <td class="doc" style="width:45%;">
        <div class="doc-title">OFFICIAL RECEIPT</div>
        <div class="doc-meta">Receipt No. <strong>{$this_code}</strong></div>
        <div class="doc-meta">Invoice Date: <strong>{$this_date}</strong></div>
        <div class="pill">{$statusLabel}</div>
      </td>
    </tr></table>

    <div class="rule"></div>

    <table class="parties"><tr>
      <td style="padding-right:16px;">
        <div class="label">Billed To</div>
        <div class="party-name">{$this_patient}</div>
        {$patientContact}
      </td>
      <td>
        <div class="label">Clinic</div>
        <div class="party-name">EssenciaSmile Dental</div>
        <div class="muted">{$branchBlock}</div>
      </td>
    </tr></table>

    <div class="section">
      <div class="label">Services</div>
      <table class="lines">
        <thead><tr><th>Description</th><th class="num" style="text-align:right;">Amount</th></tr></thead>
        <tbody><tr>
          <td><div class="svc">{$this_services}</div>{$svcMetaHtml}{$notesHtml}</td>
          <td class="num">{$this_subtotal}</td>
        </tr></tbody>
      </table>
    </div>

    <div class="section">
      <div class="label">Payment History</div>
      <table class="lines">
        <thead><tr><th>Date</th><th>Method</th><th>Received By</th><th class="num" style="text-align:right;">Amount</th></tr></thead>
        <tbody>{$payRows}</tbody>
      </table>
    </div>

    <table class="totals">
      {$discountRows}
      <tr><td>Total Amount</td><td class="num">{$this_total}</td></tr>
      <tr><td>Amount Paid</td><td class="num paid">{$this_paid}</td></tr>
      <tr class="grand"><td>Balance Due</td><td class="num {$balanceClass}">{$this_balance}</td></tr>
    </table>

    <div class="foot">
      <div class="thanks">Thank you for trusting us with your smile!</div>
      This is an official receipt from EssenciaSmile Dental. Generated on {$generated}.
    </div>

  </div>
</div>
{$autoPrint}
</body>
</html>
HTML;
}