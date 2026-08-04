<?php
// public/ajax/get-products-by-type.php
// Returns active products for a given product_type_id, for the
// "Add Product" picker on the compare page (industry → category →
// product type → product name → add). Optional &vendor_id= restricts
// to just that vendor's products (vendor-first flow).
//
// Products the user has already added to their compare session are
// excluded, since adding a duplicate would just be rejected anyway —
// better to never show it as an option in the first place.

if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');

$productTypeId = (int)($_GET['product_type_id'] ?? 0);
$vendorId      = (int)($_GET['vendor_id'] ?? 0);
if (!$productTypeId) { echo '[]'; exit; }

$sessionKey = session_id();

$sql = "SELECT p.id, p.name, p.price_range, u.company, u.name AS vendor_name
        FROM products p
        JOIN users u ON u.id = p.vendor_id
        WHERE p.product_type_id = ? AND p.status = 'active'
          AND p.id NOT IN (
              SELECT product_id FROM compare_sessions WHERE session_key = ?
          )";
$params = [$productTypeId, $sessionKey];
if ($vendorId) { $sql .= " AND p.vendor_id = ?"; $params[] = $vendorId; }
$sql .= " ORDER BY p.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
