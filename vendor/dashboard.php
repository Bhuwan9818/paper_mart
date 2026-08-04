<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';
requireRoleStrict('vendor');
$user = currentUser(); $uid = $user['id'];

$sub   = getVendorSubscription($pdo, $uid);
$usage = getVendorUsage($pdo, $uid);
$prodCheck = checkProductLimit($pdo, $uid, $sub);
$enqCheck  = checkEnquiryLimit($pdo, $uid, $sub);

$totalProducts  = $pdo->prepare("SELECT COUNT(*) FROM products WHERE vendor_id=?"); $totalProducts->execute([$uid]); $totalProducts=$totalProducts->fetchColumn();
$activeProducts = $pdo->prepare("SELECT COUNT(*) FROM products WHERE vendor_id=? AND status='active'"); $activeProducts->execute([$uid]); $activeProducts=$activeProducts->fetchColumn();
$totalEnquiries = $pdo->prepare("SELECT COUNT(*) FROM enquiries WHERE vendor_id=?"); $totalEnquiries->execute([$uid]); $totalEnquiries=$totalEnquiries->fetchColumn();
$openEnquiries  = $pdo->prepare("SELECT COUNT(*) FROM enquiries WHERE vendor_id=? AND status='open'"); $openEnquiries->execute([$uid]); $openEnquiries=$openEnquiries->fetchColumn();
$thisMonthEnq   = $usage['enquiries_sent'] ?? 0;

$recentEnq = $pdo->prepare("SELECT e.*,u.name AS cname,p.name AS pname FROM enquiries e JOIN users u ON u.id=e.customer_id LEFT JOIN products p ON p.id=e.product_id WHERE e.vendor_id=? ORDER BY e.created_at DESC LIMIT 5");
$recentEnq->execute([$uid]); $recentEnquiries=$recentEnq->fetchAll();

$recentProds = $pdo->prepare("SELECT p.*,i.name AS iname,pt.name AS tname FROM products p JOIN industries i ON i.id=p.industry_id JOIN product_types pt ON pt.id=p.product_type_id WHERE p.vendor_id=? ORDER BY p.created_at DESC LIMIT 4");
$recentProds->execute([$uid]); $recentProducts=$recentProds->fetchAll();

$pageTitle='Dashboard'; $activePage='dashboard';
include __DIR__ . '/../includes/head.php';
?>
<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <div>
      <div class="topbar-left" style="gap:6px"><h1>Dashboard</h1></div>
      <div class="topbar-breadcrumb">Welcome back, <?= sanitize(explode(' ',$user['name'])[0]) ?>!</div>
    </div>
  </div>
  <div class="topbar-right">
    <a href="<?= BASE_URL ?>/vendor/notifications.php" class="notif-btn">
      🔔<?php if ($openEnquiries>0): ?><span class="notif-dot"></span><?php endif; ?>
    </a>
    <?php if ($prodCheck['allowed']): ?>
      <a href="<?= BASE_URL ?>/vendor/add-product.php" class="btn btn-primary btn-sm">＋ Add Product</a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/vendor/subscription.php" class="btn btn-warning btn-sm">🔒 Upgrade to Add</a>
    <?php endif; ?>
    <div class="topbar-avatar"><?= avatarLetter($user['name']) ?></div>
  </div>
</div>

<div class="content">
  <?= showFlash() ?>
  <?= subscriptionBanner($sub) ?>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card indigo">
      <div class="stat-header">
        <div class="stat-icon indigo">📦</div>
        <span class="stat-trend flat">Total</span>
      </div>
      <div class="stat-value"><?= $totalProducts ?></div>
      <div class="stat-label">Products Listed</div>
      <?php
        $lim = $sub['product_limit'];
        $pct = $lim > 0 ? min(100, round($totalProducts/$lim*100)) : 0;
        $cls = $pct>=90?'danger':($pct>=70?'warn':'safe');
      ?>
      <?php if ($lim > 0): ?>
      <div class="usage-bar-wrap">
        <div class="usage-bar-top"><span><?= $totalProducts ?>/<?= $lim ?> used</span><span><?= $pct ?>%</span></div>
        <div class="usage-bar"><div class="usage-bar-fill <?= $cls ?>" style="width:<?= $pct ?>%"></div></div>
      </div>
      <?php else: ?><div class="stat-sub" style="color:var(--success)">✓ Unlimited</div><?php endif; ?>
    </div>

    <div class="stat-card green">
      <div class="stat-header">
        <div class="stat-icon green">✅</div>
        <span class="stat-trend up">Live</span>
      </div>
      <div class="stat-value"><?= $activeProducts ?></div>
      <div class="stat-label">Active Products</div>
      <div class="stat-sub"><?= $totalProducts-$activeProducts ?> pending/inactive</div>
    </div>

    <div class="stat-card amber">
      <div class="stat-header">
        <div class="stat-icon amber">📩</div>
        <span class="stat-trend <?= $openEnquiries>0?'up':'flat' ?>"><?= $openEnquiries ?> open</span>
      </div>
      <div class="stat-value"><?= $totalEnquiries ?></div>
      <div class="stat-label">Total Enquiries</div>
      <?php
        $elim = $sub['enquiry_limit'];
        $epct = $elim > 0 ? min(100,round($thisMonthEnq/$elim*100)) : 0;
        $ecls = $epct>=90?'danger':($epct>=70?'warn':'safe');
      ?>
      <?php if ($elim > 0): ?>
      <div class="usage-bar-wrap">
        <div class="usage-bar-top"><span>This month: <?= $thisMonthEnq ?>/<?= $elim ?></span><span><?= $epct ?>%</span></div>
        <div class="usage-bar"><div class="usage-bar-fill <?= $ecls ?>" style="width:<?= $epct ?>%"></div></div>
      </div>
      <?php else: ?><div class="stat-sub" style="color:var(--success)">✓ Unlimited this month</div><?php endif; ?>
    </div>

    <div class="stat-card purple">
      <div class="stat-header">
        <div class="stat-icon purple">💳</div>
        <span class="stat-trend flat"><?= ucfirst($sub['status']??'trial') ?></span>
      </div>
      <div class="stat-value" style="font-size:20px;margin-top:4px"><?= sanitize($sub['plan_name']??'Free') ?></div>
      <div class="stat-label">Current Plan</div>
      <?php if ($sub && $sub['expires_at']): ?>
        <div class="stat-sub">Expires <?= date('d M Y',strtotime($sub['expires_at'])) ?></div>
      <?php endif; ?>
      <div style="margin-top:10px"><a href="<?= BASE_URL ?>/vendor/subscription.php" class="btn btn-purple btn-xs">Manage Plan</a></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
    <!-- Recent Enquiries -->
    <div class="card">
      <div class="card-header">
        <h2>📩 Recent Enquiries</h2>
        <a href="<?= BASE_URL ?>/vendor/enquiries.php" class="btn btn-outline btn-sm">View All</a>
      </div>
      <?php if ($recentEnquiries): ?>
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Customer</th><th>Product</th><th>Status</th><th>When</th></tr></thead>
          <tbody>
          <?php foreach ($recentEnquiries as $e): ?>
            <tr>
              <td><span style="font-weight:600"><?= sanitize($e['cname']) ?></span></td>
              <td class="text-muted" style="font-size:12.5px"><?= sanitize($e['pname']??'General') ?></td>
              <td><?= statusBadge($e['status']) ?></td>
              <td class="text-muted"><?= timeAgo($e['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="empty-state"><div class="empty-state-icon">📭</div><h3>No enquiries yet</h3><p>They'll appear here once customers enquire.</p></div><?php endif; ?>
    </div>

    <!-- Recent Products -->
    <div class="card">
      <div class="card-header">
        <h2>📦 Recent Products</h2>
        <a href="<?= BASE_URL ?>/vendor/manage-products.php" class="btn btn-outline btn-sm">View All</a>
      </div>
      <?php if ($recentProducts): ?>
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Product</th><th>Type</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recentProducts as $p): ?>
            <tr>
              <td><span style="font-weight:600"><?= sanitize($p['name']) ?></span></td>
              <td class="text-muted" style="font-size:12.5px"><?= sanitize($p['tname']) ?></td>
              <td><?= statusBadge($p['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="empty-state"><div class="empty-state-icon">📭</div><h3>No products yet</h3><p><a href="<?= BASE_URL ?>/vendor/add-product.php">Add your first product</a></p></div><?php endif; ?>
    </div>
  </div>

  <!-- Quick actions -->
  <div class="card">
    <div class="card-header"><h2>⚡ Quick Actions</h2></div>
    <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap">
      <?php if ($prodCheck['allowed']): ?>
        <a href="<?= BASE_URL ?>/vendor/add-product.php" class="btn btn-primary">➕ Add Product</a>
      <?php else: ?>
        <button class="btn btn-outline" disabled>🔒 Product limit reached</button>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>/vendor/enquiries.php" class="btn btn-outline">📩 View Enquiries</a>
      <a href="<?= BASE_URL ?>/vendor/analytics.php" class="btn btn-outline">📊 Analytics</a>
      <a href="<?= BASE_URL ?>/vendor/subscription.php" class="btn btn-purple">💳 <?= in_array($sub['slug']??'free',['free','starter']) ? 'Upgrade Plan' : 'Manage Plan' ?></a>
    </div>
  </div>
</div>

<script>
document.getElementById('hamburger').addEventListener('click',()=>{document.getElementById('sidebar').classList.add('open');document.getElementById('sidebar-overlay').classList.add('show');});
document.getElementById('sidebar-overlay').addEventListener('click',()=>{document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.remove('show');});
</script>
</div></div></body></html>
