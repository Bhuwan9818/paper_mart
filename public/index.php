<?php
$pageTitle   = 'PaperMart — India\'s #1 B2B Paper & Packaging Marketplace';
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

// Active banners for the hero ad carousel
try {
    $heroBanners = $pdo->query("SELECT * FROM banners WHERE status='active' ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (Exception $e) {
    $heroBanners = []; // banners table not yet migrated — falls back to plain hero
}

$catIcons=['Corrugated Boxes'=>'📦','Kraft Paper'=>'📜','Duplex Board'=>'🗂️','Mono Carton'=>'🎁','Woven Fabric'=>'🧵','Non-Woven Fabric'=>'🎀','Industrial Adhesives'=>'🧴','Surface Coatings'=>'🖌️'];
?>

<!-- AD DISPLAY SECTION -->
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
    <!-- Search card overlay: position:absolute fills .ad-stage, flex aligns card vertically -->
    <div class="ad-stage-inner">
      <div class="container">
        <div class="ad-search-card">
          <h3>Find Your Products</h3>
          <form action="<?= BASE_URL ?>/public/products.php" method="GET">
            <select name="industry" class="ad-search-field">
              <option value="">All Industries</option>
              <?php foreach($industries as $ind): ?>
                <option value="<?= $ind['id'] ?>"><?= sH($ind['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="category" class="ad-search-field">
              <option value="">All Categories</option>
              <?php foreach($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= sH($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="q" placeholder="Product, GSM, specification…" class="ad-search-field">
            <button type="submit" class="ad-search-submit">Search Products</button>
          </form>
          <a href="<?= BASE_URL ?>/public/products.php" class="ad-search-advanced">Browse All Categories →</a>
        </div>
      </div>
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

<!-- BROWSE CATEGORIES CAROUSEL -->
<section class="cat-carousel-section">
  <div class="container">
    <div class="cat-carousel-header">
      <div>
        <div class="section-label">Explore</div>
        <h2 class="cat-carousel-title">Browse Products by Category</h2>
        <p class="cat-carousel-sub">Discover thousands of verified products across all major paper &amp; packaging categories</p>
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
  grid-template-columns: 1fr !important;
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

<!-- HOW IT WORKS -->
<section style="background:#fff">
  <div class="container">
    <div class="section-head center">
      <div class="section-label">Simple Process</div>
      <h2>How PaperMart Works</h2>
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
