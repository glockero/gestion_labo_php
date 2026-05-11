<?php
// admin/configuracion.php
require_once __DIR__ . '/../public/includes/header.php';
require_once __DIR__ . '/../app/CatalogoModel.php';
require_once __DIR__ . '/../app/UsuarioModel.php';

requireRole('admin');

$tab = $_GET['tab'] ?? 'usuarios';

$usuarios = UsuarioModel::getAll();
$tecnicos = CatalogoModel::getAll('tecnicos', false);
$salas = CatalogoModel::getAll('salas', false);
$equipos = CatalogoModel::getAll('equipos', false);
$familias = CatalogoModel::getAll('familias', false);
$estados = CatalogoModel::getAll('estados', false);

// Basic handler for adding simple catalogs here to keep it contained, normally this would go to a separate script
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrf();
    $action = $_POST['action'];
    
    if ($action === 'add_catalogo') {
        $tipo = $_POST['tipo'];
        $nombre = $_POST['nombre'];
        $extra = $_POST['extra'] ?? null;
        if (CatalogoModel::agregar($tipo, $nombre, $extra)) {
            setFlashMessage('success', 'Elemento agregado correctamente.');
        } else {
            setFlashMessage('danger', 'Error al agregar. Puede que ya exista.');
        }
        redirect("/../admin/configuracion.php?tab=$tipo");
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="h3"><i class="bi bi-gear"></i> Configuración del Sistema</h2>
</div>

<div class="row">
    <div class="col-md-3 mb-4">
        <div class="list-group shadow-sm mb-4">
            <a href="?tab=usuarios" class="list-group-item list-group-item-action <?= $tab === 'usuarios' ? 'active' : '' ?>"><i class="bi bi-people"></i> Usuarios</a>
            <a href="?tab=tecnicos" class="list-group-item list-group-item-action <?= $tab === 'tecnicos' ? 'active' : '' ?>"><i class="bi bi-tools"></i> Técnicos</a>
            <a href="?tab=salas" class="list-group-item list-group-item-action <?= $tab === 'salas' ? 'active' : '' ?>"><i class="bi bi-geo-alt"></i> Salas</a>
            <a href="?tab=equipos" class="list-group-item list-group-item-action <?= $tab === 'equipos' ? 'active' : '' ?>"><i class="bi bi-pc-display"></i> Equipos</a>
            <a href="?tab=familias" class="list-group-item list-group-item-action <?= $tab === 'familias' ? 'active' : '' ?>"><i class="bi bi-diagram-2"></i> Familias</a>
            <a href="?tab=estados" class="list-group-item list-group-item-action <?= $tab === 'estados' ? 'active' : '' ?>"><i class="bi bi-tags"></i> Estados</a>
        </div>
        
        <div class="card shadow-sm border-0 border-start border-success border-4">
            <div class="card-body">
                <h6 class="fw-bold mb-3"><i class="bi bi-shield-check"></i> Respaldo</h6>
                <p class="small text-muted mb-3">Genera una copia de seguridad de la base de datos.</p>
                <a href="backups.php" class="btn btn-sm btn-outline-success w-100" target="_blank"><i class="bi bi-download"></i> Descargar SQL</a>
            </div>
        </div>
    </div>
    
    <div class="col-md-9">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0 text-capitalize">Administrar <?= e($tab) ?></h5>
            </div>
            <div class="card-body">
            
            <?php if ($tab === 'usuarios'): ?>
                <div class="alert alert-info small">La creación de usuarios y edición de roles se implementará en un endpoint separado o se realiza vía base de datos temporalmente.</div>
                <table class="table table-hover table-sm">
                    <thead><tr><th>ID</th><th>Username</th><th>Rol</th><th>Técnico Asoc.</th><th>Estado</th><th>Último Login</th></tr></thead>
                    <tbody>
                        <?php foreach($usuarios as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td><?= e($u['username']) ?></td>
                            <td><span class="badge bg-<?= $u['rol'] === 'admin' ? 'danger' : 'primary' ?>"><?= $u['rol'] ?></span></td>
                            <td><?= e($u['tecnico_nombre'] ?? '-') ?></td>
                            <td><?= $u['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
                            <td><?= $u['last_login'] ? formatDatetimeArg($u['last_login']) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
            <?php else: ?>
                <!-- Generic Catalog View -->
                <?php 
                $data = ${$tab}; 
                ?>
                <form method="POST" class="row g-3 mb-4 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="add_catalogo">
                    <input type="hidden" name="tipo" value="<?= e($tab) ?>">
                    
                    <div class="col-md-6">
                        <label class="form-label">Nuevo Nombre</label>
                        <input type="text" name="nombre" class="form-control" required>
                    </div>
                    <?php if ($tab === 'equipos'): ?>
                    <div class="col-md-4">
                        <label class="form-label">Valor (Opcional)</label>
                        <input type="text" name="extra" class="form-control">
                    </div>
                    <?php endif; ?>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus"></i> Agregar</button>
                    </div>
                </form>
                
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <?php if ($tab === 'equipos') echo "<th>Valor</th>"; ?>
                            <?php if ($tab === 'tecnicos') echo "<th>Estado</th>"; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($data as $d): ?>
                        <tr>
                            <td><?= $d['id'] ?></td>
                            <td><?= e($d['nombre']) ?></td>
                            <?php if ($tab === 'equipos') echo "<td>" . e($d['valor']) . "</td>"; ?>
                            <?php if ($tab === 'tecnicos') echo "<td>" . ($d['activo'] ? '<span class="text-success">Activo</span>' : '<span class="text-danger">Inactivo</span>') . "</td>"; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
                
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../public/includes/footer.php'; ?>
