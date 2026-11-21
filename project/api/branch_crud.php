<?php
// =============================================================
//  api/branch_crud.php  —  add, edit, delete branches
//  Owner only.
//
//  The address is entered in parts (street, province, city / municipality,
//  barangay, ZIP — the Patient Portal's dropdown data) and also saved
//  joined in `address`, which every other page reads. The parts need
//  database/proposed/2026-11-16_owner_records_eval2.sql; until it runs only
//  the joined address is saved. Contact: +63 mobile, "+639XXXXXXXXX".
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/branch_controller.php';
require_once __DIR__ . '/../controllers/audit_controller.php';
require_once __DIR__ . '/../controllers/portal_validation.php';   // pv_mobile_error + name_helper (normalize_address_text)
require_once __DIR__ . '/../controllers/staff_names.php';         // staffMissingColumn
require_once __DIR__ . '/../controllers/staff_notifications.php'; // staff bell: hours / closures

header('Content-Type: application/json');
requireLogin();

if (!isOwner()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// The action dispatch is at the END of this file: top-level `const`
// declarations (BR_EXCEPTIONS_SQL_MSG, BR_AUDIT_FIELDS) only exist once
// execution has reached them, so dispatching up here crashed with
// "Undefined constant" on edit / delete / exceptions.

// ─────────────────────────────────────────────────────────────
//  SCHEDULE EXCEPTIONS
// ─────────────────────────────────────────────────────────────

const BR_EXCEPTIONS_SQL_MSG = 'Schedule exceptions aren\'t set up yet. Please run database/proposed/2026-11-12_branch_schedule_exceptions.sql in Supabase first.';

/** The branch row (id, name, operating days) or null. */
function exceptionBranch(int $branchId): ?array {
    if ($branchId <= 0) return null;
    $r = supabase_request('branches', 'GET', [], 'select=branch_id,branch_name,operating_days&branch_id=eq.' . $branchId . '&limit=1');
    return (!$r['error'] && !empty($r['data'][0])) ? $r['data'][0] : null;
}

function exceptionDateValid(string $date): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** Today in Manila (the clinic's timezone), YYYY-MM-DD. */
function exceptionToday(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
}

/** Long label for audit text and messages: "Mon, Nov 17, 2026". */
function exceptionDateLabel(string $date): string {
    return date('D, M j, Y', strtotime($date));
}

/** Rows for one branch: upcoming first-class, plus the last 90 days for reference. */
function listBranchExceptions(array $d): array {
    $branch = exceptionBranch((int)($d['branch_id'] ?? 0));
    if (!$branch) return ['success' => false, 'message' => 'Branch not found.'];

    $from = date('Y-m-d', strtotime(exceptionToday() . ' -90 days'));
    $rows = getBranchExceptions((int)$branch['branch_id'], $from);
    if ($rows === null) return ['success' => false, 'setup' => true, 'message' => BR_EXCEPTIONS_SQL_MSG];

    return [
        'success'        => true,
        'today'          => exceptionToday(),
        'operating_days' => branchOperatingDays($branch),
        'exceptions'     => array_map(fn($r) => [
            'exception_id'   => (int)$r['exception_id'],
            'exception_date' => $r['exception_date'],
            'is_open'        => (bool)$r['is_open'],
            'reason'         => (string)($r['reason'] ?? ''),
        ], $rows),
    ];
}

/**
 * Add or change the override for one date. Closing a day that still has
 * active appointments needs confirm = true (they are NOT cancelled
 * automatically — staff contact the patients and reschedule).
 */
function saveBranchException(array $d): array {
    $branch = exceptionBranch((int)($d['branch_id'] ?? 0));
    if (!$branch) return ['success' => false, 'message' => 'Branch not found.'];

    $date   = trim((string)($d['exception_date'] ?? ''));
    $isOpen = filter_var($d['is_open'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $reason = trim(preg_replace('/\s+/', ' ', (string)($d['reason'] ?? '')));

    if (!exceptionDateValid($date))                       return ['success' => false, 'message' => 'Please choose a valid date.'];
    if ($isOpen === null)                                 return ['success' => false, 'message' => 'Please choose Open or Closed.'];
    if ($reason === '')                                   return ['success' => false, 'message' => 'Please enter the reason for this exception.'];
    if (mb_strlen($reason) > BR_EXCEPTION_REASON_MAX)     return ['success' => false, 'message' => 'Reason is too long (' . BR_EXCEPTION_REASON_MAX . ' characters max).'];
    if ($date > date('Y-m-d', strtotime(exceptionToday() . ' +1 year'))) {
        return ['success' => false, 'message' => 'Please choose a date within the next year.'];
    }

    // An override that matches the usual schedule changes nothing — say so
    $usualOpen = in_array((int)date('w', strtotime($date)), branchOperatingDays($branch), true);
    $dayName   = date('l', strtotime($date));
    if ($usualOpen === $isOpen) {
        return ['success' => false, 'message' => $branch['branch_name'] . ' is already ' . ($isOpen ? 'open' : 'closed')
            . ' on ' . $dayName . 's by its usual schedule. Pick the other option, or a different date.'];
    }

    // Closing a day: warn about appointments that are still on it
    if (!$isOpen && empty($d['confirm']) && $date >= exceptionToday()) {
        $a = supabase_request('appointments', 'GET', [],
            'select=appointment_id&branch_id=eq.' . (int)$branch['branch_id'] . '&appointment_date=eq.' . $date
            . '&status=not.in.(cancelled,completed,no_show)&limit=0', ['Prefer: count=exact']);
        $count = 0;
        if (!$a['error'] && preg_match('#/(\d+)$#', (string)($a['headers']['content-range'] ?? ''), $m)) $count = (int)$m[1];
        if ($count > 0) {
            return ['success' => false, 'needs_confirm' => true, 'count' => $count,
                    'message' => $count . ' active appointment' . ($count === 1 ? ' is' : 's are') . ' booked at ' . $branch['branch_name']
                               . ' on ' . exceptionDateLabel($date) . '. They will NOT be cancelled automatically — please contact the patient'
                               . ($count === 1 ? '' : 's') . ' to reschedule.'];
        }
    }

    $existing = supabase_request(BR_EXCEPTIONS_TABLE, 'GET', [],
        'select=exception_id,is_open,reason&branch_id=eq.' . (int)$branch['branch_id'] . '&exception_date=eq.' . $date . '&limit=1');
    if ($existing['error']) return ['success' => false, 'message' => exceptionDbError($existing['error'])];

    $payload = ['is_open' => $isOpen, 'reason' => $reason !== '' ? $reason : null];
    $old     = $existing['data'][0] ?? null;
    if ($old) {
        $r = supabase_request(BR_EXCEPTIONS_TABLE, 'PATCH', $payload, 'exception_id=eq.' . (int)$old['exception_id']);
    } else {
        $r = supabase_request(BR_EXCEPTIONS_TABLE, 'POST', $payload + [
            'branch_id'      => (int)$branch['branch_id'],
            'exception_date' => $date,
            'created_by'     => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
        ]);
    }
    if ($r['error']) return ['success' => false, 'message' => exceptionDbError($r['error'])];

    $label = exceptionDateLabel($date) . ' — ' . ($isOpen ? 'OPEN' : 'CLOSED') . ($reason !== '' ? ' (' . $reason . ')' : '');
    logActivity('branches', $old ? 'UPDATE' : 'INSERT', $branch['branch_id'], $branch['branch_name'],
        'Schedule exception ' . ($old ? 'changed' : 'added') . ': ' . $label,
        (int)$branch['branch_id'], $branch['branch_name']);
    // Staff bell — only when something really changed (same open/closed + reason = no alert)
    if (!$old || (bool)$old['is_open'] !== $isOpen || (string)($old['reason'] ?? '') !== $reason) {
        staffn_branch_exception((int)$branch['branch_id'], $branch['branch_name'], $date, $isOpen, $reason, (bool)$old);
    }

    return ['success' => true, 'message' => $branch['branch_name'] . ' will be ' . ($isOpen ? 'open' : 'closed') . ' on ' . exceptionDateLabel($date) . '.'];
}

function deleteBranchException(array $d): array {
    $id = (int)($d['exception_id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Missing exception ID.'];

    $r = supabase_request(BR_EXCEPTIONS_TABLE, 'GET', [], 'select=exception_id,branch_id,exception_date,is_open,reason&exception_id=eq.' . $id . '&limit=1');
    if ($r['error']) return ['success' => false, 'message' => exceptionDbError($r['error'])];
    $row = $r['data'][0] ?? null;
    if (!$row) return ['success' => false, 'message' => 'That schedule exception no longer exists.'];

    $del = supabase_request(BR_EXCEPTIONS_TABLE, 'DELETE', [], 'exception_id=eq.' . $id);
    if ($del['error']) return ['success' => false, 'message' => exceptionDbError($del['error'])];

    $branch = exceptionBranch((int)$row['branch_id']);
    $name   = $branch['branch_name'] ?? ('Branch #' . $row['branch_id']);
    logActivity('branches', 'DELETE', $row['branch_id'], $name,
        'Schedule exception removed: ' . exceptionDateLabel($row['exception_date']) . ' — ' . ($row['is_open'] ? 'OPEN' : 'CLOSED')
        . (!empty($row['reason']) ? ' (' . $row['reason'] . ')' : '') . '. The usual weekly schedule applies again.',
        (int)$row['branch_id'], $name);

    return ['success' => true, 'message' => 'Removed. ' . exceptionDateLabel($row['exception_date']) . ' follows the usual schedule again.'];
}

function exceptionDbError(mixed $error): string {
    $msg = is_string($error) ? $error : json_encode($error);
    if (stripos($msg, BR_EXCEPTIONS_TABLE) !== false || stripos($msg, 'does not exist') !== false || stripos($msg, 'schema cache') !== false) {
        return BR_EXCEPTIONS_SQL_MSG;
    }
    return $msg;
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
/** Branch address parts (tidied like the portal's) + the joined one-line address. */
function branchAddressParts(array $d): array {
    $part = fn($k, $max) => mb_substr(normalize_address_text((string)($d[$k] ?? '')), 0, $max);
    $p = [
        'street'   => $part('street', 150),
        'barangay' => $part('barangay', 100),
        'city'     => $part('city', 100),
        'province' => $part('province', 100),
        'zip_code' => preg_replace('/\D/', '', (string)($d['zip_code'] ?? '')),
    ];
    $line = implode(', ', array_filter([$p['street'], $p['barangay'], $p['city'], $p['province']], 'strlen'));
    $p['address'] = trim($line . ($p['zip_code'] !== '' ? ' ' . $p['zip_code'] : ''));
    return $p;
}

function branchPayload(array $d, bool $isAdd): array {
    $name    = trim($d['branch_name']    ?? '');
    $addr    = branchAddressParts($d);
    $contact = trim($d['contact_number'] ?? '');
    $open    = trim($d['open_time']      ?? '');
    $close   = trim($d['close_time']     ?? '');
    $status  = $d['status'] ?? 'active';

    if ($name === '')                 return [null, 'Branch name is required.'];
    if (mb_strlen($name) > 100)       return [null, 'Branch name is too long (100 characters max).'];
    if ($addr['street'] === '')       return [null, 'Building / house no. and street are required.'];
    if ($addr['province'] === '')     return [null, 'Province is required.'];
    if ($addr['city'] === '')         return [null, 'City / municipality is required.'];
    if ($addr['barangay'] === '')     return [null, 'Barangay is required.'];
    if (!preg_match('/^\d{4}$/', $addr['zip_code'])) return [null, 'ZIP code must be 4 digits.'];
    if ($e = pv_mobile_error($contact, true)) return [null, $e];
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $open) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $close)) {
        return [null, 'Please enter both an open and a close time.'];
    }
    if (substr($close, 0, 5) <= substr($open, 0, 5)) {
        return [null, 'Close time must be later than open time.'];
    }
    if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

    $payload = [
        'branch_name'    => $name,
        'address'        => $addr['address'],
        'street'         => $addr['street'],
        'province'       => $addr['province'],
        'city'           => $addr['city'],
        'barangay'       => $addr['barangay'],
        'zip_code'       => $addr['zip_code'],
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

/** Write a branches row; without the address-part columns (SQL not run yet) save the joined address only. */
function branchWrite(string $method, array $payload, string $query = ''): array {
    $r = supabase_request('branches', $method, $payload, $query);
    if ($r['error'] && staffMissingColumn($r['error']) && stripos(json_encode($r['error']), 'operating_days') === false) {
        $r = supabase_request('branches', $method, array_diff_key($payload, array_flip(['street', 'province', 'city', 'barangay', 'zip_code'])), $query);
    }
    return $r;
}

function addBranch(array $d): array {
    [$payload, $err] = branchPayload($d, true);
    if ($err) return ['success' => false, 'message' => $err];

    $payload['branch_code'] = generateBranchCode();
    $payload['created_at']  = date('c');

    $r = branchWrite('POST', $payload);
    if ($r['error']) return ['success' => false, 'message' => branchDbError($r['error'])];

    $new = $r['data'][0] ?? [];
    logActivity('branches', 'INSERT', $new['branch_id'] ?? $payload['branch_code'], $payload['branch_name'],
        'Branch added — ' . formatOperatingDays($payload['operating_days']) . ', '
        . substr($payload['open_time'], 0, 5) . '–' . substr($payload['close_time'], 0, 5) . ', ' . $payload['status'] . '.',
        isset($new['branch_id']) ? (int)$new['branch_id'] : null, $payload['branch_name'],
        null, null, branchAuditValues($payload));
    return ['success' => true, 'message' => 'Branch added successfully.'];
}

// ── Audit of branch changes ──────────────────────────────────
// Operating days / hours / status history matters beyond the audit page:
// the predictive analytics reads these rows (module 'branches',
// before_data/after_data.operating_days) to know which weekdays a branch
// was open on any past date.

const BR_AUDIT_FIELDS = [
    'branch_name'    => 'Name',
    'address'        => 'Address',
    'contact_number' => 'Contact',
    'open_time'      => 'Open time',
    'close_time'     => 'Close time',
    'operating_days' => 'Operating days',
    'status'         => 'Status',
];

/** The audited fields of a branch row/payload in one comparable shape. */
function branchAuditValues(array $row): array {
    $out = [];
    foreach (array_keys(BR_AUDIT_FIELDS) as $f) {
        if (!array_key_exists($f, $row)) continue;
        $v = $row[$f];
        if ($f === 'operating_days')                         $v = normalizeOperatingDays($v);
        elseif ($f === 'open_time' || $f === 'close_time')   $v = $v ? substr((string)$v, 0, 5) : null;
        else                                                 $v = $v === null ? null : trim((string)$v);
        $out[$f] = $v;
    }
    return $out;
}

/** "Operating days: Mon–Sat → Sun, Tue–Sat; Status: active → inactive" */
function branchAuditText(string $f, mixed $v): string {
    if ($f === 'operating_days') return formatOperatingDays($v);
    return ($v === null || $v === '') ? '—' : (string)$v;
}

function editBranch(array $d): array {
    if (empty($d['branch_id'])) return ['success' => false, 'message' => 'Missing branch ID.'];
    $branchId = (int)$d['branch_id'];

    [$payload, $err] = branchPayload($d, false);
    if ($err) return ['success' => false, 'message' => $err];

    // Old values first, so the audit row can show exactly what changed
    $oldRes = supabase_request('branches', 'GET', [],
        'select=' . implode(',', array_keys(BR_AUDIT_FIELDS)) . '&branch_id=eq.' . $branchId . '&limit=1');
    $oldRow = (!$oldRes['error'] && !empty($oldRes['data'][0])) ? $oldRes['data'][0] : null;

    // Active → Inactive while patients are still booked: confirm first (appointments are NOT cancelled)
    if ($oldRow && ($oldRow['status'] ?? 'active') === 'active' && $payload['status'] === 'inactive' && empty($d['confirm'])) {
        $a = supabase_request('appointments', 'GET', [],
            'select=appointment_id&branch_id=eq.' . $branchId . '&appointment_date=gte.' . exceptionToday()
            . '&status=not.in.(cancelled,completed,no_show)&limit=1', ['Prefer: count=exact']);
        $count = 0;
        if (!$a['error']) {
            $count = preg_match('#/(\d+)$#', (string)($a['headers']['content-range'] ?? ''), $m) ? (int)$m[1] : count($a['data'] ?? []);
        }
        if ($count > 0) {
            return ['success' => false, 'needs_confirm' => true, 'count' => $count,
                    'message' => $count . ' upcoming appointment' . ($count === 1 ? ' is' : 's are') . ' booked at ' . $payload['branch_name']
                               . '. Making it Inactive stops new online bookings but does NOT cancel ' . ($count === 1 ? 'it' : 'them')
                               . ' — please contact the patient' . ($count === 1 ? '' : 's') . ' to reschedule.'];
        }
    }

    $r = branchWrite('PATCH', $payload, 'branch_id=eq.' . $branchId);
    if ($r['error']) return ['success' => false, 'message' => branchDbError($r['error'])];

    $new    = branchAuditValues($payload);
    $before = [];
    $after  = [];
    if ($oldRow) {
        $old = branchAuditValues($oldRow);
        foreach ($new as $f => $v) {
            if (($old[$f] ?? null) !== $v) { $before[$f] = $old[$f] ?? null; $after[$f] = $v; }
        }
    }
    if ($before || !$oldRow) {
        $parts = [];
        foreach ($after as $f => $v) {
            $parts[] = BR_AUDIT_FIELDS[$f] . ': ' . branchAuditText($f, $before[$f]) . ' → ' . branchAuditText($f, $v);
        }
        logActivity('branches', 'UPDATE', $branchId, $payload['branch_name'],
            $oldRow ? 'Branch updated — ' . implode('; ', $parts) . '.' : 'Branch updated (previous values could not be read).',
            $branchId, $payload['branch_name'],
            null, $oldRow ? $before : null, $oldRow ? $after : $new);
    }
    if ($oldRow && $after) {
        $hrs = formatOperatingDays($new['operating_days'] ?? ($oldRow['operating_days'] ?? null)) . ', '
             . ($new['open_time'] ?? '?') . '–' . ($new['close_time'] ?? '?');
        staffn_branch_updated($branchId, $payload['branch_name'], $before, $after, $hrs);
    }
    return ['success' => true, 'message' => 'Branch updated successfully.'];
}

function deleteBranch(array $d): array {
    if (empty($d['branch_id'])) return ['success' => false, 'message' => 'Missing branch ID.'];

    $oldRes = supabase_request('branches', 'GET', [],
        'select=' . implode(',', array_keys(BR_AUDIT_FIELDS)) . '&branch_id=eq.' . (int)$d['branch_id'] . '&limit=1');
    $oldRow = (!$oldRes['error'] && !empty($oldRes['data'][0])) ? $oldRes['data'][0] : null;

    $r = supabase_request('branches', 'DELETE', [], 'branch_id=eq.' . (int)$d['branch_id']);
    if ($r['error']) {
        $msg = is_string($r['error']) ? $r['error'] : json_encode($r['error']);
        if (stripos($msg, 'foreign key') !== false || stripos($msg, 'violates') !== false) {
            return ['success' => false, 'message' => 'This branch still has patients, appointments or staff linked to it. Set it to Inactive instead.'];
        }
        return ['success' => false, 'message' => $msg];
    }
    $name = $oldRow['branch_name'] ?? ('Branch #' . (int)$d['branch_id']);
    logActivity('branches', 'DELETE', (int)$d['branch_id'], $name, 'Branch deleted.',
        null, null, null, $oldRow ? branchAuditValues($oldRow) : null, null);
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

// ─────────────────────────────────────────────────────────────
//  DISPATCH (kept last — see the note at the top)
// ─────────────────────────────────────────────────────────────
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'add':    echo json_encode(addBranch($input));    break;
    case 'edit':   echo json_encode(editBranch($input));   break;
    case 'delete': echo json_encode(deleteBranch($input)); break;
    // Schedule exceptions (one date that differs from the weekly schedule)
    case 'list_exceptions':  echo json_encode(listBranchExceptions($input));  break;
    case 'save_exception':   echo json_encode(saveBranchException($input));   break;
    case 'delete_exception': echo json_encode(deleteBranchException($input)); break;
    default:       echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}
