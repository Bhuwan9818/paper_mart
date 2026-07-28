<?php
// public/ajax/slot-availability.php
// Returns, for a proposed start date + duration, the booking status of every
// active ad slot (used/max/free/full) PLUS the global cross-slot hero-banner
// capacity (max MAX_ACTIVE_HERO_BANNERS shown at once). Used by the vendor
// booking form to disable slots that are already fully booked for those dates.

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

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM banner_ads
     WHERE slot_id = ?
       AND status IN ('pending','approved','running')
       AND start_date <= ? AND end_date >= ?"
);

$slotResult = [];
foreach ($slots as $s) {
    $stmt->execute([$s['id'], $end, $start]);
    $used = (int)$stmt->fetchColumn();
    $slotResult[$s['id']] = [
        'used' => $used,
        'max'  => (int)$s['max_concurrent'],
        'free' => max(0, (int)$s['max_concurrent'] - $used),
        'full' => $used >= (int)$s['max_concurrent'],
    ];
}

$globalUsed = getGlobalActiveBannerCount($pdo, $start, $end);

echo json_encode([
    'ok'         => true,
    'start'      => $start,
    'end'        => $end,
    'slots'      => $slotResult,
    'globalUsed' => $globalUsed,
    'globalMax'  => MAX_ACTIVE_HERO_BANNERS,
    'globalFull' => $globalUsed >= MAX_ACTIVE_HERO_BANNERS,
]);
