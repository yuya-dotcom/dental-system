<?php
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/notification_helper.php';
require_once __DIR__ . '/sms_helper.php'; // sendSMS(), normalizePhMobile(), UniSMS config (config/secrets.php)

const PHONE_OTP_TTL             = 600;  // 10 minutes
const PHONE_OTP_RESEND_COOLDOWN = 30;   // seconds
const PHONE_OTP_MAX_ATTEMPTS    = 5;    // wrong guesses per code (needs phone_otp_attempts column)

// ─── SEND PHONE OTP ───────────────────────────────────────────────────────────
function sendPhoneOtp(int $account_id, string $phone): array {
    $phone = normalizePhMobile($phone);
    if ($phone === null) {
        return ['success' => false, 'message' => 'Your mobile number must be a valid PH number (e.g. 9XX XXX XXXX). Please update it in your profile.'];
    }

    // Server-side resend cooldown (the 30s UI timer alone can be bypassed)
    $cur = supabase_request('patient_accounts', 'GET', [], 'account_id=eq.' . $account_id . '&select=phone_otp_expires_at&limit=1');
    $prevExp = $cur['data'][0]['phone_otp_expires_at'] ?? null;
    if ($prevExp && strtotime($prevExp) - time() > PHONE_OTP_TTL - PHONE_OTP_RESEND_COOLDOWN) {
        return ['success' => false, 'message' => 'Please wait 30 seconds before requesting a new code.'];
    }

    $code       = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires_at = date('Y-m-d H:i:s', time() + PHONE_OTP_TTL);

    $patch  = ['phone_otp_code' => $code, 'phone_otp_expires_at' => $expires_at];
    $update = supabase_request('patient_accounts', 'PATCH', $patch + ['phone_otp_attempts' => 0], 'account_id=eq.' . $account_id);
    if ($update['error']) {
        // phone_otp_attempts column not added yet (database/2026-10-18_phone_otp_attempts.sql)
        $update = supabase_request('patient_accounts', 'PATCH', $patch, 'account_id=eq.' . $account_id);
    }

    if ($update['error']) {
        return ['success' => false, 'message' => 'Could not generate code. Please try again.'];
    }

    $message = "EssenciaSmile: Your verification code is {$code}. Expires in 10 minutes.";
    $result  = sendSMS($phone, $message);

    if (!$result['success']) {
        return ['success' => false, 'message' => 'Failed to send SMS: ' . $result['message']];
    }

    return ['success' => true, 'message' => 'Verification code sent to your phone.'];
}

// ─── VERIFY PHONE OTP ─────────────────────────────────────────────────────────
function verifyPhoneOtp(int $account_id, string $code): array {
    if (empty($code)) {
        return ['success' => false, 'message' => 'Please enter the code.'];
    }

    $select = 'account_id=eq.' . $account_id . '&limit=1&select=phone_otp_code,phone_otp_expires_at,phone_verified';
    $result = supabase_request('patient_accounts', 'GET', [], $select . ',phone_otp_attempts');
    $hasAttempts = !$result['error'];
    if (!$hasAttempts) {
        // phone_otp_attempts column not added yet — fall back to one guess per code
        $result = supabase_request('patient_accounts', 'GET', [], $select);
    }

    if (empty($result['data'][0])) {
        return ['success' => false, 'message' => 'Account not found.'];
    }

    $account = $result['data'][0];

    if ($account['phone_verified']) {
        return ['success' => true, 'already_verified' => true, 'message' => 'Your phone is already verified.'];
    }

    if (empty($account['phone_otp_expires_at']) || strtotime($account['phone_otp_expires_at']) < time()) {
        return ['success' => false, 'message' => 'This code has expired. Please request a new one.'];
    }

    $stored  = (string)$account['phone_otp_code'];
    $match   = 'account_id=eq.' . $account_id . '&phone_otp_code=eq.' . urlencode($stored);
    $burn    = fn() => supabase_request('patient_accounts', 'PATCH',
        ['phone_otp_code' => null, 'phone_otp_expires_at' => null], $match);
    if ($stored === '') {
        return ['success' => false, 'message' => 'This code is no longer valid. Please request a new one.'];
    }

    // Each guess first claims one attempt with a compare-and-set write, so
    // parallel requests can't exceed PHONE_OTP_MAX_ATTEMPTS for one code.
    $n = 0;
    if ($hasAttempts) {
        $n = (int)($account['phone_otp_attempts'] ?? 0);
        if ($n >= PHONE_OTP_MAX_ATTEMPTS) {
            $burn();
            return ['success' => false, 'message' => 'Too many incorrect attempts. Please request a new code.'];
        }
        $claim = supabase_request('patient_accounts', 'PATCH', ['phone_otp_attempts' => $n + 1],
            $match . '&phone_otp_attempts=eq.' . $n);
        if ($claim['error'] || empty($claim['data'])) {
            return ['success' => false, 'message' => 'Please try again.'];
        }
    }

    if (!hash_equals($stored, $code)) {
        if (!$hasAttempts || $n + 1 >= PHONE_OTP_MAX_ATTEMPTS) {
            $burn();
            return ['success' => false, 'message' => 'Incorrect code. Please request a new code.'];
        }
        $left = PHONE_OTP_MAX_ATTEMPTS - $n - 1;
        return ['success' => false, 'message' => "Incorrect code. You have $left attempt" . ($left === 1 ? '' : 's') . ' left.'];
    }

    // Conditional on the same code still being stored: single use
    $verRes = supabase_request('patient_accounts', 'PATCH', [
        'phone_verified'       => true,
        'phone_otp_code'       => null,
        'phone_otp_expires_at' => null,
    ], $match);

    if ($verRes['error'] || empty($verRes['data'])) {
        return ['success' => false, 'message' => 'This code is no longer valid. Please request a new one.'];
    }

    notif_account_event((int)$account_id, 'phone_verified', 'Phone number verified',
        'Your mobile number has been verified successfully.',
        'acct:phone_verified:' . md5((string)$account['phone_otp_expires_at']));

    return ['success' => true, 'already_verified' => false, 'message' => 'Your phone number has been verified successfully!'];
}

// ─── HANDLE AJAX REQUESTS ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    session_start();

    $action     = $_POST['action'] ?? '';
    $account_id = (int)($_SESSION['portal_account_id'] ?? 0);

    if (!$account_id) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
        exit;
    }

    // ─── SEND OTP ───
    if ($action === 'send_phone_otp') {

        // 1. Try to get the phone from patient_accounts first
        $accRes = supabase_request('patient_accounts', 'GET', [],
            'account_id=eq.' . $account_id . '&select=patient_id,contact_number&limit=1');
        $account = $accRes['data'][0] ?? [];
        $phone = $account['contact_number'] ?? '';

        // 2. If empty or a placeholder, fallback to the patients table
        if (empty($phone) || preg_match('/^__PORTAL_\d+__$/', $phone) || preg_match('/^PORTAL-\d+$/', $phone)) {
            if (!empty($account['patient_id'])) {
                $patRes = supabase_request('patients', 'GET', [],
                    'patient_id=eq.' . $account['patient_id'] . '&select=contact_number&limit=1');
                $patPhone = $patRes['data'][0]['contact_number'] ?? '';

                // Only use it if it's a real number
                if (!empty($patPhone) && !preg_match('/^__PORTAL_\d+__$/', $patPhone) && !preg_match('/^PORTAL-\d+$/', $patPhone)) {
                    $phone = $patPhone;
                }
            }
        }

        // 3. Final validation
        if (empty($phone) || preg_match('/^__PORTAL_\d+__$/', $phone) || preg_match('/^PORTAL-\d+$/', $phone)) {
            echo json_encode(['success' => false, 'message' => 'No valid phone number on file. Please add one in your profile first.']);
            exit;
        }

        // 4. Send the SMS
        $result = sendPhoneOtp($account_id, $phone);
        echo json_encode($result);
        exit;
    }

    // ─── VERIFY OTP ───
    if ($action === 'verify_phone_otp') {
        $code   = trim($_POST['code'] ?? '');
        $result = verifyPhoneOtp($account_id, $code);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}
