<?php
/**
 * Shared pieces of the Patient Portal "Show Filters" panel
 * (behaviour: assets/js/portal-filters.js, styles: portal-dashboard.css → .pfl-*).
 * Each page writes its own fields between pfl_panel_open() and pfl_panel_close().
 */

/** The Show Filters / Hide Filters button. */
function pfl_toggle(string $key): string
{
    $k = htmlspecialchars($key, ENT_QUOTES);
    return '<button type="button" class="pfl-toggle" aria-expanded="false" aria-controls="pfl_' . $k . '_panel">' .
        '<i class="fa-solid fa-sliders pfl-toggle-icon" aria-hidden="true"></i>' .
        '<span class="pfl-toggle-label">Show Filters</span>' .
        '<span class="pfl-count" hidden></span></button>';
}

function pfl_panel_open(string $key): string
{
    $k = htmlspecialchars($key, ENT_QUOTES);
    return '<div class="pfl-panel" id="pfl_' . $k . '_panel" hidden><div class="pfl-grid">';
}

/** Closes the field grid and adds the result count + Reset Filters. */
function pfl_panel_close(bool $hasDates = true): string
{
    return '</div>' .
        ($hasDates ? '<p class="pfl-warn" hidden><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> The "From" date is later than the "To" date.</p>' : '') .
        '<div class="pfl-foot"><span class="pfl-result" aria-live="polite"></span>' .
        '<button type="button" class="pfl-reset" disabled><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reset Filters</button>' .
        '</div></div>';
}

/** A filter <select>; options after "All" are filled by JS when $options is empty. */
function pfl_select(string $key, string $name, string $label, string $allLabel, array $options = []): string
{
    $id = 'pfl_' . $key . '_' . $name;
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $opts = '<option value="">' . $h($allLabel) . '</option>';
    foreach ($options as $v => $l) $opts .= '<option value="' . $h($v) . '">' . $h($l) . '</option>';
    return '<div class="pfl-field"><label class="pfl-label" for="' . $id . '">' . $h($label) . '</label>' .
        '<select id="' . $id . '" class="pf-select js-custom-select" data-cs-float data-pfl-field="' . $h($name) .
        '" data-pfl-all="' . $h($allLabel) . '">' . $opts . '</select></div>';
}

/** From / To date pair. */
function pfl_dates(string $key, string $label = 'Date'): string
{
    $out = '';
    foreach (['from' => 'From', 'to' => 'To'] as $name => $word) {
        $id = 'pfl_' . $key . '_' . $name;
        $out .= '<div class="pfl-field pfl-field-date"><label class="pfl-label" for="' . $id . '">' .
            htmlspecialchars($label . ' ' . strtolower($word), ENT_QUOTES) . '</label>' .
            '<input type="date" id="' . $id . '" class="pf-input js-date-picker" data-pfl-field="' . $name . '" placeholder="Any date"></div>';
    }
    return $out;
}

/** Text search box. */
function pfl_search(string $key, string $name, string $label, string $placeholder): string
{
    $id = 'pfl_' . $key . '_' . $name;
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    return '<div class="pfl-field pfl-field-search"><label class="pfl-label" for="' . $id . '">' . $h($label) . '</label>' .
        '<div class="pfl-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>' .
        '<input type="search" id="' . $id . '" class="pf-input" data-pfl-field="' . $h($name) . '" placeholder="' . $h($placeholder) . '" maxlength="60" autocomplete="off"></div></div>';
}
