<?php
$pageTitle   = 'Compare Products — PaperMart';
$currentPage = 'compare';
include __DIR__.'/includes/header.php';

// Get compare items from session
$sessionKey = session_id();
try {
    $stmt=$pdo->prepare("SELECT p.*,u.company,u.name AS vname,vp.is_verified,c.name AS cname,pt.name AS tname FROM compare_sessions cs JOIN products p ON p.id=cs.product_id JOIN users u ON u.id=p.vendor_id LEFT JOIN vendor_profiles vp ON vp.vendor_id=p.vendor_id JOIN categories c ON c.id=p.category_id JOIN product_types pt ON pt.id=p.product_type_id WHERE cs.session_key=? AND p.status='active' ORDER BY cs.added_at ASC");
    $stmt->execute([$sessionKey]); $products=$stmt->fetchAll();
} catch(Exception $e) { $products=[]; }

// Get all unique attribute names across compared products
$allAttrNames=[];
$productAttrs=[];
foreach($products as $p){
    $as=$pdo->prepare("SELECT * FROM product_attributes WHERE product_id=? ORDER BY sort_order");
    $as->execute([$p['id']]); $list=$as->fetchAll();
    $productAttrs[$p['id']]=[];
    foreach($list as $a){ $productAttrs[$p['id']][$a['attribute_name']]=$a['attribute_value'].($a['attribute_unit']?' '.$a['attribute_unit']:''); $allAttrNames[$a['attribute_name']]=true; }
}
$allAttrNames=array_keys($allAttrNames);

// Industries for the "Add Product" picker (industry → category → product type → product)
$industries = $pdo->query("SELECT id, name FROM industries WHERE status=1 ORDER BY sort_order, name")->fetchAll();
?>

<div style="background:var(--n50);padding:14px 0;border-bottom:1px solid var(--n200)">
  <div class="container" style="font-size:13px;color:var(--n500)">
    <a href="<?= BASE_URL ?>/public/index.php" style="color:var(--brand-2)">Home</a> › <span>Compare Products</span>
  </div>
</div>

<section class="compact">
  <div class="container">
    <div class="section-head" style="display:flex;align-items:flex-start;justify-content:space-between">
      <div>
        <div class="section-label">Side-by-Side</div>
        <h1 style="font-size:28px">Compare Products</h1>
        <p style="color:var(--n500);margin-top:6px">Compare specifications, prices, and vendors to make the best decision.</p>
      </div>
      <?php if ($products): ?>
      <button class="btn btn-outline btn-sm" onclick="clearComparePage()">✕ Clear All</button>
      <?php endif; ?>
    </div>

    <?php if (!$products): ?>
      <div class="empty-state">
        <div class="empty-icon">⚖️</div>
        <h3>No Products to Compare</h3>
        <p>Browse products and click the ⚖️ icon to add up to 4 products to compare.</p>
        <div style="display:flex;gap:10px;justify-content:center;margin-top:18px;flex-wrap:wrap">
          <button type="button" class="btn btn-primary" onclick="openAddProductPicker()">+ Add Product to Compare</button>
          <a href="<?= BASE_URL ?>/public/products.php" class="btn btn-outline">Browse Products</a>
        </div>
      </div>
    <?php elseif(count($products)<2): ?>
      <div class="site-alert site-alert-info">
        <span>ℹ️</span> Add at least 2 products to compare. You've added <?= count($products) ?>.
        <button type="button" onclick="openAddProductPicker()" style="background:none;border:none;color:var(--brand-2);font-weight:600;margin-left:8px;cursor:pointer;text-decoration:underline">Add More Products →</button>
      </div>
    <?php endif; ?>

    <?php if (count($products)>=1): ?>
    <div style="overflow-x:auto;margin-top:20px">
      <table class="compare-table">
        <thead>
          <tr>
            <th>Feature</th>
            <?php foreach($products as $p): ?>
            <th>
              <?php
                $imgs=array_filter(explode(',',$p['images']??''));
                $img=reset($imgs)?UPLOAD_URL.trim(reset($imgs)):'';
              ?>
              <?php if($img): ?><img src="<?= sH($img) ?>" class="compare-img"><?php else: ?><div style="height:160px;background:var(--n50);border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;font-size:48px;margin-bottom:10px">📦</div><?php endif; ?>
              <a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>" style="font-family:'Poppins',sans-serif;font-size:14px;font-weight:700;color:var(--n900);display:block;margin-bottom:5px"><?= sH($p['name']) ?></a>
              <div style="font-size:12px;color:var(--n500);margin-bottom:8px"><?= sH($p['cname']) ?></div>
              <button class="btn btn-accent btn-sm btn-full" onclick="openEnquiryModal(<?= $p['id'] ?>,<?= $p['vendor_id'] ?>,'<?= sH($p['name']) ?>')">📩 Enquire</button>
              <button class="btn btn-outline btn-sm btn-full" style="margin-top:6px" onclick="removeFromComparePage(<?= $p['id'] ?>)">Remove</button>
            </th>
            <?php endforeach; ?>
            <?php if(count($products)<4): ?>
            <th style="vertical-align:middle">
              <button type="button" onclick="openAddProductPicker()" style="display:flex;flex-direction:column;align-items:center;gap:8px;color:var(--n400);background:none;border:none;cursor:pointer;width:100%">
                <span style="font-size:40px">+</span>
                <span style="font-size:13px">Add Product</span>
              </button>
            </th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Price Range</td>
            <?php foreach($products as $p): ?><td><?= $p['price_range']?'₹ '.sH($p['price_range']):'—' ?></td><?php endforeach; ?>
            <?php if(count($products)<4): ?><td></td><?php endif; ?>
          </tr>
          <tr>
            <td>Min. Order</td>
            <?php foreach($products as $p): ?><td><?= sH($p['min_order_qty']?:'—') ?></td><?php endforeach; ?>
            <?php if(count($products)<4): ?><td></td><?php endif; ?>
          </tr>
          <tr>
            <td>Vendor</td>
            <?php foreach($products as $p): ?>
            <td><?= sH($p['company']?:$p['vname']) ?><?php if($p['is_verified']): ?><br><span class="badge badge-green" style="margin-top:4px;font-size:10px">✓ Verified</span><?php endif; ?></td>
            <?php endforeach; ?>
            <?php if(count($products)<4): ?><td></td><?php endif; ?>
          </tr>
          <tr>
            <td>Product Type</td>
            <?php foreach($products as $p): ?><td><?= sH($p['tname']) ?></td><?php endforeach; ?>
            <?php if(count($products)<4): ?><td></td><?php endif; ?>
          </tr>

          <?php foreach($allAttrNames as $attrName): ?>
          <tr>
            <td><?= sH($attrName) ?></td>
            <?php
            // Find numeric values to highlight best
            $vals=array_map(fn($p)=>$productAttrs[$p['id']][$attrName]??null,$products);
            $numVals=array_filter($vals,fn($v)=>is_numeric(preg_replace('/[^0-9.]/','',$v??'')));
            $maxVal=$numVals?max(array_map(fn($v)=>(float)preg_replace('/[^0-9.]/','',$v),array_values($numVals))):null;
            foreach($products as $p):
              $v=$productAttrs[$p['id']][$attrName]??null;
              $numV=$v?preg_replace('/[^0-9.]/','',$v):null;
              $isBest=$maxVal!==null&&$numV!==null&&(float)$numV>=$maxVal&&count($numVals)>1;
            ?>
            <td class="<?= $isBest?'best':'' ?>"><?= $v?sH($v):'<span style="color:var(--n300)">—</span>' ?></td>
            <?php endforeach; ?>
            <?php if(count($products)<4): ?><td></td><?php endif; ?>
          </tr>
          <?php endforeach; ?>

          <tr>
            <td>Action</td>
            <?php foreach($products as $p): ?>
            <td>
              <div style="display:flex;flex-direction:column;gap:6px">
                <a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>" class="btn btn-primary btn-sm btn-full">View Details</a>
                <button class="btn btn-accent btn-sm btn-full" onclick="openEnquiryModal(<?= $p['id'] ?>,<?= $p['vendor_id'] ?>,'<?= sH($p['name']) ?>')">📩 Enquire</button>
              </div>
            </td>
            <?php endforeach; ?>
            <?php if(count($products)<4): ?><td></td><?php endif; ?>
          </tr>
        </tbody>
      </table>
    </div>
    <p style="font-size:12.5px;color:var(--n400);margin-top:12px;text-align:center">* Highlighted cells (green) indicate the best value for that specification among compared products.</p>
    <?php endif; ?>
  </div>
</section>

<!-- Add Product picker modal -->
<div class="modal-backdrop" id="add-product-modal">
  <div class="modal">
    <div class="modal-header">
      <h3>+ Add Product to Compare</h3>
      <button class="modal-close" onclick="closeAddProductPicker()">✕</button>
    </div>
    <div class="modal-body">
      <div id="ap-error" class="site-alert site-alert-error" style="display:none"></div>

      <div class="form-group">
        <label class="form-label">Industry</label>
        <select id="ap-industry" class="form-input" onchange="apOnIndustryChange()">
          <option value="">Select Industry…</option>
          <?php foreach($industries as $ind): ?>
            <option value="<?= $ind['id'] ?>"><?= sH($ind['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Category</label>
        <select id="ap-category" class="form-input" onchange="apOnCategoryChange()" disabled>
          <option value="">Select Industry first…</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Product Type</label>
        <select id="ap-type" class="form-input" onchange="apOnTypeChange()" disabled>
          <option value="">Select Category first…</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Product</label>
        <select id="ap-product" class="form-input" disabled>
          <option value="">Select Product Type first…</option>
        </select>
      </div>

      <button type="button" class="btn btn-accent btn-full btn-lg" id="ap-add-btn" onclick="apAddSelectedProduct()" disabled>Add to Compare</button>
    </div>
  </div>
</div>

<!-- Enquiry modal -->
<div class="modal-backdrop" id="enquiry-modal">
  <div class="modal"><div class="modal-header"><h3>📩 Send Enquiry</h3><button class="modal-close" onclick="closeEnquiryModal()">✕</button></div>
  <div class="modal-body">
    <div id="enq-success" class="site-alert site-alert-success" style="display:none"></div>
    <form id="enq-form" onsubmit="submitEnquiry(event)">
      <input type="hidden" id="enq-product-id" name="product_id"><input type="hidden" id="enq-vendor-id" name="vendor_id">
      <div id="enq-product-name" style="background:var(--n50);border-radius:var(--r-sm);padding:10px 14px;margin-bottom:16px;font-weight:600;color:var(--brand)"></div>
      <div class="form-row"><div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-input" required></div><div class="form-group"><label class="form-label">Email *</label><input type="email" name="email" class="form-input" required></div></div>
      <div class="form-row"><div class="form-group"><label class="form-label">Phone</label><input type="tel" name="phone" class="form-input"></div><div class="form-group"><label class="form-label">Company</label><input type="text" name="company" class="form-input"></div></div>
      <div class="form-group"><label class="form-label">Message</label><textarea name="message" class="form-input" rows="3" style="resize:vertical"></textarea></div>
      <button type="submit" class="btn btn-accent btn-full btn-lg" id="enq-btn">Send Enquiry</button>
    </form>
  </div></div>
</div>

<script>
// This page's own Remove / Clear All buttons use these local versions
// instead of the global ones in footer.php. The global functions only
// refresh the floating compare bar (used site-wide), but on this page
// the actual comparison table is server-rendered — so after a
// successful add/remove/clear, the page needs to reload to reflect the
// change immediately rather than waiting for a manual refresh.
function removeFromComparePage(productId){
  fetch(BASE+'/public/ajax/compare.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=remove&product_id=' + encodeURIComponent(productId)
  })
    .then(r => r.json())
    .then(d => { if (d.ok) window.location.reload(); else alert(d.msg || 'Could not remove this product.'); })
    .catch(() => alert('Something went wrong. Please try again.'));
}
function clearComparePage(){
  if (!confirm('Remove all products from comparison?')) return;
  fetch(BASE+'/public/ajax/compare.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=clear'
  })
    .then(r => r.json())
    .then(d => { if (d.ok) window.location.reload(); })
    .catch(() => alert('Something went wrong. Please try again.'));
}

function openEnquiryModal(pid,vid,name){document.getElementById('enq-product-id').value=pid;document.getElementById('enq-vendor-id').value=vid;document.getElementById('enq-product-name').textContent='📦 '+name;document.getElementById('enq-success').style.display='none';document.getElementById('enq-form').style.display='block';document.getElementById('enq-btn').textContent='Send Enquiry';document.getElementById('enq-btn').disabled=false;document.getElementById('enquiry-modal').classList.add('open');}
function closeEnquiryModal(){document.getElementById('enquiry-modal').classList.remove('open');}
document.getElementById('enquiry-modal').addEventListener('click',function(e){if(e.target===this)closeEnquiryModal();});
function submitEnquiry(e){e.preventDefault();const btn=document.getElementById('enq-btn');btn.textContent='Sending…';btn.disabled=true;fetch(BASE+'/public/ajax/enquiry.php',{method:'POST',body:new FormData(e.target)}).then(r=>r.json()).then(d=>{if(d.ok){document.getElementById('enq-success').textContent=d.msg;document.getElementById('enq-success').style.display='flex';document.getElementById('enq-form').style.display='none';}else{btn.textContent='Send Enquiry';btn.disabled=false;alert(d.msg);}});}

// ── Add Product picker: industry → category → product type → product ──
function openAddProductPicker(){
  document.getElementById('ap-industry').value = '';
  apResetSelect('ap-category', 'Select Industry first…');
  apResetSelect('ap-type', 'Select Category first…');
  apResetSelect('ap-product', 'Select Product Type first…');
  document.getElementById('ap-add-btn').disabled = true;
  apShowError('');
  document.getElementById('add-product-modal').classList.add('open');
}
function closeAddProductPicker(){
  document.getElementById('add-product-modal').classList.remove('open');
}
document.getElementById('add-product-modal').addEventListener('click',function(e){if(e.target===this)closeAddProductPicker();});

function apResetSelect(id, placeholder){
  const el = document.getElementById(id);
  el.innerHTML = '<option value="">'+placeholder+'</option>';
  el.disabled = true;
}
function apShowError(msg){
  const e = document.getElementById('ap-error');
  e.textContent = msg;
  e.style.display = msg ? 'flex' : 'none';
}

function apOnIndustryChange(){
  apShowError('');
  apResetSelect('ap-category', 'Select Industry first…');
  apResetSelect('ap-type', 'Select Category first…');
  apResetSelect('ap-product', 'Select Product Type first…');
  document.getElementById('ap-add-btn').disabled = true;

  const industryId = document.getElementById('ap-industry').value;
  if (!industryId) return;

  fetch(BASE + '/ajax/get-categories.php?industry_id=' + industryId)
    .then(r => r.json())
    .then(list => {
      const sel = document.getElementById('ap-category');
      if (!list.length) { sel.innerHTML = '<option value="">No categories available</option>'; return; }
      sel.innerHTML = '<option value="">Select Category…</option>' +
        list.map(c => `<option value="${c.id}">${apEsc(c.name)}</option>`).join('');
      sel.disabled = false;
    })
    .catch(() => apShowError('Could not load categories. Please try again.'));
}

function apOnCategoryChange(){
  apShowError('');
  apResetSelect('ap-type', 'Select Category first…');
  apResetSelect('ap-product', 'Select Product Type first…');
  document.getElementById('ap-add-btn').disabled = true;

  const categoryId = document.getElementById('ap-category').value;
  if (!categoryId) return;

  fetch(BASE + '/ajax/get-product-types.php?category_id=' + categoryId)
    .then(r => r.json())
    .then(list => {
      const sel = document.getElementById('ap-type');
      if (!list.length) { sel.innerHTML = '<option value="">No product types available</option>'; return; }
      sel.innerHTML = '<option value="">Select Product Type…</option>' +
        list.map(t => `<option value="${t.id}">${apEsc(t.name)}</option>`).join('');
      sel.disabled = false;
    })
    .catch(() => apShowError('Could not load product types. Please try again.'));
}

function apOnTypeChange(){
  apShowError('');
  apResetSelect('ap-product', 'Select Product Type first…');
  document.getElementById('ap-add-btn').disabled = true;

  const typeId = document.getElementById('ap-type').value;
  if (!typeId) return;

  fetch(BASE + '/public/ajax/get-products-by-type.php?product_type_id=' + typeId)
    .then(r => r.json())
    .then(list => {
      const sel = document.getElementById('ap-product');
      if (!list.length) { sel.innerHTML = '<option value="">No products available</option>'; return; }
      sel.innerHTML = '<option value="">Select Product…</option>' +
        list.map(p => {
          const label = p.name + (p.company || p.vendor_name ? ' — ' + (p.company || p.vendor_name) : '');
          return `<option value="${p.id}">${apEsc(label)}</option>`;
        }).join('');
      sel.disabled = false;
    })
    .catch(() => apShowError('Could not load products. Please try again.'));
}

// Single persistent listener for the product dropdown, attached once at
// page load rather than re-attached every time the list is repopulated.
document.getElementById('ap-product').addEventListener('change', function(){
  document.getElementById('ap-add-btn').disabled = !this.value;
});

function apAddSelectedProduct(){
  apShowError('');
  const productId = document.getElementById('ap-product').value;
  if (!productId) { apShowError('Please select a product first.'); return; }

  const btn = document.getElementById('ap-add-btn');
  btn.disabled = true;
  btn.textContent = 'Adding…';

  fetch(BASE + '/public/ajax/compare.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=add&product_id=' + encodeURIComponent(productId)
  })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        // The comparison table is server-rendered, so reload to show
        // the newly added product in the full side-by-side table.
        window.location.reload();
      } else {
        btn.disabled = false;
        btn.textContent = 'Add to Compare';
        apShowError(d.msg || 'Could not add this product. It may already be in your comparison.');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.textContent = 'Add to Compare';
      apShowError('Something went wrong. Please try again.');
    });
}

function apEsc(s){
  const d = document.createElement('div');
  d.textContent = s ?? '';
  return d.innerHTML;
}
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
