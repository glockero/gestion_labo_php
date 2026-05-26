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

// Other repairs that share this NPU — used to surface a collapsible
// "Historial del NPU" card below the equipment data. Excludes the current
// repair so the user sees only the history, not a self-reference.
$npuOtrasReparaciones = [];
if (!empty($rep['npu'])) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT r.id, r.fecha, r.equipo, r.sala, r.estado, r.urgente,
               COALESCE(t.nombre, r.tecnico_nombre_historico) AS tecnico_nombre
        FROM reparaciones r
        LEFT JOIN tecnicos t ON r.tecnico_id = t.id
        WHERE TRIM(r.npu) = ? AND r.id <> ?
        ORDER BY r.fecha DESC, r.id DESC
    ");
    $stmt->execute([trim($rep['npu']), $rep['id']]);
    $npuOtrasReparaciones = $stmt->fetchAll();
}
$npuTotalCount = count($npuOtrasReparaciones);
$tecnicos = CatalogoModel::getAll('tecnicos');
$estados_catalogo = CatalogoModel::getAll('estados');
$is_admin = $user_role === 'admin';
$is_assigned_tecnico = $user_role === 'tecnico' && !empty($rep['tecnico_id']) && (int)$rep['tecnico_id'] === (int)($_SESSION['tecnico_id'] ?? 0);
$can_manage_rep = $is_admin || $is_assigned_tecnico;
$can_reassign_tecnico = $is_admin;
// A technician viewing an unassigned repair can claim it for themselves.
$can_self_assign = $user_role === 'tecnico'
    && empty($rep['tecnico_id'])
    && !empty($_SESSION['tecnico_id']);
$can_change_prioridad = $is_admin;
$can_devolver_rep = $can_manage_rep;
$allowed_estado_options = array_values(array_filter($estados_catalogo, function ($estadoItem) use ($rep, $user_role) {
    return canTransitionEstado($rep['estado'], $estadoItem['nombre'], $user_role) || normalizeEstadoLabel($rep['estado']) === normalizeEstadoLabel($estadoItem['nombre']);
}));
$can_change_estado = $can_manage_rep && count($allowed_estado_options) > 1;

// Build a map [estado_destino => requiere_comentario?] used by the JS to
// decide whether to open the comment modal before submitting. The same
// predicate is enforced server-side in api/reparacion_estado.php; this
// avoids the prompt-then-422 round trip.
$estados_requieren_comentario = [];
foreach ($allowed_estado_options as $est) {
    $estados_requieren_comentario[$est['nombre']] = estadoTransitionRequiresComment($rep['estado'], $est['nombre']);
}

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
        --detail-bg: #f3f4f6;
        --detail-surface: #ffffff;
        --detail-surface-soft: #f8fafc;
        --detail-border: #d6dbe3;
        --detail-border-strong: #b8c2cf;
        --detail-text: #1f2937;
        --detail-muted: #6b7280;
        --detail-heading: #111827;
        --detail-accent: #334155;
        --detail-shadow: 0 18px 40px -30px rgba(15, 23, 42, 0.45);
    }

    body {
        background:
            radial-gradient(circle at top left, rgba(255, 255, 255, 0.95), transparent 34%),
            linear-gradient(180deg, #f8fafc 0%, var(--detail-bg) 100%) !important;
    }

    .detail-shell {
        font-size: 13.5px;
        color: var(--detail-text);
    }

    .detail-header {
        margin-bottom: 1.5rem;
        padding: 1.5rem;
        border: 1px solid rgba(214, 219, 227, 0.95);
        border-radius: 18px;
        background: linear-gradient(135deg, rgba(255,255,255,0.98), rgba(248,250,252,0.94));
        box-shadow: var(--detail-shadow);
    }

    .detail-kicker {
        margin-bottom: 0.45rem;
        color: var(--detail-muted);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
    }

    .detail-title {
        font-size: 28px;
        font-weight: 700;
        color: var(--detail-heading);
        margin: 0;
        letter-spacing: -0.03em;
    }

    .detail-subtitle {
        margin: 0.45rem 0 0;
        color: var(--detail-muted);
        max-width: 720px;
        line-height: 1.55;
    }

    .detail-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
        margin-top: 1rem;
    }

    .detail-meta-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        min-height: 34px;
        padding: 0.45rem 0.8rem;
        border: 1px solid var(--detail-border);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.72);
        color: var(--detail-text);
        font-size: 12px;
        font-weight: 600;
    }

    .detail-meta-chip i {
        color: var(--detail-accent);
    }

    .detail-layout {
        align-items: start;
    }

    .dense-card {
        background: rgba(255, 255, 255, 0.97);
        border-radius: 18px;
        border: 1px solid var(--detail-border);
        box-shadow: var(--detail-shadow);
        margin-bottom: 1rem;
        overflow: hidden;
    }

    .dense-card .card-header {
        background: linear-gradient(180deg, rgba(249, 250, 251, 0.96), rgba(243, 244, 246, 0.9)) !important;
        padding: 0.9rem 1.2rem;
        border-bottom: 1px solid var(--detail-border) !important;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .dense-card .card-header h6 {
        font-size: 12px;
        font-weight: 700;
        margin: 0;
        color: var(--detail-heading) !important;
        text-transform: uppercase;
        letter-spacing: 0.12em;
    }

    .dense-card .card-header h6 i {
        color: var(--detail-accent) !important;
        font-size: 13px;
        margin-right: 0.35rem;
    }

    .dense-card .card-body {
        padding: 1.2rem;
    }

    .data-grid {
        display: grid;
        grid-template-columns: 150px 1fr;
        gap: 0.7rem 1rem;
        align-items: baseline;
    }

    @media (max-width: 575.98px) {
        .data-grid {
            grid-template-columns: 1fr;
            gap: 0.15rem;
        }

        .data-label {
            margin-top: 0.5rem;
        }
    }

    .data-label {
        color: var(--detail-muted);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.12em;
    }

    .data-value {
        font-size: 14px;
        font-weight: 600;
        color: var(--detail-heading);
        line-height: 1.5;
    }

    .data-chip {
        display: inline-flex;
        align-items: center;
        min-height: 30px;
        padding: 0.3rem 0.7rem;
        border-radius: 999px;
        border: 1px solid var(--detail-border);
        background: var(--detail-surface-soft);
        color: var(--detail-text);
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .section-divider {
        margin: 1.35rem 0;
        border-color: var(--detail-border);
        opacity: 1;
    }

    .section-caption {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        margin-bottom: 0.95rem;
        color: var(--detail-heading);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .section-caption i {
        color: var(--detail-accent);
    }

    .btn-compact {
        height: 38px;
        padding: 0 0.95rem;
        font-size: 13px;
        display: inline-flex;
        align-items: center;
        border-radius: 10px;
        font-weight: 600;
        border-width: 1px;
    }

    .btn-toolbar-neutral {
        background: transparent;
        border-color: var(--detail-border-strong);
        color: var(--detail-text);
    }

    .btn-toolbar-neutral:hover {
        background: var(--detail-surface-soft);
        border-color: var(--detail-accent);
        color: var(--detail-heading);
    }

    .btn-toolbar-primary {
        background: var(--detail-heading);
        border-color: var(--detail-heading);
        color: #fff;
    }

    .btn-toolbar-primary:hover {
        background: #1f2937;
        border-color: #1f2937;
        color: #fff;
    }

    .form-control-compact, .form-select-compact {
        min-height: 42px;
        font-size: 13px;
        border-radius: 10px;
        border: 1px solid var(--detail-border-strong);
        padding: 0.55rem 0.75rem;
        background-color: #ffffff;
        color: var(--detail-text);
        transition: all 0.15s ease;
    }

    .form-control-compact:focus, .form-select-compact:focus {
        border-color: var(--detail-accent);
        box-shadow: 0 0 0 3px rgba(51, 65, 85, 0.12);
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.55rem 0.9rem;
        font-size: 12px;
        font-weight: 700;
        border-radius: 999px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        text-align: center;
    }

    .status-success { background-color: #ecfdf3; color: #166534; border: 1px solid #b7e4c7; }
    .status-warning { background-color: #fff8e6; color: #92400e; border: 1px solid #f3d19c; }
    .status-danger { background-color: #fef2f2; color: #991b1b; border: 1px solid #efc4c4; }
    .status-info { background-color: #eff6ff; color: #1d4ed8; border: 1px solid #c6d6f7; }
    .status-secondary { background-color: #f5f6f7; color: #4b5563; border: 1px solid #d6dbe3; }
    .status-primary { background-color: #eef2f7; color: #334155; border: 1px solid #cfd8e3; }
    .status-dark { background-color: #e5e7eb; color: #111827; border: 1px solid #d1d5db; }

    .tech-avatar {
        width: 38px;
        height: 38px;
        font-size: 14px;
        background-color: #cbd5e1;
        color: #fff;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        box-shadow: inset 0 0 0 1px rgba(255,255,255,0.2);
    }

    .aside-stack {
        position: sticky;
        top: 1rem;
    }

    .aside-value {
        font-size: 15px;
        font-weight: 700;
        color: var(--detail-heading);
    }

    .aside-note,
    .helper-note {
        margin-top: 0.75rem;
        color: var(--detail-muted);
        font-size: 11.5px;
        line-height: 1.5;
    }

    .observaciones-box {
        padding: 1rem 1.05rem;
        border: 1px solid var(--detail-border);
        border-radius: 14px;
        background: linear-gradient(180deg, #fafafa 0%, #f7f8fa 100%);
        max-height: 260px;
        overflow-y: auto;
        white-space: pre-wrap;
        font-size: 13.5px;
        line-height: 1.65;
    }

    .comment-composer {
        display: flex;
        gap: 0.75rem;
        align-items: flex-end;
    }

    .summary-panel {
        padding: 1rem 1.05rem;
        border: 1px solid var(--detail-border);
        border-radius: 14px;
        background: linear-gradient(180deg, rgba(249,250,251,0.98), rgba(243,244,246,0.94));
    }

    .summary-panel.is-warning {
        border-color: #e7c98a;
        background: linear-gradient(180deg, rgba(255,251,235,0.98), rgba(254,243,199,0.72));
    }

    .summary-label {
        color: var(--detail-muted);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        margin-bottom: 0.5rem;
    }

    .summary-value {
        color: var(--detail-heading);
        font-size: 18px;
        font-weight: 700;
        line-height: 1.3;
    }

    .summary-text {
        color: var(--detail-text);
        font-size: 12.5px;
        line-height: 1.55;
        margin-top: 0.55rem;
    }

    .timeline {
        position: relative;
        padding: 1rem 1.25rem 1.1rem 3rem;
    }

    .timeline::before {
        content: '';
        position: absolute;
        left: 1.9rem;
        top: 1.2rem;
        bottom: 1.2rem;
        width: 2px;
        background: linear-gradient(180deg, #d1d5db 0%, #e5e7eb 100%);
    }

    .timeline-item {
        position: relative;
        padding-bottom: 1rem;
    }

    .timeline-item:last-child { padding-bottom: 0; }

    .timeline-dot {
        position: absolute;
        left: -2rem;
        top: 0.15rem;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: #fff;
        border: 2px solid var(--detail-border-strong);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        color: var(--detail-muted);
        z-index: 1;
    }

    .timeline-dot.dot-blue   { background: #2563eb; border-color: #1d4ed8; color: #fff; }
    .timeline-dot.dot-violet { background: #7c3aed; border-color: #6d28d9; color: #fff; }
    .timeline-dot.dot-amber  { background: #f59e0b; border-color: #d97706; color: #fff; }
    .timeline-dot.dot-cyan   { background: #06b6d4; border-color: #0891b2; color: #fff; }
    .timeline-dot.dot-orange { background: #ea580c; border-color: #c2410c; color: #fff; }
    .timeline-dot.dot-red    { background: #ef4444; border-color: #b91c1c; color: #fff; }
    .timeline-dot.dot-green  { background: #16a34a; border-color: #15803d; color: #fff; }
    .timeline-dot.dot-gray   { background: #94a3b8; border-color: #64748b; color: #fff; }

    .timeline-content {
        font-size: 13px;
        line-height: 1.45;
    }

    .timeline-content .accion {
        font-weight: 700;
        color: var(--detail-heading);
        font-size: 13px;
    }

    .timeline-content .fecha {
        font-size: 11px;
        color: var(--detail-muted);
        white-space: nowrap;
    }

    .timeline-content .meta {
        font-size: 11.5px;
        color: var(--detail-muted);
        margin-top: 0.15rem;
    }

    .timeline-content .detalle {
        margin-top: 0.55rem;
        padding: 0.7rem 0.85rem;
        background: var(--detail-surface-soft);
        border: 1px solid var(--detail-border);
        border-radius: 12px;
        font-size: 12px;
        color: #374151;
        font-style: italic;
    }

    .timeline-empty {
        padding: 1.4rem;
        text-align: center;
        color: var(--detail-muted);
        font-style: italic;
        font-size: 13px;
    }

    .page-loading-overlay {
        position: fixed;
        inset: 0;
        background: rgba(248, 250, 252, 0.65);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(2px);
    }

    .page-loading-overlay.is-active { display: flex; }

    .page-loading-overlay .spinner-border {
        width: 2.5rem;
        height: 2.5rem;
        color: var(--detail-accent);
    }

    textarea.autosize {
        resize: none;
        min-height: 42px;
        max-height: 220px;
        overflow-y: auto;
    }

    .npu-history-card .card-header {
        cursor: pointer;
        user-select: none;
        transition: background 0.12s;
    }

    .npu-history-card .card-header:hover { background: #eef2f7 !important; }

    .npu-history-card .card-header .toggle-icon {
        transition: transform 0.2s ease;
        color: var(--detail-muted);
        font-size: 14px;
    }

    .npu-history-card .card-header[aria-expanded="true"] .toggle-icon {
        transform: rotate(180deg);
    }

    .npu-history-card .npu-history-count {
        font-size: 11px;
        font-weight: 700;
        background: #f3f4f6;
        color: #4b5563;
        border: 1px solid var(--detail-border);
        border-radius: 999px;
        padding: 0.18rem 0.5rem;
        margin-left: 0.5rem;
    }

    .npu-history-card table {
        font-size: 12.5px;
        margin: 0;
    }

    .npu-history-card table thead th {
        background: #eef2f7 !important;
        color: #374151 !important;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        padding: 0.7rem 0.75rem;
        border-bottom: 1px solid var(--detail-border) !important;
    }

    .npu-history-card table tbody td {
        padding: 0.55rem 0.75rem;
        border-bottom: 1px solid var(--detail-border);
        vertical-align: middle;
    }

    .npu-history-card table tbody tr:last-child td { border-bottom: none; }
    .npu-history-card table tbody tr:hover td { background: #f8fafc; }
    .npu-history-card tr.urgent-row td { background: #fff7ed !important; }

    .npu-history-card .open-link {
        color: var(--detail-muted);
        text-decoration: none;
        font-size: 14px;
    }

    .npu-history-card .open-link:hover { color: var(--detail-heading); }

    .detail-modal .modal-content {
        border: 1px solid var(--detail-border);
        border-radius: 18px;
        box-shadow: 0 20px 60px -25px rgba(15, 23, 42, 0.4);
    }

    .detail-modal .modal-header,
    .detail-modal .modal-footer {
        background: #fafafa;
        border-color: var(--detail-border);
    }

    .detail-modal .modal-body textarea {
        min-height: 120px;
        border-radius: 12px;
    }

    @media (max-width: 991.98px) {
        .aside-stack {
            position: static;
        }
    }

    @media (max-width: 767.98px) {
        .detail-header {
            padding: 1.2rem;
        }

        .detail-title {
            font-size: 24px;
        }

        .comment-composer {
            flex-direction: column;
            align-items: stretch;
        }

        .comment-composer .btn-compact {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="detail-shell">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center detail-header gap-3">
        <div>
            <div class="detail-kicker">Ficha de reparación</div>
            <h2 class="detail-title">Reparación · <?= e($rep['equipo']) ?></h2>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-compact btn-toolbar-neutral"><i class="bi bi-arrow-left me-1"></i>Volver</a>
            <?php if ($user_role === 'admin'): ?>
            <a href="reparacion_editar.php?id=<?= $rep['id'] ?>" class="btn btn-compact btn-toolbar-primary"><i class="bi bi-pencil me-1"></i>Editar</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 detail-layout">
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
                            <span class="data-chip"><?= e($rep['sala']) ?></span>
                        </div>
                        
                        <div class="data-label">Equipo / Familia</div>
                        <div class="data-value"><?= e($rep['equipo']) ?> <span class="text-muted fw-normal fst-italic ms-1" style="font-size: 12px;">(<?= e($rep['familia']) ?>)</span></div>
                        
                        <div class="data-label">NPU / Patrimonio</div>
                        <div class="data-value fw-bold text-dark"><?= e($rep['npu']) ?: '<span class="text-muted fw-normal fst-italic">N/A</span>' ?></div>
                        
                        <div class="data-label">UID</div>
                        <div class="data-value"><?= e($rep['uid']) ?: '<span class="text-muted fst-italic">N/A</span>' ?></div>
                        
                        <div class="data-label">Parte</div>
                        <div class="data-value"><?= e($rep['parte']) ?: '<span class="text-muted fst-italic">N/A</span>' ?></div>
                    </div>
                    
                    <hr class="section-divider">
                    
                    <div class="section-caption"><i class="bi bi-clock-history"></i>Tiempos de proceso</div>
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

            <!-- Historial del NPU (colapsable) -->
            <?php if ($npuTotalCount > 0):
                $npuStatusClass = function ($estado) {
                    $e = strtoupper((string)$estado);
                    if (strpos($e, 'REPARADO') !== false) return 'status-success';
                    if (strpos($e, 'SIN REPARACION') !== false) return 'status-danger';
                    if (strpos($e, 'PEND') !== false) return 'status-warning';
                    if (strpos($e, 'PRUEBA') !== false) return 'status-info';
                    return 'status-secondary';
                };
            ?>
            <div class="dense-card npu-history-card">
                <div class="card-header d-flex justify-content-between align-items-center"
                     role="button"
                     data-bs-toggle="collapse"
                     data-bs-target="#npuHistoryCollapse"
                     aria-expanded="false"
                     aria-controls="npuHistoryCollapse">
                    <h6 class="text-primary m-0">
                        <i class="bi bi-clock-history me-1"></i>Historial del NPU
                        <span class="npu-history-count"><?= $npuTotalCount ?> ingreso<?= $npuTotalCount === 1 ? '' : 's' ?> previo<?= $npuTotalCount === 1 ? '' : 's' ?></span>
                    </h6>
                    <i class="bi bi-chevron-down toggle-icon"></i>
                </div>
                <div class="collapse" id="npuHistoryCollapse">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">ID</th>
                                        <th style="width: 110px;">Fecha</th>
                                        <th>Equipo</th>
                                        <th>Sala</th>
                                        <th>Técnico</th>
                                        <th>Estado</th>
                                        <th style="width: 36px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($npuOtrasReparaciones as $hr):
                                        $isUrg = $hr['urgente'] === 'SI';
                                    ?>
                                    <tr<?= $isUrg ? ' class="urgent-row"' : '' ?>>
                                        <td><strong>#<?= e($hr['id']) ?></strong></td>
                                        <td class="text-nowrap"><?= formatDatetimeArg($hr['fecha']) ?></td>
                                        <td><?= e($hr['equipo']) ?></td>
                                        <td><?= e($hr['sala']) ?></td>
                                        <td><?= e($hr['tecnico_nombre'] ?: '—') ?></td>
                                        <td>
                                            <span class="status-pill <?= $npuStatusClass($hr['estado']) ?>"
                                                  style="font-size: 10px; padding: 3px 6px; width: auto; min-width: 0;">
                                                <?= e($hr['estado']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a href="reparacion_detalle.php?id=<?= e($hr['id']) ?>" class="open-link" title="Abrir ficha">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Observaciones / Comentarios -->
            <div class="dense-card">
                <div class="card-header">
                    <h6 class="text-primary"><i class="bi bi-chat-text me-1"></i>Observaciones</h6>
                </div>
                <div class="card-body">
                    <div class="observaciones-box mb-3" id="observacionesText"><?= e($rep['observaciones']) ?: '<span class="text-muted fst-italic">Sin comentarios registrados.</span>' ?></div>
                    
                    <form id="formComentario">
                        <div class="comment-composer">
                            <textarea id="nuevoComentario" class="form-control form-control-compact autosize flex-grow-1"
                                      placeholder="Agregar una nota técnica o avance... (Ctrl+Enter para enviar)"
                                      rows="1" <?= $can_manage_rep ? '' : 'disabled' ?>></textarea>
                            <button class="btn btn-compact btn-toolbar-primary" type="submit" id="btnComentario" <?= $can_manage_rep ? '' : 'disabled' ?>>
                                <i class="bi bi-send me-1"></i>Enviar
                            </button>
                        </div>
                    </form>
                    <?php if (!$can_manage_rep): ?>
                    <div class="helper-note">Solo administrador o técnico asignado pueden comentar.</div>
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
            <div class="aside-stack">
            <!-- Asignación Técnico -->
            <div class="dense-card">
                <div class="card-header">
                    <h6><i class="bi bi-person-gear me-1"></i>Técnico asignado</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <?php if ($rep['tecnico_nombre']): ?>
                            <div class="tech-avatar" style="background-color: <?= e(avatarColor($rep['tecnico_nombre'])) ?>;">
                                <?= e(mb_strtoupper(mb_substr($rep['tecnico_nombre'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                            </div>
                            <span class="aside-value" id="textTecnico"><?= e($rep['tecnico_nombre']) ?></span>
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
                    <?php elseif ($can_self_assign): ?>
                        <button type="button" id="btnAutoAsignar" class="btn btn-toolbar-primary btn-compact w-100 fw-bold shadow-none">
                            <i class="bi bi-person-check me-1"></i>Asignarme esta reparación
                        </button>
                        <div class="aside-note">Pasarás a ser el técnico a cargo y la reparación cambiará a "En Reparación".</div>
                    <?php else: ?>
                        <div class="aside-note">Solo administrador puede reasignar.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Estado Actual -->
            <div class="dense-card">
                <div class="card-header">
                    <h6><i class="bi bi-activity me-1"></i>Estado actual</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <span id="badgeEstado" class="status-pill w-100 justify-content-center <?= getBadgeClass($rep['estado']) ?>"><?= e($rep['estado']) ?></span>
                    </div>

                    <div>
                        <label class="form-label text-muted mb-1" style="font-size: 12px;">Modificar Estado</label>
                        <select id="selectEstado" class="form-select form-select-compact w-100" <?= $can_change_estado ? '' : 'disabled' ?>>
                            <?php foreach ($allowed_estado_options as $est): ?>
                                <option value="<?= e($est['nombre']) ?>" <?= $rep['estado'] === $est['nombre'] ? 'selected' : '' ?>><?= e($est['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$can_change_estado): ?>
                        <div class="aside-note">No tenés permisos para cambiar el estado.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Prioridad -->
            <div class="dense-card">
                <div class="card-header">
                    <h6><i class="bi bi-exclamation-diamond me-1"></i>Prioridad</h6>
                </div>
                <div class="card-body">
                    <div class="summary-panel <?= $rep['urgente'] === 'SI' ? 'is-warning' : '' ?>">
                        <div class="summary-label">Nivel de atención</div>
                        <div id="textPrioridad" class="summary-value">
                            <?php if ($rep['urgente'] === 'SI'): ?>
                                <span class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Urgente</span>
                            <?php else: ?>
                                <span>Normal</span>
                            <?php endif; ?>
                        </div>
                        <div class="summary-text">Usá esta marca para destacar casos que requieren resolución o seguimiento prioritario.</div>
                    </div>
                    <button id="btnTogglePrioridad" class="btn btn-sm btn-compact mt-3 w-100 <?= $rep['urgente'] === 'SI' ? 'btn-outline-danger' : 'btn-toolbar-neutral' ?>" <?= $can_change_prioridad ? '' : 'disabled' ?>>
                        <?= $rep['urgente'] === 'SI' ? 'Quitar Urgencia' : 'Hacer Urgente' ?>
                    </button>
                    <?php if (!$can_change_prioridad): ?>
                    <div class="aside-note">Solo administrador puede cambiar prioridad.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Devolver Reparación -->
            <div class="dense-card">
                <div class="card-header">
                    <h6><i class="bi bi-arrow-counterclockwise me-1"></i>Devolución</h6>
                </div>
                <div class="card-body">
                    <div class="summary-panel is-warning">
                        <div class="summary-label">Acción administrativa</div>
                        <div class="summary-text">Quita el técnico asignado y retrocede la reparación a "Pendiente de Revisión".</div>
                    </div>
                    <button id="btnDevolverReparacion" class="btn btn-warning btn-compact w-100 fw-bold shadow-none mt-3" <?= $can_devolver_rep ? '' : 'disabled' ?>>
                        <i class="bi bi-arrow-return-left me-1"></i>Devolver Reparación
                    </button>
                    <?php if (!$can_devolver_rep): ?>
                    <div class="aside-note">Sin permisos para devolver.</div>
                    <?php endif; ?>
                </div>
            </div>

            </div>
        </div>
    </div>
</div>

<script>
const reparacionId = <?= $rep['id'] ?>;
const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '<?= generateCsrfToken() ?>';
// Map { "ESTADO_DESTINO": true|false } generated server-side from
// estadoTransitionRequiresComment() — same source of truth as the API.
const ESTADO_REQUIERE_COMENTARIO = <?= json_encode($estados_requieren_comentario, JSON_UNESCAPED_UNICODE) ?>;
const REP_TIENE_TECNICO = <?= !empty($rep['tecnico_id']) ? 'true' : 'false' ?>;
const SESSION_TECNICO_ID = <?= json_encode($_SESSION['tecnico_id'] ?? null) ?>;

// --- Page loading overlay used during any fetch round-trip ---
const pageLoading = (() => {
    const el = document.createElement('div');
    el.className = 'page-loading-overlay';
    el.innerHTML = '<div class="spinner-border" role="status"><span class="visually-hidden">Cargando...</span></div>';
    document.body.appendChild(el);
    return {
        show: () => el.classList.add('is-active'),
        hide: () => el.classList.remove('is-active'),
    };
})();

// --- Autosize: grow a textarea with its content (capped by CSS max-height) ---
function autosize(el) {
    if (!el) return;
    const adjust = () => {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 220) + 'px';
    };
    el.addEventListener('input', adjust);
    requestAnimationFrame(adjust);
}
document.querySelectorAll('textarea.autosize').forEach(autosize);

// --- Reload preserving scroll position across the round-trip ---
const SCROLL_KEY = 'rd_scroll_' + reparacionId;
function reloadPreservingScroll() {
    sessionStorage.setItem(SCROLL_KEY, window.scrollY);
    location.reload();
}
window.addEventListener('DOMContentLoaded', () => {
    const y = sessionStorage.getItem(SCROLL_KEY);
    if (y !== null) {
        sessionStorage.removeItem(SCROLL_KEY);
        requestAnimationFrame(() => window.scrollTo({ top: parseInt(y, 10), behavior: 'instant' in window ? 'instant' : 'auto' }));
    }
});

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
    const inputComentario = document.getElementById('nuevoComentario');
    formComentario.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btnComentario');
        const val = inputComentario.value.trim();
        if (!val || btn.disabled) return;

        btn.disabled = true;
        pageLoading.show();
        try {
            await fetchApi(`../api/reparacion_comentario.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: reparacionId, comentario: val })
            });
            inputComentario.value = '';
            reloadPreservingScroll();
        } catch (err) {
            pageLoading.hide();
            btn.disabled = false;
            showToast('Error al guardar comentario', 'danger');
        }
    });

    // Ctrl/Cmd+Enter inside the textarea submits the form
    inputComentario?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            formComentario.requestSubmit();
        }
    });
}

const selectEstado = document.getElementById('selectEstado');
if (selectEstado && !selectEstado.disabled) {
    // Snapshot the current value so we can roll back when validation fails
    // or the request errors out — avoids a full reload that would kill the toast.
    const estadoOriginal = selectEstado.value;

    selectEstado.addEventListener('change', async (e) => {
        const newEstado = e.target.value;
        let comentario = '';

        // Block transitions that need an owner if the repair has no technician yet
        if (newEstado.toUpperCase() !== 'PEND. DE REVISION' && !REP_TIENE_TECNICO) {
            showToast('Asigná un técnico antes de cambiar a este estado.', 'warning');
            selectEstado.value = estadoOriginal;
            return;
        }

        if (ESTADO_REQUIERE_COMENTARIO[newEstado]) {
            comentario = await promptModal({
                title: 'Cambio de estado',
                message: `El estado va a cambiar a "${newEstado}". Detallá el motivo o el resultado para dejar constancia en el historial.`,
                placeholder: 'Comentario final…',
                confirmLabel: 'Confirmar cambio',
                confirmClass: 'btn-primary',
                icon: 'bi-arrow-repeat',
            });
            if (comentario === null) {
                selectEstado.value = estadoOriginal;
                return;
            }
        }

        pageLoading.show();
        try {
            await fetchApi(`../api/reparacion_estado.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: reparacionId, estado: newEstado, comentario: comentario })
            });
            reloadPreservingScroll();
        } catch (err) {
            pageLoading.hide();
            selectEstado.value = estadoOriginal;
            showToast(err?.message || 'Error al cambiar estado', 'danger');
        }
    });
}

const selectTecnico = document.getElementById('selectTecnico');
if (selectTecnico) {
    selectTecnico.addEventListener('change', async (e) => {
        const newTecnicoId = e.target.value;
        pageLoading.show();
        try {
            await fetchApi(`../api/reparacion_tecnico.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: reparacionId, tecnico_id: newTecnicoId })
            });
            reloadPreservingScroll();
        } catch (err) {
            pageLoading.hide();
            showToast('Error al cambiar técnico', 'danger');
            reloadPreservingScroll();
        }
    });
}

const btnAutoAsignar = document.getElementById('btnAutoAsignar');
if (btnAutoAsignar && SESSION_TECNICO_ID) {
    btnAutoAsignar.addEventListener('click', async () => {
        btnAutoAsignar.disabled = true;
        pageLoading.show();
        try {
            await fetchApi(`../api/reparacion_tecnico.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: reparacionId, tecnico_id: SESSION_TECNICO_ID })
            });
            reloadPreservingScroll();
        } catch (err) {
            pageLoading.hide();
            btnAutoAsignar.disabled = false;
            showToast(err?.message || 'Error al asignarte la reparación', 'danger');
        }
    });
}

const btnTogglePrioridad = document.getElementById('btnTogglePrioridad');
if (btnTogglePrioridad && !btnTogglePrioridad.disabled) {
    btnTogglePrioridad.addEventListener('click', async () => {
        const currentUrgente = '<?= $rep['urgente'] ?>';
        const newUrgente = currentUrgente === 'SI' ? 'NO' : 'SI';
        btnTogglePrioridad.disabled = true;
        pageLoading.show();
        try {
            await fetchApi(`../api/reparacion_prioridad.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: reparacionId, urgente: newUrgente })
            });
            reloadPreservingScroll();
        } catch (err) {
            pageLoading.hide();
            btnTogglePrioridad.disabled = false;
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

        btnDevolverReparacion.disabled = true;
        pageLoading.show();
        try {
            await fetchApi(`../api/reparacion_devolver.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: reparacionId, comentario: motivo })
            });
            reloadPreservingScroll();
        } catch (err) {
            pageLoading.hide();
            btnDevolverReparacion.disabled = false;
            showToast('Error al devolver la reparación', 'danger');
        }
    });
}
</script>

<!-- Generic prompt modal: replaces the browser's prompt() with a textarea -->
<div class="modal fade detail-modal" id="actionModal" tabindex="-1" aria-hidden="true">
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
