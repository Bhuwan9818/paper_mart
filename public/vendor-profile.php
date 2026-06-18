<?php
$currentPage='vendors';
include __DIR__.'/includes/header.php';
$vid=(int)($_GET['id']??0);
$stmt=$pdo->prepare("SELECT u.*,vp.* FROM users u LEFT JOIN vendor_profiles vp ON vp.vendor_id=u.id WHERE u.id=? AND u.role='vendor' AND u.status='active'");
$stmt->execute([$vid]); $vendor=$stmt->fetch();
if(!$vendor){header('Location:'.BASE_URL.'/public/vendors.php');exit;}
$prods=$pdo->prepare("SELECT p.*,c.name AS cname FROM products p JOIN categories c ON c.id=p.category_id WHERE p.vendor_id=? AND p.status='active' ORDER BY p.is_featured DESC,p.views DESC LIMIT 20");
$prods->execute([$vid]); $products=$prods->fetchAll();
$pageTitle=sH($vendor['company']?:$vendor['name']).' — PaperMart';
?>
<div style="background:var(--brand);padding:32px 0;color:#fff">
  <div class="container">
    <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
      <div style="width:72px;height:72px;border-radius:16px;background:rgba(255,255,255,.15);font-family:'Raleway',sans-serif;font-size:30px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0"><?= strtoupper(substr($vendor['company']?:$vendor['name'],0,1)) ?></div>
      <div>
        <h1 style="font-size:24px;color:#fff;margin-bottom:4px"><?= sH($vendor['company']?:$vendor['name']) ?></h1>
        <?php if($vendor['tagline']): ?><p style="color:rgba(255,255,255,.75);font-size:14px"><?= sH($vendor['tagline']) ?></p><?php endif; ?>
        <div style="display:flex;gap:12px;margin-top:10px;flex-wrap:wrap;font-size:13px;color:rgba(255,255,255,.7)">
          <?php if($vendor['city']): ?><span>📍 <?= sH($vendor['city']) ?></span><?php endif; ?>
          <?php if($vendor['established_yr']): ?><span>🏭 Est. <?= $vendor['established_yr'] ?></span><?php endif; ?>
          <?php if($vendor['gst_number']): ?><span>🧾 GST: <?= sH($vendor['gst_number']) ?></span><?php endif; ?>
          <?php if($vendor['is_verified']): ?><span style="background:rgba(22,163,74,.3);border:1px solid rgba(22,163,74,.5);padding:2px 10px;border-radius:100px;color:#86efac">✓ Verified</span><?php endif; ?>
        </div>
      </div>
      <div style="margin-left:auto"><button class="btn btn-accent" onclick="openEnquiryModal(0,<?= $vid ?>,'General Enquiry')">📩 Contact Vendor</button></div>
    </div>
  </div>
</div>
<section class="compact">
  <div class="container">
    <h2 style="font-size:20px;margin-bottom:20px">Products by <?= sH($vendor['company']?:$vendor['name']) ?> <span style="font-size:14px;font-weight:400;color:var(--n500)">(<?= count($products) ?> products)</span></h2>
    <?php if($products): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:18px">
      <?php foreach($products as $p):
        $imgs=array_filter(explode(',',$p['images']??''));
        $img=reset($imgs)?UPLOAD_URL.trim(reset($imgs)):'';
      ?>
      <div class="card">
        <div class="card-img">
          <?php if($img): ?><img src="<?= sH($img) ?>" alt="<?= sH($p['name']) ?>" loading="lazy"><?php else: ?><span class="card-img-ph">📦</span><?php endif; ?>
          <div class="card-compare-pos"><button class="btn btn-compare" onclick="addToCompare(<?= $p['id'] ?>,'<?= sH($p['name']) ?>','<?= sH($p['images']??'') ?>')" data-id="<?= $p['id'] ?>">⚖️</button></div>
        </div>
        <div class="card-body">
          <div class="card-cat"><?= sH($p['cname']) ?></div>
          <div class="card-title"><a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>"><?= sH($p['name']) ?></a></div>
          <?php if($p['price_range']): ?><div class="card-price">₹ <?= sH($p['price_range']) ?></div><?php endif; ?>
        </div>
        <div class="card-footer">
          <a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm" style="flex:1">View</a>
          <button class="btn btn-accent btn-sm" onclick="openEnquiryModal(<?= $p['id'] ?>,<?= $p['vendor_id'] ?>,'<?= sH($p['name']) ?>')">Enquire</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?><div class="empty-state"><div class="empty-icon">📭</div><h3>No products yet</h3></div><?php endif; ?>
  </div>
</section>
<div class="modal-backdrop" id="enquiry-modal">
  <div class="modal"><div class="modal-header"><h3>📩 Send Enquiry</h3><button class="modal-close" onclick="closeEnquiryModal()">✕</button></div>
  <div class="modal-body">
    <div id="enq-success" class="site-alert site-alert-success" style="display:none"></div>
    <form id="enq-form" onsubmit="submitEnquiry(event)">
      <input type="hidden" id="enq-product-id" name="product_id"><input type="hidden" id="enq-vendor-id" name="vendor_id">
      <div id="enq-product-name" style="background:var(--n50);border-radius:var(--r-sm);padding:10px 14px;margin-bottom:16px;font-weight:600;color:var(--brand)"></div>
      <div class="form-row"><div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-input" required></div><div class="form-group"><label class="form-label">Email *</label><input type="email" name="email" class="form-input" required></div></div>
      <div class="form-row"><div class="form-group"><label class="form-label">Phone</label><input type="tel" name="phone" class="form-input"></div><div class="form-group"><label class="form-label">Company</label><input type="text" name="company" class="form-input"></div></div>
      <div class="form-group"><label class="form-label">Message / Requirements</label><textarea name="message" class="form-input" rows="4" style="resize:vertical"></textarea></div>
      <button type="submit" class="btn btn-accent btn-full btn-lg" id="enq-btn">Send Enquiry</button>
    </form>
  </div></div>
</div>
<script>
function openEnquiryModal(pid,vid,name){document.getElementById('enq-product-id').value=pid;document.getElementById('enq-vendor-id').value=vid;document.getElementById('enq-product-name').textContent='📦 '+name;document.getElementById('enq-success').style.display='none';document.getElementById('enq-form').style.display='block';document.getElementById('enq-btn').textContent='Send Enquiry';document.getElementById('enq-btn').disabled=false;document.getElementById('enquiry-modal').classList.add('open');}
function closeEnquiryModal(){document.getElementById('enquiry-modal').classList.remove('open');}
document.getElementById('enquiry-modal').addEventListener('click',function(e){if(e.target===this)closeEnquiryModal();});
function submitEnquiry(e){e.preventDefault();const btn=document.getElementById('enq-btn');btn.textContent='Sending…';btn.disabled=true;fetch(BASE+'/public/ajax/enquiry.php',{method:'POST',body:new FormData(e.target)}).then(r=>r.json()).then(d=>{if(d.ok){document.getElementById('enq-success').textContent=d.msg;document.getElementById('enq-success').style.display='flex';document.getElementById('enq-form').style.display='none';}else{btn.textContent='Send Enquiry';btn.disabled=false;alert(d.msg);}});}
</script>
<?php include __DIR__.'/includes/footer.php'; ?>
