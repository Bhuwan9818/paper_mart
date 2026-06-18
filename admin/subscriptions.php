<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

// Handle manual plan change
if (isset($_POST['change_plan'])) {
    $vid = (int)$_POST['vendor_id'];
    $pid = (int)$_POST['plan_id'];
    $status = in_array($_POST['sub_status'],['active','trial','expired','cancelled']) ? $_POST['sub_status'] : 'active';
    $expires = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
    try {
        $existing = $pdo->prepare("SELECT id FROM vendor_subscriptions WHERE vendor_id=? ORDER BY created_at DESC LIMIT 1");
        $existing->execute([$vid]); $row = $existing->fetch();
        if ($row) {
            $pdo->prepare("UPDATE vendor_subscriptions SET plan_id=?,status=?,expires_at=?,updated_at=NOW() WHERE id=?")->execute([$pid,$status,$expires,$row['id']]);
        } else {
            $pdo->prepare("INSERT INTO vendor_subscriptions (vendor_id,plan_id,status,started_at,expires_at) VALUES(?,?,?,NOW(),?)")->execute([$vid,$pid,$status,$expires]);
        }
        flash('success','Subscription updated.');
    } catch(Exception $e) { flash('error','Could not update subscription.'); }
    header('Location: subscriptions.php'); exit;
}

$search  = trim($_GET['search'] ?? '');
$status  = $_GET['status'] ?? '';
$plan    = $_GET['plan'] ?? '';
$page    = max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;

try {
    // Summary
    $subStats = [
        'active'   => $pdo->query("SELECT COUNT(*) FROM vendor_subscriptions WHERE status='active'")->fetchColumn(),
        'trial'    => $pdo->query("SELECT COUNT(*) FROM vendor_subscriptions WHERE status='trial'")->fetchColumn(),
        'expired'  => $pdo->query("SELECT COUNT(*) FROM vendor_subscriptions WHERE status='expired'")->fetchColumn(),
        'cancelled'=> $pdo->query("SELECT COUNT(*) FROM vendor_subscriptions WHERE status='cancelled'")->fetchColumn(),
    ];

    $plans = $pdo->query("SELECT * FROM subscription_plans WHERE is_active=1 ORDER BY sort_order")->fetchAll();

    $where = "WHERE u.role='vendor'"; $params=[];
    if ($search) { $where.=" AND (u.name LIKE ? OR u.email LIKE ? OR u.company LIKE ?)"; $params=array_merge($params,["%$search%","%$search%","%$search%"]); }
    if ($status)  { $where.=" AND vs.status=?";  $params[]=$status; }
    if ($plan)    { $where.=" AND pl.slug=?";     $params[]=$plan; }

    $totalRow = $pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN vendor_subscriptions vs ON vs.vendor_id=u.id AND vs.id=(SELECT MAX(id) FROM vendor_subscriptions WHERE vendor_id=u.id) LEFT JOIN subscription_plans pl ON pl.id=vs.plan_id $where");
    $totalRow->execute($params); $total=$totalRow->fetchColumn();

    $params[] = $perPage; $params[] = $offset;
    $vendors = $pdo->prepare("SELECT u.id AS uid, u.name, u.email, u.company, u.status AS user_status,
        vs.id AS sub_id, vs.status AS sub_status, vs.billing_cycle, vs.started_at, vs.expires_at, vs.trial_ends_at,
        pl.id AS plan_id, pl.name AS plan_name, pl.slug, pl.price_monthly, pl.price_yearly, pl.color AS plan_color,
        (SELECT COUNT(*) FROM products WHERE vendor_id=u.id) AS product_count,
        (SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE vendor_id=u.id AND status='paid') AS total_paid
        FROM users u
        LEFT JOIN vendor_subscriptions vs ON vs.vendor_id=u.id AND vs.id=(SELECT MAX(id) FROM vendor_subscriptions WHERE vendor_id=u.id)
        LEFT JOIN subscription_plans pl ON pl.id=vs.plan_id
        $where ORDER BY u.created_at DESC LIMIT ? OFFSET ?");
    $vendors->execute($params); $vendors=$vendors->fetchAll();
} catch(Exception $e) {
    $vendors=[]; $total=0; $subStats=['active'=>0,'trial'=>0,'expired'=>0,'cancelled'=>0]; $plans=[];
}

$pageTitle='Subscriptions'; $activePage='subscriptions';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
.plan-badge { display:inline-block; padding:3px 10px; border-radius:100px; font-size:11.5px; font-weight:700; }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:var(--radius-lg); padding:28px; width:100%; max-width:460px; box-shadow:var(--shadow-lg); }
.modal-title { font-size:16px; font-weight:700; margin-bottom:18px; }
</style>
<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Subscriptions</h1>
  </div>
  <div class="topbar-right">
    <a href="reports.php?type=subscriptions" class="btn btn-outline btn-sm">📥 Export CSV</a>
  </div>
</div>
<div class="content">
  <?= showFlash() ?>
  <div class="page-header"><h1>💳 Vendor Subscriptions</h1></div>

  <!-- Status summary -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px">
    <?php $sc=[['active','Active','green'],['trial','On Trial','amber'],['expired','Expired','red'],['cancelled','Cancelled','purple']]; ?>
    <?php foreach($sc as [$s,$lbl,$c]): ?>
    <div class="stat-card" style="cursor:pointer" onclick="location.href='?status=<?=$s?>'">
      <div class="stat-icon <?=$c?>">💳</div>
      <div class="stat-info"><div class="value"><?= $subStats[$s] ?></div><div class="label"><?=$lbl?></div></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-header">
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;width:100%">
        <input type="text" name="search" value="<?=sanitize($search)?>" placeholder="Search vendor…" class="form-control" style="flex:1;min-width:140px">
        <select name="status" class="form-control" style="width:130px">
          <option value="">All Status</option>
          <?php foreach(['active','trial','expired','cancelled'] as $s): ?>
          <option value="<?=$s?>" <?=$status===$s?'selected':''?>><?=ucfirst($s)?></option>
          <?php endforeach; ?>
        </select>
        <select name="plan" class="form-control" style="width:140px">
          <option value="">All Plans</option>
          <?php foreach($plans as $pl): ?>
          <option value="<?=sanitize($pl['slug'])?>" <?=$plan===$pl['slug']?'selected':''?>><?=sanitize($pl['name'])?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if($search||$status||$plan): ?><a href="?" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
      </form>
    </div>

    <?php if($vendors): ?>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>#</th><th>Vendor</th><th>Plan</th><th>Status</th><th>Billing</th><th>Expires</th><th>Products</th><th>Total Paid</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($vendors as $i=>$v): ?>
        <?php $planColor = $v['plan_color'] ?? '#64748b'; ?>
        <tr>
          <td class="text-muted"><?=$offset+$i+1?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:30px;height:30px;border-radius:50%;background:var(--primary);color:#fff;font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><?=avatarLetter($v['name'])?></div>
              <div>
                <div style="font-weight:500"><?=sanitize($v['name'])?></div>
                <div style="font-size:11.5px;color:var(--text-muted)"><?=sanitize($v['email'])?></div>
              </div>
            </div>
          </td>
          <td>
            <?php if($v['plan_name']): ?>
            <span class="plan-badge" style="background:<?=$planColor?>22;color:<?=$planColor?>"><?=sanitize($v['plan_name'])?></span>
            <?php else: ?>
            <span class="text-muted" style="font-size:12px">No plan</span>
            <?php endif; ?>
          </td>
          <td><?= $v['sub_status'] ? statusBadge($v['sub_status']) : '<span class="badge badge-secondary">None</span>' ?></td>
          <td class="text-muted"><?=ucfirst($v['billing_cycle']??'—')?></td>
          <td class="text-muted" style="font-size:12px">
            <?php
            $exp = $v['expires_at'] ?: $v['trial_ends_at'];
            if($exp) {
                $days = (int)((strtotime($exp)-time())/86400);
                $color = $days < 0 ? 'var(--danger)' : ($days < 7 ? 'var(--warning)' : 'var(--text-muted)');
                echo "<span style='color:$color'>".date('d M Y',strtotime($exp))."</span>";
                if($days < 0) echo "<br><small style='color:var(--danger)'>Expired</small>";
                elseif($days < 30) echo "<br><small style='color:$color'>{$days}d left</small>";
            } else echo '—';
            ?>
          </td>
          <td style="font-weight:600;color:var(--primary)"><?=$v['product_count']?></td>
          <td style="font-weight:600;color:var(--success)">₹<?=number_format($v['total_paid'],0)?></td>
          <td>
            <button type="button" class="btn btn-primary btn-xs" onclick="openModal(<?=htmlspecialchars(json_encode($v),ENT_QUOTES)?>)">✏️ Edit</button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginate($total,$perPage,$page,'?search='.urlencode($search).'&status='.$status.'&plan='.$plan) ?>
    <?php else: ?>
    <div class="empty-state"><div class="es-icon">💳</div><p>No vendors found.</p></div>
    <?php endif; ?>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <div class="modal-title">✏️ Edit Subscription</div>
    <form method="POST">
      <input type="hidden" name="change_plan" value="1">
      <input type="hidden" name="vendor_id" id="modal_vendor_id">
      <div style="margin-bottom:14px">
        <div style="font-weight:600;margin-bottom:4px" id="modal_vendor_name"></div>
        <div style="font-size:12px;color:var(--text-muted)" id="modal_vendor_email"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Plan</label>
        <select name="plan_id" id="modal_plan_id" class="form-control">
          <?php foreach($plans as $pl): ?>
          <option value="<?=$pl['id']?>"><?=sanitize($pl['name'])?> — ₹<?=number_format($pl['price_monthly'])?>/mo</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="sub_status" id="modal_sub_status" class="form-control">
          <?php foreach(['active','trial','expired','cancelled'] as $s): ?>
          <option value="<?=$s?>"><?=ucfirst($s)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Expires At</label>
        <input type="datetime-local" name="expires_at" id="modal_expires_at" class="form-control">
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
        <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
<script>
function openModal(v) {
  document.getElementById('modal_vendor_id').value   = v.uid;
  document.getElementById('modal_vendor_name').textContent  = v.name;
  document.getElementById('modal_vendor_email').textContent = v.email;
  document.getElementById('modal_plan_id').value     = v.plan_id || '';
  document.getElementById('modal_sub_status').value  = v.sub_status || 'trial';
  const exp = v.expires_at || v.trial_ends_at || '';
  document.getElementById('modal_expires_at').value  = exp ? exp.replace(' ','T').slice(0,16) : '';
  document.getElementById('editModal').classList.add('open');
}
function closeModal() {
  document.getElementById('editModal').classList.remove('open');
}
document.getElementById('editModal').addEventListener('click', function(e){ if(e.target===this) closeModal(); });
</script>
</div></div></body></html>
