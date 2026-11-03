<?php
// =============================================================
//  receipt.php
//  Print-friendly receipt for one invoice.
//    receipt.php?invoice_id=12           → view, with Print / Download PDF
//    receipt.php?invoice_id=12&print=1   → also opens the print dialog
//                                           (used by the portal's Print Receipt)
//  Staff can open any receipt; a patient only their own
//  (see controllers/receipt_helper.php).
// =============================================================

require_once __DIR__ . '/controllers/receipt_helper.php';

$access    = receipt_require_access();
$invoiceId = receipt_invoice_id($_GET['invoice_id'] ?? null);
$receipt   = receipt_load($invoiceId, $access);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo receipt_html($receipt, false, !empty($_GET['print']));