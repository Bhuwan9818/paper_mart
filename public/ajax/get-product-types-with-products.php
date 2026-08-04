<?php
// public/ajax/get-product-types-with-products.php
// Returns only product types (within a given category) that currently
// have at least one active product — used by the compare page's "Add
// Product" picker. Optional &vendor_id= further restricts to product
// types where THAT vendor specifically has active products (vendor-first flow).

require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');

$categoryId = (int)($_GET['category_id'] ?? 0);
$vendorId   = (int)($_GET['vendor_id'] ?? 0);
if (!$categoryId) { echo '[]'; exit; }

$sql = "SELECT DISTINCT pt.id, pt.name
        FROM product_types pt
        JOIN products p ON p.product_type_id = pt.id AND p.status = 'active'
        WHERE pt.category_id = ? AND pt.status = 1";
$params = [$categoryId];
if ($vendorId) { $sql .= " AND p.vendor_id = ?"; $params[] = $vendorId; }
$sql .= " ORDER BY pt.sort_order, pt.name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
