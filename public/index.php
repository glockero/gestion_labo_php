<?php
// public/index.php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../app/ReparacionModel.php';
require_once __DIR__ . '/../app/CatalogoModel.php';

$is_tecnico = $user_role === 'tecnico';
$tabs_estado = [
    'URGENTES' => 'Urgentes',
    'PEND_REPARACION' => 'Pend. de Reparación',
    'EN_REPARACION' => 'En Reparación',
    'REPARADOS' => 'Reparados',
    'PENDIENTES' => 'Pendientes',
    'SIN_REPARACION' => 'Sin Reparación',
    'TODAS' => 'Todas'
];
if ($is_tecnico) {
    $tabs_estado = ['URGENTES' => 'Urgentes', 'MIS_REPARACIONES' => 'Mis Reparaciones'] + array_diff_key($tabs_estado, ['URGENTES' => true]);
}

$estado_defecto = $is_tecnico ? 'MIS_REPARACIONES' : 'PEND_REPARACION';
$estado_actual = strtoupper($_GET['estado'] ?? $estado_defecto);
if (!array_key_exists($estado_actual, $tabs_estado)) {
    $estado_actual = $estado_defecto;
}
$busqueda = $_GET['q'] ?? '';
$sala_filtro = $_GET['f_sala'] ?? '';
$tecnico_filtro = $_GET['f_tecnico'] ?? '';
$estado_filtro = $_GET['f_estado'] ?? '';
$anio_filtro = $_GET['f_anio'] ?? '';
$mes_filtro = $_GET['f_mes'] ?? '';
$dia_filtro = $_GET['f_dia'] ?? '';
$diasemana_filtro = $_GET['f_diasemana'] ?? '';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$filtros = [
    'estado' => $estado_actual,
    'busqueda' => $busqueda,
    'sala' => $sala_filtro,
    'tecnico_id' => $tecnico_filtro === 'SIN_ASIGNAR' ? null : $tecnico_filtro,
    'tecnico_sin_asignar' => $tecnico_filtro === 'SIN_ASIGNAR',
    'f_anio' => $anio_filtro,
    'f_mes' => $mes_filtro,
    'f_dia' => $dia_filtro,
    'f_diasemana' => $diasemana_filtro
];

if ($estado_filtro) {
    $filtros['estado_exacto'] = $estado_filtro;
}

$reparaciones = ReparacionModel::getList($filtros, $limit, $offset);
$total_registros = ReparacionModel::getCount($filtros);
$total_pages = ceil($total_registros / $limit);

$salas = CatalogoModel::getAll('salas');
$tecnicos = CatalogoModel::getAll('tecnicos');
$estados = CatalogoModel::getAll('estados');

$pdo = getDbConnection();
$anios_opt = $pdo->query("SELECT DISTINCT YEAR(fecha) as anio FROM reparaciones WHERE fecha IS NOT NULL ORDER BY anio DESC")->fetchAll();

$tab_counts = [];
foreach (array_keys($tabs_estado) as $tab_key) {
    $f_temp = $filtros;
    $f_temp['estado'] = $tab_key;
    unset($f_temp['estado_exacto']);
    $tab_counts[$tab_key] = ReparacionModel::getCount($f_temp);
}

$npus_repetidos = [];
$npu_list = array_filter(array_column($reparaciones, 'npu'));
if (count($npu_list) > 0) {
    $in = str_repeat('?,', count($npu_list) - 1) . '?';
    $stmtN = $pdo->prepare("SELECT npu FROM reparaciones WHERE npu IN ($in) GROUP BY npu HAVING COUNT(*) > 1");
    $stmtN->execute(array_values($npu_list));
    $npus_repetidos = $stmtN->fetchAll(PDO::FETCH_COLUMN);
}

?>
<style>
    :root {
        --border-color: #e5e7eb;
        --bg-light: #f8fafc;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --primary-blue: #2563eb;
    }

    /* Page Title */
    .page-title {
        font-size: 28px;
        font-weight: 700;
        color: var(--text-main);
        margin-bottom: 0;
        letter-spacing: -0.01em;
    }

    /* Search & Filter Header */
    .dashboard-header {
        background: #fff;
        border-radius: 8px;
        padding: 0.75rem 1rem;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
        border: 1px solid var(--border-color);
        margin-bottom: 1rem;
    }

    .search-input-wrapper {
        position: relative;
        flex-grow: 1;
    }
    
    .search-input-wrapper .bi-search {
        position: absolute;
        left: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 0.85rem;
    }

    .search-input {
        padding-left: 2.25rem;
        border-radius: 6px;
        height: 40px;
        font-size: 14px;
        border: 1px solid #d1d5db;
        background-color: var(--bg-light);
        transition: all 0.15s;
    }
    
    .search-input:focus {
        background-color: #fff;
        border-color: var(--primary-blue);
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
    }

    .dashboard-header .btn {
        height: 40px;
        padding: 0 1.25rem;
        font-size: 13px;
        display: inline-flex;
        align-items: center;
        border-radius: 6px;
        font-weight: 600;
        white-space: nowrap;
    }

    /* Compact Filter Cards */
    .filter-group-title {
        font-size: 12px;
        font-weight: 700;
        color: var(--text-muted);
        margin-bottom: 0.15rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .filter-select {
        height: 38px;
        font-size: 13px;
        border-radius: 6px;
        border-color: #d1d5db;
        padding: 0 1.5rem 0 0.5rem;
        background-color: var(--bg-light);
    }

    /* Tabs (Chips) */
    .nav-pills-custom {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        margin-bottom: 1rem;
    }

    .nav-tab-custom {
        border-radius: 6px;
        padding: 0.35rem 0.75rem;
        font-weight: 600;
        font-size: 13px;
        color: var(--text-muted);
        background: #fff;
        border: 1px solid var(--border-color);
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 0.35rem;
        transition: all 0.15s;
    }

    .nav-tab-custom:hover {
        background: var(--bg-light);
        color: var(--text-main);
    }

    .nav-tab-custom.active {
        color: #fff;
        border-color: transparent;
    }
    
    .nav-tab-custom[data-estado="URGENTES"] { 
        background-color: #ef4444; 
        color: #fff;
        border-color: #ef4444;
    }
    
    .nav-tab-custom[data-estado="URGENTES"]:hover {
        background-color: #dc2626;
        color: #fff;
    }

    .nav-tab-custom[data-estado="URGENTES"] .badge { 
        color: #dc2626 !important; 
        background: rgba(255,255,255,0.9) !important; 
        font-weight: 700; 
    }

    .nav-tab-custom[data-estado="PEND_REPARACION"].active { background-color: #2563eb; }
    .nav-tab-custom[data-estado="PEND_REPARACION"].active .badge { color: #2563eb !important; background: rgba(255,255,255,0.9) !important; font-weight: 700; }

    .nav-tab-custom[data-estado="EN_REPARACION"].active { background-color: #7c3aed; }
    .nav-tab-custom[data-estado="EN_REPARACION"].active .badge { color: #7c3aed !important; background: rgba(255,255,255,0.9) !important; font-weight: 700; }

    .nav-tab-custom[data-estado="REPARADOS"].active { background-color: #16a34a; }
    .nav-tab-custom[data-estado="REPARADOS"].active .badge { color: #16a34a !important; background: rgba(255,255,255,0.9) !important; font-weight: 700; }

    .nav-tab-custom[data-estado="PENDIENTES"].active { background-color: #d97706; }
    .nav-tab-custom[data-estado="PENDIENTES"].active .badge { color: #b45309 !important; background: rgba(255,255,255,0.9) !important; font-weight: 700; }

    .nav-tab-custom[data-estado="SIN_REPARACION"].active { background-color: #64748b; }
    .nav-tab-custom[data-estado="SIN_REPARACION"].active .badge { color: #475569 !important; background: rgba(255,255,255,0.9) !important; font-weight: 700; }

    .nav-tab-custom[data-estado="TODAS"].active { background-color: #f97316; }
    .nav-tab-custom[data-estado="TODAS"].active .badge { color: #ea580c !important; background: rgba(255,255,255,0.9) !important; font-weight: 700; }
    
    .nav-tab-custom[data-estado="MIS_REPARACIONES"].active { background-color: #0ea5e9; }
    .nav-tab-custom[data-estado="MIS_REPARACIONES"].active .badge { color: #0284c7 !important; background: rgba(255,255,255,0.9) !important; font-weight: 700; }

    .nav-tab-custom .badge {
        font-size: 12px;
        padding: 0.2em 0.4em;
        background: rgba(0,0,0,0.08) !important;
        color: inherit !important;
        border: none !important;
    }

    /* Table Styling */
    .table-container {
        background: #fff;
        border-radius: 8px;
        border: 1px solid var(--border-color);
        box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        overflow: hidden;
    }

    .table-scroll {
        max-height: calc(100vh - 330px);
        overflow-y: auto;
        overflow-x: auto;
    }

    .custom-table {
        margin-bottom: 0;
        font-size: 13.5px;
        width: 100%;
        table-layout: fixed;
        border-collapse: separate;
        border-spacing: 0;
    }

    .custom-table th, .custom-table td {
        overflow: hidden;
        vertical-align: middle;
    }

    .col-ingreso { width: 90px; }
    .col-identif { width: 130px; }
    .col-equipo { width: 220px; }
    .col-tecnico { width: 140px; }
    .col-estado { width: 140px; }
    .col-observaciones { width: auto; }
    .col-acciones { width: 110px; text-align: center; }

    .custom-table thead th {
        position: sticky;
        top: 0;
        z-index: 10;
        background-color: var(--bg-light);
        color: var(--text-muted);
        font-weight: 700;
        text-transform: uppercase;
        font-size: 12px;
        padding: 0.6rem 0.8rem;
        border-bottom: 1px solid var(--border-color);
        white-space: nowrap;
    }

    .custom-table tbody td {
        background: #ffffff;
        padding: 0.5rem 0.8rem;
        vertical-align: middle;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-main);
    }

    .custom-table tbody tr:last-child td {
        border-bottom: none;
    }

    .custom-table tbody tr:hover td {
        background-color: var(--bg-light);
    }

    /* Scrollbar moderna */
    .table-scroll::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    .table-scroll::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 8px;
    }

    .table-scroll::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 8px;
    }

    .table-scroll::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    /* Specific Cell Styles */
    .ingreso-cell {
        display: flex;
        flex-direction: column;
        gap: 2px;
        line-height: 1.2;
    }

    .ingreso-fecha {
        font-size: 13px;
        font-weight: 600;
        color: #0f172a;
    }

    .ingreso-hora {
        font-size: 12px;
        color: #64748b;
        font-weight: 500;
    }

    .cell-date {
        font-size: 13px;
        color: var(--text-muted);
        white-space: nowrap;
    }
    
    .identificacion-cell {
        display: flex;
        flex-direction: column;
        gap: 3px;
        line-height: 1.25;
    }

    .identificacion-cell .cell-label {
        color: #64748b;
        font-weight: 500;
        font-size: 13px;
    }

    .identificacion-cell strong {
        color: #0f172a;
        font-weight: 700;
        font-size: 14px;
    }

    .cell-equipo a { font-weight: 600; color: var(--primary-blue); font-size: 14px; }
    .cell-equipo .sala { font-size: 13px; color: var(--text-muted); margin-top: 1px; }

    .tech-avatar {
        width: 20px;
        height: 20px;
        font-size: 10px;
        background-color: #cbd5e1;
        color: #fff;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    
    .cell-tech { font-size: 13px; font-weight: 500; }
    .cell-obs { 
        font-size: 13px; 
        color: var(--text-muted); 
    }

    .observacion-cell {
        max-width: 100%;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .acciones-cell {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 6px 10px;
        font-size: 12px;
        font-weight: 700;
        border-radius: 4px;
        letter-spacing: 0.02em;
        text-transform: uppercase;
        white-space: normal;
        max-width: 130px;
        min-width: 110px;
        text-align: center;
        line-height: 1.15;
        word-break: normal;
    }
    
    .status-success { background-color: #ecfdf5; color: #166534; border: 1px solid #a7f3d0; }
    .status-warning { background-color: #fefce8; color: #854d0e; border: 1px solid #fde047; }
    .status-danger { background-color: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .status-info { background-color: #f0f9ff; color: #075985; border: 1px solid #bae6fd; }
    .status-secondary { background-color: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }

    .action-btn {
        width: 26px;
        height: 26px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        border: 1px solid var(--border-color);
        background: #fff;
        color: var(--text-muted);
        transition: all 0.15s;
    }
    
    .action-btn:hover { background: var(--bg-light); color: var(--text-main); }
    .action-btn.text-primary:hover { color: var(--primary-blue); border-color: var(--primary-blue); }
    .action-btn.text-danger:hover { color: #dc2626; border-color: #dc2626; }

    .urgency-badge {
        font-size: 11px;
        padding: 0.1rem 0.3rem;
        border-radius: 3px;
        background-color: #fee2e2;
        color: #b91c1c;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 2px;
        margin-top: 2px;
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="page-title">Seguimiento de Equipos</h2>
</div>

<!-- Buscador y Filtros -->
<div class="dashboard-header">
    <form method="GET" action="index.php" id="searchForm" class="d-flex flex-column gap-2">
        <input type="hidden" name="estado" value="<?= e($estado_actual) ?>">
        
        <div class="d-flex flex-column flex-md-row gap-2">
            <div class="search-input-wrapper">
                <i class="bi bi-search"></i>
                <input type="search" name="q" value="<?= e($busqueda) ?>" class="form-control search-input" placeholder="Buscar por UID, NPU, o palabras clave..." autocomplete="off">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-dark">Buscar</button>
                <?php if ($busqueda || $sala_filtro || $tecnico_filtro || $estado_filtro || $anio_filtro || $mes_filtro || $dia_filtro || $diasemana_filtro): ?>
                <a href="index.php?estado=<?= e($estado_actual) ?>" class="btn btn-outline-secondary" title="Limpiar filtros">
                    <i class="bi bi-eraser-fill me-md-1"></i><span class="d-none d-md-inline">Limpiar</span>
                </a>
                <?php endif; ?>
                <button type="submit" formaction="<?= APP_URL ?>/../exports/exportar_excel.php" class="btn btn-success border-0" style="background-color: #10b981;" title="Exportar a Excel">
                    <i class="bi bi-file-earmark-excel-fill me-md-1"></i><span class="d-none d-md-inline">Excel</span>
                </button>
            </div>
        </div>

        <div class="row g-2 align-items-end">
            <div class="col-12 col-xl-6">
                <div class="filter-group-title">Filtros de Fecha</div>
                <div class="d-flex flex-wrap flex-md-nowrap gap-2">
                    <select name="f_anio" class="form-select filter-select flex-fill" style="min-width: 90px;" onchange="this.form.submit()">
                        <option value="">Año (Todos)</option>
                        <?php foreach ($anios_opt as $a): ?>
                        <option value="<?= e($a['anio']) ?>" <?= $anio_filtro == $a['anio'] ? 'selected' : '' ?>><?= e($a['anio']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="f_mes" class="form-select filter-select flex-fill" style="min-width: 105px;" onchange="this.form.submit()">
                        <option value="">Mes (Todos)</option>
                        <?php 
                        $meses = ['01'=>'Enero', '02'=>'Febrero', '03'=>'Marzo', '04'=>'Abril', '05'=>'Mayo', '06'=>'Junio', '07'=>'Julio', '08'=>'Agosto', '09'=>'Septiembre', '10'=>'Octubre', '11'=>'Noviembre', '12'=>'Diciembre'];
                        foreach ($meses as $num => $nom): ?>
                            <option value="<?= $num ?>" <?= $mes_filtro === $num ? 'selected' : '' ?>><?= substr($nom, 0, 3) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="f_dia" class="form-select filter-select flex-fill" style="min-width: 90px;" onchange="this.form.submit()">
                        <option value="">Día (Todos)</option>
                        <?php for ($i=1; $i<=31; $i++): $d = sprintf("%02d", $i); ?>
                            <option value="<?= $d ?>" <?= $dia_filtro === $d ? 'selected' : '' ?>><?= $d ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="f_diasemana" class="form-select filter-select flex-fill" style="min-width: 105px;" onchange="this.form.submit()">
                        <option value="">Día Sem.</option>
                        <option value="1" <?= $diasemana_filtro === '1' ? 'selected' : '' ?>>Lun</option>
                        <option value="2" <?= $diasemana_filtro === '2' ? 'selected' : '' ?>>Mar</option>
                        <option value="3" <?= $diasemana_filtro === '3' ? 'selected' : '' ?>>Mié</option>
                        <option value="4" <?= $diasemana_filtro === '4' ? 'selected' : '' ?>>Jue</option>
                        <option value="5" <?= $diasemana_filtro === '5' ? 'selected' : '' ?>>Vie</option>
                        <option value="6" <?= $diasemana_filtro === '6' ? 'selected' : '' ?>>Sáb</option>
                        <option value="0" <?= $diasemana_filtro === '0' ? 'selected' : '' ?>>Dom</option>
                    </select>
                </div>
            </div>

            <div class="col-12 col-xl-6 mt-2 mt-xl-0">
                <div class="filter-group-title">Filtros Operativos</div>
                <div class="d-flex flex-wrap flex-md-nowrap gap-2">
                    <select name="f_sala" class="form-select filter-select flex-fill" style="min-width: 120px;" onchange="this.form.submit()">
                        <option value="">Todas las Salas</option>
                        <?php foreach ($salas as $s): ?>
                        <option value="<?= e($s['nombre']) ?>" <?= $sala_filtro === $s['nombre'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="f_estado" class="form-select filter-select flex-fill" style="min-width: 120px;" onchange="this.form.submit()">
                        <option value="">Todos los Estados</option>
                        <?php foreach ($estados as $e): ?>
                        <option value="<?= e($e['nombre']) ?>" <?= $estado_filtro === $e['nombre'] ? 'selected' : '' ?>><?= e($e['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="f_tecnico" class="form-select filter-select flex-fill" style="min-width: 120px;" onchange="this.form.submit()">
                        <option value="">Todos los Técnicos</option>
                        <option value="SIN_ASIGNAR" <?= $tecnico_filtro === 'SIN_ASIGNAR' ? 'selected' : '' ?>>Sin Asignar</option>
                        <?php foreach ($tecnicos as $t): ?>
                        <option value="<?= e($t['id']) ?>" <?= $tecnico_filtro == $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Nav Tabs (Chips) -->
<div class="nav-pills-custom">
    <?php foreach ($tabs_estado as $valor_estado => $etiqueta_estado): 
        $qParams = $_GET;
        $qParams['estado'] = $valor_estado;
        $qParams['page'] = 1;
        $url = '?' . http_build_query($qParams);
        $activeClass = $estado_actual === $valor_estado ? 'active' : '';
    ?>
        <a href="<?= $url ?>" class="nav-tab-custom <?= $activeClass ?>" data-estado="<?= $valor_estado ?>">
           <?php if ($valor_estado == 'URGENTES'): ?><i class="bi bi-exclamation-triangle-fill"></i>
           <?php elseif ($valor_estado == 'MIS_REPARACIONES'): ?><i class="bi bi-person-workspace"></i>
           <?php elseif ($valor_estado == 'PEND_REPARACION'): ?><i class="bi bi-inbox"></i>
           <?php elseif ($valor_estado == 'EN_REPARACION'): ?><i class="bi bi-tools"></i>
           <?php elseif ($valor_estado == 'REPARADOS'): ?><i class="bi bi-check2-circle"></i>
           <?php elseif ($valor_estado == 'SIN_REPARACION'): ?><i class="bi bi-x-octagon"></i>
           <?php elseif ($valor_estado == 'PENDIENTES'): ?><i class="bi bi-clock-history"></i>
           <?php else: ?><i class="bi bi-collection"></i><?php endif; ?>
           <?= $etiqueta_estado ?>
           <span class="badge rounded-pill"><?= $tab_counts[$valor_estado] ?? 0 ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- Table Data -->
<div class="table-container mb-4">
    <div class="table-scroll">
        <table class="table custom-table">
            <thead>
                <tr>
                    <th>Ingreso</th>
                    <th>Identificación</th>
                    <th>Equipo y Sala</th>
                    <th>Técnico</th>
                    <th>Estado</th>
                    <?php if ($estado_actual === 'EN REPARACION' || $estado_actual === 'EN_REPARACION'): ?>
                        <th>Inicio Rep.</th>
                    <?php endif; ?>
                    <?php if ($estado_actual === 'REPARADOS'): ?>
                        <th>Fecha Rep.</th>
                    <?php endif; ?>
                    <th>Observaciones</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reparaciones as $r): ?>
                <tr>
                    <td>
                        <?php 
                        $fecha_fmt = formatDatetimeArg($r['fecha']); 
                        $fecha_partes = explode(' ', $fecha_fmt);
                        ?>
                        <div class="ingreso-cell">
                            <span class="ingreso-fecha"><?= e($fecha_partes[0] ?? '') ?></span>
                            <span class="ingreso-hora"><?= e($fecha_partes[1] ?? '') ?></span>
                        </div>
                    </td>
                    
                    <td>
                        <div class="identificacion-cell">
                            <div>
                                <span class="cell-label">UID:</span>
                                <strong><?= e($r['uid']) ?: '--' ?></strong>
                            </div>
                            <div>
                                <span class="cell-label">NPU:</span>
                                <strong><?= e($r['npu']) ?: '--' ?></strong>
                                <?php if ($r['npu'] && in_array(trim($r['npu']), $npus_repetidos)): ?>
                                    <span class="badge bg-warning text-dark border border-warning ms-1" style="font-size: 10px; padding: 0.15rem 0.3rem;" title="Este equipo ya fue reparado con anterioridad">R</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    
                    <td class="cell-equipo">
                        <a href="reparacion_detalle.php?id=<?= $r['id'] ?>" class="text-decoration-none" title="Ver Ficha Técnica">
                            <?= e($r['equipo']) ?>
                        </a>
                        <div class="sala">
                            <i class="bi bi-geo-alt-fill me-1 text-danger opacity-75"></i><?= e($r['sala']) ?>
                        </div>
                    </td>
                    
                    <td class="cell-tech">
                        <?php if ($r['tecnico_nombre']): ?>
                            <div class="d-flex align-items-center gap-2">
                                <div class="tech-avatar"><?= strtoupper(substr(e($r['tecnico_nombre']), 0, 1)) ?></div>
                                <span><?= e($r['tecnico_nombre']) ?></span>
                            </div>
                        <?php else: ?>
                            <span class="text-muted fst-italic" style="font-size: 12px;">Sin asignar</span>
                        <?php endif; ?>
                    </td>
                    
                    <td>
                        <?php 
                        $badge_class = 'status-secondary';
                        $est = strtoupper($r['estado']);
                        if (strpos($est, 'REPARADO') !== false) $badge_class = 'status-success';
                        elseif (strpos($est, 'PEND') !== false) $badge_class = 'status-warning';
                        elseif (strpos($est, 'SIN REPARACION') !== false) $badge_class = 'status-danger';
                        elseif (strpos($est, 'PRUEBA') !== false) $badge_class = 'status-info';
                        
                        $display_estado = e($r['estado']);
                        if ($est === 'PENDIENTE DE REPUESTO') {
                            $display_estado = 'PENDIENTE DE<br>REPUESTO';
                        } elseif ($est === 'PEND. DE REVISION' || $est === 'PENDIENTE DE REVISION') {
                            $display_estado = 'PENDIENTE DE<br>REVISIÓN';
                        } elseif ($est === 'EN REPARACION') {
                            $display_estado = 'EN<br>REPARACIÓN';
                        } elseif ($est === 'SIN REPARACION') {
                            $display_estado = 'SIN<br>REPARACIÓN';
                        }
                        ?>
                        <div class="d-flex flex-column align-items-start gap-1">
                            <span class="status-pill <?= $badge_class ?>"><?= $display_estado ?></span>
                            <?php if ($r['urgente'] === 'SI'): ?>
                                <span class="urgency-badge"><i class="bi bi-exclamation-triangle-fill"></i> URG</span>
                            <?php endif; ?>
                        </div>
                    </td>

                    <?php if ($estado_actual === 'EN REPARACION' || $estado_actual === 'EN_REPARACION'): ?>
                        <td class="cell-date fw-bold text-dark">
                            <?= $r['fecha_en_reparacion'] ? date('d/m/y', strtotime($r['fecha_en_reparacion'])) : '--' ?>
                        </td>
                    <?php endif; ?>

                    <?php if ($estado_actual === 'REPARADOS'): ?>
                        <td class="cell-date fw-bold text-dark">
                            <?= $r['fecha_reparado'] ? date('d/m/y', strtotime($r['fecha_reparado'])) : '--' ?>
                        </td>
                    <?php endif; ?>

                    <td class="cell-obs">
                        <div class="observacion-cell" title="<?= e($r['observaciones']) ?>">
                            <?= e($r['observaciones']) ?: '<span class="fst-italic opacity-50">Sin comentarios</span>' ?>
                        </div>
                    </td>
                    
                    <td class="text-end">
                        <div class="acciones-cell">
                            <a href="reparacion_detalle.php?id=<?= $r['id'] ?>" class="action-btn" title="Ver Ficha">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if ($user_role === 'admin'): ?>
                            <a href="reparacion_editar.php?id=<?= $r['id'] ?>" class="action-btn text-primary" title="Editar">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <button type="button" class="action-btn text-danger" title="Eliminar" onclick="confirmDelete(<?= $r['id'] ?>)">
                                <i class="bi bi-trash3"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if (empty($reparaciones)): ?>
    <div class="text-center py-4">
        <div class="text-muted opacity-25 mb-2" style="font-size: 2rem;"><i class="bi bi-inbox"></i></div>
        <h6 class="text-muted fw-bold mb-1">No se encontraron registros</h6>
        <p class="text-secondary small mb-0">Modificá los filtros para buscar nuevamente.</p>
    </div>
    <?php endif; ?>
    
    <!-- Footer / Pagination -->
    <div class="p-2 px-3 bg-light border-top d-flex justify-content-between align-items-center">
        <span class="text-muted" style="font-size: 12px;">
            Página <b><?= $page ?></b> de <?= max(1, $total_pages) ?>
        </span>
        
        <nav aria-label="Navegación">
            <ul class="pagination pagination-sm mb-0">
                <?php
                $qParams = $_GET;
                $prevDisabled = $page <= 1 ? 'disabled' : '';
                $qParams['page'] = max(1, $page - 1);
                ?>
                <li class="page-item <?= $prevDisabled ?>">
                    <a class="page-link shadow-none" href="?<?= http_build_query($qParams) ?>"><i class="bi bi-chevron-left"></i></a>
                </li>

                <?php for($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): 
                    $active = $page == $i ? 'active' : '';
                    $qParams['page'] = $i;
                ?>
                    <li class="page-item <?= $active ?>">
                        <a class="page-link shadow-none" href="?<?= http_build_query($qParams) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>

                <?php
                $nextDisabled = $page >= $total_pages ? 'disabled' : '';
                $qParams['page'] = min($total_pages, $page + 1);
                ?>
                <li class="page-item <?= $nextDisabled ?>">
                    <a class="page-link shadow-none" href="?<?= http_build_query($qParams) ?>"><i class="bi bi-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
    </div>
</div>

<div class="modal fade" id="modalEliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 340px;">
        <div class="modal-content border-0 shadow-sm" style="border-radius: 12px;">
            <div class="modal-body text-center p-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-danger bg-opacity-10 text-danger rounded-circle mb-3" style="width: 52px; height: 52px;">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.5rem;"></i>
                </div>
                <h6 class="fw-bold text-dark mb-1" style="font-size: 0.95rem;">Eliminar Reparación</h6>
                <p class="text-muted mb-4" style="font-size: 0.8rem; line-height: 1.4;">Esta acción es permanente y no se puede deshacer. ¿Confirmar eliminación?</p>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light flex-grow-1 fw-bold text-secondary border" style="font-size: 0.8rem; padding: 0.5rem;" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" action="../admin/eliminar_reparacion.php" id="deleteForm" class="flex-grow-1">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="id" id="deleteId">
                        <button type="submit" class="btn btn-danger w-100 fw-bold shadow-none" style="font-size: 0.8rem; padding: 0.5rem;">Eliminar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id) {
    document.getElementById('deleteId').value = id;
    new bootstrap.Modal(document.getElementById('modalEliminar')).show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>