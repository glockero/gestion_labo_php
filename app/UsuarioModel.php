<?php
// app/UsuarioModel.php
require_once __DIR__ . '/db.php';

class UsuarioModel {
    public static function ensureSchema() {
        static $done = false;
        if ($done) return;

        $pdo = getDbConnection();

        if (!$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'deleted_at'")->fetch()) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN deleted_at DATETIME NULL AFTER created_at");
        }

        if (!$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'password_reset_required'")->fetch()) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN password_reset_required BOOLEAN DEFAULT FALSE AFTER deleted_at");
        }

        $done = true;
    }

    public static function getAll() {
        self::ensureSchema();
        $pdo = getDbConnection();
        $stmt = $pdo->query("
            SELECT u.id, u.username, u.rol, u.tecnico_id, u.activo, u.last_login, u.last_ip,
                   u.session_id, u.created_at, t.nombre as tecnico_nombre
            FROM usuarios u
            LEFT JOIN tecnicos t ON u.tecnico_id = t.id
            WHERE u.deleted_at IS NULL
            ORDER BY u.username ASC
        ");
        return $stmt->fetchAll();
    }

    public static function getById($id) {
        self::ensureSchema();
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public static function toggleActive($id, $status) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }

    public static function softDelete($id) {
        self::ensureSchema();
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            UPDATE usuarios
            SET deleted_at = NOW(), activo = 0, session_id = NULL
            WHERE id = ? AND deleted_at IS NULL
        ");
        return $stmt->execute([$id]);
    }

    public static function forceLogout($id) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE usuarios SET session_id = NULL WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Generates a 4-digit temporary PIN, persists its hash, forces logout,
     * and marks the user to require a password change on next login.
     * Returns the plain-text PIN so the admin can pass it to the user.
     */
    public static function resetPassword($id) {
        self::ensureSchema();
        $pdo = getDbConnection();
        $temp = self::generateTempPin();
        $hash = password_hash($temp, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            UPDATE usuarios
            SET password_hash = ?, session_id = NULL, password_reset_required = 1
            WHERE id = ?
        ");
        $stmt->execute([$hash, $id]);
        return $temp;
    }

    /**
     * Sets a new password (validated) and clears the reset-required flag.
     * Used by the forced-change-password screen.
     */
    public static function setOwnPassword($id, $newPassword) {
        self::ensureSchema();
        self::assertPasswordStrength($newPassword);
        $pdo = getDbConnection();
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            UPDATE usuarios
            SET password_hash = ?, password_reset_required = 0
            WHERE id = ?
        ");
        return $stmt->execute([$hash, $id]);
    }

    private static function generateTempPin($length = 4) {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= (string)random_int(0, 9);
        }
        return $out;
    }

    private static function assertPasswordStrength($password) {
        if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException(
                'La contraseña debe tener al menos ' . MIN_PASSWORD_LENGTH . ' caracteres.'
            );
        }
    }

    public static function create($username, $password, $rol, $tecnicoId = null, $activo = true) {
        self::assertPasswordStrength($password);

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
            self::assertPasswordStrength($newPassword);
            $fields[] = "password_hash = ?";
            $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        $params[] = $id;

        $stmt = $pdo->prepare("UPDATE usuarios SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($params);
    }
}
