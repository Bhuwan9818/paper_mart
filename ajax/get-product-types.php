<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
$categoryId = (int)($_GET['category_id'] ?? 0);
if (!$categoryId) { echo '[]'; exit; }
$stmt = $pdo->prepare("SELECT id, name FROM product_types WHERE category_id = ? AND status = 1 ORDER BY sort_order, name");
$stmt->execute([$categoryId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
