<?php
// Returns ALL attribute definitions — no longer filtered by product type.
// The vendor selects whichever attributes apply to their product.
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$stmt = $pdo->query("SELECT * FROM attribute_definitions ORDER BY sort_order, id");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
