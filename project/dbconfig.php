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
// ------------------------------------------------------------------
define('SUPABASE_URL', 'https://fgltarvzzreozvtjiefy.supabase.co');
define('SUPABASE_KEY', 'sb_publishable_pbeam841xV0bj3Bumg4Fcg_PFYyNMWm');


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
function supabase_request(
    string $table,
    string $method  = 'GET',
    array  $data    = [],
    string $query   = '',
    array  $headers = []
): array {

    // Build the full URL to the Supabase table endpoint
    // e.g. https://xxx.supabase.co/rest/v1/appointments?branch_id=eq.1
    $url = SUPABASE_URL . '/rest/v1/' . $table;
    if ($query) $url .= '?' . $query;

    // These are like "envelope labels" on the request —
    // they tell Supabase who we are and what format to use
    $defaultHeaders = [
        'apikey: '               . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    // When inserting or updating, tell Supabase to return
    // the saved row so we can confirm what was stored
    if (in_array($method, ['POST', 'PATCH'])) {
        $defaultHeaders[] = 'Prefer: return=representation';
    }

    $allHeaders = array_merge($defaultHeaders, $headers);

    // curl is PHP's built-in way to make HTTP requests
    // Think of it as PHP opening a browser tab to visit a URL
    $responseHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $allHeaders,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders) {
            $len   = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        },
    ]);

    // If we have data to send (POST or PATCH), attach it as JSON
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    // Send the request and wait for Supabase to reply
    $response   = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    // curl_close() removed — deprecated since PHP 8.5
    // PHP cleans up the curl handle automatically now

    // If curl itself failed (no internet, timeout, etc.)
    if ($curlError) {
        error_log('Supabase curl error: ' . $curlError);
        return ['data' => null, 'status' => 0, 'error' => 'Network error. Please try again.'];
    }

    // Convert the JSON string response into a PHP array
    $decoded = json_decode($response, true);

    // 400+ status means something went wrong on Supabase's side
    $error = null;
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
// ──────────────────────────────────────────────────────────────
//  AUDIT LOGGING
//  The old log_action() has been replaced with the universal
//  logActivity() function in controllers/audit_controller.php
//
//  HOW TO LOG IN ANY API FILE:
//    require_once __DIR__ . '/controllers/audit_controller.php';
//    logActivity('patients', 'INSERT', $patientId, $fullName, 'Patient added');
// ──────────────────────────────────────────────────────────────