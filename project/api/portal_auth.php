<?php
// api/portal_auth.php
session_start();
// Older builds kept the plaintext password in the session; purge it from any
// session still carrying one (it only ever existed for password logins).
if (isset($_SESSION['portal_plain_password'])) {
    $_SESSION['portal_auth_method'] ??= 'password';
    unset($_SESSION['portal_plain_password']);
}
$accountId     = $_SESSION['portal_account_id']     ?? null;
$cacheKey      = 'dash_cache_' . $accountId;
$dashCache     = $_SESSION[$cacheKey]                ?? null;

session_write_close();

header('Content-Type: application/json');

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../portal-session-guard.php';
require_once __DIR__ . '/../controllers/name_helper.php';   // name + address capitalisation
require_once __DIR__ . '/../controllers/notification_helper.php';
require_once __DIR__ . '/../controllers/appointment_services_helper.php';
require_once __DIR__ . '/../controllers/portal_loaders.php';   // parallel read loaders

$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Parent / guardian columns: GUARDIAN_COLS (controllers/portal_loaders.php)

/**
 * Called at login (session already open): drops values cached for whoever was
 * signed in before in this browser session, so a new login never inherits the
 * previous account's verification state, gender, patient link or dashboard.
 */
function portal_clear_account_cache(): void
{
    foreach (['portal_contact_ok', 'portal_gender', 'portal_patient_id', 'portal_patient_id_for'] as $k) unset($_SESSION[$k]);
    foreach (array_keys($_SESSION) as $k) {
        if (str_starts_with((string)$k, 'dash_cache_')) unset($_SESSION[$k]);
    }
}

// ── REGISTER ──────────────────────────────────────────────────────────────────
if ($action === 'register') {
    $email    = trim($input['email']    ?? '');
    $password =      $input['password'] ?? '';

    if (!$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'Required fields missing.']);
        exit;
    }

    // Password rules mirror the registration form; checked before anything is
    // created so a mismatch never produces an account. Values are never echoed.
    if (!is_string($password) || strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
        exit;
    }
    $confirmPassword = $input['confirm_password'] ?? null;
    if (!is_string($confirmPassword) || !hash_equals($password, $confirmPassword)) {
        echo json_encode([
            'success' => false,
            'code'    => 'PASSWORD_MISMATCH',
            'message' => 'Passwords do not match. Please re-enter your password and try again.',
        ]);
        exit;
    }

    // Terms and Conditions must be accepted — mirrors the checkbox required
    // on the registration form. Rejects the request if it's missing/false,
    // e.g. someone calling the API directly without going through the UI.
    $agreedTerms = $input['agreed_terms'] ?? false;
    if ($agreedTerms !== true) {
        echo json_encode([
            'success' => false,
            'code'    => 'TERMS_NOT_AGREED',
            'message' => 'You must agree to the Terms and Conditions to create an account.',
        ]);
        exit;
    }

    // Gender must be one of GENDER_OPTIONS (checked before the account is created)
    if (normalize_gender($input['gender'] ?? '') === null) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid gender.']);
        exit;
    }

    $check = supabase_request(
        'patient_accounts',
        'GET',
        [],
        'email=eq.' . urlencode($email) . '&select=account_id'
    );
    if (!empty($check['data'])) {
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit;
    }

    $rawContact    = trim($input['contact_number'] ?? '');
    $contactNumber = $rawContact ?: null;

    // Consistent name capitalisation (controllers/name_helper.php)
    $firstName  = normalize_person_name($input['first_name']  ?? '');
    $lastName   = normalize_person_name($input['last_name']   ?? '');
    $middleName = normalize_person_name($input['middle_name'] ?? '');
    $suffix     = normalize_suffix($input['suffix']           ?? '');

    $result = supabase_request('patient_accounts', 'POST', [
        'email'               => $email,
        'password_hash'       => password_hash($password, PASSWORD_DEFAULT),
        'first_name'          => $firstName,
        'last_name'           => $lastName,
        'middle_name'         => $middleName ?: null,
        'suffix'              => $suffix     ?: null,
        'birthdate'           => $input['birthdate']        ?? null,
        'contact_number'      => $contactNumber,
        'is_verified'         => false,
        'is_profile_complete' => false,
    ]);

    if ($result['status'] === 201 && !empty($result['data'][0]['account_id'])) {
        $accountId = (int)$result['data'][0]['account_id'];

        // $firstName, $lastName, $middleName, $suffix are already normalised above
        $birthdate   = $input['birthdate']         ?? null;
        $gender      = normalize_gender($input['gender'] ?? '') ?? '';
        $civilStatus = trim($input['civil_status'] ?? '');
        $nationality = trim($input['nationality']  ?? '');
        $homePhone   = trim($input['home_phone']   ?? '');
        // Consistent address capitalisation (controllers/name_helper.php)
        $address     = normalize_address_text($input['address']  ?? '');
        $barangay    = normalize_address_text($input['barangay'] ?? '');
        $city        = normalize_address_text($input['city']     ?? '');
        $province    = normalize_address_text($input['province'] ?? '');
        $zip         = trim($input['zip']          ?? '');

        $newContact = trim($input['contact_number'] ?? '');
        if ($newContact) {
            supabase_request(
                'patient_accounts',
                'PATCH',
                ['contact_number' => $newContact],
                'account_id=eq.' . $accountId
            );
        }

        $nameParts   = array_filter([$firstName, $middleName, $lastName, $suffix]);
        $fullName    = implode(' ', $nameParts);
        $cn          = !empty($rawContact) ? $rawContact : ('__PORTAL_' . $accountId . '__');
        $patientCode = 'PAT-P' . str_pad($accountId, 5, '0', STR_PAD_LEFT);

        $newPatient = supabase_request('patients', 'POST', [
            'patient_code'   => $patientCode,
            'full_name'      => $fullName,
            'contact_number' => $cn,
            'gender'         => $gender    ?: null,
            'birthdate'      => $birthdate ?: null,
            'status'         => 'active',
        ]);

        if (!empty($newPatient['data'][0]['patient_id'])) {
            $patientId = (int)$newPatient['data'][0]['patient_id'];

            supabase_request(
                'patient_accounts',
                'PATCH',
                ['patient_id' => $patientId],
                'account_id=eq.' . $accountId
            );

            supabase_request('patient_profiles', 'POST', [
                'patient_id'   => $patientId,
                'civil_status' => $civilStatus ?: null,
                'nationality'  => $nationality ?: null,
                'home_phone'   => $homePhone   ?: null,
                'address'      => $address     ?: null,
                'barangay'     => $barangay    ?: null,
                'city'         => $city        ?: null,
                'province'     => $province    ?: null,
                'zip'          => $zip         ?: null,
                'updated_at'   => date('c'),
            ]);
        }

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Registration failed.']);
    }
    exit;
}

// ── GOOGLE AUTH ───────────────────────────────────────────────────────────────
/**
 * SECURITY: verifies a Supabase Auth access token with Supabase itself
 * (GET /auth/v1/user) and returns the signed-in user, or null.
 * The email used for portal login comes ONLY from this verified user —
 * never from the request body (that allowed logging in as anyone).
 */
function portal_verified_google_user(string $accessToken): ?array
{
    if ($accessToken === '' || strlen($accessToken) > 4096) return null;
    $secrets = require __DIR__ . '/../config/secrets.php';
    $apikey  = (string)($secrets['supabase_publishable_key'] ?? '');
    if (!str_starts_with($apikey, 'sb_publishable_')) $apikey = SUPABASE_KEY;
    unset($secrets);

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init(SUPABASE_URL . '/auth/v1/user');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['apikey: ' . $apikey, 'Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
        ] + supabase_tls_options());
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno && $attempt === 0 && supabase_tls_fallback($errno)) continue;   // same TLS fallback as dbconfig.php
        break;
    }
    if ($code !== 200) return null;
    $user = json_decode((string)$body, true);
    if (!is_array($user) || empty($user['email']) || empty($user['id'])) return null;

    // Must be a Google identity (not e.g. an unconfirmed e-mail/password sign-up)
    $meta      = $user['app_metadata'] ?? [];
    $providers = array_merge([(string)($meta['provider'] ?? '')], (array)($meta['providers'] ?? []));
    if (!in_array('google', $providers, true)) return null;
    return $user;
}

if ($action === 'google_auth') {
    $gUser = portal_verified_google_user(trim((string)($input['access_token'] ?? '')));
    if (!$gUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Google sign-in could not be verified. Please try again.']);
        exit;
    }
    $email    = trim((string)$gUser['email']);   // verified by Supabase Auth, not taken from the request
    $fullName = trim((string)($gUser['user_metadata']['full_name'] ?? $gUser['user_metadata']['name'] ?? $input['full_name'] ?? ''));

    if (!$email) {
        echo json_encode(['success' => false, 'message' => 'No email returned from Google.']);
        exit;
    }

    $existing = supabase_request(
        'patient_accounts',
        'GET',
        [],
        'email=eq.' . urlencode($email) .
            '&select=account_id,first_name,last_name,is_profile_complete&limit=1'
    );

    if (!empty($existing['data'])) {
        $acc = $existing['data'][0];
        session_start();
        portal_clear_account_cache();
        $_SESSION['portal_account_id']       = $acc['account_id'];
        $_SESSION['portal_name']             = $acc['first_name'];
        $_SESSION['portal_profile_complete'] = $acc['is_profile_complete'];
        $_SESSION['portal_login_time']       = time();
        $_SESSION['portal_last_activity']    = time();
        $_SESSION['portal_auth_method']      = 'google';
        session_write_close();
        echo json_encode(['success' => true]);
        exit;
    }

    $nameParts = explode(' ', normalize_person_name($fullName), 2);
    $firstName = trim($nameParts[0] ?? '');
    $lastName  = trim($nameParts[1] ?? '');

    $result = supabase_request('patient_accounts', 'POST', [
        'email'               => $email,
        'password_hash'       => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'first_name'          => $firstName,
        'last_name'           => $lastName,
        'is_verified'         => true,
        'is_profile_complete' => false,
    ]);

    if ($result['status'] === 201 && !empty($result['data'][0]['account_id'])) {
        $accountId = (int)$result['data'][0]['account_id'];

        $patientCode = 'PAT-P' . str_pad($accountId, 5, '0', STR_PAD_LEFT);
        $cn          = '__PORTAL_' . $accountId . '__';
        $fullNameStr = trim("$firstName $lastName");

        $newPatient = supabase_request('patients', 'POST', [
            'patient_code'   => $patientCode,
            'full_name'      => $fullNameStr,
            'contact_number' => $cn,
            'status'         => 'active',
        ]);

        if (!empty($newPatient['data'][0]['patient_id'])) {
            $patientId = (int)$newPatient['data'][0]['patient_id'];
            supabase_request(
                'patient_accounts',
                'PATCH',
                ['patient_id' => $patientId],
                'account_id=eq.' . $accountId
            );
            supabase_request('patient_profiles', 'POST', [
                'patient_id' => $patientId,
                'updated_at' => date('c'),
            ]);
        }

        session_start();
        portal_clear_account_cache();
        $_SESSION['portal_account_id']       = $accountId;
        $_SESSION['portal_name']             = $firstName;
        $_SESSION['portal_profile_complete'] = false;
        $_SESSION['portal_login_time']       = time();
        $_SESSION['portal_last_activity']    = time();
        $_SESSION['portal_auth_method']      = 'google';
        session_write_close();

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Auto-registration failed.']);
    }
    exit;
}

// ── CHECK SESSION ─────────────────────────────────────────────────────────────
if ($action === 'check_session') {
    session_start();
    echo json_encode([
        // expired (idle / max duration) counts as logged out; does not refresh activity
        'logged_in' => isset($_SESSION['portal_account_id']) && portal_session_expired() === null,
    ]);
    exit;
}

// ── LOGIN ─────────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $email    = trim($input['email']    ?? '');
    $password =      $input['password'] ?? '';

    $result = supabase_request(
        'patient_accounts',
        'GET',
        [],
        'email=eq.' . urlencode($email) .
            '&select=account_id,password_hash,first_name,last_name,is_profile_complete'
    );

    if (empty($result['data'])) {
        echo json_encode(['success' => false, 'message' => 'No account found with this email.']);
        exit;
    }

    $acc = $result['data'][0];

    if (!password_verify($password, $acc['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
        exit;
    }

    session_start();
    portal_clear_account_cache();
    $_SESSION['portal_account_id']       = $acc['account_id'];
    $_SESSION['portal_name']             = $acc['first_name'];
    $_SESSION['portal_profile_complete'] = $acc['is_profile_complete'];
    $_SESSION['portal_auth_method']      = 'password';
    $_SESSION['portal_login_time']       = time();
    $_SESSION['portal_last_activity']    = time();
    session_write_close();

    echo json_encode(['success' => true]);
    exit;
}

// ── LOGOUT ────────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    session_start();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

// ── FORGOT PASSWORD (public — the user is logged out by definition) ───────────
// The new password is set on portal-reset-password.php via the emailed token.
if ($action === 'forgot_password') {
    require_once __DIR__ . '/../controllers/password_reset_controller.php';

    $email = trim((string)($input['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Enter a valid email address.']);
        exit;
    }

    // Throttle: 5 requests per 15 minutes per browser session
    session_start();
    $recent = array_filter($_SESSION['pw_reset_requests'] ?? [], fn($t) => $t > time() - 900);
    if (count($recent) >= 5) {
        session_write_close();
        echo json_encode(['success' => false, 'message' => 'Too many reset requests. Please wait 15 minutes and try again.']);
        exit;
    }
    $recent[] = time();
    $_SESSION['pw_reset_requests'] = $recent;
    session_write_close();

    // Same response whether or not the email has an account
    if (!requestPatientPasswordReset($email)) {
        echo json_encode(['success' => false, 'message' => 'We could not process your request right now. Please try again later.']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── AUTH GUARD — all actions below require a valid session ────────────────────
portal_require_login_api();
$accountId = $_SESSION['portal_account_id'];

// computeProfileComplete() lives in controllers/portal_loaders.php

// ── BOOTSTRAP — everything the dashboard shows on first load, in ONE request ─
// The loaders run concurrently: their queries share one HTTP/2 connection and
// go out in a few parallel waves instead of ~11 separate API calls (each with
// its own TLS handshake). The payload per key is identical to the single action.
if ($action === 'bootstrap') {
    $aid = (int)$accountId;
    getPatientId($aid);   // resolve + cache once, instead of once per loader
    echo json_encode(['success' => true] + portal_run([
        'dashboard_summary'   => pl_dashboard_summary($aid, $dashCache),
        'get_profile'         => pl_profile($aid),
        'get_treatments'      => pl_treatments($aid),
        'get_billing'         => pl_billing($aid),
        'get_dental_chart'    => pl_dental_chart($aid),
        'get_files'           => pl_files($aid),
        'get_waivers'         => pl_waivers($aid),
        'contact_status'      => pl_contact_status($aid),
        'check_questionnaire' => pl_check_questionnaire($aid),
        'appointments'        => pl_appointments($aid),
        'feedback_status'     => pl_feedback_status($aid),
    ]));
    exit;
}

// ── CHECK QUESTIONNAIRE STATUS ────────────────────────────────────────────────
if ($action === 'check_questionnaire') {
    echo json_encode(portal_run_one(pl_check_questionnaire((int)$accountId)));
    exit;
}

// ── SUBMIT QUESTIONNAIRE ──────────────────────────────────────────────────────
if ($action === 'submit_questionnaire') {
    $mqCheck = supabase_request(
        'medical_questionnaire',
        'GET',
        [],
        'account_id=eq.' . (int)$accountId . '&select=questionnaire_id&limit=1'
    );
    if (!empty($mqCheck['data'])) {
        echo json_encode(['success' => true, 'already_submitted' => true]);
        exit;
    }

    $linkRes   = supabase_request(
        'patient_accounts',
        'GET',
        [],
        'account_id=eq.' . (int)$accountId . '&select=patient_id&limit=1'
    );
    $patientId = $linkRes['data'][0]['patient_id'] ?? null;

    $boolField = function ($val) {
        if ($val === 'yes' || $val === true  || $val === 1) return true;
        if ($val === 'no'  || $val === false || $val === 0) return false;
        return null;
    };

    $payload = [
        'account_id'          => (int)$accountId,
        'patient_id'          => $patientId ? (int)$patientId : null,
        'q_good_health'       => $boolField($input['q_good_health']       ?? null),
        'q_under_treatment'   => $boolField($input['q_under_treatment']   ?? null),
        'treatment_condition' => $input['treatment_condition']             ?? null,
        'q_serious_illness'   => $boolField($input['q_serious_illness']   ?? null),
        'illness_detail'      => $input['illness_detail']                 ?? null,
        'q_hospitalized'      => $boolField($input['q_hospitalized']      ?? null),
        'hospitalized_detail' => $input['hospitalized_detail']            ?? null,
        'q_taking_meds'       => $boolField($input['q_taking_meds']       ?? null),
        'meds_detail'         => $input['meds_detail']                    ?? null,
        'q_tobacco'           => $boolField($input['q_tobacco']           ?? null),
        'q_dangerous_drugs'   => $boolField($input['q_dangerous_drugs']   ?? null),
        'q_pregnant'          => $boolField($input['q_pregnant']          ?? null),
        'q_nursing'           => $boolField($input['q_nursing']           ?? null),
        'q_birth_control'     => $boolField($input['q_birth_control']     ?? null),
        'completed_at'        => date('c'),
    ];

    $res = supabase_request('medical_questionnaire', 'POST', $payload);

    if ($res['status'] === 201 || !isset($res['error'])) {
        $accRes = supabase_request(
            'patient_accounts',
            'GET',
            [],
            'account_id=eq.' . (int)$accountId .
                '&select=first_name,last_name,birthdate,patient_id&limit=1'
        );
        $acc = $accRes['data'][0] ?? [];
        $pId = $acc['patient_id'] ?? null;

        $genderVal = '';
        if ($pId) {
            $patRes    = supabase_request(
                'patients',
                'GET',
                [],
                'patient_id=eq.' . $pId . '&select=gender&limit=1'
            );
            $genderVal = $patRes['data'][0]['gender'] ?? '';
        }
        $acc['gender'] = $genderVal;

        $profRow = [];
        if ($pId) {
            $prRes   = supabase_request(
                'patient_profiles',
                'GET',
                [],
                'patient_id=eq.' . $pId . '&select=address,city&limit=1'
            );
            $profRow = $prRes['data'][0] ?? [];
        }

        $accForCheck = array_merge($acc, $profRow);
        $isComplete  = computeProfileComplete($accForCheck, true);

        supabase_request(
            'patient_accounts',
            'PATCH',
            ['is_profile_complete' => $isComplete],
            'account_id=eq.' . (int)$accountId
        );

        session_start();
        unset($_SESSION['dash_cache_' . $accountId]);
        $_SESSION['portal_profile_complete'] = $isComplete;
        session_write_close();

        echo json_encode(['success' => true, 'is_profile_complete' => $isComplete]);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to save questionnaire.']);
    }
    exit;
}

// ── DASHBOARD SUMMARY (60 s per-session cache) ────────────────────────────────
if ($action === 'dashboard_summary') {
    echo json_encode(portal_run_one(pl_dashboard_summary((int)$accountId, $dashCache)));
    exit;
}

// ── CONTACT VERIFICATION STATUS ───────────────────────────────────────────────
// Polled by the dashboard so a verification done elsewhere (email link in
// another tab, phone OTP) shows up without a page reload. Always read fresh.
if ($action === 'contact_status') {
    echo json_encode(portal_run_one(pl_contact_status((int)$accountId)));
    exit;
}

// ── GET PROFILE ───────────────────────────────────────────────────────────────
if ($action === 'get_profile') {
    echo json_encode(portal_run_one(pl_profile((int)$accountId)));
    exit;
}

// ── UPDATE PROFILE ────────────────────────────────────────────────────────────
if ($action === 'update_profile') {
    // Consistent name capitalisation (controllers/name_helper.php)
    $firstName  = normalize_person_name($input['first_name']  ?? '');
    $lastName   = normalize_person_name($input['last_name']   ?? '');
    $middleName = normalize_person_name($input['middle_name'] ?? '');
    $suffix     = normalize_suffix($input['suffix']           ?? '');
    $birthdate  = trim($input['birthdate']   ?? '');
    // null = unrecognised value: keep whatever is already stored rather than overwrite it
    $gender     = normalize_gender($input['gender'] ?? '');

    if (!$firstName || !$lastName) {
        echo json_encode(['success' => false, 'message' => 'First and last name are required.']);
        exit;
    }

    // Parent / guardian (optional). Phone: +63 followed by a 10-digit mobile number.
    $optText = function (string $key, int $max) use ($input) {
        $v = trim((string)($input[$key] ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $max);
    };
    $guardianPhone = $optText('guardian_phone', 13);
    if ($guardianPhone !== null && !preg_match('/^\+639\d{9}$/', $guardianPhone)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid guardian mobile number (e.g. +63 9XX XXX XXXX).']);
        exit;
    }
    $guardianFields = [
        'guardian_name'     => ($gn = normalize_person_name($input['guardian_name'] ?? '')) === '' ? null : mb_substr($gn, 0, 150),
        'guardian_relation' => $optText('guardian_relation', 40),
        'guardian_phone'    => $guardianPhone,
        'guardian_address'  => ($ga = normalize_address_text($input['guardian_address'] ?? '')) === '' ? null : mb_substr($ga, 0, 255),
    ];

    $mqRes            = supabase_request(
        'medical_questionnaire',
        'GET',
        [],
        'account_id=eq.' . (int)$accountId . '&select=questionnaire_id&limit=1'
    );
    $hasQuestionnaire = !empty($mqRes['data']);

    $hasBasic   = !empty($firstName) && !empty($lastName) && !empty($birthdate) && !empty(trim($input['gender'] ?? ''));
    $hasContact = !empty(trim($input['address'] ?? '')) && !empty(trim($input['city'] ?? ''));

    $isProfileComplete = $hasBasic && $hasContact && $hasQuestionnaire;

$newContact = trim($input['contact_number'] ?? '');   // defined before use (was read too late)
$accountPatch = [
    'first_name'          => $firstName,
    'last_name'           => $lastName,
    'middle_name'         => $middleName ?: null,
    'suffix'              => $suffix     ?: null,
    'birthdate'           => $birthdate  ?: null,
    'is_profile_complete' => $isProfileComplete,
];
if (!empty($newContact)) {
    $accountPatch['contact_number'] = $newContact;

    // A verified phone only counts for the number that received the OTP:
    // a different number (compared by its last 10 digits) must be re-verified.
    $curRes  = supabase_request('patient_accounts', 'GET', [], 'account_id=eq.' . (int)$accountId . '&select=contact_number&limit=1');
    $digits  = fn($n) => substr(preg_replace('/\D/', '', (string)$n), -10);
    if ($digits($curRes['data'][0]['contact_number'] ?? '') !== $digits($newContact)) {
        $accountPatch['phone_verified']       = false;
        $accountPatch['phone_otp_code']       = null;
        $accountPatch['phone_otp_expires_at'] = null;
        session_start();
        unset($_SESSION['portal_contact_ok']);   // re-check the email-or-phone rule
        session_write_close();
    }
}
$accSave = supabase_request('patient_accounts', 'PATCH', $accountPatch, 'account_id=eq.' . $accountId);
if (($accSave['status'] ?? 0) === 0 || $accSave['status'] >= 400) {
    echo json_encode(['success' => false, 'message' => 'We could not save your profile. Please try again.']);
    exit;
}

    $linkRes       = supabase_request(
        'patient_accounts',
        'GET',
        [],
        'account_id=eq.' . $accountId . '&select=patient_id,contact_number&limit=1'
    );
    $patientId     = $linkRes['data'][0]['patient_id']     ?? null;
    $contactNumber = $linkRes['data'][0]['contact_number'] ?? null;

    if (!$patientId) {
        $nameParts   = array_filter([$firstName, $middleName, $lastName, $suffix]);
        $fullName    = implode(' ', $nameParts);
        $cn          = !empty($contactNumber) ? $contactNumber : ('__PORTAL_' . $accountId . '__');
        $patientCode = 'PAT-P' . str_pad($accountId, 5, '0', STR_PAD_LEFT);

        $newPatient = supabase_request('patients', 'POST', [
            'patient_code'   => $patientCode,
            'full_name'      => $fullName,
            'contact_number' => $cn,
            'gender'         => $gender    ?: null,
            'birthdate'      => $birthdate ?: null,
            'status'         => 'active',
        ]);

        if (!empty($newPatient['data'][0]['patient_id'])) {
            $patientId = (int)$newPatient['data'][0]['patient_id'];
            supabase_request(
                'patient_accounts',
                'PATCH',
                ['patient_id' => $patientId],
                'account_id=eq.' . $accountId
            );
        }
    } else {
        $nameParts    = array_filter([$firstName, $middleName, $lastName, $suffix]);
        $patientPatch = [
            'full_name' => implode(' ', $nameParts),
            'birthdate' => $birthdate ?: null,
        ];
        if ($gender !== null) {
            $patientPatch['gender'] = $gender ?: null;
        }
        if (!empty($newContact)) {
            $patientPatch['contact_number'] = $newContact;
        }
        supabase_request('patients', 'PATCH', $patientPatch, 'patient_id=eq.' . $patientId);
    }

    if ($patientId) {
        // Consistent address capitalisation (controllers/name_helper.php); empty → null
        $addr = function (string $key) use ($input) {
            $v = normalize_address_text($input[$key] ?? '');
            return $v === '' ? null : $v;
        };
        $profilePayload = [
            'civil_status'      => $input['civil_status']      ?? null,
            // Free value (legacy entries are preserved); trimmed, capped, empty → null
            'nationality'       => ($nat = trim((string)($input['nationality'] ?? ''))) === '' ? null : mb_substr($nat, 0, 60),
            'home_phone'        => $input['home_phone']        ?? null,
            'address'           => $addr('address'),
            'barangay'          => $addr('barangay'),
            'city'              => $addr('city'),
            'province'          => $addr('province'),
            'zip'               => $input['zip']               ?? null,
            'chief_complaint'   => $input['chief_complaint']   ?? null,
            'referral_source'   => $input['referral_source']   ?? null,
            'referred_by'       => $input['referred_by']       ?? null,
            'prev_dentist'      => $input['prev_dentist']      ?? null,
            'last_dental_visit' => $input['last_dental_visit'] ?: null,
            'dental_notes'      => $input['dental_notes']      ?? null,
            'allergies'         => json_encode($input['allergies']  ?? []),
            'other_allergies'   => $input['other_allergies']   ?? null,
            'allergy_reaction'  => $input['allergy_reaction']  ?? null,
            'conditions'        => json_encode($input['conditions'] ?? []),
            'other_conditions'  => $input['other_conditions']  ?? null,
            'updated_at'        => date('c'),
        ];

        $existCheck = supabase_request(
            'patient_profiles',
            'GET',
            [],
            'patient_id=eq.' . $patientId . '&select=profile_id&limit=1'
        );

        $saveProfileRow = function (array $payload) use ($existCheck, $patientId) {
            if (!empty($existCheck['data'])) {
                return supabase_request('patient_profiles', 'PATCH', $payload, 'patient_id=eq.' . $patientId);
            }
            $payload['patient_id'] = $patientId;
            return supabase_request('patient_profiles', 'POST', $payload);
        };
        $failed = fn($r) => ($r['status'] ?? 0) === 0 || $r['status'] >= 400;

        $profSave = $saveProfileRow($profilePayload + $guardianFields);
        // Guardian columns not added yet (database/2026-10-17_patient_guardian_fields.sql
        // not applied). Without guardian details, save everything else as before; with
        // them, fail loudly instead of reporting success while discarding the data.
        if ($failed($profSave) && stripos((string)($profSave['error'] ?? ''), 'guardian_') !== false) {
            error_log('update_profile: guardian columns missing on patient_profiles — run database/2026-10-17_patient_guardian_fields.sql');
            if (array_filter($guardianFields, fn($v) => $v !== null)) {
                echo json_encode(['success' => false, 'message' => 'Parent / guardian details cannot be saved yet. Please contact the clinic, or clear those fields to save the rest of your profile.']);
                exit;
            }
            $profSave = $saveProfileRow($profilePayload);
        }
        if ($failed($profSave)) {
            echo json_encode(['success' => false, 'message' => 'We could not save your profile details. Please try again.']);
            exit;
        }
    }

    if ($patientId) {
        $mqCheck = supabase_request(
            'medical_questionnaire',
            'GET',
            [],
            'account_id=eq.' . (int)$accountId . '&patient_id=is.null&select=questionnaire_id&limit=1'
        );
        if (!empty($mqCheck['data'])) {
            supabase_request(
                'medical_questionnaire',
                'PATCH',
                ['patient_id' => (int)$patientId],
                'account_id=eq.' . (int)$accountId
            );
        }
    }

    // The session was released by portal_require_login_api(), so reopen it to make
    // these stick (before, the cleared dashboard cache came back for up to 60 s)
    portal_session_set([
        'portal_profile_complete' => $isProfileComplete,
        'portal_name'             => $firstName,
    ], ['dash_cache_' . $accountId]);

    echo json_encode(['success' => true, 'is_profile_complete' => $isProfileComplete]);
    exit;
}

// ── CHANGE PASSWORD ───────────────────────────────────────────────────────────
// Requires the current password. Passwords are never echoed or logged.
if ($action === 'change_password') {
    $current = $input['current_password'] ?? '';
    $new     = $input['new_password']     ?? '';
    $confirm = $input['confirm_password'] ?? '';
    if (!is_string($current) || !is_string($new) || !is_string($confirm) || $current === '') {
        echo json_encode(['success' => false, 'field' => 'current', 'message' => 'Please enter your current password.']);
        exit;
    }

    // Brute-force guard: 5 wrong current passwords in 15 minutes locks this form
    session_start();
    $fails = array_filter($_SESSION['pw_change_fails'] ?? [], fn($t) => $t > time() - 900);
    $_SESSION['pw_change_fails'] = $fails;
    session_write_close();
    if (count($fails) >= 5) {
        echo json_encode(['success' => false, 'message' => 'Too many incorrect attempts. Please wait 15 minutes and try again.']);
        exit;
    }

    if (strlen($new) < 8) {
        echo json_encode(['success' => false, 'field' => 'new', 'message' => 'New password must be at least 8 characters.']);
        exit;
    }
    if (!hash_equals($new, $confirm)) {
        echo json_encode(['success' => false, 'field' => 'confirm', 'message' => 'Passwords do not match. Please re-enter your new password.']);
        exit;
    }
    if (hash_equals($current, $new)) {
        echo json_encode(['success' => false, 'field' => 'new', 'message' => 'Your new password must be different from your current password.']);
        exit;
    }

    $accRes = supabase_request(
        'patient_accounts',
        'GET',
        [],
        'account_id=eq.' . (int)$accountId . '&select=password_hash&limit=1'
    );
    $hash = $accRes['data'][0]['password_hash'] ?? '';
    if (!$hash || !password_verify($current, $hash)) {
        session_start();
        $_SESSION['pw_change_fails'][] = time();
        session_write_close();
        echo json_encode(['success' => false, 'field' => 'current', 'message' => 'Your current password is incorrect.']);
        exit;
    }

    $save = supabase_request(
        'patient_accounts',
        'PATCH',
        ['password_hash' => password_hash($new, PASSWORD_DEFAULT)],
        'account_id=eq.' . (int)$accountId
    );
    if (($save['status'] ?? 0) === 0 || $save['status'] >= 400) {
        echo json_encode(['success' => false, 'message' => 'We could not update your password. Please try again.']);
        exit;
    }

    session_start();
    session_regenerate_id(true);
    unset($_SESSION['pw_change_fails']);
    session_write_close();

    notif_account_event(
        (int)$accountId,
        'password_changed',
        'Password changed',
        "Your portal password was changed. If this wasn't you, please contact the clinic right away.",
        'acct:password_changed:' . time()
    );

    echo json_encode(['success' => true]);
    exit;
}

// ── CANCEL APPOINTMENT ────────────────────────────────────────────────────────
if ($action === 'cancel_appointment') {
    $apptId    = (int)($input['appointment_id'] ?? 0);
    $patientId = getPatientId($accountId);

    if (!$apptId || !$patientId) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $chk = supabase_request(
        'appointments',
        'GET',
        [],
        'appointment_id=eq.' . $apptId .
            '&patient_id=eq.'    . $patientId .
            '&status=eq.pending&select=appointment_id&limit=1'
    );

    if (empty($chk['data'])) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found or cannot be cancelled.']);
        exit;
    }

    $cancelRes = supabase_request(
        'appointments',
        'PATCH',
        ['status' => 'cancelled', 'updated_at' => date('c')],   // updated_at = cancellation time
        'appointment_id=eq.' . $apptId
    );

    if (!empty($cancelRes['data'][0])) {
        notif_appointment($cancelRes['data'][0], ['status' => 'pending'] + $cancelRes['data'][0]);
    }

    // The upcoming / completed counts changed: drop the 60 s dashboard cache
    portal_session_set([], ['dash_cache_' . $accountId]);

    echo json_encode(['success' => true]);
    exit;
}

// ── GET TREATMENTS / DENTAL CHART / BILLING ───────────────────────────────────
// Loaders live in controllers/portal_loaders.php (same queries, run in parallel waves).
// Billing and treatment extras feed the View Details / Treatment History modals;
// the dental chart is read-only here (written by the admin api/dental_chart.php).
if ($action === 'get_treatments') {
    echo json_encode(portal_run_one(pl_treatments((int)$accountId)));
    exit;
}

if ($action === 'get_dental_chart') {
    echo json_encode(portal_run_one(pl_dental_chart((int)$accountId)));
    exit;
}

if ($action === 'get_billing') {
    echo json_encode(portal_run_one(pl_billing((int)$accountId)));
    exit;
}

// ── GET FILES ─────────────────────────────────────────────────────────────────
if ($action === 'get_files') {
    echo json_encode(portal_run_one(pl_files((int)$accountId)));
    exit;
}

// ── GET WAIVERS ───────────────────────────────────────────────────────────────
// Waivers are uploaded by the clinic (admin → Patient Record → Waivers tab)
// and signed here by the patient.
if ($action === 'get_waivers') {
    echo json_encode(portal_run_one(pl_waivers((int)$accountId)));
    exit;
}

if ($action === 'sign_waiver') {
    $patientId = getPatientId($accountId);
    $waiverId  = (int)($input['waiver_id'] ?? 0);
    $signedBy  = trim(preg_replace('/\s+/', ' ', (string)($input['signed_by'] ?? '')));

    if (!$patientId || !$waiverId) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
        exit;
    }
    // Explicit consent is required — mirrors the checkbox in the Sign Waiver dialog
    if (($input['consent'] ?? false) !== true) {
        echo json_encode(['success' => false, 'code' => 'CONSENT_REQUIRED', 'message' => 'Please confirm that you have read and agree to this waiver.']);
        exit;
    }
    if ($signedBy === '' || mb_strlen($signedBy) > 100) {
        echo json_encode(['success' => false, 'message' => 'Please type your full name (up to 100 characters).']);
        exit;
    }

    // The waiver must belong to this patient and still be unsigned
    $chk = supabase_request(
        'waivers',
        'GET',
        [],
        'waiver_id=eq.' . $waiverId . '&patient_id=eq.' . $patientId . '&select=waiver_id,status&limit=1'
    );
    $row = $chk['data'][0] ?? null;
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Waiver not found.']);
        exit;
    }
    if (strcasecmp((string)($row['status'] ?? ''), 'Signed') === 0) {
        echo json_encode(['success' => false, 'message' => 'This waiver has already been signed.']);
        exit;
    }

    $res = supabase_request(
        'waivers',
        'PATCH',
        ['status' => 'Signed', 'signed_at' => date('c'), 'signed_by' => $signedBy],
        'waiver_id=eq.' . $waiverId . '&patient_id=eq.' . $patientId
    );
    if (!empty($res['error'])) {
        echo json_encode(['success' => false, 'message' => 'Could not save your signature. Please try again.']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Waiver signed.']);
    exit;
}

// ── GET MEDICAL RECORD ────────────────────────────────────────────────────────
// Fetches vital signs, appointment info, and medical profile for a single visit.
// Called from the Treatment History page when the patient clicks "View" on a row.
if ($action === 'get_medical_record') {

    $appointmentId = (int)($input['appointment_id'] ?? 0);
    if (!$appointmentId) {
        echo json_encode(['success' => false, 'message' => 'Invalid appointment ID.']);
        exit;
    }

    $patientId = getPatientId((int)$accountId);
    if (!$patientId) {
        echo json_encode(['success' => false, 'message' => 'Patient account not linked.']);
        exit;
    }

    // 1. Verify the appointment belongs to this patient (security gate)
    $aptRes = supabase_request(
        'appointments',
        'GET',
        [],
        'appointment_id=eq.' . $appointmentId .
        '&patient_id=eq.'    . $patientId .
        '&select=appointment_id,appointment_code,appointment_date,' .
        'appointment_time,notes,status,payment_status,' .
        'branches(branch_name),services(service_name)' .
        '&limit=1'
    );
    $appointment = $aptRes['data'][0] ?? null;

    if (!$appointment) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found or access denied.']);
        exit;
    }
    $appointment = attachAppointmentServiceNames([$appointment])[0];   // all 1–3 services

    // 2. Fetch vital signs for this appointment (most recent record)
    $vitalsRes = supabase_request(
        'vital_signs',
        'GET',
        [],
        'appointment_id=eq.' . $appointmentId .
        '&select=blood_pressure,pulse_rate,temperature,checked_by,checked_at,notes' .
        '&order=checked_at.desc' .
        '&limit=1'
    );
    $vitals = $vitalsRes['data'][0] ?? null;

    // 3. Fetch medical profile (allergies + conditions) from patient_profiles
    $profileRes = supabase_request(
        'patient_profiles',
        'GET',
        [],
        'patient_id=eq.' . $patientId .
        '&select=allergies,other_allergies,allergy_reaction,conditions,other_conditions' .
        '&limit=1'
    );
    $profile = $profileRes['data'][0] ?? null;

    // Decode JSON arrays stored as strings in Supabase
    if ($profile) {
        $toArr = function ($val) {
            if (is_array($val))   return $val;
            if (is_string($val) && $val !== '') {
                $decoded = json_decode($val, true);
                return is_array($decoded) ? $decoded : [];
            }
            return [];
        };
        $profile['allergies']  = $toArr($profile['allergies']  ?? []);
        $profile['conditions'] = $toArr($profile['conditions'] ?? []);
    }

    echo json_encode([
        'success'     => true,
        'appointment' => $appointment,
        'vitals'      => $vitals,
        'profile'     => $profile,
    ]);
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Invalid action.']);


// ── Helper: resolve patient_id from account_id (session-cached, see pl_patient_id) ──
function getPatientId(int $accountId): ?int
{
    return portal_run_one(pl_patient_id($accountId));
}
