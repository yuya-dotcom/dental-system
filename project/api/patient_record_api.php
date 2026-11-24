<?php
// =============================================================
//  api/patient_record_api.php
//  Tab data for patient-records.php (Patient Details, Appointments,
//  Treatments, Billing, Clinical Notes) + add / delete clinical notes.
// =============================================================
session_start();
define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']); // required before patient_control.php is loaded
require_once __DIR__ . '/../partials/auth_check.php';
require_once __DIR__ . '/../controllers/patient_controller.php'; // list helpers (findOrCreatePatient, etc.)
require_once __DIR__ . '/../controllers/patient_control.php';    // single-record functions live here
require_once __DIR__ . '/../dbconfig.php';

header('Content-Type: application/json');

$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$patientId = (int)($_POST['patient_id'] ?? $_GET['patient_id'] ?? 0);

if (!$patientId) {
    echo json_encode(['success' => false, 'message' => 'Invalid patient ID.']);
    exit;
}
requirePatientAccess($patientId);   // non-owners: only patients with an appointment at their branch
// Reads don't change the session: release its lock so the tabs (and the chart) load side by side
// instead of waiting for each other. The access check above has already cached its answer.
if (str_starts_with($action, 'get_')) session_write_close();

switch ($action) {

    // ── TAB: Appointments ─────────────────────────────
    case 'get_appointments':
        $page = max(1, (int)($_GET['page'] ?? 1));
        // One combined read; the separate reads are the fallback
        $tab  = getPatientAppointmentsTab($patientId, $page)
             ?? ['upcoming' => getPatientUpcomingAppointments($patientId), 'history' => getPatientAppointmentHistory($patientId, $page)];
        echo json_encode(['success' => true, 'upcoming' => $tab['upcoming'], 'history' => $tab['history']]);
        break;

    // ── TAB: Clinical Notes ───────────────────────────
    case 'get_notes':
        $notes = getPatientClinicalNotes($patientId);
        echo json_encode(['success' => true, 'notes' => $notes]);
        break;

    case 'add_note':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        $noteText      = trim($_POST['note_text'] ?? '');
        $noteDate      = $_POST['note_date'] ?? date('Y-m-d');
        $dentistId     = !empty($_POST['dentist_id']) ? (int)$_POST['dentist_id'] : null;
        $appointmentId = !empty($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;
        $isPrivate     = ($_POST['is_private'] ?? '0') === '1';
        $branchId      = (int)($_SESSION['branch_id'] ?? 0);
        $createdBy     = $_SESSION['full_name'] ?? 'Unknown';

        if (!$noteText) {
            echo json_encode(['success' => false, 'message' => 'Note text is required.']);
            exit;
        }

        $res = addClinicalNote($patientId, $branchId, $noteText, $noteDate, $dentistId, $appointmentId, $createdBy, $isPrivate);
        supabase_request('audit_logs', 'POST', [
            'user_id'  => (int)$_SESSION['user_id'],
            'username' => $createdBy,
            'action'   => 'ADD_CLINICAL_NOTE',
            'details'  => 'Added note for patient ID ' . $patientId,
            'module'   => 'Patient Record',
        ]);
        echo json_encode(['success' => true, 'message' => 'Note saved.']);
        break;

    case 'delete_note':
        $noteId = (int)($_POST['note_id'] ?? 0);
        if (!$noteId) {
            echo json_encode(['success' => false, 'message' => 'Invalid note.']);
            exit;
        }
        $del = deleteClinicalNote($noteId, $patientId);
        if (($del['status'] ?? 0) < 200 || ($del['status'] ?? 0) >= 300 || empty($del['data'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Note not found for this patient.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Note deleted.']);
        break;

    // ── TAB: Treatments ───────────────────────────────
    case 'get_treatments':
        $treatments = getPatientTreatments($patientId);
        echo json_encode(['success' => true, 'treatments' => $treatments]);
        break;

    // ── TAB: Billing ──────────────────────────────────
    case 'get_billing':
        // The totals come from the same invoice rows (was a second read of the same table)
        $invoices = getPatientInvoices($patientId);
        $summary  = patientFinancialSummaryFrom($invoices);
        echo json_encode(['success' => true, 'invoices' => $invoices, 'summary' => $summary]);
        break;

    // ── TAB: Patient Details (personal + contact; no branch — patients aren't
    //    tied to one; the branch belongs to each appointment) ──────────────
    case 'get_details':
        require_once __DIR__ . '/../controllers/admin_batch.php';
        $r = admin_sb_many([
            'p' => admin_q('patients', 'select=full_name,gender,birthdate,contact_number&patient_id=eq.' . $patientId . '&limit=1'),
            'a' => admin_q('patient_accounts', 'select=first_name,middle_name,last_name,suffix,email,contact_number,birthdate&patient_id=eq.' . $patientId . '&limit=1'),
            'f' => admin_q('patient_profiles', 'select=address,barangay,city,province,zip&patient_id=eq.' . $patientId . '&limit=1'),
        ]);
        $p = $r['p']['data'][0] ?? null;
        if (!$p) { echo json_encode(['success' => false, 'message' => 'Patient not found.']); break; }
        $a = $r['a']['data'][0] ?? [];
        $f = $r['f']['data'][0] ?? [];
        $clean = fn($v) => ($v = trim((string)($v ?? ''))) === '' ? null : $v;
        $birth = $clean($p['birthdate'] ?? null) ?? $clean($a['birthdate'] ?? null);
        $address = implode(', ', array_filter(array_map($clean, [
            $f['address'] ?? null, $f['barangay'] ?? null, $f['city'] ?? null, $f['province'] ?? null, $f['zip'] ?? null,
        ])));
        echo json_encode(['success' => true, 'details' => [
            'full_name'      => $clean($p['full_name'] ?? null),
            'gender'         => $clean($p['gender'] ?? null) ? ucfirst(strtolower(trim($p['gender']))) : null,
            'birthdate'      => $birth ? date('M d, Y', strtotime($birth)) : null,
            'age'            => $birth ? calculateAge($birth) : null,
            'contact_number' => $clean($p['contact_number'] ?? null) ?? $clean($a['contact_number'] ?? null),
            'email'          => $clean($a['email'] ?? null),
            'address'        => $address !== '' ? $address : null,
            'has_account'    => !empty($a),
        ]]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        break;
}
exit;