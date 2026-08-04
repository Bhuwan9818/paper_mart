<?php
// public/ajax/get-vendors-with-products.php
// Returns active vendors that currently have at least one active product —
// used by the compare page's "Add Product" picker, vendor-first flow.
// Supports an optional ?q= search filter (company name or vendor name) so
// the dropdown stays usable even with a large number of vendors.

require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

$sql = "SELECT DISTINCT u.id, u.company, u.name,
               (SELECT COUNT(*) FROM products p2 WHERE p2.vendor_id = u.id AND p2.status = 'active') AS product_count
        FROM users u
        JOIN products p ON p.vendor_id = u.id AND p.status = 'active'
        WHERE u.role = 'vendor' AND u.status = 'active'";
$params = [];
if ($q !== '') {
    $sql .= " AND (u.company LIKE ? OR u.name LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%";
}
$sql .= " ORDER BY u.company ASC, u.name ASC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
