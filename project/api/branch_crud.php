<?php
// =============================================================
//  api/branch_crud.php  —  add, edit, delete branches
//  Owner only.
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/branch_controller.php';

header('Content-Type: application/json');
requireLogin();

if (!isOwner()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'add':    echo json_encode(addBranch($input));    break;
    case 'edit':   echo json_encode(editBranch($input));   break;
    case 'delete': echo json_encode(deleteBranch($input)); break;
    default:       echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

/** Next code in the existing format: BR-00001, BR-00002, … */
function generateBranchCode(): string {
    $r    = supabase_request('branches', 'GET', [], 'select=branch_code&order=branch_id.desc&limit=1');
    $last = $r['data'][0]['branch_code'] ?? 'BR-00000';
    $num  = (int)preg_replace('/\D/', '', $last) + 1;
    return 'BR-' . str_pad($num, 5, '0', STR_PAD_LEFT);
}

/**
 * Validate the form fields shared by add + edit.
 * Returns [payload, error]; error is null when valid.
 */
function branchPayload(array $d, bool $isAdd): array {
    $name    = trim($d['branch_name']    ?? '');
    $address = trim($d['address']        ?? '');
    $contact = trim($d['contact_number'] ?? '');
    $open    = trim($d['open_time']      ?? '');
    $close   = trim($d['close_time']     ?? '');
    $status  = $d['status'] ?? 'active';

    if ($name === '')                 return [null, 'Branch name is required.'];
    if (mb_strlen($name) > 100)       return [null, 'Branch name is too long (100 characters max).'];
    if ($address === '')              return [null, 'Address is required.'];
    if ($contact === '')              return [null, 'Contact number is required.'];
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $open) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $close)) {
        return [null, 'Please enter both an open and a close time.'];
    }
    if (substr($close, 0, 5) <= substr($open, 0, 5)) {
        return [null, 'Close time must be later than open time.'];
    }
    if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

    $payload = [
        'branch_name'    => $name,
        'address'        => $address,
        'contact_number' => $contact,
        'open_time'      => $open,
        'close_time'     => $close,
        'status'         => $status,
        'updated_at'     => date('c'),
    ];

    // Operating days: required when sent; an add without them gets Mon–Sat.
    if (array_key_exists('operating_days', $d)) {
        $days = normalizeOperatingDays($d['operating_days']);
        if (!$days) return [null, 'Please select at least one operating day.'];
        $payload['operating_days'] = $days;
    } elseif ($isAdd) {
        $payload['operating_days'] = BR_DEFAULT_OPERATING_DAYS;
    }

    return [$payload, null];
}

function addBranch(array $d): array {
    [$payload, $err] = branchPayload($d, true);
    if ($err) return ['success' => false, 'message' => $err];

    $payload['branch_code'] = generateBranchCode();
    $payload['created_at']  = date('c');

    $r = supabase_request('branches', 'POST', $payload);
    if ($r['error']) return ['success' => false, 'message' => branchDbError($r['error'])];
    return ['success' => true, 'message' => 'Branch added successfully.'];
}

function editBranch(array $d): array {
    if (empty($d['branch_id'])) return ['success' => false, 'message' => 'Missing branch ID.'];

    [$payload, $err] = branchPayload($d, false);
    if ($err) return ['success' => false, 'message' => $err];

    $r = supabase_request('branches', 'PATCH', $payload, 'branch_id=eq.' . (int)$d['branch_id']);
    if ($r['error']) return ['success' => false, 'message' => branchDbError($r['error'])];
    return ['success' => true, 'message' => 'Branch updated successfully.'];
}

function deleteBranch(array $d): array {
    if (empty($d['branch_id'])) return ['success' => false, 'message' => 'Missing branch ID.'];

    $r = supabase_request('branches', 'DELETE', [], 'branch_id=eq.' . (int)$d['branch_id']);
    if ($r['error']) {
        $msg = is_string($r['error']) ? $r['error'] : json_encode($r['error']);
        if (stripos($msg, 'foreign key') !== false || stripos($msg, 'violates') !== false) {
            return ['success' => false, 'message' => 'This branch still has patients, appointments or staff linked to it. Set it to Inactive instead.'];
        }
        return ['success' => false, 'message' => $msg];
    }
    return ['success' => true, 'message' => 'Branch deleted successfully.'];
}

/** Friendlier text for a database error on add/edit. */
function branchDbError(mixed $error): string {
    $msg = is_string($error) ? $error : json_encode($error);
    if (stripos($msg, 'operating_days') !== false) {
        return 'Operating days aren\'t set up yet. Please run the service-durations / operating-days SQL file in Supabase first.';
    }
    return $msg;
}