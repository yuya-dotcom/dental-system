<?php
// =============================================================
//  controllers/staff_names.php — staff name parts ⇄ display names
//
//  Two stored formats, one person:
//    users.full_name     "Santos, Maria Cruz Jr."      (Account Records)
//    dentists.full_name  "Dr. Maria Cruz Santos Jr."   (shown on appointments,
//                                                       treatments, receipts …)
//  The parts (first / middle / last / suffix) convert between them, so an
//  account edit and a dentist edit keep each other's name in step.
// =============================================================
require_once __DIR__ . '/name_helper.php';

const STAFF_SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];

/** Staff gender choices: stored value => label (values match the Patient Portal's / DB check). */
const STAFF_GENDERS = ['Male' => 'Male', 'Female' => 'Female', 'Non-binary' => 'Non-Binary'];

/** A staff gender value from user input ('non-binary', 'Non-Binary' …), or null when not one of the choices. */
function staffGender($value): ?string
{
    $v = strtolower(trim((string)$value));
    foreach (array_keys(STAFF_GENDERS) as $g) if (strtolower($g) === $v) return $g;
    return null;
}

/** Clean parts: names capitalised, suffix from the known list ('' otherwise). */
function staffNameParts(array $p): array
{
    $suffix = normalize_suffix((string)($p['suffix'] ?? ''));
    return [
        'first_name'  => normalize_person_name((string)($p['first_name'] ?? '')),
        'middle_name' => normalize_person_name((string)($p['middle_name'] ?? '')),
        'last_name'   => normalize_person_name((string)($p['last_name'] ?? '')),
        'suffix'      => in_array($suffix, STAFF_SUFFIXES, true) ? $suffix : '',
    ];
}

/** Best-effort split of either stored format (or a plain "First Middle Last"). */
function staffSplitName(string $full): array
{
    $full  = trim(preg_replace('/\s+/u', ' ', $full));
    $full  = preg_replace('/^(dr|dra|doc)\.?\s+/i', '', $full);
    $parts = $full === '' ? [] : explode(' ', $full);
    $suffix = '';
    if (count($parts) > 1 && in_array(end($parts), STAFF_SUFFIXES, true)) $suffix = array_pop($parts);
    $joined = implode(' ', $parts);
    if (str_contains($joined, ',')) {                       // "Last, First Middle"
        [$last, $rest] = array_map('trim', explode(',', $joined, 2));
        $rest = $rest === '' ? [] : explode(' ', $rest);
        return ['first_name' => (string)array_shift($rest), 'middle_name' => implode(' ', $rest), 'last_name' => $last, 'suffix' => $suffix];
    }
    $last  = count($parts) > 1 ? (string)array_pop($parts) : '';
    $first = (string)array_shift($parts);
    return ['first_name' => $first, 'middle_name' => implode(' ', $parts), 'last_name' => $last, 'suffix' => $suffix];
}

/** "Dr. First Middle Last Suffix" */
function staffDentistName(array $p): string
{
    $name = trim(implode(' ', array_filter([$p['first_name'] ?? '', $p['middle_name'] ?? '', $p['last_name'] ?? '', $p['suffix'] ?? ''], 'strlen')));
    return $name === '' ? '' : 'Dr. ' . $name;
}

/** "Last, First Middle Suffix" */
function staffAccountName(array $p): string
{
    $given = trim(implode(' ', array_filter([$p['first_name'] ?? '', $p['middle_name'] ?? '', $p['suffix'] ?? ''], 'strlen')));
    $last  = trim((string)($p['last_name'] ?? ''));
    return trim($last . ($last !== '' && $given !== '' ? ', ' : '') . $given);
}

/** True when a database error is "this column doesn't exist" (SQL file not run yet). */
function staffMissingColumn($error): bool
{
    $msg = is_string($error) ? $error : json_encode($error);
    return (bool)preg_match('/column|PGRST204|42703|schema cache/i', (string)$msg);
}
