<?php
// public/ajax/get-categories-with-products.php
// Returns only categories (within a given industry) that currently have
// at least one active product — used by the compare page's "Add
// Product" picker. Optional &vendor_id= further restricts to categories
// where THAT vendor specifically has active products (vendor-first flow).

require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');

$industryId = (int)($_GET['industry_id'] ?? 0);
$vendorId   = (int)($_GET['vendor_id'] ?? 0);
if (!$industryId) { echo '[]'; exit; }

$sql = "SELECT DISTINCT c.id, c.name
        FROM categories c
        JOIN products p ON p.category_id = c.id AND p.status = 'active'
        WHERE c.industry_id = ? AND c.status = 1";
$params = [$industryId];
if ($vendorId) { $sql .= " AND p.vendor_id = ?"; $params[] = $vendorId; }
$sql .= " ORDER BY c.sort_order, c.name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
