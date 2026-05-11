<?php
// public/reparacion_nueva.php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../app/CatalogoModel.php';
require_once __DIR__ . '/../app/ReparacionModel.php';

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
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="h3"><i class="bi bi-plus-circle"></i> Nuevo Ingreso</h2>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver</a>
</div>

<div class="card shadow-sm max-w-800 mx-auto">
    <div class="card-body">
        <form method="POST" action="reparacion_nueva.php">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Fecha y Hora</label>
                    <input type="text" name="fecha" class="form-control bg-light" 
                           value="<?= date('Y-m-d H:i') ?>" 
                           <?= $user_role !== 'admin' ? 'readonly' : '' ?>>
                    <?php if ($user_role === 'admin'): ?>
                    <div class="form-text">Formato: YYYY-MM-DD HH:MM</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check form-switch fs-5 mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="urgente" name="urgente" value="SI">
                        <label class="form-check-label text-danger fw-bold" for="urgente">Marcar como URGENTE</label>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Sala *</label>
                    <select name="sala" class="form-select tom-select" data-allow-new required>
                        <option value="">Seleccione o escriba...</option>
                        <?php foreach ($salas as $s): ?>
                            <option value="<?= e($s['nombre']) ?>"><?= e($s['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Equipo *</label>
                    <select name="equipo" class="form-select tom-select" data-allow-new required>
                        <option value="">Seleccione o escriba...</option>
                        <?php foreach ($equipos as $eq): ?>
                            <option value="<?= e($eq['nombre']) ?>"><?= e($eq['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Familia</label>
                    <select name="familia" class="form-select tom-select" data-allow-new>
                        <option value="">Seleccione o escriba...</option>
                        <?php foreach ($familias as $f): ?>
                            <option value="<?= e($f['nombre']) ?>"><?= e($f['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">NPU / Patrimonio</label>
                    <input type="text" name="npu" id="npu_input" class="form-control" placeholder="Ej: 123456">
                    <div id="npu_warning" class="form-text text-danger d-none"><i class="bi bi-exclamation-triangle"></i> Este NPU ya tiene registros previos.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">UID (MAC/IP/Etc)</label>
                    <input type="text" name="uid" class="form-control" placeholder="Identificador único">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Parte/Modelo</label>
                    <input type="text" name="parte" class="form-control" placeholder="Ej: Fuente 500W">
                </div>
            </div>

            <?php if ($user_role === 'admin'): ?>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Asignar a Técnico (Opcional)</label>
                    <select name="tecnico_id" class="form-select tom-select">
                        <option value="">Dejar PEND. DE REVISION</option>
                        <?php foreach ($tecnicos as $t): ?>
                            <option value="<?= e($t['id']) ?>"><?= e($t['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <div class="mb-4">
                <label class="form-label">Falla Reportada / Observaciones Iniciales</label>
                <textarea name="observaciones" class="form-control" rows="3"></textarea>
            </div>

            <hr>
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-save"></i> Guardar Ingreso</button>
        </form>
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
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
