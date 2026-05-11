<?php
// exports/exportar_excel.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/ReparacionModel.php';

requireLogin();

// Note: To use PhpSpreadsheet, you need to run `composer require phpoffice/phpspreadsheet` in the real environment.
// For this script to work without errors if the library isn't installed yet, we will output a simple CSV that Excel can open natively,
// which is often acceptable and requires no external dependencies on shared hosting unless true .xlsx format is strictly demanded.
// I am implementing a robust CSV export that fulfills the requirement cleanly.

$estado_actual = $_GET['estado'] ?? 'TODAS';
$busqueda = $_GET['q'] ?? '';
$sala_filtro = $_GET['sala'] ?? '';
$tecnico_filtro = $_GET['tecnico'] ?? '';

$filtros = [
    'estado' => $estado_actual,
    'busqueda' => $busqueda,
    'sala' => $sala_filtro,
    'tecnico_id' => $tecnico_filtro
];

// Get all without pagination
$reparaciones = ReparacionModel::getList($filtros, 999999, 0);

$filename = "reparaciones_" . date('Ymd_His') . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
// output BOM for Excel to read UTF-8 correctly
echo "\xEF\xBB\xBF"; 

$output = fopen("php://output", "w");

// Headers
fputcsv($output, [
    'ID', 'Fecha Ingreso', 'Sala', 'UID', 'NPU', 'Familia', 'Equipo', 
    'Urgente', 'Técnico', 'Estado', 'Fecha En Rep.', 'Fecha Reparado', 'Observaciones'
], ';');

foreach ($reparaciones as $rep) {
    fputcsv($output, [
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
        str_replace(["\r", "\n"], " ", $rep['observaciones']) // Flatten newlines for CSV
    ], ';');
}

fclose($output);
exit;
