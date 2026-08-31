<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/customer_subscription.php';
requireRoleStrict('customer');

$user = currentUser();
$cid  = $user['id'];
$sub  = getCustomerSubscription($pdo, $cid);
$usage = getCustomerUsage($pdo, $cid);

// Free-plan switch happens instantly (no payment needed). Paid plans go
// through Razorpay Checkout via the "Pay & Activate" button below.
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['plan_id'])) {
    verifyCsrf();
    $planId = (int)$_POST['plan_id'];
    $cycle  = $_POST['billing_cycle'] ?? 'monthly';
    $plan   = $pdo->prepare("SELECT * FROM customer_plans WHERE id=? AND is_active=1"); $plan->execute([$planId]); $plan=$plan->fetch();
    if ($plan) {
        $price = $cycle==='yearly' ? $plan['price_yearly'] : $plan['price_monthly'];
        if ($price > 0) {
            flash('error', 'This plan requires payment — please use the "Pay & Activate" button.');
            header('Location: subscription.php'); exit;
        }
        $pdo->prepare("UPDATE customer_subscriptions SET status='cancelled' WHERE customer_id=? AND status IN('active','trial')")->execute([$cid]);
        $expires = $cycle==='yearly' ? date('Y-m-d H:i:s',strtotime('+1 year')) : date('Y-m-d H:i:s',strtotime('+1 month'));
        $pdo->prepare("INSERT INTO customer_subscriptions (customer_id,plan_id,billing_cycle,status,started_at,expires_at) VALUES(?,?,?,'active',NOW(),?)")
            ->execute([$cid,$planId,$cycle,$expires]);
        flash('success','Plan activated: '.$plan['name'].' ('.$cycle.')');
        header('Location: subscription.php'); exit;
    }
}

$plans = $pdo->query("SELECT * FROM customer_plans WHERE is_active=1 ORDER BY sort_order")->fetchAll();

$pageTitle = 'Subscription'; $activePage = 'subscription';
include __DIR__ . '/../includes/head.php';
?>
<?php renderTopbar('Subscription'); ?>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header"><h1>⭐ Your Plan</h1></div>

    <div class="card" style="margin-bottom:24px">
        <div class="card-body">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
                <div>
                    <div style="font-size:12.5px;color:var(--text-muted)">Current Plan</div>
                    <div style="font-size:22px;font-weight:800;color:var(--primary)"><?= sanitize($sub['plan_name'] ?? 'Free') ?></div>
                </div>
                <div style="display:flex;gap:24px;flex-wrap:wrap">
                    <div>
                        <div style="font-size:11.5px;color:var(--text-muted)">Compares This Month</div>
                        <div style="font-weight:700"><?= $usage['compares_used'] ?> <?= $sub['compare_limit']==-1?'':'/ '.$sub['compare_limit'] ?></div>
                    </div>
                    <div>
                        <div style="font-size:11.5px;color:var(--text-muted)">TDS Downloads</div>
                        <div style="font-weight:700"><?= $usage['tds_downloaded'] ?> <?= $sub['tds_limit']==-1?'':'/ '.$sub['tds_limit'] ?></div>
                    </div>
                    <div>
                        <div style="font-size:11.5px;color:var(--text-muted)">Enquiries Sent</div>
                        <div style="font-weight:700"><?= $usage['enquiries_sent'] ?> <?= $sub['enquiry_limit']==-1?'':'/ '.$sub['enquiry_limit'] ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px">
        <?php foreach ($plans as $plan):
            $isCurrent = ($sub['plan_id'] ?? null) == $plan['id'];
            $features = json_decode($plan['features'] ?? '[]', true) ?: [];
        ?>
        <div class="card" style="<?= $isCurrent ? 'border:2px solid '.$plan['color'] : '' ?>;position:relative">
            <?php if ($plan['badge']): ?><div style="position:absolute;top:-10px;right:16px;background:<?= sanitize($plan['color']) ?>;color:#fff;font-size:11px;font-weight:700;padding:4px 12px;border-radius:100px"><?= sanitize($plan['badge']) ?></div><?php endif; ?>
            <div class="card-body">
                <div style="font-size:18px;font-weight:800;color:<?= sanitize($plan['color']) ?>;margin-bottom:4px"><?= sanitize($plan['name']) ?></div>
                <div style="font-size:26px;font-weight:800;margin-bottom:14px">₹<?= number_format($plan['price_monthly']) ?><span style="font-size:13px;font-weight:400;color:var(--text-muted)">/month</span></div>
                <ul style="list-style:none;padding:0;margin:0 0 18px">
                    <?php foreach ($features as $f): ?>
                    <li style="padding:6px 0;font-size:13.5px;display:flex;gap:8px"><span style="color:#16a34a">✓</span><?= sanitize($f) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($isCurrent): ?>
                    <button class="btn btn-outline" style="width:100%;justify-content:center" disabled>✓ Current Plan</button>
                <?php elseif ((float)$plan['price_monthly'] <= 0): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
                        <input type="hidden" name="billing_cycle" value="monthly">
                        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Switch to Free</button>
                    </form>
                <?php else: ?>
                    <button type="button" class="btn btn-primary" style="width:100%;justify-content:center;background:<?= sanitize($plan['color']) ?>"
                            data-plan-id="<?= $plan['id'] ?>" onclick="payForCustomerPlan(this)">Upgrade to <?= sanitize($plan['name']) ?></button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
function payForCustomerPlan(btn) {
  const planId = btn.dataset.planId;
  const originalText = btn.textContent;
  btn.disabled = true; btn.textContent = 'Starting payment...';

  fetch('<?=BASE_URL?>/ajax/create-payment-order.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({csrf_token: '<?= csrfToken() ?>', purpose: 'customer_subscription', plan_id: planId, billing_cycle: 'monthly'})
  }).then(r=>r.json()).then(d=>{
    if (!d.ok) { alert(d.msg || 'Could not start payment.'); btn.disabled=false; btn.textContent=originalText; return; }

    const rzp = new Razorpay({
      key: d.key_id, amount: d.amount, currency: d.currency, order_id: d.order_id,
      name: d.name, description: d.description, prefill: d.prefill,
      theme: { color: '#8B241D' },
      handler: function(response) {
        btn.textContent = 'Verifying payment...';
        fetch('<?=BASE_URL?>/ajax/verify-payment.php', {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body: new URLSearchParams({
            csrf_token: '<?= csrfToken() ?>', txn_id: d.txn_id,
            razorpay_order_id: response.razorpay_order_id,
            razorpay_payment_id: response.razorpay_payment_id,
            razorpay_signature: response.razorpay_signature
          })
        }).then(r=>r.json()).then(v=>{
          if (v.ok) { window.location.href = 'subscription.php'; }
          else { alert(v.msg || 'Payment could not be verified. Please contact support if you were charged.'); btn.disabled=false; btn.textContent=originalText; }
        }).catch(()=>{ alert('Network error while verifying payment.'); btn.disabled=false; btn.textContent=originalText; });
      },
      modal: { ondismiss: function(){ btn.disabled=false; btn.textContent=originalText; } }
    });
    rzp.on('payment.failed', function(){ alert('Payment failed. Please try again.'); btn.disabled=false; btn.textContent=originalText; });
    rzp.open();
    btn.textContent = originalText; btn.disabled = false;
  }).catch(()=>{ alert('Network error. Please try again.'); btn.disabled=false; btn.textContent=originalText; });
}
document.getElementById('hamburger').addEventListener('click',()=>{document.getElementById('sidebar').classList.add('open');document.getElementById('sidebar-overlay').classList.add('show');});
document.getElementById('sidebar-overlay').addEventListener('click',()=>{document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.remove('show');});
</script>
</div></div></body></html>
