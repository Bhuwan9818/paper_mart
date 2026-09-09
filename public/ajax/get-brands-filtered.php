<?php
// public/ajax/get-brands-filtered.php
// Returns active vendors (brands) that currently have at least one active
// product matching whatever combination of filters is supplied. Powers
// the "Brand" field in both modes of the homepage hero search card:
//   - Industry mode: optionally scoped by industry_id / category_id / type_id / country
//   - Brand mode:    optionally scoped by country only (brand comes first there)
// No filters supplied -> every active vendor with at least one live product.

require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');

$industryId = (int)($_GET['industry_id'] ?? 0);
$categoryId = (int)($_GET['category_id'] ?? 0);
$typeId     = (int)($_GET['type_id'] ?? 0);
$country    = trim($_GET['country'] ?? '');

$sql = "SELECT DISTINCT u.id, COALESCE(u.company, u.name) AS label
        FROM users u
        JOIN products p ON p.vendor_id = u.id AND p.status = 'active'
        WHERE u.role = 'vendor' AND u.status = 'active'";
$params = [];
if ($industryId) { $sql .= " AND p.industry_id = ?";     $params[] = $industryId; }
if ($categoryId) { $sql .= " AND p.category_id = ?";     $params[] = $categoryId; }
if ($typeId)     { $sql .= " AND p.product_type_id = ?"; $params[] = $typeId; }
if ($country !== '') { $sql .= " AND u.country = ?";     $params[] = $country; }
$sql .= " ORDER BY label ASC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
