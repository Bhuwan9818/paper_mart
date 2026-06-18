<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';
requireRoleStrict('vendor');
$user = currentUser(); $uid = $user['id'];
$sub  = getVendorSubscription($pdo, $uid);

// Mark all as read on visit
$pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);

// Get notifications (with fallback if table empty - add sample ones)
try {
    $notifs = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
    $notifs->execute([$uid]); $notifications=$notifs->fetchAll();
} catch(Exception $e) { $notifications=[]; }

// If empty, create welcome notifications
if (empty($notifications)) {
    $samples = [
        ['🎉','Welcome to VendorHub!','Your vendor account is ready. Start by adding your first product.', date('Y-m-d H:i:s'), BASE_URL.'/vendor/add-product.php'],
        ['💳','Free Trial Active','You have 14 days of free trial. Explore all features!', date('Y-m-d H:i:s'), BASE_URL.'/vendor/subscription.php'],
        ['📦','Complete your profile','Add your company details and GST number to build buyer trust.', date('Y-m-d H:i:s'), BASE_URL.'/vendor/profile.php'],
    ];
    // just show them as static
    foreach ($samples as [$icon,$title,$msg,$time,$link]) {
        try { $pdo->prepare("INSERT INTO notifications (user_id,title,message,link,is_read) VALUES(?,?,?,?,1)")->execute([$uid,$title,$msg,$link]); } catch(Exception $e){}
    }
    $notifs2 = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
    $notifs2->execute([$uid]); $notifications=$notifs2->fetchAll();
}

$pageTitle='Notifications'; $activePage='notifications';
include __DIR__ . '/../includes/head.php';

// Notification icon map
$iconMap = ['enquiry'=>'📩','product'=>'📦','subscription'=>'💳','profile'=>'👤','system'=>'⚙️','welcome'=>'🎉','warning'=>'⚠️','success'=>'✅'];
$colorMap = ['📩'=>'#dbeafe','📦'=>'#d1fae5','💳'=>'#ede9fe','👤'=>'#fce7f3','⚙️'=>'#f1f5f9','🎉'=>'#fef3c7','⚠️'=>'#fef3c7','✅'=>'#d1fae5'];
?>
<div class="topbar">
  <div class="topbar-left"><button class="hamburger" id="hamburger"><span></span><span></span><span></span></button><h1>Notifications</h1></div>
  <div class="topbar-right">
    <form method="POST" action="<?= BASE_URL ?>/ajax/mark-all-read.php" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <button class="btn btn-outline btn-sm" type="button" onclick="markAllRead()">✓ Mark All Read</button>
    </form>
    <div class="topbar-avatar"><?= avatarLetter($user['name']) ?></div>
  </div>
</div>
<div class="content">
  <?= subscriptionBanner($sub) ?>

  <div class="card" style="overflow:hidden">
    <div class="card-header">
      <h2>🔔 All Notifications</h2>
      <span class="badge badge-secondary"><?= count($notifications) ?> total</span>
    </div>

    <?php if ($notifications): ?>
    <div class="notif-list" id="notif-list">
    <?php foreach ($notifications as $n):
      // Pick icon from title heuristics
      $title = strtolower($n['title']);
      $icon = '🔔';
      if (str_contains($title,'enquir')) $icon='📩';
      elseif (str_contains($title,'product')) $icon='📦';
      elseif (str_contains($title,'subscri')||str_contains($title,'plan')||str_contains($title,'trial')) $icon='💳';
      elseif (str_contains($title,'profile')) $icon='👤';
      elseif (str_contains($title,'welcome')) $icon='🎉';
      elseif (str_contains($title,'approv')) $icon='✅';
      elseif (str_contains($title,'reject')||str_contains($title,'expir')) $icon='⚠️';
      $bg = $colorMap[$icon] ?? '#f1f5f9';
      $isUnread = !$n['is_read'];
      echo '<script>console.log('.json_encode([$n['link'],$icon,$bg]).')</script>';
    ?>
      <div class="notif-item <?= $isUnread?'unread':'' ?>" id="notif-<?= $n['id'] ?>"
           onclick="<?= $n['link'] ? "window.location='".$n['link']."'" : '' ?>">
        <?php if ($isUnread): ?><div style="width:6px;height:6px;border-radius:50%;background:var(--primary);flex-shrink:0;margin-top:7px"></div><?php endif; ?>
        <div class="notif-icon" style="background:<?= $bg ?>"><?= $icon ?></div>
        <div class="notif-content">
          <div class="notif-title"><?= sanitize($n['title']) ?></div>
          <?php if ($n['message']): ?><div class="notif-text"><?= sanitize($n['message']) ?></div><?php endif; ?>
        </div>
        <div class="notif-time"><?= timeAgo($n['created_at']) ?></div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php else: ?>
      <div class="empty-state">
        <div class="empty-state-icon">🔕</div>
        <h3>All caught up!</h3>
        <p>No notifications yet. They'll appear here when something needs your attention.</p>
      </div>
    <?php endif; ?>
  </div>

  <!-- Notification preferences -->
  <div class="card">
    <div class="card-header"><h2>⚙️ Notification Preferences</h2></div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <?php
        $prefs = [
          ['📩','New Enquiries','Get notified when a customer sends an enquiry',true],
          ['✅','Product Approved','When admin approves your product listing',true],
          ['⚠️','Product Rejected','When admin rejects or deactivates your product',true],
          ['💳','Subscription Expiry','Reminders before your plan expires',true],
          ['📊','Weekly Report','Weekly performance summary email',false],
          ['🎯','Promotions','Platform offers and upgrade discounts',false],
        ];
        foreach ($prefs as [$icon,$label,$desc,$default]):
        ?>
        <div style="display:flex;align-items:center;gap:12px;padding:14px;border:1px solid var(--border-light);border-radius:10px">
          <span style="font-size:22px"><?= $icon ?></span>
          <div style="flex:1">
            <div style="font-weight:600;font-size:13.5px"><?= $label ?></div>
            <div style="font-size:12px;color:var(--text-muted)"><?= $desc ?></div>
          </div>
          <label style="position:relative;width:42px;height:24px;flex-shrink:0;cursor:pointer">
            <input type="checkbox" <?= $default?'checked':'' ?> style="opacity:0;width:0;height:0" onchange="this.nextElementSibling.style.background=this.checked?'var(--primary)':'var(--border)'">
            <span style="position:absolute;inset:0;background:<?= $default?'var(--primary)':'var(--border)' ?>;border-radius:100px;transition:.3s">
              <span style="position:absolute;left:<?= $default?'20px':'2px' ?>;top:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:.3s;box-shadow:0 1px 3px rgba(0,0,0,.2)"></span>
            </span>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:16px">
        <button class="btn btn-primary">💾 Save Preferences</button>
      </div>
    </div>
  </div>
</div>
<script>
function markAllRead() {
  document.querySelectorAll('.notif-item.unread').forEach(el=>{
    el.classList.remove('unread');
    const dot = el.querySelector('div[style*="border-radius:50%"]');
    if(dot) dot.remove();
  });
}
document.getElementById('hamburger').addEventListener('click',()=>{document.getElementById('sidebar').classList.add('open');document.getElementById('sidebar-overlay').classList.add('show');});
document.getElementById('sidebar-overlay').addEventListener('click',()=>{document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.remove('show');});
</script>
</div></div></body></html>
