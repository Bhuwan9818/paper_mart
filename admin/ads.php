<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

$tab = $_GET['tab'] ?? 'bookings';

// ─── POST ACTIONS ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'booking_status') {
        $id     = (int)$_POST['id'];
        $status = in_array($_POST['status'], ['pending','approved','rejected','running','paused','completed','cancelled']) ? $_POST['status'] : 'pending';
        $note   = trim($_POST['admin_note'] ?? '');
        $order  = (int)($_POST['sort_order'] ?? 0);
        $pdo->prepare("UPDATE banner_ads SET status=?, admin_note=?, sort_order=? WHERE id=?")->execute([$status, $note, $order, $id]);
        if ($status === 'approved') {
            $pdo->prepare("UPDATE banner_ads SET status='running' WHERE id=? AND start_date<=CURDATE() AND end_date>=CURDATE() AND (SELECT COUNT(*) FROM ad_payments WHERE ad_id=? AND status='paid')>0")->execute([$id,$id]);
        }
        flash('success', 'Booking updated.');
        header('Location: ads.php?tab=bookings'); exit;
    }
    if ($action === 'save_package') {
        $id=$id=(int)($_POST['id']??0); $name=trim($_POST['name']??''); $desc=trim($_POST['description']??'');
        $days=max(1,(int)($_POST['duration_days']??7)); $price=max(0,(float)($_POST['price']??0));
        $slots=max(1,(int)($_POST['max_slots']??1)); $order=(int)($_POST['sort_order']??0); $active=isset($_POST['is_active'])?1:0;
        if(!$name){flash('error','Package name required.');header('Location: ads.php?tab=packages');exit;}
        if($id){$pdo->prepare("UPDATE ad_packages SET name=?,description=?,duration_days=?,price=?,max_slots=?,sort_order=?,is_active=? WHERE id=?")->execute([$name,$desc,$days,$price,$slots,$order,$active,$id]);flash('success','Package updated.');}
        else{$pdo->prepare("INSERT INTO ad_packages(name,description,duration_days,price,max_slots,sort_order,is_active)VALUES(?,?,?,?,?,?,?)")->execute([$name,$desc,$days,$price,$slots,$order,$active]);flash('success','Package created.');}
        header('Location: ads.php?tab=packages'); exit;
    }
    if ($action === 'delete_package') { $pdo->prepare("DELETE FROM ad_packages WHERE id=?")->execute([(int)$_POST['id']]); flash('success','Deleted.'); header('Location: ads.php?tab=packages'); exit; }
    if ($action === 'save_slot') {
        $id=(int)($_POST['id']??0); $name=trim($_POST['name']??''); $start=trim($_POST['start_time']??''); $end=trim($_POST['end_time']??'');
        $max=max(1,(int)($_POST['max_concurrent']??3)); $desc=trim($_POST['description']??''); $order=(int)($_POST['sort_order']??0); $active=isset($_POST['is_active'])?1:0;
        if(!$name||!$start||!$end){flash('error','Name and times required.');header('Location: ads.php?tab=slots');exit;}
        if($id){$pdo->prepare("UPDATE ad_slots SET name=?,start_time=?,end_time=?,max_concurrent=?,description=?,sort_order=?,is_active=? WHERE id=?")->execute([$name,$start,$end,$max,$desc,$order,$active,$id]);flash('success','Slot updated.');}
        else{$pdo->prepare("INSERT INTO ad_slots(name,start_time,end_time,max_concurrent,description,sort_order,is_active)VALUES(?,?,?,?,?,?,?)")->execute([$name,$start,$end,$max,$desc,$order,$active]);flash('success','Slot created.');}
        header('Location: ads.php?tab=slots'); exit;
    }
    if ($action === 'delete_slot') { $pdo->prepare("DELETE FROM ad_slots WHERE id=?")->execute([(int)$_POST['id']]); flash('success','Deleted.'); header('Location: ads.php?tab=slots'); exit; }
    if ($action === 'payment_status') {
        $id=(int)$_POST['id']; $status=in_array($_POST['status'],['pending','paid','failed','refunded'])?$_POST['status']:'pending'; $ref=trim($_POST['payment_ref']??'');
        $pdo->prepare("UPDATE ad_payments SET status=?,payment_ref=?,paid_at=? WHERE id=?")->execute([$status,$ref,$status==='paid'?date('Y-m-d H:i:s'):null,$id]);
        if($status==='paid'){$pdo->prepare("UPDATE banner_ads ba JOIN ad_payments ap ON ap.ad_id=ba.id SET ba.status=IF(ba.start_date<=CURDATE() AND ba.end_date>=CURDATE(),'running','approved') WHERE ap.id=? AND ba.status='pending'")->execute([$id]);}
        flash('success','Payment updated.'); header('Location: ads.php?tab=payments'); exit;
    }
    // Fallback banner CRUD (when no vendor ads are running)
    if ($action === 'save_fallback_banner') {
        $bid=(int)($_POST['bid']??0); $title=trim($_POST['title']??''); $sub=trim($_POST['subtitle']??'');
        $link=trim($_POST['link_url']??''); $btn=trim($_POST['button_text']??'');
        $bstatus=in_array($_POST['bstatus']??'',['active','inactive'])?$_POST['bstatus']:'active';
        $border=(int)($_POST['bsort']??0);
        $imgName=null;
        if(!empty($_FILES['bimage']['name'])){$imgName=uploadImage($_FILES['bimage'],'banner');if(!$imgName){flash('error','Image upload failed.');header('Location: ads.php?tab=fallback');exit;}}
        if($bid){
            if($imgName){$old=$pdo->prepare("SELECT image FROM banners WHERE id=?");$old->execute([$bid]);$of=$old->fetchColumn();if($of&&file_exists(UPLOAD_DIR.$of))@unlink(UPLOAD_DIR.$of);$pdo->prepare("UPDATE banners SET image=?,title=?,subtitle=?,link_url=?,button_text=?,sort_order=?,status=? WHERE id=?")->execute([$imgName,$title,$sub,$link,$btn,$border,$bstatus,$bid]);}
            else{$pdo->prepare("UPDATE banners SET title=?,subtitle=?,link_url=?,button_text=?,sort_order=?,status=? WHERE id=?")->execute([$title,$sub,$link,$btn,$border,$bstatus,$bid]);}
            flash('success','Banner updated.');
        } else {
            if(!$imgName){flash('error','Please select an image.');header('Location: ads.php?tab=fallback');exit;}
            $pdo->prepare("INSERT INTO banners(image,title,subtitle,link_url,button_text,sort_order,status)VALUES(?,?,?,?,?,?,?)")->execute([$imgName,$title,$sub,$link,$btn,$border,$bstatus]);
            flash('success','Banner added.');
        }
        header('Location: ads.php?tab=fallback'); exit;
    }
    if ($action === 'delete_fallback_banner') {
        $bid=(int)$_POST['bid']; $img=$pdo->prepare("SELECT image FROM banners WHERE id=?"); $img->execute([$bid]); $fn=$img->fetchColumn();
        if($fn&&file_exists(UPLOAD_DIR.$fn))@unlink(UPLOAD_DIR.$fn);
        $pdo->prepare("DELETE FROM banners WHERE id=?")->execute([$bid]);
        flash('success','Banner deleted.'); header('Location: ads.php?tab=fallback'); exit;
    }
    if ($action === 'toggle_fallback') {
        $bid=(int)$_POST['bid']; $s=$_POST['bstatus']==='active'?'inactive':'active';
        $pdo->prepare("UPDATE banners SET status=? WHERE id=?")->execute([$s,$bid]);
        flash('success','Banner '.($s==='active'?'activated':'deactivated').'.');
        header('Location: ads.php?tab=fallback'); exit;
    }
}

// Auto-status engine
try {
    $pdo->query("UPDATE banner_ads ba SET ba.status='running' WHERE ba.status='approved' AND ba.start_date<=CURDATE() AND ba.end_date>=CURDATE() AND (SELECT COUNT(*) FROM ad_payments WHERE ad_id=ba.id AND status='paid')>0");
    $pdo->query("UPDATE banner_ads SET status='completed' WHERE status IN('running','approved') AND end_date<CURDATE()");
} catch(Exception $e){}

// Data
$search=$_GET['search']??''; $statusFilter=$_GET['status']??'';
$page=max(1,(int)($_GET['page']??1)); $perPage=15; $offset=($page-1)*$perPage;
$bWhere="WHERE 1=1"; $bParams=[];
if($statusFilter){$bWhere.=" AND ba.status=?";$bParams[]=$statusFilter;}
if($search){$bWhere.=" AND (u.company LIKE ? OR u.name LIKE ? OR ba.title LIKE ?)";$t="%$search%";$bParams=array_merge($bParams,[$t,$t,$t]);}
$bTotal=$pdo->prepare("SELECT COUNT(*) FROM banner_ads ba JOIN users u ON u.id=ba.vendor_id $bWhere");
$bTotal->execute($bParams); $bTotal=$bTotal->fetchColumn();
$bParams[]=$perPage; $bParams[]=$offset;
$bookings=$pdo->prepare("SELECT ba.*,u.name AS vendor_name,u.company,u.email AS vendor_email,p.name AS package_name,p.duration_days,p.price AS package_price,s.name AS slot_name,s.start_time,s.end_time,(SELECT status FROM ad_payments WHERE ad_id=ba.id ORDER BY created_at DESC LIMIT 1) AS payment_status,(SELECT payment_ref FROM ad_payments WHERE ad_id=ba.id AND status='paid' LIMIT 1) AS payment_ref FROM banner_ads ba JOIN users u ON u.id=ba.vendor_id JOIN ad_packages p ON p.id=ba.package_id JOIN ad_slots s ON s.id=ba.slot_id $bWhere ORDER BY ba.created_at DESC LIMIT ? OFFSET ?");
$bookings->execute($bParams); $bookings=$bookings->fetchAll();
$packages=$pdo->query("SELECT * FROM ad_packages ORDER BY sort_order,id")->fetchAll();
$slots=$pdo->query("SELECT s.*,(SELECT COUNT(*) FROM banner_ads WHERE slot_id=s.id AND status IN('running','approved')) AS active_ads FROM ad_slots s ORDER BY sort_order,start_time")->fetchAll();
$payments=$pdo->query("SELECT ap.*,u.name AS vendor_name,u.company,p.name AS package_name,ba.title AS ad_title,ba.start_date,ba.end_date FROM ad_payments ap JOIN users u ON u.id=ap.vendor_id JOIN ad_packages p ON p.id=ap.package_id JOIN banner_ads ba ON ba.id=ap.ad_id ORDER BY ap.created_at DESC LIMIT 100")->fetchAll();
$stats=['total'=>$pdo->query("SELECT COUNT(*) FROM banner_ads")->fetchColumn(),'running'=>$pdo->query("SELECT COUNT(*) FROM banner_ads WHERE status='running'")->fetchColumn(),'pending'=>$pdo->query("SELECT COUNT(*) FROM banner_ads WHERE status='pending'")->fetchColumn(),'revenue'=>$pdo->query("SELECT COALESCE(SUM(amount),0) FROM ad_payments WHERE status='paid'")->fetchColumn()];
// Fallback banners (admin-managed, shown when no vendor ads are running)
try { $fallbackBanners=$pdo->query("SELECT * FROM banners ORDER BY sort_order ASC,id ASC")->fetchAll(); } catch(Exception $e){ $fallbackBanners=[]; }
$runningCount=(int)$pdo->query("SELECT COUNT(*) FROM banner_ads WHERE status='running'")->fetchColumn();

$pageTitle='Ad Management'; $activePage='ads';
include __DIR__ . '/../includes/head.php';
?>
<style>
/* ── AD MANAGEMENT — premium UI overrides ──────────────────── */
.ads-topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:14px}
.ads-topbar h1{font-size:22px;font-weight:800;letter-spacing:-.5px;position:relative;padding-bottom:10px}
.ads-topbar h1::after{content:'';position:absolute;bottom:0;left:0;width:36px;height:3px;background:linear-gradient(90deg,var(--crimson),var(--gold-dark));border-radius:2px}
.stats-grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
/* Premium tabs */
.ads-tabs{display:flex;gap:0;margin-bottom:24px;background:var(--cream-light);border-radius:12px;padding:4px;border:1px solid var(--border-light)}
.ads-tab{flex:1;text-align:center;padding:10px 8px;font-size:13px;font-weight:600;border-radius:9px;text-decoration:none;color:var(--text-muted);transition:var(--transition);white-space:nowrap}
.ads-tab.active{background:var(--crimson);color:#fff;box-shadow:0 2px 8px rgba(139,36,29,.25)}
.ads-tab:hover:not(.active){background:var(--cream);color:var(--crimson)}
/* Booking card grid for mobile */
.booking-card{background:var(--white);border:1px solid var(--border-light);border-radius:var(--radius-lg);padding:18px;transition:var(--transition);position:relative;overflow:hidden}
.booking-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--crimson),var(--crimson-mid))}
.booking-card.running::before{background:linear-gradient(90deg,#16a34a,#34d399)}
.booking-card.pending::before{background:linear-gradient(90deg,#d97706,#fbbf24)}
.booking-card.rejected::before{background:linear-gradient(90deg,#dc2626,#f87171)}
.booking-card.completed::before{background:linear-gradient(90deg,#64748b,#94a3b8)}
.booking-card:hover{box-shadow:var(--shadow-md)}
.banner-thumb{width:100%;aspect-ratio:8/3;object-fit:cover;border-radius:8px;border:1px solid var(--border-light);margin-bottom:12px}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:100px;font-size:11px;font-weight:700;letter-spacing:.02em}
.status-pill::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;opacity:.8}
.sp-running{background:#dcfce7;color:#16a34a}
.sp-pending{background:#fef3c7;color:#d97706}
.sp-approved{background:#dbeafe;color:#2563eb}
.sp-rejected{background:#fee2e2;color:#dc2626}
.sp-paused{background:#ede9fe;color:#7c3aed}
.sp-completed{background:#f1f5f9;color:#64748b}
.sp-cancelled{background:#fee2e2;color:#dc2626}
.sp-paid{background:#dcfce7;color:#16a34a}
.sp-failed{background:#fee2e2;color:#dc2626}
.sp-refunded{background:#ede9fe;color:#7c3aed}
/* Package cards */
.pkg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px}
.pkg-card{background:var(--white);border:1px solid var(--border-light);border-radius:var(--radius-lg);padding:22px;position:relative;transition:var(--transition)}
.pkg-card:hover{box-shadow:var(--shadow-md);border-color:var(--crimson-light)}
.pkg-price{font-family:'Poppins',sans-serif;font-size:28px;font-weight:800;color:var(--crimson);line-height:1}
.pkg-days{font-size:12.5px;color:var(--text-muted);margin-top:2px}
/* Slot cards */
.slot-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
.slot-card{background:var(--white);border:1px solid var(--border-light);border-radius:var(--radius-lg);padding:18px 20px;position:relative;transition:var(--transition)}
.slot-card:hover{box-shadow:var(--shadow-md)}
.slot-time{font-family:'Poppins',sans-serif;font-size:15px;font-weight:700;color:var(--crimson);letter-spacing:.03em}
.slot-fill{height:6px;border-radius:3px;background:var(--border-light);margin:12px 0;overflow:hidden}
.slot-fill-bar{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--crimson),var(--crimson-mid));transition:.4s ease}
/* Fallback banner section */
.fb-banner-card{background:var(--white);border:1px solid var(--border-light);border-radius:var(--radius-lg);overflow:hidden;transition:var(--transition)}
.fb-banner-card:hover{box-shadow:var(--shadow-md)}
.fb-thumb{width:100%;aspect-ratio:8/3;object-fit:cover}
.fb-actions{padding:14px 16px;display:flex;align-items:center;gap:10px;border-top:1px solid var(--border-light)}
/* Filter bar */
.filter-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:18px}
.filter-row .form-control{height:38px;font-size:13.5px}
/* Info alert */
.info-strip{background:linear-gradient(135deg,var(--gold-light),var(--cream-light));border:1px solid var(--gold-dark);border-radius:10px;padding:13px 16px;font-size:13px;color:var(--crimson-deep);margin-bottom:20px;display:flex;align-items:flex-start;gap:10px}
/* Responsive */
@media(max-width:1100px){.stats-grid-4{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){
  .stats-grid-4{grid-template-columns:repeat(2,1fr)}
  .ads-tabs{flex-wrap:wrap;gap:4px}
  .ads-tab{flex:none;width:calc(50% - 2px)}
  .pkg-grid{grid-template-columns:1fr}
  .slot-grid{grid-template-columns:1fr}
  .content{padding:16px}
}
@media(max-width:480px){
  .stats-grid-4{grid-template-columns:1fr 1fr}
  .ads-tab{font-size:12px;padding:8px 4px}
}
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Ad Management</h1>
  </div>
  <div class="topbar-right">
    <a href="ads.php?tab=fallback" class="btn btn-outline btn-sm">🖼️ Fallback Banners</a>
  </div>
</div>
<div class="content">
  <?= showFlash() ?>

  <!-- Stats -->
  <div class="stats-grid-4">
    <div class="stat-card indigo">
      <div class="stat-header"><div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:6px">Total Bookings</div><div style="font-size:28px;font-weight:800;color:var(--crimson)"><?= number_format($stats['total']) ?></div></div><div class="stat-icon indigo">🎯</div></div>
    </div>
    <div class="stat-card green">
      <div class="stat-header"><div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:6px">Running Now</div><div style="font-size:28px;font-weight:800;color:var(--success)"><?= $stats['running'] ?></div></div><div class="stat-icon green">📡</div></div>
    </div>
    <div class="stat-card amber">
      <div class="stat-header"><div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:6px">Awaiting Approval</div><div style="font-size:28px;font-weight:800;color:var(--gold-dark)"><?= $stats['pending'] ?></div></div><div class="stat-icon amber">⏳</div></div>
    </div>
    <div class="stat-card purple">
      <div class="stat-header"><div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:6px">Ad Revenue</div><div style="font-size:24px;font-weight:800;color:var(--crimson)">₹<?= number_format($stats['revenue'],0) ?></div></div><div class="stat-icon purple">💰</div></div>
    </div>
  </div>

  <!-- Tab navigation -->
  <div class="ads-tabs">
    <?php foreach(['bookings'=>'📋 Bookings','packages'=>'💳 Packages','slots'=>'⏰ Slots','payments'=>'💰 Payments','fallback'=>'🖼️ Fallback Banners'] as $t=>$l): ?>
    <a href="?tab=<?=$t?>" class="ads-tab <?=$tab===$t?'active':''?>"><?=$l?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'bookings'): ?>
  <!-- Filter -->
  <div class="filter-row">
    <form method="GET" style="display:contents">
      <input type="hidden" name="tab" value="bookings">
      <input type="text" name="search" value="<?=sanitize($search)?>" class="form-control" style="max-width:220px" placeholder="Search vendor, title…">
      <select name="status" class="form-control" style="max-width:150px">
        <option value="">All Statuses</option>
        <?php foreach(['pending','approved','running','paused','rejected','completed','cancelled'] as $s): ?><option value="<?=$s?>" <?=$statusFilter===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-sm" type="submit">Filter</button>
      <a href="?tab=bookings" class="btn btn-outline btn-sm">Clear</a>
    </form>
  </div>
  <?php if (!$bookings): ?>
    <div class="empty-state"><div class="empty-state-icon">📭</div><h3>No ad bookings yet</h3><p>When vendors book banner ads, they'll appear here.</p></div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">
    <?php foreach($bookings as $b):
      $sp='sp-'.($b['status']??'pending');
      $ps=$b['payment_status']??'pending'; $psp='sp-'.$ps;
    ?>
    <div class="booking-card <?=sanitize($b['status'])?>">
      <img src="<?=UPLOAD_URL.sanitize($b['image'])?>" alt="" class="banner-thumb">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px;gap:8px">
        <div>
          <div style="font-weight:700;font-size:13.5px"><?=sanitize($b['company']?:$b['vendor_name'])?></div>
          <div style="font-size:11.5px;color:var(--text-muted)"><?=sanitize($b['vendor_email'])?></div>
        </div>
        <span class="status-pill <?=$sp?>"><?=ucfirst($b['status'])?></span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px;margin-bottom:12px">
        <div><div style="color:var(--text-muted);font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px">Slot</div><strong><?=sanitize($b['slot_name'])?></strong><div style="color:var(--text-muted)"><?=substr($b['start_time'],0,5)?>–<?=substr($b['end_time'],0,5)?></div></div>
        <div><div style="color:var(--text-muted);font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px">Package</div><strong><?=sanitize($b['package_name'])?></strong><div style="color:var(--crimson);font-weight:700">₹<?=number_format($b['package_price'],0)?></div></div>
        <div><div style="color:var(--text-muted);font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px">Duration</div><?=date('d M',strtotime($b['start_date']))?> → <?=date('d M Y',strtotime($b['end_date']))?></div>
        <div><div style="color:var(--text-muted);font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px">Payment</div><span class="status-pill <?=$psp?>"><?=ucfirst($ps)?></span><?php if($b['payment_ref']): ?><div style="font-size:10px;color:var(--text-muted);margin-top:2px">Ref: <?=sanitize($b['payment_ref'])?></div><?php endif; ?></div>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-primary btn-sm" style="flex:1" onclick='openBookingModal(<?=htmlspecialchars(json_encode($b),ENT_QUOTES)?>)'>✏️ Edit</button>
        <a href="<?=UPLOAD_URL.sanitize($b['image'])?>" target="_blank" class="btn btn-outline btn-sm">🖼️</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($bTotal > $perPage): ?><div style="margin-top:20px"><?=paginate($bTotal,$perPage,$page,'?tab=bookings&search='.urlencode($search).'&status='.urlencode($statusFilter))?></div><?php endif; ?>
  <?php endif; ?>

  <?php elseif ($tab === 'packages'): ?>
  <div style="display:flex;justify-content:flex-end;margin-bottom:16px"><button class="btn btn-primary btn-sm" onclick="openPackageModal()">+ New Package</button></div>
  <div class="pkg-grid">
    <?php foreach($packages as $p): ?>
    <div class="pkg-card">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px">
        <div>
          <div class="pkg-price">₹<?=number_format($p['price'],0)?></div>
          <div class="pkg-days"><?=$p['duration_days']?> days · max <?=$p['max_slots']?> slot<?=$p['max_slots']>1?'s':''?></div>
        </div>
        <?=$p['is_active']?'<span class="status-pill sp-running">Active</span>':'<span class="status-pill sp-completed">Inactive</span>'?>
      </div>
      <div style="font-weight:700;font-size:14.5px;margin-bottom:4px"><?=sanitize($p['name'])?></div>
      <div style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;line-height:1.5"><?=sanitize($p['description'])?:'-'?></div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-outline btn-sm" style="flex:1" onclick='openPackageModal(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'>✏️ Edit</button>
        <form method="POST" onsubmit="return confirm('Delete this package?')">
          <input type="hidden" name="csrf_token" value="<?=csrfToken()?>">
          <input type="hidden" name="action" value="delete_package">
          <input type="hidden" name="id" value="<?=$p['id']?>">
          <button type="submit" class="btn btn-danger btn-sm">🗑</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="pkg-card" style="border-style:dashed;display:flex;align-items:center;justify-content:center;min-height:160px;cursor:pointer" onclick="openPackageModal()">
      <div style="text-align:center;color:var(--text-muted)"><div style="font-size:28px;margin-bottom:6px">+</div><div style="font-size:13px;font-weight:600">New Package</div></div>
    </div>
  </div>

  <?php elseif ($tab === 'slots'): ?>
  <div style="display:flex;justify-content:flex-end;margin-bottom:16px"><button class="btn btn-primary btn-sm" onclick="openSlotModal()">+ New Time Slot</button></div>
  <div class="slot-grid">
    <?php foreach($slots as $s):
      $fillPct=min(100,$s['max_concurrent']>0?round($s['active_ads']/$s['max_concurrent']*100):0);
      $isFull=$s['active_ads']>=$s['max_concurrent'];
    ?>
    <div class="slot-card">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:6px">
        <div>
          <div style="font-weight:700;font-size:14px;margin-bottom:2px"><?=sanitize($s['name'])?></div>
          <div class="slot-time"><?=substr($s['start_time'],0,5)?> – <?=substr($s['end_time'],0,5)?></div>
        </div>
        <?=$s['is_active']?'<span class="status-pill sp-running">Active</span>':'<span class="status-pill sp-completed">Off</span>'?>
      </div>
      <?php if($s['description']): ?><div style="font-size:12px;color:var(--text-muted);margin-top:4px"><?=sanitize($s['description'])?></div><?php endif; ?>
      <div class="slot-fill"><div class="slot-fill-bar" style="width:<?=$fillPct?>%;<?=$isFull?'background:linear-gradient(90deg,#dc2626,#f87171)':''?>"></div></div>
      <div style="font-size:12px;color:<?=$isFull?'#dc2626':'var(--success)'?>;font-weight:600;margin-bottom:14px">
        <?=$s['active_ads']?>/<?=$s['max_concurrent']?> ads running<?=$isFull?' · FULL':''?>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-outline btn-sm" style="flex:1" onclick='openSlotModal(<?=htmlspecialchars(json_encode($s),ENT_QUOTES)?>)'>✏️ Edit</button>
        <form method="POST" onsubmit="return confirm('Delete this slot?')">
          <input type="hidden" name="csrf_token" value="<?=csrfToken()?>">
          <input type="hidden" name="action" value="delete_slot">
          <input type="hidden" name="id" value="<?=$s['id']?>">
          <button type="submit" class="btn btn-danger btn-sm">🗑</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="slot-card" style="border-style:dashed;display:flex;align-items:center;justify-content:center;min-height:160px;cursor:pointer" onclick="openSlotModal()">
      <div style="text-align:center;color:var(--text-muted)"><div style="font-size:28px;margin-bottom:6px">+</div><div style="font-size:13px;font-weight:600">New Slot</div></div>
    </div>
  </div>

  <?php elseif ($tab === 'payments'): ?>
  <div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Vendor</th><th>Package</th><th>Amount</th><th>Ref</th><th>Status</th><th>Date</th><th></th></tr></thead>
        <tbody>
        <?php if(!$payments): ?><tr><td colspan="7"><div class="empty-state"><div class="empty-state-icon">💰</div><p>No payments yet.</p></div></td></tr><?php endif; ?>
        <?php foreach($payments as $p):
          $pc=['paid'=>'sp-paid','failed'=>'sp-failed','refunded'=>'sp-refunded','pending'=>'sp-pending'][$p['status']]??'sp-pending';
        ?>
        <tr>
          <td><div style="font-weight:600;font-size:13px"><?=sanitize($p['company']?:$p['vendor_name'])?></div><div style="font-size:11px;color:var(--text-muted)"><?=sanitize($p['ad_title']?:'—')?></div></td>
          <td style="font-size:13px"><?=sanitize($p['package_name'])?></td>
          <td style="font-weight:700;color:var(--crimson)">₹<?=number_format($p['amount'],0)?></td>
          <td style="font-size:12px;max-width:120px;overflow:hidden;text-overflow:ellipsis"><?=sanitize($p['payment_ref']?:'—')?></td>
          <td><span class="status-pill <?=$pc?>"><?=ucfirst($p['status'])?></span></td>
          <td style="font-size:12px"><?=date('d M Y',strtotime($p['paid_at']??$p['created_at']))?></td>
          <td><button class="btn btn-outline btn-xs" onclick='openPaymentModal(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'>✏️</button></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php elseif ($tab === 'fallback'): ?>
  <!-- Fallback Banners: shown on homepage when no vendor ads are running -->
  <?php if ($runningCount > 0): ?>
  <div class="info-strip">
    <span style="font-size:18px;flex-shrink:0">ℹ️</span>
    <div><strong><?=$runningCount?> vendor ad(s) are currently running</strong> — fallback banners won't show on the homepage right now. They'll automatically display when no paid ads are active.</div>
  </div>
  <?php endif; ?>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <div><h2 style="font-size:16px;font-weight:700;margin:0">Fallback Banners</h2><p style="font-size:12.5px;color:var(--text-muted);margin-top:2px">These display in the hero carousel when no vendor ads are running. Recommended: 1600×600px.</p></div>
    <button class="btn btn-primary btn-sm" onclick="openFbModal()">+ Add Banner</button>
  </div>
  <?php if (!$fallbackBanners): ?>
    <div class="empty-state"><div class="empty-state-icon">🖼️</div><h3>No fallback banners yet</h3><p>Add banners to show when no vendor ads are active.</p></div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px">
    <?php foreach($fallbackBanners as $fb): ?>
    <div class="fb-banner-card">
      <div style="position:relative">
        <img src="<?=UPLOAD_URL.sanitize($fb['image'])?>" class="fb-thumb" alt="">
        <span class="status-pill <?=$fb['status']==='active'?'sp-running':'sp-completed'?>" style="position:absolute;top:8px;right:8px"><?=ucfirst($fb['status'])?></span>
      </div>
      <?php if($fb['title']||$fb['subtitle']): ?>
      <div style="padding:10px 14px 0">
        <?php if($fb['title']): ?><div style="font-weight:700;font-size:13px"><?=sanitize($fb['title'])?></div><?php endif; ?>
        <?php if($fb['subtitle']): ?><div style="font-size:12px;color:var(--text-muted)"><?=sanitize($fb['subtitle'])?></div><?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="fb-actions">
        <button class="btn btn-outline btn-xs" style="flex:1" onclick='openFbModal(<?=htmlspecialchars(json_encode($fb),ENT_QUOTES)?>)'>✏️ Edit</button>
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?=csrfToken()?>">
          <input type="hidden" name="action" value="toggle_fallback">
          <input type="hidden" name="bid" value="<?=$fb['id']?>">
          <input type="hidden" name="bstatus" value="<?=$fb['status']?>">
          <button type="submit" class="btn btn-outline btn-xs"><?=$fb['status']==='active'?'⏸ Hide':'▶ Show'?></button>
        </form>
        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this banner permanently?')">
          <input type="hidden" name="csrf_token" value="<?=csrfToken()?>">
          <input type="hidden" name="action" value="delete_fallback_banner">
          <input type="hidden" name="bid" value="<?=$fb['id']?>">
          <button type="submit" class="btn btn-danger btn-xs">🗑</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Booking Edit Modal -->
<div id="booking-modal" class="modal-backdrop">
  <div class="modal" style="max-width:500px">
    <div class="modal-header"><h3>Edit Ad Booking</h3><button class="modal-close" onclick="closeModal('booking-modal')">✕</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?=csrfToken()?>">
      <input type="hidden" name="action" value="booking_status">
      <input type="hidden" name="id" id="bm-id">
      <div id="bm-info" style="font-size:12.5px;color:var(--text-muted);background:var(--cream-light);border-radius:8px;padding:10px 12px;margin-bottom:14px"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" id="bm-status" class="form-control">
            <?php foreach(['pending','approved','rejected','running','paused','completed','cancelled'] as $s): ?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Sort Order <span style="font-size:10.5px;color:var(--text-muted)">(0 = first)</span></label>
          <input type="number" name="sort_order" id="bm-order" class="form-control" value="0" min="0" max="99">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Admin Note <span style="font-size:11px;color:var(--text-muted)">(visible to vendor on rejection)</span></label>
        <textarea name="admin_note" id="bm-note" class="form-control" rows="3" placeholder="Optional note…"></textarea>
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1">Save Changes</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('booking-modal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Package Modal -->
<div id="package-modal" class="modal-backdrop">
  <div class="modal" style="max-width:460px">
    <div class="modal-header"><h3 id="pm-title">New Package</h3><button class="modal-close" onclick="closeModal('package-modal')">✕</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?=csrfToken()?>">
      <input type="hidden" name="action" value="save_package">
      <input type="hidden" name="id" id="pm-id" value="">
      <div class="form-group"><label class="form-label">Name <span class="req">*</span></label><input type="text" name="name" id="pm-name" class="form-control" maxlength="100" required></div>
      <div class="form-group"><label class="form-label">Description</label><textarea name="description" id="pm-desc" class="form-control" rows="2"></textarea></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">Duration (days) <span class="req">*</span></label><input type="number" name="duration_days" id="pm-days" class="form-control" min="1" required></div>
        <div class="form-group"><label class="form-label">Price (₹) <span class="req">*</span></label><input type="number" name="price" id="pm-price" class="form-control" min="0" step="0.01" required></div>
        <div class="form-group"><label class="form-label">Max Slots</label><input type="number" name="max_slots" id="pm-slots" class="form-control" min="1"></div>
        <div class="form-group"><label class="form-label">Sort Order</label><input type="number" name="sort_order" id="pm-order" class="form-control"></div>
      </div>
      <div class="form-group"><label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13.5px"><input type="checkbox" name="is_active" id="pm-active" value="1"> Active (visible to vendors)</label></div>
      <button type="submit" class="btn btn-primary btn-full">Save Package</button>
    </form>
  </div>
</div>

<!-- Slot Modal -->
<div id="slot-modal" class="modal-backdrop">
  <div class="modal" style="max-width:440px">
    <div class="modal-header"><h3 id="sm-title">New Time Slot</h3><button class="modal-close" onclick="closeModal('slot-modal')">✕</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?=csrfToken()?>">
      <input type="hidden" name="action" value="save_slot">
      <input type="hidden" name="id" id="sm-id" value="">
      <div class="form-group"><label class="form-label">Slot Name <span class="req">*</span></label><input type="text" name="name" id="sm-name" class="form-control" maxlength="100" required></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">Start Time <span class="req">*</span></label><input type="time" name="start_time" id="sm-start" class="form-control" required></div>
        <div class="form-group"><label class="form-label">End Time <span class="req">*</span></label><input type="time" name="end_time" id="sm-end" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Max Concurrent Ads</label><input type="number" name="max_concurrent" id="sm-max" class="form-control" min="1"></div>
        <div class="form-group"><label class="form-label">Sort Order</label><input type="number" name="sort_order" id="sm-order" class="form-control"></div>
      </div>
      <div class="form-group"><label class="form-label">Description</label><input type="text" name="description" id="sm-desc" class="form-control" maxlength="255"></div>
      <div class="form-group"><label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13.5px"><input type="checkbox" name="is_active" id="sm-active" value="1"> Active</label></div>
      <button type="submit" class="btn btn-primary btn-full">Save Slot</button>
    </form>
  </div>
</div>

<!-- Payment Modal -->
<div id="payment-modal" class="modal-backdrop">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><h3>Update Payment</h3><button class="modal-close" onclick="closeModal('payment-modal')">✕</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?=csrfToken()?>">
      <input type="hidden" name="action" value="payment_status">
      <input type="hidden" name="id" id="paym-id">
      <div id="paym-info" style="font-size:13px;color:var(--text-muted);background:var(--cream-light);border-radius:8px;padding:10px 12px;margin-bottom:14px"></div>
      <div class="form-group"><label class="form-label">Status</label><select name="status" id="paym-status" class="form-control"><option value="pending">Pending</option><option value="paid">Paid ✅</option><option value="failed">Failed</option><option value="refunded">Refunded</option></select></div>
      <div class="form-group"><label class="form-label">Payment Reference / UTR</label><input type="text" name="payment_ref" id="paym-ref" class="form-control" placeholder="UTR / Transaction ID…"></div>
      <p style="font-size:11.5px;color:var(--text-muted);margin-bottom:14px">Marking as <strong>Paid</strong> auto-activates the booking if the start date has arrived.</p>
      <button type="submit" class="btn btn-primary btn-full">Update Payment</button>
    </form>
  </div>
</div>

<!-- Fallback Banner Modal -->
<div id="fb-modal" class="modal-backdrop">
  <div class="modal" style="max-width:500px">
    <div class="modal-header"><h3 id="fb-modal-title">Add Fallback Banner</h3><button class="modal-close" onclick="closeModal('fb-modal')">✕</button></div>
    <form method="POST" enctype="multipart/form-data" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?=csrfToken()?>">
      <input type="hidden" name="action" value="save_fallback_banner">
      <input type="hidden" name="bid" id="fb-id" value="">
      <div class="form-group"><label class="form-label">Banner Image <span id="fb-req" class="req">*</span></label><input type="file" name="bimage" id="fb-img" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp"><div id="fb-preview" style="margin-top:8px"></div><p style="font-size:11.5px;color:var(--text-muted);margin-top:4px">Recommended: 1600×600px · JPG/PNG/WebP · max 5MB. Leave blank when editing to keep current image.</p></div>
      <div class="form-group"><label class="form-label">Title (optional overlay)</label><input type="text" name="title" id="fb-title" class="form-control" maxlength="150" placeholder="e.g. Premium Kraft Paper"></div>
      <div class="form-group"><label class="form-label">Subtitle (optional)</label><input type="text" name="subtitle" id="fb-subtitle" class="form-control" maxlength="255"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">Button Text</label><input type="text" name="button_text" id="fb-btn" class="form-control" maxlength="60" placeholder="e.g. Shop Now"></div>
        <div class="form-group"><label class="form-label">Link URL</label><input type="text" name="link_url" id="fb-link" class="form-control" placeholder="/public/products.php"></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">Sort Order</label><input type="number" name="bsort" id="fb-sort" class="form-control" value="0"></div>
        <div class="form-group"><label class="form-label">Status</label><select name="bstatus" id="fb-status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      </div>
      <button type="submit" class="btn btn-primary btn-full">Save Banner</button>
    </form>
  </div>
</div>

<script>
function closeModal(id){document.getElementById(id).classList.remove('open')}
function openModal(id){document.getElementById(id).classList.add('open')}
['booking-modal','package-modal','slot-modal','payment-modal','fb-modal'].forEach(id=>{
  document.getElementById(id).addEventListener('click',function(e){if(e.target===this)closeModal(id)});
});
function openBookingModal(b){
  document.getElementById('bm-id').value=b.id;
  document.getElementById('bm-status').value=b.status;
  document.getElementById('bm-note').value=b.admin_note||'';
  document.getElementById('bm-order').value=b.sort_order||0;
  document.getElementById('bm-info').innerHTML='<strong>'+(b.company||b.vendor_name)+'</strong> &middot; '+b.package_name+' &middot; '+b.slot_name+'<br><span style="font-size:11px">'+b.start_date+' → '+b.end_date+'</span>';
  openModal('booking-modal');
}
function openPackageModal(p){
  const n=!p;
  document.getElementById('pm-title').textContent=n?'New Package':'Edit Package';
  document.getElementById('pm-id').value=n?'':(p.id||'');
  document.getElementById('pm-name').value=n?'':(p.name||'');
  document.getElementById('pm-desc').value=n?'':(p.description||'');
  document.getElementById('pm-days').value=n?7:(p.duration_days||7);
  document.getElementById('pm-price').value=n?'':(p.price||'');
  document.getElementById('pm-slots').value=n?1:(p.max_slots||1);
  document.getElementById('pm-order').value=n?0:(p.sort_order||0);
  document.getElementById('pm-active').checked=n?true:(p.is_active==1);
  openModal('package-modal');
}
function openSlotModal(s){
  const n=!s;
  document.getElementById('sm-title').textContent=n?'New Time Slot':'Edit Slot';
  document.getElementById('sm-id').value=n?'':(s.id||'');
  document.getElementById('sm-name').value=n?'':(s.name||'');
  document.getElementById('sm-start').value=n?'':(s.start_time||'').substring(0,5);
  document.getElementById('sm-end').value=n?'':(s.end_time||'').substring(0,5);
  document.getElementById('sm-max').value=n?3:(s.max_concurrent||3);
  document.getElementById('sm-order').value=n?0:(s.sort_order||0);
  document.getElementById('sm-desc').value=n?'':(s.description||'');
  document.getElementById('sm-active').checked=n?true:(s.is_active==1);
  openModal('slot-modal');
}
function openPaymentModal(p){
  document.getElementById('paym-id').value=p.id;
  document.getElementById('paym-status').value=p.status;
  document.getElementById('paym-ref').value=p.payment_ref||'';
  document.getElementById('paym-info').innerHTML='<strong>'+(p.company||p.vendor_name)+'</strong> &middot; '+p.package_name+' &middot; <strong style="color:var(--crimson)">₹'+parseFloat(p.amount).toFixed(0)+'</strong>';
  openModal('payment-modal');
}
function openFbModal(b){
  const n=!b;
  document.getElementById('fb-modal-title').textContent=n?'Add Fallback Banner':'Edit Fallback Banner';
  document.getElementById('fb-id').value=n?'':(b.id||'');
  document.getElementById('fb-title').value=n?'':(b.title||'');
  document.getElementById('fb-subtitle').value=n?'':(b.subtitle||'');
  document.getElementById('fb-btn').value=n?'':(b.button_text||'');
  document.getElementById('fb-link').value=n?'':(b.link_url||'');
  document.getElementById('fb-sort').value=n?0:(b.sort_order||0);
  document.getElementById('fb-status').value=n?'active':(b.status||'active');
  document.getElementById('fb-req').style.display=n?'inline':'none';
  document.getElementById('fb-preview').innerHTML=n?'':`<img src="<?=UPLOAD_URL?>${b.image}" style="width:100%;aspect-ratio:8/3;object-fit:cover;border-radius:6px;border:1px solid var(--border-light)">`;
  openModal('fb-modal');
}
</script>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?=BASE_URL?>/assets/script.js"></script>
</div></div></body></html>
