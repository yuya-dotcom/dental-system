<?php
// =============================================================
//  api/portal_records_pdf.php  —  Patient Portal › Settings › Download My Records
//    GET → a PDF of the signed-in patient's own details, appointments,
//          treatments and invoices (with payments).
//
//  Everything comes from the same loaders the portal pages use
//  (controllers/portal_loaders.php), which scope every query to the account
//  in the server session — never to anything the browser sends.
//  Layout uses tables only (Dompdf), formatting helpers from receipt_helper.php.
// =============================================================

// A PHP warning printed before the PDF would corrupt the file
ini_set('display_errors', '0');
ob_start();

session_start();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json');   // replaced once the PDF is ready

require_once __DIR__ . '/../portal-session-guard.php';
portal_require_login_api();                  // 401 JSON when signed out / expired

$accountId = (int)($_SESSION['portal_account_id'] ?? 0);
session_write_close();

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/portal_loaders.php';
require_once __DIR__ . '/../controllers/receipt_helper.php';   // rcpt_e / rcpt_money / rcpt_date / rcpt_method / rcpt_logo

function records_fail(int $status, string $message): void
{
    ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$autoload = null;
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../vendor/autoload.php'] as $p) {
    if (is_file($p)) { $autoload = $p; break; }
}
if (!$autoload) records_fail(500, 'The PDF tool is not available right now.');
require_once $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

// ── Data (one parallel run where the host allows it) ──────────────────────────
$data = portal_run([
    'profile'      => pl_profile($accountId),
    'appointments' => pl_appointments($accountId),
    'treatments'   => pl_treatments($accountId),
    'billing'      => pl_billing($accountId),
]);
if (empty($data['profile']['success'])) records_fail(500, "We couldn't load your records right now. Please try again.");

$acc      = $data['profile']['account'] ?? [];
$prof     = $data['profile']['profile'] ?? [];
$patient  = $data['appointments']['patient'] ?? [];
$appts    = $data['appointments']['appointments'] ?? [];
$treats   = $data['treatments']['treatments'] ?? [];
$invoices = $data['billing']['invoices'] ?? [];

// ── Small formatters ──────────────────────────────────────────────────────────
$e     = fn($v) => rcpt_e((string)($v ?? ''));
$dash  = fn($v) => ($v === null || trim((string)$v) === '') ? '&mdash;' : rcpt_e((string)$v);
$time  = function (?string $t): string {
    if (!$t) return '&mdash;';
    $ts = strtotime('1970-01-01 ' . $t);
    return $ts ? date('g:i A', $ts) : rcpt_e($t);
};
$word  = fn(?string $s) => $s ? rcpt_e(ucwords(str_replace('_', ' ', strtolower($s)))) : '&mdash;';
$money = fn($n) => rcpt_money((float)($n ?? 0));

$fullName = trim(implode(' ', array_filter([
    $acc['first_name'] ?? '', $acc['middle_name'] ?? '', $acc['last_name'] ?? '', $acc['suffix'] ?? '',
]))) ?: ($patient['full_name'] ?? 'Patient');
// Stored as 9XXXXXXXXX, 09XXXXXXXXX or +639XXXXXXXXX → always "+63 9XXXXXXXXX"
$phone  = trim((string)($acc['contact_number'] ?? ''));
$digits = preg_replace('/\D/', '', $phone);
if (preg_match('/^(?:63|0)?(9\d{9})$/', $digits, $m)) $phone = '+63 ' . $m[1];
elseif (preg_match('/^(__PORTAL_\d+__|PORTAL-\d+)$/', $phone)) $phone = '';   // placeholder, not a number
$address = implode(', ', array_filter([
    $prof['address'] ?? '', $prof['barangay'] ?? '', $prof['city'] ?? '', $prof['province'] ?? '', $prof['zip'] ?? '',
], fn($x) => trim((string)$x) !== ''));
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));

// ── HTML ──────────────────────────────────────────────────────────────────────
function records_html(bool $withLogo): string
{
    global $e, $dash, $time, $word, $money, $fullName, $phone, $address, $acc, $patient, $appts, $treats, $invoices, $now;

    $logo  = rcpt_logo(true, $withLogo);
    $brand = $logo ? '<img src="' . rcpt_e($logo) . '" alt="EssenciaSmile" class="logo">'
                   : '<span class="brand-text">EssenciaSmile</span>';

    $row = fn(string $label, string $value) => '<tr><td class="k">' . $label . '</td><td class="v">' . $value . '</td></tr>';
    $empty = fn(int $cols, string $msg) => '<tr><td colspan="' . $cols . '" class="none">' . $msg . '</td></tr>';

    // Appointments
    $aRows = '';
    foreach ($appts as $a) {
        $svc = implode(', ', $a['service_names'] ?? []) ?: ($a['services']['service_name'] ?? '');
        $aRows .= '<tr><td>' . $dash($a['appointment_code'] ?? '') . '</td><td>' . rcpt_date($a['appointment_date'] ?? null)
            . '</td><td>' . $time($a['appointment_time'] ?? null) . '</td><td>' . $dash($a['branches']['branch_name'] ?? '')
            . '</td><td>' . $dash($svc) . '</td><td>' . $word($a['status'] ?? '') . '</td></tr>';
    }
    // Treatments
    $tRows = '';
    foreach ($treats as $t) {
        $tRows .= '<tr><td>' . $dash($t['treatment_code'] ?? '') . '</td><td>' . rcpt_date($t['treatment_date'] ?? null)
            . '</td><td>' . $dash($t['service_name'] ?? '') . '</td><td>' . $dash($t['tooth_number'] ?? '')
            . '</td><td>' . $dash($t['dentist_name'] ?? '') . '</td><td>' . $word($t['status'] ?? '') . '</td></tr>';
    }
    // Invoices + payments
    $iRows = '';
    $totalDue = 0.0;
    foreach ($invoices as $i) {
        $total = (float)($i['total_amount'] ?? 0);
        $paid  = (float)($i['amount_paid'] ?? 0);
        $bal   = isset($i['balance']) ? (float)$i['balance'] : max(0, $total - $paid);
        $totalDue += max(0, $bal);
        $iRows .= '<tr><td>' . $dash($i['invoice_code'] ?? '') . '</td><td>' . rcpt_date($i['invoice_date'] ?? null)
            . '</td><td>' . $dash(implode(', ', $i['service_names'] ?? [])) . '</td><td class="num">' . $money($total)
            . '</td><td class="num">' . $money($paid) . '</td><td class="num">' . $money($bal)
            . '</td><td>' . $word($i['payment_status'] ?? '') . '</td></tr>';
        foreach (($i['payments'] ?? []) as $p) {
            $iRows .= '<tr class="pay"><td></td><td colspan="2">Payment &middot; ' . rcpt_date($p['payment_date'] ?? null)
                . ' &middot; ' . rcpt_e(rcpt_method($p['payment_method'] ?? '')) . '</td><td></td><td class="num">'
                . $money($p['amount'] ?? 0) . '</td><td></td><td></td></tr>';
        }
    }

    return '<!doctype html><html><head><meta charset="utf-8"><style>
      @page { margin: 28px 30px 40px; }
      body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #1e293b; }
      .logo { height: 34px; } .brand-text { font-size: 18px; font-weight: bold; color: #1d4ed8; }
      .head td { vertical-align: middle; } .title { text-align: right; }
      .title h1 { font-size: 15px; margin: 0; color: #0f172a; } .title div { color: #64748b; margin-top: 2px; }
      h2 { font-size: 11.5px; color: #1d4ed8; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #dbeafe; }
      table { width: 100%; border-collapse: collapse; }
      .info td { padding: 3px 0; } .info .k { width: 120px; color: #64748b; } .info .v { font-weight: bold; }
      .list th { background: #eff6ff; color: #1e3a8a; text-align: left; font-size: 8.5px; text-transform: uppercase; padding: 5px 5px; }
      .list td { padding: 5px 5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
      .list .num { text-align: right; white-space: nowrap; } .list th.num { text-align: right; }
      .list tr.pay td { color: #64748b; font-size: 8.5px; border-bottom: 1px dotted #e2e8f0; padding-top: 2px; padding-bottom: 2px; }
      .none { color: #94a3b8; text-align: center; padding: 10px; }
      .due { text-align: right; margin-top: 6px; font-weight: bold; }
      .foot { margin-top: 22px; color: #94a3b8; font-size: 8px; border-top: 1px solid #e2e8f0; padding-top: 6px; }
    </style></head><body>
      <table class="head"><tr><td>' . $brand . '</td><td class="title"><h1>Patient Records</h1>
        <div>Generated ' . rcpt_e($now->format('F j, Y g:i A')) . '</div></td></tr></table>

      <h2>Patient Details</h2>
      <table class="info">'
        . $row('Name', $e($fullName))
        . ($patient['patient_code'] ?? '' ? $row('Patient No.', $e($patient['patient_code'])) : '')
        . $row('Birthdate', rcpt_date($acc['birthdate'] ?? null))
        . $row('Email', $dash($acc['email'] ?? ''))
        . $row('Mobile', $dash($phone))
        . $row('Address', $dash($address))
      . '</table>

      <h2>Appointments (' . count($appts) . ')</h2>
      <table class="list"><tr><th>Code</th><th>Date</th><th>Time</th><th>Branch</th><th>Services</th><th>Status</th></tr>'
        . ($aRows ?: $empty(6, 'No appointments yet.')) . '</table>

      <h2>Treatments (' . count($treats) . ')</h2>
      <table class="list"><tr><th>Code</th><th>Date</th><th>Procedure</th><th>Tooth</th><th>Dentist</th><th>Status</th></tr>'
        . ($tRows ?: $empty(6, 'No treatments yet.')) . '</table>

      <h2>Invoices &amp; Payments (' . count($invoices) . ')</h2>
      <table class="list"><tr><th>Invoice</th><th>Date</th><th>Services</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th></tr>'
        . ($iRows ?: $empty(7, 'No invoices yet.')) . '</table>'
      . ($invoices ? '<div class="due">Outstanding balance: ' . $money($totalDue) . '</div>' : '') . '

      <div class="foot">Generated from the EssenciaSmile Patient Portal for the account holder\'s personal reference.
        For certified copies of your dental records, please contact the clinic.</div>
    </body></html>';
}

function records_pdf(bool $withLogo): string
{
    $o = new Options();
    $o->set('defaultFont', 'DejaVu Sans');
    $o->set('isRemoteEnabled', false);   // the logo is embedded; nothing is fetched
    $d = new Dompdf($o);
    $d->loadHtml(records_html($withLogo));
    $d->setPaper('A4', 'portrait');
    $d->render();
    return $d->output();
}

@set_time_limit(120);
try {
    $pdf = records_pdf(true);
} catch (Throwable $ex) {
    error_log('Records PDF (with logo) failed for account ' . $accountId . ': ' . $ex->getMessage());
    try {
        $pdf = records_pdf(false);   // most often an image problem: try without the logo
    } catch (Throwable $ex2) {
        error_log('Records PDF failed for account ' . $accountId . ': ' . $ex2->getMessage());
        records_fail(500, 'The PDF could not be created. Please try again.');
    }
}

ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="EssenciaSmile-Records-' . $now->format('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
