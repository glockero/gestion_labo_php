<?php
// app/config.php
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'laboratorio_db');

define('APP_URL', '/laboratorio_php/public'); // Adjust for cPanel if needed
define('APP_NAME', 'Laboratorio Técnico');

// Display errors for development (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set default timezone
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Start session securely
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_start();
}
