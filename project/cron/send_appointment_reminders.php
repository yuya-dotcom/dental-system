<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit(1);
}

// =============================================================
//  cron/send_appointment_reminders.php
//  Day-before SMS reminders for CONFIRMED appointments.
//  Run nightly by Windows Task Scheduler via cron/run_reminders.bat.
//
//  php send_appointment_reminders.php                 send for tomorrow
//  php send_appointment_reminders.php --dry-run       preview, no SMS / no DB writes
//  php send_appointment_reminders.php --dry-run --date=2026-10-23
//      --date = the APPOINTMENT date to process (default: tomorrow, Asia/Manila)
// =============================================================

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/sms_helper.php';

const REMINDER_SMS_MAX = 160; // one GSM-7 segment

// ── Arguments ────────────────────────────────────────────────
$dryRun = false;
$date   = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (strncmp($arg, '--date=', 7) === 0) {
        $date = substr($arg, 7);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\nUsage: php send_appointment_reminders.php [--dry-run] [--date=YYYY-MM-DD]\n");
        exit(2);
    }
}
if ($date === null) {
    $date = (new DateTime('tomorrow'))->format('Y-m-d');
} else {
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        fwrite(STDERR, "Invalid --date '{$date}'. Expected a real calendar date as YYYY-MM-DD.\n");
        exit(2);
    }
}

// ── Helpers ──────────────────────────────────────────────────

/** One row by id (cached). Null if missing or on error (error is logged). */
function rem_lookup(string $table, string $idCol, $id, string $cols): ?array
{
    static $cache = [];
    $id = (int)$id;
    if ($id < 1) return null;
    $k = "{$table}:{$id}";
    if (!array_key_exists($k, $cache)) {
        $res = supabase_request($table, 'GET', [], "{$idCol}=eq.{$id}&select={$cols}&limit=1");
        if ($res['error'] !== null || $res['status'] !== 200) {
            error_log("reminders: {$table} lookup failed [{$res['status']}] for id {$id}");
            return null; // not cached, so a later appointment can retry
        }
        $cache[$k] = $res['data'][0] ?? null;
    }
    return $cache[$k];
}

/** Portal account linked to a patient (for appointments booked without account_id). */
function rem_account_for_patient($patientId): ?array
{
    $patientId = (int)$patientId;
    if ($patientId < 1) return null;
    $res = supabase_request('patient_accounts', 'GET', [],
        'patient_id=eq.' . $patientId . '&select=first_name,contact_number&order=account_id.asc&limit=1');
    if ($res['error'] !== null || $res['status'] !== 200) {
        error_log("reminders: patient_accounts lookup failed [{$res['status']}] for patient {$patientId}");
        return null;
    }
    return $res['data'][0] ?? null;
}

/**
 * Build the reminder, shortening step by step until it fits one segment.
 * Returns null if no variant fits.
 */
function rem_build_message(string $first, int $ts, string $branch, string $code, string $branchPhone): ?string
{
    $when    = date('M j', $ts) . ' at ' . date('g:i A', $ts);
    $contact = $branchPhone !== '' ? " To reschedule, call {$branchPhone}." : ' To reschedule, please contact the clinic.';

    $build = function (string $name, string $br, bool $withRef) use ($when, $code, $contact) {
        $msg = 'EssenciaSmile: ' . ($name !== '' ? "Hi {$name}," : 'Hi,')
             . " reminder of your appointment tomorrow, {$when}"
             . ($br !== '' ? ", {$br} branch" : '') . '.'
             . ($withRef && $code !== '' ? " Ref {$code}." : '')
             . $contact;
        return sms_gsm_safe($msg);
    };

    $shortName   = strlen($first) > 10 ? substr($first, 0, 10) : $first;
    $shortBranch = trim((string)preg_replace('/\s+City$/i', '', $branch));
    if (strlen($shortBranch) > 20) $shortBranch = rtrim(substr($shortBranch, 0, 20));

    $variants = [
        [$first, $branch, true],
        [$first, $branch, false],      // 1. drop the reference
        [$shortName, $branch, false],  // 2. shorten the first name
        ['', $branch, false],
        ['', $shortBranch, false],     // 3. shorten the branch text
    ];
    foreach ($variants as [$n, $b, $ref]) {
        $msg = $build($n, $b, $ref);
        if (strlen($msg) <= REMINDER_SMS_MAX) return $msg;
    }
    return null;
}

// ── Fetch confirmed appointments for the target date ─────────
$base    = 'appointment_date=eq.' . rawurlencode($date) . '&status=eq.confirmed&order=appointment_time.asc';
$columns = 'appointment_id,appointment_code,patient_id,account_id,branch_id,appointment_date,appointment_time';
$embeds  = ',patients(full_name,contact_number),patient_accounts(first_name,contact_number),branches(branch_name,contact_number)';

$isList   = fn($r) => $r['error'] === null && $r['status'] === 200 && is_array($r['data'])
                      && ($r['data'] === [] || array_keys($r['data']) === range(0, count($r['data']) - 1));
$embedded = true;
$res      = supabase_request('appointments', 'GET', [], $base . '&select=' . $columns . $embeds);
if (!$isList($res)) {
    error_log("reminders: embedded appointment query failed [{$res['status']}]: " . ($res['error'] ?? 'unexpected response') . ' — using separate lookups');
    $embedded = false;
    $res      = supabase_request('appointments', 'GET', [], $base . '&select=' . $columns);
    if (!$isList($res)) {
        $msg = "[" . date('Y-m-d H:i:s') . " Asia/Manila] FATAL: could not load appointments for {$date} [{$res['status']}]";
        error_log($msg);
        fwrite(STDERR, $msg . PHP_EOL);
        exit(1);
    }
}
$appointments = $res['data'];

echo '[' . date('Y-m-d H:i:s') . ' Asia/Manila] ' . ($dryRun ? 'DRY RUN — ' : '')
   . "appointment date {$date}: " . count($appointments) . " confirmed appointment(s)" . PHP_EOL;

// ── Process ──────────────────────────────────────────────────
$counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'duplicate' => 0];
if ($dryRun) $counts = ['dry-run' => 0, 'failed' => 0, 'skipped' => 0];

foreach ($appointments as $a) {
    $id  = (int)($a['appointment_id'] ?? 0);
    $ref = !empty($a['appointment_code']) ? (string)$a['appointment_code'] : "#{$id}";
    try {
        $ts = strtotime(($a['appointment_date'] ?? '') . ' ' . ($a['appointment_time'] ?? ''));
        if ($id < 1 || !$ts || ($a['appointment_date'] ?? '') !== $date) {
            error_log("reminders: invalid appointment row {$ref}");
            $counts['failed']++;
            echo "{$ref}  result=failed  (invalid appointment data)" . PHP_EOL;
            continue;
        }

        $patient = $embedded ? ($a['patients'] ?? null)
                             : rem_lookup('patients', 'patient_id', $a['patient_id'] ?? 0, 'full_name,contact_number');
        $account = $embedded ? ($a['patient_accounts'] ?? null)
                             : rem_lookup('patient_accounts', 'account_id', $a['account_id'] ?? 0, 'first_name,contact_number');
        $branch  = $embedded ? ($a['branches'] ?? null)
                             : rem_lookup('branches', 'branch_id', $a['branch_id'] ?? 0, 'branch_name,contact_number');
        if (!$account && !empty($a['patient_id'])) {
            $account = rem_account_for_patient($a['patient_id']);
        }

        // Mobile: patients.contact_number first, then the portal account's number.
        // Placeholders such as __PORTAL_12__ normalize to null.
        $phone = null;
        foreach ([$patient['contact_number'] ?? null, $account['contact_number'] ?? null] as $raw) {
            if (is_string($raw) && trim($raw) !== '' && ($p = normalizePhMobile($raw)) !== null) {
                $phone = $p;
                break;
            }
        }

        // First name: portal first_name, else first word of full_name, else none
        $first = trim((string)($account['first_name'] ?? ''));
        if ($first === '') {
            $first = (string)(preg_split('/\s+/', trim((string)($patient['full_name'] ?? '')))[0] ?? '');
        }
        $first = trim(sms_gsm_safe($first));

        $branchName  = trim(sms_gsm_safe((string)($branch['branch_name'] ?? '')));
        $branchPhone = trim(sms_gsm_safe((string)($branch['contact_number'] ?? '')));
        if (strlen(preg_replace('/\D/', '', $branchPhone)) < 7) $branchPhone = '';

        $message = rem_build_message($first, $ts, $branchName, sms_gsm_safe((string)($a['appointment_code'] ?? '')), $branchPhone);
        $label   = $ref . '  branch=' . ($branchName !== '' ? $branchName : '?') . '  mobile=' . ($phone ? 'yes' : 'none');

        if ($message === null) {
            error_log("reminders: message for {$ref} exceeds " . REMINDER_SMS_MAX . ' chars after shortening; not sent');
            $counts['failed']++;
            echo "{$label}  result=failed  (message too long)" . PHP_EOL;
            continue;
        }
        $len = strlen($message);

        if ($dryRun) {
            $r = $phone ? 'dry-run' : 'skipped';
            $counts[$r]++;
            echo "{$label}  len={$len}  result={$r}" . PHP_EOL . "    {$message}" . PHP_EOL;
            continue;
        }

        $dedupeKey = 'reminder:' . $id . ':' . date('Y-m-d\TH:i', $ts);
        $r = sms_send_once($dedupeKey, 'appointment_reminder', $id,
            !empty($a['patient_id']) ? (int)$a['patient_id'] : null, $phone, $message);
        $result = isset($counts[$r['result']]) ? $r['result'] : 'failed';
        $counts[$result]++;
        echo "{$label}  len={$len}  result={$result}" . ($r['error'] ? "  ({$r['error']})" : '') . PHP_EOL;
    } catch (Throwable $e) {
        error_log("reminders: {$ref} failed: " . $e->getMessage());
        $counts['failed']++;
        echo "{$ref}  result=failed  (internal error)" . PHP_EOL;
    }
}

// ── Summary ──────────────────────────────────────────────────
$parts = [];
foreach ($counts as $k => $v) $parts[] = "{$k}={$v}";
echo '[' . date('Y-m-d H:i:s') . ' Asia/Manila] ' . ($dryRun ? 'DRY RUN ' : '') . implode(' ', $parts) . PHP_EOL;
exit(0);
