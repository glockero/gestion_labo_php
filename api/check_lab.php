<?php
// api/check_lab.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/CatalogoModel.php';

requireLogin();

header('Content-Type: application/json');

$lab = $_GET['lab'] ?? '';
$exclude = $_GET['exclude'] ?? null;

if ($lab === '' || (int)$lab <= 0) {
    echo json_encode(['exists' => false]);
    exit;
}

$row = CatalogoModel::findEquipoByLab($lab, $exclude);

if ($row) {
    echo json_encode([
        'exists' => true,
        'equipo' => $row['nombre'],
    ]);
} else {
    echo json_encode(['exists' => false]);
}
