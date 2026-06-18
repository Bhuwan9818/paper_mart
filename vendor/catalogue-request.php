<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('vendor');
$user = currentUser();
$uid  = $user['id'];

// ── Handle submission ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $type      = in_array($_POST['request_type'] ?? '', ['industry','category','product_type']) ? $_POST['request_type'] : '';
    $name      = trim($_POST['name'] ?? '');
    $parentId  = (int)($_POST['parent_id'] ?? 0);
    $parentName= trim($_POST['parent_name'] ?? '');
    $desc      = trim($_POST['description'] ?? '');
    $reason    = trim($_POST['reason'] ?? '');

    if (!$type || !$name) {
        $error = 'Please fill in the request type and name.';
    } elseif ($type !== 'industry' && !$parentId) {
        $error = 'Please select a parent industry or category.';
    } else {
        // Duplicate guard
        $dup = $pdo->prepare("SELECT id FROM catalogue_requests WHERE vendor_id=? AND request_type=? AND name=? AND status='pending'");
        $dup->execute([$uid, $type, $name]);
        if ($dup->fetch()) {
            $error = 'You already have a pending request for "' . htmlspecialchars($name) . '".';
        } else {
            $pdo->prepare("INSERT INTO catalogue_requests (vendor_id,request_type,parent_id,parent_name,name,description,reason) VALUES(?,?,?,?,?,?,?)")
                ->execute([$uid, $type, $parentId ?: null, $parentName, $name, $desc, $reason]);
            flash('success', 'Your request has been submitted! The admin will review it shortly.');
            header('Location: catalogue-request.php'); exit;
        }
    }
}

// ── Load vendor's past requests ────────────────────────────────
$myRequests = $pdo->prepare("SELECT * FROM catalogue_requests WHERE vendor_id=? ORDER BY created_at DESC LIMIT 30");
$myRequests->execute([$uid]); $myRequests = $myRequests->fetchAll();

// For parent dropdowns
$industries = $pdo->query("SELECT id,name FROM industries WHERE status=1 ORDER BY name")->fetchAll();

$pageTitle  = 'Catalogue Request';
$activePage = 'catalogue-request';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
.req-type-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:20px; }
.req-type-card {
  border:2px solid var(--border); border-radius:var(--radius); padding:16px 14px;
  cursor:pointer; text-align:center; transition:all .18s; background:#fff;
}
.req-type-card:hover { border-color:var(--primary); background:var(--primary-light); }
.req-type-card.selected { border-color:var(--primary); background:var(--primary-light); }
.req-type-card .rtc-icon { font-size:26px; margin-bottom:6px; }
.req-type-card .rtc-label { font-size:13px; font-weight:700; }
.req-type-card .rtc-sub   { font-size:11.5px; color:var(--text-muted); margin-top:2px; }

.req-history-badge { display:inline-flex; align-items:center; gap:5px; font-size:12px; padding:3px 10px; border-radius:100px; font-weight:600; }
.rbadge-pending  { background:#fef3c7; color:#92400e; }
.rbadge-approved { background:#d1fae5; color:#065f46; }
.rbadge-rejected { background:#fee2e2; color:#991b1b; }
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Catalogue Request</h1>
  </div>
  <div class="topbar-right">
    <a href="<?= BASE_URL ?>/vendor/add-product.php" class="btn btn-outline btn-sm">← Back to Add Product</a>
  </div>
</div>

<div class="content">
  <?= showFlash() ?>
  <?php if (!empty($error)): ?><div class="alert alert-error">⚠️ <?= sanitize($error) ?></div><?php endif; ?>

  <div class="page-header">
    <div>
      <h1>🗂️ Request New Catalogue Entry</h1>
      <div class="breadcrumb">Can't find your industry, category, or product type? Submit a request for admin approval.</div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 380px;gap:22px;align-items:start">

    <!-- ── Request Form ── -->
    <div class="card">
      <div class="card-header"><h2>📝 New Request</h2></div>
      <div class="card-body">
        <form method="POST" id="req-form">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="request_type" id="f_request_type" value="">
          <input type="hidden" name="parent_id"    id="f_parent_id"    value="0">
          <input type="hidden" name="parent_name"  id="f_parent_name"  value="">

          <!-- Type selector -->
          <div class="form-group">
            <label class="form-label">What are you requesting? <span class="req">*</span></label>
            <div class="req-type-grid">
              <div class="req-type-card" id="card-industry" onclick="selectType('industry')">
                <div class="rtc-icon">🏭</div>
                <div class="rtc-label">New Industry</div>
                <div class="rtc-sub">e.g. Electronics, Textile</div>
              </div>
              <div class="req-type-card" id="card-category" onclick="selectType('category')">
                <div class="rtc-icon">🗂️</div>
                <div class="rtc-label">New Category</div>
                <div class="rtc-sub">Under an existing industry</div>
              </div>
              <div class="req-type-card" id="card-product_type" onclick="selectType('product_type')">
                <div class="rtc-icon">🔖</div>
                <div class="rtc-label">New Product Type</div>
                <div class="rtc-sub">Under an existing category</div>
              </div>
            </div>
          </div>

          <!-- Parent: Industry selector (for category) -->
          <div class="form-group" id="parent-industry-group" style="display:none">
            <label class="form-label">Parent Industry <span class="req">*</span></label>
            <select class="form-control" id="sel-parent-industry" onchange="parentIndustryChanged(this)">
              <option value="">-- Select Industry --</option>
              <?php foreach($industries as $ind): ?>
              <option value="<?= $ind['id'] ?>" data-name="<?= sanitize($ind['name']) ?>"><?= sanitize($ind['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Parent: Category selector (for product_type) -->
          <div class="form-group" id="parent-category-group" style="display:none">
            <label class="form-label">Parent Industry <span class="req">*</span></label>
            <select class="form-control" id="sel-pt-industry" onchange="loadCategoriesForPT(this.value)">
              <option value="">-- Select Industry --</option>
              <?php foreach($industries as $ind): ?>
              <option value="<?= $ind['id'] ?>"><?= sanitize($ind['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <label class="form-label" style="margin-top:10px">Parent Category <span class="req">*</span></label>
            <select class="form-control" id="sel-parent-category" onchange="parentCategoryChanged(this)">
              <option value="">-- Select Category --</option>
            </select>
          </div>

          <!-- Name -->
          <div class="form-group" id="name-group" style="display:none">
            <label class="form-label" id="name-label">Name <span class="req">*</span></label>
            <input type="text" name="name" id="f_name" class="form-control" placeholder="Enter name…" maxlength="150">
          </div>

          <!-- Description -->
          <div class="form-group" id="desc-group" style="display:none">
            <label class="form-label">Description <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
            <textarea name="description" id="f_desc" class="form-control" rows="2" placeholder="Short description…"></textarea>
          </div>

          <!-- Reason -->
          <div class="form-group" id="reason-group" style="display:none">
            <label class="form-label">Why do you need this? <span class="req">*</span></label>
            <textarea name="reason" id="f_reason" class="form-control" rows="3" placeholder="Explain why this entry is needed…"></textarea>
            <div class="form-text">Providing a clear reason helps admin approve faster.</div>
          </div>

          <div id="submit-group" style="display:none">
            <button type="submit" class="btn btn-primary">🚀 Submit Request</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── My Past Requests ── -->
    <div class="card">
      <div class="card-header"><h2>📋 My Requests</h2></div>
      <?php if ($myRequests): ?>
      <div style="padding:0 4px">
        <?php foreach($myRequests as $r): ?>
        <?php
          $badgeClass = match($r['status']) { 'approved'=>'rbadge-approved', 'rejected'=>'rbadge-rejected', default=>'rbadge-pending' };
          $typeIcon   = match($r['request_type']) { 'industry'=>'🏭', 'category'=>'🗂️', default=>'🔖' };
        ?>
        <div style="padding:14px;border-bottom:1px solid var(--border-light)">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:4px">
            <div style="font-weight:600;font-size:13.5px"><?=$typeIcon?> <?= sanitize($r['name']) ?></div>
            <span class="req-history-badge <?=$badgeClass?>"><?= ucfirst($r['status']) ?></span>
          </div>
          <?php if($r['parent_name']): ?><div style="font-size:12px;color:var(--text-muted)">Under: <?= sanitize($r['parent_name']) ?></div><?php endif; ?>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:3px"><?= ucfirst($r['request_type']) ?> · <?= timeAgo($r['created_at']) ?></div>
          <?php if($r['status']==='rejected' && $r['admin_note']): ?>
          <div style="margin-top:6px;padding:6px 10px;background:#fee2e2;border-radius:6px;font-size:12px;color:#991b1b">
            Admin note: <?= sanitize($r['admin_note']) ?>
          </div>
          <?php endif; ?>
          <?php if($r['status']==='approved' && $r['created_id']): ?>
          <div style="margin-top:6px;font-size:12px;color:var(--success)">✅ Added to catalogue — you can now use it when adding products.</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state"><div class="es-icon">📋</div><p>No requests yet.</p></div>
      <?php endif; ?>
    </div>

  </div>
</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
<script>
const BASE = document.querySelector('meta[name="base-url"]').content;
let currentType = '';

function selectType(type) {
  currentType = type;
  document.getElementById('f_request_type').value = type;
  ['industry','category','product_type'].forEach(t => {
    document.getElementById('card-' + t).classList.toggle('selected', t === type);
  });
  document.getElementById('parent-industry-group').style.display  = type === 'category'     ? '' : 'none';
  document.getElementById('parent-category-group').style.display  = type === 'product_type' ? '' : 'none';
  document.getElementById('name-group').style.display   = '';
  document.getElementById('desc-group').style.display   = '';
  document.getElementById('reason-group').style.display = '';
  document.getElementById('submit-group').style.display = '';
  const labels = {industry:'Industry Name', category:'Category Name', product_type:'Product Type Name'};
  document.getElementById('name-label').innerHTML = (labels[type]||'Name') + ' <span class="req">*</span>';
  // Reset parent
  document.getElementById('f_parent_id').value   = '0';
  document.getElementById('f_parent_name').value = '';
}

function parentIndustryChanged(sel) {
  document.getElementById('f_parent_id').value   = sel.value;
  document.getElementById('f_parent_name').value = sel.options[sel.selectedIndex]?.dataset.name || '';
}

function loadCategoriesForPT(industryId) {
  const sel = document.getElementById('sel-parent-category');
  sel.innerHTML = '<option value="">Loading…</option>';
  document.getElementById('f_parent_id').value   = '0';
  document.getElementById('f_parent_name').value = '';
  if (!industryId) { sel.innerHTML='<option value="">-- Select Category --</option>'; return; }
  fetch(BASE + '/ajax/get-categories.php?industry_id=' + industryId)
    .then(r=>r.json()).then(data=>{
      sel.innerHTML = '<option value="">-- Select Category --</option>';
      data.forEach(c=>{ const o=document.createElement('option'); o.value=c.id; o.textContent=c.name; sel.appendChild(o); });
    });
}

function parentCategoryChanged(sel) {
  document.getElementById('f_parent_id').value   = sel.value;
  document.getElementById('f_parent_name').value = sel.options[sel.selectedIndex]?.textContent.trim() || '';
}

document.getElementById('req-form').addEventListener('submit', function(e){
  if (!currentType) { e.preventDefault(); alert('Please select a request type.'); return; }
  if (!document.getElementById('f_name').value.trim()) { e.preventDefault(); alert('Please enter a name.'); return; }
  if (!document.getElementById('f_reason').value.trim()) { e.preventDefault(); alert('Please provide a reason.'); return; }
  if (currentType !== 'industry' && !document.getElementById('f_parent_id').value*1) { e.preventDefault(); alert('Please select a parent.'); return; }
});

document.getElementById('hamburger').addEventListener('click',()=>{ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebar-overlay').classList.add('show'); });
document.getElementById('sidebar-overlay').addEventListener('click',()=>{ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebar-overlay').classList.remove('show'); });
</script>
</div></div></body></html>
