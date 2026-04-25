<?php
session_start();
define('REQUIRED_ROLES', ['owner', 'admin', 'dentist']); // required before patient_control.php is loaded
require_once __DIR__ . '/../partials/auth_check.php';
require_once __DIR__ . '/../controllers/patient_controller.php'; // list helpers (findOrCreatePatient, etc.)
require_once __DIR__ . '/../controllers/patient_control.php';    // ← FIX: single-record functions live here
require_once __DIR__ . '/../dbconfig.php';

header('Content-Type: application/json');

$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$patientId = (int)($_POST['patient_id'] ?? $_GET['patient_id'] ?? 0);

if (!$patientId) {
    echo json_encode(['success' => false, 'message' => 'Invalid patient ID.']);
    exit;
}

switch ($action) {

    // ── TAB: Appointments ─────────────────────────────
    case 'get_appointments':
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $upcoming = getPatientUpcomingAppointments($patientId);
        $history  = getPatientAppointmentHistory($patientId, $page);
        echo json_encode(['success' => true, 'upcoming' => $upcoming, 'history' => $history]);
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

    case 'update_note':
        $noteId    = (int)($_POST['note_id'] ?? 0);
        $noteText  = trim($_POST['note_text'] ?? '');
        $isPrivate = ($_POST['is_private'] ?? '0') === '1';
        if (!$noteId || !$noteText) {
            echo json_encode(['success' => false, 'message' => 'Missing fields.']);
            exit;
        }
        updateClinicalNote($noteId, $noteText, $isPrivate);
        echo json_encode(['success' => true, 'message' => 'Note updated.']);
        break;

    case 'delete_note':
        $noteId = (int)($_POST['note_id'] ?? 0);
        if (!$noteId) {
            echo json_encode(['success' => false, 'message' => 'Invalid note.']);
            exit;
        }
        deleteClinicalNote($noteId);
        echo json_encode(['success' => true, 'message' => 'Note deleted.']);
        break;

    // ── TAB: Dental Chart ─────────────────────────────
    case 'get_dental_chart':
        $chart = getPatientDentalChartData($patientId);
        echo json_encode(['success' => true, 'chart' => $chart]);
        break;

    // ── TAB: Treatments ───────────────────────────────
    case 'get_treatments':
        $treatments = getPatientTreatments($patientId);
        echo json_encode(['success' => true, 'treatments' => $treatments]);
        break;

    // ── TAB: Billing ──────────────────────────────────
    case 'get_billing':
        $invoices = getPatientInvoices($patientId);
        $summary  = getPatientFinancialSummary($patientId);
        echo json_encode(['success' => true, 'invoices' => $invoices, 'summary' => $summary]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        break;
}
exit;