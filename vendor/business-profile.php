<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('vendor');
require_once __DIR__ . '/../includes/team.php';
requirePermission('business-profile');

$uid = currentUser()['id'];
$u   = $pdo->prepare("SELECT * FROM users WHERE id=?"); $u->execute([$uid]); $u = $u->fetch();
$vp  = $pdo->prepare("SELECT * FROM vendor_profiles WHERE vendor_id=?"); $vp->execute([$uid]); $vp = $vp->fetch() ?: [];

/* ── Countries list ──────────────────────────────────────── */
$countries = ["Afghanistan","Albania","Algeria","Angola","Argentina","Armenia","Australia","Austria","Azerbaijan","Bahrain","Bangladesh","Belarus","Belgium","Bolivia","Bosnia","Brazil","Bulgaria","Cambodia","Cameroon","Canada","Chile","China","Colombia","Croatia","Czech Republic","Denmark","Ecuador","Egypt","Ethiopia","Finland","France","Georgia","Germany","Ghana","Greece","Guatemala","Hungary","India","Indonesia","Iran","Iraq","Ireland","Israel","Italy","Japan","Jordan","Kazakhstan","Kenya","Kuwait","Lebanon","Libya","Malaysia","Mexico","Morocco","Myanmar","Nepal","Netherlands","New Zealand","Nigeria","Norway","Oman","Pakistan","Peru","Philippines","Poland","Portugal","Qatar","Romania","Russia","Saudi Arabia","Serbia","Singapore","South Africa","South Korea","Spain","Sri Lanka","Sweden","Switzerland","Syria","Taiwan","Tanzania","Thailand","Tunisia","Turkey","UAE","Uganda","Ukraine","United Kingdom","United States","Uzbekistan","Venezuela","Vietnam","Yemen","Zimbabwe"];

/* ── POST handler ────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'business';

    /* ── Account + Password ──────────────────────────────── */
    if ($action === 'account') {
        $name     = trim($_POST['name']        ?? '');
        $phone    = trim($_POST['phone']       ?? '');
        $company  = trim($_POST['company']     ?? '');
        $gst      = trim($_POST['gst_number']  ?? '');
        $address  = trim($_POST['address']     ?? '');
        $city     = trim($_POST['city']        ?? '');
        $state    = trim($_POST['state']       ?? '');
        $pincode  = trim($_POST['pincode']     ?? '');
        $country  = trim($_POST['country']     ?? '');
        $timezone = trim($_POST['timezone']    ?? '');

        if (!empty($_POST['new_password'])) {
            if (!password_verify($_POST['current_password'] ?? '', $u['password'])) {
                flash('error','Current password is incorrect.'); header('Location: business-profile.php?tab=account'); exit;
            }
            if (strlen($_POST['new_password']) < 6) {
                flash('error','Password must be at least 6 characters.'); header('Location: business-profile.php?tab=account'); exit;
            }
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($_POST['new_password'],PASSWORD_DEFAULT),$uid]);
        }
        $pdo->prepare("UPDATE users SET name=?,phone=?,company=?,gst_number=?,address=?,city=?,state=?,pincode=?,country=?,timezone=?,updated_at=NOW() WHERE id=?")
            ->execute([$name,$phone,$company,$gst,$address,$city,$state,$pincode,$country,$timezone,$uid]);
        $_SESSION['name'] = $name;
        flash('success','Account information updated.');
        header('Location: business-profile.php?tab=account'); exit;
    }

    /* ── Business / Social / Trade — all go through one upsert ── */
    $data = [
        'tagline'             => trim($_POST['tagline']             ?? ''),
        'about_us'            => trim($_POST['about_us']           ?? ''),
        'established_yr'      => (int)($_POST['established_yr']    ?? 0) ?: null,
        'business_type'       => trim($_POST['business_type']      ?? ''),
        'business_nature'     => trim($_POST['business_nature']    ?? ''),
        'industry_focus'      => trim($_POST['industry_focus']     ?? ''),
        'employees'           => trim($_POST['employees']          ?? ''),
        'annual_turnover'     => trim($_POST['annual_turnover']    ?? ''),
        'production_capacity' => trim($_POST['production_capacity']?? ''),
        'main_products'       => trim($_POST['main_products']      ?? ''),
        'certifications'      => trim($_POST['certifications']     ?? ''),
        'export_countries'    => implode(',', array_filter(array_map('trim', (array)($_POST['export_countries'] ?? [])))),
        'import_countries'    => implode(',', array_filter(array_map('trim', (array)($_POST['import_countries'] ?? [])))),
        'min_order_value'     => trim($_POST['min_order_value']    ?? ''),
        'payment_terms'       => implode(',', array_filter(array_map('trim', (array)($_POST['payment_terms']    ?? [])))),
        'delivery_time'       => trim($_POST['delivery_time']      ?? ''),
        'shipping_methods'    => implode(',', array_filter(array_map('trim', (array)($_POST['shipping_methods'] ?? [])))),
        'trade_shows'         => trim($_POST['trade_shows']        ?? ''),
        'website'             => trim($_POST['website']            ?? ''),
        'linkedin'            => trim($_POST['linkedin']           ?? ''),
        'facebook'            => trim($_POST['facebook']           ?? ''),
        'twitter'             => trim($_POST['twitter']            ?? ''),
        'instagram'           => trim($_POST['instagram']          ?? ''),
        'youtube'             => trim($_POST['youtube']            ?? ''),
        'whatsapp'            => trim($_POST['whatsapp']           ?? ''),
        'contact_person'      => trim($_POST['contact_person']     ?? ''),
        'contact_designation' => trim($_POST['contact_designation']?? ''),
        'contact_email'       => trim($_POST['contact_email']      ?? ''),
        'contact_phone'       => trim($_POST['contact_phone']      ?? ''),
        'company_registration'=> trim($_POST['company_registration']?? ''),
        'tax_id'              => trim($_POST['tax_id']             ?? ''),
        'bank_country'        => trim($_POST['bank_country']       ?? ''),
        'currency_accepted'   => implode(',', array_filter(array_map('trim', (array)($_POST['currency_accepted'] ?? [])))),
        'sample_available'    => isset($_POST['sample_available'])  ? 1 : 0,
        'oem_available'       => isset($_POST['oem_available'])     ? 1 : 0,
        'r_and_d_available'   => isset($_POST['r_and_d_available']) ? 1 : 0,
    ];

    /* Images */
    $logo   = $vp['logo']   ?? null;
    $banner = $vp['banner'] ?? null;
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR,0755,true);
    if (!empty($_FILES['logo']['tmp_name'])   && $_FILES['logo']['error']   === UPLOAD_ERR_OK) { $fn=uploadImage($_FILES['logo'],  'logo');   if($fn) $logo=$fn; }
    if (!empty($_FILES['banner']['tmp_name']) && $_FILES['banner']['error'] === UPLOAD_ERR_OK) { $fn=uploadImage($_FILES['banner'],'banner'); if($fn) $banner=$fn; }
    $data['logo']   = $logo;
    $data['banner'] = $banner;

    $exists = $pdo->prepare("SELECT vendor_id FROM vendor_profiles WHERE vendor_id=?"); $exists->execute([$uid]);
    if ($exists->fetch()) {
        $set = implode(',', array_map(fn($k)=>"$k=?", array_keys($data)));
        $pdo->prepare("UPDATE vendor_profiles SET $set WHERE vendor_id=?")
            ->execute([...array_values($data), $uid]);
    } else {
        $cols = implode(',', array_keys($data));
        $phs  = implode(',', array_fill(0, count($data), '?'));
        $pdo->prepare("INSERT INTO vendor_profiles (vendor_id,$cols) VALUES(?,$phs)")
            ->execute([$uid, ...array_values($data)]);
    }

    flash('success','Profile updated successfully!');
    $tab = $_POST['active_tab'] ?? 'business';
    header("Location: business-profile.php?tab=$tab"); exit;
}

/* ── Completion score ────────────────────────────────────── */
$scoreFields = ['tagline','about_us','established_yr','business_type','employees','annual_turnover',
                'certifications','logo','banner','website','contact_person','export_countries','production_capacity'];
$filled  = count(array_filter($scoreFields, fn($f) => !empty($vp[$f])));
$score   = round($filled / count($scoreFields) * 100);
$activeTab = $_GET['tab'] ?? 'business';

$pageTitle  = 'Business Profile';
$activePage = 'business-profile';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
/* ── Hero ─────────────────────────────────────────────────── */
.prof-hero{position:relative;border-radius:18px;overflow:hidden;margin-bottom:22px;box-shadow:0 4px 24px rgba(0,0,0,.12)}
.prof-banner{width:100%;height:210px;object-fit:cover;display:block}
.prof-banner-ph{width:100%;height:210px;background:linear-gradient(135deg,var(--primary) 0%,#8b5cf6 100%);display:flex;align-items:center;justify-content:center;font-size:48px;color:rgba(255,255,255,.25)}
.prof-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.65) 0%,transparent 55%)}
.prof-info{position:absolute;bottom:0;left:0;right:0;padding:20px 24px;display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap}
.prof-logo-wrap{width:76px;height:76px;border-radius:14px;border:3px solid rgba(255,255,255,.9);overflow:hidden;background:#fff;flex-shrink:0;box-shadow:0 4px 14px rgba(0,0,0,.25)}
.prof-logo-wrap img{width:100%;height:100%;object-fit:cover}
.prof-logo-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:800;background:var(--primary);color:#fff}
.prof-meta{flex:1;min-width:180px}
.prof-meta h2{color:#fff;font-size:19px;font-weight:700;margin:0 0 3px;text-shadow:0 1px 4px rgba(0,0,0,.4)}
.prof-meta p{color:rgba(255,255,255,.78);font-size:13px;margin:0}
.badge-verified{background:#d1fae5;color:#065f46;border-radius:100px;padding:4px 14px;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:5px}
.badge-unverified{background:rgba(255,255,255,.15);color:rgba(255,255,255,.8);border-radius:100px;padding:4px 14px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:5px;backdrop-filter:blur(4px)}

/* ── Score bar ────────────────────────────────────────────── */
.score-bar{height:7px;background:#e5e7eb;border-radius:100px;overflow:hidden;margin:8px 0 4px}
.score-fill{height:100%;border-radius:100px;background:linear-gradient(90deg,var(--primary),#8b5cf6);transition:width .6s}

/* ── Tab nav ──────────────────────────────────────────────── */
.tab-nav{display:flex;border-bottom:2px solid var(--border);margin-bottom:24px;gap:0;overflow-x:auto;scrollbar-width:none}
.tab-nav::-webkit-scrollbar{display:none}
.tab-btn{padding:11px 18px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;color:var(--text-muted);border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .18s;white-space:nowrap;display:flex;align-items:center;gap:6px;font-family:inherit}
.tab-btn.active{color:var(--primary);border-bottom-color:var(--primary)}
.tab-panel{display:none}
.tab-panel.active{display:block;animation:fadeIn .2s}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}

/* ── Upload boxes ─────────────────────────────────────────── */
.upload-box{border:2px dashed var(--border);border-radius:12px;padding:22px;text-align:center;cursor:pointer;transition:all .2s;position:relative;overflow:hidden;background:#fafcff}
.upload-box:hover{border-color:var(--primary);background:#f0f4ff}
.upload-box input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.upload-preview{width:100%;height:130px;object-fit:contain;border-radius:8px;margin-top:10px}
.upload-preview-logo{width:80px;height:80px;object-fit:cover;border-radius:10px;border:2px solid var(--border);margin-top:10px}

/* ── Checkbox group ───────────────────────────────────────── */
.chk-grid{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px}
.chk-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1.5px solid var(--border);border-radius:100px;cursor:pointer;font-size:12.5px;font-weight:500;transition:all .15s;user-select:none}
.chk-pill:has(input:checked){background:var(--primary);color:#fff;border-color:var(--primary)}
.chk-pill input{display:none}

/* ── Multi-select country box ─────────────────────────────── */
.country-select{width:100%;min-height:80px;font-size:13px;border:1.5px solid var(--border);border-radius:8px;padding:6px;font-family:inherit}
.country-select:focus{outline:none;border-color:var(--primary)}

/* ── Capability toggles ───────────────────────────────────── */
.cap-toggle{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border:1.5px solid var(--border);border-radius:12px;cursor:pointer;transition:all .18s;margin-bottom:8px}
.cap-toggle:has(input:checked){border-color:var(--primary);background:#eef2ff}
.cap-toggle input{width:20px;height:20px;accent-color:var(--primary);cursor:pointer;flex-shrink:0}
.cap-toggle-info h4{font-size:13.5px;font-weight:700;margin:0 0 2px}
.cap-toggle-info p{font-size:12px;color:var(--text-muted);margin:0}

/* ── Section divider ──────────────────────────────────────── */
.sec-divider{font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;margin:20px 0 12px;display:flex;align-items:center;gap:8px}
.sec-divider::after{content:'';flex:1;height:1px;background:var(--border)}

/* ── Contact person card ──────────────────────────────────── */
.contact-card{background:#f8faff;border:1px solid #e0e7ff;border-radius:12px;padding:16px;margin-bottom:16px}
.contact-card-head{font-size:12px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px}

/* Completion tips */
.tip-item{display:flex;align-items:center;gap:8px;padding:6px 0;font-size:13px;color:var(--text-muted)}
.tip-item.done{color:#10b981}
.tip-dot{width:8px;height:8px;border-radius:50%;background:#e5e7eb;flex-shrink:0}
.tip-item.done .tip-dot{background:#10b981}
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Business Profile</h1>
  </div>
  <div class="topbar-right">
    <a href="<?= BASE_URL ?>/public/vendor-profile.php?id=<?= $uid ?>" target="_blank" class="btn btn-outline btn-sm">👁 Public View</a>
  </div>
</div>

<div class="content">
  <?= showFlash() ?>

  <!-- ── Hero ───────────────────────────────────────────── -->
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
        <p><?= sanitize($vp['tagline'] ?? 'Add your business tagline below') ?>
          <?php if (!empty($vp['established_yr'])): ?>
            · Est. <?= $vp['established_yr'] ?>
          <?php endif; ?>
        </p>
      </div>
      <?php if (!empty($vp['is_verified'])): ?>
        <span class="badge-verified">✓ Verified Vendor</span>
      <?php else: ?>
        <span class="badge-unverified">⏳ Pending Verification</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Completion bar ─────────────────────────────────── -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 20px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
        <span style="font-size:13px;font-weight:700">Profile Completion</span>
        <span style="font-size:13px;font-weight:800;color:<?= $score>=80?'#10b981':($score>=50?'#f59e0b':'#ef4444') ?>"><?= $score ?>%</span>
      </div>
      <div class="score-bar"><div class="score-fill" style="width:<?= $score ?>%"></div></div>
      <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0">
        <?php if ($score < 40): ?>🔴 A complete profile gets <strong>3× more enquiries</strong>. Start by adding your tagline and logo.
        <?php elseif ($score < 70): ?>🟡 Good start! Add your production capacity, contact person and export markets to rank higher.
        <?php elseif ($score < 100): ?>🟢 Almost there — fill in remaining details for a perfect profile.
        <?php else: ?>✅ <strong>Perfect profile!</strong> You're showing buyers everything they need.
        <?php endif; ?>
      </p>
    </div>
  </div>

  <!-- ── Tab Nav ────────────────────────────────────────── -->
  <div class="tab-nav">
    <button class="tab-btn <?= $activeTab==='business'?'active':'' ?>" onclick="switchTab('business')">🏢 Business Info</button>
    <button class="tab-btn <?= $activeTab==='trade'   ?'active':'' ?>" onclick="switchTab('trade')">🌍 Trade &amp; Exports</button>
    <button class="tab-btn <?= $activeTab==='contact' ?'active':'' ?>" onclick="switchTab('contact')">📞 Contact &amp; Legal</button>
    <button class="tab-btn <?= $activeTab==='media'   ?'active':'' ?>" onclick="switchTab('media')">🖼️ Logo &amp; Banner</button>
    <button class="tab-btn <?= $activeTab==='social'  ?'active':'' ?>" onclick="switchTab('social')">🌐 Online Presence</button>
    <button class="tab-btn <?= $activeTab==='account' ?'active':'' ?>" onclick="switchTab('account')">👤 Account</button>
  </div>

  <!-- ════════════════════════════════════════════════════
       TAB 1 — BUSINESS INFO
       ════════════════════════════════════════════════════ -->
  <div class="tab-panel <?= $activeTab==='business'?'active':'' ?>" id="tab-business">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="business">
      <input type="hidden" name="active_tab" value="business">

      <div class="card">
        <div class="card-header"><h2>🏢 Business Identity</h2></div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label">Business Tagline <span class="req">*</span></label>
            <input type="text" name="tagline" class="form-control" maxlength="200"
                   value="<?= sanitize($vp['tagline']??'') ?>"
                   placeholder="e.g. Leading paper manufacturer supplying globally since 2005">
            <div class="form-text">Displayed on your public profile and beside all your products.</div>
          </div>
          <div class="form-group">
            <label class="form-label">About Us</label>
            <textarea name="about_us" class="form-control" rows="4"
                      placeholder="Describe your company, history, expertise, and what makes you unique…"><?= sanitize($vp['about_us']??'') ?></textarea>
          </div>

          <div class="sec-divider">Company Details</div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Year Established</label>
              <input type="number" name="established_yr" class="form-control" min="1800" max="<?= date('Y') ?>"
                     value="<?= sanitize($vp['established_yr']??'') ?>" placeholder="e.g. 2005">
            </div>
            <div class="form-group">
              <label class="form-label">Business Type</label>
              <select name="business_type" class="form-control">
                <option value="">-- Select --</option>
                <?php foreach(['Manufacturer','Trader / Distributor','Manufacturer & Trader','Wholesaler','Retailer','Agent / Broker','Importer','Exporter','Importer & Exporter','Service Provider'] as $bt): ?>
                <option value="<?= $bt ?>" <?= ($vp['business_type']??'')===$bt?'selected':'' ?>><?= $bt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Business Nature</label>
              <select name="business_nature" class="form-control">
                <option value="">-- Select --</option>
                <?php foreach(['Sole Proprietorship','Partnership','Private Limited (Pvt. Ltd.)','Public Limited','LLC','Corporation','Co-operative','Non-Profit / NGO','Other'] as $bn): ?>
                <option value="<?= $bn ?>" <?= ($vp['business_nature']??'')===$bn?'selected':'' ?>><?= $bn ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Number of Employees</label>
              <select name="employees" class="form-control">
                <option value="">-- Select --</option>
                <?php foreach(['1–10','11–25','26–50','51–100','101–250','251–500','501–1000','1001–5000','5000+'] as $e): ?>
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
                <?php foreach(['Below $100K','$100K–$500K','$500K–$1M','$1M–$5M','$5M–$10M','$10M–$50M','Above $50M',
                               'Below ₹1 Cr','₹1–5 Cr','₹5–10 Cr','₹10–25 Cr','₹25–50 Cr','₹50–100 Cr','Above ₹100 Cr'] as $t): ?>
                <option value="<?= $t ?>" <?= ($vp['annual_turnover']??'')===$t?'selected':'' ?>><?= $t ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Production Capacity</label>
              <input type="text" name="production_capacity" class="form-control"
                     value="<?= sanitize($vp['production_capacity']??'') ?>"
                     placeholder="e.g. 5000 MT/month, 2 lakh units/day">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Main Products / Services</label>
            <input type="text" name="main_products" class="form-control"
                   value="<?= sanitize($vp['main_products']??'') ?>"
                   placeholder="e.g. Kraft Paper, Sack Kraft, Corrugated Boxes">
          </div>
          <div class="form-group">
            <label class="form-label">Industry Focus</label>
            <input type="text" name="industry_focus" class="form-control"
                   value="<?= sanitize($vp['industry_focus']??'') ?>"
                   placeholder="e.g. Packaging, Food Grade, Construction, Pharmaceuticals">
          </div>

          <div class="sec-divider">Certifications &amp; Quality</div>

          <div class="form-group">
            <label class="form-label">Certifications &amp; Standards</label>
            <input type="text" name="certifications" class="form-control"
                   value="<?= sanitize($vp['certifications']??'') ?>"
                   placeholder="e.g. ISO 9001:2015, FSC, REACH, CE, BIS, FDA, Halal, Kosher">
            <div class="form-text">Comma-separated. These appear as badges on your profile.</div>
          </div>

          <div class="sec-divider">Capabilities</div>

          <label class="cap-toggle">
            <div class="cap-toggle-info">
              <h4>📦 Sample Available</h4>
              <p>You can provide product samples to potential buyers before bulk order.</p>
            </div>
            <input type="checkbox" name="sample_available" value="1" <?= !empty($vp['sample_available'])?'checked':'' ?>>
          </label>
          <label class="cap-toggle">
            <div class="cap-toggle-info">
              <h4>🔧 OEM / Custom Manufacturing</h4>
              <p>You accept orders for custom specifications or private label manufacturing.</p>
            </div>
            <input type="checkbox" name="oem_available" value="1" <?= !empty($vp['oem_available'])?'checked':'' ?>>
          </label>
          <label class="cap-toggle">
            <div class="cap-toggle-info">
              <h4>🔬 R&amp;D / Product Development</h4>
              <p>You have in-house R&amp;D capabilities for developing new products.</p>
            </div>
            <input type="checkbox" name="r_and_d_available" value="1" <?= !empty($vp['r_and_d_available'])?'checked':'' ?>>
          </label>
        </div>
      </div>
      <div style="margin-bottom:32px">
        <button type="submit" class="btn btn-primary">💾 Save Business Info</button>
      </div>
    </form>
  </div>

  <!-- ════════════════════════════════════════════════════
       TAB 2 — TRADE & EXPORTS
       ════════════════════════════════════════════════════ -->
  <div class="tab-panel <?= $activeTab==='trade'?'active':'' ?>" id="tab-trade">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="business">
      <input type="hidden" name="active_tab" value="trade">
      <?php /* Carry over other tabs' data */ ?>
      <?php foreach(['tagline','about_us','established_yr','business_type','business_nature','employees',
                     'annual_turnover','production_capacity','main_products','industry_focus','certifications',
                     'contact_person','contact_designation','contact_email','contact_phone',
                     'company_registration','tax_id','bank_country',
                     'website','linkedin','facebook','twitter','instagram','youtube','whatsapp'] as $hf): ?>
      <input type="hidden" name="<?= $hf ?>" value="<?= sanitize($vp[$hf]??'') ?>">
      <?php endforeach; ?>
      <?php foreach(['sample_available','oem_available','r_and_d_available'] as $hf): ?>
      <?php if(!empty($vp[$hf])): ?><input type="hidden" name="<?= $hf ?>" value="1"><?php endif; ?>
      <?php endforeach; ?>

      <div class="card">
        <div class="card-header"><h2>🌍 Export &amp; Import Markets</h2></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Export Countries</label>
              <select name="export_countries[]" class="country-select" multiple size="7">
                <?php $expArr = array_filter(explode(',', $vp['export_countries']??'')); ?>
                <?php foreach($countries as $c): ?>
                <option value="<?= $c ?>" <?= in_array($c,$expArr)?'selected':'' ?>><?= $c ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Hold Ctrl / Cmd to select multiple countries.</div>
            </div>
            <div class="form-group">
              <label class="form-label">Import Countries</label>
              <select name="import_countries[]" class="country-select" multiple size="7">
                <?php $impArr = array_filter(explode(',', $vp['import_countries']??'')); ?>
                <?php foreach($countries as $c): ?>
                <option value="<?= $c ?>" <?= in_array($c,$impArr)?'selected':'' ?>><?= $c ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Countries you source raw materials or products from.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2>💳 Payment &amp; Trading Terms</h2></div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label">Accepted Payment Terms</label>
            <div class="chk-grid">
              <?php $ptArr = array_filter(explode(',', $vp['payment_terms']??''));
              foreach(['T/T (Wire Transfer)','L/C (Letter of Credit)','CAD','D/P','D/A','Western Union','PayPal','Advance Payment','Net 30','Net 60','Net 90'] as $pt): ?>
              <label class="chk-pill">
                <input type="checkbox" name="payment_terms[]" value="<?= $pt ?>" <?= in_array($pt,$ptArr)?'checked':'' ?>>
                <?= $pt ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Currencies Accepted</label>
            <div class="chk-grid">
              <?php $curArr = array_filter(explode(',', $vp['currency_accepted']??''));
              foreach(['USD','EUR','GBP','INR','AED','SAR','CNY','JPY','SGD','AUD','CAD','CHF'] as $cur): ?>
              <label class="chk-pill">
                <input type="checkbox" name="currency_accepted[]" value="<?= $cur ?>" <?= in_array($cur,$curArr)?'checked':'' ?>>
                <?= $cur ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Minimum Order Value</label>
              <input type="text" name="min_order_value" class="form-control"
                     value="<?= sanitize($vp['min_order_value']??'') ?>"
                     placeholder="e.g. $500, ₹50,000, 1 MT">
            </div>
            <div class="form-group">
              <label class="form-label">Typical Delivery Time</label>
              <select name="delivery_time" class="form-control">
                <option value="">-- Select --</option>
                <?php foreach(['1–3 days','3–7 days','1–2 weeks','2–4 weeks','4–6 weeks','6–8 weeks','8–12 weeks','Custom / Negotiable'] as $dt): ?>
                <option value="<?= $dt ?>" <?= ($vp['delivery_time']??'')===$dt?'selected':'' ?>><?= $dt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Shipping Methods</label>
            <div class="chk-grid">
              <?php $smArr = array_filter(explode(',', $vp['shipping_methods']??''));
              foreach(['Sea Freight','Air Freight','Road / Truck','Rail','Express Courier (DHL/FedEx)','FOB','CIF','EXW','Door to Door','Multimodal'] as $sm): ?>
              <label class="chk-pill">
                <input type="checkbox" name="shipping_methods[]" value="<?= $sm ?>" <?= in_array($sm,$smArr)?'checked':'' ?>>
                <?= $sm ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Trade Shows &amp; Events Attended</label>
            <input type="text" name="trade_shows" class="form-control"
                   value="<?= sanitize($vp['trade_shows']??'') ?>"
                   placeholder="e.g. Paperex India, Drupa Germany, Pack Expo USA">
            <div class="form-text">Comma-separated list of exhibitions or trade fairs you participate in.</div>
          </div>
        </div>
      </div>
      <div style="margin-bottom:32px">
        <button type="submit" class="btn btn-primary">💾 Save Trade Details</button>
      </div>
    </form>
  </div>

  <!-- ════════════════════════════════════════════════════
       TAB 3 — CONTACT & LEGAL
       ════════════════════════════════════════════════════ -->
  <div class="tab-panel <?= $activeTab==='contact'?'active':'' ?>" id="tab-contact">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="business">
      <input type="hidden" name="active_tab" value="contact">
      <?php foreach(['tagline','about_us','established_yr','business_type','business_nature','employees',
                     'annual_turnover','production_capacity','main_products','industry_focus','certifications',
                     'website','linkedin','facebook','twitter','instagram','youtube','whatsapp'] as $hf): ?>
      <input type="hidden" name="<?= $hf ?>" value="<?= sanitize($vp[$hf]??'') ?>">
      <?php endforeach; ?>
      <?php foreach(['sample_available','oem_available','r_and_d_available'] as $hf): ?>
      <?php if(!empty($vp[$hf])): ?><input type="hidden" name="<?= $hf ?>" value="1"><?php endif; ?>
      <?php endforeach; ?>
      <input type="hidden" name="export_countries[]" value="<?= sanitize($vp['export_countries']??'') ?>">
      <input type="hidden" name="import_countries[]" value="<?= sanitize($vp['import_countries']??'') ?>">
      <input type="hidden" name="payment_terms[]"    value="<?= sanitize($vp['payment_terms']??'') ?>">
      <input type="hidden" name="shipping_methods[]" value="<?= sanitize($vp['shipping_methods']??'') ?>">
      <input type="hidden" name="currency_accepted[]"value="<?= sanitize($vp['currency_accepted']??'') ?>">

      <div class="card">
        <div class="card-header"><h2>📞 Primary Contact Person</h2></div>
        <div class="card-body">
          <div class="alert alert-info" style="margin-bottom:16px">
            💡 This contact info appears on your public profile for buyers to reach the right person directly.
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Contact Person Name</label>
              <input type="text" name="contact_person" class="form-control"
                     value="<?= sanitize($vp['contact_person']??'') ?>" placeholder="e.g. Rajesh Sharma">
            </div>
            <div class="form-group">
              <label class="form-label">Designation / Title</label>
              <input type="text" name="contact_designation" class="form-control"
                     value="<?= sanitize($vp['contact_designation']??'') ?>"
                     placeholder="e.g. Export Manager, Sales Director, CEO">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Direct Email</label>
              <input type="email" name="contact_email" class="form-control"
                     value="<?= sanitize($vp['contact_email']??'') ?>" placeholder="sales@yourcompany.com">
            </div>
            <div class="form-group">
              <label class="form-label">Direct Phone / WhatsApp</label>
              <input type="text" name="contact_phone" class="form-control"
                     value="<?= sanitize($vp['contact_phone']??'') ?>" placeholder="+91 98765 43210">
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2>🏦 Legal &amp; Compliance</h2></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Company Registration No.</label>
              <input type="text" name="company_registration" class="form-control"
                     value="<?= sanitize($vp['company_registration']??'') ?>"
                     placeholder="e.g. U74999MH2005PTC153057">
              <div class="form-text">Business / Corporate / ROC registration number.</div>
            </div>
            <div class="form-group">
              <label class="form-label">Tax ID / VAT / GST No.</label>
              <input type="text" name="tax_id" class="form-control"
                     value="<?= sanitize($vp['tax_id']??'') ?>"
                     placeholder="e.g. 22AAAAA0000A1Z5 · VAT123456 · EIN 00-0000000">
              <div class="form-text">GST, VAT, TIN, EIN or equivalent tax identifier.</div>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Primary Banking Country</label>
            <select name="bank_country" class="form-control">
              <option value="">-- Select Country --</option>
              <?php foreach($countries as $c): ?>
              <option value="<?= $c ?>" <?= ($vp['bank_country']??'')===$c?'selected':'' ?>><?= $c ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div style="margin-bottom:32px">
        <button type="submit" class="btn btn-primary">💾 Save Contact &amp; Legal</button>
      </div>
    </form>
  </div>

  <!-- ════════════════════════════════════════════════════
       TAB 4 — LOGO & BANNER
       ════════════════════════════════════════════════════ -->
  <div class="tab-panel <?= $activeTab==='media'?'active':'' ?>" id="tab-media">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="business">
      <input type="hidden" name="active_tab" value="media">
      <?php foreach(['tagline','about_us','established_yr','business_type','business_nature','employees',
                     'annual_turnover','production_capacity','main_products','industry_focus','certifications',
                     'contact_person','contact_designation','contact_email','contact_phone',
                     'company_registration','tax_id','bank_country',
                     'website','linkedin','facebook','twitter','instagram','youtube','whatsapp',
                     'min_order_value','delivery_time','trade_shows'] as $hf): ?>
      <input type="hidden" name="<?= $hf ?>" value="<?= sanitize($vp[$hf]??'') ?>">
      <?php endforeach; ?>
      <?php foreach(['sample_available','oem_available','r_and_d_available'] as $hf): ?>
      <?php if(!empty($vp[$hf])): ?><input type="hidden" name="<?= $hf ?>" value="1"><?php endif; ?>
      <?php endforeach; ?>

      <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px">
        <!-- Logo -->
        <div class="card">
          <div class="card-header"><h2>🏷️ Company Logo</h2></div>
          <div class="card-body">
            <div class="upload-box">
              <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" onchange="previewFile(this,'logo-preview')">
              <?php if (!empty($vp['logo'])): ?>
                <img src="<?= UPLOAD_URL.sanitize($vp['logo']) ?>" class="upload-preview-logo" id="logo-preview" alt="Logo">
                <p style="font-size:12px;color:var(--text-muted);margin:10px 0 0">Click to change</p>
              <?php else: ?>
                <div style="font-size:36px;margin-bottom:8px">🏷️</div>
                <p style="font-size:13px;font-weight:600;color:var(--primary)">Upload Logo</p>
                <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0">Square · PNG or JPG · Max 2 MB</p>
                <img id="logo-preview" style="display:none" class="upload-preview-logo" alt="">
              <?php endif; ?>
            </div>
          </div>
        </div>
        <!-- Banner -->
        <div class="card">
          <div class="card-header"><h2>🖼️ Profile Banner</h2></div>
          <div class="card-body">
            <div class="upload-box">
              <input type="file" name="banner" accept="image/jpeg,image/png,image/webp" onchange="previewFile(this,'banner-preview')">
              <?php if (!empty($vp['banner'])): ?>
                <img src="<?= UPLOAD_URL.sanitize($vp['banner']) ?>" class="upload-preview" id="banner-preview" alt="Banner">
                <p style="font-size:12px;color:var(--text-muted);margin:10px 0 0">Click to change</p>
              <?php else: ?>
                <div style="font-size:36px;margin-bottom:8px">🖼️</div>
                <p style="font-size:13px;font-weight:600;color:var(--primary)">Upload Banner</p>
                <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0">1200 × 300 px · JPG/PNG · Max 5 MB</p>
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

  <!-- ════════════════════════════════════════════════════
       TAB 5 — ONLINE PRESENCE
       ════════════════════════════════════════════════════ -->
  <div class="tab-panel <?= $activeTab==='social'?'active':'' ?>" id="tab-social">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="business">
      <input type="hidden" name="active_tab" value="social">
      <?php foreach(['tagline','about_us','established_yr','business_type','business_nature','employees',
                     'annual_turnover','production_capacity','main_products','industry_focus','certifications',
                     'contact_person','contact_designation','contact_email','contact_phone',
                     'company_registration','tax_id','bank_country',
                     'min_order_value','delivery_time','trade_shows'] as $hf): ?>
      <input type="hidden" name="<?= $hf ?>" value="<?= sanitize($vp[$hf]??'') ?>">
      <?php endforeach; ?>
      <?php foreach(['sample_available','oem_available','r_and_d_available'] as $hf): ?>
      <?php if(!empty($vp[$hf])): ?><input type="hidden" name="<?= $hf ?>" value="1"><?php endif; ?>
      <?php endforeach; ?>

      <div class="card">
        <div class="card-header"><h2>🌐 Online Presence</h2></div>
        <div class="card-body">
          <div class="sec-divider">Website</div>
          <div class="form-group">
            <label class="form-label">🌐 Company Website</label>
            <input type="url" name="website" class="form-control"
                   value="<?= sanitize($vp['website']??'') ?>" placeholder="https://www.yourcompany.com">
          </div>

          <div class="sec-divider">Social Media</div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">💼 LinkedIn</label>
              <input type="url" name="linkedin" class="form-control"
                     value="<?= sanitize($vp['linkedin']??'') ?>" placeholder="https://linkedin.com/company/...">
            </div>
            <div class="form-group">
              <label class="form-label">📘 Facebook</label>
              <input type="url" name="facebook" class="form-control"
                     value="<?= sanitize($vp['facebook']??'') ?>" placeholder="https://facebook.com/...">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">🐦 Twitter / X</label>
              <input type="url" name="twitter" class="form-control"
                     value="<?= sanitize($vp['twitter']??'') ?>" placeholder="https://x.com/...">
            </div>
            <div class="form-group">
              <label class="form-label">📸 Instagram</label>
              <input type="url" name="instagram" class="form-control"
                     value="<?= sanitize($vp['instagram']??'') ?>" placeholder="https://instagram.com/...">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">▶️ YouTube</label>
              <input type="url" name="youtube" class="form-control"
                     value="<?= sanitize($vp['youtube']??'') ?>" placeholder="https://youtube.com/@...">
            </div>
            <div class="form-group">
              <label class="form-label">💬 WhatsApp Business</label>
              <input type="text" name="whatsapp" class="form-control"
                     value="<?= sanitize($vp['whatsapp']??'') ?>" placeholder="+91 98765 43210">
              <div class="form-text">Include country code. Used for WhatsApp chat button.</div>
            </div>
          </div>
        </div>
      </div>
      <div style="margin-bottom:32px">
        <button type="submit" class="btn btn-primary">💾 Save Online Presence</button>
      </div>
    </form>
  </div>

  <!-- ════════════════════════════════════════════════════
       TAB 6 — ACCOUNT
       ════════════════════════════════════════════════════ -->
  <div class="tab-panel <?= $activeTab==='account'?'active':'' ?>" id="tab-account">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="account">

      <div class="card">
        <div class="card-header"><h2>👤 Account Information</h2></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Full Name <span class="req">*</span></label>
              <input type="text" name="name" class="form-control" required value="<?= sanitize($u['name']) ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Phone / Mobile</label>
              <input type="text" name="phone" class="form-control"
                     value="<?= sanitize($u['phone']??'') ?>" placeholder="+1 555 000 0000">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Email Address</label>
            <input type="email" class="form-control" value="<?= sanitize($u['email']) ?>" disabled>
            <div class="form-text">Contact admin to change email.</div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Company / Business Name</label>
              <input type="text" name="company" class="form-control" value="<?= sanitize($u['company']??'') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">GST / VAT / Tax No.</label>
              <input type="text" name="gst_number" class="form-control"
                     value="<?= sanitize($u['gst_number']??'') ?>" placeholder="Tax ID for your country">
            </div>
          </div>

          <div class="sec-divider">Address</div>

          <div class="form-group">
            <label class="form-label">Street Address</label>
            <textarea name="address" class="form-control" rows="2"><?= sanitize($u['address']??'') ?></textarea>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">City</label>
              <input type="text" name="city" class="form-control" value="<?= sanitize($u['city']??'') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">State / Province / Region</label>
              <input type="text" name="state" class="form-control" value="<?= sanitize($u['state']??'') ?>">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Postal / ZIP Code</label>
              <input type="text" name="pincode" class="form-control"
                     value="<?= sanitize($u['pincode']??'') ?>" placeholder="e.g. 400001, 10001, SW1A 1AA">
            </div>
            <div class="form-group">
              <label class="form-label">Country <span class="req">*</span></label>
              <select name="country" class="form-control">
                <option value="">-- Select Country --</option>
                <?php foreach($countries as $c): ?>
                <option value="<?= $c ?>" <?= ($u['country']??'')===$c?'selected':'' ?>><?= $c ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Timezone</label>
            <select name="timezone" class="form-control">
              <option value="">-- Select Timezone --</option>
              <?php foreach(['UTC-12:00','UTC-11:00','UTC-10:00','UTC-09:00','UTC-08:00 (PST)','UTC-07:00 (MST)',
                             'UTC-06:00 (CST)','UTC-05:00 (EST)','UTC-04:00','UTC-03:00','UTC-02:00','UTC-01:00',
                             'UTC+00:00 (GMT/London)','UTC+01:00 (CET/Paris/Berlin)','UTC+02:00 (EET/Cairo)',
                             'UTC+03:00 (Moscow/Riyadh)','UTC+03:30 (Tehran)','UTC+04:00 (Dubai/Baku)',
                             'UTC+04:30 (Kabul)','UTC+05:00 (Karachi/Tashkent)','UTC+05:30 (IST — India)',
                             'UTC+05:45 (Kathmandu)','UTC+06:00 (Dhaka/Almaty)','UTC+06:30 (Yangon)',
                             'UTC+07:00 (Bangkok/Jakarta)','UTC+08:00 (Beijing/Singapore/KL)',
                             'UTC+09:00 (Tokyo/Seoul)','UTC+09:30 (Adelaide)','UTC+10:00 (Sydney/AEST)',
                             'UTC+11:00','UTC+12:00 (Auckland/Fiji)'] as $tz): ?>
              <option value="<?= $tz ?>" <?= ($u['timezone']??'')===$tz?'selected':'' ?>><?= $tz ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2>🔒 Change Password</h2></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Current Password</label>
              <input type="password" name="current_password" class="form-control" autocomplete="current-password">
            </div>
            <div class="form-group">
              <label class="form-label">New Password <span style="font-weight:400;font-size:12px">(min 6 chars)</span></label>
              <input type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="6">
            </div>
          </div>
          <div class="form-text">Leave both fields blank to keep your current password.</div>
        </div>
      </div>
      <div style="margin-bottom:32px">
        <button type="submit" class="btn btn-primary">💾 Save Account Info</button>
      </div>
    </form>
  </div>

</div><!-- /content -->

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
<script>
const ACTIVE_TAB = '<?= $activeTab ?>';
function switchTab(name) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  event.currentTarget.classList.add('active');
  // Update URL without reload
  history.replaceState(null,'','?tab='+name);
}
// Activate correct tab on load
document.addEventListener('DOMContentLoaded', () => {
  const btn = [...document.querySelectorAll('.tab-btn')].find(b => b.getAttribute('onclick')?.includes("'"+ACTIVE_TAB+"'"));
  if (btn && ACTIVE_TAB !== 'business') btn.click();
});
function previewFile(input, id) {
  const el = document.getElementById(id);
  if (!input.files||!input.files[0]||!el) return;
  const r = new FileReader();
  r.onload = e => { el.src = e.target.result; el.style.display = 'block'; };
  r.readAsDataURL(input.files[0]);
}
</script>
</div></div></body></html>
