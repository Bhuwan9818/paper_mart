<?php
$pageTitle='About PaperMart — B2B Paper Marketplace India'; $currentPage='about';
include __DIR__.'/includes/header.php';
$totalProds=$pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$totalVends=$pdo->query("SELECT COUNT(*) FROM users WHERE role='vendor' AND status='active'")->fetchColumn();
?>
<div style="background:linear-gradient(135deg,var(--brand),#0f4c75);padding:56px 0;color:#fff">
  <div class="container" style="text-align:center">
    <div class="section-label" style="color:#7dcfff">About PaperMart</div>
    <h1 style="color:#fff;font-size:clamp(1.8rem,4vw,3rem);margin-bottom:14px">India's B2B Paper Marketplace</h1>
    <p style="color:rgba(255,255,255,.75);font-size:1.05rem;max-width:560px;margin:0 auto">Connecting paper manufacturers and buyers since 2024. No commissions, no middlemen — just direct business.</p>
  </div>
</div>
<section><div class="container" style="max-width:860px">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center;margin-bottom:56px">
    <div>
      <div class="section-label">Our Mission</div>
      <h2 style="font-size:24px;margin-bottom:14px">Making B2B Paper Trading Simple</h2>
      <p style="color:var(--n500);line-height:1.8;margin-bottom:14px">PaperMart was built to eliminate friction from the paper procurement process. Whether you're a small packaging company sourcing kraft paper or a large FMCG brand needing bulk corrugated boxes, we make it easy to find the right supplier at the right price.</p>
      <p style="color:var(--n500);line-height:1.8">We're not a marketplace where you buy — we're a platform where you discover and connect. All transactions happen directly between you and the vendor.</p>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <?php foreach([[number_format($totalProds).'+'  ,'Products Listed'],['500+','Enquiries/Month'],[$totalVends.'+','Verified Vendors'],['24hrs','Avg. Response']] as [$n,$l]): ?>
      <div style="background:var(--n50);border:1px solid var(--n200);border-radius:var(--r-lg);padding:22px;text-align:center">
        <div style="font-family:'Poppins',sans-serif;font-size:28px;font-weight:800;color:var(--brand)"><?= $n ?></div>
        <div style="font-size:13px;color:var(--n500);margin-top:4px"><?= $l ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div style="margin-bottom:48px">
    <div class="section-label">How It Works</div>
    <h2 style="font-size:22px;margin-bottom:24px">Simple, Transparent, Free</h2>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px">
      <?php foreach([['For Buyers','🛍️',['Browse thousands of paper products','Filter by GSM, grade, type','Compare multiple vendors','Send enquiry for free','Get quotes directly from manufacturers']],['For Vendors','🏪',['Register your company free','List up to 3 products on free plan','Receive buyer enquiries','Upgrade for unlimited listings','Access analytics dashboard']],['For Everyone','🌍',['No commission on any deals','Verified suppliers only','Secure enquiry system','Mobile-friendly platform','Dedicated support team']]] as [$title,$icon,$features]): ?>
      <div style="padding:24px;border:1px solid var(--n200);border-radius:var(--r-lg)">
        <div style="font-size:36px;margin-bottom:12px"><?= $icon ?></div>
        <h3 style="font-size:16px;margin-bottom:14px"><?= $title ?></h3>
        <ul style="list-style:none;font-size:13.5px;color:var(--n500)">
          <?php foreach($features as $f): ?><li style="padding:5px 0;border-bottom:1px solid var(--n100);display:flex;gap:8px"><span style="color:var(--green);flex-shrink:0">✓</span><?= $f ?></li><?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div style="text-align:center;background:linear-gradient(135deg,var(--brand),#0f4c75);border-radius:var(--r-lg);padding:40px;color:#fff">
    <h2 style="color:#fff;margin-bottom:10px">Ready to Get Started?</h2>
    <p style="color:rgba(255,255,255,.75);margin-bottom:22px">Join thousands of buyers and vendors on PaperMart — it's free.</p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
      <a href="<?= BASE_URL ?>/public/products.php" class="btn btn-accent btn-lg">Browse Products</a>
      <a href="<?= BASE_URL ?>/public/vendor-register.php" class="btn btn-lg" style="background:rgba(255,255,255,.1);color:#fff;border:1.5px solid rgba(255,255,255,.3)">Become a Vendor</a>
    </div>
  </div>
</div></section>
<?php include __DIR__.'/includes/footer.php'; ?>
