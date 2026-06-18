<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

/* ── Helpers ──────────────────────────────────────────────── */
function saveIndustryImages(array $existing): string {
    global $pdo;
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $imgs = $existing;                         // keep already-saved filenames
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
            $ext  = strtolower(preg_replace('/[^a-z0-9]/i','',pathinfo($nameArr[$k],PATHINFO_EXTENSION))) ?: 'jpg';
            $fn   = 'ind_'.bin2hex(random_bytes(6)).'.'.$ext;
            if (move_uploaded_file($tmp, UPLOAD_DIR.$fn)) $imgs[] = $fn;
        }
    }
    return implode(',', array_filter($imgs));
}

/* ── POST: add / edit ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name   = trim($_POST['name']        ?? '');
    $desc   = trim($_POST['description'] ?? '');
    $order  = (int)($_POST['sort_order'] ?? 0);
    $editId = (int)($_POST['edit_id']    ?? 0);
    $icon   = trim($_POST['icon']        ?? '');

    if ($name) {
        if ($editId) {
            /* Load existing images so we can merge */
            $row = $pdo->prepare("SELECT images FROM industries WHERE id=?"); $row->execute([$editId]); $row=$row->fetch();
            $existing = $row ? array_filter(explode(',', $row['images']??'')) : [];

            /* Remove images the admin unchecked */
            $keep = $_POST['keep_images'] ?? [];
            $existing = array_values(array_filter($existing, fn($f)=>in_array($f,$keep)));

            $images = saveIndustryImages($existing);
            $pdo->prepare("UPDATE industries SET name=?,description=?,sort_order=?,images=?,icon=? WHERE id=?")
                ->execute([$name,$desc,$order,$images,$icon,$editId]);
            flash('success','Industry updated.');
        } else {
            $images = saveIndustryImages([]);
            $pdo->prepare("INSERT INTO industries (name,description,sort_order,images,icon) VALUES(?,?,?,?,?)")
                ->execute([$name,$desc,$order,$images,$icon]);
            flash('success','Industry added.');
        }
    } else {
        flash('error','Industry name is required.');
    }
    header('Location: industries.php'); exit;
}

/* ── GET: toggle / delete ─────────────────────────────────── */
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    match($_GET['action']) {
        'delete' => (function() use($pdo,$id){
            $pdo->prepare("DELETE FROM industries WHERE id=?")->execute([$id]);
            flash('success','Industry deleted.');
        })(),
        'toggle' => $pdo->prepare("UPDATE industries SET status=1-status WHERE id=?")->execute([$id]),
        default  => null
    };
    header('Location: industries.php'); exit;
}

/* ── Edit prefill ─────────────────────────────────────────── */
$editing = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM industries WHERE id=?");
    $s->execute([(int)$_GET['edit']]); $editing = $s->fetch();
}

$industries = $pdo->query("
    SELECT i.*, (SELECT COUNT(*) FROM categories WHERE industry_id=i.id) AS cat_count
    FROM industries i ORDER BY i.sort_order, i.name
")->fetchAll();

$pageTitle='Industries'; $activePage='industries';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
.img-upload-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:10px}
.img-slot{border:2px dashed var(--border);border-radius:10px;overflow:hidden;aspect-ratio:4/3;position:relative;cursor:pointer;transition:border-color .2s;background:#fafcff}
.img-slot:hover{border-color:var(--primary)}
.img-slot img{width:100%;height:100%;object-fit:cover;display:block}
.img-slot-ph{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;color:#94a3b8;font-size:11px;font-weight:600}
.img-slot-ph span{font-size:13px}
.img-remove{position:absolute;top:4px;right:4px;background:rgba(239,68,68,.9);color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:2}
.img-keep-chk{position:absolute;top:4px;left:4px;width:16px;height:16px;accent-color:#10b981;cursor:pointer}
.ind-row-img{width:38px;height:38px;border-radius:8px;object-fit:cover;border:1px solid var(--border)}
.ind-row-img-ph{width:38px;height:38px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:16px;border:1px solid var(--border)}
.icon-grid{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.icon-opt{width:36px;height:36px;border:2px solid var(--border);border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:18px;transition:all .15s}
.icon-opt:hover,.icon-opt.sel{border-color:var(--primary);background:#eef2ff}
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Industries</h1>
  </div>
  <div class="topbar-right" style="font-size:13px;color:var(--text-muted)">
    <?= count($industries) ?> total
  </div>
</div>

<div class="content">
  <?= showFlash() ?>
  <div style="display:grid;grid-template-columns:1fr 400px;gap:22px;align-items:start">

    <!-- ── List ───────────────────────────────────────────── -->
    <div class="card" style="margin-top:0">
      <div class="card-header"><h2>🏭 All Industries</h2></div>
      <?php if ($industries): ?>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr><th>#</th><th colspan="2">Industry</th><th>Categories</th><th>Order</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php foreach ($industries as $i => $ind):
            $indImgs = array_values(array_filter(explode(',', $ind['images']??'')));
            $thumb   = $indImgs[0] ?? null;
          ?>
          <tr <?= ($editing && $editing['id']==$ind['id']) ? 'style="background:#eef2ff"' : '' ?>>
            <td style="color:var(--text-muted);font-size:12px"><?= $i+1 ?></td>
            <td>
              <?php if ($thumb): ?>
                <img src="<?= UPLOAD_URL.sanitize($thumb) ?>" class="ind-row-img" alt="">
              <?php else: ?>
                <div class="ind-row-img-ph"><?= sanitize($ind['icon']?:'🏭') ?></div>
              <?php endif; ?>
            </td>
            <td>
              <strong><?= sanitize($ind['name']) ?></strong>
              <?php if (!empty($indImgs)): ?>
                <span style="font-size:10.5px;color:var(--text-muted);margin-left:4px"><?= count($indImgs) ?> img<?= count($indImgs)>1?'s':'' ?></span>
              <?php endif; ?>
              <?php if ($ind['description']): ?>
                <div style="font-size:11.5px;color:var(--text-muted);margin-top:1px"><?= sanitize(mb_strimwidth($ind['description'],0,50,'…')) ?></div>
              <?php endif; ?>
            </td>
            <td><span style="font-weight:600"><?= $ind['cat_count'] ?></span></td>
            <td style="color:var(--text-muted)"><?= (int)$ind['sort_order'] ?></td>
            <td><?= statusBadge($ind['status']?'active':'inactive') ?></td>
            <td>
              <div class="td-actions">
                <a href="?edit=<?= $ind['id'] ?>" class="btn btn-outline btn-xs" title="Edit">✏️</a>
                <a href="?action=toggle&id=<?= $ind['id'] ?>" class="btn btn-warning btn-xs" title="Toggle">⏯</a>
                <a href="?action=delete&id=<?= $ind['id'] ?>" class="btn btn-danger btn-xs"
                   onclick="return confirm('Delete industry &quot;<?= addslashes(sanitize($ind['name'])) ?>&quot;? All categories and product types under it will also be deleted.')">🗑</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <div class="empty-state"><div class="es-icon">🏭</div><p>No industries yet.</p></div>
      <?php endif; ?>
    </div>

    <!-- ── Add / Edit Form ────────────────────────────────── -->
    <div class="card" style="margin-top:0;position:sticky;top:80px">
      <div class="card-header" style="align-items:center">
        <h2 style="margin:0"><?= $editing ? '✏️ Edit Industry' : '➕ Add Industry' ?></h2>
        <?php if ($editing): ?>
          <a href="industries.php" class="btn btn-outline btn-xs" style="margin-left:auto">✕ Cancel</a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="ind-form">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <?php if ($editing): ?>
            <input type="hidden" name="edit_id" value="<?= $editing['id'] ?>">
          <?php endif; ?>

          <!-- Name -->
          <div class="form-group">
            <label class="form-label">Industry Name <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" required autofocus
                   value="<?= sanitize($editing['name'] ?? '') ?>"
                   placeholder="e.g. Paper &amp; Packaging">
          </div>

          <!-- Description -->
          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2" placeholder="Brief description shown on the website"><?= sanitize($editing['description'] ?? '') ?></textarea>
          </div>

          <!-- Icon picker -->
          <div class="form-group">
            <label class="form-label">Icon <span style="font-weight:400;font-size:12px;color:var(--text-muted)">— shown when no image is set</span></label>
            <input type="text" name="icon" id="icon-input" class="form-control" maxlength="4"
                   value="<?= sanitize($editing['icon'] ?? '') ?>" placeholder="Paste any emoji e.g. 🏭">
            <div class="icon-grid" id="icon-grid">
              <?php foreach(['🏭','📦','🌿','♻️','🧴','🥡','📄','🧪','🏗️','⚙️','🛒','🥗'] as $em): ?>
              <div class="icon-opt <?= ($editing['icon']??'')===$em?'sel':'' ?>" onclick="pickIcon('<?= $em ?>')" title="<?= $em ?>"><?= $em ?></div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Images -->
          <div class="form-group">
            <label class="form-label">
              Images
              <span style="font-weight:400;font-size:12px;color:var(--text-muted)">— min 1, max 3 · JPG / PNG / WebP · 5 MB each</span>
            </label>

            <?php
            $editImgs = $editing ? array_values(array_filter(explode(',', $editing['images']??''))) : [];
            $slotsUsed = count($editImgs);
            $newSlots  = max(0, 3 - $slotsUsed);
            ?>

            <!-- Existing images (edit mode) -->
            <?php if ($editImgs): ?>
            <div style="margin-bottom:10px">
              <div style="font-size:11.5px;font-weight:600;color:var(--text-muted);margin-bottom:6px">Current images — uncheck to remove:</div>
              <div class="img-upload-grid">
                <?php foreach ($editImgs as $fi): ?>
                <div class="img-slot" style="cursor:default">
                  <img src="<?= UPLOAD_URL.sanitize($fi) ?>" alt="">
                  <input type="checkbox" name="keep_images[]" value="<?= sanitize($fi) ?>"
                         class="img-keep-chk" checked title="Uncheck to remove">
                </div>
                <?php endforeach; ?>
                <?php for ($s=0;$s<(3-$slotsUsed);$s++): ?>
                <div class="img-slot img-slot-ph" style="cursor:default;border-style:dashed">
                  <span style="font-size:16px;color:#e2e8f0">+</span>
                  <span style="color:#e2e8f0">Upload new</span>
                </div>
                <?php endfor; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- New file upload drop zone -->
            <div id="drop-zone" style="border:2px dashed var(--border);border-radius:12px;padding:20px;text-align:center;cursor:pointer;transition:border-color .2s;background:#fafcff;position:relative">
              <input type="file" name="images[]" id="img-input" multiple accept="image/jpeg,image/png,image/webp,image/gif"
                     style="position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%"
                     onchange="previewNewImgs(this)">
              <div style="font-size:28px;margin-bottom:6px">📁</div>
              <div style="font-size:13px;font-weight:600;color:var(--primary)">Click or drag &amp; drop images</div>
              <div style="font-size:11.5px;color:var(--text-muted);margin-top:3px">
                You can add <?= $slotsUsed ? (3-$slotsUsed) : 3 ?> more image<?= ($slotsUsed===2)?'':'s' ?> (<?= 3-$slotsUsed ?>/3 remaining)
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
            <div class="form-text">Lower number = shown first on the website.</div>
          </div>

          <div style="display:flex;gap:10px">
            <button type="submit" class="btn btn-primary" style="flex:1">
              <?= $editing ? '💾 Update Industry' : '➕ Add Industry' ?>
            </button>
            <?php if ($editing): ?>
              <a href="industries.php" class="btn btn-outline">Cancel</a>
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
let newFiles    = [];   // File objects — submitted via FormData on form submit

/* ── Icon picker ──────────────────────────────────────────── */
function pickIcon(em) {
  document.getElementById('icon-input').value = em;
  document.querySelectorAll('.icon-opt').forEach(el =>
    el.classList.toggle('sel', el.textContent.trim() === em));
}

/* ── Add files to newFiles array ──────────────────────────── */
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
    ? newFiles.length + ' new file(s) ready to upload (' + total + '/3 total)'
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
      d.style.cssText = 'position:relative;width:72px;height:72px;border-radius:9px;overflow:hidden;border:2px solid #a5b4fc;flex-shrink:0';
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
  this.value = ''; // reset so same file can be re-picked
});

/* ── Drag & drop ──────────────────────────────────────────── */
const dz = document.getElementById('drop-zone');
dz.addEventListener('dragover',  e => { e.preventDefault(); dz.style.borderColor = 'var(--primary)'; dz.style.background = '#eff6ff'; });
dz.addEventListener('dragleave', () => { dz.style.borderColor = ''; dz.style.background = '#fafcff'; });
dz.addEventListener('drop',      e => {
  e.preventDefault();
  dz.style.borderColor = '';
  dz.style.background  = '#fafcff';
  addNewFiles(e.dataTransfer.files);
});

/* ── Submit: inject files into FormData and POST via fetch ── */
document.getElementById('ind-form').addEventListener('submit', function(e) {
  e.preventDefault();

  const hasExisting = document.querySelectorAll('[name="keep_images[]"]:checked').length > 0;
  if (!hasExisting && newFiles.length === 0) {
    if (!confirm('No images selected. Continue without images?')) return;
  }

  const btn = this.querySelector('[type=submit]');
  btn.disabled = true;
  btn.textContent = '⏳ Saving…';

  const fd = new FormData(this);
  // Remove the blank file input (it has no files since we manage newFiles separately)
  fd.delete('images[]');
  // Append each file individually
  newFiles.forEach(f => fd.append('images[]', f, f.name));

  fetch(this.action || window.location.href, { method: 'POST', body: fd })
    .then(res => {
      // Server redirects on success — follow it
      if (res.redirected) { window.location.href = res.url; return; }
      // If no redirect, just go back to the page
      window.location.href = window.location.pathname;
    })
    .catch(() => {
      // Fallback: submit normally
      this.removeEventListener('submit', arguments.callee);
      this.submit();
    });
});
</script>
</div></div></body></html>
