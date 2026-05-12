<?php
// admin/configuracion.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/CatalogoModel.php';
require_once __DIR__ . '/../app/UsuarioModel.php';
require_once __DIR__ . '/../app/historial.php';

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
    } elseif ($action === 'add_usuario') {
        $username = trim($_POST['new_username'] ?? '');
        $password = $_POST['new_password'] ?? '';
        $rol = $_POST['rol'] ?? 'tecnico';
        $tecnicoId = $_POST['tecnico_id'] ?? null;
        $activo = isset($_POST['activo']);

        if ($username === '' || $password === '') {
            setFlashMessage('danger', 'Usuario y contraseña son obligatorios.');
        } else {
            try {
                UsuarioModel::create($username, $password, $rol, $tecnicoId, $activo);
                registrarHistorial('USUARIO_CREAR', "Se creó el usuario $username con rol $rol.");
                setFlashMessage('success', 'Usuario creado correctamente.');
            } catch (PDOException $e) {
                setFlashMessage('danger', 'No se pudo crear el usuario. Verifique que el nombre no esté repetido.');
            }
        }

        redirect('/../admin/configuracion.php?tab=usuarios');
    } elseif ($action === 'update_usuario') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $rol = $_POST['rol'] ?? 'tecnico';
        $tecnicoId = $_POST['tecnico_id'] ?? null;
        $activo = isset($_POST['activo']);
        $usuarioActual = UsuarioModel::getById($userId);

        if (!$usuarioActual || $username === '') {
            setFlashMessage('danger', 'Datos de usuario inválidos.');
            redirect('/../admin/configuracion.php?tab=usuarios');
        }

        if ($userId === (int)$_SESSION['user_id'] && !$activo) {
            setFlashMessage('danger', 'No puede desactivar su propio usuario.');
            redirect('/../admin/configuracion.php?tab=usuarios');
        }

        if ($userId === (int)$_SESSION['user_id'] && $rol !== 'admin') {
            setFlashMessage('danger', 'No puede quitarse a sí mismo el rol de administrador.');
            redirect('/../admin/configuracion.php?tab=usuarios');
        }

        try {
            UsuarioModel::update($userId, $username, $rol, $tecnicoId, $activo, $password);
            registrarHistorial('USUARIO_EDITAR', "Se actualizó el usuario {$usuarioActual['username']}.");
            setFlashMessage('success', 'Usuario actualizado correctamente.');
        } catch (PDOException $e) {
            setFlashMessage('danger', 'No se pudo actualizar el usuario. Verifique que el nombre no esté repetido.');
        }

        redirect('/../admin/configuracion.php?tab=usuarios');
    }
}

require_once __DIR__ . '/../public/includes/header.php';
?>

<style>
/* Global Dense Styles for Admin */
.settings-shell {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --sidebar-bg: #f8fafc;
    --border-color: #e2e8f0;
    --text-main: #1e293b;
    --text-muted: #64748b;
    font-size: 13.5px;
}

.settings-shell .settings-page-title {
    font-size: 1.25rem;
    font-weight: 700;
}

.settings-shell .settings-section-intro {
    font-size: 0.85rem;
    color: var(--text-muted);
}

/* Compact Sub-Sidebar */
.sub-sidebar {
    background: transparent;
}

.sub-sidebar .list-group-item {
    border: none;
    padding: 0.5rem 0.75rem;
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--text-muted);
    border-radius: 6px !important;
    margin-bottom: 2px;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    transition: all 0.15s;
}

.sub-sidebar .list-group-item:hover {
    background: #f1f5f9;
    color: var(--text-main);
}

.sub-sidebar .list-group-item.active {
    background: #fff;
    color: var(--primary);
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    border: 1px solid var(--border-color);
}

.sub-sidebar .list-group-item i {
    font-size: 1.1rem;
}

/* Backup Card */
.backup-mini-card {
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 0.75rem;
    margin-top: 1.5rem;
}

.backup-mini-card h6 {
    font-size: 0.8rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.backup-mini-card p {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
}

/* Dense Cards */
.dense-card {
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    overflow: hidden;
}

.dense-card .card-header {
    background: #fff;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border-color);
}

.dense-card .card-header h6 {
    font-size: 0.9rem;
    font-weight: 700;
    margin: 0;
}

.dense-card .card-body {
    padding: 1rem;
}

/* Form Styles */
.dense-form .form-label {
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: var(--text-main);
}

.dense-form .form-control,
.dense-form .form-select {
    font-size: 0.85rem;
    padding: 0.4rem 0.6rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    height: auto;
    min-height: 0;
}

.dense-form .form-control:focus {
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
}

.dense-form .btn-primary {
    padding: 0.4rem 1rem;
    font-size: 0.85rem;
}

/* User Table */
.dense-table {
    font-size: 13px;
}

.dense-table thead th {
    background: #f8fafc;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    font-weight: 700;
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid var(--border-color);
}

.dense-table tbody td {
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid #f1f5f9;
}

.dense-table .user-avatar {
    width: 28px;
    height: 28px;
    font-size: 0.75rem;
    border-radius: 6px;
}

.dense-table .status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}

.status-dot.active { background-color: #22c55e; }
.status-dot.inactive { background-color: #ef4444; }

.badge-pill {
    font-size: 0.7rem;
    padding: 0.2rem 0.5rem;
    border-radius: 9999px;
    font-weight: 600;
}

.badge-admin { background: #fee2e2; color: #991b1b; }
.badge-tecnico { background: #e0f2fe; color: #075985; }

.btn-icon {
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border-radius: 4px;
}

@media (max-width: 991.98px) {
    .settings-shell .catalog-hero {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="settings-shell">
    <div class="mb-4">
        <h2 class="settings-page-title mb-1">Configuración del Sistema</h2>
        <p class="settings-section-intro mb-0">Gestión de usuarios y catálogos operativos con alta densidad de datos.</p>
    </div>

    <div class="row g-4">
        <div class="col-md-3 col-xl-2 mb-4">
            <div class="sub-sidebar list-group">
                <a href="?tab=usuarios" class="list-group-item list-group-item-action <?= $tab === 'usuarios' ? 'active' : '' ?>"><i class="bi bi-people"></i> Usuarios</a>
                <a href="?tab=tecnicos" class="list-group-item list-group-item-action <?= $tab === 'tecnicos' ? 'active' : '' ?>"><i class="bi bi-tools"></i> Técnicos</a>
                <a href="?tab=salas" class="list-group-item list-group-item-action <?= $tab === 'salas' ? 'active' : '' ?>"><i class="bi bi-geo-alt"></i> Salas</a>
                <a href="?tab=equipos" class="list-group-item list-group-item-action <?= $tab === 'equipos' ? 'active' : '' ?>"><i class="bi bi-pc-display"></i> Equipos</a>
                <a href="?tab=familias" class="list-group-item list-group-item-action <?= $tab === 'familias' ? 'active' : '' ?>"><i class="bi bi-diagram-2"></i> Familias</a>
                <a href="?tab=estados" class="list-group-item list-group-item-action <?= $tab === 'estados' ? 'active' : '' ?>"><i class="bi bi-tags"></i> Estados</a>
            </div>
            
            <div class="backup-mini-card shadow-sm">
                <h6>Base de Datos</h6>
                <p>Descarga un respaldo SQL completo.</p>
                <a href="backups.php" class="btn btn-sm btn-outline-secondary w-100 py-1" style="font-size: 0.75rem;" target="_blank">
                    <i class="bi bi-download me-1"></i> Download SQL
                </a>
            </div>
        </div>
        
        <div class="col-md-9 col-xl-10">
            <?php if ($tab === 'usuarios'): ?>
                <div class="row g-3">
                    <div class="col-lg-3">
                        <div class="dense-card">
                            <div class="card-header">
                                <h6>Crear Usuario</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="dense-form d-grid gap-2" autocomplete="off">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="action" value="add_usuario">

                                    <div>
                                        <label class="form-label">Nombre de Usuario</label>
                                        <input type="text" name="new_username" class="form-control" required placeholder="Usuario">
                                    </div>
                                    <div>
                                        <label class="form-label">Contraseña</label>
                                        <input type="password" name="new_password" class="form-control" required placeholder="••••">
                                    </div>
                                    <div>
                                        <label class="form-label">Rol del Sistema</label>
                                        <select name="rol" class="form-select user-role-select" data-target="new-user-tecnico">
                                            <option value="tecnico">Técnico</option>
                                            <option value="admin">Administrador</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Técnico Vinculado</label>
                                        <select name="tecnico_id" id="new-user-tecnico" class="form-select">
                                            <option value="">Ninguno / No aplica</option>
                                            <?php foreach ($tecnicos as $t): ?>
                                                <option value="<?= e($t['id']) ?>"><?= e($t['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-check form-switch mt-1">
                                        <input type="checkbox" class="form-check-input" id="activo_nuevo" name="activo" checked>
                                        <label class="form-check-label" style="font-size: 0.75rem;" for="activo_nuevo">Habilitar Acceso</label>
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100 mt-2 fw-bold">Crear Usuario</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-9">
                        <div class="dense-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6>Personnel Management</h6>
                                <span class="text-muted" style="font-size: 0.75rem;"><?= count($usuarios) ?> Total</span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table dense-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>User Identity</th>
                                                <th>Permissions</th>
                                                <th>Technician</th>
                                                <th class="text-center">Status</th>
                                                <th>Last Login</th>
                                                <th class="text-end"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($usuarios as $u): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="user-avatar d-flex align-items-center justify-content-center bg-light text-primary fw-bold border">
                                                            <?= strtoupper(substr($u['username'], 0, 1)) ?>
                                                        </div>
                                                        <span class="fw-bold"><?= e($u['username']) ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($u['rol'] === 'admin'): ?>
                                                        <span class="badge-pill badge-admin">Admin</span>
                                                    <?php else: ?>
                                                        <span class="badge-pill badge-tecnico">Tech</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-muted small"><?= e($u['tecnico_nombre'] ?? '-') ?></td>
                                                <td class="text-center">
                                                    <span class="status-dot <?= $u['activo'] ? 'active' : 'inactive' ?>" title="<?= $u['activo'] ? 'Active' : 'Disabled' ?>"></span>
                                                </td>
                                                <td class="text-muted small">
                                                    <?= $u['last_login'] ? date('d/m/y H:i', strtotime($u['last_login'])) : 'Never' ?>
                                                </td>
                                                <td class="text-end px-3">
                                                    <button type="button" class="btn btn-sm btn-light border btn-icon" data-bs-toggle="modal" data-bs-target="#editUser<?= $u['id'] ?>">
                                                        <i class="bi bi-pencil" style="font-size: 0.75rem;"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php 
                $data = ${$tab}; 
                $tab_titles = [
                    'tecnicos' => 'Técnicos',
                    'salas' => 'Salas',
                    'equipos' => 'Equipos',
                    'familias' => 'Familias',
                    'estados' => 'Estados',
                ];
                $tab_counts = [
                    'tecnicos' => count($tecnicos),
                    'salas' => count($salas),
                    'equipos' => count($equipos),
                    'familias' => count($familias),
                    'estados' => count($estados),
                ];
                ?>
                <div class="row g-3">
                    <div class="col-lg-4">
                        <div class="dense-card">
                            <div class="card-header">
                                <h6>Agregar <?= rtrim($tab_titles[$tab] ?? 'Elemento', 's') ?></h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="dense-form d-grid gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="action" value="add_catalogo">
                                    <input type="hidden" name="tipo" value="<?= e($tab) ?>">

                                    <div>
                                        <label class="form-label">Nombre</label>
                                        <input type="text" name="nombre" class="form-control" required placeholder="Nombre del <?= rtrim(strtolower($tab_titles[$tab] ?? 'elemento'), 's') ?>">
                                    </div>
                                    <?php if ($tab === 'equipos'): ?>
                                    <div>
                                        <label class="form-label">Valor de Referencia</label>
                                        <input type="text" name="extra" class="form-control" placeholder="Opcional">
                                    </div>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-primary w-100 mt-2 fw-bold">
                                        <i class="bi bi-plus-circle me-1"></i>Añadir Registro
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="dense-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6>Listado de <?= $tab_titles[$tab] ?? 'Registros' ?></h6>
                                <span class="text-muted" style="font-size: 0.75rem;"><?= $tab_counts[$tab] ?? count($data) ?> Total</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($data)): ?>
                                <div class="table-responsive">
                                    <table class="table dense-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Descripción</th>
                                                <?php if ($tab === 'equipos'): ?>
                                                <th>Valor Ref.</th>
                                                <?php endif; ?>
                                                <?php if ($tab === 'tecnicos'): ?>
                                                <th class="text-center">Estado</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($data as $d): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold"><?= e($d['nombre']) ?></div>
                                                    <div class="text-muted" style="font-size: 0.7rem;">ID #<?= e($d['id']) ?></div>
                                                </td>
                                                <?php if ($tab === 'equipos'): ?>
                                                <td class="text-muted"><?= e($d['valor'] ?: '-') ?></td>
                                                <?php endif; ?>
                                                <?php if ($tab === 'tecnicos'): ?>
                                                <td class="text-center">
                                                    <span class="status-dot <?= !empty($d['activo']) ? 'active' : 'inactive' ?>"></span>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="p-4 text-center text-muted small">No hay registros cargados.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($tab === 'usuarios'): ?>
    <?php foreach($usuarios as $u): ?>
        <div class="modal fade" id="editUser<?= $u['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
                <form method="POST" class="modal-content border-0 shadow-sm" style="border-radius: 12px;" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="update_usuario">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <?php if ($u['id'] == $_SESSION['user_id']): ?>
                        <input type="hidden" name="activo" value="1">
                    <?php endif; ?>

                    <div class="modal-header border-bottom-0 pt-4 pb-0 px-4">
                        <h6 class="modal-title fw-bold text-dark" style="font-size: 1rem;"><i class="bi bi-pencil-square text-primary me-2"></i>Editar <?= e($u['username']) ?></h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="font-size: 0.75rem;"></button>
                    </div>
                    <div class="modal-body px-4 py-3 d-grid gap-3">
                        <div class="dense-form">
                            <label class="form-label" style="font-size: 0.75rem;">Nombre de Usuario</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted" style="padding: 0.4rem 0.6rem;"><i class="bi bi-person"></i></span>
                                <input type="text" name="username" class="form-control border-start-0" value="<?= e($u['username']) ?>" required autocomplete="off" style="font-size: 0.85rem; padding: 0.4rem 0.6rem;">
                            </div>
                        </div>
                        <div class="dense-form">
                            <label class="form-label" style="font-size: 0.75rem;">Nueva Contraseña</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted" style="padding: 0.4rem 0.6rem;"><i class="bi bi-lock"></i></span>
                                <input type="password" name="password" class="form-control border-start-0" placeholder="Dejar en blanco para mantener" autocomplete="new-password" style="font-size: 0.85rem; padding: 0.4rem 0.6rem;">
                            </div>
                        </div>
                        <div class="dense-form">
                            <label class="form-label" style="font-size: 0.75rem;">Rol del Sistema</label>
                            <select name="rol" class="form-select user-role-select" data-target="tecnico_<?= $u['id'] ?>" style="font-size: 0.85rem; padding: 0.4rem 0.6rem;">
                                <option value="admin" <?= $u['rol'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                <option value="tecnico" <?= $u['rol'] === 'tecnico' ? 'selected' : '' ?>>Técnico</option>
                            </select>
                        </div>
                        <div class="dense-form">
                            <label class="form-label" style="font-size: 0.75rem;">Vincular con Técnico</label>
                            <select name="tecnico_id" id="tecnico_<?= $u['id'] ?>" class="form-select tom-select-user" <?= $u['rol'] === 'admin' ? 'disabled' : '' ?> style="font-size: 0.85rem; padding: 0.4rem 0.6rem;">
                                <option value="">Ninguno / No aplica</option>
                                <?php foreach ($tecnicos as $t): ?>
                                    <option value="<?= e($t['id']) ?>" <?= (string)($u['tecnico_id'] ?? '') === (string)$t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-check form-switch p-2 bg-light rounded mt-1 border">
                            <input type="checkbox" class="form-check-input ms-0 me-2" name="activo" id="activo_<?= $u['id'] ?>" <?= $u['activo'] ? 'checked' : '' ?> <?= $u['id'] == $_SESSION['user_id'] ? 'disabled' : '' ?>>
                            <label class="form-check-label fw-semibold text-dark" for="activo_<?= $u['id'] ?>" style="font-size: 0.8rem;">Usuario habilitado</label>
                        </div>
                        <?php if ($u['id'] == $_SESSION['user_id']): ?>
                        <div class="alert alert-info border-0 bg-info bg-opacity-10 text-info-emphasis d-flex align-items-center gap-2 mb-0" style="font-size: 0.75rem; padding: 0.5rem;">
                            <i class="bi bi-info-circle-fill"></i> No podés desactivar tu propia cuenta.
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer border-top-0 px-4 pb-4 pt-0 d-flex gap-2">
                        <button type="button" class="btn btn-light flex-grow-1 fw-bold text-secondary border" style="font-size: 0.8rem; padding: 0.5rem;" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary flex-grow-1 fw-bold shadow-none" style="font-size: 0.8rem; padding: 0.5rem;">Actualizar Datos</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.user-role-select').forEach(function (select) {
            const syncTarget = function () {
                const targetId = select.dataset.target;
                const tecnicoSelect = document.getElementById(targetId);
                if (!tecnicoSelect) return;
                const isAdmin = select.value === 'admin';
                tecnicoSelect.disabled = isAdmin;
                if (isAdmin) {
                    tecnicoSelect.value = '';
                    if (tecnicoSelect.tomselect) {
                        tecnicoSelect.tomselect.clear();
                        tecnicoSelect.tomselect.disable();
                    }
                } else if (tecnicoSelect.tomselect) {
                    tecnicoSelect.tomselect.enable();
                }
            };

            select.addEventListener('change', syncTarget);
            syncTarget();
        });
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../public/includes/header.php'; ?>
<?php require_once __DIR__ . '/../public/includes/footer.php'; ?>
