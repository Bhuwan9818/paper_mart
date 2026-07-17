<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';
requireRoleStrict('vendor');
require_once __DIR__ . '/../includes/team.php';
requirePermission('analytics');
$user = currentUser(); $uid = $user['id'];
$sub  = getVendorSubscription($pdo, $uid);

// Generate last 6 months labels and mock data seeded from real DB counts
$months = []; $labels = [];
for ($i=5; $i>=0; $i--) {
    $ts = strtotime("-$i months");
    $months[] = date('Y-m', $ts);
    $labels[]  = date('M Y', $ts);
}

// Real enquiry counts per month
$enqData = [];
foreach ($months as $m) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM enquiries WHERE vendor_id=? AND DATE_FORMAT(created_at,'%Y-%m')=?");
    $s->execute([$uid,$m]); $enqData[] = (int)$s->fetchColumn();
}
// Real product counts per month
$prodData = [];
foreach ($months as $m) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM products WHERE vendor_id=? AND DATE_FORMAT(created_at,'%Y-%m')=?");
    $s->execute([$uid,$m]); $prodData[] = (int)$s->fetchColumn();
}
// Views: use analytics table if exists, else 0
$viewData = array_fill(0, 6, 0);
try {
    foreach ($months as $i => $m) {
        $s = $pdo->prepare("SELECT COALESCE(SUM(1),0) FROM vendor_analytics WHERE vendor_id=? AND DATE_FORMAT(event_date,'%Y-%m')=? AND event_type='product_view'");
        $s->execute([$uid,$m]); $viewData[$i] = (int)$s->fetchColumn();
    }
} catch (Exception $e) {}

// Top products by enquiry
$topProducts = $pdo->prepare("SELECT p.name, COUNT(e.id) AS enq_count FROM products p LEFT JOIN enquiries e ON e.product_id=p.id WHERE p.vendor_id=? GROUP BY p.id ORDER BY enq_count DESC LIMIT 5");
$topProducts->execute([$uid]); $topProducts=$topProducts->fetchAll();

// Summary totals
$totalViews    = array_sum($viewData);
$totalEnqiries = array_sum($enqData);
$totalProds    = array_sum($prodData);
$convRate      = $totalViews>0 ? round($totalEnqiries/$totalViews*100,1) : 0;

$pageTitle='Analytics'; $activePage='analytics';
include __DIR__ . '/../includes/head.php';
?>
<div class="topbar">
  <div class="topbar-left"><button class="hamburger" id="hamburger"><span></span><span></span><span></span></button><h1>Analytics</h1></div>
  <div class="topbar-right"><div class="topbar-avatar"><?= avatarLetter($user['name']) ?></div></div>
</div>
<div class="content">
  <?= subscriptionBanner($sub) ?>

  <?php if (!$sub['analytics']): ?>
  <div class="card" style="background:linear-gradient(135deg,#faf5ff,#f5f3ff);border:1px solid #e9d5ff">
    <div class="card-body" style="text-align:center;padding:40px">
      <div style="font-size:48px;margin-bottom:12px">📊</div>
      <h2 style="font-size:20px;font-weight:800;margin-bottom:8px">Advanced Analytics</h2>
      <p style="color:var(--text-muted);margin-bottom:20px">Upgrade to Professional or Enterprise to unlock detailed analytics, performance tracking, and growth insights.</p>
      <a href="<?= BASE_URL ?>/vendor/subscription.php" class="btn btn-purple">🚀 Upgrade to Unlock</a>
    </div>
  </div>
  <?php endif; ?>

  <!-- Summary stats -->
  <div class="stats-grid">
    <div class="stat-card indigo">
      <div class="stat-header"><div class="stat-icon indigo">👁️</div><span class="stat-trend flat">6 months</span></div>
      <div class="stat-value"><?= number_format($totalViews) ?></div>
      <div class="stat-label">Total Product Views</div>
    </div>
    <div class="stat-card amber">
      <div class="stat-header"><div class="stat-icon amber">📩</div><span class="stat-trend up">+<?= $enqData[5] ?> this month</span></div>
      <div class="stat-value"><?= number_format($totalEnqiries) ?></div>
      <div class="stat-label">Total Enquiries</div>
    </div>
    <div class="stat-card green">
      <div class="stat-header"><div class="stat-icon green">🎯</div></div>
      <div class="stat-value"><?= $convRate ?>%</div>
      <div class="stat-label">Conversion Rate</div>
      <div class="stat-sub">Views → Enquiries</div>
    </div>
    <div class="stat-card orange">
      <div class="stat-header"><div class="stat-icon orange">📦</div></div>
      <div class="stat-value"><?= $totalProds ?></div>
      <div class="stat-label">Products Added (6mo)</div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px">
    <!-- Enquiry + Views chart -->
    <div class="card">
      <div class="card-header">
        <h2>📈 Enquiries & Views Over Time</h2>
        <div class="chart-legend">
          <div class="legend-item"><div class="legend-dot" style="background:#6366f1"></div>Enquiries</div>
          <div class="legend-item"><div class="legend-dot" style="background:#10b981"></div>Views</div>
        </div>
      </div>
      <div class="card-body">
        <div class="chart-wrap"><canvas id="mainChart"></canvas></div>
      </div>
    </div>

    <!-- Top products -->
    <div class="card">
      <div class="card-header"><h2>🏆 Top Products</h2></div>
      <div class="card-body" style="padding:0">
        <?php if ($topProducts): ?>
        <?php $maxEnq = max(array_column($topProducts,'enq_count') ?: [1]); ?>
        <?php foreach ($topProducts as $i=>$tp): ?>
          <?php $pct = $maxEnq>0 ? round($tp['enq_count']/$maxEnq*100) : 0; ?>
          <div class="metric-row" style="padding:12px 22px">
            <div style="min-width:0;flex:1">
              <div class="metric-label" style="font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= sanitize($tp['name']) ?></div>
              <div class="usage-bar" style="margin-top:6px"><div class="usage-bar-fill safe" style="width:<?= $pct ?>%"></div></div>
            </div>
            <div class="metric-value" style="margin-left:14px;font-size:14px;flex-shrink:0"><?= $tp['enq_count'] ?> enq</div>
          </div>
        <?php endforeach; ?>
        <?php else: ?><div class="empty-state" style="padding:30px"><div class="empty-state-icon">📭</div><p>No data yet</p></div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Products added bar chart -->
  <div class="card">
    <div class="card-header"><h2>📦 Products Added Per Month</h2></div>
    <div class="card-body">
      <div class="chart-wrap"><canvas id="prodChart"></canvas></div>
    </div>
  </div>
</div>

<script>
const labels = <?= json_encode($labels) ?>;
const enqData = <?= json_encode($enqData) ?>;
const viewData = <?= json_encode($viewData) ?>;
const prodData = <?= json_encode($prodData) ?>;

Chart.defaults.font.family = 'Inter, sans-serif';
Chart.defaults.color = '#6b7280';

new Chart(document.getElementById('mainChart'), {
  type: 'line',
  data: {
    labels,
    datasets: [
      { label:'Enquiries', data:enqData, borderColor:'#6366f1', backgroundColor:'rgba(99,102,241,0.08)', tension:0.4, pointBackgroundColor:'#6366f1', pointRadius:4, fill:true },
      { label:'Views',     data:viewData, borderColor:'#10b981', backgroundColor:'rgba(16,185,129,0.08)', tension:0.4, pointBackgroundColor:'#10b981', pointRadius:4, fill:true }
    ]
  },
  options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true, grid:{color:'#f0f1f8'}}, x:{grid:{display:false}} } }
});

new Chart(document.getElementById('prodChart'), {
  type: 'bar',
  data: {
    labels,
    datasets: [{ label:'Products Added', data:prodData, backgroundColor:'rgba(99,102,241,0.75)', borderRadius:6, hoverBackgroundColor:'#6366f1' }]
  },
  options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true, grid:{color:'#f0f1f8'}}, x:{grid:{display:false}} } }
});

document.getElementById('hamburger').addEventListener('click',()=>{document.getElementById('sidebar').classList.add('open');document.getElementById('sidebar-overlay').classList.add('show');});
document.getElementById('sidebar-overlay').addEventListener('click',()=>{document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.remove('show');});
</script>
</div></div></body></html>
