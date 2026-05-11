<?php
// api/reparacion_comentario.php
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
$comentario = trim($input['comentario'] ?? '');
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!validateCsrfToken($csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF inválido']);
    exit;
}

if (!$id || !$comentario) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos incompletos']);
    exit;
}

$pdo = getDbConnection();
$stmt = $pdo->prepare("SELECT observaciones FROM reparaciones WHERE id = ?");
$stmt->execute([$id]);
$rep = $stmt->fetch();

if (!$rep) {
    http_response_code(404);
    echo json_encode(['error' => 'No encontrado']);
    exit;
}

$fechaStr = date('d/m/Y H:i');
$usuarioStr = $_SESSION['user_username'];
$nuevaObs = "[$fechaStr - $usuarioStr]\n$comentario\n\n" . $rep['observaciones'];

$stmt = $pdo->prepare("UPDATE reparaciones SET observaciones = ? WHERE id = ?");
$stmt->execute([$nuevaObs, $id]);

registrarHistorial('COMENTARIO', $comentario, $id);

echo json_encode(['success' => true, 'observaciones' => $nuevaObs]);
