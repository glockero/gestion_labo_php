<?php
// api/check_npu.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/ReparacionModel.php';

requireLogin();

header('Content-Type: application/json');

$npu = $_GET['npu'] ?? '';
$exclude = $_GET['exclude'] ?? null;

if (empty($npu)) {
    echo json_encode(['exists' => false]);
    exit;
}

$exists = ReparacionModel::checkNpuRepetido($npu, $exclude);

echo json_encode(['exists' => $exists]);
