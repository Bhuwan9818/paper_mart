<?php
// public/ajax/check-ad-slot.php
// Returns how many banner_ads bookings are already occupying
// a given slot for a proposed date range.
// Called by the vendor booking form's live availability check.

if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');

$slotId = (int)($_GET['slot_id'] ?? 0);
$start  = trim($_GET['start'] ?? '');
$days   = max(1, (int)($_GET['days'] ?? 1));

if (!$slotId || !$start || !strtotime($start)) {
    echo json_encode(['ok' => false, 'used' => 0]);
    exit;
}

$end = date('Y-m-d', strtotime($start . ' +' . ($days - 1) . ' days'));

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM banner_ads
     WHERE slot_id = ?
       AND status IN ('pending','approved','running')
       AND start_date <= ?
       AND end_date   >= ?"
);
$stmt->execute([$slotId, $end, $start]);

echo json_encode(['ok' => true, 'used' => (int)$stmt->fetchColumn()]);
