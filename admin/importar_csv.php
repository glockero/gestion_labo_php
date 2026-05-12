<?php
// admin/importar_csv.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/historial.php';
require_once __DIR__ . '/../app/ImportService.php';

requireRole('admin');

$defaultFiles = [
    'familias' => dirname(__DIR__) . '/familias.csv',
    'equipos' => dirname(__DIR__) . '/equipos.csv',
    'reparaciones' => dirname(__DIR__) . '/reparaciones.csv',
];
$estadoMappings = ImportService::getEstadoMappings();

$importResult = null;
$importError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrf();

    $action = $_POST['action'];
    $filePath = trim((string)($_POST['file_path'] ?? ''));

    try {
        if ($action === 'import_familias') {
            $importResult = ImportService::importFamiliasCsv($filePath);
            registrarHistorial('IMPORTAR_FAMILIAS', "Se importó familias.csv desde $filePath.");
        } elseif ($action === 'preview_familias') {
            $importResult = ImportService::importFamiliasCsv($filePath, true);
        } elseif ($action === 'import_equipos') {
            $importResult = ImportService::importEquiposCsv($filePath);
            registrarHistorial('IMPORTAR_EQUIPOS', "Se importó equipos.csv desde $filePath.");
        } elseif ($action === 'preview_equipos') {
            $importResult = ImportService::importEquiposCsv($filePath, true);
        } elseif ($action === 'import_reparaciones') {
            $importResult = ImportService::importReparacionesCsv($filePath);
            registrarHistorial('IMPORTAR_REPARACIONES', "Se importó reparaciones.csv desde $filePath.");
        } elseif ($action === 'preview_reparaciones') {
            $importResult = ImportService::importReparacionesCsv($filePath, true);
        } elseif ($action === 'reset_reparaciones') {
            $importResult = ImportService::resetReparaciones();
            registrarHistorial('RESET_REPARACIONES', 'Se eliminaron todas las reparaciones e historial asociado por FK.');
        }
    } catch (Throwable $e) {
        $importError = $e->getMessage();
    }
}

require_once __DIR__ . '/../public/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="h3"><i class="bi bi-file-earmark-arrow-up"></i> Importar CSV</h2>
    <a href="configuracion.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver</a>
</div>

<?php if ($importError): ?>
<div class="alert alert-danger">
    <strong>Error de importación:</strong> <?= e($importError) ?>
</div>
<?php endif; ?>

<?php if ($importResult): ?>
<div class="alert alert-success">
    <strong><?= ($importResult['mode'] ?? '') === 'preview' ? 'Simulación completada.' : 'Operación completada.' ?></strong>
    <div class="mt-2">
        <?php foreach ($importResult as $key => $value): ?>
            <div><strong><?= e(ucfirst($key)) ?>:</strong> <?= e((string)$value) ?></div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-4 border-info">
    <div class="card-body">
        <h5 class="card-title"><i class="bi bi-diagram-3"></i> Mapeo de Estados del CSV</h5>
        <p class="text-muted small mb-3">Las importaciones de reparaciones normalizan algunos estados del CSV para que encajen con el workflow actual.</p>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr><th>Estado CSV</th><th>Estado guardado</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($estadoMappings as $from => $to): ?>
                    <tr>
                        <td><code><?= e($from) ?></code></td>
                        <td><code><?= e($to) ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-tags"></i> Familias</h5>
                <p class="text-muted small">Importa el catálogo de familias desde `familias.csv`.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="import_familias">
                    <div class="mb-3">
                        <label class="form-label">Archivo</label>
                        <input type="text" name="file_path" class="form-control" value="<?= e($defaultFiles['familias']) ?>" required>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Importar familias</button>
                        <button type="submit" name="action" value="preview_familias" class="btn btn-outline-secondary"><i class="bi bi-search"></i> Simular</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-pc-display"></i> Equipos</h5>
                <p class="text-muted small">Importa o actualiza el catálogo de equipos y su valor desde `equipos.csv`.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="import_equipos">
                    <div class="mb-3">
                        <label class="form-label">Archivo</label>
                        <input type="text" name="file_path" class="form-control" value="<?= e($defaultFiles['equipos']) ?>" required>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Importar equipos</button>
                        <button type="submit" name="action" value="preview_equipos" class="btn btn-outline-secondary"><i class="bi bi-search"></i> Simular</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm h-100 border-primary">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-tools"></i> Reparaciones</h5>
                <p class="text-muted small">Importa reparaciones históricas. Si faltan salas, familias, equipos, técnicos o estados, también los crea.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="import_reparaciones">
                    <div class="mb-3">
                        <label class="form-label">Archivo</label>
                        <input type="text" name="file_path" class="form-control" value="<?= e($defaultFiles['reparaciones']) ?>" required>
                    </div>
                    <div class="alert alert-light small mb-3">
                        La importación omite filas que ya coinciden con una reparación existente usando fecha, sala, UID, NPU, familia, equipo, técnico y estado.
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Importar reparaciones</button>
                        <button type="submit" name="action" value="preview_reparaciones" class="btn btn-outline-secondary"><i class="bi bi-search"></i> Simular</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-danger mt-4">
    <div class="card-body">
        <h5 class="card-title text-danger"><i class="bi bi-exclamation-triangle"></i> Reinicio de Reparaciones</h5>
        <p class="text-muted small">Borra todas las filas de `reparaciones`. El historial asociado a cada reparación se elimina automáticamente por `ON DELETE CASCADE`. No toca usuarios ni catálogos.</p>
        <form method="POST" onsubmit="return confirm('Esto va a borrar todas las reparaciones importadas y manuales. ¿Continuar?');">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="reset_reparaciones">
            <button type="submit" class="btn btn-danger"><i class="bi bi-trash3"></i> Limpiar reparaciones y volver a importar</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../public/includes/footer.php'; ?>
