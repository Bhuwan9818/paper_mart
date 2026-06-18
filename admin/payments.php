<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

// Handle status update
if (isset($_POST['update_status'])) {
    $pid = (int)$_POST['payment_id'];
    $ns  = in_array($_POST['new_status'],['paid','pending','failed','refunded']) ? $_POST['new_status'] : 'pending';
    $pd  = $ns === 'paid' ? ', paid_at=NOW()' : '';
    try {
        $pdo->prepare("UPDATE subscription_payments SET status=? $pd WHERE id=?")->execute([$ns, $pid]);
        flash('success', 'Payment status updated.');
    } catch(Exception $e) { flash('error','Could not update.'); }
    header('Location: payments.php'); exit;
}

$search  = trim($_GET['search'] ?? '');
$status  = $_GET['status'] ?? '';
$plan    = $_GET['plan'] ?? '';
$month   = $_GET['month'] ?? '';
$page    = max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;

$where = "WHERE 1=1"; $params = [];
if ($search) { $where.=" AND (u.name LIKE ? OR u.email LIKE ? OR u.company LIKE ?)"; $params=array_merge($params,["%$search%","%$search%","%$search%"]); }
if ($status)  { $where.=" AND sp2.status=?";         $params[]=$status; }
if ($plan)    { $where.=" AND pl.slug=?";             $params[]=$plan; }
if ($month)   { $where.=" AND DATE_FORMAT(sp2.created_at,'%Y-%m')=?"; $params[]=$month; }

try {
    $totalRow = $pdo->prepare("SELECT COUNT(*) FROM subscription_payments sp2 JOIN users u ON u.id=sp2.vendor_id JOIN subscription_plans pl ON pl.id=sp2.plan_id $where");
    $totalRow->execute($params); $total = $totalRow->fetchColumn();

    $params[] = $perPage; $params[] = $offset;
    $stmt = $pdo->prepare("SELECT sp2.*, u.name AS vendor_name, u.email AS vendor_email, u.company, pl.name AS plan_name, pl.slug, pl.color AS plan_color
        FROM subscription_payments sp2
        JOIN users u  ON u.id=sp2.vendor_id
        JOIN subscription_plans pl ON pl.id=sp2.plan_id
        $where ORDER BY sp2.created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute($params); $payments = $stmt->fetchAll();

    // Summary stats
    $totalRevenue  = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status='paid'")->fetchColumn();
    $monthRevenue  = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status='paid' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn();
    $pendingAmt    = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status='pending'")->fetchColumn();
    $paidCount     = $pdo->query("SELECT COUNT(*) FROM subscription_payments WHERE status='paid'")->fetchColumn();

    // Plans for filter
    $plans = $pdo->query("SELECT slug, name FROM subscription_plans ORDER BY sort_order")->fetchAll();

    // Months for filter
    $months = $pdo->query("SELECT DISTINCT DATE_FORMAT(created_at,'%Y-%m') AS m FROM subscription_payments ORDER BY m DESC LIMIT 12")->fetchAll(PDO::FETCH_COLUMN);
} catch(Exception $e) {
    $payments=[]; $total=0; $totalRevenue=$monthRevenue=$pendingAmt=$paidCount=0; $plans=[]; $months=[];
}

$pageTitle='Payments'; $activePage='payments';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Payments</h1>
  </div>
  <div class="topbar-right">
    <a href="reports.php?type=payments" class="btn btn-outline btn-sm">📥 Export CSV</a>
  </div>
</div>
<div class="content">
  <?= showFlash() ?>
  <div class="page-header"><h1>💰 Payment Management</h1></div>

  <!-- Summary -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-bottom:22px">
    <div class="stat-card"><div class="stat-icon green">💰</div><div class="stat-info"><div class="value">₹<?= number_format($totalRevenue) ?></div><div class="label">Total Revenue</div></div></div>
    <div class="stat-card"><div class="stat-icon blue">📅</div><div class="stat-info"><div class="value">₹<?= number_format($monthRevenue) ?></div><div class="label">This Month</div></div></div>
    <div class="stat-card"><div class="stat-icon amber">⏳</div><div class="stat-info"><div class="value">₹<?= number_format($pendingAmt) ?></div><div class="label">Pending Amount</div></div></div>
    <div class="stat-card"><div class="stat-icon purple">✅</div><div class="stat-info"><div class="value"><?= $paidCount ?></div><div class="label">Paid Transactions</div></div></div>
  </div>

  <div class="card">
    <div class="card-header">
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;width:100%">
        <input type="text" name="search" value="<?= sanitize($search) ?>" placeholder="Search vendor…" class="form-control" style="flex:1;min-width:140px">
        <select name="status" class="form-control" style="width:130px">
          <option value="">All Status</option>
          <?php foreach(['paid','pending','failed','refunded'] as $s): ?>
          <option value="<?=$s?>" <?=$status===$s?'selected':''?>><?=ucfirst($s)?></option>
          <?php endforeach; ?>
        </select>
        <select name="plan" class="form-control" style="width:140px">
          <option value="">All Plans</option>
          <?php foreach($plans as $pl): ?>
          <option value="<?=sanitize($pl['slug'])?>" <?=$plan===$pl['slug']?'selected':''?>><?=sanitize($pl['name'])?></option>
          <?php endforeach; ?>
        </select>
        <select name="month" class="form-control" style="width:140px">
          <option value="">All Months</option>
          <?php foreach($months as $mo): ?>
          <option value="<?=$mo?>" <?=$month===$mo?'selected':''?>><?=date('M Y',strtotime($mo.'-01'))?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if($search||$status||$plan||$month): ?><a href="?" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
      </form>
    </div>

    <?php if($payments): ?>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>#</th><th>Vendor</th><th>Plan</th><th>Amount</th><th>Cycle</th><th>Method</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($payments as $i=>$p): ?>
        <tr>
          <td class="text-muted"><?= $offset+$i+1 ?></td>
          <td>
            <div style="font-weight:500"><?= sanitize($p['vendor_name']) ?></div>
            <div style="font-size:12px;color:var(--text-muted)"><?= sanitize($p['vendor_email']) ?></div>
            <?php if($p['company']): ?><div style="font-size:11px;color:var(--text-muted)"><?= sanitize($p['company']) ?></div><?php endif; ?>
          </td>
          <td><span style="background:<?=sanitize($p['plan_color'])?>22;color:<?=sanitize($p['plan_color'])?>;padding:3px 10px;border-radius:100px;font-size:12px;font-weight:600"><?= sanitize($p['plan_name']) ?></span></td>
          <td style="font-weight:700;color:var(--success)">₹<?= number_format($p['amount'],2) ?></td>
          <td class="text-muted"><?= ucfirst($p['billing_cycle']) ?></td>
          <td class="text-muted"><?= ucfirst($p['payment_method']) ?></td>
          <td><?= statusBadge($p['status']) ?></td>
          <td class="text-muted" style="white-space:nowrap"><?= date('d M Y', strtotime($p['created_at'])) ?><?php if($p['paid_at']): ?><br><small><?= date('H:i', strtotime($p['paid_at'])) ?></small><?php endif; ?></td>
          <td>
            <form method="POST" style="display:inline-flex;gap:4px;align-items:center">
              <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
              <select name="new_status" class="form-control" style="padding:4px 6px;font-size:12px;width:100px">
                <?php foreach(['paid','pending','failed','refunded'] as $s): ?>
                <option value="<?=$s?>" <?=$p['status']===$s?'selected':''?>><?=ucfirst($s)?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" name="update_status" class="btn btn-outline btn-xs">✓</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginate($total,$perPage,$page,'?search='.urlencode($search).'&status='.$status.'&plan='.$plan.'&month='.$month) ?>
    <?php else: ?>
    <div class="empty-state"><div class="es-icon">💰</div><p>No payment records found.</p></div>
    <?php endif; ?>
  </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
