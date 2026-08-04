<?php
// ajax/get-all-attributes.php
// Returns ALL master attributes for a given industry (+ global ones)
// Used by the multi-select dropdowns on the add-product page
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$industryId = (int)($_GET['industry_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT id, attribute_name, attribute_unit, attribute_type, options_list
    FROM master_attributes
    WHERE is_active = 1
      AND (industry_id = ? OR industry_id IS NULL)
    ORDER BY sort_order, attribute_name
");
$stmt->execute([$industryId ?: 0]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
