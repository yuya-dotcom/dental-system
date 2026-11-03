<?php
// portal-session-guard.php
// Shared session validation for the patient portal — pages and the auth API.
// Must be required AFTER session_start().

define('PORTAL_IDLE_TIMEOUT', 60 * 60);
define('PORTAL_ABSOLUTE_TIMEOUT', 8 * 60 * 60);

function portal_session_expired(): ?string
{
    if (!isset($_SESSION['portal_account_id'])) {
        return null; // not logged in — nothing to expire
    }

    $now = time();

    if (
        isset($_SESSION['portal_login_time']) &&
        ($now - $_SESSION['portal_login_time']) > PORTAL_ABSOLUTE_TIMEOUT
    ) {
        return 'max_duration';
    }

    if (
        isset($_SESSION['portal_last_activity']) &&
        ($now - $_SESSION['portal_last_activity']) > PORTAL_IDLE_TIMEOUT
    ) {
        return 'inactivity';
    }

    return null;
}

function portal_destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

/** Use at the top of protected PAGES (after session_start()). */
function portal_require_login(string $loginUrl = 'portal-login.php'): void
{
    $reason = portal_session_expired();
    if ($reason !== null) {
        portal_destroy_session();
        header('Location: ' . $loginUrl . '?expired=' . $reason);
        exit;
    }
    if (!isset($_SESSION['portal_account_id'])) {
        header('Location: ' . $loginUrl);
        exit;
    }
    $_SESSION['portal_last_activity'] = time();
}

/**
 * Registration rule: at least ONE verified contact method — email
 * (patient_accounts.is_verified) OR phone (patient_accounts.phone_verified).
 * Read from the database, never from the client. A satisfied result is cached
 * in the session; update_profile clears it when the phone number changes.
 * Returns ['ok' => bool, 'email' => bool, 'phone' => bool].
 */
function portal_contact_status(int $accountId): array
{
    // $_SESSION stays readable after session_write_close()
    if (!empty($_SESSION['portal_contact_ok'])) {
        return ['ok' => true, 'email' => null, 'phone' => null];
    }

    require_once __DIR__ . '/dbconfig.php';
    $res = supabase_request('patient_accounts', 'GET', [],
        'account_id=eq.' . $accountId . '&select=is_verified,phone_verified&limit=1');
    $row   = $res['data'][0] ?? [];
    $email = !empty($row['is_verified']);
    $phone = !empty($row['phone_verified']);
    $ok    = $email || $phone;

    if ($ok) {
        $wasActive = session_status() === PHP_SESSION_ACTIVE;
        if (!$wasActive) session_start();
        $_SESSION['portal_contact_ok'] = true;
        if (!$wasActive) session_write_close();
    }
    return ['ok' => $ok, 'email' => $email, 'phone' => $phone];
}

/** API counterpart: 403 CONTACT_UNVERIFIED until one contact method is verified. */
function portal_require_contact_verified_api(int $accountId): void
{
    if (!portal_contact_status($accountId)['ok']) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'code'    => 'CONTACT_UNVERIFIED',
            'message' => 'Please verify your email address or your phone number to continue. Verifying either one is enough.',
        ]);
        exit;
    }
}

/** Use at the top of protected API actions (after session_start()). */
function portal_require_login_api(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $reason = portal_session_expired();
    if ($reason !== null) {
        portal_destroy_session();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Session expired.', 'code' => 'SESSION_EXPIRED']);
        exit;
    }
    if (!isset($_SESSION['portal_account_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
        exit;
    }
    $_SESSION['portal_last_activity'] = time();
    session_write_close();
}
