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
        max-width: 960px;
        margin: 0 auto;
        /* No overflow: hidden — Tom Select's dropdown extends below the card */
    }

    .dense-card .card-body {
        padding: 1.1rem 1.4rem 1.2rem;
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        column-gap: 1.5rem;
        row-gap: 0.75rem;
        align-items: start;
    }

    .form-grid .col-left,
    .form-grid .col-right {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
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
    }

    .obs-block textarea {
        flex: 1 1 auto;
        min-height: 140px;
    }

    @media (max-width: 767.98px) {
        .form-grid {
            grid-template-columns: 1fr;
        }
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

    .textarea-compact {
        font-size: 14px;
        border-radius: 6px;
        border: 1px solid #d1d5db;
        padding: 0.5rem 0.75rem;
        background-color: var(--bg-light);
        min-height: 90px;
        max-height: 150px;
        resize: vertical;
    }

    .textarea-compact:focus {
        background-color: #fff;
        border-color: var(--primary-blue);
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
        outline: none;
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

    @media (max-width: 767.98px) {
        .date-field-block {
            width: 100%;
            flex: 0 0 100%;
        }
        .switch-block {
            margin-top: 0;
        }
    }

    /* Override TomSelect to match compact style */
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
    /* Dropdown panel: roomier with single-line items + tooltip for long names */
    .ts-dropdown {
        border-radius: 6px !important;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12) !important;
        border-color: #cbd5e1 !important;
        min-width: 100% !important;
        width: auto !important;
        max-width: 480px !important;
    }
    .ts-dropdown .ts-dropdown-content {
        max-height: 420px !important;
    }
    .ts-dropdown .option {
        padding: 0.45rem 0.75rem !important;
        font-size: 13px !important;
        line-height: 1.3 !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        border-bottom: 1px solid #f1f5f9;
    }
    .ts-dropdown .option.active,
    .ts-dropdown .option:hover {
        background: #eff6ff !important;
        color: #1e293b !important;
    }
    .ts-dropdown .option.selected {
        background: #dbeafe !important;
        color: #1d4ed8 !important;
        font-weight: 600;
    }
    .ts-dropdown .no-results {
        padding: 0.6rem 0.75rem !important;
        color: #94a3b8 !important;
        font-style: italic;
    }
</style>

<div class="form-shell">
    <div class="d-flex justify-content-between align-items-center mb-3" style="max-width: 900px; margin: 0 auto;">
        <h2 class="page-title"><i class="bi bi-plus-circle text-primary me-2" style="font-size: 22px;"></i>Nuevo Ingreso</h2>
        <a href="index.php" class="btn btn-light btn-compact border text-secondary"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    </div>

    <div class="dense-card">
        <div class="card-body">
            <form method="POST" action="reparacion_nueva.php" class="d-flex flex-column gap-3">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                
                <div class="form-grid">
                    <div class="col-left">
                        <div class="meta-row">
                            <div class="date-field-block">
                                <label class="form-label-compact">Fecha y Hora</label>
                                <input type="text" name="fecha" id="fecha_input" class="form-control form-control-compact bg-light"
                                       value="<?= date('Y-m-d H:i') ?>" readonly>
                            </div>
                            <div class="switch-block">
                                <label class="switch-inline form-switch urgent-pill">
                                    <input class="form-check-input m-0" type="checkbox" role="switch" id="urgente" name="urgente" value="SI">
                                    <span style="padding-top: 2px;">URGENTE</span>
                                </label>
                            </div>
                        </div>
                        <?php if ($user_role === 'admin'): ?>
                        <label class="switch-inline form-switch" style="margin-top: -4px;">
                            <input class="form-check-input m-0" type="checkbox" role="switch" id="unlock_fecha">
                            <span class="text-muted" style="font-size: 12px; padding-top: 2px;">Editar fecha manualmente</span>
                        </label>
                        <?php endif; ?>

                        <div>
                            <label class="form-label-compact">Sala <span class="text-danger">*</span></label>
                            <select name="sala" class="form-select tom-select" data-allow-new required>
                                <option value="">Seleccione o escriba...</option>
                                <?php foreach ($salas as $s): ?>
                                    <option value="<?= e($s['nombre']) ?>"><?= e($s['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label-compact">Familia</label>
                                <select name="familia" id="select-familia" class="form-select tom-select" data-allow-new>
                                    <option value="">Seleccione o escriba...</option>
                                    <option value="__SIN_FAMILIA__">(Sin familia)</option>
                                    <?php foreach ($familias as $f): ?>
                                        <option value="<?= e($f['nombre']) ?>"><?= e($f['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label-compact">Equipo <span class="text-danger">*</span></label>
                                <select name="equipo" id="select-equipo" class="form-select tom-select" data-allow-new required>
                                    <option value="">Seleccione o escriba...</option>
                                    <?php foreach ($equipos as $eq): ?>
                                        <option value="<?= e($eq['nombre']) ?>" data-familia="<?= e($eq['familia'] ?? '') ?>"><?= e($eq['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label-compact">NPU</label>
                                <input type="text" name="npu" id="npu_input" class="form-control form-control-compact" placeholder="Ej: 123456">
                                <div id="npu_warning" class="form-text-compact text-danger d-none mt-1"><i class="bi bi-exclamation-triangle"></i> NPU con registros previos.</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label-compact">UID</label>
                                <input type="text" name="uid" class="form-control form-control-compact" placeholder="Identificador único">
                            </div>
                        </div>
                    </div>

                    <div class="col-right">
                        <?php if ($user_role === 'admin'): ?>
                        <div>
                            <label class="form-label-compact">Asignar Técnico (Opcional)</label>
                            <select name="tecnico_id" class="form-select tom-select">
                                <option value="">Dejar PEND. DE REVISION</option>
                                <?php foreach ($tecnicos as $t): ?>
                                    <option value="<?= e($t['id']) ?>"><?= e($t['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="obs-block">
                            <label class="form-label-compact">Falla Reportada / Observaciones Iniciales</label>
                            <textarea name="observaciones" class="form-control textarea-compact"></textarea>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-1 pt-3 border-top">
                    <a href="index.php" class="btn btn-light btn-compact border text-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-primary btn-compact shadow-none"><i class="bi bi-save me-1"></i> Guardar Ingreso</button>
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
