<?php
// exports/exportar_excel.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/ReparacionModel.php';
require_once __DIR__ . '/../app/XlsxBuilder.php';

requireLogin();

$estado_actual = $_GET['estado'] ?? 'TODAS';
$busqueda = $_GET['q'] ?? '';
$sala_filtro = $_GET['f_sala'] ?? '';
$tecnico_filtro = $_GET['f_tecnico'] ?? '';
$estado_filtro = $_GET['f_estado'] ?? '';
$anio_filtro = $_GET['f_anio'] ?? '';
$mes_filtro = $_GET['f_mes'] ?? '';
$dia_filtro = $_GET['f_dia'] ?? '';
$diasemana_filtro = $_GET['f_diasemana'] ?? '';

$filtros = [
    'estado' => $estado_actual,
    'busqueda' => $busqueda,
    'sala' => $sala_filtro,
    'tecnico_id' => $tecnico_filtro === 'SIN_ASIGNAR' ? null : $tecnico_filtro,
    'tecnico_sin_asignar' => $tecnico_filtro === 'SIN_ASIGNAR',
    'f_anio' => $anio_filtro,
    'f_mes' => $mes_filtro,
    'f_dia' => $dia_filtro,
    'f_diasemana' => $diasemana_filtro,
];

if ($estado_filtro) {
    $filtros['estado'] = $estado_filtro;
}

$reparaciones = ReparacionModel::getList($filtros, 999999, 0);

$rows = [];
foreach ($reparaciones as $rep) {
    $rows[] = [
        $rep['id'],
        $rep['fecha'],
        $rep['sala'],
        $rep['uid'],
        $rep['npu'],
        $rep['familia'],
        $rep['equipo'],
        $rep['urgente'],
        $rep['tecnico_nombre'] ?? 'Sin asignar',
        $rep['estado'],
        $rep['fecha_en_reparacion'],
        $rep['fecha_reparado'],
        str_replace(["\r", "\n"], ' ', (string) $rep['observaciones']),
    ];
}

$filename = 'reparaciones_' . date('Ymd_His') . '.xlsx';

XlsxBuilder::output($filename, 'Reparaciones', [
    'ID', 'Fecha Ingreso', 'Sala', 'UID', 'NPU', 'Familia', 'Equipo', 
    'Urgente', 'Técnico', 'Estado', 'Fecha En Rep.', 'Fecha Reparado', 'Observaciones'
], $rows);
