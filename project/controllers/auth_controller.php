<?php
// =============================================================
//  controllers/auth_controller.php
//  Authentication & Session Management
//
//  Functions:
//    - loginUser()        — validate credentials, start session
//    - requireLogin()     — redirect to login if not logged in
//    - requireRole()      — redirect if role not allowed
//    - getCurrentUser()   — return session user data
//    - logoutUser()       — destroy session
//
//  Forgot Password (self-service, admin/owner/dentist accounts):
//    - findUserByIdentifier()  — look up a user by email OR username
//    - requestPasswordReset()  — generate + email a reset token
//    - validateResetToken()    — check a token is real, unexpired,
//                                 and belongs to an active account
//    - completePasswordReset() — set the new password, burn the token
//
//  Note: this is separate from the Owner's manual "Reset Password"
//  action in accounts-records.php / api/user_crud.php, which is
//  untouched by any of the above.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../config/app.php';
// controllers/mailer.php (PHPMailer + Composer autoloader, ~50–70 ms per request
// without OPcache) is loaded only when an email is actually sent — see
// sendPasswordResetEmail(). Every staff page includes this file.

use PHPMailer\PHPMailer\Exception;

// ─────────────────────────────────────────────────────────────
//  SESSION BOOTSTRAP
//  Call once at top of every protected page via requireLogin()
// ─────────────────────────────────────────────────────────────
function bootSession(): void
{
    // A page that released the session lock (admin_release_session() in
    // controllers/admin_batch.php) keeps reading $_SESSION — don't re-lock it.
    if (!empty($GLOBALS['__admin_session_released'])) return;
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
}

// ─────────────────────────────────────────────────────────────
//  LOGIN
// ─────────────────────────────────────────────────────────────

/**
 * Attempt login with email + password.
 * Returns ['success' => bool, 'message' => string]
 */
function loginUser(string $email, string $password): array
{
    bootSession();

    if (empty($email) || empty($password)) {
        return ['success' => false, 'message' => 'Email and password are required.'];
    }

    // Fetch user by email
    $result = supabase_request('users', 'GET', [], implode('&', [
        'username=eq.' . urlencode(trim($email)),
        'status=eq.active',
        'select=user_id,user_code,full_name,email,username,password,role,branch_id',
        'limit=1',
    ]));

    if ($result['error'] || empty($result['data'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    $user = $result['data'][0];

    // Verify password
    if (!password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    // Store in session
    $_SESSION['user_id']   = $user['user_id'];
    $_SESSION['user_code'] = $user['user_code'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['branch_id'] = $user['branch_id'];
    $_SESSION['logged_in'] = true;

    // ── Resolve branch_name once at login for audit logs ────
    // Storing it in session avoids an extra DB call on every action.
    if ($user['role'] === 'owner') {
        $_SESSION['branch_name'] = 'All Branches';
    } elseif (!empty($user['branch_id'])) {
        $branchRes = supabase_request(
            'branches', 'GET', [],
            'branch_id=eq.' . (int)$user['branch_id'] . '&select=branch_name&limit=1'
        );
        $_SESSION['branch_name'] = $branchRes['data'][0]['branch_name'] ?? null;
    } else {
        $_SESSION['branch_name'] = null;
    }

    return ['success' => true, 'message' => 'Login successful.'];
}

// ─────────────────────────────────────────────────────────────
//  PROTECTION GUARDS
// ─────────────────────────────────────────────────────────────

/**
 * Redirect to login if not authenticated.
 * Call at the top of every admin page.
 */
function requireLogin(): void
{
    bootSession();
    if (empty($_SESSION['logged_in'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Restrict page to specific roles.
 * Pass one or more allowed roles.
 * e.g. requireRole('owner') or requireRole('owner', 'admin')
 */
function requireRole(string ...$roles): void
{
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        header('Location: unauthorized.php');
        exit;
    }
}

/**
 * Return current logged-in user data from session.
 */
function getCurrentUser(): array
{
    bootSession();
    return [
        'user_id'     => $_SESSION['user_id']     ?? null,
        'user_code'   => $_SESSION['user_code']   ?? null,
        'full_name'   => $_SESSION['full_name']   ?? 'Unknown',
        'email'       => $_SESSION['email']       ?? '',
        'role'        => $_SESSION['role']         ?? '',
        'branch_id'   => $_SESSION['branch_id']   ?? null,
        'branch_name' => $_SESSION['branch_name'] ?? null,
    ];
}

/**
 * Check if current user is owner.
 */
function isOwner(): bool
{
    bootSession();
    return ($_SESSION['role'] ?? '') === 'owner';
}

/**
 * Check if current user is admin.
 */
function isAdmin(): bool
{
    bootSession();
    return ($_SESSION['role'] ?? '') === 'admin';
}

/**
 * Check if current user is dentist.
 */
function isDentist(): bool
{
    bootSession();
    return ($_SESSION['role'] ?? '') === 'dentist';
}

/**
 * Get the branch_id filter for the current user.
 * Owner returns 0 (all branches), others return their branch_id.
 */
function getSessionBranch(): int
{
    bootSession();
    if (($_SESSION['role'] ?? '') === 'owner') return 0;
    return (int)($_SESSION['branch_id'] ?? 0);
}

// ─────────────────────────────────────────────────────────────
//  LOGOUT
// ─────────────────────────────────────────────────────────────

function logoutUser(): void
{
    bootSession();
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

// =============================================================
//  FORGOT PASSWORD (self-service)
// =============================================================

/**
 * Look up an active user by either their username or their email.
 * Returns the matching row (user_id, full_name, email, username) or null.
 */
function findUserByIdentifier(string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    $result = supabase_request('users', 'GET', [], implode('&', [
        'or=(username.eq.' . urlencode($identifier) . ',email.eq.' . urlencode($identifier) . ')',
        'status=eq.active',
        'select=user_id,full_name,email,username',
        'limit=1',
    ]));

    return $result['data'][0] ?? null;
}

/**
 * Handle a "forgot password" request for the given email-or-username.
 *
 * IMPORTANT: the caller must show the SAME message to the user
 * regardless of what this returns — the return value exists only so
 * the caller can log/debug internally. Branching the user-facing
 * message on this would reveal whether an account exists.
 */
function requestPasswordReset(string $identifier): bool
{
    $user = findUserByIdentifier($identifier);
    if (!$user) {
        return false;
    }

    $userId = (int)$user['user_id'];

    // Generate the raw token (only ever seen in the emailed link) and
    // the hash that actually gets stored (same principle as passwords —
    // if the DB leaks, stored hashes alone can't be used to reset anything).
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    // Invalidate any previous pending token for this user before
    // issuing a new one — only the most recently requested link works.
    supabase_request('users_password_reset_tokens', 'DELETE', [], 'user_id=eq.' . $userId);

    $insert = supabase_request('users_password_reset_tokens', 'POST', [
        'user_id'    => $userId,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
    ]);

    if ($insert['error']) {
        return false;
    }

    // Built from the configured base URL, never $_SERVER['HTTP_HOST']
    // (client-controlled; could point the emailed link at another domain).
    $resetLink = app_url('reset-password.php') . '?token=' . $rawToken;

    $sent = sendPasswordResetEmail($user['email'], $user['full_name'], $resetLink);
    return $sent['success'];
}

/**
 * Send the password reset email. Reuses the same PHPMailer/Gmail SMTP
 * setup (controllers/mailer.php) as patient email verification, and
 * mirrors its HTML template for a consistent look across both emails.
 */
function sendPasswordResetEmail(string $email, string $fullName, string $resetLink): array
{
    $name = htmlspecialchars($fullName ?: 'there');
    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
            .header { background: #0d9488; padding: 30px; text-align: center; }
            .header h1 { color: #ffffff; margin: 0; font-size: 24px; }
            .body { padding: 30px; color: #333333; }
            .body p { line-height: 1.6; }
            .btn { display: inline-block; margin: 20px 0; padding: 14px 32px; background: #0d9488; color: #ffffff !important; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; }
            .footer { padding: 20px 30px; background: #f9f9f9; font-size: 12px; color: #999999; text-align: center; }
            .note { font-size: 13px; color: #666; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1> EssenciaSmile</h1>
            </div>
            <div class='body'>
                <p>Hi <strong>$name</strong>,</p>
                <p>We received a request to reset the password for your <strong>EssenciaSmile</strong> staff account. Click the button below to choose a new password:</p>
                <p style='text-align: center;'>
                    <a href='$resetLink' class='btn'>Reset My Password</a>
                </p>
                <p class='note'>This link will expire in <strong>30 minutes</strong>. If you did not request this, you can safely ignore this email — your password will remain unchanged.</p>
                <p class='note'>If the button doesn't work, copy and paste this link into your browser:<br>
                    <a href='$resetLink' style='color: #0d9488; word-break: break-all;'>$resetLink</a>
                </p>
            </div>
            <div class='footer'>
                &copy; " . date('Y') . " EssenciaSmile Dental Clinic. All rights reserved.
            </div>
        </div>
    </body>
    </html>";

    require_once __DIR__ . '/mailer.php';   // loaded lazily (see top of file)

    try {
        $mail = createMailer();
        $mail->addAddress($email, $name);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Reset Your Password – EssenciaSmile';
        $mail->Body    = $html;
        $mail->AltBody = "Hi $name, reset your password here: $resetLink (expires in 30 minutes). If you didn't request this, you can ignore this email.";
        $mail->send();

        return ['success' => true, 'message' => 'Reset email sent successfully.'];
    } catch (Exception | \RuntimeException $e) {
        // RuntimeException = PHPMailer unavailable (see createMailer()).
        // Keep diagnostics server-side; never echo SMTP/path details.
        error_log('Password reset email failed: ' . (isset($mail) ? ($mail->ErrorInfo ?: $e->getMessage()) : $e->getMessage()));
        return ['success' => false, 'message' => 'We could not send the reset email right now. Please try again later.'];
    }
}

/**
 * Check whether a raw reset token (as it appears in the URL) is real,
 * unexpired, and still points at an active account.
 * Returns ['valid' => bool, 'user_id' => ?int, 'full_name' => ?string]
 */
function validateResetToken(string $rawToken): array
{
    if (empty($rawToken)) {
        return ['valid' => false];
    }

    $tokenHash = hash('sha256', $rawToken);
    $nowIso    = gmdate('Y-m-d\TH:i:s\Z');

    $result = supabase_request('users_password_reset_tokens', 'GET', [], implode('&', [
        'token_hash=eq.' . urlencode($tokenHash),
        'expires_at=gt.' . urlencode($nowIso),
        'select=user_id',
        'limit=1',
    ]));

    $row = $result['data'][0] ?? null;
    if (!$row) {
        return ['valid' => false];
    }

    $userId = (int)$row['user_id'];

    // Confirm the account is still active (e.g. wasn't deactivated
    // in between the reset request and the link being opened).
    $userRes = supabase_request('users', 'GET', [],
        'user_id=eq.' . $userId . '&status=eq.active&select=user_id,full_name&limit=1'
    );
    $user = $userRes['data'][0] ?? null;
    if (!$user) {
        return ['valid' => false];
    }

    return ['valid' => true, 'user_id' => $userId, 'full_name' => $user['full_name']];
}

/**
 * Complete a password reset: validate the token, validate the new
 * password, update it, and burn the token so it can't be reused.
 */
function completePasswordReset(string $rawToken, string $newPassword, string $confirmPassword): array
{
    $check = validateResetToken($rawToken);
    if (!$check['valid']) {
        return ['success' => false, 'message' => 'This reset link is invalid or has expired.'];
    }

    if (strlen($newPassword) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
    }
    if ($newPassword !== $confirmPassword) {
        return ['success' => false, 'message' => 'Passwords do not match.'];
    }

    $userId = $check['user_id'];

    $update = supabase_request('users', 'PATCH', [
        'password'   => password_hash($newPassword, PASSWORD_BCRYPT),
        'updated_at' => date('c'),
    ], 'user_id=eq.' . $userId);

    if ($update['error']) {
        return ['success' => false, 'message' => 'Could not update password. Please try again.'];
    }

    // One-time use: deleting the token is what makes it permanently
    // unusable, even if the link is opened again afterwards.
    supabase_request('users_password_reset_tokens', 'DELETE', [], 'user_id=eq.' . $userId);

    return ['success' => true, 'message' => 'Password reset successfully.'];
}