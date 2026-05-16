<?php
// public/dashboard.php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/estado.php';

$pdo = getDbConnection();

// KPIs
$kpiHoy = $pdo->query("SELECT COUNT(*) FROM reparaciones WHERE DATE(fecha) = CURDATE()")->fetchColumn();
$kpiSemana = $pdo->query("SELECT COUNT(*) FROM reparaciones WHERE YEARWEEK(fecha, 1) = YEARWEEK(CURDATE(), 1)")->fetchColumn();
$kpiMes = $pdo->query("SELECT COUNT(*) FROM reparaciones WHERE MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())")->fetchColumn();
$kpiReparados = $pdo->query("SELECT COUNT(*) FROM reparaciones WHERE UPPER(estado) LIKE 'REPARADO%' AND MONTH(fecha_reparado) = MONTH(CURDATE()) AND YEAR(fecha_reparado) = YEAR(CURDATE())")->fetchColumn();

// Data for charts
$topEquipos = $pdo->query("SELECT equipo, COUNT(*) as total FROM reparaciones GROUP BY equipo ORDER BY total DESC LIMIT 10")->fetchAll();
$estadoDistribucion = $pdo->query("SELECT estado, COUNT(*) as total FROM reparaciones GROUP BY estado")->fetchAll();
$evolucionMensual = $pdo->query("SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, COUNT(*) as total FROM reparaciones GROUP BY mes ORDER BY mes DESC LIMIT 12")->fetchAll();
$evolucionMensual = array_reverse($evolucionMensual);
?>

<div class="admin-shell">
    <div class="admin-page-header">
        <div>
            <h2 class="admin-page-title"><i class="bi bi-graph-up text-primary me-1"></i> Dashboard</h2>
            <p class="admin-page-subtitle">Resumen operativo de ingresos, reparaciones y distribucion del laboratorio.</p>
        </div>
        <a href="../admin/metricas.php" class="btn btn-light border text-secondary admin-btn-sm">
            <i class="bi bi-bar-chart-line me-1"></i>Metricas
        </a>
    </div>

    <div class="admin-kpi-grid">
        <div class="admin-kpi-card" style="--kpi-color: #2563eb;">
            <div class="admin-kpi-label">Ingresos Hoy</div>
            <div class="admin-kpi-value"><?= number_format($kpiHoy) ?></div>
        </div>
        <div class="admin-kpi-card" style="--kpi-color: #0ea5e9;">
            <div class="admin-kpi-label">Esta Semana</div>
            <div class="admin-kpi-value"><?= number_format($kpiSemana) ?></div>
        </div>
        <div class="admin-kpi-card" style="--kpi-color: #d97706;">
            <div class="admin-kpi-label">Este Mes</div>
            <div class="admin-kpi-value"><?= number_format($kpiMes) ?></div>
        </div>
        <div class="admin-kpi-card" style="--kpi-color: #16a34a;">
            <div class="admin-kpi-label">Reparados Mes</div>
            <div class="admin-kpi-value"><?= number_format($kpiReparados) ?></div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="admin-card h-100">
                <div class="admin-card-header">
                    <h6><i class="bi bi-graph-up me-1"></i>Evolucion de Ingresos</h6>
                    <span class="text-muted" style="font-size: 11px;">Ultimos 12 meses</span>
                </div>
                <div class="admin-chart-body">
                    <canvas id="chartEvolucion"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="admin-card h-100">
                <div class="admin-card-header">
                    <h6><i class="bi bi-pc-display me-1"></i>Top 10 Equipos</h6>
                    <span class="text-muted" style="font-size: 11px;">Mas ingresos</span>
                </div>
                <div class="admin-chart-body">
                    <canvas id="chartEquipos"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="admin-card h-100">
                <div class="admin-card-header">
                    <h6><i class="bi bi-tags me-1"></i>Distribucion por Estado</h6>
                </div>
                <div class="admin-chart-body d-flex justify-content-center">
                    <canvas id="chartEstados"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dataEvo = <?= json_encode($evolucionMensual) ?>;
    new Chart(document.getElementById('chartEvolucion'), {
        type: 'line',
        data: {
            labels: dataEvo.map(d => d.mes),
            datasets: [{
                label: 'Ingresos',
                data: dataEvo.map(d => d.total),
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.08)',
                borderWidth: 2,
                tension: 0.25,
                fill: true,
                pointRadius: 3
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    const dataEq = <?= json_encode($topEquipos) ?>;
    new Chart(document.getElementById('chartEquipos'), {
        type: 'bar',
        data: {
            labels: dataEq.map(d => d.equipo),
            datasets: [{
                label: 'Cantidad',
                data: dataEq.map(d => d.total),
                backgroundColor: '#2563eb',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y'
        }
    });

    const dataEst = <?= json_encode($estadoDistribucion) ?>;
    new Chart(document.getElementById('chartEstados'), {
        type: 'doughnut',
        data: {
            labels: dataEst.map(d => d.estado),
            datasets: [{
                data: dataEst.map(d => d.total),
                backgroundColor: ['#64748b', '#2563eb', '#16a34a', '#dc2626', '#d97706', '#0ea5e9']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { boxWidth: 10, boxHeight: 10, font: { size: 11 } }
                }
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
