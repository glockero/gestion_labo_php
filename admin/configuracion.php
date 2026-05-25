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

// For the "tecnicos" tab: count active repairs per technician to warn before deletion
$repsActivasPorTecnico = $tab === 'tecnicos' ? CatalogoModel::getReparacionesActivasPorTecnico() : [];
// For free-text catalogs: count total reparaciones referencing each name
$usoEnReparaciones = in_array($tab, ['salas', 'equipos', 'familias'], true)
    ? CatalogoModel::getUsoEnReparaciones($tab)
    : [];

// Basic handler for adding simple catalogs here to keep it contained, normally this would go to a separate script
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrf();
    $action = $_POST['action'];
    
    if ($action === 'add_catalogo') {
        $tipo = $_POST['tipo'];
        $nombre = trim($_POST['nombre'] ?? '');
        $extra = $_POST['extra'] ?? null;
        $labConflict = null;
        if ($tipo === 'equipos') {
            $lab = $_POST['lab'] ?? '';
            $labConflict = CatalogoModel::findEquipoByLab($lab);
            if ($nombre !== '' && $lab !== '') {
                $nombre = $nombre . ' (LAB' . (int)$lab . ')';
            }
            $extra = [
                'valor' => $_POST['extra'] ?? null,
                'lab' => $lab,
                'familia' => $_POST['familia'] ?? null,
            ];
        }
        if ($labConflict) {
            setFlashMessage('danger', "El LAB {$labConflict['lab']} ya está asignado a \"{$labConflict['nombre']}\". Elegí otro número.");
        } elseif (CatalogoModel::agregar($tipo, $nombre, $extra)) {
            setFlashMessage('success', 'Elemento agregado correctamente.');
        } else {
            setFlashMessage('danger', 'Error al agregar. Puede que el nombre ya exista o esté vacío.');
        }
        adminRedirect("/configuracion.php?tab=$tipo");
    } elseif ($action === 'edit_catalogo') {
        $tipo = $_POST['tipo'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $extra = $_POST['extra'] ?? null;
        $labConflict = null;
        if ($tipo === 'equipos') {
            $lab = $_POST['lab'] ?? '';
            $labConflict = CatalogoModel::findEquipoByLab($lab, $id);
            if ($nombre !== '' && $lab !== '') {
                $nombre = $nombre . ' (LAB' . (int)$lab . ')';
            }
            $extra = [
                'valor' => $_POST['extra'] ?? null,
                'lab' => $lab,
                'familia' => $_POST['familia'] ?? null,
            ];
        }
        if ($labConflict) {
            setFlashMessage('danger', "El LAB {$labConflict['lab']} ya está asignado a \"{$labConflict['nombre']}\". Elegí otro número.");
            adminRedirect("/configuracion.php?tab=$tipo");
        }
        $activo = isset($_POST['activo']);
        try {
            CatalogoModel::editar($tipo, $id, $nombre, $extra, $tipo === 'tecnicos' ? $activo : null);
            registrarHistorial('CATALOGO_EDITAR', "Se editó {$tipo} #{$id} → " . trim($nombre));
            setFlashMessage('success', 'Registro actualizado.');
        } catch (InvalidArgumentException $e) {
            setFlashMessage('danger', $e->getMessage());
        } catch (PDOException $e) {
            setFlashMessage('danger', 'No se pudo actualizar. Puede que el nombre ya esté en uso.');
        }
        adminRedirect("/configuracion.php?tab=$tipo");
    } elseif ($action === 'delete_catalogo') {
        $tipo = $_POST['tipo'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        $registro = CatalogoModel::getById($tipo, $id);
        if (!$registro) {
            setFlashMessage('danger', 'Registro no encontrado.');
        } else {
            try {
                CatalogoModel::eliminar($tipo, $id);
                $verbo = $tipo === 'tecnicos' ? 'desactivado' : 'eliminado';
                registrarHistorial('CATALOGO_ELIMINAR', "Se {$verbo} {$tipo} #{$id} ({$registro['nombre']}).");
                setFlashMessage('success', "Registro '{$registro['nombre']}' {$verbo}.");
            } catch (PDOException $e) {
                setFlashMessage('danger', 'No se pudo eliminar el registro.');
            }
        }
        adminRedirect("/configuracion.php?tab=$tipo");
    } elseif ($action === 'bulk_update_equipos') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            setFlashMessage('danger', 'No se seleccionaron equipos.');
            adminRedirect('/configuracion.php?tab=equipos');
        }
        $updates = [];
        if (array_key_exists('familia', $_POST)) {
            $updates['familia'] = $_POST['familia'];
        }
        if (empty($updates)) {
            setFlashMessage('danger', 'No se indicó qué campo actualizar.');
            adminRedirect('/configuracion.php?tab=equipos');
        }
        $n = CatalogoModel::bulkUpdateEquipos($ids, $updates);
        registrarHistorial('CATALOGO_BULK_UPDATE', "Bulk update sobre $n equipos: " . json_encode($updates, JSON_UNESCAPED_UNICODE));
        setFlashMessage('success', "Se actualizaron $n equipo(s).");
        adminRedirect('/configuracion.php?tab=equipos');
    } elseif ($action === 'toggle_tecnico') {
        $id = (int)($_POST['id'] ?? 0);
        $registro = CatalogoModel::getById('tecnicos', $id);
        if (!$registro) {
            setFlashMessage('danger', 'Técnico no encontrado.');
        } else {
            $nuevoActivo = empty($registro['activo']) ? 1 : 0;
            CatalogoModel::toggleTecnicoActivo($id, $nuevoActivo);
            $estado = $nuevoActivo ? 'activado' : 'desactivado';
            registrarHistorial('CATALOGO_TOGGLE', "Técnico {$registro['nombre']} {$estado}.");
            setFlashMessage('success', "Técnico '{$registro['nombre']}' {$estado}.");
        }
        adminRedirect('/configuracion.php?tab=tecnicos');
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
                // If a soft-deleted user exists with the same name, revive it
                // (preserves id + history) instead of failing on UNIQUE.
                $existente = UsuarioModel::getByUsernameIncludingDeleted($username);
                if ($existente && !empty($existente['deleted_at'])) {
                    UsuarioModel::reviveByUsername($username, $password, $rol, $tecnicoId, $activo);
                    registrarHistorial('USUARIO_REVIVIR', "Se restauró el usuario eliminado $username con rol $rol.");
                    setFlashMessage('success', "Se restauró el usuario eliminado '$username' con los nuevos datos.");
                } else {
                    UsuarioModel::create($username, $password, $rol, $tecnicoId, $activo);
                    registrarHistorial('USUARIO_CREAR', "Se creó el usuario $username con rol $rol.");
                    setFlashMessage('success', 'Usuario creado correctamente.');
                }
            } catch (InvalidArgumentException $e) {
                setFlashMessage('danger', $e->getMessage());
            } catch (PDOException $e) {
                setFlashMessage('danger', 'No se pudo crear el usuario. Verifique que el nombre no esté repetido.');
            }
        }

        adminRedirect('/configuracion.php?tab=usuarios');
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
            adminRedirect('/configuracion.php?tab=usuarios');
        }

        if ($userId === (int)$_SESSION['user_id'] && !$activo) {
            setFlashMessage('danger', 'No puede desactivar su propio usuario.');
            adminRedirect('/configuracion.php?tab=usuarios');
        }

        if ($userId === (int)$_SESSION['user_id'] && $rol !== 'admin') {
            setFlashMessage('danger', 'No puede quitarse a sí mismo el rol de administrador.');
            adminRedirect('/configuracion.php?tab=usuarios');
        }

        try {
            UsuarioModel::update($userId, $username, $rol, $tecnicoId, $activo, $password);
            registrarHistorial('USUARIO_EDITAR', "Se actualizó el usuario {$usuarioActual['username']}.");
            setFlashMessage('success', 'Usuario actualizado correctamente.');
        } catch (InvalidArgumentException $e) {
            setFlashMessage('danger', $e->getMessage());
        } catch (PDOException $e) {
            setFlashMessage('danger', 'No se pudo actualizar el usuario. Verifique que el nombre no esté repetido.');
        }

        adminRedirect('/configuracion.php?tab=usuarios');
    } elseif ($action === 'delete_usuario') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $target = UsuarioModel::getById($userId);

        if (!$target) {
            setFlashMessage('danger', 'Usuario no encontrado.');
        } elseif ($userId === (int)$_SESSION['user_id']) {
            setFlashMessage('danger', 'No puede eliminar su propio usuario.');
        } else {
            UsuarioModel::softDelete($userId);
            registrarHistorial('USUARIO_ELIMINAR', "Se eliminó el usuario {$target['username']}.");
            setFlashMessage('success', "Usuario '{$target['username']}' eliminado.");
        }
        adminRedirect('/configuracion.php?tab=usuarios');
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $target = UsuarioModel::getById($userId);

        if (!$target) {
            setFlashMessage('danger', 'Usuario no encontrado.');
        } elseif ($userId === (int)$_SESSION['user_id']) {
            setFlashMessage('danger', 'Para cambiar su propia contraseña, use el formulario de edición.');
        } else {
            $temp = UsuarioModel::resetPassword($userId);
            registrarHistorial('USUARIO_RESET_PASS', "Se reseteó la contraseña de {$target['username']}.");
            $_SESSION['temp_password_display'] = [
                'username' => $target['username'],
                'password' => $temp,
            ];
        }
        adminRedirect('/configuracion.php?tab=usuarios');
    } elseif ($action === 'force_logout') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $target = UsuarioModel::getById($userId);

        if (!$target) {
            setFlashMessage('danger', 'Usuario no encontrado.');
        } elseif ($userId === (int)$_SESSION['user_id']) {
            setFlashMessage('danger', 'No puede forzar el cierre de su propia sesión.');
        } else {
            UsuarioModel::forceLogout($userId);
            registrarHistorial('USUARIO_FORCE_LOGOUT', "Se forzó el cierre de sesión de {$target['username']}.");
            setFlashMessage('success', "Sesión de '{$target['username']}' cerrada.");
        }
        adminRedirect('/configuracion.php?tab=usuarios');
    }
}

require_once __DIR__ . '/../public/includes/header.php';
?>



<div class="settings-shell admin-shell">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-3 flex-wrap">
        <div>
            <h2 class="settings-page-title mb-1">Configuración del Sistema</h2>
            <p class="settings-section-intro mb-0">Gestión de usuarios y catálogos operativos con alta densidad de datos.</p>
        </div>
        <div class="settings-header-actions">
            <a href="backups.php" class="btn-backup" target="_blank" title="Descargar respaldo SQL completo">
                <i class="bi bi-download"></i> Respaldo SQL
            </a>
        </div>
    </div>

    <nav class="settings-tabnav">
        <a href="?tab=usuarios" class="tab-item <?= $tab === 'usuarios' ? 'active' : '' ?>"><i class="bi bi-people"></i> Usuarios</a>
        <a href="?tab=tecnicos" class="tab-item <?= $tab === 'tecnicos' ? 'active' : '' ?>"><i class="bi bi-tools"></i> Técnicos</a>
        <a href="?tab=salas" class="tab-item <?= $tab === 'salas' ? 'active' : '' ?>"><i class="bi bi-geo-alt"></i> Salas</a>
        <a href="?tab=equipos" class="tab-item <?= $tab === 'equipos' ? 'active' : '' ?>"><i class="bi bi-pc-display"></i> Equipos</a>
        <a href="?tab=familias" class="tab-item <?= $tab === 'familias' ? 'active' : '' ?>"><i class="bi bi-diagram-2"></i> Familias</a>
        <a href="?tab=estados" class="tab-item <?= $tab === 'estados' ? 'active' : '' ?>"><i class="bi bi-tags"></i> Estados</a>
        <a href="importar_csv.php" class="tab-item"><i class="bi bi-file-earmark-arrow-up"></i> Importar CSV</a>
        <a href="historial.php" class="tab-item"><i class="bi bi-clock-history"></i> Historial</a>
    </nav>

    <?php if ($tab === 'usuarios' && !empty($_SESSION['temp_password_display'])):
        $tp = $_SESSION['temp_password_display'];
        unset($_SESSION['temp_password_display']);
    ?>
    <div class="temp-pass-banner-premium" id="tempPassBanner">
        <i class="bi bi-shield-lock-fill text-warning" style="font-size: 1.5rem; margin-left: 6px;"></i>
        <div style="flex: 1 1 220px;">
            <div class="label">PIN temporal para <?= e($tp['username']) ?></div>
            <div style="font-size: 12px; color: #78350f; margin-top: 2px; font-weight: 500;">
                Entregáselo al usuario. Al iniciar sesión, se le pedirá definir una nueva contraseña. No se mostrará otra vez.
            </div>
        </div>
        <code id="tempPassValue" style="letter-spacing: 4px;"><?= e($tp['password']) ?></code>
        <button type="button" class="btn-copy" onclick="copyTempPass()">
            <i class="bi bi-clipboard me-1.5"></i>Copiar
        </button>
        <button type="button" class="btn-close-banner" onclick="document.getElementById('tempPassBanner').remove()" style="margin-left: auto;" title="Cerrar">
            <i class="bi bi-x-lg" style="font-size: 14px;"></i>
        </button>
    </div>
    <script>
    function copyTempPass() {
        const val = document.getElementById('tempPassValue').textContent;
        navigator.clipboard.writeText(val).then(() => {
            const btn = event.currentTarget;
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check2 me-1.5"></i>Copiado';
            setTimeout(() => btn.innerHTML = orig, 1500);
        });
    }
    </script>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12">
            <?php if ($tab === 'usuarios'): ?>
<style>
    /* Premium variables for User Management */
    :root {
        --user-border: #e2e8f0;
        --user-bg-light: #f8fafc;
        --user-text-main: #0f172a;
        --user-text-muted: #64748b;
        --user-primary: #3b82f6;
        --user-primary-hover: #2563eb;
        --user-success: #10b981;
        --user-success-bg: rgba(16, 185, 129, 0.08);
        --user-danger: #ef4444;
        --user-danger-bg: rgba(239, 68, 68, 0.08);
        --user-warning: #f59e0b;
        --user-warning-bg: #fffbeb;
    }

    /* Elevated Cards with Gradient highlights */
    .user-card-premium {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid var(--user-border);
        box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.04), 0 8px 10px -6px rgba(15, 23, 42, 0.03);
        position: relative;
        overflow: hidden;
        transition: border-color 0.25s, box-shadow 0.25s;
    }
    
    .user-card-premium:hover {
        border-color: #cbd5e1;
        box-shadow: 0 12px 30px -8px rgba(15, 23, 42, 0.06);
    }

    .user-card-premium::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #3b82f6, #8b5cf6);
        z-index: 10;
    }

    .user-card-premium .card-header {
        background: #ffffff;
        border-bottom: 1px solid var(--user-border);
        padding: 1rem 1.25rem 0.9rem;
    }

    .user-card-premium .card-header h6 {
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--user-text-main);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .user-card-premium .card-body {
        padding: 1.25rem;
    }

    /* Filters Bar */
    .user-filters-premium {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
        padding: 0.85rem 1.25rem;
        border-bottom: 1px solid var(--user-border);
        background: #f8fafc;
    }

    .user-filters-premium .filter-search {
        position: relative;
        flex: 1 1 240px;
        max-width: 320px;
    }

    .user-filters-premium .filter-search input {
        width: 100%;
        font-size: 13px;
        padding: 0.45rem 0.75rem 0.45rem 2.1rem;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        background: #ffffff;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        height: 36px;
    }

    .user-filters-premium .filter-search input:focus {
        outline: none;
        border-color: var(--user-primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        background: #ffffff;
    }

    .user-filters-premium .filter-search .bi-search {
        position: absolute;
        left: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 13.5px;
    }

    .user-filters-premium select {
        font-size: 13px;
        padding: 0.45rem 2.1rem 0.45rem 0.75rem;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        background: #ffffff;
        height: 36px;
        min-width: 150px;
        transition: all 0.2s;
    }

    .user-filters-premium select:focus {
        border-color: var(--user-primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        outline: none;
    }

    .user-filters-premium .online-toggle {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 12.5px;
        font-weight: 600;
        color: var(--user-text-muted);
        cursor: pointer;
        user-select: none;
        margin: 0;
        padding: 0.45rem 0.85rem;
        border-radius: 8px;
        border: 1px dashed var(--user-border);
        background: #ffffff;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        height: 36px;
    }

    .user-filters-premium .online-toggle:hover {
        border-color: var(--user-success);
        color: #065f46;
        background: var(--user-success-bg);
    }

    .user-filters-premium .online-toggle.is-active {
        border-color: var(--user-success);
        background: var(--user-success);
        color: #ffffff;
        border-style: solid;
        box-shadow: 0 4px 10px rgba(16, 185, 129, 0.15);
    }

    .user-filters-premium .online-toggle.is-active .bi-circle-fill {
        color: #ffffff !important;
        animation: pulse-online-active 1.6s ease-out infinite;
    }

    @keyframes pulse-online-active {
        0% { transform: scale(1); opacity: 0.8; }
        50% { transform: scale(1.3); opacity: 1; }
        100% { transform: scale(1); opacity: 0.8; }
    }

    .user-filters-premium .online-toggle input {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
    }

    .user-filters-premium .filter-clear {
        font-size: 12px;
        color: var(--user-primary);
        background: none;
        border: none;
        padding: 0.45rem 0.6rem;
        cursor: pointer;
        font-weight: 600;
        border-radius: 6px;
        transition: background 0.15s;
    }

    .user-filters-premium .filter-clear:hover {
        background: #eff6ff;
    }

    /* Premium Avatar with Status Overlay */
    .avatar-wrapper {
        position: relative;
        display: inline-block;
    }

    .user-avatar-premium {
        width: 32px;
        height: 32px;
        font-size: 13px;
        border-radius: 8px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.2s;
    }

    /* Color gradients for avatars based on role */
    .avatar-admin {
        background: linear-gradient(135deg, #fee2e2, #fecaca) !important;
        color: #991b1b !important;
        border: 1px solid rgba(239, 68, 68, 0.1) !important;
    }

    .avatar-tecnico {
        background: linear-gradient(135deg, #e0f2fe, #bae6fd) !important;
        color: #0369a1 !important;
        border: 1px solid rgba(59, 130, 246, 0.1) !important;
    }

    .status-badge-overlay {
        position: absolute;
        bottom: -2px;
        right: -2px;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        border: 2px solid #ffffff;
        box-sizing: content-box;
    }

    .status-badge-overlay.online {
        background-color: #10b981;
        box-shadow: 0 0 0 1px rgba(16, 185, 129, 0.15);
    }
    
    .status-badge-overlay.active {
        background-color: #64748b;
    }

    .status-badge-overlay.inactive {
        background-color: #ef4444;
    }

    /* Pulsing online status dot */
    .status-badge-overlay.online::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background-color: #10b981;
        animation: avatar-pulse 1.8s infinite ease-in-out;
        z-index: -1;
    }

    @keyframes avatar-pulse {
        0% { transform: scale(1); opacity: 0.7; }
        100% { transform: scale(2.4); opacity: 0; }
    }

    /* Elegant Role Badges */
    .role-badge-premium {
        font-size: 11.5px;
        font-weight: 600;
        padding: 0.25rem 0.55rem;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        letter-spacing: 0.01em;
    }

    .role-badge-admin {
        background-color: rgba(239, 68, 68, 0.06);
        color: #dc2626;
        border: 1px solid rgba(239, 68, 68, 0.15);
    }

    .role-badge-tecnico {
        background-color: rgba(59, 130, 246, 0.06);
        color: #2563eb;
        border: 1px solid rgba(59, 130, 246, 0.15);
    }

    /* Action Buttons in Table */
    .btn-action-circle {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #ffffff;
        border: 1px solid var(--user-border);
        color: var(--user-text-muted);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        padding: 0;
    }

    .btn-action-circle:hover {
        background: #f1f5f9;
        color: var(--user-text-main);
        transform: translateY(-1px);
    }

    .btn-action-circle.btn-edit-user:hover {
        border-color: var(--user-primary);
        color: var(--user-primary);
        background: #eff6ff;
        box-shadow: 0 4px 10px rgba(59, 130, 246, 0.1);
    }

    .btn-action-circle.btn-logout-user:hover {
        border-color: var(--user-warning);
        color: var(--user-warning);
        background: #fffbeb;
        box-shadow: 0 4px 10px rgba(245, 158, 11, 0.1);
    }

    /* Custom Forms within configuracion */
    .settings-shell .dense-form .form-label {
        font-size: 11.5px;
        font-weight: 600;
        color: var(--user-text-main);
        margin-bottom: 0.35rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .settings-shell .dense-form .form-control,
    .settings-shell .dense-form .form-select {
        height: 38px;
        font-size: 13.5px;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        padding: 0.45rem 0.75rem;
        background-color: var(--user-bg-light);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        color: var(--user-text-main);
    }

    .settings-shell .dense-form .form-control:focus,
    .settings-shell .dense-form .form-select:focus {
        background-color: #ffffff;
        border-color: var(--user-primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        outline: none;
    }

    /* Switch Customization */
    .form-switch-premium {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        background: #f8fafc;
        border: 1px solid var(--user-border);
        border-radius: 8px;
        padding: 0.6rem 0.75rem;
        cursor: pointer;
        transition: all 0.2s;
    }

    .form-switch-premium:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
    }

    .form-switch-premium .form-check-input {
        margin: 0;
        cursor: pointer;
        width: 1.75em;
        height: 1em;
    }

    /* Temp PIN Banner Premium */
    .temp-pass-banner-premium {
        background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        border: 1px solid rgba(245, 158, 11, 0.4);
        border-radius: 12px;
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
        box-shadow: 0 10px 20px -5px rgba(245, 158, 11, 0.05);
        position: relative;
    }

    .temp-pass-banner-premium::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        bottom: 0;
        width: 4px;
        background: #f59e0b;
        border-top-left-radius: 12px;
        border-bottom-left-radius: 12px;
    }

    .temp-pass-banner-premium .label {
        font-size: 13px;
        color: #78350f;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .temp-pass-banner-premium code {
        font-size: 1.45rem;
        font-family: monospace;
        background: #ffffff;
        border: 1px solid rgba(245, 158, 11, 0.25);
        border-radius: 8px;
        padding: 0.35rem 0.9rem;
        color: #b45309;
        font-weight: 700;
        letter-spacing: 4px;
        user-select: all;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
    }

    .temp-pass-banner-premium .btn-copy {
        font-size: 12.5px;
        padding: 0.45rem 1rem;
        border-radius: 8px;
        border: 1px solid #f59e0b;
        background: #ffffff;
        color: #b45309;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 2px 4px rgba(245, 158, 11, 0.05);
    }

    .temp-pass-banner-premium .btn-copy:hover {
        background: #fef3c7;
        transform: translateY(-1px);
    }

    .temp-pass-banner-premium .btn-close-banner {
        background: none;
        border: none;
        color: #b45309;
        font-size: 20px;
        cursor: pointer;
        padding: 4px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        width: 28px;
        height: 28px;
        transition: background 0.2s;
    }

    .temp-pass-banner-premium .btn-close-banner:hover {
        background: rgba(180, 83, 9, 0.08);
    }

    /* Danger zone */
    .settings-shell .danger-zone {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 10px;
        padding: 0.9rem 1rem;
        margin-top: 0.5rem;
    }
    
    .settings-shell .danger-zone h6 {
        font-size: 11px;
        color: #991b1b;
        margin: 0 0 0.6rem 0;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .settings-shell .danger-zone .btn {
        font-size: 12px;
        font-weight: 600;
        padding: 0.45rem 0.8rem;
        border-radius: 8px;
    }
</style>
                <div class="row g-3">
                    <div class="col-lg-3">
                        <div class="user-card-premium">
                            <div class="card-header">
                                <h6><i class="bi bi-person-plus text-primary fs-5 me-1"></i>Crear Usuario</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="dense-form d-grid gap-2" autocomplete="off">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="action" value="add_usuario">

                                    <div>
                                        <label class="form-label"><i class="bi bi-person text-secondary me-1"></i>Nombre de Usuario</label>
                                        <input type="text" name="new_username" class="form-control" required placeholder="Usuario">
                                    </div>
                                    <div>
                                        <label class="form-label"><i class="bi bi-lock text-secondary me-1"></i>Contraseña</label>
                                        <input type="password" name="new_password" class="form-control" required minlength="<?= MIN_PASSWORD_LENGTH ?>" placeholder="Mín. <?= MIN_PASSWORD_LENGTH ?> caracteres">
                                    </div>
                                    <div>
                                        <label class="form-label"><i class="bi bi-shield-check text-secondary me-1"></i>Rol del Sistema</label>
                                        <select name="rol" class="form-select user-role-select" data-target="new-user-tecnico">
                                            <option value="tecnico">Técnico</option>
                                            <option value="admin">Administrador</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label"><i class="bi bi-person-badge text-secondary me-1"></i>Técnico Vinculado</label>
                                        <select name="tecnico_id" id="new-user-tecnico" class="form-select">
                                            <option value="">Ninguno / No aplica</option>
                                            <?php foreach ($tecnicos as $t): ?>
                                                <option value="<?= e($t['id']) ?>"><?= e($t['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-switch-premium mt-1">
                                        <input type="checkbox" class="form-check-input" id="activo_nuevo" name="activo" checked>
                                        <label class="form-check-label fw-semibold text-dark m-0 cursor-pointer" style="font-size: 0.8rem;" for="activo_nuevo">Habilitar Acceso</label>
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100 mt-2 fw-bold" style="border-radius: 8px; height: 38px;">Crear Usuario</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-9">
                        <div class="user-card-premium">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6><i class="bi bi-people-fill text-primary fs-5 me-1"></i>Gestión de Usuarios</h6>
                                <span class="badge bg-light text-secondary border fw-bold" style="font-size: 0.75rem;" id="userCount">
                                    <?= count($usuarios) ?> <?= count($usuarios) === 1 ? 'usuario' : 'usuarios' ?>
                                </span>
                            </div>
                            <div class="user-filters-premium">
                                <div class="filter-search">
                                    <i class="bi bi-search"></i>
                                    <input type="search" id="userSearch" placeholder="Buscar por usuario o técnico..." autocomplete="off">
                                </div>
                                <select id="userRoleFilter" title="Filtrar por rol">
                                    <option value="">Todos los roles</option>
                                    <option value="admin">Solo Administradores</option>
                                    <option value="tecnico">Solo Técnicos</option>
                                </select>
                                <label class="online-toggle" id="userOnlineFilterLabel" title="Mostrar solo usuarios con sesión activa">
                                    <input type="checkbox" id="userOnlineFilter">
                                    <span><i class="bi bi-circle-fill text-success" style="font-size: 0.5rem;"></i> Solo en línea</span>
                                </label>
                                <button type="button" class="filter-clear" id="userFilterClear" hidden>
                                    <i class="bi bi-x-circle me-1"></i>Limpiar
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table dense-table mb-0" id="userTable">
                                        <thead>
                                            <tr>
                                                <th>Usuario</th>
                                                <th>Rol</th>
                                                <th>Técnico</th>
                                                <th class="text-center">Estado</th>
                                                <th>Última conexión</th>
                                                <th>Creado</th>
                                                <th class="text-end"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($usuarios as $u):
                                                $isSelf = (int)$u['id'] === (int)$_SESSION['user_id'];
                                                $isOnline = !empty($u['session_id']);
                                                $searchKey = mb_strtolower($u['username'] . ' ' . ($u['tecnico_nombre'] ?? ''), 'UTF-8');
                                            ?>
                                            <tr data-username="<?= e($searchKey) ?>"
                                                data-rol="<?= e($u['rol']) ?>"
                                                data-online="<?= $isOnline ? '1' : '0' ?>">
                                                <td>
                                                    <div class="d-flex align-items-center gap-2.5">
                                                        <div class="avatar-wrapper">
                                                            <div class="user-avatar-premium avatar-<?= $u['rol'] ?>">
                                                                <?= e(mb_strtoupper(mb_substr($u['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                                            </div>
                                                            <span class="status-badge-overlay <?= !$u['activo'] ? 'inactive' : ($isOnline ? 'online' : 'active') ?>" 
                                                                  title="<?= !$u['activo'] ? 'Deshabilitado' : ($isOnline ? 'En línea' : 'Habilitado') ?>"></span>
                                                        </div>
                                                        <div class="d-flex flex-column">
                                                            <span class="fw-bold text-dark" style="font-size: 13.5px;"><?= e($u['username']) ?></span>
                                                            <?php if ($isSelf): ?>
                                                                <span class="text-primary fw-semibold" style="font-size: 11px; margin-top: -1px;">Tú mismo</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($u['rol'] === 'admin'): ?>
                                                        <span class="role-badge-premium role-badge-admin"><i class="bi bi-shield-lock me-0.5"></i>Administrador</span>
                                                    <?php else: ?>
                                                        <span class="role-badge-premium role-badge-tecnico"><i class="bi bi-person-gear me-0.5"></i>Técnico</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-secondary small fw-medium"><?= e($u['tecnico_nombre'] ?? '-') ?></td>
                                                <td class="text-center">
                                                    <?php if (!$u['activo']): ?>
                                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-10 fw-semibold" style="font-size: 11.5px; padding: 0.25rem 0.5rem; border-radius: 6px;">Suspendido</span>
                                                    <?php elseif ($isOnline): ?>
                                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-10 fw-semibold" style="font-size: 11.5px; padding: 0.25rem 0.5rem; border-radius: 6px;">Activo</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-10 fw-semibold" style="font-size: 11.5px; padding: 0.25rem 0.5rem; border-radius: 6px;">Habilitado</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-secondary small fw-medium">
                                                    <?php if ($isOnline): ?>
                                                        <span class="text-success fw-bold d-flex align-items-center gap-1" style="font-size: 12.5px;">
                                                            <span class="d-inline-block bg-success rounded-circle animate-pulse" style="width: 6px; height: 6px;"></span> En línea
                                                        </span>
                                                        <?php if (!empty($u['last_ip'])): ?>
                                                            <div class="text-muted text-secondary" style="font-size: 10.5px; margin-top: 1px;"><i class="bi bi-hdd me-0.5"></i> <?= e($u['last_ip']) ?></div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <div class="d-flex align-items-center gap-1 text-muted">
                                                            <i class="bi bi-clock me-0.5"></i>
                                                            <?= $u['last_login'] ? date('d/m/y H:i', strtotime($u['last_login'])) : '<span class="text-muted fst-italic">Nunca</span>' ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-muted small">
                                                    <i class="bi bi-calendar3 me-0.5"></i>
                                                    <?= $u['created_at'] ? date('d/m/y', strtotime($u['created_at'])) : '-' ?>
                                                </td>
                                                <td class="text-end px-3">
                                                    <div class="d-inline-flex gap-1.5">
                                                        <?php if ($isOnline && !$isSelf): ?>
                                                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Forzar cierre de sesión de <?= e($u['username']) ?>?');">
                                                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                                                <input type="hidden" name="action" value="force_logout">
                                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                                <button type="submit" class="btn-action-circle btn-logout-user" title="Forzar cierre de sesión">
                                                                    <i class="bi bi-box-arrow-right text-warning"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn-action-circle btn-edit-user" data-bs-toggle="modal" data-bs-target="#editUser<?= $u['id'] ?>" title="Editar">
                                                            <i class="bi bi-pencil-fill"></i>
                                                        </button>
                                                    </div>
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

                                    <?php if ($tab === 'equipos'): ?>
                                    <div>
                                        <label class="form-label">LAB <span class="text-danger">*</span></label>
                                        <input type="number" name="lab" id="catAddLab" class="form-control" required min="1" placeholder="Ej: 101" autocomplete="off">
                                        <div id="catAddLabWarn" class="form-text text-danger d-none" style="font-size: 0.72rem;">
                                            <i class="bi bi-exclamation-triangle"></i> <span></span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <label class="form-label">Nombre</label>
                                        <input type="text" name="nombre" class="form-control" required placeholder="Nombre del <?= rtrim(strtolower($tab_titles[$tab] ?? 'elemento'), 's') ?>">
                                    </div>
                                    <?php if ($tab === 'equipos'): ?>
                                    <div>
                                        <label class="form-label">Familia</label>
                                        <select name="familia" class="form-select">
                                            <option value="">-- Sin familia --</option>
                                            <?php foreach ($familias as $f): ?>
                                                <option value="<?= e($f['nombre']) ?>"><?= e($f['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Valor</label>
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
                        <div class="dense-card catalog-list-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6>Listado de <?= $tab_titles[$tab] ?? 'Registros' ?></h6>
                                <span class="text-muted" style="font-size: 0.75rem;" id="catalogCount"><?= $tab_counts[$tab] ?? count($data) ?> registros</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($data)): ?>
                                <?php if (in_array($tab, ['tecnicos', 'salas', 'equipos', 'familias'], true)): ?>
                                <div class="user-filters">
                                    <div class="filter-search">
                                        <i class="bi bi-search"></i>
                                        <input type="search" id="catalogSearch" placeholder="Buscar por nombre..." autocomplete="off">
                                    </div>
                                    <button type="button" class="filter-clear" id="catalogFilterClear" hidden>
                                        <i class="bi bi-x-circle me-1"></i>Limpiar
                                    </button>
                                </div>
                                <?php endif; ?>
                                <?php if ($tab === 'equipos'): ?>
                                <div class="bulk-action-bar" id="bulkBar" style="display: none;">
                                    <span><span class="bulk-count" id="bulkCount">0</span> equipos seleccionados</span>
                                    <span class="bulk-label">·</span>
                                    <label class="bulk-label m-0">Asignar familia:</label>
                                    <select id="bulkFamilia">
                                        <option value="">-- Sin familia --</option>
                                        <?php foreach ($familias as $f): ?>
                                            <option value="<?= e($f['nombre']) ?>"><?= e($f['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-primary" id="bulkApply">
                                        <i class="bi bi-check2"></i> Aplicar
                                    </button>
                                    <button type="button" class="btn btn-sm btn-light border" id="bulkClear">
                                        Limpiar selección
                                    </button>
                                </div>
                                <?php endif; ?>
                                <div class="catalog-table-scroll">
                                <table class="table dense-table mb-0" id="catalogTable">
                                        <thead>
                                            <tr>
                                                <?php if ($tab === 'tecnicos'): ?>
                                                <th class="cat-name-cell">Nombre</th>
                                                <th class="text-center" style="width: 110px;">Estado</th>
                                                <th class="text-center" style="width: 90px;" title="Reparaciones activas asignadas">Activas</th>
                                                <?php elseif (in_array($tab, ['salas', 'equipos', 'familias'], true)): ?>
                                                <?php if ($tab === 'equipos'): ?>
                                                <th style="width: 32px;"><input type="checkbox" class="bulk-check-all" id="bulkCheckAll" title="Seleccionar todos"></th>
                                                <?php endif; ?>
                                                <th class="cat-name-cell">Nombre</th>
                                                <?php if ($tab === 'equipos'): ?>
                                                <th style="width: 130px;">Familia</th>
                                                <th>Valor</th>
                                                <?php endif; ?>
                                                <th class="text-center" style="width: 80px;" title="Reparaciones que referencian este nombre">REP.</th>
                                                <?php else: ?>
                                                <th class="cat-name-cell">Descripción</th>
                                                <?php endif; ?>
                                                <th class="text-end" style="width: 110px;">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($data as $d):
                                                $activeCnt = $tab === 'tecnicos' ? ($repsActivasPorTecnico[(int)$d['id']] ?? 0) : 0;
                                                $usageCnt = in_array($tab, ['salas','equipos','familias'], true)
                                                    ? ($usoEnReparaciones[mb_strtoupper(trim($d['nombre']), 'UTF-8')] ?? 0)
                                                    : 0;
                                                $isActivo = $tab !== 'tecnicos' || !empty($d['activo']);
                                                $hideId = in_array($tab, ['tecnicos','salas','equipos','familias','estados'], true);
                                                $searchKey = mb_strtolower($d['nombre'], 'UTF-8');
                                            ?>
                                            <tr data-nombre="<?= e($searchKey) ?>"<?= $tab === 'tecnicos' && !$isActivo ? ' class="table-secondary text-muted"' : '' ?>>
                                                <?php if ($tab === 'equipos'): ?>
                                                <td class="text-center"><input type="checkbox" class="bulk-check" value="<?= e($d['id']) ?>"></td>
                                                <?php endif; ?>
                                                <td class="cat-name-cell">
                                                    <div class="fw-bold" title="<?= e($d['nombre']) ?>"><?= e($d['nombre']) ?></div>
                                                    <?php if (!$hideId): ?>
                                                    <div class="text-muted" style="font-size: 0.7rem;">ID #<?= e($d['id']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if ($tab === 'tecnicos'): ?>
                                                <td class="text-center">
                                                    <?php if ($isActivo): ?>
                                                        <span class="badge-pill" style="background:#dcfce7;color:#166534;">Activo</span>
                                                    <?php else: ?>
                                                        <span class="badge-pill" style="background:#f3f4f6;color:#6b7280;">Inactivo</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($activeCnt > 0): ?>
                                                        <span class="badge-pill" style="background:#dbeafe;color:#1e40af;"><?= $activeCnt ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted" style="font-size: 0.75rem;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php elseif (in_array($tab, ['salas','equipos','familias'], true)): ?>
                                                    <?php if ($tab === 'equipos'): ?>
                                                    <td class="text-muted">
                                                        <?php if (!empty($d['familia'])): ?>
                                                            <?= e($d['familia']) ?>
                                                        <?php else: ?>
                                                            <span class="text-muted fst-italic" style="font-size: 0.75rem;">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-muted" style="white-space: nowrap;"><?= e(formatArs($d['valor']) ?: '—') ?></td>
                                                    <?php endif; ?>
                                                    <td class="text-center">
                                                        <?php if ($usageCnt > 0): ?>
                                                            <span class="badge-pill" style="background:#dbeafe;color:#1e40af;"><?= $usageCnt ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted" style="font-size: 0.75rem;">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                                <td class="text-end px-3">
                                                    <div class="d-inline-flex gap-1">
                                                        <?php if ($tab === 'tecnicos'): ?>
                                                            <button type="button" class="btn btn-sm btn-light border btn-icon"
                                                                    data-catalog-action="toggle"
                                                                    data-id="<?= e($d['id']) ?>"
                                                                    data-nombre="<?= e($d['nombre']) ?>"
                                                                    data-activo="<?= $isActivo ? '1' : '0' ?>"
                                                                    title="<?= $isActivo ? 'Desactivar' : 'Activar' ?>">
                                                                <i class="bi bi-<?= $isActivo ? 'pause' : 'play' ?>-fill text-<?= $isActivo ? 'warning' : 'success' ?>" style="font-size: 0.75rem;"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php
                                                            $nombreClean = $tab === 'equipos'
                                                                ? preg_replace('/\s*\(LAB\d+\)\s*$/u', '', $d['nombre'])
                                                                : $d['nombre'];
                                                        ?>
                                                        <button type="button" class="btn btn-sm btn-light border btn-icon"
                                                                data-catalog-action="edit"
                                                                data-id="<?= e($d['id']) ?>"
                                                                data-nombre="<?= e($nombreClean) ?>"
                                                                data-valor="<?= e($d['valor'] ?? '') ?>"
                                                                data-lab="<?= e($d['lab'] ?? '') ?>"
                                                                data-familia="<?= e($d['familia'] ?? '') ?>"
                                                                data-activo="<?= $isActivo ? '1' : '0' ?>"
                                                                title="Editar">
                                                            <i class="bi bi-pencil" style="font-size: 0.75rem;"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-light border btn-icon"
                                                                data-catalog-action="delete"
                                                                data-id="<?= e($d['id']) ?>"
                                                                data-nombre="<?= e($d['nombre']) ?>"
                                                                data-active-count="<?= $activeCnt ?>"
                                                                data-usage-count="<?= $usageCnt ?>"
                                                                title="Eliminar">
                                                            <i class="bi bi-trash text-danger" style="font-size: 0.75rem;"></i>
                                                        </button>
                                                    </div>
                                                </td>
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

<?php if ($tab !== 'usuarios'):
    $tipoLabelSingular = rtrim($tab_titles[$tab] ?? 'Elemento', 's');
?>
<!-- Generic catalog delete confirmation modal -->
<div class="modal fade" id="catalogDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <form method="POST" class="modal-content border-0 shadow-sm" style="border-radius: 12px;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="delete_catalogo">
            <input type="hidden" name="tipo" value="<?= e($tab) ?>">
            <input type="hidden" name="id" id="catDeleteId" value="">

            <div class="modal-header border-bottom-0 pt-4 pb-0 px-4">
                <h6 class="modal-title fw-bold" style="font-size: 1rem;">
                    <i class="bi bi-trash3-fill text-danger me-2"></i>
                    <?= $tab === 'tecnicos' ? 'Eliminar técnico' : 'Eliminar ' . e(rtrim(strtolower($tab_titles[$tab] ?? 'registro'), 's')) ?>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body px-4 py-3" style="font-size: 0.85rem; color: #334155;">
                <p class="mb-2">
                    ¿Eliminar a <strong id="catDeleteName" class="text-danger">—</strong>?
                </p>

                <?php if ($tab === 'tecnicos'): ?>
                <ul class="ps-3 mb-2" style="font-size: 0.8rem; color: #64748b;">
                    <li>El técnico se <strong>borra permanentemente</strong> del sistema.</li>
                    <li>Sus reparaciones <strong>no se borran</strong>: se conservan con su nombre como referencia histórica.</li>
                    <li>No podrá reactivarse: para inhabilitar temporalmente, usá el botón <i class="bi bi-pause-fill text-warning"></i> Desactivar.</li>
                </ul>
                <div id="catDeleteActiveWarn" class="alert alert-warning border-0 mb-0" style="font-size: 0.78rem; padding: 0.55rem 0.7rem; background: #fef3c7; display: none;">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Tiene <strong><span id="catDeleteActiveCount">0</span></strong> reparación(es) activa(s).
                    Considerá reasignarlas antes de eliminar.
                </div>
                <?php else: ?>
                <ul class="ps-3 mb-2" style="font-size: 0.8rem; color: #64748b;">
                    <li>Las reparaciones existentes conservan el nombre como texto.</li>
                    <li>No se podrá seleccionar al crear nuevas reparaciones.</li>
                    <li>Esta acción <strong>no se puede deshacer</strong>.</li>
                </ul>
                <?php if (in_array($tab, ['salas','equipos','familias'], true)): ?>
                <div id="catDeleteUsageWarn" class="alert alert-warning border-0 mb-0" style="font-size: 0.78rem; padding: 0.55rem 0.7rem; background: #fef3c7; display: none;">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Aparece en <strong><span id="catDeleteUsageCount">0</span></strong> reparación(es).
                    Se mantienen visibles pero el nombre deja de estar disponible para nuevos ingresos.
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="modal-footer border-top-0 px-4 pb-4 pt-2 d-flex gap-2">
                <button type="button" class="btn btn-light flex-grow-1 fw-bold text-secondary border"
                        style="font-size: 0.8rem; padding: 0.5rem;" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger flex-grow-1 fw-bold shadow-none"
                        style="font-size: 0.8rem; padding: 0.5rem;">
                    <i class="bi bi-trash me-1"></i>Eliminar
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($tab === 'tecnicos'): ?>
<!-- Tecnico activate/deactivate confirmation modal -->
<div class="modal fade" id="catalogToggleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <form method="POST" class="modal-content border-0 shadow-sm" style="border-radius: 12px;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="toggle_tecnico">
            <input type="hidden" name="id" id="catToggleId" value="">

            <div class="modal-header border-bottom-0 pt-4 pb-0 px-4">
                <h6 class="modal-title fw-bold" style="font-size: 1rem;">
                    <i id="catToggleIcon" class="bi bi-pause-fill text-warning me-2"></i>
                    <span id="catToggleTitle">Desactivar técnico</span>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body px-4 py-3" style="font-size: 0.85rem; color: #334155;">
                <p class="mb-2">
                    <span id="catToggleVerb">¿Desactivar</span> a <strong id="catToggleName" class="text-primary">—</strong>?
                </p>
                <ul id="catToggleDeactivateBullets" class="ps-3 mb-0" style="font-size: 0.8rem; color: #64748b;">
                    <li>No aparecerá más en los selectores de técnico.</li>
                    <li>Sus reparaciones se mantienen.</li>
                    <li>Podés reactivarlo cuando quieras.</li>
                </ul>
                <ul id="catToggleActivateBullets" class="ps-3 mb-0" style="font-size: 0.8rem; color: #64748b; display: none;">
                    <li>Vuelve a aparecer en los selectores de técnico.</li>
                    <li>Las asignaciones previas se mantienen.</li>
                </ul>
            </div>

            <div class="modal-footer border-top-0 px-4 pb-4 pt-2 d-flex gap-2">
                <button type="button" class="btn btn-light flex-grow-1 fw-bold text-secondary border"
                        style="font-size: 0.8rem; padding: 0.5rem;" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" id="catToggleSubmit" class="btn btn-warning flex-grow-1 fw-bold shadow-none"
                        style="font-size: 0.8rem; padding: 0.5rem;">
                    <i id="catToggleSubmitIcon" class="bi bi-pause-fill me-1"></i>
                    <span id="catToggleSubmitText">Desactivar</span>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Generic catalog edit modal -->
<div class="modal fade" id="catalogEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <form method="POST" class="modal-content border-0 shadow-sm" style="border-radius: 12px;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="edit_catalogo">
            <input type="hidden" name="tipo" value="<?= e($tab) ?>">
            <input type="hidden" name="id" id="catEditId" value="">

            <div class="modal-header border-bottom-0 pt-4 pb-0 px-4">
                <h6 class="modal-title fw-bold" style="font-size: 1rem;">
                    <i class="bi bi-pencil-square text-primary me-2"></i>Editar <?= e($tipoLabelSingular) ?>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body px-4 py-3 d-grid gap-3">
                <?php if ($tab === 'equipos'): ?>
                <div class="dense-form">
                    <label class="form-label">LAB <span class="text-danger">*</span></label>
                    <input type="number" name="lab" id="catEditLab" class="form-control" required min="1" autocomplete="off">
                    <div id="catEditLabWarn" class="form-text text-danger d-none" style="font-size: 0.72rem;">
                        <i class="bi bi-exclamation-triangle"></i> <span></span>
                    </div>
                </div>
                <?php endif; ?>
                <div class="dense-form">
                    <label class="form-label">Nombre</label>
                    <input type="text" name="nombre" id="catEditNombre" class="form-control" required maxlength="100">
                </div>
                <?php if ($tab === 'equipos'): ?>
                <div class="dense-form">
                    <label class="form-label">Familia</label>
                    <select name="familia" id="catEditFamilia" class="form-select">
                        <option value="">-- Sin familia --</option>
                        <?php foreach ($familias as $f): ?>
                            <option value="<?= e($f['nombre']) ?>"><?= e($f['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="dense-form">
                    <label class="form-label">Valor</label>
                    <input type="text" name="extra" id="catEditExtra" class="form-control" maxlength="100" placeholder="Opcional">
                </div>
                <?php endif; ?>
                <?php if ($tab === 'tecnicos'): ?>
                <div class="form-check form-switch p-2 bg-light rounded border">
                    <input type="checkbox" class="form-check-input ms-0 me-2" name="activo" id="catEditActivo">
                    <label class="form-check-label fw-semibold" for="catEditActivo" style="font-size: 0.8rem;">Técnico activo</label>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-top-0 px-4 pb-4 pt-0 d-flex gap-2">
                <button type="button" class="btn btn-light flex-grow-1 fw-bold text-secondary border" style="font-size: 0.8rem; padding: 0.5rem;" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary flex-grow-1 fw-bold shadow-none" style="font-size: 0.8rem; padding: 0.5rem;">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const CSRF_CAT = <?= json_encode(generateCsrfToken()) ?>;
    const TIPO = <?= json_encode($tab) ?>;

    function postCatalog(action, fields) {
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = '';
        const all = Object.assign({ csrf_token: CSRF_CAT, action: action, tipo: TIPO }, fields);
        Object.entries(all).forEach(([k, v]) => {
            const i = document.createElement('input');
            i.type = 'hidden';
            i.name = k;
            i.value = v;
            f.appendChild(i);
        });
        document.body.appendChild(f);
        f.submit();
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-catalog-action]');
        if (!btn) return;
        const action = btn.dataset.catalogAction;
        const id = btn.dataset.id;
        const nombre = btn.dataset.nombre || '';

        if (action === 'edit') {
            const modalEl = document.getElementById('catalogEditModal');
            document.getElementById('catEditId').value = id;
            document.getElementById('catEditNombre').value = nombre;
            const extraInput = document.getElementById('catEditExtra');
            if (extraInput) extraInput.value = btn.dataset.valor || '';
            const labInput = document.getElementById('catEditLab');
            if (labInput) labInput.value = btn.dataset.lab || '';
            const familiaInput = document.getElementById('catEditFamilia');
            if (familiaInput) familiaInput.value = btn.dataset.familia || '';
            const activoInput = document.getElementById('catEditActivo');
            if (activoInput) activoInput.checked = btn.dataset.activo === '1';
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        } else if (action === 'toggle') {
            const isActivo = btn.dataset.activo === '1';
            const modalEl = document.getElementById('catalogToggleModal');
            if (!modalEl) return;

            document.getElementById('catToggleId').value = id;
            document.getElementById('catToggleName').textContent = nombre;
            document.getElementById('catToggleTitle').textContent = isActivo ? 'Desactivar técnico' : 'Reactivar técnico';
            document.getElementById('catToggleVerb').textContent = isActivo ? '¿Desactivar' : '¿Reactivar';
            document.getElementById('catToggleSubmitText').textContent = isActivo ? 'Desactivar' : 'Reactivar';

            const headerIcon = document.getElementById('catToggleIcon');
            const submitBtn = document.getElementById('catToggleSubmit');
            const submitIcon = document.getElementById('catToggleSubmitIcon');
            headerIcon.className = isActivo ? 'bi bi-pause-fill text-warning me-2' : 'bi bi-play-fill text-success me-2';
            submitBtn.className = `btn ${isActivo ? 'btn-warning' : 'btn-success'} flex-grow-1 fw-bold shadow-none`;
            submitBtn.style.fontSize = '0.8rem';
            submitBtn.style.padding = '0.5rem';
            submitIcon.className = isActivo ? 'bi bi-pause-fill me-1' : 'bi bi-play-fill me-1';

            document.getElementById('catToggleDeactivateBullets').style.display = isActivo ? '' : 'none';
            document.getElementById('catToggleActivateBullets').style.display = isActivo ? 'none' : '';

            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        } else if (action === 'delete') {
            const modalEl = document.getElementById('catalogDeleteModal');
            document.getElementById('catDeleteId').value = id;
            document.getElementById('catDeleteName').textContent = nombre;

            const activeWarn = document.getElementById('catDeleteActiveWarn');
            if (activeWarn) {
                const cnt = parseInt(btn.dataset.activeCount || '0', 10);
                activeWarn.style.display = (TIPO === 'tecnicos' && cnt > 0) ? '' : 'none';
                const cntSpan = document.getElementById('catDeleteActiveCount');
                if (cntSpan) cntSpan.textContent = cnt;
            }

            const usageWarn = document.getElementById('catDeleteUsageWarn');
            if (usageWarn) {
                const usage = parseInt(btn.dataset.usageCount || '0', 10);
                usageWarn.style.display = usage > 0 ? '' : 'none';
                const usageSpan = document.getElementById('catDeleteUsageCount');
                if (usageSpan) usageSpan.textContent = usage;
            }

            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    });

    // --- Bulk actions for equipos ---
    (function () {
        const bulkBar = document.getElementById('bulkBar');
        if (!bulkBar) return;
        const bulkCount   = document.getElementById('bulkCount');
        const checkAll    = document.getElementById('bulkCheckAll');
        const applyBtn    = document.getElementById('bulkApply');
        const clearBtn    = document.getElementById('bulkClear');
        const familiaSel  = document.getElementById('bulkFamilia');

        function visibleChecks() {
            return Array.from(document.querySelectorAll('.bulk-check'))
                .filter(cb => cb.closest('tr').style.display !== 'none');
        }
        function selectedChecks() {
            return Array.from(document.querySelectorAll('.bulk-check:checked'));
        }
        function refresh() {
            const sel = selectedChecks();
            bulkCount.textContent = sel.length;
            bulkBar.style.display = sel.length > 0 ? '' : 'none';
            // Master state reflects visible rows
            const vis = visibleChecks();
            if (checkAll) {
                const allChecked = vis.length > 0 && vis.every(cb => cb.checked);
                checkAll.checked = allChecked;
                checkAll.indeterminate = !allChecked && sel.length > 0;
            }
        }

        if (checkAll) {
            checkAll.addEventListener('change', () => {
                visibleChecks().forEach(cb => cb.checked = checkAll.checked);
                refresh();
            });
        }
        document.querySelectorAll('.bulk-check').forEach(cb => cb.addEventListener('change', refresh));

        clearBtn?.addEventListener('click', () => {
            document.querySelectorAll('.bulk-check').forEach(cb => cb.checked = false);
            refresh();
        });

        applyBtn?.addEventListener('click', () => {
            const ids = selectedChecks().map(cb => cb.value);
            if (!ids.length) return;
            const familia = familiaSel.value;
            const label = familia || '(sin familia)';
            if (!confirm(`Aplicar familia "${label}" a ${ids.length} equipo(s)?`)) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            const append = (name, value) => {
                const i = document.createElement('input');
                i.type = 'hidden'; i.name = name; i.value = value;
                form.appendChild(i);
            };
            append('csrf_token', CSRF_CAT);
            append('action', 'bulk_update_equipos');
            append('familia', familia);
            ids.forEach(id => append('ids[]', id));
            document.body.appendChild(form);
            form.submit();
        });

        // Re-run after search filter shows/hides rows
        const catSearchInput = document.getElementById('catalogSearch');
        catSearchInput?.addEventListener('input', () => requestAnimationFrame(refresh));

        refresh();
    })();

    // --- Live LAB uniqueness check (equipos only) ---
    function attachLabCheck(input, warnBox, getExcludeId) {
        if (!input || !warnBox) return;
        const span = warnBox.querySelector('span');
        let timer = null;
        const check = async () => {
            const val = input.value.trim();
            if (!val || parseInt(val, 10) <= 0) {
                warnBox.classList.add('d-none');
                input.classList.remove('is-invalid');
                return;
            }
            try {
                const exclude = getExcludeId ? getExcludeId() : '';
                const url = `../api/check_lab.php?lab=${encodeURIComponent(val)}` +
                            (exclude ? `&exclude=${encodeURIComponent(exclude)}` : '');
                const res = await fetch(url);
                if (!res.ok) {
                    console.error('check_lab returned', res.status);
                    return;
                }
                const data = await res.json();
                if (data.exists) {
                    span.textContent = `LAB ${val} ya está asignado a "${data.equipo}".`;
                    warnBox.classList.remove('d-none');
                    input.classList.add('is-invalid');
                } else {
                    warnBox.classList.add('d-none');
                    input.classList.remove('is-invalid');
                }
            } catch (e) {
                console.error('check_lab failed', e);
            }
        };
        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(check, 350);
        });
        input.addEventListener('blur', check);
    }

    attachLabCheck(
        document.getElementById('catAddLab'),
        document.getElementById('catAddLabWarn'),
        null
    );
    attachLabCheck(
        document.getElementById('catEditLab'),
        document.getElementById('catEditLabWarn'),
        () => document.getElementById('catEditId').value
    );

    // --- Catalog table search filter ---
    const catTable = document.getElementById('catalogTable');
    const catSearch = document.getElementById('catalogSearch');
    const catClear = document.getElementById('catalogFilterClear');
    const catCount = document.getElementById('catalogCount');
    if (catTable && catSearch) {
        const rows = Array.from(catTable.tBodies[0].rows);
        const total = rows.length;
        const norm = (s) => (s || '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        const apply = () => {
            const q = norm(catSearch.value.trim());
            let visible = 0;
            rows.forEach((r) => {
                const match = !q || norm(r.dataset.nombre).includes(q);
                r.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            catClear.hidden = !q;
            if (catCount) {
                catCount.textContent = q ? `${visible} de ${total} registros` : `${total} registros`;
            }
        };
        catSearch.addEventListener('input', apply);
        catClear.addEventListener('click', () => { catSearch.value = ''; apply(); catSearch.focus(); });
    }
})();
</script>
<?php endif; ?>

<?php if ($tab === 'usuarios'): ?>
    <?php foreach($usuarios as $u): ?>
        <div class="modal fade" id="editUser<?= $u['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
                <form method="POST" class="modal-content border-0 shadow-sm" style="border-radius: 16px;" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="update_usuario">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <?php if ($u['id'] == $_SESSION['user_id']): ?>
                        <input type="hidden" name="activo" value="1">
                    <?php endif; ?>

                    <div class="modal-header border-bottom-0 pt-4 pb-0 px-4">
                        <h6 class="modal-title fw-bold text-dark" style="font-size: 1.05rem; display: flex; align-items: center; gap: 8px;"><i class="bi bi-person-gear text-primary" style="font-size: 1.2rem;"></i>Editar <?= e($u['username']) ?></h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="font-size: 0.75rem;"></button>
                    </div>
                    <div class="modal-body px-4 py-3 d-grid gap-3">
                        <div class="dense-form">
                            <label class="form-label" style="font-size: 0.75rem;"><i class="bi bi-person text-secondary me-0.5"></i>Nombre de Usuario</label>
                            <input type="text" name="username" class="form-control" value="<?= e($u['username']) ?>" required autocomplete="off" style="font-size: 0.85rem; padding: 0.45rem 0.75rem; border-radius: 8px;">
                        </div>
                        <div class="dense-form">
                            <label class="form-label" style="font-size: 0.75rem;"><i class="bi bi-lock text-secondary me-0.5"></i>Nueva Contraseña</label>
                            <input type="password" name="password" class="form-control" placeholder="Dejar en blanco para mantener" minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password" style="font-size: 0.85rem; padding: 0.45rem 0.75rem; border-radius: 8px;">
                        </div>
                        <div class="dense-form">
                            <label class="form-label" style="font-size: 0.75rem;"><i class="bi bi-shield-check text-secondary me-0.5"></i>Rol del Sistema</label>
                            <select name="rol" class="form-select user-role-select" data-target="tecnico_<?= $u['id'] ?>" style="font-size: 0.85rem; padding: 0.45rem 0.75rem; border-radius: 8px; height: 38px;">
                                <option value="admin" <?= $u['rol'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                <option value="tecnico" <?= $u['rol'] === 'tecnico' ? 'selected' : '' ?>>Técnico</option>
                            </select>
                        </div>
                        <div class="dense-form">
                            <label class="form-label" style="font-size: 0.75rem;"><i class="bi bi-person-badge text-secondary me-0.5"></i>Vincular con Técnico</label>
                            <select name="tecnico_id" id="tecnico_<?= $u['id'] ?>" class="form-select" <?= $u['rol'] === 'admin' ? 'disabled' : '' ?> style="font-size: 0.85rem; padding: 0.45rem 0.75rem; border-radius: 8px; height: 38px;">
                                <option value="">Ninguno / No aplica</option>
                                <?php foreach ($tecnicos as $t): ?>
                                    <option value="<?= e($t['id']) ?>" <?= (string)($u['tecnico_id'] ?? '') === (string)$t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-switch-premium p-2.5 rounded border mt-1">
                            <input type="checkbox" class="form-check-input ms-0 me-2" name="activo" id="activo_<?= $u['id'] ?>" <?= $u['activo'] ? 'checked' : '' ?> <?= $u['id'] == $_SESSION['user_id'] ? 'disabled' : '' ?>>
                            <label class="form-check-label fw-semibold text-dark m-0 cursor-pointer" for="activo_<?= $u['id'] ?>" style="font-size: 0.82rem;">Usuario habilitado para el sistema</label>
                        </div>
                        <?php if ($u['id'] == $_SESSION['user_id']): ?>
                        <div class="alert alert-info border-0 bg-info bg-opacity-10 text-info-emphasis d-flex align-items-center gap-2 mb-0" style="font-size: 0.75rem; padding: 0.5rem; border-radius: 8px;">
                            <i class="bi bi-info-circle-fill"></i> No podés desactivar tu propia cuenta.
                        </div>
                        <?php else: ?>
                        <div class="danger-zone">
                            <h6><i class="bi bi-shield-exclamation me-1"></i>Acciones avanzadas</h6>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-outline-warning btn-sm flex-grow-1"
                                        data-user-action="reset-password"
                                        data-user-id="<?= e($u['id']) ?>"
                                        data-username="<?= e($u['username']) ?>">
                                    <i class="bi bi-key-fill me-1"></i>Resetear contraseña
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm flex-grow-1"
                                        data-user-action="delete"
                                        data-user-id="<?= e($u['id']) ?>"
                                        data-username="<?= e($u['username']) ?>">
                                    <i class="bi bi-trash-fill me-1"></i>Eliminar cuenta
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer border-top-0 px-4 pb-4 pt-0 d-flex gap-2">
                        <button type="button" class="btn btn-light flex-grow-1 fw-bold text-secondary border" style="font-size: 0.8rem; padding: 0.55rem; border-radius: 8px;" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary flex-grow-1 fw-bold shadow-none" style="font-size: 0.8rem; padding: 0.55rem; border-radius: 8px;">Actualizar Datos</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Delete user confirmation modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
            <form method="POST" class="modal-content border-0 shadow-sm" style="border-radius: 12px;">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="action" value="delete_usuario">
                <input type="hidden" name="user_id" id="deleteUserId" value="">

                <div class="modal-header border-bottom-0 pt-4 pb-0 px-4">
                    <h6 class="modal-title fw-bold" style="font-size: 1rem;">
                        <i class="bi bi-trash3-fill text-danger me-2"></i>Eliminar usuario
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body px-4 py-3" style="font-size: 0.85rem; color: #334155;">
                    <p class="mb-2">
                        ¿Eliminar al usuario <strong id="deleteUsername" class="text-danger">—</strong>?
                    </p>
                    <ul class="ps-3 mb-2" style="font-size: 0.8rem; color: #64748b;">
                        <li>El usuario queda marcado como eliminado y no podrá iniciar sesión.</li>
                        <li>Si está en línea, su sesión activa se cerrará.</li>
                        <li>El registro se mantiene en BD para preservar historial y auditoría.</li>
                    </ul>
                    <div class="alert alert-warning border-0 mb-0" style="font-size: 0.75rem; padding: 0.5rem 0.65rem; background: #fef3c7;">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Esta acción no se puede deshacer desde la interfaz.
                    </div>
                </div>

                <div class="modal-footer border-top-0 px-4 pb-4 pt-2 d-flex gap-2">
                    <button type="button" class="btn btn-light flex-grow-1 fw-bold text-secondary border"
                            style="font-size: 0.8rem; padding: 0.5rem;" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger flex-grow-1 fw-bold shadow-none"
                            style="font-size: 0.8rem; padding: 0.5rem;">
                        <i class="bi bi-trash me-1"></i>Eliminar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset password confirmation modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
            <form method="POST" class="modal-content border-0 shadow-sm" style="border-radius: 12px;">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetModalUserId" value="">

                <div class="modal-header border-bottom-0 pt-4 pb-0 px-4">
                    <h6 class="modal-title fw-bold" style="font-size: 1rem;">
                        <i class="bi bi-key-fill text-warning me-2"></i>Resetear contraseña
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body px-4 py-3" style="font-size: 0.85rem; color: #334155;">
                    <p class="mb-2">
                        Se va a generar un <strong>PIN temporal de 4 dígitos</strong> para
                        <strong id="resetModalUsername" class="text-primary">—</strong>.
                    </p>
                    <ul class="ps-3 mb-2" style="font-size: 0.8rem; color: #64748b;">
                        <li>Su sesión activa se cerrará inmediatamente.</li>
                        <li>Al volver a iniciar sesión deberá definir una nueva contraseña antes de continuar.</li>
                        <li>El PIN se muestra <strong>una sola vez</strong>: copialo y entregaselo.</li>
                    </ul>
                    <div class="alert alert-warning border-0 mb-0" style="font-size: 0.75rem; padding: 0.5rem 0.65rem; background: #fef3c7;">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Esta acción no se puede deshacer. La contraseña anterior se perderá.
                    </div>
                </div>

                <div class="modal-footer border-top-0 px-4 pb-4 pt-2 d-flex gap-2">
                    <button type="button" class="btn btn-light flex-grow-1 fw-bold text-secondary border"
                            style="font-size: 0.8rem; padding: 0.5rem;" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning flex-grow-1 fw-bold shadow-none"
                            style="font-size: 0.8rem; padding: 0.5rem;">
                        <i class="bi bi-key me-1"></i>Generar PIN
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const CSRF_TOKEN_USER = <?= json_encode(generateCsrfToken()) ?>;

    function postUserAction(action, userId) {
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = '';
        const fields = { csrf_token: CSRF_TOKEN_USER, action: action, user_id: userId };
        Object.entries(fields).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            f.appendChild(input);
        });
        document.body.appendChild(f);
        f.submit();
    }

    function openResetPasswordModal(userId, username) {
        const resetModalEl = document.getElementById('resetPasswordModal');
        document.getElementById('resetModalUsername').textContent = username;
        document.getElementById('resetModalUserId').value = userId;
        chainOpenModal(resetModalEl);
    }

    function openDeleteUserModal(userId, username) {
        const deleteModalEl = document.getElementById('deleteUserModal');
        document.getElementById('deleteUsername').textContent = username;
        document.getElementById('deleteUserId').value = userId;
        chainOpenModal(deleteModalEl);
    }

    // If another modal is currently open (e.g. the edit modal), close it
    // first and chain the target modal after its close animation completes.
    function chainOpenModal(targetEl) {
        const openModal = document.querySelector('.modal.show');
        if (openModal && openModal !== targetEl) {
            openModal.addEventListener('hidden.bs.modal', () => {
                bootstrap.Modal.getOrCreateInstance(targetEl).show();
            }, { once: true });
            bootstrap.Modal.getOrCreateInstance(openModal).hide();
        } else {
            bootstrap.Modal.getOrCreateInstance(targetEl).show();
        }
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-user-action]');
        if (!btn) return;
        const action = btn.dataset.userAction;
        const userId = btn.dataset.userId;
        const username = btn.dataset.username || '';

        if (action === 'reset-password') {
            openResetPasswordModal(userId, username);
        } else if (action === 'delete') {
            openDeleteUserModal(userId, username);
        }
    });

    // --- User table filters (search + role + online) ---
    (function () {
        const table = document.getElementById('userTable');
        if (!table) return;

        const searchInput = document.getElementById('userSearch');
        const roleSelect = document.getElementById('userRoleFilter');
        const onlineCheck = document.getElementById('userOnlineFilter');
        const clearBtn = document.getElementById('userFilterClear');
        const countLabel = document.getElementById('userCount');
        const rows = Array.from(table.tBodies[0].rows);
        const total = rows.length;

        // Normalize accents for case/diacritic-insensitive search
        const norm = (s) => (s || '').toString()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '');

        // Inject an "empty state" row that shows when all rows are filtered out
        const emptyRow = document.createElement('tr');
        emptyRow.className = 'table-empty-row';
        emptyRow.hidden = true;
        emptyRow.innerHTML = `<td colspan="7"><i class="bi bi-search me-2"></i>Sin resultados para los filtros aplicados.</td>`;
        table.tBodies[0].appendChild(emptyRow);

        function plural(n) { return n === 1 ? 'usuario' : 'usuarios'; }

        function applyFilters() {
            const q = norm(searchInput.value.trim());
            const role = roleSelect.value;
            const onlyOnline = onlineCheck.checked;
            const hasFilter = q !== '' || role !== '' || onlyOnline;

            const onlineLabel = document.getElementById('userOnlineFilterLabel');
            if (onlineLabel) {
                if (onlyOnline) {
                    onlineLabel.classList.add('is-active');
                } else {
                    onlineLabel.classList.remove('is-active');
                }
            }

            let visible = 0;
            rows.forEach((row) => {
                const matchQ = !q || norm(row.dataset.username).includes(q);
                const matchRole = !role || row.dataset.rol === role;
                const matchOnline = !onlyOnline || row.dataset.online === '1';
                const show = matchQ && matchRole && matchOnline;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            emptyRow.hidden = visible !== 0;
            clearBtn.hidden = !hasFilter;
            countLabel.textContent = hasFilter
                ? `${visible} de ${total} ${plural(total)}`
                : `${total} ${plural(total)}`;
        }

        searchInput.addEventListener('input', applyFilters);
        roleSelect.addEventListener('change', applyFilters);
        onlineCheck.addEventListener('change', applyFilters);
        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            roleSelect.value = '';
            onlineCheck.checked = false;
            applyFilters();
            searchInput.focus();
        });
    })();

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

<?php require_once __DIR__ . '/../public/includes/footer.php'; ?>
