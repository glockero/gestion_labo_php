<?php
// app/auth.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/historial.php';

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
