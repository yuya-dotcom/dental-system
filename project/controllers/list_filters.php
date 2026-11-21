<?php
/* =============================================================
   controllers/list_filters.php
   Shared sort / search / date-range helpers for admin record lists.

   Query params (all optional, all validated here):
     sort  -> key of a page-defined whitelist  (never raw SQL)
     q     -> free-text search (ilike on whitelisted columns)
     from  -> YYYY-MM-DD   to -> YYYY-MM-DD

   Controls render with the ids listSort / listSearch / listFrom /
   listTo, which window._applyFilters (assets/js/admin.js) picks up
   automatically, together with any [data-list-param] element.
   ============================================================= */

if (!function_exists('listSort')) {

/**
 * Validated sort choice.
 * $options = ['key' => ['Label', 'col.desc,id.desc'], ...]
 * Returns [key, 'order=…'] — falls back to $default for unknown keys.
 */
function listSort(array $options, string $default): array
{
    $key = (string)($_GET['sort'] ?? '');
    if (!isset($options[$key])) $key = $default;
    return [$key, 'order=' . $options[$key][1]];
}

/** A strict YYYY-MM-DD query param, or '' when missing/invalid. */
function listDate(string $param): string
{
    $v = trim((string)($_GET[$param] ?? ''));
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}

/**
 * PostgREST filters for a date range on $col (inclusive on both ends).
 * Reversed ranges are swapped in place. For timestamp columns the
 * bounds use the server's timezone offset so "to" covers the whole day.
 */
function listDateRange(string $col, string &$from, string &$to, bool $timestamp = false): array
{
    if ($from && $to && $from > $to) [$from, $to] = [$to, $from];
    $q = [];
    if ($timestamp) {
        $tz = rawurlencode(date('P'));
        if ($from) $q[] = $col . '=gte.' . $from . 'T00:00:00' . $tz;
        if ($to)   $q[] = $col . '=lt.'  . date('Y-m-d', strtotime($to . ' +1 day')) . 'T00:00:00' . $tz;
    } else {
        if ($from) $q[] = $col . '=gte.' . $from;
        if ($to)   $q[] = $col . '=lte.' . $to;
    }
    return $q;
}

/** Trimmed search text from ?q= (max 80 chars). */
function listSearch(): string
{
    return mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
}

/**
 * PostgREST "or" filter matching $q (case-insensitive, partial) in any of $cols.
 * Quote, backslash and wildcard characters are stripped; the rest is matched literally.
 * Returns [] when there is nothing to search.
 */
function listSearchFilter(string $q, array $cols): array
{
    // The value is sent double-quoted, so commas/parentheses are literal; only quotes,
    // backslashes and wildcard characters need removing.
    $term = trim(preg_replace('/\s+/u', ' ', preg_replace('/["\\\\*%]/u', ' ', $q)));
    if ($term === '' || !$cols) return [];
    $v = rawurlencode('"*' . $term . '*"');
    return ['or=(' . implode(',', array_map(fn($c) => $c . '.ilike.' . $v, $cols)) . ')'];
}

/**
 * Page URL carrying the current filters (empty / '0' values dropped).
 * Always contains '?', so callers can append '&page=N'.
 */
function listUrl(string $page, array $params): string
{
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return $page . '?' . http_build_query($params);
}

// ── Rendering ────────────────────────────────────────────────

/**
 * Sortable column header. Clicking toggles between the two whitelisted sort
 * keys ($first is applied on the first click). $sortBase = listUrl() of the
 * current filters WITHOUT sort/page, so sorting keeps filters and resets to page 1.
 */
function listTh(string $label, string $first, string $second, string $current, string $sortBase, string $attrs = ''): string
{
    $active = $current === $first || $current === $second;
    $next   = $current === $first ? $second : $first;
    $dir    = !$active ? '' : ((str_ends_with($current, '_desc') || $current === 'newest') ? 'desc' : 'asc');
    $icon   = $dir === 'asc' ? 'bi bi-arrow-up' : ($dir === 'desc' ? 'bi bi-arrow-down' : 'bi bi-arrow-down-up th-sort-idle');
    $title  = 'Sort by ' . $label;
    return '<th' . ($attrs ? ' ' . $attrs : '') . ($active ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '') . '>'
         . '<a href="' . htmlspecialchars($sortBase . '&sort=' . rawurlencode($next)) . '" class="th-sort' . ($active ? ' active' : '') . '" title="' . htmlspecialchars($title) . '">'
         . htmlspecialchars($label) . '<i class="' . $icon . '" aria-hidden="true"></i></a></th>';
}

function listField(string $label, string $control, string $forId = '', string $extraClass = ''): string
{
    return '<div class="list-field' . ($extraClass ? ' ' . $extraClass : '') . '">'
         . '<label class="list-label"' . ($forId ? ' for="' . $forId . '"' : '') . '>' . htmlspecialchars($label) . '</label>'
         . $control . '</div>';
}

function listSearchControl(string $value, string $placeholder = 'Search…'): string
{
    $ctl = '<div class="list-search">'
         . '<i class="feather-search" aria-hidden="true"></i>'
         . '<input type="search" id="listSearch" value="' . htmlspecialchars($value) . '" placeholder="' . htmlspecialchars($placeholder) . '"'
         . ' autocomplete="off" onkeydown="if(event.key===\'Enter\'){event.preventDefault();applyFilters();}"'
         . ' onsearch="if(!this.value)applyFilters()">'
         . '</div>';
    return listField('Search', $ctl, 'listSearch', 'list-field-search');
}

function listSortControl(array $options, string $current, string $default): string
{
    $html = '<select id="listSort" class="js-custom-select" data-cs-filter="wide" data-default="' . htmlspecialchars($default) . '" onchange="applyFilters()">';
    foreach ($options as $key => [$label]) {
        $html .= '<option value="' . htmlspecialchars($key) . '"' . ($key === $current ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
    }
    return listField('Sort By', $html . '</select>', 'listSort');
}

function listDateRangeControl(string $from, string $to, string $label = 'Date Range'): string
{
    $ctl = '<div class="list-dates">'
         . '<input type="date" id="listFrom" class="js-date-picker" data-cs-filter value="' . htmlspecialchars($from) . '" placeholder="From" onchange="applyFilters()">'
         . '<span class="list-dates-sep">–</span>'
         . '<input type="date" id="listTo" class="js-date-picker" data-cs-filter value="' . htmlspecialchars($to) . '" placeholder="To" onchange="applyFilters()">'
         . '</div>';
    return listField($label, $ctl, '', 'list-field-dates');
}

/** A non-negative amount from ?$param (2 decimals), or '' when missing/invalid. */
function listAmount(string $param): string
{
    $v = trim((string)($_GET[$param] ?? ''));
    return (is_numeric($v) && (float)$v >= 0) ? rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') : '';
}

/** Min / max amount inputs mapped to ?min= and ?max= (applied on change or Enter). */
function listAmountRangeControl(string $min, string $max, string $label = 'Amount (₱)'): string
{
    $attrs = ' type="number" min="0" step="0.01" inputmode="decimal" class="list-input" onchange="applyFilters()"'
           . ' onkeydown="if(event.key===\'Enter\'){event.preventDefault();applyFilters();}"';
    $ctl = '<div class="list-dates">'
         . '<input id="listMin" data-list-param="min" placeholder="Min" value="' . htmlspecialchars($min) . '"' . $attrs . '>'
         . '<span class="list-dates-sep">–</span>'
         . '<input id="listMax" data-list-param="max" placeholder="Max" value="' . htmlspecialchars($max) . '"' . $attrs . '>'
         . '</div>';
    return listField($label, $ctl, 'listMin', 'list-field-amount');
}

/**
 * Generic filter dropdown. $options = [value => label]; '' is the "All" choice.
 * $param is the URL key it maps to (read by window._applyFilters).
 */
function listSelectControl(string $label, string $param, array $options, string $current, string $id = ''): string
{
    $id = $id ?: 'listF_' . $param;
    $html = '<select id="' . $id . '" class="js-custom-select" data-cs-filter data-list-param="' . htmlspecialchars($param) . '" onchange="applyFilters()">';
    foreach ($options as $val => $text) {
        $val = (string)$val;
        $html .= '<option value="' . htmlspecialchars($val) . '"' . ($val === $current ? ' selected' : '') . '>' . htmlspecialchars($text) . '</option>';
    }
    return listField($label, $html . '</select>', $id);
}

/** Reset link back to the page's default view; $active = number of non-default filters. */
function listResetControl(string $page, int $active): string
{
    return '<div class="list-field list-field-reset">'
         . '<a href="' . htmlspecialchars($page) . '" class="list-reset' . ($active ? '' : ' disabled') . '"'
         . ($active ? ' title="Clear all filters and sorting"' : ' aria-disabled="true" tabindex="-1"') . '>'
         . '<i class="feather-rotate-ccw" aria-hidden="true"></i> Reset'
         . ($active ? ' <span class="list-active-count">' . $active . '</span>' : '') . '</a></div>';
}

/** Number of values in $values that differ from the page default (for the Reset badge). */
function listActiveCount(array $values): int
{
    return count(array_filter($values, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0' && $v !== false));
}

/**
 * Empty-state row in the Appointment Records design (appointments-records.css .ar-empty):
 * icon chip, title, one helpful line. When filters are on, the line offers "Reset filters".
 * $icon may be prefixed "danger:" for a load error (red chip).
 */
function listEmpty(int $colspan, bool $filtered, string $title, string $text = '', string $icon = 'feather-inbox', string $resetPage = ''): string
{
    $e = fn($s) => htmlspecialchars($s, ENT_QUOTES);
    $tone = 't-slate';
    if (str_starts_with($icon, 'danger:')) { $tone = 't-red'; $icon = substr($icon, 7); }
    if ($filtered) {
        $icon  = 'feather-search';
        $title = 'No records match your filters';
        $textHtml = $resetPage
            ? '<a href="' . $e($resetPage) . '" class="fw-semibold">Reset filters</a> to see everything.'
            : 'Try a different search or filter.';
    } else {
        $textHtml = $e($text);
    }
    return '<tr class="rec-empty-row"><td colspan="' . $colspan . '" class="ar-empty">'
         . '<span class="an-ico ' . $tone . '"><i class="' . $e($icon) . '"></i></span>'
         . '<div class="ar-empty-title">' . $e($title) . '</div>'
         . ($textHtml !== '' ? '<div class="ar-empty-text">' . $textHtml . '</div>' : '')
         . '</td></tr>';
}

/**
 * Card-footer count line in the Appointment Records design (.ar-foot-count):
 * "Showing 1–10 of 42 patients  ⊗ Clear filters". $noun is singular; $plural defaults to $noun . 's'.
 */
function listFootCount(int $from, int $to, int $total, string $noun, string $resetPage = '', bool $filtered = false, string $plural = ''): string
{
    $e = fn($s) => htmlspecialchars($s, ENT_QUOTES);
    $plural = $plural !== '' ? $plural : $noun . 's';
    $h = '<p class="ar-foot-count mb-0"><span>';
    $h .= $total
        ? 'Showing <b>' . $from . '–' . $to . '</b> of <b>' . number_format($total) . '</b> ' . $e($total === 1 ? $noun : $plural)
        : 'No ' . $e($plural) . ' to show';
    $h .= '</span>';
    if ($filtered && $resetPage) {
        $h .= '<a href="' . $e($resetPage) . '" class="ar-clear"><i class="feather-x-circle"></i>Clear filters</a>';
    }
    return $h . '</p>';
}

}

/**
 * Windowed pager (‹ 1 … 4 5 6 … 12 ›) — same markup as the record pages.
 * $baseUrl = listUrl() of the current filters WITHOUT page. Empty when there is one page.
 */
function listPager(string $baseUrl, int $page, int $totalPages): string
{
    if ($totalPages <= 1) return '';
    $u   = fn(int $p) => htmlspecialchars($baseUrl . '&page=' . $p);
    $off = '<a class="text-muted" style="pointer-events:none;opacity:.4;" aria-disabled="true"><i class="bi bi-arrow-%s"></i></a>';
    $h   = '<ul class="list-unstyled d-flex align-items-center gap-1 mb-0 pagination-common-style" aria-label="Pages">';
    $h  .= '<li>' . ($page > 1 ? '<a href="' . $u($page - 1) . '" aria-label="Previous page"><i class="bi bi-arrow-left"></i></a>' : sprintf($off, 'left')) . '</li>';
    $wS = max(1, $page - 2); $wE = min($totalPages, $wS + 4); $wS = max(1, $wE - 4);
    if ($wS > 1) { $h .= '<li><a href="' . $u(1) . '">1</a></li>' . ($wS > 2 ? '<li><span class="px-1 text-muted">…</span></li>' : ''); }
    for ($p = $wS; $p <= $wE; $p++) {
        $h .= '<li><a href="' . $u($p) . '"' . ($p === $page ? ' class="active" aria-current="page"' : '') . '>' . $p . '</a></li>';
    }
    if ($wE < $totalPages) { $h .= ($wE < $totalPages - 1 ? '<li><span class="px-1 text-muted">…</span></li>' : '') . '<li><a href="' . $u($totalPages) . '">' . $totalPages . '</a></li>'; }
    $h .= '<li>' . ($page < $totalPages ? '<a href="' . $u($page + 1) . '" aria-label="Next page"><i class="bi bi-arrow-right"></i></a>' : sprintf($off, 'right')) . '</li>';
    return $h . '</ul>';
}
