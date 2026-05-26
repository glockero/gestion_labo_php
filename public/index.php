<?php
// public/index.php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../app/ReparacionModel.php';
require_once __DIR__ . '/../app/CatalogoModel.php';

$is_tecnico = $user_role === 'tecnico';
$tabs_estado = [
    'URGENTES' => 'Urgentes',
    'PEND_REPARACION' => 'Pend. Rep.',
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
$mis_pendientes = $is_tecnico
    ? ReparacionModel::getTecnicoPendientesSnapshot((int)($_SESSION['tecnico_id'] ?? 0), 6)
    : ['totales' => ['abiertas' => 0, 'urgentes' => 0, 'en_reparacion' => 0, 'vencidas_7' => 0, 'max_dias' => 0], 'items' => []];

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
        --border-color: #cbd5e1; /* Higher contrast border */
        --bg-light: #f1f5f9; /* Slightly deeper slate light background */
        --text-main: #0f172a; /* Deeper black-slate text for pure readability */
        --text-muted: #475569; /* Much higher contrast muted label text */
        --primary-blue: #1d4ed8; /* Pristine deeper blue */
    }

    body {
        background-color: #edf2f7 !important; /* Deeper cool slate background for beautiful card frames */
    }

    @media (min-width: 992px) {
        body {
            overflow-y: hidden;
        }

        .main-content {
            height: 100vh;
            overflow: hidden;
        }

        .main-content > .fade-in {
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 0;
        }
    }

    /* Page Title */
    .page-title {
        font-size: 28px;
        font-weight: 800;
        color: var(--text-main);
        margin-bottom: 0;
        letter-spacing: -0.02em;
    }

    /* Search & Filter Header */
    .dashboard-header {
        background: #ffffff;
        border-radius: 12px;
        padding: 0.65rem 0.9rem;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.02), 
                    0 4px 14px -2px rgba(148, 163, 184, 0.06);
        border: 1px solid var(--border-color);
        margin-bottom: 0.85rem;
    }

    .search-input-wrapper {
        position: relative;
        flex-grow: 1;
    }

    .search-input-wrapper .bi-search {
        position: absolute;
        left: 0.65rem;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b; /* Higher contrast search icon */
        font-size: 0.85rem;
    }

    .search-input {
        padding-left: 2rem;
        border-radius: 6px;
        height: 32px;
        font-size: 12.5px;
        border: 1px solid #94a3b8; /* More contrast */
        background-color: #ffffff;
        color: var(--text-main);
        transition: all 0.15s ease-in-out;
    }

    .search-input::placeholder {
        color: #94a3b8;
    }

    .search-input:focus {
        background-color: #ffffff;
        border-color: var(--primary-blue);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }

    .dashboard-header .btn {
        height: 32px;
        padding: 0 1rem;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        border-radius: 6px;
        font-weight: 600;
        white-space: nowrap;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        transition: all 0.15s ease;
    }

    /* Compact Filter Cards */
    .filter-group-title {
        font-size: 10px;
        font-weight: 800;
        color: var(--text-muted);
        margin-bottom: 0.15rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .filter-select {
        height: 30px;
        font-size: 12px;
        border-radius: 6px;
        border: 1px solid #94a3b8; /* More contrast */
        padding: 0 1.4rem 0 0.5rem;
        background-color: #ffffff;
        color: var(--text-main);
        transition: all 0.15s ease;
    }

    .filter-select:focus {
        border-color: var(--primary-blue);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }

    /* Enhanced Tab Container Section */
    .tabs-section-container {
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: 12px;
        padding: 0.65rem 0.8rem;
        margin-bottom: 1rem;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.015), 
                    0 4px 12px -2px rgba(148, 163, 184, 0.05),
                    inset 0 1px 0 0 #ffffff;
        animation: slideUpFade 0.35s cubic-bezier(0.16, 1, 0.3, 1) both;
    }

    .tabs-section-header {
        display: flex;
        align-items: center;
        margin-bottom: 0.5rem;
        gap: 0.4rem;
        padding: 0 0.25rem;
    }

    .tabs-section-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 18px;
        height: 18px;
        border-radius: 4px;
        background: #f1f5f9;
        color: #64748b;
        font-size: 10px;
        border: 1px solid #e2e8f0;
    }

    .tabs-section-title {
        font-size: 11px;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .tabs-section-divider {
        flex-grow: 1;
        height: 1px;
        background: radial-gradient(circle, #e2e8f0 0%, rgba(226, 232, 240, 0) 100%);
        margin: 0 0.5rem;
    }

    .tabs-section-meta {
        font-size: 11px;
        font-weight: 600;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 0.35rem;
        background: #fff;
        border: 1px solid #e2e8f0;
        padding: 0.1rem 0.45rem;
        border-radius: 9999px;
        box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);
    }

    .live-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background-color: #10b981;
        display: inline-block;
        position: relative;
    }

    .live-dot::after {
        content: '';
        position: absolute;
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background-color: #10b981;
        animation: pulse-dot 1.8s cubic-bezier(0.24, 0, 0.38, 1) infinite;
        top: 0;
        left: 0;
    }

    @keyframes pulse-dot {
        0% {
            transform: scale(0.95);
            opacity: 0.8;
        }
        50% {
            opacity: 0.4;
        }
        100% {
            transform: scale(2.4);
            opacity: 0;
        }
    }

    @keyframes slideUpFade {
        from {
            opacity: 0;
            transform: translateY(6px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Tabs (Chips) */
    .nav-pills-custom {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        margin-bottom: 0;
    }

    .nav-tab-custom {
        border-radius: 8px;
        padding: 0.32rem 0.68rem;
        font-weight: 600;
        font-size: 12.5px;
        color: #475569;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 0.38rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
    }

    .nav-tab-custom:hover {
        background: #f8fafc;
        color: #0f172a;
        border-color: #cbd5e1;
        transform: translateY(-1.5px);
        box-shadow: 0 4px 10px -2px rgba(15, 23, 42, 0.06), 
                    0 2px 4px -2px rgba(15, 23, 42, 0.04);
    }

    .nav-tab-custom.active {
        color: #ffffff !important;
        border-color: transparent !important;
        transform: translateY(-1px);
        text-shadow: 0 1px 1px rgba(0, 0, 0, 0.15);
    }

    .nav-tab-custom .bi {
        font-size: 13.5px;
        transition: transform 0.2s ease, opacity 0.2s ease;
        opacity: 0.85;
    }

    .nav-tab-custom:hover .bi {
        transform: scale(1.15);
        opacity: 1;
    }

    .nav-tab-custom.active .bi {
        opacity: 1;
        transform: scale(1.05);
    }

    /* URGENTES inactive style */
    .nav-tab-custom[data-estado="URGENTES"] {
        background-color: #fff5f5;
        color: #c53030;
        border-color: #feb2b2;
    }
    .nav-tab-custom[data-estado="URGENTES"]:not(.active) .bi {
        color: #e53e3e;
    }
    .nav-tab-custom[data-estado="URGENTES"]:hover {
        background-color: #fff0f0;
        color: #9b2c2c;
        border-color: #fc8181;
    }

    /* Active State Color Gradients & Shadows */
    .nav-tab-custom[data-estado="URGENTES"].active {
        background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%) !important;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.28) !important;
    }
    .nav-tab-custom[data-estado="PEND_REPARACION"].active {
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.28) !important;
    }
    .nav-tab-custom[data-estado="EN_REPARACION"].active {
        background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%) !important;
        box-shadow: 0 4px 12px rgba(109, 40, 217, 0.28) !important;
    }
    .nav-tab-custom[data-estado="REPARADOS"].active {
        background: linear-gradient(135deg, #10b981 0%, #047857 100%) !important;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.28) !important;
    }
    .nav-tab-custom[data-estado="PENDIENTES"].active {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important;
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.28) !important;
    }
    .nav-tab-custom[data-estado="SIN_REPARACION"].active {
        background: linear-gradient(135deg, #64748b 0%, #475569 100%) !important;
        box-shadow: 0 4px 12px rgba(100, 116, 139, 0.28) !important;
    }
    .nav-tab-custom[data-estado="TODAS"].active {
        background: linear-gradient(135deg, #f97316 0%, #c2410c 100%) !important;
        box-shadow: 0 4px 12px rgba(249, 115, 22, 0.28) !important;
    }
    .nav-tab-custom[data-estado="MIS_REPARACIONES"].active {
        background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%) !important;
        box-shadow: 0 4px 12px rgba(14, 165, 233, 0.28) !important;
    }

    /* Inactive specific icon hints */
    .nav-tab-custom[data-estado="PEND_REPARACION"]:not(.active)  .bi { color: #3b82f6; }
    .nav-tab-custom[data-estado="EN_REPARACION"]:not(.active)    .bi { color: #8b5cf6; }
    .nav-tab-custom[data-estado="REPARADOS"]:not(.active)        .bi { color: #10b981; }
    .nav-tab-custom[data-estado="PENDIENTES"]:not(.active)       .bi { color: #f59e0b; }
    .nav-tab-custom[data-estado="SIN_REPARACION"]:not(.active)   .bi { color: #64748b; }
    .nav-tab-custom[data-estado="TODAS"]:not(.active)            .bi { color: #f97316; }
    .nav-tab-custom[data-estado="MIS_REPARACIONES"]:not(.active) .bi { color: #0ea5e9; }

    /* Count Badge Styling */
    .nav-tab-custom .badge {
        font-size: 11px;
        font-weight: 750;
        padding: 0.18rem 0.45rem;
        background-color: #f1f5f9 !important;
        color: #475569 !important;
        border: 1px solid #e2e8f0 !important;
        transition: all 0.2s ease;
        line-height: 1;
    }

    .nav-tab-custom:hover .badge {
        background-color: #e2e8f0 !important;
        color: #1e293b !important;
        border-color: #cbd5e1 !important;
    }

    .nav-tab-custom[data-estado="URGENTES"] .badge {
        background-color: #ffebeb !important;
        color: #c53030 !important;
        border-color: #fecaca !important;
    }

    .nav-tab-custom[data-estado="URGENTES"]:hover .badge {
        background-color: #fecaca !important;
        color: #9b2c2c !important;
        border-color: #fca5a5 !important;
    }

    .nav-tab-custom.active .badge {
        background-color: #ffffff !important;
        border-color: transparent !important;
        font-weight: 800;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1), inset 0 1px 0 0 rgba(255,255,255,0.2);
    }

    .nav-tab-custom[data-estado="URGENTES"].active .badge { color: #c53030 !important; }
    .nav-tab-custom[data-estado="PEND_REPARACION"].active .badge { color: #1d4ed8 !important; }
    .nav-tab-custom[data-estado="EN_REPARACION"].active .badge { color: #6d28d9 !important; }
    .nav-tab-custom[data-estado="REPARADOS"].active .badge { color: #047857 !important; }
    .nav-tab-custom[data-estado="PENDIENTES"].active .badge { color: #b45309 !important; }
    .nav-tab-custom[data-estado="SIN_REPARACION"].active .badge { color: #475569 !important; }
    .nav-tab-custom[data-estado="TODAS"].active .badge { color: #c2410c !important; }
    .nav-tab-custom[data-estado="MIS_REPARACIONES"].active .badge { color: #0369a1 !important; }

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

    @media (min-width: 992px) {
        .table-container {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-height: 0;
            margin-bottom: 0 !important;
        }

        .table-scroll,
        .table-scroll.no-chips {
            flex: 1 1 auto;
            max-height: none;
            min-height: 0;
        }
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
        background-color: #1e293b !important; /* Deep dark slate to ground the design */
        color: #f8fafc !important; /* Pristine off-white */
        font-weight: 700;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 0.04em;
        padding: 0.65rem 0.8rem;
        border-bottom: 2px solid #0f172a !important; /* Darker bottom border */
        white-space: nowrap;
    }

    .custom-table tbody td {
        background: #ffffff;
        padding: 0.5rem 0.8rem;
        vertical-align: middle;
        border-bottom: 1px solid #cbd5e1; /* Clear grid line border */
        color: var(--text-main);
    }

    /* Zebra striping: alternate rows are light slate-tinted off-white for crisp high-density contrast */
    .custom-table tbody tr:nth-child(even):not(.urgent-row) td {
        background: #f8fafc;
    }

    .custom-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Hover: extremely elegant blue-gray highlight with thin accent line */
    .custom-table tbody tr:hover td {
        background-color: #f1f5f9 !important; /* contrast grey on hover */
        box-shadow: inset 0 1px 0 0 #3b82f6, inset 0 -1px 0 0 #3b82f6;
    }
    /* Preserves state stripe on first column, adding the blue highlight hover line */
    .custom-table tbody tr:not(.urgent-row):hover td:first-child {
        box-shadow: inset 3px 0 0 0 var(--row-stripe, transparent),
                    inset 0 1px 0 0 #3b82f6,
                    inset 0 -1px 0 0 #3b82f6;
    }
    .custom-table tbody tr.urgent-row:hover td:first-child {
        box-shadow: inset 1px 0 0 0 #3b82f6, inset 0 1px 0 0 #3b82f6, inset 0 -1px 0 0 #3b82f6;
    }
    .custom-table tbody tr:hover td:last-child {
        box-shadow: inset -1px 0 0 0 #3b82f6, inset 0 1px 0 0 #3b82f6, inset 0 -1px 0 0 #3b82f6;
    }

    /* Stripe lateral por estado (no se aplica a urgent-row para no pisar el contorno rojo) */
    .custom-table tbody tr:not(.urgent-row) td:first-child {
        box-shadow: inset 3px 0 0 0 var(--row-stripe, transparent);
    }
    .custom-table tbody tr.estado-success   { --row-stripe: #10b981; }
    .custom-table tbody tr.estado-warning   { --row-stripe: #f59e0b; }
    .custom-table tbody tr.estado-danger    { --row-stripe: #ef4444; }
    .custom-table tbody tr.estado-info      { --row-stripe: #3b82f6; }
    .custom-table tbody tr.estado-secondary { --row-stripe: #94a3b8; }

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
        background: #ffffff;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.02);
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

    /* Tecnico panel */
    .tech-panel {
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        border: 1px solid #dbeafe;
        border-radius: 12px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        margin-bottom: 1rem;
        overflow: hidden;
    }

    .tech-panel-head {
        align-items: flex-start;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        gap: 0.85rem;
        justify-content: space-between;
        padding: 0.9rem 1rem 0.75rem;
    }

    .tech-panel-title {
        color: #0f172a;
        font-size: 0.98rem;
        font-weight: 700;
        margin: 0;
    }

    .tech-panel-subtitle {
        color: #64748b;
        font-size: 0.78rem;
        margin: 0.2rem 0 0;
    }

    .tech-panel-body {
        padding: 0.9rem 1rem 1rem;
    }

    .tech-panel.is-collapsed .tech-panel-head {
        border-bottom-color: transparent;
    }

    .tech-panel-toggle {
        align-items: center;
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 8px;
        color: #475569;
        display: inline-flex;
        font-size: 0.76rem;
        font-weight: 700;
        gap: 0.42rem;
        line-height: 1;
        padding: 0.42rem 0.68rem;
        transition: all 0.15s ease;
    }

    .tech-panel-toggle:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
        color: #0f172a;
    }

    .tech-panel.is-collapsed .tech-panel-toggle {
        background: #dbeafe;
        border-color: #60a5fa;
        color: #1d4ed8;
        box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.08);
    }

    .tech-panel.is-collapsed .tech-panel-toggle:hover {
        background: #bfdbfe;
        border-color: #3b82f6;
        color: #1d4ed8;
    }

    .tech-panel-toggle .bi {
        font-size: 0.82rem;
        transition: transform 0.18s ease;
    }

    .tech-panel.is-collapsed .tech-panel-toggle .bi {
        transform: rotate(-90deg);
    }

    .tech-panel-body-wrap {
        display: grid;
        grid-template-rows: 1fr;
        transition: grid-template-rows 0.2s ease;
    }

    .tech-panel.is-collapsed .tech-panel-body-wrap {
        grid-template-rows: 0fr;
    }

    .tech-panel-body-inner {
        min-height: 0;
        overflow: hidden;
    }

    .tech-kpi-grid {
        display: grid;
        gap: 0.65rem;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        margin-bottom: 0.9rem;
    }

    .tech-kpi {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        min-width: 0;
        padding: 0.7rem 0.8rem;
    }

    .tech-kpi-label {
        color: #64748b;
        font-size: 0.69rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        margin-bottom: 0.25rem;
        text-transform: uppercase;
    }

    .tech-kpi-value {
        color: #0f172a;
        font-size: 1.2rem;
        font-weight: 700;
        line-height: 1;
    }

    .tech-kpi-note {
        color: #64748b;
        font-size: 0.72rem;
        margin-top: 0.2rem;
    }

    .tech-kpi.is-urgent {
        background: #fff7ed;
        border-color: #fdba74;
    }

    .tech-kpi.is-urgent .tech-kpi-value {
        color: #c2410c;
    }

    .tech-kpi.is-aged {
        background: #fef2f2;
        border-color: #fca5a5;
    }

    .tech-kpi.is-aged .tech-kpi-value {
        color: #b91c1c;
    }

    .tech-task-list {
        display: grid;
        gap: 0.65rem;
    }

    .tech-task {
        align-items: flex-start;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        display: grid;
        gap: 0.75rem;
        grid-template-columns: minmax(0, 1fr) auto;
        padding: 0.8rem 0.9rem;
    }

    .tech-task.is-urgent {
        border-color: #fca5a5;
        box-shadow: inset 3px 0 0 #ef4444;
    }

    .tech-task-main {
        min-width: 0;
    }

    .tech-task-topline {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        margin-bottom: 0.35rem;
    }

    .tech-priority-chip,
    .tech-age-chip {
        align-items: center;
        border-radius: 999px;
        display: inline-flex;
        font-size: 0.69rem;
        font-weight: 700;
        gap: 0.25rem;
        line-height: 1;
        padding: 0.28rem 0.5rem;
        text-transform: uppercase;
    }

    .tech-priority-chip {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .tech-priority-chip.is-urgent {
        background: #fef2f2;
        color: #b91c1c;
    }

    .tech-age-chip {
        background: #f8fafc;
        color: #475569;
    }

    .tech-age-chip.is-aged {
        background: #fff7ed;
        color: #c2410c;
    }

    .tech-task-title {
        color: #0f172a;
        display: block;
        font-size: 0.92rem;
        font-weight: 700;
        margin-bottom: 0.18rem;
        overflow: hidden;
        text-decoration: none;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .tech-task-title:hover {
        color: #1d4ed8;
    }

    .tech-task-meta {
        color: #64748b;
        display: flex;
        flex-wrap: wrap;
        font-size: 0.76rem;
        gap: 0.3rem 0.75rem;
        margin-bottom: 0.3rem;
    }

    .tech-task-meta span {
        align-items: center;
        display: inline-flex;
        gap: 0.28rem;
        min-width: 0;
    }

    .tech-task-notes {
        color: #475569;
        font-size: 0.78rem;
        line-height: 1.35;
        margin: 0;
    }

    .tech-task-notes span {
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
        overflow: hidden;
    }

    .tech-task-side {
        align-items: flex-end;
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
        min-width: 112px;
    }

    .tech-task-side .status-pill {
        max-width: none;
        min-width: 0;
        width: 100%;
    }

    .tech-task-action {
        font-size: 0.76rem;
        font-weight: 700;
        padding: 0.38rem 0.72rem;
    }

    .tech-empty {
        background: #fff;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        color: #64748b;
        padding: 1rem;
        text-align: center;
    }

    @media (max-width: 991px) {
        .tech-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 767px) {
        .tech-panel-head,
        .tech-task {
            grid-template-columns: 1fr;
        }

        .tech-panel-head {
            display: block;
        }

        .tech-kpi-grid {
            grid-template-columns: 1fr 1fr;
        }

        .tech-task-side {
            align-items: stretch;
            min-width: 0;
        }

        .tech-panel-toggle span {
            display: none;
        }
    }
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

<?php if ($is_tecnico): ?>
<?php
    $totales_tecnico = $mis_pendientes['totales'];
    $items_tecnico = $mis_pendientes['items'];
?>
<section class="tech-panel">
    <div class="tech-panel-head">
        <div>
            <h3 class="tech-panel-title"><i class="bi bi-person-workspace text-primary me-1"></i>Mis pendientes</h3>
            <p class="tech-panel-subtitle">Vista rapida para priorizar urgentes, trabajo activo y reparaciones con mayor antiguedad.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="index.php?estado=MIS_REPARACIONES" class="btn btn-light border btn-sm fw-semibold">
                <i class="bi bi-list-task me-1"></i>Ver bandeja
            </a>
            <button type="button" class="tech-panel-toggle" id="techPanelToggle" aria-expanded="true" aria-controls="techPanelBodyWrap">
                <i class="bi bi-chevron-down"></i>
                <span>Ocultar</span>
            </button>
        </div>
    </div>
    <div class="tech-panel-body-wrap" id="techPanelBodyWrap">
        <div class="tech-panel-body-inner">
        <div class="tech-panel-body">
        <div class="tech-kpi-grid">
            <div class="tech-kpi">
                <div class="tech-kpi-label">Abiertas</div>
                <div class="tech-kpi-value"><?= number_format($totales_tecnico['abiertas']) ?></div>
                <div class="tech-kpi-note">Asignadas a tu usuario</div>
            </div>
            <div class="tech-kpi is-urgent">
                <div class="tech-kpi-label">Urgentes</div>
                <div class="tech-kpi-value"><?= number_format($totales_tecnico['urgentes']) ?></div>
                <div class="tech-kpi-note">Para atacar primero</div>
            </div>
            <div class="tech-kpi">
                <div class="tech-kpi-label">En reparacion</div>
                <div class="tech-kpi-value"><?= number_format($totales_tecnico['en_reparacion']) ?></div>
                <div class="tech-kpi-note">Ya iniciadas</div>
            </div>
            <div class="tech-kpi <?= $totales_tecnico['vencidas_7'] > 0 ? 'is-aged' : '' ?>">
                <div class="tech-kpi-label">7+ dias</div>
                <div class="tech-kpi-value"><?= number_format($totales_tecnico['vencidas_7']) ?></div>
                <div class="tech-kpi-note">Maximo: <?= (int)$totales_tecnico['max_dias'] ?> dias</div>
            </div>
        </div>

        <?php if (!empty($items_tecnico)): ?>
        <div class="tech-task-list">
            <?php foreach ($items_tecnico as $item): ?>
                <?php
                    $itemEstado = strtoupper((string)$item['estado']);
                    $itemUrgente = ($item['urgente'] ?? 'NO') === 'SI';
                    $itemDias = max(0, (int)($item['dias_abierta'] ?? 0));
                    $itemAged = $itemDias >= 7;
                    $badge_class = 'status-secondary';
                    if (strpos($itemEstado, 'REPARADO') !== false) $badge_class = 'status-success';
                    elseif (strpos($itemEstado, 'PEND') !== false) $badge_class = 'status-warning';
                    elseif (strpos($itemEstado, 'SIN REPARACION') !== false) $badge_class = 'status-danger';
                    elseif (strpos($itemEstado, 'PRUEBA') !== false || strpos($itemEstado, 'REPARACION') !== false) $badge_class = 'status-info';
                ?>
                <article class="tech-task <?= $itemUrgente ? 'is-urgent' : '' ?>">
                    <div class="tech-task-main">
                        <div class="tech-task-topline">
                            <span class="tech-priority-chip <?= $itemUrgente ? 'is-urgent' : '' ?>">
                                <i class="bi <?= $itemUrgente ? 'bi-exclamation-triangle-fill' : 'bi-flag' ?>"></i>
                                <?= $itemUrgente ? 'Urgente' : 'Normal' ?>
                            </span>
                            <span class="tech-age-chip <?= $itemAged ? 'is-aged' : '' ?>">
                                <i class="bi bi-clock-history"></i><?= e(formatAgeLabel($item['fecha_referencia'] ?? $item['fecha'])) ?>
                            </span>
                        </div>
                        <a href="reparacion_detalle.php?id=<?= e($item['id']) ?>" class="tech-task-title">
                            <?= e($item['equipo']) ?>
                        </a>
                        <div class="tech-task-meta">
                            <span><i class="bi bi-geo-alt-fill text-danger"></i><?= e($item['sala']) ?></span>
                            <?php if (!empty($item['uid'])): ?><span><i class="bi bi-upc-scan"></i>UID <?= e($item['uid']) ?></span><?php endif; ?>
                            <?php if (!empty($item['npu'])): ?><span><i class="bi bi-tag"></i>NPU <?= e($item['npu']) ?></span><?php endif; ?>
                        </div>
                        <p class="tech-task-notes">
                            <span><?= e(trim((string)($item['observaciones'] ?? '')) ?: 'Sin observaciones cargadas.') ?></span>
                        </p>
                    </div>
                    <div class="tech-task-side">
                        <span class="status-pill <?= $badge_class ?>"><?= e($item['estado']) ?></span>
                        <a href="reparacion_detalle.php?id=<?= e($item['id']) ?>" class="btn btn-primary tech-task-action">
                            Abrir ficha
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="tech-empty">
            <div class="fw-bold text-dark mb-1">No tenes pendientes activos.</div>
            <div class="small">Cuando se te asignen reparaciones van a aparecer aca ordenadas por urgencia y antiguedad.</div>
        </div>
        <?php endif; ?>
        </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Nav Tabs (Chips) -->
<div class="tabs-section-container">
    <div class="tabs-section-header">
        <span class="tabs-section-badge"><i class="bi bi-funnel-fill"></i></span>
        <span class="tabs-section-title">Filtrar por estado</span>
        <div class="tabs-section-divider"></div>
        <span class="tabs-section-meta">
            <span class="live-dot"></span>
            <?= number_format($tab_counts['TODAS'] ?? 0) ?> órdenes en total
        </span>
    </div>
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
                    $badge_class = 'status-secondary';
                    $est = strtoupper($r['estado']);
                    if (strpos($est, 'REPARADO') !== false) $badge_class = 'status-success';
                    elseif (strpos($est, 'PEND') !== false) $badge_class = 'status-warning';
                    elseif (strpos($est, 'SIN REPARACION') !== false) $badge_class = 'status-danger';
                    elseif (strpos($est, 'PRUEBA') !== false) $badge_class = 'status-info';
                    $estado_row_class = 'estado-' . substr($badge_class, strlen('status-'));
                    $tr_classes = trim(($isUrgent ? 'urgent-row ' : '') . $estado_row_class);
                ?>
                <tr class="<?= $tr_classes ?>">
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

(function () {
    const panel = document.querySelector('.tech-panel');
    const toggle = document.getElementById('techPanelToggle');
    if (!panel || !toggle) return;

    const label = toggle.querySelector('span');
    const storageKey = 'laboratorio:tech-panel-collapsed';
    const autoCollapseDelay = 4000;
    let autoCollapseTimer = null;

    function syncState(collapsed) {
        panel.classList.toggle('is-collapsed', collapsed);
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        if (label) label.textContent = collapsed ? 'Mostrar' : 'Ocultar';
    }

    function clearAutoCollapseTimer() {
        if (autoCollapseTimer) {
            clearTimeout(autoCollapseTimer);
            autoCollapseTimer = null;
        }
    }

    function scheduleAutoCollapse() {
        clearAutoCollapseTimer();
        if (panel.classList.contains('is-collapsed')) return;
        autoCollapseTimer = setTimeout(() => {
            syncState(true);
            localStorage.setItem(storageKey, '1');
        }, autoCollapseDelay);
    }

    const storedState = localStorage.getItem(storageKey);
    syncState(storedState === null ? true : storedState === '1');

    toggle.addEventListener('click', () => {
        clearAutoCollapseTimer();
        const collapsed = !panel.classList.contains('is-collapsed');
        syncState(collapsed);
        localStorage.setItem(storageKey, collapsed ? '1' : '0');
    });

    panel.addEventListener('mouseenter', clearAutoCollapseTimer);
    panel.addEventListener('mouseleave', scheduleAutoCollapse);
    panel.addEventListener('focusin', clearAutoCollapseTimer);
    panel.addEventListener('focusout', () => {
        requestAnimationFrame(() => {
            if (!panel.contains(document.activeElement)) {
                scheduleAutoCollapse();
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
