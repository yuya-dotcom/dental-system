<?php
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/notification_helper.php';
require_once __DIR__ . '/mailer.php';

use PHPMailer\PHPMailer\Exception;

const EMAIL_TOKEN_TTL_SECONDS = 86400; // 24 hours
const EMAIL_LOGO_PNG = __DIR__ . '/../assets/images/EssenciaSmile-Original-Logo.png';

// Only a SHA-256 hash of the token is stored, so a leaked database row
// can't be used as a working verification link.
function email_token_hash(string $token): string {
    return hash('sha256', $token);
}

function is_valid_token_format(string $token): bool {
    return (bool)preg_match('/^[a-f0-9]{64}$/', $token);
}

// Expiry timestamps are stored as UTC in a "timestamp without time zone" column
function email_token_expired(?string $expires_at): bool {
    if (!$expires_at) return true;
    try {
        $exp = new DateTimeImmutable($expires_at, new DateTimeZone('UTC'));
    } catch (\Exception $e) {
        return true;
    }
    return $exp->getTimestamp() < time();
}

// ─── EMAIL TEMPLATE ───────────────────────────────────────────────────────────
function verification_email_html(string $first_name, string $verify_link, string $logo_src): string {
    $name = htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8');
    $link = htmlspecialchars($verify_link, ENT_QUOTES, 'UTF-8');
    $logo = htmlspecialchars($logo_src, ENT_QUOTES, 'UTF-8');
    $year = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="x-apple-disable-message-reformatting">
<title>Verify your email address</title>
</head>
<body style="margin:0;padding:0;background-color:#eef6fb;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Confirm your email to complete your EssenciaSmile Patient Portal registration.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef6fb;">
  <tr>
    <td align="center" style="padding:32px 12px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background-color:#ffffff;border-radius:14px;box-shadow:0 4px 18px rgba(1,120,184,0.10);border:1px solid #e3eef5;">
        <tr>
          <td align="center" style="padding:32px 32px 8px 32px;">
            <img src="{$logo}" width="240" height="42" alt="EssenciaSmile" style="display:block;width:240px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">
          </td>
        </tr>
        <tr>
          <td style="padding:0 32px;">
            <div style="height:3px;line-height:3px;font-size:0;background-color:#93D135;width:56px;margin:16px auto 0 auto;border-radius:2px;">&nbsp;</div>
          </td>
        </tr>
        <tr>
          <td style="padding:24px 32px 0 32px;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2d3d;">
            <h1 style="margin:0 0 16px 0;font-size:24px;line-height:1.3;font-weight:700;color:#0b3a57;text-align:center;">Verify your email address</h1>
            <p style="margin:0 0 14px 0;font-size:16px;line-height:1.6;">Hi <strong>{$name}</strong>,</p>
            <p style="margin:0 0 14px 0;font-size:16px;line-height:1.6;">Thank you for registering with <strong>EssenciaSmile Dental Clinic</strong>. To complete your Patient Portal registration, please confirm that this email address belongs to you.</p>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding:14px 32px 22px 32px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td align="center" bgcolor="#0279BC" style="border-radius:8px;background-color:#0279BC;">
                  <a href="{$link}" target="_blank" style="display:inline-block;padding:14px 36px;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;font-weight:700;line-height:1.2;color:#ffffff;text-decoration:none;border-radius:8px;background-color:#0279BC;">Verify My Email</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:0 32px;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3faeb;border-left:4px solid #93D135;border-radius:6px;">
              <tr>
                <td style="padding:12px 16px;font-size:14px;line-height:1.5;color:#3b4a22;">
                  &#9201; This link expires in <strong>24 hours</strong> and can be used only once.
                </td>
              </tr>
            </table>
            <p style="margin:18px 0 0 0;font-size:14px;line-height:1.6;color:#4a5a6a;"><strong>Didn't create this account?</strong> You can safely ignore this email &mdash; your email address will not be verified without this confirmation. Never share this link with anyone.</p>
            <p style="margin:18px 0 6px 0;font-size:13px;line-height:1.5;color:#6b7b8b;">If the button doesn't work, copy and paste this link into your browser:</p>
            <p style="margin:0 0 28px 0;font-size:13px;line-height:1.5;word-break:break-all;"><a href="{$link}" target="_blank" style="color:#0279BC;text-decoration:underline;">{$link}</a></p>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding:20px 32px;background-color:#f6f9fb;border-top:1px solid #e3eef5;border-radius:0 0 14px 14px;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#7c8b99;">
            <strong style="color:#0b3a57;">EssenciaSmile Dental Clinic</strong> &middot; Patient Portal<br>
            This is an automated message, please do not reply.<br>
            &copy; {$year} EssenciaSmile Dental Clinic. All rights reserved.
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
}

// ─── SEND VERIFICATION EMAIL ──────────────────────────────────────────────────
function sendVerificationEmail(int $account_id, string $email, string $first_name): array {
    $generic_fail = ['success' => false, 'message' => 'We could not send the verification email right now. Please try again later.'];

    // 1. Generate a secure token; the raw value only ever goes into the email
    $token      = bin2hex(random_bytes(32));
    $expires_at = gmdate('Y-m-d\TH:i:s', time() + EMAIL_TOKEN_TTL_SECONDS);

    // 2. Save the token hash (replaces any earlier, still-pending token)
    $update = supabase_request('patient_accounts', 'PATCH', [
        'email_verification_token' => email_token_hash($token),
        'email_token_expires_at'   => $expires_at
    ], 'account_id=eq.' . $account_id . '&is_verified=eq.false');

    if ($update['status'] < 200 || $update['status'] >= 300) {
        return $generic_fail;
    }
    if (empty($update['data'])) {
        return ['success' => false, 'message' => 'Your email address is already verified.'];
    }

    // 3. Build the verification link from the configured base URL
    $verify_link = app_url('verify') . '?token=' . $token;
    $first_name  = trim($first_name) ?: 'Patient';

    // 4. Send the email; the logo is embedded (CID) so it loads without a public URL
    try {
        $mail = createMailer();
        $mail->CharSet = 'UTF-8';
        $mail->addAddress($email, $first_name);
        $logo_src = '';
        if (is_file(EMAIL_LOGO_PNG)) {
            $mail->addEmbeddedImage(EMAIL_LOGO_PNG, 'essencia-logo', 'EssenciaSmile-Logo.png', 'base64', 'image/png');
            $logo_src = 'cid:essencia-logo';
        }
        $mail->Subject = 'Verify your email address – EssenciaSmile';
        $mail->Body    = verification_email_html($first_name, $verify_link, $logo_src);
        $mail->AltBody = "Hi $first_name,\n\nPlease verify your email address to complete your EssenciaSmile Patient Portal registration:\n$verify_link\n\nThis link expires in 24 hours and can be used only once. If you did not create this account, you can ignore this email.";
        $mail->send();

        return ['success' => true, 'message' => 'Verification email sent successfully.'];
    } catch (Exception | \RuntimeException $e) { // RuntimeException = PHPMailer unavailable
        // ErrorInfo holds SMTP diagnostics only (no token); keep it server-side
        error_log('Verification email failed for account ' . $account_id . ': ' . ($mail->ErrorInfo ?? $e->getMessage()));
        return $generic_fail;
    }
}

// ─── VERIFY TOKEN ─────────────────────────────────────────────────────────────
// $consume = false only checks the link (GET preview page);
// $consume = true marks the account verified and burns the token (POST).
function verifyEmailToken(string $token, bool $consume = true): array {
    $invalid = ['success' => false, 'message' => 'This verification link is invalid or has already been used.'];

    if (!is_valid_token_format($token)) {
        return $invalid;
    }
    $hash = email_token_hash($token);

    // 1. Find the one account holding this token hash
    $result = supabase_request('patient_accounts', 'GET', [],
        'email_verification_token=eq.' . $hash .
        '&select=account_id,email_token_expires_at,is_verified&limit=1');

    if (empty($result['data']) || !isset($result['data'][0])) {
        return $invalid;
    }

    $account = $result['data'][0];

    // 2. Check if already verified
    if ($account['is_verified']) {
        return ['success' => true, 'already_verified' => true, 'message' => 'Your email is already verified.'];
    }

    // 3. Check expiry
    if (email_token_expired($account['email_token_expires_at'])) {
        return ['success' => false, 'message' => 'This verification link has expired. Please request a new one.'];
    }

    if (!$consume) {
        return ['success' => true, 'pending' => true];
    }

    // 4. Mark verified and clear the token in one conditional update: it only
    //    matches while this exact hash is still stored, so the token is single-use
    $account_id = (int)$account['account_id'];
    $verRes = supabase_request('patient_accounts', 'PATCH', [
        'is_verified'              => true,
        'email_verification_token' => null,
        'email_token_expires_at'   => null
    ], 'account_id=eq.' . $account_id . '&email_verification_token=eq.' . $hash . '&is_verified=eq.false');

    if ($verRes['status'] < 200 || $verRes['status'] >= 300 || empty($verRes['data'])) {
        return $invalid;
    }

    // key is unique per verification link, so re-opening the link never duplicates
    notif_account_event($account_id, 'email_verified', 'Email verified',
        'Your email address has been verified successfully.',
        'acct:email_verified:' . md5((string)$account['email_token_expires_at']));

    return ['success' => true, 'already_verified' => false, 'message' => 'Your email has been verified successfully!'];
}

// ─── HANDLE AJAX REQUESTS ─────────────────────────────────────────────────────
// Only when this file is the requested script (verify-email.php includes it too)
if (strcasecmp((string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''), __FILE__) === 0 &&$_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    session_start();

    $action = $_POST['action'] ?? '';

    if ($action === 'send_verification') {
        $account_id = (int)($_SESSION['portal_account_id'] ?? 0);

        if (!$account_id) {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
            exit;
        }

        // Fetch email and name from Supabase
        $accRes = supabase_request('patient_accounts', 'GET', [],
            'account_id=eq.' . $account_id . '&select=email,first_name&limit=1');
        $acc = $accRes['data'][0] ?? null;

        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Account not found.']);
            exit;
        }

        $result = sendVerificationEmail($account_id, $acc['email'], (string)($acc['first_name'] ?? ''));
        echo json_encode($result);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}
