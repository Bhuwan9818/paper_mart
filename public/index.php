<?php
$pageTitle   = 'paperKart — India\'s #1 B2B Paper & Packaging Marketplace';
$pageDesc    = 'Source kraft paper, corrugated boxes, duplex board and packaging materials from verified manufacturers across India.';
$currentPage = 'home';
include __DIR__.'/includes/header.php';

// Data
$industries  = $pdo->query("SELECT i.*,(SELECT COUNT(*) FROM categories WHERE industry_id=i.id AND status=1) AS cat_count FROM industries i WHERE i.status=1 ORDER BY i.sort_order LIMIT 8")->fetchAll();
$featured    = $pdo->query("SELECT p.*,u.name AS vname,u.company,vp.is_verified,i.name AS iname,c.name AS cname FROM products p JOIN users u ON u.id=p.vendor_id LEFT JOIN vendor_profiles vp ON vp.vendor_id=p.vendor_id JOIN industries i ON i.id=p.industry_id JOIN categories c ON c.id=p.category_id WHERE p.status='active' AND p.is_featured=1 ORDER BY p.views DESC LIMIT 8")->fetchAll();
$latest      = $pdo->query("SELECT p.*,u.name AS vname,u.company,vp.is_verified,c.name AS cname FROM products p JOIN users u ON u.id=p.vendor_id LEFT JOIN vendor_profiles vp ON vp.vendor_id=p.vendor_id JOIN categories c ON c.id=p.category_id WHERE p.status='active' ORDER BY p.created_at DESC LIMIT 8")->fetchAll();
$totalProds  = $pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$totalVends  = $pdo->query("SELECT COUNT(*) FROM users WHERE role='vendor' AND status='active'")->fetchColumn();
$totalEnqs   = $pdo->query("SELECT COUNT(*) FROM web_enquiries")->fetchColumn();
$categories  = $pdo->query("SELECT c.*,i.name AS iname,(SELECT COUNT(*) FROM products WHERE category_id=c.id AND status='active') AS prod_count FROM categories c JOIN industries i ON i.id=c.industry_id WHERE c.status=1 ORDER BY prod_count DESC LIMIT 12")->fetchAll();

// Most Popular Brands — verified/active vendors ranked by live catalogue size.
$popularVendors = $pdo->query(
    "SELECT u.id, u.name, u.company, u.city, u.country,
            vp.is_verified, vp.logo, vp.tagline, vp.rating, vp.total_reviews,
            COUNT(p.id) AS prod_count
     FROM users u
     JOIN products p ON p.vendor_id = u.id AND p.status='active'
     LEFT JOIN vendor_profiles vp ON vp.vendor_id = u.id
     WHERE u.role='vendor' AND u.status='active'
     GROUP BY u.id
     ORDER BY vp.is_verified DESC, prod_count DESC, vp.rating DESC
     LIMIT 8"
)->fetchAll();

// "By The Numbers" / Platform Metrics impact band — 100% real dynamic counts from backend
$totalCustomersHandled = (int)$pdo->query(
    "SELECT COUNT(DISTINCT email) FROM (
        SELECT email FROM users WHERE role='customer' AND email != ''
        UNION 
        SELECT email FROM web_enquiries WHERE email IS NOT NULL AND email != ''
    ) t"
)->fetchColumn();
if ($totalCustomersHandled === 0) {
    $totalCustomersHandled = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
}
$totalVendorsRegistered = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='vendor'")->fetchColumn();
$totalLeadsGiven = 0;
try {
    $totalLeadsGiven = (int)$pdo->query("SELECT COUNT(*) FROM v_all_enquiries")->fetchColumn();
} catch (Exception $e) {
    $totalLeadsGiven = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM enquiries) + (SELECT COUNT(*) FROM web_enquiries)")->fetchColumn();
}
$totalActiveProds = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$totalCatsLive    = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE status=1")->fetchColumn();
$totalLiveSpecs   = (int)$pdo->query("SELECT COUNT(*) FROM product_attributes")->fetchColumn();

// Compare Products showcase — pick the most popular category and its top 3
// products, then surface only the attributes they actually share, so the
// teaser table is always meaningful instead of full of blank cells.
$compareCat      = $categories[0] ?? null;
$compareProducts = [];
$compareAttrRows = [];
if ($compareCat) {
    // Prefer products with images first, then by views, for better visual showcase
    $cpStmt = $pdo->prepare(
        "SELECT p.id, p.name, p.images, p.price_range,
                u.name AS vname, u.company, u.city, u.state,
                vp.is_verified, vp.rating, vp.logo
         FROM products p
         JOIN users u ON u.id = p.vendor_id
         LEFT JOIN vendor_profiles vp ON vp.vendor_id = p.vendor_id
         WHERE p.category_id = ? AND p.status = 'active'
         ORDER BY (CASE WHEN p.images IS NOT NULL AND p.images != '' THEN 1 ELSE 0 END) DESC,
                  p.views DESC, p.created_at DESC LIMIT 3"
    );
    $cpStmt->execute([$compareCat['id']]);
    $compareProducts = $cpStmt->fetchAll();

    if (count($compareProducts) >= 2) {
        $ids = implode(',', array_map('intval', array_column($compareProducts, 'id')));
        $rows = $pdo->query(
            "SELECT product_id, attribute_name, attribute_value FROM product_attributes
             WHERE product_id IN($ids) AND attribute_name NOT IN ('__price','__tds')
             ORDER BY sort_order"
        )->fetchAll();
        $byAttr = [];
        foreach ($rows as $r) $byAttr[$r['attribute_name']][$r['product_id']] = $r['attribute_value'];
        // Only keep attributes shared by at least 2 of the 3 products, capped to 7 rows.
        $byAttr = array_filter($byAttr, fn($v) => count($v) >= 2);
        $compareAttrRows = array_slice($byAttr, 0, 7, true);
    }
    if (count($compareProducts) < 2 || empty($compareAttrRows)) { $compareProducts = []; } // nothing meaningful to show
}

// Countries vendors operate from, and the full active industries list —
// for the two modes of the hero search card (By Country / By Industry).
$searchCardIndustries = $pdo->query("SELECT id, name FROM industries WHERE status=1 ORDER BY sort_order, name")->fetchAll();
$searchCardVendors = $pdo->query(
    "SELECT DISTINCT u.id, COALESCE(u.company, u.name) AS label
     FROM users u JOIN products p ON p.vendor_id=u.id AND p.status='active'
     WHERE u.role='vendor' AND u.status='active' ORDER BY label ASC LIMIT 200"
)->fetchAll();
$heroCountries = $pdo->query(
    "SELECT DISTINCT u.country
     FROM users u JOIN products p ON p.vendor_id=u.id AND p.status='active'
     WHERE u.role='vendor' AND u.status='active' AND u.country IS NOT NULL AND u.country <> ''
     ORDER BY u.country ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// Hero carousel — priority order:
// 1. Vendor banner_ads that are currently running in an active time slot
// 2. Admin-managed fallback banners (when no vendor ads are running)
// 3. Empty array → plain gradient hero fallback
try {
    $heroBanners = $pdo->query(
        "SELECT ba.id, ba.image, ba.title, ba.subtitle, ba.link_url, ba.button_text,
                ba.sort_order, ba.impressions, ba.clicks,
                u.company AS vendor_company,
                s.name AS slot_name, s.start_time, s.end_time
         FROM banner_ads ba
         JOIN users u    ON u.id = ba.vendor_id
         JOIN ad_slots s ON s.id = ba.slot_id
         WHERE ba.status   = 'running'
           AND s.is_active = 1
           AND ba.start_date <= CURDATE()
           AND ba.end_date   >= CURDATE()
           AND CURTIME() BETWEEN s.start_time AND s.end_time
         ORDER BY ba.sort_order ASC, ba.id ASC
         LIMIT 20"  // safety cap only — real capacity is enforced per-slot at booking time
    )->fetchAll();
    // Increment impression counter for every ad shown this page load
    if ($heroBanners) {
        $ids = implode(',', array_map(fn($b) => (int)$b['id'], $heroBanners));
        $pdo->query("UPDATE banner_ads SET impressions = impressions + 1 WHERE id IN($ids)");
    }
} catch (Exception $e) {
    $heroBanners = [];
}
// If no vendor ads are active right now, fall back to admin-managed banners
if (empty($heroBanners)) {
    try {
        $heroBanners = $pdo->query(
            "SELECT id, image, title, subtitle, link_url, button_text, sort_order,
                    NULL AS vendor_company, NULL AS slot_name
             FROM banners
             WHERE status = 'active'
             ORDER BY sort_order ASC, id ASC
             LIMIT 20"
        )->fetchAll();
    } catch (Exception $e) {
        $heroBanners = []; // banners table doesn't exist yet — plain hero shown
    }
}

$catIcons=['Corrugated Boxes'=>'📦','Kraft Paper'=>'📜','Duplex Board'=>'🗂️','Mono Carton'=>'🎁','Woven Fabric'=>'🧵','Non-Woven Fabric'=>'🎀','Industrial Adhesives'=>'🧴','Surface Coatings'=>'🖌️'];
?>

<!-- AD DISPLAY SECTION -->
<div class="ad-stage-wrap">
<section class="ad-stage" id="hero-carousel">
  <?php if ($heroBanners): ?>
    <div class="ad-slides" aria-hidden="true">
      <?php foreach ($heroBanners as $i => $b): ?>
        <img class="ad-slide <?= $i===0?'active':'' ?>"
             src="<?= sH(UPLOAD_URL.$b['image']) ?>"
             alt="<?= sH($b['title'] ?: 'Promotional banner') ?>"
             loading="<?= $i===0?'eager':'lazy' ?>" decoding="async">
      <?php endforeach; ?>
    </div>
    <?php if (count($heroBanners) > 1): ?>
    <!-- Progress bar: position:absolute sticks to bottom of .ad-stage -->
    <div class="ad-progress" id="ad-progress">
      <div class="container">
        <div class="ad-progress-inner">
          <span class="ad-progress-num" id="ad-progress-current">01</span>
          <div class="ad-progress-track"><div class="ad-progress-fill" id="ad-progress-fill"></div></div>
          <span class="ad-progress-num ad-progress-total"><?= sprintf('%02d', count($heroBanners)) ?></span>
          <button class="ad-progress-btn" id="hero-prev" aria-label="Previous banner">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
          </button>
          <button class="ad-progress-btn" id="hero-next" aria-label="Next banner">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
          </button>
        </div>
      </div>
    </div>
    <?php endif; ?>
  <?php else: ?>
    <!-- Fallback: position:absolute fills same .ad-stage box, aspect-ratio still drives height -->
    <div class="ad-stage-empty">
      <div class="container">
        <div class="hero-label"><span class="hero-label-dot"></span>India's #1 B2B Paper Marketplace</div>
        <h1>Source <em>Quality Paper</em> Directly from Manufacturers</h1>
        <p class="hero-desc">Connect with verified suppliers of kraft paper, corrugated boxes, duplex board, and packaging materials. Get the best prices — no middlemen.</p>
        <div class="hero-ctas">
          <a href="<?= BASE_URL ?>/public/products.php" class="btn btn-accent btn-lg">Browse Products</a>
          <a href="<?= BASE_URL ?>/public/enquiry.php" class="btn btn-lg" style="background:rgba(255,255,255,.1);color:#fff;border:1.5px solid rgba(255,255,255,.3)">Send Enquiry</a>
        </div>
      </div>
    </div>
  <?php endif; ?>
</section>

<?php if ($heroBanners): ?>
<!-- Search card: sibling of .ad-stage, not a child — this is deliberate.
     .ad-stage has overflow:hidden + a fixed aspect-ratio height, so on
     mobile (where the card flows below the banner instead of overlaying
     it) a child element would get clipped. Being a sibling inside the
     same .ad-stage-wrap avoids that, while CSS still visually overlays it
     on desktop via absolute positioning. -->
<div class="ad-stage-inner">
  <div class="container">
    <div class="ad-search-card">
      <h3>Find Your Products</h3>

      <div class="ad-search-toggle">
        <label class="ad-search-radio" onclick="asSwitchMode('brand')">
          <input type="radio" name="search-mode" value="brand" onchange="asSwitchMode('brand')">
          <span class="ad-search-radio-dot"></span> By Brand
        </label>
        <label class="ad-search-radio" onclick="asSwitchMode('industry')">
          <input type="radio" name="search-mode" value="industry" checked onchange="asSwitchMode('industry')">
          <span class="ad-search-radio-dot"></span> By Industry
        </label>
      </div>

      <form action="<?= BASE_URL ?>/public/products.php" method="GET" id="hero-search-form">
        <!-- Brand mode: Country → Brand → Industry → Category -->
        <div id="as-group-brand" class="as-group" style="display:none">
          <select name="country" id="asb-country" class="ad-search-field" onchange="asOnBrandModeCountryChange()">
            <option value="">All Countries</option>
            <?php foreach($heroCountries as $c): ?>
              <option value="<?= sH($c) ?>"><?= sH($c) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="vendor" id="asb-brand" class="ad-search-field" onchange="asOnBrandModeBrandChange()">
            <option value="">All Brands</option>
            <?php foreach($searchCardVendors as $v): ?>
              <option value="<?= $v['id'] ?>"><?= sH($v['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="industry" id="asb-industry" class="ad-search-field" onchange="asOnBrandModeIndustryChange()">
            <option value="">All Industries</option>
            <?php foreach($searchCardIndustries as $ind): ?>
              <option value="<?= $ind['id'] ?>"><?= sH($ind['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="category" id="asb-category" class="ad-search-field" disabled>
            <option value="">All Categories</option>
          </select>
        </div>

        <!-- Industry mode: Industry → Category → Product Type → Country → Brand -->
        <div id="as-group-industry" class="as-group">
          <select name="industry" id="asi-industry" class="ad-search-field" onchange="asOnIndustryChange()">
            <option value="">Select Industry</option>
            <?php foreach($searchCardIndustries as $ind): ?>
              <option value="<?= $ind['id'] ?>"><?= sH($ind['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="category" id="asi-category" class="ad-search-field" onchange="asOnCategoryChange()" disabled>
            <option value="">Select Industry first…</option>
          </select>
          <select name="type" id="asi-type" class="ad-search-field" disabled>
            <option value="">Select Category first…</option>
          </select>
          <select name="country" id="asi-country" class="ad-search-field" onchange="asOnIndustryModeCountryChange()">
            <option value="">All Countries</option>
            <?php foreach($heroCountries as $c): ?>
              <option value="<?= sH($c) ?>"><?= sH($c) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="vendor" id="asi-brand" class="ad-search-field">
            <option value="">All Brands</option>
            <?php foreach($searchCardVendors as $v): ?>
              <option value="<?= $v['id'] ?>"><?= sH($v['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        
        <button type="submit" class="ad-search-submit">Search Products</button>
      </form>
      <a href="<?= BASE_URL ?>/public/products.php" class="ad-search-advanced">Browse All Categories →</a>
    </div>
  </div>
</div>
<?php endif; ?>
</div><!-- /.ad-stage-wrap -->

<!-- STATS STRIP -->
<section class="stats-strip">
  <div class="container">
    <div class="stats-strip-grid">
      <div class="stats-strip-item"><div class="stats-strip-n"><?= number_format($totalProds) ?>+</div><div class="stats-strip-l">Products Listed</div></div>
      <div class="stats-strip-item"><div class="stats-strip-n"><?= number_format($totalVends) ?>+</div><div class="stats-strip-l">Verified Vendors</div></div>
      <div class="stats-strip-item"><div class="stats-strip-n"><?= number_format($totalEnqs+1200) ?>+</div><div class="stats-strip-l">Enquiries Sent</div></div>
    </div>
  </div>
</section>

<?php if (count($heroBanners) > 1): ?>
<script>
(function(){
  const SLIDE_DURATION = 6000;
  const slideEls = document.querySelectorAll('#hero-carousel .ad-slide');
  const prevBtn  = document.getElementById('hero-prev');
  const nextBtn  = document.getElementById('hero-next');
  const numEl    = document.getElementById('ad-progress-current');
  const fillEl   = document.getElementById('ad-progress-fill');
  let current = 0, timer = null;

  function render(i){
    current = i;
    slideEls.forEach((el,idx) => el.classList.toggle('active', idx===i));
    if (numEl) numEl.textContent = String(i+1).padStart(2,'0');
    if (fillEl) {
      fillEl.style.transition = 'none';
      fillEl.style.width = '0%';
      void fillEl.offsetWidth;
      fillEl.style.transition = 'width '+SLIDE_DURATION+'ms linear';
      fillEl.style.width = '100%';
    }
  }
  function next(){ render((current+1) % slideEls.length); }
  function prev(){ render((current-1+slideEls.length) % slideEls.length); }
  function startAutoplay(){ clearInterval(timer); timer = setInterval(next, SLIDE_DURATION); }

  if (prevBtn) prevBtn.addEventListener('click', () => { prev(); startAutoplay(); });
  if (nextBtn) nextBtn.addEventListener('click', () => { next(); startAutoplay(); });

  const heroEl = document.getElementById('hero-carousel');
  heroEl.addEventListener('mouseenter', () => clearInterval(timer));
  heroEl.addEventListener('mouseleave', startAutoplay);

  let startX = 0;
  heroEl.addEventListener('touchstart', e => { startX = e.touches[0].clientX; }, {passive:true});
  heroEl.addEventListener('touchend', e => {
    const dx = e.changedTouches[0].clientX - startX;
    if (Math.abs(dx) > 40) { dx < 0 ? next() : prev(); startAutoplay(); }
  }, {passive:true});

  render(0); startAutoplay();
})();
</script>
<?php endif; ?>

<script>
(function(){
  const BASE_PATH = <?= json_encode(BASE_URL) ?>;
  const ALL_INDUSTRIES = <?= json_encode(array_map(fn($i) => ['id' => $i['id'], 'name' => $i['name']], $searchCardIndustries)) ?>;

  function escLbl(s){ return String(s).replace(/</g,'&lt;'); }

  window.asSwitchMode = function(mode){
    const brandGroup    = document.getElementById('as-group-brand');
    const industryGroup = document.getElementById('as-group-industry');
    if (!brandGroup || !industryGroup) return;
    const showBrand = mode === 'brand';

    // Synchronize radio input checked states
    const brandRadio = document.querySelector('input[name="search-mode"][value="brand"]');
    const indRadio   = document.querySelector('input[name="search-mode"][value="industry"]');
    if (brandRadio) brandRadio.checked = showBrand;
    if (indRadio)   indRadio.checked   = !showBrand;

    brandGroup.style.display    = showBrand ? 'block' : 'none';
    industryGroup.style.display = showBrand ? 'none'  : 'block';

    // Disable every field in the hidden group and enable the visible group
    brandGroup.querySelectorAll('select').forEach(el => el.disabled = !showBrand);
    industryGroup.querySelectorAll('select').forEach(el => el.disabled = showBrand);

    if (showBrand) {
      const indVal = document.getElementById('asb-industry')?.value;
      const catSel = document.getElementById('asb-category');
      if (catSel) catSel.disabled = !indVal;
    } else {
      const indVal  = document.getElementById('asi-industry')?.value;
      const catVal  = document.getElementById('asi-category')?.value;
      const catSel  = document.getElementById('asi-category');
      const typeSel = document.getElementById('asi-type');
      if (catSel) catSel.disabled = !indVal;
      if (typeSel) typeSel.disabled = !catVal;
    }
  };

  /* ── Brand refresh (shared by both modes) ───────────────────────── */
  function asRefreshBrands(prefix, filters){
    const brandSel = document.getElementById(prefix + '-brand');
    if (!brandSel) return;
    const params = new URLSearchParams();
    if (filters.industryId) params.set('industry_id', filters.industryId);
    if (filters.categoryId) params.set('category_id', filters.categoryId);
    if (filters.typeId)     params.set('type_id', filters.typeId);
    if (filters.country)    params.set('country', filters.country);

    const keepValue = brandSel.value;
    brandSel.innerHTML = '<option value="">Loading…</option>';
    fetch(BASE_PATH + '/public/ajax/get-brands-filtered.php?' + params.toString())
      .then(r => r.json())
      .then(list => {
        brandSel.innerHTML = '<option value="">All Brands</option>' +
          list.map(b => `<option value="${b.id}">${escLbl(b.label)}</option>`).join('');
        if (keepValue && list.some(b => String(b.id) === keepValue)) brandSel.value = keepValue;
      })
      .catch(() => { brandSel.innerHTML = '<option value="">All Brands</option>'; });
  }

  /* ── INDUSTRY MODE: Industry → Category → Type → Country → Brand ── */
  window.asOnIndustryChange = function(){
    const indEl = document.getElementById('asi-industry');
    const catSel  = document.getElementById('asi-category');
    const typeSel = document.getElementById('asi-type');
    if (!indEl || !catSel || !typeSel) return;
    const industryId = indEl.value;

    catSel.innerHTML = '<option value="">Loading…</option>'; catSel.disabled = true;
    typeSel.innerHTML = '<option value="">Select Category first…</option>'; typeSel.disabled = true;

    if (!industryId) {
      catSel.innerHTML = '<option value="">Select Industry first…</option>';
    } else {
      const keepCat = catSel.getAttribute('data-keep') || '';
      fetch(BASE_PATH + '/public/ajax/get-categories-with-products.php?industry_id=' + industryId)
        .then(r => r.json())
        .then(list => {
          if (!list.length) { catSel.innerHTML = '<option value="">No categories available</option>'; return; }
          catSel.innerHTML = '<option value="">All Categories</option>' +
            list.map(c => `<option value="${c.id}">${escLbl(c.name)}</option>`).join('');
          catSel.disabled = false;
          if (keepCat && list.some(c => String(c.id) === keepCat)) {
            catSel.value = keepCat;
            catSel.removeAttribute('data-keep');
            asOnCategoryChange();
          }
        })
        .catch(() => { catSel.innerHTML = '<option value="">Could not load categories</option>'; });
    }

    asRefreshBrands('asi', {
      industryId, country: document.getElementById('asi-country')?.value || ''
    });
  };

  window.asOnCategoryChange = function(){
    const catEl = document.getElementById('asi-category');
    const typeSel = document.getElementById('asi-type');
    if (!catEl || !typeSel) return;
    const categoryId = catEl.value;
    typeSel.innerHTML = '<option value="">Loading…</option>'; typeSel.disabled = true;

    if (!categoryId) {
      typeSel.innerHTML = '<option value="">Select Category first…</option>';
    } else {
      const keepType = typeSel.getAttribute('data-keep') || '';
      fetch(BASE_PATH + '/public/ajax/get-product-types-with-products.php?category_id=' + categoryId)
        .then(r => r.json())
        .then(list => {
          if (!list.length) { typeSel.innerHTML = '<option value="">No product types available</option>'; return; }
          typeSel.innerHTML = '<option value="">All Product Types</option>' +
            list.map(t => `<option value="${t.id}">${escLbl(t.name)}</option>`).join('');
          typeSel.disabled = false;
          if (keepType && list.some(t => String(t.id) === keepType)) {
            typeSel.value = keepType;
            typeSel.removeAttribute('data-keep');
          }
        })
        .catch(() => { typeSel.innerHTML = '<option value="">Could not load product types</option>'; });
    }

    asRefreshBrands('asi', {
      industryId: document.getElementById('asi-industry')?.value || '',
      categoryId,
      country: document.getElementById('asi-country')?.value || ''
    });
  };

  window.asOnIndustryModeCountryChange = function(){
    asRefreshBrands('asi', {
      industryId: document.getElementById('asi-industry')?.value || '',
      categoryId: document.getElementById('asi-category')?.value || '',
      typeId: document.getElementById('asi-type')?.value || '',
      country: document.getElementById('asi-country')?.value || ''
    });
  };

  /* ── BRAND MODE: Country → Brand → Industry → Category ───────────── */
  window.asOnBrandModeCountryChange = function(){
    asRefreshBrands('asb', { country: document.getElementById('asb-country')?.value || '' });
  };

  window.asOnBrandModeBrandChange = function(){
    const brandEl = document.getElementById('asb-brand');
    const indSel = document.getElementById('asb-industry');
    const catSel = document.getElementById('asb-category');
    if (!brandEl || !indSel || !catSel) return;
    const vendorId = brandEl.value;
    catSel.innerHTML = '<option value="">All Categories</option>'; catSel.disabled = true;

    if (!vendorId) {
      indSel.innerHTML = '<option value="">All Industries</option>' +
        ALL_INDUSTRIES.map(i => `<option value="${i.id}">${escLbl(i.name)}</option>`).join('');
      indSel.value = '';
      return;
    }
    const keepInd = indSel.getAttribute('data-keep') || indSel.value;
    indSel.innerHTML = '<option value="">Loading…</option>';
    fetch(BASE_PATH + '/public/ajax/get-industries-by-vendor.php?vendor_id=' + vendorId)
      .then(r => r.json())
      .then(list => {
        indSel.innerHTML = '<option value="">All Industries</option>' +
          list.map(i => `<option value="${i.id}">${escLbl(i.name)}</option>`).join('');
        if (keepInd && list.some(i => String(i.id) === keepInd)) {
          indSel.value = keepInd;
          indSel.removeAttribute('data-keep');
          asOnBrandModeIndustryChange();
        }
      })
      .catch(() => { indSel.innerHTML = '<option value="">Could not load industries</option>'; });
  };

  window.asOnBrandModeIndustryChange = function(){
    const indEl = document.getElementById('asb-industry');
    const brandEl = document.getElementById('asb-brand');
    const catSel = document.getElementById('asb-category');
    if (!indEl || !catSel) return;
    const industryId = indEl.value;
    const vendorId   = brandEl ? brandEl.value : '';
    catSel.innerHTML = '<option value="">Loading…</option>'; catSel.disabled = true;

    if (!industryId) {
      catSel.innerHTML = '<option value="">All Categories</option>';
      return;
    }
    let url = BASE_PATH + '/public/ajax/get-categories-with-products.php?industry_id=' + industryId;
    if (vendorId) url += '&vendor_id=' + vendorId;
    fetch(url).then(r => r.json())
      .then(list => {
        catSel.innerHTML = '<option value="">All Categories</option>' +
          list.map(c => `<option value="${c.id}">${escLbl(c.name)}</option>`).join('');
        catSel.disabled = false;
      })
      .catch(() => { catSel.innerHTML = '<option value="">Could not load categories</option>'; });
  };

  function asInit(){
    const checkedRadio = document.querySelector('input[name="search-mode"]:checked');
    const mode = checkedRadio ? checkedRadio.value : 'industry';
    asSwitchMode(mode);

    // Rehydrate dependent dropdowns if browser preserved form values on back button
    if (mode === 'brand') {
      const brandVal = document.getElementById('asb-brand')?.value;
      const indVal   = document.getElementById('asb-industry')?.value;
      if (brandVal) {
        document.getElementById('asb-industry')?.setAttribute('data-keep', indVal);
        asOnBrandModeBrandChange();
      } else if (indVal) {
        asOnBrandModeIndustryChange();
      }
    } else {
      const indVal = document.getElementById('asi-industry')?.value;
      const catVal = document.getElementById('asi-category')?.value;
      if (indVal) {
        document.getElementById('asi-category')?.setAttribute('data-keep', catVal);
        asOnIndustryChange();
      }
    }
  }

  // Ensure mode matches radio on load and on back navigation / pageshow
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', asInit);
  } else {
    asInit();
  }
  window.addEventListener('pageshow', asInit);
})();
</script>

<!-- GRADE QUICK FINDER BAR -->
<section class="grade-finder-section">
  <div class="container">
    <div class="grade-finder-wrap">
      <div class="grade-finder-label">
        <span>⚡ Quick Finder:</span>
      </div>
      <a href="<?= BASE_URL ?>/public/products.php?q=Kraft+Paper" class="grade-chip"><span>📜</span> Kraft Paper (14–40 BF)</a>
      <a href="<?= BASE_URL ?>/public/products.php?q=Duplex+Board" class="grade-chip"><span>🗂️</span> Duplex Board (LWC/HWC)</a>
      <a href="<?= BASE_URL ?>/public/products.php?q=Corrugated" class="grade-chip"><span>📦</span> Corrugated Boxes & Sheets</a>
      <a href="<?= BASE_URL ?>/public/products.php?q=FBB" class="grade-chip"><span>🎁</span> Folding Box Board (FBB)</a>
      <a href="<?= BASE_URL ?>/public/products.php?q=SBS" class="grade-chip"><span>📄</span> Solid Bleached Sulfate (SBS)</a>
      <a href="<?= BASE_URL ?>/public/products.php?q=Fluting" class="grade-chip"><span>🧵</span> Fluting Medium</a>
      <a href="<?= BASE_URL ?>/public/products.php?q=Specialty" class="grade-chip"><span>✨</span> Specialty & Tissue</a>
    </div>
  </div>
</section>

<!-- VALUE PROPOSITIONS moved below — it now appears after Featured + Mills for better flow -->

<!-- BROWSE CATEGORIES CAROUSEL -->
<section class="cat-carousel-section">
  <div class="container">
    <div class="cat-carousel-header">
      <div>
        <div class="section-badge">Most In-Demand</div>
        <h2 class="cat-carousel-title">Popular Product Categories</h2>
        <p class="cat-carousel-sub">Ranked by live catalogue size — the categories buyers search for most across industrial paper &amp; packaging</p>
      </div>
      <div class="cat-carousel-nav-btns">
        <button class="cat-nav-btn" id="cat-prev" aria-label="Previous categories">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
        </button>
        <button class="cat-nav-btn" id="cat-next" aria-label="Next categories">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </button>
      </div>
    </div>

    <div class="cat-carousel-wrapper">
      <div class="cat-carousel-track" id="cat-track">
        <?php
        $catGradients = [
          ['#8B241D','#C0392B'],
          ['#1a3a5c','#2962a8'],
          ['#15532e','#1a7a45'],
          ['#5b21b6','#7c3aed'],
          ['#92400e','#d97706'],
          ['#0e4d6c','#0891b2'],
          ['#6b21a8','#a855f7'],
          ['#3f3f46','#71717a'],
          ['#1e3a5f','#2563eb'],
          ['#7f1d1d','#dc2626'],
          ['#064e3b','#059669'],
          ['#1e1b4b','#4338ca'],
        ];
        foreach($categories as $idx=>$cat):
          $icon=$catIcons[$cat['name']] ?? '📦';
          $g = $catGradients[$idx % count($catGradients)];
        ?>
        <a href="<?= BASE_URL ?>/public/products.php?category=<?= $cat['id'] ?>" class="cat-card" style="--gc1:<?= $g[0] ?>;--gc2:<?= $g[1] ?>">
          <div class="cat-card-bg"></div>
          <div class="cat-card-shine"></div>
          <div class="cat-card-content">
            <div class="cat-card-icon-wrap">
              <span class="cat-card-icon"><?= $icon ?></span>
            </div>
            <div class="cat-card-info">
              <div class="cat-card-name"><?= sH($cat['name']) ?></div>
              <div class="cat-card-industry"><?= sH($cat['iname']) ?></div>
            </div>
            <div class="cat-card-count">
              <div class="cat-card-count-left">
                <span class="cat-card-count-num"><?= $cat['prod_count'] ?></span>
                <span class="cat-card-count-lbl">Products</span>
              </div>
              <div class="cat-card-arrow">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
              </div>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
        <a href="<?= BASE_URL ?>/public/products.php" class="cat-card" style="--gc1:#8B241D;--gc2:#A8302A">
          <div class="cat-card-bg"></div>
          <div class="cat-card-shine"></div>
          <div class="cat-card-content">
            <div style="margin-bottom:18px;font-family:'Poppins',sans-serif;font-weight:700;font-size:18px;line-height:1.3;padding:10px 0">
              Browse All Categories &amp; Mills →
            </div>
            <div class="cat-card-count">
              <span class="cat-card-count-lbl">View All Catalogue</span>
              <div class="cat-card-arrow">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
              </div>
            </div>
          </div>
        </a>
      </div>
    </div>
    <div class="cat-carousel-dots" id="cat-dots"></div>
  </div>
</section>

<script>
(function(){
  const track   = document.getElementById('cat-track');
  const prevBtn = document.getElementById('cat-prev');
  const nextBtn = document.getElementById('cat-next');
  const dotsEl  = document.getElementById('cat-dots');
  const wrapper = track ? track.parentElement : null;

  if(!track || !prevBtn || !nextBtn || !wrapper) return;

  const cards = track.querySelectorAll('.cat-card');
  const gap   = 18;
  const MOBILE_BP = 1024;
  const isDesktop = () => window.innerWidth > MOBILE_BP;
  let current = 0;

  function cardWidth(){
    const first = cards[0];
    return first ? first.getBoundingClientRect().width + gap : 230 + gap;
  }
  function visible(){ return Math.max(1, Math.floor(wrapper.offsetWidth / cardWidth())); }
  function maxIndex(){ return Math.max(0, cards.length - visible()); }

  function buildDots(){
    dotsEl.innerHTML = '';
    const pages = maxIndex() + 1;
    for(let i = 0; i < pages; i++){
      const d = document.createElement('button');
      d.className = 'cat-dot' + (i === 0 ? ' active' : '');
      d.setAttribute('aria-label', 'Page ' + (i+1));
      d.addEventListener('click', () => goTo(i));
      dotsEl.appendChild(d);
    }
  }
  function updateDots(){
    dotsEl.querySelectorAll('.cat-dot').forEach((d,i) => d.classList.toggle('active', i === current));
  }

  function goTo(idx){
    if (!isDesktop()) return;
    current = Math.max(0, Math.min(idx, maxIndex()));
    track.style.transform = 'translateX(-' + (current * cardWidth()) + 'px)';
    prevBtn.disabled = current === 0;
    nextBtn.disabled = current >= maxIndex();
    updateDots();
  }

  function enterDesktopMode(){
    track.style.transform = 'translateX(0px)';
    buildDots();
    goTo(0);
  }
  function enterMobileMode(){
    track.style.transform = 'none';
    dotsEl.innerHTML = '';
  }

  prevBtn.addEventListener('click', () => goTo(current - 1));
  nextBtn.addEventListener('click', () => goTo(current + 1));

  let wasDesktop = isDesktop();
  if (wasDesktop) enterDesktopMode(); else enterMobileMode();

  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      const nowDesktop = isDesktop();
      if (nowDesktop !== wasDesktop) {
        wasDesktop = nowDesktop;
        nowDesktop ? enterDesktopMode() : enterMobileMode();
      } else if (nowDesktop) {
        goTo(Math.min(current, maxIndex()));
      }
    }, 120);
  });
})();
</script>

<!-- FEATURED PRODUCTS — immediate value: products at top -->
<?php if ($featured): ?>
<section style="background:var(--n50);padding:64px 0">
  <div class="container">
    <div class="section-head" style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px">
      <div>
        <div class="section-badge">Verified Quality</div>
        <h2>Featured Paper &amp; Board Products</h2>
        <p style="color:var(--n500);margin:0">Handpicked industrial paper grades from top-rated, certified manufacturers</p>
      </div>
      <a href="<?= BASE_URL ?>/public/products.php" class="btn btn-outline btn-sm">Browse All Products →</a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:20px">
      <?php foreach($featured as $p):
        $imgs=array_filter(explode(',',$p['images']??''));
        $img=reset($imgs)?UPLOAD_URL.trim(reset($imgs)):'';
      ?>
      <div class="card">
        <div class="card-img">
          <?php if($img): ?><img src="<?= sH($img) ?>" alt="<?= sH($p['name']) ?>" loading="lazy"><?php else: ?><span class="card-img-ph">📦</span><?php endif; ?>
          <?php if($p['is_featured']): ?><div class="card-badge-pos"><span class="badge badge-amber">⭐ Top Rated</span></div><?php endif; ?>
          <div class="card-compare-pos">
            <button class="btn btn-compare" onclick="addToCompare(<?= $p['id'] ?>,'<?= sH($p['name']) ?>','<?= sH($p['images']??'') ?>')" data-id="<?= $p['id'] ?>" title="Add to compare">⚖️ Compare</button>
          </div>
        </div>
        <div class="card-body">
          <div class="card-cat"><?= sH($p['cname']) ?></div>
          <div class="card-title"><a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>"><?= sH($p['name']) ?></a></div>
          <?php if($p['price_range']): ?><div class="card-price">₹ <?= sH($p['price_range']) ?></div><?php endif; ?>
          <div class="card-vendor">
            <div class="vav"><?= strtoupper(substr($p['vname'],0,1)) ?></div>
            <div>
              <div class="v-name"><?= sH($p['company']?:$p['vname']) ?></div>
            </div>
            <?php if($p['is_verified']): ?><div class="v-verified">✓ Verified</div><?php endif; ?>
          </div>
        </div>
        <div class="card-footer">
          <a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm" style="flex:1">View Specs</a>
          <button class="btn btn-accent btn-sm" onclick="openEnquiryModal(<?= $p['id'] ?>,<?= $p['vendor_id'] ?>,'<?= sH($p['name']) ?>')">📩 Enquire</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- MOST POPULAR BRANDS / MILL SPOTLIGHT — trust building after products -->
<?php if ($popularVendors): ?>
<section class="brands-section">
  <div class="container">
    <div class="section-head" style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px">
      <div>
        <div class="section-badge">Verified Partners</div>
        <h2>Leading Mills &amp; Manufacturers</h2>
        <p style="color:var(--n500);margin:0">Direct access to verified paper manufacturers with proven production capabilities and quality compliance</p>
      </div>
      <a href="<?= BASE_URL ?>/public/vendors.php" class="btn btn-outline btn-sm">View All Manufacturers →</a>
    </div>
    <div class="mill-spotlight-grid">
      <?php foreach ($popularVendors as $v):
        $logoUrl = !empty($v['logo']) ? UPLOAD_URL . $v['logo'] : '';
        $displayName = $v['company'] ?: $v['name'];
      ?>
      <div class="mill-card">
        <div class="mill-card-head">
          <div class="mill-card-logo">
            <?php if ($logoUrl): ?>
              <img src="<?= sH($logoUrl) ?>" alt="<?= sH($displayName) ?>" loading="lazy">
            <?php else: ?>
              <span><?= strtoupper(substr($displayName, 0, 1)) ?></span>
            <?php endif; ?>
          </div>
          <div>
            <div class="mill-card-name"><?= sH($displayName) ?></div>
            <?php if ($v['city'] || $v['country']): ?>
              <div class="mill-card-location">📍 <?= sH(trim(($v['city'] ?: '') . (($v['city'] && $v['country']) ? ', ' : '') . ($v['country'] ?: ''))) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="mill-tags">
          <?php if ($v['is_verified']): ?><span class="mill-tag" style="background:var(--green-lt);color:var(--green);font-weight:700">✓ Verified Mill</span><?php endif; ?>
          <span class="mill-tag">ISO/FSC Compliant</span>
          <span class="mill-tag">Direct Dispatch</span>
        </div>
        <div class="mill-card-stats">
          <div><div class="mill-stat-n"><?= (int)$v['prod_count'] ?></div><div class="mill-stat-l">Live Products</div></div>
          <div><div class="mill-stat-n"><?= $v['total_reviews'] > 0 ? '★ ' . number_format((float)$v['rating'], 1) : 'Top' ?></div><div class="mill-stat-l">Mill Rating</div></div>
          <div><div class="mill-stat-n">100%</div><div class="mill-stat-l">Direct Sourcing</div></div>
        </div>
        <a href="<?= BASE_URL ?>/public/vendor-profile.php?id=<?= $v['id'] ?>" class="btn btn-outline btn-sm btn-full" style="margin-top:6px">View Mill Catalogue →</a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- VALUE PROPOSITIONS (WHY PAPERKART) — moved here for better flow after trust -->
<section class="value-props-section">
  <div class="container">
    <div class="section-head center">
      <div class="section-badge">Direct From Manufacturers</div>
      <h2>Why India's Top Converters &amp; Buyers Choose paperKart</h2>
      <p style="max-width:680px;margin:0 auto">Eliminate middlemen margins, verify mill technical data sheets, and procure industrial paper with complete transparency.</p>
    </div>
    <div class="vp-grid">
      <div class="vp-card">
        <div class="vp-icon">🏭</div>
        <div class="vp-title">Direct Mill Pricing</div>
        <div class="vp-desc">Connect directly with verified paper mills and corrugators. Zero brokerage and 100% transparent factory rates.</div>
      </div>
      <div class="vp-card">
        <div class="vp-icon">📄</div>
        <div class="vp-title">Lab-Verified TDS</div>
        <div class="vp-desc">Download authentic Technical Data Sheets (GSM, BF, Cobb, RCT, Tear Factor) verified directly by mill quality labs.</div>
      </div>
      <div class="vp-card">
        <div class="vp-icon">⚖️</div>
        <div class="vp-title">Multi-Spec Comparison</div>
        <div class="vp-desc">Compare up to 4 paper grades side-by-side on burst factor, GSM tolerances, moisture %, and price in real-time.</div>
      </div>
      <div class="vp-card">
        <div class="vp-icon">🚚</div>
        <div class="vp-title">Pan-India Bulk RFQs</div>
        <div class="vp-desc">Send single or consolidated RFQs for truckload / container orders and receive competing quotes within 2 hours.</div>
      </div>
    </div>
  </div>
</section>

<!-- SOURCING PROCESS (HOW IT WORKS) -->
<section style="background:#fff;padding:64px 0">
  <div class="container">
    <div class="section-head center">
      <div class="section-badge">Streamlined Procurement</div>
      <h2>How paperKart Sourcing Works</h2>
      <p style="max-width:620px;margin:0 auto">A transparent, reliable 4-step workflow designed for procurement managers, corrugators, and converters.</p>
    </div>
    <div class="process-timeline">
      <div class="process-step">
        <div class="process-num">1</div>
        <div class="process-icon">🔍</div>
        <div class="process-title">Discover &amp; Filter</div>
        <div class="process-desc">Search thousands of paper reels and sheets by GSM, Burst Factor, Cobb value, coating, and manufacturer location.</div>
      </div>
      <div class="process-step">
        <div class="process-num">2</div>
        <div class="process-icon">⚖️</div>
        <div class="process-title">Side-by-Side Compare</div>
        <div class="process-desc">Compare up to 4 grades simultaneously across mechanical strength, optical brightness, and commercial MOQ terms.</div>
      </div>
      <div class="process-step">
        <div class="process-num">3</div>
        <div class="process-icon">📄</div>
        <div class="process-title">Verify TDS &amp; Samples</div>
        <div class="process-desc">Download mill lab test reports (TDS) and request free physical sample swatches before placing production orders.</div>
      </div>
      <div class="process-step">
        <div class="process-num">4</div>
        <div class="process-icon">🤝</div>
        <div class="process-title">Direct Factory Deal</div>
        <div class="process-desc">Connect directly with the mill sales team, negotiate bulk truckload rates, and arrange mill-gate dispatch.</div>
      </div>
    </div>
  </div>
</section>

<!-- PLATFORM METRICS / BY THE NUMBERS -->
<section class="impact-section">
  <div class="impact-orb impact-orb-1"></div>
  <div class="impact-orb impact-orb-2"></div>
  <div class="container">
    <div class="section-head center impact-head">
      <div class="impact-badge">
        <span class="impact-live-dot"></span>
        Live Enterprise Sourcing Network
      </div>
      <h2>Direct Mill Procurement at Industrial Scale</h2>
      <p>Connecting packaging converters, corrugators, and commercial printers with verified paper mills across India with guaranteed specs and mill-gate rates.</p>
    </div>

    <div class="impact-grid">
      <?php
      $impactStats = [
        [
          'num'   => number_format($totalCustomersHandled) . '+',
          'label' => 'Total Customers Handled',
          'sub'   => 'Verified converters, box makers &amp; packaging buyers',
          'pill'  => 'B2B Buyers',
          'svg'   => '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#F0C060" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        ],
        [
          'num'   => number_format($totalVendorsRegistered) . '+',
          'label' => 'Vendors &amp; Mills Registered',
          'sub'   => 'Partnered paper mills &amp; verified packaging manufacturers',
          'pill'  => 'Direct Mill-Gate',
          'svg'   => '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#F0C060" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8l-7 4V8l-7 4V4H2z"/></svg>',
        ],
        [
          'num'   => number_format($totalLeadsGiven) . '+',
          'label' => 'Total Leads Given',
          'sub'   => 'Commercial RFQs, enquiries &amp; buyer requirements routed',
          'pill'  => 'Dispatched Leads',
          'svg'   => '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#F0C060" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
        ],
        [
          'num'   => number_format($totalActiveProds) . '+',
          'label' => 'Catalogued Paper Grades',
          'sub'   => 'Kraft paper, duplex board, folding boxboard &amp; liner reels',
          'pill'  => 'Live Catalogue',
          'svg'   => '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#F0C060" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
        ],
        [
          'num'   => number_format($totalCatsLive) . '+',
          'label' => 'Industrial Categories',
          'sub'   => 'Covering corrugation, flexible packaging &amp; commercial print',
          'pill'  => 'Market Segments',
          'svg'   => '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#F0C060" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
        ],
        [
          'num'   => number_format($totalLiveSpecs) . '+',
          'label' => 'Technical Specs Indexed',
          'sub'   => 'Lab tested GSM, burst factor, caliper &amp; tear strength data',
          'pill'  => 'Spec Intelligence',
          'svg'   => '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#F0C060" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
        ],
      ];
      foreach ($impactStats as $st):
      ?>
      <div class="impact-card">
        <div class="impact-card-top">
          <div class="impact-icon-badge">
            <?= $st['svg'] ?>
          </div>
          <span class="impact-card-pill"><?= $st['pill'] ?></span>
        </div>
        <div class="impact-num"><?= $st['num'] ?></div>
        <div class="impact-label"><?= $st['label'] ?></div>
        <div class="impact-subtext"><?= $st['sub'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Quality Assurance Trust Strip -->
    <div class="impact-trust-strip">
      <div class="impact-trust-item">
        <span class="trust-check">✓</span> Pre-Dispatch Lab GSM &amp; BF Testing
      </div>
      <div class="impact-trust-sep">•</div>
      <div class="impact-trust-item">
        <span class="trust-check">✓</span> 100% Direct Factory-Gate Invoicing
      </div>
      <div class="impact-trust-sep">•</div>
      <div class="impact-trust-item">
        <span class="trust-check">✓</span> Custom Deckle &amp; Sheet Slitting
      </div>
      <div class="impact-trust-sep">•</div>
      <div class="impact-trust-item">
        <span class="trust-check">✓</span> Dedicated Technical Sourcing Manager
      </div>
    </div>
  </div>
</section>

<!-- LIVE SIDE-BY-SIDE COMPARE SHOWCASE -->
<?php if ($compareProducts): ?>
<section class="compare-showcase">
  <div class="cmp-ambient-glow"></div>
  <div class="cmp-bg-pattern"></div>
  <div class="container">

    <!-- Section Header -->
    <div class="cmp-section-header">
      <div class="cmp-header-left">
        <div class="section-badge cmp-badge-inline">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          Spec Intelligence Matrix
        </div>
        <h2>Compare Paper Grades<br><span class="cmp-head-accent">Side-by-Side</span></h2>
        <p>Evaluating options in the <strong><?= sH($compareCat['name']) ?></strong> category? View how <?= count($compareProducts) ?> leading grades compare on mechanical, physical and printability parameters — sourced directly from certified mill TDS sheets.</p>

        <!-- Feature bullets -->
        <ul class="cmp-feature-list">
          <li>
            <span class="cmp-feat-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
            </span>
            Certified TDS & lab-tested burst factor values
          </li>
          <li>
            <span class="cmp-feat-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
            </span>
            GSM, shade, caliper & moisture spec comparison
          </li>
          <li>
            <span class="cmp-feat-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </span>
            Direct mill-verified data, zero broker markups
          </li>
          <li>
            <span class="cmp-feat-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </span>
            <?= number_format($totalLiveSpecs) ?>+ technical attributes indexed across all grades
          </li>
        </ul>

        <div class="cmp-header-actions">
          <button type="button" class="btn cmp-cta-btn" onclick="homeCompareAll([<?= implode(',', array_column($compareProducts, 'id')) ?>])">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
            Full Spec Comparison Matrix
            <span class="cta-arrow">→</span>
          </button>
          <a href="<?= BASE_URL ?>/public/products.php?category=<?= urlencode($compareCat['name']) ?>" class="cmp-browse-link">
            Browse all <?= sH($compareCat['name']) ?> grades →
          </a>
        </div>
      </div>

      <!-- Spec Table Panel -->
      <div class="cmp-table-panel">
        <div class="cmp-table-label">
          <span class="cmp-live-dot"></span>
          Live spec data — updated from mill TDS
        </div>
        <div class="cmp-table-wrap">
          <table class="cmp-table">
            <thead>
              <tr>
                <th class="cmp-th-label">
                  <span class="cmp-th-badge">PARAMETER</span>
                  <span class="cmp-th-title">Spec / Property</span>
                </th>
                <?php foreach ($compareProducts as $ci => $p):
                  $imgs = array_filter(explode(',', $p['images'] ?? ''));
                  $img  = reset($imgs) ? UPLOAD_URL . trim(reset($imgs)) : '';
                  $colClass = $ci === 0 ? 'cmp-col-highlight' : '';
                ?>
                <th class="<?= $colClass ?>">
                  <div class="cmp-prod-card">
                    
                    <div class="cmp-prod-name"><a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>"><?= sH($p['name']) ?></a></div>
                    <div class="cmp-prod-vendor">
                      <span class="vendor-dot"></span>
                      <?= sH($p['company'] ?: $p['vname']) ?>
                    </div>
                    <?php if (!empty($p['city'])): ?>
                    <div class="cmp-prod-location">
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                      <?= sH($p['city']) ?><?= !empty($p['state']) ? ', ' . sH($p['state']) : '' ?>
                    </div>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>" class="cmp-view-btn">View Spec Sheet →</a>
                  </div>
                </th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($compareAttrRows as $attrName => $byProduct):
                // Determine if all values are identical (highlight as common spec)
                $vals = array_values($byProduct);
                $allSame = count(array_unique($vals)) === 1;
              ?>
              <tr class="<?= $allSame ? 'cmp-row-same' : '' ?>">
                <td class="cmp-attr-name">
                  <span class="attr-bullet"></span>
                  <?= sH($attrName) ?>
                  <?php if ($allSame): ?><span class="cmp-match-badge" title="All products share this spec">✓ Match</span><?php endif; ?>
                </td>
                <?php foreach ($compareProducts as $ci => $p): ?>
                  <td class="cmp-attr-val <?= $ci === 0 ? 'cmp-val-highlight' : '' ?>">
                    <?= isset($byProduct[$p['id']]) ? sH($byProduct[$p['id']]) : '<span class="cmp-dash">—</span>' ?>
                  </td>
                <?php endforeach; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="cmp-table-footer">
          <span>Data sourced directly from certified mill TDS sheets</span>
          <button type="button" class="cmp-expand-btn" onclick="homeCompareAll([<?= implode(',', array_column($compareProducts, 'id')) ?>])">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
            Expand Full Matrix
          </button>
        </div>
      </div>
    </div>

  </div>
</section>
<script>
function homeCompareAll(ids) {
  const BASE_PATH = <?= json_encode(BASE_URL) ?>;
  Promise.all(ids.map(id =>
    fetch(BASE_PATH + '/public/ajax/compare.php', {
      method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'action=add&product_id=' + id
    })
  )).finally(() => { window.location.href = BASE_PATH + '/public/compare.php'; });
}
</script>
<?php endif; ?>

<!-- BULK PROCUREMENT / INSTANT RFQ BANNER -->
<section style="padding:70px 0;background:linear-gradient(180deg,#ffffff 0%,var(--n50) 100%)">
  <div class="container">
    <div class="rfq-banner">
      <div class="rfq-grid">
        <div>
          <div class="rfq-badges">
            <span class="rfq-badge">⚡ Instant Mill Dispatch</span>
            <span class="rfq-badge">📦 5+ Metric Tonnes</span>
            <span class="rfq-badge">💼 Dedicated Key Account Manager</span>
          </div>
          <h2>Need Bulk Paper or Custom Reel Sizes?</h2>
          <p>Submit your exact GSM, deckle size, burst factor, and monthly consumption. Our network of 100+ partner mills will send competing formal quotes directly to your inbox.</p>
          <div style="display:flex;gap:12px;flex-wrap:wrap">
            <a href="<?= BASE_URL ?>/public/enquiry.php" class="btn btn-accent btn-lg">Submit Custom RFQ Now</a>
            <a href="tel:+919876543210" class="btn btn-lg" style="background:rgba(255,255,255,.1);color:#fff;border:1.5px solid rgba(255,255,255,.3)">📞 Speak with Sourcing Desk</a>
          </div>
        </div>
        <div class="rfq-form-card">
          <h3>⚡ Rapid Sourcing Assistance</h3>
          <p style="font-size:12.5px;color:var(--n500);margin-bottom:14px">Get custom quotes directly from top manufacturers in 2 hours.</p>
          <form action="<?= BASE_URL ?>/public/enquiry.php" method="GET">
            <div class="form-group" style="margin-bottom:10px">
              <input type="text" name="q" class="form-input" placeholder="Required grade (e.g. 180 GSM Kraft, 28 BF)" style="font-size:12.5px;padding:8px 12px">
            </div>
            <div class="form-group" style="margin-bottom:12px">
              <input type="text" name="qty" class="form-input" placeholder="Estimated Quantity (e.g. 15 Tonnes/Month)" style="font-size:12.5px;padding:8px 12px">
            </div>
            <button type="submit" class="btn btn-primary btn-full">Request Mill Quotations →</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ENTERPRISE BUYER TESTIMONIALS -->
<section class="testimonials-section">
  <div class="container">
    <div class="section-head center">
      <div class="section-badge">Buyer Trust</div>
      <h2>Trusted by Top Packaging Plants &amp; Corrugators</h2>
      <p style="max-width:620px;margin:0 auto">See how packaging converters and FMCG brand procurement teams scale their supply chain with paperKart.</p>
    </div>
    <div class="testimonials-grid">
      <div class="testi-card">
        <div>
          <div class="testi-stars">★★★★★</div>
          <div class="testi-text">"Sourcing 300 MT of Kraft Paper monthly used to require juggling 5 brokers. On paperKart, we get direct mill-gate rates, verified TDS lab sheets, and saved 4.8% on raw material costs in Q1."</div>
        </div>
        <div class="testi-user">
          <div class="testi-avatar">R</div>
          <div>
            <div class="testi-name">Rajesh Sharma</div>
            <div class="testi-role">Director of Sourcing, Apex Corrugation Works, Gujarat</div>
          </div>
        </div>
      </div>
      <div class="testi-card">
        <div>
          <div class="testi-stars">★★★★★</div>
          <div class="testi-text">"The side-by-side comparison tool is revolutionary. We compared Cobb values and Burst Factor across 3 duplex board manufacturers in 30 seconds and confirmed sample delivery right from the dashboard."</div>
        </div>
        <div class="testi-user">
          <div class="testi-avatar">A</div>
          <div>
            <div class="testi-name">Ananya Deshmukh</div>
            <div class="testi-role">Head of Procurement, SmartPack Solutions, Mumbai</div>
          </div>
        </div>
      </div>
      <div class="testi-card">
        <div>
          <div class="testi-stars">★★★★★</div>
          <div class="testi-text">"Reliable paper mills with transparent pricing. Having access to certified Technical Data Sheets before issuing POs gave our quality control team complete peace of mind."</div>
        </div>
        <div class="testi-user">
          <div class="testi-avatar">V</div>
          <div>
            <div class="testi-name">Vikramaditya Rao</div>
            <div class="testi-role">VP Supply Chain, Deccan Packaging Industries, Hyderabad</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- FREQUENTLY ASKED QUESTIONS (FAQ ACCORDION) -->
<section class="faq-section">
  <div class="container">
    <div class="section-head center">
      <div class="section-badge">Got Questions?</div>
      <h2>Frequently Asked Questions</h2>
      <p style="max-width:600px;margin:0 auto">Everything you need to know about purchasing paper grades, minimum orders, TDS, and mill verification.</p>
    </div>
    <div class="faq-grid">
      <div class="faq-item open">
        <button class="faq-question" onclick="toggleFaq(this)">
          <span>What is the minimum order quantity (MOQ) for paper products?</span>
          <span class="faq-arrow">▼</span>
        </button>
        <div class="faq-answer">
          MOQs are set directly by each paper manufacturer and typically range from 500 kg for specialty boards to 1 full truckload (10–18 MT) for standard Kraft Paper reels and fluting medium. You can view each product's specific MOQ on its details page or enquire directly.
        </div>
      </div>
      <div class="faq-item">
        <button class="faq-question" onclick="toggleFaq(this)">
          <span>Are the Technical Data Sheets (TDS) authentic and verified?</span>
          <span class="faq-arrow">▼</span>
        </button>
        <div class="faq-answer">
          Yes. All TDS reports uploaded on paperKart are issued by the quality testing laboratories of the respective paper mills. They document key parameters including GSM tolerance, Bursting Strength (BF), Cobb 60 absorption, moisture percentage, and tensile strength.
        </div>
      </div>
      <div class="faq-item">
        <button class="faq-question" onclick="toggleFaq(this)">
          <span>How does paperKart verify paper mills and manufacturers?</span>
          <span class="faq-arrow">▼</span>
        </button>
        <div class="faq-answer">
          Vendors with the <strong style="color:var(--green)">✓ Verified</strong> badge undergo strict document validation including GST registration, industrial manufacturing licenses, factory site details, and proof of production capacity before receiving verified status.
        </div>
      </div>
      <div class="faq-item">
        <button class="faq-question" onclick="toggleFaq(this)">
          <span>Can I request physical paper samples before placing a bulk order?</span>
          <span class="faq-arrow">▼</span>
        </button>
        <div class="faq-answer">
          Yes! When sending an enquiry, select sample requirement in your message. Verified vendors provide swatch cards and A4/sample reel kits so you can run test runs on your converting machinery.
        </div>
      </div>
      <div class="faq-item">
        <button class="faq-question" onclick="toggleFaq(this)">
          <span>Is there any fee or commission for buyers using paperKart?</span>
          <span class="faq-arrow">▼</span>
        </button>
        <div class="faq-answer">
          No. paperKart is 100% free for buyers. You can search products, compare specifications, download TDS reports, and connect directly with manufacturers without any platform fees or commissions.
        </div>
      </div>
    </div>
  </div>
</section>

<!-- CTA BANNER FOR VENDORS -->
<section class="cta-banner">
  <div class="cta-banner-overlay"></div>
  <div class="container cta-banner-content">
    <h2>Are You a Paper Manufacturer or Mill Owner?</h2>
    <p>List your product catalogue for free and connect with 10,000+ verified B2B buyers, corrugators, and packaging converters across India.</p>
    <div class="cta-banner-actions">
      <a href="<?= BASE_URL ?>/public/vendor-register.php" class="btn btn-accent btn-lg">Start Listing Your Products Free</a>
      <a href="<?= BASE_URL ?>/vendor/subscription.php" class="btn btn-lg" style="background:rgba(255,255,255,.1);color:#fff;border:1.5px solid rgba(255,255,255,.3)">Explore Vendor Plans</a>
    </div>
  </div>
</section>

<!-- ENQUIRY MODAL -->
<div class="modal-backdrop" id="enquiry-modal">
  <div class="modal">
    <div class="modal-header">
      <h3>📩 Send Direct Mill Enquiry</h3>
      <button class="modal-close" onclick="closeEnquiryModal()">✕</button>
    </div>
    <div class="modal-body">
      <div id="enq-success" class="site-alert site-alert-success" style="display:none"></div>
      <form id="enq-form" onsubmit="submitEnquiry(event)">
        <input type="hidden" id="enq-product-id" name="product_id">
        <input type="hidden" id="enq-vendor-id"  name="vendor_id">
        <div id="enq-product-name" style="background:var(--n50);border-radius:var(--r-sm);padding:10px 14px;margin-bottom:16px;font-weight:600;font-size:13.5px;color:var(--brand)"></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Your Name <span class="form-required">*</span></label><input type="text" name="name" class="form-input" required></div>
          <div class="form-group"><label class="form-label">Email <span class="form-required">*</span></label><input type="email" name="email" class="form-input" required></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Phone</label><input type="tel" name="phone" class="form-input"></div>
          <div class="form-group"><label class="form-label">Company</label><input type="text" name="company" class="form-input"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">City</label><input type="text" name="city" class="form-input"></div>
          <div class="form-group"><label class="form-label">Quantity Required</label><input type="text" name="qty_needed" class="form-input" placeholder="e.g. 500 kg, 10 MT"></div>
        </div>
        <div class="form-group"><label class="form-label">Message / Requirements</label><textarea name="message" class="form-input" rows="3" style="resize:vertical" placeholder="Describe your specifications, deckle size, GSM needed…"></textarea></div>
        <button type="submit" class="btn btn-accent btn-full btn-lg" id="enq-btn">Send Enquiry to Vendor</button>
        <p style="font-size:11.5px;color:var(--n500);margin-top:10px;text-align:center">Your contact details are only shared directly with the manufacturer.</p>
      </form>
    </div>
  </div>
</div>

<script>
function toggleFaq(btn){
  const item = btn.closest('.faq-item');
  const wasOpen = item.classList.contains('open');
  document.querySelectorAll('.faq-item.open').forEach(i => i.classList.remove('open'));
  if (!wasOpen) item.classList.add('open');
}

function openEnquiryModal(productId, vendorId, productName) {
  document.getElementById('enq-product-id').value = productId;
  document.getElementById('enq-vendor-id').value  = vendorId;
  document.getElementById('enq-product-name').textContent = '📦 ' + productName;
  document.getElementById('enq-success').style.display = 'none';
  document.getElementById('enq-form').style.display = 'block';
  document.getElementById('enq-btn').textContent = 'Send Enquiry to Vendor';
  document.getElementById('enquiry-modal').classList.add('open');
}
function closeEnquiryModal() { document.getElementById('enquiry-modal').classList.remove('open'); }
document.getElementById('enquiry-modal').addEventListener('click', function(e) { if(e.target===this) closeEnquiryModal(); });
function submitEnquiry(e) {
  e.preventDefault();
  const btn = document.getElementById('enq-btn');
  btn.textContent = 'Sending…'; btn.disabled = true;
  fetch(BASE+'/public/ajax/enquiry.php', { method:'POST', body: new FormData(e.target) })
    .then(r=>r.json()).then(d => {
      if (d.ok) {
        document.getElementById('enq-success').textContent = d.msg;
        document.getElementById('enq-success').style.display = 'flex';
        document.getElementById('enq-form').style.display = 'none';
      } else {
        btn.textContent = 'Send Enquiry to Vendor'; btn.disabled = false;
        alert(d.msg);
      }
    }).catch(()=>{ btn.textContent='Send Enquiry'; btn.disabled=false; });
}
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
