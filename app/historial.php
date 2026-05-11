<?php
// app/historial.php
require_once __DIR__ . '/db.php';

function registrarHistorial($accion, $detalle = '', $reparacion_id = null) {
    $pdo = getDbConnection();
    $usuario_id = $_SESSION['user_id'] ?? null;
    
    $stmt = $pdo->prepare("
        INSERT INTO historial (reparacion_id, usuario_id, accion, detalle, fecha) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$reparacion_id, $usuario_id, $accion, $detalle]);
}
