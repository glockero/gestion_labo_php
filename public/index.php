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
$solo_urgentes = !empty($_GET['solo_urgentes']);

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page_allowed = [25, 50, 100, 200];
$per_page = (int)($_GET['per_page'] ?? 50);
if (!in_array($per_page, $per_page_allowed, true)) $per_page = 50;
$limit = $per_page;
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
    'f_diasemana' => $diasemana_filtro,
    'solo_urgentes' => $solo_urgentes,
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

$meses = ['01'=>'Enero', '02'=>'Febrero', '03'=>'Marzo', '04'=>'Abril', '05'=>'Mayo', '06'=>'Junio', '07'=>'Julio', '08'=>'Agosto', '09'=>'Septiembre', '10'=>'Octubre', '11'=>'Noviembre', '12'=>'Diciembre'];
$dias_semana = ['0'=>'Domingo', '1'=>'Lunes', '2'=>'Martes', '3'=>'Miércoles', '4'=>'Jueves', '5'=>'Viernes', '6'=>'Sábado'];

// Helper: build current URL minus a single filter param (preserves the rest)
function urlSinFiltro($param) {
    $q = $_GET;
    unset($q[$param]);
    $q['page'] = 1;
    return '?' . http_build_query($q);
}

// Build the list of active filters (label + value + key to remove)
$tecnicoNombreMap = array_column($tecnicos, 'nombre', 'id');
$active_filters = [];
if ($busqueda !== '')         $active_filters[] = ['key'=>'q',          'label'=>'Búsqueda', 'value'=>$busqueda];
if ($sala_filtro !== '')      $active_filters[] = ['key'=>'f_sala',     'label'=>'Sala',     'value'=>$sala_filtro];
if ($tecnico_filtro !== '') {
    $tecVal = $tecnico_filtro === 'SIN_ASIGNAR'
        ? 'Sin asignar'
        : ($tecnicoNombreMap[$tecnico_filtro] ?? "ID $tecnico_filtro");
    $active_filters[] = ['key'=>'f_tecnico',  'label'=>'Técnico',  'value'=>$tecVal];
}
if ($estado_filtro !== '')    $active_filters[] = ['key'=>'f_estado',   'label'=>'Estado',   'value'=>$estado_filtro];
if ($anio_filtro !== '')      $active_filters[] = ['key'=>'f_anio',     'label'=>'Año',      'value'=>$anio_filtro];
if ($mes_filtro !== '')       $active_filters[] = ['key'=>'f_mes',      'label'=>'Mes',      'value'=>$meses[$mes_filtro] ?? $mes_filtro];
if ($dia_filtro !== '')       $active_filters[] = ['key'=>'f_dia',      'label'=>'Día',      'value'=>$dia_filtro];
if ($diasemana_filtro !== '') $active_filters[] = ['key'=>'f_diasemana','label'=>'Día sem.', 'value'=>$dias_semana[$diasemana_filtro] ?? $diasemana_filtro];
if ($solo_urgentes)           $active_filters[] = ['key'=>'solo_urgentes','label'=>'Urgencia', 'value'=>'Solo urgentes'];

$pdo = getDbConnection();
$anios_opt = $pdo->query("SELECT DISTINCT YEAR(fecha) as anio FROM reparaciones WHERE fecha IS NOT NULL ORDER BY anio DESC")->fetchAll();

$tab_counts = ReparacionModel::getTabCounts($filtros);

// Map of [npu => total_ingresos] for NPUs that appear more than once across
// the whole repairs table. Only NPUs present in the current page are queried.
$npus_repetidos = [];
$npu_list = array_filter(array_column($reparaciones, 'npu'));
if (count($npu_list) > 0) {
    $in = str_repeat('?,', count($npu_list) - 1) . '?';
    $stmtN = $pdo->prepare("SELECT npu, COUNT(*) AS cnt FROM reparaciones WHERE npu IN ($in) GROUP BY npu HAVING COUNT(*) > 1");
    $stmtN->execute(array_values($npu_list));
    foreach ($stmtN->fetchAll() as $row) {
        $npus_repetidos[$row['npu']] = (int)$row['cnt'];
    }
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
        padding: 0.5rem 0.75rem;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
        border: 1px solid var(--border-color);
        margin-bottom: 0.75rem;
    }

    .search-input-wrapper {
        position: relative;
        flex-grow: 1;
    }

    .search-input-wrapper .bi-search {
        position: absolute;
        left: 0.6rem;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 0.8rem;
    }

    .search-input {
        padding-left: 1.9rem;
        border-radius: 6px;
        height: 32px;
        font-size: 12.5px;
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
        height: 32px;
        padding: 0 0.85rem;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        border-radius: 6px;
        font-weight: 600;
        white-space: nowrap;
    }

    /* Compact Filter Cards */
    .filter-group-title {
        font-size: 10.5px;
        font-weight: 700;
        color: var(--text-muted);
        margin-bottom: 0.1rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .filter-select {
        height: 30px;
        font-size: 12px;
        border-radius: 6px;
        border-color: #d1d5db;
        padding: 0 1.4rem 0 0.45rem;
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
    
    /* URGENTES inactive: red-tinted outline so it still stands out among neutral tabs */
    .nav-tab-custom[data-estado="URGENTES"] {
        background-color: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    .nav-tab-custom[data-estado="URGENTES"]:hover {
        background-color: #fee2e2;
        color: #991b1b;
        border-color: #fca5a5;
    }

    .nav-tab-custom[data-estado="URGENTES"].active {
        background-color: #ef4444;
        color: #fff;
        border-color: #ef4444;
    }

    .nav-tab-custom[data-estado="URGENTES"].active:hover {
        background-color: #dc2626;
        color: #fff;
        border-color: #dc2626;
    }

    .nav-tab-custom[data-estado="URGENTES"] .badge {
        color: #b91c1c !important;
        background: #fee2e2 !important;
        font-weight: 700;
    }

    .nav-tab-custom[data-estado="URGENTES"].active .badge {
        color: #dc2626 !important;
        background: rgba(255, 255, 255, 0.9) !important;
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

    /* Color the icon of each tab to its category so inactive tabs still hint
       at their meaning instead of being a uniform gray row. */
    .nav-tab-custom[data-estado="PEND_REPARACION"]:not(.active)  .bi { color: #2563eb; }
    .nav-tab-custom[data-estado="EN_REPARACION"]:not(.active)    .bi { color: #7c3aed; }
    .nav-tab-custom[data-estado="REPARADOS"]:not(.active)        .bi { color: #16a34a; }
    .nav-tab-custom[data-estado="PENDIENTES"]:not(.active)       .bi { color: #d97706; }
    .nav-tab-custom[data-estado="SIN_REPARACION"]:not(.active)   .bi { color: #64748b; }
    .nav-tab-custom[data-estado="TODAS"]:not(.active)            .bi { color: #f97316; }
    .nav-tab-custom[data-estado="MIS_REPARACIONES"]:not(.active) .bi { color: #0ea5e9; }

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
        max-height: calc(100vh - 360px);
        overflow-y: auto;
        overflow-x: auto;
    }
    /* When the active-filters chip bar is hidden, reclaim its ~50px so one
       more row becomes visible without making the page scroll. */
    .table-scroll.no-chips {
        max-height: calc(100vh - 310px);
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

    .col-ingreso { width: 95px; white-space: nowrap; }
    .col-identif { width: 140px; white-space: nowrap; }
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
    .identificacion-cell > div {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
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
        width: 22px;
        height: 22px;
        font-size: 10.5px;
        font-weight: 700;
        background-color: #cbd5e1;
        color: #fff;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    /* Urgent rows: red outline only (no background tint) */
    .urgent-row td {
        box-shadow: inset 0 1px 0 0 #ef4444, inset 0 -1px 0 0 #ef4444;
    }
    .urgent-row td:first-child {
        box-shadow: inset 1px 0 0 0 #ef4444, inset 0 1px 0 0 #ef4444, inset 0 -1px 0 0 #ef4444;
    }
    .urgent-row td:last-child {
        box-shadow: inset -1px 0 0 0 #ef4444, inset 0 1px 0 0 #ef4444, inset 0 -1px 0 0 #ef4444;
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

    /* Active filter chips */
    .active-filters {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap;
        margin-bottom: 0.75rem;
        padding: 0.45rem 0.75rem;
        background: #f8fafc;
        border: 1px solid var(--border-color);
        border-radius: 6px;
    }
    .active-filters .af-label {
        font-size: 11px;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-right: 0.2rem;
    }
    .filter-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.18rem 0.4rem 0.18rem 0.65rem;
        border: 1px solid #cbd5e1;
        border-radius: 999px;
        background: #fff;
        font-size: 12px;
        color: #334155;
        line-height: 1.2;
    }
    .filter-chip strong {
        font-weight: 700;
        color: #0f172a;
        max-width: 180px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .filter-chip .chip-remove {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: #f1f5f9;
        color: #64748b;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        font-size: 12px;
        line-height: 1;
        padding: 0;
        font-weight: 700;
        transition: all 0.12s;
    }
    .filter-chip .chip-remove:hover {
        background: #ef4444;
        color: #fff;
    }
    .active-filters .clear-all {
        font-size: 12px;
        color: #ef4444;
        text-decoration: none;
        font-weight: 600;
        margin-left: auto;
    }
    .active-filters .clear-all:hover { text-decoration: underline; }

    /* Per-page selector in the footer */
    .per-page-select {
        height: 28px;
        font-size: 12px;
        padding: 0 1.5rem 0 0.5rem;
        border: 1px solid var(--border-color);
        border-radius: 4px;
        background: #fff;
    }

    /* NPU history badge (clickable) */
    .npu-history-btn {
        transition: all 0.12s;
    }
    .npu-history-btn:hover {
        background-color: #d97706 !important;
        color: #fff !important;
        transform: scale(1.06);
    }

    /* "Solo urgentes" toggle */
    .urg-toggle {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        height: 32px;
        padding: 0 0.75rem;
        font-size: 12px;
        font-weight: 600;
        color: #b91c1c;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 6px;
        cursor: pointer;
        user-select: none;
        white-space: nowrap;
        transition: all 0.12s;
        margin: 0;
    }
    .urg-toggle:hover {
        background: #fee2e2;
        border-color: #fca5a5;
    }
    .urg-toggle input {
        margin: 0;
        accent-color: #ef4444;
    }
    .urg-toggle.is-active {
        background: #ef4444;
        border-color: #ef4444;
        color: #fff;
    }
    .urg-toggle.is-active input { accent-color: #fff; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="page-title">Seguimiento de Equipos</h2>
</div>

<!-- Buscador y Filtros -->
<div class="dashboard-header">
    <form method="GET" action="index.php" id="searchForm" class="d-flex flex-column gap-2">
        <input type="hidden" name="estado" value="<?= e($estado_actual) ?>">
        <input type="hidden" name="per_page" value="<?= e($per_page) ?>">
        
        <div class="d-flex flex-column flex-md-row gap-2">
            <div class="search-input-wrapper">
                <i class="bi bi-search"></i>
                <input type="search" name="q" value="<?= e($busqueda) ?>" class="form-control search-input" placeholder="Buscar por UID, NPU, o palabras clave..." autocomplete="off">
            </div>
            <div class="d-flex gap-2">
                <label class="urg-toggle <?= $solo_urgentes ? 'is-active' : '' ?>" title="Mostrar solo reparaciones urgentes">
                    <input type="checkbox" name="solo_urgentes" value="1" <?= $solo_urgentes ? 'checked' : '' ?> onchange="this.form.submit()">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>Urgentes</span>
                </label>
                <button type="submit" class="btn btn-dark">Buscar</button>
                <?php if ($busqueda || $sala_filtro || $tecnico_filtro || $estado_filtro || $anio_filtro || $mes_filtro || $dia_filtro || $diasemana_filtro): ?>
                <a href="index.php?estado=<?= e($estado_actual) ?>" class="btn btn-outline-secondary" title="Limpiar filtros">
                    <i class="bi bi-eraser-fill me-md-1"></i><span class="d-none d-md-inline">Limpiar</span>
                </a>
                <?php endif; ?>
                <button type="submit" formaction="../exports/exportar_excel.php" class="btn btn-success border-0" style="background-color: #10b981;" title="Exportar a Excel">
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
                        <?php foreach ($meses as $num => $nom): ?>
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

<?php if (!empty($active_filters)): ?>
<div class="active-filters">
    <span class="af-label"><i class="bi bi-funnel-fill me-1"></i>Filtros activos:</span>
    <?php foreach ($active_filters as $f): ?>
        <span class="filter-chip">
            <?= e($f['label']) ?>: <strong title="<?= e($f['value']) ?>"><?= e($f['value']) ?></strong>
            <a href="<?= urlSinFiltro($f['key']) ?>" class="chip-remove" title="Quitar este filtro">×</a>
        </span>
    <?php endforeach; ?>
    <a href="index.php?estado=<?= e($estado_actual) ?>&per_page=<?= e($per_page) ?>" class="clear-all">
        <i class="bi bi-x-circle me-1"></i>Limpiar todos
    </a>
</div>
<?php endif; ?>

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
    <div class="table-scroll <?= empty($active_filters) ? 'no-chips' : '' ?>">
        <table class="table custom-table">
            <thead>
                <tr>
                    <th class="col-ingreso">Ingreso</th>
                    <th class="col-identif">Identificación</th>
                    <th>Equipo y Sala</th>
                    <th>Técnico</th>
                    <th>Estado</th>
                    <?php if ($estado_actual === 'EN_REPARACION'): ?>
                        <th style="width: 95px;">Inicio Rep.</th>
                    <?php endif; ?>
                    <?php if ($estado_actual === 'REPARADOS'): ?>
                        <th style="width: 95px;">Fecha Rep.</th>
                        <th class="text-end" style="width: 115px;">Ahorro</th>
                    <?php endif; ?>
                    <th>Observaciones</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $avatarPalette = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899', '#475569'];
                foreach ($reparaciones as $r):
                    $isUrgent = $r['urgente'] === 'SI';
                ?>
                <tr<?= $isUrgent ? ' class="urgent-row"' : '' ?>>
                    <td class="col-ingreso">
                        <?php
                        $fecha_fmt = formatDatetimeArg($r['fecha']);
                        $fecha_partes = explode(' ', $fecha_fmt);
                        ?>
                        <div class="ingreso-cell">
                            <span class="ingreso-fecha"><?= e($fecha_partes[0] ?? '') ?></span>
                            <span class="ingreso-hora"><?= e($fecha_partes[1] ?? '') ?></span>
                        </div>
                    </td>
                    
                    <td class="col-identif">
                        <div class="identificacion-cell">
                            <div title="UID: <?= e($r['uid']) ?: '--' ?>">
                                <span class="cell-label">UID:</span>
                                <strong><?= e($r['uid']) ?: '--' ?></strong>
                            </div>
                            <div title="NPU: <?= e($r['npu']) ?: '--' ?>">
                                <span class="cell-label">NPU:</span>
                                <strong><?= e($r['npu']) ?: '--' ?></strong>
                                <?php if ($r['npu'] && isset($npus_repetidos[trim($r['npu'])])):
                                    $reps = $npus_repetidos[trim($r['npu'])];
                                ?>
                                    <button type="button" class="badge bg-warning text-dark border border-warning ms-1 npu-history-btn"
                                            style="font-size: 10px; padding: 0.15rem 0.3rem; cursor: pointer; line-height: 1;"
                                            title="Ver las <?= $reps ?> reparaciones de este NPU"
                                            data-npu="<?= e(trim($r['npu'])) ?>"
                                            data-current-id="<?= e($r['id']) ?>"><?= $reps ?></button>
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
                        <?php if ($r['tecnico_nombre']):
                            $avatarColor = $avatarPalette[abs(crc32($r['tecnico_nombre'])) % count($avatarPalette)];
                        ?>
                            <div class="d-flex align-items-center gap-2">
                                <div class="tech-avatar" style="background-color: <?= $avatarColor ?>;"><?= e(mb_strtoupper(mb_substr($r['tecnico_nombre'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
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
                        ?>
                        <span class="status-pill <?= $badge_class ?>"><?= e($r['estado']) ?></span>
                    </td>

                    <?php if ($estado_actual === 'EN_REPARACION'): ?>
                        <td class="cell-date fw-bold text-dark">
                            <?= $r['fecha_en_reparacion'] ? date('d/m/y', strtotime($r['fecha_en_reparacion'])) : '--' ?>
                        </td>
                    <?php endif; ?>

                    <?php if ($estado_actual === 'REPARADOS'): ?>
                        <td class="cell-date fw-bold text-dark">
                            <?= $r['fecha_reparado'] ? date('d/m/y', strtotime($r['fecha_reparado'])) : '--' ?>
                        </td>
                        <td class="text-end text-nowrap fw-bold text-dark" style="font-variant-numeric: tabular-nums;">
                            <?php $costo = formatArs($r['equipo_valor'] ?? null); ?>
                            <?= $costo !== null ? e($costo) : '<span class="text-muted fw-normal">—</span>' ?>
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
                            <button type="button" class="action-btn text-danger" title="Eliminar"
                                    onclick="confirmDelete(this)"
                                    data-id="<?= $r['id'] ?>"
                                    data-equipo="<?= e($r['equipo']) ?>"
                                    data-sala="<?= e($r['sala']) ?>"
                                    data-npu="<?= e($r['npu'] ?? '') ?>"
                                    data-fecha="<?= e(formatDatetimeArg($r['fecha'])) ?>">
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
    <div class="p-2 px-3 bg-light border-top d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <span class="text-muted" style="font-size: 12px;">
            <?php
                $start = $total_registros > 0 ? $offset + 1 : 0;
                $end = min($offset + count($reparaciones), $total_registros);
            ?>
            Mostrando <b><?= number_format($start, 0, ',', '.') ?>–<?= number_format($end, 0, ',', '.') ?></b>
            de <b><?= number_format($total_registros, 0, ',', '.') ?></b> reparaciones
            · Página <b><?= $page ?></b> de <?= max(1, $total_pages) ?>
        </span>

        <div class="d-flex align-items-center gap-2">
            <label class="text-muted m-0" style="font-size: 12px;">Por página:</label>
            <select id="perPageSelect" class="per-page-select">
                <?php foreach ($per_page_allowed as $opt): ?>
                    <option value="<?= $opt ?>" <?= $per_page === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>

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
</div>

<!-- NPU history modal: lists every repair for a given NPU -->
<div class="modal fade" id="npuHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 10px;">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title fw-bold m-0" style="font-size: 0.9rem;">
                    <i class="bi bi-clock-history me-1"></i>
                    Historial del NPU
                    <code id="npuHistoryNpu" class="ms-1" style="font-size: 0.8rem; background: #f1f5f9; padding: 1px 6px; border-radius: 4px;"></code>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-0">
                <div id="npuHistoryLoading" class="text-center py-4 text-muted" style="font-size: 0.85rem;">
                    <div class="spinner-border spinner-border-sm me-2"></div> Cargando...
                </div>
                <div id="npuHistoryEmpty" class="text-center py-4 text-muted" style="font-size: 0.85rem; display: none;">
                    No se encontraron reparaciones para este NPU.
                </div>
                <div id="npuHistoryWrapper" style="display: none;">
                    <table class="table table-sm mb-0" style="font-size: 0.78rem;">
                        <thead style="position: sticky; top: 0; background: #f8fafc; z-index: 1;">
                            <tr>
                                <th style="width: 60px; padding: 0.4rem 0.6rem;">ID</th>
                                <th style="width: 110px; padding: 0.4rem 0.6rem;">Fecha</th>
                                <th style="padding: 0.4rem 0.6rem;">Equipo</th>
                                <th style="padding: 0.4rem 0.6rem;">Sala</th>
                                <th style="padding: 0.4rem 0.6rem;">Técnico</th>
                                <th style="padding: 0.4rem 0.6rem;">Estado</th>
                                <th style="width: 50px; padding: 0.4rem 0.6rem;"></th>
                            </tr>
                        </thead>
                        <tbody id="npuHistoryRows"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2 px-3" style="font-size: 0.75rem;">
                <span class="text-muted me-auto" id="npuHistoryCount"></span>
                <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal" style="font-size: 0.75rem; padding: 0.3rem 0.7rem;">Cerrar</button>
            </div>
        </div>
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
                <div class="bg-light border rounded-3 px-3 py-2 my-3 text-start" style="font-size: 0.78rem;">
                    <div class="fw-bold text-dark" style="font-size: 0.9rem;" id="deleteEquipo">—</div>
                    <div class="text-muted mt-1">
                        <i class="bi bi-geo-alt-fill text-danger opacity-75 me-1"></i><span id="deleteSala">—</span>
                    </div>
                    <div class="text-muted">
                        <i class="bi bi-tag-fill opacity-50 me-1"></i>NPU: <span id="deleteNpu">—</span>
                    </div>
                    <div class="text-muted">
                        <i class="bi bi-calendar3 opacity-50 me-1"></i><span id="deleteFecha">—</span>
                    </div>
                </div>
                <p class="text-danger mb-4" style="font-size: 0.78rem; line-height: 1.4;">
                    <i class="bi bi-exclamation-circle-fill me-1"></i>Esta acción es permanente y no se puede deshacer.
                </p>
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
function confirmDelete(btn) {
    document.getElementById('deleteId').value = btn.dataset.id;
    document.getElementById('deleteEquipo').textContent = btn.dataset.equipo || '—';
    document.getElementById('deleteSala').textContent = btn.dataset.sala || '—';
    document.getElementById('deleteNpu').textContent = btn.dataset.npu || '—';
    document.getElementById('deleteFecha').textContent = btn.dataset.fecha || '—';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEliminar')).show();
}

// Per-page selector: rebuild URL preserving every other filter, reset to page 1
document.getElementById('perPageSelect')?.addEventListener('change', function () {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', this.value);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
});

// --- NPU history modal: click on the count badge to load all repairs for that NPU ---
(function () {
    const modalEl    = document.getElementById('npuHistoryModal');
    const loadingEl  = document.getElementById('npuHistoryLoading');
    const emptyEl    = document.getElementById('npuHistoryEmpty');
    const wrapperEl  = document.getElementById('npuHistoryWrapper');
    const tbodyEl    = document.getElementById('npuHistoryRows');
    const npuLabel   = document.getElementById('npuHistoryNpu');
    const countLabel = document.getElementById('npuHistoryCount');
    if (!modalEl) return;

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
        })[c]);
    }

    function statusClass(estado) {
        const e = (estado || '').toUpperCase();
        if (e.includes('REPARADO')) return 'status-success';
        if (e.includes('SIN REPARACION')) return 'status-danger';
        if (e.includes('PEND')) return 'status-warning';
        if (e.includes('PRUEBA')) return 'status-info';
        return 'status-secondary';
    }

    function showState(state) {
        loadingEl.style.display = state === 'loading' ? '' : 'none';
        emptyEl.style.display   = state === 'empty'   ? '' : 'none';
        wrapperEl.style.display = state === 'data'    ? '' : 'none';
    }

    async function openNpuHistory(npu, currentId) {
        npuLabel.textContent = npu;
        countLabel.textContent = '';
        tbodyEl.innerHTML = '';
        showState('loading');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();

        try {
            const res = await fetch('../api/reparaciones_por_npu.php?npu=' + encodeURIComponent(npu));
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            const reps = data.reparaciones || [];

            if (reps.length === 0) {
                showState('empty');
                return;
            }

            const curId = String(currentId ?? '');
            tbodyEl.innerHTML = reps.map(r => {
                const isCurrent = curId !== '' && String(r.id) === curId;
                const rowBg = isCurrent ? '#dbeafe' : (r.urgente === 'SI' ? '#fef2f2' : '');
                const rowStyle = rowBg
                    ? ` style="background: ${rowBg};${isCurrent ? ' border-left: 3px solid #2563eb;' : ''}"`
                    : '';
                const currentBadge = isCurrent
                    ? ` <span class="badge bg-primary ms-1" style="font-size: 9px; padding: 2px 5px; vertical-align: middle;">ACTUAL</span>`
                    : '';
                return `
                <tr${rowStyle}>
                    <td style="padding: 0.35rem 0.6rem;"><strong>#${esc(r.id)}</strong>${currentBadge}</td>
                    <td style="padding: 0.35rem 0.6rem; white-space: nowrap;">${esc(r.fecha_fmt)}</td>
                    <td style="padding: 0.35rem 0.6rem;">${esc(r.equipo)}</td>
                    <td style="padding: 0.35rem 0.6rem;">${esc(r.sala)}</td>
                    <td style="padding: 0.35rem 0.6rem;">${esc(r.tecnico_nombre || '—')}</td>
                    <td style="padding: 0.35rem 0.6rem;"><span class="status-pill ${statusClass(r.estado)}" style="font-size: 10px; min-width: 0; padding: 3px 6px;">${esc(r.estado)}</span></td>
                    <td style="padding: 0.35rem 0.6rem; text-align: center;">
                        ${isCurrent
                            ? '<span class="text-muted" title="Estás viendo esta fila"><i class="bi bi-geo-alt-fill"></i></span>'
                            : `<a href="reparacion_detalle.php?id=${esc(r.id)}" class="action-btn" title="Abrir ficha"><i class="bi bi-box-arrow-up-right"></i></a>`}
                    </td>
                </tr>
            `;
            }).join('');

            countLabel.textContent = `${reps.length} ingreso${reps.length === 1 ? '' : 's'} para este NPU`;
            showState('data');
        } catch (e) {
            console.error('Error loading NPU history', e);
            emptyEl.textContent = 'Error al cargar el historial. Intentá nuevamente.';
            showState('empty');
        }
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.npu-history-btn');
        if (!btn) return;
        e.preventDefault();
        openNpuHistory(btn.dataset.npu, btn.dataset.currentId);
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>