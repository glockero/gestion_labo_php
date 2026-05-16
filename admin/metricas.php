<?php
// admin/metricas.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/CatalogoModel.php';

requireRole('admin');

require_once __DIR__ . '/../public/includes/header.php';

$pdo = getDbConnection();

// --- Period filter ----------------------------------------------------------
$periodos = [
    'hoy'  => ['label' => 'Hoy',         'where' => 'DATE(r.fecha) = CURDATE()'],
    '7d'   => ['label' => 'Últimos 7 días',  'where' => 'r.fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)'],
    '30d'  => ['label' => 'Últimos 30 días', 'where' => 'r.fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)'],
    '90d'  => ['label' => 'Últimos 90 días', 'where' => 'r.fecha >= DATE_SUB(NOW(), INTERVAL 90 DAY)'],
    'anio' => ['label' => 'Este año',    'where' => 'YEAR(r.fecha) = YEAR(CURDATE())'],
    'todo' => ['label' => 'Todo',        'where' => '1=1'],
];
$periodo_actual = $_GET['periodo'] ?? '30d';
if (!isset($periodos[$periodo_actual])) $periodo_actual = '30d';
$wherePeriodo = $periodos[$periodo_actual]['where'];

// --- Optional dropdowns: technician + room ---------------------------------
$tecnico_filtro = $_GET['tecnico_id'] ?? '';
$sala_filtro = $_GET['sala'] ?? '';
$salas_catalog = CatalogoModel::getAll('salas', false);
$tecnicos_catalog = CatalogoModel::getAll('tecnicos', false);

// Build a WHERE clause and parameter list shared by every query below.
$whereParts = [$wherePeriodo];
$params = [];
if ($tecnico_filtro === 'SIN_ASIGNAR') {
    $whereParts[] = 'r.tecnico_id IS NULL';
} elseif ($tecnico_filtro !== '') {
    $whereParts[] = 'r.tecnico_id = ?';
    $params[] = (int)$tecnico_filtro;
}
if ($sala_filtro !== '') {
    $whereParts[] = 'r.sala = ?';
    $params[] = $sala_filtro;
}
$whereClause = '(' . implode(') AND (', $whereParts) . ')';

function runQuery(PDO $pdo, $sql, $params) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// --- KPIs (single query with SUM CASE WHEN, plus avg-time field) ----------
// avg_dias_reparacion: midiendo *trabajo efectivo* — desde que entra a
// "EN REPARACION" hasta que llega a "REPARADO". Excluye el tiempo en cola.
$kpiRow = runQuery($pdo, "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN UPPER(r.estado) LIKE 'REPARADO%' THEN 1 ELSE 0 END) AS reparadas,
        SUM(CASE WHEN UPPER(r.estado) LIKE 'SIN REPARACION%' THEN 1 ELSE 0 END) AS sin_reparacion,
        SUM(CASE WHEN UPPER(r.estado) <> 'ENTREGADO'
              AND UPPER(r.estado) NOT LIKE 'REPARADO%'
              AND UPPER(r.estado) NOT LIKE 'SIN REPARACION%'
            THEN 1 ELSE 0 END) AS en_curso,
        SUM(CASE WHEN r.urgente = 'SI'
              AND UPPER(r.estado) <> 'ENTREGADO'
              AND UPPER(r.estado) NOT LIKE 'REPARADO%'
              AND UPPER(r.estado) NOT LIKE 'SIN REPARACION%'
            THEN 1 ELSE 0 END) AS urgentes,
        AVG(CASE WHEN r.fecha_reparado IS NOT NULL
                  AND r.fecha_en_reparacion IS NOT NULL
                  AND r.fecha_reparado >= r.fecha_en_reparacion
              THEN TIMESTAMPDIFF(HOUR, r.fecha_en_reparacion, r.fecha_reparado) / 24.0
            END) AS avg_dias_reparacion
    FROM reparaciones r
    WHERE $whereClause
", $params)->fetch();

$total = (int)($kpiRow['total'] ?? 0);
$kpis = [
    ['label' => 'Rep. tomadas',      'value' => $total,                                'color' => '#1e293b'],
    ['label' => 'Reparadas',         'value' => (int)$kpiRow['reparadas'],             'color' => '#166534'],
    ['label' => 'En curso',          'value' => (int)$kpiRow['en_curso'],              'color' => '#1d4ed8'],
    ['label' => 'Sin reparación',    'value' => (int)$kpiRow['sin_reparacion'],        'color' => '#991b1b'],
    ['label' => 'Urgentes activas',  'value' => (int)$kpiRow['urgentes'],              'color' => '#b45309'],
];

$avg_reparacion = $kpiRow['avg_dias_reparacion'] !== null ? (float)$kpiRow['avg_dias_reparacion'] : null;
$total_cerradas = (int)$kpiRow['reparadas'] + (int)$kpiRow['sin_reparacion'];
$pct_exito_global = $total_cerradas > 0 ? round(((int)$kpiRow['reparadas'] / $total_cerradas) * 100, 1) : null;
$pct_urgencia = $total > 0 ? round(((int)$kpiRow['urgentes'] / $total) * 100, 1) : null;

// --- Por técnico -----------------------------------------------------------
$porTecnico = runQuery($pdo, "
    SELECT
        COALESCE(t.nombre, r.tecnico_nombre_historico, '(Sin asignar)') AS tecnico,
        COUNT(*) AS total,
        SUM(CASE WHEN UPPER(r.estado) LIKE 'REPARADO%' THEN 1 ELSE 0 END) AS reparadas,
        SUM(CASE WHEN UPPER(r.estado) LIKE 'SIN REPARACION%' THEN 1 ELSE 0 END) AS sin_reparacion,
        SUM(CASE WHEN UPPER(r.estado) <> 'ENTREGADO'
              AND UPPER(r.estado) NOT LIKE 'REPARADO%'
              AND UPPER(r.estado) NOT LIKE 'SIN REPARACION%'
            THEN 1 ELSE 0 END) AS en_curso
    FROM reparaciones r
    LEFT JOIN tecnicos t ON r.tecnico_id = t.id
    WHERE $whereClause
    GROUP BY COALESCE(t.nombre, r.tecnico_nombre_historico, '(Sin asignar)')
    ORDER BY total DESC
", $params)->fetchAll();

// --- Por sala --------------------------------------------------------------
$porSala = runQuery($pdo, "
    SELECT
        sala,
        COUNT(*) AS total,
        SUM(CASE WHEN UPPER(r.estado) LIKE 'REPARADO%' THEN 1 ELSE 0 END) AS reparadas,
        SUM(CASE WHEN UPPER(r.estado) LIKE 'SIN REPARACION%' THEN 1 ELSE 0 END) AS sin_reparacion,
        SUM(CASE WHEN UPPER(r.estado) <> 'ENTREGADO'
              AND UPPER(r.estado) NOT LIKE 'REPARADO%'
              AND UPPER(r.estado) NOT LIKE 'SIN REPARACION%'
            THEN 1 ELSE 0 END) AS en_curso
    FROM reparaciones r
    WHERE $whereClause
    GROUP BY sala
    ORDER BY total DESC
", $params)->fetchAll();

// --- Top equipos -----------------------------------------------------------
$topEquipos = runQuery($pdo, "
    SELECT equipo, COUNT(*) AS total
    FROM reparaciones r
    WHERE $whereClause AND equipo IS NOT NULL AND equipo <> ''
    GROUP BY equipo
    ORDER BY total DESC
    LIMIT 10
", $params)->fetchAll();

// --- Evolución temporal: bucket granularity depends on period ----------------
function getBucketConfig($periodo) {
    switch ($periodo) {
        case 'hoy':  return ['expr' => "DATE_FORMAT(r.fecha, '%Y-%m-%d %H:00')", 'fmt_label' => 'HH:mm', 'gran' => 'hour'];
        case '7d':
        case '30d':  return ['expr' => "DATE(r.fecha)",                          'fmt_label' => 'dd/MM', 'gran' => 'day'];
        case '90d':  return ['expr' => "DATE(DATE_SUB(r.fecha, INTERVAL WEEKDAY(r.fecha) DAY))", 'fmt_label' => 'dd/MM (sem)', 'gran' => 'week'];
        case 'anio':
        case 'todo':
        default:     return ['expr' => "DATE_FORMAT(r.fecha, '%Y-%m-01')",       'fmt_label' => 'MM/yyyy', 'gran' => 'month'];
    }
}
$bucket = getBucketConfig($periodo_actual);

$evolucion = runQuery($pdo, "
    SELECT
        {$bucket['expr']} AS bucket,
        COUNT(*) AS total,
        SUM(CASE WHEN UPPER(r.estado) LIKE 'REPARADO%' THEN 1 ELSE 0 END) AS reparadas
    FROM reparaciones r
    WHERE $whereClause
    GROUP BY bucket
    ORDER BY bucket
", $params)->fetchAll();

// Helper: percentage success (reparadas / (reparadas + sin_reparacion))
function pctExito($reparadas, $sinReparacion) {
    $den = (int)$reparadas + (int)$sinReparacion;
    if ($den === 0) return null;
    return round(((int)$reparadas / $den) * 100, 1);
}

$avatarPalette = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899', '#475569'];
function avatarColorMetrica($nombre, $palette) {
    return $palette[abs(crc32((string)$nombre)) % count($palette)];
}
?>

<style>
.metricas-shell {
    --border-color: #e5e7eb;
    --text-main: #1e293b;
    --text-muted: #64748b;
    font-size: 13.5px;
    color: var(--text-main);
}

.metricas-shell .page-title {
    font-size: 26px;
    font-weight: 700;
    margin: 0;
    letter-spacing: -0.01em;
}

.periodo-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    border: 1px solid var(--border-color);
    background: #fff;
    color: var(--text-muted);
    font-weight: 600;
    font-size: 12.5px;
    text-decoration: none;
    transition: all 0.12s;
}
.periodo-chip:hover {
    border-color: #93c5fd;
    color: #1d4ed8;
}
.periodo-chip.active {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
}

.kpi-card {
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 0.85rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    position: relative;
    overflow: hidden;
}
.kpi-card .kpi-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.kpi-card .kpi-value {
    font-size: 26px;
    font-weight: 700;
    line-height: 1;
}
.kpi-card .kpi-pct {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 2px;
}

.dense-card {
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    overflow: hidden;
}
.dense-card .card-header {
    background: #fff;
    padding: 0.7rem 1rem;
    border-bottom: 1px solid var(--border-color);
}
.dense-card .card-header h6 {
    font-size: 13px;
    font-weight: 700;
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--text-main);
}

.metricas-table {
    width: 100%;
    margin: 0;
    font-size: 13px;
}
.metricas-table thead th {
    background: #f8fafc;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0.5rem 0.75rem;
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}
.metricas-table tbody td {
    padding: 0.45rem 0.75rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.metricas-table tbody tr:last-child td { border-bottom: none; }
.metricas-table tbody tr:hover td { background: #f8fafc; }

.metricas-table .num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
.metricas-table .num.muted { color: #94a3b8; font-weight: 500; }
.metricas-table .name-cell { font-weight: 600; }

.tech-avatar-sm {
    width: 22px;
    height: 22px;
    font-size: 10.5px;
    font-weight: 700;
    color: #fff;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: 0.4rem;
    flex-shrink: 0;
}

.pct-pill {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}
.pct-good   { background: #dcfce7; color: #166534; }
.pct-ok     { background: #fef9c3; color: #854d0e; }
.pct-bad    { background: #fee2e2; color: #991b1b; }
.pct-empty  { color: #94a3b8; font-style: italic; font-size: 11px; }

.bar-cell { position: relative; padding-right: 0.75rem; min-width: 120px; }
.bar-cell .bar-bg {
    position: relative;
    background: #f1f5f9;
    border-radius: 999px;
    height: 6px;
    overflow: hidden;
}
.bar-cell .bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6, #1d4ed8);
    border-radius: 999px;
}

.table-scroll {
    max-height: calc(100vh - 380px);
    min-height: 220px;
    overflow: auto;
}
.table-scroll thead th {
    position: sticky;
    top: 0;
    z-index: 2;
}
</style>

<div class="metricas-shell">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h2 class="page-title"><i class="bi bi-bar-chart-line text-primary me-2"></i>Métricas</h2>
        <a href="../public/dashboard.php" class="btn btn-light btn-sm border text-secondary">
            <i class="bi bi-graph-up me-1"></i>Ver KPI Dashboard
        </a>
    </div>

    <!-- Filters row: periodo chips + tecnico/sala selects -->
    <form method="GET" id="metricasFilters" class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <input type="hidden" name="periodo" value="<?= e($periodo_actual) ?>">

        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($periodos as $key => $info):
                $q = $_GET; $q['periodo'] = $key;
            ?>
                <a href="?<?= http_build_query($q) ?>" class="periodo-chip <?= $periodo_actual === $key ? 'active' : '' ?>">
                    <?= e($info['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="ms-md-auto d-flex flex-wrap gap-2 align-items-center">
            <select name="tecnico_id" class="form-select form-select-sm" style="width: auto; min-width: 160px; font-size: 12.5px;" onchange="this.form.submit()">
                <option value="">Todos los técnicos</option>
                <option value="SIN_ASIGNAR" <?= $tecnico_filtro === 'SIN_ASIGNAR' ? 'selected' : '' ?>>Sin asignar</option>
                <?php foreach ($tecnicos_catalog as $t): ?>
                    <option value="<?= e($t['id']) ?>" <?= (string)$tecnico_filtro === (string)$t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="sala" class="form-select form-select-sm" style="width: auto; min-width: 140px; font-size: 12.5px;" onchange="this.form.submit()">
                <option value="">Todas las salas</option>
                <?php foreach ($salas_catalog as $s): ?>
                    <option value="<?= e($s['nombre']) ?>" <?= $sala_filtro === $s['nombre'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
                <?php endforeach; ?>
            </select>

            <?php if ($tecnico_filtro !== '' || $sala_filtro !== ''): ?>
                <a href="?periodo=<?= e($periodo_actual) ?>" class="btn btn-sm btn-light border text-secondary" style="font-size: 11.5px;">
                    <i class="bi bi-x-circle me-1"></i>Limpiar
                </a>
            <?php endif; ?>
        </div>
    </form>

    <!-- KPIs grid -->
    <div class="row g-2 mb-3">
        <?php foreach ($kpis as $kpi):
            $pct = $total > 0 && $kpi['label'] !== 'Rep. tomadas'
                ? round(($kpi['value'] / $total) * 100, 1)
                : null;
        ?>
        <div class="col-6 col-md-4 col-lg">
            <div class="kpi-card" style="border-left: 3px solid <?= e($kpi['color']) ?>;">
                <div class="kpi-label"><?= e($kpi['label']) ?></div>
                <div class="kpi-value" style="color: <?= e($kpi['color']) ?>;">
                    <?= number_format($kpi['value'], 0, ',', '.') ?>
                </div>
                <?php if ($pct !== null): ?>
                    <div class="kpi-pct"><?= number_format($pct, 1, ',', '.') ?>% del total</div>
                <?php else: ?>
                    <div class="kpi-pct">&nbsp;</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Indicadores de rendimiento -->
    <div class="row g-2 mb-3">
        <div class="col-12 col-md-4">
            <div class="kpi-card" style="border-left: 3px solid #0ea5e9;">
                <div class="kpi-label"><i class="bi bi-stopwatch me-1"></i>Tiempo prom. reparación</div>
                <div class="kpi-value" style="color: #0ea5e9; font-size: 22px;">
                    <?php if ($avg_reparacion !== null): ?>
                        <?= number_format($avg_reparacion, 1, ',', '.') ?>
                        <span style="font-size: 13px; font-weight: 500; color: var(--text-muted);">días</span>
                    <?php else: ?>
                        <span style="font-size: 16px; color: #94a3b8; font-style: italic;">Sin datos</span>
                    <?php endif; ?>
                </div>
                <div class="kpi-pct">Desde "En reparación" hasta "Reparado"</div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="kpi-card" style="border-left: 3px solid #16a34a;">
                <div class="kpi-label"><i class="bi bi-check2-circle me-1"></i>% Éxito global</div>
                <div class="kpi-value" style="color: #16a34a; font-size: 22px;">
                    <?php if ($pct_exito_global !== null): ?>
                        <?= number_format($pct_exito_global, 1, ',', '.') ?>%
                    <?php else: ?>
                        <span style="font-size: 16px; color: #94a3b8; font-style: italic;">Sin datos</span>
                    <?php endif; ?>
                </div>
                <div class="kpi-pct">Reparadas / (Reparadas + Sin Rep.)</div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="kpi-card" style="border-left: 3px solid #ef4444;">
                <div class="kpi-label"><i class="bi bi-exclamation-triangle me-1"></i>% Urgentes</div>
                <div class="kpi-value" style="color: #ef4444; font-size: 22px;">
                    <?php if ($pct_urgencia !== null): ?>
                        <?= number_format($pct_urgencia, 1, ',', '.') ?>%
                    <?php else: ?>
                        <span style="font-size: 16px; color: #94a3b8; font-style: italic;">Sin datos</span>
                    <?php endif; ?>
                </div>
                <div class="kpi-pct">Urgentes activas / Total</div>
            </div>
        </div>
    </div>

    <!-- Evolución temporal chart -->
    <div class="dense-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6><i class="bi bi-graph-up me-1"></i>Evolución temporal</h6>
            <span class="text-muted" style="font-size: 11px;">
                <?= count($evolucion) ?> puntos · granularidad: <?= e($bucket['gran']) ?>
            </span>
        </div>
        <div class="card-body p-3" style="position: relative; height: 280px;">
            <?php if (empty($evolucion)): ?>
                <div class="d-flex align-items-center justify-content-center h-100 text-muted fst-italic">
                    Sin datos para el período.
                </div>
            <?php else: ?>
                <canvas id="evolucionChart"></canvas>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <!-- Por técnico -->
        <div class="col-lg-6">
            <div class="dense-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6><i class="bi bi-person-badge me-1"></i>Por Técnico</h6>
                    <span class="text-muted" style="font-size: 11px;"><?= count($porTecnico) ?> técnicos</span>
                </div>
                <div class="table-scroll">
                    <table class="metricas-table">
                        <thead>
                            <tr>
                                <th>Técnico</th>
                                <th class="num">Total</th>
                                <th class="num">Reparadas</th>
                                <th class="num">En curso</th>
                                <th class="num">Sin Rep.</th>
                                <th class="num">% Éxito</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($porTecnico)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted fst-italic">Sin datos para el período.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($porTecnico as $t):
                                $pct = pctExito($t['reparadas'], $t['sin_reparacion']);
                                $pctCls = $pct === null ? '' : ($pct >= 80 ? 'pct-good' : ($pct >= 50 ? 'pct-ok' : 'pct-bad'));
                                $isSinAsignar = $t['tecnico'] === '(Sin asignar)';
                            ?>
                                <tr>
                                    <td class="name-cell d-flex align-items-center">
                                        <?php if (!$isSinAsignar): ?>
                                            <span class="tech-avatar-sm" style="background-color: <?= e(avatarColorMetrica($t['tecnico'], $avatarPalette)) ?>;">
                                                <?= e(mb_strtoupper(mb_substr($t['tecnico'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                            </span>
                                            <?= e($t['tecnico']) ?>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic"><?= e($t['tecnico']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num"><?= number_format($t['total'], 0, ',', '.') ?></td>
                                    <td class="num"><?= number_format($t['reparadas'], 0, ',', '.') ?></td>
                                    <td class="num"><?= number_format($t['en_curso'], 0, ',', '.') ?></td>
                                    <td class="num <?= (int)$t['sin_reparacion'] === 0 ? 'muted' : '' ?>"><?= number_format($t['sin_reparacion'], 0, ',', '.') ?></td>
                                    <td class="num">
                                        <?php if ($pct !== null): ?>
                                            <span class="pct-pill <?= e($pctCls) ?>"><?= number_format($pct, 1, ',', '.') ?>%</span>
                                        <?php else: ?>
                                            <span class="pct-empty">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Por sala -->
        <div class="col-lg-6">
            <div class="dense-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6><i class="bi bi-geo-alt me-1"></i>Por Sala</h6>
                    <span class="text-muted" style="font-size: 11px;"><?= count($porSala) ?> salas</span>
                </div>
                <div class="table-scroll">
                    <table class="metricas-table">
                        <thead>
                            <tr>
                                <th>Sala</th>
                                <th class="num">Total</th>
                                <th class="num">Reparadas</th>
                                <th class="num">En curso</th>
                                <th class="num">Sin Rep.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($porSala)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted fst-italic">Sin datos para el período.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($porSala as $s):
                                $pct = pctExito($s['reparadas'], $s['sin_reparacion']);
                            ?>
                                <tr>
                                    <td class="name-cell"><?= e($s['sala']) ?: '<span class="text-muted fst-italic">(Sin sala)</span>' ?></td>
                                    <td class="num"><?= number_format($s['total'], 0, ',', '.') ?></td>
                                    <td class="num"><?= number_format($s['reparadas'], 0, ',', '.') ?></td>
                                    <td class="num"><?= number_format($s['en_curso'], 0, ',', '.') ?></td>
                                    <td class="num <?= (int)$s['sin_reparacion'] === 0 ? 'muted' : '' ?>"><?= number_format($s['sin_reparacion'], 0, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Top equipos -->
        <div class="col-12">
            <div class="dense-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6><i class="bi bi-pc-display me-1"></i>Top 10 Equipos con más ingresos</h6>
                </div>
                <table class="metricas-table">
                    <thead>
                        <tr>
                            <th style="width: 36px;">#</th>
                            <th>Equipo</th>
                            <th class="num" style="width: 100px;">Ingresos</th>
                            <th class="bar-cell" style="width: 40%;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topEquipos)): ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted fst-italic">Sin datos para el período.</td></tr>
                        <?php else:
                            $maxTotal = max(array_map(fn($e) => (int)$e['total'], $topEquipos));
                            $maxTotal = $maxTotal > 0 ? $maxTotal : 1;
                        ?>
                            <?php foreach ($topEquipos as $i => $eq):
                                $pctBar = round(((int)$eq['total'] / $maxTotal) * 100, 1);
                            ?>
                                <tr>
                                    <td class="text-muted"><?= $i + 1 ?></td>
                                    <td class="name-cell"><?= e($eq['equipo']) ?></td>
                                    <td class="num"><?= number_format($eq['total'], 0, ',', '.') ?></td>
                                    <td class="bar-cell">
                                        <div class="bar-bg">
                                            <div class="bar-fill" style="width: <?= $pctBar ?>%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($evolucion)):
    // Format bucket labels for the chart according to the period granularity
    $chartLabels = [];
    $chartTotal = [];
    $chartReparadas = [];
    foreach ($evolucion as $row) {
        $bucketRaw = $row['bucket'];
        switch ($bucket['gran']) {
            case 'hour':
                $label = substr($bucketRaw, 11, 5); // "HH:MM"
                break;
            case 'day':
                $dt = new DateTime($bucketRaw);
                $label = $dt->format('d/m');
                break;
            case 'week':
                $dt = new DateTime($bucketRaw);
                $label = 'Sem ' . $dt->format('d/m');
                break;
            case 'month':
            default:
                $dt = new DateTime($bucketRaw);
                $label = $dt->format('m/Y');
                break;
        }
        $chartLabels[] = $label;
        $chartTotal[] = (int)$row['total'];
        $chartReparadas[] = (int)$row['reparadas'];
    }
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ctx = document.getElementById('evolucionChart');
    if (!ctx || typeof Chart === 'undefined') return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [
                {
                    label: 'Ingresadas',
                    data: <?= json_encode($chartTotal) ?>,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.08)',
                    borderWidth: 2,
                    tension: 0.25,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                },
                {
                    label: 'Reparadas',
                    data: <?= json_encode($chartReparadas) ?>,
                    borderColor: '#16a34a',
                    backgroundColor: 'rgba(22, 163, 74, 0.08)',
                    borderWidth: 2,
                    tension: 0.25,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: { boxWidth: 10, boxHeight: 10, font: { size: 11 }, padding: 12 }
                },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleFont: { size: 12 },
                    bodyFont: { size: 12 },
                    padding: 10,
                    cornerRadius: 6,
                    displayColors: true,
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11 }, color: '#64748b', maxRotation: 0, autoSkip: true, maxTicksLimit: 14 }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: '#f1f5f9' },
                    ticks: { font: { size: 11 }, color: '#64748b', precision: 0 }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../public/includes/footer.php'; ?>
