<?php
require_once dirname(__DIR__,2).'/config.php';
header('Content-Type: application/json');
$q = trim($_GET['q']??'');
if (strlen($q)<2){ echo json_encode([]); exit; }
$stmt=$pdo->prepare("SELECT id,name,slug,price_range FROM products WHERE status='active' AND (name LIKE ? OR description LIKE ? OR tags LIKE ?) LIMIT 8");
$stmt->execute(["%$q%","%$q%","%$q%"]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
