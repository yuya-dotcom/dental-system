<?php
// controllers/mailer.php
// Shared PHPMailer / Gmail SMTP factory.
//
// Extracted out of verify_email_controller.php so it can be reused by
// other flows (e.g. the admin "Forgot Password" email) without pulling
// in that file's own request-handling code. Same credentials, same
// SMTP setup — just centralized in one place.
//
// SMTP credentials live in config/secrets.php (git-ignored; see
// config/secrets.example.php). config/mailer.php just loads this file,
// so there is only one createMailer() definition.

// Composer autoloader: project/vendor/ on the server (copied there by
// .github/workflows/deploy.yml), repo-root vendor/ in local XAMPP.
// A missing autoloader must not break pages that only include this file
// (auth_controller.php is loaded by login), so it fails in createMailer().
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../vendor/autoload.php'] as $_autoload) {
    if (file_exists($_autoload)) {
        require_once $_autoload;
        break;
    }
}
unset($_autoload);

use PHPMailer\PHPMailer\PHPMailer;

function createMailer(): PHPMailer {
    if (!class_exists(PHPMailer::class)) {
        error_log('PHPMailer not found: Composer vendor/ is missing on this server.');
        throw new RuntimeException('Email is unavailable right now.');
    }

    // Plain `require` (not _once): secrets.php may already have been
    // loaded by dbconfig.php / sms_helper.php, and we need its return value.
    static $secrets = null;
    $secrets ??= require __DIR__ . '/../config/secrets.php';

    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $secrets['smtp_username'];
    $mail->Password   = $secrets['smtp_app_password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('essencia.smile@gmail.com', 'EssenciaSmile');
    $mail->isHTML(true);

    return $mail;
}