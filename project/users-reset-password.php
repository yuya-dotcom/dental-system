<?php
// =============================================================
//  reset-password.php
//  Self-service password reset — Step 2 of 2.
//  Reached via the emailed link (?token=...). Validates the token
//  before showing the form, and again on submit (it may have
//  expired or been used in between).
// =============================================================

require_once __DIR__ . '/controllers/auth_controller.php';

bootSession();

// Never cache this page — the URL itself carries a one-time-use token.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
}

// Already logged in? No need to be here.
if (!empty($_SESSION['logged_in'])) {
    header('Location: appointments-schedule.php');
    exit;
}

$token   = $_GET['token'] ?? $_POST['token'] ?? '';
$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword     = $_POST['password']         ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $result = completePasswordReset($token, $newPassword, $confirmPassword);

    if ($result['success']) {
        header('Location: login.php?reset=1');
        exit;
    }

    $error = $result['message'];
}

// Check the token is valid so we know whether to show the form at all.
// On POST failure this re-checks the same thing completePasswordReset()
// already checked — cheap, and keeps this one source of truth.
$tokenCheck = validateResetToken($token);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Reset Password</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="login-page">

    <!-- ── Left: Photo ── -->
    <div class="login-photo-col"></div>

    <!-- ── Right: Form ── -->
    <div class="login-form-col">
        <div class="login-form-inner">

            <!-- Brand -->
            <div class="login-brand">
                <span class="login-logo"></span>
            </div>

            <?php if (!$tokenCheck['valid']): ?>

                <h1 class="login-heading">Link invalid or expired</h1>
                <p class="login-sub">
                    This password reset link is no longer valid. Reset links
                    expire after 30 minutes, or after being used once.
                </p>

                <a href="forgot-password.php" class="btn-signin d-inline-flex align-items-center justify-content-center text-decoration-none">
                    Request a New Link
                </a>

            <?php else: ?>

                <h1 class="login-heading">Set a new password</h1>
                <p class="login-sub">Choose a new password for your account.</p>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show py-2 px-3 mb-3" role="alert">
                    <i class="feather-alert-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST" action="reset-password.php" autocomplete="off">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <!-- New Password -->
                    <label class="login-label">New Password</label>
                    <div class="login-input-wrap">
                        <i class="feather-lock input-icon"></i>
                        <input type="password" name="password" id="newPasswordInput"
                               placeholder="Min. 6 characters" minlength="6" required>
                        <button type="button" class="input-icon-right" onclick="togglePassword('newPasswordInput', 'newPasswordEye')" tabindex="-1">
                            <i class="feather-eye" id="newPasswordEye"></i>
                        </button>
                    </div>

                    <!-- Confirm Password -->
                    <label class="login-label">Confirm Password</label>
                    <div class="login-input-wrap">
                        <i class="feather-lock input-icon"></i>
                        <input type="password" name="confirm_password" id="confirmPasswordInput"
                               placeholder="Re-enter password" minlength="6" required>
                        <button type="button" class="input-icon-right" onclick="togglePassword('confirmPasswordInput', 'confirmPasswordEye')" tabindex="-1">
                            <i class="feather-eye" id="confirmPasswordEye"></i>
                        </button>
                    </div>

                    <button type="submit" class="btn-signin">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2.5"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                            <polyline points="10 17 15 12 10 7"/>
                            <line x1="15" y1="12" x2="3" y2="12"/>
                        </svg>
                        RESET PASSWORD
                    </button>

                </form>

            <?php endif; ?>

        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/admin.js"></script>
    <script>
        // Local to this page — mirrors the (inputId, iconId) pattern
        // needed here since there are two password fields, not one.
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon  = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('feather-eye', 'feather-eye-off');
            } else {
                input.type = 'password';
                icon.classList.replace('feather-eye-off', 'feather-eye');
            }
        }
    </script>
</body>
</html>