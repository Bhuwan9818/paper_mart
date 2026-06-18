<?php // public/includes/footer.php ?>
<!-- Trust bar -->
<div class="trust-bar">
  <div class="container">
    <div class="trust-items">
      <div class="trust-item"><span class="trust-icon">✅</span><span>Verified Vendors Only</span></div>
      <div class="trust-item"><span class="trust-icon">🔒</span><span>Secure Enquiries</span></div>
      <div class="trust-item"><span class="trust-icon">⚡</span><span>Fast Response Guarantee</span></div>
      <div class="trust-item"><span class="trust-icon">🏭</span><span>Direct from Manufacturers</span></div>
      <div class="trust-item"><span class="trust-icon">🤝</span><span>Free to Use for Buyers</span></div>
    </div>
  </div>
</div>

<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <div class="footer-logo">
          <div class="logo">
            <div class="logo-icon">📄</div>
            <div>
              <div class="logo-text">PaperMart</div>
              <div class="logo-sub">B2B Paper Marketplace</div>
            </div>
          </div>
        </div>
        <p class="footer-desc">India's leading B2B marketplace for paper, packaging, and industrial materials. Connecting buyers with verified manufacturers since 2024.</p>
        <div style="display:flex;gap:10px;margin-top:18px">
          <a href="#" style="width:36px;height:36px;background:rgba(255,255,255,.08);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;transition:var(--t)" onmouseover="this.style.background='rgba(255,255,255,.2)'" onmouseout="this.style.background='rgba(255,255,255,.08)'">🔗</a>
          <a href="#" style="width:36px;height:36px;background:rgba(255,255,255,.08);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;transition:var(--t)" onmouseover="this.style.background='rgba(255,255,255,.2)'" onmouseout="this.style.background='rgba(255,255,255,.08)'">📘</a>
          <a href="#" style="width:36px;height:36px;background:rgba(255,255,255,.08);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;transition:var(--t)" onmouseover="this.style.background='rgba(255,255,255,.2)'" onmouseout="this.style.background='rgba(255,255,255,.08)'">🐦</a>
        </div>
      </div>
      <div class="footer-col">
        <h4>Products</h4>
        <ul>
          <li><a href="<?= BASE_URL ?>/public/products.php?category=2">Kraft Paper</a></li>
          <li><a href="<?= BASE_URL ?>/public/products.php?category=1">Corrugated Boxes</a></li>
          <li><a href="<?= BASE_URL ?>/public/products.php?category=3">Duplex Board</a></li>
          <li><a href="<?= BASE_URL ?>/public/products.php?category=4">Mono Carton</a></li>
          <li><a href="<?= BASE_URL ?>/public/products.php">All Products</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>For Vendors</h4>
        <ul>
          <li><a href="<?= BASE_URL ?>/public/vendor-register.php">List Your Products</a></li>
          <li><a href="<?= BASE_URL ?>/vendor/subscription.php">Subscription Plans</a></li>
          <li><a href="<?= BASE_URL ?>/vendor/dashboard.php">Vendor Dashboard</a></li>
          <li><a href="<?= BASE_URL ?>/public/about.php">How It Works</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Support</h4>
        <ul>
          <li><a href="<?= BASE_URL ?>/public/about.php">About Us</a></li>
          <li><a href="<?= BASE_URL ?>/public/enquiry.php">Contact Us</a></li>
          <li><a href="#">Privacy Policy</a></li>
          <li><a href="#">Terms of Use</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© 2024 PaperMart India. All rights reserved.</span>
      <div class="footer-links">
        <a href="#">Privacy</a><a href="#">Terms</a><a href="#">Sitemap</a>
      </div>
    </div>
  </div>
</footer>

<!-- Mobile & responsive JS -->
<script>
/* ── Mobile filter panel toggle ─────────────────────────── */
(function(){
  const fab  = document.getElementById('mob-filter-fab');
  const fp   = document.querySelector('.filter-panel');
  if (!fab || !fp) return;

  // On mobile, wrap filter cards in collapsible div
  function initFilterPanel() {
    if (window.innerWidth > 768) {
      // Restore if previously wrapped
      const inner = fp.querySelector('.filter-panel-inner');
      if (inner) {
        while (inner.firstChild) fp.insertBefore(inner.firstChild, inner);
        inner.remove();
        fp.querySelector('.filter-toggle-btn')?.remove();
      }
      fab.style.display = 'none';
      return;
    }
    if (fp.dataset.wrapped) return;
    fp.dataset.wrapped = '1';
    fab.style.display = 'flex';

    const btn = document.createElement('button');
    btn.className = 'filter-toggle-btn';
    btn.type = 'button';
    btn.innerHTML = '⚙️ Filters <span class="ft-arrow" style="transition:transform .2s">▼</span>';
    btn.style.cssText = 'display:flex;width:100%;padding:11px 16px;background:var(--brand);color:#fff;border:none;border-radius:var(--r-sm);font-size:14px;font-weight:600;cursor:pointer;align-items:center;justify-content:space-between;margin-bottom:10px;font-family:inherit;';

    const inner = document.createElement('div');
    inner.style.cssText = 'display:none;';
    while (fp.firstChild) inner.appendChild(fp.firstChild);
    fp.appendChild(btn);
    fp.appendChild(inner);

    btn.addEventListener('click', () => {
      const open = inner.style.display === 'block';
      inner.style.display = open ? 'none' : 'block';
      btn.querySelector('.ft-arrow').style.transform = open ? '' : 'rotate(180deg)';
    });
  }

  initFilterPanel();
  window.addEventListener('resize', () => { fp.dataset.wrapped = ''; initFilterPanel(); }, { passive: true });
})();

/* ── Viewport height CSS var for mobile browsers ─────────── */
function setVh(){ document.documentElement.style.setProperty('--vh', window.innerHeight*.01+'px'); }
setVh();
window.addEventListener('resize', setVh, { passive: true });

/* ── Smooth anchor scroll ────────────────────────────────── */
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const t = document.querySelector(a.getAttribute('href'));
    if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth' }); }
  });
});
</script>

<!-- Compare Bar JS -->
<script>
const BASE = '<?= BASE_URL ?>';
function updateCompareBar(items) {
  const bar = document.getElementById('compare-bar');
  const cnt = document.getElementById('cmp-cnt');
  const btnNow = document.getElementById('btn-compare-now');
  if (!bar) return;
  if (items.length > 0) {
    bar.classList.add('visible');
    if(cnt){ cnt.textContent=items.length; cnt.style.display='inline-flex'; }
    if(btnNow) btnNow.style.display = items.length>=2 ? '' : 'none';
  } else {
    bar.classList.remove('visible');
    if(cnt) cnt.style.display='none';
  }
  const slots = document.querySelectorAll('.compare-slot');
  slots.forEach((s,i) => {
    if (items[i]) {
      const img = items[i].images ? `<img src="${BASE}/assets/uploads/${items[i].images.split(',')[0]}" class="compare-slot-img" onerror="this.style.display='none'">` : '<span style="font-size:22px">📦</span>';
      s.innerHTML = `${img}<span class="compare-slot-name">${items[i].name}</span><button class="compare-slot-del" onclick="removeFromCompare(${items[i].id})">✕</button>`;
      s.classList.remove('empty');
    } else {
      s.innerHTML = '<span style="font-size:22px;opacity:.4">+</span><span class="compare-slot-name" style="color:rgba(255,255,255,.4);font-style:italic">Add product</span>';
      s.classList.add('empty');
    }
  });
}

function addToCompare(productId, name, images) {
  fetch(BASE+'/public/ajax/compare.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`action=add&product_id=${productId}`
  }).then(r=>r.json()).then(d=>{
    if(d.ok) {
      getCompareItems();
      const btn = document.querySelector(`.btn-compare[data-id="${productId}"]`);
      if(btn){ btn.textContent='✓ Added'; btn.classList.add('added'); }
    } else alert(d.msg||'Cannot add more products.');
  });
}
function removeFromCompare(productId) {
  fetch(BASE+'/public/ajax/compare.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`action=remove&product_id=${productId}`
  }).then(r=>r.json()).then(d=>{ if(d.ok) getCompareItems(); });
}
function clearCompare() {
  fetch(BASE+'/public/ajax/compare.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=clear'}).then(()=>getCompareItems());
}
function getCompareItems() {
  fetch(BASE+'/public/ajax/compare.php?action=list').then(r=>r.json()).then(d=>updateCompareBar(d.items||[]));
}
getCompareItems();
</script>
<?php include dirname(__DIR__, 2) . '/includes/chatbot-widget.php'; ?>
</body></html>
