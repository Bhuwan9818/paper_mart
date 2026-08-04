<?php
$pageTitle   = 'Browse Products — PaperMart';
$currentPage = 'products';
include __DIR__.'/includes/header.php';

// Filters
$q          = trim($_GET['q'] ?? '');
$industryId = (int)($_GET['industry'] ?? 0);
$categoryId = (int)($_GET['category'] ?? 0);
$typeId     = (int)($_GET['type'] ?? 0);
$sort       = $_GET['sort'] ?? 'newest';
$view       = $_GET['view'] ?? 'grid';
$page       = max(1,(int)($_GET['page'] ?? 1));
$perPage    = 18;
$offset     = ($page-1)*$perPage;

// Build WHERE
$where = "WHERE p.status='active'"; $params = [];
if ($q)          { $where .= " AND (p.name LIKE ? OR p.description LIKE ? OR p.tags LIKE ?)"; $params=array_merge($params,["%$q%","%$q%","%$q%"]); }
if ($industryId) { $where .= " AND p.industry_id=?";      $params[]=$industryId; }
if ($categoryId) { $where .= " AND p.category_id=?";      $params[]=$categoryId; }
if ($typeId)     { $where .= " AND p.product_type_id=?";  $params[]=$typeId; }

$orderBy = match($sort){
  'price_asc'  => "p.price_range ASC",
  'popular'    => "p.views DESC",
  'featured'   => "p.is_featured DESC, p.views DESC",
  default      => "p.created_at DESC"
};

$countStmt=$pdo->prepare("SELECT COUNT(*) FROM products p $where");
$countStmt->execute($params); $total=$countStmt->fetchColumn();

$params2=$params; $params2[]=$perPage; $params2[]=$offset;
$stmt=$pdo->prepare("SELECT p.*,u.name AS vname,u.company,u.city AS vcity,vp.is_verified,vp.logo,i.name AS iname,c.name AS cname,pt.name AS tname
FROM products p JOIN users u ON u.id=p.vendor_id LEFT JOIN vendor_profiles vp ON vp.vendor_id=p.vendor_id
JOIN industries i ON i.id=p.industry_id JOIN categories c ON c.id=p.category_id JOIN product_types pt ON pt.id=p.product_type_id
$where ORDER BY $orderBy LIMIT ? OFFSET ?");
$stmt->execute($params2); $products=$stmt->fetchAll();

// Filter sidebar data
$allIndustries=$pdo->query("SELECT i.*,(SELECT COUNT(*) FROM products WHERE industry_id=i.id AND status='active') AS p_count FROM industries i WHERE i.status=1 ORDER BY i.sort_order")->fetchAll();
$allCategories=$pdo->query("SELECT c.*,(SELECT COUNT(*) FROM products WHERE category_id=c.id AND status='active') AS p_count FROM categories c WHERE c.status=1 ORDER BY c.sort_order")->fetchAll();
if ($industryId) {
    $allCategories=array_filter($allCategories,fn($c)=>$c['industry_id']==$industryId);
    $allCategories=array_values($allCategories);
}

// Heading
$heading = 'All Products';
if ($q)          $heading = "Results for \"".sH($q)."\"";
elseif($categoryId){ $cn=$pdo->query("SELECT name FROM categories WHERE id=$categoryId")->fetchColumn(); $heading=sH($cn??'Category'); }
elseif($industryId){ $in=$pdo->query("SELECT name FROM industries WHERE id=$industryId")->fetchColumn(); $heading=sH($in??'Industry'); }
$pageTitle = "$heading — PaperMart";
?>

<div style="background:var(--n50);padding:14px 0;border-bottom:1px solid var(--n200)">
  <div class="container">
    <div style="font-size:13px;color:var(--n500)">
      <a href="<?= BASE_URL ?>/public/index.php" style="color:var(--brand-2)">Home</a> › 
      <a href="<?= BASE_URL ?>/public/products.php" style="color:var(--brand-2)">Products</a>
      <?= $heading!=='All Products' ? " › $heading" : '' ?>
    </div>
  </div>
</div>

<section class="compact">
  <div class="container">
    <!-- Mobile filter FAB -->
<button class="mob-filter-fab" id="mob-filter-fab" aria-label="Filter products">
  ⚙️ Filters
</button>
<div class="products-layout">

      <!-- SIDEBAR FILTERS -->
      <aside class="filter-panel">
        <div class="filter-card">
          <div class="filter-title">
            🏭 Industries
            <?php if($industryId): ?><a href="?<?= http_build_query(array_diff_key($_GET,['industry'=>'','category'=>''])) ?>"><button class="filter-clear">Clear</button></a><?php endif; ?>
          </div>
          <?php foreach($allIndustries as $ind): ?>
          <label class="filter-option">
            <input type="checkbox" onchange="applyFilter('industry',<?= $ind['id'] ?>)" <?= $industryId==$ind['id']?'checked':'' ?>>
            <?= sH($ind['name']) ?>
            <span class="filter-count"><?= $ind['p_count'] ?></span>
          </label>
          <?php endforeach; ?>
        </div>

        <?php if ($allCategories): ?>
        <div class="filter-card">
          <div class="filter-title">
            🗂️ Categories
            <?php if($categoryId): ?><button class="filter-clear" onclick="applyFilter('category',0)">Clear</button><?php endif; ?>
          </div>
          <?php foreach($allCategories as $cat): ?>
          <label class="filter-option">
            <input type="checkbox" onchange="applyFilter('category',<?= $cat['id'] ?>)" <?= $categoryId==$cat['id']?'checked':'' ?>>
            <?= sH($cat['name']) ?>
            <span class="filter-count"><?= $cat['p_count'] ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="filter-card">
          <div class="filter-title">⚡ Quick Filters</div>
          <label class="filter-option" style="cursor:pointer" onclick="location='?featured=1'">
            <input type="checkbox" <?= ($_GET['featured']??'')?'checked':'' ?>> ⭐ Featured Only
          </label>
          <label class="filter-option" style="cursor:pointer" onclick="location='?verified=1'">
            <input type="checkbox" <?= ($_GET['verified']??'')?'checked':'' ?>> ✓ Verified Vendors
          </label>
        </div>

        <a href="<?= BASE_URL ?>/public/compare.php" class="btn btn-outline btn-full" style="margin-top:4px">⚖️ Compare Products</a>
      </aside>

      <!-- RESULTS -->
      <div>
        <div class="results-toolbar">
          <div class="results-count">
            <strong><?= number_format($total) ?></strong> <?= $total===1?'product':'products' ?> found
            <?= $q ? " for <em>\"".sH($q)."\"</em>" : '' ?>
          </div>
          <div style="display:flex;align-items:center;gap:10px">
            <select class="sort-select" onchange="applySort(this.value)">
              <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Newest First</option>
              <option value="popular" <?= $sort==='popular'?'selected':'' ?>>Most Popular</option>
              <option value="featured" <?= $sort==='featured'?'selected':'' ?>>Featured</option>
            </select>
            <div class="view-toggle">
              <button class="view-btn <?= $view==='grid'?'active':'' ?>" onclick="setView('grid')" title="Grid">⊞</button>
              <button class="view-btn <?= $view==='list'?'active':'' ?>" onclick="setView('list')" title="List">☰</button>
            </div>
          </div>
        </div>

        <?php if (!$products): ?>
          <div class="empty-state">
            <div class="empty-icon">🔍</div>
            <h3>No products found</h3>
            <p>Try different keywords or browse all products.</p>
            <a href="<?= BASE_URL ?>/public/products.php" class="btn btn-primary" style="margin-top:16px">Browse All Products</a>
          </div>
        <?php elseif($view==='list'): ?>
          <?php foreach($products as $p):
            $imgs=array_filter(explode(',',$p['images']??''));
            $img=reset($imgs)?UPLOAD_URL.trim(reset($imgs)):'';
            $attrs=$pdo->prepare("SELECT * FROM product_attributes WHERE product_id=? ORDER BY sort_order LIMIT 4");
            $attrs->execute([$p['id']]); $attrList=$attrs->fetchAll();
          ?>
          <div class="product-list-item">
            <div class="pli-img">
              <?php if($img): ?><img src="<?= sH($img) ?>" alt="<?= sH($p['name']) ?>" loading="lazy">
              <?php else: ?>📦<?php endif; ?>
            </div>
            <div class="pli-body">
              <h3><a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>"><?= sH($p['name']) ?></a></h3>
              <div class="pli-desc"><?= sH($p['short_desc']?:$p['description']) ?></div>
              <?php if($attrList): ?>
              <div class="pli-attrs">
                <?php foreach($attrList as $a): ?>
                  <span class="pli-attr"><strong><?= sH($a['attribute_name']) ?>:</strong> <?= sH($a['attribute_value']) ?><?= sH($a['attribute_unit']??' ') ?></span>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <div style="font-size:12.5px;color:var(--n500)">
                <span>📁 <?= sH($p['cname']) ?></span> &nbsp;·&nbsp;
                <span>🏭 <?= sH($p['company']?:$p['vname']) ?></span>
                <?php if($p['vcity']): ?>&nbsp;·&nbsp;<span>📍 <?= sH($p['vcity']) ?></span><?php endif; ?>
                <?php if($p['is_verified']): ?>&nbsp;·&nbsp;<span style="color:var(--brand-2)">✓ Verified</span><?php endif; ?>
              </div>
            </div>
            <div class="pli-actions">
              <?php if($p['price_range']): ?><div style="font-size:15px;font-weight:700;color:var(--brand);margin-bottom:6px">₹ <?= sH($p['price_range']) ?></div><?php endif; ?>
              <a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm btn-full">View Details</a>
              <button class="btn btn-accent btn-sm btn-full" onclick="openEnquiryModal(<?= $p['id'] ?>,<?= $p['vendor_id'] ?>,'<?= sH($p['name']) ?>')">📩 Enquire</button>
              <button class="btn btn-compare btn-sm btn-full" onclick="addToCompare(<?= $p['id'] ?>,'<?= sH($p['name']) ?>','<?= sH($p['images']??'') ?>')" data-id="<?= $p['id'] ?>">⚖️ Compare</button>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="products-grid">
          <?php foreach($products as $p):
            $imgs=array_filter(explode(',',$p['images']??''));
            $img=reset($imgs)?UPLOAD_URL.trim(reset($imgs)):'';
          ?>
            <div class="card">
              <div class="card-img">
                <?php if($img): ?><img src="<?= sH($img) ?>" alt="<?= sH($p['name']) ?>" loading="lazy"><?php else: ?><span class="card-img-ph">📦</span><?php endif; ?>
                <?php if($p['is_featured']): ?><div class="card-badge-pos"><span class="badge badge-amber">⭐</span></div><?php endif; ?>
                <div class="card-compare-pos"><button class="btn btn-compare" onclick="addToCompare(<?= $p['id'] ?>,'<?= sH($p['name']) ?>','<?= sH($p['images']??'') ?>')" data-id="<?= $p['id'] ?>" title="Compare">⚖️</button></div>
              </div>
              <div class="card-body">
                <div class="card-cat"><?= sH($p['cname']) ?></div>
                <div class="card-title"><a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>"><?= sH($p['name']) ?></a></div>
                <?php if($p['price_range']): ?><div class="card-price">₹ <?= sH($p['price_range']) ?></div><?php endif; ?>
                <div class="card-vendor">
                  <div class="vav"><?= strtoupper(substr($p['vname'],0,1)) ?></div>
                  <div class="v-name"><?= sH($p['company']?:$p['vname']) ?></div>
                  <?php if($p['is_verified']): ?><div class="v-verified">✓ Verified</div><?php endif; ?>
                </div>
              </div>
              <div class="card-footer">
                <a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm" style="flex:1">View</a>
                <button class="btn btn-accent btn-sm" onclick="openEnquiryModal(<?= $p['id'] ?>,<?= $p['vendor_id'] ?>,'<?= sH($p['name']) ?>')">Enquire</button>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php $pages=ceil($total/$perPage); if($pages>1):
          $base='?'.http_build_query(array_diff_key($_GET,['page'=>'']));
        ?>
        <div class="pagination">
          <?php if($page>1): ?><a href="<?= $base ?>&page=<?= $page-1 ?>" class="page-btn">‹</a><?php endif; ?>
          <?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?>
            <a href="<?= $base ?>&page=<?= $i ?>" class="page-btn <?= $i==$page?'active':'' ?>"><?= $i ?></a>
          <?php endfor; ?>
          <?php if($page<$pages): ?><a href="<?= $base ?>&page=<?= $page+1 ?>" class="page-btn">›</a><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php
// Enquiry modal (same as index)
include __DIR__.'/includes/footer.php';
// Inline modal HTML
?>
<div class="modal-backdrop" id="enquiry-modal">
  <div class="modal">
    <div class="modal-header"><h3>📩 Send Enquiry</h3><button class="modal-close" onclick="closeEnquiryModal()">✕</button></div>
    <div class="modal-body">
      <div id="enq-success" class="site-alert site-alert-success" style="display:none"></div>
      <form id="enq-form" onsubmit="submitEnquiry(event)">
        <input type="hidden" id="enq-product-id" name="product_id">
        <input type="hidden" id="enq-vendor-id"  name="vendor_id">
        <div id="enq-product-name" style="background:var(--n50);border-radius:var(--r-sm);padding:10px 14px;margin-bottom:16px;font-weight:600;font-size:13.5px;color:var(--brand)"></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Your Name *</label><input type="text" name="name" class="form-input" required></div>
          <div class="form-group"><label class="form-label">Email *</label><input type="email" name="email" class="form-input" required></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Phone</label><input type="tel" name="phone" class="form-input"></div>
          <div class="form-group"><label class="form-label">Company</label><input type="text" name="company" class="form-input"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">City</label><input type="text" name="city" class="form-input"></div>
          <div class="form-group"><label class="form-label">Quantity Needed</label><input type="text" name="qty_needed" class="form-input" placeholder="e.g. 500 kg"></div>
        </div>
        <div class="form-group"><label class="form-label">Message</label><textarea name="message" class="form-input" rows="3" style="resize:vertical"></textarea></div>
        <button type="submit" class="btn btn-accent btn-full btn-lg" id="enq-btn">Send Enquiry</button>
      </form>
    </div>
  </div>
</div>

<script>
function applyFilter(key, val) {
  const p = new URLSearchParams(window.location.search);
  if(val) p.set(key,val); else p.delete(key);
  p.delete('page'); window.location = '?' + p.toString();
}
function applySort(val) {
  const p = new URLSearchParams(window.location.search);
  p.set('sort',val); window.location = '?' + p.toString();
}
function setView(v) {
  const p = new URLSearchParams(window.location.search);
  p.set('view',v); window.location = '?' + p.toString();
}
function openEnquiryModal(pid,vid,name){
  document.getElementById('enq-product-id').value=pid;
  document.getElementById('enq-vendor-id').value=vid;
  document.getElementById('enq-product-name').textContent='📦 '+name;
  document.getElementById('enq-success').style.display='none';
  document.getElementById('enq-form').style.display='block';
  document.getElementById('enq-btn').textContent='Send Enquiry';
  document.getElementById('enq-btn').disabled=false;
  document.getElementById('enquiry-modal').classList.add('open');
}
function closeEnquiryModal(){ document.getElementById('enquiry-modal').classList.remove('open'); }
document.getElementById('enquiry-modal').addEventListener('click',function(e){if(e.target===this)closeEnquiryModal();});
function submitEnquiry(e){
  e.preventDefault();
  const btn=document.getElementById('enq-btn');
  btn.textContent='Sending…';btn.disabled=true;
  fetch(BASE+'/public/ajax/enquiry.php',{method:'POST',body:new FormData(e.target)})
    .then(r=>r.json()).then(d=>{
      if(d.ok){document.getElementById('enq-success').textContent=d.msg;document.getElementById('enq-success').style.display='flex';document.getElementById('enq-form').style.display='none';}
      else{btn.textContent='Send Enquiry';btn.disabled=false;alert(d.msg);}
    });
}
</script>
