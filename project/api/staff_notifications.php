<?php
// =============================================================
//  api/staff_notifications.php — Staff Notification Center (bell + page)
//
//  GET  ?action=summary                       → { unread, tasks[], ready }
//  GET  ?action=list&state=all|unread|read&category=&branch=&priority=&page=&per=
//                                             → { rows[], total, page, pages, unread }
//  POST {action:'read', ids:[…]}              → { unread }   Mark as Read
//  POST {action:'read_all', category?}        → { unread }   Mark All as Read
//
//  Owner / Admin / Dentist only. What a user may see (and mark) is decided
//  on the server by controllers/staff_notifications.php for every request:
//  role + sidebar modules + branch + audience; ids sent by the browser are
//  re-checked against that scope, never trusted.
// =============================================================

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/staff_notifications.php';

requireLogin();
requireStaffApi('owner', 'admin', 'dentist');

$viewer = staffn_viewer();   // reads the module list while the session is still open
if (!$viewer) apiDeny(403, 'You are not allowed to do this.');
session_write_close();       // read-only from here: don't block the user's other requests

function sn_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['success' => $code < 400] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

$ready  = staffn_ready();
$method = $_SERVER['REQUEST_METHOD'];
$input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
$action = (string)($_GET['action'] ?? $input['action'] ?? '');

// ── Summary (badge): unread count + inventory tasks ──────────
if ($method === 'GET' && $action === 'summary') {
    $tasks = [];
    try { $tasks = staffn_tasks(); } catch (Throwable $e) { error_log('staffn_tasks failed: ' . $e->getMessage()); }
    if ($ready) staffn_later(fn() => staffn_sweep());   // throttled stock / expiry check, after the response
    sn_out(['ready' => $ready, 'unread' => $ready ? staffn_unread_count($viewer) : 0, 'tasks' => $tasks]);
}

// ── List ──────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $state    = in_array($_GET['state'] ?? '', ['unread', 'read'], true) ? $_GET['state'] : 'all';
    $category = (string)($_GET['category'] ?? '');
    if ($category !== '' && !in_array($category, $viewer['cats'], true)) $category = '__none__';   // not allowed → nothing
    $priority = isset(STAFFN_PRIORITIES[$_GET['priority'] ?? '']) ? $_GET['priority'] : '';
    $branch   = $viewer['role'] === 'owner' ? max(0, (int)($_GET['branch'] ?? 0)) : 0;
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per      = max(1, min(50, (int)($_GET['per'] ?? 15)));
    if (!$ready) sn_out(['ready' => false, 'rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'unread' => 0]);

    $res = $category === '__none__' ? ['rows' => [], 'total' => 0] : staffn_list($viewer, $state, $category, $page, $per, $branch, $priority);
    if (!empty($res['error'])) sn_out(['message' => 'Notifications could not be loaded. Please try again.'], 502);
    sn_out([
        'ready'  => true,
        'rows'   => $res['rows'],
        'total'  => $res['total'],
        'page'   => $page,
        'pages'  => max(1, (int)ceil($res['total'] / $per)),
        'unread' => staffn_unread_count($viewer),
    ]);
}

// ── Mark as Read / Mark All as Read ──────────────────────────
if ($method === 'POST' && in_array($action, ['read', 'read_all'], true)) {
    if (!$ready) sn_out(['message' => 'The Notification Center is not set up yet.'], 503);
    if ($action === 'read') {
        $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];
        if (!$ids) sn_out(['message' => 'Nothing to mark.'], 400);
        staffn_mark_read($viewer, $ids);
    } else {
        $category = (string)($input['category'] ?? '');
        if ($category !== '' && !in_array($category, $viewer['cats'], true)) sn_out(['message' => 'You are not allowed to do this.'], 403);
        if (!staffn_mark_all($viewer, $category)) sn_out(['message' => 'Could not mark notifications as read. Please try again.'], 502);
    }
    sn_out(['unread' => staffn_unread_count($viewer)]);
}

sn_out(['message' => 'Unknown action.'], 400);
