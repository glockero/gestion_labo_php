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
        --edit-bg: #f3f4f6;
        --edit-surface: #ffffff;
        --edit-surface-soft: #f8fafc;
        --edit-border: #d6dbe3;
        --edit-border-strong: #b8c2cf;
        --edit-text: #1f2937;
        --edit-muted: #6b7280;
        --edit-heading: #111827;
        --edit-accent: #334155;
        --edit-warning: #92400e;
        --edit-shadow: 0 18px 40px -30px rgba(15, 23, 42, 0.45);
    }

    body {
        background:
            radial-gradient(circle at top left, rgba(255, 255, 255, 0.95), transparent 34%),
            linear-gradient(180deg, #f8fafc 0%, var(--edit-bg) 100%) !important;
    }

    .form-shell {
        font-size: 13.5px;
        color: var(--edit-text);
        max-width: 980px;
        margin: 0 auto;
    }

    .edit-header {
        margin-bottom: 1.5rem;
        padding: 1.5rem;
        border: 1px solid rgba(214, 219, 227, 0.95);
        border-radius: 8px;
        background: linear-gradient(135deg, rgba(255,255,255,0.98), rgba(248,250,252,0.94));
        box-shadow: var(--edit-shadow);
    }

    .edit-kicker {
        margin-bottom: 0.45rem;
        color: var(--edit-muted);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
    }

    .page-title {
        font-size: 28px;
        font-weight: 700;
        margin: 0;
        color: var(--edit-heading);
    }

    .edit-subtitle {
        margin: 0.45rem 0 0;
        color: var(--edit-muted);
        max-width: 720px;
        line-height: 1.55;
    }

    .edit-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
        margin-top: 1rem;
    }

    .edit-meta-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        min-height: 34px;
        padding: 0.45rem 0.8rem;
        border: 1px solid var(--edit-border);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.72);
        color: var(--edit-text);
        font-size: 12px;
        font-weight: 600;
    }

    .edit-meta-chip i {
        color: var(--edit-accent);
    }

    .btn-compact {
        height: 38px;
        padding: 0 0.95rem;
        font-size: 13px;
        display: inline-flex;
        align-items: center;
        border-radius: 8px;
        font-weight: 600;
        border-width: 1px;
    }

    .btn-toolbar-neutral {
        background: transparent;
        border-color: var(--edit-border-strong);
        color: var(--edit-text);
    }

    .btn-toolbar-neutral:hover {
        background: var(--edit-surface-soft);
        border-color: var(--edit-accent);
        color: var(--edit-heading);
    }

    .btn-toolbar-primary {
        background: var(--edit-heading);
        border-color: var(--edit-heading);
        color: #fff;
    }

    .btn-toolbar-primary:hover {
        background: #1f2937;
        border-color: #1f2937;
        color: #fff;
    }

    .dense-card {
        background: rgba(255, 255, 255, 0.97);
        border-radius: 8px;
        border: 1px solid var(--edit-border);
        box-shadow: var(--edit-shadow);
        overflow: hidden;
    }

    .dense-card .card-header {
        background: linear-gradient(180deg, rgba(249, 250, 251, 0.96), rgba(243, 244, 246, 0.9));
        border-bottom: 1px solid var(--edit-border);
        padding: 0.9rem 1.4rem;
    }

    .dense-card .card-header h6 {
        margin: 0;
        color: var(--edit-heading);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .dense-card .card-header h6 i {
        color: var(--edit-accent);
        margin-right: 0.35rem;
    }

    .dense-card .card-body {
        padding: 1.35rem 1.5rem 1.5rem;
    }

    .form-section {
        padding: 1rem;
        border: 1px solid var(--edit-border);
        border-radius: 8px;
        background: linear-gradient(180deg, #ffffff 0%, #fbfcfd 100%);
    }

    .section-caption {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        margin-bottom: 1rem;
        color: var(--edit-heading);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .section-caption i {
        color: var(--edit-accent);
    }

    .form-label-compact {
        font-size: 11px;
        font-weight: 700;
        color: var(--edit-muted);
        margin-bottom: 0.35rem;
        text-transform: uppercase;
        letter-spacing: 0.1em;
    }

    .form-control-compact, .form-select-compact {
        min-height: 42px;
        font-size: 13.5px;
        border-radius: 8px;
        border: 1px solid var(--edit-border-strong);
        padding: 0.55rem 0.75rem;
        background-color: #ffffff;
        color: var(--edit-text);
        transition: all 0.15s ease;
    }

    .form-control-compact:focus, .form-select-compact:focus {
        border-color: var(--edit-accent);
        box-shadow: 0 0 0 3px rgba(51, 65, 85, 0.12);
    }

    .form-text-compact {
        font-size: 12px;
        color: var(--edit-muted);
        margin-top: 0.25rem;
    }

    .top-form-row {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .date-field-block {
        width: 280px;
        flex: 0 0 280px;
    }

    .date-field-block small {
        display: block;
        margin-top: 6px;
        font-size: 12px;
        color: var(--edit-muted);
    }

    .switch-block {
        display: flex;
        align-items: center;
        min-height: 42px;
        margin-top: 23px;
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
        padding: 0 0.9rem;
        border: 1px solid #e7c98a;
        border-radius: 8px;
        background: #fffbeb;
        color: var(--edit-warning);
        font-weight: 700;
        height: 42px;
        transition: all 0.2s ease;
    }

    .urgent-pill.is-active {
        background: #991b1b;
        border-color: #991b1b;
        color: #fff;
        box-shadow: 0 10px 24px -18px rgba(153, 27, 27, 0.8);
    }

    .alert-compact {
        padding: 0.85rem 1rem;
        font-size: 13px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 0.7rem;
        margin-top: 0.25rem;
        line-height: 1.45;
    }

    .form-actions {
        border-top: 1px solid var(--edit-border);
        padding-top: 1rem;
    }

    @media (max-width: 767.98px) {
        .edit-header {
            padding: 1.2rem;
        }

        .page-title {
            font-size: 24px;
        }

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
        min-height: 42px !important;
        border-radius: 8px !important;
        border-color: var(--edit-border-strong) !important;
        background-color: #ffffff !important;
        font-size: 13.5px !important;
        padding: 0.55rem 0.75rem !important;
    }
    .ts-control.focus {
        border-color: var(--edit-accent) !important;
        box-shadow: 0 0 0 3px rgba(51, 65, 85, 0.12) !important;
    }
    .ts-control > input {
        font-size: 13.5px !important;
    }
    .ts-dropdown {
        border-color: var(--edit-border) !important;
        border-radius: 8px !important;
        box-shadow: 0 16px 36px -28px rgba(15, 23, 42, 0.65) !important;
    }
</style>

<div class="form-shell">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center edit-header gap-3">
        <div>
            <h2 class="page-title">Editar Reparación</h2>
            <div class="edit-meta">
                <span class="edit-meta-chip"><i class="bi bi-calendar3"></i><?= formatDatetimeArg($rep['fecha']) ?></span>
                <span class="edit-meta-chip"><i class="bi bi-door-open"></i><?= e($rep['sala']) ?></span>
                <span class="edit-meta-chip"><i class="bi bi-pc-display-horizontal"></i><?= e($rep['equipo']) ?></span>
            </div>
        </div>
        <a href="reparacion_detalle.php?id=<?= $rep['id'] ?>" class="btn btn-compact btn-toolbar-neutral"><i class="bi bi-x-lg me-1"></i>Cancelar</a>
    </div>

    <div class="dense-card">
        <div class="card-header">
            <h6><i class="bi bi-pencil-square"></i>Datos editables</h6>
        </div>
        <div class="card-body">
            <form method="POST" action="reparacion_editar.php?id=<?= $rep['id'] ?>" class="d-flex flex-column gap-3">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                
                <div class="form-section">
                    <div class="section-caption"><i class="bi bi-calendar2-check"></i>Fecha y prioridad</div>
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
                </div>

                <div class="form-section">
                    <div class="section-caption"><i class="bi bi-grid-3x3-gap"></i>Clasificación del equipo</div>
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
                </div>

                <div class="form-section">
                    <div class="section-caption"><i class="bi bi-upc-scan"></i>Identificación y asignación</div>
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

                </div>

                <div class="alert alert-warning alert-compact mb-0 bg-warning bg-opacity-10 text-warning-emphasis border-warning border-opacity-25">
                    <i class="bi bi-info-circle-fill"></i>
                    <div>Nota: Modifique las observaciones y el estado desde la vista de <a href="reparacion_detalle.php?id=<?= $rep['id'] ?>" class="fw-bold text-decoration-none">Detalle</a> para mantener el historial.</div>
                </div>

                <div class="d-flex justify-content-end gap-2 form-actions">
                    <button type="submit" class="btn btn-compact btn-toolbar-primary shadow-none"><i class="bi bi-save me-1"></i>Guardar cambios</button>
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
