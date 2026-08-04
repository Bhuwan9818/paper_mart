<?php
// public/ajax/get-industries-by-vendor.php
// Returns only industries where the given vendor currently has at least
// one active product — first step of the compare page's vendor-first
// "Add Product" picker (vendor → industry → category → type → product).

require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');

$vendorId = (int)($_GET['vendor_id'] ?? 0);
if (!$vendorId) { echo '[]'; exit; }

$stmt = $pdo->prepare(
    "SELECT DISTINCT i.id, i.name
     FROM industries i
     JOIN products p ON p.industry_id = i.id AND p.status = 'active'
     WHERE p.vendor_id = ? AND i.status = 1
     ORDER BY i.sort_order, i.name"
);
$stmt->execute([$vendorId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
