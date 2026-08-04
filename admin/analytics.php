<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

$range = (int)($_GET['range'] ?? 6); // months
if (!in_array($range,[1,3,6,12])) $range = 6;

// Monthly user registrations
$regData = [];
for ($i=$range-1;$i>=0;$i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $lbl = date('M Y', strtotime("-$i months"));
    $v = $pdo->query("SELECT COUNT(*) FROM users WHERE role='vendor' AND DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
    $c = $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer' AND DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
    $regData[] = ['month'=>$lbl,'vendors'=>(int)$v,'customers'=>(int)$c];
}

// Monthly products added
$prodData = [];
for ($i=$range-1;$i>=0;$i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $lbl = date('M', strtotime("-$i months"));
    $cnt = $pdo->query("SELECT COUNT(*) FROM products WHERE DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
    $app = $pdo->query("SELECT COUNT(*) FROM products WHERE status='active' AND DATE_FORMAT(updated_at,'%Y-%m')='$m'")->fetchColumn();
    $prodData[] = ['month'=>$lbl,'total'=>(int)$cnt,'approved'=>(int)$app];
}

// Monthly enquiries
$enqData = [];
for ($i=$range-1;$i>=0;$i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $lbl = date('M', strtotime("-$i months"));
    $cnt = $pdo->query("SELECT COUNT(*) FROM enquiries WHERE DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
    $enqData[] = ['month'=>$lbl,'count'=>(int)$cnt];
}

// Revenue monthly
$revData = [];
try {
    for ($i=$range-1;$i>=0;$i--) {
        $m = date('Y-m', strtotime("-$i months"));
        $lbl = date('M', strtotime("-$i months"));
        $rev = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status='paid' AND DATE_FORMAT(paid_at,'%Y-%m')='$m'")->fetchColumn();
        $revData[] = ['month'=>$lbl,'revenue'=>(float)$rev];
    }
} catch(Exception $e) { $revData = []; }

// Industry distribution
$industryDist = $pdo->query("SELECT i.name, COUNT(p.id) AS cnt FROM industries i LEFT JOIN products p ON p.industry_id=i.id GROUP BY i.id ORDER BY cnt DESC LIMIT 8")->fetchAll();

// Product status breakdown
$statusDist = $pdo->query("SELECT status, COUNT(*) AS cnt FROM products GROUP BY status")->fetchAll();

// Top enquired vendors
$topEnqVendors = $pdo->query("SELECT u.name, u.company, COUNT(e.id) AS cnt FROM enquiries e JOIN users u ON u.id=e.vendor_id GROUP BY e.vendor_id ORDER BY cnt DESC LIMIT 8")->fetchAll();

// Enquiry conversion rate (closed / total)
$totalEnq = $pdo->query("SELECT COUNT(*) FROM enquiries")->fetchColumn();
$closedEnq = $pdo->query("SELECT COUNT(*) FROM enquiries WHERE status='closed'")->fetchColumn();
$convRate = $totalEnq > 0 ? round(($closedEnq/$totalEnq)*100,1) : 0;

// Plan revenue breakdown
$planRevenue = [];
try {
    $planRevenue = $pdo->query("SELECT pl.name, pl.color, COALESCE(SUM(sp.amount),0) AS total FROM subscription_payments sp JOIN subscription_plans pl ON pl.id=sp.plan_id WHERE sp.status='paid' GROUP BY sp.plan_id ORDER BY total DESC")->fetchAll();
} catch(Exception $e) {}

// Active vendors this month
$activeVendorsMonth = $pdo->query("SELECT COUNT(DISTINCT vendor_id) FROM products WHERE DATE_FORMAT(created_at,'%Y-%m')='".date('Y-m')."'")->fetchColumn();

$pageTitle='Analytics'; $activePage='analytics';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
.analytics-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:16px; margin-bottom:22px; }
.metric-card { background:#fff; border-radius:var(--radius); padding:20px; box-shadow:var(--shadow-sm); border:1px solid var(--border); }
.metric-val  { font-size:32px; font-weight:800; letter-spacing:-1.5px; }
.metric-lbl  { font-size:12px; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.4px; margin-bottom:6px; }
.metric-sub  { font-size:12px; color:var(--text-muted); margin-top:6px; }
.chart-2col  { display:grid; grid-template-columns:1fr 1fr; gap:22px; margin-bottom:22px; }
.chart-3col  { display:grid; grid-template-columns:2fr 1fr; gap:22px; margin-bottom:22px; }
.chart-box   { background:#fff; border-radius:var(--radius); padding:20px; box-shadow:var(--shadow-sm); border:1px solid var(--border); }
.chart-hdr   { font-size:14px; font-weight:700; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; }
@media(max-width:900px) { .chart-2col,.chart-3col { grid-template-columns:1fr; } }
.range-btn { padding:5px 12px; border-radius:100px; font-size:12px; font-weight:600; border:1px solid var(--border); background:#fff; cursor:pointer; transition:all .15s; }
.range-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Analytics</h1>
  </div>
  <div class="topbar-right">
    <a href="reports.php" class="btn btn-outline btn-sm">📄 Generate Report</a>
  </div>
</div>

<div class="content">
  <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <h1>📈 Platform Analytics</h1>
    <div style="display:flex;gap:6px">
      <?php foreach([1=>'1M',3=>'3M',6=>'6M',12=>'12M'] as $r=>$lbl): ?>
      <a href="?range=<?=$r?>" class="range-btn <?=$range==$r?'active':''?>"><?=$lbl?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Metric Cards -->
  <div class="analytics-grid">
    <?php
    $totalVendors   = $pdo->query("SELECT COUNT(*) FROM users WHERE role='vendor'")->fetchColumn();
    $totalCustomers = $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
    $totalProducts  = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $totalEnquiries = $pdo->query("SELECT COUNT(*) FROM enquiries")->fetchColumn();
    $totalRevenue   = 0; try { $totalRevenue = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status='paid'")->fetchColumn(); } catch(Exception $e){}
    $activeSubs     = 0; try { $activeSubs = $pdo->query("SELECT COUNT(*) FROM vendor_subscriptions WHERE status='active'")->fetchColumn(); } catch(Exception $e){}
    ?>
    <div class="metric-card"><div class="metric-lbl">Total Vendors</div><div class="metric-val" style="color:var(--info)"><?=$totalVendors?></div><div class="metric-sub"><?=$activeVendorsMonth?> active this month</div></div>
    <div class="metric-card"><div class="metric-lbl">Total Customers</div><div class="metric-val" style="color:var(--success)"><?=$totalCustomers?></div></div>
    <div class="metric-card"><div class="metric-lbl">Total Products</div><div class="metric-val" style="color:var(--purple)"><?=$totalProducts?></div></div>
    <div class="metric-card"><div class="metric-lbl">Enquiry Conversion</div><div class="metric-val" style="color:var(--warning)"><?=$convRate?>%</div><div class="metric-sub"><?=$closedEnq?>/<?=$totalEnq?> closed</div></div>
    <div class="metric-card"><div class="metric-lbl">Total Revenue</div><div class="metric-val" style="color:var(--success)">₹<?=number_format($totalRevenue)?></div></div>
    <div class="metric-card"><div class="metric-lbl">Active Subscriptions</div><div class="metric-val" style="color:var(--primary)"><?=$activeSubs?></div></div>
  </div>

  <!-- Registration & Revenue Charts -->
  <div class="chart-2col">
    <div class="chart-box">
      <div class="chart-hdr">👥 User Registrations<span style="font-size:12px;font-weight:400;color:var(--text-muted)"><?=$range?> months</span></div>
      <canvas id="regChart" height="140"></canvas>
    </div>
    <div class="chart-box">
      <div class="chart-hdr">💰 Revenue Trend<span style="font-size:12px;font-weight:400;color:var(--text-muted)"><?=$range?> months</span></div>
      <canvas id="revChart" height="140"></canvas>
    </div>
  </div>

  <!-- Products & Enquiries -->
  <div class="chart-2col">
    <div class="chart-box">
      <div class="chart-hdr">📦 Products Submitted vs Approved</div>
      <canvas id="prodChart" height="140"></canvas>
    </div>
    <div class="chart-box">
      <div class="chart-hdr">📩 Monthly Enquiries</div>
      <canvas id="enqChart" height="140"></canvas>
    </div>
  </div>

  <!-- Industry + Status + Plan Revenue -->
  <div class="chart-2col">
    <div class="chart-box">
      <div class="chart-hdr">🏭 Products by Industry</div>
      <canvas id="indChart" height="160"></canvas>
    </div>
    <div class="chart-box">
      <div class="chart-hdr">🍩 Product Status Breakdown</div>
      <canvas id="statusChart" height="160"></canvas>
    </div>
  </div>

  <!-- Plan Revenue & Top Vendors -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:22px">
    <div class="chart-box">
      <div class="chart-hdr">💳 Revenue by Plan</div>
      <?php if($planRevenue): ?>
      <canvas id="planRevChart" height="160"></canvas>
      <?php else: ?><div class="empty-state" style="padding:20px"><p>No revenue data yet</p></div><?php endif; ?>
    </div>
    <div class="chart-box">
      <div class="chart-hdr">🏆 Most Enquired Vendors</div>
      <?php foreach($topEnqVendors as $i=>$v): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--border-light)">
        <div style="width:22px;height:22px;border-radius:50%;background:var(--primary-light);color:var(--primary);font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center"><?=$i+1?></div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:500;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=sanitize($v['name'])?></div>
          <div style="font-size:11px;color:var(--text-muted)"><?=sanitize($v['company']??'')?></div>
        </div>
        <div style="font-weight:700;color:var(--primary);white-space:nowrap"><?=$v['cnt']?> enquiries</div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
<script>
const regData    = <?=json_encode($regData)?>;
const revData    = <?=json_encode($revData)?>;
const prodData   = <?=json_encode($prodData)?>;
const enqData    = <?=json_encode($enqData)?>;
const indData    = <?=json_encode($industryDist)?>;
const statusData = <?=json_encode($statusDist)?>;
const planRev    = <?=json_encode($planRevenue)?>;

const COLORS = ['#6366f1','#10b981','#f59e0b','#ef4444','#8b5cf6','#f97316','#3b82f6','#ec4899'];

new Chart(document.getElementById('regChart'),{type:'line',data:{labels:regData.map(d=>d.month),datasets:[{label:'Vendors',data:regData.map(d=>d.vendors),borderColor:'#6366f1',backgroundColor:'rgba(99,102,241,.08)',tension:.4,fill:true,pointRadius:3},{label:'Customers',data:regData.map(d=>d.customers),borderColor:'#10b981',backgroundColor:'rgba(16,185,129,.06)',tension:.4,fill:true,pointRadius:3}]},options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:11}}}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});

new Chart(document.getElementById('revChart'),{type:'bar',data:{labels:revData.map(d=>d.month),datasets:[{label:'Revenue ₹',data:revData.map(d=>d.revenue),backgroundColor:'rgba(99,102,241,.8)',borderRadius:5}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'₹'+v.toLocaleString()}}}}});

new Chart(document.getElementById('prodChart'),{type:'bar',data:{labels:prodData.map(d=>d.month),datasets:[{label:'Submitted',data:prodData.map(d=>d.total),backgroundColor:'rgba(99,102,241,.6)',borderRadius:4},{label:'Approved',data:prodData.map(d=>d.approved),backgroundColor:'rgba(16,185,129,.7)',borderRadius:4}]},options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:11}}}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});

new Chart(document.getElementById('enqChart'),{type:'line',data:{labels:enqData.map(d=>d.month),datasets:[{label:'Enquiries',data:enqData.map(d=>d.count),borderColor:'#f59e0b',backgroundColor:'rgba(245,158,11,.08)',tension:.4,fill:true,pointRadius:4,pointBackgroundColor:'#f59e0b'}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});

new Chart(document.getElementById('indChart'),{type:'bar',data:{labels:indData.map(d=>d.name),datasets:[{label:'Products',data:indData.map(d=>d.cnt),backgroundColor:COLORS.slice(0,indData.length),borderRadius:5}]},options:{responsive:true,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{precision:0}}}}});

new Chart(document.getElementById('statusChart'),{type:'doughnut',data:{labels:statusData.map(d=>d.status),datasets:[{data:statusData.map(d=>d.cnt),backgroundColor:['#10b981','#f59e0b','#6366f1'],borderWidth:2,borderColor:'#fff'}]},options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:11}}}},cutout:'60%'}});

if (document.getElementById('planRevChart')) {
  new Chart(document.getElementById('planRevChart'),{type:'bar',data:{labels:planRev.map(d=>d.name),datasets:[{label:'Revenue ₹',data:planRev.map(d=>d.total),backgroundColor:planRev.map(d=>d.color+'cc'),borderRadius:6}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'₹'+v.toLocaleString()}}}}});
}
</script>
</div></div></body></html>
