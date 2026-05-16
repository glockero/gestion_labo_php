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
    return 'bg-dark';
}
?>

<style>
.historial-table-scroll {
    max-height: calc(100vh - 220px);
    min-height: 240px;
    overflow: auto;
}
.historial-table-scroll thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f8fafc;
    box-shadow: inset 0 -1px 0 #e2e8f0;
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
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Acción</th>
                    <th>Detalle</th>
                    <th>Reparación</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($registros)): ?>
                <tr><td colspan="5" class="text-center py-4 text-muted">No se encontraron registros.</td></tr>
                <?php endif; ?>
                <?php foreach ($registros as $r): ?>
                <tr>
                    <td class="text-nowrap"><?= formatDatetimeArg($r['fecha']) ?></td>
                    <td><strong><?= e($r['username'] ?? 'Sistema') ?></strong></td>
                    <td><span class="badge <?= getActionColor($r['accion']) ?>"><?= e($r['accion']) ?></span></td>
                    <td class="small"><?= e($r['detalle']) ?></td>
                    <td>
                        <?php if ($r['reparacion_id']): ?>
                            <a href="<?= APP_URL ?>/reparacion_detalle.php?id=<?= $r['reparacion_id'] ?>">#<?= $r['reparacion_id'] ?></a>
                            <br><small class="text-muted"><?= e($r['equipo']) ?> (<?= e($r['npu']) ?>)</small>
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
