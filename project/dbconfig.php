<?php
// =============================================================
//  EssenciaSmile – Supabase Configuration
//  File: project/dbconfig.php
//
//  HOW TO USE IN ANY OTHER PHP FILE:
//      require_once __DIR__ . '/dbconfig.php';
//      $result = supabase_request('table_name', 'GET', [], 'filters');
// =============================================================


// ------------------------------------------------------------------
// YOUR SUPABASE CREDENTIALS
// Think of these like your clinic's address and key card.
// SUPABASE_URL  = where your database lives on the internet
// SUPABASE_KEY  = the password that proves your PHP is allowed in
//
// The real values live in config/secrets.php (git-ignored; see
// config/secrets.example.php). Plain `require` (not _once) so the
// returned array is still available if sms_helper.php loaded it first.
// ------------------------------------------------------------------
$_secrets = require __DIR__ . '/config/secrets.php';
define('SUPABASE_URL', $_secrets['supabase_url']);
define('SUPABASE_KEY', $_secrets['supabase_key']);
unset($_secrets);

// The clinic runs on Philippine time. Without this, date()/strtotime() use the
// server's php.ini zone (Europe/Berlin locally, likely UTC on the host), which
// saved invoices made after midnight under the previous day and shifted times
// by 7–8 hours. Stored timestamps carry their offset, so existing data is unaffected.
date_default_timezone_set('Asia/Manila');


// ------------------------------------------------------------------
//  THE MAIN FUNCTION: supabase_request()
//
//  This is the one tool you use to talk to your database.
//  Every read, write, update, or delete goes through this.
//
//  PARAMETERS (what you pass in):
//    $table   -> which table? e.g. 'appointments', 'patients'
//    $method  -> what action?
//                 'GET'    = read / fetch data
//                 'POST'   = insert / add a new row
//                 'PATCH'  = update an existing row
//                 'DELETE' = delete a row
//    $data    -> the data you want to save (only for POST and PATCH)
//                 Leave as [] for GET and DELETE
//    $query   -> filters to narrow down results
//                 Supabase filter format: 'column=eq.value'
//                 eq  = equals,  neq = not equals
//                 gt  = greater than,  lt = less than
//
//  RETURN VALUE (what you get back):
//    An array with 3 keys:
//      'data'   -> the actual rows from Supabase
//      'status' -> HTTP code (200 = OK, 400+ = error)
//      'error'  -> error message string, or null if all good
// ------------------------------------------------------------------
// Persistent curl handle — reuses the same HTTPS connection
// instead of opening a new one for every request
$_supabase_ch = null;

// ------------------------------------------------------------------
//  TLS PERFORMANCE (Windows / XAMPP only)
//  PHP's curl on Windows uses OpenSSL 3.0, which spends ~0.5 s parsing
//  the PEM CA bundle (php.ini curl.cainfo) for EVERY new connection.
//  The Windows certificate store gives the same full verification
//  (peer + host name) in ~0.04 s. Linux hosts are left unchanged.
//  If a certificate check ever fails with the Windows store (e.g. a root
//  missing from it), the request is retried once with the CA bundle —
//  safe even for writes, because a failed handshake sends nothing —
//  and the bundle is used for the rest of this PHP request.
// ------------------------------------------------------------------
function supabase_tls_options(): array
{
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA') && empty($GLOBALS['_supabase_tls_bundle'])) {
        return [CURLOPT_CAINFO => null, CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA];
    }
    return [];
}

/** True when a certificate-verification error should be retried with the CA bundle. */
function supabase_tls_fallback(int $curlErrno): bool
{
    if (!in_array($curlErrno, [60, 77], true) || !supabase_tls_options()) return false;   // 60 = peer verification, 77 = CA certs
    $GLOBALS['_supabase_tls_bundle'] = true;
    error_log('Supabase TLS: Windows certificate store failed verification; using the CA bundle for this request.');
    return true;
}

function supabase_request(
    string $table,
    string $method  = 'GET',
    array  $data    = [],
    string $query   = '',
    array  $headers = []
): array {
    global $_supabase_ch;

    // Create the handle once and reuse it for every call
    if ($_supabase_ch === null) {
        $_supabase_ch = curl_init();
        curl_setopt_array($_supabase_ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            // Keep the connection alive between requests
            CURLOPT_FORBID_REUSE   => false,
            CURLOPT_FRESH_CONNECT  => false,
            // Cache DNS so we don't re-resolve supabase.co every call
            CURLOPT_DNS_CACHE_TIMEOUT => 300,
        ] + supabase_tls_options());
    }

    $url = SUPABASE_URL . '/rest/v1/' . $table;
    if ($query) $url .= '?' . $query;

    // sb_secret_ keys are not JWTs, so they go in `apikey` only. To act as a
    // signed-in user, pass 'Authorization: Bearer <user access token>' in $headers.
    $defaultHeaders = [
        'apikey: ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
        // Tell the server to keep the connection open
        'Connection: keep-alive',
    ];

    if (in_array($method, ['POST', 'PATCH'])) {
        $defaultHeaders[] = 'Prefer: return=representation';
    }

    $allHeaders = array_merge($defaultHeaders, $headers);

    $responseHeaders = [];
    curl_setopt_array($_supabase_ch, [
        CURLOPT_URL           => $url,
        CURLOPT_HTTPHEADER    => $allHeaders,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS    => !empty($data) ? json_encode($data) : null,
        CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders) {
            $len   = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        },
    ]);

    // Clear POST body for GET/DELETE requests
    if (in_array($method, ['GET', 'DELETE'])) {
        curl_setopt($_supabase_ch, CURLOPT_POSTFIELDS, null);
    }

    $response   = curl_exec($_supabase_ch);
    $statusCode = curl_getinfo($_supabase_ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($_supabase_ch);
    $curlErrno  = curl_errno($_supabase_ch);

    if ($curlError) {
        error_log('Supabase curl error: ' . $curlError);
        // Reset handle on error so next call gets a fresh connection
        curl_close($_supabase_ch);
        $_supabase_ch = null;
        // Certificate check failed with the Windows store → retry once with the CA bundle
        if (supabase_tls_fallback($curlErrno)) return supabase_request($table, $method, $data, $query, $headers);
        return ['data' => null, 'status' => 0, 'error' => 'Network error. Please try again.'];
    }

    $decoded = json_decode($response, true);
    $error   = null;

    if ($statusCode >= 400) {
        $error = $decoded['message'] ?? $decoded['error'] ?? 'Unknown error from Supabase.';
        error_log("Supabase error [{$statusCode}] on {$method} {$table}: {$error}");
    }

    return [
        'data'    => $decoded,
        'status'  => $statusCode,
        'error'   => $error,
        'headers' => $responseHeaders,
    ];
}