<?php
// Removes ONLY the demo rows created by seed.php on 2026-11-24 (IDs in seed_rollback.json).
// Usage: php rollback.php confirm
if (($argv[1] ?? '') !== 'confirm') exit("Run: php rollback.php confirm\n");
require __DIR__ . '/../../dbconfig.php';
$rb = json_decode(file_get_contents(__DIR__ . '/seed_rollback.json'), true);
$pk = ['patient_feedback'=>'feedback_id','payments'=>'payment_id','invoices'=>'invoice_id','clinical_notes'=>'note_id',
       'treatment_procedures'=>'procedure_id','treatments'=>'treatment_id','vital_signs'=>'vital_sign_id',
       'appointment_services'=>'id','appointments'=>'appointment_id','patient_profiles'=>'profile_id','patients'=>'patient_id'];
foreach ($pk as $t => $col) {
    $ids = $rb[$t] ?? []; if (!$ids) continue;
    $r = supabase_request($t, 'DELETE', [], "$col=in.(" . implode(',', $ids) . ')');
    echo "$t: deleted " . count($ids) . ' (HTTP ' . $r['status'] . ")\n";
}
