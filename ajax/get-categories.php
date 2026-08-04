<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
$industryId = (int)($_GET['industry_id'] ?? 0);
if (!$industryId) { echo '[]'; exit; }
$stmt = $pdo->prepare("SELECT id, name FROM categories WHERE industry_id = ? AND status = 1 ORDER BY sort_order, name");
$stmt->execute([$industryId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
