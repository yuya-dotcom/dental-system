<?php
// =============================================================
//  controllers/portal_validation.php
//  Server-side rules for patient details entered in the Patient
//  Portal (registration and My Profile). These are authoritative;
//  assets/js/portal-validation.js mirrors them for instant feedback.
//
//  Each *_error() helper returns null when the value is acceptable,
//  otherwise a message that can be shown to the patient as-is.
// =============================================================

require_once __DIR__ . '/name_helper.php';

// Letters (any language, incl. ñ / accents), single spaces, hyphens and
// apostrophes between letters: "Juan", "Dela Cruz", "Santos-Reyes", "O'Brien".
const PV_NAME_RE = "/^[\p{L}\p{M}]+(?:[ '’-][\p{L}\p{M}]+)*$/u";
// Same, plus an abbreviation period — for names that may carry a title
// ("Dr. Santos", "Ma. Clara"): Referred By and Previous Dentist.
const PV_TITLED_NAME_RE = "/^[\p{L}\p{M}]+(?:\.?[ '’-][\p{L}\p{M}]+)*\.?$/u";
const PV_NAME_MAX = 100;

const CIVIL_STATUS_OPTIONS = ['Single', 'Married', 'Widowed', 'Separated'];
// The four choices offered at registration (a subset of GENDER_OPTIONS;
// My Profile still offers every GENDER_OPTIONS value).
const REGISTRATION_GENDER_OPTIONS = ['Male', 'Female', 'Non-binary', 'Prefer not to say'];

/** Person / referrer name. Empty is accepted only when $required is false. */
function pv_name_error(?string $value, string $label, bool $required, bool $allowTitle = false): ?string
{
    $v = trim(preg_replace('/\s+/u', ' ', (string)$value));
    if ($v === '') return $required ? "$label is required." : null;
    if (mb_strlen($v) > PV_NAME_MAX) return "$label is too long (" . PV_NAME_MAX . ' characters max).';
    if (!preg_match($allowTitle ? PV_TITLED_NAME_RE : PV_NAME_RE, $v)) {
        return "$label can only contain letters, spaces, hyphens (-) and apostrophes (')"
            . ($allowTitle ? ', and a period after a title (e.g. Dr.).' : '.');
    }
    return null;
}

/**
 * My Profile first / middle / last name: the name rule above WITHOUT hyphens
 * (letters, spaces, apostrophes). A hyphenated name already on file ($stored)
 * that is saved unchanged is still accepted, so existing records keep working.
 */
function pv_profile_name_error(?string $value, string $label, bool $required, ?string $stored): ?string
{
    $err = pv_name_error($value, $label, $required);
    if ($err !== null) return $err;
    $v = trim(preg_replace('/\s+/u', ' ', (string)$value));
    if (strpos($v, '-') === false) return null;
    $norm = fn($x) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$x)));
    if ($stored !== null && $norm($v) === $norm($stored)) return null;
    return "$label can only contain letters, spaces and apostrophes (').";
}

/** Birthdate: a real YYYY-MM-DD date from 1900 up to yesterday (Asia/Manila). */
function pv_birthdate_error(?string $value, bool $required = true): ?string
{
    $v = trim((string)$value);
    if ($v === '') return $required ? 'Birthdate is required.' : null;
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) return 'Please enter a valid birthdate.';
    if ($v < '1900-01-01') return 'Please enter a valid birthdate.';
    if ($v >= date('Y-m-d')) return 'Birthdate must be before today.';
    return null;
}

/**
 * Mobile number as stored: "+63" followed by the 10-digit number shown after
 * the +63 prefix in the forms, which always starts with 9 (+639XXXXXXXXX).
 */
function pv_mobile_error(?string $value, bool $required = true): ?string
{
    $v = trim((string)$value);
    if ($v === '') return $required ? 'Contact number is required.' : null;
    if (!preg_match('/^\+639\d{9}$/', $v)) {
        return 'Contact number must be 10 digits starting with 9 (e.g. 9XX XXX XXXX).';
    }
    return null;
}

/** Home / telephone number (optional): digits only, 7–8 digits. */
function pv_home_phone_error(?string $value): ?string
{
    $v = trim((string)$value);
    if ($v === '') return null;
    if (!preg_match('/^\d+$/', $v)) return 'Home phone can only contain numbers.';
    if (strlen($v) > 8) return 'Home phone can have at most 8 digits.';
    if (strlen($v) < 7) return 'Home phone must have 7 or 8 digits.';
    return null;
}

/** Philippine ZIP code: exactly 4 digits. */
function pv_zip_error(?string $value, bool $required): ?string
{
    $v = trim((string)$value);
    if ($v === '') return $required ? 'ZIP code is required.' : null;
    if (!preg_match('/^\d{4}$/', $v)) return 'ZIP code must be 4 digits.';
    return null;
}

/** Civil status: one of CIVIL_STATUS_OPTIONS. Returns the canonical value via $out. */
function pv_civil_status_error(?string $value, bool $required, ?string &$out = null): ?string
{
    $v = trim((string)$value);
    $out = null;
    if ($v === '') return $required ? 'Please select your civil status.' : null;
    foreach (CIVIL_STATUS_OPTIONS as $opt) {
        if (strcasecmp($v, $opt) === 0) { $out = $opt; return null; }
    }
    return 'Please select a valid civil status.';
}

/** Nationality: required at registration; a name-like value (Filipino, Guinea-Bissauan). */
function pv_nationality_error(?string $value, bool $required): ?string
{
    $v = trim((string)$value);
    if ($v === '') return $required ? 'Please select your nationality.' : null;
    if (mb_strlen($v) > 60 || !preg_match(PV_TITLED_NAME_RE, $v)) return 'Please select a valid nationality.';
    return null;
}
