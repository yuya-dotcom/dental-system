<?php
// =============================================================
//  controllers/staff_notifications.php — Staff Notification Center
//  (Owner / Admin / Dentist). Tables: database/proposed/
//  2026-11-19_staff_notification_center.sql. The patient side
//  (controllers/notification_helper.php, notification_service.php)
//  is separate and unchanged.
//
//  WRITE SIDE — staffn_* event hooks, called only AFTER the clinic
//  write they describe has succeeded:
//    appointments  staffn_appointment()      ← notif_appointment()
//    treatments    staffn_treatment()        ← notif_treatment()
//    inventory     staffn_stock_touched()    ← inv_move() (any stock change)
//                  staffn_inventory_sync()   ← RPC paths + throttled sweep
//                  staffn_stock_adjusted(), staffn_delivery_received()
//    billing       staffn_payment(), staffn_payment_failed(), staffn_payment_reversed()
//    patients      staffn_patient_registered(), staffn_patient_info()
//    branches      staffn_branch_updated(), staffn_branch_exception()
//    feedback      staffn_feedback()
//  Every event has a dedupe_key (unique in the table): repeating the same
//  event (double click, retry, overlapping sweep) never adds a second row.
//  Inserts run after the HTTP response (staffn_later), never throw and never
//  break the workflow that triggered them. Before the SQL is applied they
//  do nothing (staffn_ready()).
//
//  READ SIDE — used by api/staff_notifications.php only. Visibility is
//  decided here, on the server, for every read and every "mark as read":
//    • category allowed for the role (STAFFN_CATEGORIES) AND the account's
//      sidebar module for it (module_access.php)
//    • event audience contains the role (STAFFN_EVENTS)
//    • owner: every branch; admin / dentist: own branch + clinic-wide rows
//    • never your own action (actor_user_id)
// =============================================================

require_once __DIR__ . '/../dbconfig.php';

const STAFFN_TABLE        = 'staff_notifications';
const STAFFN_READS        = 'staff_notification_reads';
const STAFFN_STATE        = 'staff_notification_state';
const STAFFN_UNREAD_DAYS  = 30;    // older notifications never count as unread
const STAFFN_UNREAD_CAP   = 200;   // unread rows looked at per request (badge shows 99+)
const STAFFN_SWEEP_EVERY  = 600;   // seconds between inventory sweeps (per server)
const STAFFN_LOW_RATING   = 2;     // feedback rating at or below this = low-rated
const STAFFN_EWALLETS     = ['gcash' => 'GCash', 'maya' => 'Maya', 'grabpay' => 'GrabPay'];
const STAFFN_ROLES        = ['owner', 'admin', 'dentist'];

/** category => [label, icon, sidebar module (null = no module), roles that may receive it] */
const STAFFN_CATEGORIES = [
    'appointments' => ['Appointments',       'feather-calendar',       'appointments', ['owner', 'admin', 'dentist']],
    'inventory'    => ['Inventory',          'feather-package',        'inventory',    ['owner', 'admin', 'dentist']],
    'billing'      => ['Billing & Payments', 'feather-credit-card',    'billing',      ['owner', 'admin']],
    'patients'     => ['Patients',           'feather-users',          'patients',     ['owner', 'admin', 'dentist']],
    'treatments'   => ['Treatments',         'feather-activity',       'treatments',   ['owner', 'admin', 'dentist']],
    'branch'       => ['Branch Operations',  'feather-home',           null,           ['owner', 'admin', 'dentist']],
    'feedback'     => ['Patient Feedback',   'feather-message-square', null,           ['owner']],
];

/** event => [category, priority (critical | important | info), audience roles] */
const STAFFN_EVENTS = [
    'appointment_requested'   => ['appointments', 'important', ['owner', 'admin', 'dentist']],
    'appointment_booked'      => ['appointments', 'info',      ['owner', 'admin', 'dentist']],
    'appointment_approved'    => ['appointments', 'info',      ['owner', 'admin', 'dentist']],
    'appointment_cancelled'   => ['appointments', 'important', ['owner', 'admin', 'dentist']],
    'appointment_rescheduled' => ['appointments', 'important', ['owner', 'admin', 'dentist']],
    'appointment_no_show'     => ['appointments', 'info',      ['owner', 'admin', 'dentist']],
    'stock_low'               => ['inventory',    'important', ['owner', 'admin', 'dentist']],
    'stock_out'               => ['inventory',    'critical',  ['owner', 'admin', 'dentist']],
    'stock_expiring'          => ['inventory',    'important', ['owner', 'admin', 'dentist']],
    'stock_expired'           => ['inventory',    'important', ['owner', 'admin', 'dentist']],
    'stock_adjusted'          => ['inventory',    'info',      ['owner', 'admin']],
    'delivery_received'       => ['inventory',    'info',      ['owner', 'admin']],
    'payment_received'        => ['billing',      'info',      ['owner', 'admin']],
    'payment_ewallet'         => ['billing',      'info',      ['owner', 'admin']],
    'payment_failed'          => ['billing',      'critical',  ['owner', 'admin']],
    'payment_reversed'        => ['billing',      'important', ['owner', 'admin']],
    'patient_registered'      => ['patients',     'info',      ['owner', 'admin']],
    'patient_info'            => ['patients',     'important', ['owner', 'admin', 'dentist']],
    'treatment_created'       => ['treatments',   'info',      ['owner', 'admin', 'dentist']],
    'treatment_completed'     => ['treatments',   'info',      ['owner', 'admin', 'dentist']],
    'treatment_modified'      => ['treatments',   'info',      ['owner', 'admin', 'dentist']],
    'branch_exception'        => ['branch',       'info',      ['owner', 'admin', 'dentist']],
    'branch_hours'            => ['branch',       'important', ['owner', 'admin', 'dentist']],
    'branch_closed'           => ['branch',       'important', ['owner', 'admin', 'dentist']],
    'feedback_new'            => ['feedback',     'info',      ['owner']],
    'feedback_low'            => ['feedback',     'important', ['owner']],
    'feedback_complaint'      => ['feedback',     'important', ['owner']],
];

const STAFFN_PRIORITIES = ['critical' => 'Critical', 'important' => 'Important', 'info' => 'Informational'];

// ── Infrastructure ───────────────────────────────────────────

function _staffn_flag(string $name): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'es_staffn_' . $name . '_' . md5(SUPABASE_URL) . '.flag';
}

/** True once the tables exist (cached: 1 day when present, 60 s when missing). */
function staffn_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    $file = _staffn_flag('ready');
    $c = @file_get_contents($file);
    if ($c !== false && ($p = explode('|', $c)) && count($p) === 2) {
        if (time() - (int)$p[1] < ($p[0] === '1' ? 86400 : 60)) return $ready = ($p[0] === '1');
    }
    $r = supabase_request(STAFFN_TABLE, 'GET', [], 'select=notification_id&limit=1');
    $ready = ($r['status'] ?? 0) === 200;
    @file_put_contents($file, ($ready ? '1' : '0') . '|' . time());
    return $ready;
}

function staffn_ready_reset(): void
{
    @unlink(_staffn_flag('ready'));
}

/** The signed-in STAFF user id (never a portal patient), or null. */
function staffn_actor(): ?int
{
    if (session_status() === PHP_SESSION_NONE && !isset($_SESSION)) return null;
    $role = $_SESSION['role'] ?? '';
    return in_array($role, STAFFN_ROLES, true) && !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Runs $job after the HTTP response is sent, so a staff or patient action is
 * never slowed down by notification writes. CLI / tests: runs at once.
 */
function staffn_later(callable $job): void
{
    if (PHP_SAPI === 'cli' || defined('STAFFN_SYNC')) {
        try { $job(); } catch (Throwable $e) { error_log('staffn job failed: ' . $e->getMessage()); }
        return;
    }
    static $queued = false;
    $GLOBALS['__staffn_jobs'][] = $job;
    if ($queued) return;
    $queued = true;
    if (!headers_sent() && ob_get_level() === 0) ob_start();
    register_shutdown_function(function () {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true);
        @set_time_limit(120);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (!headers_sent() && ob_get_level() > 0) {
            header('Connection: close');
            header('Content-Length: ' . ob_get_length());
            while (ob_get_level() > 0) ob_end_flush();
            flush();
        }
        // Jobs may queue more jobs (e.g. a stock sync after a movement)
        for ($i = 0; $i < 5 && !empty($GLOBALS['__staffn_jobs']); $i++) {
            $jobs = $GLOBALS['__staffn_jobs'];
            $GLOBALS['__staffn_jobs'] = [];
            foreach ($jobs as $j) {
                try { $j(); } catch (Throwable $e) { error_log('staffn deferred job failed: ' . $e->getMessage()); }
            }
        }
    });
}

/** One notification row (not yet saved). Unknown events return null. */
function staffn_row(string $event, string $key, string $title, string $message, ?int $branchId, ?string $link, ?int $actor): ?array
{
    $ev = STAFFN_EVENTS[$event] ?? null;
    if (!$ev || $key === '') return null;
    return [
        'dedupe_key'    => mb_substr($key, 0, 200),
        'category'      => $ev[0],
        'event_type'    => $event,
        'priority'      => $ev[1],
        'title'         => mb_substr($title, 0, 120),
        'message'       => mb_substr($message, 0, 400),
        'branch_id'     => $branchId ?: null,
        'link_url'      => $link,
        'audience'      => '{' . implode(',', $ev[2]) . '}',
        'actor_user_id' => $actor,
    ];
}

/** Saves rows; rows whose dedupe_key already exists are skipped. */
function staffn_insert(array $rows): bool
{
    $rows = array_values(array_filter($rows));
    if (!$rows || !staffn_ready()) return false;
    try {
        $res = supabase_request(STAFFN_TABLE, 'POST', count($rows) === 1 ? $rows[0] : $rows,
            'on_conflict=dedupe_key', ['Prefer: resolution=ignore-duplicates']);
        $ok = ($res['status'] >= 200 && $res['status'] < 300) || $res['status'] === 409;
        if (!$ok) {
            if (in_array($res['status'], [404], true)) staffn_ready_reset();
            error_log('staffn_insert failed: ' . json_encode($res['error'] ?? $res['status']));
        }
        return $ok;
    } catch (Throwable $e) {
        error_log('staffn_insert failed: ' . $e->getMessage());
        return false;
    }
}

/** Queue one event (actor = the signed-in staff user, captured now). */
function staffn_emit(string $event, string $key, string $title, string $message, ?int $branchId = null, ?string $link = null): void
{
    $actor = staffn_actor();
    staffn_later(function () use ($event, $key, $title, $message, $branchId, $link, $actor) {
        staffn_insert([staffn_row($event, $key, $title, $message, $branchId, $link, $actor)]);
    });
}

function staffn_peso(float $n): string { return '₱' . number_format($n, 2); }

function staffn_when(?string $date, ?string $time = null): string
{
    $ts = strtotime(trim(($date ?? '') . ' ' . ($time ?? '')));
    if (!$ts) return (string)$date;
    return $time ? date('D, M j \a\t g:i A', $ts) : date('D, M j, Y', $ts);
}

function staffn_q(string $v): string
{
    return rawurlencode($v);
}

// ── Appointments ─────────────────────────────────────────────

/**
 * $a = appointment row after the write (appointment_id, status, date, time, branch_id),
 * $before = snapshot before it (null = new appointment).
 */
function staffn_appointment(array $a, ?array $before): void
{
    $id = (int)($a['appointment_id'] ?? 0);
    if ($id < 1) return;
    $status = (string)($a['status'] ?? '');
    $prev   = $before ? (string)($before['status'] ?? '') : null;
    $slot   = ($a['appointment_date'] ?? '') . 'T' . substr((string)($a['appointment_time'] ?? ''), 0, 5);
    $moved  = $before
        && (($before['appointment_date'] ?? '') !== ($a['appointment_date'] ?? '')
            || substr((string)($before['appointment_time'] ?? ''), 0, 5) !== substr((string)($a['appointment_time'] ?? ''), 0, 5));

    $events = [];
    if (!$before) {
        if ($status === 'pending')   $events[] = ['appointment_requested', "appt:{$id}:new"];
        if ($status === 'confirmed') $events[] = ['appointment_booked', "appt:{$id}:new"];
    } else {
        if ($moved && in_array($status, ['pending', 'confirmed'], true)) $events[] = ['appointment_rescheduled', "appt:{$id}:resched:{$slot}"];
        if ($prev !== $status) {
            if ($status === 'confirmed' && $prev === 'pending') $events[] = ['appointment_approved', "appt:{$id}:status:confirmed"];
            if ($status === 'cancelled') $events[] = ['appointment_cancelled', "appt:{$id}:status:cancelled"];
            if ($status === 'no_show')   $events[] = ['appointment_no_show', "appt:{$id}:status:no_show"];
        }
    }
    if (!$events) return;

    $actor     = staffn_actor();
    $byPatient = $actor === null && !empty($_SESSION['portal_account_id']);
    $old       = $before ? staffn_when($before['appointment_date'] ?? null, $before['appointment_time'] ?? null) : '';
    staffn_later(function () use ($id, $events, $actor, $byPatient, $old) {
        $r = supabase_request('appointments', 'GET', [], 'appointment_id=eq.' . $id
            . '&select=appointment_code,appointment_date,appointment_time,branch_id,status,patients(full_name)&limit=1');
        $row = $r['data'][0] ?? null;
        if (!$row) return;
        $name = trim((string)($row['patients']['full_name'] ?? '')) ?: 'A patient';
        $code = (string)($row['appointment_code'] ?? '');
        $when = staffn_when($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
        $ref  = $code !== '' ? " ({$code})" : '';
        $open = in_array($row['status'] ?? '', ['pending', 'confirmed', 'checked_in'], true);
        $date = (string)($row['appointment_date'] ?? '');
        $link = $open
            ? 'appointments-schedule.php?q=' . staffn_q($code) . ($date ? '&from=' . $date . '&to=' . $date : '')
            : 'appointments-records.php?q=' . staffn_q($code);
        $rows = [];
        foreach ($events as [$event, $key]) {
            [$title, $msg] = match ($event) {
                'appointment_requested'   => ['New appointment request', "{$name} requested {$when}{$ref}. Awaiting approval."],
                'appointment_booked'      => ['New appointment booked', "{$name} is booked for {$when}{$ref}."],
                'appointment_approved'    => ['Appointment approved', "{$name}'s appointment on {$when}{$ref} is confirmed."],
                'appointment_cancelled'   => ['Appointment cancelled', "{$name}'s appointment on {$when}{$ref} was cancelled" . ($byPatient ? ' by the patient.' : '.')],
                'appointment_rescheduled' => ['Appointment rescheduled', "{$name}'s appointment{$ref} moved" . ($old ? " from {$old}" : '') . " to {$when}."],
                'appointment_no_show'     => ['Marked as No-show', "{$name} did not arrive for {$when}{$ref}."],
            };
            $rows[] = staffn_row($event, $key, $title, $msg, isset($row['branch_id']) ? (int)$row['branch_id'] : null, $link, $actor);
        }
        staffn_insert($rows);
    });
}

// ── Treatments ───────────────────────────────────────────────

const STAFFN_TRT_FIELDS = ['status', 'dentist_id', 'service_id', 'tooth_number', 'treatment_date', 'current_stage'];

/**
 * $before = treatment row before the write (null = new). finish_treatment passes
 * ['status' => ''] (= just completed). Only creation, completion and changes to
 * STAFFN_TRT_FIELDS notify; note / cost edits don't.
 */
function staffn_treatment(int $treatmentId, ?array $before): void
{
    if ($treatmentId < 1) return;
    $actor = staffn_actor();
    staffn_later(function () use ($treatmentId, $before, $actor) {
        $r = supabase_request('treatments', 'GET', [], 'treatment_id=eq.' . $treatmentId
            . '&select=treatment_code,branch_id,' . implode(',', STAFFN_TRT_FIELDS) . ',services(service_name),patients(full_name)&limit=1');
        $t = $r['data'][0] ?? null;
        if (!$t) return;
        $status = (string)($t['status'] ?? '');
        if ($before === null) {
            $event = 'treatment_created'; $key = "trt:{$treatmentId}:created";
        } elseif ($status === 'completed' && ($before['status'] ?? '') !== 'completed') {
            $event = 'treatment_completed'; $key = "trt:{$treatmentId}:completed";
        } else {
            $changed = [];
            foreach (STAFFN_TRT_FIELDS as $f) {
                if (!array_key_exists($f, $before)) continue;
                if ((string)($before[$f] ?? '') !== (string)($t[$f] ?? '')) $changed[$f] = (string)($t[$f] ?? '');
            }
            if (!$changed) return;
            $event = 'treatment_modified'; $key = "trt:{$treatmentId}:mod:" . substr(md5(json_encode($changed)), 0, 12);
        }
        $name = trim((string)($t['patients']['full_name'] ?? '')) ?: 'A patient';
        $svc  = trim((string)($t['services']['service_name'] ?? '')) ?: 'Treatment';
        $code = (string)($t['treatment_code'] ?? '');
        $ref  = $code !== '' ? " ({$code})" : '';
        [$title, $msg] = match ($event) {
            'treatment_created'   => ['New treatment record', "{$svc} for {$name}{$ref} was added."],
            'treatment_completed' => ['Treatment completed', "{$svc} for {$name}{$ref} is completed."],
            'treatment_modified'  => ['Treatment updated', "{$svc} for {$name}{$ref} was updated" . ($status !== '' ? ' — now ' . ucwords(str_replace('_', ' ', $status)) . '.' : '.')],
        };
        staffn_insert([staffn_row($event, $key, $title, $msg, isset($t['branch_id']) ? (int)$t['branch_id'] : null,
            'treatments-records.php' . ($code !== '' ? '?q=' . staffn_q($code) : ''), $actor)]);
    });
}

// ── Inventory ────────────────────────────────────────────────

/** Any stock change: re-check that item's low / out-of-stock state after the response. */
function staffn_stock_touched(int $itemId): void
{
    if ($itemId < 1) return;
    static $pending = [];
    $first = !$pending;
    $pending[$itemId] = true;
    if (!$first && PHP_SAPI !== 'cli' && !defined('STAFFN_SYNC')) return;   // one sync covers every item of this request
    staffn_later(function () use (&$pending) {
        $ids = array_keys($pending);
        $pending = [];
        if ($ids) staffn_inventory_sync($ids);
    });
}

/**
 * Low / out-of-stock alerts follow the item's live state, once per shortage:
 *   qty <= 0          → "Out of stock" (critical); an open "Low stock" stays as it is
 *   0 < qty <= min    → "Low stock" (important); an open "Out of stock" is closed
 *   qty > min, archived or inactive → both closed
 * Open alerts use the key inv:stock:<item>:<kind>:open; closing renames it to
 * …:closed:<id>, so the next shortage of the same item is notified again.
 * $itemIds null = every item (sweep).
 */
function staffn_inventory_sync(?array $itemIds = null): void
{
    if (!staffn_ready()) return;
    $ids = $itemIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $itemIds))));
    if ($ids === []) return;

    $q = 'select=item_id,item_name,stock_quantity,min_stock,branch_id,status,archived_at&limit=2000';
    if ($ids !== null) $q .= '&item_id=in.(' . implode(',', $ids) . ')';
    $items = supabase_request('inventory', 'GET', [], $q);
    if (($items['status'] ?? 0) !== 200 || !is_array($items['data'])) return;

    if ($ids !== null) {
        $keys = [];
        foreach ($ids as $i) { $keys[] = "\"inv:stock:{$i}:low:open\""; $keys[] = "\"inv:stock:{$i}:out:open\""; }
        $oq = 'dedupe_key=in.(' . implode(',', $keys) . ')';
    } else {
        $oq = 'dedupe_key=like.' . staffn_q('inv:stock:*:open');
    }
    $openRes = supabase_request(STAFFN_TABLE, 'GET', [], $oq . '&select=notification_id,dedupe_key&limit=5000');
    if (($openRes['status'] ?? 0) !== 200 || !is_array($openRes['data'])) return;
    $open = [];
    foreach ($openRes['data'] as $o) $open[$o['dedupe_key']] = (int)$o['notification_id'];

    $new = []; $close = [];
    $seen = [];
    foreach ($items['data'] as $it) {
        $id  = (int)$it['item_id'];
        $seen[$id] = true;
        $qty = (int)$it['stock_quantity'];
        $min = (int)$it['min_stock'];
        $live = empty($it['archived_at']) && ($it['status'] ?? '') !== 'inactive';
        $lowKey = "inv:stock:{$id}:low:open"; $outKey = "inv:stock:{$id}:out:open";
        $name   = (string)($it['item_name'] ?? 'Item');
        $branch = !empty($it['branch_id']) ? (int)$it['branch_id'] : null;
        if ($live && $qty <= 0) {
            if (!isset($open[$outKey])) $new[] = staffn_row('stock_out', $outKey, 'Out of stock',
                "{$name} has run out (0 left). It can't be used in treatments until restocked.", $branch,
                'inventory-records.php?status=out_of_stock', null);
        } elseif ($live && $qty <= $min) {
            if (!isset($open[$lowKey])) $new[] = staffn_row('stock_low', $lowKey, 'Low stock',
                "{$name} is down to {$qty} (reorder level {$min}).", $branch,
                'inventory-records.php?status=low_stock', null);
            if (isset($open[$outKey])) $close[$outKey] = $open[$outKey];
        } else {
            foreach ([$lowKey, $outKey] as $k) if (isset($open[$k])) $close[$k] = $open[$k];
        }
    }
    // Open alerts for items that no longer exist (deleted) are closed too
    foreach ($open as $k => $nid) {
        if (preg_match('/^inv:stock:(\d+):/', $k, $m) && !isset($seen[(int)$m[1]]) && ($ids === null || in_array((int)$m[1], $ids, true))) $close[$k] = $nid;
    }
    if ($new) staffn_insert($new);
    foreach ($close as $k => $nid) {
        supabase_request(STAFFN_TABLE, 'PATCH', ['dedupe_key' => str_replace(':open', ':closed:' . $nid, $k)],
            'notification_id=eq.' . $nid . '&dedupe_key=eq.' . staffn_q($k));
    }
}

/** Expiring-soon / expired batches: one notification per batch and state, ever. */
function staffn_expiry_sync(): void
{
    if (!staffn_ready()) return;
    require_once __DIR__ . '/inventory_v2.php';
    if (!inv_v2_ready()) return;
    $today = date('Y-m-d');
    $r = supabase_request('inventory_batches', 'GET', [],
        'select=batch_id,item_id,branch_id,lot_number,expiry_date,quantity_remaining,inventory!inner(item_name,archived_at)'
        . '&quantity_remaining=gt.0&expiry_date=lte.' . date('Y-m-d', strtotime('+' . INV_NEAR_EXPIRY_DAYS . ' days'))
        . '&inventory.archived_at=is.null&order=expiry_date.asc&limit=500');
    if (($r['status'] ?? 0) !== 200 || !is_array($r['data'])) return;
    $rows = [];
    foreach ($r['data'] as $b) {
        $expired = $b['expiry_date'] < $today;
        $name = (string)($b['inventory']['item_name'] ?? 'Item');
        $lot  = trim((string)($b['lot_number'] ?? ''));
        $what = $name . ($lot !== '' ? " (Lot {$lot})" : '');
        $n    = (int)$b['quantity_remaining'];
        $day  = date('M j, Y', strtotime($b['expiry_date']));
        $rows[] = $expired
            ? staffn_row('stock_expired', 'inv:exp:' . (int)$b['batch_id'] . ':expired', 'Item expired',
                "{$what}: {$n} unit" . ($n === 1 ? '' : 's') . " expired on {$day}. Remove them from use.",
                !empty($b['branch_id']) ? (int)$b['branch_id'] : null, 'inventory-records.php?expiry=expired', null)
            : staffn_row('stock_expiring', 'inv:exp:' . (int)$b['batch_id'] . ':soon', 'Item nearing expiration',
                "{$what}: {$n} unit" . ($n === 1 ? ' expires' : 's expire') . " on {$day}.",
                !empty($b['branch_id']) ? (int)$b['branch_id'] : null, 'inventory-records.php?expiry=soon', null);
    }
    foreach (array_chunk($rows, 100) as $chunk) staffn_insert($chunk);
}

/** Throttled background sweep (stock levels + expiry), at most once per STAFFN_SWEEP_EVERY. */
function staffn_sweep(bool $force = false): bool
{
    if (!staffn_ready()) return false;
    $fh = @fopen(_staffn_flag('sweep'), 'c+');
    if (!$fh) return false;
    if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }
    $last = (int)trim((string)stream_get_contents($fh));
    if (!$force && $last && time() - $last < STAFFN_SWEEP_EVERY) { flock($fh, LOCK_UN); fclose($fh); return false; }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, (string)time()); fflush($fh);
    try {
        staffn_inventory_sync(null);
        staffn_expiry_sync();
        return true;
    } catch (Throwable $e) {
        error_log('staffn_sweep failed: ' . $e->getMessage());
        return false;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Manual stock corrections worth telling the owner / admin about (not routine stock-in). */
const STAFFN_ADJUST_TYPES = ['adjustment', 'stock_out', 'damaged', 'expired', 'wastage'];

function staffn_stock_adjusted(int $itemId, string $itemName, int $branchId, string $typeLabel, string $type,
                               int $change, ?int $before, ?int $after, string $reason, $movementId): void
{
    if (!in_array($type, STAFFN_ADJUST_TYPES, true)) return;
    $key = $movementId ? 'inv:mv:' . $movementId : 'inv:mv:' . $itemId . ':' . md5($type . $change . $reason . date('Y-m-d H:i'));
    $msg = sprintf('%s: %s %+d', $itemName, $typeLabel, $change)
         . ($before !== null && $after !== null ? " ({$before} → {$after})" : '')
         . ($reason !== '' ? '. Reason: ' . mb_substr($reason, 0, 120) : '') . '.';
    staffn_emit('stock_adjusted', $key, 'Stock adjustment recorded', $msg, $branchId ?: null,
        'inventory-stock-movement.php?item=' . $itemId);
}

function staffn_delivery_received(int $poId, string $poNo, int $branchId, string $receiptNo, array $lines, string $branchName): void
{
    $msg = ($poNo !== '' ? $poNo : 'Purchase order') . ' delivery received' . ($branchName !== '' ? " at {$branchName}" : '')
         . ': ' . mb_substr(implode(', ', $lines), 0, 220) . '.';
    staffn_emit('delivery_received', 'po:' . $poId . ':rcv:' . ($receiptNo !== '' ? $receiptNo : md5(json_encode($lines))),
        'Purchase delivery received', $msg, $branchId ?: null, 'inventory-procurement.php?tab=orders');
    staffn_later(fn() => staffn_sweep(true));   // stock went up: close low-stock alerts now
}

// ── Billing ──────────────────────────────────────────────────

function _staffn_invoice(int $invoiceId): ?array
{
    $r = supabase_request('invoices', 'GET', [], 'invoice_id=eq.' . $invoiceId . '&select=invoice_code,branch_id&limit=1');
    return $r['data'][0] ?? null;
}

function staffn_payment(int $paymentId, int $invoiceId, float $amount, ?string $method, float $newBalance): void
{
    if ($paymentId < 1) return;
    $actor = staffn_actor();
    staffn_later(function () use ($paymentId, $invoiceId, $amount, $method, $newBalance, $actor) {
        $inv  = _staffn_invoice($invoiceId);
        $code = (string)($inv['invoice_code'] ?? '') ?: ('#' . $invoiceId);
        $m    = strtolower(trim((string)$method));
        $ew   = STAFFN_EWALLETS[$m] ?? null;
        $how  = $ew ?? ($m !== '' ? ucwords(str_replace('_', ' ', $m)) : '');
        $msg  = staffn_peso($amount) . " received for {$code}" . ($how !== '' ? " via {$how}" : '') . '. '
              . ($newBalance > 0 ? 'Balance: ' . staffn_peso($newBalance) . '.' : 'Invoice fully paid.');
        staffn_insert([staffn_row($ew ? 'payment_ewallet' : 'payment_received', 'pay:' . $paymentId,
            $ew ? 'E-wallet payment received' : 'Payment received', $msg,
            isset($inv['branch_id']) ? (int)$inv['branch_id'] : null, 'billing-records.php?q=' . staffn_q($code), $actor)]);
    });
}

/** $stage: 'save' (payment row not saved) or 'totals' (saved, invoice totals not updated). */
function staffn_payment_failed(int $invoiceId, float $amount, string $stage, ?int $paymentId = null): void
{
    $actor = staffn_actor();
    staffn_later(function () use ($invoiceId, $amount, $stage, $paymentId, $actor) {
        $inv  = _staffn_invoice($invoiceId);
        $code = (string)($inv['invoice_code'] ?? '') ?: ('#' . $invoiceId);
        $msg  = $stage === 'totals'
            ? 'Payment of ' . staffn_peso($amount) . " for {$code} was saved but the invoice balance was not updated. Please review this invoice."
            : 'A payment of ' . staffn_peso($amount) . " for {$code} could not be saved. Please check the invoice and record it again.";
        $key = $stage === 'totals' && $paymentId
            ? 'pay:' . $paymentId . ':totals'
            : 'payfail:' . $invoiceId . ':' . md5(number_format($amount, 2) . date('Y-m-d H:i'));   // a double click = one alert
        staffn_insert([staffn_row('payment_failed', $key, 'Payment failed', $msg,
            isset($inv['branch_id']) ? (int)$inv['branch_id'] : null, 'billing-records.php?q=' . staffn_q($code), $actor)]);
    });
}

function staffn_payment_reversed(int $paymentId, int $invoiceId, float $amount): void
{
    $actor = staffn_actor();
    staffn_later(function () use ($paymentId, $invoiceId, $amount, $actor) {
        $inv  = _staffn_invoice($invoiceId);
        $code = (string)($inv['invoice_code'] ?? '') ?: ('#' . $invoiceId);
        staffn_insert([staffn_row('payment_reversed', 'pay:' . $paymentId . ':reversed', 'Payment reversed',
            'A payment of ' . staffn_peso($amount) . " on {$code} was removed and the balance restored.",
            isset($inv['branch_id']) ? (int)$inv['branch_id'] : null, 'billing-records.php?q=' . staffn_q($code), $actor)]);
    });
}

// ── Patients ─────────────────────────────────────────────────

function staffn_patient_registered(int $accountId, string $name, bool $google = false): void
{
    if ($accountId < 1) return;
    $name = trim($name) ?: 'A new patient';
    staffn_emit('patient_registered', 'pacct:' . $accountId . ':registered', 'New patient registered',
        "{$name} created a Patient Portal account" . ($google ? ' with Google' : '') . '.', null,
        'patients-list.php' . (trim($name) !== '' ? '?q=' . staffn_q($name) : ''));
}

/**
 * A patient submitted medical information from the portal ($what = e.g.
 * 'medical questionnaire'). The message never contains the information itself.
 * The branch is the one of the patient's latest appointment (none = all branches).
 */
function staffn_patient_info(?int $patientId, int $accountId, string $what, string $key): void
{
    staffn_later(function () use ($patientId, $accountId, $what, $key) {
        $name = ''; $branch = null;
        if ($patientId) {
            $p = supabase_request('patients', 'GET', [], 'patient_id=eq.' . $patientId . '&select=full_name&limit=1');
            $name = trim((string)($p['data'][0]['full_name'] ?? ''));
            $a = supabase_request('appointments', 'GET', [], 'patient_id=eq.' . $patientId . '&select=branch_id&order=appointment_date.desc&limit=1');
            $branch = !empty($a['data'][0]['branch_id']) ? (int)$a['data'][0]['branch_id'] : null;
        } else {
            $p = supabase_request('patient_accounts', 'GET', [], 'account_id=eq.' . $accountId . '&select=first_name,last_name&limit=1');
            $name = trim(($p['data'][0]['first_name'] ?? '') . ' ' . ($p['data'][0]['last_name'] ?? ''));
        }
        $name = $name !== '' ? $name : 'A patient';
        staffn_insert([staffn_row('patient_info', $key, 'Patient information submitted',
            "{$name} submitted their {$what} in the Patient Portal. Review it before the next visit.", $branch,
            $patientId ? 'patient-records.php?id=' . $patientId : 'patients-list.php', null)]);
    });
}

// ── Branches ─────────────────────────────────────────────────

/** $before / $after: changed audit fields only (branch_crud.php updateBranch). */
function staffn_branch_updated(int $branchId, string $branchName, array $before, array $after, string $hoursText): void
{
    $hours = array_intersect_key($after, array_flip(['open_time', 'close_time', 'operating_days']));
    if ($hours) {
        staffn_emit('branch_hours', 'br:' . $branchId . ':hours:' . substr(md5(json_encode($hours)), 0, 12),
            'Operating hours changed', "{$branchName} now opens {$hoursText}.", $branchId, 'branches-records.php');
    }
    if (($after['status'] ?? null) === 'inactive' && ($before['status'] ?? null) !== 'inactive') {
        staffn_emit('branch_closed', 'br:' . $branchId . ':inactive:' . date('Y-m-d\TH:i'),
            'Branch temporarily closed', "{$branchName} was set to Inactive. Online booking is stopped; booked patients keep their appointments.",
            $branchId, 'branches-records.php');
    }
}

function staffn_branch_exception(int $branchId, string $branchName, string $date, bool $isOpen, string $reason, bool $changed): void
{
    $day = date('D, M j, Y', strtotime($date));
    $why = $reason !== '' ? ' — ' . mb_substr($reason, 0, 120) : '';
    $key = 'brx:' . $branchId . ':' . $date . ':' . ($isOpen ? 'open' : 'closed') . ':' . substr(md5($reason), 0, 8);
    if ($isOpen) {
        staffn_emit('branch_exception', $key, 'Schedule exception ' . ($changed ? 'updated' : 'added'),
            "{$branchName} will be OPEN on {$day}{$why}.", $branchId, 'branches-records.php');
    } else {
        staffn_emit('branch_closed', $key, 'Branch temporarily closed',
            "{$branchName} will be CLOSED on {$day}{$why}.", $branchId, 'branches-records.php');
    }
}

// ── Feedback (Owner only; never identifies the patient) ──────

function staffn_feedback(int $feedbackId, int $rating, bool $hasMessage, ?int $branchId, ?string $dentistName): void
{
    if ($feedbackId < 1) return;
    $low   = $rating <= STAFFN_LOW_RATING;
    $event = $low ? ($hasMessage ? 'feedback_complaint' : 'feedback_low') : 'feedback_new';
    $about = trim((string)$dentistName) !== '' ? ' for ' . trim($dentistName) : '';
    [$title, $msg] = match ($event) {
        'feedback_complaint' => ['Complaint received', "A patient rated their visit{$about} {$rating}/5 and left a comment."],
        'feedback_low'       => ['Low-rated feedback', "A patient rated their visit{$about} {$rating}/5."],
        'feedback_new'       => ['New feedback', "A patient rated their visit{$about} {$rating}/5" . ($hasMessage ? ' and left a comment.' : '.')],
    };
    staffn_emit($event, 'fb:' . $feedbackId, $title, $msg, $branchId, 'feedback-records.php' . ($low ? '?rating=' . $rating : ''));
}

// =============================================================
//  READ SIDE
// =============================================================

/** The signed-in staff viewer, or null. ['id','role','branch','cats'] */
function staffn_viewer(): ?array
{
    $role = $_SESSION['role'] ?? '';
    $uid  = (int)($_SESSION['user_id'] ?? 0);
    if ($uid < 1 || !in_array($role, STAFFN_ROLES, true)) return null;
    $branch = $role === 'owner' ? 0 : (int)($_SESSION['branch_id'] ?? 0);
    if ($role !== 'owner' && $branch < 1) return null;
    require_once __DIR__ . '/module_access.php';
    $cats = [];
    foreach (STAFFN_CATEGORIES as $c => [$label, $icon, $module, $roles]) {
        if (!in_array($role, $roles, true)) continue;
        if ($module !== null && $role !== 'owner' && !esCanUseModule($module)) continue;
        $cats[] = $c;
    }
    return ['id' => $uid, 'role' => $role, 'branch' => $branch, 'cats' => $cats];
}

/** PostgREST filters for everything $v may see (+ optional category / branch / priority). */
function staffn_scope(array $v, string $category = '', int $branch = 0, string $priority = ''): ?array
{
    $cats = $category !== '' ? array_values(array_intersect($v['cats'], [$category])) : $v['cats'];
    if (!$cats) return null;
    $f = [
        'category=in.(' . implode(',', $cats) . ')',
        'audience=cs.' . staffn_q('{' . $v['role'] . '}'),
    ];
    $and = ['or(actor_user_id.is.null,actor_user_id.neq.' . $v['id'] . ')'];
    if ($v['role'] !== 'owner') {
        $and[] = 'or(branch_id.is.null,branch_id.eq.' . $v['branch'] . ')';
    } elseif ($branch > 0) {
        $f[] = 'branch_id=eq.' . $branch;
    }
    if (isset(STAFFN_PRIORITIES[$priority])) $f[] = 'priority=eq.' . $priority;
    $f[] = 'and=(' . implode(',', $and) . ')';
    return $f;
}

/** Everything created at or before this moment counts as read (Mark All / age limit). */
function staffn_watermark(array $v): string
{
    $floor = gmdate('Y-m-d\TH:i:s\Z', time() - STAFFN_UNREAD_DAYS * 86400);
    $r = supabase_request(STAFFN_STATE, 'GET', [], 'user_id=eq.' . $v['id'] . '&select=read_all_at&limit=1');
    $at = $r['data'][0]['read_all_at'] ?? null;
    if ($at && strtotime($at) > strtotime($floor)) return gmdate('Y-m-d\TH:i:s\Z', strtotime($at));
    return $floor;
}

/** Ids among $ids this user marked read. */
function staffn_read_ids(array $v, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $out = [];
    foreach (array_chunk($ids, 150) as $chunk) {
        $r = supabase_request(STAFFN_READS, 'GET', [], 'user_id=eq.' . $v['id'] . '&notification_id=in.(' . implode(',', $chunk) . ')&select=notification_id');
        foreach (($r['data'] ?? []) as $x) if (isset($x['notification_id'])) $out[(int)$x['notification_id']] = true;
    }
    return $out;
}

/** Unread rows (newest first, at most STAFFN_UNREAD_CAP) for the scope. */
function staffn_unread_rows(array $v, array $scope, string $select): array
{
    $wm = staffn_watermark($v);
    $r = supabase_request(STAFFN_TABLE, 'GET', [], implode('&', array_merge($scope, [
        'created_at=gt.' . staffn_q($wm), 'select=' . $select,
        'order=created_at.desc,notification_id.desc', 'limit=' . STAFFN_UNREAD_CAP,
    ])));
    if (($r['status'] ?? 0) !== 200 || !is_array($r['data'])) return [];
    $read = staffn_read_ids($v, array_column($r['data'], 'notification_id'));
    return array_values(array_filter($r['data'], fn($n) => !isset($read[(int)$n['notification_id']])));
}

function staffn_unread_count(array $v): int
{
    $scope = staffn_scope($v);
    return $scope ? count(staffn_unread_rows($v, $scope, 'notification_id')) : 0;
}

const STAFFN_SELECT = 'notification_id,category,event_type,priority,title,message,branch_id,link_url,created_at,branches(branch_name)';

/**
 * One page of notifications. $state: all | unread | read.
 * Returns ['rows' => [...], 'total' => int, 'unread' => int].
 */
function staffn_list(array $v, string $state = 'all', string $category = '', int $page = 1, int $per = 15, int $branch = 0, string $priority = ''): array
{
    $per   = max(1, min(50, $per));
    $page  = max(1, $page);
    $scope = staffn_scope($v, $category, $branch, $priority);
    if (!$scope) return ['rows' => [], 'total' => 0];

    if ($state === 'unread') {
        $all  = staffn_unread_rows($v, $scope, STAFFN_SELECT);
        $rows = array_slice($all, ($page - 1) * $per, $per);
        return ['rows' => array_map(fn($n) => staffn_present($v, $n, true), $rows), 'total' => count($all)];
    }

    $wm = staffn_watermark($v);
    $extra = [];
    if ($state === 'read') {
        $rr = supabase_request(STAFFN_READS, 'GET', [], 'user_id=eq.' . $v['id'] . '&select=notification_id&order=read_at.desc&limit=500');
        $ids = array_map('intval', array_column(is_array($rr['data'] ?? null) ? $rr['data'] : [], 'notification_id'));
        // "and=(…)" is already in $scope: add the read condition to it
        foreach ($scope as $i => $f) {
            if (str_starts_with($f, 'and=(')) {
                $cond = $ids ? 'or(created_at.lte.' . $wm . ',notification_id.in.(' . implode(',', $ids) . '))' : 'created_at.lte.' . $wm;
                $scope[$i] = substr($f, 0, -1) . ',' . $cond . ')';
            }
        }
    }
    $r = supabase_request(STAFFN_TABLE, 'GET', [], implode('&', array_merge($scope, $extra, [
        'select=' . STAFFN_SELECT, 'order=created_at.desc,notification_id.desc',
        'limit=' . $per, 'offset=' . (($page - 1) * $per),
    ])), ['Prefer: count=exact']);
    if (($r['status'] ?? 0) !== 200 || !is_array($r['data'])) return ['rows' => [], 'total' => 0, 'error' => true];
    $total = preg_match('#/(\d+)$#', (string)($r['headers']['content-range'] ?? ''), $m) ? (int)$m[1] : count($r['data']);
    $read  = staffn_read_ids($v, array_column($r['data'], 'notification_id'));
    $wmTs  = strtotime($wm);
    $rows  = array_map(fn($n) => staffn_present($v, $n,
        !isset($read[(int)$n['notification_id']]) && strtotime($n['created_at']) > $wmTs), $r['data']);
    return ['rows' => $rows, 'total' => $total];
}

/** A page the viewer may open, else a safe fallback for the category (or null). */
function staffn_link_for(array $v, ?string $link, string $category): ?string
{
    if ($link === null || $link === '' || preg_match('#^(?:[a-z]+:|//|/)#i', $link)) return null;
    $page = basename(parse_url($link, PHP_URL_PATH) ?: '');
    if ($v['role'] === 'owner') return $link;
    $ownerOnly = ['branches-records.php', 'feedback-records.php', 'dentist-records.php', 'accounts-records.php', 'service-pricing.php'];
    if (in_array($page, $ownerOnly, true)) {
        return $category === 'branch' && esCanUseModule('appointments') ? 'appointments-schedule.php' : null;
    }
    if ($page === 'billing-records.php' && $v['role'] !== 'admin') return null;
    $mod = esPageModule($page);
    return $mod === null || esCanUseModule($mod) ? $link : null;
}

/** What the browser receives for one notification (no ids of patients / payments). */
function staffn_present(array $v, array $n, bool $unread): array
{
    $cat = STAFFN_CATEGORIES[$n['category']] ?? ['Other', 'feather-bell'];
    return [
        'id'       => (int)$n['notification_id'],
        'category' => (string)$n['category'],
        'cat_label'=> $cat[0],
        'icon'     => $cat[1],
        'priority' => (string)$n['priority'],
        'title'    => (string)$n['title'],
        'message'  => (string)$n['message'],
        'branch'   => (string)($n['branches']['branch_name'] ?? ''),
        'at'       => gmdate('Y-m-d\TH:i:s\Z', strtotime($n['created_at'])),
        'unread'   => $unread,
        'url'      => staffn_link_for($v, $n['link_url'] ?? null, (string)$n['category']),
    ];
}

/** Marks the given ids read — only ids this viewer may see. Returns how many. */
function staffn_mark_read(array $v, array $ids): int
{
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, STAFFN_UNREAD_CAP);
    $scope = staffn_scope($v);
    if (!$ids || !$scope) return 0;
    $r = supabase_request(STAFFN_TABLE, 'GET', [], implode('&', array_merge($scope, [
        'notification_id=in.(' . implode(',', $ids) . ')', 'select=notification_id',
    ])));
    $ok = array_map('intval', array_column(is_array($r['data'] ?? null) ? $r['data'] : [], 'notification_id'));
    if (!$ok) return 0;
    $now = gmdate('c');
    $w = supabase_request(STAFFN_READS, 'POST', array_map(fn($id) => ['user_id' => $v['id'], 'notification_id' => $id, 'read_at' => $now], $ok),
        'on_conflict=user_id,notification_id', ['Prefer: resolution=ignore-duplicates']);
    return ($w['status'] >= 200 && $w['status'] < 300) ? count($ok) : 0;
}

/** Mark All as Read: everything (watermark), or only one category's unread rows. */
function staffn_mark_all(array $v, string $category = ''): bool
{
    if ($category === '') {
        $w = supabase_request(STAFFN_STATE, 'POST', ['user_id' => $v['id'], 'read_all_at' => gmdate('c')],
            'on_conflict=user_id', ['Prefer: resolution=merge-duplicates']);
        return $w['status'] >= 200 && $w['status'] < 300;
    }
    $scope = staffn_scope($v, $category);
    if (!$scope) return false;
    $ids = array_column(staffn_unread_rows($v, $scope, 'notification_id'), 'notification_id');
    return !$ids || staffn_mark_read($v, $ids) > 0;
}

/** Inventory tasks waiting for this user (unchanged from the old bell). */
function staffn_tasks(): array
{
    require_once __DIR__ . '/module_access.php';
    if (($_SESSION['role'] ?? '') !== 'owner' && !esCanUseModule('inventory')) return [];
    require_once __DIR__ . '/admin_batch.php';
    require_once __DIR__ . '/branch_controller.php';
    $specs = admin_navbar_extra_specs();
    $x = [];
    foreach ($specs as $k => $sp) {
        $r = admin_get($sp['table'], $sp['query']);
        $x[$k] = (($r['status'] ?? 0) === 200 && is_array($r['data'])) ? $r['data'] : [];
    }
    $bn = [];
    if (!empty($x['trf']) || !empty($x['pr'])) foreach (getAllBranches() as $b) $bn[(int)$b['branch_id']] = $b['branch_name'];
    $out = [];
    foreach ($x['trf'] ?? [] as $t) {
        $what = ['pending' => 'Transfer to approve', 'approved' => 'Transfer to send', 'in_transit' => 'Transfer to receive'][$t['status']] ?? 'Transfer';
        $out[] = ['icon' => 'feather-truck', 'title' => $what . ' · ' . $t['transfer_no'],
                  'sub' => ($bn[(int)$t['from_branch_id']] ?? '?') . ' → ' . ($bn[(int)$t['to_branch_id']] ?? '?'),
                  'url' => 'inventory-transfers.php?status=' . $t['status']];
    }
    foreach ($x['pr'] ?? [] as $p) {
        $out[] = ['icon' => 'feather-file-plus', 'title' => 'Purchase request to approve · ' . $p['pr_no'],
                  'sub' => $bn[(int)$p['branch_id']] ?? '', 'url' => 'inventory-procurement.php?status=pending'];
    }
    foreach ($x['po'] ?? [] as $o) {
        $out[] = ['icon' => 'feather-shopping-cart', 'title' => 'Delivery due · ' . $o['po_no'],
                  'sub' => ($o['suppliers']['supplier_name'] ?? '') . ' · expected ' . date('M j', strtotime($o['expected_date'])),
                  'url' => 'inventory-procurement.php?tab=orders&status=open'];
    }
    return $out;
}

/** Categories the viewer may filter by: [key => label]. */
function staffn_category_options(array $v): array
{
    $o = [];
    foreach ($v['cats'] as $c) $o[$c] = STAFFN_CATEGORIES[$c][0];
    return $o;
}

