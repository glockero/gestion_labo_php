<?php
// api/reparacion_tecnico.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/estado.php';
require_once __DIR__ . '/../app/historial.php';

requireLogin();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$userRole = $_SESSION['user_role'] ?? '';
$sessionTecnicoId = $_SESSION['tecnico_id'] ?? null;

if ($userRole !== 'admin' && $userRole !== 'tecnico') {
    http_response_code(403);
    echo json_encode(['error' => 'Acción no autorizada']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = $input['id'] ?? null;
$tecnicoId = $input['tecnico_id'] === '' ? null : $input['tecnico_id'];
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!validateCsrfToken($csrf)) {
    http_response_code(403);
    exit;
}

$pdo = getDbConnection();

$repStmt = $pdo->prepare("SELECT id, estado, tecnico_id FROM reparaciones WHERE id = ?");
$repStmt->execute([$id]);
$rep = $repStmt->fetch();
if (!$rep) {
    http_response_code(404);
    echo json_encode(['error' => 'No encontrado']);
    exit;
}

// Técnicos solo pueden auto-asignarse a una reparación sin dueño actual.
// Admin puede reasignar libremente (cambiar tecnico o desasignar).
if ($userRole === 'tecnico') {
    if (!empty($rep['tecnico_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Esta reparación ya tiene un técnico asignado.']);
        exit;
    }
    if (!$sessionTecnicoId || (int)$tecnicoId !== (int)$sessionTecnicoId) {
        http_response_code(403);
        echo json_encode(['error' => 'Solo podés asignarte a vos mismo.']);
        exit;
    }
}

// Get tech name
$tecName = 'Sin Asignar';
if ($tecnicoId) {
    $st = $pdo->prepare("SELECT nombre FROM tecnicos WHERE id = ?");
    $st->execute([$tecnicoId]);
    $tecName = $st->fetchColumn();
}

// Auto-transition: assigning a technician to a repair still in
// "PEND. DE REVISION" moves it forward to "EN REPARACION" and stamps
// fecha_en_reparacion. Matches the behavior of reparacion_nueva.php when
// a technician is selected on intake.
$estadoAnterior = $rep['estado'];
$autoTransicion = $tecnicoId !== null && normalizeEstadoLabel($estadoAnterior) === 'PEND. DE REVISION';
$nuevoEstado = $autoTransicion ? 'EN REPARACION' : null;

$updateFields = ["tecnico_id = ?"];
$updateParams = [$tecnicoId];
if ($autoTransicion) {
    $updateFields[] = "estado = ?";
    $updateParams[] = $nuevoEstado;
    $updateFields[] = "fecha_en_reparacion = COALESCE(fecha_en_reparacion, NOW())";
}
$updateParams[] = $id;

$sql = "UPDATE reparaciones SET " . implode(', ', $updateFields) . " WHERE id = ?";
$pdo->prepare($sql)->execute($updateParams);

registrarHistorial('REASIGNAR', "Asignado a: $tecName", $id);
if ($autoTransicion) {
    registrarHistorial('ESTADO', "$estadoAnterior -> $nuevoEstado (auto por asignación)", $id);
}

echo json_encode(['success' => true, 'auto_transicion' => $autoTransicion]);
