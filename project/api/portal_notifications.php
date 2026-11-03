<?php
// =============================================================
//  api/portal_notifications.php — patient notification bell
//
//  GET  ?action=list[&offset=N] → 20 per page, newest first + unread count
//  POST {action:'mark_read', notification_id}
//  POST {action:'mark_all_read'}
//
//  Scoping: every query is filtered by the logged-in portal account
//  from the server session — never by an id sent from the browser.
// =============================================================

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../portal-session-guard.php';
require_once __DIR__ . '/../controllers/notification_helper.php';

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

// Background polling (list) must not extend the idle timeout, so only
// explicit user actions refresh portal_last_activity.
if ($action === 'list') {
    if (portal_session_expired() !== null || !isset($_SESSION['portal_account_id'])) {
        session_write_close();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
        exit;
    }
    session_write_close();
} else {
    portal_require_login_api();
}

$accountId = (int)$_SESSION['portal_account_id'];

// ── LIST ─────────────────────────────────────────────────────
if ($action === 'list') {
    $pRes      = supabase_request('patient_accounts', 'GET', [],
        'account_id=eq.' . $accountId . '&select=patient_id&limit=1');
    $patientId = isset($pRes['data'][0]['patient_id']) ? (int)$pRes['data'][0]['patient_id'] : null;

    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit  = 20;

    // Time-based notifications are created lazily here (no cron needed);
    // dedupe keys make repeated calls harmless.
    if ($offset === 0) {
        notif_generate_reminders($accountId, $patientId);
        notif_sync_announcements($accountId, $patientId);
    }

    $res = supabase_request(NOTIF_TABLE, 'GET', [],
        'account_id=eq.' . $accountId .
        '&select=notification_id,category,event_type,title,message,link_page,is_read,created_at' .
        '&order=created_at.desc,notification_id.desc&limit=' . ($limit + 1) . '&offset=' . $offset);

    if ($res['status'] < 200 || $res['status'] >= 300) {
        echo json_encode(['success' => false, 'message' => 'Notifications are unavailable right now.']);
        exit;
    }

    $cnt = supabase_request(NOTIF_TABLE, 'GET', [],
        'account_id=eq.' . $accountId . '&is_read=eq.false&select=notification_id&limit=1',
        ['Prefer: count=exact']);
    $cr     = $cnt['headers']['content-range'] ?? '';
    $unread = str_contains($cr, '/') ? (int)explode('/', $cr)[1] : 0;

    echo json_encode([
        'success'       => true,
        'notifications' => array_slice($res['data'] ?? [], 0, $limit),
        'has_more'      => count($res['data'] ?? []) > $limit,
        'unread'        => $unread,
    ]);
    exit;
}

// ── MARK ONE READ ────────────────────────────────────────────
if ($action === 'mark_read') {
    $id = (int)($input['notification_id'] ?? 0);
    if ($id < 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid notification.']);
        exit;
    }
    // account_id filter ensures a patient can only touch their own rows
    $res = supabase_request(NOTIF_TABLE, 'PATCH',
        ['is_read' => true, 'read_at' => date('c')],
        'notification_id=eq.' . $id . '&account_id=eq.' . $accountId . '&is_read=eq.false');
    echo json_encode(['success' => $res['status'] >= 200 && $res['status'] < 300]);
    exit;
}

// ── MARK ALL READ ────────────────────────────────────────────
if ($action === 'mark_all_read') {
    $res = supabase_request(NOTIF_TABLE, 'PATCH',
        ['is_read' => true, 'read_at' => date('c')],
        'account_id=eq.' . $accountId . '&is_read=eq.false');
    echo json_encode(['success' => $res['status'] >= 200 && $res['status'] < 300]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
