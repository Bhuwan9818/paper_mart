<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

$vid = (int)($_GET['id'] ?? 0);
if (!$vid) { flash('error','Invalid vendor.'); header('Location: vendors.php'); exit; }

/* ── Load vendor data ──────────────────────────────────── */
$u = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='vendor'"); $u->execute([$vid]); $u = $u->fetch();
if (!$u) { flash('error','Vendor not found.'); header('Location: vendors.php'); exit; }
$vp = $pdo->prepare("SELECT * FROM vendor_profiles WHERE vendor_id=?"); $vp->execute([$vid]); $vp = $vp->fetch() ?: [];

/* ── Stats ─────────────────────────────────────────────── */
$stats = $pdo->prepare("SELECT
    (SELECT COUNT(*) FROM products WHERE vendor_id=? AND status='active')   AS active_products,
    (SELECT COUNT(*) FROM products WHERE vendor_id=?)                        AS total_products,
    (SELECT COUNT(*) FROM enquiries WHERE vendor_id=?)                       AS total_enquiries,
    (SELECT COUNT(*) FROM enquiries WHERE vendor_id=? AND status='new')      AS new_enquiries,
    (SELECT SUM(views) FROM products WHERE vendor_id=?)                      AS total_views
");
$stats->execute([$vid,$vid,$vid,$vid,$vid]);
$stats = $stats->fetch();

/* ── Recent products ───────────────────────────────────── */
$recentProds = $pdo->prepare("SELECT p.*,c.name AS cname FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.vendor_id=? ORDER BY p.created_at DESC LIMIT 6");
$recentProds->execute([$vid]); $recentProds = $recentProds->fetchAll();

/* ── POST handler ──────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'business';

    if ($action === 'account') {
        $name    = trim($_POST['name']       ?? '');
        $phone   = trim($_POST['phone']      ?? '');
        $company = trim($_POST['company']    ?? '');
        $gst     = trim($_POST['gst_number'] ?? '');
        $address = trim($_POST['address']    ?? '');
        $city    = trim($_POST['city']       ?? '');
        $state   = trim($_POST['state']      ?? '');
        $status  = in_array($_POST['status']??'',['active','inactive','pending']) ? $_POST['status'] : 'active';

        if (!empty($_POST['new_password'])) {
            if (strlen($_POST['new_password']) < 6) {
                flash('error','Password must be at least 6 characters.');
                header("Location: vendor-profile.php?id=$vid#account"); exit;
            }
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($_POST['new_password'],PASSWORD_DEFAULT),$vid]);
        }
        $pdo->prepare("UPDATE users SET name=?,phone=?,company=?,gst_number=?,address=?,city=?,state=?,status=?,updated_at=NOW() WHERE id=?")
            ->execute([$name,$phone,$company,$gst,$address,$city,$state,$status,$vid]);
        flash('success','Account updated.');
        header("Location: vendor-profile.php?id=$vid#account"); exit;
    }

    /* Business + verification */
    $tagline     = trim($_POST['tagline']         ?? '');
    $estYr       = (int)($_POST['established_yr'] ?? 0) ?: null;
    $employees   = trim($_POST['employees']        ?? '');
    $turnover    = trim($_POST['annual_turnover']  ?? '');
    $certs       = trim($_POST['certifications']   ?? '');
    $website     = trim($_POST['website']          ?? '');
    $linkedin    = trim($_POST['linkedin']         ?? '');
    $facebook    = trim($_POST['facebook']         ?? '');
    $isVerified  = isset($_POST['is_verified']) ? 1 : 0;
    $rating      = min(5, max(0, (float)($_POST['rating'] ?? 0)));

    $logo   = $vp['logo']   ?? null;
    $banner = $vp['banner'] ?? null;

    if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error']===UPLOAD_ERR_OK) {
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR,0755,true);
        $fn = uploadImage($_FILES['logo'],'logo');
        if ($fn) $logo=$fn; else flash('error','Logo upload failed.');
    }
    if (!empty($_FILES['banner']['tmp_name']) && $_FILES['banner']['error']===UPLOAD_ERR_OK) {
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR,0755,true);
        $fn = uploadImage($_FILES['banner'],'banner');
        if ($fn) $banner=$fn; else flash('error','Banner upload failed.');
    }

    $exists = $pdo->prepare("SELECT vendor_id FROM vendor_profiles WHERE vendor_id=?"); $exists->execute([$vid]);
    if ($exists->fetch()) {
        $pdo->prepare("UPDATE vendor_profiles SET tagline=?,established_yr=?,employees=?,annual_turnover=?,certifications=?,logo=?,banner=?,website=?,linkedin=?,facebook=?,is_verified=?,rating=? WHERE vendor_id=?")
            ->execute([$tagline,$estYr,$employees,$turnover,$certs,$logo,$banner,$website,$linkedin,$facebook,$isVerified,$rating,$vid]);
    } else {
        $pdo->prepare("INSERT INTO vendor_profiles (vendor_id,tagline,established_yr,employees,annual_turnover,certifications,logo,banner,website,linkedin,facebook,is_verified,rating) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$vid,$tagline,$estYr,$employees,$turnover,$certs,$logo,$banner,$website,$linkedin,$facebook,$isVerified,$rating]);
    }

    /* Notify vendor if verified status changed */
    if ($isVerified && !($vp['is_verified']??0)) {
        try {
            $pdo->prepare("INSERT INTO notifications (user_id,title,message,link) VALUES(?,?,?,?)")
                ->execute([$vid,'🎉 Your Account is Now Verified!',
                    'Congratulations! Your vendor account has been verified by our team. Your products now show a Verified badge.',
                    BASE_URL.'/vendor/business-profile.php']);
        } catch(Exception $e){}
    }

    flash('success','Vendor profile updated.');
    header("Location: vendor-profile.php?id=$vid"); exit;
}

$pageTitle  = 'Vendor: '.sanitize($u['company']?:$u['name']);
$activePage = 'vendors';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
.prof-hero{position:relative;border-radius:18px;overflow:hidden;margin-bottom:24px;box-shadow:0 4px 24px rgba(0,0,0,.1)}
.prof-banner{width:100%;height:200px;object-fit:cover;display:block}
.prof-banner-ph{width:100%;height:200px;background:linear-gradient(135deg,var(--primary) 0%,#8b5cf6 100%);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.3);font-size:48px}
.prof-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.65) 0%,transparent 55%)}
.prof-info{position:absolute;bottom:0;left:0;right:0;padding:20px 24px;display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap}
.prof-logo-wrap{width:72px;height:72px;border-radius:14px;border:3px solid rgba(255,255,255,.9);overflow:hidden;background:#fff;flex-shrink:0;box-shadow:0 4px 12px rgba(0,0,0,.2)}
.prof-logo-wrap img{width:100%;height:100%;object-fit:cover}
.prof-logo-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;background:var(--primary);color:#fff}
.prof-meta{flex:1;min-width:200px}
.prof-meta h2{color:#fff;font-size:20px;font-weight:700;margin:0 0 3px;text-shadow:0 1px 4px rgba(0,0,0,.4)}
.prof-meta p{color:rgba(255,255,255,.75);font-size:13px;margin:0}

.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;margin-bottom:24px}
.stat-card{background:#fff;border:1px solid var(--border);border-radius:14px;padding:16px 18px;text-align:center;box-shadow:var(--shadow-sm)}
.stat-val{font-size:26px;font-weight:800;color:var(--primary);line-height:1;margin-bottom:4px}
.stat-lbl{font-size:11.5px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px}

.tab-nav{display:flex;border-bottom:2px solid var(--border);margin-bottom:24px;gap:0;overflow-x:auto}
.tab-btn{padding:11px 20px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;color:var(--text-muted);border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .18s;white-space:nowrap;display:flex;align-items:center;gap:6px}
.tab-btn.active{color:var(--primary);border-bottom-color:var(--primary)}
.tab-panel{display:none}
.tab-panel.active{display:block;animation:fadeIn .2s}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}

.upload-box{border:2px dashed var(--border);border-radius:12px;padding:20px;text-align:center;cursor:pointer;transition:all .2s;position:relative;overflow:hidden}
.upload-box:hover{border-color:var(--primary);background:#f0f4ff}
.upload-box input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.upload-preview{width:100%;height:120px;object-fit:contain;border-radius:8px;margin-top:10px}
.upload-preview-logo{width:72px;height:72px;object-fit:cover;border-radius:10px;border:2px solid var(--border);margin-top:10px}

.verify-card{border:2px solid #fbbf24;background:#fffbeb;border-radius:14px;padding:18px 20px;margin-bottom:20px;display:flex;align-items:flex-start;gap:14px}
.verify-card.verified{border-color:#10b981;background:#ecfdf5}
.badge-verified{display:inline-flex;align-items:center;gap:5px;background:#d1fae5;color:#065f46;border-radius:100px;padding:4px 14px;font-size:12px;font-weight:700}
.badge-unverified{display:inline-flex;align-items:center;gap:5px;background:#fef3c7;color:#92400e;border-radius:100px;padding:4px 14px;font-size:12px;font-weight:700}

.prod-mini{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)}
.prod-mini:last-child{border-bottom:none}
.prod-mini-img{width:36px;height:36px;border-radius:8px;object-fit:cover;background:#f1f5f9;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px}
.prod-mini-img img{width:100%;height:100%;object-fit:cover;border-radius:8px}
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Vendor Profile</h1>
  </div>
  <div class="topbar-right" style="gap:8px">
    <a href="<?= BASE_URL ?>/public/vendor-profile.php?id=<?= $vid ?>" target="_blank" class="btn btn-outline btn-sm">👁 Public View</a>
    <a href="vendors.php" class="btn btn-outline btn-sm">← All Vendors</a>
  </div>
</div>

<div class="content">
  <?= showFlash() ?>

  <!-- Hero -->
  <div class="prof-hero">
    <?php if (!empty($vp['banner'])): ?>
      <img src="<?= UPLOAD_URL.sanitize($vp['banner']) ?>" class="prof-banner" alt="Banner">
    <?php else: ?>
      <div class="prof-banner-ph">🏭</div>
    <?php endif; ?>
    <div class="prof-overlay"></div>
    <div class="prof-info">
      <div class="prof-logo-wrap">
        <?php if (!empty($vp['logo'])): ?>
          <img src="<?= UPLOAD_URL.sanitize($vp['logo']) ?>" alt="Logo">
        <?php else: ?>
          <div class="prof-logo-ph"><?= strtoupper(substr($u['company']?:$u['name'],0,1)) ?></div>
        <?php endif; ?>
      </div>
      <div class="prof-meta">
        <h2><?= sanitize($u['company']?:$u['name']) ?></h2>
        <p><?= sanitize($vp['tagline'] ?? $u['email']) ?></p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if (!empty($vp['is_verified'])): ?>
          <span class="badge-verified">✓ Verified</span>
        <?php else: ?>
          <span class="badge-unverified">⚠ Unverified</span>
        <?php endif; ?>
        <?= statusBadge($u['status']) ?>
      </div>
    </div>
  </div>

  <!-- Stats row -->
  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-val"><?= $stats['active_products'] ?></div>
      <div class="stat-lbl">Active Products</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?= $stats['total_products'] ?></div>
      <div class="stat-lbl">Total Products</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?= $stats['total_enquiries'] ?></div>
      <div class="stat-lbl">Total Enquiries</div>
    </div>
    <div class="stat-card">
      <div class="stat-val" style="color:#ef4444"><?= $stats['new_enquiries'] ?></div>
      <div class="stat-lbl">New Enquiries</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?= number_format((int)$stats['total_views']) ?></div>
      <div class="stat-lbl">Total Views</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?= $vp['rating'] ? number_format($vp['rating'],1).'★' : '—' ?></div>
      <div class="stat-lbl">Rating</div>
    </div>
  </div>

  <!-- Verification quick card -->
  <div class="verify-card <?= !empty($vp['is_verified'])?'verified':'' ?>">
    <div style="font-size:28px"><?= !empty($vp['is_verified'])?'✅':'⚠️' ?></div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:14px;margin-bottom:3px">
        <?= !empty($vp['is_verified']) ? 'This vendor is Verified' : 'This vendor is NOT verified yet' ?>
      </div>
      <div style="font-size:12.5px;color:var(--text-muted)">
        <?= !empty($vp['is_verified'])
          ? 'A ✓ Verified badge is shown on all their products and their public profile.'
          : 'Once you verify this vendor, a ✓ Verified badge will appear on their products and profile.' ?>
      </div>
    </div>
    <form method="POST" style="flex-shrink:0">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="business">
      <input type="hidden" name="tagline"         value="<?= sanitize($vp['tagline']??'') ?>">
      <input type="hidden" name="established_yr"  value="<?= sanitize($vp['established_yr']??'') ?>">
      <input type="hidden" name="employees"       value="<?= sanitize($vp['employees']??'') ?>">
      <input type="hidden" name="annual_turnover" value="<?= sanitize($vp['annual_turnover']??'') ?>">
      <input type="hidden" name="certifications"  value="<?= sanitize($vp['certifications']??'') ?>">
      <input type="hidden" name="website"         value="<?= sanitize($vp['website']??'') ?>">
      <input type="hidden" name="linkedin"        value="<?= sanitize($vp['linkedin']??'') ?>">
      <input type="hidden" name="facebook"        value="<?= sanitize($vp['facebook']??'') ?>">
      <input type="hidden" name="rating"          value="<?= sanitize($vp['rating']??'0') ?>">
      <?php if (empty($vp['is_verified'])): ?>
        <input type="hidden" name="is_verified" value="1">
        <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Verify this vendor?')">✓ Verify Now</button>
      <?php else: ?>
        <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Remove verification from this vendor?')">✕ Remove Verification</button>
      <?php endif; ?>
    </form>
  </div>

  <!-- Tabs -->
  <div class="tab-nav">
    <button class="tab-btn active" onclick="switchTab('business')">🏢 Business Info</button>
    <button class="tab-btn" onclick="switchTab('media')">🖼️ Logo &amp; Banner</button>
    <button class="tab-btn" onclick="switchTab('account')">👤 Account</button>
    <button class="tab-btn" onclick="switchTab('products')">📦 Products</button>
  </div>

  <!-- TAB 1: Business Info -->
  <div class="tab-panel active" id="tab-business">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="business">
      <div class="card">
        <div class="card-header"><h2>🏢 Business Details</h2></div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label">Business Tagline</label>
            <input type="text" name="tagline" class="form-control" maxlength="200"
                   value="<?= sanitize($vp['tagline']??'') ?>"
                   placeholder="e.g. Leading paper manufacturer in India">
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Year Established</label>
              <input type="number" name="established_yr" class="form-control" min="1900" max="<?= date('Y') ?>"
                     value="<?= sanitize($vp['established_yr']??'') ?>" placeholder="e.g. 2005">
            </div>
            <div class="form-group">
              <label class="form-label">Employees</label>
              <select name="employees" class="form-control">
                <option value="">-- Select --</option>
                <?php foreach(['1-10','11-25','26-50','51-100','101-250','251-500','500+'] as $e): ?>
                <option value="<?= $e ?>" <?= ($vp['employees']??'')===$e?'selected':'' ?>><?= $e ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Annual Turnover</label>
              <select name="annual_turnover" class="form-control">
                <option value="">-- Select --</option>
                <?php foreach(['Below ₹1 Cr','₹1–5 Cr','₹5–10 Cr','₹10–25 Cr','₹25–50 Cr','₹50–100 Cr','Above ₹100 Cr'] as $t): ?>
                <option value="<?= $t ?>" <?= ($vp['annual_turnover']??'')===$t?'selected':'' ?>><?= $t ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Admin Rating (0–5)</label>
              <input type="number" name="rating" class="form-control" min="0" max="5" step="0.1"
                     value="<?= sanitize($vp['rating']??'0') ?>" placeholder="e.g. 4.5">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Certifications</label>
            <input type="text" name="certifications" class="form-control"
                   value="<?= sanitize($vp['certifications']??'') ?>"
                   placeholder="ISO 9001:2015, FSC Certified, BIS Mark">
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">🌐 Website</label>
              <input type="url" name="website" class="form-control" value="<?= sanitize($vp['website']??'') ?>" placeholder="https://...">
            </div>
            <div class="form-group">
              <label class="form-label">💼 LinkedIn</label>
              <input type="url" name="linkedin" class="form-control" value="<?= sanitize($vp['linkedin']??'') ?>" placeholder="https://linkedin.com/company/...">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">📘 Facebook</label>
            <input type="url" name="facebook" class="form-control" value="<?= sanitize($vp['facebook']??'') ?>" placeholder="https://facebook.com/...">
          </div>
          <div style="padding:14px;background:#f8faff;border-radius:10px;border:1px solid #e0e7ff;margin-top:4px">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
              <input type="checkbox" name="is_verified" value="1" <?= !empty($vp['is_verified'])?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--primary)">
              <div>
                <div style="font-weight:700;font-size:13.5px">✓ Mark as Verified Vendor</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Shows a verified badge on all their products and public profile.</div>
              </div>
            </label>
          </div>
        </div>
      </div>
      <div style="margin-bottom:32px">
        <button type="submit" class="btn btn-primary">💾 Save Business Info</button>
      </div>
    </form>
  </div>

  <!-- TAB 2: Media -->
  <div class="tab-panel" id="tab-media">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="business">
      <input type="hidden" name="tagline"         value="<?= sanitize($vp['tagline']??'') ?>">
      <input type="hidden" name="established_yr"  value="<?= sanitize($vp['established_yr']??'') ?>">
      <input type="hidden" name="employees"       value="<?= sanitize($vp['employees']??'') ?>">
      <input type="hidden" name="annual_turnover" value="<?= sanitize($vp['annual_turnover']??'') ?>">
      <input type="hidden" name="certifications"  value="<?= sanitize($vp['certifications']??'') ?>">
      <input type="hidden" name="website"         value="<?= sanitize($vp['website']??'') ?>">
      <input type="hidden" name="linkedin"        value="<?= sanitize($vp['linkedin']??'') ?>">
      <input type="hidden" name="facebook"        value="<?= sanitize($vp['facebook']??'') ?>">
      <input type="hidden" name="rating"          value="<?= sanitize($vp['rating']??'0') ?>">
      <?php if (!empty($vp['is_verified'])): ?><input type="hidden" name="is_verified" value="1"><?php endif; ?>
      <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
        <div class="card">
          <div class="card-header"><h2>🏷️ Company Logo</h2></div>
          <div class="card-body">
            <div class="upload-box">
              <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" onchange="previewFile(this,'logo-preview')">
              <?php if (!empty($vp['logo'])): ?>
                <img src="<?= UPLOAD_URL.sanitize($vp['logo']) ?>" class="upload-preview-logo" id="logo-preview" alt="Logo">
                <p style="font-size:12px;color:var(--text-muted);margin:8px 0 0">Click to change</p>
              <?php else: ?>
                <div style="font-size:36px;margin-bottom:8px">🏷️</div>
                <p style="font-size:13px;font-weight:600;color:var(--primary)">Upload Logo</p>
                <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0">PNG or JPG · Square · Max 2MB</p>
                <img id="logo-preview" style="display:none" class="upload-preview-logo" alt="">
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><h2>🖼️ Profile Banner</h2></div>
          <div class="card-body">
            <div class="upload-box">
              <input type="file" name="banner" accept="image/jpeg,image/png,image/webp" onchange="previewFile(this,'banner-preview')">
              <?php if (!empty($vp['banner'])): ?>
                <img src="<?= UPLOAD_URL.sanitize($vp['banner']) ?>" class="upload-preview" id="banner-preview" alt="Banner">
                <p style="font-size:12px;color:var(--text-muted);margin:8px 0 0">Click to change</p>
              <?php else: ?>
                <div style="font-size:36px;margin-bottom:8px">🖼️</div>
                <p style="font-size:13px;font-weight:600;color:var(--primary)">Upload Banner</p>
                <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0">JPG or PNG · 1200×300px · Max 5MB</p>
                <img id="banner-preview" style="display:none" class="upload-preview" alt="">
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <div style="margin-bottom:32px">
        <button type="submit" class="btn btn-primary">💾 Save Media</button>
      </div>
    </form>
  </div>

  <!-- TAB 3: Account -->
  <div class="tab-panel" id="tab-account">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="account">
      <div class="card">
        <div class="card-header"><h2>👤 Account Details</h2></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Full Name <span class="req">*</span></label>
              <input type="text" name="name" class="form-control" value="<?= sanitize($u['name']) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Phone</label>
              <input type="text" name="phone" class="form-control" value="<?= sanitize($u['phone']??'') ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Email (read-only)</label>
            <input type="email" class="form-control" value="<?= sanitize($u['email']) ?>" disabled>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Company</label>
              <input type="text" name="company" class="form-control" value="<?= sanitize($u['company']??'') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">GST Number</label>
              <input type="text" name="gst_number" class="form-control" value="<?= sanitize($u['gst_number']??'') ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Address</label>
            <textarea name="address" class="form-control" rows="2"><?= sanitize($u['address']??'') ?></textarea>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">City</label>
              <input type="text" name="city" class="form-control" value="<?= sanitize($u['city']??'') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">State</label>
              <input type="text" name="state" class="form-control" value="<?= sanitize($u['state']??'') ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Account Status</label>
            <select name="status" class="form-control">
              <option value="active"   <?= $u['status']==='active'  ?'selected':''?>>✅ Active</option>
              <option value="pending"  <?= $u['status']==='pending' ?'selected':''?>>⏳ Pending</option>
              <option value="inactive" <?= $u['status']==='inactive'?'selected':''?>>⛔ Inactive</option>
            </select>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><h2>🔒 Reset Password <span style="font-size:12px;font-weight:400;color:var(--text-muted)">(admin override)</span></h2></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">New Password</label>
              <input type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="6" placeholder="Leave blank to keep current">
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:2px">
              <div class="form-text">Admin can reset without knowing the old password.</div>
            </div>
          </div>
        </div>
      </div>
      <div style="margin-bottom:32px">
        <button type="submit" class="btn btn-primary">💾 Save Account</button>
      </div>
    </form>
  </div>

  <!-- TAB 4: Products -->
  <div class="tab-panel" id="tab-products">
    <div class="card">
      <div class="card-header">
        <h2>📦 Recent Products</h2>
        <a href="<?= BASE_URL ?>/admin/products.php?vendor_id=<?= $vid ?>" class="btn btn-outline btn-sm" style="margin-left:auto">View All →</a>
      </div>
      <div class="card-body">
        <?php if ($recentProds): foreach ($recentProds as $p):
          $imgs = array_filter(explode(',', $p['images']??''));
          $img  = reset($imgs) ? UPLOAD_URL.trim(reset($imgs)) : '';
        ?>
        <div class="prod-mini">
          <div class="prod-mini-img">
            <?php if ($img): ?><img src="<?= sanitize($img) ?>" alt=""><?php else: ?>📦<?php endif; ?>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= sanitize($p['name']) ?></div>
            <div style="font-size:12px;color:var(--text-muted)"><?= sanitize($p['cname']??'') ?> · <?= date('d M Y',strtotime($p['created_at'])) ?></div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
            <?= statusBadge($p['status']) ?>
            <a href="<?= BASE_URL ?>/admin/view-product.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-xs">View</a>
          </div>
        </div>
        <?php endforeach; else: ?>
          <div class="empty-state"><div class="es-icon">📭</div><p>No products yet.</p></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /content -->

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
<script>
function switchTab(name) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  event.currentTarget.classList.add('active');
}
function previewFile(input, id) {
  const el = document.getElementById(id);
  if (!input.files||!input.files[0]||!el) return;
  const r = new FileReader();
  r.onload = e => { el.src = e.target.result; el.style.display = 'block'; };
  r.readAsDataURL(input.files[0]);
}
if (window.location.hash === '#account') switchTab('account');
</script>
</div></div></body></html>
