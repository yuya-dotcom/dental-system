<?php
// =============================================================
//  controllers/shared_cache.php
//  Small file cache SHARED by every staff user, for read-only lists that
//  every staff page asks for but that rarely change:
//    • the bell's "Waiting for You" inventory tasks (transfers, purchase
//      requests / orders)                    → admin_navbar_extra_specs()
//    • the branch list                       → admin_branches_spec()
//  (controllers/admin_batch.php decides what is cacheable.)
//
//  Accuracy rules — the cache can never hide a change made through the app:
//    • A "generation" stamp is bumped after EVERY write that goes through
//      supabase_request(), admin_sb_exec() or portal_sb_multi() (any table,
//      any RPC — deliberately broader than needed, so triggers and RPC side
//      effects are covered too). An entry is used only while the stamp it was
//      stored with is still current.
//    • The stamp is read BEFORE the data is fetched and bumped AFTER a write
//      finishes, so a read racing a write is always stored as already stale.
//    • ES_SCACHE_TTL caps the age for changes made outside the app (e.g. the
//      Supabase dashboard). Queries carry today's date where it matters
//      (expiry / due dates), so a new day is a new entry.
//    • Only successful responses are stored. Any file problem = cache miss.
// =============================================================

const ES_SCACHE_TTL = 120;   // seconds

function es_scache_dir(): ?string
{
    static $dir = false;
    if ($dir === false) {
        $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'es_scache_' . substr(md5(SUPABASE_URL), 0, 12);
        $dir = (is_dir($d) || @mkdir($d, 0700, true)) && is_writable($d) ? $d : null;
    }
    return $dir;
}

/** Current generation stamp ('' when the cache is unavailable). */
function es_scache_gen(): string
{
    $d = es_scache_dir();
    if (!$d) return '';
    $g = @file_get_contents($d . DIRECTORY_SEPARATOR . 'gen');
    if ($g === false || $g === '') { es_scache_bump(); $g = @file_get_contents($d . DIRECTORY_SEPARATOR . 'gen'); }
    return (string)$g;
}

/** Invalidates every entry. Called after any write. */
function es_scache_bump(): void
{
    $d = es_scache_dir();
    if (!$d) return;
    @file_put_contents($d . DIRECTORY_SEPARATOR . 'gen', bin2hex(random_bytes(8)) . '.' . microtime(true), LOCK_EX);
}

/** Cached value for $key stored under generation $gen and younger than the TTL, else null. */
function es_scache_get(string $key, string $gen)
{
    $d = es_scache_dir();
    if (!$d || $gen === '') return null;
    $raw = @file_get_contents($d . DIRECTORY_SEPARATOR . 'q_' . md5($key) . '.json');
    if ($raw === false) return null;
    $e = json_decode($raw, true);
    if (!is_array($e) || ($e['gen'] ?? null) !== $gen || ($e['key'] ?? null) !== $key) return null;
    if ((time() - (int)($e['at'] ?? 0)) >= ES_SCACHE_TTL) return null;
    return $e['val'] ?? null;
}

/** Stores $value for $key under generation $gen (read before the data was fetched). */
function es_scache_set(string $key, string $gen, $value): void
{
    $d = es_scache_dir();
    if (!$d || $gen === '') return;
    $file = $d . DIRECTORY_SEPARATOR . 'q_' . md5($key) . '.json';
    $tmp  = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode(['key' => $key, 'gen' => $gen, 'at' => time(), 'val' => $value]);
    if ($json === false || @file_put_contents($tmp, $json) === false) return;
    if (!@rename($tmp, $file)) @unlink($tmp);
}

/** Bumps the generation when $method is a write (called after the request finished). */
function es_scache_note(string $method): void
{
    $m = strtoupper($method);
    if ($m !== 'GET' && $m !== 'HEAD') es_scache_bump();
}
