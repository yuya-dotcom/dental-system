<?php
//verify-email.php

session_start();
require_once __DIR__ . '/dbconfig.php';
require_once __DIR__ . '/controllers/verify_email_controller.php';

// The token is a bearer secret: keep it out of caches and Referer headers
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

// GET only checks the link and asks for confirmation; the account is verified
// on POST, so mail scanners that pre-open links can't consume the token.
$isConfirm = $_SERVER['REQUEST_METHOD'] === 'POST';
$token     = (string)($isConfirm ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));
$result    = verifyEmailToken($token, $isConfirm);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="referrer" content="no-referrer">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification – EssenciaSmile</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #f0fdfa 0%, #ccfbf1 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', sans-serif;
        }
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            max-width: 480px;
            width: 100%;
        }
        .icon-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
        }
        .icon-success { background: #d1fae5; color: #059669; }
        .icon-error   { background: #fee2e2; color: #dc2626; }
        .icon-info    { background: #dbeafe; color: #2563eb; }
        .btn-teal {
            background: #0d9488;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 28px;
            font-weight: 600;
        }
        .btn-teal:hover { background: #0f766e; color: #fff; }
        .clinic-name { color: #0d9488; font-weight: 700; }
    </style>
</head>
<body>
    <div class="container px-3">
        <div class="card p-5 text-center mx-auto">

            <!-- Logo / Clinic Name -->
            <div class="mb-3">
                <img src="assets/images/EssenciaSmile-Original-Logo.svg" alt="EssenciaSmile" style="width:220px;max-width:100%;height:auto;">
            </div>

            <?php if ($result['success'] && !empty($result['pending'])): ?>
                <!-- Confirm step -->
                <div class="icon-circle icon-info mx-auto">
                    <i class="bi bi-envelope-paper"></i>
                </div>
                <h4 class="fw-bold mb-2">Confirm your email</h4>
                <p class="text-muted">Click the button below to finish verifying your email address and complete your patient portal registration.</p>
                <form method="post" action="verify-email.php">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn-teal mt-2">
                        <i class="bi bi-check2-circle me-1"></i> Verify My Email
                    </button>
                </form>

            <?php elseif ($result['success']): ?>
                <?php if (!empty($result['already_verified'])): ?>
                    <!-- Already verified -->
                    <div class="icon-circle icon-info mx-auto">
                        <i class="bi bi-check2-circle"></i>
                    </div>
                    <h4 class="fw-bold mb-2">Already Verified</h4>
                    <p class="text-muted">Your email address has already been verified. You're all set!</p>
                <?php else: ?>
                    <!-- Success -->
                    <div class="icon-circle icon-success mx-auto">
                        <i class="bi bi-envelope-check-fill"></i>
                    </div>
                    <h4 class="fw-bold mb-2 text-success">Email Verified!</h4>
                    <p class="text-muted">Your email address has been successfully verified. You can now enjoy full access to your patient portal.</p>
                <?php endif; ?>

                <a href="portal-login.php" class="btn btn-teal mt-3">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Go to Dashboard
                </a>

            <?php else: ?>
                <!-- Error -->
                <div class="icon-circle icon-error mx-auto">
                    <i class="bi bi-x-circle-fill"></i>
                </div>
                <h4 class="fw-bold mb-2 text-danger">Verification Failed</h4>
                <p class="text-muted"><?= htmlspecialchars($result['message']) ?></p>

                <p class="text-muted small">Log in to your portal to request a new verification email, or verify your phone number instead &mdash; verifying either one completes your registration.</p>
                <a href="portal-login.php" class="btn btn-teal mt-2">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Go to Login
                </a>
            <?php endif; ?>

        </div>
    </div>
</body>
</html>