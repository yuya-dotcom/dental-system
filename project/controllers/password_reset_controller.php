<?php
// controllers/password_reset_controller.php
// Patient Portal "Forgot Password". Patient passwords live in
// patient_accounts.password_hash (not Supabase Auth), so the reset is a
// server-side, single-use emailed token — same design as email verification.
// Requires database/2026-10-18_patient_password_reset.sql.

require_once __DIR__ . '/verify_email_controller.php'; // token hash/format/expiry helpers, mailer, app_url()

use PHPMailer\PHPMailer\Exception;

const PASSWORD_RESET_TTL_SECONDS = 1800;   // 30 minutes
const PASSWORD_RESET_COOLDOWN    = 60;     // min seconds between emails per account
const PASSWORD_MIN_LENGTH        = 8;      // matches registration / change_password

// ─── EMAIL TEMPLATE ───────────────────────────────────────────────────────────
function password_reset_email_html(string $first_name, string $reset_link, string $logo_src): string {
    $name = htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8');
    $link = htmlspecialchars($reset_link, ENT_QUOTES, 'UTF-8');
    $logo = htmlspecialchars($logo_src, ENT_QUOTES, 'UTF-8');
    $year = date('Y');
    $font = "font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Reset your password</title></head>
<body style="margin:0;padding:0;background-color:#eef6fb;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef6fb;">
  <tr><td align="center" style="padding:32px 12px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background-color:#ffffff;border-radius:14px;box-shadow:0 4px 18px rgba(1,120,184,0.10);border:1px solid #e3eef5;">
      <tr><td align="center" style="padding:32px 32px 8px 32px;">
        <img src="{$logo}" width="240" height="42" alt="EssenciaSmile" style="display:block;width:240px;max-width:100%;height:auto;border:0;">
      </td></tr>
      <tr><td style="padding:16px 32px 0 32px;{$font}color:#0b3a57;">
        <h1 style="margin:0 0 14px 0;font-size:22px;">Reset your password</h1>
        <p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;color:#33475b;">Hi <strong>{$name}</strong>,</p>
        <p style="margin:0 0 22px 0;font-size:15px;line-height:1.6;color:#33475b;">We received a request to reset the password for your EssenciaSmile Patient Portal account. Click the button below to choose a new password.</p>
        <p style="margin:0 0 22px 0;text-align:center;">
          <a href="{$link}" target="_blank" style="display:inline-block;padding:14px 36px;{$font}font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;background-color:#0279BC;">Reset My Password</a>
        </p>
        <p style="margin:0 0 14px 0;font-size:14px;line-height:1.6;color:#4a5a6a;">&#9201; This link expires in <strong>30 minutes</strong> and can be used only once.</p>
        <p style="margin:0 0 14px 0;font-size:14px;line-height:1.6;color:#4a5a6a;"><strong>Didn't request this?</strong> You can safely ignore this email &mdash; your password will stay the same. Never share this link with anyone.</p>
        <p style="margin:0 0 6px 0;font-size:13px;line-height:1.5;color:#6b7b8b;">If the button doesn't work, copy and paste this link into your browser:</p>
        <p style="margin:0 0 28px 0;font-size:13px;line-height:1.5;word-break:break-all;"><a href="{$link}" target="_blank" style="color:#0279BC;">{$link}</a></p>
      </td></tr>
      <tr><td align="center" style="padding:20px 32px;background-color:#f6f9fb;border-top:1px solid #e3eef5;border-radius:0 0 14px 14px;{$font}font-size:12px;line-height:1.6;color:#7c8b99;">
        <strong style="color:#0b3a57;">EssenciaSmile Dental Clinic</strong> &middot; Patient Portal<br>
        This is an automated message, please do not reply.<br>
        &copy; {$year} EssenciaSmile Dental Clinic. All rights reserved.
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

// ─── REQUEST A RESET ──────────────────────────────────────────────────────────
// Returns true when the request was processed. Unknown emails also return
// true so callers can show one generic message (no account enumeration);
// real failures are logged server-side only.
function requestPatientPasswordReset(string $email): bool {
    $res = supabase_request('patient_accounts', 'GET', [],
        'email=eq.' . urlencode($email) . '&select=account_id,email,first_name,password_reset_expires_at&limit=1');

    if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
        error_log('Password reset lookup failed (status ' . ($res['status'] ?? 0) . ')');
        return false;
    }
    $acc = $res['data'][0] ?? null;
    if (!$acc) {
        return true; // no account — same response as success
    }
    $account_id = (int)$acc['account_id'];

    // Cooldown: a link issued in the last minute is still on its way
    if (!email_token_expired($acc['password_reset_expires_at'] ?? null)) {
        $exp = new DateTimeImmutable($acc['password_reset_expires_at'], new DateTimeZone('UTC'));
        if ($exp->getTimestamp() - time() > PASSWORD_RESET_TTL_SECONDS - PASSWORD_RESET_COOLDOWN) {
            return true;
        }
    }

    // Only the hash is stored; issuing a new token replaces any earlier one
    $token  = bin2hex(random_bytes(32));
    $update = supabase_request('patient_accounts', 'PATCH', [
        'password_reset_token'      => email_token_hash($token),
        'password_reset_expires_at' => gmdate('Y-m-d\TH:i:s', time() + PASSWORD_RESET_TTL_SECONDS),
    ], 'account_id=eq.' . $account_id);

    if (($update['status'] ?? 0) < 200 || $update['status'] >= 300 || empty($update['data'])) {
        error_log('Password reset token save failed for account ' . $account_id . ' (status ' . ($update['status'] ?? 0) . ')');
        return false;
    }

    $reset_link = app_url('portal-reset-password.php') . '?token=' . $token;
    $first_name = trim((string)($acc['first_name'] ?? '')) ?: 'Patient';

    try {
        $mail = createMailer();
        $mail->CharSet = 'UTF-8';
        $mail->addAddress($acc['email'], $first_name);
        $logo_src = '';
        if (is_file(EMAIL_LOGO_PNG)) {
            $mail->addEmbeddedImage(EMAIL_LOGO_PNG, 'essencia-logo', 'EssenciaSmile-Logo.png', 'base64', 'image/png');
            $logo_src = 'cid:essencia-logo';
        }
        $mail->Subject = 'Reset your password – EssenciaSmile';
        $mail->Body    = password_reset_email_html($first_name, $reset_link, $logo_src);
        $mail->AltBody = "Hi $first_name,\n\nReset your EssenciaSmile Patient Portal password here:\n$reset_link\n\nThis link expires in 30 minutes and can be used only once. If you didn't request this, you can ignore this email.";
        $mail->send();
        return true;
    } catch (Exception | \RuntimeException $e) { // RuntimeException = PHPMailer unavailable
        // ErrorInfo holds SMTP diagnostics only (no token); keep it server-side
        error_log('Password reset email failed for account ' . $account_id . ': ' . ($mail->ErrorInfo ?? $e->getMessage()));
        return false;
    }
}

// ─── VALIDATE / COMPLETE ──────────────────────────────────────────────────────
// Returns ['valid' => bool, 'account_id' => int, 'hash' => string]
function validatePatientResetToken(string $token): array {
    if (!is_valid_token_format($token)) {
        return ['valid' => false];
    }
    $hash = email_token_hash($token);
    $res  = supabase_request('patient_accounts', 'GET', [],
        'password_reset_token=eq.' . $hash . '&select=account_id,password_reset_expires_at&limit=1');
    $row = $res['data'][0] ?? null;
    if (!$row || email_token_expired($row['password_reset_expires_at'] ?? null)) {
        return ['valid' => false];
    }
    return ['valid' => true, 'account_id' => (int)$row['account_id'], 'hash' => $hash];
}

function completePatientPasswordReset(string $token, string $new, string $confirm): array {
    $invalid = ['success' => false, 'invalid_link' => true,
                'message' => 'This reset link is invalid, has expired, or has already been used. Please request a new one.'];

    $check = validatePatientResetToken($token);
    if (!$check['valid']) {
        return $invalid;
    }
    if (strlen($new) < PASSWORD_MIN_LENGTH) {
        return ['success' => false, 'message' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.'];
    }
    if (!hash_equals($new, $confirm)) {
        return ['success' => false, 'message' => 'Passwords do not match.'];
    }

    // Update and burn the token in one conditional write: it only matches
    // while this exact hash is still stored, so the link is single-use.
    $account_id = $check['account_id'];
    $save = supabase_request('patient_accounts', 'PATCH', [
        'password_hash'             => password_hash($new, PASSWORD_DEFAULT),
        'password_reset_token'      => null,
        'password_reset_expires_at' => null,
    ], 'account_id=eq.' . $account_id . '&password_reset_token=eq.' . $check['hash']);

    if (($save['status'] ?? 0) < 200 || $save['status'] >= 300 || empty($save['data'])) {
        return $invalid;
    }

    notif_account_event($account_id, 'password_changed', 'Password reset',
        "Your portal password was reset using the Forgot Password link. If this wasn't you, please contact the clinic right away.",
        'acct:password_reset:' . time());

    return ['success' => true, 'message' => 'Your password has been reset.'];
}
