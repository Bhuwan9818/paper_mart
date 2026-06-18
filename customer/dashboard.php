<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('customer');
$user = currentUser();
$uid  = $user['id'];

$totalEnq  = $pdo->prepare("SELECT COUNT(*) FROM enquiries WHERE customer_id=?"); $totalEnq->execute([$uid]);  $totalEnq  = $totalEnq->fetchColumn();
$openEnq   = $pdo->prepare("SELECT COUNT(*) FROM enquiries WHERE customer_id=? AND status='open'"); $openEnq->execute([$uid]);  $openEnq   = $openEnq->fetchColumn();
$closedEnq = $pdo->prepare("SELECT COUNT(*) FROM enquiries WHERE customer_id=? AND status='closed'"); $closedEnq->execute([$uid]); $closedEnq = $closedEnq->fetchColumn();

$recent = $pdo->prepare("SELECT e.*,v.name AS vendor_name,p.name AS product_name FROM enquiries e JOIN users v ON v.id=e.vendor_id LEFT JOIN products p ON p.id=e.product_id WHERE e.customer_id=? ORDER BY e.created_at DESC LIMIT 8");
$recent->execute([$uid]); $recentEnq = $recent->fetchAll();

$pageTitle='My Dashboard'; $activePage='dashboard';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <h1>Dashboard</h1>
    </div>
    <div class="topbar-right">
        <div class="topbar-avatar"><?= avatarLetter($user['name']) ?></div>
    </div>
</div>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header">
        <h1>👋 Welcome, <?= sanitize(explode(' ',$user['name'])[0]) ?>!</h1>
    </div>
    <div class="stats-grid" style="grid-template-columns:repeat(3,1fr)">
        <div class="stat-card"><div class="stat-icon amber">📩</div><div class="stat-info"><div class="value"><?= $totalEnq ?></div><div class="label">Total Enquiries</div></div></div>
        <div class="stat-card"><div class="stat-icon blue">🔓</div><div class="stat-info"><div class="value"><?= $openEnq ?></div><div class="label">Open Enquiries</div></div></div>
        <div class="stat-card"><div class="stat-icon green">✅</div><div class="stat-info"><div class="value"><?= $closedEnq ?></div><div class="label">Closed Enquiries</div></div></div>
    </div>
    <div class="card">
        <div class="card-header"><h2>📩 My Recent Enquiries</h2><a href="<?= BASE_URL ?>/customer/enquiries.php" class="btn btn-outline btn-sm">View All</a></div>
        <?php if ($recentEnq): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Vendor</th><th>Product</th><th>Subject</th><th>Status</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($recentEnq as $e): ?>
                <tr>
                    <td><?= sanitize($e['vendor_name']) ?></td>
                    <td><?= sanitize($e['product_name'] ?? '—') ?></td>
                    <td><?= sanitize($e['subject'] ?: 'General Enquiry') ?></td>
                    <td><?= statusBadge($e['status']) ?></td>
                    <td class="text-muted"><?= timeAgo($e['created_at']) ?></td>
                    <td><a href="<?= BASE_URL ?>/customer/enquiries.php?id=<?= $e['id'] ?>" class="btn btn-outline btn-xs">View →</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="empty-state"><div class="es-icon">📭</div><p>No enquiries yet. Browse products on the main website and send an enquiry!</p></div>
        <?php endif; ?>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
