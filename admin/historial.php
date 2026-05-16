<?php
// admin/historial.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/db.php';

requireRole('admin');

require_once __DIR__ . '/../public/includes/header.php';

$pdo = getDbConnection();

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 100;
$offset = ($page - 1) * $limit;

$busqueda = $_GET['q'] ?? '';

$sql = "SELECT h.*, u.username, r.equipo, r.npu 
        FROM historial h 
        LEFT JOIN usuarios u ON h.usuario_id = u.id 
        LEFT JOIN reparaciones r ON h.reparacion_id = r.id";

$where = [];
$params = [];

if ($busqueda) {
    $where[] = "(h.accion LIKE ? OR h.detalle LIKE ? OR u.username LIKE ? OR r.npu LIKE ?)";
    $b = "%$busqueda%";
    array_push($params, $b, $b, $b, $b);
}

if (count($where) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY h.fecha DESC LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registros = $stmt->fetchAll();

// Get total for pagination
$sqlCount = "SELECT COUNT(*) FROM historial h LEFT JOIN usuarios u ON h.usuario_id = u.id LEFT JOIN reparaciones r ON h.reparacion_id = r.id";
if (count($where) > 0) {
    $sqlCount .= " WHERE " . implode(" AND ", $where);
}
$stmtC = $pdo->prepare($sqlCount);
$stmtC->execute($params);
$total = $stmtC->fetchColumn();
$total_pages = ceil($total / $limit);

function getActionColor($accion) {
    if (strpos($accion, 'LOGIN') !== false) return 'bg-success';
    if (strpos($accion, 'LOGOUT') !== false) return 'bg-secondary';
    if (strpos($accion, 'ESTADO') !== false) return 'bg-primary';
    if (strpos($accion, 'INGRESO') !== false) return 'bg-warning text-dark';
    if (strpos($accion, 'EDITAR') !== false) return 'bg-info text-dark';
    if (strpos($accion, 'ELIMINAR') !== false) return 'bg-danger';
    if (strpos($accion, 'CREAR') !== false || strpos($accion, 'IMPORTAR') !== false) return 'bg-success';
    if (strpos($accion, 'RESET') !== false || strpos($accion, 'PASS') !== false) return 'bg-warning text-dark';
    return 'bg-dark';
}

/**
 * Returns a shorter, human-friendly label for an action stored in BD.
 * The DB still keeps the canonical key (USUARIO_ELIMINAR, etc.) — this is
 * only used at render time so labels fit a narrow column without truncating.
 */
function getActionLabel($accion) {
    static $map = [
        'USUARIO_CREAR'         => 'USR. CREAR',
        'USUARIO_EDITAR'        => 'USR. EDITAR',
        'USUARIO_ELIMINAR'      => 'USR. ELIMINAR',
        'USUARIO_RESET_PASS'    => 'RESET PASS',
        'USUARIO_FORCE_LOGOUT'  => 'FORZAR LOGOUT',
        'USUARIO_CAMBIO_PASS'   => 'CAMBIO PASS',
        'CATALOGO_EDITAR'       => 'CAT. EDITAR',
        'CATALOGO_ELIMINAR'     => 'CAT. ELIMINAR',
        'CATALOGO_TOGGLE'       => 'CAT. TOGGLE',
        'IMPORTAR_FAMILIAS'     => 'IMPORT FAM.',
        'IMPORTAR_EQUIPOS'      => 'IMPORT EQUIP.',
        'IMPORTAR_REPARACIONES' => 'IMPORT REP.',
        'RESET_REPARACIONES'    => 'RESET REP.',
    ];
    return $map[$accion] ?? $accion;
}
?>

<style>
.historial-table-scroll {
    max-height: calc(100vh - 220px);
    min-height: 240px;
    overflow: auto;
}
.historial-table-scroll table {
    table-layout: fixed;
    width: 100%;
}
.historial-table-scroll thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f8fafc;
    box-shadow: inset 0 -1px 0 #e2e8f0;
}

/* Column sizing: keep Fecha/Usuario/Acción/Reparación narrow; Detalle takes the rest */
.col-h-fecha    { width: 165px; }
.col-h-usuario  { width: 120px; }
.col-h-accion   { width: 160px; }
.col-h-rep      { width: 220px; }

/* Action badge: allow wrap on long labels and use a tighter font */
.col-h-accion .badge {
    font-size: 10.5px;
    padding: 0.32em 0.5em;
    white-space: normal;
    line-height: 1.2;
    text-align: left;
}

/* Prevent fixed-width cells from overflowing into neighbors */
.historial-table-scroll td,
.historial-table-scroll th {
    overflow: hidden;
}

/* Detalle: long tokens without spaces should wrap, not blow the layout */
.col-h-detalle {
    word-break: break-word;
    overflow-wrap: anywhere;
    white-space: normal;
}

/* Reparación cell: keep equipo+NPU on at most 2 lines with ellipsis */
.col-h-rep .rep-meta {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    font-size: 11px;
    color: #64748b;
    line-height: 1.25;
    margin-top: 2px;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="h3"><i class="bi bi-clock-history"></i> Historial General del Sistema</h2>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="historial.php" class="row g-3">
            <div class="col-md-10">
                <input type="text" name="q" class="form-control" placeholder="Buscar por acción, usuario, NPU o detalle..." value="<?= e($busqueda) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Buscar</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="historial-table-scroll">
        <table class="table table-modern mb-0">
            <thead>
                <tr>
                    <th class="col-h-fecha">Fecha</th>
                    <th class="col-h-usuario">Usuario</th>
                    <th class="col-h-accion">Acción</th>
                    <th class="col-h-detalle">Detalle</th>
                    <th class="col-h-rep">Reparación</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($registros)): ?>
                <tr><td colspan="5" class="text-center py-4 text-muted">No se encontraron registros.</td></tr>
                <?php endif; ?>
                <?php foreach ($registros as $r): ?>
                <tr>
                    <td class="col-h-fecha text-nowrap"><?= formatDatetimeArg($r['fecha']) ?></td>
                    <td class="col-h-usuario"><strong><?= e($r['username'] ?? 'Sistema') ?></strong></td>
                    <td class="col-h-accion"><span class="badge <?= getActionColor($r['accion']) ?>" title="<?= e($r['accion']) ?>"><?= e(getActionLabel($r['accion'])) ?></span></td>
                    <td class="col-h-detalle small"><?= e($r['detalle']) ?></td>
                    <td class="col-h-rep">
                        <?php if ($r['reparacion_id']): ?>
                            <a href="<?= APP_URL ?>/reparacion_detalle.php?id=<?= $r['reparacion_id'] ?>">#<?= $r['reparacion_id'] ?></a>
                            <div class="rep-meta" title="<?= e($r['equipo'] . ' (' . $r['npu'] . ')') ?>"><?= e($r['equipo']) ?> (<?= e($r['npu']) ?>)</div>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-center">
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php
                $qParams = $_GET;
                for($i = max(1, $page - 3); $i <= min($total_pages, $page + 3); $i++): 
                    $active = $page == $i ? 'active' : '';
                    $qParams['page'] = $i;
                ?>
                <li class="page-item <?= $active ?>"><a class="page-link" href="?<?= http_build_query($qParams) ?>"><?= $i ?></a></li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../public/includes/footer.php'; ?>
