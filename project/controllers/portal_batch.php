<?php
// =============================================================
//  controllers/portal_batch.php — parallel Supabase reads for the Patient Portal
//
//  Every PHP request has to open its own TLS connection to Supabase, and that
//  handshake costs far more than a query (≈0.5 s vs ≈0.1 s). The old portal
//  endpoints then ran their queries one after another. This file lets a
//  request send all independent queries at once, multiplexed over ONE
//  HTTP/2 connection:
//
//    portal_sb_multi([...])  — run several requests in parallel
//    portal_run([...])       — drive "loader" generators in waves: each loader
//                              yields the requests it needs next, receives the
//                              results, and finally returns its JSON payload.
//                              All loaders' requests of the same wave share
//                              one round trip.
//
//  Results have the same shape as supabase_request() in dbconfig.php
//  (data / status / error / headers). dbconfig.php itself is not changed, so
//  the staff modules keep using supabase_request() exactly as before.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';

/** True when PHP's parallel curl functions are usable (disabled on some hosts). */
function portal_multi_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = true;
        foreach (['curl_multi_init', 'curl_multi_add_handle', 'curl_multi_exec', 'curl_multi_select',
                  'curl_multi_info_read', 'curl_multi_getcontent', 'curl_multi_remove_handle'] as $f) {
            if (!function_exists($f)) { $ok = false; break; }
        }
    }
    return $ok;
}

/** A request spec for portal_sb_multi(). */
function sbq(string $table, string $query = '', string $method = 'GET', array $data = []): array
{
    return ['table' => $table, 'query' => $query, 'method' => $method, 'data' => $data];
}

/**
 * Runs the requests in parallel and returns their results under the same keys.
 * @param array<string,array> $reqs key => sbq(...)
 */
function portal_sb_multi(array $reqs): array
{
    static $mh = null;           // kept for the whole PHP request → one reused connection
    if (!$reqs) return [];

    // Some hosts (InfinityFree) disable curl_multi_* → run the same requests
    // one by one through supabase_request() (same result shape)
    if (!portal_multi_available()) {
        $out = [];
        foreach ($reqs as $key => $r) {
            $out[$key] = supabase_request($r['table'], strtoupper($r['method'] ?? 'GET'), $r['data'] ?? [], $r['query'] ?? '');
        }
        return $out;
    }

    if ($mh === null) {
        $mh = curl_multi_init();
        if (defined('CURLMOPT_PIPELINING') && defined('CURLPIPE_MULTIPLEX')) {
            curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        }
    }

    $handles = [];
    $headers = [];
    foreach ($reqs as $key => $r) {
        $method = strtoupper($r['method'] ?? 'GET');
        $url    = SUPABASE_URL . '/rest/v1/' . $r['table'] . (($r['query'] ?? '') !== '' ? '?' . $r['query'] : '');
        $hdr    = [
            'apikey: ' . SUPABASE_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if (in_array($method, ['POST', 'PATCH'], true)) $hdr[] = 'Prefer: return=representation';

        $headers[$key] = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
            // Wait for the first connection and multiplex on it instead of
            // opening (and TLS-handshaking) one connection per request
            CURLOPT_PIPEWAIT       => true,
            CURLOPT_DNS_CACHE_TIMEOUT => 300,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers, $key) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[$key][strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
        ]);
        if (!empty($r['data'])) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($r['data']));
        curl_setopt_array($ch, supabase_tls_options());   // dbconfig.php: fast Windows CA store
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running && curl_multi_select($mh, 1.0) === -1) usleep(1000);
    } while ($running && $status === CURLM_OK);
    foreach ($reqs as $r) es_scache_note($r['method'] ?? 'GET');   // writes invalidate the shared cache

    // Per-transfer result codes (curl_errno() is not set for multi transfers)
    $errnos = [];
    while ($info = curl_multi_info_read($mh)) $errnos[spl_object_id($info['handle'])] = $info['result'];
    $out = [];
    $retry = [];
    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if (in_array($errnos[spl_object_id($ch)] ?? 0, [60, 77], true)) $retry[] = $key;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($err !== '' || $code === 0) {
            error_log('Supabase curl error (portal batch): ' . ($err ?: 'no response'));
            $out[$key] = ['data' => null, 'status' => 0, 'error' => 'Network error. Please try again.', 'headers' => []];
            continue;
        }
        $decoded = json_decode((string)$body, true);
        $error   = null;
        if ($code >= 400) {
            $error = $decoded['message'] ?? $decoded['error'] ?? 'Unknown error from Supabase.';
            error_log("Supabase error [{$code}] on {$reqs[$key]['method']} {$reqs[$key]['table']}: {$error}");
        }
        $out[$key] = ['data' => $decoded, 'status' => $code, 'error' => $error, 'headers' => $headers[$key]];
    }

    // Certificate check failed with the Windows store → retry those once with the CA bundle
    if ($retry && supabase_tls_fallback(60)) {
        foreach (portal_sb_multi(array_intersect_key($reqs, array_flip($retry))) as $k => $r) $out[$k] = $r;
    }
    return $out;
}

/**
 * Drives loader generators concurrently. Each loader yields
 * [key => sbq(...)] and receives [key => result]; its return value is its
 * payload. A loader that throws gets ['success' => false] (the others continue).
 * @param array<string,Generator> $loaders
 */
function portal_run(array $loaders): array
{
    $results = [];
    $active  = [];
    foreach ($loaders as $name => $gen) {
        try {
            if ($gen->valid()) $active[$name] = $gen;
            else $results[$name] = $gen->getReturn();
        } catch (Throwable $e) {
            error_log("portal loader {$name}: " . $e->getMessage());
            $results[$name] = ['success' => false, 'message' => 'Failed to load.'];
        }
    }

    while ($active) {
        $batch = [];
        foreach ($active as $name => $gen) {
            foreach ((array)$gen->current() as $k => $req) $batch[$name . "\x1F" . $k] = $req;
        }
        $res = portal_sb_multi_merged($batch);

        foreach ($active as $name => $gen) {
            $mine = [];
            foreach ((array)$gen->current() as $k => $_) $mine[$k] = $res[$name . "\x1F" . $k];
            try {
                $gen->send($mine);
                if (!$gen->valid()) {
                    $results[$name] = $gen->getReturn();
                    unset($active[$name]);
                }
            } catch (Throwable $e) {
                error_log("portal loader {$name}: " . $e->getMessage());
                $results[$name] = ['success' => false, 'message' => 'Failed to load.'];
                unset($active[$name]);
            }
        }
    }

    // Keep the caller's order
    $ordered = [];
    foreach ($loaders as $name => $_) $ordered[$name] = $results[$name] ?? null;
    return $ordered;
}

/**
 * portal_sb_multi() for one wave of loader requests, with fewer round trips:
 *   • identical GETs are sent once;
 *   • GETs on the same table with the SAME filters / order / limit whose
 *     select lists are plain columns (no embeds, aliases or casts) are sent
 *     as ONE request for the union of columns, and every loader gets its
 *     rows cut back to exactly the columns it asked for, in its own order —
 *     the same result it would have received on its own.
 * If a merged request fails (e.g. one loader names a column that does not
 * exist yet), its loaders are re-run separately, so one failure never
 * spreads to another loader. Writes and POST RPCs are never merged.
 */
function portal_sb_multi_merged(array $batch): array
{
    $groups = [];   // group key => [members: key => cols|null]
    $send   = [];   // request key => spec
    $of     = [];   // original key => request key
    foreach ($batch as $key => $r) {
        $method = strtoupper($r['method'] ?? 'GET');
        if ($method !== 'GET' || !empty($r['data'])) { $send[$key] = $r; $of[$key] = $key; continue; }
        $sel = null; $rest = [];
        foreach (explode('&', (string)($r['query'] ?? '')) as $part) {
            if (str_starts_with($part, 'select=') && $sel === null) $sel = substr($part, 7);
            else $rest[] = $part;
        }
        $plain = $sel !== null && preg_match('/^[a-z_][a-z0-9_]*(,[a-z_][a-z0-9_]*)*$/', $sel);
        // plain column lists group by table + filters; anything else only with exact duplicates
        $g = $plain ? 'P|' . $r['table'] . '?' . implode('&', $rest) : 'X|' . $r['table'] . '?' . $r['query'];
        $groups[$g]['members'][$key] = $plain ? explode(',', $sel) : null;
        $groups[$g]['spec'] ??= $r;
        $groups[$g]['rest'] = $rest;
    }
    $merged = [];   // request key => [original key => cols|null]
    foreach ($groups as $g => $grp) {
        $first = array_key_first($grp['members']);
        if (count($grp['members']) === 1) { $send[$first] = $batch[$first]; $of[$first] = $first; continue; }
        $spec = $grp['spec'];
        if ($g[0] === 'P') {
            $cols = [];
            foreach ($grp['members'] as $c) foreach ($c as $col) $cols[$col] = true;
            $spec['query'] = implode('&', array_merge(['select=' . implode(',', array_keys($cols))], array_filter($grp['rest'], fn($p) => $p !== '')));
        }
        $send[$first] = $spec;
        $merged[$first] = $grp['members'];
        foreach ($grp['members'] as $k => $_) $of[$k] = $first;
    }
    if (!$merged) return portal_sb_multi($batch);

    $res = portal_sb_multi($send);
    $out = []; $retry = [];
    foreach ($batch as $key => $_) {
        $rk = $of[$key];
        $r  = $res[$rk];
        if (!isset($merged[$rk])) { $out[$key] = $r; continue; }
        $ok = empty($r['error']) && ($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300 && is_array($r['data']);
        if (!$ok) { $retry[$key] = $batch[$key]; continue; }
        $cols = $merged[$rk][$key];
        if ($cols !== null) {
            $r['data'] = array_map(function ($row) use ($cols) {
                $o = [];
                foreach ($cols as $c) if (is_array($row) && array_key_exists($c, $row)) $o[$c] = $row[$c];
                return $o;
            }, $r['data']);
        }
        $out[$key] = $r;
    }
    if ($retry) foreach (portal_sb_multi($retry) as $k => $r) $out[$k] = $r;
    $ordered = [];
    foreach ($batch as $key => $_) $ordered[$key] = $out[$key];
    return $ordered;
}

/** Runs a single loader and returns its payload. */
function portal_run_one(Generator $loader)
{
    return portal_run(['x' => $loader])['x'];
}

/** Writes values to the session from a request that already released the lock. */
function portal_session_set(array $values, array $unset = []): void
{
    $wasActive = session_status() === PHP_SESSION_ACTIVE;
    if (!$wasActive) @session_start();
    foreach ($values as $k => $v) $_SESSION[$k] = $v;
    foreach ($unset as $k) unset($_SESSION[$k]);
    if (!$wasActive) session_write_close();
}

/**
 * patient_id linked to the portal account. Cached in the server session once
 * found (the link is set when the patient record is created and never moves),
 * so most requests skip this lookup entirely. Not cached while unlinked.
 */
function pl_patient_id(int $accountId): Generator
{
    $cached = $_SESSION['portal_patient_id'] ?? null;
    if ($cached && (int)($_SESSION['portal_patient_id_for'] ?? 0) === $accountId) return (int)$cached;

    $r  = yield ['acc' => sbq('patient_accounts', 'account_id=eq.' . $accountId . '&select=patient_id&limit=1')];
    $id = $r['acc']['data'][0]['patient_id'] ?? null;
    if ($id) portal_session_set(['portal_patient_id' => (int)$id, 'portal_patient_id_for' => $accountId]);
    return $id ? (int)$id : null;
}
