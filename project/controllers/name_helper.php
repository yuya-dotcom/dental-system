<?php
// =============================================================
//  controllers/name_helper.php
//  Consistent capitalisation for person names.
//
//    normalize_person_name('PHILIP SANTOS')   → 'Philip Santos'
//    normalize_person_name('juan dela cruz')  → 'Juan Dela Cruz'
//    normalize_person_name('o\'BRIEN')        → "O'Brien"
//    normalize_person_name('DeVera')          → 'DeVera'   (kept)
//    normalize_suffix('jr')                   → 'Jr.'
//    normalize_full_name('JUAN CRUZ iii')     → 'Juan Cruz III'
//
//  Rule: a word (or hyphen part) is re-cased only when it is clearly
//  wrong — ALL CAPS, all lower-case, starting lower-case (caps-lock
//  typing), or two capitals in a row (KUPal → Kupal). A word that already starts upper-case and mixes cases
//  (DeVera, McArthur, LeBron) is left exactly as typed.
//
//  The same rules exist in JavaScript (normalizeNameInput) for the
//  live preview on the forms; this PHP version is the one that counts.
// =============================================================

// Loaded with require_once, so these are declared only once.

/** Re-case one hyphen-free part of a word (e.g. "SANTOS", "o'brien", "mcarthur"). */
function _name_fix_part(string $part): string
{
    if ($part === '') return '';

    $upper = mb_strtoupper($part, 'UTF-8');
    $lower = mb_strtolower($part, 'UTF-8');
    if ($upper === $lower) return $part;                 // no letters (e.g. "2nd"? digits) — leave

    $first        = mb_substr($part, 0, 1, 'UTF-8');
    $isAllUpper   = ($part === $upper);
    $isAllLower   = ($part === $lower);
    $startsLower  = ($first === mb_strtolower($first, 'UTF-8') && $first !== mb_strtoupper($first, 'UTF-8'));
    // Two capitals in a row inside a mixed-case word is a Shift/Caps slip (KUPal, JUan)
    $capsRun      = (bool)preg_match('/\p{Lu}{2}/u', $part);

    if (!$isAllUpper && !$isAllLower && !$startsLower && !$capsRun) {
        return $part;                                     // intentional mixed case: DeVera, McArthur
    }

    // Title-case: first letter upper, rest lower
    $out = mb_strtoupper(mb_substr($lower, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($lower, 1, null, 'UTF-8');

    // O'Brien, D'Souza, L'Amour — capitalise after a one-letter prefix + apostrophe
    $out = preg_replace_callback(
        "/^(\p{L})(['’])(\p{L})/u",
        fn($m) => $m[1] . $m[2] . mb_strtoupper($m[3], 'UTF-8'),
        $out
    );

    // McArthur, McDonald (Mac… is deliberately NOT changed: Macaraeg, Macapagal)
    $out = preg_replace_callback(
        "/^(Mc)(\p{L})/u",
        fn($m) => $m[1] . mb_strtoupper($m[2], 'UTF-8'),
        $out
    );

    return $out;
}

/** Normalise a full or partial person name. Returns '' for empty input. */
function normalize_person_name(?string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '') return '';

    $words = explode(' ', $name);
    foreach ($words as &$word) {
        // Hyphenated parts are handled separately: SANTOS-reyes → Santos-Reyes
        $word = implode('-', array_map('_name_fix_part', explode('-', $word)));
    }
    unset($word);

    return implode(' ', $words);
}

/** Normalise a suffix to the portal's standard forms; '' when empty. */
function normalize_suffix(?string $suffix): string
{
    $s = trim(preg_replace('/\s+/u', ' ', (string)$suffix));
    if ($s === '') return '';

    $key = strtolower(rtrim($s, '.'));
    $map = [
        'jr' => 'Jr.', 'sr' => 'Sr.',
        'ii' => 'II', 'iii' => 'III', 'iv' => 'IV', 'v' => 'V',
    ];
    return $map[$key] ?? normalize_person_name($s);
}

/**
 * Normalise a whole name stored in one field (patients.full_name),
 * where a suffix may be the last word: "JUAN CRUZ jr" → "Juan Cruz Jr."
 */
function normalize_full_name(?string $name): string
{
    $out = normalize_person_name($name);
    if ($out === '') return '';
    $words = explode(' ', $out);
    if (count($words) > 1) {
        $last = end($words);
        if (in_array(strtolower(rtrim($last, '.')), ['jr', 'sr', 'ii', 'iii', 'iv', 'v'], true)) {
            $words[count($words) - 1] = normalize_suffix($last);
        }
    }
    return implode(' ', $words);
}

// =============================================================
//  Addresses (home address, barangay, city / municipality, province)
// =============================================================

/** All-caps abbreviations that stay in capitals inside addresses. */
const ADDRESS_KEEP_UPPER = [
    'NCR', 'BGC', 'CAR', 'ARMM', 'BARMM', 'CALABARZON', 'MIMAROPA', 'SOCCSKSARGEN',
    'EDSA', 'LRT', 'MRT', 'SM', 'UP', 'PUP', 'UST', 'FEU', 'DLSU', 'PO', 'PSC',
];

/** Small words written in lower case unless they start the address. */
const ADDRESS_LOWER_WORDS = ['of', 'and'];

/**
 * Normalise the capitalisation of an address field:
 *   "123 main street, barangay kaligayahan" → "123 Main Street, Barangay Kaligayahan"
 *   "CITY OF LAS PIÑAS"                     → "City of Las Piñas"
 *   "unit 4b, 2ND AVE., bgc"                → "Unit 4b, 2nd Ave., BGC"
 * Only letters change. Numbers, ZIP codes, punctuation, hyphens and
 * apostrophes stay where they are, and intentional mixed case
 * (McKinley, DasmariñasVille) is kept as typed.
 */
function normalize_address_text(?string $text): string
{
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
    if ($text === '') return '';

    $seenWord = false;
    return preg_replace_callback("/[\p{L}\p{M}\p{N}'’]+/u", function ($m) use (&$seenWord) {
        $w = $m[0];

        // Anything with a digit: house / unit numbers, ZIP codes, "12-B".
        // Only ordinals get lower-case endings: 2ND → 2nd.
        if (preg_match('/\p{N}/u', $w)) {
            if (preg_match('/^(\d+)(st|nd|rd|th)$/iu', $w, $o)) {
                return $o[1] . strtolower($o[2]);
            }
            return $w;
        }

        $first = !$seenWord;
        $seenWord = true;

        $upper   = mb_strtoupper($w, 'UTF-8');
        $lower   = mb_strtolower($w, 'UTF-8');
        $uniform = ($w === $upper || $w === $lower); // typed all caps / all lower

        if ($uniform && in_array($upper, ADDRESS_KEEP_UPPER, true)) return $upper;
        if ($uniform && preg_match('/^(I{1,3}|IV|VI{0,3}|IX|X{1,3})$/u', $upper)) return $upper; // Phase II
        if ($uniform && !$first && in_array($lower, ADDRESS_LOWER_WORDS, true)) return $lower;

        return _name_fix_part($w); // same rules as names: O'Brien, McKinley, mixed case kept
    }, $text);
}

// ── Gender ───────────────────────────────────────────────────────────────────
// Stored as plain text in patients.gender (nullable VARCHAR).
const GENDER_OPTIONS = ['Male', 'Female', 'Non-binary', 'Another gender', 'Prefer not to say'];

/**
 * Returns the canonical option for $value (case-insensitive), '' when empty,
 * or null when it isn't a supported option — callers decide whether to
 * reject it or leave the stored value untouched.
 */
function normalize_gender($value): ?string
{
    $v = trim((string)$value);
    if ($v === '') return '';
    foreach (GENDER_OPTIONS as $opt) {
        if (strcasecmp($v, $opt) === 0) return $opt;
    }
    return null;
}
