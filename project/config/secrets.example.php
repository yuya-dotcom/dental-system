<?php
// config/secrets.example.php
// Copy to config/secrets.php and fill in real values.
// config/secrets.php is git-ignored — never commit real keys.

if (!defined('UNISMS_API_KEY')) {
    define('UNISMS_API_KEY', 'sk_your_key_here');
}

if (!defined('UNISMS_SENDER_ID')) {
    define('UNISMS_SENDER_ID', 'Unisoft');
}

return [
    'supabase_url' => 'https://your-project-ref.supabase.co',
    'supabase_key' => 'sb_secret_your_secret_key_here',               // server-side only
    'supabase_publishable_key' => 'sb_publishable_your_key_here',  // safe for the browser

    'smtp_username'     => 'your_gmail_address@gmail.com',
    'smtp_app_password' => 'your_gmail_app_password_here',

    // Public base URL for emailed links, no trailing slash (config/app.php).
    // Server: 'https://your-domain'. Local: omit for localhost, or a LAN IP for phones.
    'app_base_url' => 'https://your-domain.example',

    // Web Push (VAPID) + SMS OTP hashing. Generate once with:
    //   php cron/generate_vapid_keys.php --subject=mailto:clinic@example.com
    // and copy the SAME values to the server and to the GitHub Actions secrets
    // (.github/workflows/reminders.yml). Changing the VAPID keys invalidates every
    // patient's push subscription; changing otp_pepper only voids codes in flight.
    'vapid_public_key'      => '',   // base64url, 65-byte P-256 point (safe to share)
    'vapid_private_key_pem' => '',   // -----BEGIN PRIVATE KEY----- … (server only)
    'vapid_subject'         => 'mailto:clinic@example.com',
    'otp_pepper'            => '',   // 64 hex chars
];
