<?php
// =============================================================
//  controllers/appointment_lifecycle.php
//  One place for the appointment status rules shared by the staff side,
//  the Patient Portal and the cron script:
//
//    • NO SHOW   aptSweepNoShows(): pending / confirmed appointments whose
//                booked visit has ENDED (Asia/Manila) and that were never
//                checked in become no_show. Runs from the server on staff
//                pages, staff + portal APIs, the availability APIs and
//                cron/mark_no_shows.php — not from the browser. Throttled
//                (one sweep per APT_NO_SHOW_EVERY seconds per server) and
//                idempotent: the PATCH is filtered on status=in.(pending,
//                confirmed), so a row is changed (and logged) only once.
//
//    • CANCELLED aptCancel(): one cancellation write for patients (portal)
//                and staff (schedule / records). Stores who, when and why in
//                the cancellation columns (database/2026-11-12_appointment_
//                cancellation.sql) AND as a labelled line in notes, so the
//                details survive even before the migration is run.
//                aptCancellationInfo() reads it back (columns first, then
//                the notes line, else "Not recorded").
//
//    • PORTAL    aptAccountForPatient(): the portal account linked to a
//                patient, stored on staff-made appointments so the patient
//                sees them in the portal.
//
//  Appointment Records is the same `appointments` table (no copy), so a
//  cancelled or no-show appointment is in Records exactly once.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
// audit_controller declares getAppointmentInfoForAudit() unguarded, so it must
// load before appointment_controller (pulled in by branch_config), whose copy is guarded
require_once __DIR__ . '/audit_controller.php';
require_once __DIR__ . '/branch_config.php';          // bookingTz(), blockedMinutes(), BOOKING_SLOT_MINUTES
require_once __DIR__ . '/notification_helper.php';    // notif_appointment()

const APT_NO_SHOW_EVERY        = 60;     // seconds between sweeps (per server)
const APT_NO_SHOW_BATCH        = 300;    // rows looked at per sweep
const APT_CANCEL_REASON_MAX    = 500;
const APT_CANCEL_COLS          = ['cancelled_at', 'cancelled_by_type', 'cancelled_by_user_id',
                                  'cancelled_by_account_id', 'cancelled_by_name', 'cancellation_reason'];
const APT_CANCEL_COLS_SELECT   = 'cancelled_at,cancelled_by_type,cancelled_by_user_id,cancelled_by_account_id,cancelled_by_name,cancellation_reason';

// ─────────────────────────────────────────────────────────────
//  Schema probe: are the cancellation columns there yet?
// ─────────────────────────────────────────────────────────────

function _aptFlagFile(string $name): string
{
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'es_' . $name . '_' . substr(md5(__DIR__), 0, 10);
}

/** True when a PostgREST error is about one of $cols not existing (migration not run yet). */
function aptIsMissingColumnError(array $res, array $cols = APT_CANCEL_COLS): bool
{
    if (($res['status'] ?? 0) < 400) return false;
    $err = is_string($res['error'] ?? null) ? $res['error'] : json_encode($res['error'] ?? $res['data'] ?? '');
    $err = (string)$err;
    if (!str_contains($err, '42703') && !str_contains($err, 'PGRST204') && !preg_match('/column|does not exist|schema cache/i', $err)) return false;
    foreach ($cols as $c) if (str_contains($err, $c)) return true;
    return false;
}

/** Remembered for 10 minutes after a "column does not exist" answer, so reads don't retry every time. */
function aptCancelColsKnownMissing(): bool
{
    $f = _aptFlagFile('cancel_cols_missing');
    return is_file($f) && (time() - (int)@filemtime($f)) < 600;
}

function aptCancelColsMarkMissing(bool $missing): void
{
    $f = _aptFlagFile('cancel_cols_missing');
    if ($missing) @touch($f); elseif (is_file($f)) @unlink($f);
}

// ─────────────────────────────────────────────────────────────
//  PORTAL LINK
// ─────────────────────────────────────────────────────────────

/** The portal account of a patient (lowest account_id), or null for a walk-in without an account. */
function aptAccountForPatient(int $patientId): ?int
{
    if ($patientId < 1) return null;
    $r = supabase_request('patient_accounts', 'GET', [],
        'patient_id=eq.' . $patientId . '&select=account_id&order=account_id.asc&limit=1');
    $id = (int)($r['data'][0]['account_id'] ?? 0);
    return $id > 0 ? $id : null;
}

// ─────────────────────────────────────────────────────────────
//  CANCELLATION
// ─────────────────────────────────────────────────────────────

/** Trim / collapse spaces. Returns [reason, errorCode|null]. */
function aptCleanCancelReason($raw): array
{
    $reason = trim(preg_replace('/\s+/u', ' ', (string)($raw ?? '')));
    if ($reason === '') return ['', 'REASON_REQUIRED'];
    if (mb_strlen($reason) > APT_CANCEL_REASON_MAX) return [$reason, 'REASON_TOO_LONG'];
    return [$reason, null];
}

/** "Owner" / "Admin" / "Dentist" */
function aptRoleLabel(?string $role): string
{
    return ['owner' => 'Owner', 'admin' => 'Admin', 'dentist' => 'Dentist'][(string)$role] ?? 'Staff';
}

/**
 * Cancel one appointment.
 *   $actor = ['type' => 'patient', 'account_id' => int, 'name' => string]
 *          | ['type' => 'staff',   'user_id' => int, 'name' => string, 'role' => string]
 *   $guard  extra PostgREST filter (e.g. '&patient_id=eq.5' for the portal)
 *   $from   statuses that may be cancelled (the PATCH is filtered on them, so a
 *           status that changed in the meantime is never overwritten)
 * Returns ['ok' => bool, 'row' => ?array, 'prev' => ?string, 'code' => ?string, 'message' => ?string,
 *          'stored_in_columns' => bool]
 */
function aptCancel(int $appointmentId, array $actor, string $reason, array $from, string $guard = ''): array
{
    $fail = fn(string $code, string $msg) => ['ok' => false, 'row' => null, 'prev' => null, 'code' => $code, 'message' => $msg, 'stored_in_columns' => false];

    $cur = supabase_request('appointments', 'GET', [], 'appointment_id=eq.' . $appointmentId . $guard .
        '&select=' . NOTIF_APPT_SELECT . ',notes&limit=1');
    $row = $cur['data'][0] ?? null;
    if (!$row) return $fail('NOT_FOUND', 'Appointment not found.');
    $prev = (string)($row['status'] ?? '');
    if (!in_array($prev, $from, true)) {
        $label = ['completed' => 'completed', 'cancelled' => 'already cancelled', 'no_show' => 'marked as no-show',
                  'checked_in' => 'already checked in'][$prev] ?? 'no longer active';
        $r = $fail('NOT_CANCELLABLE', "This appointment is $label, so it can't be cancelled.");
        $r['prev'] = $prev;
        return $r;
    }

    $isPatient = ($actor['type'] ?? '') === 'patient';
    $name      = trim((string)($actor['name'] ?? '')) ?: ($isPatient ? 'Patient' : 'Staff');
    $now       = new DateTimeImmutable('now', bookingTz());
    $who       = $isPatient ? 'patient' : $name . ' (' . aptRoleLabel($actor['role'] ?? null) . ')';
    // Same labelled line the portal always wrote; staff lines name the person.
    $line      = 'Cancelled by ' . $who . ' on ' . $now->format('M j, Y g:i A') . ' — Reason: ' . $reason;
    $prior     = trim((string)($row['notes'] ?? ''));

    $base = [
        'status'     => 'cancelled',
        'notes'      => $prior === '' ? $line : $prior . "\n" . $line,
        'updated_at' => $now->format('c'),
    ];
    $cols = [
        'cancelled_at'            => $now->format('c'),
        'cancelled_by_type'       => $isPatient ? 'patient' : 'staff',
        'cancelled_by_user_id'    => $isPatient ? null : ((int)($actor['user_id'] ?? 0) ?: null),
        'cancelled_by_account_id' => $isPatient ? ((int)($actor['account_id'] ?? 0) ?: null) : null,
        'cancelled_by_name'       => $isPatient ? $name : $name . ' (' . aptRoleLabel($actor['role'] ?? null) . ')',
        'cancellation_reason'     => $reason,
    ];
    $filter = 'appointment_id=eq.' . $appointmentId . $guard . '&status=in.(' . implode(',', $from) . ')';

    $inCols = !aptCancelColsKnownMissing();
    $res = supabase_request('appointments', 'PATCH', $inCols ? $base + $cols : $base, $filter);
    if ($inCols && aptIsMissingColumnError($res)) {
        aptCancelColsMarkMissing(true);
        $inCols = false;
        $res = supabase_request('appointments', 'PATCH', $base, $filter);
    }
    if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300 || empty($res['data'][0])) {
        return $fail('CHANGED', 'The appointment could not be cancelled. Its status may have just changed — please refresh and try again.');
    }
    if ($inCols) aptCancelColsMarkMissing(false);

    notif_appointment($res['data'][0], ['status' => $prev] + $res['data'][0]);
    return ['ok' => true, 'row' => $res['data'][0], 'prev' => $prev, 'code' => null, 'message' => null, 'stored_in_columns' => $inCols];
}

/**
 * Who cancelled, when and why — for a CANCELLED appointment row (null otherwise).
 * Reads the cancellation columns; for older rows, the labelled notes line;
 * fields that were never captured are null (shown as "Not recorded").
 *   ['by' => ?string, 'by_type' => 'patient'|'staff'|null, 'at' => ?ISO, 'reason' => ?string,
 *    'source' => 'recorded'|'notes'|'none']
 */
function aptCancellationInfo(array $apt): ?array
{
    if (($apt['status'] ?? '') !== 'cancelled') return null;

    if (!empty($apt['cancelled_at']) || !empty($apt['cancellation_reason']) || !empty($apt['cancelled_by_type'])) {
        $type = in_array($apt['cancelled_by_type'] ?? '', ['patient', 'staff'], true) ? $apt['cancelled_by_type'] : null;
        $by   = trim((string)($apt['cancelled_by_name'] ?? '')) ?: null;
        if ($type === 'patient' && !$by) $by = trim((string)($apt['patients']['full_name'] ?? '')) ?: 'Patient';
        return ['by' => $by, 'by_type' => $type, 'at' => $apt['cancelled_at'] ?: null,
                'reason' => trim((string)($apt['cancellation_reason'] ?? '')) ?: null, 'source' => 'recorded'];
    }

    // Before the columns existed: the last "Cancelled by … on … — Reason: …" line in notes
    $notes = (string)($apt['notes'] ?? '');
    if (preg_match_all('/^Cancelled by (.+?) on ([A-Z][a-z]{2} \d{1,2}, \d{4} \d{1,2}:\d{2} [AP]M) — Reason: (.*)$/mu', $notes, $m, PREG_SET_ORDER)) {
        $hit  = end($m);
        $isPt = $hit[1] === 'patient';
        $at   = DateTimeImmutable::createFromFormat('M j, Y g:i A', $hit[2], bookingTz());
        return [
            'by'      => $isPt ? (trim((string)($apt['patients']['full_name'] ?? '')) ?: 'Patient') : $hit[1],
            'by_type' => $isPt ? 'patient' : 'staff',
            'at'      => $at ? $at->format('c') : null,
            'reason'  => trim($hit[3]) !== '' ? trim($hit[3]) : null,
            'source'  => 'notes',
        ];
    }
    return ['by' => null, 'by_type' => null, 'at' => null, 'reason' => null, 'source' => 'none'];
}

// ─────────────────────────────────────────────────────────────
//  NO SHOW
// ─────────────────────────────────────────────────────────────

/**
 * Mark ended, never-checked-in appointments as no_show (all branches).
 * "Ended" = appointment_date + appointment_time + the booked visit length
 * (duration_minutes rounded up to whole 30-minute slots, 30 min when unknown)
 * is before now in Asia/Manila. Skipped: checked_in / completed / cancelled /
 * no_show, and any appointment that already has a treatment record (it was
 * checked in even if its status says otherwise).
 *
 * $force  ignore the throttle (cron script / tests)
 * $dryRun only report what would change
 * Returns the appointment ids marked (or that would be marked).
 */
function aptSweepNoShows(bool $force = false, bool $dryRun = false): array
{
    static $ranThisRequest = false;
    if ($ranThisRequest && !$force) return [];
    $ranThisRequest = true;

    // Throttle across requests: one sweep per APT_NO_SHOW_EVERY seconds, and
    // never two at once (non-blocking lock — a busy sweep is simply skipped).
    $lockFile = _aptFlagFile('noshow_sweep');
    $fh = @fopen($lockFile, 'c+');
    if (!$fh) return [];
    if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return []; }
    $last = (int)trim((string)stream_get_contents($fh));
    if (!$force && $last && (time() - $last) < APT_NO_SHOW_EVERY) { flock($fh, LOCK_UN); fclose($fh); return []; }
    if (!$dryRun) { ftruncate($fh, 0); rewind($fh); fwrite($fh, (string)time()); fflush($fh); }

    try {
        return _aptSweepNoShowsNow($dryRun);
    } catch (\Throwable $e) {
        error_log('[appointment_lifecycle] no-show sweep failed: ' . $e->getMessage());
        return [];
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function _aptSweepNoShowsNow(bool $dryRun): array
{
    $now   = new DateTimeImmutable('now', bookingTz());
    $today = $now->format('Y-m-d');

    $q = 'status=in.(pending,confirmed)&appointment_date=lte.' . $today .
         '&order=appointment_date.asc,appointment_time.asc&limit=' . APT_NO_SHOW_BATCH .
         '&select=appointment_id,appointment_code,patient_id,branch_id,appointment_date,appointment_time';
    $res = supabase_request('appointments', 'GET', [], $q . ',duration_minutes');
    if (($res['status'] ?? 0) >= 400) $res = supabase_request('appointments', 'GET', [], $q);
    if (($res['status'] ?? 0) >= 400 || !is_array($res['data'] ?? null)) return [];

    $due = [];
    foreach ($res['data'] as $a) {
        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $a['appointment_date'] . ' ' . substr((string)$a['appointment_time'], 0, 5), bookingTz());
        if (!$start) continue;
        $len = blockedMinutes((int)($a['duration_minutes'] ?? BOOKING_SLOT_MINUTES));
        if ($start->modify('+' . $len . ' minutes') <= $now) $due[(int)$a['appointment_id']] = $a;
    }
    if (!$due) return [];

    // Checked in already (a treatment exists) → leave it for staff to resolve
    $t = supabase_request('treatments', 'GET', [], 'appointment_id=in.(' . implode(',', array_keys($due)) . ')&select=appointment_id');
    if (($t['status'] ?? 0) >= 400) return [];   // can't tell → don't guess
    foreach (($t['data'] ?? []) as $tr) unset($due[(int)$tr['appointment_id']]);
    if (!$due || $dryRun) return array_keys($due);

    $upd = supabase_request('appointments', 'PATCH',
        ['status' => 'no_show', 'updated_at' => $now->format('c')],
        'appointment_id=in.(' . implode(',', array_keys($due)) . ')&status=in.(pending,confirmed)');
    if (($upd['status'] ?? 0) < 200 || ($upd['status'] ?? 0) >= 300) return [];

    $marked = [];
    foreach ((is_array($upd['data'] ?? null) ? $upd['data'] : []) as $row) {
        if (!is_array($row) || empty($row['appointment_id'])) continue;
        $id = (int)$row['appointment_id'];
        $marked[] = $id;
        $a = $due[$id] ?? $row;
        notif_appointment($row, ['status' => 'confirmed'] + $row);   // "missed appointment" to the patient
        // Audit row (system actor, written directly: this can run inside a
        // portal or read-only request whose session is already closed)
        supabase_request('audit_logs', 'POST', [
            'user_id'     => null,
            'username'    => 'System (automatic)',
            'role'        => 'system',
            'branch_id'   => !empty($row['branch_id']) ? (int)$row['branch_id'] : null,
            'branch_name' => null,
            'module'      => 'appointment-schedules',
            'action'      => 'UPDATE',
            'entity_id'   => (string)$id,
            'entity_name' => (string)($row['appointment_code'] ?? $a['appointment_code'] ?? ('#' . $id)),
            'description' => 'Status changed to No Show automatically: the appointment on ' .
                             date('M j, Y', strtotime($a['appointment_date'])) . ' at ' .
                             date('g:i A', strtotime(substr((string)$a['appointment_time'], 0, 5))) .
                             ' ended without a check-in',
            'created_at'  => $now->format('c'),
        ]);
    }
    return $marked;
}
