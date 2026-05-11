<?php
// app/helpers.php

function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect($path) {
    header("Location: " . APP_URL . $path);
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
