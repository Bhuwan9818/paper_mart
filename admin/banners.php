<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

// ---- Handle Add / Edit (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $id         = (int)($_POST['id'] ?? 0);
    $title      = trim($_POST['title'] ?? '');
    $subtitle   = trim($_POST['subtitle'] ?? '');
    $linkUrl    = trim($_POST['link_url'] ?? '');
    $buttonText = trim($_POST['button_text'] ?? '');
    $sortOrder  = (int)($_POST['sort_order'] ?? 0);
    $status     = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

    $imageName = null;
    if (!empty($_FILES['image']['name'])) {
        $imageName = uploadImage($_FILES['image'], 'banner');
        if (!$imageName) {
            flash('error', 'Image upload failed. Please use JPG, PNG, GIF or WebP under 5MB.');
            header('Location: banners.php'); exit;
        }
    }

    if ($id) {
        if ($imageName) {
            $old = $pdo->prepare("SELECT image FROM banners WHERE id=?");
            $old->execute([$id]);
            $oldImg = $old->fetchColumn();
            if ($oldImg && file_exists(UPLOAD_DIR . $oldImg)) @unlink(UPLOAD_DIR . $oldImg);

            $stmt = $pdo->prepare("UPDATE banners SET image=?, title=?, subtitle=?, link_url=?, button_text=?, sort_order=?, status=? WHERE id=?");
            $stmt->execute([$imageName, $title, $subtitle, $linkUrl, $buttonText, $sortOrder, $status, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE banners SET title=?, subtitle=?, link_url=?, button_text=?, sort_order=?, status=? WHERE id=?");
            $stmt->execute([$title, $subtitle, $linkUrl, $buttonText, $sortOrder, $status, $id]);
        }
        flash('success', 'Banner updated.');
    } else {
        if (!$imageName) {
            flash('error', 'Please select a banner image.');
            header('Location: banners.php'); exit;
        }
        $stmt = $pdo->prepare("INSERT INTO banners (image,title,subtitle,link_url,button_text,sort_order,status) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$imageName, $title, $subtitle, $linkUrl, $buttonText, $sortOrder, $status]);
        flash('success', 'Banner added.');
    }
    header('Location: banners.php'); exit;
}

// ---- Handle quick actions (GET) ----
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    match ($_GET['action']) {
        'activate'   => $pdo->prepare("UPDATE banners SET status='active' WHERE id=?")->execute([$id]),
        'deactivate' => $pdo->prepare("UPDATE banners SET status='inactive' WHERE id=?")->execute([$id]),
        'delete'     => (function() use ($pdo, $id) {
            $img = $pdo->prepare("SELECT image FROM banners WHERE id=?");
            $img->execute([$id]);
            $fn = $img->fetchColumn();
            if ($fn && file_exists(UPLOAD_DIR . $fn)) @unlink(UPLOAD_DIR . $fn);
            $pdo->prepare("DELETE FROM banners WHERE id=?")->execute([$id]);
        })(),
        'up'   => (function() use ($pdo, $id) {
            $cur = $pdo->prepare("SELECT sort_order FROM banners WHERE id=?"); $cur->execute([$id]);
            $curOrder = $cur->fetchColumn();
            $prev = $pdo->prepare("SELECT id,sort_order FROM banners WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1");
            $prev->execute([$curOrder]); $p = $prev->fetch();
            if ($p) {
                $pdo->prepare("UPDATE banners SET sort_order=? WHERE id=?")->execute([$p['sort_order'], $id]);
                $pdo->prepare("UPDATE banners SET sort_order=? WHERE id=?")->execute([$curOrder, $p['id']]);
            }
        })(),
        'down' => (function() use ($pdo, $id) {
            $cur = $pdo->prepare("SELECT sort_order FROM banners WHERE id=?"); $cur->execute([$id]);
            $curOrder = $cur->fetchColumn();
            $next = $pdo->prepare("SELECT id,sort_order FROM banners WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1");
            $next->execute([$curOrder]); $n = $next->fetch();
            if ($n) {
                $pdo->prepare("UPDATE banners SET sort_order=? WHERE id=?")->execute([$n['sort_order'], $id]);
                $pdo->prepare("UPDATE banners SET sort_order=? WHERE id=?")->execute([$curOrder, $n['id']]);
            }
        })(),
        default => null
    };
    header('Location: banners.php'); exit;
}

$banners = $pdo->query("SELECT * FROM banners ORDER BY sort_order ASC, id ASC")->fetchAll();

$pageTitle = 'Hero Banners'; $activePage = 'banners';
include __DIR__ . '/../includes/head.php';
?>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between">
        <h1>🖼️ Hero Banners <span style="font-size:14px;font-weight:400;color:var(--text-muted)">(<?= count($banners) ?>)</span></h1>
        <button class="btn btn-primary btn-sm" onclick="openBannerModal()">+ Add Banner</button>
    </div>

    <div class="card" style="padding:16px 20px;margin-bottom:18px;background:#fef9ec;border:1px solid #f5deab">
        <strong>ℹ️ How this works:</strong>
        These banners power the rotating hero carousel on the public homepage. Recommended image size
        <strong>1600×600px</strong> (wide landscape), JPG/PNG/WebP, under 5MB. Use ▲ / ▼ to reorder.
        Only <strong>Active</strong> banners appear on the site.
    </div>

    <div class="card">
        <?php if ($banners): ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:50px">Order</th>
                        <th style="width:160px">Preview</th>
                        <th>Title / Subtitle</th>
                        <th>Link</th>
                        <th style="width:90px">Status</th>
                        <th style="width:170px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($banners as $i => $b): ?>
                <tr>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:2px;font-size:12px">
                            <a href="?action=up&id=<?= $b['id'] ?>" style="<?= $i===0 ? 'opacity:.3;pointer-events:none' : '' ?>" title="Move up">▲</a>
                            <a href="?action=down&id=<?= $b['id'] ?>" style="<?= $i===count($banners)-1 ? 'opacity:.3;pointer-events:none' : '' ?>" title="Move down">▼</a>
                        </div>
                    </td>
                    <td>
                        <img src="<?= UPLOAD_URL . sanitize($b['image']) ?>" alt="" style="width:140px;height:52px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">
                    </td>
                    <td>
                        <div style="font-weight:500"><?= sanitize($b['title'] ?: '—') ?></div>
                        <div style="font-size:12px;color:var(--text-muted)"><?= sanitize($b['subtitle'] ?: '') ?></div>
                    </td>
                    <td class="text-muted" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;font-size:12.5px"><?= sanitize($b['link_url'] ?: '—') ?></td>
                    <td>
                        <?php if ($b['status'] === 'active'): ?>
                            <a href="?action=deactivate&id=<?= $b['id'] ?>" class="badge badge-success" title="Click to deactivate">Active</a>
                        <?php else: ?>
                            <a href="?action=activate&id=<?= $b['id'] ?>" class="badge badge-secondary" title="Click to activate">Inactive</a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="td-actions">
                            <button class="btn btn-outline btn-xs" onclick='openBannerModal(<?= json_encode($b, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✏️ Edit</button>
                            <a href="?action=delete&id=<?= $b['id'] ?>" class="btn btn-danger btn-xs" onclick="return confirm('Delete this banner permanently?')">🗑</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="empty-state"><div class="empty-state-icon">🖼️</div><h3>No banners yet</h3><p>Click "+ Add Banner" to create your first hero carousel slide.</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- Add / Edit Modal -->
<div id="banner-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;max-width:520px;width:92%;max-height:90vh;overflow-y:auto">
    <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <h3 id="banner-modal-title" style="margin:0;font-size:17px">Add Banner</h3>
      <button onclick="closeBannerModal()" style="border:none;background:none;font-size:20px;cursor:pointer;color:var(--text-muted)">✕</button>
    </div>
    <form id="banner-form" method="POST" enctype="multipart/form-data" style="padding:22px">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="id" id="b-id" value="">

      <div class="form-group">
        <label class="form-label">Banner Image <span id="b-img-required" class="req">*</span></label>
        <input type="file" name="image" id="b-image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
        <div id="b-current-img" style="margin-top:8px"></div>
        <p style="font-size:11.5px;color:var(--text-muted);margin-top:4px">Recommended: 1600×600px landscape, under 5MB. Leave blank when editing to keep the current image.</p>
      </div>

      <div class="form-group">
        <label class="form-label">Title (optional overlay text)</label>
        <input type="text" name="title" id="b-title" class="form-control" maxlength="150" placeholder="e.g. Premium Kraft Paper — Direct From Mills">
      </div>

      <div class="form-group">
        <label class="form-label">Subtitle (optional)</label>
        <input type="text" name="subtitle" id="b-subtitle" class="form-control" maxlength="255" placeholder="e.g. Flat 10% off on bulk orders this month">
      </div>

      <div style="display:flex;gap:12px">
        <div class="form-group" style="flex:1">
          <label class="form-label">Button Text</label>
          <input type="text" name="button_text" id="b-btn-text" class="form-control" maxlength="60" placeholder="e.g. Shop Now">
        </div>
        <div class="form-group" style="flex:1">
          <label class="form-label">Link URL</label>
          <input type="text" name="link_url" id="b-link" class="form-control" placeholder="e.g. /public/products.php?category=5">
        </div>
      </div>

      <div style="display:flex;gap:12px">
        <div class="form-group" style="flex:1">
          <label class="form-label">Sort Order</label>
          <input type="number" name="sort_order" id="b-sort" class="form-control" value="0">
        </div>
        <div class="form-group" style="flex:1">
          <label class="form-label">Status</label>
          <select name="status" id="b-status" class="form-control">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px">Save Banner</button>
    </form>
  </div>
</div>

<script>
function openBannerModal(data){
  const modal=document.getElementById('banner-modal');
  document.getElementById('banner-form').reset();
  document.getElementById('b-current-img').innerHTML='';
  if(data){
    document.getElementById('banner-modal-title').textContent='Edit Banner';
    document.getElementById('b-id').value=data.id;
    document.getElementById('b-title').value=data.title||'';
    document.getElementById('b-subtitle').value=data.subtitle||'';
    document.getElementById('b-btn-text').value=data.button_text||'';
    document.getElementById('b-link').value=data.link_url||'';
    document.getElementById('b-sort').value=data.sort_order||0;
    document.getElementById('b-status').value=data.status||'active';
    document.getElementById('b-img-required').style.display='none';
    document.getElementById('b-current-img').innerHTML =
      '<img src="<?= UPLOAD_URL ?>'+data.image+'" style="width:160px;height:60px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0">';
  } else {
    document.getElementById('banner-modal-title').textContent='Add Banner';
    document.getElementById('b-id').value='';
    document.getElementById('b-img-required').style.display='inline';
  }
  modal.style.display='flex';
}
function closeBannerModal(){ document.getElementById('banner-modal').style.display='none'; }
document.getElementById('banner-modal').addEventListener('click',function(e){ if(e.target===this) closeBannerModal(); });
</script>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
