<?php
// public/reparacion_nueva.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/CatalogoModel.php';
require_once __DIR__ . '/../app/ReparacionModel.php';
require_once __DIR__ . '/../app/historial.php';

requireLogin();
$user_role = $_SESSION['user_role'] ?? 'tecnico';

$salas = CatalogoModel::getAll('salas');
$equipos = CatalogoModel::getAll('equipos');
$familias = CatalogoModel::getAll('familias');
$tecnicos = CatalogoModel::getAll('tecnicos');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    
    $pdo = getDbConnection();
    
    try {
        $pdo->beginTransaction();
        
        $fecha = $_POST['fecha'] ?? getCurrentDatetime();
        if ($user_role !== 'admin') {
            $fecha = getCurrentDatetime(); // Force current for techs
        } else {
            // Ensure format Y-m-d H:i:s if provided manually, else default
             $fecha = date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $fecha)));
        }
        
        // Handle Tom Select "allow-new" by ensuring catalog entries exist
        $familiaInput = $_POST['familia'] ?? '';
        if ($familiaInput === '__SIN_FAMILIA__') {
            $familiaInput = '';
        }

        $sala_id = CatalogoModel::asegurarExiste('salas', $_POST['sala'] ?? '');
        $equipo_id = CatalogoModel::asegurarExiste('equipos', $_POST['equipo'] ?? '');
        $familia_id = CatalogoModel::asegurarExiste('familias', $familiaInput);

        $sala = $_POST['sala'] ?? '';
        $equipo = $_POST['equipo'] ?? '';
        $familia = $familiaInput;
        
        $uid = trim($_POST['uid'] ?? '');
        $npu = trim($_POST['npu'] ?? '');
        $parte = trim($_POST['parte'] ?? '');
        $urgente = isset($_POST['urgente']) && $_POST['urgente'] === 'SI' ? 'SI' : 'NO';
        
        // Tecnico assigning logic
        $tecnico_id = null;
        if ($user_role === 'tecnico') {
            $tecnico_id = $_SESSION['tecnico_id'];
        } elseif (!empty($_POST['tecnico_id'])) {
            $tecnico_id = $_POST['tecnico_id'];
        }
        
        $estado = 'PEND. DE REVISION';
        $fecha_en_reparacion = null;
        
        if ($tecnico_id) {
            $estado = 'EN REPARACION';
            $fecha_en_reparacion = $fecha;
        }

        $observaciones = trim($_POST['observaciones'] ?? '');
        $dia_semana = date('l', strtotime($fecha)); // Assuming english is ok or translate later

        $stmt = $pdo->prepare("
            INSERT INTO reparaciones (
                fecha, sala, uid, npu, parte, familia, equipo, urgente, 
                tecnico_id, estado, observaciones, dia_semana, fecha_en_reparacion
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $fecha, $sala, $uid, $npu, $parte, $familia, $equipo, $urgente,
            $tecnico_id, $estado, $observaciones, $dia_semana, $fecha_en_reparacion
        ]);
        
        $newId = $pdo->lastInsertId();
        
        registrarHistorial('INGRESO', "Se ingresó el equipo $equipo (NPU: $npu)", $newId);
        
        $pdo->commit();
        
        setFlashMessage('success', 'Reparación ingresada correctamente.');
        redirect('/reparacion_detalle.php?id=' . $newId);
            } catch (Exception $e) {
        $pdo->rollBack();
        setFlashMessage('danger', 'Error al guardar: ' . e($e->getMessage()));
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
    :root {
        --border-color: #e2e8f0;
        --bg-light: #f8fafc;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --primary-blue: #3b82f6;
        --primary-blue-hover: #2563eb;
        --accent-red: #ef4444;
        --accent-red-hover: #dc2626;
        --panel-bg: #ffffff;
        --panel-border: #e2e8f0;
        --input-border: #cbd5e1;
    }

    .form-shell {
        font-size: 13.5px;
        color: var(--text-main);
        max-width: 980px;
    }

    .page-title {
        font-size: 26px;
        font-weight: 700;
        margin: 0;
        letter-spacing: -0.02em;
        color: var(--text-main);
    }

    .btn-compact {
        height: 38px;
        padding: 0 1.25rem;
        font-size: 13.5px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .btn-compact i {
        font-size: 15px;
    }

    .btn-primary-gradient {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        border: none;
        color: #ffffff;
    }

    .btn-primary-gradient:hover {
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #ffffff;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
    }

    .btn-primary-gradient:active {
        transform: translateY(0);
    }

    .dense-card {
        background: #ffffff;
        border-radius: 14px;
        border: 1px solid var(--border-color);
        box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.04), 0 8px 10px -6px rgba(15, 23, 42, 0.03);
        max-width: 900px;
        margin: 0;
        position: relative;
        overflow: visible; /* Tom Select's dropdown extends below card */
    }

    /* Top decorative gradient bar */
    .dense-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #3b82f6, #6366f1);
        z-index: 10;
        border-top-left-radius: 14px;
        border-top-right-radius: 14px;
    }

    .dense-card .card-body {
        padding: 1.5rem 1.6rem 1.6rem;
    }

    .form-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.05fr) minmax(280px, 0.95fr);
        column-gap: 1.5rem;
        row-gap: 1.2rem;
        align-items: start;
    }

    /* Form Section Panel */
    .form-section-panel {
        background: var(--panel-bg);
        border: 1px solid var(--panel-border);
        border-radius: 10px;
        padding: 1.25rem;
        transition: border-color 0.2s, box-shadow 0.2s;
        display: flex;
        flex-direction: column;
        gap: 0.95rem;
    }

    .form-section-panel:hover {
        border-color: #cbd5e1;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.02);
    }

    .form-section-title {
        font-size: 11.5px;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--text-muted);
        margin: 0 0 0.2rem 0;
        display: flex;
        align-items: center;
        gap: 6px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 0.4rem;
    }

    .form-section-title i {
        font-size: 13.5px;
    }

    .form-grid .col-left,
    .form-grid .col-right {
        display: flex;
        flex-direction: column;
        gap: 1.2rem;
    }

    .form-grid .col-right {
        height: 100%;
    }

    .meta-row {
        display: flex;
        align-items: flex-end;
        gap: 12px;
        flex-wrap: wrap;
    }

    .meta-row .date-field-block {
        flex: 1 1 auto;
        min-width: 180px;
    }

    .obs-block {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        height: 100%;
    }

    .obs-block textarea {
        flex: 1 1 auto;
        min-height: 160px;
    }

    .new-repair-header {
        max-width: 900px;
        margin: 0 0 1rem;
    }

    .new-repair-actions {
        background: #f8fafc;
        margin: 0.5rem -1.6rem -1.6rem;
        padding: 1rem 1.6rem;
        border-top: 1px solid var(--border-color);
        border-bottom-left-radius: 14px;
        border-bottom-right-radius: 14px;
    }

    @media (max-width: 767.98px) {
        .form-grid {
            grid-template-columns: 1fr;
        }
    }

    .form-label-compact {
        font-size: 12px;
        font-weight: 600;
        color: var(--text-main);
        margin-bottom: 0.35rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .form-control-compact, .form-select-compact {
        height: 38px;
        font-size: 13.5px;
        border-radius: 8px;
        border: 1px solid var(--input-border);
        padding: 0.45rem 0.75rem;
        background-color: var(--bg-light);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        color: var(--text-main);
    }

    .form-control-compact:focus, .form-select-compact:focus {
        background-color: #fff;
        border-color: var(--primary-blue);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        outline: none;
    }

    .textarea-compact {
        font-size: 13.5px;
        border-radius: 8px;
        border: 1px solid var(--input-border);
        padding: 0.55rem 0.75rem;
        background-color: var(--bg-light);
        min-height: 120px;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        color: var(--text-main);
        resize: vertical;
    }

    .textarea-compact:focus {
        background-color: #fff;
        border-color: var(--primary-blue);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        outline: none;
    }

    .form-text-compact {
        font-size: 11.5px;
        color: #94a3b8;
        margin-top: 0.25rem;
    }

    .date-field-block {
        width: 200px;
        flex: 1 1 auto;
    }

    .switch-block {
        display: flex;
        align-items: center;
        height: 38px;
    }

    /* Custom Toggling Urgent Button */
    .urgent-pill {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 0 16px;
        border: 1px dashed var(--accent-red);
        border-radius: 8px;
        background: #fff;
        color: var(--accent-red);
        font-weight: 700;
        font-size: 12px;
        height: 38px;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        user-select: none;
        position: relative;
    }

    .urgent-pill input[type="checkbox"] {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
        pointer-events: none;
    }

    .urgent-pill:hover {
        background: #fef2f2;
    }

    .urgent-pill.is-active {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        border: 1px solid #ef4444;
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        transform: translateY(-1px);
    }

    .unlock-date-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: var(--text-muted);
        cursor: pointer;
        user-select: none;
        transition: color 0.2s;
        margin-top: 4px;
    }

    .unlock-date-label:hover {
        color: var(--text-main);
    }

    .unlock-date-label input[type="checkbox"] {
        width: 14px;
        height: 14px;
        border-radius: 4px;
        border: 1px solid var(--input-border);
        cursor: pointer;
    }

    @media (max-width: 767.98px) {
        .date-field-block {
            width: 100%;
            flex: 0 0 100%;
        }
        .switch-block {
            margin-top: 0.5rem;
            width: 100%;
        }
        .urgent-pill {
            width: 100%;
        }
    }

    /* Complete override of TomSelect to look professional and premium */
    .ts-control {
        height: 38px !important;
        border-radius: 8px !important;
        border: 1px solid var(--input-border) !important;
        background-color: var(--bg-light) !important;
        font-size: 13.5px !important;
        padding: 0.45rem 0.75rem !important;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
        box-shadow: none !important;
    }
    
    .ts-control:focus, .ts-control.focus {
        background-color: #ffffff !important;
        border-color: var(--primary-blue) !important;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12) !important;
    }

    .ts-wrapper.single .ts-control {
        padding-right: 2rem !important;
    }

    .ts-wrapper.single .ts-control::after {
        border-color: var(--text-muted) transparent transparent transparent !important;
        border-width: 5px 4px 0 4px !important;
        right: 12px !important;
    }
    
    .ts-wrapper.single.dropdown-active .ts-control::after {
        border-color: transparent transparent var(--text-muted) transparent !important;
        border-width: 0 4px 5px 4px !important;
    }

    .ts-dropdown {
        border-radius: 8px !important;
        box-shadow: 0 10px 30px -10px rgba(15, 23, 42, 0.12), 0 8px 12px -8px rgba(15, 23, 42, 0.08) !important;
        border: 1px solid var(--border-color) !important;
        background: #ffffff !important;
        padding: 4px !important;
        margin-top: 4px !important;
        z-index: 1000 !important;
    }

    .ts-dropdown .option {
        padding: 0.5rem 0.75rem !important;
        font-size: 13px !important;
        border-radius: 6px !important;
        margin-bottom: 2px !important;
        transition: all 0.15s ease !important;
        cursor: pointer !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ts-dropdown .option:last-child {
        margin-bottom: 0 !important;
    }

    .ts-dropdown .option.active,
    .ts-dropdown .option:hover {
        background-color: #eff6ff !important;
        color: #1e3a8a !important;
    }

    .ts-dropdown .option.selected {
        background-color: #dbeafe !important;
        color: #1d4ed8 !important;
        font-weight: 600 !important;
    }

    .ts-dropdown .create {
        padding: 0.5rem 0.75rem !important;
        font-size: 13px !important;
        color: var(--primary-blue) !important;
        font-weight: 500 !important;
    }
</style>

<div class="form-shell admin-shell">
    <div class="new-repair-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <div>
            <h2 class="page-title"><i class="bi bi-plus-circle-fill text-primary me-2" style="font-size: 24px;"></i>Nueva Reparación</h2>
            <p class="admin-page-subtitle mb-0">Carga rápida de ingreso de equipos, identificadores y estado inicial.</p>
        </div>
        <a href="index.php" class="btn btn-light btn-compact border text-secondary shadow-sm"><i class="bi bi-arrow-left me-1.5"></i>Volver</a>
    </div>

    <div class="dense-card">
        <div class="card-body">
            <form method="POST" action="reparacion_nueva.php" class="d-flex flex-column gap-3">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                
                <div class="form-grid">
                    <div class="col-left">
                        <!-- PANEL 1: REGISTRO E INGRESO -->
                        <div class="form-section-panel">
                            <h6 class="form-section-title"><i class="bi bi-clock-history text-primary"></i> Datos del Ingreso</h6>
                            <div class="meta-row">
                                <div class="date-field-block">
                                    <label class="form-label-compact"><i class="bi bi-calendar-event text-secondary"></i> Fecha y Hora</label>
                                    <input type="text" name="fecha" id="fecha_input" class="form-control form-control-compact bg-light"
                                           value="<?= date('Y-m-d H:i') ?>" readonly>
                                </div>
                                <div class="switch-block">
                                    <label class="urgent-pill" style="margin: 0;">
                                        <input type="checkbox" id="urgente" name="urgente" value="SI">
                                        <i class="bi bi-exclamation-circle urgent-icon me-1"></i>
                                        <span>URGENTE</span>
                                    </label>
                                </div>
                            </div>
                            <?php if ($user_role === 'admin'): ?>
                            <div style="margin-top: -4px;">
                                <label class="unlock-date-label">
                                    <input class="form-check-input m-0" type="checkbox" id="unlock_fecha">
                                    <span>Editar fecha y hora manualmente</span>
                                </label>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- PANEL 2: UBICACIÓN Y EQUIPO -->
                        <div class="form-section-panel">
                            <h6 class="form-section-title"><i class="bi bi-box-seam text-primary"></i> Ubicación & Clasificación</h6>
                            <div>
                                <label class="form-label-compact"><i class="bi bi-door-closed text-secondary"></i> Sala <span class="text-danger">*</span></label>
                                <select name="sala" class="form-select tom-select" data-allow-new required>
                                    <option value="">Seleccione o escriba...</option>
                                    <?php foreach ($salas as $s): ?>
                                        <option value="<?= e($s['nombre']) ?>"><?= e($s['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label-compact"><i class="bi bi-tags text-secondary"></i> Familia</label>
                                    <select name="familia" id="select-familia" class="form-select tom-select" data-allow-new>
                                        <option value="">Seleccione o escriba...</option>
                                        <option value="__SIN_FAMILIA__">(Sin familia)</option>
                                        <?php foreach ($familias as $f): ?>
                                            <option value="<?= e($f['nombre']) ?>"><?= e($f['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label-compact"><i class="bi bi-laptop text-secondary"></i> Equipo <span class="text-danger">*</span></label>
                                    <select name="equipo" id="select-equipo" class="form-select tom-select" data-allow-new required>
                                        <option value="">Seleccione o escriba...</option>
                                        <?php foreach ($equipos as $eq): ?>
                                            <option value="<?= e($eq['nombre']) ?>" data-familia="<?= e($eq['familia'] ?? '') ?>"><?= e($eq['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- PANEL 3: IDENTIFICACIÓN TÉCNICA -->
                        <div class="form-section-panel">
                            <h6 class="form-section-title"><i class="bi bi-fingerprint text-primary"></i> Identificación Técnica</h6>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label-compact"><i class="bi bi-hash text-secondary"></i> NPU</label>
                                    <input type="text" name="npu" id="npu_input" class="form-control form-control-compact" placeholder="Ej: 123456">
                                    <div id="npu_warning" class="form-text-compact text-danger d-none mt-1.5"><i class="bi bi-exclamation-triangle-fill"></i> NPU con registros previos.</div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label-compact"><i class="bi bi-qr-code text-secondary"></i> UID</label>
                                    <input type="text" name="uid" class="form-control form-control-compact" placeholder="Identificador único">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-right">
                        <!-- PANEL 4: ASIGNACIÓN Y FALLA -->
                        <div class="form-section-panel h-100">
                            <h6 class="form-section-title"><i class="bi bi-chat-left-text text-primary"></i> Asignación & Detalles</h6>
                            <?php if ($user_role === 'admin'): ?>
                            <div>
                                <label class="form-label-compact"><i class="bi bi-person-badge text-secondary"></i> Asignar Técnico (Opcional)</label>
                                <select name="tecnico_id" class="form-select tom-select">
                                    <option value="">Dejar PEND. DE REVISION</option>
                                    <?php foreach ($tecnicos as $t): ?>
                                        <option value="<?= e($t['id']) ?>"><?= e($t['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="obs-block">
                                <label class="form-label-compact"><i class="bi bi-tools text-secondary"></i> Falla Reportada / Observaciones Iniciales</label>
                                <textarea name="observaciones" class="form-control textarea-compact" placeholder="Detalla los síntomas de la falla o el estado en que ingresa el equipo..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="new-repair-actions d-flex justify-content-end gap-2">
                    <a href="index.php" class="btn btn-light btn-compact border text-secondary shadow-sm">Cancelar</a>
                    <button type="submit" class="btn btn-primary-gradient btn-compact shadow-sm"><i class="bi bi-save me-1.5"></i>Guardar Ingreso</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const npuInput = document.getElementById('npu_input');
    const npuWarning = document.getElementById('npu_warning');
    
    if (npuInput) {
        npuInput.addEventListener('blur', async function() {
            const val = this.value.trim();
            if (val.length > 2) {
                try {
                    const res = await fetchApi(`../api/check_npu.php?npu=${encodeURIComponent(val)}`);
                    if (res.exists) {
                        npuWarning.classList.remove('d-none');
                    } else {
                        npuWarning.classList.add('d-none');
                    }
                } catch (e) {
                    console.error("Error checking NPU", e);
                }
            } else {
                npuWarning.classList.add('d-none');
            }
        });
    }

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

    // Equipo ↔ Familia: bidirectional sync.
    // - Pick an Equipo → auto-fill its Familia.
    // - Pick a Familia → filter the Equipo dropdown to only that familia
    //   (empty familia = show all). Equipos without familia stay visible
    //   only when no familia filter is active.
    const selectEquipo = document.getElementById('select-equipo');
    const selectFamilia = document.getElementById('select-familia');

    // Snapshot every equipo option once so we can re-populate after filtering.
    const allEquipos = selectEquipo
        ? Array.from(selectEquipo.querySelectorAll('option'))
            .filter(o => o.value !== '')
            .map(o => ({ value: o.value, text: o.textContent, familia: o.dataset.familia || '' }))
        : [];

    function applyFamiliaFilter(familia) {
        if (!selectEquipo || !selectEquipo.tomselect) return;
        const ts = selectEquipo.tomselect;
        const currentValue = ts.getValue();
        let filtered;
        if (!familia) {
            filtered = allEquipos;
        } else if (familia === '__SIN_FAMILIA__') {
            filtered = allEquipos.filter(e => !e.familia);
        } else {
            filtered = allEquipos.filter(e => e.familia === familia);
        }

        ts.clearOptions();
        filtered.forEach(e => ts.addOption({ value: e.value, text: e.text, familia: e.familia }));
        ts.refreshOptions(false);

        // If the currently selected equipo is no longer in the filtered list, clear it.
        if (currentValue && !filtered.some(e => e.value === currentValue)) {
            ts.clear(true);
        }
    }

    if (selectEquipo && selectFamilia) {
        selectEquipo.addEventListener('change', () => {
            const val = selectEquipo.value;
            const match = allEquipos.find(e => e.value === val);
            const familia = match ? match.familia : '';
            if (!familia) return;
            if (selectFamilia.tomselect) {
                if (!selectFamilia.tomselect.options[familia]) {
                    selectFamilia.tomselect.addOption({ value: familia, text: familia });
                }
                // Avoid recursion: only set if different.
                if (selectFamilia.tomselect.getValue() !== familia) {
                    selectFamilia.tomselect.setValue(familia, true); // silent
                    applyFamiliaFilter(familia);
                }
            } else {
                selectFamilia.value = familia;
            }
        });

        selectFamilia.addEventListener('change', () => {
            applyFamiliaFilter(selectFamilia.value || '');
        });
    }

    const urgentCheckbox = document.getElementById('urgente');
    if (urgentCheckbox) {
        const urgentPill = urgentCheckbox.closest('.urgent-pill');
        
        function updateUrgentState() {
            const icon = urgentPill.querySelector('.urgent-icon');
            if (urgentCheckbox.checked) {
                urgentPill.classList.add('is-active');
                if (icon) {
                    icon.classList.remove('bi-exclamation-circle');
                    icon.classList.add('bi-fire');
                }
            } else {
                urgentPill.classList.remove('is-active');
                if (icon) {
                    icon.classList.remove('bi-fire');
                    icon.classList.add('bi-exclamation-circle');
                }
            }
        }
        
        urgentCheckbox.addEventListener('change', updateUrgentState);
        updateUrgentState();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
