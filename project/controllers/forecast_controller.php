<?php
// =============================================================
//  controllers/forecast_controller.php
//  Predictive Analytics — read-only access to the results the
//  Python job (project/analytics/run_forecast.py) writes into
//  forecast_runs / forecast_values / forecast_metrics / forecast_inventory.
//  SQL: database/proposed/2026-11-12_forecast_tables.sql
//
//  Branch scope is enforced HERE (never in JS): owners see every
//  branch, admins / dentists only their own.
//  Zero HTML. Zero $_GET reading.
//
//  Speed (each Supabase round trip from the server is ~0.5–1 s):
//    • wave 1 — branches + newest successful run + newest run, in parallel
//    • a run's results never change once it has succeeded, so the bundle
//      (values / metrics / inventory) is cached in a temp file per
//      run + branch scope; only a brand-new run pays waves 2–3
//    • wave 2 — first page of the three result tables, in parallel
//    • wave 3 — any further pages (PostgREST returns ≤ 1000 rows), in parallel
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/auth_controller.php';     // scopedBranch()
require_once __DIR__ . '/admin_batch.php';         // admin_q(), admin_sb_many()

const FORECAST_SOURCES     = ['live', 'simulated'];
const FORECAST_MIN_OBS     = 56;   // statistical models (plan D7)
// Per-model minimum observed days — mirrors MIN_OBS in analytics/es_forecast/config.py.
// Runs made after 2026-11-12 also store it in forecast_runs.summary.min_obs, which wins.
const FORECAST_MODEL_MIN_OBS = ['seasonal_naive' => 14, 'mlr' => 56, 'arima' => 56, 'sarima' => 56];
const FORECAST_EVAL_FOLDS    = 4;  // rolling-origin folds (config.py FOLDS)
const FORECAST_FOLD_DAYS     = 7;  // days per fold (config.py FOLD_HORIZON)
const FORECAST_STALE_MIN   = 30;   // a 'running' run older than this is treated as failed
const FORECAST_PAGE        = 1000; // PostgREST max rows per request
const FORECAST_CACHE_DAYS  = 7;    // cached bundles of old runs are deleted after this

const FORECAST_RUN_COLS = 'run_id,data_source,status,started_at,finished_at,last_observed_date,horizon_days,triggered_by,summary,error';
const FORECAST_TABLES = [
    'values'    => ['forecast_values',
        'select=branch_id,metric,item_id,kind,model,value_date,is_open,yhat,yhat_lower,yhat_upper&or=(kind.eq.actual,is_selected.eq.true)&order=branch_id,metric,item_id,value_date'],
    'metrics'   => ['forecast_metrics',
        'select=branch_id,metric,item_id,model,status,n_obs,n_folds,mae,rmse,mase,mape,is_selected,model_order,message&order=branch_id,metric,item_id,model'],
    'inventory' => ['forecast_inventory',
        'select=branch_id,item_id,item_name,stock_on_hand,min_stock,forecast_use,days_of_stock,needs_reorder,basis&order=branch_id,needs_reorder.desc,days_of_stock.asc.nullslast'],
];

/** Total row count from a "Prefer: count=exact" response (Content-Range: 0-999/2184). */
function forecastTotal(array $res): int
{
    $cr = $res['headers']['content-range'] ?? '';
    return ($cr && str_contains($cr, '/')) ? (int)explode('/', $cr)[1] : (is_array($res['data']) ? count($res['data']) : 0);
}

function forecastCacheFile(string $key): string
{
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'es_fc_' . substr(md5(__DIR__), 0, 8) . '_' . $key . '.json';
}

/**
 * values / metrics / inventory of one run, limited to $branchIds.
 * Served from the temp-file cache when this run + scope was read before.
 */
function getForecastBundle(int $runId, array $branchIds): array
{
    $empty = ['values' => [], 'metrics' => [], 'inventory' => []];
    $branchIds = array_values(array_filter(array_map('intval', $branchIds), fn($b) => $b > 0));
    sort($branchIds);
    if ($runId <= 0 || !$branchIds) return $empty;

    $file = forecastCacheFile('run' . $runId . '_b' . implode('-', $branchIds));
    if (is_file($file)) {
        $hit = json_decode((string)@file_get_contents($file), true);
        if (is_array($hit) && isset($hit['values'], $hit['metrics'], $hit['inventory'])) return $hit;
    }

    $in = '&run_id=eq.' . $runId . '&branch_id=in.(' . implode(',', $branchIds) . ')';
    $count = ['Prefer: count=exact'];

    // Wave 2: first page of every table (with total counts)
    $specs = [];
    foreach (FORECAST_TABLES as $k => [$table, $q]) {
        $specs[$k] = admin_q($table, $q . $in . '&limit=' . FORECAST_PAGE . '&offset=0', $count);
    }
    $first = admin_sb_many($specs);
    $out = $empty;
    $more = [];
    foreach (FORECAST_TABLES as $k => [$table, $q]) {
        $r = $first[$k] ?? null;
        if (!$r || $r['error'] || !is_array($r['data'])) return $empty;   // don't cache a partial read
        $out[$k] = $r['data'];
        for ($off = FORECAST_PAGE, $total = forecastTotal($r); $off < $total; $off += FORECAST_PAGE) {
            $more[$k . '|' . $off] = admin_q($table, $q . $in . '&limit=' . FORECAST_PAGE . '&offset=' . $off);
        }
    }

    // Wave 3: remaining pages, all at once
    if ($more) {
        $pages = admin_sb_many($more);
        ksort($pages, SORT_NATURAL);
        foreach ($pages as $key => $r) {
            if ($r['error'] || !is_array($r['data'])) return $empty;
            $k = explode('|', $key)[0];
            $out[$k] = array_merge($out[$k], $r['data']);
        }
    }

    // Results of a successful run are immutable → safe to keep. Write atomically.
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($out)) !== false) @rename($tmp, $file);
    if (mt_rand(1, 20) === 1) {             // occasionally drop bundles of old runs
        foreach (glob(forecastCacheFile('*')) ?: [] as $f) {
            if (@filemtime($f) < time() - FORECAST_CACHE_DAYS * 86400) @unlink($f);
        }
    }
    return $out;
}

/** Everything the Forecast tab needs (api/forecast_data.php) for one data source. */
function getForecastPageData(string $source): array
{
    if (!in_array($source, FORECAST_SOURCES, true)) $source = 'live';

    // Wave 1: branches + newest successful run + newest run of any status
    $w = admin_sb_many([
        'branches' => admin_q('branches', 'select=branch_id,branch_name&order=branch_name.asc'),
        'ok'       => admin_q('forecast_runs', 'select=' . FORECAST_RUN_COLS . '&data_source=eq.' . $source . '&status=eq.success&order=finished_at.desc&limit=1'),
        'latest'   => admin_q('forecast_runs', 'select=' . FORECAST_RUN_COLS . '&data_source=eq.' . $source . '&order=started_at.desc&limit=1'),
    ]);

    $all   = is_array($w['branches']['data'] ?? null) ? $w['branches']['data'] : [];
    $own   = scopedBranch(0);               // 0 = owner (every branch), > 0 = own branch, -1 = none
    $names = [];
    foreach ($all as $b) {
        $id = (int)$b['branch_id'];
        if ($own === 0 || $id === $own) $names[$id] = $b['branch_name'];
    }
    $scope = array_keys($names);

    $setup = !empty($w['ok']['error']);     // forecast tables not created yet
    $run   = $setup ? null : ($w['ok']['data'][0] ?? null);
    $last  = $setup ? null : ($w['latest']['data'][0] ?? null);
    $lastFailed = $running = null;
    if ($last && $last['status'] !== 'success') {
        $stale = $last['status'] === 'running' && strtotime($last['started_at']) < time() - FORECAST_STALE_MIN * 60;
        if ($last['status'] === 'failed' || $stale) $lastFailed = $last;
        elseif ($last['status'] === 'running') $running = $last;
    }

    $bundle = $run ? getForecastBundle((int)$run['run_id'], $scope) : ['values' => [], 'metrics' => [], 'inventory' => []];

    // Only the scoped branches' parts of the run summary reach the browser
    $sum  = is_array($run['summary'] ?? null) ? $run['summary'] : [];
    $pick = function ($map) use ($scope) {
        $out = [];
        foreach ((array)$map as $bid => $v) if (in_array((int)$bid, $scope, true)) $out[(int)$bid] = (int)$v;
        return (object)$out;
    };
    $minObs = FORECAST_MODEL_MIN_OBS;
    if (is_array($sum['min_obs'] ?? null)) {
        foreach ($sum['min_obs'] as $m => $n) if (isset($minObs[$m])) $minObs[$m] = (int)$n;
    }

    return [
        'source'     => $source,
        'setup'      => $setup,
        'run'        => $run ? [
            'run_id'             => (int)$run['run_id'],
            'started_at'         => $run['started_at'],
            'finished_at'        => $run['finished_at'],
            'last_observed_date' => $run['last_observed_date'],
            'horizon_days'       => (int)$run['horizon_days'],
            'history_start'      => $sum['start'] ?? null,
            'open_days'          => $pick($sum['open_days'] ?? []),
            'suspected_missing'  => $pick($sum['suspected_missing'] ?? []),
        ] : null,
        'lastFailed' => $lastFailed ? [
            'started_at' => $lastFailed['started_at'],
            'error'      => $lastFailed['status'] === 'running'
                ? 'The run did not finish within ' . FORECAST_STALE_MIN . ' minutes.'
                : (string)($lastFailed['error'] ?? 'Unknown error'),
        ] : null,
        'running'    => $running ? ['started_at' => $running['started_at']] : null,
        'branches'   => (object)$names,
        'minObs'     => FORECAST_MIN_OBS,
        'modelMinObs'=> $minObs,
        'evalFolds'  => (int)($sum['folds'] ?? FORECAST_EVAL_FOLDS),
        'foldDays'   => (int)($sum['fold_horizon'] ?? FORECAST_FOLD_DAYS),
        'isOwner'    => ($_SESSION['role'] ?? '') === 'owner',
        'serverTime' => gmdate('c'),
    ] + $bundle;
}
