<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');
$user = currentUser();

// Core stats
$stats = [
    'vendors'       => $pdo->query("SELECT COUNT(*) FROM users WHERE role='vendor'")->fetchColumn(),
    'customers'     => $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn(),
    'products'      => $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
    'pending'       => $pdo->query("SELECT COUNT(*) FROM products WHERE status='pending'")->fetchColumn(),
    'featured'      => $pdo->query("SELECT COUNT(*) FROM products WHERE is_featured=1")->fetchColumn(),
    'enquiries'     => $pdo->query("SELECT COUNT(*) FROM enquiries")->fetchColumn(),
    'open_enq'      => $pdo->query("SELECT COUNT(*) FROM enquiries WHERE status='open'")->fetchColumn(),
    'industries'    => $pdo->query("SELECT COUNT(*) FROM industries")->fetchColumn(),
    'categories'    => $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn(),
];
// Featured products
$featuredProducts = $pdo->query("SELECT p.*,u.name AS vendor_name FROM products p JOIN users u ON u.id=p.vendor_id WHERE p.is_featured=1 AND p.status='active' ORDER BY p.updated_at DESC LIMIT 6")->fetchAll();

// Revenue stats (safe – tables may not exist yet)
try {
    $stats['total_revenue'] = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status='paid'")->fetchColumn();
    $stats['rev_this_month']= $pdo->query("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status='paid' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn();
    $stats['active_subs']   = $pdo->query("SELECT COUNT(*) FROM vendor_subscriptions WHERE status='active'")->fetchColumn();
    $stats['trial_subs']    = $pdo->query("SELECT COUNT(*) FROM vendor_subscriptions WHERE status='trial'")->fetchColumn();
} catch(Exception $e) {
    $stats['total_revenue'] = $stats['rev_this_month'] = $stats['active_subs'] = $stats['trial_subs'] = 0;
}

// Recent activity
$recentProducts  = $pdo->query("SELECT p.*,u.name AS vendor_name,pt.name AS type_name FROM products p JOIN users u ON u.id=p.vendor_id JOIN product_types pt ON pt.id=p.product_type_id ORDER BY p.created_at DESC LIMIT 5")->fetchAll();
$recentEnquiries = $pdo->query("SELECT e.*,u.name AS customer_name,v.name AS vendor_name FROM enquiries e JOIN users u ON u.id=e.customer_id JOIN users v ON v.id=e.vendor_id ORDER BY e.created_at DESC LIMIT 5")->fetchAll();

// Top vendors by products
$topVendors = $pdo->query("SELECT u.name, u.company, COUNT(p.id) AS pcount, u.status FROM users u LEFT JOIN products p ON p.vendor_id=u.id WHERE u.role='vendor' GROUP BY u.id ORDER BY pcount DESC LIMIT 5")->fetchAll();

// Monthly registrations (last 6 months) for chart
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $label = date('M Y', strtotime("-$i months"));
    $v = $pdo->query("SELECT COUNT(*) FROM users WHERE role='vendor' AND DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
    $c = $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer' AND DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
    $monthlyData[] = ['month'=>$label,'vendors'=>$v,'customers'=>$c];
}

// Revenue by month (last 6)
$revData = [];
try {
    for ($i = 5; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("-$i months"));
        $label = date('M', strtotime("-$i months"));
        $rev = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status='paid' AND DATE_FORMAT(paid_at,'%Y-%m')='$m'")->fetchColumn();
        $revData[] = ['month'=>$label,'revenue'=>(float)$rev];
    }
} catch(Exception $e) { $revData = []; }

// Plan distribution
$planDist = [];
try {
    $planDist = $pdo->query("SELECT sp.name, COUNT(vs.id) AS cnt FROM vendor_subscriptions vs JOIN subscription_plans sp ON sp.id=vs.plan_id WHERE vs.status IN ('active','trial') GROUP BY sp.id")->fetchAll();
} catch(Exception $e) {}

// Pending catalogue requests
try {
    $stats['pending_catalogue'] = $pdo->query("SELECT COUNT(*) FROM catalogue_requests WHERE status='pending'")->fetchColumn();
} catch(Exception $e) { $stats['pending_catalogue'] = 0; }

// New vendors this week
$newVendorsWeek = $pdo->query("SELECT COUNT(*) FROM users WHERE role='vendor' AND created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
$newProductsWeek= $pdo->query("SELECT COUNT(*) FROM products WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();

$pageTitle='Admin Dashboard'; $activePage='dashboard';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
.kpi-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:16px; margin-bottom:22px; }
.kpi-card {
  background:#fff; border-radius:var(--radius); padding:18px 20px;
  box-shadow:var(--shadow-sm); border:1px solid var(--border);
  display:flex; flex-direction:column; gap:8px; position:relative; overflow:hidden;
  transition:var(--transition);
}
.kpi-card:hover { box-shadow:var(--shadow); transform:translateY(-2px); }
.kpi-card::before { content:''; position:absolute; top:-20px; right:-20px; width:80px; height:80px; border-radius:50%; opacity:.07; }
.kpi-card.blue::before   { background:var(--info); }
.kpi-card.green::before  { background:var(--success); }
.kpi-card.purple::before { background:var(--purple); }
.kpi-card.amber::before  { background:var(--warning); }
.kpi-card.red::before    { background:var(--danger); }
.kpi-card.indigo::before { background:var(--primary); }

.kpi-label { font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); }
.kpi-value { font-size:28px; font-weight:800; letter-spacing:-1px; }
.kpi-value.blue   { color:var(--info); }
.kpi-value.green  { color:var(--success); }
.kpi-value.purple { color:var(--purple); }
.kpi-value.amber  { color:var(--warning); }
.kpi-value.red    { color:var(--danger); }
.kpi-value.indigo { color:var(--primary); }
.kpi-sub  { font-size:11.5px; color:var(--text-muted); display:flex; align-items:center; gap:4px; }
.kpi-badge { font-size:10px; padding:2px 7px; border-radius:100px; font-weight:700; }
.badge-up   { background:#d1fae5; color:#065f46; }
.badge-warn { background:#fef3c7; color:#92400e; }

.dash-2col { display:grid; grid-template-columns:1fr 1fr; gap:22px; }
.dash-3col { display:grid; grid-template-columns:2fr 1fr; gap:22px; }
@media(max-width:900px) { .dash-2col,.dash-3col { grid-template-columns:1fr; } }

.activity-item { display:flex; align-items:flex-start; gap:12px; padding:12px 0; border-bottom:1px solid var(--border-light); }
.activity-item:last-child { border:none; }
.activity-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; margin-top:6px; }

.chart-card { background:#fff; border-radius:var(--radius); padding:20px; box-shadow:var(--shadow-sm); border:1px solid var(--border); }
.chart-title { font-size:14px; font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px; }

.vendor-rank { display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid var(--border-light); }
.vendor-rank:last-child { border:none; }
.rank-num { width:24px; height:24px; border-radius:50%; background:var(--primary-light); color:var(--primary); font-size:11px; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }

.week-badge { display:inline-flex; align-items:center; gap:4px; background:var(--primary-light); color:var(--primary); font-size:11px; font-weight:600; padding:3px 9px; border-radius:100px; }
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Admin Dashboard</h1>
  </div>
  <div class="topbar-right">
    <?php if ($stats['pending'] > 0): ?>
      <a href="<?= BASE_URL ?>/admin/products.php?status=pending" class="btn btn-warning btn-sm">⏳ <?= $stats['pending'] ?> Pending</a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/admin/reports.php" class="btn btn-outline btn-sm">📄 Reports</a>
    <div class="topbar-avatar"><?= avatarLetter($user['name']) ?></div>
  </div>
</div>

<div class="content">
  <?= showFlash() ?>

  <div class="page-header">
    <div>
      <h1>👋 Admin Overview</h1>
      <div class="breadcrumb">
        <?= date('l, d F Y') ?>
        &nbsp;·&nbsp;
        <span class="week-badge">+<?= $newVendorsWeek ?> vendors this week</span>
        <span class="week-badge" style="margin-left:6px;background:var(--success-light);color:var(--success)">+<?= $newProductsWeek ?> products this week</span>
      </div>
    </div>
  </div>

  <!-- KPI Cards Row 1 -->
  <div class="kpi-grid">
    <div class="kpi-card blue">
      <div class="kpi-label">Total Vendors</div>
      <div class="kpi-value blue"><?= $stats['vendors'] ?></div>
      <div class="kpi-sub"><span class="kpi-badge badge-up">+<?= $newVendorsWeek ?> this week</span></div>
    </div>
    <div class="kpi-card green">
      <div class="kpi-label">Customers</div>
      <div class="kpi-value green"><?= $stats['customers'] ?></div>
      <div class="kpi-sub">Registered buyers</div>
    </div>
    <div class="kpi-card purple">
      <div class="kpi-label">Total Products</div>
      <div class="kpi-value purple"><?= $stats['products'] ?></div>
      <div class="kpi-sub">
        <span class="kpi-badge badge-warn"><?= $stats['pending'] ?> pending</span>
        &nbsp;<span style="background:#fffbeb;color:#92400e;font-size:10px;padding:2px 7px;border-radius:100px;font-weight:700">⭐ <?= $stats['featured'] ?> featured</span>
      </div>
    </div>
    <div class="kpi-card amber">
      <div class="kpi-label">Enquiries</div>
      <div class="kpi-value amber"><?= $stats['enquiries'] ?></div>
      <div class="kpi-sub"><?= $stats['open_enq'] ?> open</div>
    </div>
    <div class="kpi-card green">
      <div class="kpi-label">Revenue (Total)</div>
      <div class="kpi-value green">₹<?= number_format($stats['total_revenue']) ?></div>
      <div class="kpi-sub">All-time paid</div>
    </div>
    <div class="kpi-card indigo">
      <div class="kpi-label">Revenue (Month)</div>
      <div class="kpi-value indigo">₹<?= number_format($stats['rev_this_month']) ?></div>
      <div class="kpi-sub"><?= date('F Y') ?></div>
    </div>
    <div class="kpi-card blue">
      <div class="kpi-label">Active Subs</div>
      <div class="kpi-value blue"><?= $stats['active_subs'] ?></div>
      <div class="kpi-sub"><?= $stats['trial_subs'] ?> on trial</div>
    </div>
    <div class="kpi-card amber">
      <div class="kpi-label">Industries</div>
      <div class="kpi-value amber"><?= $stats['industries'] ?></div>
      <div class="kpi-sub"><?= $stats['categories'] ?> categories</div>
    </div>
  </div>

  <!-- Charts Row -->
  <div class="dash-2col" style="margin-bottom:22px">
    <div class="chart-card">
      <div class="chart-title">📈 Monthly Registrations</div>
      <canvas id="regChart" height="130"></canvas>
    </div>
    <div class="chart-card">
      <div class="chart-title">💰 Revenue Trend (6 months)</div>
      <canvas id="revChart" height="130"></canvas>
    </div>
  </div>

  <!-- Quick Actions -->
  <div class="card" style="margin-bottom:22px">
    <div class="card-header"><h2>⚡ Quick Actions</h2></div>
    <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap">
      <a href="<?= BASE_URL ?>/admin/products.php?status=pending" class="btn btn-warning">⏳ Review Pending (<?= $stats['pending'] ?>)</a>
      <a href="<?= BASE_URL ?>/admin/vendors.php"                 class="btn btn-primary">🏪 Manage Vendors</a>
      <a href="<?= BASE_URL ?>/admin/payments.php"                class="btn btn-primary" style="background:var(--success);border-color:var(--success)">💰 View Payments</a>
      <a href="<?= BASE_URL ?>/admin/subscriptions.php"           class="btn btn-outline">💳 Subscriptions</a>
      <a href="<?= BASE_URL ?>/admin/analytics.php"               class="btn btn-outline">📈 Analytics</a>
      <a href="<?= BASE_URL ?>/admin/reports.php"                 class="btn btn-outline">📄 Download Reports</a>
      <a href="<?= BASE_URL ?>/admin/industries.php"              class="btn btn-outline">🏭 Add Industry</a>
      <a href="<?= BASE_URL ?>/admin/attributes.php"              class="btn btn-outline">📋 Attributes</a>
      <a href="<?= BASE_URL ?>/admin/products.php?featured=1"       class="btn btn-outline" style="background:#fffbeb;border-color:#fde68a;color:#92400e">⭐ Featured (<?= $stats['featured'] ?>)</a>
      <?php if($stats['pending_catalogue'] > 0): ?>
      <a href="<?= BASE_URL ?>/admin/catalogue-requests.php" class="btn btn-warning">🗂️ <?= $stats['pending_catalogue'] ?> Catalogue Request(s)</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Data Tables Row -->
  <div class="dash-2col" style="margin-bottom:22px">
    <!-- Recent Products -->
    <div class="card">
      <div class="card-header"><h2>📦 Recent Products</h2><a href="<?= BASE_URL ?>/admin/products.php" class="btn btn-outline btn-sm">View All</a></div>
      <?php if ($recentProducts): ?>
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Product</th><th>Vendor</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach ($recentProducts as $p): ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/admin/edit-product.php?id=<?= $p['id'] ?>" style="font-weight:600;color:var(--text);text-decoration:none"><?= sanitize($p['name']) ?></a><br><small class="text-muted"><?= sanitize($p['type_name']) ?></small></td>
            <td><?= sanitize($p['vendor_name']) ?></td>
            <td><?= statusBadge($p['status']) ?></td>
            <td class="text-muted" style="white-space:nowrap">
              <?= timeAgo($p['created_at']) ?><br>
              <a href="<?= BASE_URL ?>/admin/edit-product.php?id=<?= $p['id'] ?>" style="font-size:11px">✏️ Edit</a>
              <?php if($p['status']==='pending'): ?>&nbsp;<a href="<?= BASE_URL ?>/admin/products.php?action=approve&id=<?= $p['id'] ?>" style="font-size:11px;color:#10b981" onclick="return confirm('Approve?')">✅ Approve</a><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="empty-state"><div class="es-icon">📭</div><p>No products yet</p></div><?php endif; ?>
    </div>

    <!-- Recent Enquiries -->
    <div class="card">
      <div class="card-header"><h2>📩 Recent Enquiries</h2><a href="<?= BASE_URL ?>/admin/enquiries.php" class="btn btn-outline btn-sm">View All</a></div>
      <?php if ($recentEnquiries): ?>
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Customer</th><th>Vendor</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach ($recentEnquiries as $e): ?>
          <tr>
            <td><?= sanitize($e['customer_name']) ?></td>
            <td><?= sanitize($e['vendor_name']) ?></td>
            <td><?= statusBadge($e['status']) ?></td>
            <td class="text-muted"><?= timeAgo($e['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="empty-state"><div class="es-icon">📭</div><p>No enquiries yet</p></div><?php endif; ?>
    </div>
  </div>

  <!-- Featured Products Control -->
  <div class="card" style="margin-bottom:22px">
    <div class="card-header">
      <h2>⭐ Featured Products <span style="font-size:12px;font-weight:400;color:var(--text-muted)"><?= count($featuredProducts) ?> active</span></h2>
      <a href="<?= BASE_URL ?>/admin/products.php?featured=1" class="btn btn-outline btn-sm" style="margin-left:auto">Manage All →</a>
    </div>
    <div class="card-body">
      <?php if ($featuredProducts): ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px">
        <?php foreach ($featuredProducts as $fp):
          $imgs = array_filter(explode(',', $fp['images']??'')); $fi = UPLOAD_URL.trim(reset($imgs)?:'');
        ?>
        <div style="border:1px solid var(--border);border-radius:12px;overflow:hidden;display:flex;gap:0;flex-direction:column;box-shadow:var(--shadow-sm)">
          <div style="height:80px;background:#f8faff;display:flex;align-items:center;justify-content:center;overflow:hidden">
            <?php if (reset($imgs)): ?><img src="<?= sanitize($fi) ?>" style="width:100%;height:100%;object-fit:cover"><?php else: ?><span style="font-size:32px">📦</span><?php endif; ?>
          </div>
          <div style="padding:10px 12px;flex:1">
            <div style="font-weight:700;font-size:13px;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= sanitize($fp['name']) ?></div>
            <div style="font-size:11.5px;color:var(--text-muted)"><?= sanitize($fp['vendor_name']) ?></div>
          </div>
          <div style="padding:8px 12px;display:flex;gap:6px;border-top:1px solid var(--border);background:#fafcff">
            <a href="<?= BASE_URL ?>/admin/edit-product.php?id=<?= $fp['id'] ?>" class="btn btn-outline btn-xs" style="flex:1;text-align:center">✏️ Edit</a>
            <a href="<?= BASE_URL ?>/admin/products.php?action=feature&id=<?= $fp['id'] ?>" class="btn btn-warning btn-xs" style="flex:1;text-align:center" onclick="return confirm('Remove from featured?')">☆ Unfeature</a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <div class="empty-state" style="padding:24px">
          <div class="es-icon">⭐</div>
          <p>No featured products yet. <a href="<?= BASE_URL ?>/admin/products.php">Go to Products</a> and click the ⭐ star icon on any product to feature it.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Bottom Row: Top Vendors + Plan Distribution -->
  <div class="dash-3col">
    <div class="card">
      <div class="card-header"><h2>🏆 Top Vendors by Products</h2><a href="<?= BASE_URL ?>/admin/vendors.php" class="btn btn-outline btn-sm">View All</a></div>
      <div class="card-body">
        <?php foreach ($topVendors as $i => $v): ?>
        <div class="vendor-rank">
          <div class="rank-num"><?= $i+1 ?></div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:13.5px"><?= sanitize($v['name']) ?></div>
            <div style="font-size:12px;color:var(--text-muted)"><?= sanitize($v['company'] ?: '—') ?></div>
          </div>
          <div style="text-align:right">
            <div style="font-weight:700;color:var(--primary)"><?= $v['pcount'] ?> products</div>
            <?= statusBadge($v['status']) ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$topVendors): ?><div class="empty-state" style="padding:20px"><p>No vendor data</p></div><?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h2>💳 Plan Distribution</h2><a href="<?= BASE_URL ?>/admin/subscriptions.php" class="btn btn-outline btn-sm">View All</a></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
        <?php if ($planDist): ?>
        <canvas id="planChart" height="160"></canvas>
        <?php else: ?>
        <div class="empty-state" style="padding:20px"><p>No subscription data yet</p></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
<script>
// Registration Chart
const regCtx = document.getElementById('regChart');
if (regCtx) {
  const regData = <?= json_encode($monthlyData) ?>;
  new Chart(regCtx, {
    type:'line',
    data:{
      labels: regData.map(d=>d.month),
      datasets:[
        {label:'Vendors',data:regData.map(d=>d.vendors),borderColor:'#6366f1',backgroundColor:'rgba(99,102,241,.08)',tension:.4,fill:true,pointRadius:4},
        {label:'Customers',data:regData.map(d=>d.customers),borderColor:'#10b981',backgroundColor:'rgba(16,185,129,.06)',tension:.4,fill:true,pointRadius:4}
      ]
    },
    options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:11}}}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
  });
}

// Revenue Chart
const revCtx = document.getElementById('revChart');
if (revCtx) {
  const revData = <?= json_encode($revData) ?>;
  new Chart(revCtx, {
    type:'bar',
    data:{
      labels: revData.map(d=>d.month),
      datasets:[{label:'Revenue (₹)',data:revData.map(d=>d.revenue),backgroundColor:'rgba(99,102,241,.75)',borderRadius:6,borderSkipped:false}]
    },
    options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'₹'+v.toLocaleString()}}}}
  });
}

// Plan Donut Chart
const planCtx = document.getElementById('planChart');
if (planCtx) {
  const planData = <?= json_encode($planDist) ?>;
  new Chart(planCtx, {
    type:'doughnut',
    data:{
      labels:planData.map(d=>d.name),
      datasets:[{data:planData.map(d=>d.cnt),backgroundColor:['#6366f1','#10b981','#f59e0b','#ef4444'],borderWidth:2,borderColor:'#fff'}]
    },
    options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:11}}}},cutout:'65%'}
  });
}
</script>
</div></div></body></html>
