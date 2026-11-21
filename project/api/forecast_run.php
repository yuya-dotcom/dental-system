<?php
// =============================================================
//  api/forecast_run.php  —  "Run forecast now" (Predictive Analytics)
//  Owner only. POST JSON:
//    {action: 'start', source: 'live'|'simulated'}  start analytics/run_forecast.py in the background
//    {action: 'status', source}                     newest run of that source (status, times, error)
//  The Python job writes forecast_runs itself; this file only starts it
//  and reads its status. Nothing here touches clinic records.
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/forecast_controller.php';

header('Content-Type: application/json');
requireLogin();
if (!isOwner() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$source = in_array($input['source'] ?? '', FORECAST_SOURCES, true) ? $input['source'] : 'live';
$user   = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'owner';
session_write_close();   // the run takes minutes; don't hold the session lock

switch ($input['action'] ?? '') {
    case 'start':  echo json_encode(forecastStart($source, (string)$user)); break;
    case 'status': echo json_encode(['success' => true] + forecastStatus($source)); break;
    default:       echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

/** Newest run of a source; a 'running' row older than FORECAST_STALE_MIN counts as failed. */
function forecastStatus(string $source): array
{
    $r = supabase_request('forecast_runs', 'GET', [],
        'select=run_id,status,started_at,finished_at,error&data_source=eq.' . $source . '&order=started_at.desc&limit=1');
    if ($r['error']) return ['run' => null, 'setup' => true];
    $run = $r['data'][0] ?? null;
    if ($run && $run['status'] === 'running' && strtotime($run['started_at']) < time() - FORECAST_STALE_MIN * 60) {
        $run['status'] = 'failed';
        $run['error']  = 'The run did not finish within ' . FORECAST_STALE_MIN . ' minutes.';
    }
    return ['run' => $run, 'server_time' => gmdate('c')];
}

/** python.exe to use: ES_PYTHON env var, else the first `python` on PATH. */
function forecastPython(): ?string
{
    $env = getenv('ES_PYTHON');
    if ($env && is_file($env)) return $env;
    $found = trim((string)@shell_exec('where python 2>NUL'));
    foreach (preg_split('/\R/', $found) as $p) {
        $p = trim($p);
        // skip the Microsoft Store stub (WindowsApps), which only opens the Store
        if ($p !== '' && is_file($p) && stripos($p, 'WindowsApps') === false) return $p;
    }
    return null;
}

function forecastStart(string $source, string $user): array
{
    $st = forecastStatus($source);
    if (!empty($st['setup'])) return ['success' => false, 'message' => 'Run the forecast tables SQL file in Supabase first.'];
    if (($st['run']['status'] ?? '') === 'running') {
        return ['success' => false, 'message' => 'A forecast is already running (started ' . date('g:i A', strtotime($st['run']['started_at'])) . '). Please wait for it to finish.'];
    }

    $python = forecastPython();
    if (!$python) return ['success' => false, 'message' => 'Python was not found on the server. Set the ES_PYTHON environment variable to python.exe.'];

    $bat = realpath(__DIR__ . '/../analytics/run_forecast.bat');
    if (!$bat) return ['success' => false, 'message' => 'analytics/run_forecast.bat is missing.'];
    $who = 'dashboard-' . (substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $user), 0, 60) ?: 'owner');

    // Detached: `start "" /B` returns immediately. The .bat logs to cron/logs/forecast.log.
    // The child inherits ES_PYTHON, so it works even when Apache's PATH has no python.
    putenv('ES_PYTHON=' . $python);
    $cmd = 'start "" /B "' . $bat . '" ' . $source . ' ' . $who;
    $h = @popen($cmd, 'r');
    if ($h === false) return ['success' => false, 'message' => 'Could not start the forecast job.'];
    pclose($h);

    return ['success' => true, 'message' => 'Forecast started. This usually takes 1–3 minutes.', 'requested_at' => gmdate('c')];
}
