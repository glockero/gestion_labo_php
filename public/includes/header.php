<?php
// public/includes/header.php
require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/flash.php';

requireLogin();
$user_role = $_SESSION['user_role'] ?? 'tecnico';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= generateCsrfToken() ?>">
    <title><?= e(APP_NAME) ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Tom Select -->
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
</head>
<body>
    <!-- Mobile Sidebar Toggler -->
    <button class="sidebar-toggler" id="sidebarToggler" title="Menu">
        <i class="bi bi-list fs-3"></i>
    </button>

    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

        <div id="flash-alerts">
            <?php
            $messages = getFlashMessages();
            foreach ($messages as $msg):
                $category = htmlspecialchars($msg['type']);
                $message = htmlspecialchars($msg['message']);
                $icon = 'bi-info-circle-fill text-primary';
                if ($category == 'success') $icon = 'bi-check-circle-fill text-success';
                if ($category == 'danger') $icon = 'bi-exclamation-triangle-fill text-danger';
            ?>
              <div class="alert alert-<?= $category ?> alert-dismissible fade show shadow-sm border-0 rounded-3 d-flex align-items-center mb-4" role="alert">
                <i class="bi <?= $icon ?> me-2 fs-5"></i>
                <div class="fw-medium"><?= $message ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
            <?php endforeach; ?>
        </div>
        
        <div class="fade-in">
