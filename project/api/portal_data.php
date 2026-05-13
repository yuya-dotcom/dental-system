<?php
// =============================================================
//  api/portal_data.php  — public-safe data endpoints
//  Called by the patient portal booking page.
//
//  Actions (no auth required — not sensitive):
//    get_branches  — active branches with open/close times
//    get_slots     — booked time slots for a branch + date
//    get_services  — active services for booking dropdown
// =============================================================

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../dbconfig.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── GET BRANCHES ──────────────────────────────────────────────
if ($action === 'get_branches') {
    $res = supabase_request(
        'branches',
        'GET',
        [],
        'status=eq.active&select=branch_id,branch_name,open_time,close_time&order=branch_name.asc'
    );
    echo json_encode([
        'success'  => true,
        'branches' => $res['data'] ?? [],
    ]);
    exit;
}

// ── GET SLOTS ─────────────────────────────────────────────────
if ($action === 'get_slots') {
    $branchId = (int)($_GET['branch_id'] ?? 0);
    $date     = trim($_GET['date'] ?? '');

    if (!$branchId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success' => false, 'booked' => []]);
        exit;
    }

    $res = supabase_request(
        'appointments',
        'GET',
        [],
        'branch_id=eq.'          . $branchId .
        '&appointment_date=eq.'  . urlencode($date) .
        '&status=in.(pending,confirmed,checked_in)' .
        '&select=appointment_time'
    );

    $booked = [];
    foreach (($res['data'] ?? []) as $row) {
        $booked[] = substr($row['appointment_time'], 0, 5);
    }

    echo json_encode(['success' => true, 'booked' => $booked]);
    exit;
}

// ── GET SERVICES ──────────────────────────────────────────────
if ($action === 'get_services') {
    $res = supabase_request(
        'services',
        'GET',
        [],
        'status=eq.active&select=service_id,service_name&order=service_name.asc&limit=200'
    );
    echo json_encode([
        'success'  => true,
        'services' => $res['data'] ?? [],
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);