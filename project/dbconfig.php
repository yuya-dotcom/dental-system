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
        ]);
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

    if ($curlError) {
        error_log('Supabase curl error: ' . $curlError);
        // Reset handle on error so next call gets a fresh connection
        curl_close($_supabase_ch);
        $_supabase_ch = null;
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