<?php
// public/ajax/slot-availability.php
// Returns, for a proposed start date + duration, the booking status of every
// active ad slot (used/max/free/full). Each slot is checked independently —
// slots are distinct, non-overlapping times of day (e.g. 6am-12pm, 12pm-6pm),
// so a full slot never affects availability in any other slot.

if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
header('Content-Type: application/json');

$start = trim($_GET['start'] ?? '');
$days  = max(1, (int)($_GET['days'] ?? 1));

if (!$start || !strtotime($start)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid date']);
    exit;
}

$end = date('Y-m-d', strtotime($start . ' +' . ($days - 1) . ' days'));

$slots = $pdo->query("SELECT id, max_concurrent FROM ad_slots WHERE is_active=1")->fetchAll();

$slotResult = [];
foreach ($slots as $s) {
    $used = getSlotActiveBannerCount($pdo, $s['id'], $start, $end);
    $slotResult[$s['id']] = [
        'used' => $used,
        'max'  => (int)$s['max_concurrent'],
        'free' => max(0, (int)$s['max_concurrent'] - $used),
        'full' => $used >= (int)$s['max_concurrent'],
    ];
}

echo json_encode([
    'ok'    => true,
    'start' => $start,
    'end'   => $end,
    'slots' => $slotResult,
]);

