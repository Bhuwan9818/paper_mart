<?php
// public/ajax/search.php
// Powers the live typeahead in the site header. Returns matching products
// (by name/description/tags AND by attribute spec like "20 BF" / "100 GSM",
// typed with or without a space) plus matching mills/vendors, so a buyer
// typing a mill name sees the mill directly instead of having to guess a
// product name first.

require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode(['products' => [], 'vendors' => []]); exit; }

$qNorm = strtolower(str_replace(' ', '', $q));

// Products — name/description/tags, or a matching attribute spec value+unit
// (e.g. attribute_value="20", attribute_unit="BF" matches a search for "20bf" or "20 bf").
$pStmt = $pdo->prepare(
    "SELECT p.id, p.name, p.slug, p.price_range, u.company, u.name AS vendor_name
     FROM products p
     JOIN users u ON u.id = p.vendor_id
     WHERE p.status = 'active' AND (
        p.name LIKE ? OR p.description LIKE ? OR p.tags LIKE ? OR EXISTS (
            SELECT 1 FROM product_attributes pa WHERE pa.product_id = p.id
            AND REPLACE(LOWER(CONCAT(pa.attribute_value, pa.attribute_unit)), ' ', '') LIKE ?
        )
     )
     ORDER BY p.is_featured DESC, p.views DESC
     LIMIT 6"
);
$pStmt->execute(["%$q%", "%$q%", "%$q%", "%$qNorm%"]);
$products = $pStmt->fetchAll(PDO::FETCH_ASSOC);

// Mills/vendors — matched by company or contact name, only ones with active products.
$vStmt = $pdo->prepare(
    "SELECT DISTINCT u.id, u.company, u.name,
            (SELECT COUNT(*) FROM products p2 WHERE p2.vendor_id = u.id AND p2.status = 'active') AS product_count
     FROM users u
     JOIN products p ON p.vendor_id = u.id AND p.status = 'active'
     WHERE u.role = 'vendor' AND u.status = 'active' AND (u.company LIKE ? OR u.name LIKE ?)
     ORDER BY u.company ASC
     LIMIT 5"
);
$vStmt->execute(["%$q%", "%$q%"]);
$vendors = $vStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['products' => $products, 'vendors' => $vendors]);
