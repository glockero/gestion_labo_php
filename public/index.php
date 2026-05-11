<?php
// public/index.php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../app/ReparacionModel.php';
require_once __DIR__ . '/../app/CatalogoModel.php';

$estado_actual = $_GET['estado'] ?? 'TODAS';
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

// If f_estado is specifically chosen, we override the tab's state logic 
// (or we can pass both, but logic dictates f_estado is an explicit override).
// Let's pass them all to the model.
$filtros = [
    'estado' => $estado_actual,
    'busqueda' => $busqueda,
    'sala' => $sala_filtro,
    'tecnico_id' => $tecnico_filtro === 'SIN_ASIGNAR' ? null : $tecnico_filtro, // Handle SIN_ASIGNAR 
    'f_anio' => $anio_filtro,
    'f_mes' => $mes_filtro,
    'f_dia' => $dia_filtro,
    'f_diasemana' => $diasemana_filtro
];

// Special logic for f_estado: if provided, it restricts the query further or overrides.
if ($estado_filtro) {
    $filtros['estado'] = $estado_filtro; 
}

$reparaciones = ReparacionModel::getList($filtros, $limit, $offset);
$total_registros = ReparacionModel::getCount($filtros);
$total_pages = ceil($total_registros / $limit);

$salas = CatalogoModel::getAll('salas');
$tecnicos = CatalogoModel::getAll('tecnicos');
$estados = CatalogoModel::getAll('estados');

// We need years option. Just hardcode recent ones or query them.
$pdo = getDbConnection();
$anios_opt = $pdo->query("SELECT DISTINCT YEAR(fecha) as anio FROM reparaciones WHERE fecha IS NOT NULL ORDER BY anio DESC")->fetchAll();

// Get counts for tabs
$tabs_estado = [
    'URGENTES' => 'Urgentes',
    'MIS_REPARACIONES' => 'Mis Reparaciones',
    'PEND_REPARACION' => 'Pend. de Reparación',
    'EN_REPARACION' => 'En Reparación',
    'REPARADOS' => 'Reparados',
    'PENDIENTES' => 'Pendientes',
    'SIN_REPARACION' => 'Sin Reparación',
    'TODAS' => 'Todas'
];

$tab_counts = [];
foreach (array_keys($tabs_estado) as $tab_key) {
    $f_temp = $filtros;
    $f_temp['estado'] = $tab_key;
    $tab_counts[$tab_key] = ReparacionModel::getCount($f_temp);
}

// Map real state names to tab groupings if needed. The original logic likely grouped 'PEND. DE REVISION' into 'PEND_REPARACION'.
// We'll trust the model's logic for exact match for now, or you can adjust it inside ReparacionModel.

$npus_repetidos = [];
// Detect NPUs with multiple entries in this view
$npu_list = array_filter(array_column($reparaciones, 'npu'));
if (count($npu_list) > 0) {
    $in = str_repeat('?,', count($npu_list) - 1) . '?';
    $stmtN = $pdo->prepare("SELECT npu FROM reparaciones WHERE npu IN ($in) GROUP BY npu HAVING COUNT(*) > 1");
    $stmtN->execute(array_values($npu_list));
    $npus_repetidos = $stmtN->fetchAll(PDO::FETCH_COLUMN);
}

?>
<style>
    /* Status Pills */
    .status-pill {
        display: inline-flex;
        align-items: center;
        padding: 0.35em 0.8em;
        font-size: 0.75rem;
        font-weight: 700;
        border-radius: 9999px;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }
    
    .status-success { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .status-warning { background-color: #fef9c3; color: #854d0e; border: 1px solid #fef08a; }
    .status-danger { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .status-info { background-color: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }
    .status-secondary { background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

    /* Search & Filter Header */
    .dashboard-header {
        background: #fff;
        border-radius: 16px;
        padding: 1.5rem;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        margin-bottom: 2rem;
    }

    .search-input-wrapper {
        position: relative;
        flex-grow: 1;
    }
    
    .search-input-wrapper .bi-search {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
    }

    .search-input {
        padding-left: 2.75rem;
        border-radius: 12px;
        height: 3rem;
        font-size: 1rem;
        border: 2px solid #e2e8f0;
        background-color: #f8fafc;
    }
    
    .search-input:focus {
        background-color: #fff;
    }

    .quick-filters select {
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        background-color: #f8fafc;
        font-size: 0.85rem;
        color: #475569;
        font-weight: 500;
        padding: 0.5rem 1rem;
        width: 100%;
        cursor: pointer;
    }

    /* Tabs */
    .nav-pills-custom {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        padding-bottom: 0.5rem;
    }

    .nav-tab-custom {
        white-space: nowrap;
        border-radius: 10px;
        padding: 0.6rem 1.2rem;
        font-weight: 600;
        font-size: 0.9rem;
        color: #64748b;
        background: #f1f5f9;
        border: 1px solid transparent;
        transition: all 0.2s;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .nav-tab-custom:hover {
        background: #e2e8f0;
        color: #1e293b;
    }

    .nav-tab-custom.active {
        background: #fff;
        color: #0f172a;
        border-color: #e2e8f0;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    
    .nav-tab-custom[data-estado="MIS_REPARACIONES"].active { border-bottom: 3px solid #0ea5e9; }
    .nav-tab-custom[data-estado="PEND_REPARACION"].active { border-bottom: 3px solid #f59e0b; }
    .nav-tab-custom[data-estado="EN_REPARACION"].active { border-bottom: 3px solid #3b82f6; }
    .nav-tab-custom[data-estado="REPARADOS"].active { border-bottom: 3px solid #10b981; }
    .nav-tab-custom[data-estado="PENDIENTES"].active { border-bottom: 3px solid #8b5cf6; }
    .nav-tab-custom[data-estado="SIN_REPARACION"].active { border-bottom: 3px solid #ef4444; }
    .nav-tab-custom[data-estado="TODAS"].active { border-bottom: 3px solid #64748b; }
    .nav-tab-custom[data-estado="URGENTES"].active { border-bottom: 3px solid #dc3545 !important; background-color: #fef2f2 !important; color: #b91c1c !important; }

    /* Table Styling */
    .table-container {
        background: #fff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .table-scroll {
        max-height: calc(100vh - 360px);
        min-height: 320px;
        overflow: auto;
    }

    .table-scroll .table-responsive {
        overflow: visible;
    }

    .custom-table {
        margin-bottom: 0;
        font-size: 0.9rem;
    }

    .custom-table thead th {
        background-color: #f8fafc;
        color: #475569;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        padding: 1rem;
        border-bottom: 2px solid #e2e8f0;
        border-right: 1px solid #cbd5e1;
        white-space: nowrap;
        position: sticky;
        top: 0;
        z-index: 2;
        box-shadow: inset 0 -1px 0 #cbd5e1;
    }

    .custom-table thead th:last-child {
        border-right: none;
    }

    .custom-table tbody tr {
        transition: background-color 0.15s;
    }
    
    .custom-table tbody tr:hover {
        background-color: #f8fafc;
    }

    .custom-table td {
        padding: 1rem;
        vertical-align: middle;
        border-bottom: 1px solid #cbd5e1;
        border-right: 1px solid #cbd5e1;
        color: #1e293b;
    }

    .custom-table td:last-child {
        border-right: none;
    }

    .action-btn {
        width: 32px;
        height: 32px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
    }
    
    .urgency-badge {
        font-size: 0.7rem;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        background-color: #fee2e2;
        color: #ef4444;
        font-weight: bold;
        display: flex;
        align-items: center;
        gap: 3px;
        width: fit-content;
    }
</style>

<div class="d-flex justify-content-between align-items-end mb-4 flex-wrap gap-3">
    <div>
        <h2 class="fw-bold mb-1 text-primary-color">Seguimiento de Equipos</h2>
    </div>
</div>

<!-- Buscador y Filtros -->
<div class="dashboard-header">
    <form method="GET" action="index.php" id="searchForm">
        <input type="hidden" name="estado" value="<?= e($estado_actual) ?>">
        
        <div class="d-flex flex-column flex-md-row gap-3 mb-3">
            <div class="search-input-wrapper">
                <i class="bi bi-search"></i>
                <input type="search" name="q" value="<?= e($busqueda) ?>" class="form-control search-input" placeholder="Buscar por UID, NPU, o palabras clave..." autocomplete="off">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-dark px-4">Buscar</button>
                <?php if ($busqueda || $sala_filtro || $tecnico_filtro || $estado_filtro || $anio_filtro || $mes_filtro || $dia_filtro || $diasemana_filtro): ?>
                <a href="index.php?estado=<?= e($estado_actual) ?>" class="btn btn-outline-secondary d-flex align-items-center" title="Limpiar todos los filtros">
                    <i class="bi bi-eraser-fill me-1"></i> Limpiar
                </a>
                <?php endif; ?>
                
                <button type="submit" formaction="<?= APP_URL ?>/../exports/exportar_excel.php" class="btn btn-success d-flex align-items-center shadow-sm" title="Descargar vista actual en Excel">
                    <i class="bi bi-file-earmark-excel-fill me-1"></i> Excel
                </button>
            </div>
        </div>

        <div class="row g-3 mb-2">
            <div class="col-12 col-xl-6">
                <div class="card h-100 border-0 shadow-sm bg-light">
                    <div class="card-body p-2 p-md-3">
                        <span class="text-secondary fw-bold small d-block mb-2"><i class="bi bi-calendar-event me-1"></i>Filtros de Fecha</span>
                        <div class="quick-filters row g-2">
                            <div class="col-6 col-md-3">
                                <select name="f_anio" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">Año (Todos)</option>
                                    <?php foreach ($anios_opt as $a): ?>
                                    <option value="<?= e($a['anio']) ?>" <?= $anio_filtro == $a['anio'] ? 'selected' : '' ?>><?= e($a['anio']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <select name="f_mes" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">Mes (Todos)</option>
                                    <?php 
                                    $meses = ['01'=>'Enero', '02'=>'Febrero', '03'=>'Marzo', '04'=>'Abril', '05'=>'Mayo', '06'=>'Junio', '07'=>'Julio', '08'=>'Agosto', '09'=>'Septiembre', '10'=>'Octubre', '11'=>'Noviembre', '12'=>'Diciembre'];
                                    foreach ($meses as $num => $nom): ?>
                                        <option value="<?= $num ?>" <?= $mes_filtro === $num ? 'selected' : '' ?>><?= $nom ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <select name="f_dia" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">Día (Todos)</option>
                                    <?php for ($i=1; $i<=31; $i++): $d = sprintf("%02d", $i); ?>
                                        <option value="<?= $d ?>" <?= $dia_filtro === $d ? 'selected' : '' ?>><?= $d ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <select name="f_diasemana" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">Día Sem. (Todos)</option>
                                    <option value="1" <?= $diasemana_filtro === '1' ? 'selected' : '' ?>>Lunes</option>
                                    <option value="2" <?= $diasemana_filtro === '2' ? 'selected' : '' ?>>Martes</option>
                                    <option value="3" <?= $diasemana_filtro === '3' ? 'selected' : '' ?>>Miércoles</option>
                                    <option value="4" <?= $diasemana_filtro === '4' ? 'selected' : '' ?>>Jueves</option>
                                    <option value="5" <?= $diasemana_filtro === '5' ? 'selected' : '' ?>>Viernes</option>
                                    <option value="6" <?= $diasemana_filtro === '6' ? 'selected' : '' ?>>Sábado</option>
                                    <option value="0" <?= $diasemana_filtro === '0' ? 'selected' : '' ?>>Domingo</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="card h-100 border-0 shadow-sm bg-light">
                    <div class="card-body p-2 p-md-3">
                        <span class="text-secondary fw-bold small d-block mb-2"><i class="bi bi-funnel me-1"></i>Otros Filtros</span>
                        <div class="quick-filters row g-2">
                            <div class="col-md-4">
                                <select name="f_sala" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">Todas las Salas</option>
                                    <?php foreach ($salas as $s): ?>
                                    <option value="<?= e($s['nombre']) ?>" <?= $sala_filtro === $s['nombre'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select name="f_estado" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">Todos los Estados</option>
                                    <?php foreach ($estados as $e): ?>
                                    <option value="<?= e($e['nombre']) ?>" <?= $estado_filtro === $e['nombre'] ? 'selected' : '' ?>><?= e($e['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select name="f_tecnico" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">Todos los Técnicos</option>
                                    <option value="SIN_ASIGNAR" <?= $tecnico_filtro === 'SIN_ASIGNAR' ? 'selected' : '' ?>>-- Sin Asignar --</option>
                                    <?php foreach ($tecnicos as $t): ?>
                                    <option value="<?= e($t['id']) ?>" <?= $tecnico_filtro == $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Nav Tabs -->
<div class="nav-pills-custom mb-3">
    <?php foreach ($tabs_estado as $valor_estado => $etiqueta_estado): 
        $qParams = $_GET;
        $qParams['estado'] = $valor_estado;
        $qParams['page'] = 1;
        $url = '?' . http_build_query($qParams);
        $activeClass = $estado_actual === $valor_estado ? 'active' : '';
        $urgentClass = $valor_estado === 'URGENTES' ? 'border-danger text-danger bg-danger bg-opacity-10' : '';
    ?>
        <a href="<?= $url ?>" 
           class="nav-tab-custom <?= $activeClass ?> <?= $urgentClass ?>"
           data-estado="<?= $valor_estado ?>">
           
           <?php if ($valor_estado == 'URGENTES'): ?><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>
           <?php elseif ($valor_estado == 'MIS_REPARACIONES'): ?><i class="bi bi-person-workspace text-info me-1"></i>
           <?php elseif ($valor_estado == 'PEND_REPARACION'): ?><i class="bi bi-inbox me-1"></i>
           <?php elseif ($valor_estado == 'EN_REPARACION'): ?><i class="bi bi-tools me-1"></i>
           <?php elseif ($valor_estado == 'REPARADOS'): ?><i class="bi bi-check2-circle me-1"></i>
           <?php elseif ($valor_estado == 'SIN_REPARACION'): ?><i class="bi bi-x-octagon me-1"></i>
           <?php elseif ($valor_estado == 'PENDIENTES'): ?><i class="bi bi-clock-history me-1"></i>
           <?php else: ?><i class="bi bi-collection me-1"></i><?php endif; ?>
           
           <?= $etiqueta_estado ?>
           
           <span class="badge <?= $valor_estado == 'URGENTES' ? 'bg-danger text-white' : 'bg-light text-secondary border' ?> ms-1 rounded-pill">
               <?= $tab_counts[$valor_estado] ?? 0 ?>
           </span>
        </a>
    <?php endforeach; ?>
</div>

<!-- Table Data -->
<div class="table-container">
    <div class="table-scroll">
    <div class="table-responsive">
        <table class="table custom-table">
            <thead class="table-light">
                <tr>
                    <th>Ingreso</th>
                    <th>Identificación</th>
                    <th>Equipo y Sala</th>
                    <th>Técnico</th>
                    <th>Estado</th>
                    <?php if ($estado_actual === 'EN REPARACION' || $estado_actual === 'EN_REPARACION'): ?>
                        <th class="text-primary"><i class="bi bi-calendar me-1"></i>Inicio Rep.</th>
                    <?php endif; ?>
                    <?php if ($estado_actual === 'REPARADOS'): ?>
                        <th class="text-success"><i class="bi bi-calendar-check me-1"></i>Fecha Rep.</th>
                    <?php endif; ?>
                    <th>Observaciones</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reparaciones as $r): ?>
                <tr>
                    <td class="text-muted"><i class="bi bi-calendar3 me-1 opacity-50"></i><?= formatDatetimeArg($r['fecha']) ?></td>
                    
                    <td>
                        <div class="fw-bold text-dark"><?= e($r['uid']) ?: '--' ?></div>
                        <div class="text-muted small d-flex align-items-center gap-1">
                            <span>NPU: <span class="fw-bold text-primary"><?= e($r['npu']) ?: '--' ?></span></span>
                            <?php if ($r['npu'] && in_array(trim($r['npu']), $npus_repetidos)): ?>
                                <span class="badge bg-warning text-dark border border-warning px-1 py-0 rounded-1" 
                                      title="Este equipo ya fue reparado con anterioridad" 
                                      style="font-size: 0.65rem; cursor: help;">
                                    (R)
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    
                    <td>
                        <a href="reparacion_detalle.php?id=<?= $r['id'] ?>" class="text-decoration-none fw-bold text-primary d-inline-block mb-1 hover-underline" title="Ver Ficha Técnica">
                            <?= e($r['equipo']) ?> <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 0.75rem;"></i>
                        </a>
                        <div class="text-muted small">
                            <i class="bi bi-geo-alt-fill text-danger opacity-50 me-1"></i><?= e($r['sala']) ?>
                        </div>
                    </td>
                    
                    <td>
                        <?php if ($r['tecnico_nombre']): ?>
                            <div class="d-flex align-items-center gap-2">
                                <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 24px; height: 24px; font-size: 0.7rem;">
                                    <?= strtoupper(substr(e($r['tecnico_nombre']), 0, 1)) ?>
                                </div>
                                <span class="fw-medium"><?= e($r['tecnico_nombre']) ?></span>
                            </div>
                        <?php else: ?>
                            <span class="text-muted fst-italic">Sin asignar</span>
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
                        <span class="status-pill <?= $badge_class ?>">
                            <?= e($r['estado']) ?>
                        </span>
                        
                        <?php if ($r['urgente'] === 'SI'): ?>
                            <div class="urgency-badge mt-2">
                                <i class="bi bi-exclamation-triangle-fill"></i> URGENTE
                            </div>
                        <?php endif; ?>
                    </td>

                    <?php if ($estado_actual === 'EN REPARACION' || $estado_actual === 'EN_REPARACION'): ?>
                        <td class="text-primary fw-bold align-middle" style="font-size: 0.85rem; white-space: nowrap;">
                            <?= $r['fecha_en_reparacion'] ? date('d/m/Y', strtotime($r['fecha_en_reparacion'])) : '--' ?>
                        </td>
                    <?php endif; ?>

                    <?php if ($estado_actual === 'REPARADOS'): ?>
                        <td class="text-success fw-bold align-middle" style="font-size: 0.85rem;">
                            <?= $r['fecha_reparado'] ? date('d/m/Y', strtotime($r['fecha_reparado'])) : '--' ?>
                        </td>
                    <?php endif; ?>

                    <td>
                        <div class="text-truncate text-muted" style="max-width: 250px;" title="<?= e($r['observaciones']) ?>">
                            <?= e($r['observaciones']) ?: 'Sin comentarios' ?>
                        </div>
                    </td>
                    
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <a href="reparacion_detalle.php?id=<?= $r['id'] ?>" class="btn btn-light action-btn text-dark border" title="Ver Ficha">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if ($user_role === 'admin'): ?>
                            <a href="reparacion_editar.php?id=<?= $r['id'] ?>" class="btn btn-light action-btn text-primary border" title="Editar orden">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                            <button type="button" class="btn btn-light action-btn text-danger border" title="Eliminar orden" onclick="confirmDelete(<?= $r['id'] ?>)">
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
    </div>
    
    <?php if (empty($reparaciones)): ?>
    <div class="text-center py-5">
        <div class="display-1 text-muted opacity-25 mb-3"><i class="bi bi-inbox"></i></div>
        <h5 class="text-muted fw-bold">No se encontraron registros</h5>
        <p class="text-secondary">Intentá limpiar la búsqueda o cambiar los filtros.</p>
        <?php if ($busqueda || $sala_filtro || $tecnico_filtro || $estado_filtro || $anio_filtro || $mes_filtro || $dia_filtro || $diasemana_filtro): ?>
        <a href="index.php?estado=<?= e($estado_actual) ?>" class="btn btn-outline-primary mt-2">Limpiar filtros</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Footer / Pagination -->
    <div class="p-3 border-top bg-light d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
        <span class="text-muted small fw-medium">
            Mostrando página <?= $page ?> de <?= max(1, $total_pages) ?>
        </span>
        
        <nav aria-label="Navegación de páginas">
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
    <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-body text-center p-4">
                <i class="bi bi-trash3 text-danger mb-3" style="font-size: 3rem;"></i>
                <h5 class="fw-bold text-dark mb-2">Eliminar reparación</h5>
                <p class="text-muted small mb-4">Esta acción eliminará el registro de forma permanente. ¿Deseas continuar?</p>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light flex-grow-1 fw-bold text-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" action="../admin/eliminar_reparacion.php" id="deleteForm" class="flex-grow-1">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="id" id="deleteId">
                        <button type="submit" class="btn btn-danger w-100 fw-bold">Sí, eliminar</button>
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
