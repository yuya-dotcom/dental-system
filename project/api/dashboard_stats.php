<?php
// =============================================================
//  api/dashboard_stats.php
//  GET [?branch=N] — live figures for the Dashboard (read-only JSON).
//  Owner: all branches, or one branch with ?branch=N.
//  Admin / dentist: always their own branch (?branch is ignored).
//  Business logic: controllers/dashboard_overview.php
// =============================================================
session_start();
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/admin_batch.php';
require_once __DIR__ . '/../controllers/dashboard_overview.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
requireLogin();
requireStaffApi('owner', 'admin', 'dentist');
admin_release_session();   // read-only: don't block the user's other requests
require_once __DIR__ . '/../controllers/appointment_lifecycle.php';
aptSweepNoShows();          // ended, never-checked-in appointments → no_show before counting (throttled)

if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiDeny(405, 'Method not allowed.');

$branch = scopedBranch(max(0, (int)($_GET['branch'] ?? 0)));   // owner: as asked; others: own branch

// ?split=1 (owner): all branches AND every branch in ONE response, from one set of
// queries (Analytics' clinic comparison) — same figures as one call per branch.
if (isset($_GET['split']) && isOwner()) {
    try {
        $scopes = dashboardOverviewSplit();
    } catch (Throwable $e) {
        error_log('[dashboard_stats split] ' . $e->getMessage());
        apiDeny(500, 'Could not load the dashboard figures.');
    }
    foreach ($scopes as $id => &$s) {
        $s['meta']['branch_id']   = $id;
        $s['meta']['branch_name'] = $id === 0 ? 'All Branches' : $s['meta']['branch_name'];
        $s['meta']['can_switch']  = true;
    }
    unset($s);
    echo json_encode(['success' => true, 'data' => ['scopes' => $scopes]]);
    exit;
}

$branchName = 'All Branches';
if ($branch > 0) {
    $b = supabase_request('branches', 'GET', [], 'branch_id=eq.' . $branch . '&select=branch_name&limit=1');
    $branchName = $b['data'][0]['branch_name'] ?? ('Branch #' . $branch);
}

try {
    $data = dashboardOverview($branch);
} catch (Throwable $e) {
    error_log('[dashboard_stats] ' . $e->getMessage());
    apiDeny(500, 'Could not load the dashboard figures.');
}

$data['meta']['branch_id']   = $branch;
$data['meta']['branch_name'] = $branchName;
$data['meta']['can_switch']  = isOwner();

echo json_encode(['success' => true, 'data' => $data]);
