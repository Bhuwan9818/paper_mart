<?php // logout.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
logoutUser();
header('Location: ' . BASE_URL . '/login.php');
exit;
