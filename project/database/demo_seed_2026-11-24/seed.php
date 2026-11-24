<?php
// Seed demo records: completed Oct/Nov appointments with treatments + billing.
// Usage: php seed.php dry|run <rollback.json>
require __DIR__ . '/../../dbconfig.php';
$mode = $argv[1] ?? 'dry';
$rbFile = $argv[2] ?? __DIR__ . '/seed_rollback.json';
$RUN = $mode === 'run';

$DENT = [1 => 9, 2 => 11, 3 => 8];                       // branch => dentist_id
$DNAME = [1 => 'Chelo', 2 => 'Joel', 3 => 'Carl'];
$ADMIN = [1 => 'Franco', 2 => 'Ledona', 3 => 'Mendoza'];

// services: [service_id, tooth, surfaces]
$P = [
 // ---- Group A: patients registered Sep/Oct ----
 ['name'=>'Angela Mae Cruz Villanueva','g'=>'Female','bd'=>'1995-03-14','b'=>1,'created'=>'2026-09-28T10:12:00+08:00','phone'=>'+639171234501',
  'addr'=>['Blk 4 Lot 12 Sampaguita St.','Bagong Silang','Caloocan City','Metro Manila','1428'],'cc'=>'Tooth sensitivity and cleaning','ref'=>'Facebook','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-10-06','09:00',[[1,null,null],[4,null,null]],[['full','cash']],'Oral prophylaxis done. Mild gingivitis noted; advised flossing.','Completed',5],
   ['2026-11-03','10:00',[[5,'14','O']],[['full','gcash']],'Composite filling on #14 occlusal. Patient tolerated well.','Completed',null],
  ]],
 ['name'=>'Mark Anthony Dela Rosa','g'=>'Male','bd'=>'1988-07-22','b'=>1,'created'=>'2026-10-02T14:30:00+08:00','phone'=>'+639181234502',
  'addr'=>['88 Rizal Ave. Ext.','Grace Park East','Caloocan City','Metro Manila','1403'],'cc'=>'Pain on lower right molar','ref'=>'Walk-in','allergies'=>['Penicillin'],'cond'=>['Hypertension'],
  'visits'=>[
   ['2026-10-14','13:00',[[1,null,null],[2,'30',null]],[['full','cash']],'Extraction of #30 (non-restorable caries). BP 130/85 before procedure. Post-op instructions given.','Completed',4],
   ['2026-11-11','14:00',[[1,null,null]],[['full','cash']],'Post-extraction check: socket healing well.','Completed',null],
  ]],
 ['name'=>'Kristine Joy Bautista Ramos','g'=>'Female','bd'=>'2001-11-05','b'=>2,'created'=>'2026-10-01T09:05:00+08:00','phone'=>'+639271234503',
  'addr'=>['15 Shaw Blvd.','Wack-Wack Greenhills','Mandaluyong City','Metro Manila','1550'],'cc'=>'Wants whiter teeth','ref'=>'Instagram','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-10-09','10:30',[[6,null,null]],[['full','maya']],'In-office whitening, 2 shades lighter. Avoid colored drinks 48 h.','Completed',5],
  ]],
 ['name'=>'Jerome Lopez Garcia','g'=>'Male','bd'=>'1979-01-30','b'=>2,'created'=>'2026-10-12T11:20:00+08:00','phone'=>'+639281234504',
  'addr'=>['230 Boni Ave.','Barangka Ilaya','Mandaluyong City','Metro Manila','1550'],'cc'=>'Throbbing pain upper/lower left','ref'=>'Referred by friend','allergies'=>[],'cond'=>['Diabetes'],
  'visits'=>[
   ['2026-10-20','09:00',[[7,'19',null]],[['3000','bank']],'Root canal #19 completed. Crown recommended; scheduled follow-up.','Follow-up Required',null],
   ['2026-11-17','09:00',[[8,'19',null]],[['2000','bank','2026-11-17'],['2000','cash','2026-11-21']],'Porcelain crown seated on #19. Occlusion adjusted.','Completed',5],
  ]],
 ['name'=>'Patricia Anne Mendoza Lim','g'=>'Female','bd'=>'1992-05-18','b'=>3,'created'=>'2026-09-15T15:40:00+08:00','phone'=>'+639391234505',
  'addr'=>['12 Kalayaan Ave.','Pinyahan','Quezon City','Metro Manila','1100'],'cc'=>'Monthly brace adjustment','ref'=>'Google','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-10-08','11:00',[[3,null,null]],[['full','cash']],'Archwire changed; elastics given.','In Treatment',null],
   ['2026-11-05','11:00',[[3,null,null]],[['full','gcash']],'Brace adjustment, progress on track.','In Treatment',5],
   ['2026-11-19','15:00',[[3,null,null],[4,null,null]],[['full','cash']],'Brace adjustment plus cleaning around brackets.','In Treatment',null],
  ]],
 ['name'=>'Rafael Manuel Torres','g'=>'Male','bd'=>'1985-09-09','b'=>3,'created'=>'2026-10-10T08:50:00+08:00','phone'=>'+639401234506',
  'addr'=>['45 Tandang Sora Ave.','Culiat','Quezon City','Metro Manila','1128'],'cc'=>'Loose upper tooth','ref'=>'Walk-in','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-10-17','10:00',[[1,null,null],[2,'1',null]],[['full','cash']],'Extraction #1 (mobile, periodontal). Gauze and meds instructions.','Completed',4],
  ]],
 ['name'=>'Bea Camille Aquino Flores','g'=>'Female','bd'=>'2012-02-12','b'=>1,'created'=>'2026-10-18T13:15:00+08:00','phone'=>'+639151234507',
  'addr'=>['7 Mabini St.','Poblacion','Caloocan City','Metro Manila','1400'],'cc'=>'Cavity on upper molar (minor, with mother)','ref'=>'Family','allergies'=>[],'cond'=>[],
  'guardian'=>['Lorna Aquino Flores','Mother','+639151234577'],
  'visits'=>[
   ['2026-10-24','09:30',[[4,null,null],[5,'3','OB']],[['full','cash']],'Cleaning and filling #3. Guardian present and consented.','Completed',5],
  ]],
 ['name'=>'Nathaniel Ong Castillo','g'=>'Male','bd'=>'1998-12-01','b'=>2,'created'=>'2026-10-22T16:00:00+08:00','phone'=>'+639061234508',
  'addr'=>['301 Pioneer St.','Highway Hills','Mandaluyong City','Metro Manila','1554'],'cc'=>'Routine cleaning','ref'=>'Facebook','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-10-29','14:30',[[4,null,null]],[['full','credit_card']],'Scaling and polishing. Small caries on #30 noted.','Completed',null],
   ['2026-11-20','13:30',[[5,'30','MO']],[['400','cash']],'Composite filling #30 MO.','Completed',4],
  ]],
 ['name'=>'Liza Marie Gonzales Navarro','g'=>'Female','bd'=>'1970-04-25','b'=>3,'created'=>'2026-10-20T10:00:00+08:00','phone'=>'+639561234509',
  'addr'=>['19 Visayas Ave.','Vasra','Quezon City','Metro Manila','1128'],'cc'=>'Broken front tooth','ref'=>'Referred by Patricia Lim','allergies'=>['Latex'],'cond'=>['Asthma'],
  'visits'=>[
   ['2026-10-27','13:00',[[7,'8',null]],[['full','debit_card']],'Root canal #8 (fractured, pulp exposed). Temporary filling placed.','Follow-up Required',null],
   ['2026-11-13','13:00',[[8,'8',null]],[['full','bank']],'Crown #8 cemented; shade A2 matched.','Completed',5],
  ]],
 ['name'=>'Joshua David Pascual','g'=>'Male','bd'=>'2003-06-17','b'=>1,'created'=>'2026-10-30T12:45:00+08:00','phone'=>'+639761234510',
  'addr'=>['60 A. Mabini St.','Sangandaan','Caloocan City','Metro Manila','1408'],'cc'=>'First check-up','ref'=>'TikTok','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-11-07','11:00',[[1,null,null],[4,null,null]],[['full','gcash']],'Initial exam and cleaning. No caries.','Completed',5],
  ]],
 // ---- Group B: new patients registered this November (not Nov 24) ----
 ['name'=>'Samantha Rose Del Mundo','g'=>'Female','bd'=>'1999-08-03','b'=>2,'created'=>'2026-11-02T09:30:00+08:00','phone'=>'+639171234511',
  'addr'=>['88 Maysilo Circle','Plainview','Mandaluyong City','Metro Manila','1550'],'cc'=>'Cleaning and whitening','ref'=>'Instagram','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-11-06','09:30',[[1,null,null],[4,null,null]],[['full','cash']],'Exam and cleaning; whitening scheduled.','Completed',null],
   ['2026-11-21','10:00',[[6,null,null]],[['full','gcash']],'Whitening session done, 3 shades lighter.','Completed',5],
  ]],
 ['name'=>'Carlo Miguel Fernandez Uy','g'=>'Male','bd'=>'1990-10-10','b'=>3,'created'=>'2026-11-04T17:10:00+08:00','phone'=>'+639281234512',
  'addr'=>['5 Scout Tobias St.','Laging Handa','Quezon City','Metro Manila','1103'],'cc'=>'Impacted wisdom tooth pain','ref'=>'Google','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-11-10','10:30',[[1,null,null],[2,'17',null]],[['full','cash']],'Extraction #17. Prescribed analgesic; follow-up in 1 week if swelling.','Completed',4],
  ]],
 ['name'=>'Jasmine Nicole Ramos Tan','g'=>'Female','bd'=>'2005-01-21','b'=>1,'created'=>'2026-11-09T08:20:00+08:00','phone'=>'+639391234513',
  'addr'=>['22 P. Zamora St.','Bagong Barrio','Caloocan City','Metro Manila','1400'],'cc'=>'Two cavities','ref'=>'Facebook','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-11-14','13:30',[[5,'19','O'],[5,'20','OD']],[['800','maya']],'Fillings on #19 O and #20 OD.','Completed',5],
  ]],
 ['name'=>'Daniel Jose Santiago','g'=>'Male','bd'=>'1982-03-03','b'=>2,'created'=>'2026-11-13T14:00:00+08:00','phone'=>'+639401234514',
  'addr'=>['140 Martinez St.','Addition Hills','Mandaluyong City','Metro Manila','1550'],'cc'=>'Severe toothache lower right','ref'=>'Walk-in','allergies'=>['Ibuprofen'],'cond'=>[],
  'visits'=>[
   ['2026-11-18','11:00',[[7,'30',null]],[['2500','cash']],'Root canal #30, canals cleaned and obturated. Crown advised.','Follow-up Required',null],
  ]],
 ['name'=>'Hannah Grace Villareal Co','g'=>'Female','bd'=>'1996-12-12','b'=>3,'created'=>'2026-11-18T10:40:00+08:00','phone'=>'+639561234515',
  'addr'=>['33 Mother Ignacia Ave.','South Triangle','Quezon City','Metro Manila','1103'],'cc'=>'Check-up and cleaning','ref'=>'Referred by friend','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-11-22','14:00',[[1,null,null],[4,null,null]],[['full','cash']],'Exam and prophylaxis; good oral hygiene.','Completed',5],
  ]],
];

function req($t, $m, $d = [], $q = '') {
    $r = supabase_request($t, $m, $d, $q);
    if ($r['error'] || $r['status'] >= 300) { fwrite(STDERR, "FAIL $m $t: " . json_encode($r['error']) . " " . json_encode($d) . "\n"); throw new Exception("stop"); }
    return $r['data'];
}
function nextNum($t, $col) { $d = req($t, 'GET', [], "select=$col&order=$col.desc&limit=1"); return (int)preg_replace('/\D/', '', $d[0][$col] ?? '0') + 1; }
function code($p, $n) { return $p . str_pad($n, 5, '0', STR_PAD_LEFT); }
function ts($date, $time, $addMin = 0) { return date('c', strtotime("$date $time Asia/Manila") + $addMin * 60); }

$svc = []; foreach (req('services', 'GET', [], 'select=service_id,service_name,base_price,duration_minutes') as $s) $svc[$s['service_id']] = $s;
$br = []; foreach (req('branches', 'GET', [], 'select=branch_id,operating_days') as $b) $br[$b['branch_id']] = $b['operating_days'];
$exc = req('branch_schedule_exceptions', 'GET', [], 'select=*');
$busy = []; foreach (req('appointments', 'GET', [], 'select=branch_id,appointment_date,appointment_time,status&status=not.in.(cancelled,no_show)') as $a) $busy[$a['branch_id'] . '|' . $a['appointment_date'] . '|' . substr($a['appointment_time'], 0, 5)] = 1;

$n = ['pat' => nextNum('patients', 'patient_code'), 'apt' => nextNum('appointments', 'appointment_code'), 'tr' => nextNum('treatments', 'treatment_code'), 'inv' => nextNum('invoices', 'invoice_code')];
echo "Next codes: " . json_encode($n) . "\n";

// ---- validate the plan ----
$problems = [];
foreach ($P as $p) foreach ($p['visits'] as $v) {
    [$d, $t] = $v; $dow = (int)date('w', strtotime($d));
    if (!in_array($dow, $br[$p['b']])) $problems[] = "$d closed for branch {$p['b']}";
    if ($d >= '2026-11-24') $problems[] = "$d not in the past";
    if ($d < substr($p['created'], 0, 10)) $problems[] = "{$p['name']} visit before registration";
    if (isset($busy[$p['b'] . "|$d|$t"])) $problems[] = "slot taken b{$p['b']} $d $t";
    foreach ($exc as $e) if (($e['branch_id'] ?? null) == $p['b'] && ($e['exception_date'] ?? $e['date'] ?? '') === $d) $problems[] = "exception on $d b{$p['b']}: " . json_encode($e);
}
$visits = array_sum(array_map(fn($p) => count($p['visits']), $P));
echo count($P) . " patients, $visits completed appointments\n";
if ($problems) { echo "PROBLEMS:\n" . implode("\n", $problems) . "\n"; exit(1); }
echo "Plan OK\n";
if (!$RUN) exit(0);

$rb = ['patients' => [], 'patient_profiles' => [], 'appointments' => [], 'appointment_services' => [], 'vital_signs' => [], 'treatments' => [], 'treatment_procedures' => [], 'clinical_notes' => [], 'invoices' => [], 'payments' => [], 'patient_feedback' => []];
$save = function () use (&$rb, $rbFile) { file_put_contents($rbFile, json_encode($rb, JSON_PRETTY_PRINT)); };
$totalBilled = 0; $totalPaid = 0;
try {
foreach ($P as $p) {
    $b = $p['b']; $last = end($p['visits'])[0];
    $pat = req('patients', 'POST', ['patient_code' => code('PAT-', $n['pat']++), 'full_name' => $p['name'], 'contact_number' => $p['phone'], 'gender' => $p['g'],
        'birthdate' => $p['bd'], 'branch_id' => $b, 'last_visit' => $last, 'status' => 'active', 'created_at' => $p['created'], 'updated_at' => $p['created']])[0];
    $pid = $pat['patient_id']; $rb['patients'][] = $pid; $save();
    $g = $p['guardian'] ?? [null, null, null];
    $prof = req('patient_profiles', 'POST', ['patient_id' => $pid, 'civil_status' => (date('Y') - (int)substr($p['bd'], 0, 4)) > 27 ? 'Married' : 'Single', 'nationality' => 'Filipino',
        'address' => $p['addr'][0], 'barangay' => $p['addr'][1], 'city' => $p['addr'][2], 'province' => $p['addr'][3], 'zip' => $p['addr'][4],
        'chief_complaint' => $p['cc'], 'referral_source' => $p['ref'], 'allergies' => $p['allergies'], 'conditions' => $p['cond'],
        'guardian_name' => $g[0], 'guardian_relation' => $g[1], 'guardian_phone' => $g[2], 'created_at' => $p['created'], 'updated_at' => $p['created']])[0];
    $rb['patient_profiles'][] = $prof['profile_id']; $save();

    foreach ($p['visits'] as $vi => $v) {
        [$d, $t, $lines, $pays, $note, $stage, $rating] = $v;
        $dur = 0; $cost = 0; $sids = [];
        foreach ($lines as $l) { $cost += (float)$svc[$l[0]]['base_price']; if (!in_array($l[0], $sids)) { $sids[] = $l[0]; $dur += (int)$svc[$l[0]]['duration_minutes']; } }
        $booked = max(strtotime($p['created']) + 600, strtotime("$d 09:00 Asia/Manila") - 86400 * (3 + $vi));
        $done = ts($d, $t, $dur + 10);
        $apt = req('appointments', 'POST', ['appointment_code' => code('APT-', $n['apt']++), 'patient_id' => $pid, 'branch_id' => $b, 'dentist_id' => $DENT[$b], 'service_id' => $sids[0],
            'appointment_date' => $d, 'appointment_time' => "$t:00", 'appointment_type' => 'Consultation', 'status' => 'completed', 'payment_status' => 'unpaid',
            'duration_minutes' => max(30, $dur), 'created_at' => date('c', $booked), 'updated_at' => $done])[0];
        $aid = $apt['appointment_id']; $rb['appointments'][] = $aid; $save();
        foreach ($sids as $i => $sid) { $r = req('appointment_services', 'POST', ['appointment_id' => $aid, 'service_id' => $sid, 'sort_order' => $i + 1, 'created_at' => date('c', $booked)])[0]; $rb['appointment_services'][] = $r['id']; }
        $vs = req('vital_signs', 'POST', ['appointment_id' => $aid, 'patient_id' => $pid, 'blood_pressure' => in_array('Hypertension', $p['cond']) ? '130/85' : (110 + ($pid % 4) * 5) . '/' . (70 + ($pid % 3) * 5),
            'pulse_rate' => 68 + ($pid * 7 + $vi * 3) % 20, 'temperature' => 36.4 + (($pid + $vi) % 5) / 10, 'checked_by' => $ADMIN[$b], 'checked_at' => ts($d, $t, -5), 'created_at' => ts($d, $t, -5)])[0];
        $rb['vital_signs'][] = $vs['vital_sign_id'];
        $teeth = implode(', ', array_values(array_unique(array_filter(array_map(fn($l) => $l[1], $lines)))));
        $tr = req('treatments', 'POST', ['treatment_code' => code('TR-', $n['tr']++), 'appointment_id' => $aid, 'patient_id' => $pid, 'dentist_id' => $DENT[$b], 'branch_id' => $b,
            'service_id' => $sids[0], 'tooth_number' => $teeth ?: null, 'procedure_notes' => $note, 'cost' => $cost, 'current_stage' => $stage, 'status' => 'completed',
            'treatment_date' => $d, 'created_at' => ts($d, $t), 'updated_at' => $done])[0];
        $tid = $tr['treatment_id']; $rb['treatments'][] = $tid; $save();
        $procs = array_map(fn($l) => ['treatment_id' => $tid, 'service_id' => $l[0], 'tooth_number' => $l[1], 'surfaces' => $l[2], 'unit_price' => (float)$svc[$l[0]]['base_price'],
            'status' => 'completed', 'created_by' => 1, 'created_at' => $done], $lines);
        foreach (req('treatment_procedures', 'POST', $procs) as $r) $rb['treatment_procedures'][] = $r['procedure_id'];
        $cn = req('clinical_notes', 'POST', ['patient_id' => $pid, 'appointment_id' => $aid, 'dentist_id' => $DENT[$b], 'branch_id' => $b, 'note_text' => $note, 'note_date' => $d,
            'created_by' => $DNAME[$b], 'is_private' => false, 'created_at' => $done, 'updated_at' => $done])[0];
        $rb['clinical_notes'][] = $cn['note_id'];
        // invoice + payments
        $paid = 0; foreach ($pays as $py) $paid += $py[0] === 'full' ? $cost : (float)$py[0];
        $paid = min($paid, $cost); $status = $paid <= 0 ? 'unpaid' : ($paid >= $cost ? 'paid' : 'partial');
        $lastPay = end($pays)[2] ?? $d;
        $inv = req('invoices', 'POST', ['invoice_code' => code('INV-', $n['inv']++), 'appointment_id' => $aid, 'treatment_id' => $tid, 'patient_id' => $pid, 'branch_id' => $b,
            'subtotal_amount' => $cost, 'discount_amount' => 0, 'total_amount' => $cost, 'amount_paid' => $paid, 'payment_status' => $status, 'invoice_date' => $d,
            'created_at' => $done, 'updated_at' => ts($lastPay, $t, $dur + 20)])[0];
        $iid = $inv['invoice_id']; $rb['invoices'][] = $iid; $save();
        foreach ($pays as $py) {
            $amt = $py[0] === 'full' ? $cost : (float)$py[0]; $pd = $py[2] ?? $d;
            $pr = req('payments', 'POST', ['invoice_id' => $iid, 'amount' => $amt, 'payment_method' => $py[1], 'payment_date' => $pd, 'recorded_by' => $ADMIN[$b],
                'notes' => $status === 'paid' ? null : 'Partial payment', 'created_at' => ts($pd, $t, $dur + 20)])[0];
            $rb['payments'][] = $pr['payment_id'];
        }
        req('appointments', 'PATCH', ['payment_status' => $status], 'appointment_id=eq.' . $aid);
        if ($rating) {
            $msgs = [5 => 'Very gentle and explained everything clearly. Highly recommended!', 4 => 'Good service, a bit of waiting time but the dentist was kind.'];
            $fb = req('patient_feedback', 'POST', ['appointment_id' => $aid, 'patient_id' => $pid, 'branch_id' => $b, 'dentist_id' => $DENT[$b], 'category' => 'dentist',
                'rating' => $rating, 'message' => $msgs[$rating], 'created_at' => ts($d, '18:00', 60 * 24)])[0];
            $rb['patient_feedback'][] = $fb['feedback_id'];
        }
        $save();
        $totalBilled += $cost; $totalPaid += $paid;
        printf("  %s  %-30s %s b%d  %s  P%s  %s\n", $apt['appointment_code'], $p['name'], $d, $b, $inv['invoice_code'], number_format($cost), $status);
    }
}
} catch (Exception $e) { $save(); echo "STOPPED — partial rows recorded in $rbFile\n"; exit(1); }
$save();
echo "DONE. Billed P" . number_format($totalBilled) . ", collected P" . number_format($totalPaid) . ". Rollback ids: $rbFile\n";
