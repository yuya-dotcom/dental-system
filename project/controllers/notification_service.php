<?php
// =============================================================
//  controllers/notification_service.php
//  Central patient notifications: EMAIL (PHPMailer / Gmail SMTP),
//  SMS (UniSMS, controllers/sms_helper.php) and WEB PUSH
//  (controllers/webpush.php). One place decides what is sent, to whom,
//  over which channel, and records every attempt in notification_logs.
//
//  Events
//    appointment_confirmed    status became confirmed (staff confirm, or a
//                             staff-created appointment, which starts confirmed)
//    appointment_cancelled    status became cancelled (staff or patient)
//    appointment_rescheduled  date or time really changed (pending/confirmed)
//    appointment_reminder     day before, from cron/send_appointment_reminders.php
//    announcement             clinic announcement (push)
//
//  Entry points
//    notify_appointment_change($row, $before)  called by notif_appointment()
//        (controllers/notification_helper.php) AFTER the appointment write
//        succeeded. Sending is deferred to the end of the request, so the
//        staff action is never slowed down or rolled back by a provider.
//    notify_send_reminders($row, $dryRun)       nightly reminder job
//    notify_retry($logId)                        admin "Retry" of a failed row
//    notify_announcement($id, $title, $message, $branchId)
//
//  Guarantees
//    • A message is sent at most once per dedupe_key: the log row is CLAIMED
//      (unique insert) before the provider is called, so repeated cron runs,
//      double clicks or overlapping requests can't send twice.
//    • A network timeout leaves the row 'sending' (outcome unknown) and it is
//      never retried automatically.
//    • SMS and push carry no clinical details (no services, no treatments).
//    • Patient preferences (notification_preferences) are honoured per channel;
//      no row = email on, SMS on, push off.
//    • Never throws: notification problems are logged, never surfaced to the
//      workflow that triggered them.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/sms_helper.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/webpush.php';

if (!function_exists('notify_appointment_change')) {

define('NOTIFY_LOG_TABLE', 'notification_logs');
define('NOTIFY_MAX_ATTEMPTS', 3);
define('NOTIFY_SMS_MAX', 160);       // one GSM-7 segment
define('NOTIFY_LOGO_PNG', __DIR__ . '/../assets/images/EssenciaSmile-Original-Logo.png');
define('NOTIFY_CHANNELS', ['email', 'sms', 'push']);

// ── Built-in templates (used when notification_templates has no row) ──
// Placeholders: {first_name} {date} {time} {branch} {branch_phone} {code}
//               {old_date} {old_time} {reason_line} {title} {message}
function notify_default_templates(): array
{
    return [
        'appointment_confirmed' => [
            'email' => ['Your appointment is confirmed', 'Hi {first_name}, your appointment at EssenciaSmile {branch} is confirmed. We look forward to seeing you.'],
            'sms'   => [null, 'EssenciaSmile: Hi {first_name}, your appointment on {date} at {time}, {branch} branch is confirmed. Ref {code}.'],
            'push'  => ['Appointment confirmed', 'Your appointment on {date} at {time} is confirmed.'],
        ],
        'appointment_cancelled' => [
            'email' => ['Your appointment was cancelled', 'Hi {first_name}, your appointment at EssenciaSmile {branch} has been cancelled.{reason_line} You can book a new schedule anytime in the Patient Portal.'],
            'sms'   => [null, 'EssenciaSmile: Hi {first_name}, your appointment on {date} at {time}, {branch} branch has been cancelled. Ref {code}.'],
            'push'  => ['Appointment cancelled', 'Your appointment on {date} at {time} has been cancelled.'],
        ],
        'appointment_rescheduled' => [
            'email' => ['Your appointment was rescheduled', 'Hi {first_name}, your appointment at EssenciaSmile {branch} has a new schedule.'],
            'sms'   => [null, 'EssenciaSmile: Hi {first_name}, your appointment is moved to {date} at {time}, {branch} branch. Ref {code}.'],
            'push'  => ['Appointment rescheduled', 'Your appointment is moved to {date} at {time}.'],
        ],
        'appointment_reminder' => [
            'email' => ['Reminder: your appointment is tomorrow', 'Hi {first_name}, this is a reminder of your appointment tomorrow at EssenciaSmile {branch}.'],
            'sms'   => [null, 'EssenciaSmile: Hi {first_name}, reminder of your appointment tomorrow, {date} at {time}, {branch} branch. Ref {code}.'],
            'push'  => ['Appointment tomorrow', 'Reminder: your appointment is tomorrow, {date} at {time}.'],
        ],
        'announcement' => [
            'push'  => ['{title}', '{message}'],
        ],
    ];
}

// ── Small helpers ──────────────────────────────────────────────

function notify_now_iso(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

/** True once the notification tables exist (migration applied). Cached per request. */
function notify_logs_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        $r  = supabase_request(NOTIFY_LOG_TABLE, 'GET', [], 'select=log_id&limit=1');
        $ok = $r['error'] === null && $r['status'] === 200;
        if (!$ok) error_log('notification_service: notification_logs unavailable [' . $r['status'] . '] — run database/proposed/2026-11-13_notification_system.sql');
    }
    return $ok;
}

/** Template for one event + channel: ['subject' => ?string, 'body' => string] or null when switched off. */
function notify_template(string $type, string $channel): ?array
{
    static $db = null;
    if ($db === null) {
        $db = [];
        $r  = supabase_request('notification_templates', 'GET', [], 'select=notification_type,channel,subject,message_body,is_active');
        if ($r['error'] === null && $r['status'] === 200 && is_array($r['data'])) {
            foreach ($r['data'] as $t) $db[$t['notification_type'] . '|' . $t['channel']] = $t;
        }
    }
    $row = $db[$type . '|' . $channel] ?? null;
    if ($row) {
        if (empty($row['is_active'])) return null;
        if (trim((string)$row['message_body']) !== '') return ['subject' => $row['subject'], 'body' => (string)$row['message_body']];
    }
    $def = notify_default_templates()[$type][$channel] ?? null;
    return $def ? ['subject' => $def[0], 'body' => $def[1]] : null;
}

function notify_render(?string $tpl, array $vars): string
{
    $rep = [];
    foreach ($vars as $k => $v) $rep['{' . $k . '}'] = (string)$v;
    return trim(strtr((string)$tpl, $rep));
}

/** SMS text: GSM-safe and, if possible, one segment (drops the reference, then shortens names). */
function notify_sms_text(string $tpl, array $vars): string
{
    $steps = [
        $vars,
        ['code' => ''] + $vars,
        ['code' => '', 'first_name' => mb_substr((string)$vars['first_name'], 0, 10)] + $vars,
        ['code' => '', 'first_name' => '', 'branch' => trim((string)preg_replace('/\s+City$/i', '', (string)$vars['branch']))] + $vars,
    ];
    $msg = '';
    foreach ($steps as $v) {
        $msg = sms_gsm_safe(notify_render($tpl, $v));
        $msg = preg_replace(['/\s+Ref\s*\.$/', '/\s+Ref\s*\.\s/', '/Hi\s*,/', '/\s{2,}/'], ['', ' ', 'Hi,', ' '], $msg);
        if (strlen($msg) <= NOTIFY_SMS_MAX) return $msg;
    }
    return $msg;   // up to two segments is still accepted by UniSMS
}

/** Preferences of a portal account; defaults when there is no row (or no account). */
function notify_prefs(?int $accountId): array
{
    $def = ['email' => true, 'sms' => true, 'push' => false];
    if (!$accountId) return $def;
    static $cache = [];
    if (!array_key_exists($accountId, $cache)) {
        $r = supabase_request('notification_preferences', 'GET', [], 'account_id=eq.' . $accountId . '&select=email_enabled,sms_enabled,push_enabled&limit=1');
        $row = ($r['error'] === null && $r['status'] === 200) ? ($r['data'][0] ?? null) : null;
        $cache[$accountId] = $row ? ['email' => (bool)$row['email_enabled'], 'sms' => (bool)$row['sms_enabled'], 'push' => (bool)$row['push_enabled']] : $def;
    }
    return $cache[$accountId];
}

/** Masked recipient for screens and logs ("+63•••••4666", "ca•••@gmail.com"). */
function notify_mask(?string $r): string
{
    $r = (string)$r;
    if ($r === '') return '—';
    if (str_contains($r, '@')) { [$u, $d] = explode('@', $r, 2); return mb_substr($u, 0, 2) . '•••@' . $d; }
    if (preg_match('/^\+?\d{10,13}$/', $r)) return substr($r, 0, 3) . '•••••' . substr($r, -4);
    return $r;
}

// ── Send exactly once (claim → send → record) ────────────────

function notify_log_update(string $match, array $fields): bool
{
    $fields['updated_at'] = notify_now_iso();
    $res = supabase_request(NOTIFY_LOG_TABLE, 'PATCH', $fields, $match);
    return $res['status'] >= 200 && $res['status'] < 300 && !empty($res['data'][0]);
}

/**
 * Claim notification_logs[$meta['dedupe_key']] and run $send() at most once.
 * $meta: dedupe_key, notification_type, channel, patient_id, account_id, appointment_id,
 *        branch_id, recipient (null = skip), subject, message (what is LOGGED).
 * $send(): ['ok' => bool, 'uncertain' => bool, 'error' => ?string, 'reference' => ?string, 'response' => mixed]
 * Returns ['result' => sent|failed|skipped|duplicate, 'error' => ?string, 'log_id' => ?int].
 */
function notify_send_once(array $meta, callable $send): array
{
    $out = fn(string $r, ?string $e = null, ?int $id = null) => ['result' => $r, 'error' => $e, 'log_id' => $id];
    $key = (string)($meta['dedupe_key'] ?? '');
    if ($key === '') return $out('failed', 'empty dedupe key');
    try {
        if (!notify_logs_available()) return $out('failed', 'notification log unavailable');
        $keyMatch  = 'dedupe_key=eq.' . rawurlencode($key);
        $recipient = isset($meta['recipient']) && trim((string)$meta['recipient']) !== '' ? trim((string)$meta['recipient']) : null;
        $row = [
            'dedupe_key' => $key, 'notification_type' => $meta['notification_type'], 'channel' => $meta['channel'],
            'patient_id' => $meta['patient_id'] ?? null, 'account_id' => $meta['account_id'] ?? null,
            'appointment_id' => $meta['appointment_id'] ?? null, 'branch_id' => $meta['branch_id'] ?? null,
            'recipient' => $recipient, 'subject' => $meta['subject'] ?? null, 'message' => $meta['message'] ?? null,
            'status' => 'sending', 'attempts' => 1,
        ];
        $ins = supabase_request(NOTIFY_LOG_TABLE, 'POST', $row, 'on_conflict=dedupe_key', ['Prefer: resolution=ignore-duplicates']);
        $claimed  = $ins['status'] >= 200 && $ins['status'] < 300 && ($ins['data'][0]['dedupe_key'] ?? null) === $key;
        $logId    = $claimed ? (int)$ins['data'][0]['log_id'] : null;
        $attempts = 1;

        if (!$claimed) {
            $cur = supabase_request(NOTIFY_LOG_TABLE, 'GET', [], $keyMatch . '&select=log_id,status,attempts&limit=1');
            $ex  = ($cur['error'] === null && $cur['status'] === 200) ? ($cur['data'][0] ?? null) : null;
            if (!$ex) { error_log("notify_send_once: claim failed [{$ins['status']}] for {$key}"); return $out('failed', 'could not write notification log'); }
            $logId = (int)$ex['log_id'];
            $st = (string)$ex['status']; $n = (int)$ex['attempts'];
            if (in_array($st, ['sent', 'sending', 'skipped'], true)) return $out('duplicate', null, $logId);
            if ($n >= NOTIFY_MAX_ATTEMPTS) return $out('failed', 'retry limit reached', $logId);
            // Compare-and-set on the attempt count: only one concurrent run wins the retry
            if (!notify_log_update($keyMatch . '&status=eq.failed&attempts=eq.' . $n,
                    ['status' => 'sending', 'attempts' => $n + 1, 'failure_reason' => null, 'provider_status' => null, 'recipient' => $recipient,
                     'subject' => $row['subject'], 'message' => $row['message']])) {
                return $out('duplicate', null, $logId);
            }
            $attempts = $n + 1;
        }
        $owned = $keyMatch . '&status=eq.sending&attempts=eq.' . $attempts;

        if ($recipient === null) {
            notify_log_update($owned, ['status' => 'skipped', 'failure_reason' => (string)($meta['skip_reason'] ?? 'no recipient')]);
            return $out('skipped', (string)($meta['skip_reason'] ?? 'no recipient'), $logId);
        }

        $r = $send();
        if (!empty($r['ok'])) {
            $fields = ['status' => 'sent', 'failure_reason' => null, 'sent_at' => notify_now_iso(),
                       'provider_reference_id' => isset($r['reference']) ? substr((string)$r['reference'], 0, 200) : null,
                       'provider_response' => $r['response'] ?? null];
            if (!empty($r['provider_status'])) $fields['provider_status'] = substr((string)$r['provider_status'], 0, 20);
            $res = $out('sent', null, $logId);
        } elseif (!empty($r['uncertain'])) {
            $fields = ['failure_reason' => 'uncertain delivery (network error); not retried automatically'];
            $res = $out('failed', 'uncertain delivery (network error)', $logId);
        } else {
            $err = substr((string)($r['error'] ?? 'provider error'), 0, 300);
            $fields = ['status' => 'failed', 'failure_reason' => $err, 'provider_response' => $r['response'] ?? null];
            $res = $out('failed', $err, $logId);
        }
        if (!notify_log_update($owned, $fields)) {
            error_log("notify_send_once: outcome '{$res['result']}' for {$key} but the log update failed; row left as 'sending'");
        }
        return $res;
    } catch (Throwable $e) {
        error_log("notify_send_once failed for {$key}: " . $e->getMessage());
        return $out('failed', 'internal error');
    }
}

// ── Channel senders ───────────────────────────────────────────

function notify_email_send(string $to, string $subject, string $html, string $text): array
{
    try {
        $mail = createMailer();
        $mail->Timeout = 15;
        $mail->addAddress($to);
        if (is_file(NOTIFY_LOGO_PNG)) $mail->addEmbeddedImage(NOTIFY_LOGO_PNG, 'essencia-logo', 'EssenciaSmile-Logo.png', 'base64', 'image/png');
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;
        $mail->send();
        return ['ok' => true, 'reference' => $mail->getLastMessageID()];
    } catch (Throwable $e) {
        // PHPMailer messages never contain the password; keep them short
        return ['ok' => false, 'error' => 'email: ' . substr($e->getMessage(), 0, 200)];
    }
}

/**
 * What is kept of a UniSMS answer: never the text (UniSMS echoes `content`,
 * which for an OTP is the code) and never the number (already in `recipient`).
 * UniSMS answers {"message": {"reference_id", "status", "fail_reason", "content", "recipient", ...}}.
 */
function notify_sms_meta($d): array
{
    $m = is_array($d['message'] ?? null) ? $d['message'] : (is_array($d['data'] ?? null) ? $d['data'] : (is_array($d) ? $d : []));
    $out = [];
    foreach (['reference_id', 'status', 'fail_reason', 'created', 'sender_id'] as $k) {
        if (isset($m[$k]) && is_scalar($m[$k])) $out[$k] = substr((string)$m[$k], 0, 200);
    }
    return $out;
}

function notify_sms_send(string $phone, string $message): array
{
    $r = sendSMS($phone, $message);
    if (!empty($r['success'])) {
        $meta = notify_sms_meta($r['data'] ?? []);
        return ['ok' => true, 'reference' => $meta['reference_id'] ?? null, 'response' => $meta,
                'provider_status' => $meta['status'] ?? null];
    }
    if (($r['message'] ?? '') === SMS_NETWORK_ERROR_MSG) return ['ok' => false, 'uncertain' => true];
    return ['ok' => false, 'error' => 'sms: ' . (string)($r['message'] ?? 'UniSMS error')];
}

/**
 * Delivery status of one sent SMS from UniSMS (GET /api/sms/{reference_id}).
 * UniSMS statuses: pending | retrying | sent | failed. A carrier 'failed' turns
 * the log row into status 'failed' (the patient did not get it, so a retry is safe).
 * Returns the provider status, or null when it could not be read.
 */
function notify_sms_refresh_status(array $row): ?string
{
    $ref = trim((string)($row['provider_reference_id'] ?? ''));
    if ($ref === '' || !preg_match('/^[A-Za-z0-9_\-]{4,120}$/', $ref) || !defined('UNISMS_API_KEY')) return null;
    $ch = curl_init('https://unismsapi.com/api/sms/' . rawurlencode($ref));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => UNISMS_API_KEY . ':', CURLOPT_TIMEOUT => 10]);
    $body = curl_exec($ch);
    $st   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_errno($ch);
    curl_close($ch);
    if ($err || $st !== 200) return null;
    $meta   = notify_sms_meta(json_decode((string)$body, true) ?: []);
    $status = strtolower((string)($meta['status'] ?? ''));
    if (!in_array($status, ['pending', 'retrying', 'sent', 'failed'], true)) return null;
    $fields = ['provider_status' => $status, 'status_checked_at' => notify_now_iso()];
    $match  = 'log_id=eq.' . (int)$row['log_id'] . '&status=eq.sent';
    if ($status === 'failed') {
        $fields += ['status' => 'failed', 'failure_reason' => 'carrier: ' . ($meta['fail_reason'] ?? 'not delivered')];
    }
    notify_log_update($match, $fields);
    return $status;
}

/** Refresh SMS delivery status of recent rows still pending at the provider (cron). Returns counts by status. */
function notify_sms_refresh_recent(int $hours = 48, int $limit = 100): array
{
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - $hours * 3600);
    $r = supabase_request(NOTIFY_LOG_TABLE, 'GET', [], 'channel=eq.sms&status=eq.sent&provider_reference_id=not.is.null'
        . '&or=(provider_status.is.null,provider_status.in.(pending,retrying))&sent_at=gte.' . rawurlencode($since)
        . '&select=log_id,provider_reference_id&order=sent_at.asc&limit=' . $limit);
    $counts = [];
    if ($r['error'] !== null || $r['status'] !== 200 || !is_array($r['data'])) return $counts;
    foreach ($r['data'] as $row) {
        $s = notify_sms_refresh_status($row) ?? 'unknown';
        $counts[$s] = ($counts[$s] ?? 0) + 1;
    }
    return $counts;
}

/** Push to every subscription of the given accounts; prunes expired subscriptions. */
function notify_push(array $accountIds, string $type, string $keyBase, array $msg, array $meta): array
{
    $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'duplicate' => 0];
    $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
    if (!$accountIds) return $counts;
    $r = supabase_request('push_subscriptions', 'GET', [], 'account_id=in.(' . implode(',', $accountIds) . ')&select=subscription_id,account_id,endpoint,p256dh,auth,failure_count');
    if ($r['error'] !== null || $r['status'] !== 200) return $counts;
    foreach ($r['data'] as $sub) {
        $sid  = (int)$sub['subscription_id'];
        $host = (string)(parse_url((string)$sub['endpoint'], PHP_URL_HOST) ?: 'push');
        $res  = notify_send_once([
            'dedupe_key' => "{$keyBase}:{$sid}", 'notification_type' => $type, 'channel' => 'push',
            'account_id' => (int)$sub['account_id'], 'recipient' => $host,
            'subject' => $msg['title'], 'message' => json_encode(['subscription_id' => $sid] + $msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ] + $meta, function () use ($sub, $msg, $sid) {
            $p = webpush_send($sub, $msg);
            if ($p['gone']) {
                supabase_request('push_subscriptions', 'DELETE', [], 'subscription_id=eq.' . $sid);
            } elseif ($p['ok']) {
                supabase_request('push_subscriptions', 'PATCH', ['failure_count' => 0, 'last_success_at' => notify_now_iso(), 'updated_at' => notify_now_iso()], 'subscription_id=eq.' . $sid);
            } else {
                supabase_request('push_subscriptions', 'PATCH', ['failure_count' => (int)$sub['failure_count'] + 1, 'updated_at' => notify_now_iso()], 'subscription_id=eq.' . $sid);
            }
            return ['ok' => $p['ok'], 'error' => $p['error'] . ($p['gone'] ? ' (subscription removed)' : ''), 'reference' => $p['reference'], 'response' => ['http' => $p['status']]];
        });
        $counts[$res['result']] = ($counts[$res['result']] ?? 0) + 1;
    }
    return $counts;
}

// ── Appointment context ───────────────────────────────────────

/** Everything needed to address one appointment's patient (fresh from the database). */
function notify_appointment_context(int $appointmentId): ?array
{
    if ($appointmentId < 1) return null;
    $sel = 'appointment_id,appointment_code,patient_id,account_id,branch_id,appointment_date,appointment_time,status,'
         . 'patients(full_name,contact_number),patient_accounts(account_id,first_name,email,is_verified,contact_number),'
         . 'branches(branch_name,contact_number,address)';
    $r = supabase_request('appointments', 'GET', [], 'appointment_id=eq.' . $appointmentId . '&select=' . $sel . '&limit=1');
    $a = ($r['error'] === null && $r['status'] === 200) ? ($r['data'][0] ?? null) : null;
    if (!$a) return null;

    // Portal accounts of this patient (the booking account first)
    $accounts = [];
    if (!empty($a['patient_accounts']['account_id'])) $accounts[] = $a['patient_accounts'];
    if (!empty($a['patient_id'])) {
        $acc = supabase_request('patient_accounts', 'GET', [], 'patient_id=eq.' . (int)$a['patient_id'] . '&select=account_id,first_name,email,is_verified,contact_number&order=account_id.asc');
        foreach (($acc['data'] ?? []) as $row) {
            if (!in_array((int)$row['account_id'], array_map(fn($x) => (int)$x['account_id'], $accounts), true)) $accounts[] = $row;
        }
    }
    $primary = $accounts[0] ?? null;

    $phone = null;
    foreach ([$a['patients']['contact_number'] ?? null, $primary['contact_number'] ?? null] as $raw) {
        if (is_string($raw) && trim($raw) !== '' && ($p = normalizePhMobile($raw)) !== null) { $phone = $p; break; }
    }
    $email = null;
    foreach ($accounts as $acc) {
        $e = trim((string)($acc['email'] ?? ''));
        if (!empty($acc['is_verified']) && filter_var($e, FILTER_VALIDATE_EMAIL)) { $email = $e; break; }
    }
    $first = trim((string)($primary['first_name'] ?? ''));
    if ($first === '') $first = (string)(preg_split('/\s+/', trim((string)($a['patients']['full_name'] ?? '')))[0] ?? '');
    $first = $first !== '' ? mb_convert_case(mb_strtolower($first), MB_CASE_TITLE) : '';

    $branchPhone = trim((string)($a['branches']['contact_number'] ?? ''));
    if (strlen(preg_replace('/\D/', '', $branchPhone)) < 7) $branchPhone = '';

    return [
        'id' => (int)$a['appointment_id'], 'code' => (string)($a['appointment_code'] ?? ''),
        'patient_id' => !empty($a['patient_id']) ? (int)$a['patient_id'] : null,
        'account_id' => $primary ? (int)$primary['account_id'] : null,
        'account_ids' => array_map(fn($x) => (int)$x['account_id'], $accounts),
        'branch_id' => !empty($a['branch_id']) ? (int)$a['branch_id'] : null,
        'date' => (string)($a['appointment_date'] ?? ''), 'time' => substr((string)($a['appointment_time'] ?? ''), 0, 5),
        'status' => (string)($a['status'] ?? ''),
        'first_name' => $first, 'email' => $email, 'phone' => $phone,
        'branch' => trim((string)($a['branches']['branch_name'] ?? '')), 'branch_phone' => $branchPhone,
        'branch_address' => trim((string)($a['branches']['address'] ?? '')),
    ];
}

function notify_slot(array $c): string { return $c['date'] . 'T' . $c['time']; }

function notify_vars(array $c, ?array $before, string $style): array
{
    $ts  = strtotime($c['date'] . ' ' . $c['time']);
    $bts = $before ? strtotime(($before['appointment_date'] ?? '') . ' ' . substr((string)($before['appointment_time'] ?? ''), 0, 5)) : false;
    $fmt = $style === 'email' ? 'l, F j, Y' : 'M j';
    return [
        'first_name' => $c['first_name'], 'code' => $c['code'],
        'date' => $ts ? date($fmt, $ts) : $c['date'], 'time' => $ts ? date('g:i A', $ts) : $c['time'],
        'old_date' => $bts ? date($fmt, $bts) : '', 'old_time' => $bts ? date('g:i A', $bts) : '',
        'branch' => $c['branch'] !== '' ? $c['branch'] : 'clinic', 'branch_phone' => $c['branch_phone'],
        'reason_line' => '', 'title' => '', 'message' => '',
    ];
}

// ── Email layout ──────────────────────────────────────────────

function notify_email_html(string $heading, string $intro, array $rows, ?string $note, string $preheader): string
{
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $tr = '';
    foreach ($rows as [$label, $value, $strike]) {
        $val = $strike ? '<span style="text-decoration:line-through;color:#8a94a6;">' . $e($value) . '</span>' : '<strong style="color:#0b3a57;">' . $e($value) . '</strong>';
        $tr .= '<tr><td style="padding:10px 0;border-bottom:1px solid #eef2f7;font-size:14px;color:#6b7a90;width:38%;">' . $e($label) . '</td>'
             . '<td style="padding:10px 0;border-bottom:1px solid #eef2f7;font-size:14px;">' . $val . '</td></tr>';
    }
    $noteHtml = $note ? '<p style="margin:18px 0 0 0;font-size:13px;line-height:1.6;color:#6b7a90;">' . $e($note) . '</p>' : '';
    $portal = $e(app_url('portal-login.php'));
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{$e($heading)}</title></head>
<body style="margin:0;padding:0;background-color:#eef6fb;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{$e($preheader)}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef6fb;"><tr><td align="center" style="padding:32px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background-color:#ffffff;border-radius:14px;border:1px solid #e3eef5;box-shadow:0 4px 18px rgba(1,120,184,0.10);">
<tr><td align="center" style="padding:28px 32px 6px 32px;"><img src="cid:essencia-logo" width="220" alt="EssenciaSmile" style="display:block;width:220px;max-width:100%;height:auto;border:0;"></td></tr>
<tr><td style="padding:0 32px;"><div style="height:3px;line-height:3px;font-size:0;background-color:#93D135;width:56px;margin:14px auto 0 auto;border-radius:2px;">&nbsp;</div></td></tr>
<tr><td style="padding:22px 32px 0 32px;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2d3d;">
<h1 style="margin:0 0 14px 0;font-size:22px;line-height:1.3;font-weight:700;color:#0b3a57;text-align:center;">{$e($heading)}</h1>
<p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;">{$e($intro)}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">{$tr}</table>
{$noteHtml}
</td></tr>
<tr><td align="center" style="padding:22px 32px 26px 32px;"><a href="{$portal}" target="_blank" style="display:inline-block;padding:12px 28px;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;background-color:#0279BC;">Open the Patient Portal</a></td></tr>
<tr><td style="padding:16px 32px 24px 32px;border-top:1px solid #eef2f7;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#8a94a6;text-align:center;">
This is an automated message from EssenciaSmile Dental Clinic. You can change how we contact you in the Patient Portal under Profile &gt; Notifications.<br>&copy; {$year} EssenciaSmile</td></tr>
</table></td></tr></table></body></html>
HTML;
}

/** Subject + HTML + plain text for an appointment email. */
function notify_appointment_email(string $type, array $c, ?array $before, array $tpl): array
{
    $v = notify_vars($c, $before, 'email');
    $intro = notify_render($tpl['body'], $v);
    $subject = notify_render($tpl['subject'] ?: 'EssenciaSmile appointment update', $v) . ' – EssenciaSmile';
    $rows = [];
    if ($type === 'appointment_rescheduled' && $v['old_date'] !== '') {
        $rows[] = ['New schedule', $v['date'] . ' at ' . $v['time'], false];
        $rows[] = ['Previous schedule', $v['old_date'] . ' at ' . $v['old_time'], true];
    } else {
        $rows[] = ['Date', $v['date'], $type === 'appointment_cancelled'];
        $rows[] = ['Time', $v['time'], $type === 'appointment_cancelled'];
    }
    $rows[] = ['Clinic', 'EssenciaSmile ' . $v['branch'], false];
    if ($c['branch_address'] !== '') $rows[] = ['Address', $c['branch_address'], false];
    if ($c['branch_phone'] !== '') $rows[] = ['Clinic phone', $c['branch_phone'], false];
    if ($c['code'] !== '') $rows[] = ['Reference no.', $c['code'], false];
    if ($type === 'appointment_cancelled') $rows[] = ['Status', 'Cancelled', false];

    $headings = ['appointment_confirmed' => 'Appointment confirmed', 'appointment_cancelled' => 'Appointment cancelled',
                 'appointment_rescheduled' => 'Appointment rescheduled', 'appointment_reminder' => 'See you tomorrow'];
    $note = $type === 'appointment_cancelled' ? null
          : 'Please arrive 10 minutes early. If you need to change your schedule, contact the clinic' . ($c['branch_phone'] !== '' ? ' at ' . $c['branch_phone'] : '') . ' or use the Patient Portal.';
    $html = notify_email_html($headings[$type] ?? 'Appointment update', $intro, $rows, $note, $intro);
    $text = $intro . "\n\n" . implode("\n", array_map(fn($r) => $r[0] . ': ' . $r[1], $rows)) . ($note ? "\n\n" . $note : '') . "\n\nPatient Portal: " . app_url('portal-login.php');
    return [$subject, $html, $text];
}

// ── Dispatch one event over every channel ─────────────────────

/**
 * Send $type for one appointment context. $keySuffix makes the dedupe key
 * (e.g. the slot for reminders / confirmations / reschedules).
 * Returns per-channel results.
 */
function notify_dispatch(string $type, array $c, ?array $before, string $keySuffix, bool $dryRun = false): array
{
    $results = [];
    $prefs = notify_prefs($c['account_id']);
    $meta  = ['notification_type' => $type, 'patient_id' => $c['patient_id'], 'account_id' => $c['account_id'],
              'appointment_id' => $c['id'], 'branch_id' => $c['branch_id']];
    $base  = "{$type}:%s:{$c['id']}" . ($keySuffix !== '' ? ":{$keySuffix}" : '');

    // Email
    $tpl = notify_template($type, 'email');
    if ($tpl && $prefs['email']) {
        [$subject, $html, $text] = notify_appointment_email($type, $c, $before, $tpl);
        $results['email'] = $dryRun ? ['result' => $c['email'] ? 'dry-run' : 'skipped', 'preview' => $subject]
            : notify_send_once($meta + ['dedupe_key' => sprintf($base, 'email'), 'channel' => 'email', 'recipient' => $c['email'],
                'skip_reason' => 'no verified email address', 'subject' => $subject, 'message' => $html],
                fn() => notify_email_send($c['email'], $subject, $html, $text));
    } else {
        $results['email'] = ['result' => 'off'];
    }
    // SMS
    $tpl = notify_template($type, 'sms');
    if ($tpl && $prefs['sms']) {
        $sms = notify_sms_text($tpl['body'], notify_vars($c, $before, 'sms'));
        $results['sms'] = $dryRun ? ['result' => $c['phone'] ? 'dry-run' : 'skipped', 'preview' => $sms]
            : notify_send_once($meta + ['dedupe_key' => sprintf($base, 'sms'), 'channel' => 'sms', 'recipient' => $c['phone'],
                'skip_reason' => 'no valid mobile number', 'message' => $sms],
                fn() => notify_sms_send($c['phone'], $sms));
    } else {
        $results['sms'] = ['result' => 'off'];
    }
    // Push (only accounts that turned it on)
    $tpl = notify_template($type, 'push');
    $pushAccounts = array_values(array_filter($c['account_ids'], fn($id) => notify_prefs($id)['push']));
    if ($tpl && $pushAccounts) {
        $v = notify_vars($c, $before, 'sms');
        $msg = ['title' => notify_render($tpl['subject'] ?: 'EssenciaSmile', $v), 'body' => notify_render($tpl['body'], $v),
                'url' => app_url('portal-dashboard.php#appointments'), 'tag' => 'appt-' . $c['id']];
        $results['push'] = $dryRun ? ['result' => 'dry-run', 'preview' => $msg['body']]
            : notify_push($pushAccounts, $type, sprintf($base, 'push'), $msg, $meta);
    } else {
        $results['push'] = ['result' => 'off'];
    }
    return $results;
}

// ── Deferred sending (after the HTTP response) ────────────────

function notify_defer(callable $job): void
{
    if (PHP_SAPI === 'cli') { $job(); return; }
    static $queued = false;
    $GLOBALS['__notify_jobs'][] = $job;
    if ($queued) return;
    $queued = true;
    // Buffer the rest of this request's output so it can be finished early (see below)
    if (!headers_sent() && ob_get_level() === 0) ob_start();
    register_shutdown_function(function () {
        $jobs = $GLOBALS['__notify_jobs'] ?? [];
        $GLOBALS['__notify_jobs'] = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true);
        @set_time_limit(120);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();                 // PHP-FPM: response is delivered now
        } elseif (!headers_sent() && ob_get_level() > 0) {
            header('Connection: close');               // mod_php: tell the browser the body ends here
            header('Content-Length: ' . ob_get_length());
            while (ob_get_level() > 0) ob_end_flush();
            flush();
        }
        foreach ($jobs as $j) {
            try { $j(); } catch (Throwable $e) { error_log('notify deferred job failed: ' . $e->getMessage()); }
        }
    });
}

// ── Entry points ──────────────────────────────────────────────

/**
 * Called after an appointment write succeeded. $row holds at least
 * appointment_id, status, appointment_date, appointment_time; $before is the
 * snapshot taken before the write (null for a new appointment).
 */
function notify_appointment_change(array $row, ?array $before): void
{
    try {
        $id = (int)($row['appointment_id'] ?? 0);
        if ($id < 1) return;
        $status = (string)($row['status'] ?? '');
        $slot = ($row['appointment_date'] ?? '') . 'T' . substr((string)($row['appointment_time'] ?? ''), 0, 5);
        $moved = $before
            && (($before['appointment_date'] ?? '') !== ($row['appointment_date'] ?? '')
                || substr((string)($before['appointment_time'] ?? ''), 0, 5) !== substr((string)($row['appointment_time'] ?? ''), 0, 5));

        $events = [];
        if ($status === 'confirmed' && (!$before || ($before['status'] ?? '') !== 'confirmed')) {
            $events[] = ['appointment_confirmed', $slot];          // includes the (new) schedule
        } elseif ($moved && in_array($status, ['pending', 'confirmed'], true)) {
            // Keyed by new slot + previous slot (+ write time when known), so a later move back
            // to the same slot is still announced while a repeated request is not
            $from = ($before['appointment_date'] ?? '') . 'T' . substr((string)($before['appointment_time'] ?? ''), 0, 5);
            $at   = preg_replace('/\D/', '', (string)($row['updated_at'] ?? ''));
            $events[] = ['appointment_rescheduled', $slot . ':from:' . $from . ($at !== '' ? ':' . substr($at, 0, 14) : '')];
        }
        if ($status === 'cancelled' && (!$before || ($before['status'] ?? '') !== 'cancelled')) {
            $events[] = ['appointment_cancelled', ''];
        }
        if (!$events) return;

        $snapshot = $before;
        notify_defer(function () use ($id, $events, $snapshot) {
            $c = notify_appointment_context($id);
            if (!$c) return;
            foreach ($events as [$type, $suffix]) {
                if ($type !== 'appointment_rescheduled') $suffix = notify_status_suffix($id, $type, $suffix);
                // The appointment may have changed again before this ran: only send what is still true
                if ($type === 'appointment_confirmed' && $c['status'] !== 'confirmed') continue;
                if ($type === 'appointment_cancelled' && $c['status'] !== 'cancelled') continue;
                if ($type === 'appointment_rescheduled' && (!in_array($c['status'], ['pending', 'confirmed'], true) || !str_starts_with($suffix, notify_slot($c) . ':from:'))) continue;
                notify_dispatch($type, $c, $snapshot, $suffix);
            }
        });
    } catch (Throwable $e) {
        error_log('notify_appointment_change failed: ' . $e->getMessage());
    }
}

/**
 * Dedupe suffix for a confirmed / cancelled message. The same transition repeated
 * (double click, retried request, overlapping requests) reuses the latest key, so it
 * is sent once; a NEW transition (cancel → re-confirm → cancel again) gets the next
 * generation (":g2", ":g3"…), so the patient hears about every real change.
 */
function notify_status_suffix(int $appointmentId, string $type, string $base): string
{
    $r = supabase_request(NOTIFY_LOG_TABLE, 'GET', [], 'appointment_id=eq.' . $appointmentId
        . '&notification_type=in.(appointment_confirmed,appointment_cancelled)&channel=in.(email,sms)'
        . '&select=dedupe_key,notification_type&order=log_id.desc&limit=200');
    $rows = ($r['error'] === null && $r['status'] === 200 && is_array($r['data'])) ? $r['data'] : [];
    if (!$rows) return $base;
    $suffixOf = function (string $key): string {   // "<type>:<channel>:<id>[:<suffix>]"
        $p = explode(':', $key, 4);
        return $p[3] ?? '';
    };
    $last = $rows[0];
    $lastSuffix = $suffixOf((string)$last['dedupe_key']);
    $lastBase = (string)preg_replace('/(^|:)g\d+$/', '', $lastSuffix);
    if ($last['notification_type'] === $type && $lastBase === $base) return $lastSuffix;   // same transition again
    $generations = [];
    foreach ($rows as $row) $generations[$row['notification_type'] . '|' . $suffixOf((string)$row['dedupe_key'])] = true;
    return ($base !== '' ? $base . ':' : '') . 'g' . (count($generations) + 1);
}

/** Day-before reminder for one confirmed appointment (cron). Keyed by the CURRENT slot. */
function notify_send_reminders(int $appointmentId, bool $dryRun = false): array
{
    $c = notify_appointment_context($appointmentId);
    if (!$c) return ['error' => 'appointment not found'];
    if ($c['status'] !== 'confirmed') return ['error' => 'not confirmed (' . $c['status'] . ')'];
    return notify_dispatch('appointment_reminder', $c, null, notify_slot($c), $dryRun);
}

/**
 * Retry one failed row (admin). Only rows that are 'failed' with attempts left;
 * uses the stored recipient and content. Returns ['ok' => bool, 'message' => string].
 */
function notify_retry(int $logId): array
{
    $r = supabase_request(NOTIFY_LOG_TABLE, 'GET', [], 'log_id=eq.' . $logId . '&select=*&limit=1');
    $row = ($r['error'] === null && $r['status'] === 200) ? ($r['data'][0] ?? null) : null;
    if (!$row) return ['ok' => false, 'message' => 'Notification not found.'];
    if ($row['status'] !== 'failed') return ['ok' => false, 'message' => 'Only failed notifications can be retried.'];
    if ((int)$row['attempts'] >= NOTIFY_MAX_ATTEMPTS) return ['ok' => false, 'message' => 'This notification reached the retry limit.'];
    if ($row['notification_type'] === 'otp') return ['ok' => false, 'message' => 'Verification codes are never re-sent; the patient can request a new code.'];
    if ($row['notification_type'] !== 'announcement' && !empty($row['appointment_id'])) {
        $c = notify_appointment_context((int)$row['appointment_id']);
        $stillTrue = $c && match ($row['notification_type']) {
            'appointment_confirmed', 'appointment_reminder' => $c['status'] === 'confirmed',
            'appointment_cancelled' => $c['status'] === 'cancelled',
            'appointment_rescheduled' => in_array($c['status'], ['pending', 'confirmed'], true),
            default => true,
        };
        if (!$stillTrue) return ['ok' => false, 'message' => 'The appointment has changed since then, so this message is out of date.'];
    }
    $meta = array_intersect_key($row, array_flip(['dedupe_key', 'notification_type', 'channel', 'patient_id', 'account_id', 'appointment_id', 'branch_id', 'recipient', 'subject', 'message']));
    $send = match ($row['channel']) {
        'sms'   => fn() => notify_sms_send((string)$row['recipient'], (string)$row['message']),
        'email' => fn() => notify_email_send((string)$row['recipient'], (string)$row['subject'], (string)$row['message'], trim(html_entity_decode(strip_tags((string)$row['message'])))),
        'push'  => function () use ($row) {
            $m = json_decode((string)$row['message'], true) ?: [];
            $s = supabase_request('push_subscriptions', 'GET', [], 'subscription_id=eq.' . (int)($m['subscription_id'] ?? 0) . '&select=*&limit=1');
            $sub = $s['data'][0] ?? null;
            if (!$sub) return ['ok' => false, 'error' => 'the device subscription no longer exists'];
            unset($m['subscription_id']);
            $p = webpush_send($sub, $m);
            if ($p['gone']) supabase_request('push_subscriptions', 'DELETE', [], 'subscription_id=eq.' . (int)$sub['subscription_id']);
            return ['ok' => $p['ok'], 'error' => $p['error'], 'reference' => $p['reference']];
        },
        default => fn() => ['ok' => false, 'error' => 'unknown channel'],
    };
    $res = notify_send_once($meta, $send);
    return match ($res['result']) {
        'sent'      => ['ok' => true, 'message' => 'Sent successfully.'],
        'duplicate' => ['ok' => false, 'message' => 'This notification is already being sent or was sent.'],
        default     => ['ok' => false, 'message' => 'Still failing: ' . ($res['error'] ?? 'unknown error')],
    };
}

/** Push an announcement to every account with push on (optionally only patients of one branch). */
function notify_announcement(int $announcementId, string $title, string $message, ?int $branchId): array
{
    $tpl = notify_template('announcement', 'push');
    if (!$tpl) return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'duplicate' => 0];
    $p = supabase_request('notification_preferences', 'GET', [], 'push_enabled=eq.true&select=account_id');
    $ids = array_map(fn($r) => (int)$r['account_id'], ($p['data'] ?? []));
    if ($branchId && $ids) {
        $acc = supabase_request('patient_accounts', 'GET', [], 'account_id=in.(' . implode(',', $ids) . ')&select=account_id,patient_id');
        $patients = array_filter(array_map(fn($r) => (int)($r['patient_id'] ?? 0), $acc['data'] ?? []));
        $inBranch = [];
        if ($patients) {
            $ap = supabase_request('appointments', 'GET', [], 'branch_id=eq.' . $branchId . '&patient_id=in.(' . implode(',', $patients) . ')&select=patient_id');
            $inBranch = array_unique(array_map(fn($r) => (int)$r['patient_id'], $ap['data'] ?? []));
        }
        $ids = array_values(array_map(fn($r) => (int)$r['account_id'], array_filter($acc['data'] ?? [], fn($r) => in_array((int)($r['patient_id'] ?? 0), $inBranch, true))));
    }
    $v = ['title' => $title, 'message' => $message];
    $msg = ['title' => mb_substr(notify_render($tpl['subject'] ?: '{title}', $v), 0, 80), 'body' => mb_substr(notify_render($tpl['body'], $v), 0, 240),
            'url' => app_url('portal-dashboard.php'), 'tag' => 'ann-' . $announcementId];
    return notify_push($ids, 'announcement', "announcement:push:{$announcementId}", $msg, ['notification_type' => 'announcement', 'branch_id' => $branchId]);
}

} // function_exists guard
