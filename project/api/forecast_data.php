<?php
// =============================================================
//  api/forecast_data.php  —  Predictive Analytics data (read only)
//  GET ?source=live|simulated → the same payload analytics.php embeds as
//  window.ES_FORECAST, so the page can switch data source and refresh after
//  "Run forecast" without a full reload.
//  Branch scope is enforced in controllers/forecast_controller.php
//  (owner: every branch, admin / dentist: own branch only).
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/forecast_controller.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
requireLogin();
if (!in_array($_SESSION['role'] ?? '', ['owner', 'admin', 'dentist'], true)) {
    apiDeny(403, 'Unauthorized.');
}

$source = ($_GET['source'] ?? '') === 'simulated' ? 'simulated' : 'live';
$data   = getForecastPageData($source);   // reads the session for scope + role
session_write_close();

echo json_encode(['success' => true, 'data' => $data]);
