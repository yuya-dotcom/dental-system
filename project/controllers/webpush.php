<?php
// =============================================================
//  controllers/webpush.php — Web Push sender (no third-party library)
//
//  Implements the two standards every browser push service accepts:
//    • VAPID (RFC 8292): an ES256-signed JWT identifies this server.
//    • Message encryption "aes128gcm" (RFC 8291 + RFC 8188): the payload
//      is encrypted for the subscriber's browser key, so the push service
//      (Google / Mozilla / Apple) can't read it.
//  Uses only PHP's OpenSSL + hash extensions (no gmp needed).
//
//  Keys live in config/secrets.php (git-ignored):
//    'vapid_public_key'      base64url, 65-byte uncompressed P-256 point
//    'vapid_private_key_pem' PEM (EC P-256 private key)
//    'vapid_subject'         mailto: or https: contact for push services
//  Generate them once with:  php cron/generate_vapid_keys.php
// =============================================================

if (!function_exists('webpush_send')) {

function webpush_b64u_enc(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function webpush_b64u_dec(string $s): string
{
    $s = strtr(trim($s), '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return (string)base64_decode($s, true);
}

/** OpenSSL config for EC key generation (XAMPP on Windows ships one outside the default path). */
function webpush_openssl_args(): array
{
    $args = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
    if (!getenv('OPENSSL_CONF')) {
        foreach ([dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', 'C:/xampp/php/extras/ssl/openssl.cnf'] as $cnf) {
            if (is_file($cnf)) { $args['config'] = $cnf; break; }
        }
    }
    return $args;
}

/** VAPID settings from config/secrets.php, or null when push is not configured. */
function webpush_config(): ?array
{
    static $cfg = false;
    if ($cfg !== false) return $cfg;
    $s   = require __DIR__ . '/../config/secrets.php';
    $pub = (string)($s['vapid_public_key'] ?? '');
    $pem = (string)($s['vapid_private_key_pem'] ?? '');
    $sub = (string)($s['vapid_subject'] ?? '');
    $cfg = (strlen(webpush_b64u_dec($pub)) === 65 && str_contains($pem, 'PRIVATE KEY') && preg_match('#^(mailto:|https://)#', $sub))
        ? ['public' => $pub, 'pem' => $pem, 'subject' => $sub] : null;
    return $cfg;
}

/** Raw 65-byte uncompressed point → PEM public key (SubjectPublicKeyInfo for P-256). */
function webpush_raw_to_pem(string $raw): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** DER ECDSA signature → raw 64-byte r||s (JWS ES256 format). */
function webpush_der_to_raw(string $der): ?string
{
    if (strlen($der) < 8 || ord($der[0]) !== 0x30) return null;
    $pos = 2;
    if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        if (ord($der[$pos]) !== 0x02) return null;
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $pos += 2 + $len;
        $int = ltrim($int, "\x00");
        if (strlen($int) > 32) return null;
        $out .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
    }
    return $out;
}

/** VAPID Authorization header value for one push service origin. */
function webpush_vapid_header(string $endpoint, array $cfg): ?string
{
    $p = parse_url($endpoint);
    if (empty($p['scheme']) || empty($p['host'])) return null;
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $input = webpush_b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.'
           . webpush_b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $cfg['subject']]));
    $key = openssl_pkey_get_private($cfg['pem']);
    if (!$key || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) return null;
    $sig = webpush_der_to_raw($der);
    if ($sig === null) return null;
    return 'vapid t=' . $input . '.' . webpush_b64u_enc($sig) . ', k=' . $cfg['public'];
}

/** RFC 8291 aes128gcm body for one subscriber, or null on bad keys. */
function webpush_encrypt(string $payload, string $p256dhB64, string $authB64, ?string $localPem = null, ?string $salt = null): ?string
{
    $uaPublic = webpush_b64u_dec($p256dhB64);
    $authSec  = webpush_b64u_dec($authB64);
    if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSec) < 16) return null;

    // $localPem / $salt are only passed by the RFC 8291 test vector check (cron/test_webpush_vector.php)
    $local = $localPem !== null ? openssl_pkey_get_private($localPem) : openssl_pkey_new(webpush_openssl_args());
    if (!$local) return null;
    $d = openssl_pkey_get_details($local);
    $asPublic = "\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $peer = openssl_pkey_get_public(webpush_raw_to_pem($uaPublic));
    if (!$peer) return null;
    $shared = openssl_pkey_derive($peer, $local, 32);
    if ($shared === false || strlen($shared) !== 32) return null;

    $ikm   = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $authSec);
    $salt  = $salt ?? random_bytes(16);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
    $tag   = '';
    $ct    = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) return null;
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $ct . $tag;
}

/**
 * Send one push message.
 * Returns ['ok' => bool, 'status' => int, 'gone' => bool (subscription expired: delete it),
 *          'error' => ?string, 'reference' => ?string].
 */
function webpush_send(array $sub, array $message, int $ttl = 86400): array
{
    $out = fn(bool $ok, int $st, ?string $err = null, bool $gone = false, ?string $ref = null)
        => ['ok' => $ok, 'status' => $st, 'gone' => $gone, 'error' => $err, 'reference' => $ref];
    $cfg = webpush_config();
    if (!$cfg) return $out(false, 0, 'push not configured (VAPID keys missing)');
    $endpoint = (string)($sub['endpoint'] ?? '');
    if (!preg_match('#^https://#', $endpoint)) return $out(false, 0, 'invalid endpoint', true);

    $body = webpush_encrypt(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string)($sub['p256dh'] ?? ''), (string)($sub['auth'] ?? ''));
    if ($body === null) return $out(false, 0, 'invalid subscription keys', true);
    $auth = webpush_vapid_header($endpoint, $cfg);
    if ($auth === null) return $out(false, 0, 'could not sign VAPID token');

    $headers = [];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm',
                                   'TTL: ' . $ttl, 'Urgency: normal', 'Authorization: ' . $auth],
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$headers) {
            $p = explode(':', $h, 2);
            if (count($p) === 2) $headers[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($h);
        },
    ] + (function_exists('supabase_tls_options') ? supabase_tls_options() : []));
    $resp  = curl_exec($ch);
    $errno = curl_errno($ch);
    $st    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr  = curl_error($ch);
    curl_close($ch);

    if ($errno) return $out(false, 0, 'network error: ' . substr($cerr, 0, 120));
    if ($st >= 200 && $st < 300) return $out(true, $st, null, false, $headers['location'] ?? null);
    $gone = in_array($st, [404, 410], true);
    return $out(false, $st, 'push service returned HTTP ' . $st . ($resp ? ': ' . substr(trim(strip_tags((string)$resp)), 0, 160) : ''), $gone);
}

} // function_exists guard
