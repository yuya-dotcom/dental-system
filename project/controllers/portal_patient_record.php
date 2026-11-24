<?php
// controllers/portal_patient_record.php
// Creates the `patients` row behind a Patient Portal account.
//
// patients.contact_number is UNIQUE (patients_contact_number_key). A portal
// patient whose mobile number is already on another patient record (a walk-in
// added by staff, a family member sharing one phone) used to hit a 409 here; the
// error was ignored, so no patient / patient_profiles row was created and
// everything except the account columns (name, email, phone, birthdate) was lost.
//
// We never link the account to that other record — the number alone does not
// prove it is the same person, and linking would expose someone else's records.
// Instead the new patient row gets the per-account placeholder
// (__PORTAL_<account_id>__, already understood everywhere) and the real number
// stays on patient_accounts.contact_number, which the portal reads and the
// staff views fall back to.

require_once __DIR__ . '/../dbconfig.php';

/** Placeholder contact for a portal patient whose number cannot go on patients. */
function portal_contact_placeholder(int $accountId): string
{
    return '__PORTAL_' . $accountId . '__';
}

/** True when a Supabase response is a unique violation on the given constraint. */
function portal_is_unique_violation(array $res, string $constraint): bool
{
    if ((int)($res['status'] ?? 0) !== 409 && ($res['data']['code'] ?? '') !== '23505') return false;
    $msg = json_encode($res['data'] ?? '') . ' ' . (string)($res['error'] ?? '');
    return stripos($msg, $constraint) !== false;
}

/**
 * Creates the patients row for a portal account. $fields are the patients
 * columns (full_name, contact_number, gender, birthdate, branch_id, ...);
 * patient_code and status are filled in here.
 *
 * Returns ['patient_id' => int|null, 'error' => string|null, 'reused' => bool].
 */
function portal_create_patient_row(int $accountId, array $fields): array
{
    $code = 'PAT-P' . str_pad((string)$accountId, 5, '0', STR_PAD_LEFT);
    $row  = $fields + ['status' => 'active'];
    $row['patient_code']   = $code;
    $row['contact_number'] = trim((string)($row['contact_number'] ?? '')) ?: portal_contact_placeholder($accountId);

    $res = supabase_request('patients', 'POST', $row);

    // Mobile number already on another patient record → keep it on the account only
    if (portal_is_unique_violation($res, 'contact_number')) {
        error_log('[portal] patients.contact_number taken; account ' . $accountId . ' uses a placeholder on its patient row');
        $row['contact_number'] = portal_contact_placeholder($accountId);
        $res = supabase_request('patients', 'POST', $row);
    }

    // This account's own code already exists (an earlier attempt created the row
    // but could not link it). Reuse it only if no other account is linked to it.
    if (portal_is_unique_violation($res, 'patient_code')) {
        $ex  = supabase_request('patients', 'GET', [], 'patient_code=eq.' . urlencode($code) . '&select=patient_id&limit=1');
        $pid = (int)($ex['data'][0]['patient_id'] ?? 0);
        if ($pid) {
            $owner = supabase_request('patient_accounts', 'GET', [],
                'patient_id=eq.' . $pid . '&account_id=neq.' . $accountId . '&select=account_id&limit=1');
            if (($owner['status'] ?? 0) === 200 && empty($owner['data'])) {
                return ['patient_id' => $pid, 'error' => null, 'reused' => true];
            }
        }
    }

    $pid = (int)($res['data'][0]['patient_id'] ?? 0);
    if ($pid) return ['patient_id' => $pid, 'error' => null, 'reused' => false];

    $err = (string)($res['error'] ?? 'Unknown error');
    error_log('[portal] could not create patient row for account ' . $accountId . ': ' . $err);
    return ['patient_id' => null, 'error' => $err, 'reused' => false];
}

/**
 * PATCHes a patients row; if the new contact_number belongs to another patient,
 * saves everything else (the number stays on patient_accounts).
 */
function portal_patch_patient_row(int $patientId, array $patch): array
{
    $res = supabase_request('patients', 'PATCH', $patch, 'patient_id=eq.' . $patientId);
    if (isset($patch['contact_number']) && portal_is_unique_violation($res, 'contact_number')) {
        unset($patch['contact_number']);
        $res = $patch ? supabase_request('patients', 'PATCH', $patch, 'patient_id=eq.' . $patientId) : ['status' => 204];
    }
    return $res;
}
