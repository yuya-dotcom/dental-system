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
/**
 * Returns the <img src> for the email logo ($file is under assets/images/).
 *
 * The logo is always loaded from a public https site, never attached: Gmail
 * lists an embedded (CID) image as an attachment chip ("EssenciaSmile-Logo.png")
 * in the inbox, even though it is only the header logo.
 *   - Live site (APP_BASE_URL is public https): this site's own copy.
 *   - Local XAMPP / LAN (no public URL): the live site's copy, so local test
 *     emails look the same. Override with 'email_asset_base_url' in
 *     config/secrets.php; set it to '' to embed the logo again.
 */
const MAILER_DEFAULT_ASSET_BASE = 'https://essenciasmile.online';

function mailer_logo_src(PHPMailer $mail, string $file): string
{
    require_once __DIR__ . '/../config/app.php';   // APP_BASE_URL, app_url()
    $host = strtolower((string)parse_url(APP_BASE_URL, PHP_URL_HOST));
    $isPublic = str_starts_with(APP_BASE_URL, 'https://') && $host !== ''
        && $host !== 'localhost' && !str_ends_with($host, '.local')
        && (filter_var($host, FILTER_VALIDATE_IP) === false
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);

    $base = APP_BASE_URL;
    if (!$isPublic) {
        $secrets = is_file(__DIR__ . '/../config/secrets.php') ? (require __DIR__ . '/../config/secrets.php') : [];
        $base    = rtrim((string)($secrets['email_asset_base_url'] ?? MAILER_DEFAULT_ASSET_BASE), '/');
        unset($secrets);
    }

    if ($base !== '') {
        // ?v= = file date, so a changed logo is not served from old caches
        $v = is_file($file) ? '?v=' . filemtime($file) : '';
        return $base . '/assets/images/' . rawurlencode(basename($file)) . $v;
    }
    if (!is_file($file)) return '';
    $mail->addEmbeddedImage($file, 'essencia-logo', 'EssenciaSmile-Logo.png', 'base64', 'image/png');
    return 'cid:essencia-logo';
}
