<?php
// public/reparacion_detalle.php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../app/ReparacionModel.php';
require_once __DIR__ . '/../app/CatalogoModel.php';

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

$historial = ReparacionModel::getHistorialReparacion($id);
$tecnicos = CatalogoModel::getAll('tecnicos');
$estados_catalogo = CatalogoModel::getAll('estados');

// Helper
function getBadgeClass($estado) {
    switch (strtoupper($estado)) {
        case 'PEND. DE REVISION': return 'bg-secondary';
        case 'EN REPARACION': return 'bg-primary';
        case 'REPARADO': return 'bg-success';
        case 'SIN REPARACION': return 'bg-danger';
        case 'PENDIENTE DE REPUESTO': return 'bg-warning text-dark';
        case 'ENTREGADO': return 'bg-info text-dark';
        default: return 'bg-dark';
    }
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="h3"><i class="bi bi-file-earmark-text"></i> Detalle de Reparación #<?= $rep['id'] ?></h2>
    <div>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver</a>
        <?php if ($user_role === 'admin'): ?>
        <a href="reparacion_editar.php?id=<?= $rep['id'] ?>" class="btn btn-primary"><i class="bi bi-pencil"></i> Editar</a>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <!-- Ficha Técnica -->
    <div class="col-md-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-bottom">
                <h5 class="mb-0 text-primary"><i class="bi bi-info-circle"></i> Datos del Equipo</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-sm-4 text-muted">Fecha Ingreso</div>
                    <div class="col-sm-8 fw-bold"><?= formatDatetimeArg($rep['fecha']) ?></div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-4 text-muted">Sala</div>
                    <div class="col-sm-8"><span class="badge bg-light text-dark border"><?= e($rep['sala']) ?></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-4 text-muted">Equipo / Familia</div>
                    <div class="col-sm-8"><?= e($rep['equipo']) ?> <span class="text-muted">(<?= e($rep['familia']) ?>)</span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-4 text-muted">NPU / Patrimonio</div>
                    <div class="col-sm-8 fw-bold"><?= e($rep['npu']) ?: '<span class="text-muted">N/A</span>' ?></div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-4 text-muted">UID</div>
                    <div class="col-sm-8"><?= e($rep['uid']) ?: '<span class="text-muted">N/A</span>' ?></div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-4 text-muted">Parte</div>
                    <div class="col-sm-8"><?= e($rep['parte']) ?: '<span class="text-muted">N/A</span>' ?></div>
                </div>
                
                <hr>
                
                <h6 class="text-primary"><i class="bi bi-clock-history"></i> Tiempos</h6>
                <div class="row mb-2">
                    <div class="col-sm-4 text-muted">En Reparación</div>
                    <div class="col-sm-8"><?= formatDatetimeArg($rep['fecha_en_reparacion']) ?: '-' ?></div>
                </div>
                <div class="row mb-2">
                    <div class="col-sm-4 text-muted">Finalizado</div>
                    <div class="col-sm-8">
                        <?= formatDatetimeArg($rep['fecha_reparado']) ?> 
                        <?= formatDatetimeArg($rep['fecha_sin_reparacion']) ?>
                        <?= (!$rep['fecha_reparado'] && !$rep['fecha_sin_reparacion']) ? '-' : '' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Observaciones / Comentarios -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-primary"><i class="bi bi-chat-text"></i> Observaciones</h5>
            </div>
            <div class="card-body">
                <div class="bg-light p-3 rounded mb-3" style="max-height: 300px; overflow-y: auto; white-space: pre-wrap;" id="observacionesText"><?= e($rep['observaciones']) ?></div>
                
                <form id="formComentario">
                    <div class="input-group">
                        <input type="text" id="nuevoComentario" class="form-control" placeholder="Agregar una nota técnica o avance...">
                        <button class="btn btn-outline-primary" type="submit" id="btnComentario"><i class="bi bi-send"></i> Agregar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Historial -->
        <div class="card shadow-sm">
            <div class="card-header bg-white border-bottom">
                <h5 class="mb-0 text-secondary"><i class="bi bi-journal-text"></i> Historial de Movimientos</h5>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if (empty($historial)): ?>
                    <li class="list-group-item text-muted text-center py-3">No hay historial registrado.</li>
                    <?php endif; ?>
                    <?php foreach ($historial as $h): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong><?= e($h['accion']) ?></strong>
                            <small class="text-muted"><?= formatDatetimeArg($h['fecha']) ?></small>
                        </div>
                        <div class="small text-muted mt-1">Por: <?= e($h['username']) ?></div>
                        <?php if ($h['detalle']): ?>
                        <div class="small mt-1 fst-italic">"<?= e($h['detalle']) ?>"</div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- Panel de Estado y Acciones Rápidas -->
    <div class="col-md-4">
        <!-- Estado Actual -->
        <div class="card shadow-sm border-0 bg-light mb-4">
            <div class="card-body text-center">
                <h6 class="text-muted text-uppercase fw-bold mb-2">Estado Actual</h6>
                <div class="fs-4 mb-2">
                    <span id="badgeEstado" class="badge <?= getBadgeClass($rep['estado']) ?> w-100 py-2"><?= e($rep['estado']) ?></span>
                </div>
                
                <div class="mt-3 text-start">
                    <label class="form-label small text-muted mb-1">Cambiar Estado</label>
                    <select id="selectEstado" class="form-select form-select-sm">
                        <?php foreach ($estados_catalogo as $est): ?>
                            <option value="<?= e($est['nombre']) ?>" <?= $rep['estado'] === $est['nombre'] ? 'selected' : '' ?>><?= e($est['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Prioridad -->
        <div class="card shadow-sm mb-4 border-<?= $rep['urgente'] === 'SI' ? 'danger' : 'secondary' ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted text-uppercase fw-bold mb-0">Prioridad</h6>
                        <div class="fs-5 mt-1" id="textPrioridad">
                            <?php if ($rep['urgente'] === 'SI'): ?>
                                <span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle"></i> URGENTE</span>
                            <?php else: ?>
                                <span class="text-secondary">Normal</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <button id="btnTogglePrioridad" class="btn btn-sm btn-outline-<?= $rep['urgente'] === 'SI' ? 'danger' : 'secondary' ?>">
                            Cambiar a <?= $rep['urgente'] === 'SI' ? 'Normal' : 'Urgente' ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Asignación -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h6 class="text-muted text-uppercase fw-bold mb-2">Técnico Asignado</h6>
                <div class="mb-3 fs-5">
                    <i class="bi bi-person-badge text-primary"></i> <strong id="textTecnico"><?= e($rep['tecnico_nombre'] ?? 'Sin asignar') ?></strong>
                </div>
                
                <?php if ($user_role === 'admin' || $rep['tecnico_id'] == $_SESSION['tecnico_id'] || !$rep['tecnico_id']): ?>
                <label class="form-label small text-muted mb-1">Reasignar</label>
                <select id="selectTecnico" class="form-select form-select-sm">
                    <option value="">-- Sin Asignar --</option>
                    <?php foreach ($tecnicos as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $rep['tecnico_id'] == $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
const reparacionId = <?= $rep['id'] ?>;
const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '<?= generateCsrfToken() ?>';

document.getElementById('formComentario').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnComentario');
    const input = document.getElementById('nuevoComentario');
    const val = input.value.trim();
    if(!val) return;
    
    btn.disabled = true;
    try {
        const res = await fetchApi(`../api/reparacion_comentario.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ id: reparacionId, comentario: val })
        });
        document.getElementById('observacionesText').innerText = res.observaciones;
        input.value = '';
        location.reload(); // Reload to show history
    } catch(err) {
        alert("Error al guardar comentario");
    } finally {
        btn.disabled = false;
    }
});

document.getElementById('selectEstado').addEventListener('change', async (e) => {
    const newEstado = e.target.value;
    let comentario = '';
    
    if (newEstado === 'REPARADO' || newEstado === 'SIN REPARACION') {
        comentario = prompt(`Al cambiar el estado a ${newEstado}, es obligatorio dejar un comentario final:`);
        if (comentario === null || comentario.trim() === '') {
            alert('Debe ingresar un comentario.');
            location.reload(); // reset select
            return;
        }
    }
    
    try {
        const res = await fetchApi(`../api/reparacion_estado.php`, {
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

document.getElementById('btnTogglePrioridad').addEventListener('click', async (e) => {
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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
