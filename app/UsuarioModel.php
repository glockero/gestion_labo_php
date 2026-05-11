<?php
// app/UsuarioModel.php
require_once __DIR__ . '/db.php';

class UsuarioModel {
    public static function getAll() {
        $pdo = getDbConnection();
        $stmt = $pdo->query("
            SELECT u.id, u.username, u.rol, u.activo, u.last_login, t.nombre as tecnico_nombre 
            FROM usuarios u 
            LEFT JOIN tecnicos t ON u.tecnico_id = t.id 
            ORDER BY u.username ASC
        ");
        return $stmt->fetchAll();
    }

    public static function getById($id) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public static function toggleActive($id, $status) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }
}
