<?php
// =============================================================
//  api/dentist_crud.php  —  edit, delete dentists
//  Owner + Admin (admins: own branch only).
//  Used by: dentist-records.php (assets/js/dentists.js)
//  New dentists come from Accounts (a Dentist account — api/user_crud.php).
//
//  • The name is saved as parts (first / middle / last / suffix) and joined
//    into full_name as "Dr. First Middle Last Suffix" for every other page.
//    Name parts, age, gender and email need
//    database/proposed/2026-11-16_owner_records_eval2.sql; until it runs the
//    joined name / contact / branch / status still save and the reply says so.
//
//  • Every change is written to the audit log (module 'dentists'),
//    with the changed fields' before / after values.
//  • A dentist linked to a staff login (dentists.user_id) keeps that
//    account in step: name and branch changes are applied to the
//    users row too (api/user_crud.php syncs the other direction).
//  • Making an Active dentist On Leave / Inactive, or moving them to
//    another branch, while they still have upcoming appointments needs
//    confirm = true. Appointments are never changed automatically.
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/audit_controller.php';
require_once __DIR__ . '/../controllers/staff_names.php';
require_once __DIR__ . '/../controllers/portal_validation.php';   // pv_mobile_error (+63 9XX XXX XXXX)

header('Content-Type: application/json');
requireLogin();

if (!isOwner() && !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

const DENT_CRUD_STATUSES = ['active', 'on_leave', 'inactive'];
const DENT_AUDIT_FIELDS  = [
    'full_name'      => 'Name',
    'specialization' => 'Specialization',
    'contact_number' => 'Contact',
    'branch_id'      => 'Branch',
    'status'         => 'Status',
    'age'            => 'Age',
    'gender'         => 'Gender',
    'email'          => 'Email',
];
/** Columns added by the 2026-11-16 SQL file (left out of reads / writes until it runs). */
const DENT_NEW_FIELDS = ['first_name', 'middle_name', 'last_name', 'suffix', 'age', 'gender', 'email'];
/** Appointment statuses that still need the dentist / branch. */
const DENT_ACTIVE_APPT_FILTER = 'status=not.in.(cancelled,completed,no_show)';

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'edit':   echo json_encode(editDentist($input));   break;
    case 'delete': echo json_encode(deleteDentist($input)); break;
    default:       echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

/**
 * Validate the form fields shared by add + edit.
 * Returns [payload, error]; error is null when valid.
 */
function dentistPayload(array $d): array {
    $parts   = staffNameParts($d);
    $name    = staffDentistName($parts);
    $spec    = trim((string)($d['specialization'] ?? ''));
    $contact = trim((string)($d['contact_number'] ?? ''));
    $email   = trim((string)($d['email'] ?? ''));
    $ageRaw  = trim((string)($d['age'] ?? ''));
    $gender  = staffGender($d['gender'] ?? '');   // Male / Female / Non-binary
    $status  = (string)($d['status'] ?? 'active');

    if ($parts['first_name'] === '')                 return [null, 'First name is required.'];
    if ($parts['last_name'] === '')                  return [null, 'Last name is required.'];
    if (mb_strlen($name) > 100)                      return [null, 'Name is too long (100 characters max).'];
    if (!preg_match('/^\d{1,3}$/', $ageRaw) || (int)$ageRaw < 18 || (int)$ageRaw > 100) return [null, 'Age must be a whole number between 18 and 100.'];
    if ($gender === null)                            return [null, 'Please choose a gender.'];
    if ($email === '')                               return [null, 'Email is required.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) return [null, 'Email address is not valid.'];
    if (mb_strlen($spec) > 100)                      return [null, 'Specialization is too long (100 characters max).'];
    if ($e = pv_mobile_error($contact, true))        return [null, $e];
    if (empty($d['branch_id']) || (int)$d['branch_id'] <= 0) return [null, 'Branch is required.'];
    if (!in_array($status, DENT_CRUD_STATUSES, true)) $status = 'active';

    $row = [
        'full_name'      => $name,
        'first_name'     => $parts['first_name'],
        'middle_name'    => $parts['middle_name'] !== '' ? $parts['middle_name'] : null,
        'last_name'      => $parts['last_name'],
        'suffix'         => $parts['suffix'] !== '' ? $parts['suffix'] : null,
        'age'            => (int)$ageRaw,
        'gender'         => $gender,
        'email'          => $email,
        'contact_number' => $contact,
        'branch_id'      => (int)$d['branch_id'],
        'status'         => $status,
        'updated_at'     => date('c'),
    ];
    // Specialization is no longer on the Edit form: only changed when sent
    if (array_key_exists('specialization', $d)) $row['specialization'] = $spec !== '' ? $spec : null;
    return [$row, null];
}

// ── Helpers ──────────────────────────────────────────────────

/** Branch name by id (cached per request); '' when unknown. */
function dentBranchName(?int $branchId): string {
    static $cache = [];
    if (!$branchId) return '';
    if (!array_key_exists($branchId, $cache)) {
        $r = supabase_request('branches', 'GET', [], 'select=branch_name&branch_id=eq.' . $branchId . '&limit=1');
        $cache[$branchId] = (!$r['error'] && !empty($r['data'][0])) ? (string)$r['data'][0]['branch_name'] : '';
    }
    return $cache[$branchId];
}

/** The dentist row (audited fields + user_id) or null. */
function dentFind(int $dentistId): ?array {
    if ($dentistId <= 0) return null;
    $q = fn(array $cols) => 'select=dentist_id,user_id,' . implode(',', $cols) . '&dentist_id=eq.' . $dentistId . '&limit=1';
    $r = supabase_request('dentists', 'GET', [], $q(array_keys(DENT_AUDIT_FIELDS)));
    if ($r['error'] && staffMissingColumn($r['error'])) {   // SQL file not run yet
        $r = supabase_request('dentists', 'GET', [], $q(array_diff(array_keys(DENT_AUDIT_FIELDS), DENT_NEW_FIELDS)));
    }
    return (!$r['error'] && !empty($r['data'][0])) ? $r['data'][0] : null;
}

/** Audited fields in one comparable shape. */
function dentAuditValues(array $row): array {
    $out = [];
    foreach (array_keys(DENT_AUDIT_FIELDS) as $f) {
        if (!array_key_exists($f, $row)) continue;
        $v = $row[$f];
        $out[$f] = in_array($f, ['branch_id', 'age'], true) ? ($v === null || $v === '' ? null : (int)$v) : ($v === null || $v === '' ? null : trim((string)$v));
    }
    return $out;
}

/** Human text for one audited value. */
function dentAuditText(string $f, mixed $v): string {
    if ($v === null || $v === '') return '—';
    if ($f === 'branch_id') return dentBranchName((int)$v) ?: ('Branch #' . (int)$v);
    if ($f === 'status')    return $v === 'on_leave' ? 'On Leave' : ucfirst((string)$v);
    return (string)$v;
}

/** Number of upcoming appointments that still need this dentist (null if it can't be read). */
function dentUpcomingCount(int $dentistId): ?int {
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $r = supabase_request('appointments', 'GET', [],
        'select=appointment_id&dentist_id=eq.' . $dentistId . '&appointment_date=gte.' . $today . '&' . DENT_ACTIVE_APPT_FILTER . '&limit=1',
        ['Prefer: count=exact']);
    if ($r['error']) return null;
    if (preg_match('#/(\d+)$#', (string)($r['headers']['content-range'] ?? ''), $m)) return (int)$m[1];
    return is_array($r['data']) ? count($r['data']) : 0;
}

/** Database errors arrive as a string or a decoded PostgREST object. */
function dentistDbError(mixed $error): string {
    return is_string($error) ? $error : json_encode($error);
}

// ── Actions ──────────────────────────────────────────────────

function editDentist(array $d): array {
    $id = (int)($d['dentist_id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Missing dentist ID.'];
    [$payload, $err] = dentistPayload($d);
    if ($err) return ['success' => false, 'message' => $err];

    if (isAdmin() && $payload['branch_id'] !== getSessionBranch()) {
        return ['success' => false, 'message' => 'You can only manage dentists in your own branch.'];
    }

    $old = dentFind($id);
    if (!$old) return ['success' => false, 'message' => 'This dentist no longer exists. Refresh the page.'];
    if (isAdmin() && (int)$old['branch_id'] !== getSessionBranch()) {
        return ['success' => false, 'message' => 'You can only manage dentists in your own branch.'];
    }

    // Upcoming appointments: confirm before taking an active dentist off duty or moving them
    $leaving = ($old['status'] ?? 'active') === 'active' && $payload['status'] !== 'active';
    $moving  = (int)$old['branch_id'] !== $payload['branch_id'];
    if (($leaving || $moving) && empty($d['confirm'])) {
        $count = dentUpcomingCount($id);
        if ($count) {
            $what = $leaving
                ? 'Setting them to ' . dentAuditText('status', $payload['status'])
                : 'Moving them to ' . (dentBranchName($payload['branch_id']) ?: 'another branch');
            return ['success' => false, 'needs_confirm' => true, 'count' => $count,
                    'message' => $payload['full_name'] . ' still has ' . $count . ' upcoming appointment' . ($count === 1 ? '' : 's') . '. '
                               . $what . ' does NOT cancel or move ' . ($count === 1 ? 'it' : 'them')
                               . ' — please reassign or reschedule ' . ($count === 1 ? 'it' : 'them') . ' in Appointments.'];
        }
    }

    $r = supabase_request('dentists', 'PATCH', $payload, 'dentist_id=eq.' . $id);
    $warning = null;
    if ($r['error'] && staffMissingColumn($r['error'])) {
        // SQL file not run yet: save what the older columns can hold
        $r = supabase_request('dentists', 'PATCH', array_diff_key($payload, array_flip(DENT_NEW_FIELDS)), 'dentist_id=eq.' . $id);
        $warning = 'The name, contact, branch and status were saved, but age, gender and email could not be saved yet. '
                 . 'Run database/proposed/2026-11-16_owner_records_eval2.sql to enable them.';
        foreach (DENT_NEW_FIELDS as $f) unset($payload[$f]);   // keep the audit to what was really saved
    }
    if ($r['error']) return ['success' => false, 'message' => dentistDbError($r['error'])];

    // Keep the linked staff login in step (name + branch). Accounts use "Last, First Middle".
    $synced = false;
    if (!empty($old['user_id'])) {
        $u = supabase_request('users', 'PATCH',
            ['full_name' => staffAccountName(staffNameParts($d)), 'branch_id' => $payload['branch_id'], 'updated_at' => date('c')],
            'user_id=eq.' . (int)$old['user_id'] . '&role=eq.dentist');
        $synced = !$u['error'];
    }

    // Audit: only what changed
    $before = []; $after = [];
    $oldV = dentAuditValues($old);
    foreach (dentAuditValues($payload) as $f => $v) {
        if (($oldV[$f] ?? null) !== $v) { $before[$f] = $oldV[$f] ?? null; $after[$f] = $v; }
    }
    if ($before) {
        $parts = [];
        foreach ($after as $f => $v) $parts[] = DENT_AUDIT_FIELDS[$f] . ': ' . dentAuditText($f, $before[$f]) . ' → ' . dentAuditText($f, $v);
        $nameOrBranch = isset($after['full_name']) || isset($after['branch_id']);
        logActivity('dentists', 'UPDATE', $id, $payload['full_name'],
            'Dentist updated — ' . implode('; ', $parts) . '.'
            . ($synced && $nameOrBranch ? ' Linked staff login updated too.' : ''),
            $payload['branch_id'], dentBranchName($payload['branch_id']) ?: null, null, $before, $after);
    }

    return ['success' => true, 'warning' => $warning, 'message' => $payload['full_name'] . '\'s details were saved'
        . ($synced && (isset($after['full_name']) || isset($after['branch_id'])) ? ' (staff login updated too).' : '.')];
}

function deleteDentist(array $d): array {
    $id = (int)($d['dentist_id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Missing dentist ID.'];
    $old = dentFind($id);
    if ($old && isAdmin() && (int)$old['branch_id'] !== getSessionBranch()) {
        return ['success' => false, 'message' => 'You can only manage dentists in your own branch.'];
    }

    $r = supabase_request('dentists', 'DELETE', [], 'dentist_id=eq.' . $id);
    if ($r['error']) {
        $msg = dentistDbError($r['error']);
        if (stripos($msg, 'foreign key') !== false || stripos($msg, 'violates') !== false || stripos($msg, '23503') !== false) {
            return ['success' => false, 'message' => 'This dentist still has appointments, treatments or feedback on record, so it can\'t be deleted. Set the dentist to Inactive instead — the history is kept.'];
        }
        return ['success' => false, 'message' => $msg];
    }

    $name = $old['full_name'] ?? ('Dentist #' . $id);
    logActivity('dentists', 'DELETE', $id, $name,
        'Dentist deleted.' . (!empty($old['user_id']) ? ' Their staff login was not deleted.' : ''),
        isset($old['branch_id']) ? (int)$old['branch_id'] : null, isset($old['branch_id']) ? (dentBranchName((int)$old['branch_id']) ?: null) : null,
        null, $old ? dentAuditValues($old) : null, null);

    return ['success' => true, 'message' => 'Dentist deleted successfully.'];
}
