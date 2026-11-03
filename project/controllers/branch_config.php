<?php
// =============================================================
//  controllers/branch_config.php
//  Online booking rules — one place for everything the patient
//  booking page and its APIs need to agree on:
//
//    • branches   name, address, hours, operating days, status
//                 (from `branches`; built-in list only if Supabase is down)
//    • slots      30-minute start times from open_time to close_time
//    • visits     length = sum of the chosen services' duration_minutes
//    • fit        a visit must end by closing time and must not overlap
//                 another booking at the same branch
//    • dates      bookable from tomorrow (Asia/Manila), on open days only
//
//  Used by: portal-web-scheduling.php, api/get_timeslots.php,
//           api/get_booked_days.php, api/portal_book_appointment.php
//  Zero HTML. Zero $_GET/$_POST reading.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/branch_controller.php'; // normalizeOperatingDays(), formatOperatingDays()

const BOOKING_TIMEZONE     = 'Asia/Manila';
const BOOKING_SLOT_MINUTES = 30;
const BOOKING_MAX_SERVICES = 3;

/**
 * Used only when the branches table can't be read, so booking still shows
 * the clinics. Keyed by the real branch_id. Keep in step with Branch Records.
 */
const BOOKING_FALLBACK_BRANCHES = [
    1 => ['branch_name' => 'Caloocan',    'address' => '132 Vista Verde Ave., Caloocan City, Metro Manila'],
    2 => ['branch_name' => 'Mandaluyong', 'address' => '565 Sgt. Bumatay St., Mandaluyong City, Metro Manila'],
    3 => ['branch_name' => 'Novaliches',  'address' => '108 Susano Rd., Novaliches, Quezon City 1117'],
];
const BOOKING_FALLBACK_OPEN  = '09:00';
const BOOKING_FALLBACK_CLOSE = '17:00';

// ─────────────────────────────────────────────────────────────
//  DATES
// ─────────────────────────────────────────────────────────────

function bookingTz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone(BOOKING_TIMEZONE);
}

/** Today's date in Manila, YYYY-MM-DD (PHP's own timezone setting is ignored). */
function bookingToday(): string
{
    return (new DateTimeImmutable('now', bookingTz()))->format('Y-m-d');
}

function isValidIsoDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    return checkdate($m, $d, $y);
}

/** 0 = Sunday … 6 = Saturday */
function weekdayOf(string $date): int
{
    return (int)(new DateTimeImmutable($date, bookingTz()))->format('w');
}

// ─────────────────────────────────────────────────────────────
//  BRANCHES
// ─────────────────────────────────────────────────────────────

/** "09:00:00" → 540 (minutes after midnight); null when invalid. */
function timeToMinutes(?string $t): ?int
{
    if (!$t || !preg_match('/^(\d{2}):(\d{2})/', $t, $m)) return null;
    return (int)$m[1] * 60 + (int)$m[2];
}

/** 540 → "09:00" */
function minutesToTime(int $min): string
{
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

/** 540 → "9:00 AM" */
function minutesToLabel(int $min): string
{
    $h = intdiv($min, 60) % 24;
    return sprintf('%d:%02d %s', ($h % 12) ?: 12, $min % 60, $h < 12 ? 'AM' : 'PM');
}

/** Clean one branch row into the shape the booking code uses. */
function normalizeBookingBranch(array $b): array
{
    $open  = timeToMinutes($b['open_time']  ?? null) ?? timeToMinutes(BOOKING_FALLBACK_OPEN);
    $close = timeToMinutes($b['close_time'] ?? null) ?? timeToMinutes(BOOKING_FALLBACK_CLOSE);
    $days  = normalizeOperatingDays($b['operating_days'] ?? null) ?: BR_DEFAULT_OPERATING_DAYS;

    return [
        'branch_id'   => (int)$b['branch_id'],
        'branch_name' => trim((string)($b['branch_name'] ?? '')),
        'address'     => trim((string)($b['address'] ?? '')),
        'open_min'    => $open,
        'close_min'   => $close,
        'open_time'   => minutesToTime($open),
        'close_time'  => minutesToTime($close),
        'days'        => $days,
        'days_label'  => formatOperatingDays($days),
        'hours_label' => minutesToLabel($open) . ' – ' . minutesToLabel($close),
        'active'      => ($b['status'] ?? 'active') === 'active' && $close > $open,
    ];
}

/**
 * All branches for booking, keyed by branch_id, ordered by name.
 * One request per PHP run (cached).
 */
function getBookingBranches(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cols = 'branch_id,branch_name,address,open_time,close_time,status';
    $res  = supabase_request('branches', 'GET', [], 'select=' . $cols . ',operating_days&order=branch_name.asc');
    if ($res['error']) {
        // operating_days not added yet → Mon–Sat
        $res = supabase_request('branches', 'GET', [], 'select=' . $cols . '&order=branch_name.asc');
    }

    $rows = (!$res['error'] && is_array($res['data'])) ? $res['data'] : [];
    if (!$rows) {
        foreach (BOOKING_FALLBACK_BRANCHES as $id => $b) {
            $rows[] = $b + ['branch_id' => $id, 'open_time' => BOOKING_FALLBACK_OPEN,
                            'close_time' => BOOKING_FALLBACK_CLOSE, 'status' => 'active'];
        }
    }

    $cache = [];
    foreach ($rows as $row) {
        $b = normalizeBookingBranch($row);
        $cache[$b['branch_id']] = $b;
    }
    return $cache;
}

function getBookingBranch(int $branchId): ?array
{
    return getBookingBranches()[$branchId] ?? null;
}

/**
 * Why a date can't be booked at a branch, or null when it can.
 * Codes: invalid | past | closed | inactive
 */
function dateUnavailableReason(array $branch, string $date): ?string
{
    if (!isValidIsoDate($date))                           return 'invalid';
    if ($date <= bookingToday())                          return 'past';     // same-day booking not allowed
    if (!$branch['active'])                               return 'inactive';
    if (!in_array(weekdayOf($date), $branch['days'], true)) return 'closed';
    return null;
}

// ─────────────────────────────────────────────────────────────
//  SERVICES / VISIT LENGTH
// ─────────────────────────────────────────────────────────────

/** Active services for the booking page (with duration when the column exists). */
function getBookableServices(): array
{
    $base = 'select=service_id,service_name,service_type,base_price,description';
    $res  = supabase_request('services', 'GET', [], $base . ',duration_minutes&status=eq.active&order=service_name.asc');
    if ($res['error']) {
        $res = supabase_request('services', 'GET', [], $base . '&status=eq.active&order=service_name.asc');
    }
    $rows = (!$res['error'] && is_array($res['data'])) ? $res['data'] : [];

    return array_map(fn($s) => [
        'service_id'       => (int)$s['service_id'],
        'service_name'     => (string)$s['service_name'],
        'service_type'     => (string)($s['service_type'] ?? ''),
        'base_price'       => round((float)($s['base_price'] ?? 0), 2),
        'description'      => (string)($s['description'] ?? ''),
        'duration_minutes' => max(BOOKING_SLOT_MINUTES / 2, (int)($s['duration_minutes'] ?? BOOKING_SLOT_MINUTES)),
    ], $rows);
}

/** Clean a list of service IDs: integers, unique, at most BOOKING_MAX_SERVICES. */
function cleanServiceIds(mixed $raw): array
{
    if (is_string($raw)) $raw = explode(',', $raw);
    if (!is_array($raw)) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn($i) => $i > 0)));
    return array_slice($ids, 0, BOOKING_MAX_SERVICES);
}

/**
 * Total visit length of the chosen services, read from the database
 * (never trusted from the browser).
 * Returns ['minutes' => int, 'error' => ?string]
 */
function visitDurationFor(array $serviceIds): array
{
    if (!$serviceIds) return ['minutes' => BOOKING_SLOT_MINUTES, 'error' => null];

    $q   = 'service_id=in.(' . implode(',', $serviceIds) . ')&select=service_id,status';
    $res = supabase_request('services', 'GET', [], $q . ',duration_minutes');
    if ($res['error']) $res = supabase_request('services', 'GET', [], $q);
    if ($res['error'] || !is_array($res['data'])) {
        return ['minutes' => 0, 'error' => 'Could not check the selected services. Please try again.'];
    }

    $found = [];
    foreach ($res['data'] as $s) $found[(int)$s['service_id']] = $s;

    $total = 0;
    foreach ($serviceIds as $id) {
        $s = $found[$id] ?? null;
        if (!$s || ($s['status'] ?? 'active') !== 'active') {
            return ['minutes' => 0, 'error' => 'One of the selected services is no longer available. Please choose again.'];
        }
        $total += (int)($s['duration_minutes'] ?? BOOKING_SLOT_MINUTES);
    }
    return ['minutes' => max(BOOKING_SLOT_MINUTES / 2, $total), 'error' => null];
}

/** Minutes a visit blocks on the schedule: rounded up to whole 30-minute slots. */
function blockedMinutes(int $duration): int
{
    return (int)(ceil(max(1, $duration) / BOOKING_SLOT_MINUTES) * BOOKING_SLOT_MINUTES);
}

/** 90 → "1 hr 30 min" */
function visitLengthLabel(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h && $m) return $h . ' hr ' . $m . ' min';
    return $h ? $h . ' hr' : $m . ' min';
}

// ─────────────────────────────────────────────────────────────
//  AVAILABILITY
// ─────────────────────────────────────────────────────────────

/**
 * Existing bookings at a branch between two dates (inclusive), grouped by date:
 *   ['2026-10-14' => [[startMin, endMin], …], …]
 * Cancelled and no-show appointments don't count. Appointments saved before
 * durations existed count as one 30-minute slot.
 */
function getBookedRanges(int $branchId, string $from, string $to): array
{
    $q = implode('&', [
        'branch_id=eq.'        . $branchId,
        'appointment_date=gte.' . $from,
        'appointment_date=lte.' . $to,
        'status=neq.cancelled',
        'status=neq.no_show',
        'select=appointment_date,appointment_time',
    ]);
    $res = supabase_request('appointments', 'GET', [], $q . ',duration_minutes');
    if ($res['error']) $res = supabase_request('appointments', 'GET', [], $q);
    if ($res['error'] || !is_array($res['data'])) return ['__error' => true];

    $out = [];
    foreach ($res['data'] as $row) {
        $start = timeToMinutes($row['appointment_time'] ?? null);
        if ($start === null) continue;
        $len = blockedMinutes((int)($row['duration_minutes'] ?? BOOKING_SLOT_MINUTES));
        $out[$row['appointment_date']][] = [$start, $start + $len];
    }
    return $out;
}

/**
 * Every start time of a branch's day, each marked available or not.
 *   [['time' => '09:00', 'label' => '9:00 AM', 'available' => true,  'reason' => null], …]
 * reason: 'booked' (overlaps a booking) | 'closing' (would end after closing)
 */
function buildDaySlots(array $branch, int $duration, array $ranges): array
{
    $slots = [];
    $block = blockedMinutes($duration);
    for ($t = $branch['open_min']; $t + BOOKING_SLOT_MINUTES <= $branch['close_min']; $t += BOOKING_SLOT_MINUTES) {
        $reason = null;
        foreach ($ranges as [$s, $e]) {
            if ($t < $e && $t + $block > $s) { $reason = 'booked'; break; }
        }
        if (!$reason && $t + $duration > $branch['close_min']) $reason = 'closing';

        $slots[] = [
            'time'      => minutesToTime($t),
            'label'     => minutesToLabel($t),
            'ends'      => minutesToLabel($t + $duration),
            'available' => $reason === null,
            'reason'    => $reason,
        ];
    }
    return $slots;
}

/** True when a visit of $duration can start at $time (HH:MM) given $ranges. */
function canStartAt(array $branch, string $time, int $duration, array $ranges): bool
{
    $t = timeToMinutes($time);
    if ($t === null || ($t - $branch['open_min']) % BOOKING_SLOT_MINUTES !== 0) return false;
    foreach (buildDaySlots($branch, $duration, $ranges) as $slot) {
        if ($slot['time'] === minutesToTime($t)) return $slot['available'];
    }
    return false; // not one of the branch's start times
}