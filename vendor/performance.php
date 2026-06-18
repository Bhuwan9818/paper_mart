<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';
requireRoleStrict('vendor');
$user = currentUser(); $uid = $user['id'];
$sub  = getVendorSubscription($pdo, $uid);
$usage = getVendorUsage($pdo, $uid);
$prodCheck = checkProductLimit($pdo,$uid,$sub);
$enqCheck  = checkEnquiryLimit($pdo,$uid,$sub);

// Performance metrics
$totalProds    = (int)$pdo->prepare("SELECT COUNT(*) FROM products WHERE vendor_id=?")->execute([$uid]) ? $pdo->query("SELECT COUNT(*) FROM products WHERE vendor_id=$uid")->fetchColumn() : 0;
$activeProds   = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE vendor_id=$uid AND status='active'")->fetchColumn();
$pendingProds  = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE vendor_id=$uid AND status='pending'")->fetchColumn();
$totalEnq      = (int)$pdo->query("SELECT COUNT(*) FROM enquiries WHERE vendor_id=$uid")->fetchColumn();
$openEnq       = (int)$pdo->query("SELECT COUNT(*) FROM enquiries WHERE vendor_id=$uid AND status='open'")->fetchColumn();
$closedEnq     = (int)$pdo->query("SELECT COUNT(*) FROM enquiries WHERE vendor_id=$uid AND status='closed'")->fetchColumn();
$inProgEnq     = (int)$pdo->query("SELECT COUNT(*) FROM enquiries WHERE vendor_id=$uid AND status='in_progress'")->fetchColumn();
$responseRate  = $totalEnq > 0 ? round(($closedEnq+$inProgEnq)/$totalEnq*100) : 0;
$listingHealth = $totalProds > 0 ? round($activeProds/$totalProds*100) : 0;
$planUtilProd  = $sub['product_limit']>0 ? round($totalProds/$sub['product_limit']*100) : 0;
$planUtilEnq   = $sub['enquiry_limit']>0 ? round(($usage['enquiries_sent']??0)/$sub['enquiry_limit']*100) : 0;

// Score algorithm
$score = 0;
$score += min(30, $activeProds * 3);       // up to 30 pts for active products
$score += min(25, $responseRate * 0.25);   // up to 25 pts for response rate
$score += min(20, $totalEnq * 0.5);        // up to 20 pts for enquiries
$score += ($sub['slug']??'free')==='free' ? 0 : (($sub['slug']==='professional'||$sub['slug']==='enterprise') ? 25 : 10);
$score = min(100, round($score));

$scoreColor = $score>=80?'#10b981':($score>=50?'#f59e0b':'#ef4444');
$scoreLabel = $score>=80?'Excellent':($score>=60?'Good':($score>=40?'Average':'Needs Work'));

// Alerts / recommendations
$alerts = [];
if ($pendingProds > 0) $alerts[] = ['type'=>'warning','icon'=>'⏳','msg'=>"$pendingProds product(s) pending admin approval."];
if ($openEnq > 0)     $alerts[] = ['type'=>'info',   'icon'=>'📩','msg'=>"$openEnq open enquir".($openEnq===1?'y':'ies')." waiting for response.",'link'=>BASE_URL.'/vendor/enquiries.php'];
if ($listingHealth < 50 && $totalProds > 0) $alerts[] = ['type'=>'warning','icon'=>'📦','msg'=>"Listing health is low. Activate more products to improve visibility."];
if ($planUtilProd >= 90 && $sub['product_limit']>0) $alerts[] = ['type'=>'danger','icon'=>'🔒','msg'=>"Product limit almost reached ({$totalProds}/{$sub['product_limit']}). Upgrade your plan.",'link'=>BASE_URL.'/vendor/subscription.php'];
if ($planUtilEnq >= 90 && $sub['enquiry_limit']>0) $alerts[] = ['type'=>'danger','icon'=>'🔒','msg'=>"Monthly enquiry limit almost reached. Upgrade for unlimited enquiries.",'link'=>BASE_URL.'/vendor/subscription.php'];
if (!$sub['analytics']) $alerts[] = ['type'=>'info','icon'=>'📊','msg'=>"Unlock advanced analytics with a Professional or Enterprise plan.",'link'=>BASE_URL.'/vendor/subscription.php'];

$pageTitle='Performance Insights'; $activePage='performance';
include __DIR__ . '/../includes/head.php';
?>
<div class="topbar">
  <div class="topbar-left"><button class="hamburger" id="hamburger"><span></span><span></span><span></span></button><h1>Performance Insights</h1></div>
  <div class="topbar-right"><div class="topbar-avatar"><?= avatarLetter($user['name']) ?></div></div>
</div>
<div class="content">
  <?= subscriptionBanner($sub) ?>

  <!-- Score + alerts -->
  <div style="display:grid;grid-template-columns:260px 1fr;gap:20px;margin-bottom:20px;align-items:start">
    <!-- Score card -->
    <div class="card" style="background:linear-gradient(135deg,#0f0f1a,#1a1040);color:#fff;border:none">
      <div class="card-body" style="text-align:center;padding:32px 22px">
        <svg width="140" height="140" viewBox="0 0 140 140" style="margin:0 auto 16px;display:block">
          <circle cx="70" cy="70" r="58" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="12"/>
          <circle cx="70" cy="70" r="58" fill="none" stroke="<?= $scoreColor ?>" stroke-width="12"
            stroke-dasharray="<?= round(2*3.14159*58) ?>"
            stroke-dashoffset="<?= round(2*3.14159*58*(1-$score/100)) ?>"
            stroke-linecap="round"
            class="progress-ring-circle"
            transform="rotate(-90 70 70)"/>
          <text x="70" y="65" text-anchor="middle" fill="#fff" font-size="28" font-weight="800" font-family="Inter"><?= $score ?></text>
          <text x="70" y="84" text-anchor="middle" fill="#94a3b8" font-size="12" font-family="Inter">out of 100</text>
        </svg>
        <div style="font-size:20px;font-weight:800;color:<?= $scoreColor ?>"><?= $scoreLabel ?></div>
        <div style="font-size:12.5px;color:#94a3b8;margin-top:4px">Vendor Performance Score</div>
      </div>
    </div>

    <!-- Alerts -->
    <div>
      <?php if ($alerts): ?>
        <?php foreach ($alerts as $a): ?>
        <div class="alert alert-<?= $a['type'] === 'danger' ? 'error' : $a['type'] ?>" style="margin-bottom:10px">
          <span style="font-size:18px;flex-shrink:0"><?= $a['icon'] ?></span>
          <div style="flex:1"><?= sanitize($a['msg']) ?><?php if (!empty($a['link'])): ?> <a href="<?= $a['link'] ?>" style="font-weight:600;margin-left:6px">Fix this →</a><?php endif; ?></div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="alert alert-success"><span style="font-size:18px">🎉</span><div>Everything looks great! Keep up the excellent work.</div></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Metrics grid -->
  <div class="stats-grid">
    <div class="stat-card green">
      <div class="stat-header"><div class="stat-icon green">🎯</div><span class="stat-trend <?= $responseRate>=70?'up':'flat' ?>"><?= $responseRate ?>%</span></div>
      <div class="stat-value"><?= $responseRate ?>%</div>
      <div class="stat-label">Response Rate</div>
      <div class="usage-bar-wrap"><div class="usage-bar"><div class="usage-bar-fill <?= $responseRate>=70?'safe':($responseRate>=40?'warn':'danger') ?>" style="width:<?= $responseRate ?>%"></div></div></div>
    </div>
    <div class="stat-card indigo">
      <div class="stat-header"><div class="stat-icon indigo">❤️</div></div>
      <div class="stat-value"><?= $listingHealth ?>%</div>
      <div class="stat-label">Listing Health</div>
      <div class="stat-sub"><?= $activeProds ?> active / <?= $totalProds ?> total</div>
      <div class="usage-bar-wrap"><div class="usage-bar"><div class="usage-bar-fill <?= $listingHealth>=70?'safe':($listingHealth>=40?'warn':'danger') ?>" style="width:<?= $listingHealth ?>%"></div></div></div>
    </div>
    <div class="stat-card amber">
      <div class="stat-header"><div class="stat-icon amber">📦</div></div>
      <div class="stat-value"><?= $planUtilProd ?>%</div>
      <div class="stat-label">Plan Usage (Products)</div>
      <div class="stat-sub"><?= $totalProds ?> / <?= $sub['product_limit']==-1?'∞':$sub['product_limit'] ?> slots used</div>
      <div class="usage-bar-wrap"><div class="usage-bar"><div class="usage-bar-fill <?= $planUtilProd>=90?'danger':($planUtilProd>=70?'warn':'safe') ?>" style="width:<?= min(100,$planUtilProd) ?>%"></div></div></div>
    </div>
    <div class="stat-card orange">
      <div class="stat-header"><div class="stat-icon orange">📩</div></div>
      <div class="stat-value"><?= $planUtilEnq ?>%</div>
      <div class="stat-label">Plan Usage (Enquiries)</div>
      <div class="stat-sub"><?= $usage['enquiries_sent']??0 ?> / <?= $sub['enquiry_limit']==-1?'∞':$sub['enquiry_limit'] ?> this month</div>
      <div class="usage-bar-wrap"><div class="usage-bar"><div class="usage-bar-fill <?= $planUtilEnq>=90?'danger':($planUtilEnq>=70?'warn':'safe') ?>" style="width:<?= min(100,$planUtilEnq) ?>%"></div></div></div>
    </div>
  </div>

  <!-- Detailed breakdown -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
    <div class="card">
      <div class="card-header"><h2>📩 Enquiry Breakdown</h2></div>
      <div class="card-body" style="padding:0">
        <?php
        $enqRows = [
          ['Open',        $openEnq,    '#3b82f6', round($totalEnq>0?$openEnq/$totalEnq*100:0)],
          ['In Progress', $inProgEnq,  '#f59e0b', round($totalEnq>0?$inProgEnq/$totalEnq*100:0)],
          ['Closed',      $closedEnq,  '#10b981', round($totalEnq>0?$closedEnq/$totalEnq*100:0)],
        ];
        foreach ($enqRows as [$lbl,$val,$clr,$pct]):
        ?>
        <div class="metric-row" style="padding:14px 22px">
          <div style="display:flex;align-items:center;gap:8px;min-width:120px">
            <div style="width:8px;height:8px;border-radius:50%;background:<?= $clr ?>;flex-shrink:0"></div>
            <span class="metric-label"><?= $lbl ?></span>
          </div>
          <div class="metric-bar"><div class="metric-bar-fill" style="width:<?= $pct ?>%;background:<?= $clr ?>"></div></div>
          <div class="metric-value" style="min-width:40px;text-align:right"><?= $val ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h2>🚀 Improvement Tips</h2></div>
      <div class="card-body" style="padding:0">
        <?php
        $tips = [
          ['💡','Complete your profile to build trust with buyers.', BASE_URL.'/vendor/profile.php'],
          ['📸','Add high-quality images to every product listing.', BASE_URL.'/vendor/manage-products.php'],
          ['⚡','Respond to enquiries within 24 hours to improve score.', BASE_URL.'/vendor/enquiries.php'],
          ['📊','Track performance weekly to spot trends early.', BASE_URL.'/vendor/analytics.php'],
          ['💎','Upgrade to Professional for priority listing & analytics.', BASE_URL.'/vendor/subscription.php'],
        ];
        foreach ($tips as [$icon,$tip,$link]):
        ?>
        <div style="display:flex;align-items:flex-start;gap:12px;padding:13px 22px;border-bottom:1px solid var(--border-light)">
          <span style="font-size:18px;flex-shrink:0;margin-top:1px"><?= $icon ?></span>
          <div style="flex:1;font-size:13.5px;color:var(--text-muted)"><?= $tip ?></div>
          <a href="<?= $link ?>" style="font-size:12.5px;color:var(--primary);font-weight:600;white-space:nowrap">Go →</a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<script>
document.getElementById('hamburger').addEventListener('click',()=>{document.getElementById('sidebar').classList.add('open');document.getElementById('sidebar-overlay').classList.add('show');});
document.getElementById('sidebar-overlay').addEventListener('click',()=>{document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.remove('show');});
</script>
</div></div></body></html>
