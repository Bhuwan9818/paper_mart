<?php
// public/ajax/check-ad-slot.php
// NOTE: superseded by public/ajax/slot-availability.php (which checks every
// slot in one call). Kept working here in case anything still calls it.
// Returns how many banner_ads bookings are already occupying a given slot
// for a proposed date range — checked per-slot only, since slots are
// independent, non-overlapping times of day.

if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
header('Content-Type: application/json');

$slotId = (int)($_GET['slot_id'] ?? 0);
$start  = trim($_GET['start'] ?? '');
$days   = max(1, (int)($_GET['days'] ?? 1));

if (!$slotId || !$start || !strtotime($start)) {
    echo json_encode(['ok' => false, 'used' => 0]);
    exit;
}

$end = date('Y-m-d', strtotime($start . ' +' . ($days - 1) . ' days'));

$used = getSlotActiveBannerCount($pdo, $slotId, $start, $end);

echo json_encode([
    'ok'   => true,
    'used' => $used,
]);
