<?php
// public/ajax/get-categories-with-products.php
// Returns only categories (within a given industry) that currently have
// at least one active product — used by the compare page's "Add
// Product" picker.

require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');

$industryId = (int)($_GET['industry_id'] ?? 0);
if (!$industryId) { echo '[]'; exit; }

$stmt = $pdo->prepare(
    "SELECT DISTINCT c.id, c.name
     FROM categories c
     JOIN products p ON p.category_id = c.id AND p.status = 'active'
     WHERE c.industry_id = ? AND c.status = 1
     ORDER BY c.sort_order, c.name"
);
$stmt->execute([$industryId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
