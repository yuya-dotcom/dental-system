<?php
// =============================================================
//  controllers/portal_loaders.php — Patient Portal read endpoints as loaders
//
//  Each pl_*() generator returns the exact JSON payload its API action used to
//  echo, but yields its Supabase queries in dependency "waves" so independent
//  queries run in parallel (see portal_batch.php). They are used by
//    • api/portal_auth.php          — the single actions + 'bootstrap'
//    • api/portal_get_appointments.php
//    • api/portal_feedback.php      — action=status
//
//  Every query is scoped to the logged-in account / its patient_id, which the
//  callers take from the server session — never from the browser.
// =============================================================

require_once __DIR__ . '/portal_batch.php';

// ── Local (email) password vs. Google-only accounts ─────────────────────────
// Patient passwords live in patient_accounts.password_hash. An account created
// by "Sign in with Google" never had a password the patient knows, so it is
// stored as PORTAL_NO_PASSWORD_PREFIX + random hex — not a valid hash, so
// password_verify() always fails and email sign-in stays impossible, exactly as
// before. Registering with email, or setting one via Forgot Password
// (password_reset_controller.php), writes a real hash. So "has a password"
// comes from the stored credential itself, never from the sign-in method of
// the current session or the email domain. A patient who signed in with Google
// but also has a real password keeps Change Password.
if (!defined('PORTAL_NO_PASSWORD_PREFIX')) define('PORTAL_NO_PASSWORD_PREFIX', '!nopw:');

if (!function_exists('portal_has_local_password')) {
    function portal_has_local_password(?string $hash): bool
    {
        $hash = (string)$hash;
        if ($hash === '' || str_starts_with($hash, PORTAL_NO_PASSWORD_PREFIX)) return false;
        // Only a real password_hash() value counts (bcrypt / argon)
        return !empty(password_get_info($hash)['algo']);
    }
}

// Parent / guardian columns on patient_profiles (database/2026-10-17_patient_guardian_fields.sql)
if (!defined('GUARDIAN_COLS')) define('GUARDIAN_COLS', 'guardian_name,guardian_relation,guardian_phone,guardian_address');

/** Same rule as before: basics + address + questionnaire. */
if (!function_exists('computeProfileComplete')) {
    function computeProfileComplete(array $account, bool $hasQuestionnaire): bool
    {
        $hasBasic   = !empty($account['first_name'])
            && !empty($account['last_name'])
            && !empty($account['birthdate'])
            && !empty($account['gender']);

        $hasContact = !empty(trim($account['address'] ?? ''))
            && !empty(trim($account['city']    ?? ''));

        return $hasBasic && $hasContact && $hasQuestionnaire;
    }
}

// ── DASHBOARD SUMMARY (RPC; 60 s per-session cache as before) ──────────────────
function pl_dashboard_summary(int $accountId, $dashCache): Generator
{
    if (is_array($dashCache) && (time() - ($dashCache['ts'] ?? 0)) < 60) return $dashCache['data'];

    $r = yield ['rpc' => sbq('rpc/get_dashboard_summary', '', 'POST', ['p_account_id' => $accountId])];
    $response = $r['rpc']['data'] ?? ['success' => false, 'message' => 'Failed to load dashboard.'];

    portal_session_set(['dash_cache_' . $accountId => ['ts' => time(), 'data' => $response]]);
    return $response;
}

// ── CONTACT VERIFICATION STATUS (always fresh) ─────────────────────────────────
function pl_contact_status(int $accountId): Generator
{
    $r   = yield ['acc' => sbq('patient_accounts', 'account_id=eq.' . $accountId . '&select=is_verified,phone_verified&limit=1')];
    $res = $r['acc'];
    if (($res['status'] ?? 0) === 0 || $res['status'] >= 400 || empty($res['data'])) return ['success' => false];

    $email = !empty($res['data'][0]['is_verified']);
    $phone = !empty($res['data'][0]['phone_verified']);
    if ($email || $phone) portal_session_set(['portal_contact_ok' => true]);
    return ['success' => true, 'email' => $email, 'phone' => $phone];
}

// ── CHECK QUESTIONNAIRE ────────────────────────────────────────────────────────
function pl_check_questionnaire(int $accountId): Generator
{
    $r = yield ['mq' => sbq('medical_questionnaire', 'account_id=eq.' . $accountId . '&select=questionnaire_id&limit=1')];
    return ['success' => true, 'has_questionnaire' => !empty($r['mq']['data'])];
}

// ── GET PROFILE ────────────────────────────────────────────────────────────────
function pl_profile(int $accountId): Generator
{
    $patientId = yield from pl_patient_id($accountId);

    $profileCols = implode(',', [
        'civil_status', 'nationality', 'home_phone', 'address', 'barangay', 'city', 'province', 'zip',
        'chief_complaint', 'referral_source', 'referred_by', 'prev_dentist', 'last_dental_visit',
        'dental_notes', 'allergies', 'other_allergies', 'allergy_reaction', 'conditions', 'other_conditions',
    ]) . ',' . GUARDIAN_COLS;

    $w1 = [
        'acc' => sbq('patient_accounts', 'account_id=eq.' . $accountId .
            '&select=account_id,email,first_name,last_name,middle_name,suffix,' .
            'birthdate,contact_number,is_profile_complete,is_verified,phone_verified,patient_id,password_hash&limit=1'),
        'mq'  => sbq('medical_questionnaire', 'account_id=eq.' . $accountId . '&select=questionnaire_id&limit=1'),
    ];
    if ($patientId) {
        $w1['pat']  = sbq('patients', 'patient_id=eq.' . $patientId . '&select=gender,contact_number,birthdate&limit=1');
        $w1['prof'] = sbq('patient_profiles', 'patient_id=eq.' . $patientId . '&select=' . $profileCols . '&limit=1');
    }
    $r = yield $w1;

    if (empty($r['acc']['data'])) return ['success' => false, 'message' => 'Account not found.'];

    $account    = $r['acc']['data'][0];
    // Only a yes/no leaves the server — the hash itself is never sent
    $account['has_password'] = portal_has_local_password($account['password_hash'] ?? null);
    unset($account['password_hash']);
    $patientRow = $patientId ? ($r['pat']['data'][0] ?? []) : [];

    if (empty($account['contact_number']) && !empty($patientRow['contact_number']))
        $account['contact_number'] = $patientRow['contact_number'];
    if (empty($account['birthdate']) && !empty($patientRow['birthdate']))
        $account['birthdate'] = $patientRow['birthdate'];
    $account['gender'] = $patientRow['gender'] ?? '';

    $profileRow = [];
    if ($patientId) {
        $prRes = $r['prof'];
        // Guardian columns come from database/2026-10-17_patient_guardian_fields.sql;
        // until that migration is applied, load the profile without them.
        if (($prRes['status'] ?? 0) >= 400) {
            $r2    = yield ['prof' => sbq('patient_profiles', 'patient_id=eq.' . $patientId . '&select=*&limit=1')];
            $prRes = $r2['prof'];
        }
        $profileRow = $prRes['data'][0] ?? [];
    }

    $hasQuestionnaire = !empty($r['mq']['data']);
    $isComplete       = computeProfileComplete(array_merge($account, $profileRow), $hasQuestionnaire);
    $account['is_profile_complete'] = $isComplete;

    $storedComplete = (bool)($r['acc']['data'][0]['is_profile_complete'] ?? false);
    if ($isComplete !== $storedComplete) {
        yield ['fix' => sbq('patient_accounts', 'account_id=eq.' . $accountId, 'PATCH', ['is_profile_complete' => $isComplete])];
        portal_session_set(['portal_profile_complete' => $isComplete], ['dash_cache_' . $accountId]);
    }

    return [
        'success'  => true,
        'account'  => $account,
        'profile'  => $profileRow,
        // Sessions from before portal_auth_method existed: legacy password logins
        // get it set at the top of portal_auth.php, so a missing value means Google
        'auth_method' => $_SESSION['portal_auth_method'] ?? 'google',
        'has_questionnaire' => $hasQuestionnaire,   // for the My Profile completion checklist
    ];
}

// ── GET TREATMENTS ─────────────────────────────────────────────────────────────
function pl_treatments(int $accountId): Generator
{
    $patientId = yield from pl_patient_id($accountId);
    if (!$patientId) return ['success' => true, 'treatments' => []];

    $r = yield ['t' => sbq('treatments', 'patient_id=eq.' . $patientId .
        '&select=treatment_id,treatment_code,treatment_date,tooth_number,status,appointment_id,' .
        'branch_id,current_stage,cost,procedure_notes,' .
        'services(service_name),dentists(full_name)' .
        '&order=treatment_date.desc')];
    $rows = is_array($r['t']['data'] ?? null) ? $r['t']['data'] : [];

    $aptIds    = array_values(array_unique(array_filter(array_map(fn($x) => (int)($x['appointment_id'] ?? 0), $rows))));
    $branchIds = array_values(array_unique(array_filter(array_map(fn($x) => (int)($x['branch_id'] ?? 0), $rows))));
    $trtIds    = array_values(array_filter(array_map(fn($x) => (int)($x['treatment_id'] ?? 0), $rows)));

    // Linked appointments, branches and invoices — one parallel wave
    $w = [];
    if ($aptIds) $w['a'] = sbq('appointments', 'appointment_id=in.(' . implode(',', $aptIds) . ')' .
        '&patient_id=eq.' . $patientId . '&select=appointment_id,appointment_code,appointment_date,appointment_time');
    if ($branchIds) $w['b'] = sbq('branches', 'branch_id=in.(' . implode(',', $branchIds) . ')&select=branch_id,branch_name,address');
    if ($trtIds) $w['i'] = sbq('invoices', 'treatment_id=in.(' . implode(',', $trtIds) . ')' .
        '&patient_id=eq.' . $patientId .
        '&select=treatment_id,invoice_code,invoice_date,total_amount,amount_paid,balance,payment_status' .
        '&order=invoice_date.desc');
    $r2 = $w ? yield $w : [];

    $aptMap = [];
    foreach (($r2['a']['data'] ?? []) as $ap) $aptMap[(int)$ap['appointment_id']] = $ap;
    $branchMap = [];
    foreach (($r2['b']['data'] ?? []) as $br) $branchMap[(int)$br['branch_id']] = $br;
    $invMap = [];
    foreach (($r2['i']['data'] ?? []) as $inv) {
        $tid = (int)($inv['treatment_id'] ?? 0);
        if ($tid && !isset($invMap[$tid])) $invMap[$tid] = $inv;
    }

    $treatments = [];
    foreach ($rows as $row) {
        $aid = (int)($row['appointment_id'] ?? 0);
        $bid = (int)($row['branch_id'] ?? 0);
        $tid = (int)($row['treatment_id'] ?? 0);
        $apt = $aptMap[$aid]    ?? null;
        $br  = $branchMap[$bid] ?? null;

        $treatments[] = [
            'treatment_code'   => $row['treatment_code'],
            'treatment_date'   => $row['treatment_date'],
            'tooth_number'     => $row['tooth_number'],
            'status'           => $row['status'],
            'appointment_id'   => $row['appointment_id'] ?? null,
            'service_name'     => $row['services']['service_name'] ?? '—',
            'dentist_name'     => $row['dentists']['full_name']    ?? '—',
            // ── details for the View Treatment History modal ──
            'treatment_id'     => $tid ?: null,
            'current_stage'    => $row['current_stage']   ?? null,
            'cost'             => $row['cost']            ?? null,
            'procedure_notes'  => $row['procedure_notes'] ?? null,
            'branch_name'      => $br['branch_name']      ?? null,
            'branch_address'   => $br['address']          ?? null,
            'appointment_code' => $apt['appointment_code'] ?? null,
            'appointment_date' => $apt['appointment_date'] ?? null,
            'appointment_time' => $apt['appointment_time'] ?? null,
            'invoice'          => $invMap[$tid]           ?? null,
        ];
    }
    return ['success' => true, 'treatments' => $treatments];
}

// ── GET DENTAL CHART ───────────────────────────────────────────────────────────
function pl_dental_chart(int $accountId): Generator
{
    $patientId = yield from pl_patient_id($accountId);
    if (!$patientId) return ['success' => true, 'chart' => [], 'history' => []];

    // Chart rows and condition history do not depend on each other
    $r = yield [
        'c' => sbq('dental_chart', 'patient_id=eq.' . $patientId .
            '&select=chart_id,tooth_number,condition,notes,updated_at&order=updated_at.desc'),
        'h' => sbq('dental_chart_history', 'patient_id=eq.' . $patientId .
            '&select=tooth_number,surface,old_condition,new_condition,notes,changed_at,source' .
            '&order=changed_at.desc&limit=500'),
    ];

    // Keep only the newest row per tooth
    $chart = [];
    foreach (($r['c']['data'] ?? []) as $row) {
        $tooth = trim((string)($row['tooth_number'] ?? ''));
        if ($tooth === '' || isset($chart[$tooth])) continue;
        $row['tooth_number'] = $tooth;
        $row['surfaces']     = new stdClass();   // JSON {} even when empty
        $chart[$tooth]       = $row;
    }

    $chartIds = array_values(array_filter(array_map(fn($x) => (int)($x['chart_id'] ?? 0), $chart)));
    if ($chartIds) {
        $s = yield ['s' => sbq('dental_chart_surfaces', 'chart_id=in.(' . implode(',', $chartIds) . ')&select=chart_id,surface,condition,notes')];
        $byChart = [];
        foreach (($s['s']['data'] ?? []) as $x) {
            $byChart[(int)$x['chart_id']][strtoupper(trim($x['surface']))] = [
                'condition' => $x['condition'],
                'notes'     => $x['notes'],
            ];
        }
        foreach ($chart as $tooth => $row) {
            $cid = (int)$row['chart_id'];
            if (!empty($byChart[$cid])) $chart[$tooth]['surfaces'] = $byChart[$cid];
        }
    }

    return ['success' => true, 'chart' => array_values($chart), 'history' => $r['h']['data'] ?? []];
}

// ── GET BILLING ────────────────────────────────────────────────────────────────
function pl_billing(int $accountId): Generator
{
    $patientId = yield from pl_patient_id($accountId);
    if (!$patientId) return ['success' => true, 'invoices' => []];

    $r = yield ['inv' => sbq('invoices', 'patient_id=eq.' . $patientId .
        '&select=invoice_id,invoice_code,invoice_date,total_amount,amount_paid,balance,' .
        'payment_status,treatment_id,appointment_id,branch_id,treatments(treatment_code)' .
        '&order=invoice_date.desc')];
    $rows = is_array($r['inv']['data'] ?? null) ? $r['inv']['data'] : [];

    $ids = function (string $key) use ($rows): array {
        return array_values(array_unique(array_filter(array_map(fn($x) => (int)($x[$key] ?? 0), $rows))));
    };

    // Branches, treatments, visits and payments only need the invoice rows — one wave
    $w = [];
    if ($bIds = $ids('branch_id')) $w['b'] = sbq('branches', 'branch_id=in.(' . implode(',', $bIds) . ')&select=branch_id,branch_name,address,contact_number');
    if ($tIds = $ids('treatment_id')) $w['t'] = sbq('treatments', 'treatment_id=in.(' . implode(',', $tIds) . ')' .
        '&patient_id=eq.' . $patientId .
        '&select=treatment_id,treatment_code,treatment_date,tooth_number,procedure_notes,' .
        'services(service_name),dentists(full_name)');
    if ($aIds = $ids('appointment_id')) $w['a'] = sbq('appointments', 'appointment_id=in.(' . implode(',', $aIds) . ')' .
        '&patient_id=eq.' . $patientId .
        '&select=appointment_id,appointment_code,appointment_date,appointment_time,service_id');
    if ($iIds = $ids('invoice_id')) $w['p'] = sbq('payments', 'invoice_id=in.(' . implode(',', $iIds) . ')' .
        '&select=invoice_id,payment_id,payment_date,amount,payment_method,recorded_by,notes' .
        '&order=payment_date.asc,payment_id.asc');
    $r2 = $w ? yield $w : [];

    $branchMap = [];
    foreach (($r2['b']['data'] ?? []) as $br) $branchMap[(int)$br['branch_id']] = $br;
    $trtMap = [];
    foreach (($r2['t']['data'] ?? []) as $t) $trtMap[(int)$t['treatment_id']] = $t;
    $aptMap = [];
    foreach (($r2['a']['data'] ?? []) as $ap) $aptMap[(int)$ap['appointment_id']] = $ap;
    $payMap = [];
    foreach (($r2['p']['data'] ?? []) as $p) {
        $payMap[(int)$p['invoice_id']][] = [
            'payment_date'   => $p['payment_date']   ?? null,
            'amount'         => $p['amount']         ?? 0,
            'payment_method' => $p['payment_method'] ?? null,
            'recorded_by'    => $p['recorded_by']    ?? null,
            'notes'          => $p['notes']          ?? null,
        ];
    }

    // Booked services, for invoices without a treatment
    $svcMap  = [];
    $needSvc = array_values(array_unique(array_filter(array_map(
        fn($x) => empty($trtMap[(int)($x['treatment_id'] ?? 0)]) ? (int)($x['appointment_id'] ?? 0) : 0,
        $rows
    ))));
    if ($needSvc) {
        $s = yield ['s' => sbq('appointment_services', 'appointment_id=in.(' . implode(',', $needSvc) . ')' .
            '&select=appointment_id,sort_order,services(service_name)&order=sort_order.asc')];
        foreach (($s['s']['data'] ?? []) as $sv) {
            $name = $sv['services']['service_name'] ?? '';
            if ($name !== '') $svcMap[(int)$sv['appointment_id']][] = $name;
        }
        // Older bookings keep a single service on the appointment itself
        $single = [];
        foreach ($needSvc as $aid) {
            $sid = (int)($aptMap[$aid]['service_id'] ?? 0);
            if (empty($svcMap[$aid]) && $sid) $single[$aid] = $sid;
        }
        if ($single) {
            $s2 = yield ['s2' => sbq('services', 'service_id=in.(' . implode(',', array_unique($single)) . ')&select=service_id,service_name')];
            $names = [];
            foreach (($s2['s2']['data'] ?? []) as $sv) $names[(int)$sv['service_id']] = $sv['service_name'];
            foreach ($single as $aid => $sid) {
                if (!empty($names[$sid])) $svcMap[$aid] = [$names[$sid]];
            }
        }
    }

    $invoices = [];
    foreach ($rows as $row) {
        $iid = (int)$row['invoice_id'];
        $trt = $trtMap[(int)($row['treatment_id'] ?? 0)]   ?? null;
        $apt = $aptMap[(int)($row['appointment_id'] ?? 0)] ?? null;
        $br  = $branchMap[(int)($row['branch_id'] ?? 0)]   ?? null;

        $services = [];
        if (!empty($trt['services']['service_name'])) $services[] = $trt['services']['service_name'];
        elseif ($apt) $services = $svcMap[(int)$apt['appointment_id']] ?? [];

        $invoices[] = [
            'invoice_id'     => $row['invoice_id'],
            'invoice_code'   => $row['invoice_code'],
            'invoice_date'   => $row['invoice_date'],
            'total_amount'   => $row['total_amount'],
            'amount_paid'    => $row['amount_paid'],
            'payment_status' => $row['payment_status'],
            'treatment_code' => $row['treatments']['treatment_code'] ?? '—',
            // ── details for the View Details modal ──
            'balance'          => $row['balance'] ?? null,
            'branch_name'      => $br['branch_name']    ?? null,
            'branch_address'   => $br['address']        ?? null,
            'branch_contact'   => $br['contact_number'] ?? null,
            'appointment_code' => $apt['appointment_code'] ?? null,
            'appointment_date' => $apt['appointment_date'] ?? null,
            'appointment_time' => $apt['appointment_time'] ?? null,
            'service_names'    => $services,
            'treatment_date'   => $trt['treatment_date']  ?? null,
            'tooth_number'     => $trt['tooth_number']    ?? null,
            'dentist_name'     => $trt['dentists']['full_name'] ?? null,
            'procedure_notes'  => $trt['procedure_notes'] ?? null,
            'payments'         => $payMap[$iid] ?? [],
        ];
    }
    return ['success' => true, 'invoices' => $invoices];
}

// ── MAKE A PAYMENT: QUOTE (read-only) ──────────────────────────────────────────
// Billing & Payments → Make a Payment. The browser sends the invoice ids, the
// chosen e-wallet and the amount the patient wants to pay. The outstanding
// balance is worked out here from the invoices table (never taken from the
// browser) and the amount is checked against it: more than ₱0, at most the
// selected balance, two decimals at most. A partial amount is applied to the
// oldest selected invoice first (portal_allocate_payment).
// Nothing is written: no payment row, no invoice change. No payment gateway is
// connected yet, so the reply always says gateway_available:false — balances
// may only change once a gateway confirms a payment.
const PORTAL_EWALLET_METHODS = ['gcash' => 'GCash', 'maya' => 'Maya', 'grabpay' => 'GrabPay'];
const PORTAL_EWALLET_MAX_INVOICES = 50;

/** Outstanding amount of one invoice row; 0 when it can't be paid online. */
function portal_invoice_outstanding(array $row): float
{
    $status = strtolower((string)($row['payment_status'] ?? '')) ?: 'unpaid';
    if (!in_array($status, ['unpaid', 'partial'], true)) return 0.0; // paid, cancelled, void…
    return round(max(0, (float)($row['total_amount'] ?? 0) - (float)($row['amount_paid'] ?? 0)), 2);
}

/** A peso amount typed by the patient → centavos, or null when it isn't a valid amount. */
function portal_amount_cents($v): ?int
{
    if (is_int($v)) return $v >= 0 && $v < 10000000 ? $v * 100 : null;
    if (is_float($v)) {
        if (!is_finite($v) || $v < 0 || $v >= 10000000) return null;
        $c = round($v * 100);
        return abs($c - $v * 100) < 1e-6 ? (int)$c : null;
    }
    if (!is_string($v)) return null;
    $v = str_replace(',', '', trim($v));
    if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $v)) return null;
    $parts = explode('.', $v);
    return (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
}

/**
 * Splits a payment over invoices, oldest invoice first (by invoice_date, then id).
 * $lines: [['invoice_id','invoice_date','due_cents'], …]. Returns the lines with
 * pay_cents and remaining_cents. Pure: used for the quote now and meant for the
 * step that records a gateway-confirmed payment later.
 */
function portal_allocate_payment(array $lines, int $payCents): array
{
    usort($lines, fn($a, $b) => [(string)$a['invoice_date'], $a['invoice_id']] <=> [(string)$b['invoice_date'], $b['invoice_id']]);
    $left = $payCents;
    foreach ($lines as &$l) {
        $l['pay_cents'] = min($l['due_cents'], max(0, $left));
        $l['remaining_cents'] = $l['due_cents'] - $l['pay_cents'];
        $left -= $l['pay_cents'];
    }
    unset($l);
    return $lines;
}

function pl_ewallet_quote(int $accountId, $invoiceIds, $method, $amount = null): Generator
{
    $fail = fn(string $code, string $msg) => ['success' => false, 'code' => $code, 'message' => $msg];
    $peso = fn(int $c) => '₱' . number_format($c / 100, 2);

    $method = strtolower(trim((string)$method));
    if (!isset(PORTAL_EWALLET_METHODS[$method])) {
        return $fail('INVALID_METHOD', 'Please choose GCash, Maya or GrabPay.');
    }
    $ids = [];
    foreach ((is_array($invoiceIds) ? $invoiceIds : []) as $v) {
        if (is_int($v) || (is_string($v) && ctype_digit($v))) {
            if ((int)$v > 0) $ids[(int)$v] = true;   // keys → duplicates collapse
        }
    }
    $ids = array_keys($ids);
    if (!$ids) return $fail('NO_INVOICES', 'Please select at least one invoice to pay.');
    if (count($ids) > PORTAL_EWALLET_MAX_INVOICES) return $fail('TOO_MANY', 'Too many invoices were selected.');

    $payCents = portal_amount_cents($amount);
    if ($payCents === null) return $fail('INVALID_AMOUNT', 'Please enter a valid amount, for example 500 or 500.50.');
    if ($payCents <= 0) return $fail('INVALID_AMOUNT', 'Please enter an amount greater than ₱0.00.');

    $patientId = yield from pl_patient_id($accountId);
    if (!$patientId) return $fail('INVALID_INVOICE', 'Some of the selected invoices could not be found.');

    $r = yield ['inv' => sbq('invoices', 'patient_id=eq.' . $patientId .
        '&invoice_id=in.(' . implode(',', $ids) . ')' .
        '&select=invoice_id,invoice_date,total_amount,amount_paid,payment_status')];
    if (($r['inv']['status'] ?? 500) >= 400 || !is_array($r['inv']['data'] ?? null)) {
        return $fail('UNAVAILABLE', "We couldn't check your invoices right now. Please try again.");
    }
    $rows = [];
    foreach ($r['inv']['data'] as $row) $rows[(int)$row['invoice_id']] = $row;

    $dueCents = 0;
    $lines = [];
    foreach ($ids as $id) {
        // Another patient's invoice (or a made-up id) is simply not returned above
        if (!isset($rows[$id])) return $fail('INVALID_INVOICE', 'Some of the selected invoices could not be found.');
        $due = (int)round(portal_invoice_outstanding($rows[$id]) * 100);
        if ($due <= 0) {
            return $fail('NOT_PAYABLE', 'One of the selected invoices no longer has a balance. Your billing list has been refreshed.');
        }
        $lines[] = ['invoice_id' => $id, 'invoice_date' => $rows[$id]['invoice_date'] ?? '', 'due_cents' => $due];
        $dueCents += $due;
    }
    if ($payCents > $dueCents) {
        return $fail('AMOUNT_TOO_HIGH', "The amount can't be more than the selected balance of " . $peso($dueCents) . '.');
    }

    $label = PORTAL_EWALLET_METHODS[$method];
    $alloc = portal_allocate_payment($lines, $payCents);
    return [
        'success'           => true,
        'gateway_available' => false,
        'method'            => $method,
        'method_label'      => $label,
        'amount'            => $payCents / 100,                 // what the patient would pay
        'outstanding'       => $dueCents / 100,                 // selected balance, from the DB
        'remaining'         => ($dueCents - $payCents) / 100,   // after a confirmed payment
        'is_partial'        => $payCents < $dueCents,
        'invoice_count'     => count($lines),
        'invoices'          => array_map(fn($l) => [
            'invoice_id' => $l['invoice_id'],
            'amount_due' => $l['due_cents'] / 100,
            'pay'        => $l['pay_cents'] / 100,
            'remaining'  => $l['remaining_cents'] / 100,
        ], $alloc),
        'message'           => "Paying with {$label} isn't available yet. Nothing has been charged and your balance hasn't changed.",
    ];
}

// ── GET FILES ──────────────────────────────────────────────────────────────────
function pl_files(int $accountId): Generator
{
    $patientId = yield from pl_patient_id($accountId);
    if (!$patientId) return ['success' => true, 'files' => []];

    $r = yield ['f' => sbq('patient_files', 'patient_id=eq.' . $patientId .
        '&select=file_id,file_type,file_name,file_path,uploaded_by,uploaded_at&order=uploaded_at.desc')];
    return ['success' => true, 'files' => $r['f']['data'] ?? []];
}

// ── GET WAIVERS ────────────────────────────────────────────────────────────────
function pl_waivers(int $accountId): Generator
{
    $patientId = yield from pl_patient_id($accountId);
    if (!$patientId) return ['success' => true, 'waivers' => []];

    $r = yield ['w' => sbq('waivers', 'patient_id=eq.' . $patientId .
        '&order=waiver_id.desc&select=waiver_id,waiver_type,file_path,status,signed_at,signed_by')];
    return ['success' => true, 'waivers' => is_array($r['w']['data'] ?? null) ? $r['w']['data'] : []];
}

// ── FEEDBACK STATUS (same rules as feedbackSubmittedMap()) ─────────────────────
function pl_feedback_status(int $accountId): Generator
{
    $patientId = yield from pl_patient_id($accountId);
    if (!$patientId) return ['success' => true, 'submitted' => (object)[]];

    $r   = yield ['a' => sbq('appointments', 'patient_id=eq.' . $patientId . '&select=appointment_id')];
    $ids = array_values(array_unique(array_filter(array_map('intval', array_column($r['a']['data'] ?? [], 'appointment_id')))));
    if (!$ids) return ['success' => true, 'submitted' => (object)[]];

    $f = (yield ['f' => sbq('patient_feedback', 'appointment_id=in.(' . implode(',', $ids) . ')&select=appointment_id,category,created_at')])['f'];
    if ($f['error'] || !is_array($f['data'])) {
        return ['success' => false, 'code' => 'unavailable', 'message' => 'Feedback is not available right now.'];
    }
    $map = [];
    foreach ($f['data'] as $x) $map[(int)$x['appointment_id']] = ['category' => $x['category'], 'created_at' => $x['created_at']];
    return ['success' => true, 'submitted' => (object)$map];
}

// ── APPOINTMENTS (formerly inline in api/portal_get_appointments.php) ─────────
function pl_appointments(int $accountId, string $status = ''): Generator
{
    $patientId = yield from pl_patient_id($accountId);

    // One source of truth: the appointments of THIS account's patient — booked in
    // the portal or made by staff (Add Appointment) — and nobody else's. Before a
    // patient record is linked, only the account's own bookings exist.
    $owner = $patientId ? 'patient_id=eq.' . (int)$patientId : 'account_id=eq.' . $accountId;
    $query = $owner
        . '&select=appointment_id,appointment_code,appointment_date,appointment_time,'
        . 'appointment_type,status,payment_status,notes,service_id,created_at,updated_at,'
        . 'branches(branch_name,address),'
        . 'services(service_name,base_price),'
        . 'dentists(full_name)'
        . '&order=appointment_date.desc,appointment_time.desc'
        . '&limit=200';
    if ($status !== '' && $status !== 'all') $query .= '&status=eq.' . urlencode($status);

    // The list and the patient's own details (for the details modal) in one wave
    $w1 = [
        'apts' => sbq('appointments', $query),
        'acc'  => sbq('patient_accounts', 'account_id=eq.' . $accountId
            . '&select=email,first_name,middle_name,last_name,suffix,birthdate,contact_number,patient_id&limit=1'),
    ];
    if ($patientId) {
        $w1['pat'] = sbq('patients', 'patient_id=eq.' . $patientId . '&select=patient_code,full_name,birthdate,contact_number&limit=1');
    }
    $r = yield $w1;

    $rows = is_array($r['apts']['data'] ?? null) ? $r['apts']['data'] : [];
    if (empty($rows)) return ['success' => true, 'appointments' => [], 'patient' => null];

    $aptIds = array_values(array_filter(array_map(fn($x) => (int)($x['appointment_id'] ?? 0), $rows)));
    $idList = implode(',', $aptIds);

    // Services from the junction table + latest invoice per appointment
    $r2 = yield [
        'svc' => sbq('appointment_services', 'appointment_id=in.(' . $idList . ')'
            . '&select=appointment_id,sort_order,services(service_id,service_name,base_price)'
            . '&order=appointment_id.asc,sort_order.asc'),
        'inv' => sbq('invoices', 'appointment_id=in.(' . $idList . ')'
            . '&select=invoice_id,appointment_id,invoice_code,invoice_date,'
            . 'total_amount,amount_paid,balance,payment_status'
            . '&order=invoice_date.desc,invoice_id.desc'),
    ];

    $svcMap = $svcTotal = [];
    foreach (($r2['svc']['data'] ?? []) as $row) {
        $aid  = (int)$row['appointment_id'];
        $name = $row['services']['service_name'] ?? null;
        if ($name) {
            $svcMap[$aid][] = $name;
            $svcTotal[$aid] = ($svcTotal[$aid] ?? 0) + (float)($row['services']['base_price'] ?? 0);
        }
    }

    $invMap = [];
    foreach (($r2['inv']['data'] ?? []) as $inv) {
        $aid = (int)($inv['appointment_id'] ?? 0);
        if ($aid && !isset($invMap[$aid])) {
            $inv['payment_method'] = null;
            $invMap[$aid] = $inv;
        }
    }

    // Latest payment method per invoice
    if ($invMap) {
        $invIds = array_map(fn($i) => (int)$i['invoice_id'], array_values($invMap));
        $p = yield ['pay' => sbq('payments', 'invoice_id=in.(' . implode(',', $invIds) . ')'
            . '&select=invoice_id,payment_method,payment_date&order=payment_date.desc,payment_id.desc')];
        $methodByInvoice = [];
        foreach (($p['pay']['data'] ?? []) as $x) {
            $iid = (int)($x['invoice_id'] ?? 0);
            if ($iid && !isset($methodByInvoice[$iid]) && !empty($x['payment_method'])) $methodByInvoice[$iid] = $x['payment_method'];
        }
        foreach ($invMap as $aid => $inv) $invMap[$aid]['payment_method'] = $methodByInvoice[(int)$inv['invoice_id']] ?? null;
    }

    foreach ($rows as &$apt) {
        $aid = (int)($apt['appointment_id'] ?? 0);
        // Prefer junction data; fall back to legacy service_id join
        if (!empty($svcMap[$aid])) {
            $apt['service_names']  = $svcMap[$aid];
            $apt['services_total'] = $svcTotal[$aid] ?? 0;
        } elseif (!empty($apt['services']['service_name'])) {
            $apt['service_names']  = [$apt['services']['service_name']];
            $apt['services_total'] = (float)($apt['services']['base_price'] ?? 0);
        } else {
            $apt['service_names']  = [];
            $apt['services_total'] = 0;
        }
        $apt['invoice'] = $invMap[$aid] ?? null;
    }
    unset($apt);

    // Logged-in patient's details (for the details modal)
    $patient = null;
    $acc     = $r['acc']['data'][0] ?? null;
    if ($acc) {
        // Same patient as before: the one linked to this account
        $pat = (!empty($acc['patient_id']) && (int)$acc['patient_id'] === (int)$patientId) ? ($r['pat']['data'][0] ?? []) : [];

        $contact = !empty($acc['contact_number']) ? $acc['contact_number'] : ($pat['contact_number'] ?? null);
        if ($contact && preg_match('/^(__PORTAL_\d+__|PORTAL-\d+)$/', $contact)) $contact = null; // placeholder

        $name = trim(implode(' ', array_filter([
            $acc['first_name']  ?? '',
            $acc['middle_name'] ?? '',
            $acc['last_name']   ?? '',
            $acc['suffix']      ?? '',
        ])));

        $patient = [
            'full_name'      => $name !== '' ? $name : ($pat['full_name'] ?? null),
            'patient_code'   => $pat['patient_code'] ?? null,
            'birthdate'      => !empty($acc['birthdate']) ? $acc['birthdate'] : ($pat['birthdate'] ?? null),
            'contact_number' => $contact,
            'email'          => $acc['email'] ?? null,
        ];
    }

    return ['success' => true, 'appointments' => $rows, 'patient' => $patient];
}
