<?php
// api/portal_auth.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/supabase.php'; // adjust path as needed

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// ──────────────────────────────────────────────────────────────────────
// Supabase REST helper
// ──────────────────────────────────────────────────────────────────────
function sb_get($table, $query = '') {
    global $SUPABASE_URL, $SUPABASE_KEY;
    $url = "$SUPABASE_URL/rest/v1/$table" . ($query ? "?$query" : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "apikey: $SUPABASE_KEY",
            "Authorization: Bearer $SUPABASE_KEY",
        ]
    ]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true);
}

function sb_post($table, $data) {
    global $SUPABASE_URL, $SUPABASE_KEY;
    $ch = curl_init("$SUPABASE_URL/rest/v1/$table");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            "apikey: $SUPABASE_KEY",
            "Authorization: Bearer $SUPABASE_KEY",
            "Content-Type: application/json",
            "Prefer: return=representation"
        ]
    ]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true);
}

function sb_patch($table, $query, $data) {
    global $SUPABASE_URL, $SUPABASE_KEY;
    $ch = curl_init("$SUPABASE_URL/rest/v1/$table?$query");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            "apikey: $SUPABASE_KEY",
            "Authorization: Bearer $SUPABASE_KEY",
            "Content-Type: application/json",
            "Prefer: return=representation"
        ]
    ]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true);
}

function generate_otp() { return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT); }

function send_otp_sms($contact, $otp) {
    // TODO: Integrate your SMS gateway (e.g. Semaphore, Vonage, Twilio)
    // For development, log it:
    error_log("OTP for $contact: $otp");
    return true;
}

// ──────────────────────────────────────────────────────────────────────
// REGISTER
// ──────────────────────────────────────────────────────────────────────
if ($action === 'register') {
    $email    = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $contact  = trim($input['contact_number'] ?? '');

    if (!$email || !$password || !$contact) {
        echo json_encode(['success'=>false,'message'=>'Required fields missing.']); exit;
    }

    // Check duplicate email
    $existing = sb_get('patient_accounts', "email=eq." . urlencode($email) . "&select=account_id");
    if (!empty($existing) && !isset($existing['code'])) {
        echo json_encode(['success'=>false,'message'=>'An account with this email already exists.']); exit;
    }

    // Check duplicate contact
    $existContact = sb_get('patient_accounts', "contact_number=eq." . urlencode($contact) . "&select=account_id");
    if (!empty($existContact) && !isset($existContact['code'])) {
        echo json_encode(['success'=>false,'message'=>'This contact number is already registered.']); exit;
    }

    $otp        = generate_otp();
    $otp_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $hash       = password_hash($password, PASSWORD_DEFAULT);

    $payload = [
        'email'          => $email,
        'password_hash'  => $hash,
        'contact_number' => $contact,
        'first_name'     => $input['first_name'] ?? '',
        'last_name'      => $input['last_name']  ?? '',
        'middle_name'    => $input['middle_name'] ?? null,
        'suffix'         => $input['suffix']      ?? null,
        'otp'            => $otp,
        'otp_expiry'     => $otp_expiry,
        'is_verified'    => false,
        'is_profile_complete' => false,
    ];

    $result = sb_post('patient_accounts', $payload);

    if (isset($result[0]['account_id'])) {
        send_otp_sms($contact, $otp);
        // Store email in session for OTP step
        $_SESSION['pending_otp_email'] = $email;
        echo json_encode(['success'=>true]);
    } else {
        $msg = $result['message'] ?? 'Registration failed.';
        echo json_encode(['success'=>false,'message'=>$msg]);
    }
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// VERIFY OTP
// ──────────────────────────────────────────────────────────────────────
if ($action === 'verify_otp') {
    $email = trim($input['email'] ?? '');
    $otp   = trim($input['otp']   ?? '');

    $rows = sb_get('patient_accounts', "email=eq." . urlencode($email) . "&select=account_id,otp,otp_expiry,is_verified");
    if (empty($rows) || !isset($rows[0])) {
        echo json_encode(['success'=>false,'message'=>'Account not found.']); exit;
    }
    $acc = $rows[0];
    if ($acc['is_verified']) {
        echo json_encode(['success'=>true,'message'=>'Already verified.']); exit;
    }
    if ($acc['otp'] !== $otp) {
        echo json_encode(['success'=>false,'message'=>'Incorrect OTP.']); exit;
    }
    if (strtotime($acc['otp_expiry']) < time()) {
        echo json_encode(['success'=>false,'message'=>'OTP has expired. Please request a new one.']); exit;
    }

    sb_patch('patient_accounts', "account_id=eq.{$acc['account_id']}", ['is_verified'=>true,'otp'=>null,'otp_expiry'=>null]);
    echo json_encode(['success'=>true]);
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// RESEND OTP
// ──────────────────────────────────────────────────────────────────────
if ($action === 'resend_otp') {
    $email = trim($input['email'] ?? '');
    $rows = sb_get('patient_accounts', "email=eq." . urlencode($email) . "&select=account_id,contact_number");
    if (empty($rows) || !isset($rows[0])) {
        echo json_encode(['success'=>false,'message'=>'Account not found.']); exit;
    }
    $otp    = generate_otp();
    $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    sb_patch('patient_accounts', "account_id=eq.{$rows[0]['account_id']}", ['otp'=>$otp,'otp_expiry'=>$expiry]);
    send_otp_sms($rows[0]['contact_number'], $otp);
    echo json_encode(['success'=>true]);
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// LOGIN
// ──────────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $email    = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    $rows = sb_get('patient_accounts', "email=eq." . urlencode($email) . "&select=account_id,password_hash,is_verified,first_name,last_name,is_profile_complete");
    if (empty($rows) || !isset($rows[0])) {
        echo json_encode(['success'=>false,'message'=>'No account found with this email.']); exit;
    }
    $acc = $rows[0];
    if (!password_verify($password, $acc['password_hash'])) {
        echo json_encode(['success'=>false,'message'=>'Incorrect password.']); exit;
    }
    if (!$acc['is_verified']) {
        echo json_encode(['success'=>false,'message'=>'Please verify your account first. Check your phone for the OTP.']); exit;
    }

    $_SESSION['portal_account_id']      = $acc['account_id'];
    $_SESSION['portal_name']            = $acc['first_name'];
    $_SESSION['portal_profile_complete']= $acc['is_profile_complete'];

    echo json_encode(['success'=>true]);
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// LOGOUT
// ──────────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success'=>true]);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Invalid action.']);