<?php
// app/UsuarioModel.php
require_once __DIR__ . '/db.php';

class UsuarioModel {
    public static function getAll() {
        $pdo = getDbConnection();
        $stmt = $pdo->query(" 
            SELECT u.id, u.username, u.rol, u.tecnico_id, u.activo, u.last_login, t.nombre as tecnico_nombre 
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

    public static function create($username, $password, $rol, $tecnicoId = null, $activo = true) {
        $pdo = getDbConnection();
        $username = trim($username);
        $rol = $rol === 'admin' ? 'admin' : 'tecnico';
        $tecnicoId = $rol === 'tecnico' && $tecnicoId ? $tecnicoId : null;
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("INSERT INTO usuarios (username, password_hash, rol, tecnico_id, activo) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$username, $passwordHash, $rol, $tecnicoId, $activo ? 1 : 0]);
    }

    public static function update($id, $username, $rol, $tecnicoId = null, $activo = true, $newPassword = '') {
        $pdo = getDbConnection();
        $username = trim($username);
        $rol = $rol === 'admin' ? 'admin' : 'tecnico';
        $tecnicoId = $rol === 'tecnico' && $tecnicoId ? $tecnicoId : null;

        $fields = ["username = ?", "rol = ?", "tecnico_id = ?", "activo = ?"];
        $params = [$username, $rol, $tecnicoId, $activo ? 1 : 0];

        if ($newPassword !== '') {
            $fields[] = "password_hash = ?";
            $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        $params[] = $id;

        $stmt = $pdo->prepare("UPDATE usuarios SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($params);
    }
}
