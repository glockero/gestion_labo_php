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

$uploadsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'imports';
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0775, true);
}

$tipoLabels = [
    'familias'     => 'Familias',
    'equipos'      => 'Equipos',
    'reparaciones' => 'Reparaciones',
];

$importMethods = [
    'familias'     => 'importFamiliasCsv',
    'equipos'      => 'importEquiposCsv',
    'reparaciones' => 'importReparacionesCsv',
];

$importResult = null;
$importError  = null;
$previewType  = null;   // 'familias' | 'equipos' | 'reparaciones'
$previewData  = null;   // ['headers'=>..., 'rows'=>..., 'total'=>..., 'summary'=>..., 'path'=>...]

/**
 * Move an uploaded CSV into uploads/imports with a unique, safe filename.
 * Returns the absolute path. Throws on validation errors.
 */
function persistUploadedCsv($fieldName, $uploadsDir) {
    if (empty($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = $_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE;
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'El archivo supera el tamaño máximo permitido por el servidor.',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el tamaño máximo permitido por el formulario.',
            UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente. Intente nuevamente.',
            UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta el directorio temporal en el servidor.',
            UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en disco.',
            UPLOAD_ERR_EXTENSION  => 'Una extensión PHP detuvo la subida del archivo.',
        ];
        throw new RuntimeException($messages[$code] ?? 'Error desconocido al subir el archivo.');
    }

    $info = $_FILES[$fieldName];

    if ($info['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('El archivo supera 10 MB.');
    }

    $ext = strtolower(pathinfo($info['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        throw new RuntimeException('Solo se permiten archivos .csv (recibido: .' . e($ext) . ').');
    }

    $base = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($info['name'], PATHINFO_FILENAME));
    if ($base === '') $base = 'archivo';
    $unique = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $base . '.csv';
    $dest = $uploadsDir . DIRECTORY_SEPARATOR . $unique;

    if (!move_uploaded_file($info['tmp_name'], $dest)) {
        throw new RuntimeException('No se pudo guardar el archivo en uploads/imports.');
    }

    return $dest;
}

/** Resolve an already-uploaded path coming from a hidden input. */
function resolveStoredUploadPath($rawPath, $uploadsDir) {
    $realBase = realpath($uploadsDir);
    $real     = realpath($rawPath);
    if ($realBase === false || $real === false) return null;
    $baseWithSep = rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return strpos($real, $baseWithSep) === 0 ? $real : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrf();
    $action = $_POST['action'];

    try {
        // Preview: requires an uploaded file
        if (preg_match('/^preview_(familias|equipos|reparaciones)$/', $action, $m)) {
            $tipo = $m[1];
            $path = persistUploadedCsv('csv_file', $uploadsDir);
            $method = $importMethods[$tipo];
            $sample = ImportService::previewRows($path);
            $summary = ImportService::$method($path, true);
            $previewType = $tipo;
            $previewData = $sample + ['summary' => $summary, 'path' => $path];

        // Import: requires a previously uploaded file referenced by hidden input
        } elseif (preg_match('/^import_(familias|equipos|reparaciones)$/', $action, $m)) {
            $tipo = $m[1];
            $path = resolveStoredUploadPath((string)($_POST['uploaded_path'] ?? ''), $uploadsDir);
            if (!$path) {
                throw new RuntimeException('La ruta del archivo subido no es válida. Volvé a subir el archivo.');
            }
            $method = $importMethods[$tipo];
            $importResult = ImportService::$method($path);
            registrarHistorial('IMPORTAR_' . strtoupper($tipo), "Se importó {$tipo} desde " . basename($path) . '.');
            @unlink($path); // cleanup once committed

        } elseif ($action === 'cancel_preview') {
            $path = resolveStoredUploadPath((string)($_POST['uploaded_path'] ?? ''), $uploadsDir);
            if ($path) @unlink($path);

        } elseif ($action === 'reset_reparaciones') {
            if (strtolower(trim((string)($_POST['confirm_reset'] ?? ''))) !== 'borrar') {
                throw new RuntimeException('Confirmacion invalida. Escribi "borrar" para reiniciar las reparaciones.');
            }
            $importResult = ImportService::resetReparaciones();
            registrarHistorial('RESET_REPARACIONES', 'Se eliminaron todas las reparaciones e historial asociado por FK.');
        }
    } catch (Throwable $e) {
        $importError = $e->getMessage();
    }
}

require_once __DIR__ . '/../public/includes/header.php';
?>



<div class="csv-import-shell admin-shell">
<div class="csv-import-header">
    <div>
        <h2 class="csv-import-title"><i class="bi bi-file-earmark-arrow-up me-1"></i> Importar CSV</h2>
        <p class="csv-import-subtitle">Subi el archivo, revisa la previsualizacion y confirma solo cuando los datos se vean correctos.</p>
    </div>
    <a href="configuracion.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver a Sistema</a>
</div>

<?php if ($importError): ?>
<div class="alert alert-danger">
    <strong>Error de importación:</strong> <?= e($importError) ?>
</div>
<?php endif; ?>

<?php if ($importResult): ?>
<div class="alert alert-success">
    <strong><?= ($importResult['mode'] ?? '') === 'preview' ? 'Simulación completada.' : 'Operación completada.' ?></strong>
    <div class="mt-2 d-flex flex-wrap gap-2">
        <?php foreach ($importResult as $key => $value):
            if ($key === 'mode' || $key === 'state_map_detail') continue;
            if (is_array($value)) {
                $value = count($value) . ' items';
            }
        ?>
            <span class="badge bg-white text-dark border"><strong><?= e(ucfirst($key)) ?>:</strong> <?= e((string)$value) ?></span>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($importResult['states_mapped']) && !empty($importResult['state_map_detail'])): ?>
    <div class="mt-2 pt-2 border-top" style="font-size: 0.78rem;">
        <i class="bi bi-diagram-3 me-1"></i>
        <strong>Mapeo de estados aplicado</strong>
        (<?= (int)$importResult['states_mapped'] ?> fila<?= (int)$importResult['states_mapped'] === 1 ? '' : 's' ?>):
        <?php foreach (array_filter(array_map('trim', explode(',', $importResult['state_map_detail']))) as $pair): ?>
            <code class="ms-1" style="font-size: 0.72rem; background: #fff; padding: 1px 4px; border-radius: 3px;"><?= e($pair) ?></code>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>


<?php
// Per-card descriptive content
$cardMeta = [
    'familias' => [
        'icon'        => 'bi-tags',
        'title'       => 'Familias',
        'description' => 'Catálogo de familias.',
        'note'        => null,
        'border'      => '',
    ],
    'equipos' => [
        'icon'        => 'bi-pc-display',
        'title'       => 'Equipos',
        'description' => 'Catálogo de equipos y su valor.',
        'note'        => null,
        'border'      => '',
    ],
    'reparaciones' => [
        'icon'        => 'bi-tools',
        'title'       => 'Reparaciones',
        'description' => 'Reparaciones históricas. Si faltan salas, familias, equipos, técnicos o estados, también los crea.',
        'note'        => 'Las filas que ya coinciden con una reparación existente (fecha, sala, UID, NPU, familia, equipo, técnico y estado) se omiten.',
        'border'      => 'border-primary',
    ],
];
?>

<div class="csv-import-grid">
    <?php foreach (['familias', 'equipos', 'reparaciones'] as $tipo):
        $meta = $cardMeta[$tipo];
    ?>
    <section class="csv-import-card <?= $meta['border'] ? 'highlight' : '' ?>">
        <div class="csv-card-head">
            <span class="csv-card-icon"><i class="bi <?= $meta['icon'] ?>"></i></span>
            <div>
                <h3 class="csv-card-title"><?= e($meta['title']) ?></h3>
                <p class="csv-card-desc"><?= e($meta['description']) ?></p>
            </div>
        </div>
        <div class="csv-card-body">

            <form method="POST" enctype="multipart/form-data" class="csv-import-form">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="preview_<?= e($tipo) ?>">
                    <div>
                        <label class="form-label">Archivo CSV</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
                        <small class="text-muted" style="font-size: 0.7rem;">Tamaño máximo: 10 MB.</small>
                    </div>
                    <?php if ($meta['note']): ?>
                    <div class="csv-note">
                        <i class="bi bi-info-circle"></i><span><?= e($meta['note']) ?></span>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-eye"></i> Previsualizar
                    </button>
            </form>
        </div>
    </section>
    <?php endforeach; ?>
</div>

<?php if ($previewType && $previewData !== null):
    $headers = $previewData['headers'];
    $rows    = $previewData['rows'];
    $total   = $previewData['total'];
    $shown   = $previewData['displayed'];
    $summary = $previewData['summary'];
    $meta    = $cardMeta[$previewType];
?>
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 10px;">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title fw-bold" style="font-size: 0.92rem;">
                    <i class="bi <?= $meta['icon'] ?> me-1"></i>
                    Previsualización: <?= e($meta['title']) ?>
                </h6>
                <form method="POST" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="cancel_preview">
                    <input type="hidden" name="uploaded_path" value="<?= e($previewData['path']) ?>">
                    <button type="submit" class="btn-close" aria-label="Cerrar"></button>
                </form>
            </div>

            <div class="modal-body p-3">
                <div class="alert alert-light border d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2 py-2 px-2" style="font-size: 0.72rem;">
                    <div>
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i>
                        <strong><?= e(basename($previewData['path'])) ?></strong>
                        · <span class="text-muted"><?= number_format($total, 0, ',', '.') ?> filas</span>
                    </div>
                    <div class="text-muted" style="font-size: 0.68rem;">
                        <?php foreach ($summary as $k => $v):
                            if ($k === 'mode' || $k === 'state_map_detail') continue;
                            if (is_array($v)) $v = count($v) . ' items';
                        ?>
                            <span class="badge bg-light text-dark border me-1" style="font-weight: 500;"><?= e(ucfirst($k)) ?>: <strong><?= e((string)$v) ?></strong></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (!empty($summary['states_mapped']) && !empty($summary['state_map_detail'])): ?>
                <div class="alert alert-warning border-0 py-2 px-2 mb-2" style="font-size: 0.72rem; background: #fef9c3;">
                    <i class="bi bi-diagram-3 me-1 text-warning"></i>
                    <strong>Mapeo de estados aplicado</strong>
                    (<?= (int)$summary['states_mapped'] ?> fila<?= (int)$summary['states_mapped'] === 1 ? '' : 's' ?>):
                    <?php
                        $pairs = array_filter(array_map('trim', explode(',', $summary['state_map_detail'])));
                        foreach ($pairs as $pair):
                    ?>
                        <code class="ms-1" style="font-size: 0.68rem; background: #fff; padding: 1px 4px; border-radius: 3px;"><?= e($pair) ?></code>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="table-responsive" style="max-height: 320px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <table class="table table-sm mb-0" style="font-size: 0.7rem;">
                        <thead style="position: sticky; top: 0; background: #f8fafc; z-index: 1;">
                            <tr>
                                <th style="width: 28px; padding: 0.3rem 0.4rem;">#</th>
                                <?php foreach ($headers as $h): ?>
                                    <th style="padding: 0.3rem 0.4rem;"><?= e($h) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $i => $row): ?>
                                <tr>
                                    <td class="text-muted" style="padding: 0.25rem 0.4rem;"><?= $i + 1 ?></td>
                                    <?php foreach ($headers as $j => $h): ?>
                                        <td style="padding: 0.25rem 0.4rem;"><?= e((string)($row[$j] ?? '')) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($shown < $total): ?>
                <p class="text-muted small mb-0 mt-2" style="font-size: 0.68rem;">
                    Mostrando primeras <?= $shown ?> filas de <?= number_format($total, 0, ',', '.') ?>.
                </p>
                <?php endif; ?>
            </div>

            <div class="modal-footer py-2 px-3 d-flex gap-2 justify-content-end">
                <form method="POST" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="cancel_preview">
                    <input type="hidden" name="uploaded_path" value="<?= e($previewData['path']) ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-sm" style="font-size: 0.75rem; padding: 0.3rem 0.6rem;">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                </form>
                <form method="POST" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="import_<?= e($previewType) ?>">
                    <input type="hidden" name="uploaded_path" value="<?= e($previewData['path']) ?>">
                    <button type="submit" class="btn btn-success btn-sm fw-bold" style="font-size: 0.75rem; padding: 0.3rem 0.7rem;">
                        <i class="bi bi-check2-circle"></i> Confirmar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal')).show();
});
</script>
<?php endif; ?>

<div class="csv-reset-panel">
    <span class="icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
    <div>
        <h6>Reinicio de Reparaciones</h6>
        <p class="text-muted small">Borra todas las filas de `reparaciones`. El historial asociado a cada reparación se elimina automáticamente por `ON DELETE CASCADE`. No toca usuarios ni catálogos.</p>
        <form method="POST" onsubmit="return confirm('Esto va a borrar todas las reparaciones importadas y manuales. ¿Continuar?');">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="reset_reparaciones">
            <button type="submit" class="btn btn-danger"><i class="bi bi-trash3"></i> Limpiar reparaciones</button>
        </form>
    </div>
    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#resetReparacionesModal">
        <i class="bi bi-trash3"></i> Reiniciar
    </button>
</div>
</div>

<div class="modal fade" id="resetReparacionesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
        <div class="modal-content border-0 shadow-sm" style="border-radius: 12px;">
            <div class="modal-body text-center p-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-danger bg-opacity-10 text-danger rounded-circle mb-3" style="width: 52px; height: 52px;">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.5rem;"></i>
                </div>
                <h6 class="fw-bold text-dark mb-1" style="font-size: 0.95rem;">Reiniciar Reparaciones</h6>
                <p class="text-muted mb-3" style="font-size: 0.78rem; line-height: 1.4;">
                    Esta accion borra todas las reparaciones importadas y manuales. El historial asociado tambien se eliminara.
                </p>
                <div class="bg-light border rounded-3 px-3 py-2 my-3 text-start" style="font-size: 0.78rem;">
                    <div class="fw-bold text-danger mb-1"><i class="bi bi-shield-exclamation me-1"></i>Confirmacion requerida</div>
                    <label for="resetConfirmText" class="form-label text-muted mb-1" style="font-size: 0.72rem;">
                        Escribi <code>borrar</code> para continuar
                    </label>
                    <input type="text" id="resetConfirmText" name="confirm_reset" form="resetReparacionesForm" class="form-control csv-reset-modal-input" autocomplete="off" spellcheck="false">
                </div>
                <form method="POST" id="resetReparacionesForm">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="reset_reparaciones">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light flex-grow-1 fw-bold text-secondary border" style="font-size: 0.8rem; padding: 0.5rem;" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" id="resetConfirmButton" class="btn btn-danger flex-grow-1 fw-bold shadow-none" style="font-size: 0.8rem; padding: 0.5rem;" disabled>Borrar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('resetReparacionesModal');
    const input = document.getElementById('resetConfirmText');
    const button = document.getElementById('resetConfirmButton');
    if (!modal || !input || !button) return;

    function syncResetButton() {
        button.disabled = input.value.trim().toLowerCase() !== 'borrar';
    }

    input.addEventListener('input', syncResetButton);
    modal.addEventListener('shown.bs.modal', function () {
        input.value = '';
        syncResetButton();
        input.focus();
    });
    modal.addEventListener('hidden.bs.modal', function () {
        input.value = '';
        syncResetButton();
    });
});
</script>

<?php require_once __DIR__ . '/../public/includes/footer.php'; ?>
