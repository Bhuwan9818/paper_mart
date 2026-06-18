<?php
$currentPage = 'products';
include __DIR__.'/includes/header.php';

$id=$pdo->prepare("SELECT p.*,u.name AS vname,u.company,u.phone AS vphone,u.city AS vcity,u.state AS vstate,u.email AS vemail,vp.is_verified,vp.logo,vp.tagline,vp.established_yr,vp.certifications,i.name AS iname,c.name AS cname,pt.name AS tname,pt.id AS ptid FROM products p JOIN users u ON u.id=p.vendor_id LEFT JOIN vendor_profiles vp ON vp.vendor_id=p.vendor_id JOIN industries i ON i.id=p.industry_id JOIN categories c ON c.id=p.category_id JOIN product_types pt ON pt.id=p.product_type_id WHERE p.id=? AND p.status='active'");
$id->execute([(int)($_GET['id']??0)]); $p=$id->fetch();
if (!$p) { header('Location:'.BASE_URL.'/public/products.php'); exit; }

// Increment views
$pdo->prepare("UPDATE products SET views=views+1 WHERE id=?")->execute([$p['id']]);

// Attributes
$attrs=$pdo->prepare("SELECT * FROM product_attributes WHERE product_id=? ORDER BY sort_order");
$attrs->execute([$p['id']]); $attrList=$attrs->fetchAll();

// Other vendors selling same product type
$otherVendors=getVendorsByProduct($pdo,$p['ptid'],$p['id']);

// Related products
$related=$pdo->prepare("SELECT p2.*,u.name AS vname,u.company,vp.is_verified FROM products p2 JOIN users u ON u.id=p2.vendor_id LEFT JOIN vendor_profiles vp ON vp.vendor_id=p2.vendor_id WHERE p2.category_id=? AND p2.id!=? AND p2.status='active' ORDER BY p2.views DESC LIMIT 4");
$related->execute([$p['category_id'],$p['id']]); $relatedProds=$related->fetchAll();

$imgs=array_values(array_filter(array_map('trim',explode(',',$p['images']??''))));
$mainImg=$imgs?UPLOAD_URL.$imgs[0]:'';
$pageTitle=sH($p['name']).' — PaperMart';
// Prepare attribute lists: hide technical files (TDS) from specs/overview
$visibleAttrList = array_values(array_filter($attrList, function($a){
  $name = strtolower(trim($a['attribute_name'] ?? ''));
  return !($name === '__tds' || stripos($name,'tds') !== false);
}));
// Collect TDS filenames (normalized + deduplicated)
$tdsFiles = [];
foreach($attrList as $a){
  $an = strtolower(trim($a['attribute_name'] ?? ''));
  if($an==='__tds' || stripos($an,'tds')!==false){
    $fname = basename(trim($a['attribute_value'] ?? ''));
    if($fname) $tdsFiles[] = $fname;
  }
}
$tdsFiles = array_values(array_unique($tdsFiles));
?>

<!-- Breadcrumb -->
<div style="background:var(--n50);padding:12px 0;border-bottom:1px solid var(--n200)">
  <div class="container" style="font-size:13px;color:var(--n500)">
    <a href="<?= BASE_URL ?>/public/index.php" style="color:var(--brand-2)">Home</a> ›
    <a href="<?= BASE_URL ?>/public/products.php" style="color:var(--brand-2)">Products</a> ›
    <a href="<?= BASE_URL ?>/public/products.php?category=<?= $p['category_id'] ?>" style="color:var(--brand-2)"><?= sH($p['cname']) ?></a> ›
    <span><?= sH($p['name']) ?></span>
  </div>
</div>

<section class="compact">
  <div class="container">
    <div class="product-detail-layout">

      <!-- LEFT: Gallery + Details -->
      <div>
        <!-- Gallery -->
        <div class="gallery-main" id="main-gallery">
          <?php if($mainImg): ?><img src="<?= sH($mainImg) ?>" alt="<?= sH($p['name']) ?>" id="main-img"><?php else: ?>📦<?php endif; ?>
        </div>
        <?php if(count($imgs)>1): ?>
        <div class="gallery-thumbs">
          <?php foreach($imgs as $i=>$img): ?>
            <div class="g-thumb <?= $i===0?'active':'' ?>" onclick="switchImg('<?= sH(UPLOAD_URL.$img) ?>',this)">
              <img src="<?= sH(UPLOAD_URL.$img) ?>" alt="<?= sH($p['name']) ?> <?= $i+1 ?>">
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Product info tabs -->
        <div style="margin-top:28px">
          <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('overview',this)">Overview</button>
            <button class="tab-btn" onclick="switchTab('specs',this)">Specifications</button>
            <button class="tab-btn" onclick="switchTab('description',this)">Description</button>
            <button class="tab-btn" onclick="switchTab('vendor-info',this)">Vendor Info</button>
            <?php if($otherVendors): ?>
            <button class="tab-btn" onclick="switchTab('other-vendors',this)">Other Vendors <span style="background:var(--accent);color:#fff;border-radius:100px;padding:1px 7px;font-size:10px;margin-left:4px"><?= count($otherVendors) ?></span></button>
            <?php endif; ?>
          </div>

          <div class="tab-panel active" id="tab-overview">
            <h2 style="font-size:20px;margin-bottom:12px"><?= sH($p['name']) ?></h2>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap">
              <span class="badge badge-blue"><?= sH($p['cname']) ?></span>
              <span class="badge badge-gray"><?= sH($p['tname']) ?></span>
              <?php if($p['is_verified']): ?><span class="badge badge-green">✓ Verified Vendor</span><?php endif; ?>
            </div>
            <?php if($p['price_range']): ?><div style="font-size:20px;font-weight:700;color:var(--brand);margin-bottom:12px">₹ <?= sH($p['price_range']) ?> <span style="font-size:13px;font-weight:400;color:var(--n500)">/ unit</span></div><?php endif; ?>
            <?php if($p['min_order_qty']): ?><p style="font-size:13.5px;color:var(--n500);margin-bottom:16px">📦 Min. Order: <strong><?= sH($p['min_order_qty']) ?></strong></p><?php endif; ?>
            <p style="font-size:14.5px;line-height:1.75;color:var(--n700)"><?= nl2br(sH($p['short_desc']?:$p['description'])) ?></p>

            <?php if($attrList): ?>
            <div style="margin-top:20px">
              <h3 style="font-size:15px;margin-bottom:12px">Key Specifications</h3>
              <div style="display:flex;flex-wrap:wrap;gap:8px">
                <?php foreach(array_slice($visibleAttrList,0,6) as $a): ?>
                <div style="background:var(--n50);border:1px solid var(--n200);border-radius:var(--r-sm);padding:10px 14px;min-width:120px">
                  <div style="font-size:11px;color:var(--n500);text-transform:uppercase;letter-spacing:.05em;font-weight:600"><?= sH($a['attribute_name']) ?></div>
                  <div style="font-size:16px;font-weight:700;color:var(--brand);margin-top:3px"><?= sH($a['attribute_value']) ?><?= $a['attribute_unit']?' '.sH($a['attribute_unit']):'' ?></div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if($tdsFiles): ?>
            <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
              <div style="font-size:13px;color:var(--n500);font-weight:600">TDS:</div>
              <?php foreach($tdsFiles as $tf): $f=basename($tf); $fpath=__DIR__.'/../assets/tds/'.$f; $furl=BASE_URL.'/assets/tds/'.$f; if(!file_exists($fpath)) continue; ?>
                <div style="display:inline-flex;align-items:center;gap:15px;padding:6px 10px;border-radius:999px;background:#f8fafc;border:1px solid var(--n200)">
                  <span style="font-size:13px;font-weight:600;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= sH($f) ?></span>
                  <a class="btn btn-outline btn-sm" href="<?= sH($furl) ?>" target="_blank" style="padding:6px 8px">View</a>
                  <a class="btn btn-accent btn-sm" href="<?= sH($furl) ?>" download style="padding:6px 8px">Download</a>
                </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:10px;margin-top:22px;flex-wrap:wrap">
              <button class="btn btn-accent btn-lg" onclick="document.getElementById('enq-panel').scrollIntoView({behavior:'smooth'})">📩 Send Enquiry</button>
              <button class="btn btn-outline" onclick="addToCompare(<?= $p['id'] ?>,'<?= sH($p['name']) ?>','<?= sH($p['images']??'') ?>')">⚖️ Add to Compare</button>
            </div>
            
          </div>

          <div class="tab-panel" id="tab-specs">
            <?php if($visibleAttrList): ?>
            <table class="attr-table">
              <tbody>
              <?php foreach($visibleAttrList as $a): ?>
                <tr><td><?= sH($a['attribute_name']) ?></td><td><strong><?= sH($a['attribute_value']) ?></strong><?= $a['attribute_unit']?' '.sH($a['attribute_unit']):'' ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php else: ?><p style="color:var(--n500)">No specifications listed for this product.</p><?php endif; ?>
          </div>

          <div class="tab-panel" id="tab-description">
            <div style="display:grid;grid-template-columns:1fr;gap:20px;align-items:start">
              <div style="background:#fff;border:1px solid var(--n200);border-radius:12px;padding:22px;box-shadow:0 8px 30px rgba(15,23,42,0.04)">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
                  <h3 style="margin:0;font-size:18px;color:var(--n900)">Product Description</h3>
                  <div style="flex:1"></div>
                  <span style="font-size:13px;color:var(--n500)"><?= sH($p['tname']) ?></span>
                </div>
                <?php if(trim($p['description'])): ?>
                <div style="color:var(--n700);line-height:1.85;font-size:15px;white-space:pre-wrap"><?= nl2br(sH($p['description'])) ?></div>
                <?php else: ?>
                <p style="color:var(--n500);margin:0">No extended description available for this product.</p>
                <?php endif; ?>
              </div>

              <aside>
                <div style="background:linear-gradient(180deg,#fff,#fbfdff);border:1px solid #eef6ff;border-radius:12px;padding:16px;box-shadow:0 8px 24px rgba(11,22,70,0.06)">
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                    <div style="font-weight:700;color:#0f172a">TDS & Reports</div>
                    <div style="font-size:12px;color:var(--n500)">Technical files</div>
                  </div>
                  <div style="font-size:13px;color:var(--n500);margin-bottom:12px">Open or download technical datasheets and reports provided by the vendor.</div>

                  <?php
                  $tdsFiles = [];
                  foreach($attrList as $a){
                    $an = strtolower(trim($a['attribute_name'] ?? ''));
                    if($an==='__tds' || stripos($an,'tds')!==false){
                      $fname = basename(trim($a['attribute_value'] ?? ''));
                      if($fname) $tdsFiles[] = $fname;
                    }
                  }
                  $tdsFiles = array_values(array_unique($tdsFiles));
                  if(!$tdsFiles): ?>
                    <div style="padding:10px;border-radius:8px;background:#fff;border:1px dashed var(--n200);color:var(--n500)">No reports available for this product.</div>
                  <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:10px">
                      <?php foreach($tdsFiles as $f): $file = basename($f); $fpath=__DIR__.'/../assets/tds/'.$file; $furl=BASE_URL.'/assets/tds/'.$file; if(!file_exists($fpath)) continue; $fs=filesize($fpath); if($fs>1024*1024) $fsize=round($fs/1024/1024,2).' MB'; elseif($fs>1024) $fsize=round($fs/1024,2).' KB'; else $fsize=$fs.' B'; ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px;border-radius:10px;background:#fff;border:1px solid var(--n200)">
                          <div style="display:flex;gap:10px;align-items:center;min-width:0">
                            <div style="width:44px;height:44px;border-radius:8px;background:#eef2ff;color:#1e40af;display:flex;align-items:center;justify-content:center;font-weight:800">PDF</div>
                            <div style="min-width:0">
                              <div style="font-weight:700;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:180px"><?= sH($file) ?></div>
                              <div style="font-size:12px;color:var(--n500)"><?= sH($fsize) ?></div>
                            </div>
                          </div>
                          <div style="display:flex;gap:8px;flex-shrink:0">
                            <a class="btn btn-outline btn-sm" href="<?= sH($furl) ?>" target="_blank">View</a>
                            <a class="btn btn-accent btn-sm" href="<?= sH($furl) ?>" download>Download</a>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </aside>
            </div>
          </div>

          <div class="tab-panel" id="tab-vendor-info">
            <div class="vendor-card" style="margin-bottom:16px">
              <div class="vc-header">
                <div class="vc-logo"><?= strtoupper(substr($p['vname'],0,1)) ?></div>
                <div>
                  <div class="vc-name"><?= sH($p['company']?:$p['vname']) ?></div>
                  <?php if($p['tagline']): ?><div class="vc-tag"><?= sH($p['tagline']) ?></div><?php endif; ?>
                  <?php if($p['is_verified']): ?><span class="badge badge-green" style="margin-top:5px">✓ Verified Vendor</span><?php endif; ?>
                </div>
              </div>
              <?php if($p['certifications']): ?><p style="font-size:13px;color:var(--n500)">🏅 <?= sH($p['certifications']) ?></p><?php endif; ?>
              <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
                <?php if($p['vcity']||$p['vstate']): ?><span style="font-size:13px;color:var(--n500)">📍 <?= sH(trim($p['vcity'].', '.$p['vstate'],', ')) ?></span><?php endif; ?>
                <?php if($p['established_yr']): ?><span style="font-size:13px;color:var(--n500)">🏭 Est. <?= $p['established_yr'] ?></span><?php endif; ?>
              </div>
              <a href="<?= BASE_URL ?>/public/vendor-profile.php?id=<?= $p['vendor_id'] ?>" class="btn btn-outline btn-sm" style="margin-top:14px">View All Products by This Vendor →</a>
            </div>
          </div>

          <?php if($otherVendors): ?>
          <div class="tab-panel" id="tab-other-vendors">
            <p style="font-size:14px;color:var(--n500);margin-bottom:16px"><strong><?= count($otherVendors) ?></strong> other vendor(s) also sell similar <?= sH($p['tname']) ?>. Compare and choose the best one for your needs.</p>
            <div class="vendor-cards">
              <?php foreach($otherVendors as $ov):
                $ovimgs=array_filter(explode(',',$ov['images']??''));
                $ovimg=reset($ovimgs)?UPLOAD_URL.trim(reset($ovimgs)):'';
              ?>
              <div class="vendor-card">
                <div class="vc-header">
                  <div class="vc-logo"><?= strtoupper(substr($ov['vendor_name'],0,1)) ?></div>
                  <div>
                    <div class="vc-name"><?= sH($ov['company']?:$ov['vendor_name']) ?></div>
                    <?php if($ov['is_verified']): ?><span class="badge badge-green" style="font-size:10px">✓ Verified</span><?php endif; ?>
                  </div>
                </div>
                <div style="font-size:13.5px;font-weight:700;color:var(--brand);margin-bottom:10px"><?= sH($ov['name']) ?></div>
                <?php if($ov['price_range']): ?><div style="font-size:13px;color:var(--n500);margin-bottom:10px">₹ <?= sH($ov['price_range']) ?></div><?php endif; ?>
                <div style="display:flex;gap:8px">
                  <a href="<?= BASE_URL ?>/public/product.php?id=<?= $ov['id'] ?>" class="btn btn-outline btn-sm" style="flex:1">View Product</a>
                  <button class="btn btn-accent btn-sm" onclick="openEnquiryModal(<?= $ov['id'] ?>,<?= $ov['vendor_id'] ?>,'<?= sH($ov['name']) ?>')">Enquire</button>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- RIGHT: Enquiry Panel -->
      <div id="enq-panel">
        <div class="enquiry-panel">
          <h3>📩 Get Best Price</h3>
          <p>Send your requirements to <?= sH($p['company']?:$p['vname']) ?> and get a quote within 24 hours.</p>
          <div id="enq-success-panel" class="site-alert site-alert-success" style="display:none;background:rgba(22,163,74,.2);border-color:rgba(255,255,255,.4);color:#fff"></div>
          <form id="enq-form-panel" onsubmit="submitEnquiryPanel(event)">
            <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
            <input type="hidden" name="vendor_id"  value="<?= $p['vendor_id'] ?>">
            <input type="text"   name="name"    class="eq-input" placeholder="Your Name *"  required>
            <input type="email"  name="email"   class="eq-input" placeholder="Email Address *" required>
            <input type="tel"    name="phone"   class="eq-input" placeholder="Phone Number">
            <input type="text"   name="company" class="eq-input" placeholder="Company Name">
            <input type="text"   name="city"    class="eq-input" placeholder="Your City">
            <input type="text"   name="qty_needed" class="eq-input" placeholder="Quantity Needed (e.g. 500 kg)">
            <textarea name="message" class="eq-input" rows="3" placeholder="Your requirements, specifications…" style="resize:vertical"></textarea>
            <button type="submit" class="eq-submit" id="enq-panel-btn">Send Enquiry →</button>
            <p style="font-size:11.5px;color:rgba(255,255,255,.55);margin-top:10px;text-align:center">Free service · No spam · Direct to vendor</p>
          </form>
        </div>

        <!-- Quick stats -->
        <div style="background:var(--n50);border:1px solid var(--n200);border-radius:var(--r-lg);padding:18px;margin-top:14px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;text-align:center">
            <div><div style="font-size:20px;font-weight:800;color:var(--brand)"><?= $p['views'] ?></div><div style="font-size:12px;color:var(--n500)">Total Views</div></div>
            <div><div style="font-size:20px;font-weight:800;color:var(--brand)"><?= count($otherVendors)+1 ?></div><div style="font-size:12px;color:var(--n500)">Vendors Available</div></div>
          </div>
          <?php if($p['min_order_qty']): ?>
          <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--n200);font-size:13px;color:var(--n500)">
            📦 Min. Order: <strong style="color:var(--n900)"><?= sH($p['min_order_qty']) ?></strong>
          </div>
          <?php endif; ?>
          <button class="btn btn-outline btn-sm btn-full" style="margin-top:12px" onclick="addToCompare(<?= $p['id'] ?>,'<?= sH($p['name']) ?>','<?= sH($p['images']??'') ?>')">⚖️ Add to Compare</button>
        </div>
      </div>
    </div>

    <!-- Related Products -->
    <?php if($relatedProds): ?>
    <div style="margin-top:48px">
      <h2 style="font-size:20px;margin-bottom:20px">Related Products in <?= sH($p['cname']) ?></h2>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px">
        <?php foreach($relatedProds as $rp):
          $rimgs=array_filter(explode(',',$rp['images']??''));
          $rimg=reset($rimgs)?UPLOAD_URL.trim(reset($rimgs)):'';
        ?>
        <div class="card">
          <div class="card-img">
            <?php if($rimg): ?><img src="<?= sH($rimg) ?>" alt="<?= sH($rp['name']) ?>" loading="lazy"><?php else: ?><span class="card-img-ph">📦</span><?php endif; ?>
            <div class="card-compare-pos"><button class="btn btn-compare" onclick="addToCompare(<?= $rp['id'] ?>,'<?= sH($rp['name']) ?>','<?= sH($rp['images']??'') ?>')" data-id="<?= $rp['id'] ?>">⚖️</button></div>
          </div>
          <div class="card-body">
            <div class="card-title"><a href="<?= BASE_URL ?>/public/product.php?id=<?= $rp['id'] ?>"><?= sH($rp['name']) ?></a></div>
            <?php if($rp['price_range']): ?><div class="card-price">₹ <?= sH($rp['price_range']) ?></div><?php endif; ?>
            <div class="card-vendor">
              <div class="vav"><?= strtoupper(substr($rp['vname'],0,1)) ?></div>
              <div class="v-name"><?= sH($rp['company']?:$rp['vname']) ?></div>
              <?php if($rp['is_verified']): ?><div class="v-verified">✓</div><?php endif; ?>
            </div>
          </div>
          <div class="card-footer">
            <a href="<?= BASE_URL ?>/public/product.php?id=<?= $rp['id'] ?>" class="btn btn-outline btn-sm" style="flex:1">View</a>
            <button class="btn btn-accent btn-sm" onclick="openEnquiryModal(<?= $rp['id'] ?>,<?= $rp['vendor_id'] ?>,'<?= sH($rp['name']) ?>')">Enquire</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- Enquiry modal (for related cards) -->
<div class="modal-backdrop" id="enquiry-modal">
  <div class="modal"><div class="modal-header"><h3>📩 Send Enquiry</h3><button class="modal-close" onclick="closeEnquiryModal()">✕</button></div>
  <div class="modal-body">
    <div id="enq-success" class="site-alert site-alert-success" style="display:none"></div>
    <form id="enq-form" onsubmit="submitEnquiry(event)">
      <input type="hidden" id="enq-product-id" name="product_id"><input type="hidden" id="enq-vendor-id" name="vendor_id">
      <div id="enq-product-name" style="background:var(--n50);border-radius:var(--r-sm);padding:10px 14px;margin-bottom:16px;font-weight:600;font-size:13.5px;color:var(--brand)"></div>
      <div class="form-row"><div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-input" required></div><div class="form-group"><label class="form-label">Email *</label><input type="email" name="email" class="form-input" required></div></div>
      <div class="form-row"><div class="form-group"><label class="form-label">Phone</label><input type="tel" name="phone" class="form-input"></div><div class="form-group"><label class="form-label">Company</label><input type="text" name="company" class="form-input"></div></div>
      <div class="form-group"><label class="form-label">Message</label><textarea name="message" class="form-input" rows="3" style="resize:vertical"></textarea></div>
      <button type="submit" class="btn btn-accent btn-full btn-lg" id="enq-btn">Send Enquiry</button>
    </form>
  </div></div>
</div>

<script>
function switchImg(src,el){document.getElementById('main-img').src=src;document.querySelectorAll('.g-thumb').forEach(t=>t.classList.remove('active'));el.classList.add('active');}
function switchTab(id,el){document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));document.getElementById('tab-'+id).classList.add('active');el.classList.add('active');}
function openEnquiryModal(pid,vid,name){document.getElementById('enq-product-id').value=pid;document.getElementById('enq-vendor-id').value=vid;document.getElementById('enq-product-name').textContent='📦 '+name;document.getElementById('enq-success').style.display='none';document.getElementById('enq-form').style.display='block';document.getElementById('enq-btn').textContent='Send Enquiry';document.getElementById('enq-btn').disabled=false;document.getElementById('enquiry-modal').classList.add('open');}
function closeEnquiryModal(){document.getElementById('enquiry-modal').classList.remove('open');}
document.getElementById('enquiry-modal').addEventListener('click',function(e){if(e.target===this)closeEnquiryModal();});
function submitEnquiry(e){e.preventDefault();const btn=document.getElementById('enq-btn');btn.textContent='Sending…';btn.disabled=true;fetch(BASE+'/public/ajax/enquiry.php',{method:'POST',body:new FormData(e.target)}).then(r=>r.json()).then(d=>{if(d.ok){document.getElementById('enq-success').textContent=d.msg;document.getElementById('enq-success').style.display='flex';document.getElementById('enq-form').style.display='none';}else{btn.textContent='Send Enquiry';btn.disabled=false;alert(d.msg);}});}
function submitEnquiryPanel(e){e.preventDefault();const btn=document.getElementById('enq-panel-btn');btn.textContent='Sending…';btn.disabled=true;fetch(BASE+'/public/ajax/enquiry.php',{method:'POST',body:new FormData(e.target)}).then(r=>r.json()).then(d=>{if(d.ok){document.getElementById('enq-success-panel').textContent='✅ '+d.msg;document.getElementById('enq-success-panel').style.display='flex';document.getElementById('enq-form-panel').style.display='none';}else{btn.textContent='Send Enquiry →';btn.disabled=false;alert(d.msg);}});}
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
