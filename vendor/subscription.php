<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';
requireRoleStrict('vendor');
$user = currentUser(); $uid = $user['id'];
$sub  = getVendorSubscription($pdo, $uid);
$usage = getVendorUsage($pdo, $uid);

// Handle plan selection (demo - in production integrate Razorpay/Stripe)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['plan_id'])) {
    verifyCsrf();
    $planId = (int)$_POST['plan_id'];
    $cycle  = $_POST['billing_cycle'] ?? 'monthly';
    $plan   = $pdo->prepare("SELECT * FROM subscription_plans WHERE id=? AND is_active=1"); $plan->execute([$planId]); $plan=$plan->fetch();
    if ($plan) {
        $price = $cycle==='yearly' ? $plan['price_yearly'] : $plan['price_monthly'];
        // Cancel existing
        $pdo->prepare("UPDATE vendor_subscriptions SET status='cancelled' WHERE vendor_id=? AND status IN('active','trial')")->execute([$uid]);
        // Create new
        $expires = $cycle==='yearly' ? date('Y-m-d H:i:s',strtotime('+1 year')) : date('Y-m-d H:i:s',strtotime('+1 month'));
        $pdo->prepare("INSERT INTO vendor_subscriptions (vendor_id,plan_id,billing_cycle,status,started_at,expires_at) VALUES(?,?,?,'active',NOW(),?)")
            ->execute([$uid,$planId,$cycle,$expires]);
        // Log payment (demo)
        if ($price > 0) {
            $pdo->prepare("INSERT INTO subscription_payments (vendor_id,plan_id,amount,billing_cycle,status,paid_at) VALUES(?,?,?,?,'paid',NOW())")->execute([$uid,$planId,$price,$cycle]);
        }
        flash('success','Plan activated: '.$plan['name'].' ('.$cycle.')');
        header('Location: subscription.php'); exit;
    }
}

$plans = $pdo->query("SELECT * FROM subscription_plans WHERE is_active=1 ORDER BY sort_order")->fetchAll();
$payments = $pdo->prepare("SELECT sp.*,p.name AS plan_name FROM subscription_payments sp JOIN subscription_plans p ON p.id=sp.plan_id WHERE sp.vendor_id=? ORDER BY sp.created_at DESC LIMIT 10");
$payments->execute([$uid]); $payments=$payments->fetchAll();

$prodCheck = checkProductLimit($pdo,$uid,$sub);
$enqCheck  = checkEnquiryLimit($pdo,$uid,$sub);
$pageTitle='Subscription'; $activePage='subscription';
include __DIR__ . '/../includes/head.php';
?>
<div class="topbar">
  <div class="topbar-left"><button class="hamburger" id="hamburger"><span></span><span></span><span></span></button><h1>Subscription & Plans</h1></div>
  <div class="topbar-right"><div class="topbar-avatar"><?= avatarLetter($user['name']) ?></div></div>
</div>
<div class="content">
  <?= showFlash() ?>
  <?= subscriptionBanner($sub) ?>

  <!-- Current plan summary -->
  <div class="card" style="background:linear-gradient(135deg,#0f0f1a,#1a1040);color:#fff;border:none">
    <div class="card-body" style="padding:28px">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
        <div>
          <div style="font-size:12px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Current Plan</div>
          <div style="font-size:28px;font-weight:800;letter-spacing:-0.5px"><?= sanitize($sub['plan_name']??'Free') ?></div>
          <div style="color:#94a3b8;font-size:13.5px;margin-top:4px">
            Status: <span style="color:<?= $sub['status']==='active'?'#34d399':($sub['status']==='trial'?'#fbbf24':'#f87171') ?>;font-weight:600"><?= ucfirst($sub['status']??'trial') ?></span>
            <?php if ($sub && $sub['expires_at']): ?>· Expires <?= date('d M Y',strtotime($sub['expires_at'])) ?><?php endif; ?>
          </div>
        </div>
        <div style="display:flex;gap:24px;flex-wrap:wrap">
          <div style="text-align:center">
            <div style="font-size:28px;font-weight:800"><?= $prodCheck['total']??0 ?>/<?= $sub['product_limit']==-1?'∞':$sub['product_limit'] ?></div>
            <div style="font-size:12px;color:#94a3b8">Products</div>
          </div>
          <div style="text-align:center">
            <div style="font-size:28px;font-weight:800"><?= $usage['enquiries_sent']??0 ?>/<?= $sub['enquiry_limit']==-1?'∞':$sub['enquiry_limit'] ?></div>
            <div style="font-size:12px;color:#94a3b8">Enquiries/mo</div>
          </div>
          <div style="text-align:center">
            <div style="font-size:28px;font-weight:800"><?= $sub['image_limit'] ?></div>
            <div style="font-size:12px;color:#94a3b8">Images/product</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Billing toggle -->
  <div style="text-align:center;margin:28px 0 20px">
    <h2 style="font-size:22px;font-weight:800;margin-bottom:8px">Choose Your Plan</h2>
    <p style="color:var(--text-muted);margin-bottom:16px">Scale as your business grows</p>
    <div style="display:inline-flex;background:var(--bg);border:1px solid var(--border);border-radius:100px;padding:4px;gap:0">
      <button id="btn-monthly" class="btn btn-sm btn-primary" style="border-radius:100px" onclick="toggleBilling('monthly')">Monthly</button>
      <button id="btn-yearly"  class="btn btn-sm btn-ghost"   style="border-radius:100px" onclick="toggleBilling('yearly')">Yearly <span style="color:var(--success);font-size:10.5px;font-weight:700">SAVE 17%</span></button>
    </div>
  </div>

  <!-- Plans grid -->
  <div class="plans-grid" style="margin-bottom:28px">
  <?php foreach ($plans as $plan):
    $isCurrent = ($sub && $sub['plan_id']==$plan['id'] && in_array($sub['status'],['active','trial']));
    $features = json_decode($plan['features']??'[]',true)?: [];
    $color = $plan['color'] ?: '#6366f1';
  ?>
    <div class="plan-card <?= $isCurrent?'current':'' ?> <?= $plan['badge']?'featured':'' ?>" style="<?= $isCurrent?"border-color:{$color}":'' ?>">
      <?php if ($plan['badge']): ?><div class="plan-badge" style="background:linear-gradient(135deg,<?= $color ?>,<?= $color ?>cc)"><?= sanitize($plan['badge']) ?></div><?php endif; ?>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
        <div style="width:32px;height:32px;border-radius:8px;background:<?= $color ?>22;display:flex;align-items:center;justify-content:center;font-size:15px">
          <?= $plan['slug']==='free'?'🆓':($plan['slug']==='starter'?'🚀':($plan['slug']==='professional'?'💎':'🏆')) ?>
        </div>
        <div class="plan-name"><?= sanitize($plan['name']) ?></div>
        <?php if ($isCurrent): ?><span class="badge badge-success" style="margin-left:auto">Current</span><?php endif; ?>
      </div>
      <div class="plan-price">
        <div>
          <span class="plan-price-amount" id="price_m_<?= $plan['id'] ?>">
            <?= $plan['price_monthly']==0 ? 'Free' : '₹'.number_format($plan['price_monthly']) ?>
          </span>
          <span class="plan-price-amount" id="price_y_<?= $plan['id'] ?>" style="display:none">
            <?= $plan['price_yearly']==0 ? 'Free' : '₹'.number_format($plan['price_yearly']) ?>
          </span>
          <span class="plan-price-period" id="per_m_<?= $plan['id'] ?>"><?= $plan['price_monthly']>0?'/month':'' ?></span>
          <span class="plan-price-period" id="per_y_<?= $plan['id'] ?>" style="display:none"><?= $plan['price_yearly']>0?'/year':'' ?></span>
        </div>
        <?php if ($plan['price_yearly']>0): ?>
          <div style="font-size:11.5px;color:var(--success);margin-top:3px" id="save_<?= $plan['id'] ?>" style="display:none">
            Save ₹<?= number_format($plan['price_monthly']*12 - $plan['price_yearly']) ?>/year
          </div>
        <?php endif; ?>
      </div>
      <ul class="plan-features">
        <?php foreach ($features as $f): ?>
          <li><span class="check">✓</span><?= sanitize($f) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$isCurrent): ?>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
        <input type="hidden" name="billing_cycle" class="billing-input" value="monthly">
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;background:linear-gradient(135deg,<?= $color ?>,<?= $color ?>cc)">
          <?= $plan['price_monthly']==0 ? 'Downgrade to Free' : 'Choose '.$plan['name'] ?>
        </button>
      </form>
      <?php else: ?>
        <button class="btn btn-outline" style="width:100%;justify-content:center;cursor:default" disabled>✓ Active Plan</button>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>

  <!-- Payment History -->
  <?php if ($payments): ?>
  <div class="card">
    <div class="card-header"><h2>🧾 Payment History</h2></div>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Date</th><th>Plan</th><th>Amount</th><th>Cycle</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td><?= date('d M Y',strtotime($p['created_at'])) ?></td>
            <td><strong><?= sanitize($p['plan_name']) ?></strong></td>
            <td>₹<?= number_format($p['amount'],2) ?></td>
            <td><?= ucfirst($p['billing_cycle']??'monthly') ?></td>
            <td><?= statusBadge($p['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<script>
let billing = 'monthly';
function toggleBilling(b) {
  billing = b;
  document.getElementById('btn-monthly').className = b==='monthly'?'btn btn-sm btn-primary':'btn btn-sm btn-ghost';
  document.getElementById('btn-monthly').style.borderRadius='100px';
  document.getElementById('btn-yearly').className  = b==='yearly' ?'btn btn-sm btn-primary':'btn btn-sm btn-ghost';
  document.getElementById('btn-yearly').style.borderRadius='100px';
  document.querySelectorAll('.billing-input').forEach(i=>i.value=b);
  document.querySelectorAll('[id^="price_m_"]').forEach(el=>el.style.display=b==='monthly'?'':'none');
  document.querySelectorAll('[id^="price_y_"]').forEach(el=>el.style.display=b==='yearly' ?'':'none');
  document.querySelectorAll('[id^="per_m_"]').forEach(el=>el.style.display=b==='monthly'?'':'none');
  document.querySelectorAll('[id^="per_y_"]').forEach(el=>el.style.display=b==='yearly' ?'':'none');
  document.querySelectorAll('[id^="save_"]').forEach(el=>el.style.display=b==='yearly'?'':'none');
}
document.getElementById('hamburger').addEventListener('click',()=>{document.getElementById('sidebar').classList.add('open');document.getElementById('sidebar-overlay').classList.add('show');});
document.getElementById('sidebar-overlay').addEventListener('click',()=>{document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.remove('show');});
</script>
</div></div></body></html>
