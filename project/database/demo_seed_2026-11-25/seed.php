<?php
// Seed demo records (batch 2, 2026-11-25): 20 patients with completed Sep–Nov appointments, treatments + billing.
// Usage: php seed.php dry|run [rollback.json]
require __DIR__ . '/../../dbconfig.php';
$mode = $argv[1] ?? 'dry';
$rbFile = $argv[2] ?? __DIR__ . '/seed_rollback.json';
$RUN = $mode === 'run';

$DENT = [1 => 9, 2 => 11, 3 => 8];                       // branch => dentist_id
$DNAME = [1 => 'Chelo', 2 => 'Joel', 3 => 'Carl'];
$ADMIN = [1 => 'Franco', 2 => 'Ledona', 3 => 'Mendoza'];

// visit = [date, time, [[service_id, tooth, surfaces]...], [[amount|'full', method, pay_date?]...], note, stage, rating]
$P = [
 // ---------------- Caloocan (branch 1, Mon–Sat) ----------------
 ['name'=>'Maria Clara Santos Reyes','g'=>'Female','bd'=>'1987-02-11','b'=>1,'created'=>'2026-09-22T10:05:00+08:00','phone'=>'+639171234516',
  'addr'=>['21 Gen. Luis St.','Bagbaguin','Caloocan City','Metro Manila','1440'],'cc'=>'Food getting stuck between upper teeth','ref'=>'Facebook','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-10-01','10:00',[[1,null,null],[4,null,null]],[['full','cash']],'Exam and prophylaxis. Interproximal caries on #12 and #13.','Completed',null],
   ['2026-10-22','10:00',[[5,'13','MO'],[5,'12','D']],[['full','gcash']],'Composite fillings #13 MO and #12 D. Contacts restored.','Completed',5],
  ]],
 ['name'=>'Paolo Enrique Mercado','g'=>'Male','bd'=>'1993-06-05','b'=>1,'created'=>'2026-10-05T15:20:00+08:00','phone'=>'+639181234517',
  'addr'=>['9 Malolos Ave.','Maypajo','Caloocan City','Metro Manila','1410'],'cc'=>'Lingering pain on upper right molar','ref'=>'Google','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-10-10','13:00',[[7,'3',null]],[['2500','cash','2026-10-10'],['2500','gcash','2026-10-24']],'Root canal #3 (irreversible pulpitis). Crown advised.','Follow-up Required',null],
   ['2026-11-12','13:00',[[8,'3',null]],[['4000','bank','2026-11-12'],['4000','bank','2026-11-19']],'Porcelain-fused-to-metal crown seated on #3.','Completed',5],
  ]],
 ['name'=>'Erlinda Soriano Cabrera','g'=>'Female','bd'=>'1958-09-30','b'=>1,'created'=>'2026-10-15T09:40:00+08:00','phone'=>'+639271234518',
  'addr'=>['104 Samson Rd.','Sangandaan','Caloocan City','Metro Manila','1408'],'cc'=>'Loose lower front teeth','ref'=>'Referred by daughter','allergies'=>['Sulfa drugs'],'cond'=>['Hypertension','Diabetes'],
  'visits'=>[
   ['2026-10-19','09:00',[[1,null,null],[2,'24',null],[2,'25',null]],[['full','cash']],'Extraction #24 and #25 (grade III mobility). BP 130/85, blood sugar controlled. Partial denture discussed.','Completed',4],
   ['2026-11-16','09:00',[[1,null,null]],[['full','cash']],'Post-extraction review: sockets healed, no infection.','Completed',null],
  ]],
 ['name'=>'Kevin James Morales','g'=>'Male','bd'=>'2000-04-14','b'=>1,'created'=>'2026-10-26T18:15:00+08:00','phone'=>'+639281234519',
  'addr'=>['17 Biglang Awa St.','Grace Park West','Caloocan City','Metro Manila','1406'],'cc'=>'Tartar build-up','ref'=>'TikTok','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-10-31','14:00',[[4,null,null]],[['full','maya']],'Scaling and polishing; moderate calculus on lower anteriors.','Completed',4],
  ]],
 ['name'=>'Althea Joy Pangilinan','g'=>'Female','bd'=>'2014-08-19','b'=>1,'created'=>'2026-11-03T11:00:00+08:00','phone'=>'+639391234520',
  'addr'=>['3 Ilang-Ilang St.','Bagong Silang','Caloocan City','Metro Manila','1428'],'cc'=>'First dental visit (minor, with mother)','ref'=>'Family','allergies'=>[],'cond'=>[],
  'guardian'=>['Rowena Pangilinan','Mother','+639391234590'],
  'visits'=>[
   ['2026-11-09','15:00',[[1,null,null],[4,null,null]],[['full','cash']],'Exam and cleaning; small occlusal caries on #30. Guardian present.','Completed',null],
   ['2026-11-20','15:00',[[5,'30','O']],[['full','cash']],'Filling #30 O. Fluoride and brushing instructions given to guardian.','Completed',5],
  ]],
 ['name'=>'Ronaldo Bernardo Diaz','g'=>'Male','bd'=>'1975-12-02','b'=>1,'created'=>'2026-11-10T13:30:00+08:00','phone'=>'+639401234521',
  'addr'=>['55 10th Ave.','Grace Park East','Caloocan City','Metro Manila','1403'],'cc'=>'Stained teeth from smoking','ref'=>'Walk-in','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-11-17','10:30',[[6,null,null]],[['full','credit_card']],'In-office whitening, 2 shades lighter. Smoking cessation advised.','Completed',4],
  ]],
 ['name'=>'Trisha Mae Gutierrez','g'=>'Female','bd'=>'1997-03-27','b'=>1,'created'=>'2026-11-16T08:45:00+08:00','phone'=>'+639561234522',
  'addr'=>['78 Camarin Rd.','Camarin','Caloocan City','Metro Manila','1422'],'cc'=>'Sensitivity on lower left','ref'=>'Instagram','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-11-21','09:00',[[1,null,null],[4,null,null]],[['full','gcash']],'Exam and cleaning; caries on #19 DO.','Completed',null],
   ['2026-11-23','16:00',[[5,'19','DO']],[['full','cash']],'Composite filling #19 DO; sensitivity resolved.','Completed',5],
  ]],
 // ---------------- Mandaluyong (branch 2, Mon–Sat) ----------------
 ['name'=>'Miguel Antonio Reyes Sison','g'=>'Male','bd'=>'1991-01-15','b'=>2,'created'=>'2026-09-25T12:10:00+08:00','phone'=>'+639761234523',
  'addr'=>['12 Coronado St.','Hulo','Mandaluyong City','Metro Manila','1550'],'cc'=>'Ongoing braces, transferred from another clinic','ref'=>'Google','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-10-05','10:00',[[3,null,null]],[['full','cash']],'Records reviewed; archwire changed to 0.016 NiTi.','In Treatment',null],
   ['2026-11-02','10:00',[[3,null,null]],[['full','gcash']],'Brace adjustment; spaces closing well.','In Treatment',5],
   ['2026-11-23','10:00',[[3,null,null],[4,null,null]],[['full','cash']],'Adjustment plus cleaning around brackets.','In Treatment',null],
  ]],
 ['name'=>'Clarissa Dimaculangan Yap','g'=>'Female','bd'=>'1984-10-08','b'=>2,'created'=>'2026-10-07T10:25:00+08:00','phone'=>'+639171234524',
  'addr'=>['401 EDSA','Wack-Wack Greenhills','Mandaluyong City','Metro Manila','1555'],'cc'=>'Check-up and cleaning','ref'=>'Referred by friend','allergies'=>[],'cond'=>['Asthma'],
  'visits'=>[
   ['2026-10-13','14:00',[[1,null,null],[4,null,null]],[['full','debit_card']],'Exam and prophylaxis. Inhaler on hand. Caries #2 occlusal.','Completed',null],
   ['2026-10-27','14:00',[[5,'2','O']],[['full','cash']],'Composite filling #2 O.','Completed',5],
  ]],
 ['name'=>'Renato Cuevas Lagman','g'=>'Male','bd'=>'1966-05-21','b'=>2,'created'=>'2026-10-14T16:50:00+08:00','phone'=>'+639181234525',
  'addr'=>['66 San Francisco St.','Plainview','Mandaluyong City','Metro Manila','1550'],'cc'=>'Swelling near upper left premolar','ref'=>'Walk-in','allergies'=>['Penicillin'],'cond'=>['Hypertension'],
  'visits'=>[
   ['2026-10-21','11:00',[[7,'14',null]],[['3000','cash','2026-10-21'],['2000','bank','2026-11-04']],'Root canal #14; abscess drained. Clindamycin prescribed (penicillin allergy).','Follow-up Required',null],
   ['2026-11-19','11:00',[[8,'14',null]],[['full','credit_card']],'Crown cemented on #14; bite checked.','Completed',4],
  ]],
 ['name'=>'Andrea Nicole Velasco','g'=>'Female','bd'=>'2002-07-30','b'=>2,'created'=>'2026-10-28T19:05:00+08:00','phone'=>'+639271234526',
  'addr'=>['9 Kalentong St.','Daang Bakal','Mandaluyong City','Metro Manila','1550'],'cc'=>'Teeth whitening before graduation','ref'=>'Instagram','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-11-04','16:00',[[6,null,null]],[['full','gcash']],'Whitening, 3 shades lighter. White diet for 48 h.','Completed',5],
  ]],
 ['name'=>'Joaquin Rafael Mendoza Chua','g'=>'Male','bd'=>'2015-03-09','b'=>2,'created'=>'2026-11-05T09:15:00+08:00','phone'=>'+639281234527',
  'addr'=>['27 Boni Ave.','Malamig','Mandaluyong City','Metro Manila','1550'],'cc'=>'Toothache on upper molar (minor, with father)','ref'=>'Family','allergies'=>[],'cond'=>[],
  'guardian'=>['Rafael Chua','Father','+639281234597'],
  'visits'=>[
   ['2026-11-07','10:00',[[1,null,null],[4,null,null],[5,'3','O']],[['full','cash']],'Cleaning and filling #3 O. Guardian present and consented.','Completed',5],
  ]],
 ['name'=>'Princess Ann Belmonte Ocampo','g'=>'Female','bd'=>'1999-11-23','b'=>2,'created'=>'2026-11-11T14:35:00+08:00','phone'=>'+639391234528',
  'addr'=>['150 Sheridan St.','Buayang Bato','Mandaluyong City','Metro Manila','1552'],'cc'=>'Pain from lower wisdom tooth','ref'=>'Facebook','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-11-14','15:00',[[1,null,null],[2,'32',null]],[['full','cash']],'Extraction #32 (partially erupted, pericoronitis). Post-op care given.','Completed',4],
  ]],
 ['name'=>'Victor Hugo Santos Lacson','g'=>'Male','bd'=>'1980-02-17','b'=>2,'created'=>'2026-11-17T11:30:00+08:00','phone'=>'+639401234529',
  'addr'=>['8 Pines St.','Barangka Itaas','Mandaluyong City','Metro Manila','1550'],'cc'=>'Routine check-up','ref'=>'Google','allergies'=>['Aspirin'],'cond'=>[],
  'visits'=>[
   ['2026-11-19','16:00',[[1,null,null],[4,null,null]],[['full','maya']],'Exam and cleaning; caries #18 O and #31 B.','Completed',null],
   ['2026-11-23','14:00',[[5,'18','O'],[5,'31','B']],[['full','gcash']],'Composite fillings #18 O and #31 B.','Completed',5],
  ]],
 // ---------------- Quezon City (branch 3, Sun + Tue–Sat) ----------------
 ['name'=>'Gabriel Luis Navarro Tan','g'=>'Male','bd'=>'1994-09-12','b'=>3,'created'=>'2026-09-29T13:00:00+08:00','phone'=>'+639561234530',
  'addr'=>['40 Timog Ave.','Sacred Heart','Quezon City','Metro Manila','1103'],'cc'=>'Cleaning and check-up','ref'=>'Facebook','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-10-04','10:00',[[1,null,null],[4,null,null]],[['full','cash']],'Exam and prophylaxis; caries #15 occlusal.','Completed',null],
   ['2026-10-25','10:00',[[5,'15','O']],[['full','gcash']],'Composite filling #15 O.','Completed',5],
  ]],
 ['name'=>'Rowena Pascua Domingo','g'=>'Female','bd'=>'1972-01-04','b'=>3,'created'=>'2026-10-09T10:20:00+08:00','phone'=>'+639761234531',
  'addr'=>['19 Kamuning Rd.','Kamuning','Quezon City','Metro Manila','1103'],'cc'=>'Dark, painful upper front tooth','ref'=>'Referred by friend','allergies'=>[],'cond'=>['Diabetes'],
  'visits'=>[
   ['2026-10-15','09:00',[[7,'12',null]],[['full','bank']],'Root canal #12 (necrotic pulp). Crown recommended.','Follow-up Required',null],
   ['2026-11-07','09:00',[[8,'12',null]],[['4000','cash','2026-11-07'],['4000','gcash','2026-11-21']],'All-ceramic crown seated on #12, shade A3.','Completed',5],
  ]],
 ['name'=>'Ian Christopher Lozano','g'=>'Male','bd'=>'2004-05-16','b'=>3,'created'=>'2026-10-21T17:40:00+08:00','phone'=>'+639171234532',
  'addr'=>['88 Katipunan Ave.','Loyola Heights','Quezon City','Metro Manila','1108'],'cc'=>'Bleeding gums when brushing','ref'=>'TikTok','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-10-28','15:00',[[4,null,null]],[['full','cash']],'Deep scaling; gingivitis, flossing instructions given.','Completed',4],
  ]],
 ['name'=>'Mikaela Sofia Ferrer Dizon','g'=>'Female','bd'=>'2010-12-29','b'=>3,'created'=>'2026-10-30T09:55:00+08:00','phone'=>'+639181234533',
  'addr'=>['5 Maginhawa St.','Teachers Village East','Quezon City','Metro Manila','1101'],'cc'=>'Braces adjustment (minor, with mother)','ref'=>'Instagram','allergies'=>[],'cond'=>[],
  'guardian'=>['Josephine Ferrer Dizon','Mother','+639181234593'],
  'visits'=>[
   ['2026-11-01','11:00',[[3,null,null]],[['full','gcash']],'Brace adjustment, new elastics. Guardian present.','In Treatment',null],
   ['2026-11-15','11:00',[[3,null,null]],[['full','gcash']],'Brace adjustment; bracket on #7 re-bonded.','In Treatment',5],
  ]],
 ['name'=>'Ernesto Villanueva Ramirez','g'=>'Male','bd'=>'1961-07-07','b'=>3,'created'=>'2026-11-12T08:30:00+08:00','phone'=>'+639271234534',
  'addr'=>['230 Commonwealth Ave.','Holy Spirit','Quezon City','Metro Manila','1127'],'cc'=>'Broken upper molar','ref'=>'Walk-in','allergies'=>[],'cond'=>['Hypertension'],
  'visits'=>[
   ['2026-11-17','13:30',[[1,null,null],[2,'2',null]],[['full','cash']],'Extraction #2 (fractured root). BP 130/85 pre-op.','Completed',4],
  ]],
 ['name'=>'Camille Joyce Abad Santos','g'=>'Female','bd'=>'1990-04-02','b'=>3,'created'=>'2026-11-18T12:00:00+08:00','phone'=>'+639281234535',
  'addr'=>['14 Scout Limbaga St.','Laging Handa','Quezon City','Metro Manila','1103'],'cc'=>'Cleaning and whitening before wedding','ref'=>'Referred by Hannah Co','allergies'=>[],'cond'=>[],
  'visits'=>[
   ['2026-11-21','11:00',[[1,null,null],[4,null,null],[6,null,null]],[['full','credit_card']],'Exam, prophylaxis, then in-office whitening (3 shades).','Completed',5],
  ]],
];

// in-plan slot collisions (one branch, one slot)
$seen = [];
foreach ($P as $p) foreach ($p['visits'] as $v) { $k = $p['b'] . "|{$v[0]}|{$v[1]}"; if (isset($seen[$k])) exit("Duplicate slot in plan: $k\n"); $seen[$k] = 1; }

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
    if ($d >= '2026-11-25') $problems[] = "$d not in the past";
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
