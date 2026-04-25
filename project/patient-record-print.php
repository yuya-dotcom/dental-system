<?php

define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']);
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/secure_controller.php';
require_once __DIR__ . '/controllers/patient_controller.php'; // list helpers
require_once __DIR__ . '/controllers/patient_control.php';    // FIX: getPatientById(), getPatientFinancialSummary(), etc.
require_once __DIR__ . '/dbconfig.php';

$patientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$patientId) die('Invalid patient.');

$patient    = getPatientById($patientId);
if (!$patient) die('Patient not found.');

$summary    = getPatientFinancialSummary($patientId);
$active     = getPatientActiveTreatments($patientId);
$upcoming   = getPatientUpcomingAppointments($patientId);
$invoices   = getPatientInvoices($patientId);
$treatments = getPatientTreatments($patientId);
$notes      = getPatientClinicalNotes($patientId);
$age        = calculateAge($patient['birthdate'] ?? null);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Patient Record — <?= htmlspecialchars($patient['full_name']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 11px; color: #333; margin: 0; padding: 20px; }
        h2  { font-size: 16px; margin: 0 0 2px; }
        h4  { font-size: 12px; margin: 16px 0 6px; border-bottom: 1px solid #ddd; padding-bottom: 4px; color: #555; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #333; }
        .meta   { color: #666; font-size: 10px; margin-top: 4px; }
        table   { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td  { border: 1px solid #ddd; padding: 4px 7px; text-align: left; }
        th      { background: #f5f5f5; font-weight: 600; font-size: 10px; }
        .badge  { display: inline-block; padding: 1px 6px; border-radius: 10px; font-size: 9px; font-weight: 600; background: #eee; }
        .summary-row { display: flex; gap: 20px; margin-bottom: 12px; }
        .summary-box { border: 1px solid #ddd; padding: 8px 12px; border-radius: 4px; flex: 1; }
        .summary-box .label { font-size: 9px; color: #888; }
        .summary-box .value { font-size: 13px; font-weight: 700; }
        @media print { body { padding: 0; } }
    </style>
</head>
<body onload="window.print()">

    <!-- HEADER -->
    <div class="header">
        <div>
            <h2><?= htmlspecialchars($patient['full_name']) ?></h2>
            <div class="meta">
                <?= htmlspecialchars($patient['patient_code']) ?> &nbsp;·&nbsp;
                <?= htmlspecialchars($patient['gender'] ?? '—') ?> &nbsp;·&nbsp;
                <?= $age ?> &nbsp;·&nbsp;
                DOB: <?= $patient['birthdate'] ? date('M d, Y', strtotime($patient['birthdate'])) : '—' ?> &nbsp;·&nbsp;
                <?= htmlspecialchars($patient['contact_number']) ?> &nbsp;·&nbsp;
                Branch: <?= htmlspecialchars($patient['branches']['branch_name'] ?? '—') ?>
            </div>
        </div>
        <div style="text-align:right; color:#888; font-size:10px;">
            <div><strong>EssenciaSmile Dental</strong></div>
            <div>Printed: <?= date('M d, Y g:i A') ?></div>
            <div>Status: <strong><?= ucfirst($patient['status']) ?></strong></div>
        </div>
    </div>

    <!-- FINANCIAL SUMMARY -->
    <h4>Financial Summary</h4>
    <div class="summary-row">
        <div class="summary-box"><div class="label">Total Billed</div><div class="value">₱<?= number_format($summary['total_billed'],2) ?></div></div>
        <div class="summary-box"><div class="label">Total Paid</div><div class="value">₱<?= number_format($summary['total_paid'],2) ?></div></div>
        <div class="summary-box"><div class="label">Balance</div><div class="value">₱<?= number_format($summary['total_balance'],2) ?></div></div>
    </div>

    <!-- ACTIVE TREATMENTS -->
    <h4>Active Treatment Plans</h4>
    <?php if (empty($active)): ?>
        <p style="color:#888;">None</p>
    <?php else: ?>
        <table><thead><tr><th>Code</th><th>Service</th><th>Tooth</th><th>Stage</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($active as $t): ?>
        <tr>
            <td><?= htmlspecialchars($t['treatment_code']) ?></td>
            <td><?= htmlspecialchars($t['services']['service_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($t['tooth_number'] ?? '—') ?></td>
            <td><?= htmlspecialchars($t['current_stage']) ?></td>
            <td><?= ucfirst($t['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>

    <!-- UPCOMING APPOINTMENTS -->
    <h4>Upcoming Appointments</h4>
    <?php if (empty($upcoming)): ?>
        <p style="color:#888;">None scheduled.</p>
    <?php else: ?>
        <table><thead><tr><th>Date</th><th>Time</th><th>Service</th><th>Dentist</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($upcoming as $a): ?>
        <tr>
            <td><?= date('M d, Y', strtotime($a['appointment_date'])) ?></td>
            <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
            <td><?= htmlspecialchars($a['services']['service_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($a['dentists']['full_name'] ?? '—') ?></td>
            <td><?= ucfirst($a['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>

    <!-- CLINICAL NOTES -->
    <h4>Clinical Notes</h4>
    <?php if (empty($notes)): ?>
        <p style="color:#888;">No notes on record.</p>
    <?php else: ?>
        <?php foreach ($notes as $n): ?>
        <div style="margin-bottom:8px; border:1px solid #eee; padding:7px 10px; border-radius:4px;">
            <strong><?= date('M d, Y', strtotime($n['note_date'])) ?></strong>
            — <?= htmlspecialchars($n['dentists']['full_name'] ?? $n['created_by']) ?>
            <?= $n['is_private'] ? '<span class="badge">Private</span>' : '' ?>
            <div style="margin-top:4px; white-space:pre-wrap;"><?= htmlspecialchars($n['note_text']) ?></div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- BILLING HISTORY -->
    <h4>Invoice History</h4>
    <?php if (empty($invoices)): ?>
        <p style="color:#888;">No invoices.</p>
    <?php else: ?>
        <table><thead><tr><th>Invoice</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($invoices as $inv): ?>
        <tr>
            <td><?= htmlspecialchars($inv['invoice_code']) ?></td>
            <td><?= date('M d, Y', strtotime($inv['invoice_date'])) ?></td>
            <td>₱<?= number_format($inv['total_amount'],2) ?></td>
            <td>₱<?= number_format($inv['amount_paid'],2) ?></td>
            <td>₱<?= number_format($inv['balance'],2) ?></td>
            <td><?= ucfirst($inv['payment_status']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>

</body>
</html>