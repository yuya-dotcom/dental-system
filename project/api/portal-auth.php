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

// ──────────────────────────────────────────────────────────────────────
// REGISTER
// Email OTP is already verified client-side via Supabase Auth JS
// before this endpoint is called, so we insert with is_verified = true
// ──────────────────────────────────────────────────────────────────────
if ($action === 'register') {
    $email    = trim($input['email']    ?? '');
    $password = $input['password']      ?? '';

    if (!$email || !$password) {
        echo json_encode(['success'=>false,'message'=>'Required fields missing.']); exit;
    }

    // Check duplicate email
    $existing = sb_get('patient_accounts', "email=eq." . urlencode($email) . "&select=account_id");
    if (!empty($existing) && !isset($existing['code'])) {
        echo json_encode(['success'=>false,'message'=>'An account with this email already exists.']); exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $payload = [
        'email'               => $email,
        'password_hash'       => $hash,
        'first_name'          => $input['first_name']  ?? '',
        'last_name'           => $input['last_name']   ?? '',
        'middle_name'         => $input['middle_name'] ?? null,
        'suffix'              => $input['suffix']      ?? null,
        'birthdate'           => $input['birthdate']   ?? null,
        // Email was verified via Supabase OTP before reaching here
        'is_verified'         => true,
        'is_profile_complete' => false,
    ];

    $result = sb_post('patient_accounts', $payload);

    if (isset($result[0]['account_id'])) {
        echo json_encode(['success'=>true]);
    } else {
        $msg = $result['message'] ?? 'Registration failed.';
        echo json_encode(['success'=>false,'message'=>$msg]);
    }
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// LOGIN
// ──────────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $email    = trim($input['email'] ?? '');
    $password = $input['password']   ?? '';

    $rows = sb_get('patient_accounts', "email=eq." . urlencode($email) . "&select=account_id,password_hash,is_verified,first_name,last_name,is_profile_complete");
    if (empty($rows) || !isset($rows[0])) {
        echo json_encode(['success'=>false,'message'=>'No account found with this email.']); exit;
    }

    $acc = $rows[0];

    if (!password_verify($password, $acc['password_hash'])) {
        echo json_encode(['success'=>false,'message'=>'Incorrect password.']); exit;
    }

    if (!$acc['is_verified']) {
        echo json_encode([
            'success' => false,
            'code'    => 'EMAIL_NOT_VERIFIED',
            'message' => 'Please verify your email before logging in.'
        ]);
        exit;
    }

    $_SESSION['portal_account_id']       = $acc['account_id'];
    $_SESSION['portal_name']             = $acc['first_name'];
    $_SESSION['portal_profile_complete'] = $acc['is_profile_complete'];

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