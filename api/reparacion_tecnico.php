<?php
// api/reparacion_tecnico.php
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
$tecnicoId = $input['tecnico_id'] === '' ? null : $input['tecnico_id'];
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!validateCsrfToken($csrf)) {
    http_response_code(403);
    exit;
}

$pdo = getDbConnection();

$repStmt = $pdo->prepare("SELECT id FROM reparaciones WHERE id = ?");
$repStmt->execute([$id]);
if (!$repStmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'No encontrado']);
    exit;
}

// Get tech name
$tecName = 'Sin Asignar';
if ($tecnicoId) {
    $st = $pdo->prepare("SELECT nombre FROM tecnicos WHERE id = ?");
    $st->execute([$tecnicoId]);
    $tecName = $st->fetchColumn();
}

$stmt = $pdo->prepare("UPDATE reparaciones SET tecnico_id = ? WHERE id = ?");
$stmt->execute([$tecnicoId, $id]);

registrarHistorial('REASIGNAR', "Asignado a: $tecName", $id);

echo json_encode(['success' => true]);
