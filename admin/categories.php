<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

/* ── Helper: save uploaded images ──────────────────────── */
function saveCategoryImages(array $existing): string {
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $imgs = $existing;
    $files = $_FILES['images'] ?? [];
    $tmpArr  = isset($files['tmp_name']) ? (array)$files['tmp_name'] : [];
    $errArr  = isset($files['error'])    ? (array)$files['error']    : [];
    $nameArr = isset($files['name'])     ? (array)$files['name']     : [];
    if (!empty($tmpArr)) {
        foreach ($tmpArr as $k => $tmp) {
            if (count($imgs) >= 3) break;
            if (empty($tmp) || ($errArr[$k] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            if (!in_array($finfo->file($tmp), ['image/jpeg','image/png','image/webp','image/gif'])) continue;
            if (filesize($tmp) > 5 * 1024 * 1024) continue;
            $ext = strtolower(preg_replace('/[^a-z0-9]/i','',pathinfo($nameArr[$k],PATHINFO_EXTENSION))) ?: 'jpg';
            $fn  = 'cat_'.bin2hex(random_bytes(6)).'.'.$ext;
            if (move_uploaded_file($tmp, UPLOAD_DIR.$fn)) $imgs[] = $fn;
        }
    }
    return implode(',', array_filter($imgs));
}

/* ── POST: add / edit ─────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name       = trim($_POST['name']        ?? '');
    $industryId = (int)($_POST['industry_id'] ?? 0);
    $desc       = trim($_POST['description'] ?? '');
    $order      = (int)($_POST['sort_order'] ?? 0);
    $editId     = (int)($_POST['edit_id']    ?? 0);
    $icon       = trim($_POST['icon']        ?? '');

    if ($name && $industryId) {
        if ($editId) {
            $row = $pdo->prepare("SELECT images FROM categories WHERE id=?"); $row->execute([$editId]); $row=$row->fetch();
            $existing = $row ? array_filter(explode(',', $row['images']??'')) : [];
            $keep = $_POST['keep_images'] ?? [];
            $existing = array_values(array_filter($existing, fn($f)=>in_array($f,$keep)));
            $images = saveCategoryImages($existing);
            $pdo->prepare("UPDATE categories SET name=?,industry_id=?,description=?,sort_order=?,images=?,icon=? WHERE id=?")
                ->execute([$name,$industryId,$desc,$order,$images,$icon,$editId]);
            flash('success','Category updated.');
        } else {
            $images = saveCategoryImages([]);
            $pdo->prepare("INSERT INTO categories (name,industry_id,description,sort_order,images,icon) VALUES(?,?,?,?,?,?)")
                ->execute([$name,$industryId,$desc,$order,$images,$icon]);
            flash('success','Category added.');
        }
    } else {
        flash('error','Category name and industry are required.');
    }
    header('Location: categories.php'.(!empty($_GET['industry_id'])?'?industry_id='.(int)$_GET['industry_id']:'')); exit;
}

/* ── GET: toggle / delete ─────────────────────────────── */
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    match($_GET['action']) {
        'delete' => (function() use($pdo,$id){ $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$id]); flash('success','Category deleted.'); })(),
        'toggle' => $pdo->prepare("UPDATE categories SET status=1-status WHERE id=?")->execute([$id]),
        default  => null
    };
    header('Location: categories.php'); exit;
}

/* ── Edit prefill ─────────────────────────────────────── */
$editing = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM categories WHERE id=?");
    $s->execute([(int)$_GET['edit']]); $editing = $s->fetch();
}

$industries = getAllIndustries($pdo, false);
$filterInd  = (int)($_GET['industry_id'] ?? 0);
$whereSQL   = $filterInd ? "WHERE c.industry_id=$filterInd" : '';

$categories = $pdo->query("
    SELECT c.*, i.name AS industry_name,
           (SELECT COUNT(*) FROM product_types WHERE category_id=c.id) AS type_count
    FROM categories c
    JOIN industries i ON i.id=c.industry_id
    $whereSQL
    ORDER BY i.sort_order, i.name, c.sort_order, c.name
")->fetchAll();

$pageTitle='Categories'; $activePage='categories';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
.img-slot{border:2px dashed var(--border);border-radius:10px;overflow:hidden;aspect-ratio:4/3;position:relative;cursor:pointer;transition:border-color .2s;background:#fafcff}
.img-slot img{width:100%;height:100%;object-fit:cover;display:block}
.img-slot-ph{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;width:100%;height:100%;color:#94a3b8;font-size:11px}
.img-keep-chk{position:absolute;top:4px;left:4px;width:16px;height:16px;accent-color:#10b981;cursor:pointer}
.cat-row-img{width:36px;height:36px;border-radius:8px;object-fit:cover;border:1px solid var(--border);flex-shrink:0}
.cat-row-ph{width:36px;height:36px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:15px;border:1px solid var(--border);flex-shrink:0}
.icon-grid{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.icon-opt{width:34px;height:34px;border:2px solid var(--border);border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:16px;transition:all .15s}
.icon-opt:hover,.icon-opt.sel{border-color:var(--primary);background:#eef2ff}
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Categories</h1>
  </div>
  <div class="topbar-right" style="font-size:13px;color:var(--text-muted)">
    <?= count($categories) ?> total
  </div>
</div>

<div class="content">
  <?= showFlash() ?>
  <div style="display:grid;grid-template-columns:1fr 410px;gap:22px;align-items:start">

    <!-- ── List ─────────────────────────────────────────── -->
    <div class="card" style="margin-top:0">
      <div class="card-header" style="flex-wrap:wrap;gap:10px">
        <h2 style="margin:0">🗂️ All Categories</h2>
        <form method="GET" style="display:flex;gap:8px;align-items:center;margin-left:auto">
          <select name="industry_id" class="form-control" style="width:180px" onchange="this.form.submit()">
            <option value="">All Industries</option>
            <?php foreach ($industries as $ind): ?>
            <option value="<?= $ind['id'] ?>" <?= $filterInd==$ind['id']?'selected':''?>><?= sanitize($ind['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($filterInd): ?><a href="categories.php" class="btn btn-outline btn-xs">✕ Clear</a><?php endif; ?>
        </form>
      </div>

      <?php if ($categories): ?>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr><th>#</th><th colspan="2">Category</th><th>Industry</th><th>Types</th><th>Order</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php foreach ($categories as $i => $cat):
            $catImgs = array_values(array_filter(explode(',', $cat['images']??'')));
            $thumb   = $catImgs[0] ?? null;
          ?>
          <tr <?= ($editing && $editing['id']==$cat['id']) ? 'style="background:#eef2ff"' : '' ?>>
            <td style="color:var(--text-muted);font-size:12px"><?= $i+1 ?></td>
            <td>
              <?php if ($thumb): ?>
                <img src="<?= UPLOAD_URL.sanitize($thumb) ?>" class="cat-row-img" alt="">
              <?php else: ?>
                <div class="cat-row-ph"><?= sanitize($cat['icon']?:'🗂️') ?></div>
              <?php endif; ?>
            </td>
            <td>
              <strong><?= sanitize($cat['name']) ?></strong>
              <?php if (!empty($catImgs)): ?>
                <span style="font-size:10.5px;color:var(--text-muted);margin-left:4px"><?= count($catImgs) ?> img<?= count($catImgs)>1?'s':'' ?></span>
              <?php endif; ?>
            </td>
            <td style="font-size:13px;color:var(--text-muted)"><?= sanitize($cat['industry_name']) ?></td>
            <td><span style="font-weight:600"><?= $cat['type_count'] ?></span></td>
            <td style="color:var(--text-muted)"><?= (int)$cat['sort_order'] ?></td>
            <td><?= statusBadge($cat['status']?'active':'inactive') ?></td>
            <td>
              <div class="td-actions">
                <a href="?edit=<?= $cat['id'] ?>" class="btn btn-outline btn-xs" title="Edit">✏️</a>
                <a href="?action=toggle&id=<?= $cat['id'] ?>" class="btn btn-warning btn-xs" title="Toggle">⏯</a>
                <a href="?action=delete&id=<?= $cat['id'] ?>" class="btn btn-danger btn-xs"
                   onclick="return confirm('Delete category &quot;<?= addslashes(sanitize($cat['name'])) ?>&quot;?')">🗑</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <div class="empty-state"><div class="es-icon">🗂️</div><p>No categories found.</p></div>
      <?php endif; ?>
    </div>

    <!-- ── Add / Edit Form ──────────────────────────────── -->
    <div class="card" style="margin-top:0;position:sticky;top:80px">
      <div class="card-header" style="align-items:center">
        <h2 style="margin:0"><?= $editing ? '✏️ Edit Category' : '➕ Add Category' ?></h2>
        <?php if ($editing): ?>
          <a href="categories.php" class="btn btn-outline btn-xs" style="margin-left:auto">✕ Cancel</a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="cat-form">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <?php if ($editing): ?>
            <input type="hidden" name="edit_id" value="<?= $editing['id'] ?>">
          <?php endif; ?>

          <!-- Industry -->
          <div class="form-group">
            <label class="form-label">Industry <span class="req">*</span></label>
            <select name="industry_id" class="form-control" required>
              <option value="">-- Select Industry --</option>
              <?php foreach ($industries as $ind): ?>
              <option value="<?= $ind['id'] ?>" <?= ($editing['industry_id']??0)==$ind['id']?'selected':''?>><?= sanitize($ind['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Name -->
          <div class="form-group">
            <label class="form-label">Category Name <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" required autofocus
                   value="<?= sanitize($editing['name'] ?? '') ?>"
                   placeholder="e.g. Corrugated Boxes">
          </div>

          <!-- Description -->
          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2" placeholder="Short description for website display"><?= sanitize($editing['description'] ?? '') ?></textarea>
          </div>

          <!-- Icon -->
          <div class="form-group">
            <label class="form-label">Icon <span style="font-weight:400;font-size:12px;color:var(--text-muted)">— fallback when no image</span></label>
            <input type="text" name="icon" id="icon-input" class="form-control" maxlength="4"
                   value="<?= sanitize($editing['icon'] ?? '') ?>" placeholder="Paste emoji e.g. 📦">
            <div class="icon-grid">
              <?php foreach(['📦','🗂️','🧴','🥡','🛍️','📄','🧪','🏗️','⚙️','🌿','♻️','🖨️','🧱','🚚','🛒'] as $em): ?>
              <div class="icon-opt <?= ($editing['icon']??'')===$em?'sel':'' ?>" onclick="pickIcon('<?= $em ?>')" title="<?= $em ?>"><?= $em ?></div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Images -->
          <?php
          $editImgs  = $editing ? array_values(array_filter(explode(',', $editing['images']??''))) : [];
          $slotsUsed = count($editImgs);
          ?>
          <div class="form-group">
            <label class="form-label">
              Images
              <span style="font-weight:400;font-size:12px;color:var(--text-muted)">min 1, max 3 · JPG/PNG/WebP · 5 MB</span>
            </label>

            <!-- Existing images -->
            <?php if ($editImgs): ?>
            <div style="margin-bottom:10px">
              <div style="font-size:11.5px;font-weight:600;color:var(--text-muted);margin-bottom:6px">Current — uncheck to remove:</div>
              <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
                <?php foreach ($editImgs as $fi): ?>
                <div class="img-slot" style="cursor:default">
                  <img src="<?= UPLOAD_URL.sanitize($fi) ?>" alt="">
                  <input type="checkbox" name="keep_images[]" value="<?= sanitize($fi) ?>"
                         class="img-keep-chk" checked title="Uncheck to remove">
                </div>
                <?php endforeach; ?>
                <?php for ($s=0;$s<(3-$slotsUsed);$s++): ?>
                <div class="img-slot" style="cursor:default;border-style:dashed">
                  <div class="img-slot-ph"><span style="color:#e2e8f0;font-size:18px">+</span><span style="color:#e2e8f0">new</span></div>
                </div>
                <?php endfor; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- Drop zone -->
            <div id="drop-zone" style="border:2px dashed var(--border);border-radius:12px;padding:18px;text-align:center;cursor:pointer;transition:border-color .2s;background:#fafcff;position:relative">
              <input type="file" name="images[]" id="img-input" multiple accept="image/jpeg,image/png,image/webp,image/gif"
                     style="position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%"
                     onchange="previewNewImgs(this)">
              <div style="font-size:26px;margin-bottom:5px">📁</div>
              <div style="font-size:12.5px;font-weight:600;color:var(--primary)">Click or drag &amp; drop</div>
              <div style="font-size:11.5px;color:var(--text-muted);margin-top:3px">
                <?= 3-$slotsUsed ?>/3 slots remaining
              </div>
            </div>
            <div id="new-img-status" style="font-size:12px;min-height:16px;margin-top:5px;font-weight:600;color:#f59e0b"></div>
            <div id="new-img-previews" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px"></div>
          </div>

          <!-- Sort order -->
          <div class="form-group">
            <label class="form-label">Sort Order</label>
            <input type="number" name="sort_order" class="form-control" min="0"
                   value="<?= (int)($editing['sort_order'] ?? 0) ?>">
          </div>

          <div style="display:flex;gap:10px">
            <button type="submit" class="btn btn-primary" style="flex:1">
              <?= $editing ? '💾 Update Category' : '➕ Add Category' ?>
            </button>
            <?php if ($editing): ?>
              <a href="categories.php" class="btn btn-outline">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

  </div>
</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
<script>
const MAX_IMGS  = 3;
const usedSlots = <?= $slotsUsed ?? 0 ?>;
let newFiles    = [];

/* ── Icon picker ──────────────────────────────────────────── */
function pickIcon(em) {
  document.getElementById('icon-input').value = em;
  document.querySelectorAll('.icon-opt').forEach(el =>
    el.classList.toggle('sel', el.textContent.trim() === em));
}

/* ── Add files ────────────────────────────────────────────── */
function addNewFiles(fileList) {
  const remaining = MAX_IMGS - usedSlots - newFiles.length;
  Array.from(fileList).forEach(f => {
    if (newFiles.length >= remaining) return;
    if (!f.type.startsWith('image/')) return;
    if (newFiles.some(x => x.name === f.name && x.size === f.size)) return;
    newFiles.push(f);
  });
  renderNewPreviews();
  updateStatus();
}

function removeNewFile(i) {
  newFiles.splice(i, 1);
  renderNewPreviews();
  updateStatus();
}

function updateStatus() {
  const total = usedSlots + newFiles.length;
  const st = document.getElementById('new-img-status');
  st.textContent = newFiles.length
    ? newFiles.length + ' new file(s) ready (' + total + '/3 total)'
    : '';
  st.style.color = total >= MAX_IMGS ? '#10b981' : '#f59e0b';
}

function renderNewPreviews() {
  const c = document.getElementById('new-img-previews');
  c.innerHTML = '';
  newFiles.forEach((f, i) => {
    const r = new FileReader();
    r.onload = ev => {
      const d = document.createElement('div');
      d.style.cssText = 'position:relative;width:66px;height:66px;border-radius:9px;overflow:hidden;border:2px solid #a5b4fc;flex-shrink:0';
      d.innerHTML = `<img src="${ev.target.result}" style="width:100%;height:100%;object-fit:cover;display:block">
        <button type="button" onclick="removeNewFile(${i})"
          style="position:absolute;top:2px;right:2px;background:rgba(239,68,68,.9);color:#fff;border:none;border-radius:50%;width:18px;height:18px;font-size:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0">✕</button>`;
      c.appendChild(d);
    };
    r.readAsDataURL(f);
  });
}

/* ── File input onChange ──────────────────────────────────── */
document.getElementById('img-input').addEventListener('change', function() {
  addNewFiles(this.files);
  this.value = '';
});

/* ── Drag & drop ──────────────────────────────────────────── */
const dz = document.getElementById('drop-zone');
dz.addEventListener('dragover',  e => { e.preventDefault(); dz.style.borderColor='var(--primary)'; dz.style.background='#eff6ff'; });
dz.addEventListener('dragleave', () => { dz.style.borderColor=''; dz.style.background='#fafcff'; });
dz.addEventListener('drop',      e => {
  e.preventDefault();
  dz.style.borderColor = '';
  dz.style.background  = '#fafcff';
  addNewFiles(e.dataTransfer.files);
});

/* ── Submit via FormData with files appended ──────────────── */
document.getElementById('cat-form').addEventListener('submit', function(e) {
  e.preventDefault();

  const hasExisting = document.querySelectorAll('[name="keep_images[]"]:checked').length > 0;
  if (!hasExisting && newFiles.length === 0) {
    if (!confirm('No images selected. Continue without images?')) return;
  }

  const btn = this.querySelector('[type=submit]');
  btn.disabled = true;
  btn.textContent = '⏳ Saving…';

  const fd = new FormData(this);
  fd.delete('images[]');
  newFiles.forEach(f => fd.append('images[]', f, f.name));

  fetch(this.action || window.location.href, { method: 'POST', body: fd })
    .then(res => {
      if (res.redirected) { window.location.href = res.url; return; }
      window.location.href = window.location.pathname;
    })
    .catch(() => {
      this.submit();
    });
});
</script>
</div></div></body></html>
