<?php
$pageTitle = $pageTitle ?? 'Dashboard';
if (session_status() === PHP_SESSION_NONE) session_start();
$role = $_SESSION['role'] ?? 'vendor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — VendorHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
</head>
<body>
<div class="layout">
<?php
if ($role === 'vendor' && !function_exists('getVendorSubscription')) {
    require_once __DIR__ . '/subscription.php';
}
include __DIR__ . '/sidebar.php';
?>
<div class="main">
<div class="sidebar-overlay" id="sidebar-overlay"></div>
