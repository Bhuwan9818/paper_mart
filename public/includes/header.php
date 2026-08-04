<?php
// public/includes/header.php
if (session_status()===PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__,2).'/config.php';
require_once dirname(__DIR__,2).'/includes/auth.php';
require_once dirname(__DIR__,2).'/includes/functions.php';
require_once __DIR__.'/site_functions.php';

$pageTitle   = $pageTitle ?? 'PaperMart — India\'s B2B Paper Marketplace';
$pageDesc    = $pageDesc  ?? 'Find kraft paper, corrugated boxes, duplex board and more from verified Indian manufacturers.';
$currentPage = $currentPage ?? '';

$compareCount = 0;
try {
  $cs=$pdo->prepare("SELECT COUNT(*) FROM compare_sessions WHERE session_key=?");
  $cs->execute([session_id()]); $compareCount=$cs->fetchColumn();
} catch(Exception $e){}

try { $navIndustries=$pdo->query("SELECT * FROM industries WHERE status=1 ORDER BY sort_order LIMIT 8")->fetchAll(); }
catch(Exception $e){ $navIndustries=[]; }
try { $navCategories=$pdo->query("SELECT c.*,i.name AS iname FROM categories c JOIN industries i ON i.id=c.industry_id WHERE c.status=1 ORDER BY i.sort_order,c.sort_order LIMIT 20")->fetchAll(); }
catch(Exception $e){ $navCategories=[]; }

$isLoggedIn = isLoggedIn();
$userRole   = $_SESSION['role'] ?? '';
$userName   = $_SESSION['name'] ?? '';
$searchQ    = sH($_GET['q'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= sH($pageTitle) ?></title>
<meta name="description" content="<?= sH($pageDesc) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/site.css">
</head>
<body>

<!-- ═══════════════════════════════════════════════════════
     MOBILE MENU DRAWER OVERLAY
     ═══════════════════════════════════════════════════════ -->
<div class="mob-overlay" id="mob-overlay" onclick="closeMobMenu()"></div>

<!-- ═══════════════════════════════════════════════════════
     MOBILE MENU DRAWER
     ═══════════════════════════════════════════════════════ -->
<div class="mob-drawer" id="mob-drawer">

  <!-- Drawer header -->
  <div class="mob-drawer-head">
    <a href="<?= BASE_URL ?>/public/index.php" class="logo" onclick="closeMobMenu()">
      <div class="logo-icon">📄</div>
      <div>
        <div class="logo-text">PaperMart</div>
        <div class="logo-sub">B2B Paper Marketplace</div>
      </div>
    </a>
    <button class="mob-close" onclick="closeMobMenu()" aria-label="Close menu">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>

  <!-- Mobile search -->
  <div class="mob-search">
    <form action="<?= BASE_URL ?>/public/products.php" method="GET">
      <div class="mob-search-wrap">
        <span class="mob-search-icon">🔍</span>
        <input type="text" name="q" value="<?= $searchQ ?>" placeholder="Search products…" autocomplete="off">
        <button type="submit">Go</button>
      </div>
    </form>
  </div>

  <!-- Auth strip -->
  <div class="mob-auth-strip">
    <?php if ($isLoggedIn): ?>
      <div class="mob-auth-user">
        <div class="mob-auth-avatar"><?= strtoupper(substr($userName,0,1)) ?></div>
        <div>
          <div class="mob-auth-name"><?= sH($userName) ?></div>
          <div class="mob-auth-role"><?= ucfirst($userRole) ?></div>
        </div>
      </div>
      <div class="mob-auth-links">
        <a href="<?= BASE_URL ?>/<?= $userRole ?>/dashboard.php" onclick="closeMobMenu()">
          <span>📊</span> My Dashboard
        </a>
        <a href="<?= BASE_URL ?>/logout.php">
          <span>🚪</span> Logout
        </a>
      </div>
    <?php else: ?>
      <div class="mob-auth-btns">
        <a href="<?= BASE_URL ?>/login.php" class="mob-btn-signin" onclick="closeMobMenu()">Sign In</a>
        <a href="<?= BASE_URL ?>/public/vendor-register.php" class="mob-btn-vendor" onclick="closeMobMenu()">🏪 Become a Vendor</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- Navigation links -->
  <nav class="mob-nav">
    <a href="<?= BASE_URL ?>/public/index.php" class="mob-nav-link <?= $currentPage==='home'?'active':'' ?>" onclick="closeMobMenu()">
      <span class="mob-nav-icon">🏠</span> Home
    </a>

    <!-- Products accordion -->
    <div class="mob-accordion">
      <button class="mob-accordion-trigger <?= $currentPage==='products'?'active':'' ?>" onclick="toggleAccordion(this)">
        <span><span class="mob-nav-icon">📦</span> Products</span>
        <svg class="acc-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="mob-accordion-body">
        <?php if ($navIndustries): ?>
        <div class="mob-dd-label">By Industry</div>
        <?php foreach($navIndustries as $ni): ?>
          <a href="<?= BASE_URL ?>/public/products.php?industry=<?= $ni['id'] ?>" onclick="closeMobMenu()"><?= sH($ni['name']) ?></a>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($navCategories): ?>
        <div class="mob-dd-label" style="margin-top:8px">By Category</div>
        <?php foreach(array_slice($navCategories,0,8) as $nc): ?>
          <a href="<?= BASE_URL ?>/public/products.php?category=<?= $nc['id'] ?>" onclick="closeMobMenu()"><?= sH($nc['name']) ?></a>
        <?php endforeach; ?>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/public/products.php" class="mob-view-all" onclick="closeMobMenu()">View All Products →</a>
      </div>
    </div>

    <a href="<?= BASE_URL ?>/public/vendors.php" class="mob-nav-link <?= $currentPage==='vendors'?'active':'' ?>" onclick="closeMobMenu()">
      <span class="mob-nav-icon">🏭</span> Vendors
    </a>
    <a href="<?= BASE_URL ?>/public/compare.php" class="mob-nav-link <?= $currentPage==='compare'?'active':'' ?>" onclick="closeMobMenu()">
      <span class="mob-nav-icon">⚖️</span> Compare
      <?php if ($compareCount > 0): ?>
        <span class="mob-badge"><?= $compareCount ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= BASE_URL ?>/public/enquiry.php" class="mob-nav-link" onclick="closeMobMenu()">
      <span class="mob-nav-icon">✉️</span> Send Enquiry
    </a>
    <a href="<?= BASE_URL ?>/public/about.php" class="mob-nav-link" onclick="closeMobMenu()">
      <span class="mob-nav-icon">ℹ️</span> About
    </a>
  </nav>

  <!-- Contact info footer in drawer -->
  <div class="mob-drawer-foot">
    <div class="mob-contact-items">
      <a href="tel:+919876543210"><span>📞</span> +91 98765 43210</a>
      <a href="mailto:info@papermart.in"><span>✉️</span> info@papermart.in</a>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     COMPARE BAR
     ═══════════════════════════════════════════════════════ -->
<div class="compare-bar <?= $compareCount>0?'visible':'' ?>" id="compare-bar">
  <div class="container">
    <strong style="white-space:nowrap;font-size:14px">Compare Products</strong>
    <div class="compare-slots" id="compare-slots">
      <?php for($i=0;$i<4;$i++): ?>
        <div class="compare-slot empty" id="cs-<?= $i ?>">
          <span style="font-size:22px;opacity:.4">+</span>
          <span class="compare-slot-name" style="color:rgba(255,255,255,.4);font-style:italic">Add product</span>
        </div>
      <?php endfor; ?>
    </div>
    <div style="display:flex;gap:10px;flex-shrink:0">
      <a href="<?= BASE_URL ?>/public/compare.php" class="btn btn-accent btn-sm" id="btn-compare-now" style="<?= $compareCount<2?'display:none':'' ?>">Compare Now →</a>
      <button class="btn btn-outline btn-sm" style="border-color:rgba(255,255,255,.3);color:#fff" onclick="clearCompare()">Clear</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     SITE HEADER
     ═══════════════════════════════════════════════════════ -->
<header class="site-header" id="site-header">

  <!-- Top bar (desktop only) -->
  <div class="header-top">
    <div class="container">
      <div class="header-top-left">
        <span>📞 +91 98765 43210</span>
        <span>✉️ info@papermart.in</span>
        <span>🕐 Mon–Sat, 9AM–6PM</span>
      </div>
      <div class="header-top-right">
        <?php if ($isLoggedIn): ?>
          <a href="<?= BASE_URL ?>/<?= $userRole ?>/dashboard.php">My Dashboard</a>
          <a href="<?= BASE_URL ?>/logout.php">Logout</a>
        <?php else: ?>
          <a href="<?= BASE_URL ?>/login.php">Sign In</a>
          <a href="<?= BASE_URL ?>/register.php">Register</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/public/vendor-register.php" style="color:#fbbf24;font-weight:600">🏪 Become a Vendor</a>
      </div>
    </div>
  </div>

  <!-- Main header row -->
  <div class="header-main">
    <div class="container">

      <!-- Hamburger (mobile) -->
      <button class="hamburger" id="hamburger-btn" onclick="openMobMenu()" aria-label="Open menu" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>

      <!-- Logo -->
      <a href="<?= BASE_URL ?>/public/index.php" class="logo">
        <div class="logo-icon">📄</div>
        <div>
          <div class="logo-text">PaperMart</div>
          <div class="logo-sub">B2B Paper Marketplace</div>
        </div>
      </a>

      <!-- Search bar (desktop) -->
      <div class="search-bar">
        <form action="<?= BASE_URL ?>/public/products.php" method="GET">
          <input type="text" name="q" value="<?= $searchQ ?>" placeholder="Search kraft paper, corrugated boxes, duplex board…" autocomplete="off">
          <button type="submit">🔍</button>
        </form>
      </div>

      <!-- Desktop actions -->
      <div class="header-actions">
        <a href="<?= BASE_URL ?>/public/compare.php" class="btn-hdr btn-hdr-outline" title="Compare Products">
          ⚖️ Compare <span class="compare-badge" id="cmp-cnt" style="<?= $compareCount<1?'display:none':'' ?>"><?= $compareCount ?></span>
        </a>
        <?php if ($isLoggedIn): ?>
          <a href="<?= BASE_URL ?>/<?= $userRole ?>/dashboard.php" class="btn-hdr btn-hdr-primary">Dashboard</a>
        <?php else: ?>
          <a href="<?= BASE_URL ?>/login.php" class="btn-hdr btn-hdr-outline">Sign In</a>
          <a href="<?= BASE_URL ?>/public/vendor-register.php" class="btn-hdr btn-hdr-accent">List Your Products</a>
        <?php endif; ?>
      </div>

      <!-- Mobile right icons -->
      <div class="mob-header-icons">
        <a href="<?= BASE_URL ?>/public/compare.php" class="mob-icon-btn" title="Compare">
          ⚖️
          <?php if ($compareCount > 0): ?>
            <span class="mob-icon-badge"><?= $compareCount ?></span>
          <?php endif; ?>
        </a>
        <button class="mob-icon-btn mob-search-toggle" onclick="toggleMobSearch()" aria-label="Search">
          🔍
        </button>
      </div>

    </div>
  </div>

  <!-- Mobile search bar (collapses in/out) -->
  <div class="mob-search-bar" id="mob-search-bar">
    <div class="container">
      <form action="<?= BASE_URL ?>/public/products.php" method="GET">
        <div class="mob-search-inner">
          <input type="text" name="q" value="<?= $searchQ ?>" placeholder="Search products…" autocomplete="off" id="mob-search-input">
          <button type="submit">Search</button>
          <button type="button" class="mob-search-close" onclick="toggleMobSearch()">✕</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Desktop nav bar -->
  <nav class="site-nav" id="desktop-nav">
    <div class="container">
      <div class="nav-item <?= $currentPage==='home'?'active':'' ?>">
        <a href="<?= BASE_URL ?>/public/index.php" class="nav-trigger">Home</a>
      </div>
      <div class="nav-item <?= $currentPage==='products'?'active':'' ?>">
        <span class="nav-trigger">Products ▾</span>
        <div class="nav-dropdown">
          <div class="dd-label">Browse by Industry</div>
          <?php foreach($navIndustries as $ni): ?>
            <a href="<?= BASE_URL ?>/public/products.php?industry=<?= $ni['id'] ?>"><?= sH($ni['name']) ?></a>
          <?php endforeach; ?>
          <div class="dd-label" style="margin-top:6px">Browse by Category</div>
          <?php foreach(array_slice($navCategories,0,8) as $nc): ?>
            <a href="<?= BASE_URL ?>/public/products.php?category=<?= $nc['id'] ?>"><?= sH($nc['name']) ?></a>
          <?php endforeach; ?>
          <a href="<?= BASE_URL ?>/public/products.php" style="font-weight:700;color:var(--brand-2);border-top:1px solid var(--n100);margin-top:4px">View All Products →</a>
        </div>
      </div>
      <div class="nav-item <?= $currentPage==='vendors'?'active':'' ?>">
        <a href="<?= BASE_URL ?>/public/vendors.php" class="nav-trigger">Vendors</a>
      </div>
      <div class="nav-item <?= $currentPage==='compare'?'active':'' ?>">
        <a href="<?= BASE_URL ?>/public/compare.php" class="nav-trigger">Compare</a>
      </div>
      <div class="nav-item <?= $currentPage==='enquiry'?'active':'' ?>">
        <a href="<?= BASE_URL ?>/public/enquiry.php" class="nav-trigger">Send Enquiry</a>
      </div>
      <div class="nav-item <?= $currentPage==='about'?'active':'' ?>">
        <a href="<?= BASE_URL ?>/public/about.php" class="nav-trigger">About</a>
      </div>
    </div>
  </nav>
</header>

<!-- Flash message -->
<?php if (!empty($_SESSION['flash_msg'])): ?>
<div class="container" style="margin-top:16px">
  <div class="site-alert site-alert-success"><?= sH($_SESSION['flash_msg']) ?></div>
</div>
<?php unset($_SESSION['flash_msg']); endif; ?>

<script>
/* ═══════════════════════════════════════════════════════════
   SITE NAVIGATION — desktop dropdowns + mobile drawer
   ═══════════════════════════════════════════════════════════ */

/* ── Mobile drawer ───────────────────────────────────────── */
function openMobMenu() {
  document.getElementById('mob-drawer').classList.add('open');
  document.getElementById('mob-overlay').classList.add('open');
  document.getElementById('hamburger-btn').setAttribute('aria-expanded','true');
  document.body.classList.add('mob-open');
}
function closeMobMenu() {
  document.getElementById('mob-drawer').classList.remove('open');
  document.getElementById('mob-overlay').classList.remove('open');
  document.getElementById('hamburger-btn').setAttribute('aria-expanded','false');
  document.body.classList.remove('mob-open');
}

/* ── Mobile search toggle ────────────────────────────────── */
function toggleMobSearch() {
  const bar = document.getElementById('mob-search-bar');
  const isOpen = bar.classList.toggle('open');
  if (isOpen) setTimeout(() => document.getElementById('mob-search-input')?.focus(), 150);
}
function closeMobSearch() {
  document.getElementById('mob-search-bar')?.classList.remove('open');
}

/* ── Accordion in mobile drawer ──────────────────────────── */
function toggleAccordion(btn) {
  const body = btn.nextElementSibling; 
  const isOpen = body.classList.contains('open');
  document.querySelectorAll('.mob-accordion-body.open').forEach(b => {
    b.classList.remove('open');
    b.previousElementSibling?.classList.remove('open');
  });
  if (!isOpen) { body.classList.add('open'); btn.classList.add('open'); }
}

/* ── Desktop dropdown nav — click-to-open on touch ──────── */
document.addEventListener('DOMContentLoaded', () => {
  const isTouchDevice = () => window.matchMedia('(hover: none)').matches;

  document.querySelectorAll('.nav-item').forEach(item => {
    const trigger = item.querySelector('.nav-trigger');
    if (!trigger || !item.querySelector('.nav-dropdown')) return;

    // On touch: toggle on click instead of relying on CSS :hover
    trigger.addEventListener('click', e => {
      if (!isTouchDevice() && window.innerWidth > 768) return; // let CSS :hover handle desktop
      e.preventDefault();
      const isOpen = item.classList.contains('dd-open');
      document.querySelectorAll('.nav-item.dd-open').forEach(o => o.classList.remove('dd-open'));
      if (!isOpen) item.classList.add('dd-open');
    });
  });

  // Close dropdown when clicking outside
  document.addEventListener('click', e => {
    if (!e.target.closest('.nav-item')) {
      document.querySelectorAll('.nav-item.dd-open').forEach(o => o.classList.remove('dd-open'));
    }
  });
});

/* ── Keyboard navigation ─────────────────────────────────── */
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeMobMenu(); closeMobSearch(); }
});

/* ── Sticky header shadow ────────────────────────────────── */
window.addEventListener('scroll', () => {
  document.getElementById('site-header')?.classList.toggle('scrolled', window.scrollY > 10);
}, { passive: true });
</script>
