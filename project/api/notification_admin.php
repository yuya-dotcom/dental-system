<?php
// =============================================================
//  api/notification_admin.php — Notifications page actions (staff)
//
//  POST JSON {action, ...}
//    retry              {log_id}            owner / admin (own branch)  — failed rows only
//    refresh_status     {log_id}            owner / admin (own branch)  — SMS delivery status from UniSMS
//    save_template      {template_id, subject, message_body, is_active}   owner
//    reset_template     {template_id}                                      owner
//    create_announcement {title, message, branch_id, ends_at, push}       owner
//    end_announcement   {announcement_id}                                  owner
//
//  Everything goes through controllers/notification_service.php; provider
//  keys never leave the server. Each change is written to the audit trail.
// =============================================================

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/audit_controller.php';
require_once __DIR__ . '/../controllers/notification_service.php';

requireLogin();
requireStaffApi('owner', 'admin');

// GET ?log_id= → one log row for the details modal (branch-scoped)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
    $id = (int)($_GET['log_id'] ?? 0);
    if ($id < 1) apiDeny(400, 'Notification ID required.');
    $r = supabase_request('notification_logs', 'GET', [], 'log_id=eq.' . $id . '&limit=1&select=log_id,notification_type,channel,recipient,subject,message,status,attempts,'
        . 'provider_reference_id,provider_status,status_checked_at,failure_reason,sent_at,created_at,updated_at,branch_id,appointment_id,'
        . 'patients(full_name),appointments(appointment_code,appointment_date,appointment_time,status),branches(branch_name)');
    $row = $r['data'][0] ?? null;
    if (!$row || isset($r['data']['message'])) apiDeny(404, 'Notification not found.');
    requireBranchAccess($row['branch_id'] ?? null);
    echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiDeny(405, 'Method not allowed.');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) apiDeny(400, 'Invalid request.');
$action  = (string)($in['action'] ?? '');
$isOwner = ($_SESSION['role'] ?? '') === 'owner';

$ok   = function (string $msg, array $extra = []) { echo json_encode(['success' => true, 'message' => $msg] + $extra); exit; };
$fail = function (string $msg, int $http = 400) { http_response_code($http); echo json_encode(['success' => false, 'message' => $msg]); exit; };

if (!notify_logs_available()) $fail('The notification tables are not set up yet. Run database/proposed/2026-11-13_notification_system.sql first.', 503);

/** One log row the current user may act on (branch-scoped for admins). */
function na_log_row(int $logId): array
{
    if ($logId < 1) apiDeny(400, 'Notification ID required.');
    $r = supabase_request(NOTIFY_LOG_TABLE, 'GET', [], 'log_id=eq.' . $logId . '&select=log_id,branch_id,channel,status,notification_type,provider_reference_id,recipient&limit=1');
    $row = $r['data'][0] ?? null;
    if (!$row) apiDeny(404, 'Notification not found.');
    requireBranchAccess($row['branch_id'] ?? null);   // rows without a branch (e.g. OTP) are owner-only
    return $row;
}

function na_types(): array
{
    return ['appointment_confirmed' => 'Appointment Confirmed', 'appointment_cancelled' => 'Appointment Cancelled',
            'appointment_rescheduled' => 'Appointment Rescheduled', 'appointment_reminder' => 'Appointment Reminder',
            'announcement' => 'Announcement'];
}

switch ($action) {

case 'retry': {
    $row = na_log_row((int)($in['log_id'] ?? 0));
    if ($row['status'] !== 'failed') $fail('Only failed notifications can be retried.', 409);   // no audit row for a refused click
    $res = notify_retry((int)$row['log_id']);
    logActivity('notifications', 'UPDATE', (int)$row['log_id'], strtoupper($row['channel']) . ' ' . $row['notification_type'],
        'Retried a failed ' . $row['channel'] . ' notification: ' . ($res['ok'] ? 'sent' : $res['message']),
        isset($row['branch_id']) ? (int)$row['branch_id'] : null);
    $res['ok'] ? $ok($res['message']) : $fail($res['message'], 409);
}

case 'refresh_status': {
    $row = na_log_row((int)($in['log_id'] ?? 0));
    if ($row['channel'] !== 'sms' || $row['status'] !== 'sent' || empty($row['provider_reference_id'])) {
        $fail('Delivery status can only be checked for sent SMS messages with a UniSMS reference.');
    }
    $s = notify_sms_refresh_status($row);
    if ($s === null) $fail('UniSMS did not return a status right now. Please try again later.', 502);
    $label = ['pending' => 'queued at the carrier', 'retrying' => 'being retried by the carrier', 'sent' => 'delivered to the carrier network', 'failed' => 'not delivered (marked failed)'][$s];
    $ok('UniSMS status: ' . $label . '.', ['provider_status' => $s]);
}

case 'save_template':
case 'reset_template': {
    if (!$isOwner) apiDeny(403, 'Only the owner can edit notification templates.');
    $id = (int)($in['template_id'] ?? 0);
    $r  = supabase_request('notification_templates', 'GET', [], 'template_id=eq.' . $id . '&select=*&limit=1');
    $tpl = $r['data'][0] ?? null;
    if (!$tpl) $fail('Template not found.', 404);
    $type = (string)$tpl['notification_type']; $ch = (string)$tpl['channel'];

    if ($action === 'reset_template') {
        $def = notify_default_templates()[$type][$ch] ?? null;
        if (!$def) $fail('There is no built-in default for this template.');
        $patch = ['subject' => $def[0], 'message_body' => $def[1], 'is_active' => true];
    } else {
        $subject = trim((string)($in['subject'] ?? ''));
        $body    = trim(preg_replace("/\r\n?/", "\n", (string)($in['message_body'] ?? '')));
        $active  = filter_var($in['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($body === '') $fail('The message cannot be empty.');
        $limits = ['sms' => [0, 300], 'push' => [80, 240], 'email' => [150, 1500]][$ch] ?? [150, 1500];
        if ($ch !== 'sms' && $subject === '') $fail($ch === 'push' ? 'The push title cannot be empty.' : 'The email subject cannot be empty.');
        if ($ch === 'sms') $subject = '';
        if (mb_strlen($subject) > $limits[0] && $ch !== 'sms') $fail('The ' . ($ch === 'push' ? 'title' : 'subject') . ' can be at most ' . $limits[0] . ' characters.');
        if (mb_strlen($body) > $limits[1]) $fail('The message can be at most ' . $limits[1] . ' characters.');
        // Only known placeholders (they carry no clinical information)
        $allowed = $type === 'announcement' ? ['title', 'message']
                 : ['first_name', 'date', 'time', 'branch', 'branch_phone', 'code', 'old_date', 'old_time', 'reason_line'];
        preg_match_all('/\{([a-z_]+)\}/i', $subject . ' ' . $body, $m);
        $unknown = array_diff(array_unique($m[1]), $allowed);
        if ($unknown) $fail('Unknown placeholder: {' . implode('}, {', $unknown) . '}. Allowed: {' . implode('}, {', $allowed) . '}.');
        if ($ch === 'sms' && strlen(sms_gsm_safe(str_replace(["\n"], ' ', $body))) !== strlen(str_replace(["\n"], ' ', $body))) {
            // not an error: the text is converted when sent; tell the owner
            $note = ' Special characters will be replaced with plain ones when the SMS is sent.';
        }
        $patch = ['subject' => $subject !== '' ? $subject : null, 'message_body' => $body, 'is_active' => $active];
    }
    $patch['updated_at'] = notify_now_iso();
    $patch['updated_by'] = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'owner');
    $u = supabase_request('notification_templates', 'PATCH', $patch, 'template_id=eq.' . $id);
    if ($u['error'] !== null || empty($u['data'])) $fail('The template could not be saved. Please try again.', 500);
    $name = (na_types()[$type] ?? $type) . ' (' . strtoupper($ch) . ')';
    logActivity('notifications', 'UPDATE', $id, $name, ($action === 'reset_template' ? 'Reset notification template to default: ' : 'Edited notification template: ') . $name,
        null, null, null, ['subject' => $tpl['subject'], 'message_body' => $tpl['message_body'], 'is_active' => $tpl['is_active']],
        ['subject' => $patch['subject'], 'message_body' => $patch['message_body'], 'is_active' => $patch['is_active']]);
    $ok(($action === 'reset_template' ? 'Template reset to the default text.' : 'Template saved.') . ($note ?? ''), ['template' => $u['data'][0]]);
}

case 'create_announcement': {
    if (!$isOwner) apiDeny(403, 'Only the owner can publish announcements.');
    $title   = trim((string)($in['title'] ?? ''));
    $message = trim(preg_replace("/\r\n?/", "\n", (string)($in['message'] ?? '')));
    $branch  = (int)($in['branch_id'] ?? 0);
    $ends    = trim((string)($in['ends_at'] ?? ''));
    $push    = filter_var($in['push'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($title === '' || mb_strlen($title) > 80) $fail('Enter a title of up to 80 characters.');
    if ($message === '' || mb_strlen($message) > 500) $fail('Enter a message of up to 500 characters.');
    $row = ['title' => $title, 'message' => $message, 'branch_id' => $branch > 0 ? $branch : null, 'is_active' => true];
    if ($ends !== '') {
        $d = DateTime::createFromFormat('!Y-m-d', $ends, new DateTimeZone('Asia/Manila'));
        if (!$d || $d->format('Y-m-d') !== $ends) $fail('Enter a valid end date.');
        if ($ends < (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d')) $fail('The end date cannot be in the past.');
        $row['ends_at'] = $d->modify('+1 day')->format('c');   // shown through the whole end date
    }
    $branchName = null;
    if ($branch > 0) {
        $b = supabase_request('branches', 'GET', [], 'branch_id=eq.' . $branch . '&select=branch_name&limit=1');
        $branchName = $b['data'][0]['branch_name'] ?? null;
        if ($branchName === null) $fail('Branch not found.');
    }
    $ins = supabase_request('clinic_announcements', 'POST', $row);
    $annId = (int)($ins['data'][0]['announcement_id'] ?? 0);
    if ($ins['error'] !== null || $annId < 1) $fail('The announcement could not be published. Please try again.', 500);
    logActivity('notifications', 'INSERT', $annId, $title, 'Published a patient announcement' . ($branchName ? " for {$branchName}" : ' for all branches') . ($push ? ' (with push)' : ''),
        $branch > 0 ? $branch : null, $branchName);
    if ($push) {
        notify_defer(fn() => notify_announcement($annId, $title, $message, $branch > 0 ? $branch : null));
    }
    $ok('Announcement published. Patients see it in the Patient Portal' . ($push ? '; push notifications are being sent to subscribed devices.' : '.'));
}

case 'end_announcement': {
    if (!$isOwner) apiDeny(403, 'Only the owner can end announcements.');
    $id = (int)($in['announcement_id'] ?? 0);
    $u = supabase_request('clinic_announcements', 'PATCH', ['is_active' => false], 'announcement_id=eq.' . $id . '&is_active=eq.true');
    if ($u['error'] !== null || empty($u['data'])) $fail('Announcement not found or already ended.', 404);
    logActivity('notifications', 'UPDATE', $id, (string)$u['data'][0]['title'], 'Ended a patient announcement');
    $ok('Announcement ended. It is no longer shown to patients who have not seen it yet.');
}

default:
    $fail('Unknown action.');
}
