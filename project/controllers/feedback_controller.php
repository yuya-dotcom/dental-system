<?php
// =============================================================
//  controllers/feedback_controller.php — patient feedback rules
//
//  Used by: api/portal_feedback.php (patient side)
//           feedback-records.php    (owner side)
//
//  Rules (all checked here, on the server):
//    • feedback belongs to one appointment of the logged-in patient
//    • only completed appointments WITH a completed treatment are eligible
//    • patients submit Dentist Feedback only ('clinic' rows from before
//      this change are kept and still shown to the Owner)
//    • one feedback per appointment — backed by the unique key on
//      patient_feedback.appointment_id
//    • the dentist is resolved on the server, never taken from the request:
//      the one dentist on that appointment's completed treatments, else the
//      appointment's assigned dentist
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/appointment_services_helper.php';

const FEEDBACK_MAX_LEN   = 1000;
const FEEDBACK_CATEGORIES = [            // labels for stored rows (Owner side)
    'dentist' => 'Dentist Feedback',
    'clinic'  => 'Clinic & Management Feedback',
];
const FEEDBACK_PATIENT_CATEGORY = 'dentist'; // the only category patients can submit

/** Patient id linked to a portal account (null when not linked yet). */
function feedbackPatientId(int $accountId): ?int
{
    $res = supabase_request('patient_accounts', 'GET', [],
        'account_id=eq.' . $accountId . '&select=patient_id&limit=1');
    $id = $res['data'][0]['patient_id'] ?? null;
    return $id ? (int)$id : null;
}

/**
 * appointment_id => ['category', 'created_at'] for the given appointments.
 * Returns null when the table can't be read (e.g. migration not run yet).
 */
function feedbackSubmittedMap(array $appointmentIds): ?array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $appointmentIds))));
    if (!$ids) return [];
    $res = supabase_request('patient_feedback', 'GET', [],
        'appointment_id=in.(' . implode(',', $ids) . ')&select=appointment_id,category,created_at');
    if ($res['error'] || !is_array($res['data'])) return null;
    $map = [];
    foreach ($res['data'] as $r) $map[(int)$r['appointment_id']] = ['category' => $r['category'], 'created_at' => $r['created_at']];
    return $map;
}

/**
 * Completed treatments of the appointment and the dentist the patient may review:
 * the one dentist who performed them, else the appointment's assigned dentist.
 * Returns ['treated' => bool, 'dentist' => ?array]; dentist is null when none
 * is recorded or the treatments name more than one dentist.
 */
function feedbackResolveDentist(array $apt, int $patientId): array
{
    $res = supabase_request('treatments', 'GET', [],
        'appointment_id=eq.' . (int)$apt['appointment_id'] .
        '&patient_id=eq.' . $patientId .
        '&status=eq.completed&select=dentist_id');
    $rows = is_array($res['data'] ?? null) ? $res['data'] : [];
    if (!$rows) return ['treated' => false, 'dentist' => null];
    $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['dentist_id'] ?? 0), $rows))));
    if (count($ids) > 1) return ['treated' => true, 'dentist' => null];
    $dentistId = $ids[0] ?? (int)($apt['dentist_id'] ?? 0);
    if (!$dentistId) return ['treated' => true, 'dentist' => null];
    $d = supabase_request('dentists', 'GET', [], 'dentist_id=eq.' . $dentistId . '&select=dentist_id,full_name&limit=1');
    $row = $d['data'][0] ?? null;
    return ['treated' => true, 'dentist' => $row ? ['dentist_id' => (int)$row['dentist_id'], 'full_name' => (string)$row['full_name']] : null];
}

/**
 * Loads an appointment for feedback and checks every rule.
 *   ['ok' => true,  'appointment' => [...], 'dentist' => ?array]
 *   ['ok' => false, 'code' => 'not_found|not_completed|no_dentist|already_submitted|unavailable', 'message' => '...']
 */
function feedbackLoadAppointment(int $patientId, int $appointmentId): array
{
    $res = supabase_request('appointments', 'GET', [],
        'appointment_id=eq.' . $appointmentId .
        '&patient_id=eq.' . $patientId .
        '&select=appointment_id,appointment_code,appointment_date,appointment_time,status,branch_id,dentist_id,' .
        'services(service_name),branches(branch_name)&limit=1');
    if ($res['error']) return ['ok' => false, 'code' => 'unavailable', 'message' => 'Could not load this appointment. Please try again.'];
    $apt = $res['data'][0] ?? null;
    // Someone else's appointment looks exactly like a missing one
    if (!$apt) return ['ok' => false, 'code' => 'not_found', 'message' => 'This appointment could not be found.'];
    if (($apt['status'] ?? '') !== 'completed') {
        return ['ok' => false, 'code' => 'not_completed', 'message' => 'Feedback opens once this appointment has been completed.'];
    }

    $done = feedbackSubmittedMap([$appointmentId]);
    if ($done === null) return ['ok' => false, 'code' => 'unavailable', 'message' => 'Feedback is not available right now. Please try again later.'];
    if (isset($done[$appointmentId])) {
        return ['ok' => false, 'code' => 'already_submitted', 'message' => 'You have already sent feedback for this appointment.'];
    }

    $who = feedbackResolveDentist($apt, $patientId);
    if (!$who['treated']) {
        return ['ok' => false, 'code' => 'not_completed', 'message' => 'Feedback opens once the treatment for this visit has been completed.'];
    }
    if (!$who['dentist']) {
        return ['ok' => false, 'code' => 'no_dentist', 'message' => 'No dentist is recorded for this visit, so dentist feedback is not available. Please contact the clinic if this is wrong.'];
    }

    $apt = attachAppointmentServiceNames([$apt])[0];
    return ['ok' => true, 'appointment' => $apt, 'dentist' => $who['dentist']];
}
