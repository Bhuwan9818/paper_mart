<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('vendor');
require_once __DIR__ . '/../includes/team.php';
requirePermission('ads');
$user = currentUser();
$uid  = $user['id'];

// ─── POST ACTIONS ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'book_ad') {
        $packageId  = (int)($_POST['package_id'] ?? 0);
        $slotId     = (int)($_POST['slot_id'] ?? 0);
        $startDate  = trim($_POST['start_date'] ?? '');
        $title      = trim($_POST['title'] ?? '');
        $subtitle   = trim($_POST['subtitle'] ?? '');
        $linkUrl    = trim($_POST['link_url'] ?? '');
        $buttonText = trim($_POST['button_text'] ?? '');
        $payRef     = trim($_POST['payment_ref'] ?? '');
        $errors = [];
        if (!$packageId) $errors[] = 'Please select a package.';
        if (!$slotId)    $errors[] = 'Please select a time slot.';
        if (!$startDate || strtotime($startDate) < strtotime('today')) $errors[] = 'Start date must be today or a future date.';
        if (empty($_FILES['image']['name'])) $errors[] = 'Please upload a banner image.';
        $pkg = null;
        if (!$errors) {
            $s = $pdo->prepare("SELECT * FROM ad_packages WHERE id=? AND is_active=1"); $s->execute([$packageId]); $pkg=$s->fetch();
            if (!$pkg) $errors[] = 'Invalid package.';
        }
        if (!$errors) {
            $sr = $pdo->prepare("SELECT * FROM ad_slots WHERE id=? AND is_active=1"); $sr->execute([$slotId]); $sr=$sr->fetch();
            if (!$sr) { $errors[] = 'Invalid slot.'; } else {
                $endDate = date('Y-m-d', strtotime($startDate.' +'.($pkg['duration_days']-1).' days'));
                $ov = $pdo->prepare("SELECT COUNT(*) FROM banner_ads WHERE slot_id=? AND status IN('approved','running','pending') AND start_date<=? AND end_date>=?");
                $ov->execute([$slotId,$endDate,$startDate]);
                if ($ov->fetchColumn() >= $sr['max_concurrent']) $errors[] = 'This slot is fully booked for your selected dates. Please try different dates or a different slot.';
            }
        }
        if (!$errors) {
            $imgName = uploadImage($_FILES['image'], 'ad');
            if (!$imgName) $errors[] = 'Image upload failed. Please use JPG/PNG/WebP under 5MB.';
        }
        if (!$errors) {
            $endDate = date('Y-m-d', strtotime($startDate.' +'.($pkg['duration_days']-1).' days'));
            $pdo->prepare("INSERT INTO banner_ads(vendor_id,package_id,slot_id,image,title,subtitle,link_url,button_text,start_date,end_date,status)VALUES(?,?,?,?,?,?,?,?,'$startDate','$endDate','pending')")->execute([$uid,$packageId,$slotId,$imgName,$title,$subtitle,$linkUrl,$buttonText]);
            $adId = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO ad_payments(ad_id,vendor_id,package_id,amount,currency,payment_method,payment_ref,status)VALUES(?,?,?,?,'INR','manual',?,'pending')")->execute([$adId,$uid,$packageId,$pkg['price'],$payRef]);
            flash('success','Ad booking submitted! Make your payment and our team will activate it within 24 hours.');
            header('Location: ads.php'); exit;
        }
        foreach ($errors as $e) flash('error', $e);
        header('Location: ads.php?tab=book'); exit;
    }

    if ($action === 'update_pay_ref') {
        $adId=(int)$_POST['ad_id']; $ref=trim($_POST['payment_ref']??'');
        if ($ref) { $pdo->prepare("UPDATE ad_payments SET payment_ref=? WHERE ad_id=? AND vendor_id=? AND status='pending'")->execute([$ref,$adId,$uid]); flash('success','Reference submitted. We\'ll verify within 24 hours.'); }
        else flash('error','Please enter a valid reference.');
        header('Location: ads.php'); exit;
    }

    if ($action === 'cancel_ad') {
        $adId=(int)$_POST['ad_id'];
        $pdo->prepare("UPDATE banner_ads SET status='cancelled' WHERE id=? AND vendor_id=? AND status IN('pending','approved')")->execute([$adId,$uid]);
        flash('success','Ad booking cancelled.');
        header('Location: ads.php'); exit;
    }
}

$tab = $_GET['tab'] ?? 'my-ads';
$packages = $pdo->query("SELECT * FROM ad_packages WHERE is_active=1 ORDER BY sort_order,price")->fetchAll();
$slots    = $pdo->query("SELECT * FROM ad_slots WHERE is_active=1 ORDER BY sort_order,start_time")->fetchAll();
$myAds    = $pdo->prepare("SELECT ba.*,p.name AS package_name,p.duration_days,p.price AS package_price,s.name AS slot_name,s.start_time,s.end_time,(SELECT status FROM ad_payments WHERE ad_id=ba.id ORDER BY created_at DESC LIMIT 1) AS pay_status,(SELECT payment_ref FROM ad_payments WHERE ad_id=ba.id ORDER BY created_at DESC LIMIT 1) AS pay_ref,(SELECT id FROM ad_payments WHERE ad_id=ba.id ORDER BY created_at DESC LIMIT 1) AS pay_id FROM banner_ads ba JOIN ad_packages p ON p.id=ba.package_id JOIN ad_slots s ON s.id=ba.slot_id WHERE ba.vendor_id=? ORDER BY ba.created_at DESC");
$myAds->execute([$uid]); $myAds=$myAds->fetchAll();

$pageTitle='Banner Ads'; $activePage='ads';
include __DIR__ . '/../includes/head.php';
?>
<style>
/* ── VENDOR ADS — premium UI ────────────────────── */
.ads-tabs{display:flex;gap:0;margin-bottom:24px;background:var(--cream-light);border-radius:12px;padding:4px;border:1px solid var(--border-light)}
.ads-tab{flex:1;text-align:center;padding:10px 12px;font-size:13.5px;font-weight:600;border-radius:9px;text-decoration:none;color:var(--text-muted);transition:var(--transition)}
.ads-tab.active{background:var(--crimson);color:#fff;box-shadow:0 2px 8px rgba(139,36,29,.25)}
.ads-tab:hover:not(.active){background:var(--cream);color:var(--crimson)}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:100px;font-size:11px;font-weight:700;letter-spacing:.02em}
.status-pill::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;opacity:.8}
.sp-running{background:#dcfce7;color:#16a34a}.sp-pending{background:#fef3c7;color:#d97706}
.sp-approved{background:#dbeafe;color:#2563eb}.sp-rejected{background:#fee2e2;color:#dc2626}
.sp-paused{background:#ede9fe;color:#7c3aed}.sp-completed{background:#f1f5f9;color:#64748b}
.sp-cancelled{background:#fee2e2;color:#dc2626}.sp-paid{background:#dcfce7;color:#16a34a}
.sp-failed{background:#fee2e2;color:#dc2626}.sp-refunded{background:#ede9fe;color:#7c3aed}
/* Ad listing card */
.ad-list-card{background:var(--white);border:1px solid var(--border-light);border-radius:var(--radius-lg);overflow:hidden;transition:var(--transition);position:relative}
.ad-list-card::before{content:'';position:absolute;top:0;left:0;bottom:0;width:4px}
.ad-list-card.running::before{background:linear-gradient(180deg,#16a34a,#34d399)}
.ad-list-card.pending::before{background:linear-gradient(180deg,#d97706,#fbbf24)}
.ad-list-card.approved::before{background:linear-gradient(180deg,#2563eb,#60a5fa)}
.ad-list-card.rejected::before,.ad-list-card.cancelled::before{background:linear-gradient(180deg,#dc2626,#f87171)}
.ad-list-card.completed::before{background:linear-gradient(180deg,#64748b,#94a3b8)}
.ad-list-card:hover{box-shadow:var(--shadow-md)}
.ad-card-inner{display:grid;grid-template-columns:160px 1fr auto;gap:16px;align-items:center;padding:16px 16px 16px 20px}
.ad-thumb{width:160px;height:60px;object-fit:cover;border-radius:8px;border:1px solid var(--border-light);display:block}
.ad-meta{display:flex;gap:14px;flex-wrap:wrap;margin-top:8px}
.ad-meta-item{font-size:11.5px;color:var(--text-muted)}
.ad-meta-item strong{color:var(--text);display:block;font-size:12.5px}
.days-left-badge{display:inline-flex;align-items:center;gap:5px;background:var(--crimson-light);color:var(--crimson);border-radius:100px;padding:3px 10px;font-size:11px;font-weight:700}
/* Package selector */
.pkg-select-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:6px}
.pkg-select-card{border:2px solid var(--border-light);border-radius:12px;padding:16px 14px;cursor:pointer;transition:var(--transition);position:relative;text-align:center}
.pkg-select-card:hover{border-color:var(--crimson-light);box-shadow:var(--shadow-sm)}
.pkg-select-card.selected{border-color:var(--crimson);background:var(--crimson-light)}
.pkg-select-card.selected::after{content:'✓';position:absolute;top:8px;right:10px;font-size:12px;font-weight:800;color:var(--crimson)}
.pkg-price{font-family:'Poppins',sans-serif;font-size:26px;font-weight:800;color:var(--crimson)}
.pkg-name{font-size:13px;font-weight:600;margin:4px 0 2px}
.pkg-days-tag{font-size:11.5px;color:var(--text-muted)}
/* Slot card */
.slot-select-card{border:2px solid var(--border-light);border-radius:10px;padding:14px;cursor:pointer;transition:var(--transition);position:relative}
.slot-select-card:hover{border-color:var(--crimson-light)}
.slot-select-card.selected{border-color:var(--crimson);background:var(--crimson-light)}
.slot-select-card.selected::after{content:'✓';position:absolute;top:8px;right:10px;font-size:12px;font-weight:800;color:var(--crimson)}
.slot-select-card.full{opacity:.5;pointer-events:none}
.slot-time-label{font-family:'Poppins',sans-serif;font-size:13.5px;font-weight:700;color:var(--crimson)}
.slot-fill-mini{height:4px;border-radius:2px;background:var(--border-light);margin:8px 0;overflow:hidden}
.slot-fill-mini-bar{height:100%;border-radius:2px;background:linear-gradient(90deg,var(--crimson),var(--crimson-mid))}
/* Steps */
.step-header{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.step-num{width:26px;height:26px;border-radius:50%;background:var(--crimson);color:#fff;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.step-title{font-size:14px;font-weight:700;color:var(--text)}
.step-section{margin-bottom:28px;padding-bottom:28px;border-bottom:1px solid var(--border-light)}
.step-section:last-child{border-bottom:none;margin-bottom:0}
/* Banner preview */
.banner-preview-box{width:100%;aspect-ratio:8/3;border-radius:10px;overflow:hidden;border:2px dashed var(--border-light);background:var(--cream-light);display:flex;align-items:center;justify-content:center;transition:.2s;margin-top:10px}
.banner-preview-box img{width:100%;height:100%;object-fit:cover}
.banner-preview-box.has-img{border-style:solid;border-color:var(--border-light)}
/* Payment box */
.payment-box{background:linear-gradient(135deg,var(--gold-light),var(--cream-light));border:1px solid var(--gold-dark);border-radius:12px;padding:18px 20px}
.payment-amount{font-family:'Poppins',sans-serif;font-size:32px;font-weight:800;color:var(--crimson);line-height:1}
.bank-details{background:rgba(255,255,255,.7);border:1px solid var(--cream-dark);border-radius:8px;padding:12px 14px;font-size:12.5px;line-height:1.8;margin:12px 0}
/* Right sidebar info */
.info-card{background:var(--white);border:1px solid var(--border-light);border-radius:var(--radius-lg);overflow:hidden}
.info-card-header{background:linear-gradient(135deg,var(--crimson),var(--crimson-mid));color:#fff;padding:14px 18px;font-size:13.5px;font-weight:700}
.info-card-body{padding:16px 18px}
.how-step{display:flex;gap:12px;margin-bottom:14px;align-items:flex-start}
.how-step:last-child{margin-bottom:0}
.how-num{width:24px;height:24px;border-radius:50%;background:var(--crimson);color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
.slot-avail-row{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border:1px solid var(--border-light);border-radius:8px;margin-bottom:8px}
.slot-avail-row:last-child{margin-bottom:0}
/* Responsive */
@media(max-width:900px){.ad-card-inner{grid-template-columns:1fr;gap:10px}.ad-thumb{width:100%;height:auto;aspect-ratio:8/3}}
@media(max-width:768px){
  .ads-tabs{gap:4px;flex-wrap:wrap}
  .ads-tab{flex:none;width:calc(50% - 2px)}
  .book-layout{flex-direction:column!important}
  .book-sidebar{width:100%!important}
  .content{padding:16px}
  .pkg-select-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:480px){
  .pkg-select-grid{grid-template-columns:1fr}
  .ads-tab{font-size:12px;padding:8px 4px}
}
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Banner Ads</h1>
  </div>
  <div class="topbar-right">
    <a href="?tab=book" class="btn btn-primary btn-sm">+ Book New Ad</a>
    <div class="topbar-avatar"><?= avatarLetter($user['name']) ?></div>
  </div>
</div>
<div class="content">
  <?= showFlash() ?>

  <!-- Tabs -->
  <div class="ads-tabs">
    <a href="?tab=my-ads" class="ads-tab <?= $tab==='my-ads'?'active':'' ?>">📋 My Ads</a>
    <a href="?tab=book"   class="ads-tab <?= $tab==='book'  ?'active':'' ?>">➕ Book New Ad</a>
  </div>

  <?php if ($tab === 'my-ads'): ?>
  <!-- ═══════ MY ADS ═══════ -->
  <?php if (!$myAds): ?>
    <div class="empty-state">
      <div class="empty-state-icon">🎯</div>
      <h3>No Ad Bookings Yet</h3>
      <p>Book your first banner ad to showcase your products on the homepage carousel.</p>
      <a href="?tab=book" class="btn btn-primary" style="margin-top:14px">+ Book a Banner Ad</a>
    </div>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:12px">
    <?php foreach($myAds as $ad):
      $sc = $ad['status']; $pst=$ad['pay_status']??'pending';
      $daysLeft = $sc==='running' ? max(0,(int)((strtotime($ad['end_date'])-time())/86400)) : null;
    ?>
    <div class="ad-list-card <?= sanitize($sc) ?>">
      <div class="ad-card-inner">
        <img src="<?= UPLOAD_URL.sanitize($ad['image']) ?>" class="ad-thumb" alt="">
        <div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px">
            <strong style="font-size:14px"><?= sanitize($ad['package_name']) ?></strong>
            <span class="status-pill sp-<?= sanitize($sc) ?>"><?= ucfirst($sc) ?></span>
            <span class="status-pill sp-<?= sanitize($pst) ?>"><?= ucfirst($pst) ?> payment</span>
            <?php if ($daysLeft!==null): ?><span class="days-left-badge">⏱ <?= $daysLeft ?> days left</span><?php endif; ?>
          </div>
          <div class="ad-meta">
            <div class="ad-meta-item"><strong><?= sanitize($ad['slot_name']) ?></strong><?= substr($ad['start_time'],0,5)?>–<?=substr($ad['end_time'],0,5)?></div>
            <div class="ad-meta-item"><strong>Schedule</strong><?= date('d M',strtotime($ad['start_date'])) ?> – <?= date('d M Y',strtotime($ad['end_date'])) ?></div>
            <div class="ad-meta-item"><strong>Amount</strong>₹<?= number_format($ad['package_price'],0) ?></div>
            <?php if($ad['pay_ref']): ?><div class="ad-meta-item"><strong>Ref</strong><?= sanitize($ad['pay_ref']) ?></div><?php endif; ?>
          </div>
          <?php if ($ad['admin_note'] && in_array($sc,['rejected','paused'])): ?>
          <div style="margin-top:8px;font-size:12px;color:#dc2626;background:#fee2e2;border-radius:6px;padding:6px 10px">📌 <?= sanitize($ad['admin_note']) ?></div>
          <?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;min-width:90px">
          <?php if (($ad['pay_status']??'pending')==='pending'): ?>
          <button class="btn btn-primary btn-sm" onclick="openPayRefModal(<?=$ad['id']?>,<?=$ad['pay_id']?:0?>,<?=$ad['package_price']?>,'<?=addslashes($ad['package_name'])?>')">💳 Pay Ref</button>
          <?php endif; ?>
          <?php if(in_array($sc,['pending','approved'])): ?>
          <form method="POST" onsubmit="return confirm('Cancel this booking?')">
            <input type="hidden" name="csrf_token" value="<?=csrfToken()?>">
            <input type="hidden" name="action" value="cancel_ad">
            <input type="hidden" name="ad_id" value="<?=$ad['id']?>">
            <button type="submit" class="btn btn-outline btn-sm">✕ Cancel</button>
          </form>
          <?php endif; ?>
          <a href="<?=UPLOAD_URL.sanitize($ad['image'])?>" target="_blank" class="btn btn-outline btn-sm">🖼️ View</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php elseif ($tab === 'book'): ?>
  <!-- ═══════ BOOK NEW AD ═══════ -->
  <div class="book-layout" style="display:flex;gap:22px;align-items:flex-start">

    <!-- Main form -->
    <div style="flex:1;min-width:0">
      <form method="POST" enctype="multipart/form-data" id="book-form">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="book_ad">
        <input type="hidden" name="package_id" id="f-package-id">
        <input type="hidden" name="slot_id" id="f-slot-id">

        <!-- Step 1: Package -->
        <div class="step-section">
          <div class="step-header"><div class="step-num">1</div><div class="step-title">Choose a Package</div></div>
          <div class="pkg-select-grid">
            <?php foreach($packages as $p): ?>
            <div class="pkg-select-card" data-id="<?=$p['id']?>" data-days="<?=$p['duration_days']?>" data-price="<?=$p['price']?>" onclick="selectPackage(this)">
              <div class="pkg-price">₹<?= number_format($p['price'],0) ?></div>
              <div class="pkg-name"><?= sanitize($p['name']) ?></div>
              <div class="pkg-days-tag"><?= $p['duration_days'] ?> days<?= $p['max_slots']>1?' · '.$p['max_slots'].' slots':'' ?></div>
              <?php if($p['description']): ?><div style="font-size:11px;color:var(--text-muted);margin-top:6px;line-height:1.4"><?= sanitize($p['description']) ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <div id="pkg-err" style="display:none;font-size:12.5px;color:#dc2626;margin-top:6px">⚠️ Please select a package.</div>
        </div>

        <!-- Step 2: Time Slot -->
        <div class="step-section">
          <div class="step-header"><div class="step-num">2</div><div class="step-title">Select a Time Slot</div></div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px">
            <?php foreach($slots as $s):
              $today=date('Y-m-d');
              $usedNow=$pdo->prepare("SELECT COUNT(*) FROM banner_ads WHERE slot_id=? AND status IN('approved','running','pending') AND start_date<=? AND end_date>=?");
              $usedNow->execute([$s['id'],$today,$today]); $usedNow=(int)$usedNow->fetchColumn();
              $freeNow=$s['max_concurrent']-$usedNow; $isFull=$freeNow<=0;
              $fillPct=min(100,$s['max_concurrent']>0?round($usedNow/$s['max_concurrent']*100):0);
            ?>
            <div class="slot-select-card <?= $isFull?'full':'' ?>" data-id="<?=$s['id']?>" data-max="<?=$s['max_concurrent']?>" onclick="<?= $isFull?'':'selectSlot(this)' ?>">
              <div style="font-weight:700;font-size:13px;margin-bottom:2px"><?= sanitize($s['name']) ?></div>
              <div class="slot-time-label"><?=substr($s['start_time'],0,5)?>–<?=substr($s['end_time'],0,5)?></div>
              <div class="slot-fill-mini"><div class="slot-fill-mini-bar" style="width:<?=$fillPct?>%;<?=$isFull?'background:linear-gradient(90deg,#dc2626,#f87171)':''?>"></div></div>
              <div style="font-size:11.5px;color:<?=$isFull?'#dc2626':'var(--success)'?>;font-weight:600"><?=$isFull?'FULL':'✓ '.$freeNow.' slot'.($freeNow>1?'s':'').' available'?></div>
              <?php if($s['description']): ?><div style="font-size:10.5px;color:var(--text-muted);margin-top:4px"><?=sanitize($s['description'])?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <div id="slot-err" style="display:none;font-size:12.5px;color:#dc2626;margin-top:6px">⚠️ Please select a time slot.</div>
          <div id="avail-notice" style="display:none;font-size:12.5px;margin-top:10px;padding:8px 12px;border-radius:8px"></div>
        </div>

        <!-- Step 3: Dates -->
        <div class="step-section">
          <div class="step-header"><div class="step-num">3</div><div class="step-title">Set Your Start Date</div></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            <div class="form-group">
              <label class="form-label">Start Date <span class="req">*</span></label>
              <input type="date" name="start_date" id="start-date" class="form-control" min="<?=date('Y-m-d')?>" required onchange="onDateChange()">
            </div>
            <div class="form-group">
              <label class="form-label">End Date <span style="font-size:11px;color:var(--text-muted)">(auto-calculated)</span></label>
              <div id="end-date-display" style="height:42px;display:flex;align-items:center;padding:0 14px;background:var(--cream-light);border:1.5px solid var(--border-light);border-radius:var(--radius-sm);font-size:13.5px;color:var(--text-muted);border-radius:8px">Select package + date</div>
            </div>
          </div>
        </div>

        <!-- Step 4: Banner Image -->
        <div class="step-section">
          <div class="step-header"><div class="step-num">4</div><div class="step-title">Upload Your Banner</div></div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Banner Image <span class="req">*</span></label>
            <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp" required onchange="previewBanner(this)">
            <p style="font-size:11.5px;color:var(--text-muted);margin-top:6px;line-height:1.7">📐 <strong>Required: 1600 × 600 px</strong> (8:3 ratio) · JPG / PNG / WebP · Max 5 MB<br>Keep text, logos, and key content within the <strong>centre 80%</strong> of the image.</p>
          </div>
          <div class="banner-preview-box" id="banner-preview-wrap">
            <div style="text-align:center;color:var(--text-muted)"><div style="font-size:28px;margin-bottom:4px">🖼️</div><div style="font-size:12.5px">Your banner will appear here</div></div>
          </div>
          <div id="banner-dim-warn" style="display:none;font-size:12px;color:#d97706;margin-top:6px;padding:6px 10px;background:#fef3c7;border-radius:6px">⚠️ Dimensions don't match 1600×600. The image will still display but may appear cropped or stretched.</div>
        </div>

        <!-- Step 5: Optional Overlay -->
        <div class="step-section">
          <div class="step-header"><div class="step-num">5</div><div class="step-title">Optional Text Overlay <span style="font-size:12px;font-weight:400;color:var(--text-muted)">(leave blank if banner is self-contained)</span></div></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group"><label class="form-label">Title</label><input type="text" name="title" class="form-control" maxlength="150" placeholder="e.g. Premium Kraft Paper"></div>
            <div class="form-group"><label class="form-label">CTA Button Text</label><input type="text" name="button_text" class="form-control" maxlength="60" placeholder="e.g. Shop Now"></div>
          </div>
          <div class="form-group"><label class="form-label">Subtitle</label><input type="text" name="subtitle" class="form-control" maxlength="255" placeholder="e.g. Flat 10% off on bulk orders this month"></div>
          <div class="form-group" style="margin-bottom:0"><label class="form-label">Click-through URL</label><input type="url" name="link_url" class="form-control" placeholder="https://…"></div>
        </div>

        <!-- Step 6: Payment -->
        <div class="step-section">
          <div class="step-header"><div class="step-num">6</div><div class="step-title">Complete Payment</div></div>
          <div class="payment-box">
            <div style="margin-bottom:4px;font-size:12px;font-weight:600;color:var(--crimson-deep);text-transform:uppercase;letter-spacing:.05em">Amount to Pay</div>
            <div class="payment-amount" id="pay-amount">Select a package above</div>
            <div class="bank-details">
              <strong>Bank Transfer / UPI</strong><br>
              Company: PaperMart India Pvt Ltd<br>
              Bank: HDFC Bank &nbsp;|&nbsp; IFSC: HDFC0001234<br>
              A/C: 5010 0123 4567 &nbsp;|&nbsp; UPI: papermart@hdfcbank
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label" style="font-size:13px">Transaction / UTR Reference <span style="font-size:11px;color:var(--text-muted)">(optional now, required to activate)</span></label>
              <input type="text" name="payment_ref" class="form-control" placeholder="e.g. UTR123456789012">
            </div>
          </div>
          <p style="font-size:12px;color:var(--text-muted);margin-top:10px;line-height:1.6">Your ad will be reviewed within 24 hours. It goes live once payment is verified. You can add your payment reference later from "My Ads".</p>
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-full" onclick="return validateBooking()" style="font-size:15px;padding:14px">Submit Ad Booking →</button>
      </form>
    </div>

    <!-- Right sidebar -->
    <div class="book-sidebar" style="width:300px;flex-shrink:0;display:flex;flex-direction:column;gap:16px">

      <div class="info-card">
        <div class="info-card-header">📋 How It Works</div>
        <div class="info-card-body">
          <?php foreach([['1','Pick a package','Choose duration and price that fits your goal.'],['2','Choose a slot','Each slot runs during a specific time window daily.'],['3','Upload banner','Designed at 1600×600px for best results.'],['4','Pay & submit','Transfer and paste the UTR. We verify in 24h.'],['5','Go live 🚀','Your banner rotates automatically on the homepage.']] as [$n,$t,$d]): ?>
          <div class="how-step"><div class="how-num"><?=$n?></div><div><div style="font-weight:600;font-size:12.5px"><?=$t?></div><div style="font-size:11.5px;color:var(--text-muted);margin-top:2px"><?=$d?></div></div></div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="info-card">
        <div class="info-card-header">⏰ Slot Availability Today</div>
        <div class="info-card-body">
          <?php foreach($slots as $s):
            $today=date('Y-m-d');
            $st=$pdo->prepare("SELECT COUNT(*) FROM banner_ads WHERE slot_id=? AND status IN('approved','running','pending') AND start_date<=? AND end_date>=?");
            $st->execute([$s['id'],$today,$today]); $used=(int)$st->fetchColumn();
            $free=$s['max_concurrent']-$used; $isFull=$free<=0;
          ?>
          <div class="slot-avail-row">
            <div><div style="font-size:13px;font-weight:600"><?=sanitize($s['name'])?></div><div style="font-size:11px;color:var(--text-muted)"><?=substr($s['start_time'],0,5)?>–<?=substr($s['end_time'],0,5)?></div></div>
            <div style="text-align:right"><div style="font-size:12px;font-weight:700;color:<?=$isFull?'#dc2626':'#16a34a'?>"><?=$isFull?'FULL':$free.' free'?></div><div style="font-size:10px;color:var(--text-muted)"><?=$used?>/<?=$s['max_concurrent']?></div></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="info-card">
        <div class="info-card-header">📐 Banner Requirements</div>
        <div class="info-card-body">
          <ul style="font-size:12.5px;color:var(--text-muted);line-height:2.1;padding-left:18px;margin:0">
            <li>Size: <strong>1600 × 600 px</strong></li>
            <li>Format: JPG, PNG, WebP</li>
            <li>Max file: 5 MB</li>
            <li>Keep content in centre 80%</li>
            <li>No competitor branding</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Payment Reference Modal -->
<div id="payref-modal" class="modal-backdrop">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><h3>Submit Payment Reference</h3><button class="modal-close" onclick="document.getElementById('payref-modal').classList.remove('open')">✕</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?=csrfToken()?>">
      <input type="hidden" name="action" value="update_pay_ref">
      <input type="hidden" name="ad_id" id="pr-adid">
      <div id="pr-info" style="font-size:13px;color:var(--text-muted);background:var(--cream-light);border-radius:8px;padding:10px 12px;margin-bottom:14px"></div>
      <div class="form-group"><label class="form-label">Transaction / UTR Reference <span class="req">*</span></label><input type="text" name="payment_ref" id="pr-ref" class="form-control" placeholder="e.g. UTR123456789012" required></div>
      <p style="font-size:11.5px;color:var(--text-muted);margin-bottom:14px">Once submitted, our team will verify and activate your ad within 24 hours.</p>
      <button type="submit" class="btn btn-primary btn-full">Submit Reference</button>
    </form>
  </div>
</div>

<script>
let selectedPkgId=null, selectedSlotId=null;

function selectPackage(el){
  document.querySelectorAll('.pkg-select-card').forEach(c=>c.classList.remove('selected'));
  el.classList.add('selected');
  selectedPkgId=el.dataset.id;
  document.getElementById('f-package-id').value=selectedPkgId;
  document.getElementById('pkg-err').style.display='none';
  const price=parseFloat(el.dataset.price);
  document.getElementById('pay-amount').textContent='₹'+price.toLocaleString('en-IN',{minimumFractionDigits:0});
  onDateChange();
}

function selectSlot(el){
  document.querySelectorAll('.slot-select-card:not(.full)').forEach(c=>c.classList.remove('selected'));
  el.classList.add('selected');
  selectedSlotId=el.dataset.id;
  document.getElementById('f-slot-id').value=selectedSlotId;
  document.getElementById('slot-err').style.display='none';
  checkLiveAvailability();
}

function onDateChange(){
  const startEl=document.getElementById('start-date');
  const dispEl=document.getElementById('end-date-display');
  const pkgEl=document.querySelector('.pkg-select-card.selected');
  if(!startEl.value||!pkgEl){dispEl.textContent='Select package + date';return;}
  const days=parseInt(pkgEl.dataset.days);
  const start=new Date(startEl.value);
  start.setDate(start.getDate()+days-1);
  dispEl.textContent=start.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
  dispEl.style.color='var(--text)';
  checkLiveAvailability();
}

function checkLiveAvailability(){
  const n=document.getElementById('avail-notice');
  const startEl=document.getElementById('start-date');
  const pkgEl=document.querySelector('.pkg-select-card.selected');
  if(!selectedSlotId||!startEl.value||!pkgEl){n.style.display='none';return;}
  fetch('<?=BASE_URL?>/public/ajax/check-ad-slot.php?slot_id='+selectedSlotId+'&start='+startEl.value+'&days='+pkgEl.dataset.days)
    .then(r=>r.json()).then(d=>{
      const maxEl=document.querySelector('.slot-select-card.selected');
      const max=maxEl?parseInt(maxEl.dataset.max):3;
      const free=max-(d.used||0);
      n.style.display='block';
      if(free>0){n.style.background='#dcfce7';n.style.color='#16a34a';n.textContent='✅ '+free+' slot'+(free>1?'s':'')+' available for your selected dates.';}
      else{n.style.background='#fee2e2';n.style.color='#dc2626';n.textContent='❌ This slot is fully booked for your dates. Please try different dates or another slot.';}
    }).catch(()=>{n.style.display='none';});
}

function previewBanner(input){
  const wrap=document.getElementById('banner-preview-wrap');
  const warn=document.getElementById('banner-dim-warn');
  if(!input.files[0]){wrap.innerHTML='<div style="text-align:center;color:var(--text-muted)"><div style="font-size:28px;margin-bottom:4px">🖼️</div><div style="font-size:12.5px">Your banner will appear here</div></div>';wrap.classList.remove('has-img');return;}
  const url=URL.createObjectURL(input.files[0]);
  const img=document.createElement('img');
  img.onload=function(){
    const ratio=this.naturalWidth/this.naturalHeight;
    warn.style.display=Math.abs(ratio-8/3)<0.35?'none':'block';
    URL.revokeObjectURL(url);
  };
  img.src=url; img.style.cssText='width:100%;height:100%;object-fit:cover';
  wrap.innerHTML=''; wrap.appendChild(img); wrap.classList.add('has-img');
}

function validateBooking(){
  let ok=true;
  if(!selectedPkgId){document.getElementById('pkg-err').style.display='block';ok=false;document.querySelector('.step-section').scrollIntoView({behavior:'smooth'});}
  if(!selectedSlotId){document.getElementById('slot-err').style.display='block';if(ok)document.getElementById('slot-err').scrollIntoView({behavior:'smooth'});ok=false;}
  return ok;
}

function openPayRefModal(adId,payId,price,pkgName){
  document.getElementById('pr-adid').value=adId;
  document.getElementById('pr-ref').value='';
  document.getElementById('pr-info').innerHTML='<strong>'+pkgName+'</strong> &middot; ₹'+parseFloat(price).toFixed(0);
  document.getElementById('payref-modal').classList.add('open');
}
document.getElementById('payref-modal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
</script>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?=BASE_URL?>/assets/script.js"></script>
</div></div></body></html>
