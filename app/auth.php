<?php
// app/auth.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/historial.php';

function ensureAuthSupportTables() {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo = getDbConnection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        ip VARCHAR(45) PRIMARY KEY,
        attempts INT NOT NULL DEFAULT 0,
        first_attempt_at DATETIME NOT NULL,
        blocked_until DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $initialized = true;
}

function checkLoginRateLimit($ip) {
    ensureAuthSupportTables();

    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT attempts, first_attempt_at, blocked_until FROM login_attempts WHERE ip = ?");
    $stmt->execute([$ip]);
    $attempt = $stmt->fetch();

    if (!$attempt) {
        return [true, null];
    }

    $now = new DateTimeImmutable();
    if (!empty($attempt['blocked_until'])) {
        $blockedUntil = new DateTimeImmutable($attempt['blocked_until']);
        if ($blockedUntil > $now) {
            $seconds = $blockedUntil->getTimestamp() - $now->getTimestamp();
            return [false, "Demasiados intentos. Intenta nuevamente en {$seconds} segundos."];
        }
    }

    $firstAttemptAt = new DateTimeImmutable($attempt['first_attempt_at']);
    if (($now->getTimestamp() - $firstAttemptAt->getTimestamp()) > 300) {
        resetLoginRateLimit($ip);
    }

    return [true, null];
}

function recordFailedLoginAttempt($ip) {
    ensureAuthSupportTables();

    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT attempts, first_attempt_at FROM login_attempts WHERE ip = ?");
    $stmt->execute([$ip]);
    $attempt = $stmt->fetch();
    $now = new DateTimeImmutable();

    if (!$attempt) {
        $insert = $pdo->prepare("INSERT INTO login_attempts (ip, attempts, first_attempt_at, blocked_until) VALUES (?, 1, NOW(), NULL)");
        $insert->execute([$ip]);
        return;
    }

    $firstAttemptAt = new DateTimeImmutable($attempt['first_attempt_at']);
    $attempts = (int)$attempt['attempts'];
    if (($now->getTimestamp() - $firstAttemptAt->getTimestamp()) > 300) {
        $attempts = 0;
        $firstAttemptAt = $now;
    }

    $attempts++;
    $blockedUntil = null;
    if ($attempts > 5) {
        $blockedUntil = $now->add(new DateInterval('PT15M'))->format('Y-m-d H:i:s');
    }

    $update = $pdo->prepare("UPDATE login_attempts SET attempts = ?, first_attempt_at = ?, blocked_until = ? WHERE ip = ?");
    $update->execute([$attempts, $firstAttemptAt->format('Y-m-d H:i:s'), $blockedUntil, $ip]);
}

function resetLoginRateLimit($ip) {
    ensureAuthSupportTables();

    $pdo = getDbConnection();
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?");
    $stmt->execute([$ip]);
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('/login.php');
    }
    
    // Check if session is still valid (single session per user)
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT session_id, activo FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || !$user['activo'] || $user['session_id'] !== session_id()) {
        logout();
        redirect('/login.php?error=session_invalid');
    }
}

function requireRole($role) {
    requireLogin();
    if ($_SESSION['user_role'] !== $role) {
        http_response_code(403);
        die("Acceso denegado: Se requiere rol de $role.");
    }
}

function login($username, $password) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, password_hash, rol, activo, tecnico_id FROM usuarios WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && $user['activo'] && password_verify($password, $user['password_hash'])) {
        // Successful login
        session_regenerate_id(true); // Prevent session fixation
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_username'] = $username;
        $_SESSION['user_role'] = $user['rol'];
        $_SESSION['tecnico_id'] = $user['tecnico_id'];
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $sess_id = session_id();
        
        // Update user record
        $updateStmt = $pdo->prepare("UPDATE usuarios SET last_login = NOW(), last_ip = ?, session_id = ? WHERE id = ?");
        $updateStmt->execute([$ip, $sess_id, $user['id']]);
        resetLoginRateLimit($ip);
        
        registrarHistorial('LOGIN', "El usuario $username inició sesión.");
        return true;
    }
    return false;
}

function logout() {
    if (isLoggedIn()) {
        $username = $_SESSION['user_username'];
        
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE usuarios SET session_id = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        
        registrarHistorial('LOGOUT', "El usuario $username cerró sesión.");
    }
    
    $_SESSION = [];
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
}
