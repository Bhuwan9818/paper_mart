<?php
// ajax/get-master-attributes.php
// Returns master attributes filtered by industry_id
// Used by the "Add Extra Attribute" modal picker
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$industryId = (int)($_GET['industry_id'] ?? 0);

// Get attributes that belong to this industry OR are global (NULL)
$stmt = $pdo->prepare("
    SELECT id, attribute_name, attribute_unit, attribute_type, options_list
    FROM master_attributes
    WHERE is_active = 1
      AND (industry_id = ? OR industry_id IS NULL)
    ORDER BY sort_order, attribute_name
");
$stmt->execute([$industryId ?: 0]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Also get already-used attribute names for the product type if passed
$alreadyUsed = [];
if (!empty($_GET['used'])) {
    $alreadyUsed = array_map('strtolower', explode(',', $_GET['used']));
}

// Mark ones already in the template
foreach ($rows as &$row) {
    $row['already_used'] = in_array(strtolower($row['attribute_name']), $alreadyUsed);
}
unset($row);

echo json_encode($rows);
