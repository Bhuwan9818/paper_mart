<?php
// public/ajax/get-brands-by-country.php
// Returns active vendors (brands) located in a given country, restricted
// to ones who currently have at least one active product. Powers the
// "By Country" mode of the homepage hero search card: Country → Brand.

require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');

$country = trim($_GET['country'] ?? '');
if (!$country) { echo '[]'; exit; }

$stmt = $pdo->prepare(
    "SELECT DISTINCT u.id, COALESCE(u.company, u.name) AS label
     FROM users u JOIN products p ON p.vendor_id = u.id AND p.status = 'active'
     WHERE u.role = 'vendor' AND u.status = 'active' AND u.country = ?
     ORDER BY label ASC"
);
$stmt->execute([$country]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
