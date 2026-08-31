<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('customer');

$cid = currentUser()['id'];
$id  = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id=? AND vendor_id=? AND type='customer_subscription'");
$stmt->execute([$id, $cid]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found, or you do not have access to it.');
}

include __DIR__ . '/../includes/invoice-template.php';
