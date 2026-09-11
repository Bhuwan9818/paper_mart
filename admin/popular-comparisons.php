<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

// ---- Handle Add / Edit (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $id          = (int)($_POST['id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $subtitle    = trim($_POST['subtitle'] ?? '');
    $categoryId  = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $badgeText   = trim($_POST['badge_text'] ?? 'Popular');
    $isSponsored = !empty($_POST['is_sponsored']) ? 1 : 0;
    $sponsorName = trim($_POST['sponsor_name'] ?? '');
    $sponsorLink = trim($_POST['sponsor_link'] ?? '');
    $sortOrder   = (int)($_POST['sort_order'] ?? 0);
    $status      = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
    
    // Product IDs (array from form)
    $productIdsArr = array_filter(array_map('intval', $_POST['product_ids'] ?? []));
    $productIdsArr = array_values(array_unique($productIdsArr));

    if (empty($title)) {
        flash('error', 'Please enter a comparison title.');
        header('Location: popular-comparisons.php'); exit;
    }

    if (count($productIdsArr) < 2) {
        flash('error', 'Please select at least 2 products to compare.');
        header('Location: popular-comparisons.php'); exit;
    }

    if (count($productIdsArr) > 4) {
        $productIdsArr = array_slice($productIdsArr, 0, 4);
    }

    $productIdsStr = implode(',', $productIdsArr);

    if ($id) {
        $stmt = $pdo->prepare("UPDATE popular_comparisons SET title=?, subtitle=?, category_id=?, product_ids=?, is_sponsored=?, sponsor_name=?, sponsor_link=?, badge_text=?, sort_order=?, status=? WHERE id=?");
        $stmt->execute([$title, $subtitle, $categoryId, $productIdsStr, $isSponsored, $sponsorName, $sponsorLink, $badgeText, $sortOrder, $status, $id]);
        flash('success', 'Popular comparison updated successfully.');
    } else {
        $stmt = $pdo->prepare("INSERT INTO popular_comparisons (title, subtitle, category_id, product_ids, is_sponsored, sponsor_name, sponsor_link, badge_text, sort_order, status) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$title, $subtitle, $categoryId, $productIdsStr, $isSponsored, $sponsorName, $sponsorLink, $badgeText, $sortOrder, $status]);
        flash('success', 'New popular comparison added successfully.');
    }
    header('Location: popular-comparisons.php'); exit;
}

// ---- Handle quick actions (GET) ----
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    match ($_GET['action']) {
        'activate'   => $pdo->prepare("UPDATE popular_comparisons SET status='active' WHERE id=?")->execute([$id]),
        'deactivate' => $pdo->prepare("UPDATE popular_comparisons SET status='inactive' WHERE id=?")->execute([$id]),
        'toggle_sponsor' => (function() use ($pdo, $id) {
            $cur = $pdo->prepare("SELECT is_sponsored FROM popular_comparisons WHERE id=?");
            $cur->execute([$id]);
            $isSp = (int)$cur->fetchColumn();
            $newSp = $isSp ? 0 : 1;
            $pdo->prepare("UPDATE popular_comparisons SET is_sponsored=? WHERE id=?")->execute([$newSp, $id]);
        })(),
        'delete'     => $pdo->prepare("DELETE FROM popular_comparisons WHERE id=?")->execute([$id]),
        'up'   => (function() use ($pdo, $id) {
            $cur = $pdo->prepare("SELECT sort_order FROM popular_comparisons WHERE id=?"); $cur->execute([$id]);
            $curOrder = (int)$cur->fetchColumn();
            $prev = $pdo->prepare("SELECT id,sort_order FROM popular_comparisons WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1");
            $prev->execute([$curOrder]); $p = $prev->fetch();
            if ($p) {
                $pdo->prepare("UPDATE popular_comparisons SET sort_order=? WHERE id=?")->execute([$p['sort_order'], $id]);
                $pdo->prepare("UPDATE popular_comparisons SET sort_order=? WHERE id=?")->execute([$curOrder, $p['id']]);
            }
        })(),
        'down' => (function() use ($pdo, $id) {
            $cur = $pdo->prepare("SELECT sort_order FROM popular_comparisons WHERE id=?"); $cur->execute([$id]);
            $curOrder = (int)$cur->fetchColumn();
            $next = $pdo->prepare("SELECT id,sort_order FROM popular_comparisons WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1");
            $next->execute([$curOrder]); $n = $next->fetch();
            if ($n) {
                $pdo->prepare("UPDATE popular_comparisons SET sort_order=? WHERE id=?")->execute([$n['sort_order'], $id]);
                $pdo->prepare("UPDATE popular_comparisons SET sort_order=? WHERE id=?")->execute([$curOrder, $n['id']]);
            }
        })(),
        default => null
    };
    header('Location: popular-comparisons.php'); exit;
}

// Fetch all popular comparisons
$comparisons = $pdo->query("
    SELECT pc.*, c.name AS category_name 
    FROM popular_comparisons pc 
    LEFT JOIN categories c ON c.id = pc.category_id 
    ORDER BY pc.sort_order ASC, pc.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch categories for filter dropdown
$categories = $pdo->query("SELECT id, name FROM categories WHERE status=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch all active products for the picker modal
$allProducts = $pdo->query("
    SELECT p.id, p.name, p.price_range, c.name AS category_name, u.company, u.name AS vendor_name
    FROM products p
    JOIN users u ON u.id = p.vendor_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.status = 'active'
    ORDER BY p.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Create product lookup dictionary
$productMap = [];
foreach ($allProducts as $ap) {
    $productMap[$ap['id']] = $ap;
}

$pageTitle = 'Popular Comparisons Manager'; 
$activePage = 'popular-comparisons';
include __DIR__ . '/../includes/head.php';
?>
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <h1>Popular Comparisons</h1>
    </div>
    <div class="topbar-right"><?php include __DIR__ . '/../includes/topbar-user-menu.php'; ?></div>
</div>

<div class="content">
    <?= showFlash() ?>
    
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
        <div>
            <h1 style="font-size:22px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px">
                ⚖️ Popular Comparisons 
                <span style="font-size:14px;font-weight:400;color:var(--text-muted)">(<?= count($comparisons) ?>)</span>
            </h1>
            <p style="margin:4px 0 0;font-size:13px;color:var(--text-muted)">
                Curate predefined side-by-side product matchups on the public compare page. Support for sponsored placements and custom badges.
            </p>
        </div>
        <div style="display:flex;gap:10px">
            <a href="<?= BASE_URL ?>/public/compare.php" target="_blank" class="btn btn-outline btn-sm">
                👁️ Preview Public Compare Page
            </a>
            <button class="btn btn-primary btn-sm" onclick="openComparisonModal()">
                + Add Popular Comparison
            </button>
        </div>
    </div>

    <!-- Info Banner -->
    <div class="card" style="padding:16px 20px;margin-bottom:20px;background:linear-gradient(135deg,#f0f9ff 0%,#e0f2fe 100%);border:1px solid #bae6fd">
        <div style="display:flex;align-items:flex-start;gap:12px">
            <span style="font-size:20px">💡</span>
            <div style="font-size:13px;color:#0369a1;line-height:1.5">
                <strong>How Popular Comparisons Work:</strong>
                These pre-configured matchups appear prominently on the public <code>/public/compare.php</code> page in a dedicated showcase section (similar to CardDekho popular comparison cards). 
                When a buyer clicks <strong>"Compare Now"</strong> on any card, the system automatically loads the exact grades into the side-by-side specification table.
                You can mark entries as <strong>Sponsored</strong> with mill sponsorship attribution to monetize top placement.
            </div>
        </div>
    </div>

    <div class="card">
        <?php if ($comparisons): ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:50px">Order</th>
                        <th style="width:260px">Comparison Title & Badge</th>
                        <th>Compared Products (2–4 Grades)</th>
                        <th style="width:170px">Sponsorship</th>
                        <th style="width:90px">Status</th>
                        <th style="width:140px;text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($comparisons as $i => $c): 
                    $pids = array_filter(array_map('intval', explode(',', $c['product_ids'])));
                ?>
                <tr>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:2px;font-size:12px">
                            <a href="?action=up&id=<?= $c['id'] ?>" style="<?= $i===0 ? 'opacity:.3;pointer-events:none' : '' ?>" title="Move up">▲</a>
                            <a href="?action=down&id=<?= $c['id'] ?>" style="<?= $i===count($comparisons)-1 ? 'opacity:.3;pointer-events:none' : '' ?>" title="Move down">▼</a>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:600;font-size:14px;color:var(--text-dark);line-height:1.3">
                            <?= sanitize($c['title']) ?>
                        </div>
                        <?php if ($c['subtitle']): ?>
                            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;line-height:1.3">
                                <?= sanitize($c['subtitle']) ?>
                            </div>
                        <?php endif; ?>
                        <div style="margin-top:6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                            <?php if ($c['badge_text']): ?>
                                <span class="badge" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;font-size:10.5px">
                                    🏷️ <?= sanitize($c['badge_text']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($c['category_name']): ?>
                                <span class="badge" style="background:#e0e7ff;color:#3730a3;font-size:10.5px">
                                    📁 <?= sanitize($c['category_name']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                            <?php foreach ($pids as $pidIndex => $pid): 
                                $pData = $productMap[$pid] ?? null;
                            ?>
                                <?php if ($pidIndex > 0): ?>
                                    <span style="font-size:11px;font-weight:800;color:var(--brand);background:#f8fafc;border:1px solid #e2e8f0;padding:2px 5px;border-radius:4px">VS</span>
                                <?php endif; ?>
                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:5px 8px;font-size:12px;max-width:210px">
                                    <?php if ($pData): ?>
                                        <div style="font-weight:600;color:var(--text-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= sanitize($pData['name']) ?>">
                                            📦 <?= sanitize($pData['name']) ?>
                                        </div>
                                        <div style="font-size:10.5px;color:var(--text-muted)">
                                            🏭 <?= sanitize($pData['company'] ?: $pData['vendor_name']) ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#ef4444">Product #<?= $pid ?> (Missing/Inactive)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($c['is_sponsored']): ?>
                            <div style="display:inline-flex;align-items:center;gap:4px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:3px 8px;border-radius:100px;font-size:11px;font-weight:700">
                                <span>⭐ Sponsored</span>
                            </div>
                            <?php if ($c['sponsor_name']): ?>
                                <div style="font-size:11.5px;color:var(--text-muted);margin-top:3px">
                                    by <strong><?= sanitize($c['sponsor_name']) ?></strong>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-secondary" style="font-size:11px">Organic</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['status'] === 'active'): ?>
                            <a href="?action=deactivate&id=<?= $c['id'] ?>" class="badge badge-success" title="Click to deactivate">Active</a>
                        <?php else: ?>
                            <a href="?action=activate&id=<?= $c['id'] ?>" class="badge badge-secondary" title="Click to activate">Inactive</a>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right">
                        <div class="td-actions" style="justify-content:flex-end">
                            <button class="btn btn-outline btn-xs" onclick='openComparisonModal(<?= json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✏️ Edit</button>
                            <a href="?action=delete&id=<?= $c['id'] ?>" class="btn btn-danger btn-xs" onclick="return confirm('Delete this popular comparison?')">🗑</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="empty-state" style="padding:50px 20px;text-align:center">
                <div class="empty-state-icon" style="font-size:42px;margin-bottom:12px">⚖️</div>
                <h3 style="font-size:18px;font-weight:700">No popular comparisons yet</h3>
                <p style="color:var(--text-muted);max-width:400px;margin:0 auto 16px auto;font-size:13.5px">
                    Create predefined side-by-side product pairings for paper & packaging grades to guide buyers on the compare page.
                </p>
                <button class="btn btn-primary btn-sm" onclick="openComparisonModal()">+ Add First Comparison</button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add / Edit Modal -->
<div id="comparison-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;max-width:640px;width:94%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 25px -5px rgba(0,0,0,.15)">
    <div style="padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <h3 id="comp-modal-title" style="margin:0;font-size:17px;font-weight:700">Add Popular Comparison</h3>
      <button onclick="closeComparisonModal()" style="border:none;background:none;font-size:20px;cursor:pointer;color:var(--text-muted)">✕</button>
    </div>

    <form id="comparison-form" method="POST" style="padding:22px 24px">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="id" id="comp-id" value="">

      <div class="form-group">
        <label class="form-label">Comparison Title <span class="req">*</span></label>
        <input type="text" name="title" id="comp-title" class="form-control" required maxlength="255" placeholder="e.g. Bleached vs Unbleached Carry Bag Kraft Paper">
      </div>

      <div class="form-group">
        <label class="form-label">Subtitle / Description</label>
        <textarea name="subtitle" id="comp-subtitle" class="form-control" rows="2" maxlength="300" placeholder="e.g. Side-by-side analysis of burst factor, tensile rigidity, and Cobb sizing for shopping bags..."></textarea>
      </div>

      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <div class="form-group" style="flex:1;min-width:200px">
          <label class="form-label">Category Filter (Optional)</label>
          <select name="category_id" id="comp-category-id" class="form-control">
            <option value="">All Categories</option>
            <?php foreach($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>"><?= sanitize($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="flex:1;min-width:200px">
          <label class="form-label">Badge Tag</label>
          <input type="text" name="badge_text" id="comp-badge-text" class="form-control" placeholder="e.g. Popular 2026, Top Matchup, High Strength" value="Popular">
        </div>
      </div>

      <!-- Compared Products Picker Section (2 to 4 products) -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:16px 0">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
          <label class="form-label" style="margin:0;font-weight:700;color:var(--text-dark)">
            Compared Products (2 to 4) <span class="req">*</span>
          </label>
          <span style="font-size:11.5px;color:var(--text-muted)">Selected: <strong id="selected-count">0</strong>/4</span>
        </div>

        <!-- Selected Products Container -->
        <div id="selected-products-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
            <!-- Dynamic product item chips inserted here -->
        </div>

        <!-- Product Search / Add Bar -->
        <div style="position:relative">
            <div style="display:flex;gap:8px">
                <input type="text" id="product-picker-search" class="form-control" placeholder="Search product name or mill name to add..." autocomplete="off">
            </div>
            <div id="product-picker-results" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:20;background:#fff;border:1px solid #cbd5e1;border-radius:6px;box-shadow:0 10px 15px -3px rgba(0,0,0,.1);max-height:220px;overflow-y:auto;margin-top:4px">
                <!-- Filtered search results appear here -->
            </div>
        </div>
        <p style="font-size:11.5px;color:var(--text-muted);margin:6px 0 0 0">
            Type to search active catalogue products and click to attach them. Minimum 2, maximum 4 products per comparison.
        </p>
      </div>

      <!-- Sponsorship Section -->
      <div style="background:#fffbeb;border:1px solid #fef3c7;border-radius:8px;padding:16px;margin-bottom:16px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
            <label style="display:flex;align-items:center;gap:8px;font-weight:700;font-size:13.5px;color:#92400e;cursor:pointer;margin:0">
                <input type="checkbox" name="is_sponsored" id="comp-is-sponsored" value="1" onchange="toggleSponsorFields(this.checked)">
                ⭐ Mark as Sponsored Comparison
            </label>
            <span style="font-size:11.5px;color:#b45309">Admin monetization tag</span>
        </div>

        <div id="sponsor-fields" style="display:none">
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <div class="form-group" style="flex:1;min-width:200px;margin-bottom:8px">
                    <label class="form-label" style="color:#92400e">Sponsor Mill / Brand Name</label>
                    <input type="text" name="sponsor_name" id="comp-sponsor-name" class="form-control" placeholder="e.g. Century Pulp & Paper">
                </div>
                <div class="form-group" style="flex:1;min-width:200px;margin-bottom:8px">
                    <label class="form-label" style="color:#92400e">Sponsor Link / Profile URL (Optional)</label>
                    <input type="text" name="sponsor_link" id="comp-sponsor-link" class="form-control" placeholder="e.g. /public/vendor-profile.php?id=3">
                </div>
            </div>
        </div>
      </div>

      <div style="display:flex;gap:12px">
        <div class="form-group" style="flex:1">
          <label class="form-label">Sort Order</label>
          <input type="number" name="sort_order" id="comp-sort" class="form-control" value="0">
        </div>
        <div class="form-group" style="flex:1">
          <label class="form-label">Status</label>
          <select name="status" id="comp-status" class="form-control">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:20px">
        <button type="button" class="btn btn-outline" style="flex:1" onclick="closeComparisonModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2">Save Popular Comparison</button>
      </div>
    </form>
  </div>
</div>

<script>
// Catalog data for product picker
const catalogProducts = <?= json_encode($allProducts, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
let selectedProductIds = [];

function toggleSponsorFields(isSponsored) {
    document.getElementById('sponsor-fields').style.display = isSponsored ? 'block' : 'none';
}

function renderSelectedProducts() {
    const list = document.getElementById('selected-products-list');
    const countEl = document.getElementById('selected-count');
    countEl.textContent = selectedProductIds.length;
    
    if (selectedProductIds.length === 0) {
        list.innerHTML = '<div style="font-size:12.5px;color:var(--text-muted);font-style:italic;padding:8px;background:#fff;border-radius:6px;border:1px dashed #cbd5e1;text-align:center">No products selected yet. Search below to add 2–4 products.</div>';
        return;
    }

    let html = '';
    selectedProductIds.forEach((pid, index) => {
        const prod = catalogProducts.find(p => p.id == pid);
        const name = prod ? prod.name : 'Product #' + pid;
        const comp = prod ? (prod.company || prod.vendor_name) : '';
        const cat = prod ? prod.category_name : '';
        
        html += `
            <div style="display:flex;align-items:center;justify-content:space-between;background:#fff;border:1px solid #cbd5e1;border-radius:6px;padding:8px 12px;gap:8px">
                <div style="display:flex;align-items:center;gap:10px;overflow:hidden">
                    <span style="font-weight:700;font-size:11px;color:var(--brand);background:#e0f2fe;padding:2px 6px;border-radius:4px">#${index+1}</span>
                    <input type="hidden" name="product_ids[]" value="${pid}">
                    <div style="overflow:hidden">
                        <div style="font-weight:600;font-size:13px;color:var(--text-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escHtml(name)}</div>
                        <div style="font-size:11px;color:var(--text-muted)">${comp ? '🏭 ' + escHtml(comp) : ''} ${cat ? '• ' + escHtml(cat) : ''}</div>
                    </div>
                </div>
                <button type="button" onclick="removeProductFromComparison(${pid})" style="border:none;background:none;color:#ef4444;font-size:16px;cursor:pointer;padding:4px" title="Remove">✕</button>
            </div>
        `;
    });
    list.innerHTML = html;
}

function addProductToComparison(pid) {
    if (selectedProductIds.length >= 4) {
        alert('You can compare a maximum of 4 products.');
        return;
    }
    if (selectedProductIds.includes(pid)) {
        return;
    }
    selectedProductIds.push(pid);
    renderSelectedProducts();
    document.getElementById('product-picker-search').value = '';
    document.getElementById('product-picker-results').style.display = 'none';
}

function removeProductFromComparison(pid) {
    selectedProductIds = selectedProductIds.filter(id => id != pid);
    renderSelectedProducts();
}

// Product search typeahead logic
const searchInput = document.getElementById('product-picker-search');
const resultsBox = document.getElementById('product-picker-results');

searchInput.addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    if (!q) {
        resultsBox.style.display = 'none';
        resultsBox.innerHTML = '';
        return;
    }

    const matches = catalogProducts.filter(p => {
        if (selectedProductIds.includes(parseInt(p.id))) return false;
        const nameMatch = p.name && p.name.toLowerCase().includes(q);
        const compMatch = p.company && p.company.toLowerCase().includes(q);
        const catMatch = p.category_name && p.category_name.toLowerCase().includes(q);
        return nameMatch || compMatch || catMatch;
    }).slice(0, 10);

    if (matches.length === 0) {
        resultsBox.innerHTML = '<div style="padding:10px 14px;font-size:12px;color:var(--text-muted)">No matching products found</div>';
        resultsBox.style.display = 'block';
        return;
    }

    resultsBox.innerHTML = matches.map(p => `
        <div class="picker-item" onclick="addProductToComparison(${p.id})" style="padding:9px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
            <div>
                <div style="font-weight:600;font-size:13px;color:var(--text-dark)">${escHtml(p.name)}</div>
                <div style="font-size:11px;color:var(--text-muted)">🏭 ${escHtml(p.company || p.vendor_name || 'Mill')} • ${escHtml(p.category_name || '')}</div>
            </div>
            <span style="font-size:11px;font-weight:600;color:var(--brand);background:#f0f9ff;padding:3px 8px;border-radius:4px">+ Add</span>
        </div>
    `).join('');
    resultsBox.style.display = 'block';
});

// Close picker on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('#product-picker-search') && !e.target.closest('#product-picker-results')) {
        resultsBox.style.display = 'none';
    }
});

function openComparisonModal(data) {
    const modal = document.getElementById('comparison-modal');
    document.getElementById('comparison-form').reset();
    selectedProductIds = [];

    if (data) {
        document.getElementById('comp-modal-title').textContent = 'Edit Popular Comparison';
        document.getElementById('comp-id').value = data.id;
        document.getElementById('comp-title').value = data.title || '';
        document.getElementById('comp-subtitle').value = data.subtitle || '';
        document.getElementById('comp-category-id').value = data.category_id || '';
        document.getElementById('comp-badge-text').value = data.badge_text || 'Popular';
        
        const isSp = parseInt(data.is_sponsored) === 1;
        document.getElementById('comp-is-sponsored').checked = isSp;
        document.getElementById('comp-sponsor-name').value = data.sponsor_name || '';
        document.getElementById('comp-sponsor-link').value = data.sponsor_link || '';
        toggleSponsorFields(isSp);

        document.getElementById('comp-sort').value = data.sort_order || 0;
        document.getElementById('comp-status').value = data.status || 'active';

        if (data.product_ids) {
            selectedProductIds = data.product_ids.split(',').map(id => parseInt(id.trim())).filter(id => !isNaN(id));
        }
    } else {
        document.getElementById('comp-modal-title').textContent = 'Add Popular Comparison';
        document.getElementById('comp-id').value = '';
        document.getElementById('comp-is-sponsored').checked = false;
        toggleSponsorFields(false);
        document.getElementById('comp-badge-text').value = 'Popular';
    }

    renderSelectedProducts();
    modal.style.display = 'flex';
}

function closeComparisonModal() {
    document.getElementById('comparison-modal').style.display = 'none';
}

function escHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// Form validation before submit
document.getElementById('comparison-form').addEventListener('submit', function(e) {
    if (selectedProductIds.length < 2) {
        e.preventDefault();
        alert('Please select at least 2 products for this comparison.');
        return false;
    }
});
</script>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
