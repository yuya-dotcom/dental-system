<?php
// =============================================================
//  receipt.php
//  Print-friendly receipt for one invoice.
//    receipt.php?invoice_id=12           → view, with Print / Download PDF
//    receipt.php?invoice_id=12&print=1   → also opens the print dialog
//    receipt.php?invoice_id=12&embed=1   → receipt only (no toolbar), shown
//                                           inside the portal's View Receipt
//                                           modal (an iframe on the same site)
//  Staff can open any receipt; a patient only their own
//  (see controllers/receipt_helper.php) — the same checks in every mode.
// =============================================================

require_once __DIR__ . '/controllers/receipt_helper.php';

$embed     = !empty($_GET['embed']);
$access    = receipt_require_access(!$embed);
$invoiceId = receipt_invoice_id($_GET['invoice_id'] ?? null);
$receipt   = receipt_load($invoiceId, $access);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
// Only this site may frame the receipt (the portal modal); no other site can
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
echo receipt_html($receipt, false, !empty($_GET['print']), true, $embed);