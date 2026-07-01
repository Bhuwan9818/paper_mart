<?php
// public/ajax/get-product-types-with-products.php
// Returns only product types (within a given category) that currently
// have at least one active product — used by the compare page's "Add
// Product" picker.

require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');

$categoryId = (int)($_GET['category_id'] ?? 0);
if (!$categoryId) { echo '[]'; exit; }

$stmt = $pdo->prepare(
    "SELECT DISTINCT pt.id, pt.name
     FROM product_types pt
     JOIN products p ON p.product_type_id = pt.id AND p.status = 'active'
     WHERE pt.category_id = ? AND pt.status = 1
     ORDER BY pt.sort_order, pt.name"
);
$stmt->execute([$categoryId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
