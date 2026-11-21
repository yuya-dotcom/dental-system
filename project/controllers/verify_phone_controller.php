<?php
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/notification_helper.php';
require_once __DIR__ . '/sms_helper.php'; // sendSMS(), normalizePhMobile(), UniSMS config (config/secrets.php)
require_once __DIR__ . '/portal_validation.php'; // pv_mobile_error()

// ─── SMS OTP (UniSMS) ─────────────────────────────────────────────────────────
// Codes live in otp_verifications (database/proposed/2026-11-13_notification_system.sql):
//   • only an HMAC-SHA256 of the code is stored (keyed with config 'otp_pepper'),
//     bound to the account, purpose and destination number;
//   • issuing a new code invalidates every earlier unused code;
//   • single use, 10-minute expiry, 5 guesses per code (compare-and-set counter);
//   • resend cooldown + hourly / daily limits per account, per number and per IP.
// The code itself is never logged, stored or returned in a response.
const PHONE_OTP_TTL             = 600;  // 10 minutes
const PHONE_OTP_RESEND_COOLDOWN = 30;   // seconds
const PHONE_OTP_MAX_ATTEMPTS    = 5;    // wrong guesses per code
const PHONE_OTP_LIMITS          = [     // [scope, window in seconds, max codes sent]
    ['account', 3600, 5], ['account', 86400, 10], ['mobile', 3600, 5], ['ip', 3600, 15],
];

function phone_otp_pepper(): string {
    static $p = null;
    if ($p === null) {
        $s = require __DIR__ . '/../config/secrets.php';
        $p = (string)($s['otp_pepper'] ?? '');
        if (strlen($p) < 32) $p = hash('sha256', 'es-otp-pepper|' . SUPABASE_KEY);   // fallback until otp_pepper is set
    }
    return $p;
}

function phone_otp_hash(string $code, int $accountId, string $purpose, string $mobile): string {
    return hash_hmac('sha256', $code . '|' . $accountId . '|' . $purpose . '|' . $mobile, phone_otp_pepper());
}

function phone_otp_client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 64);
}

/** Number of otp_verifications rows matching $filter (null when the table is unavailable). */
function phone_otp_count(string $filter): ?int {
    $r = supabase_request('otp_verifications', 'GET', [], $filter . '&select=otp_id&limit=1', ['Prefer: count=exact']);
    if ($r['error'] !== null || $r['status'] < 200 || $r['status'] >= 300) return null;
    $range = '';
    foreach (($r['headers'] ?? []) as $k => $v) if (strtolower((string)$k) === 'content-range') $range = (string)$v;
    return preg_match('#/(\d+)$#', $range, $m) ? (int)$m[1] : count($r['data'] ?? []);
}

/** The newest unused code of an account for one purpose, or null. */
function phone_otp_active(int $accountId, string $purpose): ?array {
    $r = supabase_request('otp_verifications', 'GET', [],
        'account_id=eq.' . $accountId . '&purpose=eq.' . $purpose . '&consumed_at=is.null&order=created_at.desc&limit=1'
        . '&select=otp_id,mobile_number,otp_hash,expires_at,attempts,max_attempts,last_sent_at');
    return ($r['error'] === null && $r['status'] === 200) ? ($r['data'][0] ?? null) : null;
}

function phone_otp_consume(int $otpId): void {
    supabase_request('otp_verifications', 'PATCH', ['consumed_at' => gmdate('c')], 'otp_id=eq.' . $otpId . '&consumed_at=is.null');
}

// ─── SEND PHONE OTP ───────────────────────────────────────────────────────────
// $onIssued($code) runs once the code is stored, before the SMS goes out
// (the number-change flow uses it to bind the code to the new number).
function sendPhoneOtp(int $account_id, string $phone, ?callable $onIssued = null): array {
    $phone = normalizePhMobile($phone);
    if ($phone === null) {
        return ['success' => false, 'message' => 'Your mobile number must be a valid PH number (e.g. 9XX XXX XXXX). Please update it in your profile.'];
    }
    $purpose = $onIssued ? 'phone_change' : 'phone_verify';
    $unavailable = ['success' => false, 'message' => 'Phone verification is temporarily unavailable. Please try again later.'];

    // Resend cooldown (server-side; the 30s UI timer alone can be bypassed)
    $prev = phone_otp_active($account_id, $purpose);
    if ($prev && time() - strtotime($prev['last_sent_at']) < PHONE_OTP_RESEND_COOLDOWN) {
        $wait = PHONE_OTP_RESEND_COOLDOWN - (time() - strtotime($prev['last_sent_at']));
        return ['success' => false, 'code' => 'COOLDOWN', 'retry_after' => $wait, 'message' => "Please wait {$wait} seconds before requesting a new code."];
    }

    // Rate limits per account, per destination number and per IP
    $ip = phone_otp_client_ip();
    foreach (PHONE_OTP_LIMITS as [$scope, $window, $max]) {
        $since  = 'created_at=gte.' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', time() - $window));
        $filter = match ($scope) {
            'account' => 'account_id=eq.' . $account_id,
            'mobile'  => 'mobile_number=eq.' . rawurlencode($phone),
            'ip'      => 'request_ip=eq.' . rawurlencode($ip),
        } . '&' . $since;
        $n = phone_otp_count($filter);
        if ($n === null) return $unavailable;
        if ($n >= $max) {
            error_log("phone OTP rate limit: {$scope} window={$window}s account={$account_id}");
            return ['success' => false, 'code' => 'RATE_LIMITED',
                'message' => $window >= 86400 ? 'You have requested too many codes today. Please try again tomorrow.'
                                              : 'Too many codes were requested. Please wait a while before trying again.'];
        }
    }

    // New code; every earlier unused code of this purpose stops working
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    supabase_request('otp_verifications', 'PATCH', ['consumed_at' => gmdate('c')],
        'account_id=eq.' . $account_id . '&purpose=eq.' . $purpose . '&consumed_at=is.null');
    $ins = supabase_request('otp_verifications', 'POST', [
        'account_id'    => $account_id,
        'purpose'       => $purpose,
        'mobile_number' => $phone,
        'otp_hash'      => phone_otp_hash($code, $account_id, $purpose, $phone),
        'expires_at'    => gmdate('c', time() + PHONE_OTP_TTL),
        'max_attempts'  => PHONE_OTP_MAX_ATTEMPTS,
        'request_ip'    => $ip,
        'last_sent_at'  => gmdate('c'),
    ]);
    $otpId = (int)($ins['data'][0]['otp_id'] ?? 0);
    if ($ins['error'] !== null || $otpId < 1) {
        error_log('phone OTP: could not store code [' . $ins['status'] . ']');
        return $unavailable;
    }
    if ($onIssued) $onIssued($code);

    // Send through the notification log (the logged message never contains the code)
    $message = "EssenciaSmile: Your verification code is {$code}. It expires in 10 minutes. Do not share this code.";
    require_once __DIR__ . '/notification_service.php';
    $pat = supabase_request('patient_accounts', 'GET', [], 'account_id=eq.' . $account_id . '&select=patient_id&limit=1');
    if (notify_logs_available()) {
        $res = notify_send_once([
            'dedupe_key' => 'otp:sms:' . $otpId, 'notification_type' => 'otp', 'channel' => 'sms',
            'account_id' => $account_id, 'patient_id' => $pat['data'][0]['patient_id'] ?? null,
            'recipient' => $phone, 'message' => 'Verification code (hidden)',
        ], fn() => notify_sms_send($phone, $message));
    } else {
        $r   = sendSMS($phone, $message);
        $res = ['result' => !empty($r['success']) ? 'sent' : 'failed'];
    }
    unset($message, $code);

    if (($res['result'] ?? '') !== 'sent') {
        phone_otp_consume($otpId);   // an undelivered code must not stay usable
        return ['success' => false, 'message' => 'We could not send the code right now. Please try again in a moment.'];
    }
    return ['success' => true, 'message' => 'Verification code sent to your phone.'];
}

// ─── VERIFY PHONE OTP ─────────────────────────────────────────────────────────
// $change = ['number' => '+639…', 'bind' => …] verifies a code texted to a NEW
// number (see send_change_otp) and, on success, swaps that number in.
function verifyPhoneOtp(int $account_id, string $code, ?array $change = null): array {
    $code = preg_replace('/\D/', '', $code);
    if ($code === '') {
        return ['success' => false, 'message' => 'Please enter the code.'];
    }
    if (strlen($code) !== 6) {
        return ['success' => false, 'message' => 'The code has 6 digits. Please check it and try again.'];
    }

    $accRes = supabase_request('patient_accounts', 'GET', [],
        'account_id=eq.' . $account_id . '&limit=1&select=contact_number,phone_verified,patient_id');
    $account = $accRes['data'][0] ?? null;
    if (!$account) {
        return ['success' => false, 'message' => 'Account not found.'];
    }
    if ($change === null && $account['phone_verified']) {
        return ['success' => true, 'already_verified' => true, 'message' => 'Your phone is already verified.'];
    }
    if ($change !== null && empty($account['phone_verified'])) {
        return ['success' => false, 'message' => 'Your number is not verified yet, so you can edit it directly in your profile.'];
    }

    $purpose = $change !== null ? 'phone_change' : 'phone_verify';
    $row = phone_otp_active($account_id, $purpose);
    if (!$row) {
        return ['success' => false, 'message' => 'This code is no longer valid. Please request a new one.'];
    }
    $otpId = (int)$row['otp_id'];

    if (strtotime($row['expires_at']) < time()) {
        phone_otp_consume($otpId);
        return ['success' => false, 'code' => 'EXPIRED', 'message' => 'This code has expired. Please request a new one.'];
    }
    // The code must belong to the number it is confirming
    $expectedNumber = $change !== null ? (string)$change['number'] : (string)normalizePhMobile((string)$account['contact_number']);
    if ($expectedNumber === '' || !hash_equals((string)$row['mobile_number'], $expectedNumber)) {
        phone_otp_consume($otpId);
        return ['success' => false, 'message' => 'This code was sent to a different number. Please request a new one.'];
    }

    // Each guess first claims one attempt with a compare-and-set write, so
    // parallel requests can't exceed the limit for one code.
    $n   = (int)$row['attempts'];
    $max = max(1, (int)$row['max_attempts']);
    if ($n >= $max) {
        phone_otp_consume($otpId);
        return ['success' => false, 'code' => 'TOO_MANY', 'message' => 'Too many incorrect attempts. Please request a new code.'];
    }
    $claim = supabase_request('otp_verifications', 'PATCH', ['attempts' => $n + 1],
        'otp_id=eq.' . $otpId . '&attempts=eq.' . $n . '&consumed_at=is.null');
    if ($claim['error'] || empty($claim['data'])) {
        return ['success' => false, 'message' => 'Please try again.'];
    }

    if (!hash_equals((string)$row['otp_hash'], phone_otp_hash($code, $account_id, $purpose, (string)$row['mobile_number']))) {
        if ($n + 1 >= $max) {
            phone_otp_consume($otpId);
            return ['success' => false, 'code' => 'TOO_MANY', 'message' => 'Incorrect code. Please request a new code.'];
        }
        $left = $max - $n - 1;
        return ['success' => false, 'code' => 'INCORRECT', 'message' => "Incorrect code. You have $left attempt" . ($left === 1 ? '' : 's') . ' left.'];
    }

    // A correct code issued for something else (e.g. a plain verification of
    // the current number) must not swap in the new number.
    if ($change !== null && !hash_equals((string)$change['bind'], phone_change_bind($code, (string)$change['number']))) {
        phone_otp_consume($otpId);
        return ['success' => false, 'message' => 'This code is no longer valid. Please request a new one.'];
    }

    // Single use: only the request that marks the row consumed may continue
    $use = supabase_request('otp_verifications', 'PATCH', ['verified_at' => gmdate('c'), 'consumed_at' => gmdate('c')],
        'otp_id=eq.' . $otpId . '&consumed_at=is.null');
    if ($use['error'] || empty($use['data'])) {
        return ['success' => false, 'message' => 'This code is no longer valid. Please request a new one.'];
    }

    // Only now is the destination marked verified
    $patch = ['phone_verified' => true, 'phone_otp_code' => null, 'phone_otp_expires_at' => null];
    if ($change !== null) $patch['contact_number'] = $change['number'];
    $verRes = supabase_request('patient_accounts', 'PATCH', $patch, 'account_id=eq.' . $account_id);
    if ($verRes['error'] || empty($verRes['data'])) {
        return ['success' => false, 'message' => 'We could not save the verification. Please request a new code.'];
    }

    if ($change !== null) {
        return ['success' => true, 'changed' => true, 'contact_number' => $change['number'],
            'patient_id' => $verRes['data'][0]['patient_id'] ?? null,
            'message' => 'Your mobile number has been changed and verified.'];
    }

    notif_account_event((int)$account_id, 'phone_verified', 'Phone number verified',
        'Your mobile number has been verified successfully.',
        'acct:phone_verified:' . $otpId);

    return ['success' => true, 'already_verified' => false, 'message' => 'Your phone number has been verified successfully!'];
}

/** Ties a change-number code to the number it was texted to. */
function phone_change_bind(string $code, string $number): string {
    return hash('sha256', $code . '|' . $number);
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

    // ─── CHANGE A VERIFIED NUMBER — 1. text a code to the NEW number ───
    // The verified number stays on file until the new one is confirmed.
    if ($action === 'send_change_otp') {
        $raw = preg_replace('/\D/', '', (string)($_POST['new_number'] ?? ''));
        if (strlen($raw) === 12 && strpos($raw, '63') === 0) $raw = substr($raw, 2);
        $err = pv_mobile_error('+63' . $raw, true);   // expects +639XXXXXXXXX
        if ($err !== null) {
            echo json_encode(['success' => false, 'code' => 'INVALID_NUMBER', 'message' => $err]);
            exit;
        }
        $new = '+63' . $raw;

        $accRes = supabase_request('patient_accounts', 'GET', [],
            'account_id=eq.' . $account_id . '&select=contact_number,phone_verified&limit=1');
        $acc = $accRes['data'][0] ?? null;
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Account not found.']);
            exit;
        }
        if (empty($acc['phone_verified'])) {
            echo json_encode(['success' => false, 'code' => 'NOT_VERIFIED',
                'message' => 'Your number is not verified yet, so you can edit it directly in your profile.']);
            exit;
        }
        if (substr(preg_replace('/\D/', '', (string)$acc['contact_number']), -10) === $raw) {
            echo json_encode(['success' => false, 'code' => 'SAME_NUMBER', 'message' => 'This is already your current number.']);
            exit;
        }

        $result = sendPhoneOtp($account_id, $new, function ($code) use ($new) {
            $_SESSION['portal_phone_change'] = ['number' => $new, 'bind' => phone_change_bind($code, $new), 'at' => time()];
        });
        echo json_encode($result);
        exit;
    }

    // ─── CHANGE A VERIFIED NUMBER — 2. confirm the code, then swap ───
    if ($action === 'verify_change_otp') {
        $pending = $_SESSION['portal_phone_change'] ?? null;
        if (!$pending || time() - (int)$pending['at'] > PHONE_OTP_TTL) {
            unset($_SESSION['portal_phone_change']);
            echo json_encode(['success' => false, 'message' => 'This code has expired. Please request a new one.']);
            exit;
        }
        $result = verifyPhoneOtp($account_id, trim($_POST['code'] ?? ''), $pending);
        if (!empty($result['changed'])) {
            unset($_SESSION['portal_phone_change']);
            // Keep the clinic-side patient record on the same number
            if (!empty($result['patient_id'])) {
                supabase_request('patients', 'PATCH', ['contact_number' => $result['contact_number']],
                    'patient_id=eq.' . (int)$result['patient_id']);
            }
            notif_account_event($account_id, 'phone_changed', 'Mobile number changed',
                'Your mobile number was changed to one ending in ' . substr($result['contact_number'], -4) .
                '. If you did not make this change, please contact the clinic.',
                'acct:phone_changed:' . $pending['bind']);
            unset($result['patient_id']);
        }
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
