<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
if (session_status()===PHP_SESSION_NONE) session_start();

// If logged in, go to dashboard; otherwise go to public website
if (isLoggedIn()) {
    $role = $_SESSION['role'] ?? 'customer';
    $dest = match($role) {
        'admin'    => BASE_URL . '/admin/dashboard.php',
        'vendor'   => BASE_URL . '/vendor/dashboard.php',
        'customer' => BASE_URL . '/customer/dashboard.php',
        default    => BASE_URL . '/public/index.php',
    };
    header('Location: ' . $dest);
} else {
    header('Location: ' . BASE_URL . '/public/index.php');
}
exit;
