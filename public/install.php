<?php
// public/install.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/csrf.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $adminUser = $_POST['admin_user'] ?? '';
    $adminPass = $_POST['admin_pass'] ?? '';

    if (empty($adminUser) || empty($adminPass)) {
        $message = "<div class='alert alert-danger'>Usuario y contraseña son requeridos.</div>";
    } else {
        try {
            $pdo = getDbConnection();
            
            // Check if admin already exists
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
            $stmt->execute([$adminUser]);
            if ($stmt->fetch()) {
                $message = "<div class='alert alert-warning'>El usuario ya existe. Borre o use otro.</div>";
            } else {
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO usuarios (username, password_hash, rol) VALUES (?, ?, 'admin')");
                $stmt->execute([$adminUser, $hash]);
                $message = "<div class='alert alert-success'>Usuario administrador creado exitosamente. <a href='login.php'>Ir al Login</a></div>";
            }
        } catch (Exception $e) {
             $message = "<div class='alert alert-danger'>Error: " . e($e->getMessage()) . "</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; }
        .install-card { max-width: 500px; margin: auto; margin-top: 100px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card install-card shadow-sm">
            <div class="card-header bg-primary text-white text-center">
                <h4><i class="bi bi-tools"></i> Configuración Inicial</h4>
            </div>
            <div class="card-body">
                <?= $message ?>
                <p class="text-muted text-center">Cree el primer usuario administrador del sistema.</p>
                <form method="POST" action="install.php">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <div class="mb-3">
                        <label for="admin_user" class="form-label">Usuario Admin</label>
                        <input type="text" class="form-control" id="admin_user" name="admin_user" required>
                    </div>
                    <div class="mb-3">
                        <label for="admin_pass" class="form-label">Contraseña</label>
                        <input type="password" class="form-control" id="admin_pass" name="admin_pass" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-circle"></i> Crear Administrador</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
