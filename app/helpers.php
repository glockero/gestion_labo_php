<?php
// app/helpers.php

if (!defined('MIN_PASSWORD_LENGTH')) {
    define('MIN_PASSWORD_LENGTH', 8);
}

function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect($path) {
    header("Location: " . APP_URL . $path);
    exit;
}

/**
 * Redirect to a path under the admin/ folder (sibling of public/).
 * Use this instead of redirect('/../admin/...'): the previous pattern relied
 * on browser path normalization and broke if APP_URL didn't end in /public.
 */
function adminRedirect($path) {
    $base = dirname(APP_URL);
    if ($base === '\\' || $base === '.') {
        $base = '';
    }
    header("Location: " . $base . '/admin' . $path);
    exit;
}

function formatDatetimeArg($datetimeStr) {
    if (!$datetimeStr) return '';
    $dt = new DateTime($datetimeStr);
    return $dt->format('d/m/Y H:i');
}

function getCurrentDatetime() {
    return date('Y-m-d H:i:s');
}

/**
 * Parse a free-text monetary value into a float, or null when not parseable.
 * Accepts "1234.56", "1234,56", "1.234,56" and prefixes like "$ ".
 */
function parseArsToFloat($value) {
    if ($value === null) return null;
    $s = trim((string)$value);
    if ($s === '') return null;
    $clean = str_replace(['$', ' '], '', $s);
    if (strpos($clean, ',') !== false && strpos($clean, '.') !== false) {
        $clean = str_replace('.', '', $clean);
        $clean = str_replace(',', '.', $clean);
    } elseif (strpos($clean, ',') !== false) {
        $clean = str_replace(',', '.', $clean);
    }
    return is_numeric($clean) ? (float)$clean : null;
}

/**
 * Format a free-text monetary value as Argentine pesos ("$ 1.234,56").
 * Accepts plain numbers ("1234.56", "1234"), Spanish-formatted strings
 * ("1.234,56"), and already-prefixed values ("$ 1234"). Returns the
 * original string untouched if it can't be parsed as a number, or null
 * if empty/null.
 */
function formatArs($value) {
    if ($value === null) return null;
    $s = trim((string)$value);
    if ($s === '') return null;

    $clean = str_replace(['$', ' '], '', $s);

    if (strpos($clean, ',') !== false && strpos($clean, '.') !== false) {
        // Mixed: assume "." is thousand sep and "," is decimal (es-AR format)
        $clean = str_replace('.', '', $clean);
        $clean = str_replace(',', '.', $clean);
    } elseif (strpos($clean, ',') !== false) {
        // Only comma: treat as decimal separator
        $clean = str_replace(',', '.', $clean);
    }

    if (!is_numeric($clean)) {
        return $s; // not parseable, leave as the user wrote it
    }

    return '$ ' . number_format((float)$clean, 2, ',', '.');
}

/**
 * Render a compact human-readable age string in Spanish from a datetime.
 * Examples: "Hoy", "Ayer", "3 dias", "2 sem", "4 meses".
 */
function formatAgeLabel($datetimeStr) {
    if (!$datetimeStr) return '';

    try {
        $start = new DateTime($datetimeStr);
        $now = new DateTime();
        $diff = $start->diff($now);
    } catch (Exception $e) {
        return '';
    }

    $days = (int)$diff->days;
    if ($days <= 0) return 'Hoy';
    if ($days === 1) return 'Ayer';
    if ($days < 7) return $days . ' dias';

    $weeks = (int)floor($days / 7);
    if ($weeks < 5) {
        return $weeks . ' sem';
    }

    $months = max(1, (int)floor($days / 30));
    return $months . ' mes' . ($months === 1 ? '' : 'es');
}
