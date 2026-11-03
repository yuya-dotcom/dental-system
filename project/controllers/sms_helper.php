<?php
// =============================================================
//  controllers/sms_helper.php
//  Shared UniSMS sending + outgoing SMS log (table: sms_logs).
//
//  sendSMS() / normalizePhMobile() were moved here unchanged from
//  verify_phone_controller.php so the OTP flow and the cron reminder
//  script use the same code. Credentials live in config/secrets.php
//  (git-ignored; see config/secrets.example.php).
//
//  sms_send_once() claims a row in sms_logs by its unique dedupe_key
//  before sending, so the same reminder is never sent twice even if
//  the cron job runs again or two runs overlap.
// =============================================================

require_once __DIR__ . '/../config/secrets.php';
require_once __DIR__ . '/../dbconfig.php';

if (!defined('SMS_LOG_TABLE')) {
    define('SMS_LOG_TABLE', 'sms_logs');
    define('SMS_MAX_ATTEMPTS', 3);
    // Exact message sendSMS() returns on a cURL error. The request may or may
    // not have reached UniSMS, so these are never retried automatically.
    define('SMS_NETWORK_ERROR_MSG', 'Network error sending SMS.');
}

// ─── CORE SMS SENDER ──────────────────────────────────────────────────────────
if (!function_exists('sendSMS')) {
function sendSMS(string $recipient, string $message): array {
    $ch = curl_init('https://unismsapi.com/api/sms');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => UNISMS_API_KEY . ':', // Basic Auth, empty password
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'recipient'  => $recipient,
            'content'    => $message,
            'sender_id'  => UNISMS_SENDER_ID,
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);

    $response   = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log('UniSMS curl error: ' . $curlError);
        return ['success' => false, 'message' => 'Network error sending SMS.'];
    }

    $decoded = json_decode($response, true);

    if ($statusCode >= 400) {
        // UniSMS may return a string message, a nested error object, or a validation "errors" map
        $err = $decoded['message'] ?? $decoded['error']['message'] ?? $decoded['error'] ?? null;
        if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
            $first = reset($decoded['errors']);
            $err   = is_array($first) ? reset($first) : $first;
        }
        if (!is_string($err) || $err === '') $err = 'Unknown SMS error.';
        error_log("UniSMS error [{$statusCode}]: {$err} | recipient={$recipient} | response={$response}");
        return ['success' => false, 'message' => $err];
    }

    return ['success' => true, 'message' => 'SMS sent.', 'data' => $decoded];
}
}

// ─── NORMALIZE PH MOBILE TO E.164 (+639XXXXXXXXX) ─────────────────────────────
if (!function_exists('normalizePhMobile')) {
function normalizePhMobile(string $phone): ?string {
    $digits = preg_replace('/\D/', '', $phone);
    if (preg_match('/^639\d{9}$/', $digits))  return '+' . $digits;
    if (preg_match('/^09\d{9}$/', $digits))   return '+63' . substr($digits, 1);
    if (preg_match('/^9\d{9}$/', $digits))    return '+63' . $digits;
    return null;
}
}

// ─── GSM-SAFE TEXT ────────────────────────────────────────────────────────────
// Maps accents / smart punctuation to plain ASCII, then keeps only characters
// from the GSM-7 basic set so strlen() equals the SMS character count.
if (!function_exists('sms_gsm_safe')) {
function sms_gsm_safe(string $s): string {
    static $map = [
        'ñ' => 'n', 'Ñ' => 'N',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'ç' => 'c', 'Ç' => 'C',
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{2032}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{2033}" => '"',
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
        "\u{2026}" => '...', "\u{00A0}" => ' ',
        "\r\n" => ' ', "\r" => ' ', "\n" => ' ', "\t" => ' ',
        // Printable ASCII outside the GSM-7 basic set (would cost 2 chars or not exist)
        '[' => '(', ']' => ')', '{' => '(', '}' => ')',
        '\\' => '/', '|' => '/', '~' => '-', '^' => '', '`' => "'",
    ];
    $s = strtr($s, $map);
    return (string)preg_replace('/[^\x20-\x7E]/', '', $s);
}
}

// ─── SEND ONE SMS EXACTLY ONCE ────────────────────────────────────────────────
if (!function_exists('sms_send_once')) {

/** PATCH one sms_logs row; true only if PostgREST confirms a row was updated. */
function sms_log_update(string $match, array $fields): bool
{
    $fields['updated_at'] = date('c');
    $res = supabase_request(SMS_LOG_TABLE, 'PATCH', $fields, $match);
    return $res['status'] >= 200 && $res['status'] < 300 && !empty($res['data'][0]);
}

/**
 * Send $message to $phone at most once per $dedupeKey.
 * Returns ['result' => sent|failed|skipped|duplicate, 'error' => ?string].
 * Never throws.
 */
function sms_send_once(
    string $dedupeKey,
    string $type,
    ?int $appointmentId,
    ?int $patientId,
    ?string $phone,
    string $message
): array {
    $out = fn(string $result, ?string $error = null) => ['result' => $result, 'error' => $error];
    if ($dedupeKey === '') return $out('failed', 'empty dedupe key');

    try {
        $keyMatch = 'dedupe_key=eq.' . rawurlencode($dedupeKey);
        $phone    = ($phone !== null && trim($phone) !== '') ? trim($phone) : null;

        // ── 1. Claim: insert, ignoring an existing row with the same key ──
        $ins = supabase_request(SMS_LOG_TABLE, 'POST', [
            'dedupe_key'     => $dedupeKey,
            'sms_type'       => $type,
            'appointment_id' => $appointmentId,
            'patient_id'     => $patientId,
            'phone'          => $phone,
            'message'        => $message,
            'status'         => 'sending',
            'attempts'       => 1,
        ], 'on_conflict=dedupe_key', ['Prefer: resolution=ignore-duplicates']);

        // Only a returned row proves this run inserted it (duplicates come back as [])
        $claimed  = $ins['status'] >= 200 && $ins['status'] < 300
                 && ($ins['data'][0]['dedupe_key'] ?? null) === $dedupeKey;
        $attempts = 1;

        // ── 2. Not inserted: look at the existing row ──
        if (!$claimed) {
            $cur = supabase_request(SMS_LOG_TABLE, 'GET', [], $keyMatch . '&select=status,attempts&limit=1');
            if ($cur['error'] !== null || $cur['status'] !== 200) {
                error_log("sms_send_once: log lookup failed [{$cur['status']}] for {$dedupeKey}");
                return $out('failed', 'sms log unavailable');
            }
            $row = $cur['data'][0] ?? null;
            if (!$row) {
                // Insert neither succeeded nor hit an existing row
                error_log("sms_send_once: claim failed [{$ins['status']}] for {$dedupeKey}: " . ($ins['error'] ?? 'no row returned'));
                return $out('failed', 'could not write sms log');
            }

            $status = (string)($row['status'] ?? '');
            $n      = (int)($row['attempts'] ?? 0);
            if (in_array($status, ['sent', 'sending', 'skipped'], true)) {
                return $out('duplicate');
            }
            if ($status !== 'failed') {
                error_log("sms_send_once: unexpected status '{$status}' for {$dedupeKey}");
                return $out('failed', 'unexpected sms log state');
            }
            if ($n >= SMS_MAX_ATTEMPTS) {
                return $out('failed', 'retry limit reached');
            }

            // Compare-and-set on the exact attempt count: only one concurrent run wins
            $retry = sms_log_update(
                $keyMatch . '&status=eq.failed&attempts=eq.' . $n,
                ['status' => 'sending', 'attempts' => $n + 1, 'error' => null,
                 'phone' => $phone, 'message' => $message]
            );
            if (!$retry) return $out('duplicate');
            $attempts = $n + 1;
        }

        // From here this run owns the row (status = sending, attempts = $attempts)
        $owned = $keyMatch . '&status=eq.sending&attempts=eq.' . $attempts;

        // ── 3. No usable mobile number ──
        if ($phone === null) {
            if (!sms_log_update($owned, ['status' => 'skipped', 'error' => 'no valid mobile number'])) {
                error_log("sms_send_once: could not mark {$dedupeKey} as skipped");
            }
            return $out('skipped', 'no valid mobile number');
        }

        // ── 4. Send ──
        $sms = sendSMS($phone, $message);

        if (!empty($sms['success'])) {
            $fields = ['status' => 'sent', 'error' => null, 'provider_response' => $sms['data'] ?? null];
            $result = $out('sent');
        } elseif (($sms['message'] ?? '') === SMS_NETWORK_ERROR_MSG) {
            // Outcome unknown (e.g. timeout after UniSMS accepted it): keep the row
            // as 'sending' so it is never re-claimed and re-sent automatically.
            $fields = ['error' => 'uncertain delivery (network error); not retried automatically'];
            $result = $out('failed', 'uncertain delivery (network error)');
        } else {
            $err    = substr((string)($sms['message'] ?? 'SMS provider error'), 0, 300);
            $fields = ['status' => 'failed', 'error' => $err];
            $result = $out('failed', $err);
        }

        if (!sms_log_update($owned, $fields)) {
            // Row stays 'sending', so later runs treat it as a duplicate (no resend)
            error_log("sms_send_once: SMS outcome '{$result['result']}' for {$dedupeKey} but sms_logs update failed; row left as 'sending'");
            if ($result['result'] === 'sent') {
                return $out('failed', 'SMS accepted by provider but log update failed');
            }
        }
        return $result;
    } catch (Throwable $e) {
        error_log("sms_send_once failed for {$dedupeKey}: " . $e->getMessage());
        return $out('failed', 'internal error');
    }
}

} // function_exists guard
