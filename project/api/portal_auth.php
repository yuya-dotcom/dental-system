<?php
// api/portal_auth.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../dbconfig.php';

$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// ── REGISTER ──────────────────────────────────────────────────────────────────
if ($action === 'register') {
    $email    = trim($input['email']    ?? '');
    $password =      $input['password'] ?? '';

    if (!$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'Required fields missing.']);
        exit;
    }

    $check = supabase_request(
        'patient_accounts',
        'GET',
        [],
        'email=eq.' . urlencode($email) . '&select=account_id'
    );
    if (!empty($check['data'])) {
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit;
    }

    $result = supabase_request('patient_accounts', 'POST', [
        'email'               => $email,
        'password_hash'       => password_hash($password, PASSWORD_DEFAULT),
        'first_name'          => $input['first_name']  ?? '',
        'last_name'           => $input['last_name']   ?? '',
        'middle_name'         => $input['middle_name'] ?? null,
        'suffix'              => $input['suffix']      ?? null,
        'birthdate'           => $input['birthdate']   ?? null,
        'is_verified'         => true,
        'is_profile_complete' => false,
    ]);

    if ($result['status'] === 201 && !empty($result['data'][0]['account_id'])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Registration failed.']);
    }
    exit;
}

// ── LOGIN ─────────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $email    = trim($input['email']    ?? '');
    $password =      $input['password'] ?? '';

    $result = supabase_request(
        'patient_accounts',
        'GET',
        [],
        'email=eq.' . urlencode($email) .
            '&select=account_id,password_hash,first_name,last_name,is_profile_complete'
    );

    if (empty($result['data'])) {
        echo json_encode(['success' => false, 'message' => 'No account found with this email.']);
        exit;
    }

    $acc = $result['data'][0];

    if (!password_verify($password, $acc['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
        exit;
    }

    $_SESSION['portal_account_id']       = $acc['account_id'];
    $_SESSION['portal_name']             = $acc['first_name'];
    $_SESSION['portal_profile_complete'] = $acc['is_profile_complete'];

    echo json_encode(['success' => true]);
    exit;
}

// ── LOGOUT ────────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

// ── AUTH GUARD — all actions below require a valid session ────────────────────
$accountId = $_SESSION['portal_account_id'] ?? null;
if (!$accountId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// ── HELPER: compute is_profile_complete from live data ───────────────────────
function computeProfileComplete(array $account, array $profileRow): bool
{
    $hasBasic   = !empty($account['first_name'])
               && !empty($account['last_name'])
               && !empty($account['birthdate'])
               && !empty($account['gender']);

    $hasContact = !empty(trim($profileRow['address'] ?? ''))
               && !empty(trim($profileRow['city']    ?? ''));

    $hasMedical = array_key_exists('q_good_health',    $profileRow) && $profileRow['q_good_health']    !== null
               && array_key_exists('q_tobacco',         $profileRow) && $profileRow['q_tobacco']         !== null
               && array_key_exists('q_dangerous_drugs', $profileRow) && $profileRow['q_dangerous_drugs'] !== null;

    return $hasBasic && $hasContact && $hasMedical;
}

// ── DASHBOARD SUMMARY ─────────────────────────────────────────────────────────
if ($action === 'dashboard_summary') {

    $accResult = supabase_request(
        'patient_accounts',
        'GET',
        [],
        'account_id=eq.' . $accountId .
            '&select=account_id,email,first_name,last_name,middle_name,suffix,' .
            'birthdate,contact_number,is_profile_complete,patient_id,' .
            'patients(gender,contact_number,full_name)' .
            '&limit=1'
    );

    if (empty($accResult['data'])) {
        echo json_encode(['success' => false, 'message' => 'Account not found.']);
        exit;
    }

    $account       = $accResult['data'][0];
    $patientId     = $account['patient_id'] ?? null;
    $linkedPatient = $account['patients']   ?? [];

    if (empty($account['contact_number']) && !empty($linkedPatient['contact_number'])) {
        $account['contact_number'] = $linkedPatient['contact_number'];
    }
    if (empty($account['first_name']) && !empty($linkedPatient['full_name'])) {
        $parts = explode(' ', $linkedPatient['full_name'], 2);
        $account['first_name'] = $parts[0] ?? '';
        $account['last_name']  = $parts[1] ?? '';
    }
    if (empty($account['gender'])) {
        $account['gender'] = $linkedPatient['gender'] ?? '';
    }
    unset($account['patients']);

    $stats = ['upcoming' => 0, 'completed' => 0, 'balance' => 0.0, 'treatments' => 0];

    if ($patientId) {
        $apptRes = supabase_request('appointments', 'GET', [],
            'patient_id=eq.' . $patientId . '&select=status');
        foreach (($apptRes['data'] ?? []) as $row) {
            if (in_array($row['status'], ['pending', 'confirmed', 'checked_in'])) $stats['upcoming']++;
            if ($row['status'] === 'completed')                                    $stats['completed']++;
        }

        $treatRes = supabase_request('treatments', 'GET', [],
            'patient_id=eq.' . $patientId . '&select=treatment_id');
        $stats['treatments'] = count($treatRes['data'] ?? []);

        $invRes = supabase_request('invoices', 'GET', [],
            'patient_id=eq.' . $patientId .
            '&payment_status=neq.paid&select=total_amount,amount_paid');
        foreach (($invRes['data'] ?? []) as $inv) {
            $stats['balance'] += (float)($inv['total_amount'] ?? 0) - (float)($inv['amount_paid'] ?? 0);
        }
    }

    $upcoming = [];
    if ($patientId) {
        $today = date('Y-m-d');
        $uRes  = supabase_request('appointments', 'GET', [],
            'patient_id=eq.'      . $patientId .
            '&status=in.(pending,confirmed,checked_in)' .
            '&appointment_date=gte.' . $today .
            '&select=appointment_id,appointment_code,appointment_date,' .
            'appointment_time,status,' .
            'branches(branch_name),services(service_name)' .
            '&order=appointment_date.asc,appointment_time.asc&limit=5');
        foreach (($uRes['data'] ?? []) as $row) {
            $upcoming[] = [
                'appointment_id'   => $row['appointment_id'],
                'appointment_code' => $row['appointment_code'],
                'appointment_date' => $row['appointment_date'],
                'appointment_time' => $row['appointment_time'],
                'status'           => $row['status'],
                'branch_name'      => $row['branches']['branch_name']  ?? '—',
                'service_name'     => $row['services']['service_name'] ?? 'Consultation',
            ];
        }
    }

    $recentBills = [];
    if ($patientId) {
        $bRes = supabase_request('invoices', 'GET', [],
            'patient_id=eq.' . $patientId .
            '&select=invoice_code,invoice_date,total_amount,amount_paid,payment_status' .
            '&order=invoice_date.desc&limit=5');
        $recentBills = $bRes['data'] ?? [];
    }

    $profileRow = [];
    if ($patientId) {
        $pcRes = supabase_request('patient_profiles', 'GET', [],
            'patient_id=eq.' . $patientId .
            '&select=address,city,q_good_health,q_tobacco,q_dangerous_drugs&limit=1');
        $profileRow = $pcRes['data'][0] ?? [];
    }

    $isComplete = computeProfileComplete($account, $profileRow);

    if ((bool)($account['is_profile_complete'] ?? false) !== $isComplete) {
        supabase_request('patient_accounts', 'PATCH',
            ['is_profile_complete' => $isComplete],
            'account_id=eq.' . $accountId);
    }

    echo json_encode([
        'success'             => true,
        'account'             => $account,
        'stats'               => $stats,
        'upcoming'            => $upcoming,
        'recent_bills'        => $recentBills,
        'is_profile_complete' => $isComplete,
    ]);
    exit;
}

// ── GET PROFILE ───────────────────────────────────────────────────────────────
if ($action === 'get_profile') {

    $accResult = supabase_request('patient_accounts', 'GET', [],
        'account_id=eq.' . $accountId .
        '&select=account_id,email,first_name,last_name,middle_name,suffix,' .
        'birthdate,contact_number,is_profile_complete,patient_id&limit=1');

    if (empty($accResult['data'])) {
        echo json_encode(['success' => false, 'message' => 'Account not found.']);
        exit;
    }

    $account   = $accResult['data'][0];
    $patientId = $account['patient_id'] ?? null;

    $patientRow = [];
    if ($patientId) {
        $pRes = supabase_request('patients', 'GET', [],
            'patient_id=eq.' . $patientId .
            '&select=gender,contact_number,birthdate&limit=1');
        $patientRow = $pRes['data'][0] ?? [];
    }

    if (empty($account['contact_number']) && !empty($patientRow['contact_number']))
        $account['contact_number'] = $patientRow['contact_number'];
    if (empty($account['birthdate']) && !empty($patientRow['birthdate']))
        $account['birthdate'] = $patientRow['birthdate'];
    $account['gender'] = $patientRow['gender'] ?? '';

    $profileRow = [];
    if ($patientId) {
        $prRes = supabase_request('patient_profiles', 'GET', [],
            'patient_id=eq.' . $patientId . '&limit=1');
        $profileRow = $prRes['data'][0] ?? [];
    }

    $isComplete = computeProfileComplete($account, $profileRow);
    $account['is_profile_complete'] = $isComplete;

    echo json_encode(['success' => true, 'account' => $account, 'profile' => $profileRow]);
    exit;
}

// ── UPDATE PROFILE ────────────────────────────────────────────────────────────
if ($action === 'update_profile') {
    $firstName  = trim($input['first_name']  ?? '');
    $lastName   = trim($input['last_name']   ?? '');
    $middleName = trim($input['middle_name'] ?? '');
    $suffix     = trim($input['suffix']      ?? '');
    $birthdate  = trim($input['birthdate']   ?? '');
    $gender     = trim($input['gender']      ?? '');

    if (!$firstName || !$lastName) {
        echo json_encode(['success' => false, 'message' => 'First and last name are required.']);
        exit;
    }

    $hasBasic   = !empty($firstName) && !empty($lastName) && !empty($birthdate) && !empty($gender);
    $hasContact = !empty(trim($input['address'] ?? '')) && !empty(trim($input['city'] ?? ''));
    $hasMedical = isset($input['q_good_health'])    && in_array($input['q_good_health'],    ['yes', 'no'])
               && isset($input['q_tobacco'])         && in_array($input['q_tobacco'],         ['yes', 'no'])
               && isset($input['q_dangerous_drugs']) && in_array($input['q_dangerous_drugs'], ['yes', 'no']);
    $isProfileComplete = $hasBasic && $hasContact && $hasMedical;

    supabase_request('patient_accounts', 'PATCH', [
        'first_name'          => $firstName,
        'last_name'           => $lastName,
        'middle_name'         => $middleName ?: null,
        'suffix'              => $suffix     ?: null,
        'birthdate'           => $birthdate  ?: null,
        'is_profile_complete' => $isProfileComplete,
    ], 'account_id=eq.' . $accountId);

    $linkRes       = supabase_request('patient_accounts', 'GET', [],
        'account_id=eq.' . $accountId . '&select=patient_id,contact_number&limit=1');
    $patientId     = $linkRes['data'][0]['patient_id']     ?? null;
    $contactNumber = $linkRes['data'][0]['contact_number'] ?? null;

    if (!$patientId) {
        $nameParts   = array_filter([$firstName, $middleName, $lastName, $suffix]);
        $fullName    = implode(' ', $nameParts);
        $cn          = !empty($contactNumber) ? $contactNumber : ('__PORTAL_' . $accountId . '__');
        $patientCode = 'PAT-P' . str_pad($accountId, 5, '0', STR_PAD_LEFT);

        $newPatient = supabase_request('patients', 'POST', [
            'patient_code'   => $patientCode,
            'full_name'      => $fullName,
            'contact_number' => $cn,
            'gender'         => $gender    ?: null,
            'birthdate'      => $birthdate ?: null,
            'status'         => 'active',
        ]);

        if (!empty($newPatient['data'][0]['patient_id'])) {
            $patientId = (int)$newPatient['data'][0]['patient_id'];
            supabase_request('patient_accounts', 'PATCH',
                ['patient_id' => $patientId], 'account_id=eq.' . $accountId);
        }
    } else {
        $nameParts = array_filter([$firstName, $middleName, $lastName, $suffix]);
        supabase_request('patients', 'PATCH', [
            'full_name' => implode(' ', $nameParts),
            'birthdate' => $birthdate ?: null,
            'gender'    => $gender    ?: null,
        ], 'patient_id=eq.' . $patientId);
    }

    if ($patientId) {
        $boolField = function ($val) {
            if ($val === 'yes' || $val === true  || $val === 1) return true;
            if ($val === 'no'  || $val === false || $val === 0) return false;
            return null;
        };

        $profilePayload = [
            'civil_status'               => $input['civil_status']               ?? null,
            'nationality'                => $input['nationality']                ?? null,
            'home_phone'                 => $input['home_phone']                 ?? null,
            'address'                    => $input['address']                    ?? null,
            'city'                       => $input['city']                       ?? null,
            'province'                   => $input['province']                   ?? null,
            'zip'                        => $input['zip']                        ?? null,
            'chief_complaint'            => $input['chief_complaint']            ?? null,
            'referral_source'            => $input['referral_source']            ?? null,
            'referred_by'                => $input['referred_by']                ?? null,
            'prev_dentist'               => $input['prev_dentist']               ?? null,
            'last_dental_visit'          => $input['last_dental_visit']          ?: null,
            'dental_notes'               => $input['dental_notes']               ?? null,
            'physician'                  => $input['physician']                  ?? null,
            'physician_specialty'        => $input['physician_specialty']        ?? null,
            'physician_office_number'    => $input['physician_office_number']    ?? null,
            'physician_office_address'   => $input['physician_office_address']   ?? null,
            'q_good_health'              => $boolField($input['q_good_health']      ?? null),
            'q_under_treatment'          => $boolField($input['q_under_treatment']  ?? null),
            'treatment_condition'        => $input['treatment_condition']        ?? null,
            'q_serious_illness'          => $boolField($input['q_serious_illness']  ?? null),
            'illness_detail'             => $input['illness_detail']             ?? null,
            'q_hospitalized'             => $boolField($input['q_hospitalized']     ?? null),
            'hospitalized_detail'        => $input['hospitalized_detail']        ?? null,
            'q_taking_meds'              => $boolField($input['q_taking_meds']      ?? null),
            'meds_detail'                => $input['meds_detail']                ?? null,
            'q_tobacco'                  => $boolField($input['q_tobacco']          ?? null),
            'q_dangerous_drugs'          => $boolField($input['q_dangerous_drugs']  ?? null),
            'q_pregnant'                 => $boolField($input['q_pregnant']         ?? null),
            'q_nursing'                  => $boolField($input['q_nursing']          ?? null),
            'q_birth_control'            => $boolField($input['q_birth_control']    ?? null),
            'allergies'                  => json_encode($input['allergies']      ?? []),
            'other_allergies'            => $input['other_allergies']            ?? null,
            'allergy_reaction'           => $input['allergy_reaction']           ?? null,
            'blood_pressure'             => $input['blood_pressure']             ?? null,
            'blood_sugar'                => $input['blood_sugar']                ?: null,
            'pulse_rate'                 => $input['pulse_rate']                 ?: null,
            'emergency_name'             => $input['emergency_name']             ?? null,
            'emergency_relation'         => $input['emergency_relation']         ?? null,
            'emergency_phone'            => $input['emergency_phone']            ?? null,
            'conditions'                 => json_encode($input['conditions']     ?? []),
            'other_conditions'           => $input['other_conditions']           ?? null,
            'updated_at'                 => date('c'),
        ];

        $existCheck = supabase_request('patient_profiles', 'GET', [],
            'patient_id=eq.' . $patientId . '&select=profile_id&limit=1');

        if (!empty($existCheck['data'])) {
            supabase_request('patient_profiles', 'PATCH', $profilePayload, 'patient_id=eq.' . $patientId);
        } else {
            $profilePayload['patient_id'] = $patientId;
            supabase_request('patient_profiles', 'POST', $profilePayload);
        }
    }

    $_SESSION['portal_profile_complete'] = $isProfileComplete;
    $_SESSION['portal_name']             = $firstName;

    echo json_encode(['success' => true, 'is_profile_complete' => $isProfileComplete]);
    exit;
}

// ── GET APPOINTMENTS ──────────────────────────────────────────────────────────
if ($action === 'get_appointments') {
    $patientId = getPatientId($accountId);
    if (!$patientId) { echo json_encode(['success' => true, 'appointments' => []]); exit; }

    $result = supabase_request('appointments', 'GET', [],
        'patient_id=eq.' . $patientId .
        '&select=appointment_id,appointment_code,appointment_date,' .
        'appointment_time,status,' .
        'branches(branch_name),services(service_name)' .
        '&order=appointment_date.desc,appointment_time.desc');

    $appointments = [];
    foreach (($result['data'] ?? []) as $row) {
        $appointments[] = [
            'appointment_id'   => $row['appointment_id'],
            'appointment_code' => $row['appointment_code'],
            'appointment_date' => $row['appointment_date'],
            'appointment_time' => $row['appointment_time'],
            'status'           => $row['status'],
            'branch_name'      => $row['branches']['branch_name']  ?? '—',
            'service_name'     => $row['services']['service_name'] ?? 'Consultation',
        ];
    }
    echo json_encode(['success' => true, 'appointments' => $appointments]);
    exit;
}

// ── CANCEL APPOINTMENT ────────────────────────────────────────────────────────
if ($action === 'cancel_appointment') {
    $apptId    = (int)($input['appointment_id'] ?? 0);
    $patientId = getPatientId($accountId);

    if (!$apptId || !$patientId) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit;
    }

    $chk = supabase_request('appointments', 'GET', [],
        'appointment_id=eq.' . $apptId .
        '&patient_id=eq.'    . $patientId .
        '&status=eq.pending&select=appointment_id&limit=1');

    if (empty($chk['data'])) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found or cannot be cancelled.']); exit;
    }

    supabase_request('appointments', 'PATCH',
        ['status' => 'cancelled'], 'appointment_id=eq.' . $apptId);

    echo json_encode(['success' => true]);
    exit;
}

// ── GET TREATMENTS ────────────────────────────────────────────────────────────
if ($action === 'get_treatments') {
    $patientId = getPatientId($accountId);
    if (!$patientId) { echo json_encode(['success' => true, 'treatments' => []]); exit; }

    $result = supabase_request('treatments', 'GET', [],
        'patient_id=eq.' . $patientId .
        '&select=treatment_code,treatment_date,tooth_number,status,' .
        'services(service_name),dentists(full_name)' .
        '&order=treatment_date.desc');

    $treatments = [];
    foreach (($result['data'] ?? []) as $row) {
        $treatments[] = [
            'treatment_code' => $row['treatment_code'],
            'treatment_date' => $row['treatment_date'],
            'tooth_number'   => $row['tooth_number'],
            'status'         => $row['status'],
            'service_name'   => $row['services']['service_name'] ?? '—',
            'dentist_name'   => $row['dentists']['full_name']    ?? '—',
        ];
    }
    echo json_encode(['success' => true, 'treatments' => $treatments]);
    exit;
}

// ── GET DENTAL CHART ──────────────────────────────────────────────────────────
if ($action === 'get_dental_chart') {
    $patientId = getPatientId($accountId);
    if (!$patientId) { echo json_encode(['success' => true, 'chart' => []]); exit; }

    $result = supabase_request('dental_chart', 'GET', [],
        'patient_id=eq.' . $patientId . '&select=tooth_number,condition,notes');

    echo json_encode(['success' => true, 'chart' => $result['data'] ?? []]);
    exit;
}

// ── GET BILLING ───────────────────────────────────────────────────────────────
if ($action === 'get_billing') {
    $patientId = getPatientId($accountId);
    if (!$patientId) { echo json_encode(['success' => true, 'invoices' => []]); exit; }

    $result = supabase_request('invoices', 'GET', [],
        'patient_id=eq.' . $patientId .
        '&select=invoice_code,invoice_date,total_amount,amount_paid,' .
        'payment_status,treatments(treatment_code)' .
        '&order=invoice_date.desc');

    $invoices = [];
    foreach (($result['data'] ?? []) as $row) {
        $invoices[] = [
            'invoice_code'   => $row['invoice_code'],
            'invoice_date'   => $row['invoice_date'],
            'total_amount'   => $row['total_amount'],
            'amount_paid'    => $row['amount_paid'],
            'payment_status' => $row['payment_status'],
            'treatment_code' => $row['treatments']['treatment_code'] ?? '—',
        ];
    }
    echo json_encode(['success' => true, 'invoices' => $invoices]);
    exit;
}

// ── GET FILES ─────────────────────────────────────────────────────────────────
if ($action === 'get_files') {
    $patientId = getPatientId($accountId);
    if (!$patientId) { echo json_encode(['success' => true, 'files' => []]); exit; }

    $result = supabase_request('patient_files', 'GET', [],
        'patient_id=eq.' . $patientId .
        '&select=file_type,file_name,file_path,uploaded_at' .
        '&order=uploaded_at.desc');

    echo json_encode(['success' => true, 'files' => $result['data'] ?? []]);
    exit;
}

// ── GET WAIVERS ───────────────────────────────────────────────────────────────
if ($action === 'get_waivers') {
    $acc       = supabase_request('portal_accounts', 'GET', [],
        'account_id=eq.' . (int)$accountId . '&select=patient_id&limit=1');
    $patientId = $acc['data'][0]['patient_id'] ?? 0;
    if (!$patientId) { echo json_encode(['success' => false, 'waivers' => []]); exit; }

    $res = supabase_request('waivers', 'GET', [],
        'patient_id=eq.' . $patientId .
        '&order=waiver_id.desc' .
        '&select=waiver_id,waiver_type,file_path,status,signed_at,signed_by');
    echo json_encode(['success' => true, 'waivers' => $res['data'] ?? []]);
    exit;
}

// ── SIGN WAIVER ───────────────────────────────────────────────────────────────
if ($action === 'sign_waiver') {
    $acc       = supabase_request('portal_accounts', 'GET', [],
        'account_id=eq.' . (int)$accountId . '&select=patient_id&limit=1');
    $patientId = $acc['data'][0]['patient_id'] ?? 0;
    $waiverId  = (int)($input['waiver_id'] ?? 0);
    $signedBy  = trim($input['signed_by'] ?? '');

    if (!$patientId || !$waiverId || !$signedBy) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']); exit;
    }

    supabase_request('waivers', 'PATCH',
        ['status' => 'Signed', 'signed_at' => date('c'), 'signed_by' => $signedBy],
        'waiver_id=eq.' . $waiverId . '&patient_id=eq.' . $patientId);
    echo json_encode(['success' => true, 'message' => 'Waiver signed.']);
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Invalid action.']);


// ── Helper: resolve patient_id from account_id ───────────────────────────────
function getPatientId(int $accountId): ?int
{
    $res = supabase_request('patient_accounts', 'GET', [],
        'account_id=eq.' . $accountId . '&select=patient_id&limit=1');
    $id = $res['data'][0]['patient_id'] ?? null;
    return $id ? (int)$id : null;
}