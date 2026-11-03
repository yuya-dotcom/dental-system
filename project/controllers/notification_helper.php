<?php
// =============================================================
//  controllers/notification_helper.php
//  Patient-portal notifications (table: patient_notifications).
//
//  Call these only AFTER the underlying write has succeeded.
//  Every notification carries a dedupe_key that is unique per account
//  (DB constraint), so re-running an event never creates duplicates.
//  All functions are best-effort: a notification failure (or the
//  migration not applied yet) never breaks the calling workflow.
//
//  Requires dbconfig.php (supabase_request) to be loaded first.
// =============================================================

if (!function_exists('notif_create')) {

define('NOTIF_TABLE', 'patient_notifications');

/** Insert one notification; duplicates (same account + dedupe_key) are ignored. */
function notif_create(int $accountId, string $category, string $event, string $title,
                      string $message, ?string $linkPage, string $dedupeKey): bool
{
    if ($accountId < 1 || $dedupeKey === '') return false;
    try {
        $res = supabase_request(NOTIF_TABLE, 'POST', [
            'account_id' => $accountId,
            'category'   => $category,
            'event_type' => $event,
            'title'      => $title,
            'message'    => $message,
            'link_page'  => $linkPage,
            'dedupe_key' => $dedupeKey,
        ], 'on_conflict=account_id,dedupe_key', ['Prefer: resolution=ignore-duplicates']);
        // 409 = unique violation → already notified, which is fine
        return ($res['status'] >= 200 && $res['status'] < 300) || $res['status'] === 409;
    } catch (Throwable $e) {
        error_log('notif_create failed: ' . $e->getMessage());
        return false;
    }
}

/** Portal account ids that should receive notifications for a patient. */
function notif_accounts_for_patient(?int $patientId, ?int $accountId = null): array
{
    $ids = [];
    if ($accountId) $ids[] = (int)$accountId;
    if ($patientId) {
        $res = supabase_request('patient_accounts', 'GET', [],
            'patient_id=eq.' . (int)$patientId . '&select=account_id');
        foreach (($res['data'] ?? []) as $r) $ids[] = (int)$r['account_id'];
    }
    return array_values(array_unique(array_filter($ids)));
}

function notif_branch_name(?int $branchId): string
{
    static $cache = [];
    if (!$branchId) return 'the clinic';
    if (!isset($cache[$branchId])) {
        $res = supabase_request('branches', 'GET', [],
            'branch_id=eq.' . (int)$branchId . '&select=branch_name&limit=1');
        $cache[$branchId] = $res['data'][0]['branch_name'] ?? 'the clinic';
    }
    return $cache[$branchId];
}

function notif_peso(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

// ── Appointments ─────────────────────────────────────────────

define('NOTIF_APPT_SELECT', 'appointment_id,patient_id,account_id,branch_id,appointment_date,appointment_time,status');

/** Current appointment row (use before a PATCH to detect status/schedule changes). */
function notif_appt_snapshot(int $appointmentId): ?array
{
    if ($appointmentId < 1) return null;
    $res = supabase_request('appointments', 'GET', [],
        'appointment_id=eq.' . $appointmentId . '&select=' . NOTIF_APPT_SELECT . '&limit=1');
    return $res['data'][0] ?? null;
}

function notif_appt_when(array $a): string
{
    $ts = strtotime(($a['appointment_date'] ?? '') . ' ' . ($a['appointment_time'] ?? ''));
    $when = $ts ? date('D, M j, Y \a\t g:i A', $ts) : ($a['appointment_date'] ?? '');
    return $when . ' at ' . notif_branch_name(isset($a['branch_id']) ? (int)$a['branch_id'] : null);
}

/**
 * Notify about an appointment's current state.
 * $appt   — appointment id or a row containing NOTIF_APPT_SELECT columns
 * $before — snapshot taken before the change (null for a new appointment)
 */
function notif_appointment($appt, ?array $before = null): void
{
    try {
        $a = is_array($appt) ? $appt : notif_appt_snapshot((int)$appt);
        if (!$a || empty($a['appointment_id'])) return;

        $id       = (int)$a['appointment_id'];
        $status   = (string)($a['status'] ?? '');
        $accounts = notif_accounts_for_patient(
            isset($a['patient_id']) ? (int)$a['patient_id'] : null,
            isset($a['account_id']) ? (int)$a['account_id'] : null
        );
        if (!$accounts) return; // walk-in patient without a portal account
        $when = notif_appt_when($a);
        $slot = ($a['appointment_date'] ?? '') . 'T' . substr((string)($a['appointment_time'] ?? ''), 0, 5);

        $out = [];

        $rescheduled = $before
            && (($before['appointment_date'] ?? '') !== ($a['appointment_date'] ?? '')
                || substr((string)($before['appointment_time'] ?? ''), 0, 5) !== substr((string)($a['appointment_time'] ?? ''), 0, 5))
            && in_array($status, ['pending', 'confirmed'], true);
        if ($rescheduled) {
            $out[] = ['appointment_rescheduled', 'Appointment rescheduled',
                "Your appointment has been moved to {$when}.", "appt:{$id}:resched:{$slot}"];
        }

        if (!$before || ($before['status'] ?? '') !== $status) {
            $map = [
                'pending'   => ['appointment_booked', 'Appointment request received',
                                "Your appointment request for {$when} has been received and is awaiting confirmation."],
                'confirmed' => ['appointment_confirmed', 'Appointment confirmed',
                                "Your appointment on {$when} is confirmed. See you there!"],
                'cancelled' => ['appointment_cancelled', 'Appointment cancelled',
                                "Your appointment on {$when} has been cancelled."],
                'no_show'   => ['appointment_missed', 'Missed appointment',
                                "You missed your appointment on {$when}. You can book a new schedule anytime."],
                'completed' => ['appointment_completed', 'Appointment completed',
                                "Your appointment on {$when} has been completed. Thank you for visiting!"],
            ];
            if (isset($map[$status])) {
                $out[] = [$map[$status][0], $map[$status][1], $map[$status][2], "appt:{$id}:status:{$status}"];
            }
        }

        foreach ($accounts as $acc) {
            foreach ($out as [$event, $title, $msg, $key]) {
                notif_create($acc, 'appointment', $event, $title, $msg, 'appointments', $key);
            }
        }
    } catch (Throwable $e) {
        error_log('notif_appointment failed: ' . $e->getMessage());
    }
}

// ── Billing ──────────────────────────────────────────────────

function notif_invoice_created(int $invoiceId): void
{
    try {
        $res = supabase_request('invoices', 'GET', [],
            'invoice_id=eq.' . $invoiceId . '&select=invoice_id,invoice_code,patient_id,total_amount,balance&limit=1');
        $inv = $res['data'][0] ?? null;
        if (!$inv) return;
        $code = $inv['invoice_code'] ?: ('#' . $invoiceId);
        $msg  = "Billing record {$code} for " . notif_peso((float)$inv['total_amount']) . ' is now available.';
        if ((float)$inv['balance'] > 0) $msg .= ' Balance due: ' . notif_peso((float)$inv['balance']) . '.';
        foreach (notif_accounts_for_patient((int)$inv['patient_id']) as $acc) {
            notif_create($acc, 'billing', 'invoice_available', 'New billing record', $msg, 'billing',
                "inv:{$invoiceId}:created");
        }
    } catch (Throwable $e) {
        error_log('notif_invoice_created failed: ' . $e->getMessage());
    }
}

/** Called after a payment row is saved AND the invoice totals were updated. */
function notif_payment_recorded(int $paymentId, int $invoiceId, float $amount, float $newBalance): void
{
    try {
        $res = supabase_request('invoices', 'GET', [],
            'invoice_id=eq.' . $invoiceId . '&select=invoice_code,patient_id&limit=1');
        $inv = $res['data'][0] ?? null;
        if (!$inv || $paymentId < 1) return;
        $code = $inv['invoice_code'] ?: ('#' . $invoiceId);
        $msg  = 'Payment of ' . notif_peso($amount) . " was recorded for {$code}. "
              . ($newBalance > 0 ? 'Remaining balance: ' . notif_peso($newBalance) . '.' : 'This invoice is now fully paid.');
        foreach (notif_accounts_for_patient((int)$inv['patient_id']) as $acc) {
            notif_create($acc, 'billing', 'payment_recorded', 'Payment received', $msg, 'billing',
                "pay:{$paymentId}");
        }
    } catch (Throwable $e) {
        error_log('notif_payment_recorded failed: ' . $e->getMessage());
    }
}

// ── Treatments ───────────────────────────────────────────────

/**
 * $before — treatment row before an update (null = newly created).
 * Notifies on creation and on status changes only (not on every note edit).
 */
function notif_treatment(int $treatmentId, ?array $before = null): void
{
    try {
        $res = supabase_request('treatments', 'GET', [],
            'treatment_id=eq.' . $treatmentId . '&select=treatment_id,patient_id,service_id,status,tooth_number&limit=1');
        $t = $res['data'][0] ?? null;
        if (!$t) return;

        $svc = 'Treatment';
        if (!empty($t['service_id'])) {
            $s = supabase_request('services', 'GET', [],
                'service_id=eq.' . (int)$t['service_id'] . '&select=service_name&limit=1');
            $svc = $s['data'][0]['service_name'] ?? $svc;
        }
        $status = (string)($t['status'] ?? '');
        $label  = ucwords(str_replace('_', ' ', $status));

        if ($before === null) {
            [$event, $title, $msg, $key] = ['treatment_added', 'Treatment record added',
                "{$svc} has been added to your treatment history.", "trt:{$treatmentId}:created"];
        } elseif (($before['status'] ?? '') !== $status && $status !== '') {
            [$event, $title, $msg, $key] = ['treatment_status', 'Treatment updated',
                "Your {$svc} treatment is now {$label}.", "trt:{$treatmentId}:status:{$status}"];
        } else {
            return;
        }
        foreach (notif_accounts_for_patient((int)$t['patient_id']) as $acc) {
            notif_create($acc, 'treatment', $event, $title, $msg, 'treatments', $key);
        }
    } catch (Throwable $e) {
        error_log('notif_treatment failed: ' . $e->getMessage());
    }
}

// ── Account & security ───────────────────────────────────────

function notif_account_event(int $accountId, string $event, string $title, string $message, string $dedupeKey): void
{
    notif_create($accountId, 'account', $event, $title, $message, 'profile', $dedupeKey);
}

// ── Generated on fetch: reminders + announcements ────────────

/** Reminders for still-active appointments within the next 24 hours. */
function notif_generate_reminders(int $accountId, ?int $patientId): void
{
    try {
        $owner = $patientId
            ? 'or=(account_id.eq.' . $accountId . ',patient_id.eq.' . (int)$patientId . ')'
            : 'account_id=eq.' . $accountId;
        $res = supabase_request('appointments', 'GET', [],
            $owner . '&status=in.(pending,confirmed)' .
            '&appointment_date=gte.' . date('Y-m-d') .
            '&appointment_date=lte.' . date('Y-m-d', strtotime('+1 day')) .
            '&select=' . NOTIF_APPT_SELECT);
        $now = time();
        foreach (($res['data'] ?? []) as $a) {
            $ts = strtotime($a['appointment_date'] . ' ' . $a['appointment_time']);
            if (!$ts || $ts <= $now || $ts - $now > 86400) continue;
            $slot = $a['appointment_date'] . 'T' . substr($a['appointment_time'], 0, 5);
            notif_create($accountId, 'appointment', 'appointment_reminder', 'Upcoming appointment',
                'Reminder: you have an appointment on ' . notif_appt_when($a) . '.',
                'appointments', 'appt:' . (int)$a['appointment_id'] . ':reminder:' . $slot);
        }
    } catch (Throwable $e) {
        error_log('notif_generate_reminders failed: ' . $e->getMessage());
    }
}

/** Deliver active clinic announcements (all branches, or a branch the patient has visited/booked). */
function notif_sync_announcements(int $accountId, ?int $patientId): void
{
    try {
        $nowIso = gmdate('Y-m-d\TH:i:s\Z');
        $res = supabase_request('clinic_announcements', 'GET', [],
            'is_active=eq.true&starts_at=lte.' . $nowIso .
            '&or=(ends_at.is.null,ends_at.gt.' . $nowIso . ')' .
            '&select=announcement_id,title,message,branch_id&order=starts_at.desc&limit=20');
        $rows = $res['data'] ?? [];
        if (!$rows || isset($rows['message'])) return; // table missing / error

        $myBranches = null;
        foreach ($rows as $an) {
            if (!empty($an['branch_id'])) {
                if ($myBranches === null) {
                    $owner = $patientId
                        ? 'or=(account_id.eq.' . $accountId . ',patient_id.eq.' . (int)$patientId . ')'
                        : 'account_id=eq.' . $accountId;
                    $b = supabase_request('appointments', 'GET', [], $owner . '&select=branch_id');
                    $myBranches = array_map('intval', array_column($b['data'] ?? [], 'branch_id'));
                }
                if (!in_array((int)$an['branch_id'], $myBranches, true)) continue;
            }
            $title = $an['branch_id'] ? $an['title'] . ' — ' . notif_branch_name((int)$an['branch_id']) : $an['title'];
            notif_create($accountId, 'announcement', 'clinic_announcement', $title, $an['message'],
                null, 'ann:' . (int)$an['announcement_id']);
        }
    } catch (Throwable $e) {
        error_log('notif_sync_announcements failed: ' . $e->getMessage());
    }
}

} // function_exists guard
