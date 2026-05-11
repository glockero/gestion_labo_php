<?php
// public/login.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/helpers.php';

if (isLoggedIn()) {
    redirect('/index.php'); // Assuming dashboard or index will be the landing page
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        setFlashMessage('danger', 'Ingrese usuario y contraseña.');
    } else {
        if (login($username, $password)) {
            redirect('/index.php');
        } else {
            setFlashMessage('danger', 'Usuario o contraseña incorrectos, o cuenta inactiva.');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f6f9; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-card { width: 100%; max-width: 400px; border-radius: 10px; overflow: hidden; }
        .login-header { background-color: #0d6efd; color: white; padding: 20px; text-align: center; }
        .login-header i { font-size: 3rem; }
        .login-body { padding: 30px; background: white; }
    </style>
</head>
<body>
    <div class="login-card shadow-lg">
        <div class="login-header">
            <i class="bi bi-cpu"></i>
            <h3 class="mt-2 mb-0"><?= e(APP_NAME) ?></h3>
            <small>Acceso al sistema</small>
        </div>
        <div class="login-body">
            <?= displayFlashMessages() ?>
            <form method="POST" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <div class="mb-3">
                    <label for="username" class="form-label text-muted">Usuario</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="username" name="username" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label text-muted">Contraseña</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">
                    <i class="bi bi-box-arrow-in-right"></i> Ingresar
                </button>
            </form>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
