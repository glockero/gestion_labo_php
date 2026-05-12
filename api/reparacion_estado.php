<?php
// api/reparacion_estado.php
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
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = $input['id'] ?? null;
$nuevoEstado = $input['estado'] ?? '';
$comentario = trim($input['comentario'] ?? '');
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!validateCsrfToken($csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF inválido']);
    exit;
}

if (!$id || !$nuevoEstado) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos incompletos']);
    exit;
}

$pdo = getDbConnection();
$stmt = $pdo->prepare("SELECT estado, observaciones, tecnico_id FROM reparaciones WHERE id = ?");
$stmt->execute([$id]);
$rep = $stmt->fetch();

if (!$rep) {
    http_response_code(404);
    echo json_encode(['error' => 'No encontrado']);
    exit;
}

$userRole = $_SESSION['user_role'] ?? '';
$tecnicoId = $_SESSION['tecnico_id'] ?? null;
$canManage = $userRole === 'admin' || ($userRole === 'tecnico' && $rep['tecnico_id'] && (int)$rep['tecnico_id'] === (int)$tecnicoId);

if (!$canManage) {
    http_response_code(403);
    echo json_encode(['error' => 'Acción no autorizada']);
    exit;
}

$estadoAnterior = $rep['estado'];
$nuevoEstadoNormalizado = normalizeEstadoLabel($nuevoEstado);

if (!canTransitionEstado($estadoAnterior, $nuevoEstado, $userRole)) {
    http_response_code(422);
    echo json_encode(['error' => 'Transición de estado no permitida']);
    exit;
}

if (estadoTransitionRequiresComment($estadoAnterior, $nuevoEstado) && $comentario === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Debe ingresar un comentario para ese cambio de estado']);
    exit;
}

// Logica de fechas
$updateFields = ["estado = ?"];
$params = [$nuevoEstado];

if (isEstadoEnReparacion($nuevoEstado)) {
    $updateFields[] = "fecha_en_reparacion = COALESCE(fecha_en_reparacion, NOW())";
} elseif (isEstadoReparado($nuevoEstado)) {
    $updateFields[] = "fecha_reparado = NOW()";
} elseif (isEstadoSinReparacion($nuevoEstado)) {
    $updateFields[] = "fecha_sin_reparacion = NOW()";
} elseif (isEstadoPendienteIntermedio($nuevoEstado) || $nuevoEstadoNormalizado === 'ENTREGADO') {
    $updateFields[] = "fecha_pendiente = COALESCE(fecha_pendiente, NOW())";
} elseif ($nuevoEstadoNormalizado === 'PEND. DE REVISION' && normalizeEstadoLabel($estadoAnterior) !== 'PEND. DE REVISION') {
    $updateFields[] = "fecha_pendiente = NOW()";
}

// Logica observaciones
$nuevaObs = $rep['observaciones'];
$fechaStr = date('d/m/Y H:i');
$usuarioStr = $_SESSION['user_username'];
$notaAuto = "[$fechaStr - $usuarioStr]\nCAMBIO DE ESTADO: $estadoAnterior -> $nuevoEstado";

if ($comentario) {
    $notaAuto .= "\nMotivo: $comentario";
}
$nuevaObs = $notaAuto . "\n\n" . $rep['observaciones'];
$updateFields[] = "observaciones = ?";
$params[] = $nuevaObs;

$params[] = $id;

$sql = "UPDATE reparaciones SET " . implode(', ', $updateFields) . " WHERE id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

registrarHistorial('ESTADO', "$estadoAnterior -> $nuevoEstado" . ($comentario ? " ($comentario)" : ""), $id);

echo json_encode(['success' => true]);
