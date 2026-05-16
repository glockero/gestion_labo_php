<?php
// public/cambiar_password.php
// Standalone page: no global header/sidebar (user is in a blocked flow).
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/UsuarioModel.php';
require_once __DIR__ . '/../app/historial.php';

requireLogin();

// If the user isn't actually flagged for forced change, send them home.
if (empty($_SESSION['must_change_password'])) {
    redirect('/index.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($new === '' || $confirm === '') {
        $errors[] = 'Completá ambos campos.';
    } elseif ($new !== $confirm) {
        $errors[] = 'Las contraseñas no coinciden.';
    } else {
        try {
            UsuarioModel::setOwnPassword((int)$_SESSION['user_id'], $new);
            unset($_SESSION['must_change_password']);
            registrarHistorial('USUARIO_CAMBIO_PASS', "El usuario {$_SESSION['user_username']} cambió su contraseña tras un reset administrativo.");
            setFlashMessage('success', 'Contraseña actualizada. ¡Bienvenido!');
            redirect('/index.php');
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        } catch (Exception $e) {
            $errors[] = 'No se pudo guardar la nueva contraseña. Intentá de nuevo.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar contraseña · <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .change-card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
            padding: 2rem 1.75rem 1.75rem;
            border: 1px solid #e2e8f0;
        }
        .change-card .icon-circle {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: #fef3c7;
            color: #b45309;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        .change-card h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.35rem;
        }
        .change-card .subtitle {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 1.5rem;
        }
        .change-card .form-label {
            font-size: 0.78rem;
            font-weight: 600;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            margin-bottom: 0.3rem;
        }
        .change-card .form-control {
            font-size: 0.9rem;
            padding: 0.55rem 0.75rem;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
        }
        .change-card .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }
        .change-card .form-hint {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 0.3rem;
        }
        .change-card .btn-primary {
            width: 100%;
            font-weight: 700;
            font-size: 0.9rem;
            padding: 0.6rem;
            border-radius: 6px;
            margin-top: 0.5rem;
        }
        .change-card .err {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            font-size: 0.8rem;
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
            margin-bottom: 1rem;
        }
        .session-user {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 1.25rem;
            text-align: center;
        }
        .session-user a { color: #ef4444; text-decoration: none; }
        .session-user a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="change-card">
        <div class="icon-circle"><i class="bi bi-shield-lock-fill"></i></div>
        <h1>Definí una nueva contraseña</h1>
        <p class="subtitle">Tu contraseña fue reseteada por un administrador. Antes de continuar, elegí una nueva.</p>

        <?php foreach ($errors as $err): ?>
            <div class="err"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div class="mb-3">
                <label class="form-label">Nueva contraseña</label>
                <input type="password" name="new_password" class="form-control"
                       required minlength="<?= MIN_PASSWORD_LENGTH ?>"
                       autocomplete="new-password"
                       placeholder="Mín. <?= MIN_PASSWORD_LENGTH ?> caracteres">
                <div class="form-hint">Al menos <?= MIN_PASSWORD_LENGTH ?> caracteres. Evitá repetir el PIN temporal.</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Confirmar contraseña</label>
                <input type="password" name="confirm_password" class="form-control"
                       required minlength="<?= MIN_PASSWORD_LENGTH ?>"
                       autocomplete="new-password"
                       placeholder="Repetí la nueva contraseña">
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2-circle me-1"></i>Guardar y continuar
            </button>
        </form>

        <div class="session-user">
            Conectado como <strong><?= e($_SESSION['user_username'] ?? '') ?></strong>
            · <a href="<?= APP_URL ?>/logout.php">Cerrar sesión</a>
        </div>
    </div>
</body>
</html>
