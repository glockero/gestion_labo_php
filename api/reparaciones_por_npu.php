<?php
// api/reparaciones_por_npu.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

$npu = trim((string)($_GET['npu'] ?? ''));
if ($npu === '') {
    echo json_encode(['npu' => '', 'reparaciones' => []]);
    exit;
}

$pdo = getDbConnection();
$stmt = $pdo->prepare("
    SELECT r.id, r.fecha, r.equipo, r.sala, r.estado, r.urgente,
           COALESCE(t.nombre, r.tecnico_nombre_historico) AS tecnico_nombre
    FROM reparaciones r
    LEFT JOIN tecnicos t ON r.tecnico_id = t.id
    WHERE TRIM(r.npu) = ?
    ORDER BY r.fecha DESC, r.id DESC
");
$stmt->execute([$npu]);

$reparaciones = array_map(function ($r) {
    $r['fecha_fmt'] = formatDatetimeArg($r['fecha']);
    return $r;
}, $stmt->fetchAll());

echo json_encode([
    'npu' => $npu,
    'reparaciones' => $reparaciones,
]);
