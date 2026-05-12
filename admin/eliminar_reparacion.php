<?php
// admin/eliminar_reparacion.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/historial.php';

requireRole('admin');
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    
    if ($id) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM reparaciones WHERE id = ?");
        if ($stmt->execute([$id])) {
            setFlashMessage('success', 'Reparación eliminada correctamente.');
            registrarHistorial('ELIMINAR', "El administrador eliminó la reparación ID $id.", null);
        } else {
            setFlashMessage('danger', 'No se pudo eliminar la reparación.');
        }
    }
}

redirect('/index.php');
