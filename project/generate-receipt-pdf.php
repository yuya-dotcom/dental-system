<?php
// =============================================================
//  generate-receipt-pdf.php
//  Downloads the receipt for one invoice as a PDF (Dompdf).
//  Same layout and data as receipt.php — see controllers/receipt_helper.php.
//  Staff can download any receipt; a patient only their own.
// =============================================================

// Any PHP warning printed before the PDF would corrupt the file, so keep
// errors out of the output (they still go to the PHP error log).
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/controllers/receipt_helper.php';

$foundVendor = false;
$searchPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    'C:/xampp/htdocs/project/project/vendor/autoload.php',
    'C:/xampp/htdocs/project/vendor/autoload.php',
];
foreach ($searchPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $foundVendor = true;
        break;
    }
}
if (!$foundVendor) {
    ob_end_clean();
    receipt_fail(500, 'PDF library (Dompdf) not found. Run "composer require dompdf/dompdf" in ' . __DIR__ . '.');
}

use Dompdf\Dompdf;
use Dompdf\Options;

$access    = receipt_require_access();
$invoiceId = receipt_invoice_id($_GET['invoice_id'] ?? null);
$receipt   = receipt_load($invoiceId, $access);

/** Build the PDF bytes; throws if Dompdf fails. */
function build_receipt_pdf(array $receipt, bool $withLogo): string
{
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);   // the logo is embedded, nothing to fetch

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml(receipt_html($receipt, true, false, $withLogo));
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

@set_time_limit(120);
$pdf = '';
try {
    $pdf = build_receipt_pdf($receipt, true);
} catch (Throwable $e) {
    // Most often an image problem — try once more without the logo.
    error_log('Receipt PDF (with logo) failed for invoice ' . $invoiceId . ': ' . $e->getMessage());
    try {
        $pdf = build_receipt_pdf($receipt, false);
    } catch (Throwable $e2) {
        error_log('Receipt PDF failed for invoice ' . $invoiceId . ': ' . $e2->getMessage());
        ob_end_clean();
        receipt_fail(500, 'The PDF could not be created: ' . $e2->getMessage());
    }
}

ob_end_clean();   // drop anything printed so far (warnings, stray spaces)

$safeCode = preg_replace('/[^A-Za-z0-9_-]/', '', $receipt['invoice_code']) ?: (string)$invoiceId;
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Receipt-' . $safeCode . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store, max-age=0');
echo $pdf;
exit;