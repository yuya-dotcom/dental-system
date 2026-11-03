<?php
// api/checkin_appointment.php
session_start();
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/secure_controller.php';
require_once __DIR__ . '/../dbconfig.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

switch ($action) {

    // ── Fetch patient's medical profile for risk assessment ──────────────
    // Allergies + conditions come from patient_profiles.
    // Medical questions (q_*) come from medical_questionnaire via account_id.
    case 'get_patient_medical':
        $patientId = (int)($body['patient_id'] ?? 0);
        if (!$patientId) {
            echo json_encode(['success' => false, 'message' => 'Missing patient_id']);
            exit;
        }

        // 1. Fetch allergies + conditions from patient_profiles
        $profRes = supabase_request(
            'patient_profiles',
            'GET',
            [],
            'patient_id=eq.' . $patientId .
                '&select=conditions,other_conditions,allergies,other_allergies,allergy_reaction' .
                '&limit=1'
        );
        $profile = $profRes['data'][0] ?? null;

        // 2. Fetch medical questions from medical_questionnaire via patient_id
        $mqRes = supabase_request(
            'medical_questionnaire',
            'GET',
            [],
            'patient_id=eq.' . $patientId .
                '&select=q_good_health,q_under_treatment,treatment_condition,' .
                'q_serious_illness,illness_detail,q_hospitalized,hospitalized_detail,' .
                'q_taking_meds,meds_detail,q_tobacco,q_dangerous_drugs,' .
                'q_pregnant,q_nursing,q_birth_control' .
                '&limit=1'
        );
        $mq = $mqRes['data'][0] ?? null;

        if (!$profile && !$mq) {
            echo json_encode(['success' => false, 'message' => 'No medical profile found.']);
            exit;
        }

        // Merge both into one flat object for the JS side —
        // keeps the check-in modal JS unchanged
        $merged = array_merge(
            $profile ?? [],
            $mq      ?? []
        );

        echo json_encode(['success' => true, 'profile' => $merged]);
        break;

    // ── Save vital signs + update appointment status to checked_in ───────
    case 'submit_checkin':
        $appointmentId = (int)($body['appointment_id'] ?? 0);
        $patientId     = (int)($body['patient_id']     ?? 0);
        // "Checked By" is the logged-in staff member (read-only in the modal),
        // never a name sent by the browser
        $checkedBy     = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));

        if (!$appointmentId || !$patientId || $checkedBy === '') {
            echo json_encode(['success' => false, 'message' => 'appointment_id, patient_id, and checked_by are required.']);
            exit;
        }

        // Build vital signs payload — omit null fields so Supabase uses column defaults
        $vitalData = [
            'appointment_id' => $appointmentId,
            'patient_id'     => $patientId,
            'checked_by'     => $checkedBy,
        ];

        if (!empty($body['blood_pressure']))
            $vitalData['blood_pressure'] = trim($body['blood_pressure']);
        if (isset($body['pulse_rate']) && $body['pulse_rate'] !== '' && $body['pulse_rate'] !== null)
            $vitalData['pulse_rate'] = (int)$body['pulse_rate'];
        if (isset($body['temperature']) && $body['temperature'] !== '' && $body['temperature'] !== null)
            $vitalData['temperature'] = (float)$body['temperature'];
        if (!empty($body['checked_at']))
            $vitalData['checked_at'] = $body['checked_at'];
        if (!empty($body['notes']))
            $vitalData['notes'] = trim($body['notes']);

        // 1. Insert vital signs record
        $vRes = supabase_request(
            'vital_signs',
            'POST',
            $vitalData,
            '',
            ['Prefer' => 'return=representation']
        );
        if (isset($vRes['error'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to save vital signs: ' . ($vRes['error']['message'] ?? 'Unknown error'),
            ]);
            exit;
        }

        // 2. Update appointment status to checked_in
        $aptRes = supabase_request(
            'appointments',
            'PATCH',
            ['status' => 'checked_in'],
            'appointment_id=eq.' . $appointmentId,
            ['Prefer' => 'return=representation']
        );
        if (isset($aptRes['error'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Vital signs saved but could not update appointment: ' .
                             ($aptRes['error']['message'] ?? 'Unknown error'),
            ]);
            exit;
        }

        // ── 3. Auto-create treatment record if one doesn't exist yet ──────
        $aptDetail = supabase_request(
            'appointments',
            'GET',
            [],
            'appointment_id=eq.' . $appointmentId .
                '&select=appointment_id,patient_id,branch_id,service_id,appointment_date&limit=1'
        );
        $aptData = $aptDetail['data'][0] ?? null;

        if ($aptData) {
            // Only create if no treatment exists for this appointment yet
            $existing = supabase_request(
                'treatments',
                'GET',
                [],
                'appointment_id=eq.' . $appointmentId . '&select=treatment_id&limit=1'
            );

            if (empty($existing['data'])) {
                // Generate next treatment code
                $lastTrt  = supabase_request(
                    'treatments',
                    'GET',
                    [],
                    'select=treatment_code&order=treatment_code.desc&limit=1'
                );
                $lastCode = $lastTrt['data'][0]['treatment_code'] ?? 'TR-00000';
                $prefix   = str_contains($lastCode, 'TRT-') ? 'TRT-' : 'TR-';
                $padLen   = str_contains($lastCode, 'TRT-') ? 4 : 5;
                $lastNum  = (int) preg_replace('/[^0-9]/', '', $lastCode);
                $trtCode  = $prefix . str_pad($lastNum + 1, $padLen, '0', STR_PAD_LEFT);

                supabase_request('treatments', 'POST', [
                    'treatment_code' => $trtCode,
                    'appointment_id' => (int) $appointmentId,
                    'patient_id'     => (int) ($aptData['patient_id'] ?? 0),
                    'branch_id'      => (int) ($aptData['branch_id']  ?? 0),
                    'service_id'     => $aptData['service_id'] ? (int) $aptData['service_id'] : null,
                    'treatment_date' => $aptData['appointment_date'] ?? date('Y-m-d'),
                    'status'         => 'pending',
                    'created_at'     => date('c'),
                    'updated_at'     => date('c'),
                ]);
            }
        }
        // ── End treatment auto-create ─────────────────────────────────────

        echo json_encode(['success' => true, 'message' => 'Patient checked in successfully.']);
        break;

    // ── Fetch vital signs history for a patient ──────────────────────────
    case 'get_vital_signs':
        $patientId = (int)($body['patient_id'] ?? 0);
        if (!$patientId) {
            echo json_encode(['success' => false, 'message' => 'Missing patient_id']);
            exit;
        }

        $vsRes = supabase_request(
            'vital_signs',
            'GET',
            [],
            'patient_id=eq.' . $patientId .
                '&select=vital_sign_id,appointment_id,blood_pressure,pulse_rate,' .
                'temperature,checked_by,checked_at,notes,' .
                'appointments(appointment_code)' .
                '&order=checked_at.desc'
        );

        $vitals = is_array($vsRes['data']) ? $vsRes['data'] : [];

        // Flatten appointment_code up one level for easier JS access
        $vitals = array_map(function ($row) {
            $row['appointment_code'] = $row['appointments']['appointment_code'] ?? null;
            unset($row['appointments']);
            return $row;
        }, $vitals);

        echo json_encode(['success' => true, 'vitals' => $vitals]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}