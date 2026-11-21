<?php
// =============================================================
//  api/portal_notification_settings.php — patient notification settings
//
//  GET                                       → preferences, push setup, this device
//  POST {action:'save_prefs', email, sms, push}
//  POST {action:'subscribe', subscription:{endpoint, keys:{p256dh, auth}}}
//  POST {action:'unsubscribe', endpoint}
//
//  Scoping: always the logged-in portal account from the server session —
//  never an id sent by the browser. Only the VAPID PUBLIC key is returned.
// =============================================================

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../portal-session-guard.php';
require_once __DIR__ . '/../controllers/webpush.php';

portal_require_login_api();
$accountId = (int)$_SESSION['portal_account_id'];
session_write_close();

$fail = function (int $http, string $msg, array $extra = []) {
    http_response_code($http);
    echo json_encode(['success' => false, 'message' => $msg] + $extra);
    exit;
};

/** Current preferences (defaults when no row yet). null = table missing. */
function nps_prefs(int $accountId): ?array
{
    $r = supabase_request('notification_preferences', 'GET', [], 'account_id=eq.' . $accountId . '&select=email_enabled,sms_enabled,push_enabled&limit=1');
    if ($r['error'] !== null || $r['status'] !== 200) return null;
    $row = $r['data'][0] ?? null;
    return ['email' => $row ? (bool)$row['email_enabled'] : true, 'sms' => $row ? (bool)$row['sms_enabled'] : true, 'push' => $row ? (bool)$row['push_enabled'] : false];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $prefs = nps_prefs($accountId);
    if ($prefs === null) $fail(503, 'Notification settings are not available yet.', ['code' => 'NOT_SET_UP']);
    $acc = supabase_request('patient_accounts', 'GET', [], 'account_id=eq.' . $accountId . '&select=email,is_verified,contact_number,phone_verified&limit=1');
    $a = $acc['data'][0] ?? [];
    $subs = supabase_request('push_subscriptions', 'GET', [], 'account_id=eq.' . $accountId . '&select=endpoint');
    $cfg = webpush_config();
    echo json_encode(['success' => true, 'data' => [
        'prefs'          => $prefs,
        'email_ready'    => !empty($a['is_verified']) && filter_var((string)($a['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false,
        'phone_ready'    => !empty($a['phone_verified']) || preg_match('/^(\+?63|0)?9\d{9}$/', preg_replace('/\D/', '', (string)($a['contact_number'] ?? ''))) === 1,
        'push_available' => $cfg !== null,
        'vapid_public'   => $cfg['public'] ?? null,
        'endpoints'      => array_column($subs['data'] ?? [], 'endpoint'),   // to tell whether THIS device is registered
    ]]);
    exit;
}

if ($method !== 'POST') $fail(405, 'Method not allowed.');
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $fail(400, 'Invalid request.');
$action = (string)($in['action'] ?? '');

if ($action === 'save_prefs') {
    $row = ['account_id' => $accountId, 'updated_at' => gmdate('c')];
    foreach (['email', 'sms', 'push'] as $ch) {
        if (array_key_exists($ch, $in)) $row[$ch . '_enabled'] = filter_var($in[$ch], FILTER_VALIDATE_BOOLEAN);
    }
    $r = supabase_request('notification_preferences', 'POST', $row, 'on_conflict=account_id', ['Prefer: resolution=merge-duplicates']);
    if ($r['error'] !== null || $r['status'] >= 300) $fail(500, 'Your settings could not be saved. Please try again.');
    echo json_encode(['success' => true, 'message' => 'Notification settings saved.', 'prefs' => nps_prefs($accountId)]);
    exit;
}

if ($action === 'subscribe') {
    $sub = $in['subscription'] ?? null;
    $endpoint = (string)($sub['endpoint'] ?? '');
    $p256dh = (string)($sub['keys']['p256dh'] ?? '');
    $auth = (string)($sub['keys']['auth'] ?? '');
    // Push services use https endpoints; keys must be a P-256 point and a 16-byte secret
    if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?/#i', $endpoint) || strlen($endpoint) > 1024
        || strlen(webpush_b64u_dec($p256dh)) !== 65 || strlen(webpush_b64u_dec($auth)) < 16) {
        $fail(400, 'This browser returned an invalid push subscription.');
    }
    $count = supabase_request('push_subscriptions', 'GET', [], 'account_id=eq.' . $accountId . '&select=subscription_id');
    if (count($count['data'] ?? []) >= 10) $fail(400, 'Too many devices are registered. Turn push off on a device you no longer use.');
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    // One endpoint = one device; re-subscribing moves it to this account
    $r = supabase_request('push_subscriptions', 'POST', ['account_id' => $accountId, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth,
        'user_agent' => $ua, 'failure_count' => 0, 'updated_at' => gmdate('c')], 'on_conflict=endpoint', ['Prefer: resolution=merge-duplicates']);
    if ($r['error'] !== null || $r['status'] >= 300) $fail(500, 'This device could not be registered. Please try again.');
    supabase_request('notification_preferences', 'POST', ['account_id' => $accountId, 'push_enabled' => true, 'updated_at' => gmdate('c')],
        'on_conflict=account_id', ['Prefer: resolution=merge-duplicates']);
    echo json_encode(['success' => true, 'message' => 'Push notifications are on for this device.']);
    exit;
}

if ($action === 'unsubscribe') {
    $endpoint = (string)($in['endpoint'] ?? '');
    if ($endpoint !== '') {
        supabase_request('push_subscriptions', 'DELETE', [], 'account_id=eq.' . $accountId . '&endpoint=eq.' . rawurlencode($endpoint));
    }
    $left = supabase_request('push_subscriptions', 'GET', [], 'account_id=eq.' . $accountId . '&select=subscription_id&limit=1');
    if (empty($left['data'])) {
        supabase_request('notification_preferences', 'POST', ['account_id' => $accountId, 'push_enabled' => false, 'updated_at' => gmdate('c')],
            'on_conflict=account_id', ['Prefer: resolution=merge-duplicates']);
    }
    echo json_encode(['success' => true, 'message' => 'Push notifications are off for this device.']);
    exit;
}

$fail(400, 'Unknown action.');
