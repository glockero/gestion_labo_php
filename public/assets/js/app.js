// public/assets/js/app.js
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Tom Selects universally
    document.querySelectorAll('.tom-select').forEach((el) => {
        new TomSelect(el, {
            create: el.hasAttribute('data-allow-new'),
            // Default cap is 50 — too low for the equipos catalog (200+ rows).
            maxOptions: 1000,
            sortField: {
                field: "text",
                direction: "asc"
            }
        });
    });

    // Auto-hide flash messages after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('#flash-alerts .alert').forEach(alert => {
            let bsAlert = bootstrap.Alert.getInstance(alert) || new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});

// Helper for fetch API to include CSRF
async function fetchApi(url, options = {}) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                      document.querySelector('input[name="csrf_token"]')?.value;
                      
    if (!options.headers) {
        options.headers = {};
    }
    
    // Add CSRF to headers if POST/PUT/DELETE
    if (options.method && options.method.toUpperCase() !== 'GET') {
        options.headers['X-CSRF-TOKEN'] = csrfToken;
    }
    
    const response = await fetch(url, options);
    if (!response.ok) {
        throw new Error(`API Error: ${response.status}`);
    }
    return response.json();
}
