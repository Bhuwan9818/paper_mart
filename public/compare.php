<?php
$pageTitle   = 'Compare Products & Technical Specifications — PaperMart';
$currentPage = 'compare';
include __DIR__.'/includes/header.php';

// Get compare items from session
$sessionKey = session_id();
try {
    $stmt=$pdo->prepare("SELECT p.*,u.company,u.name AS vname,u.city AS vcity,u.state AS vstate,vp.is_verified,c.name AS cname,pt.name AS tname,i.name AS iname FROM compare_sessions cs JOIN products p ON p.id=cs.product_id JOIN users u ON u.id=p.vendor_id LEFT JOIN vendor_profiles vp ON vp.vendor_id=p.vendor_id LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN product_types pt ON pt.id=p.product_type_id LEFT JOIN industries i ON i.id=p.industry_id WHERE cs.session_key=? AND p.status='active' ORDER BY cs.added_at ASC");
    $stmt->execute([$sessionKey]); $products=$stmt->fetchAll();
} catch(Exception $e) { $products=[]; }

// Collect attributes & TDS files
$allAttrNames=[];
$productAttrs=[];
$productTds=[];
$comparedIds = [];
$categoryIds = [];
$industryIds = [];

foreach($products as $p){
    $comparedIds[] = (int)$p['id'];
    if ($p['category_id']) $categoryIds[] = (int)$p['category_id'];
    if ($p['industry_id']) $industryIds[] = (int)$p['industry_id'];

    $as=$pdo->prepare("SELECT * FROM product_attributes WHERE product_id=? ORDER BY sort_order");
    $as->execute([$p['id']]); $list=$as->fetchAll();
    $productAttrs[$p['id']]=[];
    $productTds[$p['id']]=[];
    foreach($list as $a){
        $an = strtolower(trim($a['attribute_name'] ?? ''));
        if ($an==='__tds' || stripos($an,'tds')!==false) {
            $fname = basename(trim($a['attribute_value'] ?? ''));
            if ($fname && file_exists(__DIR__.'/../assets/tds/'.$fname)) $productTds[$p['id']][] = $fname;
            continue; // excluded from spec comparison rows
        }
        $valFormatted = trim($a['attribute_value'] ?? '');
        if ($valFormatted !== '' && !empty($a['attribute_unit'])) {
            $valFormatted .= ' ' . trim($a['attribute_unit']);
        }
        $productAttrs[$p['id']][$a['attribute_name']] = $valFormatted;
        $allAttrNames[$a['attribute_name']]=true;
    }
    $productTds[$p['id']] = array_values(array_unique($productTds[$p['id']]));
}
$allAttrNames=array_keys($allAttrNames);

// Categorize attributes into intuitive engineering groups
$specGroups = [
    'Physical & Basis Weight' => ['GSM', 'Basis Weight', 'Grammage', 'Caliper', 'Thickness', 'Bulk', 'Cobb', 'Cobb 60', 'Cobb 1800', 'Moisture', 'Moisture Content', 'Density'],
    'Strength & Mechanical'   => ['Burst Factor', 'BF', 'Burst Index', 'Bursting Strength', 'RCT', 'Ring Crush', 'Tensile Strength', 'Tensile Index', 'Tear Factor', 'Tear Index', 'Tearing Resistance', 'Folding Endurance', 'Stiffness', 'Taber Stiffness', 'SCT', 'Short Span Compressive'],
    'Optical & Surface'       => ['Brightness', 'ISO Brightness', 'Opacity', 'Smoothness', 'Roughness', 'Bendtsen Roughness', 'Gurley Porosity', 'Gloss', 'Whiteness', 'CIE Whiteness', 'Color / Shade'],
];

$categorizedAttrs = [
    'Physical & Basis Weight' => [],
    'Strength & Mechanical'   => [],
    'Optical & Surface'       => [],
    'Additional Technical Specs' => []
];

foreach ($allAttrNames as $attr) {
    $placed = false;
    foreach ($specGroups as $gName => $keywords) {
        foreach ($keywords as $kw) {
            if (stripos($attr, $kw) !== false) {
                $categorizedAttrs[$gName][] = $attr;
                $placed = true;
                break 2;
            }
        }
    }
    if (!$placed) {
        $categorizedAttrs['Additional Technical Specs'][] = $attr;
    }
}

// Recommended alternative products
$recommendedProducts = [];
try {
    if (!empty($comparedIds)) {
        $inClause = implode(',', array_fill(0, count($comparedIds), '?'));
        $catParams = !empty($categoryIds) ? array_unique($categoryIds) : [0];
        $catInClause = implode(',', array_fill(0, count($catParams), '?'));
        
        $sql = "SELECT p.*, u.company, u.name AS vname, vp.is_verified, c.name AS cname, pt.name AS tname 
                FROM products p 
                JOIN users u ON u.id=p.vendor_id 
                LEFT JOIN vendor_profiles vp ON vp.vendor_id=p.vendor_id 
                JOIN categories c ON c.id=p.category_id 
                LEFT JOIN product_types pt ON pt.id=p.product_type_id 
                WHERE p.status='active' AND p.id NOT IN ($inClause) AND p.category_id IN ($catInClause) 
                ORDER BY p.views_count DESC, p.id DESC LIMIT 4";
        $recStmt = $pdo->prepare($sql);
        $recStmt->execute(array_merge($comparedIds, array_values($catParams)));
        $recommendedProducts = $recStmt->fetchAll();
    }
    if (empty($recommendedProducts)) {
        $recStmt = $pdo->prepare("SELECT p.*, u.company, u.name AS vname, vp.is_verified, c.name AS cname, pt.name AS tname 
                                  FROM products p 
                                  JOIN users u ON u.id=p.vendor_id 
                                  LEFT JOIN vendor_profiles vp ON vp.vendor_id=p.vendor_id 
                                  JOIN categories c ON c.id=p.category_id 
                                  LEFT JOIN product_types pt ON pt.id=p.product_type_id 
                                  WHERE p.status='active' " . (!empty($comparedIds) ? "AND p.id NOT IN (" . implode(',', $comparedIds) . ")" : "") . " 
                                  ORDER BY p.views_count DESC, p.id DESC LIMIT 4");
        $recStmt->execute();
        $recommendedProducts = $recStmt->fetchAll();
    }
} catch(Exception $e) {
    $recommendedProducts = [];
}

// Industries for Add Product picker
$industries = $pdo->query(
    "SELECT DISTINCT i.id, i.name
     FROM industries i
     JOIN products p ON p.industry_id = i.id AND p.status = 'active'
     WHERE i.status = 1
     ORDER BY i.sort_order, i.name"
)->fetchAll();
?>

<!-- Breadcrumb -->
<div style="background:var(--n50);padding:14px 0;border-bottom:1px solid var(--n200)">
  <div class="container" style="font-size:13px;color:var(--n500);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div>
      <a href="<?= BASE_URL ?>/public/index.php" style="color:var(--brand-2)">Home</a> › 
      <a href="<?= BASE_URL ?>/public/products.php" style="color:var(--brand-2)">Marketplace</a> › 
      <span>Compare Products</span>
    </div>
    <?php if ($products): ?>
    <div style="font-size:12px;color:var(--n600);font-weight:600">
      Comparing <strong style="color:var(--brand)"><?= count($products) ?></strong> of 4 products
    </div>
    <?php endif; ?>
  </div>
</div>

<section class="compact" style="padding-top:28px;padding-bottom:50px">
  <div class="container">
    <div class="section-head" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:20px">
      <div>
        <div class="section-label">Technical Side-by-Side Analysis</div>
        <h1 style="font-size:28px;color:var(--n900);font-weight:800;letter-spacing:-0.02em">Paper & Board Grade Comparison</h1>
        <p style="color:var(--n500);margin-top:6px;font-size:14.5px">Evaluate key engineering parameters, burst factor, moisture resistance, and mill commercial terms to select the ideal substrate.</p>
      </div>
      
      <?php if ($products): ?>
      <div class="cmp-controls-bar">
        <label class="diff-toggle-wrap" title="Highlight parameters with differing values across compared products">
          <input type="checkbox" id="diff-toggle" onchange="toggleDiffHighlights(this.checked)">
          <span style="font-weight:600;font-size:13px;color:var(--n700)">⚡ Highlight Differences</span>
        </label>
        <button type="button" class="btn btn-outline btn-sm" onclick="window.print()" title="Print or save as PDF">
          🖨️ Print / PDF
        </button>
        <button type="button" class="btn btn-outline btn-sm" onclick="shareComparison()" title="Copy comparison link">
          🔗 Share
        </button>
        <button type="button" class="btn btn-outline btn-sm" onclick="clearComparePage()" style="color:#b91c1c;border-color:#fca5a5" title="Clear all compared items">
          ✕ Clear All
        </button>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!$products): ?>
      <div class="empty-state" style="padding:60px 20px;text-align:center;background:#fff;border-radius:var(--r-md);box-shadow:var(--shadow-sm);border:1px dashed var(--n300)">
        <div class="empty-icon" style="font-size:54px;margin-bottom:12px">⚖️</div>
        <h3 style="font-size:20px;font-weight:700;color:var(--n800)">No Products in Comparison</h3>
        <p style="color:var(--n500);max-width:440px;margin:0 auto 20px auto;font-size:14px">Browse paper & packaging grades or click "+ Add Product" to compare up to 4 grades side-by-side with full TDS specifications.</p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
          <button type="button" class="btn btn-primary" onclick="openAddProductPicker()">+ Add Product to Compare</button>
          <a href="<?= BASE_URL ?>/public/products.php" class="btn btn-outline">Explore Marketplace</a>
        </div>
      </div>
    <?php elseif(count($products)<2): ?>
      <div class="site-alert site-alert-info" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px">
        <div>
          <span>ℹ️</span> <strong>Tip:</strong> Add at least 2 products to analyze differences side-by-side. You have added <strong>1 product</strong>.
        </div>
        <button type="button" class="btn btn-sm btn-primary" onclick="openAddProductPicker()">+ Add 2nd Product</button>
      </div>
    <?php endif; ?>

    <?php if (count($products)>=1): ?>
    <div class="compare-table-container" style="overflow-x:auto;margin-top:10px;background:#fff;border-radius:var(--r-md);box-shadow:var(--shadow-sm);border:1px solid var(--n200)">
      <table class="compare-table" id="main-compare-table" style="width:100%;border-collapse:collapse">
        <thead>
          <tr>
            <th style="min-width:210px;width:220px;background:var(--n50);vertical-align:bottom;padding:18px 16px;border-bottom:2px solid var(--n200)">
              <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:var(--n500);font-weight:700">Specification</div>
              <div style="font-size:16px;font-weight:800;color:var(--brand);margin-top:2px">Product Details</div>
            </th>
            <?php foreach($products as $p): ?>
            <th style="min-width:240px;vertical-align:top;padding:16px;border-bottom:2px solid var(--n200);border-left:1px solid var(--n200)">
              <?php
                $imgs=array_filter(explode(',',$p['images']??''));
                $img=reset($imgs)?UPLOAD_URL.trim(reset($imgs)):'';
              ?>
              <div style="position:relative;margin-bottom:10px">
                <?php if($img): ?>
                  <img src="<?= sH($img) ?>" class="compare-img" alt="<?= sH($p['name']) ?>" style="height:150px;width:100%;object-fit:cover;border-radius:var(--r-sm);border:1px solid var(--n200)">
                <?php else: ?>
                  <div style="height:150px;background:var(--n50);border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;font-size:42px;border:1px solid var(--n200)">📦</div>
                <?php endif; ?>
                <button type="button" onclick="removeFromComparePage(<?= $p['id'] ?>)" title="Remove from comparison" 
                        style="position:absolute;top:6px;right:6px;background:rgba(255,255,255,0.9);border:1px solid var(--n300);border-radius:50%;width:26px;height:26px;font-size:13px;color:#b91c1c;cursor:pointer;display:flex;align-items:center;justify-content:center">✕</button>
              </div>

              <a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>" style="font-family:'Poppins',sans-serif;font-size:14.5px;font-weight:700;color:var(--n900);display:block;line-height:1.35;margin-bottom:6px"><?= sH($p['name']) ?></a>
              <div style="font-size:12px;color:var(--n500);margin-bottom:4px"><?= sH($p['cname']) ?><?= $p['tname'] ? ' › '.sH($p['tname']) : '' ?></div>
              <div style="font-size:12.5px;font-weight:600;color:var(--brand);margin-bottom:12px;display:flex;align-items:center;gap:4px">
                🏭 <?= sH($p['company'] ?: $p['vname']) ?>
                <?php if($p['is_verified']): ?><span title="Verified Verified Manufacturer" style="color:var(--success,#16a34a);font-weight:bold">✓</span><?php endif; ?>
              </div>

              <div style="display:flex;flex-direction:column;gap:6px">
                <?php foreach ($productTds[$p['id']] ?? [] as $tdsIdx => $tdsFile): ?>
                <a href="<?= BASE_URL ?>/public/download-tds.php?file=<?= urlencode($tdsFile) ?>" target="_blank"
                   class="btn btn-outline btn-sm btn-full" style="color:#9d174d;border-color:#f9a8d4;font-size:12px;padding:6px 8px">
                  📄 Download TDS<?= count($productTds[$p['id']])>1 ? ' #'.($tdsIdx+1) : '' ?>
                </a>
                <?php endforeach; ?>
                <button class="btn btn-accent btn-sm btn-full" onclick="openEnquiryModal(<?= $p['id'] ?>,<?= $p['vendor_id'] ?>,'<?= sH($p['name']) ?>')" style="font-size:12.5px;padding:7px 10px">📩 Request Quote</button>
              </div>
            </th>
            <?php endforeach; ?>

            <?php if(count($products)<4): ?>
            <th style="min-width:180px;vertical-align:middle;text-align:center;padding:24px 16px;border-left:1px dashed var(--n300);background:var(--n50)">
              <button type="button" onclick="openAddProductPicker()" style="display:flex;flex-direction:column;align-items:center;gap:10px;color:var(--brand);background:none;border:2px dashed var(--brand);border-radius:var(--r-md);padding:24px 16px;cursor:pointer;width:100%;transition:all .2s">
                <span style="font-size:32px;line-height:1">+</span>
                <span style="font-size:13px;font-weight:700">Add Another Grade</span>
                <span style="font-size:11px;color:var(--n500)">(Up to 4 products)</span>
              </button>
            </th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <!-- ── GROUP 1: Commercial & Basic Overview ── -->
          <tr class="spec-group-header" style="background:#f1f5f9;font-weight:700;color:var(--brand)">
            <td colspan="<?= count($products) + (count($products)<4 ? 2 : 1) ?>" style="padding:10px 16px;font-size:13px;letter-spacing:0.02em;text-transform:uppercase;border-top:2px solid var(--n200);border-bottom:1px solid var(--n200)">
              📋 Commercial Terms & Mill Details
            </td>
          </tr>

          <?php
            // Helper function to render a table row with difference checking
            function renderCompareRow($label, $values, $maxCount, $highlightBest = false, $unit = '') {
                $cleanVals = array_map(function($v) { return trim(strip_tags((string)$v)); }, $values);
                $uniqueVals = array_unique(array_filter($cleanVals, function($v) { return $v !== '' && $v !== '—'; }));
                $hasDiff = count($uniqueVals) > 1;

                // Detect numeric best if requested
                $bestIdx = null;
                if ($highlightBest && count($uniqueVals) > 1) {
                    $nums = [];
                    foreach ($values as $idx => $val) {
                        $n = preg_replace('/[^0-9.]/', '', (string)$val);
                        if (is_numeric($n)) $nums[$idx] = (float)$n;
                    }
                    if (!empty($nums)) {
                        $maxN = max($nums);
                        foreach ($nums as $idx => $n) {
                            if ($n == $maxN) { $bestIdx = $idx; break; }
                        }
                    }
                }

                echo '<tr class="cmp-row" data-has-diff="'.($hasDiff ? '1' : '0').'">';
                echo '<td style="font-weight:600;color:var(--n800);background:var(--n50);padding:12px 16px;font-size:13px;border-bottom:1px solid var(--n200)">'.sH($label).'</td>';
                
                foreach ($values as $idx => $val) {
                    $isBest = ($bestIdx !== null && $bestIdx === $idx);
                    $displayVal = ($val !== '' && $val !== null) ? sH($val) : '<span style="color:var(--n400)">—</span>';
                    echo '<td class="'.($isBest ? 'best ' : '').($hasDiff ? 'has-diff-val' : '').'" style="padding:12px 16px;font-size:13px;border-left:1px solid var(--n200);border-bottom:1px solid var(--n200);text-align:center">';
                    echo $displayVal;
                    if ($isBest) echo ' <span class="badge badge-green" style="font-size:9.5px;padding:2px 6px;margin-left:4px;vertical-align:middle">Best</span>';
                    echo '</td>';
                }
                if ($maxCount < 4) {
                    echo '<td style="border-left:1px solid var(--n200);border-bottom:1px solid var(--n200);background:var(--n50)"></td>';
                }
                echo '</tr>';
            }

            // Price range
            $prices = array_map(function($p) { return $p['price_range'] ? '₹ '.trim($p['price_range']) : '—'; }, $products);
            renderCompareRow('Price Range', $prices, count($products));

            // Min order
            $moqs = array_map(function($p) { return $p['min_order_qty'] ? trim($p['min_order_qty']) : '—'; }, $products);
            renderCompareRow('Minimum Order Qty', $moqs, count($products));

            // Vendor
            $vendors = array_map(function($p) { 
                $name = $p['company'] ?: $p['vname'];
                if ($p['is_verified']) $name .= ' (Verified)';
                return $name; 
            }, $products);
            renderCompareRow('Manufacturer / Mill', $vendors, count($products));

            // Location
            $locs = array_map(function($p) { 
                $loc = array_filter([$p['vcity']??'', $p['vstate']??'']);
                return !empty($loc) ? implode(', ', $loc) : 'India'; 
            }, $products);
            renderCompareRow('Origin / Location', $locs, count($products));

            // Product Type
            $types = array_map(function($p) { return $p['tname'] ?: '—'; }, $products);
            renderCompareRow('Product Grade / Type', $types, count($products));
          ?>

          <!-- ── SPEC GROUPS ── -->
          <?php foreach ($categorizedAttrs as $groupTitle => $attrList): ?>
            <?php if (!empty($attrList)): ?>
            <tr class="spec-group-header" style="background:#f8fafc;font-weight:700;color:var(--brand)">
              <td colspan="<?= count($products) + (count($products)<4 ? 2 : 1) ?>" style="padding:10px 16px;font-size:13px;letter-spacing:0.02em;text-transform:uppercase;border-top:2px solid var(--n200);border-bottom:1px solid var(--n200)">
                ⚙️ <?= sH($groupTitle) ?>
              </td>
            </tr>
            <?php foreach ($attrList as $attrName): 
                $attrVals = array_map(function($p) use ($productAttrs, $attrName) {
                    return $productAttrs[$p['id']][$attrName] ?? '—';
                }, $products);
                renderCompareRow($attrName, $attrVals, count($products), true);
            endforeach; ?>
            <?php endif; ?>
          <?php endforeach; ?>

          <!-- ── Action Row ── -->
          <tr style="background:var(--n50)">
            <td style="font-weight:700;color:var(--n800);padding:16px;border-top:2px solid var(--n200)">Next Action</td>
            <?php foreach($products as $p): ?>
            <td style="padding:16px;border-top:2px solid var(--n200);border-left:1px solid var(--n200);text-align:center">
              <div style="display:flex;flex-direction:column;gap:6px">
                <a href="<?= BASE_URL ?>/public/product.php?id=<?= $p['id'] ?>" class="btn btn-primary btn-sm btn-full" style="font-size:12.5px">View Product Details</a>
                <button class="btn btn-accent btn-sm btn-full" onclick="openEnquiryModal(<?= $p['id'] ?>,<?= $p['vendor_id'] ?>,'<?= sH($p['name']) ?>')" style="font-size:12.5px">📩 Send RFQ</button>
              </div>
            </td>
            <?php endforeach; ?>
            <?php if(count($products)<4): ?><td style="border-top:2px solid var(--n200);border-left:1px solid var(--n200)"></td><?php endif; ?>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Multi-Vendor RFQ Section -->
    <div class="cmp-multi-rfq" style="margin-top:36px;background:linear-gradient(135deg,#0a192f 0%,#1e3a5f 100%);border-radius:var(--r-md);padding:32px;color:#fff;box-shadow:var(--shadow-md)">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px">
        <div style="max-width:680px">
          <span style="background:rgba(230,126,34,0.25);color:#f39c12;border:1px solid rgba(243,156,18,0.4);font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:100px;text-transform:uppercase;letter-spacing:0.04em">⚡ Consolidated Multi-Mill RFQ</span>
          <h3 style="font-size:22px;font-weight:800;margin-top:10px;color:#fff">Send 1 Unified RFQ to All Compared Manufacturers</h3>
          <p style="color:rgba(255,255,255,0.75);font-size:14px;margin-top:6px;line-height:1.5">Receive competitive rate quotes and technical delivery schedules simultaneously without re-entering your specifications for each mill.</p>
          <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px">
            <?php foreach($products as $p): ?>
            <span style="background:rgba(255,255,255,0.12);padding:4px 10px;border-radius:6px;font-size:12px;display:inline-flex;align-items:center;gap:6px">
              🏭 <?= sH($p['company'] ?: $p['vname']) ?> (<?= sH($p['name']) ?>)
            </span>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <button type="button" class="btn btn-accent btn-lg" onclick="openMultiVendorModal()" style="padding:14px 28px;font-size:15px;font-weight:700;box-shadow:0 6px 20px rgba(230,126,34,0.4)">
            🚀 Send Multi-Mill Enquiry →
          </button>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Technical Parameter Glossary Section -->
    <div style="margin-top:56px">
      <div class="section-head">
        <div class="section-label">Engineering Reference</div>
        <h2 style="font-size:22px;font-weight:800;color:var(--n900)">Paper & Packaging Specification Guide</h2>
        <p style="color:var(--n500);margin-top:4px;font-size:14px">Understand what technical test parameters mean for your packaging and convertibility requirements.</p>
      </div>

      <div class="glossary-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-top:20px">
        <div style="background:#fff;padding:20px;border-radius:var(--r-md);border:1px solid var(--n200);box-shadow:var(--shadow-sm)">
          <div style="font-size:15px;font-weight:700;color:var(--brand);display:flex;align-items:center;gap:8px;margin-bottom:6px">
            <span style="background:var(--n100);width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;font-size:13px">⚖️</span>
            Burst Factor (BF) & Index
          </div>
          <p style="font-size:13px;color:var(--n600);line-height:1.55;margin:0">Measures the substrate's hydrostatic pressure resistance before rupture. Essential for corrugation box stacking strength and puncture protection during transit.</p>
        </div>

        <div style="background:#fff;padding:20px;border-radius:var(--r-md);border:1px solid var(--n200);box-shadow:var(--shadow-sm)">
          <div style="font-size:15px;font-weight:700;color:var(--brand);display:flex;align-items:center;gap:8px;margin-bottom:6px">
            <span style="background:var(--n100);width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;font-size:13px">💧</span>
            Cobb 60 Value (g/m²)
          </div>
          <p style="font-size:13px;color:var(--n600);line-height:1.55;margin:0">Quantifies the amount of water absorbed by 1 m² of paper surface in 60 seconds. Lower Cobb values indicate superior water repellency and sizing quality.</p>
        </div>

        <div style="background:#fff;padding:20px;border-radius:var(--r-md);border:1px solid var(--n200);box-shadow:var(--shadow-sm)">
          <div style="font-size:15px;font-weight:700;color:var(--brand);display:flex;align-items:center;gap:8px;margin-bottom:6px">
            <span style="background:var(--n100);width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;font-size:13px">📏</span>
            GSM & Caliper (Microns)
          </div>
          <p style="font-size:13px;color:var(--n600);line-height:1.55;margin:0">GSM indicates basis weight in grams per square meter. Caliper measures single-sheet thickness in microns. High bulk provides rigidity at lower grammages.</p>
        </div>

        <div style="background:#fff;padding:20px;border-radius:var(--r-md);border:1px solid var(--n200);box-shadow:var(--shadow-sm)">
          <div style="font-size:15px;font-weight:700;color:var(--brand);display:flex;align-items:center;gap:8px;margin-bottom:6px">
            <span style="background:var(--n100);width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;font-size:13px">✨</span>
            Brightness & Opacity (%)
          </div>
          <p style="font-size:13px;color:var(--n600);line-height:1.55;margin:0">Reflectance of blue light (ISO 2470) determines print contrast and vibrancy. High opacity prevents show-through in duplex and multi-color offset printing.</p>
        </div>
      </div>
    </div>

    <!-- Recommended Alternative Grades -->
    <?php if (!empty($recommendedProducts)): ?>
    <div style="margin-top:56px">
      <div class="section-head" style="display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div>
          <div class="section-label">Suggested Grades</div>
          <h2 style="font-size:22px;font-weight:800;color:var(--n900)">Recommended Alternative Products</h2>
          <p style="color:var(--n500);margin-top:4px;font-size:14px">Top rated paper & packaging grades from certified mills in matching categories.</p>
        </div>
        <a href="<?= BASE_URL ?>/public/products.php" class="btn btn-outline btn-sm">Explore All Products →</a>
      </div>

      <div class="product-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px;margin-top:20px">
        <?php foreach($recommendedProducts as $rp): ?>
          <?php
            $rimgs=array_filter(explode(',',$rp['images']??''));
            $rimg=reset($rimgs)?UPLOAD_URL.trim(reset($rimgs)):'';
          ?>
          <div class="product-card" style="background:#fff;border-radius:var(--r-md);border:1px solid var(--n200);overflow:hidden;box-shadow:var(--shadow-sm);display:flex;flex-direction:column">
            <div style="position:relative;height:160px;background:var(--n50);overflow:hidden">
              <?php if($rimg): ?>
                <img src="<?= sH($rimg) ?>" alt="<?= sH($rp['name']) ?>" style="width:100%;height:100%;object-fit:cover">
              <?php else: ?>
                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:36px">📦</div>
              <?php endif; ?>
              <span class="badge" style="position:absolute;top:10px;left:10px;background:rgba(10,25,47,0.85);color:#fff;font-size:11px"><?= sH($rp['cname']) ?></span>
            </div>
            <div style="padding:16px;flex:1;display:flex;flex-direction:column">
              <h4 style="font-size:14.5px;font-weight:700;color:var(--n900);line-height:1.35;margin-bottom:6px">
                <a href="<?= BASE_URL ?>/public/product.php?id=<?= $rp['id'] ?>" style="color:inherit"><?= sH($rp['name']) ?></a>
              </h4>
              <div style="font-size:12px;color:var(--n500);margin-bottom:8px">🏭 <?= sH($rp['company']?:$rp['vname']) ?></div>
              <div style="font-size:13.5px;font-weight:700;color:var(--brand);margin-top:auto;margin-bottom:14px">
                <?= $rp['price_range'] ? '₹ '.sH($rp['price_range']) : 'Contact for Price' ?>
              </div>
              <div style="display:flex;gap:8px">
                <a href="<?= BASE_URL ?>/public/product.php?id=<?= $rp['id'] ?>" class="btn btn-outline btn-sm" style="flex:1;text-align:center">View</a>
                <button type="button" class="btn btn-primary btn-sm" onclick="quickAddToCompare(<?= $rp['id'] ?>)" style="flex:1">+ Compare</button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</section>

<!-- Add Product picker modal -->
<div class="modal-backdrop" id="add-product-modal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <h3 style="font-size:17px;font-weight:700">+ Add Product to Compare</h3>
      <button class="modal-close" onclick="closeAddProductPicker()">✕</button>
    </div>
    <div class="modal-body">
      <div id="ap-error" class="site-alert site-alert-error" style="display:none"></div>

      <!-- Tabs: pick how to browse -->
      <div style="display:flex;gap:8px;margin-bottom:18px;border-bottom:1.5px solid var(--n200)">
        <button type="button" id="ap-tab-btn-category" onclick="apSwitchTab('category')"
                style="flex:1;padding:10px 4px;background:none;border:none;border-bottom:2.5px solid var(--brand);font-weight:700;font-size:13.5px;color:var(--brand);cursor:pointer">🔍 By Category</button>
        <button type="button" id="ap-tab-btn-vendor" onclick="apSwitchTab('vendor')"
                style="flex:1;padding:10px 4px;background:none;border:none;border-bottom:2.5px solid transparent;font-weight:700;font-size:13.5px;color:var(--n400);cursor:pointer">🏭 By Vendor / Mill</button>
      </div>

      <!-- ── Tab 1: Category-first (industry → category → type → product) ── -->
      <div id="ap-tab-category">
        <div class="form-group">
          <label class="form-label">Industry</label>
          <select id="ap-industry" class="form-input" onchange="apOnIndustryChange()">
            <option value="">Select Industry…</option>
            <?php foreach($industries as $ind): ?>
              <option value="<?= $ind['id'] ?>"><?= sH($ind['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Category</label>
          <select id="ap-category" class="form-input" onchange="apOnCategoryChange()" disabled>
            <option value="">Select Industry first…</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Product Type</label>
          <select id="ap-type" class="form-input" onchange="apOnTypeChange()" disabled>
            <option value="">Select Category first…</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Product</label>
          <select id="ap-product" class="form-input" disabled>
            <option value="">Select Product Type first…</option>
          </select>
        </div>
      </div>

      <!-- ── Tab 2: Vendor-first (vendor/mill → industry → category → type → product) ── -->
      <div id="ap-tab-vendor" style="display:none">
        <div class="form-group" style="position:relative">
          <label class="form-label">Vendor / Mill</label>
          <input type="text" id="av-vendor-search" class="form-input" placeholder="Type a company or mill name…" autocomplete="off" oninput="avSearchVendors(this.value)" onfocus="avSearchVendors(this.value)">
          <input type="hidden" id="av-vendor-id">
          <div id="av-vendor-results" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:20;background:#fff;border:1.5px solid var(--n200);border-radius:var(--r-sm);box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:220px;overflow-y:auto;margin-top:4px"></div>
          <div id="av-vendor-chip" style="display:none;margin-top:8px;align-items:center;gap:8px;background:var(--n50);border-radius:100px;padding:6px 12px;font-size:13px;font-weight:600;color:var(--brand)">
            <span id="av-vendor-chip-name"></span>
            <button type="button" onclick="avClearVendor()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:var(--n400);font-size:13px">✕ change</button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Industry</label>
          <select id="av-industry" class="form-input" onchange="avOnIndustryChange()" disabled>
            <option value="">Select Vendor first…</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Category</label>
          <select id="av-category" class="form-input" onchange="avOnCategoryChange()" disabled>
            <option value="">Select Industry first…</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Product Type</label>
          <select id="av-type" class="form-input" onchange="avOnTypeChange()" disabled>
            <option value="">Select Category first…</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Product</label>
          <select id="av-product" class="form-input" disabled>
            <option value="">Select Product Type first…</option>
          </select>
        </div>
      </div>

      <button type="button" class="btn btn-accent btn-full btn-lg" id="ap-add-btn" onclick="apAddSelectedProduct()" disabled style="margin-top:10px">Add to Compare</button>
    </div>
  </div>
</div>

<!-- Single Enquiry Modal -->
<div class="modal-backdrop" id="enquiry-modal">
  <div class="modal">
    <div class="modal-header">
      <h3 style="font-size:17px;font-weight:700">📩 Request Quotation</h3>
      <button class="modal-close" onclick="closeEnquiryModal()">✕</button>
    </div>
    <div class="modal-body">
      <div id="enq-success" class="site-alert site-alert-success" style="display:none"></div>
      <form id="enq-form" onsubmit="submitEnquiry(event)">
        <input type="hidden" id="enq-product-id" name="product_id">
        <input type="hidden" id="enq-vendor-id" name="vendor_id">
        <div id="enq-product-name" style="background:var(--n50);border-radius:var(--r-sm);padding:10px 14px;margin-bottom:16px;font-weight:600;color:var(--brand)"></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Your Name *</label><input type="text" name="name" class="form-input" required placeholder="Full Name"></div>
          <div class="form-group"><label class="form-label">Work Email *</label><input type="email" name="email" class="form-input" required placeholder="name@company.com"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Phone Number *</label><input type="tel" name="phone" class="form-input" required placeholder="+91 98765 43210"></div>
          <div class="form-group"><label class="form-label">Company Name</label><input type="text" name="company" class="form-input" placeholder="Organization"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Required Quantity (MT / Rolls)</label><input type="text" name="qty_needed" class="form-input" placeholder="e.g. 20 MT"></div>
          <div class="form-group"><label class="form-label">Delivery City / Port</label><input type="text" name="city" class="form-input" placeholder="e.g. Surat, Gujarat"></div>
        </div>
        <div class="form-group">
          <label class="form-label">Technical Requirement / Note</label>
          <textarea name="message" class="form-input" rows="3" placeholder="Specify deckle, reel diameter, core size, or specific test certifications needed..." style="resize:vertical"></textarea>
        </div>
        <button type="submit" class="btn btn-accent btn-full btn-lg" id="enq-btn">Submit RFQ</button>
      </form>
    </div>
  </div>
</div>

<!-- Consolidated Multi-Vendor Modal -->
<div class="modal-backdrop" id="multi-rfq-modal">
  <div class="modal" style="max-width:540px">
    <div class="modal-header">
      <h3 style="font-size:17px;font-weight:700">🚀 Multi-Mill RFQ Dispatch</h3>
      <button class="modal-close" onclick="closeMultiVendorModal()">✕</button>
    </div>
    <div class="modal-body">
      <div id="multi-enq-success" class="site-alert site-alert-success" style="display:none"></div>
      <div id="multi-enq-error" class="site-alert site-alert-error" style="display:none"></div>

      <div style="background:var(--n50);border-radius:var(--r-sm);padding:12px;margin-bottom:16px;border:1px solid var(--n200)">
        <div style="font-size:12px;font-weight:700;color:var(--brand);text-transform:uppercase;margin-bottom:4px">Target Manufacturers (<?= count($products) ?>):</div>
        <div style="font-size:13px;color:var(--n700)">
          <?= implode(' • ', array_map(function($p){ return sH($p['company']?:$p['vname']); }, $products)) ?>
        </div>
      </div>

      <form id="multi-enq-form" onsubmit="submitMultiEnquiry(event)">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Your Name *</label><input type="text" name="name" class="form-input" required placeholder="Full Name"></div>
          <div class="form-group"><label class="form-label">Work Email *</label><input type="email" name="email" class="form-input" required placeholder="name@company.com"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Phone Number *</label><input type="tel" name="phone" class="form-input" required placeholder="+91 98765 43210"></div>
          <div class="form-group"><label class="form-label">Company Name</label><input type="text" name="company" class="form-input" placeholder="Organization"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Estimated Monthly Volume</label><input type="text" name="qty_needed" class="form-input" placeholder="e.g. 50 MT / month"></div>
          <div class="form-group"><label class="form-label">Delivery Location</label><input type="text" name="city" class="form-input" placeholder="Destination City"></div>
        </div>
        <div class="form-group">
          <label class="form-label">Procurement Requirements</label>
          <textarea name="message" class="form-input" rows="3" placeholder="State target GSM range, reel width/sheet dimensions, payment terms, or delivery timeline..." style="resize:vertical"></textarea>
        </div>
        <button type="submit" class="btn btn-accent btn-full btn-lg" id="multi-enq-btn">Send RFQ to All <?= count($products) ?> Mills</button>
      </form>
    </div>
  </div>
</div>

<script>
// Products metadata for multi-rfq
const comparedProductsList = <?= json_encode(array_map(function($p){
    return ['id' => (int)$p['id'], 'vendor_id' => (int)$p['vendor_id'], 'name' => $p['name']];
}, $products)) ?>;

// Differences highlighting filter
function toggleDiffHighlights(active){
  const rows = document.querySelectorAll('#main-compare-table tbody tr.cmp-row');
  rows.forEach(r => {
    if (active) {
      if (r.getAttribute('data-has-diff') === '1') {
        r.classList.add('cmp-row-highlight-diff');
        r.style.display = '';
      } else {
        r.classList.remove('cmp-row-highlight-diff');
      }
    } else {
      r.classList.remove('cmp-row-highlight-diff');
      r.style.display = '';
    }
  });
}

function shareComparison(){
  if (navigator.clipboard) {
    navigator.clipboard.writeText(window.location.href).then(() => {
      alert('Comparison page link copied to clipboard!');
    }).catch(() => {
      prompt('Copy link to share:', window.location.href);
    });
  } else {
    prompt('Copy link to share:', window.location.href);
  }
}

function quickAddToCompare(productId){
  fetch(BASE + '/public/ajax/compare.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=add&product_id=' + encodeURIComponent(productId)
  })
    .then(r => r.json())
    .then(d => {
      if (d.ok) window.location.reload();
      else alert(d.msg || 'Could not add product to comparison.');
    })
    .catch(() => alert('Something went wrong. Please try again.'));
}

function removeFromComparePage(productId){
  fetch(BASE+'/public/ajax/compare.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=remove&product_id=' + encodeURIComponent(productId)
  })
    .then(r => r.json())
    .then(d => { if (d.ok) window.location.reload(); else alert(d.msg || 'Could not remove this product.'); })
    .catch(() => alert('Something went wrong. Please try again.'));
}

function clearComparePage(){
  if (!confirm('Remove all products from comparison?')) return;
  fetch(BASE+'/public/ajax/compare.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=clear'
  })
    .then(r => r.json())
    .then(d => { if (d.ok) window.location.reload(); })
    .catch(() => alert('Something went wrong. Please try again.'));
}

function openEnquiryModal(pid,vid,name){
  document.getElementById('enq-product-id').value=pid;
  document.getElementById('enq-vendor-id').value=vid;
  document.getElementById('enq-product-name').textContent='📦 '+name;
  document.getElementById('enq-success').style.display='none';
  document.getElementById('enq-form').style.display='block';
  document.getElementById('enq-btn').textContent='Submit RFQ';
  document.getElementById('enq-btn').disabled=false;
  document.getElementById('enquiry-modal').classList.add('open');
}
function closeEnquiryModal(){document.getElementById('enquiry-modal').classList.remove('open');}
document.getElementById('enquiry-modal').addEventListener('click',function(e){if(e.target===this)closeEnquiryModal();});

function submitEnquiry(e){
  e.preventDefault();
  const btn=document.getElementById('enq-btn');
  btn.textContent='Submitting…';
  btn.disabled=true;
  fetch(BASE+'/public/ajax/enquiry.php',{
    method:'POST',
    body:new FormData(e.target)
  })
  .then(r=>r.json())
  .then(d=>{
    if(d.ok){
      document.getElementById('enq-success').textContent=d.msg;
      document.getElementById('enq-success').style.display='flex';
      document.getElementById('enq-form').style.display='none';
    }else{
      btn.textContent='Submit RFQ';
      btn.disabled=false;
      alert(d.msg);
    }
  })
  .catch(()=>{
    btn.textContent='Submit RFQ';
    btn.disabled=false;
    alert('Failed to send enquiry. Please try again.');
  });
}

// Multi Vendor Modal
function openMultiVendorModal(){
  document.getElementById('multi-enq-success').style.display='none';
  document.getElementById('multi-enq-error').style.display='none';
  document.getElementById('multi-enq-form').style.display='block';
  document.getElementById('multi-enq-btn').textContent='Send RFQ to All <?= count($products) ?> Mills';
  document.getElementById('multi-enq-btn').disabled=false;
  document.getElementById('multi-rfq-modal').classList.add('open');
}
function closeMultiVendorModal(){document.getElementById('multi-rfq-modal').classList.remove('open');}
document.getElementById('multi-rfq-modal').addEventListener('click',function(e){if(e.target===this)closeMultiVendorModal();});

function submitMultiEnquiry(e){
  e.preventDefault();
  if (!comparedProductsList.length) return;
  const btn = document.getElementById('multi-enq-btn');
  btn.textContent = 'Dispatching enquiries…';
  btn.disabled = true;

  const baseFormData = new FormData(e.target);
  
  // Submit RFQ to each mill in parallel
  const requests = comparedProductsList.map(p => {
    const fd = new FormData();
    for (let pair of baseFormData.entries()) {
      fd.append(pair[0], pair[1]);
    }
    fd.set('product_id', p.id);
    fd.set('vendor_id', p.vendor_id);
    return fetch(BASE + '/public/ajax/enquiry.php', { method: 'POST', body: fd }).then(r => r.json());
  });

  Promise.all(requests)
    .then(results => {
      const allSuccess = results.every(r => r.ok);
      if (allSuccess) {
        document.getElementById('multi-enq-success').textContent = 'Multi-Mill RFQ successfully dispatched to all ' + results.length + ' manufacturers! Mills will contact you with quotes.';
        document.getElementById('multi-enq-success').style.display = 'flex';
        document.getElementById('multi-enq-form').style.display = 'none';
      } else {
        btn.textContent = 'Send RFQ';
        btn.disabled = false;
        alert('Some enquiries could not be sent. Please review and try again.');
      }
    })
    .catch(() => {
      btn.textContent = 'Send RFQ';
      btn.disabled = false;
      alert('Failed to dispatch enquiries. Please try again.');
    });
}

// ── Add Product picker: industry → category → product type → product ──
function openAddProductPicker(){
  apSwitchTab('category');
  document.getElementById('ap-industry').value = '';
  apResetSelect('ap-category', 'Select Industry first…');
  apResetSelect('ap-type', 'Select Category first…');
  apResetSelect('ap-product', 'Select Product Type first…');
  avClearVendor();
  document.getElementById('av-vendor-search').value = '';
  apResetSelect('av-industry', 'Select Vendor first…');
  apResetSelect('av-category', 'Select Industry first…');
  apResetSelect('av-type', 'Select Category first…');
  apResetSelect('av-product', 'Select Product Type first…');
  document.getElementById('ap-add-btn').disabled = true;
  apShowError('');
  document.getElementById('add-product-modal').classList.add('open');
}
function closeAddProductPicker(){
  document.getElementById('add-product-modal').classList.remove('open');
}
document.getElementById('add-product-modal').addEventListener('click',function(e){if(e.target===this)closeAddProductPicker();});

function apResetSelect(id, placeholder){
  const el = document.getElementById(id);
  el.innerHTML = '<option value="">'+placeholder+'</option>';
  el.disabled = true;
}
function apShowError(msg){
  const e = document.getElementById('ap-error');
  e.textContent = msg;
  e.style.display = msg ? 'flex' : 'none';
}

function apOnIndustryChange(){
  apShowError('');
  apResetSelect('ap-category', 'Select Industry first…');
  apResetSelect('ap-type', 'Select Category first…');
  apResetSelect('ap-product', 'Select Product Type first…');
  document.getElementById('ap-add-btn').disabled = true;

  const industryId = document.getElementById('ap-industry').value;
  if (!industryId) return;

  fetch(BASE + '/public/ajax/get-categories-with-products.php?industry_id=' + industryId)
    .then(r => r.json())
    .then(list => {
      const sel = document.getElementById('ap-category');
      if (!list.length) { sel.innerHTML = '<option value="">No categories available</option>'; return; }
      sel.innerHTML = '<option value="">Select Category…</option>' +
        list.map(c => `<option value="${c.id}">${apEsc(c.name)}</option>`).join('');
      sel.disabled = false;
    })
    .catch(() => apShowError('Could not load categories. Please try again.'));
}

function apOnCategoryChange(){
  apShowError('');
  apResetSelect('ap-type', 'Select Category first…');
  apResetSelect('ap-product', 'Select Product Type first…');
  document.getElementById('ap-add-btn').disabled = true;

  const categoryId = document.getElementById('ap-category').value;
  if (!categoryId) return;

  fetch(BASE + '/public/ajax/get-product-types-with-products.php?category_id=' + categoryId)
    .then(r => r.json())
    .then(list => {
      const sel = document.getElementById('ap-type');
      if (!list.length) { sel.innerHTML = '<option value="">No product types available</option>'; return; }
      sel.innerHTML = '<option value="">Select Product Type…</option>' +
        list.map(t => `<option value="${t.id}">${apEsc(t.name)}</option>`).join('');
      sel.disabled = false;
    })
    .catch(() => apShowError('Could not load product types. Please try again.'));
}

function apOnTypeChange(){
  apShowError('');
  apResetSelect('ap-product', 'Select Product Type first…');
  document.getElementById('ap-add-btn').disabled = true;

  const typeId = document.getElementById('ap-type').value;
  if (!typeId) return;

  fetch(BASE + '/public/ajax/get-products-by-type.php?product_type_id=' + typeId)
    .then(r => r.json())
    .then(list => {
      const sel = document.getElementById('ap-product');
      if (!list.length) { sel.innerHTML = '<option value="">All products of this type are already in your comparison</option>'; return; }
      sel.innerHTML = '<option value="">Select Product…</option>' +
        list.map(p => {
          const label = p.name + (p.company || p.vendor_name ? ' — ' + (p.company || p.vendor_name) : '');
          return `<option value="${p.id}">${apEsc(label)}</option>`;
        }).join('');
      sel.disabled = false;
    })
    .catch(() => apShowError('Could not load products. Please try again.'));
}

let apActiveTab = 'category';
function apSwitchTab(tab){
  apActiveTab = tab;
  const isCategory = tab === 'category';
  document.getElementById('ap-tab-category').style.display = isCategory ? 'block' : 'none';
  document.getElementById('ap-tab-vendor').style.display   = isCategory ? 'none' : 'block';
  const catBtn = document.getElementById('ap-tab-btn-category');
  const venBtn = document.getElementById('ap-tab-btn-vendor');
  catBtn.style.borderBottomColor = isCategory ? 'var(--brand)' : 'transparent';
  catBtn.style.color = isCategory ? 'var(--brand)' : 'var(--n400)';
  venBtn.style.borderBottomColor = isCategory ? 'transparent' : 'var(--brand)';
  venBtn.style.color = isCategory ? 'var(--n400)' : 'var(--brand)';
  apShowError('');
  apRefreshAddBtn();
}
function apRefreshAddBtn(){
  const productId = apActiveTab === 'category'
    ? document.getElementById('ap-product').value
    : document.getElementById('av-product').value;
  document.getElementById('ap-add-btn').disabled = !productId;
}

let avSearchTimer = null;
function avSearchVendors(q){
  clearTimeout(avSearchTimer);
  const results = document.getElementById('av-vendor-results');
  if (!q || !q.trim()) { results.style.display = 'none'; results.innerHTML = ''; return; }
  avSearchTimer = setTimeout(() => {
    fetch(BASE + '/public/ajax/get-vendors-with-products.php?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(list => {
        if (!list.length) {
          results.innerHTML = '<div style="padding:12px 14px;font-size:13px;color:var(--n400)">No vendors found</div>';
          results.style.display = 'block';
          return;
        }
        results.innerHTML = list.map(v => {
          const label = v.company || v.name;
          const sub = v.company && v.name !== v.company ? v.name : '';
          return `<div class="av-vendor-item" style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--n100)"
                       onmousedown="avSelectVendor(${v.id}, ${JSON.stringify(label).replace(/"/g,'&quot;')})">
                    <div style="font-weight:600;font-size:13.5px;color:var(--n900)">${apEsc(label)}</div>
                    ${sub ? `<div style="font-size:11.5px;color:var(--n500)">${apEsc(sub)}</div>` : ''}
                    <div style="font-size:11px;color:var(--n400);margin-top:2px">${v.product_count} product${v.product_count==1?'':'s'}</div>
                  </div>`;
        }).join('');
        results.style.display = 'block';
      })
      .catch(() => { results.style.display = 'none'; });
  }, 250);
}
function avSelectVendor(id, label){
  document.getElementById('av-vendor-id').value = id;
  document.getElementById('av-vendor-search').style.display = 'none';
  document.getElementById('av-vendor-results').style.display = 'none';
  document.getElementById('av-vendor-results').innerHTML = '';
  document.getElementById('av-vendor-chip-name').textContent = label;
  document.getElementById('av-vendor-chip').style.display = 'flex';
  avOnVendorChange();
}
function avClearVendor(){
  document.getElementById('av-vendor-id').value = '';
  document.getElementById('av-vendor-search').style.display = 'block';
  document.getElementById('av-vendor-chip').style.display = 'none';
  apResetSelect('av-industry', 'Select Vendor first…');
  apResetSelect('av-category', 'Select Industry first…');
  apResetSelect('av-type', 'Select Category first…');
  apResetSelect('av-product', 'Select Product Type first…');
  apRefreshAddBtn();
}
document.addEventListener('click', function(e){
  if (!e.target.closest('#av-vendor-search') && !e.target.closest('#av-vendor-results')) {
    document.getElementById('av-vendor-results').style.display = 'none';
  }
});

function avOnVendorChange(){
  apShowError('');
  apResetSelect('av-category', 'Select Industry first…');
  apResetSelect('av-type', 'Select Category first…');
  apResetSelect('av-product', 'Select Product Type first…');
  apRefreshAddBtn();

  const vendorId = document.getElementById('av-vendor-id').value;
  if (!vendorId) return;

  fetch(BASE + '/public/ajax/get-industries-by-vendor.php?vendor_id=' + vendorId)
    .then(r => r.json())
    .then(list => {
      const sel = document.getElementById('av-industry');
      if (!list.length) { sel.innerHTML = '<option value="">No industries available</option>'; return; }
      sel.innerHTML = '<option value="">Select Industry…</option>' +
        list.map(i => `<option value="${i.id}">${apEsc(i.name)}</option>`).join('');
      sel.disabled = false;
    })
    .catch(() => apShowError('Could not load industries. Please try again.'));
}

function avOnIndustryChange(){
  apShowError('');
  apResetSelect('av-type', 'Select Category first…');
  apResetSelect('av-product', 'Select Product Type first…');
  apRefreshAddBtn();

  const industryId = document.getElementById('av-industry').value;
  const vendorId = document.getElementById('av-vendor-id').value;
  if (!industryId) return;

  fetch(BASE + '/public/ajax/get-categories-with-products.php?industry_id=' + industryId + '&vendor_id=' + vendorId)
    .then(r => r.json())
    .then(list => {
      const sel = document.getElementById('av-category');
      if (!list.length) { sel.innerHTML = '<option value="">No categories available</option>'; return; }
      sel.innerHTML = '<option value="">Select Category…</option>' +
        list.map(c => `<option value="${c.id}">${apEsc(c.name)}</option>`).join('');
      sel.disabled = false;
    })
    .catch(() => apShowError('Could not load categories. Please try again.'));
}

function avOnCategoryChange(){
  apShowError('');
  apResetSelect('av-product', 'Select Product Type first…');
  apRefreshAddBtn();

  const categoryId = document.getElementById('av-category').value;
  const vendorId = document.getElementById('av-vendor-id').value;
  if (!categoryId) return;

  fetch(BASE + '/public/ajax/get-product-types-with-products.php?category_id=' + categoryId + '&vendor_id=' + vendorId)
    .then(r => r.json())
    .then(list => {
      const sel = document.getElementById('av-type');
      if (!list.length) { sel.innerHTML = '<option value="">No product types available</option>'; return; }
      sel.innerHTML = '<option value="">Select Product Type…</option>' +
        list.map(t => `<option value="${t.id}">${apEsc(t.name)}</option>`).join('');
      sel.disabled = false;
    })
    .catch(() => apShowError('Could not load product types. Please try again.'));
}

function avOnTypeChange(){
  apShowError('');
  apRefreshAddBtn();

  const typeId = document.getElementById('av-type').value;
  const vendorId = document.getElementById('av-vendor-id').value;
  if (!typeId) return;

  fetch(BASE + '/public/ajax/get-products-by-type.php?product_type_id=' + typeId + '&vendor_id=' + vendorId)
    .then(r => r.json())
    .then(list => {
      const sel = document.getElementById('av-product');
      if (!list.length) { sel.innerHTML = '<option value="">All of this vendor\'s products of this type are already in your comparison</option>'; return; }
      sel.innerHTML = '<option value="">Select Product…</option>' +
        list.map(p => `<option value="${p.id}">${apEsc(p.name)}</option>`).join('');
      sel.disabled = false;
    })
    .catch(() => apShowError('Could not load products. Please try again.'));
}
document.getElementById('av-product').addEventListener('change', apRefreshAddBtn);

document.getElementById('ap-product').addEventListener('change', function(){
  document.getElementById('ap-add-btn').disabled = !this.value;
});

function apAddSelectedProduct(){
  apShowError('');
  const productId = apActiveTab === 'category'
    ? document.getElementById('ap-product').value
    : document.getElementById('av-product').value;
  if (!productId) { apShowError('Please select a product first.'); return; }

  const btn = document.getElementById('ap-add-btn');
  btn.disabled = true;
  btn.textContent = 'Adding…';

  fetch(BASE + '/public/ajax/compare.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=add&product_id=' + encodeURIComponent(productId)
  })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        window.location.reload();
      } else {
        btn.disabled = false;
        btn.textContent = 'Add to Compare';
        apShowError(d.msg || 'Could not add this product. It may already be in your comparison.');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.textContent = 'Add to Compare';
      apShowError('Something went wrong. Please try again.');
    });
}

function apEsc(s){
  const d = document.createElement('div');
  d.textContent = s ?? '';
  return d.innerHTML;
}
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
