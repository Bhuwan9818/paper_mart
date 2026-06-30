<?php
// public/ajax/get-products-by-type.php
// Returns active products for a given product_type_id, for the
// "Add Product" picker on the compare page (industry → category →
// product type → product name → add).

require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');

$productTypeId = (int)($_GET['product_type_id'] ?? 0);
if (!$productTypeId) { echo '[]'; exit; }

$stmt = $pdo->prepare(
    "SELECT p.id, p.name, p.price_range, u.company, u.name AS vendor_name
     FROM products p
     JOIN users u ON u.id = p.vendor_id
     WHERE p.product_type_id = ? AND p.status = 'active'
     ORDER BY p.name ASC"
);
$stmt->execute([$productTypeId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
