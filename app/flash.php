<?php
// app/flash.php

function setFlashMessage($type, $message) {
    $_SESSION['flash_messages'][] = [
        'type' => $type, // 'success', 'danger', 'warning', 'info'
        'message' => $message
    ];
}

function getFlashMessages() {
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

function displayFlashMessages() {
    $messages = getFlashMessages();
    $html = '';
    foreach ($messages as $msg) {
        $type = htmlspecialchars($msg['type']);
        $message = htmlspecialchars($msg['message']);
        $html .= "<div class=\"alert alert-{$type} alert-dismissible fade show\" role=\"alert\">
                    {$message}
                    <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\" aria-label=\"Close\"></button>
                  </div>";
    }
    return $html;
}
