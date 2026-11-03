<?php
// =============================================================
//  users-forgot-password.php
//  Self-service password reset request — Step 1 of 2.
//  Takes an email OR username, and (if it matches an active
//  account) emails a reset link. Always shows the same generic
//  success message either way, so this never reveals whether a
//  given email/username exists in the system.
// =============================================================

require_once __DIR__ . '/controllers/auth_controller.php';

bootSession();

// Never cache this page — it can carry a submitted identifier.
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

$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if ($identifier !== '') {
        // Return value intentionally unused for the user-facing message —
        // see requestPasswordReset()'s docblock for why.
        requestPasswordReset($identifier);
    }

    $submitted = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Forgot Password</title>
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

            <?php if ($submitted): ?>

                <h1 class="login-heading">Check your email</h1>
                <p class="login-sub">
                    If that email or username matches an account, we've sent a
                    password reset link to the registered email address. The
                    link will expire in 30 minutes.
                </p>

                <a href="login.php" class="btn-signin d-inline-flex align-items-center justify-content-center text-decoration-none">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round" class="me-2">
                        <line x1="19" y1="12" x2="5" y2="12"/>
                        <polyline points="12 19 5 12 12 5"/>
                    </svg>
                    Back to Sign In
                </a>

            <?php else: ?>

                <h1 class="login-heading">Forgot password?</h1>
                <p class="login-sub">Enter your email or username and we'll send you a reset link.</p>

                <form method="POST" action="forgot-password.php" autocomplete="off">

                    <label class="login-label">Email or Username</label>
                    <div class="login-input-wrap">
                        <i class="feather-user input-icon"></i>
                        <input type="text" name="identifier" placeholder="Enter your email or username"
                               required autofocus>
                    </div>

                    <button type="submit" class="btn-signin">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2.5"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                            <polyline points="10 17 15 12 10 7"/>
                            <line x1="15" y1="12" x2="3" y2="12"/>
                        </svg>
                        SEND RESET LINK
                    </button>

                </form>

                <p class="text-center mt-3 mb-0">
                    <a href="login.php" class="small text-decoration-none">
                        <i class="feather-arrow-left me-1"></i>Back to Sign In
                    </a>
                </p>

            <?php endif; ?>

        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/admin.js"></script>
</body>
</html>