<?php
// public/reparacion_editar.php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../app/CatalogoModel.php';
require_once __DIR__ . '/../app/ReparacionModel.php';

requireRole('admin');

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
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="h3"><i class="bi bi-pencil-square"></i> Editar Reparación #<?= $rep['id'] ?></h2>
    <a href="reparacion_detalle.php?id=<?= $rep['id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Cancelar</a>
</div>

<div class="card shadow-sm max-w-800 mx-auto">
    <div class="card-body">
        <form method="POST" action="reparacion_editar.php?id=<?= $rep['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Fecha y Hora</label>
                    <input type="text" name="fecha" class="form-control" value="<?= date('Y-m-d H:i', strtotime($rep['fecha'])) ?>" required>
                    <div class="form-text">Formato: YYYY-MM-DD HH:MM</div>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check form-switch fs-5 mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="urgente" name="urgente" value="SI" <?= $rep['urgente'] === 'SI' ? 'checked' : '' ?>>
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
                            <option value="<?= e($s['nombre']) ?>" <?= $rep['sala'] === $s['nombre'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
                        <?php endforeach; ?>
                        <?php if(!in_array($rep['sala'], array_column($salas, 'nombre'))): ?>
                             <option value="<?= e($rep['sala']) ?>" selected><?= e($rep['sala']) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Equipo *</label>
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
                <div class="col-md-4">
                    <label class="form-label fw-bold">Familia</label>
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
            </div>

            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">NPU / Patrimonio</label>
                    <input type="text" name="npu" id="npu_input" class="form-control" value="<?= e($rep['npu']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">UID (MAC/IP/Etc)</label>
                    <input type="text" name="uid" class="form-control" value="<?= e($rep['uid']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Parte/Modelo</label>
                    <input type="text" name="parte" class="form-control" value="<?= e($rep['parte']) ?>">
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <label class="form-label">Técnico Asignado</label>
                    <select name="tecnico_id" class="form-select tom-select">
                        <option value="">-- Sin Asignar --</option>
                        <?php foreach ($tecnicos as $t): ?>
                            <option value="<?= e($t['id']) ?>" <?= $rep['tecnico_id'] == $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> Nota: Modifique las observaciones y el estado desde la vista de <a href="reparacion_detalle.php?id=<?= $rep['id'] ?>">Detalle</a> para mantener el historial.
            </div>

            <hr>
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-save"></i> Guardar Cambios</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
