<?php
// api/reparacion_devolver.php
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
$comentario = trim($input['comentario'] ?? '');
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!validateCsrfToken($csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF inválido']);
    exit;
}

if (!$id || $comentario === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Debe ingresar un motivo de devolución']);
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
$canReturn = $userRole === 'admin' || ($userRole === 'tecnico' && $rep['tecnico_id'] && (int)$rep['tecnico_id'] === (int)$tecnicoId);

if (!$canReturn) {
    http_response_code(403);
    echo json_encode(['error' => 'Acción no autorizada']);
    exit;
}

$estadoActual = strtoupper((string)$rep['estado']);
if ($userRole === 'tecnico' && isEstadoCerrado($estadoActual)) {
    http_response_code(403);
    echo json_encode(['error' => 'La reparación ya está cerrada']);
    exit;
}

$fechaStr = date('d/m/Y H:i');
$usuarioStr = $_SESSION['user_username'] ?? 'Sistema';
$nota = "[$fechaStr - $usuarioStr]\nDEVOLUCION DE EQUIPO\nMotivo: $comentario\n(Se desasignó el técnico y volvió a PEND. DE REVISION)";
$nuevaObs = trim($nota . "\n\n" . ($rep['observaciones'] ?? ''));

$update = $pdo->prepare("UPDATE reparaciones SET tecnico_id = NULL, estado = 'PEND. DE REVISION', observaciones = ?, fecha_pendiente = NOW() WHERE id = ?");
$update->execute([$nuevaObs, $id]);

registrarHistorial('DEVOLVER', $comentario, $id);

echo json_encode(['success' => true, 'observaciones' => $nuevaObs]);
