<?php
// public/reparacion_detalle.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/estado.php';
require_once __DIR__ . '/../app/ReparacionModel.php';
require_once __DIR__ . '/../app/CatalogoModel.php';

requireLogin();
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

require_once __DIR__ . '/includes/header.php';

$historial = ReparacionModel::getHistorialReparacion($id);
$tecnicos = CatalogoModel::getAll('tecnicos');
$estados_catalogo = CatalogoModel::getAll('estados');
$is_admin = $user_role === 'admin';
$is_assigned_tecnico = $user_role === 'tecnico' && !empty($rep['tecnico_id']) && (int)$rep['tecnico_id'] === (int)($_SESSION['tecnico_id'] ?? 0);
$can_manage_rep = $is_admin || $is_assigned_tecnico;
$can_reassign_tecnico = $is_admin;
$can_change_prioridad = $is_admin;
$can_devolver_rep = $can_manage_rep;
$allowed_estado_options = array_values(array_filter($estados_catalogo, function ($estadoItem) use ($rep, $user_role) {
    return canTransitionEstado($rep['estado'], $estadoItem['nombre'], $user_role) || normalizeEstadoLabel($rep['estado']) === normalizeEstadoLabel($estadoItem['nombre']);
}));
$can_change_estado = $can_manage_rep && count($allowed_estado_options) > 1;

// Helper
function getBadgeClass($estado) {
    $estado = normalizeEstadoLabel($estado);
    if ($estado === 'PEND. DE REVISION') return 'status-secondary';
    if (isEstadoEnReparacion($estado)) return 'status-primary';
    if (isEstadoReparado($estado)) return 'status-success';
    if (isEstadoSinReparacion($estado)) return 'status-danger';
    if (isEstadoPendienteIntermedio($estado)) return 'status-warning';
    if (isEstadoEntregado($estado)) return 'status-info';
    return 'status-dark';
}
?>

<style>
    :root {
        --border-color: #e5e7eb;
        --bg-light: #f8fafc;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --primary-blue: #2563eb;
    }

    .detail-shell {
        font-size: 13.5px;
        color: var(--text-main);
    }

    .detail-header {
        margin-bottom: 1.25rem;
    }

    .detail-title {
        font-size: 26px;
        font-weight: 700;
        margin: 0;
        letter-spacing: -0.01em;
    }

    .dense-card {
        background: #fff;
        border-radius: 8px;
        border: 1px solid var(--border-color);
        box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        margin-bottom: 1rem;
        overflow: hidden;
    }

    .dense-card .card-header {
        background: #fff;
        padding: 0.6rem 1rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .dense-card .card-header h6 {
        font-size: 14px;
        font-weight: 700;
        margin: 0;
        color: var(--text-main);
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .dense-card .card-body {
        padding: 1rem;
    }

    /* Grid for data */
    .data-grid {
        display: grid;
        grid-template-columns: 140px 1fr;
        gap: 0.4rem 1rem;
        align-items: baseline;
    }
    
    @media (max-width: 575.98px) {
        .data-grid {
            grid-template-columns: 1fr;
            gap: 0.1rem;
        }
        .data-label {
            margin-top: 0.5rem;
        }
    }

    .data-label {
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .data-value {
        font-size: 14px;
        font-weight: 500;
    }

    .btn-compact {
        height: 34px;
        padding: 0 0.8rem;
        font-size: 13px;
        display: inline-flex;
        align-items: center;
        border-radius: 6px;
        font-weight: 600;
    }

    .form-control-compact, .form-select-compact {
        height: 38px;
        font-size: 13px;
        border-radius: 6px;
        border: 1px solid #d1d5db;
        padding: 0.4rem 0.6rem;
    }

    .form-control-compact:focus, .form-select-compact:focus {
        border-color: var(--primary-blue);
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
    }

    .list-group-compact .list-group-item {
        padding: 0.6rem 1rem;
        font-size: 13px;
        border-color: var(--border-color);
    }

    /* Status Pills */
    .status-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.4rem 0.8rem;
        font-size: 13px;
        font-weight: 700;
        border-radius: 6px;
        letter-spacing: 0.02em;
        text-transform: uppercase;
        width: 100%;
        text-align: center;
    }
    
    .status-success { background-color: #ecfdf5; color: #166534; border: 1px solid #a7f3d0; }
    .status-warning { background-color: #fefce8; color: #854d0e; border: 1px solid #fde047; }
    .status-danger { background-color: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .status-info { background-color: #f0f9ff; color: #075985; border: 1px solid #bae6fd; }
    .status-secondary { background-color: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }
    .status-primary { background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .status-dark { background-color: #f1f5f9; color: #0f172a; border: 1px solid #cbd5e1; }

    .tech-avatar {
        width: 24px;
        height: 24px;
        font-size: 11px;
        background-color: #cbd5e1;
        color: #fff;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
    }
</style>

<div class="detail-shell">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center detail-header gap-3">
        <h2 class="detail-title"><i class="bi bi-file-earmark-text text-muted me-2"></i>Reparación #<?= $rep['id'] ?></h2>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-light btn-compact border text-secondary"><i class="bi bi-arrow-left me-1"></i>Volver</a>
            <?php if ($user_role === 'admin'): ?>
            <a href="reparacion_editar.php?id=<?= $rep['id'] ?>" class="btn btn-primary btn-compact"><i class="bi bi-pencil me-1"></i>Editar</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <!-- Columna Izquierda -->
        <div class="col-lg-8">
            <!-- Ficha Técnica -->
            <div class="dense-card">
                <div class="card-header">
                    <h6 class="text-primary"><i class="bi bi-info-circle me-1"></i>Datos del Equipo</h6>
                </div>
                <div class="card-body">
                    <div class="data-grid mb-3">
                        <div class="data-label">Fecha Ingreso</div>
                        <div class="data-value"><?= formatDatetimeArg($rep['fecha']) ?></div>
                        
                        <div class="data-label">Sala</div>
                        <div class="data-value">
                            <span class="badge bg-light text-dark border px-2 py-1 text-uppercase" style="font-size: 11px;"><?= e($rep['sala']) ?></span>
                        </div>
                        
                        <div class="data-label">Equipo / Familia</div>
                        <div class="data-value text-primary fw-bold"><?= e($rep['equipo']) ?> <span class="text-muted fw-normal fst-italic ms-1" style="font-size: 12px;">(<?= e($rep['familia']) ?>)</span></div>
                        
                        <div class="data-label">NPU / Patrimonio</div>
                        <div class="data-value fw-bold text-dark"><?= e($rep['npu']) ?: '<span class="text-muted fw-normal fst-italic">N/A</span>' ?></div>
                        
                        <div class="data-label">UID</div>
                        <div class="data-value"><?= e($rep['uid']) ?: '<span class="text-muted fst-italic">N/A</span>' ?></div>
                        
                        <div class="data-label">Parte</div>
                        <div class="data-value"><?= e($rep['parte']) ?: '<span class="text-muted fst-italic">N/A</span>' ?></div>
                    </div>
                    
                    <hr class="my-3 text-muted opacity-25">
                    
                    <h6 class="text-secondary fw-bold text-uppercase mb-2" style="font-size: 12px;"><i class="bi bi-clock-history me-1"></i>Tiempos de Proceso</h6>
                    <div class="data-grid">
                        <div class="data-label">En Reparación</div>
                        <div class="data-value"><?= formatDatetimeArg($rep['fecha_en_reparacion']) ?: '<span class="text-muted opacity-50">-</span>' ?></div>
                        
                        <div class="data-label">Finalizado</div>
                        <div class="data-value">
                            <?= formatDatetimeArg($rep['fecha_reparado']) ?> 
                            <?= formatDatetimeArg($rep['fecha_sin_reparacion']) ?>
                            <?= (!$rep['fecha_reparado'] && !$rep['fecha_sin_reparacion']) ? '<span class="text-muted opacity-50">-</span>' : '' ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Observaciones / Comentarios -->
            <div class="dense-card">
                <div class="card-header">
                    <h6 class="text-primary"><i class="bi bi-chat-text me-1"></i>Observaciones</h6>
                </div>
                <div class="card-body">
                    <div class="bg-light border rounded p-3 mb-3" style="max-height: 250px; overflow-y: auto; white-space: pre-wrap; font-size: 13.5px;" id="observacionesText"><?= e($rep['observaciones']) ?: '<span class="text-muted fst-italic">Sin comentarios registrados.</span>' ?></div>
                    
                    <form id="formComentario">
                        <div class="input-group">
                            <input type="text" id="nuevoComentario" class="form-control form-control-compact" placeholder="Agregar una nota técnica o avance..." <?= $can_manage_rep ? '' : 'disabled' ?>>
                            <button class="btn btn-outline-primary btn-compact border-start-0" type="submit" id="btnComentario" <?= $can_manage_rep ? '' : 'disabled' ?> style="border-top-left-radius: 0; border-bottom-left-radius: 0;">
                                <i class="bi bi-send me-1"></i>Enviar
                            </button>
                        </div>
                    </form>
                    <?php if (!$can_manage_rep): ?>
                    <div class="text-muted mt-1" style="font-size: 11px;">Solo administrador o técnico asignado pueden comentar.</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Historial -->
            <div class="dense-card">
                <div class="card-header">
                    <h6 class="text-secondary"><i class="bi bi-journal-text me-1"></i>Historial de Movimientos</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush list-group-compact">
                        <?php if (empty($historial)): ?>
                        <li class="list-group-item text-muted text-center py-4 fst-italic">No hay movimientos registrados.</li>
                        <?php endif; ?>
                        <?php foreach ($historial as $h): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between align-items-baseline mb-1">
                                <strong class="text-dark"><?= e($h['accion']) ?></strong>
                                <small class="text-muted" style="font-size: 11px;"><?= formatDatetimeArg($h['fecha']) ?></small>
                            </div>
                            <div class="text-muted" style="font-size: 12px;">Por: <span class="fw-medium text-dark"><?= e($h['username']) ?></span></div>
                            <?php if ($h['detalle']): ?>
                            <div class="mt-1 p-2 bg-light border rounded text-dark" style="font-size: 12px; font-style: italic;">"<?= e($h['detalle']) ?>"</div>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Columna Derecha: Acciones Rápidas -->
        <div class="col-lg-4">
            <!-- Estado Actual -->
            <div class="dense-card bg-light border-0">
                <div class="card-body">
                    <div class="text-muted text-uppercase fw-bold mb-2" style="font-size: 11px; letter-spacing: 0.05em;">Estado Actual</div>
                    <div class="mb-3">
                        <span id="badgeEstado" class="status-pill <?= getBadgeClass($rep['estado']) ?>"><?= e($rep['estado']) ?></span>
                    </div>
                    
                    <div>
                        <label class="form-label text-muted mb-1" style="font-size: 12px;">Modificar Estado</label>
                        <select id="selectEstado" class="form-select form-select-compact w-100" <?= $can_change_estado ? '' : 'disabled' ?>>
                            <?php foreach ($allowed_estado_options as $est): ?>
                                <option value="<?= e($est['nombre']) ?>" <?= $rep['estado'] === $est['nombre'] ? 'selected' : '' ?>><?= e($est['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$can_change_estado): ?>
                        <div class="text-muted mt-1" style="font-size: 11px;">No tenés permisos para cambiar el estado.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Prioridad -->
            <div class="dense-card <?= $rep['urgente'] === 'SI' ? 'border-danger' : '' ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div class="text-muted text-uppercase fw-bold" style="font-size: 11px; letter-spacing: 0.05em;">Prioridad</div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div id="textPrioridad">
                            <?php if ($rep['urgente'] === 'SI'): ?>
                                <span class="text-danger fw-bold" style="font-size: 14px;"><i class="bi bi-exclamation-triangle-fill me-1"></i>URGENTE</span>
                            <?php else: ?>
                                <span class="text-dark fw-medium" style="font-size: 14px;">Normal</span>
                            <?php endif; ?>
                        </div>
                        <button id="btnTogglePrioridad" class="btn btn-sm btn-compact btn-outline-<?= $rep['urgente'] === 'SI' ? 'danger' : 'secondary' ?>" <?= $can_change_prioridad ? '' : 'disabled' ?>>
                            <?= $rep['urgente'] === 'SI' ? 'Quitar Urgencia' : 'Hacer Urgente' ?>
                        </button>
                    </div>
                    <?php if (!$can_change_prioridad): ?>
                    <div class="text-muted mt-2" style="font-size: 11px;">Solo administrador puede cambiar prioridad.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Asignación Técnico -->
            <div class="dense-card">
                <div class="card-body">
                    <div class="text-muted text-uppercase fw-bold mb-2" style="font-size: 11px; letter-spacing: 0.05em;">Técnico Asignado</div>
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <?php if ($rep['tecnico_nombre']): ?>
                            <div class="tech-avatar bg-primary">
                                <?= strtoupper(substr(e($rep['tecnico_nombre']), 0, 1)) ?>
                            </div>
                            <span class="fw-bold text-dark" id="textTecnico" style="font-size: 14px;"><?= e($rep['tecnico_nombre']) ?></span>
                        <?php else: ?>
                            <div class="tech-avatar"><i class="bi bi-person-x-fill"></i></div>
                            <span class="text-muted fst-italic" id="textTecnico" style="font-size: 14px;">Sin asignar</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($can_reassign_tecnico): ?>
                    <label class="form-label text-muted mb-1" style="font-size: 12px;">Reasignar</label>
                    <select id="selectTecnico" class="form-select form-select-compact w-100">
                        <option value="">-- Quitar Asignación --</option>
                        <?php foreach ($tecnicos as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $rep['tecnico_id'] == $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                        <div class="text-muted" style="font-size: 11px;">Solo administrador puede reasignar.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Devolver Reparación -->
            <div class="dense-card border-warning">
                <div class="card-body bg-warning bg-opacity-10">
                    <div class="text-warning-emphasis text-uppercase fw-bold mb-1" style="font-size: 11px; letter-spacing: 0.05em;">Devolución</div>
                    <p class="text-dark mb-2" style="font-size: 12px; line-height: 1.4;">Quita el técnico asignado y retrocede a Pendiente de Revisión.</p>
                    <button id="btnDevolverReparacion" class="btn btn-warning btn-compact w-100 fw-bold shadow-none" <?= $can_devolver_rep ? '' : 'disabled' ?>>
                        <i class="bi bi-arrow-return-left me-1"></i>Devolver Reparación
                    </button>
                    <?php if (!$can_devolver_rep): ?>
                    <div class="text-muted mt-2" style="font-size: 11px;">Sin permisos para devolver.</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
const reparacionId = <?= $rep['id'] ?>;
const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '<?= generateCsrfToken() ?>';

const formComentario = document.getElementById('formComentario');
if (formComentario) {
    formComentario.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btnComentario');
        const input = document.getElementById('nuevoComentario');
        const val = input.value.trim();
        if(!val || btn.disabled) return;
        
        btn.disabled = true;
        try {
            const res = await fetchApi(`../api/reparacion_comentario.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: reparacionId, comentario: val })
            });
            document.getElementById('observacionesText').innerText = res.observaciones;
            input.value = '';
            location.reload();
        } catch(err) {
            alert("Error al guardar comentario");
        } finally {
            btn.disabled = false;
        }
    });
}

const selectEstado = document.getElementById('selectEstado');
if (selectEstado && !selectEstado.disabled) {
    selectEstado.addEventListener('change', async (e) => {
        const newEstado = e.target.value;
        let comentario = '';
        
        const estadosConComentarioObligatorio = ['REPARADO', 'SIN REPARACION', 'PENDIENTE DE REPUESTO', 'ENTREGADO', 'PEND. DE REVISION'];
        if (estadosConComentarioObligatorio.includes(newEstado)) {
            comentario = prompt(`Al cambiar el estado a ${newEstado}, es obligatorio dejar un comentario final:`);
            if (comentario === null || comentario.trim() === '') {
                alert('Debe ingresar un comentario.');
                location.reload();
                return;
            }
        }
        
        try {
            await fetchApi(`../api/reparacion_estado.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: reparacionId, estado: newEstado, comentario: comentario })
            });
            location.reload();
        } catch(err) {
            alert("Error al cambiar estado");
            location.reload();
        }
    });
}

const selectTecnico = document.getElementById('selectTecnico');
if (selectTecnico) {
    selectTecnico.addEventListener('change', async (e) => {
        const newTecnicoId = e.target.value;
        try {
            await fetchApi(`../api/reparacion_tecnico.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: reparacionId, tecnico_id: newTecnicoId })
            });
            location.reload();
        } catch(err) {
            alert("Error al cambiar técnico");
            location.reload();
        }
    });
}

const btnTogglePrioridad = document.getElementById('btnTogglePrioridad');
if (btnTogglePrioridad && !btnTogglePrioridad.disabled) {
    btnTogglePrioridad.addEventListener('click', async () => {
        const currentUrgente = '<?= $rep['urgente'] ?>';
        const newUrgente = currentUrgente === 'SI' ? 'NO' : 'SI';
        try {
            await fetchApi(`../api/reparacion_prioridad.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: reparacionId, urgente: newUrgente })
            });
            location.reload();
        } catch(err) {
            alert("Error al cambiar prioridad");
        }
    });
}

const btnDevolverReparacion = document.getElementById('btnDevolverReparacion');
if (btnDevolverReparacion && !btnDevolverReparacion.disabled) {
    btnDevolverReparacion.addEventListener('click', async () => {
        const motivo = prompt('Ingrese el motivo de la devolución:');
        if (motivo === null || motivo.trim() === '') {
            alert('Debe ingresar un motivo.');
            return;
        }

        try {
            await fetchApi(`../api/reparacion_devolver.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: reparacionId, comentario: motivo })
            });
            location.reload();
        } catch (err) {
            alert('Error al devolver la reparación');
        }
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>