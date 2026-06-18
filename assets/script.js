/* ============================================================
   Dashboard JavaScript
   ============================================================ */

const BASE_URL = document.querySelector('meta[name="base-url"]')?.content || '/dashboard';

// ---- Sidebar toggle (mobile) ----
document.addEventListener('DOMContentLoaded', () => {
    const sidebar  = document.getElementById('sidebar');
    const overlay  = document.getElementById('sidebar-overlay');
    const hamburger = document.getElementById('hamburger');

    function openSidebar() {
        sidebar?.classList.add('open');
        overlay?.classList.add('show');
    }
    function closeSidebar() {
        sidebar?.classList.remove('open');
        overlay?.classList.remove('show');
    }

    hamburger?.addEventListener('click', openSidebar);
    overlay?.addEventListener('click', closeSidebar);

    // ---- Dynamic cascading dropdowns ----
    const industrySelect    = document.getElementById('industry_id');
    const categorySelect    = document.getElementById('category_id');
    const productTypeSelect = document.getElementById('product_type_id');

    if (industrySelect) {
        industrySelect.addEventListener('change', () => {
            const id = industrySelect.value;
            resetSelect(categorySelect,    '-- Select Category --');
            resetSelect(productTypeSelect, '-- Select Product Type --');
            clearAttributesTable();
            if (!id) return;
            fetchOptions(BASE_URL + '/ajax/get-categories.php?industry_id=' + id, categorySelect);
        });
    }

    if (categorySelect) {
        categorySelect.addEventListener('change', () => {
            const id = categorySelect.value;
            resetSelect(productTypeSelect, '-- Select Product Type --');
            clearAttributesTable();
            if (!id) return;
            fetchOptions(BASE_URL + '/ajax/get-product-types.php?category_id=' + id, productTypeSelect);
        });
    }

    if (productTypeSelect) {
        productTypeSelect.addEventListener('change', () => {
            const id = productTypeSelect.value;
            clearAttributesTable();
            if (!id) return;
            fetchAttributes(id);
        });
    }

    // ---- Image preview ----
    const imageInput = document.getElementById('images');
    if (imageInput) {
        imageInput.addEventListener('change', previewImages);
    }

    // ---- Confirm delete ----
    document.querySelectorAll('[data-confirm]').forEach(btn => {
        btn.addEventListener('click', e => {
            if (!confirm(btn.dataset.confirm || 'Are you sure?')) e.preventDefault();
        });
    });

    // ---- Status toggle ----
    document.querySelectorAll('.toggle-status').forEach(btn => {
        btn.addEventListener('click', () => {
            const { type, id, status } = btn.dataset;
            const newStatus = status === 'active' ? 'inactive' : 'active';
            if (!confirm(`Set status to "${newStatus}"?`)) return;
            fetch(BASE_URL + '/ajax/toggle-status.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `type=${type}&id=${id}&status=${newStatus}&csrf_token=${getCsrf()}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) location.reload();
                else alert('Failed: ' + (data.message || 'Unknown error'));
            });
        });
    });

    // ---- Auto-close alerts ----
    document.querySelectorAll('.alert').forEach(el => {
        setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity 0.5s'; setTimeout(() => el.remove(), 500); }, 5000);
    });
});

// ---- Dropdown helpers ----
function resetSelect(sel, placeholder) {
    if (!sel) return;
    sel.innerHTML = `<option value="">${placeholder}</option>`;
    sel.disabled = true;
}

function fetchOptions(url, targetSelect) {
    targetSelect.innerHTML = '<option>Loading...</option>';
    targetSelect.disabled = true;
    fetch(url)
        .then(r => r.json())
        .then(data => {
            targetSelect.innerHTML = `<option value="">-- Select --</option>`;
            data.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = item.name;
                targetSelect.appendChild(opt);
            });
            targetSelect.disabled = false;
        })
        .catch(() => {
            targetSelect.innerHTML = '<option>Error loading</option>';
        });
}

// ---- Attributes table ----
function clearAttributesTable() {
    const wrap = document.getElementById('attributes-table-wrap');
    const section = document.getElementById('attributes-section');
    if (wrap) wrap.innerHTML = '<p id="attr-placeholder">Select a Product Type to load attributes.</p>';
    if (section) section.style.display = 'none';
}

function fetchAttributes(productTypeId) {
    const wrap = document.getElementById('attributes-table-wrap');
    const section = document.getElementById('attributes-section');
    if (!wrap) return;

    wrap.innerHTML = '<div class="loading"><span class="spinner"></span> Loading attributes...</div>';
    section.style.display = 'block';

    fetch(BASE_URL + '/ajax/get-attributes.php?product_type_id=' + productTypeId)
        .then(r => r.json())
        .then(attrs => {
            if (!attrs.length) {
                wrap.innerHTML = '<p id="attr-placeholder" style="padding:16px;text-align:center;color:#64748b">No attributes defined for this product type. <a href="' + BASE_URL + '/admin/attributes.php">Add them in Admin</a>.</p>';
                return;
            }
            let html = `<table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Attribute</th>
                        <th>Value <span style="font-weight:400;text-transform:none">(enter specification)</span></th>
                        <th>Unit</th>
                    </tr>
                </thead><tbody>`;
            attrs.forEach((attr, i) => {
                const req = attr.is_required == 1 ? '<span class="attr-required">*</span>' : '';
                const unit = attr.attribute_unit || '';
                let inputHtml;
                if (attr.attribute_type === 'select' && attr.options_list) {
                    const opts = attr.options_list.split(',').map(o => `<option value="${escHtml(o.trim())}">${escHtml(o.trim())}</option>`).join('');
                    inputHtml = `<select name="attr_value[${i}]"><option value="">-- Select --</option>${opts}</select>`;
                } else if (attr.attribute_type === 'number') {
                    inputHtml = `<input type="number" step="any" name="attr_value[${i}]" placeholder="e.g. ${unit ? '0.0' : '0'}" ${attr.is_required ? 'required' : ''}>`;
                } else {
                    inputHtml = `<input type="text" name="attr_value[${i}]" placeholder="Enter value" ${attr.is_required ? 'required' : ''}>`;
                }
                html += `<tr>
                    <td>${i+1}</td>
                    <td>${escHtml(attr.attribute_name)} ${req}
                        <input type="hidden" name="attr_name[${i}]" value="${escHtml(attr.attribute_name)}">
                        <input type="hidden" name="attr_unit[${i}]" value="${escHtml(unit)}">
                        <input type="hidden" name="attr_id[${i}]"   value="${escHtml(attr.id)}">
                    </td>
                    <td>${inputHtml}</td>
                    <td style="color:#64748b">${escHtml(unit)}</td>
                </tr>`;
            });
            html += '</tbody></table>';
            wrap.innerHTML = html;
        })
        .catch(() => { wrap.innerHTML = '<p style="padding:16px;color:red">Failed to load attributes.</p>'; });
}

// ---- Image preview ----
function previewImages(e) {
    const container = document.getElementById('img-previews');
    if (!container) return;
    container.innerHTML = '';
    Array.from(e.target.files).forEach(file => {
        const reader = new FileReader();
        reader.onload = ev => {
            const div = document.createElement('div');
            div.className = 'img-preview-item';
            div.innerHTML = `<img src="${ev.target.result}" alt="preview">`;
            container.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
}

// ---- Utility ----
function getCsrf() {
    return document.querySelector('input[name="csrf_token"]')?.value || '';
}
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ---- Category filter (admin pages) ----
function filterByIndustry(industryId, targetSelectId) {
    const sel = document.getElementById(targetSelectId);
    if (!sel || !industryId) return;
    fetchOptions(BASE_URL + '/ajax/get-categories.php?industry_id=' + industryId, sel);
}

/* ════════════════════════════════════════════════════════════
   RESPONSIVE DASHBOARD ENHANCEMENTS
   ════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {

  /* ── Swipe to close sidebar on mobile ──────────────────── */
  const sidebar = document.getElementById('sidebar');
  if (sidebar) {
    let touchStartX = 0, touchStartY = 0;
    sidebar.addEventListener('touchstart', e => {
      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
    }, { passive: true });
    sidebar.addEventListener('touchend', e => {
      const dx = e.changedTouches[0].clientX - touchStartX;
      const dy = Math.abs(e.changedTouches[0].clientY - touchStartY);
      if (dx < -60 && dy < 40) {
        sidebar.classList.remove('open');
        document.getElementById('sidebar-overlay')?.classList.remove('show');
      }
    }, { passive: true });
  }

  /* ── Table horizontal scroll indicator ─────────────────── */
  document.querySelectorAll('.table-wrapper').forEach(wrap => {
    function checkScroll() {
      const overflows = wrap.scrollWidth > wrap.clientWidth + 2;
      wrap.classList.toggle('has-scroll', overflows);
    }
    checkScroll();
    window.addEventListener('resize', checkScroll, { passive: true });
  });

  /* ── Sticky save button visibility on edit pages ───────── */
  const stickyBtns = document.querySelector('[style*="position:sticky"][style*="bottom"]');
  if (stickyBtns) {
    window.addEventListener('scroll', () => {
      const atBottom = (window.innerHeight + window.scrollY) >= document.body.scrollHeight - 40;
      stickyBtns.style.opacity = atBottom ? '0' : '1';
    }, { passive: true });
  }

  /* ── Mobile: auto-collapse long tables to card view ────── */
  function applyCardTable() {
    if (window.innerWidth > 600) return;
    document.querySelectorAll('table.mobile-card').forEach(tbl => {
      const headers = [...tbl.querySelectorAll('thead th')].map(th => th.textContent.trim());
      tbl.querySelectorAll('tbody tr').forEach(tr => {
        [...tr.querySelectorAll('td')].forEach((td, i) => {
          if (headers[i]) td.setAttribute('data-label', headers[i]);
        });
      });
    });
  }
  applyCardTable();

  /* ── Viewport height fix (mobile browsers) ─────────────── */
  function setVh() {
    document.documentElement.style.setProperty('--vh', (window.innerHeight * 0.01) + 'px');
  }
  setVh();
  window.addEventListener('resize', setVh, { passive: true });

  /* ── Close ms-box dropdowns on outside click ───────────── */
  document.addEventListener('click', e => {
    if (!e.target.closest('.cval-ms')) {
      document.querySelectorAll('.cval-dd.open').forEach(d => d.classList.remove('open'));
      document.querySelectorAll('.cval-trigger.open').forEach(t => t.classList.remove('open'));
    }
    // Only close ms-box if click is truly outside — not inside the box itself
    if (!e.target.closest('.ms-box') && !e.target.closest('.ms-dropdown')) {
      document.querySelectorAll('.ms-box.open').forEach(b => b.classList.remove('open'));
    }
  });

  /* ── Tab overflow scroll arrows ─────────────────────────── */
  document.querySelectorAll('.tab-nav').forEach(nav => {
    nav.style.overflowX = 'auto';
    nav.style.scrollbarWidth = 'none';
  });
});
