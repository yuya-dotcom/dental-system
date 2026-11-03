<?php
// portal-reset-password.php — target of the Patient Portal "Forgot Password" email.
// GET checks the link and shows the form; POST sets the new password and burns the token.

require_once __DIR__ . '/dbconfig.php';
require_once __DIR__ . '/controllers/password_reset_controller.php';

// The token is a bearer secret: keep it out of caches and Referer headers
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$token  = (string)($isPost ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));
$error  = '';
$done   = false;

if ($isPost) {
    $result = completePatientPasswordReset($token, (string)($_POST['new_password'] ?? ''), (string)($_POST['confirm_password'] ?? ''));
    $done   = $result['success'];
    $valid  = $done || empty($result['invalid_link']);
    $error  = $done ? '' : $result['message'];
} else {
    $valid = validatePatientResetToken($token)['valid'];
}
$e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="referrer" content="no-referrer">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password – EssenciaSmile</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #eef6fb 0%, #d6ecf8 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Segoe UI', sans-serif; }
        .card { border: none; border-radius: 16px; box-shadow: 0 8px 32px rgba(0,0,0,0.1); max-width: 460px; width: 100%; }
        .icon-circle { width: 72px; height: 72px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 32px; }
        .icon-success { background: #d1fae5; color: #059669; }
        .icon-error   { background: #fee2e2; color: #dc2626; }
        .icon-info    { background: #dbeafe; color: #0279BC; }
        .btn-brand { background: #0279BC; color: #fff; border: none; border-radius: 8px; padding: 10px 28px; font-weight: 600; }
        .btn-brand:hover { background: #01699f; color: #fff; }
        .pass-wrap { position: relative; }
        .pass-wrap button { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: 0; background: none; color: #6b7b8b; }
        .form-control { padding-right: 40px; }
    </style>
</head>
<body>
    <div class="container px-3">
        <div class="card p-4 p-sm-5 mx-auto">
            <div class="mb-3 text-center">
                <img src="assets/images/EssenciaSmile-Original-Logo.svg" alt="EssenciaSmile" style="width:210px;max-width:100%;height:auto;">
            </div>

            <?php if ($done): ?>
                <div class="text-center">
                    <div class="icon-circle icon-success"><i class="bi bi-shield-check"></i></div>
                    <h4 class="fw-bold mb-2">Password updated</h4>
                    <p class="text-muted">Your password has been changed. You can now sign in with your new password.</p>
                    <a href="portal-login.php" class="btn btn-brand mt-2"><i class="bi bi-box-arrow-in-right me-1"></i> Go to Login</a>
                </div>

            <?php elseif (!$valid): ?>
                <div class="text-center">
                    <div class="icon-circle icon-error"><i class="bi bi-x-circle-fill"></i></div>
                    <h4 class="fw-bold mb-2">Link invalid or expired</h4>
                    <p class="text-muted">This password reset link is invalid, has expired, or has already been used.</p>
                    <p class="text-muted small">Go back to the login page and choose <strong>Forgot password?</strong> to request a new link.</p>
                    <a href="portal-login.php" class="btn btn-brand mt-2"><i class="bi bi-box-arrow-in-right me-1"></i> Go to Login</a>
                </div>

            <?php else: ?>
                <div class="text-center">
                    <div class="icon-circle icon-info"><i class="bi bi-key"></i></div>
                    <h4 class="fw-bold mb-2">Set a new password</h4>
                    <p class="text-muted">Choose a new password for your Patient Portal account.</p>
                </div>
                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 small" role="alert"><?= $e($error) ?></div>
                <?php endif; ?>
                <form method="post" action="portal-reset-password.php" id="resetForm" novalidate>
                    <input type="hidden" name="token" value="<?= $e($token) ?>">
                    <div class="mb-3">
                        <label for="new_password" class="form-label fw-semibold small">New Password</label>
                        <div class="pass-wrap">
                            <input type="password" class="form-control" id="new_password" name="new_password" minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="Min. <?= PASSWORD_MIN_LENGTH ?> characters" autocomplete="new-password" required>
                            <button type="button" onclick="togglePass('new_password', this)" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label for="confirm_password" class="form-label fw-semibold small">Confirm New Password</label>
                        <div class="pass-wrap">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Re-enter password" autocomplete="new-password" required>
                            <button type="button" onclick="togglePass('confirm_password', this)" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="text-danger small mb-2" id="clientErr" style="min-height:1.2em;"></div>
                    <button type="submit" class="btn btn-brand w-100" id="submitBtn"><i class="bi bi-check2-circle me-1"></i> Reset Password</button>
                </form>
                <div class="text-center mt-3"><a href="portal-login.php" class="small">Back to login</a></div>
                <script>
                    function togglePass(id, btn) {
                        const el = document.getElementById(id);
                        el.type = el.type === 'password' ? 'text' : 'password';
                        btn.firstElementChild.className = el.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
                    }
                    // Client-side checks for quick feedback; the server re-validates everything
                    document.getElementById('resetForm').addEventListener('submit', (ev) => {
                        const p = document.getElementById('new_password').value;
                        const c = document.getElementById('confirm_password').value;
                        const err = document.getElementById('clientErr');
                        err.textContent = '';
                        if (p.length < <?= PASSWORD_MIN_LENGTH ?>) err.textContent = 'Password must be at least <?= PASSWORD_MIN_LENGTH ?> characters.';
                        else if (p !== c) err.textContent = 'Passwords do not match.';
                        if (err.textContent) { ev.preventDefault(); return; }
                        document.getElementById('submitBtn').disabled = true;
                    });
                </script>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
