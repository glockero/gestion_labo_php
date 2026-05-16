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

// How many other repairs share this NPU? Used to surface a "history" badge
// next to the NPU value if it has been processed multiple times.
$npuTotalCount = 0;
if (!empty($rep['npu'])) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reparaciones WHERE TRIM(npu) = ?");
    $stmt->execute([trim($rep['npu'])]);
    $npuTotalCount = (int)$stmt->fetchColumn();
}
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

function avatarColor($nombre) {
    $palette = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899', '#475569'];
    return $palette[abs(crc32((string)$nombre)) % count($palette)];
}

/**
 * Maps a historial action label to a Bootstrap-icon + a CSS color modifier
 * for the timeline dot. Fall through is a neutral gray dot.
 */
function getHistorialVisual($accion) {
    $a = strtoupper((string)$accion);
    if (strpos($a, 'INGRESO') !== false)       return ['bi-plus-circle-fill', 'blue'];
    if (strpos($a, 'ESTADO') !== false)        return ['bi-arrow-repeat',     'violet'];
    if (strpos($a, 'COMENTARIO') !== false)    return ['bi-chat-left-text',   'amber'];
    if (strpos($a, 'TECNICO') !== false ||
        strpos($a, 'ASIGN') !== false)         return ['bi-person-check',     'cyan'];
    if (strpos($a, 'DEVOLVER') !== false ||
        strpos($a, 'DEVOLUCION') !== false)    return ['bi-arrow-return-left','orange'];
    if (strpos($a, 'URGENTE') !== false ||
        strpos($a, 'PRIORIDAD') !== false)     return ['bi-exclamation-triangle-fill', 'red'];
    if (strpos($a, 'LOGIN') !== false)         return ['bi-box-arrow-in-right','green'];
    if (strpos($a, 'LOGOUT') !== false)        return ['bi-box-arrow-right',   'gray'];
    return ['bi-circle-fill', 'gray'];
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

    /* Vertical timeline for "Historial de Movimientos" */
    .timeline {
        position: relative;
        padding: 0.75rem 1rem 0.75rem 2.4rem;
    }
    .timeline::before {
        content: '';
        position: absolute;
        left: 1.55rem;
        top: 1rem;
        bottom: 1rem;
        width: 2px;
        background: #e2e8f0;
    }
    .timeline-item {
        position: relative;
        padding-bottom: 0.85rem;
    }
    .timeline-item:last-child { padding-bottom: 0; }

    .timeline-dot {
        position: absolute;
        left: -1.55rem;
        top: 0;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: #fff;
        border: 2px solid #cbd5e1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        color: #64748b;
        z-index: 1;
    }
    .timeline-dot.dot-blue   { background: #2563eb; border-color: #2563eb; color: #fff; }
    .timeline-dot.dot-violet { background: #7c3aed; border-color: #7c3aed; color: #fff; }
    .timeline-dot.dot-amber  { background: #f59e0b; border-color: #f59e0b; color: #fff; }
    .timeline-dot.dot-cyan   { background: #06b6d4; border-color: #06b6d4; color: #fff; }
    .timeline-dot.dot-orange { background: #ea580c; border-color: #ea580c; color: #fff; }
    .timeline-dot.dot-red    { background: #ef4444; border-color: #ef4444; color: #fff; }
    .timeline-dot.dot-green  { background: #16a34a; border-color: #16a34a; color: #fff; }
    .timeline-dot.dot-gray   { background: #94a3b8; border-color: #94a3b8; color: #fff; }

    .timeline-content {
        font-size: 13px;
        line-height: 1.35;
    }
    .timeline-content .accion {
        font-weight: 700;
        color: #0f172a;
        font-size: 13px;
    }
    .timeline-content .fecha {
        font-size: 11px;
        color: var(--text-muted);
        white-space: nowrap;
    }
    .timeline-content .meta {
        font-size: 11.5px;
        color: var(--text-muted);
        margin-top: 1px;
    }
    .timeline-content .detalle {
        margin-top: 0.4rem;
        padding: 0.4rem 0.55rem;
        background: #f8fafc;
        border-left: 3px solid #cbd5e1;
        border-radius: 0 4px 4px 0;
        font-size: 12px;
        color: #334155;
        font-style: italic;
    }
    .timeline-empty {
        padding: 1.25rem;
        text-align: center;
        color: var(--text-muted);
        font-style: italic;
        font-size: 13px;
    }

    /* NPU history badge (clickable) */
    .npu-history-btn {
        font-size: 11px;
        padding: 0.2rem 0.5rem;
        line-height: 1;
        background: #fef3c7;
        color: #b45309;
        border: 1px solid #fcd34d;
        border-radius: 999px;
        cursor: pointer;
        font-weight: 700;
        margin-left: 0.4rem;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        transition: all 0.12s;
    }
    .npu-history-btn:hover {
        background: #f59e0b;
        color: #fff;
        border-color: #f59e0b;
        transform: scale(1.05);
    }
    .npu-history-btn i { font-size: 10px; }
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
                        <div class="data-value fw-bold text-dark">
                            <?= e($rep['npu']) ?: '<span class="text-muted fw-normal fst-italic">N/A</span>' ?>
                            <?php if ($npuTotalCount > 1): ?>
                                <button type="button" class="npu-history-btn"
                                        data-npu="<?= e(trim($rep['npu'])) ?>"
                                        title="Ver las <?= $npuTotalCount ?> reparaciones de este NPU">
                                    <i class="bi bi-clock-history"></i><?= $npuTotalCount ?> ingresos
                                </button>
                            <?php endif; ?>
                        </div>
                        
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

                        <?php if ($rep['fecha_reparado']): ?>
                            <div class="data-label">Reparado</div>
                            <div class="data-value text-success fw-semibold"><?= formatDatetimeArg($rep['fecha_reparado']) ?></div>
                        <?php endif; ?>

                        <?php if ($rep['fecha_sin_reparacion']): ?>
                            <div class="data-label">Sin Reparación</div>
                            <div class="data-value text-danger fw-semibold"><?= formatDatetimeArg($rep['fecha_sin_reparacion']) ?></div>
                        <?php endif; ?>

                        <?php if (!$rep['fecha_reparado'] && !$rep['fecha_sin_reparacion']): ?>
                            <div class="data-label">Finalizado</div>
                            <div class="data-value"><span class="text-muted opacity-50">-</span></div>
                        <?php endif; ?>
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
                    <?php if (empty($historial)): ?>
                        <div class="timeline-empty">No hay movimientos registrados.</div>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($historial as $h):
                                [$icon, $color] = getHistorialVisual($h['accion']);
                            ?>
                            <div class="timeline-item">
                                <span class="timeline-dot dot-<?= e($color) ?>"><i class="bi <?= e($icon) ?>"></i></span>
                                <div class="timeline-content">
                                    <div class="d-flex justify-content-between align-items-baseline gap-2">
                                        <span class="accion"><?= e($h['accion']) ?></span>
                                        <span class="fecha"><?= formatDatetimeArg($h['fecha']) ?></span>
                                    </div>
                                    <div class="meta">Por <span class="fw-semibold text-dark"><?= e($h['username'] ?: 'Sistema') ?></span></div>
                                    <?php if ($h['detalle']): ?>
                                        <div class="detalle">"<?= e($h['detalle']) ?>"</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
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
                            <div class="tech-avatar" style="background-color: <?= e(avatarColor($rep['tecnico_nombre'])) ?>;">
                                <?= e(mb_strtoupper(mb_substr($rep['tecnico_nombre'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
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

/**
 * Promise-based replacement for window.prompt() using a Bootstrap modal.
 * Resolves with the trimmed string on confirm, or null if the user cancels.
 * Validates that the text is non-empty before resolving.
 */
function promptModal({ title, message, placeholder = '', confirmLabel = 'Confirmar', confirmClass = 'btn-primary', icon = '', emptyError = 'Este campo es obligatorio.' }) {
    return new Promise((resolve) => {
        const modalEl    = document.getElementById('actionModal');
        const titleEl    = document.getElementById('actionModalTitle');
        const messageEl  = document.getElementById('actionModalMessage');
        const inputEl    = document.getElementById('actionModalInput');
        const errorEl    = document.getElementById('actionModalError');
        const confirmBtn = document.getElementById('actionModalConfirm');

        titleEl.innerHTML = (icon ? `<i class="bi ${icon} me-1"></i>` : '') + title;
        messageEl.textContent = message;
        inputEl.value = '';
        inputEl.placeholder = placeholder;
        errorEl.style.display = 'none';
        confirmBtn.textContent = confirmLabel;
        confirmBtn.className = `btn btn-sm fw-bold ${confirmClass}`;
        confirmBtn.style.fontSize = '0.8rem';
        confirmBtn.style.padding = '0.35rem 0.85rem';

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        let resolved = false;

        function cleanup() {
            confirmBtn.removeEventListener('click', onConfirm);
            inputEl.removeEventListener('keydown', onKey);
            modalEl.removeEventListener('hidden.bs.modal', onHide);
        }
        function onConfirm() {
            const val = inputEl.value.trim();
            if (!val) {
                errorEl.textContent = emptyError;
                errorEl.style.display = '';
                inputEl.focus();
                return;
            }
            resolved = true;
            cleanup();
            modal.hide();
            resolve(val);
        }
        function onKey(e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                onConfirm();
            }
        }
        function onHide() {
            if (resolved) return;
            cleanup();
            resolve(null);
        }

        confirmBtn.addEventListener('click', onConfirm);
        inputEl.addEventListener('keydown', onKey);
        modalEl.addEventListener('hidden.bs.modal', onHide);

        modal.show();
        setTimeout(() => inputEl.focus(), 150);
    });
}

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
            showToast('Error al guardar comentario', 'danger');
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
            comentario = await promptModal({
                title: 'Cambio de estado',
                message: `El estado va a cambiar a "${newEstado}". Detallá el motivo o el resultado para dejar constancia en el historial.`,
                placeholder: 'Comentario final…',
                confirmLabel: 'Confirmar cambio',
                confirmClass: 'btn-primary',
                icon: 'bi-arrow-repeat',
            });
            if (comentario === null) {
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
            showToast('Error al cambiar estado', 'danger');
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
            showToast('Error al cambiar técnico', 'danger');
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
            showToast('Error al cambiar prioridad', 'danger');
        }
    });
}

const btnDevolverReparacion = document.getElementById('btnDevolverReparacion');
if (btnDevolverReparacion && !btnDevolverReparacion.disabled) {
    btnDevolverReparacion.addEventListener('click', async () => {
        const motivo = await promptModal({
            title: 'Devolver reparación',
            message: 'La reparación va a volver a "Pendiente de Revisión" y se va a quitar el técnico asignado. Detallá el motivo.',
            placeholder: 'Motivo de la devolución…',
            confirmLabel: 'Devolver',
            confirmClass: 'btn-warning',
            icon: 'bi-arrow-return-left',
        });
        if (motivo === null) return;

        try {
            await fetchApi(`../api/reparacion_devolver.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: reparacionId, comentario: motivo })
            });
            location.reload();
        } catch (err) {
            showToast('Error al devolver la reparación', 'danger');
        }
    });
}
</script>

<!-- Generic prompt modal: replaces the browser's prompt() with a textarea -->
<div class="modal fade" id="actionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 460px;">
        <div class="modal-content" style="border-radius: 12px;">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title fw-bold m-0" id="actionModalTitle" style="font-size: 0.92rem;"></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-3">
                <p id="actionModalMessage" class="mb-2 text-dark" style="font-size: 0.85rem;"></p>
                <textarea id="actionModalInput" class="form-control" rows="4" style="font-size: 0.85rem; resize: vertical;"></textarea>
                <div id="actionModalError" class="text-danger mt-1" style="display: none; font-size: 0.75rem;"></div>
            </div>
            <div class="modal-footer py-2 px-3 d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-light btn-sm border" data-bs-dismiss="modal" style="font-size: 0.8rem; padding: 0.35rem 0.7rem;">Cancelar</button>
                <button type="button" id="actionModalConfirm" class="btn btn-primary btn-sm fw-bold" style="font-size: 0.8rem; padding: 0.35rem 0.85rem;">Confirmar</button>
            </div>
        </div>
    </div>
</div>

<!-- NPU history modal: lists every repair sharing this NPU -->
<?php if ($npuTotalCount > 1): ?>
<div class="modal fade" id="npuHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 10px;">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title fw-bold m-0" style="font-size: 0.9rem;">
                    <i class="bi bi-clock-history me-1"></i>
                    Historial del NPU
                    <code id="npuHistoryNpu" class="ms-1" style="font-size: 0.8rem; background: #f1f5f9; padding: 1px 6px; border-radius: 4px;"></code>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-0">
                <div id="npuHistoryLoading" class="text-center py-4 text-muted" style="font-size: 0.85rem;">
                    <div class="spinner-border spinner-border-sm me-2"></div> Cargando...
                </div>
                <div id="npuHistoryEmpty" class="text-center py-4 text-muted" style="font-size: 0.85rem; display: none;">
                    No se encontraron reparaciones para este NPU.
                </div>
                <div id="npuHistoryWrapper" style="display: none;">
                    <table class="table table-sm mb-0" style="font-size: 0.78rem;">
                        <thead style="position: sticky; top: 0; background: #f8fafc; z-index: 1;">
                            <tr>
                                <th style="width: 60px; padding: 0.4rem 0.6rem;">ID</th>
                                <th style="width: 110px; padding: 0.4rem 0.6rem;">Fecha</th>
                                <th style="padding: 0.4rem 0.6rem;">Equipo</th>
                                <th style="padding: 0.4rem 0.6rem;">Sala</th>
                                <th style="padding: 0.4rem 0.6rem;">Técnico</th>
                                <th style="padding: 0.4rem 0.6rem;">Estado</th>
                                <th style="width: 50px; padding: 0.4rem 0.6rem;"></th>
                            </tr>
                        </thead>
                        <tbody id="npuHistoryRows"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2 px-3" style="font-size: 0.75rem;">
                <span class="text-muted me-auto" id="npuHistoryCount"></span>
                <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal" style="font-size: 0.75rem; padding: 0.3rem 0.7rem;">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const currentRepId = <?= (int)$rep['id'] ?>;
    const modalEl    = document.getElementById('npuHistoryModal');
    const loadingEl  = document.getElementById('npuHistoryLoading');
    const emptyEl    = document.getElementById('npuHistoryEmpty');
    const wrapperEl  = document.getElementById('npuHistoryWrapper');
    const tbodyEl    = document.getElementById('npuHistoryRows');
    const npuLabel   = document.getElementById('npuHistoryNpu');
    const countLabel = document.getElementById('npuHistoryCount');
    if (!modalEl) return;

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
        })[c]);
    }

    function statusClass(estado) {
        const e = (estado || '').toUpperCase();
        if (e.includes('REPARADO')) return 'status-success';
        if (e.includes('SIN REPARACION')) return 'status-danger';
        if (e.includes('PEND')) return 'status-warning';
        if (e.includes('PRUEBA')) return 'status-info';
        return 'status-secondary';
    }

    function showState(state) {
        loadingEl.style.display = state === 'loading' ? '' : 'none';
        emptyEl.style.display   = state === 'empty'   ? '' : 'none';
        wrapperEl.style.display = state === 'data'    ? '' : 'none';
    }

    async function openNpuHistory(npu) {
        npuLabel.textContent = npu;
        countLabel.textContent = '';
        tbodyEl.innerHTML = '';
        showState('loading');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();

        try {
            const res = await fetch('../api/reparaciones_por_npu.php?npu=' + encodeURIComponent(npu));
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            const reps = data.reparaciones || [];

            if (reps.length === 0) {
                showState('empty');
                return;
            }

            tbodyEl.innerHTML = reps.map(r => {
                const isCurrent = parseInt(r.id, 10) === currentRepId;
                const rowStyle = [];
                if (r.urgente === 'SI') rowStyle.push('background: #fef2f2');
                if (isCurrent) rowStyle.push('background: #eff6ff; font-weight: 600');
                const styleAttr = rowStyle.length ? ` style="${rowStyle.join('; ')}"` : '';
                const idCell = isCurrent
                    ? `<strong>#${esc(r.id)}</strong> <span class="badge bg-primary" style="font-size: 9px; padding: 1px 4px;">ACTUAL</span>`
                    : `<strong>#${esc(r.id)}</strong>`;
                const lastCell = isCurrent
                    ? `<span class="text-muted" style="font-size: 11px;">—</span>`
                    : `<a href="reparacion_detalle.php?id=${esc(r.id)}" class="text-decoration-none" title="Abrir ficha"><i class="bi bi-box-arrow-up-right"></i></a>`;
                return `
                    <tr${styleAttr}>
                        <td style="padding: 0.35rem 0.6rem;">${idCell}</td>
                        <td style="padding: 0.35rem 0.6rem; white-space: nowrap;">${esc(r.fecha_fmt)}</td>
                        <td style="padding: 0.35rem 0.6rem;">${esc(r.equipo)}</td>
                        <td style="padding: 0.35rem 0.6rem;">${esc(r.sala)}</td>
                        <td style="padding: 0.35rem 0.6rem;">${esc(r.tecnico_nombre || '—')}</td>
                        <td style="padding: 0.35rem 0.6rem;"><span class="status-pill ${statusClass(r.estado)}" style="font-size: 10px; padding: 3px 6px; text-transform: uppercase; width: auto;">${esc(r.estado)}</span></td>
                        <td style="padding: 0.35rem 0.6rem; text-align: center;">${lastCell}</td>
                    </tr>
                `;
            }).join('');

            countLabel.textContent = `${reps.length} ingreso${reps.length === 1 ? '' : 's'} para este NPU`;
            showState('data');
        } catch (e) {
            console.error('Error loading NPU history', e);
            emptyEl.textContent = 'Error al cargar el historial. Intentá nuevamente.';
            showState('empty');
        }
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.npu-history-btn');
        if (!btn) return;
        e.preventDefault();
        openNpuHistory(btn.dataset.npu);
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>