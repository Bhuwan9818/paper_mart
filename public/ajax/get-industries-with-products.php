<?php
// public/ajax/get-industries-with-products.php
// Returns only industries that currently have at least one active
// product listed — used by the compare page's "Add Product" picker so
// buyers never see an industry that turns out to be empty further down
// the Industry → Category → Product Type → Product chain.

require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');

$stmt = $pdo->query(
    "SELECT DISTINCT i.id, i.name
     FROM industries i
     JOIN products p ON p.industry_id = i.id AND p.status = 'active'
     WHERE i.status = 1
     ORDER BY i.sort_order, i.name"
);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
