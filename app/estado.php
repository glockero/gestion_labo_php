<?php
// app/estado.php

function normalizeEstadoLabel($estado) {
    return strtoupper(trim((string)$estado));
}

function isEstadoEntregado($estado) {
    return normalizeEstadoLabel($estado) === 'ENTREGADO';
}

function isEstadoReparado($estado) {
    return strpos(normalizeEstadoLabel($estado), 'REPARADO') === 0;
}

function isEstadoSinReparacion($estado) {
    return strpos(normalizeEstadoLabel($estado), 'SIN REPARACION') === 0;
}

function isEstadoCerrado($estado) {
    return isEstadoReparado($estado) || isEstadoSinReparacion($estado) || isEstadoEntregado($estado);
}

function isEstadoPendienteIntermedio($estado) {
    $estado = normalizeEstadoLabel($estado);
    return $estado === 'PENDIENTE DE REPUESTO' || $estado === 'ESPERANDO REPUESTO' || (strpos($estado, 'PEND') === 0 && $estado !== 'PEND. DE REVISION');
}

function isEstadoEnReparacion($estado) {
    $estado = normalizeEstadoLabel($estado);
    return $estado === 'EN REPARACION' || strpos($estado, 'EN PRUEBA') === 0;
}

function estadoCategoria($estado) {
    $estado = normalizeEstadoLabel($estado);

    if ($estado === 'PEND. DE REVISION') {
        return 'revision';
    }
    if (isEstadoEnReparacion($estado)) {
        return 'working';
    }
    if (isEstadoPendienteIntermedio($estado)) {
        return 'waiting';
    }
    if (isEstadoReparado($estado)) {
        return 'repaired';
    }
    if (isEstadoSinReparacion($estado)) {
        return 'unrepaired';
    }
    if (isEstadoEntregado($estado)) {
        return 'delivered';
    }

    return 'other';
}

function canTransitionEstado($estadoActual, $nuevoEstado, $role = 'admin') {
    $from = estadoCategoria($estadoActual);
    $to = estadoCategoria($nuevoEstado);

    if (normalizeEstadoLabel($estadoActual) === normalizeEstadoLabel($nuevoEstado)) {
        return true;
    }

    $adminMatrix = [
        'revision' => ['working', 'waiting', 'unrepaired'],
        'working' => ['waiting', 'repaired', 'unrepaired', 'revision'],
        'waiting' => ['working', 'repaired', 'unrepaired', 'revision'],
        'other' => ['working', 'waiting', 'repaired', 'unrepaired', 'revision'],
        'repaired' => ['delivered', 'working', 'waiting', 'revision'],
        'unrepaired' => ['delivered', 'working', 'waiting', 'revision'],
        'delivered' => [],
    ];

    $tecnicoMatrix = [
        'revision' => ['working', 'waiting', 'unrepaired'],
        'working' => ['waiting', 'repaired', 'unrepaired'],
        'waiting' => ['working', 'repaired', 'unrepaired'],
        'other' => ['working', 'waiting', 'repaired', 'unrepaired'],
        'repaired' => [],
        'unrepaired' => [],
        'delivered' => [],
    ];

    $matrix = $role === 'admin' ? $adminMatrix : $tecnicoMatrix;
    return in_array($to, $matrix[$from] ?? [], true);
}

function estadoTransitionRequiresComment($estadoActual, $nuevoEstado) {
    $to = estadoCategoria($nuevoEstado);
    $from = estadoCategoria($estadoActual);

    if (in_array($to, ['waiting', 'repaired', 'unrepaired', 'delivered'], true)) {
        return true;
    }

    if ($to === 'revision' && $from !== 'revision') {
        return true;
    }

    return false;
}
