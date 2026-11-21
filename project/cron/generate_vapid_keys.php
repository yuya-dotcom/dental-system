<?php
// =============================================================
//  cron/generate_vapid_keys.php — one-time setup (CLI only)
//
//  Adds to config/secrets.php (git-ignored), if not already there:
//    vapid_public_key, vapid_private_key_pem, vapid_subject  (Web Push)
//    otp_pepper                                             (SMS OTP hashing)
//  Prints only the PUBLIC key. Never overwrites existing values, so it is
//  safe to run again. Use the SAME values on the server (secrets.php is
//  not deployed by FTP; upload it once) and in GitHub Secrets.
//
//  php cron/generate_vapid_keys.php [--subject=mailto:clinic@example.com]
// =============================================================
if (php_sapi_name() !== 'cli') { http_response_code(403); exit(1); }
require_once __DIR__ . '/../controllers/webpush.php';

$file = __DIR__ . '/../config/secrets.php';
$subject = 'mailto:essencia.smile@gmail.com';   // the clinic sender address used by controllers/mailer.php
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--subject=', 10) === 0) $subject = substr($a, 10);
}
if (!preg_match('#^(mailto:[^@\s]+@[^@\s]+|https://\S+)$#', $subject)) { fwrite(STDERR, "Invalid --subject\n"); exit(2); }

$current = require $file;
$add = [];
if (empty($current['vapid_public_key']) || empty($current['vapid_private_key_pem'])) {
    $args = webpush_openssl_args();
    $key  = openssl_pkey_new($args);
    if (!$key || !openssl_pkey_export($key, $pem, null, $args)) { fwrite(STDERR, "Could not create an EC P-256 key: " . openssl_error_string() . "\n"); exit(1); }
    $d   = openssl_pkey_get_details($key);
    $pub = webpush_b64u_enc("\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT));
    $add['vapid_public_key']      = $pub;
    $add['vapid_private_key_pem'] = $pem;
}
if (empty($current['vapid_subject'])) $add['vapid_subject'] = $subject;
if (empty($current['otp_pepper']))    $add['otp_pepper']    = bin2hex(random_bytes(32));

if (!$add) { echo "Nothing to add: VAPID keys and otp_pepper are already in config/secrets.php\n"; exit(0); }

$src = file_get_contents($file);
$pos = strrpos($src, '];');
if ($pos === false) { fwrite(STDERR, "config/secrets.php has no closing '];' — add the keys by hand\n"); exit(1); }
$lines = "\n    // Web Push (VAPID) + SMS OTP hashing — added by cron/generate_vapid_keys.php\n";
foreach ($add as $k => $v) $lines .= '    ' . var_export($k, true) . ' => ' . var_export($v, true) . ",\n";
file_put_contents($file, substr($src, 0, $pos) . $lines . substr($src, $pos));

echo "Added to config/secrets.php: " . implode(', ', array_keys($add)) . "\n";
if (isset($add['vapid_public_key'])) echo "VAPID public key (safe to share): {$add['vapid_public_key']}\n";
