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

// "By The Numbers" impact band — every figure here is a live COUNT(), nothing invented.
$totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='customer' AND status='active'")->fetchColumn();
$totalCatsLive  = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE status=1")->fetchColumn();
$totalClosedEnq = 0;
try { $totalClosedEnq = (int)$pdo->query("SELECT COUNT(*) FROM web_enquiries WHERE status='closed'")->fetchColumn(); } catch (Exception $e) {}

// Compare Products showcase — pick the most popular category and its top 3
// products, then surface only the attributes they actually share, so the
// teaser table is always meaningful instead of full of blank cells.
$compareCat      = $categories[0] ?? null;
$compareProducts = [];
$compareAttrRows = [];
if ($compareCat) {
    $cpStmt = $pdo->prepare(
        "SELECT p.id,p.name,p.images,p.price_range,u.name AS vname,u.company
         FROM products p JOIN users u ON u.id=p.vendor_id
         WHERE p.category_id=? AND p.status='active'
         ORDER BY p.views DESC, p.created_at DESC LIMIT 3"
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
        // Only keep attributes shared by at least 2 of the 3 products, capped to 6 rows.
        $byAttr = array_filter($byAttr, fn($v) => count($v) >= 2);
        $compareAttrRows = array_slice($byAttr, 0, 6, true);
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
        <label class="ad-search-radio">
          <input type="radio" name="search-mode" value="brand" onchange="asSwitchMode('brand')">
          <span class="ad-search-radio-dot"></span> By Brand
        </label>
        <label class="ad-search-radio">
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
          <select name="vendor" id="asi-brand" class="ad-search-field as-field-full">
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
    const showBrand = mode === 'brand';

    brandGroup.style.display    = showBrand ? 'block' : 'none';
    industryGroup.style.display = showBrand ? 'none'  : 'block';

    // Disable every field in the hidden group (so stale selections never
    // get submitted alongside the active mode's filters) and re-enable
    // the visible group. Country/Brand/Industry are independent ("All …"
    // is a valid choice) — only Category/Type stay gated behind their
    // parent selection until one is actually made.
    brandGroup.querySelectorAll('select').forEach(el => el.disabled = !showBrand);
    industryGroup.querySelectorAll('select').forEach(el => el.disabled = showBrand);

    if (showBrand) {
      document.getElementById('asb-category').disabled = !document.getElementById('asb-industry').value;
    } else {
      document.getElementById('asi-category').disabled = !document.getElementById('asi-industry').value;
      document.getElementById('asi-type').disabled = !document.getElementById('asi-category').value;
    }
  };

  /* ── Brand refresh (shared by both modes) ─────────────────────────
     Industry mode calls it with whatever industry/category/type/country
     are currently set; Brand mode calls it with country only (brand
     comes before industry/category in that mode). */
  function asRefreshBrands(prefix, filters){
    const brandSel = document.getElementById(prefix + '-brand');
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
        // Keep the previous selection if it's still in the refreshed list.
        if (keepValue && list.some(b => String(b.id) === keepValue)) brandSel.value = keepValue;
      })
      .catch(() => { brandSel.innerHTML = '<option value="">All Brands</option>'; });
  }

  /* ── INDUSTRY MODE: Industry → Category → Type → Country → Brand ── */
  window.asOnIndustryChange = function(){
    const industryId = document.getElementById('asi-industry').value;
    const catSel  = document.getElementById('asi-category');
    const typeSel = document.getElementById('asi-type');
    catSel.innerHTML = '<option value="">Loading…</option>'; catSel.disabled = true;
    typeSel.innerHTML = '<option value="">Select Category first…</option>'; typeSel.disabled = true;

    if (!industryId) {
      catSel.innerHTML = '<option value="">Select Industry first…</option>';
    } else {
      fetch(BASE_PATH + '/public/ajax/get-categories-with-products.php?industry_id=' + industryId)
        .then(r => r.json())
        .then(list => {
          if (!list.length) { catSel.innerHTML = '<option value="">No categories available</option>'; return; }
          catSel.innerHTML = '<option value="">All Categories</option>' +
            list.map(c => `<option value="${c.id}">${escLbl(c.name)}</option>`).join('');
          catSel.disabled = false;
        })
        .catch(() => { catSel.innerHTML = '<option value="">Could not load categories</option>'; });
    }

    asRefreshBrands('asi', {
      industryId, country: document.getElementById('asi-country').value
    });
  };

  window.asOnCategoryChange = function(){
    const categoryId = document.getElementById('asi-category').value;
    const typeSel = document.getElementById('asi-type');
    typeSel.innerHTML = '<option value="">Loading…</option>'; typeSel.disabled = true;

    if (!categoryId) {
      typeSel.innerHTML = '<option value="">Select Category first…</option>';
    } else {
      fetch(BASE_PATH + '/public/ajax/get-product-types-with-products.php?category_id=' + categoryId)
        .then(r => r.json())
        .then(list => {
          if (!list.length) { typeSel.innerHTML = '<option value="">No product types available</option>'; return; }
          typeSel.innerHTML = '<option value="">All Product Types</option>' +
            list.map(t => `<option value="${t.id}">${escLbl(t.name)}</option>`).join('');
          typeSel.disabled = false;
        })
        .catch(() => { typeSel.innerHTML = '<option value="">Could not load product types</option>'; });
    }

    asRefreshBrands('asi', {
      industryId: document.getElementById('asi-industry').value,
      categoryId,
      country: document.getElementById('asi-country').value
    });
  };

  window.asOnIndustryModeCountryChange = function(){
    asRefreshBrands('asi', {
      industryId: document.getElementById('asi-industry').value,
      categoryId: document.getElementById('asi-category').value,
      typeId: document.getElementById('asi-type').value,
      country: document.getElementById('asi-country').value
    });
  };

  /* ── BRAND MODE: Country → Brand → Industry → Category ───────────── */
  window.asOnBrandModeCountryChange = function(){
    asRefreshBrands('asb', { country: document.getElementById('asb-country').value });
  };

  window.asOnBrandModeBrandChange = function(){
    const vendorId = document.getElementById('asb-brand').value;
    const indSel = document.getElementById('asb-industry');
    const catSel = document.getElementById('asb-category');
    catSel.innerHTML = '<option value="">All Categories</option>'; catSel.disabled = true;

    if (!vendorId) {
      // "All Brands" — fall back to the full, unscoped industries list.
      indSel.innerHTML = '<option value="">All Industries</option>' +
        ALL_INDUSTRIES.map(i => `<option value="${i.id}">${escLbl(i.name)}</option>`).join('');
      indSel.value = '';
      return;
    }
    indSel.innerHTML = '<option value="">Loading…</option>';
    fetch(BASE_PATH + '/public/ajax/get-industries-by-vendor.php?vendor_id=' + vendorId)
      .then(r => r.json())
      .then(list => {
        indSel.innerHTML = '<option value="">All Industries</option>' +
          list.map(i => `<option value="${i.id}">${escLbl(i.name)}</option>`).join('');
      })
      .catch(() => { indSel.innerHTML = '<option value="">Could not load industries</option>'; });
  };

  window.asOnBrandModeIndustryChange = function(){
    const industryId = document.getElementById('asb-industry').value;
    const vendorId   = document.getElementById('asb-brand').value;
    const catSel = document.getElementById('asb-category');
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

  // Ensure the hidden mode's fields start correctly disabled (defence
  // against duplicate same-name fields both serializing on submit).
  //
  // IMPORTANT: don't hardcode 'industry' here. Browsers restore which
  // radio was checked on back/forward navigation (both bfcache restores
  // and regular history reloads), and that restoration isn't guaranteed
  // to happen before this inline script runs — so the toggle pill can
  // visually show "By Brand" checked while the field groups are still
  // stuck showing Industry mode. Reading the actually-checked radio (and
  // re-syncing on every `pageshow`, which fires after the browser has
  // finished restoring form state) keeps the two in sync no matter how
  // the page was reached.
  function asSyncModeFromCheckedRadio(){
    const checked = document.querySelector('input[name="search-mode"]:checked');
    asSwitchMode(checked ? checked.value : 'industry');
  }
  asSyncModeFromCheckedRadio();
  window.addEventListener('pageshow', asSyncModeFromCheckedRadio);
})();
</script>

<!-- BROWSE CATEGORIES CAROUSEL -->
<section class="cat-carousel-section">
  <div class="container">
    <div class="cat-carousel-header">
      <div>
        <div class="section-label">Most In-Demand</div>
        <h2 class="cat-carousel-title">Most Popular Categories</h2>
        <p class="cat-carousel-sub">Ranked by live catalogue size — the categories buyers search for most across paper &amp; packaging</p>
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
          0 => ['#8B241D','#C0392B'],
        ];
        $catBgPatterns=[
          '📦'=>'cubic','📜'=>'waves','🗂️'=>'dots','🎁'=>'grid',
          '🧵'=>'lines','🎀'=>'cross','🧴'=>'rings','🖌️'=>'brush',
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
        <a href="<?= BASE_URL ?>/public/products.php" class="cat-card" style="--gc1:<?= $g[0] ?>;--gc2:<?= $g[1] ?>">
          <div class="cat-card-bg"></div>
          <div class="cat-card-shine"></div>
          <div class="cat-card-content">
            <div style="margin-bottom:18px;font-family:'Raleway',sans-serif; font-weight:700;font-size:22px;line-height:1.3; padding:10px">
              View All Categories &amp; Products
            </div>
            <div class="cat-card-count">
              <span class="cat-card-count-lbl">View All</span>
              <div class="cat-card-arrow">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
              </div>
            </div>
            
          </div>
        </a>
      </div>
    </div>

    <!-- Dots -->
    <div class="cat-carousel-dots" id="cat-dots"></div>

    <!-- View All link -->
    <!-- <div style="text-align:center;margin-top:28px">
      <a href="<?= BASE_URL ?>/public/products.php" class="btn btn-outline" style="padding:12px 32px;font-size:14px;font-weight:600;border-radius:50px;border-width:2px">
        View All Categories &amp; Products →
      </a>
    </div> -->
  </div>
</section>

<style>
/* ── Category Carousel Section ────────────────────────────────── */
.cat-carousel-section {
  padding: 64px 0 56px;
  background: linear-gradient(160deg, #fff 0%, var(--n50) 60%, #fff 100%);
  position: relative;
  overflow: hidden;
}
.cat-carousel-section::before {
  content: '';
  position: absolute;
  top: -80px; right: -80px;
  width: 320px; height: 320px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(139,36,29,.06) 0%, transparent 70%);
  pointer-events: none;
}
.cat-carousel-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  margin-bottom: 36px;
  gap: 16px;
  flex-wrap: wrap;
}
.cat-carousel-title { margin: 6px 0 6px; font-size: 2rem; line-height: 1.2; }
.cat-carousel-sub { color: var(--n500); font-size: 14px; margin: 0; }
.cat-carousel-nav-btns { display: flex; gap: 10px; flex-shrink: 0; }
.cat-nav-btn {
  width: 44px; height: 44px;
  border-radius: 50%;
  border: 2px solid var(--n200);
  background: #fff;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: var(--n700);
  transition: var(--t);
  box-shadow: var(--shadow-xs);
}
.cat-nav-btn:hover { border-color: var(--brand); background: var(--brand); color: #fff; box-shadow: var(--shadow-sm); }
.cat-nav-btn:disabled { opacity: .35; cursor: default; }
.cat-nav-btn:disabled:hover { border-color: var(--n200); background: #fff; color: var(--n700); box-shadow: none; }

/* Track */
.cat-carousel-wrapper {
  overflow: hidden;
  border-radius: var(--r-lg);
  /* Subtle right fade hints there are more cards to scroll on desktop */
  -webkit-mask-image: linear-gradient(90deg, #000 85%, transparent 100%);
  mask-image: linear-gradient(90deg, #000 85%, transparent 100%);
}
.cat-carousel-track {
  display: flex;
  gap: 18px;
  transition: transform .45s cubic-bezier(.4,0,.2,1);
  will-change: transform;
  padding: 8px 4px 12px;
}

/* Card */

.cat-card-count-left{
  display: flex;
  align-items: center;
  gap: 5px;
}

.cat-card {
  flex-shrink: 0;
  width: 230px;
  min-height: 224px;
  border-radius: 20px;
  padding: 26px 20px 22px;
  text-decoration: none;
  color: #fff;
  position: relative;
  overflow: hidden;
  background: linear-gradient(145deg, var(--gc1), var(--gc2));
  box-shadow: 0 6px 24px rgba(0,0,0,.12), 0 2px 6px rgba(0,0,0,.08);
  transition: transform .28s cubic-bezier(.4,0,.2,1), box-shadow .28s cubic-bezier(.4,0,.2,1);
  cursor: pointer;
  display: block;
}
.cat-card:hover {
  transform: translateY(-6px) scale(1.02);
  box-shadow: 0 16px 40px rgba(0,0,0,.2), 0 4px 12px rgba(0,0,0,.1);
}
.cat-card-bg {
  position: absolute; inset: 0;
  background: radial-gradient(ellipse at 80% 20%, rgba(255,255,255,.18) 0%, transparent 60%);
  pointer-events: none;
}
.cat-card-shine {
  position: absolute;
  top: -40px; right: -40px;
  width: 120px; height: 120px;
  border-radius: 50%;
  background: rgba(255,255,255,.12);
  pointer-events: none;
}
.cat-card-content { position: relative; z-index: 1; }
.cat-card-icon-wrap {
  width: 56px; height: 56px;
  border-radius: 16px;
  background: rgba(255,255,255,.22);
  backdrop-filter: blur(4px);
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 18px;
  border: 1px solid rgba(255,255,255,.25);
}
.cat-card-icon { font-size: 28px; line-height: 1; }
.cat-card-info { margin-bottom: 18px; }
.cat-card-name {
  font-family: 'Poppins', sans-serif;
  font-weight: 700;
  font-size: 14.5px;
  line-height: 1.3;
  margin-bottom: 4px;
}
.cat-card-industry {
  font-size: 11.5px;
  opacity: .75;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: .04em;
}
.cat-card-count {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 5px;
  padding: 8px 12px;
  background: rgba(0,0,0,.18);
  border-radius: 10px;
  margin-bottom: 14px;
}
.cat-card-count-num {
  font-family: 'Poppins', sans-serif;
  font-size: 20px;
  font-weight: 800;
  line-height: 1;
}
.cat-card-count-lbl {
  font-size: 11px;
  opacity: .8;
  font-weight: 500;
}
.cat-card-arrow {
  /* position: absolute; */
  /* bottom: 6px; right: 10px; */
  width: 28px; height: 28px;
  border-radius: 50%;
  background: rgba(255,255,255,.2);
  display: flex; align-items: center; justify-content: center;
  transition: var(--t);
}
.cat-card:hover .cat-card-arrow {
  background: rgba(255,255,255,.35);
  transform: translateX(3px);
}

/* Dots */
.cat-carousel-dots {
  display: flex;
  justify-content: center;
  gap: 8px;
  margin-top: 24px;
}
.cat-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--n200);
  border: none;
  cursor: pointer;
  transition: all .25s ease;
  padding: 0;
}
.cat-dot.active {
  width: 28px;
  border-radius: 4px;
  background: var(--brand);
}

/* Tablet (≤1024px): native scroll-snap, no JS transform, arrow buttons hidden */
@media(max-width:1024px){
  .cat-carousel-nav-btns { display: none; }
  .cat-carousel-dots { display: none; }
  .cat-carousel-wrapper {
    overflow-x: auto;
    overflow-y: hidden;
    border-radius: 0;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    /* Remove the desktop right-fade mask on mobile */
    -webkit-mask-image: none;
    mask-image: none;
  }
  .cat-carousel-wrapper::-webkit-scrollbar { display: none; }
  .cat-carousel-track {
    /* Disable JS-driven transform, CSS scroll-snap takes over */
    transition: none;
    /* scroll-snap: each card snaps cleanly to the left edge */
    scroll-snap-type: x mandatory;
    /* Padding creates the "peek" effect — next card is just visible */
    padding: 8px 20px 16px;
    margin: 0 -20px;
  }
  .cat-card {
    scroll-snap-align: start;
    width: 200px;
  }
}

/* Mobile (≤768px): smaller cards, same scroll-snap mechanism */
@media(max-width:768px){
  .cat-carousel-section { padding: 40px 0 32px; }
  .cat-carousel-header { margin-bottom: 22px; }
  .cat-carousel-title { font-size: 1.4rem; }
  .cat-carousel-sub { font-size: 13px; }
  .cat-card {
    width: 172px;
    min-height: 192px;
    padding: 20px 16px 16px;
    border-radius: 16px;
  }
  .cat-card-icon-wrap { width: 46px; height: 46px; border-radius: 13px; margin-bottom: 14px; }
  .cat-card-icon { font-size: 24px; }
  .cat-card-name { font-size: 13px; }
  .cat-card-industry { font-size: 10.5px; }
  .cat-card-count { padding: 6px 10px; margin-bottom: 10px; }
  .cat-card-count-num { font-size: 18px; }
  .cat-card-count-lbl { font-size: 10.5px; }
  .cat-card-arrow { width: 24px; height: 24px; }
  .cat-carousel-track { padding: 6px 16px 14px; margin: 0 -16px; }
}

/* Small phone (≤480px): narrowest cards so 2+ are always visible */
@media(max-width:480px){
  .cat-card { width: 155px; min-height: 182px; }
}

.compare-group{
  display:grid;
  grid-template-columns: 1fr;
}
</style>

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

  // Below this width switch to native scroll-snap (CSS-only, no JS)
  const MOBILE_BP = 1024;
  const isDesktop = () => window.innerWidth > MOBILE_BP;

  let current = 0;

  // Read the ACTUAL rendered card width rather than a hardcoded number.
  // This is crucial — if CSS changes card width at any breakpoint, the
  // JS automatically picks up the real value with no manual update.
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
    if (!isDesktop()) return; // native CSS scroll-snap handles mobile
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
    // Clear any JS-applied transform so native scroll-snap takes over
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
    }, 120); // debounce so resize doesn't thrash
  });
})();
</script>

<!-- FEATURED PRODUCTS -->
<?php if ($featured): ?>
<section style="background:var(--n50)">
  <div class="container">
    <div class="section-head" style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px">
      <div>
        <div class="section-label">Hand Picked</div>
        <h2>Featured Products</h2>
      </div>
      <a href="<?= BASE_URL ?>/public/products.php" class="btn btn-outline btn-sm">View All →</a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:18px" >
      <?php foreach($featured as $p):
        $imgs=array_filter(explode(',',$p['images']??''));
        $img=reset($imgs)?UPLOAD_URL.trim(reset($imgs)):'';

      ?>
      
      <div class="card">
        <div class="card-img">
          <?php if($img): ?><img src="<?= sH($img) ?>" alt="<?= sH($p['name']) ?>" loading="lazy"><?php else: ?><span class="card-img-ph">📦</span><?php endif; ?>
          <?php if($p['is_featured']): ?><div class="card-badge-pos"><span class="badge badge-amber">⭐ Featured</span></div><?php endif; ?>
          <div class="card-compare-pos">
            <button class="btn btn-compare" onclick="addToCompare(<?= $p['id'] ?>,'<?= sH($p['name']) ?>','<?= sH($p['images']??'') ?>')" data-id="<?= $p['id'] ?>" title="Add to compare">⚖️</button>
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
          <a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm" style="flex:1">View Details</a>
          <button class="btn btn-accent btn-sm" onclick="openEnquiryModal(<?= $p['id'] ?>,<?= $p['vendor_id'] ?>,'<?= sH($p['name']) ?>')">Enquire</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- MOST POPULAR BRANDS -->
<?php if ($popularVendors): ?>
<section class="brands-section">
  <div class="container">
    <div class="section-head" style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px">
      <div>
        <div class="section-label">Trusted Manufacturers</div>
        <h2>Most Popular Brands</h2>
        <p style="color:var(--n500);margin:0">The manufacturers buyers connect with most, ranked by verification and live catalogue size</p>
      </div>
      <a href="<?= BASE_URL ?>/public/vendors.php" class="btn btn-outline btn-sm">View All Vendors →</a>
    </div>
    <div class="brand-grid">
      <?php foreach ($popularVendors as $v):
        $logoUrl = !empty($v['logo']) ? UPLOAD_URL . $v['logo'] : '';
        $displayName = $v['company'] ?: $v['name'];
      ?>
      <a href="<?= BASE_URL ?>/public/vendor-profile.php?id=<?= $v['id'] ?>" class="brand-card">
        <?php if ($v['is_verified']): ?><span class="brand-card-verified">✓ Verified</span><?php endif; ?>
        <div class="brand-card-logo">
          <?php if ($logoUrl): ?>
            <img src="<?= sH($logoUrl) ?>" alt="<?= sH($displayName) ?>" loading="lazy">
          <?php else: ?>
            <span><?= strtoupper(substr($displayName, 0, 1)) ?></span>
          <?php endif; ?>
        </div>
        <div class="brand-card-name"><?= sH($displayName) ?></div>
        <?php if ($v['city'] || $v['country']): ?>
          <div class="brand-card-loc"><?= sH(trim(($v['city'] ?: '') . (($v['city'] && $v['country']) ? ', ' : '') . ($v['country'] ?: ''))) ?></div>
        <?php endif; ?>
        <div class="brand-card-meta">
          <span><?= (int)$v['prod_count'] ?> Products</span>
          <?php if ($v['total_reviews'] > 0): ?><span>★ <?= number_format((float)$v['rating'], 1) ?></span><?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.brands-section{background:#fff;padding:56px 0}
.brand-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:16px}
.brand-card{position:relative;background:#fff;border:1px solid var(--n200);border-radius:var(--r-lg);padding:22px 18px;text-align:center;text-decoration:none;color:inherit;transition:var(--t);display:block}
.brand-card:hover{border-color:var(--brand-2);box-shadow:var(--shadow);transform:translateY(-3px)}
.brand-card-verified{position:absolute;top:10px;right:10px;background:var(--green-lt,#e7f7ee);color:var(--green,#1a9e5c);font-size:9.5px;font-weight:700;padding:3px 8px;border-radius:100px}
.brand-card-logo{width:60px;height:60px;border-radius:14px;background:var(--brand-3);color:var(--brand-2);display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-weight:800;font-size:22px;margin:0 auto 14px;overflow:hidden}
.brand-card-logo img{width:100%;height:100%;object-fit:cover}
.brand-card-name{font-family:'Poppins',sans-serif;font-weight:700;font-size:14px;color:var(--n900);margin-bottom:4px;line-height:1.3}
.brand-card-loc{font-size:11.5px;color:var(--n500);margin-bottom:10px}
.brand-card-meta{display:flex;justify-content:center;gap:12px;font-size:11.5px;color:var(--brand-2);font-weight:600;padding-top:10px;border-top:1px dashed var(--n200)}
@media(max-width:768px){.brands-section{padding:40px 0}.brand-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}.brand-card{padding:18px 12px}}
</style>
<?php endif; ?>

<!-- BY THE NUMBERS -->
<section class="impact-section">
  <div class="container">
    <div class="section-head center" style="margin-bottom:40px">
      <div class="section-label" style="color:var(--accent)">Our Impact</div>
      <h2 style="color:#fff">paperKart, By The Numbers</h2>
      <p style="color:rgba(255,255,255,.7)">Real activity happening on the platform right now — no estimates.</p>
    </div>
    <div class="impact-grid">
      <?php
      $impactStats = [
        ['🏭', number_format($totalVends) . '+', 'Verified Vendors'],
        ['📦', number_format($totalProds) . '+', 'Products Listed'],
        ['🧾', number_format($totalEnqs) . '+', 'Leads Delivered to Vendors'],
        ['🤝', number_format($totalClosedEnq) . '+', 'Successful Connections'],
        ['🗂️', number_format($totalCatsLive) . '+', 'Categories Covered'],
        ['👥', number_format($totalCustomers) . '+', 'Registered Buyers'],
      ];
      foreach ($impactStats as [$icon, $num, $label]):
      ?>
      <div class="impact-card">
        <div class="impact-icon"><?= $icon ?></div>
        <div class="impact-num"><?= $num ?></div>
        <div class="impact-label"><?= $label ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.impact-section{background:linear-gradient(150deg,#3a0d08 0%,var(--brand) 50%,#62130a 100%);padding:64px 0;position:relative;overflow:hidden}
.impact-section::before{content:'';position:absolute;top:-60px;right:-60px;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,rgba(240,192,96,.14) 0%,transparent 70%)}
.impact-section::after{content:'';position:absolute;bottom:-80px;left:-80px;width:320px;height:320px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.06) 0%,transparent 70%)}
.impact-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:16px;position:relative;z-index:1}
.impact-card{text-align:center;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:var(--r-lg);padding:26px 12px;backdrop-filter:blur(6px);transition:var(--t)}
.impact-card:hover{background:rgba(255,255,255,.1);transform:translateY(-3px)}
.impact-icon{font-size:30px;margin-bottom:10px}
.impact-num{font-family:'Poppins',sans-serif;font-weight:800;font-size:clamp(20px,2.4vw,30px);color:var(--accent);line-height:1}
.impact-label{font-size:12px;color:rgba(255,255,255,.75);margin-top:6px;line-height:1.4}
@media(max-width:1024px){.impact-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:520px){.impact-grid{grid-template-columns:repeat(2,1fr);gap:12px}.impact-card{padding:20px 10px}}
</style>

<!-- COMPARE PRODUCTS -->
<?php if ($compareProducts): ?>
<section class="compare-showcase">
  <div class="container">
    <div class="section-head center">
      <div class="section-label">Make Confident Decisions</div>
      <h2>Compare Products Side-by-Side</h2>
      <p>Weighing up options in <strong><?= sH($compareCat['name']) ?></strong>? Here's how <?= count($compareProducts) ?> popular picks stack up on the specs that matter.</p>
    </div>

    <div class="cmp-table-wrap">
      <table class="cmp-table">
        <thead>
          <tr>
            <th class="cmp-th-label">&nbsp;</th>
            <?php foreach ($compareProducts as $p):
              $imgs = array_filter(explode(',', $p['images'] ?? ''));
              $img  = reset($imgs) ? UPLOAD_URL . trim(reset($imgs)) : '';
            ?>
            <th>
              <div class="cmp-prod-img"><?php if ($img): ?><img src="<?= sH($img) ?>" alt="<?= sH($p['name']) ?>" loading="lazy"><?php else: ?><span>📦</span><?php endif; ?></div>
              <div class="cmp-prod-name"><a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>"><?= sH($p['name']) ?></a></div>
              <div class="cmp-prod-vendor"><?= sH($p['company'] ?: $p['vname']) ?></div>
              <?php if ($p['price_range']): ?><div class="cmp-prod-price">₹ <?= sH($p['price_range']) ?></div><?php endif; ?>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($compareAttrRows as $attrName => $byProduct): ?>
          <tr>
            <td class="cmp-attr-name"><?= sH($attrName) ?></td>
            <?php foreach ($compareProducts as $p): ?>
              <td class="cmp-attr-val"><?= isset($byProduct[$p['id']]) ? sH($byProduct[$p['id']]) : '—' ?></td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="text-align:center;margin-top:28px">
      <button type="button" class="btn btn-accent btn-lg" onclick="homeCompareAll([<?= implode(',', array_column($compareProducts, 'id')) ?>])">⚖️ Compare Full Specifications →</button>
    </div>
  </div>
</section>

<style>
.compare-showcase{background:var(--n50);padding:64px 0}
.cmp-table-wrap{overflow-x:auto;border-radius:var(--r-lg);border:1px solid var(--n200);background:#fff}
.cmp-table{width:100%;border-collapse:collapse;min-width:560px}
.cmp-table th{padding:20px 16px;border-bottom:2px solid var(--n200);vertical-align:top;min-width:160px}
.cmp-th-label{min-width:120px !important}
.cmp-prod-img{width:72px;height:72px;border-radius:var(--r);background:var(--n50);display:flex;align-items:center;justify-content:center;overflow:hidden;margin:0 auto 10px}
.cmp-prod-img img{width:100%;height:100%;object-fit:cover}
.cmp-prod-name a{font-family:'Poppins',sans-serif;font-weight:700;font-size:13.5px;color:var(--n900);text-decoration:none;line-height:1.3}
.cmp-prod-name a:hover{color:var(--brand-2)}
.cmp-prod-vendor{font-size:11px;color:var(--n500);margin-top:3px}
.cmp-prod-price{font-size:12.5px;font-weight:700;color:var(--brand-2);margin-top:6px}
.cmp-attr-name{padding:14px 16px;font-size:12.5px;font-weight:700;color:var(--n700);background:var(--n50);white-space:nowrap}
.cmp-attr-val{padding:14px 16px;font-size:13px;color:var(--n700);text-align:center;border-top:1px solid var(--n100)}
.cmp-table tbody tr:nth-child(even) .cmp-attr-name{background:#fff}
@media(max-width:768px){.compare-showcase{padding:44px 0}}
</style>
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

<!-- HOW IT WORKS -->
<section style="background:#fff">
  <div class="container">
    <div class="section-head center">
      <div class="section-label">Simple Process</div>
      <h2>How paperKart Works</h2>
      <p>Connect with verified manufacturers in 3 easy steps — completely free for buyers.</p>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:32px;margin-top:36px" class="compare-group">
      <?php
      $steps=[
        ['🔍','Search & Discover','Browse thousands of paper products from verified manufacturers. Filter by GSM, grade, and specifications.'],
        ['⚖️','Compare Products','Compare multiple products side-by-side on price, specs, GSM, BF, Cobb, and other key attributes.'],
        ['📩','Send Enquiry','Send your requirements directly to the vendor. No middlemen — get quotes straight from the source.'],
      ];
      foreach ($steps as $i=>[$icon,$title,$desc]):
      ?>
      <div style="text-align:center;padding:32px 24px;border:1px solid var(--n200);border-radius:var(--r-lg);position:relative;transition:var(--t)" onmouseover="this.style.borderColor='var(--brand-2)';this.style.transform='translateY(-4px)';this.style.boxShadow='var(--shadow)'" onmouseout="this.style.borderColor='var(--n200)';this.style.transform='none';this.style.boxShadow='none'">
        <div style="position:absolute;top:-16px;left:50%;transform:translateX(-50%);width:32px;height:32px;border-radius:50%;background:var(--brand);color:#fff;font-family:'Poppins',sans-serif;font-weight:800;font-size:14px;display:flex;align-items:center;justify-content:center"><?= $i+1 ?></div>
        <div style="font-size:44px;margin-bottom:16px"><?= $icon ?></div>
        <h3 style="font-size:17px;margin-bottom:10px"><?= $title ?></h3>
        <p style="font-size:13.5px;color:var(--n500);line-height:1.7"><?= $desc ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- LATEST PRODUCTS -->
<?php if ($latest): ?>
<section style="background:var(--n50)">
  <div class="container">
    <div class="section-head" style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px">
      <div>
        <div class="section-label">Just Added</div>
        <h2>Latest Products</h2>
      </div>
      <a href="<?= BASE_URL ?>/public/products.php?sort=newest" class="btn btn-outline btn-sm">View All →</a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:18px">
      <?php foreach($latest as $p):
        $imgs=array_filter(explode(',',$p['images']??''));
        $img=reset($imgs)?UPLOAD_URL.trim(reset($imgs)):'';
      ?>
      <div class="card">
        <div class="card-img">
          <?php if($img): ?><img src="<?= sH($img) ?>" alt="<?= sH($p['name']) ?>" loading="lazy"><?php else: ?><span class="card-img-ph">📦</span><?php endif; ?>
          <div class="card-compare-pos">
            <button class="btn btn-compare" onclick="addToCompare(<?= $p['id'] ?>,'<?= sH($p['name']) ?>','<?= sH($p['images']??'') ?>')" data-id="<?= $p['id'] ?>" title="Add to compare">⚖️</button>
          </div>
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
  </div>
</section>
<?php endif; ?>

<!-- CTA BANNER -->
<section class="cta-banner">
  <div class="cta-banner-overlay"></div>
  <div class="container cta-banner-content">
    <h2>Are You a Paper Manufacturer?</h2>
    <p>List your products for free and connect with thousands of B2B buyers across India. Plans start at ₹0.</p>
    <div class="cta-banner-actions">
      <a href="<?= BASE_URL ?>/public/vendor-register.php" class="btn btn-accent btn-lg">Start Listing Free</a>
      <a href="<?= BASE_URL ?>/vendor/subscription.php" class="btn btn-lg" style="background:rgba(255,255,255,.1);color:#fff;border:1.5px solid rgba(255,255,255,.3)">View Plans</a>
    </div>
  </div>
</section>

<!-- ENQUIRY MODAL -->
<div class="modal-backdrop" id="enquiry-modal">
  <div class="modal">
    <div class="modal-header">
      <h3>📩 Send Enquiry</h3>
      <button class="modal-close" onclick="closeEnquiryModal()">✕</button>
    </div>
    <div class="modal-body">
      <div id="enq-success" class="site-alert site-alert-success" style="display:none"></div>
      <form id="enq-form" onsubmit="submitEnquiry(event)">
      <?php // honeypotField(); ?>
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
          <div class="form-group"><label class="form-label">Quantity Required</label><input type="text" name="qty_needed" class="form-input" placeholder="e.g. 500 kg, 1 MT"></div>
        </div>
        <div class="form-group"><label class="form-label">Message / Requirements</label><textarea name="message" class="form-input" rows="3" style="resize:vertical" placeholder="Describe your requirements, specifications, GSM needed…"></textarea></div>
        <button type="submit" class="btn btn-accent btn-full btn-lg" id="enq-btn">Send Enquiry to Vendor</button>
        <p style="font-size:11.5px;color:var(--n500);margin-top:10px;text-align:center">Your contact details are only shared with the vendor.</p>
      </form>
    </div>
  </div>
</div>

<script>
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
