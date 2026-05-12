<?php
// public/reparacion_editar.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/CatalogoModel.php';
require_once __DIR__ . '/../app/ReparacionModel.php';
require_once __DIR__ . '/../app/historial.php';

requireRole('admin');

$user_role = $_SESSION['user_role'] ?? 'tecnico';

$id = $_GET['id'] ?? null;
if (!$id) {
    setFlashMessage('danger', 'ID de reparación no especificado.');
    redirect('/index.php');
}

$rep = ReparacionModel::getById($id);
if (!$rep) {
    setFlashMessage('danger', 'Reparación no encontrada.');
    redirect('/index.php');
}

$salas = CatalogoModel::getAll('salas');
$equipos = CatalogoModel::getAll('equipos');
$familias = CatalogoModel::getAll('familias');
$tecnicos = CatalogoModel::getAll('tecnicos');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    
    $pdo = getDbConnection();
    
    try {
        $fecha = $_POST['fecha'] ?? '';
        // Ensure format
        $fecha = date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $fecha)));
        
        $sala_id = CatalogoModel::asegurarExiste('salas', $_POST['sala'] ?? '');
        $equipo_id = CatalogoModel::asegurarExiste('equipos', $_POST['equipo'] ?? '');
        $familia_id = CatalogoModel::asegurarExiste('familias', $_POST['familia'] ?? '');
        
        $sala = $_POST['sala'] ?? '';
        $equipo = $_POST['equipo'] ?? '';
        $familia = $_POST['familia'] ?? '';
        
        $uid = trim($_POST['uid'] ?? '');
        $npu = trim($_POST['npu'] ?? '');
        $parte = trim($_POST['parte'] ?? '');
        $urgente = isset($_POST['urgente']) && $_POST['urgente'] === 'SI' ? 'SI' : 'NO';
        $tecnico_id = !empty($_POST['tecnico_id']) ? $_POST['tecnico_id'] : null;

        $stmt = $pdo->prepare("
            UPDATE reparaciones SET
                fecha = ?, sala = ?, uid = ?, npu = ?, parte = ?, 
                familia = ?, equipo = ?, urgente = ?, tecnico_id = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $fecha, $sala, $uid, $npu, $parte, $familia, $equipo, $urgente, $tecnico_id, $id
        ]);
        
        registrarHistorial('EDITAR', "El administrador modificó los datos de la reparación.", $id);
        
        setFlashMessage('success', 'Reparación actualizada correctamente.');
        redirect('/reparacion_detalle.php?id=' . $id);
        
    } catch (Exception $e) {
        setFlashMessage('danger', 'Error al guardar: ' . e($e->getMessage()));
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    :root {
        --border-color: #e5e7eb;
        --bg-light: #f8fafc;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --primary-blue: #2563eb;
    }

    .form-shell {
        font-size: 13.5px;
        color: var(--text-main);
    }

    .page-title {
        font-size: 26px;
        font-weight: 700;
        margin: 0;
        letter-spacing: -0.01em;
        color: var(--text-main);
    }

    .btn-compact {
        height: 36px;
        padding: 0 1rem;
        font-size: 13px;
        display: inline-flex;
        align-items: center;
        border-radius: 6px;
        font-weight: 600;
    }

    .dense-card {
        background: #fff;
        border-radius: 8px;
        border: 1px solid var(--border-color);
        box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        max-width: 900px;
        margin: 0 auto;
        overflow: hidden;
    }

    .dense-card .card-body {
        padding: 1.25rem 1.5rem;
    }

    .form-label-compact {
        font-size: 13px;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 0.2rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .form-control-compact, .form-select-compact {
        height: 40px;
        font-size: 14px;
        border-radius: 6px;
        border: 1px solid #d1d5db;
        padding: 0.4rem 0.75rem;
        background-color: var(--bg-light);
        transition: all 0.15s;
    }

    .form-control-compact:focus, .form-select-compact:focus {
        background-color: #fff;
        border-color: var(--primary-blue);
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
    }

    .form-text-compact {
        font-size: 12px;
        color: #9ca3af;
        margin-top: 0.15rem;
    }

    .top-form-row {
        display: flex;
        align-items: flex-start;
        gap: 24px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }

    .date-field-block {
        width: 260px;
        flex: 0 0 260px;
    }

    .date-field-block small {
        display: block;
        margin-top: 4px;
        font-size: 12px;
        color: #9ca3af;
    }

    .switch-block {
        display: flex;
        align-items: center;
        height: 40px;
        margin-top: 22px;
    }

    .switch-inline {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        line-height: 1;
        white-space: nowrap;
        cursor: pointer;
        margin: 0;
    }

    .urgent-pill {
        padding: 0 14px;
        border: 1px solid #fecaca;
        border-radius: 6px;
        background: #fef2f2;
        color: #b91c1c;
        font-weight: 700;
        height: 40px;
        transition: all 0.2s ease;
    }

    .urgent-pill.is-active {
        background: #dc2626;
        border-color: #dc2626;
        color: #ffffff;
        box-shadow: 0 4px 10px rgba(220, 38, 38, 0.18);
    }

    .alert-compact {
        padding: 0.75rem 1rem;
        font-size: 13px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 1rem;
    }

    @media (max-width: 767.98px) {
        .date-field-block {
            width: 100%;
            flex: 0 0 100%;
        }
        .switch-block {
            margin-top: 0;
        }
    }

    /* Override TomSelect */
    .ts-control {
        height: 40px !important;
        border-radius: 6px !important;
        border-color: #d1d5db !important;
        background-color: var(--bg-light) !important;
        font-size: 14px !important;
        padding-top: 0.5rem !important;
        padding-bottom: 0.5rem !important;
    }
    .ts-control.focus {
        background-color: #fff !important;
        border-color: var(--primary-blue) !important;
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1) !important;
    }
    .ts-control > input {
        font-size: 14px !important;
    }
</style>

<div class="form-shell">
    <div class="d-flex justify-content-between align-items-center mb-3" style="max-width: 900px; margin: 0 auto;">
        <h2 class="page-title"><i class="bi bi-pencil-square text-primary me-2" style="font-size: 22px;"></i>Editar Reparación #<?= $rep['id'] ?></h2>
        <a href="reparacion_detalle.php?id=<?= $rep['id'] ?>" class="btn btn-light btn-compact border text-secondary"><i class="bi bi-x-lg me-1"></i>Cancelar</a>
    </div>

    <div class="dense-card">
        <div class="card-body">
            <form method="POST" action="reparacion_editar.php?id=<?= $rep['id'] ?>" class="d-flex flex-column gap-3">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                
                <div class="top-form-row">
                    <div class="date-field-block">
                        <label class="form-label-compact">Fecha y Hora</label>
                        <input type="text" name="fecha" id="fecha_input" class="form-control form-control-compact bg-light" 
                               value="<?= date('Y-m-d H:i', strtotime($rep['fecha'])) ?>" readonly>
                        <?php if ($user_role === 'admin'): ?>
                        <small>Formato: YYYY-MM-DD HH:MM</small>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($user_role === 'admin'): ?>
                    <div class="switch-block">
                        <label class="switch-inline form-switch">
                            <input class="form-check-input m-0" type="checkbox" role="switch" id="unlock_fecha">
                            <span class="text-muted" style="font-size: 13px; padding-top: 2px;">Editar fecha manualmente</span>
                        </label>
                    </div>
                    <?php endif; ?>
                    
                    <div class="switch-block">
                        <label class="switch-inline form-switch urgent-pill <?= $rep['urgente'] === 'SI' ? 'is-active' : '' ?>">
                            <input class="form-check-input m-0" type="checkbox" role="switch" id="urgente" name="urgente" value="SI" <?= $rep['urgente'] === 'SI' ? 'checked' : '' ?>>
                            <span style="padding-top: 2px;">URGENTE</span>
                        </label>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label-compact">Sala <span class="text-danger">*</span></label>
                        <select name="sala" class="form-select tom-select" data-allow-new required>
                            <option value="">Seleccione o escriba...</option>
                            <?php foreach ($salas as $s): ?>
                                <option value="<?= e($s['nombre']) ?>" <?= $rep['sala'] === $s['nombre'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
                            <?php endforeach; ?>
                            <?php if(!in_array($rep['sala'], array_column($salas, 'nombre'))): ?>
                                 <option value="<?= e($rep['sala']) ?>" selected><?= e($rep['sala']) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-compact">Familia</label>
                        <select name="familia" class="form-select tom-select" data-allow-new>
                            <option value="">Seleccione o escriba...</option>
                            <?php foreach ($familias as $f): ?>
                                <option value="<?= e($f['nombre']) ?>" <?= $rep['familia'] === $f['nombre'] ? 'selected' : '' ?>><?= e($f['nombre']) ?></option>
                            <?php endforeach; ?>
                            <?php if($rep['familia'] && !in_array($rep['familia'], array_column($familias, 'nombre'))): ?>
                                 <option value="<?= e($rep['familia']) ?>" selected><?= e($rep['familia']) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-compact">Equipo <span class="text-danger">*</span></label>
                        <select name="equipo" class="form-select tom-select" data-allow-new required>
                            <option value="">Seleccione o escriba...</option>
                            <?php foreach ($equipos as $eq): ?>
                                <option value="<?= e($eq['nombre']) ?>" <?= $rep['equipo'] === $eq['nombre'] ? 'selected' : '' ?>><?= e($eq['nombre']) ?></option>
                            <?php endforeach; ?>
                            <?php if(!in_array($rep['equipo'], array_column($equipos, 'nombre'))): ?>
                                 <option value="<?= e($rep['equipo']) ?>" selected><?= e($rep['equipo']) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label-compact">NPU / Patrimonio</label>
                        <input type="text" name="npu" class="form-control form-control-compact" value="<?= e($rep['npu']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-compact">UID (MAC/IP/Etc)</label>
                        <input type="text" name="uid" class="form-control form-control-compact" value="<?= e($rep['uid']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-compact">Técnico Asignado</label>
                        <select name="tecnico_id" class="form-select tom-select">
                            <option value="">-- Sin Asignar --</option>
                            <?php foreach ($tecnicos as $t): ?>
                                <option value="<?= e($t['id']) ?>" <?= $rep['tecnico_id'] == $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="alert alert-warning alert-compact mb-0 bg-warning bg-opacity-10 text-warning-emphasis border-warning border-opacity-25">
                    <i class="bi bi-info-circle-fill"></i>
                    <div>Nota: Modifique las observaciones y el estado desde la vista de <a href="reparacion_detalle.php?id=<?= $rep['id'] ?>" class="fw-bold text-decoration-none">Detalle</a> para mantener el historial.</div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-2 pt-3 border-top">
                    <button type="submit" class="btn btn-primary btn-compact shadow-none"><i class="bi bi-save me-1"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const unlockFecha = document.getElementById('unlock_fecha');
    const fechaInput = document.getElementById('fecha_input');
    if (unlockFecha && fechaInput) {
        unlockFecha.addEventListener('change', function() {
            if (this.checked) {
                fechaInput.removeAttribute('readonly');
                fechaInput.classList.remove('bg-light');
            } else {
                fechaInput.setAttribute('readonly', 'readonly');
                fechaInput.classList.add('bg-light');
            }
        });
    }

    const urgentCheckbox = document.getElementById('urgente');
    if (urgentCheckbox) {
        const urgentPill = urgentCheckbox.closest('.urgent-pill');
        
        function updateUrgentState() {
            if (urgentCheckbox.checked) {
                urgentPill.classList.add('is-active');
            } else {
                urgentPill.classList.remove('is-active');
            }
        }
        
        urgentCheckbox.addEventListener('change', updateUrgentState);
        updateUrgentState();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
