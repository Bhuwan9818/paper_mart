<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

$pid = (int)($_GET['id'] ?? 0);
if (!$pid) { flash('error','Invalid product.'); header('Location: products.php'); exit; }

/* ── Load product ──────────────────────────────────────── */
$p = $pdo->prepare("SELECT p.*,u.name AS vendor_name,u.company AS vendor_company FROM products p JOIN users u ON u.id=p.vendor_id WHERE p.id=?");
$p->execute([$pid]); $p = $p->fetch();
if (!$p) { flash('error','Product not found.'); header('Location: products.php'); exit; }

$attrs = $pdo->prepare("SELECT * FROM product_attributes WHERE product_id=? AND attribute_name NOT LIKE '__row_%' AND attribute_name NOT LIKE '__price_%' ORDER BY sort_order");
$attrs->execute([$pid]); $attrs = $attrs->fetchAll();

$industries = $pdo->query("SELECT * FROM industries WHERE status=1 ORDER BY sort_order,name")->fetchAll();
$vendors    = $pdo->query("SELECT id,name,company FROM users WHERE role='vendor' AND status='active' ORDER BY name")->fetchAll();

/* ── POST handler ──────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name       = trim($_POST['name']          ?? '');
    $desc       = trim($_POST['description']   ?? '');
    $shortDesc  = trim($_POST['short_desc']    ?? '');
    $tags       = trim($_POST['tags']          ?? '');
    $priceRange = trim($_POST['price_range']   ?? '');
    $moq        = trim($_POST['min_order_qty'] ?? '');
    $status     = in_array($_POST['status']??'',['active','inactive','pending']) ? $_POST['status'] : 'pending';
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
    $unit       = (int)($_POST['unit_qty']     ?? 0);
    $machine    = (int)($_POST['machine_count']?? 0);
    $vendorId   = (int)($_POST['vendor_id']    ?? $p['vendor_id']);
    $industryId = (int)($_POST['industry_id']  ?? $p['industry_id']);
    $categoryId = (int)($_POST['category_id']  ?? $p['category_id']);
    $typeId     = (int)($_POST['product_type_id']?? $p['product_type_id']);
    $views      = (int)($_POST['views']        ?? $p['views']);

    /* Images — keep existing, handle removals and new uploads */
    $existingImgs = array_filter(explode(',', $p['images'] ?? ''));
    $keepImgs     = $_POST['keep_images'] ?? [];
    $keptImgs     = array_values(array_filter($existingImgs, fn($f) => in_array(trim($f), $keepImgs)));

    /* New images uploaded via AJAX (from uploaded_images JSON) */
    $newImgs = [];
    $uploadedJson = trim($_POST['uploaded_images_json'] ?? '[]');
    $uploadedNew  = json_decode($uploadedJson, true) ?: [];
    foreach ($uploadedNew as $fn) {
        $fn = basename((string)$fn);
        if (preg_match('/^prod_[a-f0-9]+\.[a-z]{2,4}$/i',$fn) && file_exists(UPLOAD_DIR.$fn))
            $newImgs[] = $fn;
    }
    $allImgs = array_merge($keptImgs, $newImgs);

    /* Slug */
    $slug = trim($_POST['slug'] ?? '');
    if (!$slug) $slug = $pid.'-'.strtolower(preg_replace('/[^a-z0-9]+/i','-',$name));

    $pdo->prepare("UPDATE products SET
        vendor_id=?,industry_id=?,category_id=?,product_type_id=?,
        name=?,slug=?,description=?,short_desc=?,tags=?,
        price_range=?,min_order_qty=?,images=?,status=?,is_featured=?,
        unit_qty=?,machine_count=?,views=?,updated_at=NOW()
        WHERE id=?")
        ->execute([$vendorId,$industryId,$categoryId,$typeId,
                   $name,$slug,$desc,$shortDesc,$tags,
                   $priceRange,$moq,implode(',',$allImgs),$status,$isFeatured,
                   $unit,$machine,$views,$pid]);

    /* Update attributes */
    foreach (($attrs) as $a) {
        $val = trim($_POST['attr_'.$a['id']] ?? '');
        $pdo->prepare("UPDATE product_attributes SET attribute_value=? WHERE id=?")->execute([$val,$a['id']]);
    }
    /* Add new custom attribute if provided */
    if (!empty($_POST['new_attr_name'])) {
        $pdo->prepare("INSERT INTO product_attributes (product_id,attribute_name,attribute_value,attribute_unit,sort_order) VALUES(?,?,?,?,?)")
            ->execute([$pid,trim($_POST['new_attr_name']),trim($_POST['new_attr_value']??''),trim($_POST['new_attr_unit']??''),count($attrs)+10]);
    }

    /* Notify vendor if status changed */
    if ($status !== $p['status']) {
        $msg = match($status) {
            'active'   => 'Your product "'.$name.'" has been approved and is now live.',
            'inactive' => 'Your product "'.$name.'" has been deactivated by admin.',
            'pending'  => 'Your product "'.$name.'" has been set back to pending review.',
            default    => ''
        };
        if ($msg) try {
            $pdo->prepare("INSERT INTO notifications (user_id,title,message,link) VALUES(?,?,?,?)")
                ->execute([$vendorId,'📦 Product Update',$msg,BASE_URL.'/vendor/manage-products.php']);
        } catch(Exception $e){}
    }

    flash('success','Product updated successfully.');
    header("Location: edit-product.php?id=$pid"); exit;
}

/* Delete attribute */
if (isset($_GET['del_attr'])) {
    $pdo->prepare("DELETE FROM product_attributes WHERE id=? AND product_id=?")->execute([(int)$_GET['del_attr'],$pid]);
    flash('success','Attribute removed.');
    header("Location: edit-product.php?id=$pid"); exit;
}

$imgArr = array_filter(explode(',',$p['images']??''));
$pageTitle  = 'Edit: '.sanitize($p['name']);
$activePage = 'products';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
.section-card{margin-bottom:20px}
.img-grid{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px}
.img-item{position:relative;width:90px;height:90px;border-radius:10px;overflow:hidden;border:2px solid var(--border)}
.img-item img{width:100%;height:100%;object-fit:cover;display:block}
.img-item-rm{position:absolute;top:2px;right:2px;background:rgba(239,68,68,.9);color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.img-new-grid{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.img-prev{position:relative;width:80px;height:80px;border-radius:8px;overflow:hidden;border:2px solid #a5b4fc}
.img-prev img{width:100%;height:100%;object-fit:cover}
.img-prev-rm{position:absolute;top:2px;right:2px;background:rgba(239,68,68,.9);color:#fff;border:none;border-radius:50%;width:18px;height:18px;font-size:10px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.feat-toggle{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:12px;border:2px solid var(--border);cursor:pointer;transition:all .2s;background:#fff}
.feat-toggle:has(input:checked){border-color:#f59e0b;background:#fffbeb}
.feat-toggle input{width:20px;height:20px;accent-color:#f59e0b;cursor:pointer;flex-shrink:0}
.drop-zone{border:2px dashed var(--border);border-radius:10px;padding:18px;text-align:center;cursor:pointer;transition:all .2s;background:#fafcff;position:relative}
.drop-zone:hover{border-color:var(--primary);background:#eff6ff}
.drop-zone input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.attr-row{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--border)}
.attr-row:last-child{border-bottom:none}
.toggle-switch{position:relative;width:44px;height:24px;flex-shrink:0}
.toggle-switch input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:#cbd5e1;border-radius:100px;cursor:pointer;transition:.2s}
.toggle-slider::before{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
.toggle-switch input:checked+.toggle-slider{background:var(--primary)}
.toggle-switch input:checked+.toggle-slider::before{transform:translateX(20px)}
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Edit Product</h1>
  </div>
  <div class="topbar-right" style="gap:8px">
    <a href="view-product.php?id=<?= $pid ?>" class="btn btn-outline btn-sm">👁 View</a>
    <a href="products.php" class="btn btn-outline btn-sm">← All Products</a>
  </div>
</div>

<div class="content">
  <?= showFlash() ?>

  <form method="POST" id="edit-form">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="uploaded_images_json" id="uploaded_images_json" value="[]">

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start">

      <!-- LEFT COLUMN -->
      <div>

        <!-- Basic Info -->
        <div class="card section-card">
          <div class="card-header"><h2>📦 Basic Information</h2></div>
          <div class="card-body">
            <div class="form-group">
              <label class="form-label">Product Name <span class="req">*</span></label>
              <input type="text" name="name" class="form-control" value="<?= sanitize($p['name']) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Short Description</label>
              <input type="text" name="short_desc" class="form-control" maxlength="300"
                     value="<?= sanitize($p['short_desc']??'') ?>" placeholder="One-line summary shown in listings">
            </div>
            <div class="form-group">
              <label class="form-label">Full Description</label>
              <textarea name="description" class="form-control" rows="5"><?= sanitize($p['description']??'') ?></textarea>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Price Range</label>
                <input type="text" name="price_range" class="form-control" value="<?= sanitize($p['price_range']??'') ?>" placeholder="₹40–₹55/kg">
              </div>
              <div class="form-group">
                <label class="form-label">Min Order Qty</label>
                <input type="text" name="min_order_qty" class="form-control" value="<?= sanitize($p['min_order_qty']??'') ?>" placeholder="500 kg">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Unit Qty</label>
                <select name="unit_qty" class="form-control">
                  <?php foreach([1,2,5,10,15,20,25,30,40,50,75,100,150,200,250,500] as $u): ?>
                  <option value="<?= $u ?>" <?= $p['unit_qty']==$u?'selected':'' ?>><?= $u ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Machine Count</label>
                <select name="machine_count" class="form-control">
                  <?php for($m=1;$m<=15;$m++): ?>
                  <option value="<?= $m ?>" <?= $p['machine_count']==$m?'selected':'' ?>><?= $m ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Views (manual override)</label>
                <input type="number" name="views" class="form-control" value="<?= (int)$p['views'] ?>" min="0">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Tags <span style="font-weight:400;color:var(--text-muted);font-size:12px">— comma separated</span></label>
              <input type="text" name="tags" class="form-control" value="<?= sanitize($p['tags']??'') ?>" placeholder="kraft paper, packaging, 80gsm">
            </div>
            <div class="form-group">
              <label class="form-label">URL Slug</label>
              <input type="text" name="slug" class="form-control" value="<?= sanitize($p['slug']??'') ?>" placeholder="auto-generated from name">
              <div class="form-text">Leave blank to auto-generate. Used in the product URL.</div>
            </div>
          </div>
        </div>

        <!-- Images -->
        <div class="card section-card">
          <div class="card-header"><h2>🖼️ Product Images</h2></div>
          <div class="card-body">
            <?php if ($imgArr): ?>
            <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:8px">Current Images — uncheck to remove:</div>
            <div class="img-grid">
              <?php foreach ($imgArr as $img): $img=trim($img); if(!$img) continue; ?>
              <label class="img-item" title="Uncheck to remove">
                <img src="<?= UPLOAD_URL.sanitize($img) ?>" alt="">
                <input type="checkbox" name="keep_images[]" value="<?= sanitize($img) ?>" checked
                       style="position:absolute;top:4px;left:4px;width:16px;height:16px;accent-color:#10b981">
              </label>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:8px">Upload New Images:</div>
            <div class="drop-zone" id="img-drop-area">
              <input type="file" id="product_images" multiple accept="image/jpeg,image/png,image/webp" onchange="uploadNewImgs(this)">
              <div style="font-size:26px;margin-bottom:6px">📁</div>
              <div style="font-size:13px;font-weight:600;color:var(--primary)">Click or drag to add images</div>
              <div style="font-size:11.5px;color:var(--text-muted);margin-top:3px">JPG · PNG · WebP · 5MB each</div>
            </div>
            <div id="img-status" style="font-size:12.5px;min-height:16px;margin-top:6px;font-weight:600"></div>
            <div class="img-new-grid" id="img-previews"></div>
          </div>
        </div>

        <!-- Attributes -->
        <div class="card section-card">
          <div class="card-header">
            <h2>📐 Attributes &amp; Specifications</h2>
            <span style="font-size:12px;color:var(--text-muted)">Edit values for existing attributes</span>
          </div>
          <div class="card-body">
            <?php if ($attrs): ?>
            <?php foreach ($attrs as $a): ?>
            <div class="attr-row">
              <div style="flex:1;min-width:0">
                <div style="font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:3px">
                  <?= sanitize($a['attribute_name']) ?>
                  <?php if ($a['attribute_unit']): ?><span style="font-weight:400">(<?= sanitize($a['attribute_unit']) ?>)</span><?php endif; ?>
                </div>
                <input type="text" name="attr_<?= $a['id'] ?>" class="form-control"
                       value="<?= sanitize($a['attribute_value']??'') ?>"
                       placeholder="Enter value" style="font-size:13px;padding:7px 10px">
              </div>
              <a href="?id=<?= $pid ?>&del_attr=<?= $a['id'] ?>" class="btn btn-danger btn-xs"
                 style="flex-shrink:0;margin-top:16px" onclick="return confirm('Remove this attribute?')">🗑</a>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-state" style="padding:20px"><p>No attributes recorded.</p></div>
            <?php endif; ?>

            <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border)">
              <div style="font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:10px">➕ Add New Attribute</div>
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                <input type="text" name="new_attr_name" class="form-control" placeholder="Attribute name" style="flex:2;min-width:120px">
                <input type="text" name="new_attr_value" class="form-control" placeholder="Value" style="flex:2;min-width:100px">
                <input type="text" name="new_attr_unit" class="form-control" placeholder="Unit" style="flex:1;min-width:70px">
              </div>
            </div>
          </div>
        </div>

      </div><!-- /left -->

      <!-- RIGHT COLUMN -->
      <div>

        <!-- Status & Controls -->
        <div class="card section-card">
          <div class="card-header"><h2>⚙️ Admin Controls</h2></div>
          <div class="card-body" style="display:flex;flex-direction:column;gap:14px">

            <!-- Featured toggle -->
            <label class="feat-toggle">
              <input type="checkbox" name="is_featured" value="1" <?= $p['is_featured']?'checked':'' ?>>
              <div>
                <div style="font-weight:700;font-size:13.5px">⭐ Featured Product</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Shown in the Featured Products section on the homepage.</div>
              </div>
            </label>

            <!-- Status -->
            <div class="form-group" style="margin:0">
              <label class="form-label">Product Status</label>
              <select name="status" class="form-control">
                <option value="active"   <?= $p['status']==='active'  ?'selected':''?>>✅ Active — live on website</option>
                <option value="pending"  <?= $p['status']==='pending' ?'selected':''?>>⏳ Pending — awaiting review</option>
                <option value="inactive" <?= $p['status']==='inactive'?'selected':''?>>⛔ Inactive — hidden from buyers</option>
              </select>
            </div>

            <!-- Assign vendor -->
            <div class="form-group" style="margin:0">
              <label class="form-label">Assigned Vendor</label>
              <select name="vendor_id" class="form-control">
                <?php foreach ($vendors as $v): ?>
                <option value="<?= $v['id'] ?>" <?= $p['vendor_id']==$v['id']?'selected':''?>>
                  <?= sanitize($v['name']) ?> — <?= sanitize($v['company']?:'') ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Quick actions -->
            <div style="display:flex;flex-direction:column;gap:6px">
              <?php if ($p['status']==='pending'): ?>
              <a href="products.php?action=approve&id=<?= $pid ?>" class="btn btn-success btn-sm" onclick="return confirm('Approve this product?')">✅ Approve &amp; Publish</a>
              <a href="products.php?action=reject&id=<?= $pid ?>"  class="btn btn-danger  btn-sm" onclick="return confirm('Reject this product?')">❌ Reject</a>
              <?php elseif ($p['status']==='active'): ?>
              <a href="products.php?action=reject&id=<?= $pid ?>" class="btn btn-warning btn-sm" onclick="return confirm('Deactivate?')">⏸ Deactivate</a>
              <?php else: ?>
              <a href="products.php?action=approve&id=<?= $pid ?>" class="btn btn-success btn-sm" onclick="return confirm('Activate?')">▶ Activate</a>
              <?php endif; ?>
              <a href="products.php?action=delete&id=<?= $pid ?>" class="btn btn-danger btn-sm"
                 onclick="return confirm('Permanently delete this product and all its data?')">🗑 Delete Product</a>
            </div>
          </div>
        </div>

        <!-- Classification -->
        <div class="card section-card">
          <div class="card-header"><h2>🏭 Classification</h2></div>
          <div class="card-body">
            <div class="form-group">
              <label class="form-label">Industry</label>
              <select name="industry_id" class="form-control" id="sel-industry" onchange="loadCats(this.value)">
                <?php foreach ($industries as $ind): ?>
                <option value="<?= $ind['id'] ?>" <?= $p['industry_id']==$ind['id']?'selected':''?>><?= sanitize($ind['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Category</label>
              <select name="category_id" class="form-control" id="sel-category" onchange="loadTypes(this.value)">
                <option value="<?= $p['category_id'] ?>">Loading…</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Product Type</label>
              <select name="product_type_id" class="form-control" id="sel-type">
                <option value="<?= $p['product_type_id'] ?>">Loading…</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Meta -->
        <div class="card section-card">
          <div class="card-header"><h2>ℹ️ Meta</h2></div>
          <div class="card-body" style="font-size:13px;color:var(--text-muted);display:flex;flex-direction:column;gap:6px">
            <div>🆔 Product ID: <strong style="color:var(--text)">#<?= $pid ?></strong></div>
            <div>📅 Created: <strong style="color:var(--text)"><?= formatDate($p['created_at']) ?></strong></div>
            <div>🔄 Updated: <strong style="color:var(--text)"><?= formatDate($p['updated_at']) ?></strong></div>
            <div>👁 Views: <strong style="color:var(--text)"><?= number_format($p['views']) ?></strong></div>
            <div>⭐ Featured: <strong style="color:<?= $p['is_featured']?'#f59e0b':'var(--text)' ?>"><?= $p['is_featured']?'Yes':'No' ?></strong></div>
            <?php if (!empty($p['slug'])): ?>
            <div style="margin-top:4px">
              <a href="<?= BASE_URL ?>/public/product.php?slug=<?= sanitize($p['slug']) ?>" target="_blank" class="btn btn-outline btn-xs">🌐 View on Website</a>
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div><!-- /right -->
    </div>

    <div style="display:flex;gap:10px;margin-bottom:40px;position:sticky;bottom:16px;z-index:100;background:transparent">
      <button type="submit" class="btn btn-primary" style="padding:12px 40px;font-size:14px;box-shadow:0 4px 18px rgba(99,102,241,.35)">💾 Save All Changes</button>
      <a href="products.php" class="btn btn-outline" style="padding:12px 24px">Cancel</a>
    </div>
  </form>
</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
<script>
const BASE = document.querySelector('meta[name="base-url"]').content;
let newlyUploaded = [];

/* Classification cascades */
const curCatId  = <?= (int)$p['category_id'] ?>;
const curTypeId = <?= (int)$p['product_type_id'] ?>;

function loadCats(indId, selectCatId) {
  const sel = document.getElementById('sel-category');
  sel.innerHTML = '<option>Loading…</option>';
  fetch(BASE+'/ajax/get-categories.php?industry_id='+indId)
    .then(r=>r.json()).then(cats=>{
      sel.innerHTML = cats.map(c=>`<option value="${c.id}" ${c.id==(selectCatId||curCatId)?'selected':''}>${c.name}</option>`).join('');
      loadTypes(sel.value);
    });
}
function loadTypes(catId, selectTypeId) {
  const sel = document.getElementById('sel-type');
  sel.innerHTML = '<option>Loading…</option>';
  fetch(BASE+'/ajax/get-product-types.php?category_id='+catId)
    .then(r=>r.json()).then(types=>{
      sel.innerHTML = types.map(t=>`<option value="${t.id}" ${t.id==(selectTypeId||curTypeId)?'selected':''}>${t.name}</option>`).join('');
    });
}
// Init on page load
loadCats(<?= (int)$p['industry_id'] ?>);
document.getElementById('sel-industry').addEventListener('change', function(){ loadCats(this.value, null); });
document.getElementById('sel-category').addEventListener('change', function(){ loadTypes(this.value, null); });

/* Image upload via AJAX */
function uploadNewImgs(input) {
  const files = Array.from(input.files);
  if (!files.length) return;
  setImgStatus('⏳ Uploading '+files.length+' image(s)…','#f59e0b');
  const fd = new FormData();
  files.forEach(f=>fd.append('images[]',f));
  fd.append('csrf_token', document.querySelector('[name=csrf_token]').value);
  fetch(BASE+'/ajax/upload-product-images.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
      if (data.saved) {
        data.saved.forEach((fn,i)=>newlyUploaded.push({filename:fn,url:data.urls[i]}));
        document.getElementById('uploaded_images_json').value = JSON.stringify(newlyUploaded.map(f=>f.filename));
      }
      setImgStatus(data.errors?.length ? '⚠️ '+data.errors.join(' | ') : '✅ '+newlyUploaded.length+' new image(s) ready','#10b981');
      renderNewPreviews();
    })
    .catch(e=>setImgStatus('❌ '+e.message,'#ef4444'));
  input.value='';
}
function renderNewPreviews(){
  const c=document.getElementById('img-previews'); c.innerHTML='';
  newlyUploaded.forEach((f,i)=>{
    const d=document.createElement('div'); d.className='img-prev';
    d.innerHTML=`<img src="${f.url}" alt=""><button type="button" class="img-prev-rm" onclick="removeNew(${i})">✕</button>`;
    c.appendChild(d);
  });
}
function removeNew(i){newlyUploaded.splice(i,1);document.getElementById('uploaded_images_json').value=JSON.stringify(newlyUploaded.map(f=>f.filename));renderNewPreviews();}
function setImgStatus(msg,col){const el=document.getElementById('img-status');if(el){el.textContent=msg;el.style.color=col;}}

const dz=document.getElementById('img-drop-area');
if(dz){
  dz.addEventListener('dragover',e=>{e.preventDefault();dz.style.borderColor='var(--primary)';});
  dz.addEventListener('dragleave',()=>{dz.style.borderColor='';});
  dz.addEventListener('drop',e=>{e.preventDefault();dz.style.borderColor='';uploadNewImgs({files:e.dataTransfer.files,value:''});});
}
</script>
</div></div></body></html>
