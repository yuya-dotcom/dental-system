<?php
// =============================================================
//  api/user_crud.php  —  add, edit, delete, reset_password,
//                         verify_owner_password
//  Owner only. Used by accounts-records.php (assets/js/accounts.js).
//
//  • A Dentist account keeps a dentist record in step (dentists table):
//    created with the account, name / branch / status synced on edit,
//    set Inactive when the role changes away or the account is deleted.
//  • Sidebar access (users.allowed_modules) — controllers/module_access.php.
//  • Reset Password of ANOTHER account needs the signed-in owner's own
//    password first (verify_owner_password → a short grant for that one
//    account). Changing YOUR OWN password needs your current password.
//  • contact_number / gender / allowed_modules need
//    database/proposed/2026-11-16_owner_records_eval2.sql; until it runs the
//    rest of the account still saves and the reply says what was skipped.
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/appointment_edit_grant.php';   // shared wrong-password limit
require_once __DIR__ . '/../controllers/module_access.php';
require_once __DIR__ . '/../controllers/staff_names.php';
require_once __DIR__ . '/../controllers/portal_validation.php';         // pv_mobile_error (+63 9XX XXX XXXX)

header('Content-Type: application/json');
header('Cache-Control: no-store');
requireLogin();

if (!isOwner()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

const USR_PW_GRANT_SECONDS = 600;   // owner verified → 10 minutes to finish that reset
const USR_NEW_COLUMNS      = ['contact_number', 'gender', 'allowed_modules'];
const USR_SCHEMA_NOTE      = 'Run database/proposed/2026-11-16_owner_records_eval2.sql to enable it.';

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'add':                   echo json_encode(addUser($input));             break;
    case 'edit':                  echo json_encode(editUser($input));            break;
    case 'delete':                echo json_encode(deleteUser($input));          break;
    case 'verify_owner_password': echo json_encode(verifyOwnerPassword($input)); break;
    case 'reset_password':        echo json_encode(resetPassword($input));       break;
    default:                      echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

// =============================================================
//  DENTIST SYNC HELPERS
// =============================================================

/** Find a dentist row linked to a user_id (or null). */
function findDentistByUserId(int $userId): ?array {
    $r = supabase_request('dentists', 'GET', [],
        'user_id=eq.' . $userId . '&select=dentist_id,full_name,branch_id,status&limit=1'
    );
    return $r['data'][0] ?? null;
}

/**
 * Write a dentists row; when the new name-part / email columns aren't in the
 * database yet, retry with the columns every version has.
 */
function dentistWrite(string $method, array $row, string $query = ''): array {
    $r = supabase_request('dentists', $method, $row, $query);
    if ($r['error'] && staffMissingColumn($r['error'])) {
        $r = supabase_request('dentists', $method,
            array_diff_key($row, array_flip(['first_name', 'middle_name', 'last_name', 'suffix', 'email', 'age', 'gender'])), $query);
    }
    return $r;
}

/** Dentist-record name fields from the account's "Last, First Middle" name. */
function dentistNameFields(string $accountName): array {
    $p = staffNameParts(staffSplitName($accountName));
    return [
        'full_name'   => staffDentistName($p) ?: $accountName,
        'first_name'  => $p['first_name'] ?: null,
        'middle_name' => $p['middle_name'] ?: null,
        'last_name'   => $p['last_name'] ?: null,
        'suffix'      => $p['suffix'] ?: null,
    ];
}

/** A new Dentist account → its dentist record. */
function syncDentistOnCreate(int $userId, string $accountName, ?int $branchId, string $specialization = '', string $email = '', ?string $contact = null, ?string $gender = null): void {
    if (!$branchId) return; // dentist must have a branch

    $row = dentistNameFields($accountName) + [
        'user_id'    => $userId,
        'branch_id'  => $branchId,
        'status'     => 'active',
        'email'      => $email !== '' ? $email : null,
        'gender'     => $gender,
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ];
    if ($specialization !== '') $row['specialization'] = $specialization;
    if ($contact)               $row['contact_number'] = $contact;
    dentistWrite('POST', $row);
}

/** Dentist account edited → name, branch and status copied to the dentist record. */
function syncDentistOnUpdate(int $userId, string $accountName, ?int $branchId, string $status, ?string $gender = null): void {
    $existing = findDentistByUserId($userId);

    if ($existing) {
        // An active login keeps a dentist who is On Leave on leave (leave is
        // managed in Dentist Records); an inactive login always deactivates.
        $dentStatus = $status === 'active'
            ? (($existing['status'] ?? '') === 'on_leave' ? 'on_leave' : 'active')
            : 'inactive';
        dentistWrite('PATCH', dentistNameFields($accountName) + [
            'branch_id'  => $branchId,
            'status'     => $dentStatus,
            'updated_at' => date('c'),
        ] + ($gender ? ['gender' => $gender] : []), 'dentist_id=eq.' . $existing['dentist_id']);
    } elseif ($branchId) {
        // No dentist record yet (account made before this sync existed)
        syncDentistOnCreate($userId, $accountName, $branchId, '', '', null, $gender);
    }
}

/** Role changed away from dentist, or the account deleted → record Inactive (history kept). */
function deactivateDentist(int $userId): void {
    $existing = findDentistByUserId($userId);
    if (!$existing) return;

    supabase_request('dentists', 'PATCH', [
        'status'     => 'inactive',
        'updated_at' => date('c'),
    ], 'dentist_id=eq.' . $existing['dentist_id']);
}

// =============================================================
//  USER CRUD
// =============================================================

function generateUserCode(): string {
    $r    = supabase_request('users', 'GET', [], 'select=user_code&order=user_id.desc&limit=1');
    $last = $r['data'][0]['user_code'] ?? 'USR-000';
    $num  = (int)substr($last, 4) + 1;
    return 'USR-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

function usernameExists(string $username, int $excludeId = 0): bool {
    $q = 'select=user_id&username=eq.' . urlencode($username);
    if ($excludeId) $q .= '&user_id=neq.' . $excludeId;
    $r = supabase_request('users', 'GET', [], $q);
    return !empty($r['data']);
}

function emailExists(string $email, int $excludeId = 0): bool {
    $q = 'select=user_id&email=eq.' . urlencode($email);
    if ($excludeId) $q .= '&user_id=neq.' . $excludeId;
    $r = supabase_request('users', 'GET', [], $q);
    return !empty($r['data']);
}

/**
 * Shared checks for add/edit. Returns an error message, or null when valid.
 * Normalises full_name / username / email / contact / role / status / branch_id in $d.
 * $prev (edit only): the stored row — an unchanged username/email is not
 * re-checked against the format rules, so older accounts can still be saved.
 */
function validateUserInput(array &$d, array $prev = []): ?string {
    $d['full_name'] = trim(preg_replace('/\s+/', ' ', (string)($d['full_name'] ?? '')));
    $d['username']  = trim((string)($d['username'] ?? ''));
    $d['email']     = trim((string)($d['email'] ?? ''));
    $d['contact_number'] = trim((string)($d['contact_number'] ?? ''));
    if ($d['full_name'] === '')           return 'Full name is required.';
    if (mb_strlen($d['full_name']) > 150) return 'Full name is too long.';
    if ($d['username'] === '')            return 'Username is required.';
    if ($d['username'] !== (string)($prev['username'] ?? "\0")
        && !preg_match('/^[A-Za-z0-9]{3,50}$/', $d['username'])) return 'Username can only use letters and numbers (3–50, no spaces or symbols).';
    if ($d['email'] === '')               return 'Email is required.';
    if ($d['email'] !== (string)($prev['email'] ?? "\0")
        && (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || strlen($d['email']) > 120)) return 'Email address is not valid.';
    if ($err = pv_mobile_error($d['contact_number'], true)) return $err;
    $d['gender'] = staffGender($d['gender'] ?? '');
    if ($d['gender'] === null)            return 'Please choose a gender.';
    if (!in_array($d['role'] ?? '', ['owner', 'admin', 'dentist'], true)) return 'Please choose a valid role.';
    $d['status']    = ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $d['branch_id'] = $d['role'] === 'owner' ? null : ((int)($d['branch_id'] ?? 0) ?: null);
    if ($d['role'] !== 'owner' && !$d['branch_id']) return 'Please choose a branch for this ' . $d['role'] . ' account.';
    // Sidebar access: omitted / null → all modules the role allows
    $d['allowed_modules'] = esNormalizeModules(array_key_exists('allowed_modules', $d) ? $d['allowed_modules'] : null, $d['role']);
    if ($d['allowed_modules'] === []) return 'Choose at least one page this account can open.';
    return null;
}

/** users.allowed_modules value: a JSON array (PostgREST stores it as text[]); null = all modules. */
function usrModulesValue(?array $modules): ?array {
    return $modules === null ? null : array_values($modules);
}

/**
 * Write a users row. When contact_number / allowed_modules aren't in the
 * database yet, save everything else and report what was skipped.
 * Returns [result, skippedNote|null].
 */
function usrWrite(string $method, array $payload, string $query): array {
    $r = supabase_request('users', $method, $payload, $query);
    if (!$r['error'] || !staffMissingColumn($r['error'])) return [$r, null];

    $skipped = [];
    if (($payload['contact_number'] ?? null) !== null) $skipped[] = 'contact number';
    if (($payload['gender'] ?? null) !== null)          $skipped[] = 'gender';
    if (($payload['allowed_modules'] ?? null) !== null) $skipped[] = 'page access';
    $r = supabase_request('users', $method, array_diff_key($payload, array_flip(USR_NEW_COLUMNS)), $query);
    $list = count($skipped) > 1 ? implode(', ', array_slice($skipped, 0, -1)) . ' and ' . end($skipped) : ($skipped[0] ?? '');
    return [$r, $skipped ? 'The ' . $list . ' could not be saved yet. ' . USR_SCHEMA_NOTE : null];
}

function addUser(array $d): array {
    if ($err = validateUserInput($d)) return ['success' => false, 'message' => $err];
    if (empty($d['password']))   return ['success' => false, 'message' => 'Password is required.'];
    if (strlen($d['password']) < 6) return ['success' => false, 'message' => 'Password must be at least 6 characters.'];

    if (usernameExists($d['username'])) return ['success' => false, 'message' => 'Username is already taken.'];
    if (emailExists($d['email']))       return ['success' => false, 'message' => 'Email is already in use.'];

    $role     = $d['role'];
    $branchId = $d['branch_id'];
    $contact  = $d['contact_number'] !== '' ? $d['contact_number'] : null;

    $payload = [
        'user_code'       => generateUserCode(),
        'full_name'       => $d['full_name'],
        'email'           => $d['email'],
        'username'        => $d['username'],
        'password'        => password_hash($d['password'], PASSWORD_BCRYPT),
        'role'            => $role,
        'branch_id'       => $branchId,
        'status'          => $d['status'],
        'contact_number'  => $contact,
        'gender'          => $d['gender'],
        'allowed_modules' => usrModulesValue($d['allowed_modules']),
        'created_at'      => date('c'),
        'updated_at'      => date('c'),
    ];

    [$r, $note] = usrWrite('POST', $payload, 'select=user_id');
    if ($r['error']) return ['success' => false, 'message' => is_string($r['error']) ? $r['error'] : 'Could not save the account.'];

    // ── Sync dentists table if role is dentist ─────────────────
    if ($role === 'dentist') {
        $newUserId = $r['data'][0]['user_id'] ?? null;
        if (!$newUserId) {   // fallback: fetch the new user by username
            $lookup = supabase_request('users', 'GET', [],
                'username=eq.' . urlencode($d['username']) . '&select=user_id&limit=1'
            );
            $newUserId = $lookup['data'][0]['user_id'] ?? null;
        }
        if ($newUserId) {
            $spec = mb_substr(trim((string)($d['specialization'] ?? '')), 0, 100);
            syncDentistOnCreate((int)$newUserId, $d['full_name'], $branchId, $spec, $d['email'], $contact, $d['gender']);
        }
    }

    return ['success' => true, 'message' => 'User account created successfully.', 'warning' => $note];
}

function editUser(array $d): array {
    if (empty($d['user_id']))   return ['success' => false, 'message' => 'Missing user ID.'];
    $id = (int)$d['user_id'];

    // Stored row: detects role changes and relaxes checks on unchanged fields
    $prevUser = supabase_request('users', 'GET', [],
        'user_id=eq.' . $id . '&select=role,username,email&limit=1'
    );
    if (empty($prevUser['data'][0])) return ['success' => false, 'message' => 'Account not found. It may have been deleted.'];
    $prevRole = $prevUser['data'][0]['role'] ?? null;

    if ($err = validateUserInput($d, $prevUser['data'][0])) return ['success' => false, 'message' => $err];
    $role = $d['role'];

    if (usernameExists($d['username'], $id)) return ['success' => false, 'message' => 'Username is already taken.'];
    if (emailExists($d['email'],       $id)) return ['success' => false, 'message' => 'Email is already in use.'];

    $branchId = $d['branch_id'];
    $status   = $d['status'];

    // Don't let the signed-in owner lock themselves out
    if ($id === (int)($_SESSION['user_id'] ?? 0)) {
        if ($role !== $prevRole)  return ['success' => false, 'message' => "You can't change your own role. Ask another owner."];
        if ($status !== 'active') return ['success' => false, 'message' => "You can't deactivate your own account."];
    }

    $payload = [
        'full_name'       => $d['full_name'],
        'email'           => $d['email'],
        'username'        => $d['username'],
        'role'            => $role,
        'branch_id'       => $branchId,
        'status'          => $status,
        'contact_number'  => $d['contact_number'] !== '' ? $d['contact_number'] : null,
        'gender'          => $d['gender'],
        'allowed_modules' => usrModulesValue($d['allowed_modules']),
        'updated_at'      => date('c'),
    ];

    [$r, $note] = usrWrite('PATCH', $payload, 'user_id=eq.' . $id);
    if ($r['error']) return ['success' => false, 'message' => is_string($r['error']) ? $r['error'] : 'Could not save the account.'];

    // ── Sync dentists table based on role change ───────────────
    if ($role === 'dentist') {
        syncDentistOnUpdate($id, $d['full_name'], $branchId, $status, $d['gender']);
    } elseif ($prevRole === 'dentist') {
        deactivateDentist($id);
    }

    return ['success' => true, 'message' => 'Account updated successfully.', 'warning' => $note];
}

function deleteUser(array $d): array {
    if (empty($d['user_id'])) return ['success' => false, 'message' => 'Missing user ID.'];
    if ((int)$d['user_id'] === (int)($_SESSION['user_id'] ?? 0)) {
        return ['success' => false, 'message' => 'You cannot delete your own account.'];
    }

    $id = (int)$d['user_id'];

    // If dentist — deactivate their dentist record first (preserve history)
    $prevUser = supabase_request('users', 'GET', [], 'user_id=eq.' . $id . '&select=role&limit=1');
    if (($prevUser['data'][0]['role'] ?? null) === 'dentist') deactivateDentist($id);

    $r = supabase_request('users', 'DELETE', [], 'user_id=eq.' . $id);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Account deleted successfully.'];
}

// =============================================================
//  PASSWORDS
// =============================================================

/**
 * Checks the signed-in owner's own password (bcrypt, like the sign-in).
 * Wrong passwords share the per-session limit of the appointment-edit check.
 * Returns null when correct, or an error reply.
 */
function usrCheckOwnPassword(string $password): ?array {
    $wait = aptEditLockSeconds();
    if ($wait > 0) {
        return ['success' => false, 'code' => 'LOCKED',
                'message' => 'Too many incorrect attempts. Please wait ' . aptEditWaitLabel($wait) . ' and try again.'];
    }
    if ($password === '') return ['success' => false, 'code' => 'EMPTY', 'message' => 'Please enter your password.'];

    $res = supabase_request('users', 'GET', [],
        'user_id=eq.' . (int)$_SESSION['user_id'] . '&select=password,status,role&limit=1');
    $me = (!$res['error'] && is_array($res['data'])) ? ($res['data'][0] ?? null) : null;
    if ($res['error']) return ['success' => false, 'code' => 'UNAVAILABLE', 'message' => "We couldn't check your password right now. Please try again."];
    if (!$me || ($me['status'] ?? '') !== 'active' || ($me['role'] ?? '') !== 'owner') {
        return ['success' => false, 'code' => 'DENIED', 'message' => 'Your account can no longer manage passwords. Please sign in again.'];
    }
    if (!is_string($me['password'] ?? null) || !password_verify($password, $me['password'])) {
        $left = aptEditRecordFailure();
        $msg  = 'Incorrect password. Please try again.';
        if ($left === 0)    $msg = 'Incorrect password. Too many attempts — please wait ' . aptEditWaitLabel(aptEditLockSeconds()) . ' and try again.';
        elseif ($left <= 2) $msg .= ' ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.';
        return ['success' => false, 'code' => 'WRONG_PASSWORD', 'message' => $msg];
    }
    unset($_SESSION['apt_edit_fails']);
    return null;
}

/** Owner re-enters THEIR password before resetting another account's password. */
function verifyOwnerPassword(array $d): array {
    $target = (int)($d['user_id'] ?? 0);
    if ($target <= 0) return ['success' => false, 'message' => 'Missing user ID.'];
    if ($target === (int)$_SESSION['user_id']) {
        return ['success' => false, 'message' => 'Use Change Password with your current password for your own account.'];
    }
    if ($fail = usrCheckOwnPassword(is_string($d['password'] ?? null) ? $d['password'] : '')) return $fail;

    $grants = is_array($_SESSION['usr_pw_grants'] ?? null) ? $_SESSION['usr_pw_grants'] : [];
    foreach ($grants as $k => $until) if ($until < time()) unset($grants[$k]);
    $grants[$target] = time() + USR_PW_GRANT_SECONDS;
    $_SESSION['usr_pw_grants'] = $grants;
    return ['success' => true, 'expires_in' => USR_PW_GRANT_SECONDS];
}

function resetPassword(array $d): array {
    $id = (int)($d['user_id'] ?? 0);
    if ($id <= 0)                  return ['success' => false, 'message' => 'Missing user ID.'];
    $new = is_string($d['new_password'] ?? null) ? $d['new_password'] : '';
    if ($new === '')               return ['success' => false, 'message' => 'New password is required.'];
    if (strlen($new) < 6)          return ['success' => false, 'message' => 'Password must be at least 6 characters.'];

    $self = $id === (int)$_SESSION['user_id'];
    if ($self) {
        // Your own account: your current password, checked on the server
        $cur = is_string($d['current_password'] ?? null) ? $d['current_password'] : '';
        if ($fail = usrCheckOwnPassword($cur)) {
            if (($fail['code'] ?? '') === 'EMPTY') $fail['message'] = 'Please enter your current password.';
            return $fail + ['field' => 'current_password'];
        }
        if (hash_equals($cur, $new)) return ['success' => false, 'message' => 'Choose a new password that is different from your current one.', 'field' => 'new_password'];
    } elseif ((int)($_SESSION['usr_pw_grants'][$id] ?? 0) < time()) {
        // Another account: the owner must have confirmed their own password first
        return ['success' => false, 'code' => 'NEEDS_VERIFY', 'message' => 'Please confirm your own password again before resetting this account.'];
    }

    $r = supabase_request('users', 'PATCH',
        ['password' => password_hash($new, PASSWORD_BCRYPT), 'updated_at' => date('c')],
        'user_id=eq.' . $id);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    if (!$self) unset($_SESSION['usr_pw_grants'][$id]);   // one reset per confirmation
    return ['success' => true, 'message' => $self ? 'Your password was changed.' : 'Password reset successfully.'];
}
