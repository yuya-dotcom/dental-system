<?php
if (!defined('REQUIRED_ROLES')) die('Direct access not allowed.');
require_once __DIR__ . '/appointment_services_helper.php';   // all services per appointment

/**
 * Fetch single patient with branch info
 */
function getPatientById(int $patientId): ?array {
    $res = supabase_request('patients', 'GET', [],
        'select=patient_id,patient_code,full_name,contact_number,gender,birthdate,last_visit,status,branch_id,created_at,branches(branch_name)&patient_id=eq.' . $patientId . '&limit=1'
    );
    $data = $res['data'] ?? [];
    return !empty($data) ? $data[0] : null;
}

/**
 * Financial summary for Overview tab
 */
function getPatientFinancialSummary(int $patientId): array {
    $res = supabase_request('invoices', 'GET', [],
        'select=total_amount,amount_paid,balance,payment_status&patient_id=eq.' . $patientId
    );
    $rows = is_array($res['data']) ? $res['data'] : [];
    $totalBilled = 0; $totalPaid = 0; $totalBalance = 0;
    foreach ($rows as $r) {
        $totalBilled  += (float)($r['total_amount'] ?? 0);
        $totalPaid    += (float)($r['amount_paid'] ?? 0);
        $totalBalance += (float)($r['balance'] ?? 0);
    }
    return [
        'total_billed'  => $totalBilled,
        'total_paid'    => $totalPaid,
        'total_balance' => $totalBalance,
        'invoice_count' => count($rows),
    ];
}

/**
 * Active treatments for Overview tab
 */
function getPatientActiveTreatments(int $patientId): array {
    $res = supabase_request('treatments', 'GET', [],
        'select=treatment_id,treatment_code,current_stage,status,tooth_number,services(service_name)&patient_id=eq.' . $patientId . '&status=in.(ongoing,in_progress,pending)&order=created_at.desc&limit=5'
    );
    return is_array($res['data']) ? $res['data'] : [];
}

/**
 * Upcoming appointments (for Overview + Appointments tab)
 */
function getPatientUpcomingAppointments(int $patientId): array {
    $today = date('Y-m-d');
    $res = supabase_request('appointments', 'GET', [],
        'select=appointment_id,appointment_code,appointment_date,appointment_time,appointment_type,status,services(service_name),dentists(full_name)&patient_id=eq.' . $patientId . '&appointment_date=gte.' . $today . '&status=in.(pending,confirmed)&order=appointment_date.asc,appointment_time.asc&limit=10'
    );
    return attachAppointmentServiceNames(is_array($res['data']) ? $res['data'] : []);
}

/**
 * All appointments history (paginated)
 */
function getPatientAppointmentHistory(int $patientId, int $page = 1, int $perPage = 10): array {
    $offset = ($page - 1) * $perPage;
    $countRes = supabase_request('appointments', 'GET', [],
        'select=appointment_id&patient_id=eq.' . $patientId . '&order=appointment_date.desc'
    );
    $total = count(is_array($countRes['data']) ? $countRes['data'] : []);

    $res = supabase_request('appointments', 'GET', [],
        'select=appointment_id,appointment_code,appointment_date,appointment_time,appointment_type,status,payment_status,notes,services(service_name),dentists(full_name),branches(branch_name)&patient_id=eq.' . $patientId . '&order=appointment_date.desc&limit=' . $perPage . '&offset=' . $offset
    );
    return [
        'rows'        => attachAppointmentServiceNames(is_array($res['data']) ? $res['data'] : []),
        'total'       => $total,
        'totalPages'  => (int)ceil($total / $perPage),
        'currentPage' => $page,
    ];
}

/**
 * Clinical notes (paginated)
 */
function getPatientClinicalNotes(int $patientId, int $page = 1, int $perPage = 20): array {
    $offset = ($page - 1) * $perPage;
    $res = supabase_request('clinical_notes', 'GET', [],
        'select=note_id,note_text,note_date,created_by,is_private,created_at,updated_at,dentists(full_name),appointments(appointment_code)&patient_id=eq.' . $patientId . '&order=note_date.desc,created_at.desc&limit=' . $perPage . '&offset=' . $offset
    );
    return is_array($res['data']) ? $res['data'] : [];
}

/**
 * All treatments for Treatment Plans tab
 */
function getPatientTreatments(int $patientId): array {
    $res = supabase_request('treatments', 'GET', [],
        'select=treatment_id,treatment_code,tooth_number,current_stage,status,treatment_date,cost,procedure_notes,services(service_name),dentists(full_name),branches(branch_name)&patient_id=eq.' . $patientId . '&order=treatment_date.desc'
    );
    return is_array($res['data']) ? $res['data'] : [];
}

/**
 * All invoices + payments for Billing tab
 */
function getPatientInvoices(int $patientId): array {
    $res = supabase_request('invoices', 'GET', [],
        'select=invoice_id,invoice_code,invoice_date,total_amount,amount_paid,balance,payment_status,payments(payment_id,amount,payment_method,payment_date,notes)&patient_id=eq.' . $patientId . '&order=invoice_date.desc'
    );
    return is_array($res['data']) ? $res['data'] : [];
}

/**
 * Appointments tab in ONE request: upcoming (same filter / order / limit as
 * getPatientUpcomingAppointments), one page of history and the history total,
 * all embedded under the patient row, with every appointment's services
 * embedded too (attachAppointmentServiceNames needs no extra read).
 *
 * It replaces 5 reads that ran one after another: upcoming, its services,
 * a count that downloaded every appointment id, the history page and its
 * services. Returns null when the combined read fails (callers fall back to
 * the individual functions). Same output shape as before.
 */
function getPatientAppointmentsTab(int $patientId, int $page = 1, int $perPage = 10): ?array
{
    $today  = date('Y-m-d');
    $offset = ($page - 1) * $perPage;
    $svc    = 'services(service_name),appointment_services(sort_order,services(service_name))';
    $sel = [
        'patient_id',
        'up:appointments(appointment_id,appointment_code,appointment_date,appointment_time,appointment_type,status,dentists(full_name),' . $svc . ')',
        'hist:appointments(appointment_id,appointment_code,appointment_date,appointment_time,appointment_type,status,payment_status,notes,dentists(full_name),branches(branch_name),' . $svc . ')',
        'n:appointments(appointment_id)',      // ids only, for the total
    ];
    $q = [
        'up.appointment_date=gte.' . $today, 'up.status=in.(pending,confirmed)',
        'up.order=appointment_date.asc,appointment_time.asc', 'up.limit=10',
        'hist.order=appointment_date.desc,appointment_time.desc.nullslast,appointment_id.desc',
        'hist.limit=' . $perPage, 'hist.offset=' . $offset,
    ];
    $res = supabase_request('patients', 'GET', [],
        'select=' . implode(',', $sel) . '&' . implode('&', $q) . '&patient_id=eq.' . $patientId . '&limit=1');
    if (!empty($res['error']) || !is_array($res['data'] ?? null)) return null;
    $row   = $res['data'][0] ?? [];
    $list  = fn(string $k) => is_array($row[$k] ?? null) ? $row[$k] : [];
    $total = count($list('n'));
    return [
        'upcoming' => attachAppointmentServiceNames($list('up')),
        'history'  => [
            'rows'        => attachAppointmentServiceNames($list('hist')),
            'total'       => $total,
            'totalPages'  => (int)ceil($total / $perPage),
            'currentPage' => $page,
        ],
    ];
}

/** Billing tab totals from rows already loaded (same sums as getPatientFinancialSummary). */
function patientFinancialSummaryFrom(array $invoices): array
{
    $billed = 0; $paid = 0; $balance = 0;
    foreach ($invoices as $r) {
        $billed  += (float)($r['total_amount'] ?? 0);
        $paid    += (float)($r['amount_paid'] ?? 0);
        $balance += (float)($r['balance'] ?? 0);
    }
    return ['total_billed' => $billed, 'total_paid' => $paid, 'total_balance' => $balance, 'invoice_count' => count($invoices)];
}

/**
 * Everything the Patient Record header/Overview (and, with $forPrint, the
 * printable record) needs, in ONE request: the patient with its invoices,
 * treatments, upcoming appointments and clinical notes embedded.
 *
 * It replaces 4 (page) / 7 (print) separate reads that ran one after another
 * on the host (no curl_multi): getPatientById, getPatientFinancialSummary,
 * getPatientActiveTreatments, getPatientUpcomingAppointments and, for print,
 * getPatientInvoices, getPatientTreatments, getPatientClinicalNotes. Every
 * list keeps the same filters, order and limits as those functions.
 *
 * Returns null when the combined read is not available (the caller then uses
 * the individual functions), or ['patient' => null] when there is no patient.
 */
function getPatientRecordBundle(int $patientId, bool $forPrint = false): ?array
{
    $today = date('Y-m-d');
    $invCols = $forPrint
        ? 'invoice_id,invoice_code,invoice_date,total_amount,amount_paid,balance,payment_status,payments(payment_id,amount,payment_method,payment_date,notes)'
        : 'total_amount,amount_paid,balance,payment_status';
    $sel = [
        'patient_id,patient_code,full_name,contact_number,gender,birthdate,last_visit,status,branch_id,created_at,branches(branch_name)',
        'inv:invoices(' . $invCols . ')',
        // Active treatments (Overview): same filter / order / limit as getPatientActiveTreatments()
        'act:treatments(treatment_id,treatment_code,current_stage,status,tooth_number,services(service_name))',
        // Upcoming (Overview): same as getPatientUpcomingAppointments(), services embedded
        'up:appointments(appointment_id,appointment_code,appointment_date,appointment_time,appointment_type,status,'
            . 'services(service_name),dentists(full_name),appointment_services(sort_order,services(service_name)))',
    ];
    $q = [
        'act.status=in.(ongoing,in_progress,pending)', 'act.order=created_at.desc', 'act.limit=5',
        'up.appointment_date=gte.' . $today, 'up.status=in.(pending,confirmed)',
        'up.order=appointment_date.asc,appointment_time.asc', 'up.limit=10',
    ];
    if ($forPrint) {
        $q[]   = 'inv.order=invoice_date.desc';
        $sel[] = 'trt:treatments(treatment_id,treatment_code,tooth_number,current_stage,status,treatment_date,cost,procedure_notes,'
               . 'services(service_name),dentists(full_name),branches(branch_name))';
        $q[]   = 'trt.order=treatment_date.desc';
        $sel[] = 'cn:clinical_notes(note_id,note_text,note_date,created_by,is_private,created_at,updated_at,dentists(full_name),appointments(appointment_code))';
        array_push($q, 'cn.order=note_date.desc,created_at.desc', 'cn.limit=20');
    }

    $res = supabase_request('patients', 'GET', [],
        'select=' . implode(',', $sel) . '&' . implode('&', $q) . '&patient_id=eq.' . $patientId . '&limit=1');
    if (!empty($res['error']) || !is_array($res['data'] ?? null)) return null;
    $row = $res['data'][0] ?? null;
    if (!$row) return ['patient' => null];

    $list = fn(string $k) => is_array($row[$k] ?? null) ? $row[$k] : [];
    $invoices = $list('inv');
    $billed = 0; $paid = 0; $balance = 0;
    foreach ($invoices as $r) {
        $billed  += (float)($r['total_amount'] ?? 0);
        $paid    += (float)($r['amount_paid'] ?? 0);
        $balance += (float)($r['balance'] ?? 0);
    }
    $out = [
        'summary'  => ['total_billed' => $billed, 'total_paid' => $paid, 'total_balance' => $balance, 'invoice_count' => count($invoices)],
        'active'   => $list('act'),
        'upcoming' => attachAppointmentServiceNames($list('up')),
    ];
    if ($forPrint) {
        $out['invoices']   = $invoices;
        $out['treatments'] = $list('trt');
        $out['notes']      = $list('cn');
    }
    foreach (['inv', 'act', 'up', 'trt', 'cn'] as $k) unset($row[$k]);
    return ['patient' => $row] + $out;
}

/**
 * CRUD: Add clinical note
 */
function addClinicalNote(int $patientId, int $branchId, string $noteText, string $noteDate, ?int $dentistId, ?int $appointmentId, string $createdBy, bool $isPrivate): array {
    $payload = [
        'patient_id'  => $patientId,
        'branch_id'   => $branchId,
        'note_text'   => $noteText,
        'note_date'   => $noteDate,
        'created_by'  => $createdBy,
        'is_private'  => $isPrivate,
        'created_at'  => date('c'),
        'updated_at'  => date('c'),
    ];
    if ($dentistId)     $payload['dentist_id']     = $dentistId;
    if ($appointmentId) $payload['appointment_id'] = $appointmentId;

    return supabase_request('clinical_notes', 'POST', $payload);
}

/**
 * CRUD: Delete clinical note
 */
function deleteClinicalNote(int $noteId, int $patientId): array {
    // Only a note of the patient whose record is open (not any note id in the system)
    return supabase_request('clinical_notes', 'DELETE', [], 'note_id=eq.' . $noteId . '&patient_id=eq.' . $patientId, ['Prefer: return=representation']);
}

/**
 * Helper: calculate age from birthdate
 */
function calculateAge(?string $birthdate): string {
    if (!$birthdate) return '—';
    try {
        $dob = new DateTime($birthdate);
        $now = new DateTime();
        return $dob->diff($now)->y . ' yrs';
    } catch (Exception $e) {
        return '—';
    }
}