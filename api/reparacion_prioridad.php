<?php
// api/reparacion_prioridad.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/historial.php';

requireLogin();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Acción no autorizada']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = $input['id'] ?? null;
$urgente = $input['urgente'] === 'SI' ? 'SI' : 'NO';
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!validateCsrfToken($csrf)) {
    http_response_code(403);
    exit;
}

$pdo = getDbConnection();
$checkStmt = $pdo->prepare("SELECT id FROM reparaciones WHERE id = ?");
$checkStmt->execute([$id]);
if (!$checkStmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'No encontrado']);
    exit;
}

$stmt = $pdo->prepare("UPDATE reparaciones SET urgente = ? WHERE id = ?");
$stmt->execute([$urgente, $id]);

registrarHistorial('PRIORIDAD', "Cambiada a: " . ($urgente === 'SI' ? 'URGENTE' : 'NORMAL'), $id);

echo json_encode(['success' => true]);
