<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('customer');
require_once __DIR__ . '/../includes/customer_subscription.php';
$user = currentUser();
$uid  = $user['id'];
$sub  = getCustomerSubscription($pdo, $uid);
$usage = getCustomerUsage($pdo, $uid);

$matchWhere = "(we.customer_id = ? OR (we.customer_id IS NULL AND we.email = ?))";
$matchParams = [$uid, $user['email']];

$totalEnq  = $pdo->prepare("SELECT COUNT(*) FROM web_enquiries we WHERE $matchWhere"); $totalEnq->execute($matchParams);  $totalEnq  = $totalEnq->fetchColumn();
$openEnq   = $pdo->prepare("SELECT COUNT(*) FROM web_enquiries we WHERE $matchWhere AND we.status='new'"); $openEnq->execute($matchParams);  $openEnq   = $openEnq->fetchColumn();
$closedEnq = $pdo->prepare("SELECT COUNT(*) FROM web_enquiries we WHERE $matchWhere AND we.status='closed'"); $closedEnq->execute($matchParams); $closedEnq = $closedEnq->fetchColumn();

$recent = $pdo->prepare("SELECT we.*, v.name AS vendor_name, v.company AS vendor_company, p.name AS product_name FROM web_enquiries we LEFT JOIN users v ON v.id=we.vendor_id LEFT JOIN products p ON p.id=we.product_id WHERE $matchWhere ORDER BY we.created_at DESC LIMIT 8");
$recent->execute($matchParams); $recentEnq = $recent->fetchAll();

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
        <?php include __DIR__ . '/../includes/topbar-user-menu.php'; ?>
    </div>
</div>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header">
        <h1>👋 Welcome, <?= sanitize(explode(' ',$user['name'])[0]) ?>!</h1>
    </div>

    <div class="card" style="margin-bottom:20px">
        <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
            <div>
                <div style="font-size:12.5px;color:var(--text-muted)">Your Plan</div>
                <div style="font-size:20px;font-weight:800;color:var(--primary)">⭐ <?= sanitize($sub['plan_name'] ?? 'Free') ?></div>
            </div>
            <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:12.5px">
                <div>Compares: <strong><?= $usage['compares_used'] ?><?= $sub['compare_limit']==-1?'':' / '.$sub['compare_limit'] ?></strong></div>
                <div>TDS Downloads: <strong><?= $usage['tds_downloaded'] ?><?= $sub['tds_limit']==-1?'':' / '.$sub['tds_limit'] ?></strong></div>
                <div>Enquiries: <strong><?= $usage['enquiries_sent'] ?><?= $sub['enquiry_limit']==-1?'':' / '.$sub['enquiry_limit'] ?></strong></div>
            </div>
            <?php if (($sub['slug'] ?? 'free') === 'free'): ?>
                <a href="<?= BASE_URL ?>/customer/subscription.php" class="btn btn-primary btn-sm">Upgrade to Premium</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/customer/subscription.php" class="btn btn-outline btn-sm">Manage Plan</a>
            <?php endif; ?>
        </div>
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
                <thead><tr><th>Vendor</th><th>Product</th><th>Status</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($recentEnq as $e): ?>
                <tr>
                    <td><?= sanitize($e['vendor_company'] ?: $e['vendor_name'] ?: '—') ?></td>
                    <td><?= sanitize($e['product_name'] ?? '—') ?></td>
                    <td><?= statusBadge($e['status']) ?></td>
                    <td class="text-muted"><?= timeAgo($e['created_at']) ?></td>
                    <td><a href="<?= BASE_URL ?>/customer/enquiries.php" class="btn btn-outline btn-xs">View →</a></td>
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
