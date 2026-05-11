<?php
// api/reparacion_estado.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
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
$stmt = $pdo->prepare("SELECT estado, observaciones FROM reparaciones WHERE id = ?");
$stmt->execute([$id]);
$rep = $stmt->fetch();

if (!$rep) {
    http_response_code(404);
    exit;
}

$estadoAnterior = $rep['estado'];

// Logica de fechas
$updateFields = ["estado = ?"];
$params = [$nuevoEstado];

if ($nuevoEstado === 'EN REPARACION') {
    $updateFields[] = "fecha_en_reparacion = COALESCE(fecha_en_reparacion, NOW())";
} elseif ($nuevoEstado === 'REPARADO') {
    $updateFields[] = "fecha_reparado = NOW()";
} elseif ($nuevoEstado === 'SIN REPARACION') {
    $updateFields[] = "fecha_sin_reparacion = NOW()";
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
