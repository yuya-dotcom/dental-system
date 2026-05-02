<?php
// api/portal_data.php
session_start();
header('Content-Type: application/json');

// Auth guard
if (!isset($_SESSION['portal_account_id'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}
$account_id = (int)$_SESSION['portal_account_id'];

require_once __DIR__ . '/../config/supabase.php';

$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// ──────────────────────────────────────────────────────────────────────
// Supabase helpers
// ──────────────────────────────────────────────────────────────────────
function sb($method, $path, $body = null) {
    global $SUPABASE_URL, $SUPABASE_KEY;
    $ch = curl_init("$SUPABASE_URL/rest/v1/$path");
    $headers = [
        "apikey: $SUPABASE_KEY",
        "Authorization: Bearer $SUPABASE_KEY",
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Prefer: return=representation';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    if ($method === 'PATCH') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true);
}

function sb_get($path)         { return sb('GET',   $path); }
function sb_patch($path, $b)   { return sb('PATCH', $path, $b); }

// ──────────────────────────────────────────────────────────────────────
// HELPER: get patient_id from account
// ──────────────────────────────────────────────────────────────────────
function get_patient_id($account_id) {
    $rows = sb_get("patient_accounts?account_id=eq.$account_id&select=patient_id");
    return $rows[0]['patient_id'] ?? null;
}

// ──────────────────────────────────────────────────────────────────────
// DASHBOARD SUMMARY
// ──────────────────────────────────────────────────────────────────────
if ($action === 'dashboard_summary') {
    // Account info
    $acc_rows = sb_get("patient_accounts?account_id=eq.$account_id&select=account_id,first_name,last_name,middle_name,suffix,email,contact_number,is_profile_complete");
    $account  = $acc_rows[0] ?? [];

    $patient_id = get_patient_id($account_id);

    // Upcoming appointments (via account_id)
    $upcoming = sb_get("appointments?account_id=eq.$account_id&status=in.(pending,confirmed,checked_in)&order=appointment_date.asc,appointment_time.asc&limit=5&select=appointment_id,appointment_code,appointment_date,appointment_time,status,branch_id,service_id");

    // Enrich: branch names, service names
    $branches = sb_get("branches?select=branch_id,branch_name");
    $services = sb_get("services?select=service_id,service_name");
    $branchMap = array_column($branches ?? [], 'branch_name', 'branch_id');
    $serviceMap= array_column($services ?? [], 'service_name', 'service_id');

    foreach ($upcoming as &$a) {
        $a['branch_name']  = $branchMap[$a['branch_id']]  ?? '—';
        $a['service_name'] = $serviceMap[$a['service_id']] ?? 'Consultation';
    }
    unset($a);

    // Stats
    $all_appts = sb_get("appointments?account_id=eq.$account_id&select=status");
    $upcoming_cnt  = count(array_filter($all_appts ?? [], fn($a) => in_array($a['status'], ['pending','confirmed','checked_in'])));
    $completed_cnt = count(array_filter($all_appts ?? [], fn($a) => $a['status'] === 'completed'));

    $balance   = 0;
    $treat_cnt = 0;
    $recent_bills = [];
    if ($patient_id) {
        $invoices = sb_get("invoices?patient_id=eq.$patient_id&select=invoice_code,invoice_date,total_amount,amount_paid,payment_status&order=invoice_date.desc&limit=5");
        foreach ($invoices ?? [] as $inv) {
            $balance += floatval($inv['total_amount']) - floatval($inv['amount_paid']);
        }
        $recent_bills = $invoices ?? [];
        $treatments   = sb_get("treatments?patient_id=eq.$patient_id&select=treatment_id");
        $treat_cnt    = count($treatments ?? []);
    }

    echo json_encode([
        'success' => true,
        'account' => $account,
        'is_profile_complete' => (bool)($account['is_profile_complete'] ?? false),
        'stats'   => ['upcoming'=>$upcoming_cnt,'completed'=>$completed_cnt,'balance'=>$balance,'treatments'=>$treat_cnt],
        'upcoming'      => array_slice($upcoming, 0, 4),
        'recent_bills'  => $recent_bills,
    ]);
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// GET APPOINTMENTS (full list)
// ──────────────────────────────────────────────────────────────────────
if ($action === 'get_appointments') {
    $appts = sb_get("appointments?account_id=eq.$account_id&order=appointment_date.desc,appointment_time.desc&select=appointment_id,appointment_code,appointment_date,appointment_time,status,branch_id,service_id");

    $branches  = sb_get("branches?select=branch_id,branch_name");
    $services  = sb_get("services?select=service_id,service_name");
    $branchMap = array_column($branches ?? [], 'branch_name', 'branch_id');
    $serviceMap= array_column($services ?? [], 'service_name', 'service_id');

    foreach ($appts as &$a) {
        $a['branch_name']  = $branchMap[$a['branch_id']]  ?? '—';
        $a['service_name'] = $serviceMap[$a['service_id']] ?? 'Consultation';
    }
    unset($a);
    echo json_encode(['success'=>true, 'appointments'=>$appts]);
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// CANCEL APPOINTMENT
// ──────────────────────────────────────────────────────────────────────
if ($action === 'cancel_appointment') {
    $appt_id = (int)($input['appointment_id'] ?? 0);
    if (!$appt_id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

    // Verify ownership
    $rows = sb_get("appointments?appointment_id=eq.$appt_id&account_id=eq.$account_id&select=appointment_id,status");
    if (empty($rows) || !isset($rows[0])) {
        echo json_encode(['success'=>false,'message'=>'Appointment not found.']); exit;
    }
    if (!in_array($rows[0]['status'], ['pending','confirmed'])) {
        echo json_encode(['success'=>false,'message'=>'This appointment cannot be cancelled.']); exit;
    }

    $result = sb_patch("appointments?appointment_id=eq.$appt_id", ['status'=>'cancelled','updated_at'=>date('c')]);
    echo json_encode(['success'=>true]);
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// GET TREATMENTS
// ──────────────────────────────────────────────────────────────────────
if ($action === 'get_treatments') {
    $patient_id = get_patient_id($account_id);
    if (!$patient_id) { echo json_encode(['success'=>true,'treatments'=>[]]); exit; }

    $treatments = sb_get("treatments?patient_id=eq.$patient_id&order=treatment_date.desc&select=treatment_id,treatment_code,treatment_date,service_id,tooth_number,status,dentist_id");

    $services = sb_get("services?select=service_id,service_name");
    $dentists = sb_get("dentists?select=dentist_id,full_name");
    $svcMap   = array_column($services ?? [], 'service_name', 'service_id');
    $denMap   = array_column($dentists ?? [], 'full_name',    'dentist_id');

    foreach ($treatments as &$t) {
        $t['service_name'] = $svcMap[$t['service_id']] ?? '—';
        $t['dentist_name'] = $denMap[$t['dentist_id']] ?? '—';
    }
    unset($t);
    echo json_encode(['success'=>true,'treatments'=>$treatments]);
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// GET DENTAL CHART
// ──────────────────────────────────────────────────────────────────────
if ($action === 'get_dental_chart') {
    $patient_id = get_patient_id($account_id);
    if (!$patient_id) { echo json_encode(['success'=>true,'chart'=>[]]); exit; }

    $chart = sb_get("dental_chart?patient_id=eq.$patient_id&select=tooth_number,condition,notes");
    echo json_encode(['success'=>true,'chart'=>$chart??[]]);
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// GET BILLING
// ──────────────────────────────────────────────────────────────────────
if ($action === 'get_billing') {
    $patient_id = get_patient_id($account_id);
    if (!$patient_id) { echo json_encode(['success'=>true,'invoices'=>[]]); exit; }

    $invoices = sb_get("invoices?patient_id=eq.$patient_id&order=invoice_date.desc&select=invoice_id,invoice_code,invoice_date,treatment_id,total_amount,amount_paid,payment_status");

    $treatments = sb_get("treatments?patient_id=eq.$patient_id&select=treatment_id,treatment_code");
    $treatMap   = array_column($treatments ?? [], 'treatment_code', 'treatment_id');

    foreach ($invoices as &$inv) {
        $inv['treatment_code'] = $treatMap[$inv['treatment_id']] ?? '—';
    }
    unset($inv);
    echo json_encode(['success'=>true,'invoices'=>$invoices??[]]);
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// GET FILES
// ──────────────────────────────────────────────────────────────────────
if ($action === 'get_files') {
    $patient_id = get_patient_id($account_id);
    if (!$patient_id) { echo json_encode(['success'=>true,'files'=>[]]); exit; }

    $files = sb_get("patient_files?patient_id=eq.$patient_id&order=uploaded_at.desc&select=file_id,file_type,file_name,file_path,uploaded_at");
    echo json_encode(['success'=>true,'files'=>$files??[]]);
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// GET WAIVERS
// ──────────────────────────────────────────────────────────────────────
if ($action === 'get_waivers') {
    $patient_id = get_patient_id($account_id);
    if (!$patient_id) { echo json_encode(['success'=>true,'waivers'=>[]]); exit; }

    $waivers = sb_get("waivers?patient_id=eq.$patient_id&select=waiver_id,waiver_type,status,signed_at,file_path");
    echo json_encode(['success'=>true,'waivers'=>$waivers??[]]);
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// GET PROFILE
// ──────────────────────────────────────────────────────────────────────
if ($action === 'get_profile') {
    $patient_id = get_patient_id($account_id);
    $rows = sb_get("patient_accounts?account_id=eq.$account_id&select=account_id,first_name,last_name,middle_name,suffix,email,contact_number,is_profile_complete");
    $acc  = $rows[0] ?? [];

    // Pull birthdate and gender from patients table if linked
    if ($patient_id) {
        $pat = sb_get("patients?patient_id=eq.$patient_id&select=birthdate,gender");
        if (!empty($pat[0])) {
            $acc['birthdate'] = $pat[0]['birthdate'] ?? null;
            $acc['gender']    = $pat[0]['gender']    ?? null;
        }
    }
    echo json_encode(['success'=>true,'account'=>$acc]);
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// UPDATE PROFILE
// ──────────────────────────────────────────────────────────────────────
if ($action === 'update_profile') {
    $firstName = trim($input['first_name'] ?? '');
    $lastName  = trim($input['last_name']  ?? '');
    if (!$firstName || !$lastName) {
        echo json_encode(['success'=>false,'message'=>'First and last name required.']); exit;
    }

    $acct_payload = [
        'first_name'  => $firstName,
        'last_name'   => $lastName,
        'middle_name' => $input['middle_name'] ?? null,
        'suffix'      => $input['suffix']      ?? null,
        'is_profile_complete' => true,
    ];
    sb_patch("patient_accounts?account_id=eq.$account_id", $acct_payload);

    // Update patients record if linked
    $patient_id = get_patient_id($account_id);
    if ($patient_id) {
        $fullName = trim("$firstName " . ($input['middle_name'] ? $input['middle_name'].' ' : '') . "$lastName" . ($input['suffix'] ? ' '.$input['suffix'] : ''));
        $pat_payload = ['full_name'=>$fullName,'updated_at'=>date('c')];
        if (!empty($input['birthdate'])) $pat_payload['birthdate'] = $input['birthdate'];
        if (!empty($input['gender']))    $pat_payload['gender']    = $input['gender'];
        sb_patch("patients?patient_id=eq.$patient_id", $pat_payload);
    }

    $_SESSION['portal_profile_complete'] = true;
    echo json_encode(['success'=>true]);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Invalid action.']);