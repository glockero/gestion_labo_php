<?php
// public/includes/sidebar.php
$current_page = basename($_SERVER['PHP_SELF']);
$system_pages = ['configuracion.php', 'importar_csv.php', 'historial.php'];
?>
<div class="sidebar" id="sidebar">
    <a class="sidebar-brand" href="<?= APP_URL ?>/index.php">
        <i class="bi bi-cpu text-info"></i>
        <span>Laboratorio</span>
    </a>
    
    <div class="px-3 mb-3 text-white-50" style="font-size: 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 10px;">
        <div id="sidebar-date" class="text-uppercase fw-bold" style="letter-spacing: 0.05em;"></div>
        <div id="sidebar-clock" class="fs-6 fw-bold text-info"></div>
    </div>

    <script>
        function updateSidebarClock() {
            const now = new Date();
            const optionsDate = { weekday: 'long', day: 'numeric', month: 'short' };
            const dateStr = now.toLocaleDateString('es-ES', optionsDate).replace('.', '');
            const timeStr = now.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            
            document.getElementById('sidebar-date').textContent = dateStr;
            document.getElementById('sidebar-clock').textContent = timeStr;
        }
        setInterval(updateSidebarClock, 1000);
        updateSidebarClock();
    </script>

    <div class="sidebar-nav">
        <div class="sidebar-section-label">OPERACION</div>
        <a href="<?= APP_URL ?>/index.php" class="sidebar-link <?= $current_page == 'index.php' ? 'active' : '' ?>">
            <i class="bi bi-list-ul"></i>
            <span>REPARACIONES</span>
        </a>

        <a href="<?= APP_URL ?>/reparacion_nueva.php" class="sidebar-link <?= $current_page == 'reparacion_nueva.php' ? 'active' : '' ?>">
            <i class="bi bi-plus-circle"></i>
            <span>NUEVA REPARACION</span>
        </a>

        <?php if ($user_role === 'admin'): ?>
        <div class="sidebar-section-label">ADMINISTRACION</div>
        <a href="<?= APP_URL ?>/../admin/configuracion.php" class="sidebar-link <?= in_array($current_page, $system_pages, true) ? 'active' : '' ?>">
            <i class="bi bi-gear"></i>
            <span>SISTEMA</span>
        </a>
        <?php endif; ?>
    </div>

    <?php if ($user_role === 'admin'): ?>
    <div class="sidebar-nav sidebar-nav-secondary">
        <div class="sidebar-section-label">ESTADISTICAS</div>
        <a href="<?= APP_URL ?>/dashboard.php" class="sidebar-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
            <i class="bi bi-graph-up-arrow"></i>
            <span>KPI DASHBOARD</span>
        </a>
        <a href="<?= APP_URL ?>/../admin/metricas.php" class="sidebar-link <?= $current_page == 'metricas.php' ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-line"></i>
            <span>MÉTRICAS</span>
        </a>
    </div>
    <?php endif; ?>

    <div class="mt-auto p-3" style="background-color: rgba(0, 0, 0, 0.2); border-top: 1px solid rgba(255,255,255,0.05);">
        <div class="d-flex align-items-center justify-content-between">
            <div class="text-white small text-truncate" style="max-width: 150px;">
                <i class="bi bi-person-circle me-1 text-info"></i> <strong><?= e($_SESSION['user_username']) ?></strong>
                <div class="mt-1">
                    <span class="badge bg-secondary" style="font-size: 0.65rem;"><?= strtoupper(e($user_role)) ?></span>
                </div>
            </div>
            <a href="<?= APP_URL ?>/logout.php" class="btn btn-sm btn-outline-light border-0" title="Cerrar Sesión">
                <i class="bi bi-box-arrow-right fs-5 text-danger"></i>
            </a>
        </div>
    </div>
</div>
