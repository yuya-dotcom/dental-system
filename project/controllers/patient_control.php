<?php
if (!defined('REQUIRED_ROLES')) die('Direct access not allowed.');

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
    return is_array($res['data']) ? $res['data'] : [];
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
        'rows'        => is_array($res['data']) ? $res['data'] : [],
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
 * Dental chart data — all treatments grouped by tooth
 */
function getPatientDentalChartData(int $patientId): array {
    $res = supabase_request('treatments', 'GET', [],
        'select=treatment_id,treatment_code,tooth_number,current_stage,status,treatment_date,procedure_notes,services(service_name),dentists(full_name)&patient_id=eq.' . $patientId . '&tooth_number=not.is.null&order=treatment_date.desc'
    );
    $rows = is_array($res['data']) ? $res['data'] : [];
    // Group by tooth number
    $chart = [];
    foreach ($rows as $r) {
        $tooth = $r['tooth_number'];
        if (!isset($chart[$tooth])) $chart[$tooth] = [];
        $chart[$tooth][] = $r;
    }
    return $chart;
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
        'select=invoice_id,invoice_code,invoice_date,total_amount,amount_paid,balance,payment_status,services(service_name),payments(payment_id,amount,payment_method,payment_date,notes)&patient_id=eq.' . $patientId . '&order=invoice_date.desc'
    );
    return is_array($res['data']) ? $res['data'] : [];
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
 * CRUD: Update clinical note
 */
function updateClinicalNote(int $noteId, string $noteText, bool $isPrivate): array {
    return supabase_request('clinical_notes', 'PATCH',
        ['note_text' => $noteText, 'is_private' => $isPrivate, 'updated_at' => date('c')],
        'note_id=eq.' . $noteId
    );
}

/**
 * CRUD: Delete clinical note
 */
function deleteClinicalNote(int $noteId): array {
    return supabase_request('clinical_notes', 'DELETE', [], 'note_id=eq.' . $noteId);
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

/**
 * Helper: status badge CSS class
 */
function patientStatusBadge(string $status): string {
    return match($status) {
        'active'   => 'bg-soft-success text-success',
        'inactive' => 'bg-soft-danger text-danger',
        default    => 'bg-soft-secondary text-secondary',
    };
}