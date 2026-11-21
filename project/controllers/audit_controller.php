<?php
// =============================================================
//  controllers/audit_controller.php
//  Universal Audit Logging — EssenciaSmile
//
//  HOW TO USE IN ANY API FILE:
//    require_once __DIR__ . '/../controllers/audit_controller.php';
//
//    logActivity(
//        module:       'appointment-schedules',
//        action:       'UPDATE',
//        entity_id:    $appointmentId,
//        entity_name:  $patientName,
//        description:  "Status changed from Pending to Confirmed"
//    );
//
//  The function automatically reads the logged-in user's data
//  from the session — you never pass user/role/branch manually.
//
//  MODULE NAMES (use these consistently across all files):
//    'appointment-schedules'   appointments-schedule.php
//    'appointment-records'     appointments-records.php
//    'patients'                patients-list.php / patient-records.php
//    'treatments'              active-treatments / treatment-records
//    'billing'                 billing-records.php
//    'inventory'               inventory-records.php
//    'accounts'                accounts-records.php
//    'branches'                branches-records.php
//    'dentists'                dentist-records.php
//    'services'                service-pricing.php
//
//  ACTION VALUES (always uppercase):
//    'INSERT'   — A new record was created
//    'UPDATE'   — An existing record was modified
//    'DELETE'   — A record was permanently removed
// =============================================================

require_once __DIR__ . '/../dbconfig.php';

/**
 * Log any system action to the audit_logs table.
 *
 * All user/session data is read automatically from $_SESSION.
 * This function silently fails on error — it NEVER breaks the
 * calling operation; logging is always secondary.
 *
 * @param string $module      Slug of the feature area (e.g. 'patients')
 * @param string $action      'INSERT' | 'UPDATE' | 'DELETE'
 * @param mixed  $entity_id   The primary key of the affected record
 * @param string $entity_name Human-readable name of the affected record
 * @param string $description Optional detail about what changed
 * @param ?int    $branch_id   Optional: the record's branch (default: the user's session branch)
 * @param ?string $branch_name Optional: name for $branch_id
 */
function logActivity(
    string $module,
    string $action,
    $entity_id,
    string $entity_name,
    string $description = '',
    ?int $branch_id = null,
    ?string $branch_name = null,
    ?array $actor = null,     // Optional: ['username' => ..., 'role' => ...] for non-staff actors (patient portal)
    ?array $before_data = null, // Optional: changed fields' old values (jsonb before_data)
    ?array $after_data  = null  // Optional: changed fields' new values (jsonb after_data)
): void {
    // Ensure session is running — safe to call even if already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // ── Pull user data from session ──────────────────────────
    // full_name is the person's real name (e.g. "Maria Santos")
    // role is their system role ('owner', 'admin', 'dentist')
    $userId     = $_SESSION['user_id']     ?? null;
    $username   = $_SESSION['full_name']   ?? ($_SESSION['username'] ?? 'System');
    $role       = $_SESSION['role']        ?? 'unknown';
    $branchId   = $_SESSION['branch_id']   ?? null;
    $branchName = $_SESSION['branch_name'] ?? null;

    // Patients have no staff user row: record who they are, never a staff id
    if ($actor) {
        $userId   = null;
        $username = (string)($actor['username'] ?? 'Patient');
        $role     = (string)($actor['role'] ?? 'patient');
    }

    // Owners span all branches — their branch_id in users table
    // may be NULL. We display 'All Branches' for them.
    // A record-specific branch (e.g. the invoice's branch) wins over the session's
    if ($branch_id) {
        $branchId   = $branch_id;
        $branchName = $branch_name;
    }

    if ($role === 'owner' && empty($branchName)) {
        $branchName = 'All Branches';
    }

    // ── Build payload ────────────────────────────────────────
    $payload = [
        'user_id'     => $userId,
        'username'    => $username,
        'role'        => $role,
        'branch_id'   => $branchId   ? (int)$branchId   : null,
        'branch_name' => $branchName ? (string)$branchName : null,
        'module'      => strtolower(trim($module)),
        'action'      => strtoupper(trim($action)),      // Always INSERT/UPDATE/DELETE
        'entity_id'   => (string)$entity_id,
        'entity_name' => trim($entity_name),
        'description' => trim($description),
        'created_at'  => date('c'),                      // ISO 8601 timestamp
    ];
    if ($before_data !== null) $payload['before_data'] = $before_data;
    if ($after_data  !== null) $payload['after_data']  = $after_data;

    // ── Insert into Supabase (fire-and-forget) ───────────────
    // We intentionally do NOT check the result — a failed log
    // must never block the actual user operation.
    try {
        // supabase_request reports failures in 'status' (it doesn't throw)
        $r = supabase_request('audit_logs', 'POST', $payload);
        if (($r['status'] ?? 0) < 200 || ($r['status'] ?? 0) >= 300) {
            error_log('[AuditController] audit_logs insert failed (' . ($r['status'] ?? 0) . '): ' . ($r['error'] ?? 'unknown'));
        }
    } catch (\Throwable $e) {
        // Log to server error log but do not surface to user
        error_log('[AuditController] logActivity failed: ' . $e->getMessage());
    }
}

// ── Login attempts ───────────────────────────────────────────
// Module 'authentication'. One row per staff sign-in attempt (login.php),
// successful or not. Stored in audit_logs — no schema change:
//   user_id / role / branch_*  the account, when the username matched one
//   username                    the account's name, or the typed username
//   entity_name                 exactly what was typed as the username
//   action                      LOGIN_SUCCESS | LOGIN_FAILED
//   after_data (jsonb)          {status, reason, reason_text, ip, user_agent}
// NEVER stored: the password (typed or hashed), session ids or tokens.

const LOGIN_AUDIT_MODULE = 'authentication';

/** Client IP as seen by the web server (REMOTE_ADDR only — proxy headers can be forged). */
function auditClientIp(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

/** Printable, length-limited copy of user-supplied text for the log. */
function auditCleanText(string $s, int $max): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

/**
 * Record one staff login attempt. Never throws, never blocks the login.
 *   $ok          true = signed in
 *   $identifier  the username typed in the form
 *   $user        the matched users row (user_id, full_name, role, branch_id) or null
 *   $reason      machine code: success | missing_credentials | unknown_account |
 *                inactive_account | incorrect_password | role_not_permitted | lookup_failed
 *   $reasonText  human-readable reason (shown in the Audit Trail)
 *   $branchName  the account's branch name when already known
 */
function logLoginAttempt(bool $ok, string $identifier, ?array $user, string $reason, string $reasonText, ?string $branchName = null): void
{
    try {
        $typed    = auditCleanText($identifier, 120);
        $role     = $user ? (string)($user['role'] ?? '') : '';
        $branchId = $user && !empty($user['branch_id']) ? (int)$user['branch_id'] : null;
        if ($user && $branchId && $branchName === null) {
            $b = supabase_request('branches', 'GET', [], 'branch_id=eq.' . $branchId . '&select=branch_name&limit=1');
            $branchName = $b['data'][0]['branch_name'] ?? null;
        }
        if ($role === 'owner' && !$branchName) $branchName = 'All Branches';

        $payload = [
            'user_id'     => $user ? (int)$user['user_id'] : null,
            'username'    => $user ? (string)($user['full_name'] ?? $typed) : ($typed !== '' ? $typed : '(blank)'),
            'role'        => $role !== '' ? $role : 'unknown',
            'branch_id'   => $branchId,
            'branch_name' => $branchName,
            'module'      => LOGIN_AUDIT_MODULE,
            'action'      => $ok ? 'LOGIN_SUCCESS' : 'LOGIN_FAILED',
            'entity_id'   => $user ? (string)$user['user_id'] : '',
            'entity_name' => $typed !== '' ? $typed : '(blank)',
            'description' => ($ok ? 'Successful login' : 'Failed login — ' . $reasonText),
            'after_data'  => [
                'status'      => $ok ? 'Successful' : 'Failed',
                'reason'      => $reason,
                'reason_text' => $reasonText,
                'ip'          => auditClientIp(),
                'user_agent'  => auditCleanText((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 200),
            ],
            'created_at'  => date('c'),
        ];

        $r = supabase_request('audit_logs', 'POST', $payload);
        $st = (int)($r['status'] ?? 0);
        // A CHECK constraint on audit_logs.action (if the live table has one) would
        // reject the new action names: keep the record with a permitted action —
        // the Login Attempts view filters on module + after_data.status, not action.
        if ($st === 400 && preg_match('/23514|check constraint/i', (string)($r['error'] ?? json_encode($r['data'] ?? '')))) {
            $payload['action'] = 'INSERT';
            $r  = supabase_request('audit_logs', 'POST', $payload);
            $st = (int)($r['status'] ?? 0);
        }
        if ($st < 200 || $st >= 300) error_log('[AuditController] login attempt insert failed (' . $st . '): ' . ($r['error'] ?? 'unknown'));
    } catch (\Throwable $e) {
        error_log('[AuditController] logLoginAttempt failed: ' . $e->getMessage());
    }
}

/**
 * Convenience wrapper: fetch a patient's full name by ID.
 * Used by API endpoints that only receive patient_id and need
 * the name for the audit entity_name field.
 *
 * Returns 'Unknown Patient' on failure so logging always works.
 */
function getPatientNameForAudit(int $patientId): string
{
    if ($patientId < 1) return 'Unknown Patient';

    $r = supabase_request(
        'patients', 'GET', [],
        'patient_id=eq.' . $patientId . '&select=full_name&limit=1'
    );

    return $r['data'][0]['full_name'] ?? 'Unknown Patient';
}

/**
 * Convenience wrapper: fetch an appointment's patient name + old status.
 * Used by update_appointment_status.php to build a rich log description.
 *
 * Returns ['patient_name' => string, 'old_status' => string]
 */
// Guarded: appointment_controller.php has a fallback copy, so include order never matters
if (!function_exists('getAppointmentInfoForAudit')) {
    function getAppointmentInfoForAudit(int $appointmentId): array
    {
        $r = supabase_request(
            'appointments', 'GET', [],
            'appointment_id=eq.' . $appointmentId .
            '&select=status,patients(full_name)&limit=1'
        );

        $row = $r['data'][0] ?? [];

        return [
            'patient_name' => $row['patients']['full_name'] ?? 'Unknown Patient',
            'old_status'   => $row['status']                ?? 'unknown',
        ];
    }
}
// ── Billing ──────────────────────────────────────────────────
// Module 'billing' (billing-records.php). Descriptions carry the before → after
// amounts; failed writes are logged with a "FAILED —" prefix (the audit_logs
// table has no separate outcome / old-new columns).

/**
 * Invoice snapshot for billing audit entries (code, patient, branch, amounts).
 * Returns [] when the invoice can't be read — logging still works.
 */
function getInvoiceInfoForAudit(int $invoiceId): array
{
    if ($invoiceId < 1) return [];
    $r = supabase_request('invoices', 'GET', [],
        'invoice_id=eq.' . $invoiceId .
        '&select=invoice_id,invoice_code,total_amount,amount_paid,balance,payment_status,branch_id,' .
        'patients(full_name),branches(branch_name)&limit=1');
    return $r['data'][0] ?? [];
}

/** "₱1,300.00" for audit descriptions. */
function auditPeso($n): string
{
    return '₱' . number_format((float)$n, 2);
}

/**
 * Log a billing event against an invoice. $info comes from
 * getInvoiceInfoForAudit() (read it BEFORE a delete).
 */
function logBillingActivity(string $action, int $invoiceId, array $info, string $description): void
{
    $code    = $info['invoice_code'] ?? ('Invoice #' . $invoiceId);
    $patient = $info['patients']['full_name'] ?? '';
    logActivity(
        'billing', $action, $invoiceId,
        $patient !== '' ? $code . ' · ' . $patient : $code,
        $description,
        !empty($info['branch_id']) ? (int)$info['branch_id'] : null,
        $info['branches']['branch_name'] ?? null
    );
}
