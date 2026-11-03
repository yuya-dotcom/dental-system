<?php
// config/app.php
// Single source of truth for the public base URL used in outgoing links
// (verification emails etc.). Never build these from $_SERVER['HTTP_HOST']:
// that header is client-controlled and can point links at another domain.
//
// Per-environment value, first match wins (no trailing slash):
//   1. APP_BASE_URL server variable (Apache: SetEnv APP_BASE_URL ...)
//   2. 'app_base_url' in config/secrets.php (git-ignored, set per machine:
//      the real https:// domain on the server, a LAN IP for phone testing)
//   3. The local XAMPP URL below.

if (!defined('APP_BASE_URL')) {
    $envBase = $_SERVER['APP_BASE_URL'] ?? getenv('APP_BASE_URL');
    if (!$envBase && is_file(__DIR__ . '/secrets.php')) {
        // Plain `require` (not _once): it may already have been loaded, and we need its return value.
        $_secrets = require __DIR__ . '/secrets.php';
        $envBase  = $_secrets['app_base_url'] ?? '';
        unset($_secrets);
    }
    define('APP_BASE_URL', rtrim($envBase ?: 'http://localhost/project/project', '/'));
}

// app_url('verify-email.php') -> APP_BASE_URL . '/verify-email.php'
function app_url(string $path = ''): string {
    return APP_BASE_URL . '/' . ltrim($path, '/');
}
