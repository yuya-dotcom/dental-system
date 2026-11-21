<?php
// =============================================================
//  controllers/admin_batch.php — faster Supabase reads for the staff pages
//
//  WHY: every PHP request opens its own TLS connection to Supabase
//  (≈0.3–0.5 s), and the staff pages then ran 3–30 queries one after
//  another (≈0.13 s each). This file lets a page:
//
//    admin_sb_many([...])   run several GET queries at once, multiplexed
//                           over ONE HTTP/2 connection
//    admin_prefetch([...])  run the page's independent queries in one
//                           parallel wave and remember the results, so the
//                           navbar bell and getAllBranches() reuse them
//                           instead of querying again
//
//  The connection is shared (curl share handle) with dbconfig.php's
//  supabase_request(), so any query that still goes through it reuses the
//  same connection instead of paying for a second TLS handshake.
//  dbconfig.php itself is NOT modified.
//
//  Also here:
//    admin_release_session()  release the PHP session lock once auth is done
//    admin_cache_get/_set()   short-lived per-user cache in the session
//
//  Results have exactly the same shape as supabase_request():
//    ['data' => ..., 'status' => int, 'error' => ?string, 'headers' => [...]]
// =============================================================

require_once __DIR__ . '/../dbconfig.php';

/** One curl share handle per PHP request: connection, DNS and TLS session are shared. */
function admin_sb_share()
{
    static $sh = null;
    if ($sh === null) {
        $sh = curl_share_init();
        foreach (['CURL_LOCK_DATA_CONNECT', 'CURL_LOCK_DATA_DNS', 'CURL_LOCK_DATA_SSL_SESSION'] as $c) {
            if (defined($c)) curl_share_setopt($sh, CURLSHOPT_SHARE, constant($c));
        }
    }
    return $sh;
}

/**
 * Attach the share handle to dbconfig.php's persistent curl handle, creating
 * it with the same options dbconfig.php uses if no request was made yet.
 */
function admin_sb_attach_share(): void
{
    global $_supabase_ch;
    static $done = false;
    if ($done) return;
    $done = true;
    // Host without curl share handles → dbconfig.php creates its own handle as usual
    if (!function_exists('curl_share_init') || !function_exists('curl_share_setopt')) return;

    // If supabase_request() already ran, its handle holds an open connection in
    // its own pool; attaching the share now would strand that connection and
    // force a new handshake, so leave such a handle alone.
    if ($_supabase_ch !== null) return;

    $_supabase_ch = curl_init();
    curl_setopt_array($_supabase_ch, [
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_TIMEOUT           => 15,
        CURLOPT_FORBID_REUSE      => false,
        CURLOPT_FRESH_CONNECT     => false,
        CURLOPT_DNS_CACHE_TIMEOUT => 300,
        CURLOPT_SHARE             => admin_sb_share(),
    ] + supabase_tls_options());   // dbconfig.php: fast Windows CA store
}
admin_sb_attach_share();

/** A GET request spec for admin_sb_many(). $headers e.g. ['Prefer: count=exact']. */
function admin_q(string $table, string $query = '', array $headers = []): array
{
    return ['table' => $table, 'query' => $query, 'method' => 'GET', 'headers' => $headers];
}

/** Runs the read requests in parallel; returns results under the same keys. */
function admin_sb_many(array $reqs): array
{
    return $reqs ? admin_sb_exec($reqs) : [];
}

/**
 * True when PHP's parallel curl functions are usable. Some hosts (InfinityFree)
 * disable curl_multi_*; the batch then runs the same requests one by one.
 */
function sb_multi_available(): bool
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

/** Low-level parallel executor (kept separate so it has a single entry point). */
function admin_sb_exec(array $reqs): array
{
    // No curl_multi on this host → same requests, sequentially, same result shape
    if (!sb_multi_available()) {
        $out = [];
        foreach ($reqs as $key => $r) {
            $out[$key] = supabase_request($r['table'], strtoupper($r['method'] ?? 'GET'), [], $r['query'] ?? '', $r['headers'] ?? []);
        }
        return $out;
    }

    static $mh = null;
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
        $hdr    = array_merge([
            'apikey: ' . SUPABASE_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $r['headers'] ?? []);

        $headers[$key] = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL               => $url,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_TIMEOUT           => 15,
            CURLOPT_CUSTOMREQUEST     => $method,
            CURLOPT_HTTPHEADER        => $hdr,
            CURLOPT_HTTP_VERSION      => CURL_HTTP_VERSION_2TLS,
            CURLOPT_PIPEWAIT          => true,   // multiplex on one connection
            CURLOPT_DNS_CACHE_TIMEOUT => 300,
            CURLOPT_SHARE             => admin_sb_share(),
            CURLOPT_HEADERFUNCTION    => function ($c, $line) use (&$headers, $key) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[$key][strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
        ]);
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
            error_log('Supabase curl error (admin batch): ' . ($err ?: 'no response'));
            $out[$key] = ['data' => null, 'status' => 0, 'error' => 'Network error. Please try again.', 'headers' => []];
            continue;
        }
        $decoded = json_decode((string)$body, true);
        $error   = null;
        if ($code >= 400) {
            $error = $decoded['message'] ?? $decoded['error'] ?? 'Unknown error from Supabase.';
            error_log("Supabase error [{$code}] on GET {$reqs[$key]['table']}: {$error}");
        }
        $out[$key] = ['data' => $decoded, 'status' => $code, 'error' => $error, 'headers' => $headers[$key]];
    }

    // Certificate check failed with the Windows store → retry those once with the CA bundle
    if ($retry && supabase_tls_fallback(60)) {
        foreach (admin_sb_exec(array_intersect_key($reqs, array_flip($retry))) as $k => $r) $out[$k] = $r;
    }
    return $out;
}

/** Total from a 'Prefer: count=exact' response (Content-Range "0-9/42" or "* /42"). */
function admin_count(array $res): int
{
    $cr = $res['headers']['content-range'] ?? '';
    if ($cr && str_contains($cr, '/')) return (int)explode('/', $cr)[1];
    return is_array($res['data'] ?? null) ? count($res['data']) : 0;
}

// ─────────────────────────────────────────────────────────────
//  PER-REQUEST PREFETCH MEMO
// ─────────────────────────────────────────────────────────────

/** The branch dropdown query used by getAllBranches() (branch_controller.php). */
function admin_branches_spec(): array
{
    return admin_scache_mark(admin_q('branches', 'select=branch_id,branch_name&order=branch_name.asc'));
}

/**
 * The bell's "Waiting for You" inventory tasks (after the 2026-11-18 migration),
 * read by staffn_tasks() (controllers/staff_notifications.php). Expiry and low
 * stock are Notification Center events now.
 *   trf  transfers waiting for this user: owner → to approve; admin → to send / to receive
 *   pr   purchase requests waiting for the owner's approval (owner only)
 *   po   purchase orders whose delivery is due (owner: all; admin: own branch)
 * Empty before the migration, so pages never query tables that don't exist yet.
 */
function admin_navbar_extra_specs(): array
{
    require_once __DIR__ . '/inventory_v2.php';
    if (!inv_v2_ready()) return [];
    $role  = $_SESSION['role'] ?? '';
    $owner = $role === 'owner';
    $b     = $owner ? 0 : scopedBranch(0);
    $bf    = $owner ? '' : '&branch_id=eq.' . $b;
    $today = date('Y-m-d');
    $out = [];
    if ($owner) {
        $out['trf'] = admin_q('stock_transfers', 'select=transfer_id,transfer_no,status,from_branch_id,to_branch_id&status=eq.pending&order=requested_at.asc&limit=50');
        $out['pr']  = admin_q('purchase_requests', 'select=pr_id,pr_no,branch_id&status=eq.pending&order=requested_at.asc&limit=50');
    } elseif ($role === 'admin') {
        $out['trf'] = admin_q('stock_transfers', 'select=transfer_id,transfer_no,status,from_branch_id,to_branch_id'
            . '&or=(and(status.eq.approved,from_branch_id.eq.' . $b . '),and(status.eq.in_transit,to_branch_id.eq.' . $b . '))&order=requested_at.asc&limit=50');
    }
    if ($role !== 'dentist') {
        $out['po'] = admin_q('purchase_orders', 'select=po_id,po_no,branch_id,expected_date,suppliers(supplier_name)&status=in.(ordered,partially_received)'
            . '&expected_date=lte.' . $today . $bf . '&order=expected_date.asc&limit=50');
    }
    return array_map('admin_scache_mark', $out);
}

/**
 * Marks a spec as SHARED-CACHEABLE (controllers/shared_cache.php): the same
 * read for every staff user, rarely changing, invalidated by any write.
 * Only the navbar bell and the branch list are marked.
 */
function admin_scache_mark(array $spec): array
{
    $GLOBALS['__admin_scache'][admin_memo_key($spec)] = true;
    return $spec;
}

function admin_scache_is(array $spec): bool
{
    return isset($GLOBALS['__admin_scache'][admin_memo_key($spec)]);
}

/** A response worth caching: 2xx, no error, rows. */
function admin_scache_ok(array $r): bool
{
    return empty($r['error']) && ($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300 && is_array($r['data'] ?? null);
}

/** Memo key: the exact request (table + query + extra headers). */
function admin_memo_key(array $spec): string
{
    return $spec['table'] . '?' . ($spec['query'] ?? '') . '|' . implode("\n", $spec['headers'] ?? []);
}

/**
 * Runs the given specs plus the shared ones in ONE parallel wave and
 * remembers every result for this request, so the code that normally runs
 * the same query (through admin_get()) gets it without another round trip.
 *   $specs   page-specific queries, key => admin_q(...)
 *   $common  which shared queries to include: 'navbar' (every page with the
 *            navbar), 'branches' (pages that call getAllBranches())
 * Returns the results of $specs.
 *
 * Only prefetch what the page reads BEFORE any write it makes in the same
 * request (a memoized read would not see that write).
 */
function admin_prefetch(array $specs = [], array $common = ['navbar', 'branches']): array
{
    $all = [];
    // 'navbar': the bell now loads in the browser (api/staff_notifications.php),
    // so pages no longer prefetch its inventory queries. The key is still accepted.
    if (in_array('branches', $common, true)) $all['__branches'] = admin_branches_spec();
    foreach ($specs as $k => $s) $all[$k] = $s;

    // Shared-cache hits skip the round trip (stamp read BEFORE fetching the misses)
    $gen = ''; $hits = [];
    foreach ($all as $k => $s) {
        if (!admin_scache_is($s)) continue;
        if ($gen === '') $gen = es_scache_gen();
        $hit = es_scache_get(admin_memo_key($s), $gen);
        if (is_array($hit)) $hits[$k] = $hit;
    }
    $res = $hits + admin_sb_many(array_diff_key($all, $hits));
    foreach ($res as $k => $r) {
        $GLOBALS['__admin_memo'][admin_memo_key($all[$k])] = $r;
        if (!isset($hits[$k]) && admin_scache_is($all[$k]) && admin_scache_ok($r)) es_scache_set(admin_memo_key($all[$k]), $gen, $r);
    }

    return array_intersect_key($res, $specs);
}

/**
 * GET through the prefetch memo: returns the prefetched result for this exact
 * query (once), otherwise runs it with supabase_request() as before.
 */
function admin_get(string $table, string $query = '', array $headers = []): array
{
    $key = admin_memo_key(['table' => $table, 'query' => $query, 'headers' => $headers]);
    if (isset($GLOBALS['__admin_memo'][$key])) {
        $r = $GLOBALS['__admin_memo'][$key];
        unset($GLOBALS['__admin_memo'][$key]);
        return $r;
    }
    if (isset($GLOBALS['__admin_scache'][$key])) {          // shared, write-invalidated cache
        $gen = es_scache_gen();
        $hit = es_scache_get($key, $gen);
        if (is_array($hit)) return $hit;
        $r = supabase_request($table, 'GET', [], $query, $headers);
        if (admin_scache_ok($r)) es_scache_set($key, $gen, $r);
        return $r;
    }
    return supabase_request($table, 'GET', [], $query, $headers);
}

// ─────────────────────────────────────────────────────────────
//  SESSION LOCK
// ─────────────────────────────────────────────────────────────

/**
 * PHP keeps the session file locked until the script ends, so while a slow
 * page renders, every other request of the same user (the next page they
 * clicked in the sidebar, AJAX calls) waits. Staff pages only READ the
 * session after the auth checks, so they can release the lock right away.
 * $_SESSION stays readable; bootSession() won't reopen it.
 */
function admin_release_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $GLOBALS['__admin_session_released'] = true;
}

// ─────────────────────────────────────────────────────────────
//  SHORT-LIVED PER-USER CACHE (stored in the user's own session)
//
//  Living in the session makes it private to the logged-in account by
//  construction; the key also carries role + branch so a role/branch change
//  in the same session never reuses another scope's data.
// ─────────────────────────────────────────────────────────────

function admin_cache_key(string $name): string
{
    return 'admin_cache_' . $name . '_' . ($_SESSION['user_id'] ?? 0) . '_'
        . ($_SESSION['role'] ?? '') . '_' . (int)($_SESSION['branch_id'] ?? 0);
}

/** Cached value if younger than $ttl seconds, else null. */
function admin_cache_get(string $name, int $ttl)
{
    $c = $_SESSION[admin_cache_key($name)] ?? null;
    if (!is_array($c) || !isset($c['ts']) || (time() - $c['ts']) >= $ttl) return null;
    return $c['v'];
}

/** Stores a value; works whether or not the session lock was released. */
function admin_cache_set(string $name, $value): void
{
    $key = admin_cache_key($name);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[$key] = ['ts' => time(), 'v' => $value];
        return;
    }
    if (headers_sent()) return;            // can't reopen; just skip caching
    $snapshot = $_SESSION;
    @session_start();
    if (session_status() !== PHP_SESSION_ACTIVE) { $_SESSION = $snapshot; return; }
    $_SESSION[$key] = ['ts' => time(), 'v' => $value];
    session_write_close();
}
