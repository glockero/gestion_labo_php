<?php
// public/includes/footer.php
?>
        </div> <!-- End fade-in -->
    </div> <!-- End main-content -->

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Tom Select JS -->
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        const sidebarToggler = document.getElementById('sidebarToggler');
        const sidebar = document.getElementById('sidebar');
        
        if (sidebarToggler && sidebar) {
            sidebarToggler.addEventListener('click', () => {
                sidebar.classList.toggle('show');
            });

            document.addEventListener('click', (e) => {
                if (window.innerWidth < 992 && 
                    !sidebar.contains(e.target) && 
                    !sidebarToggler.contains(e.target) && 
                    sidebar.classList.contains('show')) {
                    sidebar.classList.remove('show');
                }
            });
        }

        function showToast(message, type = 'info') {
            const toastContainer = document.querySelector('.toast-container');
            const toastId = 'toast-' + Date.now();
            let icon = 'bi-info-circle';
            let headerClass = 'text-primary';
            
            if (type === 'success') { icon = 'bi-check-circle'; headerClass = 'text-success'; }
            if (type === 'danger') { icon = 'bi-exclamation-triangle'; headerClass = 'text-danger'; }
            if (type === 'warning') { icon = 'bi-exclamation-circle'; headerClass = 'text-warning'; }

            const toastHTML = `
                <div id="${toastId}" class="toast border-0 shadow-sm" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="toast-header border-0">
                        <i class="bi ${icon} ${headerClass} me-2"></i>
                        <strong class="me-auto">Gestión de Reparaciones</strong>
                        <button type="button" class="btn-close shadow-none" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                    <div class="toast-body bg-white rounded-bottom">
                        ${message}
                    </div>
                </div>
            `;
            
            toastContainer.insertAdjacentHTML('beforeend', toastHTML);
            const toastElement = document.getElementById(toastId);
            const toast = new bootstrap.Toast(toastElement, { delay: 4000, autohide: true });
            toast.show();
            
            toastElement.addEventListener('hidden.bs.toast', () => {
                toastElement.remove();
            });
        }
    </script>
    <!-- Custom App JS -->
    <script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
