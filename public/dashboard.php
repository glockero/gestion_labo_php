<?php
// public/dashboard.php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../app/db.php';

$pdo = getDbConnection();

// KPIs
$kpiHoy = $pdo->query("SELECT COUNT(*) FROM reparaciones WHERE DATE(fecha) = CURDATE()")->fetchColumn();
$kpiSemana = $pdo->query("SELECT COUNT(*) FROM reparaciones WHERE YEARWEEK(fecha, 1) = YEARWEEK(CURDATE(), 1)")->fetchColumn();
$kpiMes = $pdo->query("SELECT COUNT(*) FROM reparaciones WHERE MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())")->fetchColumn();
$kpiReparados = $pdo->query("SELECT COUNT(*) FROM reparaciones WHERE estado = 'REPARADO' AND MONTH(fecha_reparado) = MONTH(CURDATE()) AND YEAR(fecha_reparado) = YEAR(CURDATE())")->fetchColumn();

// Data for charts
$topEquipos = $pdo->query("SELECT equipo, COUNT(*) as total FROM reparaciones GROUP BY equipo ORDER BY total DESC LIMIT 10")->fetchAll();
$estadoDistribucion = $pdo->query("SELECT estado, COUNT(*) as total FROM reparaciones GROUP BY estado")->fetchAll();
$evolucionMensual = $pdo->query("SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, COUNT(*) as total FROM reparaciones GROUP BY mes ORDER BY mes DESC LIMIT 12")->fetchAll();
// Reverse for chronological order in chart
$evolucionMensual = array_reverse($evolucionMensual);

?>

<h2 class="h3 mb-4"><i class="bi bi-graph-up"></i> Dashboard</h2>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm text-center border-0 border-start border-primary border-4">
            <div class="card-body">
                <h6 class="text-muted text-uppercase mb-1">Ingresos Hoy</h6>
                <h2 class="mb-0 text-primary"><?= number_format($kpiHoy) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm text-center border-0 border-start border-info border-4">
            <div class="card-body">
                <h6 class="text-muted text-uppercase mb-1">Esta Semana</h6>
                <h2 class="mb-0 text-info"><?= number_format($kpiSemana) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm text-center border-0 border-start border-warning border-4">
            <div class="card-body">
                <h6 class="text-muted text-uppercase mb-1">Este Mes</h6>
                <h2 class="mb-0 text-warning"><?= number_format($kpiMes) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm text-center border-0 border-start border-success border-4">
            <div class="card-body">
                <h6 class="text-muted text-uppercase mb-1">Reparados (Mes)</h6>
                <h2 class="mb-0 text-success"><?= number_format($kpiReparados) ?></h2>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white border-bottom text-muted fw-bold">
                Evolución de Ingresos (Últimos 12 meses)
            </div>
            <div class="card-body">
                <canvas id="chartEvolucion"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white border-bottom text-muted fw-bold">
                Top 10 Equipos que más fallan
            </div>
            <div class="card-body">
                <canvas id="chartEquipos"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white border-bottom text-muted fw-bold">
                Distribución por Estado
            </div>
            <div class="card-body d-flex justify-content-center">
                <div style="width: 70%;">
                    <canvas id="chartEstados"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Evolucion
    const dataEvo = <?= json_encode($evolucionMensual) ?>;
    new Chart(document.getElementById('chartEvolucion'), {
        type: 'line',
        data: {
            labels: dataEvo.map(d => d.mes),
            datasets: [{
                label: 'Ingresos',
                data: dataEvo.map(d => d.total),
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                tension: 0.3,
                fill: true
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // Equipos
    const dataEq = <?= json_encode($topEquipos) ?>;
    new Chart(document.getElementById('chartEquipos'), {
        type: 'bar',
        data: {
            labels: dataEq.map(d => d.equipo),
            datasets: [{
                label: 'Cantidad',
                data: dataEq.map(d => d.total),
                backgroundColor: '#fd7e14'
            }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false,
            indexAxis: 'y'
        }
    });

    // Estados
    const dataEst = <?= json_encode($estadoDistribucion) ?>;
    new Chart(document.getElementById('chartEstados'), {
        type: 'doughnut',
        data: {
            labels: dataEst.map(d => d.estado),
            datasets: [{
                data: dataEst.map(d => d.total),
                backgroundColor: ['#6c757d', '#0d6efd', '#198754', '#dc3545', '#ffc107', '#0dcaf0']
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
