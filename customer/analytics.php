<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/customer_subscription.php';
requireRoleStrict('customer');

$user = currentUser();
$cid  = $user['id'];
$sub  = getCustomerSubscription($pdo, $cid);
$hasFullAnalytics = $sub ? customerHasFullAnalytics($sub) : false;

$matchWhere = "(we.customer_id = ? OR (we.customer_id IS NULL AND we.email = ?))";
$matchParams = [$cid, $user['email']];

// Basic summary — shown to everyone regardless of plan
$totalEnq = $pdo->prepare("SELECT COUNT(*) FROM web_enquiries we WHERE $matchWhere");
$totalEnq->execute($matchParams); $totalEnq = $totalEnq->fetchColumn();

$usage = getCustomerUsage($pdo, $cid);

// Full analytics — only computed/shown for Premium customers
$monthLabels = []; $monthCounts = []; $topVendors = [];
if ($hasFullAnalytics) {
    for ($i = 5; $i >= 0; $i--) {
        $ts = strtotime("-$i months");
        $m = date('Y-m', $ts);
        $monthLabels[] = date('M Y', $ts);
        $c = $pdo->prepare("SELECT COUNT(*) FROM web_enquiries we WHERE $matchWhere AND DATE_FORMAT(we.created_at,'%Y-%m')=?");
        $c->execute(array_merge($matchParams, [$m]));
        $monthCounts[] = (int)$c->fetchColumn();
    }
    $tv = $pdo->prepare("SELECT COALESCE(v.company,v.name) AS vendor_label, COUNT(*) AS cnt FROM web_enquiries we JOIN users v ON v.id=we.vendor_id WHERE $matchWhere GROUP BY we.vendor_id ORDER BY cnt DESC LIMIT 6");
    $tv->execute($matchParams); $topVendors = $tv->fetchAll();
}

$pageTitle = 'Analytics'; $activePage = 'analytics';
include __DIR__ . '/../includes/head.php';
?>
<?php // renderTopbar('Analytics'); ?>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header"><h1>📊 My Analytics</h1></div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:22px">
        <div class="stat-card"><div class="stat-icon blue">📩</div><div class="stat-info"><div class="value"><?= $totalEnq ?></div><div class="label">Total Enquiries Sent</div></div></div>
        <div class="stat-card"><div class="stat-icon green">🔍</div><div class="stat-info"><div class="value"><?= $usage['compares_used'] ?></div><div class="label">Comparisons This Month</div></div></div>
        <div class="stat-card"><div class="stat-icon orange">📄</div><div class="stat-info"><div class="value"><?= $usage['tds_downloaded'] ?></div><div class="label">TDS Downloads This Month</div></div></div>
    </div>

    <?php if (!$hasFullAnalytics): ?>
        <div class="card">
            <div class="card-body" style="text-align:center;padding:48px 24px">
                <div style="font-size:40px;margin-bottom:10px">📈</div>
                <h2 style="font-size:20px;margin-bottom:8px">Unlock Full Analytics</h2>
                <p style="color:var(--text-muted);max-width:440px;margin:0 auto 20px">
                    See your enquiry trends over time, which vendors you contact most, and deeper insights into your activity — available on Premium.
                </p>
                <a href="<?= BASE_URL ?>/customer/subscription.php" class="btn btn-primary">Upgrade to Premium</a>
            </div>
        </div>
    <?php else: ?>
        <div class="card" style="margin-bottom:20px">
            <div class="card-header"><h2>Enquiries Sent — Last 6 Months</h2></div>
            <div class="card-body"><canvas id="enqChart" height="90"></canvas></div>
        </div>
        <div class="card">
            <div class="card-header"><h2>Vendors You Contact Most</h2></div>
            <div class="card-body">
                <?php if ($topVendors): ?>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Vendor</th><th>Enquiries Sent</th></tr></thead>
                        <tbody>
                        <?php foreach ($topVendors as $tv): ?>
                        <tr><td><?= sanitize($tv['vendor_label']) ?></td><td style="font-weight:700"><?= $tv['cnt'] ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="empty-state"><p>No enquiries yet.</p></div>
                <?php endif; ?>
            </div>
        </div>
        <script>
        new Chart(document.getElementById('enqChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode($monthLabels) ?>,
                datasets: [{ label: 'Enquiries Sent', data: <?= json_encode($monthCounts) ?>, borderColor: '#8b241d', backgroundColor: 'rgba(139,36,29,.08)', fill: true, tension: 0.3 }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
        </script>
    <?php endif; ?>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
