<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('vendor');
require_once __DIR__ . '/../includes/team.php';
requirePermission('add-product');
require_once __DIR__ . '/../includes/subscription.php';

$user = currentUser();
$uid  = $user['id'];
$error = '';

/* ── Plan gate ───────────────────────────────────────────── */
$sub          = getVendorSubscription($pdo, $uid);
$productCheck = $sub ? checkProductLimit($pdo,$uid,$sub) : ['allowed'=>false,'remaining'=>0,'limit'=>0,'total'=>0];
$planBlocked  = !$sub || !$productCheck['allowed'] || in_array($sub['status'],['expired','cancelled']);
$imgLimit     = $sub ? (int)$sub['image_limit'] : 3;

/* ── POST: save one product per PT ──────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && !$planBlocked) {
    verifyCsrf();
    $d = json_decode(trim($_POST['product_json']??'{}'),true)?:[];

    /* Basic fields */
    $name      = trim($d['name'] ?? '');
    $selIndIds = array_map('intval', $d['sel_industry_ids'] ?? []);
    $selCatIds = array_map('intval', $d['sel_category_ids'] ?? []);
    $selPtIds  = array_map('intval', $d['sel_pt_ids']       ?? []);
    $ptMapping = $d['pt_mapping'] ?? [];

    if (!$name)                              $error = 'Enter a product name.';
    elseif (empty($selPtIds))               $error = 'Select at least one Product Type.';
    elseif (empty($ptMapping))              $error = 'Complete the mapping for at least one product type.';
    else {
        /* Fetch PT/cat/ind metadata for correct FK mapping */
        $ptMeta  = []; $catMeta = [];
        foreach ($selPtIds as $pid2) {
            $r=$pdo->prepare("SELECT p.name,p.category_id,c.industry_id FROM product_types p JOIN categories c ON c.id=p.category_id WHERE p.id=?");
            $r->execute([$pid2]); $ptMeta[$pid2]=$r->fetch(PDO::FETCH_ASSOC)?:[];
        }

        $insAttr = $pdo->prepare("INSERT INTO product_attributes
            (product_id,attribute_name,attribute_value,attribute_unit,tds_file,sort_order)
            VALUES(?,?,?,?,?,?)");

        $created=0; $createdNames=[];

        foreach ($selPtIds as $ptId) {
            $pm   = $ptMapping[$ptId] ?? $ptMapping[(string)$ptId] ?? [];
            $meta = $ptMeta[$ptId]    ?? [];
            $catId = (int)($meta['category_id'] ?? 0);
            $indId = (int)($meta['industry_id'] ?? ($selIndIds[0]??0));

            /* Per-PT details */
            $brand     = trim($pm['brand']       ?? '');
            $shortDesc = trim($pm['short_desc']  ?? '');
            $desc      = trim($pm['description'] ?? '');
            $moq       = trim($pm['moq']         ?? '');
            $unit      = (int)($pm['unit']       ?? 0);
            $machine   = (int)($pm['machine']    ?? 1);

            /* Validate images */
            $imgs=[];
            foreach (array_slice($pm['images']??[],0,$imgLimit) as $fn) {
                $fn=basename((string)$fn);
                if (preg_match('/^prod_[a-f0-9]+\.[a-z]{2,4}$/i',$fn) && file_exists(UPLOAD_DIR.$fn))
                    $imgs[]=$fn;
            }

            $priceRange='';
            if (!empty($pm['price_min'])||!empty($pm['price_max']))
                $priceRange='₹'.($pm['price_min']??'').' – ₹'.($pm['price_max']??'');

            $pdo->prepare("INSERT INTO products
                (vendor_id,industry_id,category_id,product_type_id,name,short_desc,description,
                 price_range,min_order_qty,images,status)
                VALUES(?,?,?,?,?,?,?,?,?,?,'pending')")
                ->execute([$uid,$indId,$catId,$ptId,$name,$shortDesc,$desc,
                           $priceRange,$moq,implode(',',$imgs)]);
            $pid2=$pdo->lastInsertId();

            try { $pdo->prepare("UPDATE products SET unit_qty=?,machine_count=? WHERE id=?")
                      ->execute([$unit,$machine,$pid2]); } catch(Exception $e){}

            /* Attributes */
            foreach (($pm['attrs']??[]) as $ri=>$attrRow) {
                $valStr=implode(', ',(array)($attrRow['values']??[]));
                $insAttr->execute([$pid2,
                    $attrRow['name']  ?? '',
                    $valStr,
                    $attrRow['unit']  ?? '',
                    null,
                    $ri]);
            }

            /* Price row */
            if (!empty($pm['price_min'])||!empty($pm['price_max']))
                $insAttr->execute([$pid2,'__price',
                    json_encode(['min'=>$pm['price_min']??'','max'=>$pm['price_max']??'']),
                    'INR',null,999]);

            /* TDS */
            $tds=basename(trim($pm['tds']??''));
            if ($tds && preg_match('/^tds_[a-f0-9]+\.[a-z]{2,4}$/i',$tds)
                     && file_exists(__DIR__.'/../assets/tds/'.$tds))
                $insAttr->execute([$pid2,'__tds',$tds,$tds,$tds,9999]);

            $created++; $createdNames[]='"'.($meta['name']??$ptId).'"';
        }

        flash('success',$created.' product'.($created>1?'s':'').' submitted for review: '.implode(', ',$createdNames));
        header('Location: '.BASE_URL.'/vendor/manage-products.php'); exit;
    }
}

$industries = getAllIndustries($pdo);
$pageTitle  = 'Add Product';
$activePage = 'add-product';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
/* Reuse same CSS as vendor page */
.wizard{display:flex;align-items:center;background:#fff;border:1px solid var(--border);border-radius:14px;padding:16px 24px;margin-bottom:24px;overflow-x:auto;gap:0;box-shadow:var(--shadow-sm)}
.wz-item{display:flex;align-items:center;gap:8px;flex-shrink:0;cursor:pointer;user-select:none}
.wz-circle{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12.5px;flex-shrink:0;transition:all .22s;background:#f1f5f9;color:#94a3b8;border:2px solid #e2e8f0}
.wz-item.active .wz-circle{background:var(--primary);color:#fff;border-color:var(--primary);box-shadow:0 0 0 4px rgba(99,102,241,.18)}
.wz-item.done   .wz-circle{background:#10b981;color:#fff;border-color:#10b981}
.wz-label{font-size:12px;font-weight:600;color:#94a3b8;white-space:nowrap}
.wz-item.active .wz-label{color:var(--primary)} .wz-item.done .wz-label{color:#10b981}
.wz-line{flex:1;height:2px;background:#e2e8f0;min-width:20px;margin:0 4px} .wz-line.done{background:#10b981}
.step-panel{display:none} .step-panel.active{display:block;animation:fadeUp .2s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.step-nav{display:flex;justify-content:space-between;align-items:center;margin-top:22px;margin-bottom:32px}
.ms-box{border:1.5px solid var(--border);border-radius:10px;background:#fff;position:relative;cursor:pointer;transition:border-color .18s}
.ms-box:hover,.ms-box.open{border-color:var(--primary)}
.ms-box-trigger{display:flex;align-items:center;justify-content:space-between;padding:9px 12px;min-height:42px;gap:8px;cursor:pointer;user-select:none;border-radius:8px}
.ms-chips{display:flex;flex-wrap:wrap;gap:5px;flex:1;pointer-events:none}
.ms-chip{display:inline-flex;align-items:center;gap:3px;background:var(--primary);color:#fff;border-radius:100px;padding:2px 8px 2px 10px;font-size:11.5px;font-weight:500}
.ms-chip-rm{background:none;border:none;color:rgba(255,255,255,.75);cursor:pointer;font-size:12px;padding:0}
.ms-chip-rm:hover{color:#fff} .ms-placeholder{color:#94a3b8;font-size:13px;pointer-events:none}
.ms-arrow{color:#94a3b8;font-size:11px;flex-shrink:0;transition:transform .18s;pointer-events:none} .ms-box.open .ms-arrow{transform:rotate(180deg)}
.ms-dropdown{display:none;position:absolute;top:100%;left:-1px;right:-1px;z-index:9999;background:#fff;border:1.5px solid var(--primary);border-radius:0 0 10px 10px;max-height:300px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.12)} .ms-box.open .ms-dropdown{display:block}
.ms-search{width:100%;padding:8px 12px;border:none;border-bottom:1px solid var(--border);font-size:13px;font-family:inherit;outline:none;position:sticky;top:0;background:#fff}
.ms-opt{display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;font-size:13px;transition:background .1s}
.ms-opt:hover{background:#f8fafc} .ms-opt.sel{background:#eff6ff;color:var(--primary)}
.ms-chk{width:16px;height:16px;border:2px solid #cbd5e1;border-radius:3px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:9px;transition:all .12s}
.ms-opt.sel .ms-chk{background:var(--primary);border-color:var(--primary);color:#fff}
.ms-empty{padding:14px;text-align:center;color:#94a3b8;font-size:13px}
.pt-map-card{border:1px solid #e6eefc;border-radius:12px;overflow:hidden;margin-bottom:18px;background:linear-gradient(180deg,#ffffff,#fbfdff);box-shadow:0 8px 30px rgba(11,22,70,0.06)}
.pt-map-head{background:linear-gradient(135deg,#eef6ff,#f7fbff);padding:14px 18px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #e6f0ff}
.pt-map-num{width:34px;height:34px;border-radius:50%;background:var(--primary);color:#fff;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.pt-map-title{font-size:15px;font-weight:800;color:#24326a;flex:1}
.pt-map-body{padding:18px;display:grid;gap:18px;align-items:start}
.attr-map-row{display:flex;align-items:flex-start;gap:12px;padding:12px;background:#ffffff;border:1px solid #edf5ff;border-radius:10px}
.attr-map-row:not(.disabled):hover{background:#fbfdff}
.attr-map-row.disabled{opacity:.65}
.attr-map-row .attr-map-label{cursor:pointer}
.attr-map-row.disabled .val-trigger{pointer-events:none;opacity:.6}
.attr-map-toggle{width:20px;height:20px;accent-color:var(--primary);cursor:pointer;flex-shrink:0;margin-top:2px}
.attr-map-label{font-size:13px;font-weight:600;min-width:130px;flex-shrink:0}
.attr-map-unit{font-size:11.5px;color:#94a3b8;font-weight:400}
.val-select{flex:1;min-width:0}
.val-trigger{display:flex;align-items:center;justify-content:space-between;padding:7px 10px;border:1.5px solid var(--border);border-radius:8px;background:#fff;cursor:pointer;min-height:36px;gap:5px;transition:border-color .15s;font-size:12.5px}
.val-trigger:hover,.val-trigger.open{border-color:var(--primary);box-shadow:0 0 0 2px rgba(99,102,241,.1)}
.val-chips{display:flex;flex-wrap:wrap;gap:3px;flex:1}
.v-chip{display:inline-flex;align-items:center;gap:2px;background:#dbeafe;color:#1e40af;border-radius:100px;padding:1px 7px;font-size:11px;font-weight:600}
.v-rm{background:none;border:none;color:#1e40af;cursor:pointer;font-size:9px;padding:0;line-height:1;opacity:.7} .v-rm:hover{opacity:1}
.val-ph{color:#94a3b8;font-size:12px} .val-arrow{color:#94a3b8;font-size:10px;flex-shrink:0;transition:transform .18s} .val-trigger.open .val-arrow{transform:rotate(180deg)}
.val-dd{display:none;position:absolute;top:calc(100%+3px);left:0;right:0;z-index:900;background:#fff;border:1.5px solid var(--primary);border-radius:9px;box-shadow:0 8px 24px rgba(99,102,241,.15);max-height:220px;overflow-y:auto;min-width:180px}
.val-dd.open{display:block}
.val-dd-search{width:100%;padding:7px 10px;border:none;border-bottom:1px solid var(--border);font-size:12px;font-family:inherit;outline:none;border-radius:9px 9px 0 0}
.val-opt{display:flex;align-items:center;gap:7px;padding:7px 10px;cursor:pointer;font-size:12.5px;transition:background .1s}
.val-opt:hover{background:#f0f4ff} .val-opt.sel{background:#eff6ff;color:var(--primary)}
.val-chk{width:14px;height:14px;border:2px solid #cbd5e1;border-radius:3px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:8px}
.val-opt.sel .val-chk{background:var(--primary);border-color:var(--primary);color:#fff}
.val-add-row{display:flex;gap:4px;padding:5px 7px;border-top:1px solid var(--border);background:#fafcff;position:sticky;bottom:0}
.val-add-inp{flex:1;border:1px solid var(--border);border-radius:5px;padding:4px 7px;font-size:11.5px;font-family:inherit;outline:none;min-width:0}
.val-add-inp:focus{border-color:var(--primary)}
.price-pair{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.price-sym{font-size:12px;color:#94a3b8;font-weight:600}
.price-inp{width:90px;border:1.5px solid var(--border);border-radius:8px;padding:7px 9px;font-size:13px;font-family:inherit;text-align:center;transition:border-color .15s}
.price-inp:focus{outline:none;border-color:var(--primary)}
.tds-label{display:flex;align-items:center;gap:8px;border:1.5px dashed #f9a8d4;border-radius:8px;padding:7px 12px;cursor:pointer;background:#fff;transition:all .15s;font-size:12.5px}
.tds-label:hover{border-color:#ec4899;background:#fdf2f8}
.img-drop-sm{border:2px dashed var(--border);border-radius:10px;padding:14px;text-align:center;cursor:pointer;transition:all .2s;background:#fafcff;position:relative}
.img-drop-sm:hover{border-color:var(--primary);background:#f0f4ff}
.img-drop-sm input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.img-thumbs{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}
.img-thumb{position:relative;width:60px;height:60px;border-radius:8px;overflow:hidden;border:1.5px solid var(--border)}
.img-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.img-thumb-rm{position:absolute;top:1px;right:1px;background:rgba(239,68,68,.9);color:#fff;border:none;border-radius:50%;width:16px;height:16px;font-size:9px;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0}
.form-select{width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13.5px;font-family:inherit;background:#fff;appearance:auto;transition:border-color .15s}
.form-select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.rv-table{width:100%;border-collapse:collapse;font-size:12.5px}
.rv-table th{background:#eef2ff;color:#4338ca;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:9px 12px;border:1px solid #c7d2fe;text-align:left;white-space:nowrap}
.rv-table td{border:1px solid #e5e7eb;padding:9px 12px;vertical-align:middle}
.attr-check-table{width:100%;border-collapse:collapse}
.attr-check-table thead th{background:#eef2ff;color:#4338ca;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:9px 12px;border:1px solid #c7d2fe;text-align:left}
.attr-check-table td{border:1px solid #e5e7eb;padding:9px 12px;vertical-align:middle;font-size:13px}
.attr-check-table tbody tr{cursor:pointer;transition:background .1s}
.attr_grid{display:grid;grid-template-columns: 1fr 1fr;gap:16px;margin-top:16px}
.price_img_grid{display:grid;grid-template-columns: 1fr 1fr;gap:16px;margin-top:16px}
.attr-check-table tbody tr:hover td{background:#f0f4ff}
.attr-check-table tbody tr.sel td{background:#eff6ff}
.attr-check-table tbody tr.sel td:first-child{border-left:3px solid var(--primary)}
.hint{background:#fefce8;border:1px solid #fde68a;border-radius:10px;padding:11px 16px;font-size:12.5px;color:#92400e;display:flex;gap:10px;align-items:flex-start;margin-bottom:18px}
.sel-summary{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;padding:10px 14px;background:#f8faff;border:1px solid #e0e7ff;border-radius:10px;min-height:38px}
.pt-pill{background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:100px;font-size:11.5px;font-weight:600}
.cat-pill{background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:100px;font-size:11.5px;font-weight:600}
.ind-pill{background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:100px;font-size:11.5px;font-weight:600}
.attr-pill{background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:100px;font-size:11.5px;font-weight:600}
.none-pill{color:#94a3b8;font-size:12px;font-style:italic}
</style>

<!-- ── Plan blocked ─────────────────────────────────────── -->
<?php if ($planBlocked): ?>
<div class="topbar"><div class="topbar-left"><button class="hamburger" id="hamburger"><span></span><span></span><span></span></button><h1>Add Product</h1></div></div>
<div class="content">
  <div class="card" style="text-align:center;padding:40px">
    <div style="font-size:48px;margin-bottom:16px">🔒</div>
    <h2><?= !$sub?'No Active Subscription':'Product Limit Reached' ?></h2>
    <p style="color:var(--text-muted);margin:10px 0 24px"><?= !$sub?'Subscribe to start adding products.':'Upgrade your plan to add more products.' ?></p>
    <a href="<?= BASE_URL ?>/vendor/subscription.php" class="btn btn-primary">View Plans</a>
  </div>
</div>
<?php else: ?>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Add Product</h1>
  </div>
  <div class="topbar-right" style="font-size:13px;color:var(--text-muted)">
    Plan: <strong style="color:var(--primary)"><?= sanitize($sub['plan_name']??'') ?></strong>
    &nbsp;·&nbsp; <?= $productCheck['remaining'] ?> / <?= $productCheck['limit'] ?> left
    &nbsp;|&nbsp; <a href="<?= BASE_URL ?>/public/vendor-profile.php?id=<?= $uid ?>" target="_blank" class="btn btn-outline btn-sm">← Back</a>
  </div>
</div>

<div class="content">
  <?= showFlash() ?>
  <?php if ($error): ?><div class="alert alert-error">⚠️ <?= sanitize($error) ?></div><?php endif; ?>

  <form method="POST" id="main-form">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="product_json" id="product_json" value="{}">

    <!-- Wizard -->
    <div class="wizard">
      <?php foreach(['Industry & Types','Attributes','Map & Pricing','Review & Submit'] as $i=>$lbl):
        $n=$i+1; $last=$n===4;?>
      <div class="wz-item <?=$n===1?'active':''?>" id="wz-<?=$n?>" onclick="gotoStep(<?=$n?>)">
        <div class="wz-circle"><?=$n?></div>
        <div class="wz-label"><?=$lbl?></div>
      </div>
      <?php if(!$last):?><div class="wz-line" id="wl-<?=$n?>"></div><?php endif;?>
      <?php endforeach;?>
    </div>

    <!-- STEP 1 -->
    <div class="step-panel active" id="panel-1">
      <div class="card">
        <div class="card-header"><h2>🏭 Industry, Category &amp; Product Types</h2></div>
        <div class="card-body">
          <div class="hint"><span style="font-size:16px">💡</span>
            <div>Select <strong>Industries</strong> → <strong>Categories</strong> load → select <strong>Categories</strong> → <strong>Product Types</strong> load → select <strong>Product Types</strong>. One product listing per Product Type.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Industries <span class="req">*</span></label>
            <div class="ms-box" id="ind-box">
              <div class="ms-box-trigger" onclick="toggleBox('ind-box',event)"><div class="ms-chips" id="ind-chips"><span class="ms-placeholder">Select industries…</span></div><span class="ms-arrow">▼</span></div>
              <div class="ms-dropdown">
                <input class="ms-search" placeholder="Search…" oninput="filterOpts('ind-opts',this.value)" onclick="event.stopPropagation()">
                <div id="ind-opts">
                  <?php foreach($industries as $ind):?>
                  <div class="ms-opt" data-id="<?=$ind['id']?>" data-name="<?=sanitize($ind['name'])?>">
                    <div class="ms-chk"></div><span><?=sanitize($ind['name'])?></span>
                  </div>
                  <?php endforeach;?>
                </div>
              </div>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Categories <span class="req">*</span></label>
            <div class="ms-box" id="cat-box">
              <div class="ms-box-trigger" onclick="toggleBox('cat-box',event)"><div class="ms-chips" id="cat-chips"><span class="ms-placeholder">Select industries first…</span></div><span class="ms-arrow">▼</span></div>
              <div class="ms-dropdown">
                <input class="ms-search" placeholder="Search…" oninput="filterOpts('cat-opts',this.value)" onclick="event.stopPropagation()">
                <div id="cat-opts"><div class="ms-empty">Select industries first.</div></div>
              </div>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Product Types <span class="req">*</span>
              <span style="font-weight:400;color:var(--text-muted);font-size:12px">— each becomes a separate product</span>
            </label>
            <div class="ms-box" id="pt-box">
              <div class="ms-box-trigger" onclick="toggleBox('pt-box',event)"><div class="ms-chips" id="pt-chips"><span class="ms-placeholder">Select categories first…</span></div><span class="ms-arrow">▼</span></div>
              <div class="ms-dropdown">
                <input class="ms-search" placeholder="Search…" oninput="filterOpts('pt-opts',this.value)" onclick="event.stopPropagation()">
                <div id="pt-opts"><div class="ms-empty">Select categories first.</div></div>
              </div>
            </div>
            <div class="sel-summary" id="pt-summary"><span class="none-pill">No product types selected yet</span></div>
          </div>
          <div id="combo-preview" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;margin-top:4px">
            <div style="font-size:11.5px;font-weight:700;color:#166534;margin-bottom:8px">✅ Products that will be created:</div>
            <div id="combo-list" style="display:flex;flex-wrap:wrap;gap:6px"></div>
          </div>
        </div>
      </div>
      <div class="step-nav"><div></div>
        <button type="button" class="btn btn-primary" onclick="nextStep(1)">Next: Select Attributes →</button>
      </div>
    </div>

    <!-- STEP 2 -->
    <div class="step-panel" id="panel-2">
      <div class="card">
        <div class="card-header"><h2>📐 Select Attributes</h2>
          <div style="font-size:12.5px;color:var(--text-muted);margin-top:3px">Choose all attributes relevant to your products. You'll map them per product type in the next step.</div>
        </div>
        <div class="card-body">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
            <input type="text" id="attr-search" class="form-control" style="max-width:280px;font-size:13px;padding:7px 12px" placeholder="🔍 Search attributes…" oninput="filterAttrTable(this.value)">
            <button type="button" class="btn btn-outline btn-sm" onclick="selectAllAttrs(true)">Select All</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="selectAllAttrs(false)">Clear All</button>
            <span id="attr-count" style="font-size:12.5px;color:var(--text-muted);margin-left:auto">0 selected</span>
          </div>
          <div id="attr-table-wrap" style="overflow-x:auto">
            <div style="padding:28px;text-align:center;color:#94a3b8;border:2px dashed var(--border);border-radius:10px">Loading attributes…</div>
          </div>
          <div style="margin-top:14px">
            <div style="font-size:11.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Selected</div>
            <div class="sel-summary" id="attr-summary"><span class="none-pill">No attributes selected yet</span></div>
          </div>
        </div>
      </div>
      <div class="step-nav">
        <button type="button" class="btn btn-outline" onclick="gotoStep(1)">← Back</button>
        <button type="button" class="btn btn-primary" onclick="nextStep(2)">Next: Map &amp; Pricing →</button>
      </div>
    </div>

    <!-- STEP 3 -->
    <div class="step-panel" id="panel-3">
      <div class="card">
        <div class="card-header"><h2>🗺️ Map Attributes, Pricing &amp; Images</h2>
          <div style="font-size:12.5px;color:var(--text-muted);margin-top:3px">For each product type: tick which attributes apply, fill values, set price, upload TDS &amp; images.</div>
        </div>
        <div class="card-body">
          <div class="hint"><span style="font-size:16px">👇</span>
            <div>Each card = one Product Type = one product listing. Check attributes, fill values, add prices, upload TDS and images (min 1, max 10).</div>
          </div>
          <div class="form-group" style="margin-top:12px">
            <label class="form-label">Shared Product Name <span class="req">*</span></label>
            <input type="text" id="f_name" class="form-control" style="font-size:15px;padding:11px 14px" placeholder="e.g. Brown Kraft Paper · Carry Bag Grade">
            <div class="form-text">This name appears in all product listings created from this form.</div>
          </div>
          <div id="pt-cards-wrap">
            <div style="padding:28px;text-align:center;color:#94a3b8;border:2px dashed var(--border);border-radius:10px">Complete Steps 1 &amp; 2 first.</div>
          </div>
        </div>
      </div>
      <div class="step-nav">
        <button type="button" class="btn btn-outline" onclick="gotoStep(2)">← Back</button>
        <button type="button" class="btn btn-primary" onclick="nextStep(3)">Next: Review &amp; Submit →</button>
      </div>
    </div>
    
    <!-- STEP 4 (formerly STEP 5) -->
    <div class="step-panel" id="panel-4">
      <div class="card">
        <div class="card-header"><h2>✅ Review &amp; Submit</h2></div>
        <div class="card-body" id="review-body">Loading…</div>
      </div>
      <div class="step-nav">
        <button type="button" class="btn btn-outline" onclick="gotoStep(3)">← Back</button>
        <button type="submit" class="btn btn-primary" style="padding:12px 32px;font-size:14px">🚀 Add Product(s)</button>
      </div>
    </div>
  </form>
</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script>
const BASE      = document.querySelector('meta[name="base-url"]').content;
const IMG_LIMIT = 10;
const esc       = s=>String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

let selInds=[],selCats=[],selPTs=[],catData=[],ptData=[],allAttrs=[],selAttrs=[],ptState={};
let curStep=1;

function gotoStep(n){
  if(n<1||n>4) return;
  console.log('gotoStep called, n=', n);
  document.querySelectorAll('.step-panel').forEach((p,i)=>p.classList.toggle('active',i+1===n));
  document.querySelectorAll('.wz-item').forEach((w,i)=>{
    w.classList.remove('active','done');
    if(i+1===n) w.classList.add('active');
    if(i+1<n){w.classList.add('done');w.querySelector('.wz-circle').textContent='✓';}
    else w.querySelector('.wz-circle').textContent=i+1;
  });
  document.querySelectorAll('.wz-line').forEach((l,i)=>l.classList.toggle('done',i+1<n));
  curStep=n;
  if(n===2) ensureAttrsLoaded();
  if(n===3) renderPTCards();
  if(n===4) buildReview();
  window.scrollTo({top:0,behavior:'smooth'});
}

function nextStep(from){
  console.log('nextStep called, from=', from);
  if(from===1){if(!selInds.length){alert('Select at least one Industry.');return;}if(!selCats.length){alert('Select at least one Category.');return;}if(!selPTs.length){alert('Select at least one Product Type.');return;}}
  if(from===2){if(!selAttrs.length){alert('Select at least one attribute.');return;}}
  if(from===3){
    const any=selPTs.some(pt=>Object.values(ptState[pt.id]?.attrs||{}).some(a=>a.on&&a.values.length>0));
    if(!any){alert('Fill attribute values for at least one product type.');return;}
    // if(!document.getElementById('f_name').value.trim()){alert('Enter a shared Product Name.');return;}
  }
  gotoStep(from+1);
}

/* Multi-select boxes */
function toggleBox(id,e){
  e.stopPropagation();
  e.preventDefault();
  const box=document.getElementById(id);
  const isOpen=box.classList.contains('open');
  closeAllBoxes();
  if(!isOpen){
    box.classList.add('open');
    const s=box.querySelector('.ms-search');
    if(s) setTimeout(()=>s.focus(),50);
  }
}
function closeAllBoxes(){
  document.querySelectorAll('.ms-box.open').forEach(b=>b.classList.remove('open'));
}
document.addEventListener('click',function(e){
  if(!e.target.closest('.ms-box') && !e.target.closest('.ms-dropdown')) closeAllBoxes();
});
document.addEventListener('keydown',function(e){
  if(e.key==='Escape') closeAllBoxes();
});
function filterOpts(optsId,q){document.querySelectorAll('#'+optsId+' .ms-opt').forEach(el=>{el.style.display=(el.dataset.name||'').toLowerCase().includes(q.toLowerCase())?'':'none';});}

function toggleInd(id,name){
  const idx=selInds.findIndex(i=>i.id===id);
  if(idx>=0) selInds.splice(idx,1); else selInds.push({id,name});
  refreshIndOpts();renderChips('ind-chips',selInds,toggleInd,'ind-pill','Select industries…');
  selCats=[];selPTs=[];ptState={};
  renderChips('cat-chips',selCats,toggleCat,'cat-pill','Select industries first…');
  renderChips('pt-chips',selPTs,togglePT,'pt-pill','Select categories first…');
  updatePTSummary();updateComboPreview();
  document.getElementById('cat-opts').innerHTML='<div class="ms-empty">Loading…</div>';
  document.getElementById('pt-opts').innerHTML='<div class="ms-empty">Select categories first.</div>';
  loadCats();
}
function refreshIndOpts(){document.querySelectorAll('#ind-opts .ms-opt').forEach(el=>{const s=selInds.some(i=>i.id===+el.dataset.id);el.classList.toggle('sel',s);el.querySelector('.ms-chk').textContent=s?'✓':'';});}
function loadCats(){
  if(!selInds.length){document.getElementById('cat-opts').innerHTML='<div class="ms-empty">Select industries first.</div>';catData=[];return;}
  Promise.all(selInds.map(i=>fetch(BASE+'/ajax/get-categories.php?industry_id='+i.id).then(r=>r.json())))
    .then(results=>{const seen={};catData=[];results.forEach((cats,ii)=>cats.forEach(c=>{if(!seen[c.id]){seen[c.id]=true;catData.push({id:+c.id,name:c.name,industry_id:selInds[ii].id});}}));renderMsOpts('cat-opts',catData,selCats,toggleCat);});
}
function toggleCat(id,name){
  const cat=catData.find(c=>c.id===id);if(!cat)return;
  const idx=selCats.findIndex(c=>c.id===id);
  if(idx>=0)selCats.splice(idx,1);else selCats.push({id:cat.id,name:cat.name,industry_id:cat.industry_id});
  renderMsOpts('cat-opts',catData,selCats,toggleCat);renderChips('cat-chips',selCats,toggleCat,'cat-pill','Select industries first…');
  selPTs=[];ptState={};renderChips('pt-chips',selPTs,togglePT,'pt-pill','Select categories first…');updatePTSummary();updateComboPreview();
  document.getElementById('pt-opts').innerHTML='<div class="ms-empty">Loading…</div>';loadPTs();
}
function loadPTs(){
  if(!selCats.length){document.getElementById('pt-opts').innerHTML='<div class="ms-empty">Select categories first.</div>';ptData=[];return;}
  Promise.all(selCats.map(c=>fetch(BASE+'/ajax/get-product-types.php?category_id='+c.id).then(r=>r.json())))
    .then(results=>{const seen={};ptData=[];results.forEach((types,ci)=>types.forEach(t=>{if(!seen[t.id]){seen[t.id]=true;ptData.push({id:+t.id,name:t.name,category_id:selCats[ci].id});}}));renderMsOpts('pt-opts',ptData,selPTs,togglePT);});
}
function togglePT(id,name){
  const t=ptData.find(x=>x.id===id);if(!t)return;
  const idx=selPTs.findIndex(p=>p.id===id);
  if(idx>=0){selPTs.splice(idx,1);delete ptState[id];}
  else{selPTs.push({id:t.id,name:t.name,category_id:t.category_id});initPTState(t.id);}
  renderMsOpts('pt-opts',ptData,selPTs,togglePT);renderChips('pt-chips',selPTs,togglePT,'pt-pill','Select categories first…');updatePTSummary();updateComboPreview();
}
function initPTState(ptId){if(ptState[ptId])return;ptState[ptId]={attrs:{},price_min:'',price_max:'',tds:'',tdsName:'',images:[]};selAttrs.forEach(a=>{ptState[ptId].attrs[a.id]={on:false,values:[]};});}
function renderMsOpts(optsId,dataArr,selArr,toggleFn){
  const c=document.getElementById(optsId);
  if(!dataArr.length){c.innerHTML='<div class="ms-empty">No options found.</div>';return;}
  c.innerHTML=dataArr.map(item=>{const s=selArr.some(x=>x.id===item.id);return`<div class="ms-opt ${s?'sel':''}" data-id="${item.id}" data-name="${esc(item.name)}"><div class="ms-chk">${s?'✓':''}</div><span>${esc(item.name)}</span></div>`;}).join('');
  // attach handlers
  c.querySelectorAll('.ms-opt').forEach(el=>{el.addEventListener('click',function(ev){ev.stopPropagation();const id=Number(this.dataset.id);const name=this.dataset.name;toggleFn(id,name);});});
}
// Attach handlers to any server-rendered .ms-opt elements (e.g. industries list)
document.querySelectorAll('#ind-opts .ms-opt').forEach(el=>{el.addEventListener('click',function(ev){ev.stopPropagation();const id=Number(this.dataset.id);const name=this.dataset.name;toggleInd(id,name);});});
function renderChips(chipId,arr,toggleFn,pillClass,ph){
  const c=document.getElementById(chipId);
  if(!arr.length){c.innerHTML=`<span class="ms-placeholder">${ph}</span>`;return;}
  c.innerHTML=arr.map(x=>`<span class="ms-chip ${pillClass}" data-id="${x.id}" data-name="${esc(x.name)}">${esc(x.name)}<button type="button" class="ms-chip-rm">✕</button></span>`).join('');
  c.querySelectorAll('.ms-chip-rm').forEach(btn=>{btn.addEventListener('click',function(ev){ev.stopPropagation();const id=Number(this.parentElement.dataset.id);const name=this.parentElement.dataset.name;toggleFn(id,name);});});
}
function updatePTSummary(){const s=document.getElementById('pt-summary');if(!selPTs.length){s.innerHTML='<span class="none-pill">No product types selected yet</span>';return;}s.innerHTML=selPTs.map(p=>`<span class="pt-pill">${esc(p.name)}</span>`).join('');}
function updateComboPreview(){const wrap=document.getElementById('combo-preview');const list=document.getElementById('combo-list');if(!selPTs.length){wrap.style.display='none';return;}wrap.style.display='block';list.innerHTML=selPTs.map(p=>`<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:100px;font-size:12px;font-weight:600">📄 ${esc(p.name)}</span>`).join('')+`<span style="color:#6b7280;font-size:12px;margin-left:6px">→ ${selPTs.length} product${selPTs.length>1?'s':''} will be created</span>`;}

/* Step 2 */
function ensureAttrsLoaded(){if(allAttrs.length){renderAttrTable();return;}fetch(BASE+'/ajax/get-attributes.php').then(r=>r.json()).then(data=>{allAttrs=data;renderAttrTable();});}
function renderAttrTable(){
  const wrap=document.getElementById('attr-table-wrap');
  if(!allAttrs.length){wrap.innerHTML='<div style="padding:28px;text-align:center;color:#94a3b8">No attributes found.</div>';return;}
  wrap.innerHTML=`<table class="attr-check-table"><thead><tr><th style="width:44px;text-align:center">✓</th><th>Attribute Name</th><th style="width:100px">Unit</th><th style="width:120px">Type</th><th style="width:100px">Required</th></tr></thead><tbody id="attr-tbody">${allAttrs.map(a=>{const sel=selAttrs.some(s=>s.id===a.id);return`<tr class="${sel?'sel':''}" onclick="toggleAttr(${a.id})" data-name="${esc(a.attribute_name)}"><td style="text-align:center"><input type="checkbox" class="attr-map-toggle" id="ach_${a.id}" ${sel?'checked':''} onclick="event.stopPropagation();toggleAttr(${a.id})"></td><td style="font-weight:600">${esc(a.attribute_name)}</td><td style="color:#6b7280;font-size:13px">${esc(a.attribute_unit||'—')}</td><td><span class="badge badge-${a.attribute_type==='number'?'info':a.attribute_type==='select'?'warning':'secondary'}">${a.attribute_type}</span></td><td><span class="badge ${a.is_required?'badge-danger':'badge-secondary'}">${a.is_required?'Required':'Optional'}</span></td></tr>`;}).join('')}</tbody></table>`;
  updateAttrCount();updateAttrSummary();
}
function toggleAttr(id){
  const a=allAttrs.find(x=>x.id===id);if(!a)return;
  const idx=selAttrs.findIndex(s=>s.id===id);
  if(idx>=0){selAttrs.splice(idx,1);selPTs.forEach(pt=>{if(ptState[pt.id])delete ptState[pt.id].attrs[id];});}
  else{const opts=(a.options_list||'').split(',').map(v=>v.trim()).filter(Boolean);selAttrs.push({id:a.id,name:a.attribute_name,unit:a.attribute_unit||'',options:opts,type:a.attribute_type});selPTs.forEach(pt=>{if(ptState[pt.id]&&!ptState[pt.id].attrs[id])ptState[pt.id].attrs[id]={on:false,values:[]};});}
  const tr=document.querySelector(`#attr-tbody tr[onclick="toggleAttr(${id})"]`);const chk=document.getElementById('ach_'+id);const isSel=selAttrs.some(s=>s.id===id);if(tr)tr.classList.toggle('sel',isSel);if(chk)chk.checked=isSel;
  updateAttrCount();updateAttrSummary();
}
function selectAllAttrs(on){selAttrs=on?allAttrs.map(a=>({id:a.id,name:a.attribute_name,unit:a.attribute_unit||'',options:(a.options_list||'').split(',').map(v=>v.trim()).filter(Boolean),type:a.attribute_type})):[];selPTs.forEach(pt=>{if(ptState[pt.id]){if(on)selAttrs.forEach(a=>{if(!ptState[pt.id].attrs[a.id])ptState[pt.id].attrs[a.id]={on:false,values:[]};});else ptState[pt.id].attrs={};}});renderAttrTable();}
function filterAttrTable(q){document.querySelectorAll('#attr-tbody tr').forEach(tr=>{tr.style.display=(tr.dataset.name||'').toLowerCase().includes(q.toLowerCase())?'':'none';});}
function updateAttrCount(){document.getElementById('attr-count').textContent=selAttrs.length+' selected';}
function updateAttrSummary(){const s=document.getElementById('attr-summary');if(!selAttrs.length){s.innerHTML='<span class="none-pill">No attributes selected yet</span>';return;}s.innerHTML=selAttrs.map(a=>`<span class="attr-pill">${esc(a.name)}${a.unit?' ('+esc(a.unit)+')':''}</span>`).join('');}

/* Step 3 */
function renderPTCards(){
  const wrap=document.getElementById('pt-cards-wrap');
  if(!selPTs.length){wrap.innerHTML='<div style="padding:28px;text-align:center;color:#94a3b8;border:2px dashed var(--border);border-radius:10px">Complete Steps 1 &amp; 2 first.</div>';return;}
  selPTs.forEach(pt=>{if(!ptState[pt.id])ptState[pt.id]={attrs:{},price_min:'',price_max:'',tds:'',tdsName:'',images:[],brand:'',moq:'',machine:'1',unit:'',short_desc:'',description:''};selAttrs.forEach(a=>{if(!ptState[pt.id].attrs[a.id])ptState[pt.id].attrs[a.id]={on:false,values:[]};});});
  wrap.innerHTML=selPTs.map((pt,idx)=>buildPTCard(pt,idx+1)).join('');
}
function buildPTCard(pt,num){
  const s=ptState[pt.id];
  const attrRows=selAttrs.map(a=>{const as=s.attrs[a.id]||{on:false,values:[]};const vals=as.values;const chipHtml=vals.length?vals.map(v=>`<span class="v-chip">${esc(v)}<button type="button" class="v-rm" onclick="removeVal(${pt.id},${a.id},'${esc(v)}')">✕</button></span>`).join(''):`<span class="val-ph">Select values…</span>`;
    return`<div class="attr-map-row ${as.on?'':'disabled'}" id="amrow_${pt.id}_${a.id}"><input type="checkbox" class="attr-map-toggle" id="amchk_${pt.id}_${a.id}" ${as.on?'checked':''} onchange="toggleAttrOnPT(${pt.id},${a.id},this.checked)" onclick="event.stopPropagation()"><label class="attr-map-label" for="amchk_${pt.id}_${a.id}">${esc(a.name)}<span class="attr-map-unit">${a.unit?' ('+esc(a.unit)+')':''}</span></label><div class="val-select" style="position:relative"><div class="val-trigger ${as.on?'':'disabled'}" id="vtrig_${pt.id}_${a.id}" onclick="${as.on?`toggleValDD(${pt.id},${a.id},event)`:''}"><div class="val-chips" id="vchips_${pt.id}_${a.id}">${chipHtml}</div><span class="val-arrow">▼</span></div><div class="val-dd" id="vdd_${pt.id}_${a.id}" onclick="event.stopPropagation()"><input class="val-dd-search" placeholder="Search or type custom…" oninput="filterValOpts(${pt.id},${a.id},this.value)"><div id="vopts_${pt.id}_${a.id}">${renderValOpts(pt.id,a.id,a.options,vals)}</div><div class="val-add-row"><input class="val-add-inp" id="vcustom_${pt.id}_${a.id}" placeholder="Custom value…" onkeydown="if(event.key==='Enter'){event.preventDefault();addCustomVal(${pt.id},${a.id});}"><button type="button" class="btn btn-primary btn-xs" onclick="addCustomVal(${pt.id},${a.id})">＋</button></div></div></div></div>`;
  }).join('');
  const imgThumbsHtml=(s.images||[]).map((img,i)=>`<div class="img-thumb"${img.uploading?' style="opacity:.55"':''}><img src="${esc(img.url)}" alt="">${img.uploading?'<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:10px;color:#fff;background:rgba(0,0,0,.25)">⏳</div>':''}<button type="button" class="img-thumb-rm" onclick="removePTImg(${pt.id},${i})">✕</button></div>`).join('');
  const imgCount=s.images.length;const imgFull=imgCount>=IMG_LIMIT;
  return`<div class="pt-map-card" id="ptcard_${pt.id}"><div class="pt-map-head"><div class="pt-map-num">${num}</div><div class="pt-map-title">${esc(pt.name)}</div><span style="font-size:12px;color:#6366f1;font-weight:600;margin-left:auto">${imgCount}/${IMG_LIMIT} images</span>${s.tds?'<span style="font-size:12px;color:#9d174d;margin-left:8px">📄 TDS</span>':''}</div><div class="pt-map-body"><div><div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">📐 Attributes</div><div class="attr_grid">${attrRows||'<div class="ms-empty">No attributes selected. Go back to Step 2.</div>'}</div></div><div class="price_img_grid"><div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start"><div><div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">💰 Price Range (₹)</div><div class="price-pair"><span class="price-sym">₹</span><input type="number" class="price-inp" id="pmin_${pt.id}" placeholder="Min" min="0" step="0.01" value="${esc(s.price_min)}" oninput="ptState[${pt.id}].price_min=this.value"><span class="price-sym">—</span><span class="price-sym">₹</span><input type="number" class="price-inp" id="pmax_${pt.id}" placeholder="Max" min="0" step="0.01" value="${esc(s.price_max)}" oninput="ptState[${pt.id}].price_max=this.value"></div></div><div><div style="font-size:11px;font-weight:700;color:#9d174d;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">📄 TDS Report</div>${s.tds?`<div style="display:flex;align-items:center;gap:7px;background:#fdf2f8;border:1.5px solid #f9a8d4;border-radius:8px;padding:6px 12px"><span>📄</span><span style="font-size:12px;font-weight:600;color:#9d174d;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(s.tdsName||s.tds)}</span><button type="button" onclick="removePTTds(${pt.id})" style="background:none;border:none;color:#f43f5e;cursor:pointer;font-size:12px;padding:0">✕</button></div>`:`<label class="tds-label"><input type="file" accept=".pdf,.doc,.docx,.xls,.xlsx" style="display:none" onchange="uploadPTTds(${pt.id},this)"><span style="font-size:16px">📤</span><div><div style="font-size:12px;font-weight:600;color:#9d174d">Upload TDS</div><div style="font-size:11px;color:#94a3b8">PDF·DOC·XLS·10MB</div></div></label>`}<div id="tds_status_${pt.id}" style="font-size:12px;min-height:14px;margin-top:4px;font-weight:600"></div></div></div><div><div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">🖼️ Images (min 1, max ${IMG_LIMIT})</div><div class="img-thumbs" id="ptimgs_${pt.id}">${imgThumbsHtml}</div>${imgFull?'':`<div class="img-drop-sm" style="margin-top:8px"><input type="file" multiple accept="image/jpeg,image/png,image/webp" onchange="uploadPTImgs(${pt.id},this)"><div style="font-size:22px;margin-bottom:4px">📁</div><div style="font-size:12.5px;font-weight:600;color:var(--primary)">Click or drag images</div><div style="font-size:11.5px;color:var(--text-muted);margin-top:2px">JPG·PNG·WebP·5MB · ${IMG_LIMIT-imgCount} slot${IMG_LIMIT-imgCount!==1?'s':''} left</div></div>`}<div id="img_status_${pt.id}" style="font-size:12px;min-height:14px;margin-top:4px;font-weight:600;color:#f59e0b"></div></div></div>
      <div style="border-top:1.5px solid #e0e7ff;padding-top:16px;margin-top:4px">
        <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px">📦 Product Details</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
          <div><div style="font-size:11px;font-weight:600;color:#6b7280;margin-bottom:4px">Brand Name</div>
            <input type="text" class="form-control" style="font-size:13px;padding:7px 10px" placeholder="e.g. PaperMart" value="${esc(s.brand)}" oninput="ptState[${pt.id}].brand=this.value"></div>
          <div><div style="font-size:11px;font-weight:600;color:#6b7280;margin-bottom:4px">Min. Order Quantity</div>
            <input type="text" class="form-control" style="font-size:13px;padding:7px 10px" placeholder="e.g. 500 kg" value="${esc(s.moq)}" oninput="ptState[${pt.id}].moq=this.value"></div>
          <div><div style="font-size:11px;font-weight:600;color:#6b7280;margin-bottom:4px">Machine Count</div>
            <select class="form-control" style="font-size:13px;padding:7px 10px" onchange="ptState[${pt.id}].machine=this.value">
              ${[1,2,3,4,5,6,7,8,9,10,11,12,13,14,15].map(m=>`<option value="${m}" ${s.machine==m?'selected':''}>${m}</option>`).join('')}
            </select></div>
          <div><div style="font-size:11px;font-weight:600;color:#6b7280;margin-bottom:4px">Unit</div>
            <select class="form-control" style="font-size:13px;padding:7px 10px" onchange="ptState[${pt.id}].unit=this.value">
              <option value="">-- Select --</option>
              ${[1,2,5,10,15,20,25,30,40,50,75,100,150,200,250,500].map(u=>`<option value="${u}" ${s.unit==u?'selected':''}>${u}</option>`).join('')}
            </select></div>
        </div>
        <div style="margin-bottom:10px"><div style="font-size:11px;font-weight:600;color:#6b7280;margin-bottom:4px">Short Description</div>
          <input type="text" class="form-control" style="font-size:13px;padding:7px 10px" placeholder="One-line summary, max 300 chars" maxlength="300" value="${esc(s.short_desc)}" oninput="ptState[${pt.id}].short_desc=this.value"></div>
        <div><div style="font-size:11px;font-weight:600;color:#6b7280;margin-bottom:4px">Full Description</div>
          <textarea class="form-control" style="font-size:13px;padding:7px 10px;min-height:80px;resize:vertical" placeholder="Detailed product description…" oninput="ptState[${pt.id}].description=this.value">${esc(s.description)}</textarea></div>
      </div></div></div>`;
}
function toggleAttrOnPT(ptId,attrId,on){if(!ptState[ptId])return;if(!ptState[ptId].attrs[attrId])ptState[ptId].attrs[attrId]={on:false,values:[]};ptState[ptId].attrs[attrId].on=on;const idx=selPTs.findIndex(p=>p.id===ptId);if(idx>=0){const newCard=buildPTCard(selPTs[idx],idx+1);document.getElementById('ptcard_'+ptId).outerHTML=newCard;}}
function renderValOpts(ptId,attrId,options,selVals){if(!options||!options.length)return'<div style="padding:10px;text-align:center;color:#94a3b8;font-size:12px">Type a custom value below.</div>';return options.map(v=>{const s=(selVals||[]).includes(v);return`<div class="val-opt ${s?'sel':''}" data-val="${esc(v)}" onclick="toggleVal(${ptId},${attrId},'${esc(v)}',this)"><div class="val-chk">${s?'✓':''}</div><span>${esc(v)}</span></div>`;}).join('');}
function toggleValDD(ptId,attrId,e){e.stopPropagation();const dd=document.getElementById('vdd_'+ptId+'_'+attrId);const trig=document.getElementById('vtrig_'+ptId+'_'+attrId);const open=dd.classList.contains('open');closeAllValDDs();if(!open){dd.classList.add('open');trig.classList.add('open');}}
function closeAllValDDs(){document.querySelectorAll('.val-dd.open').forEach(d=>d.classList.remove('open'));document.querySelectorAll('.val-trigger.open').forEach(t=>t.classList.remove('open'));}
document.addEventListener('click',closeAllValDDs);
function toggleVal(ptId,attrId,val,el){if(!ptState[ptId]?.attrs[attrId])return;const arr=ptState[ptId].attrs[attrId].values;const i=arr.indexOf(val);if(i>=0)arr.splice(i,1);else arr.push(val);el.classList.toggle('sel',arr.includes(val));el.querySelector('.val-chk').textContent=arr.includes(val)?'✓':'';syncValChips(ptId,attrId);}
function addCustomVal(ptId,attrId){const inp=document.getElementById('vcustom_'+ptId+'_'+attrId);const v=inp.value.trim();if(!v)return;if(!ptState[ptId]?.attrs[attrId])return;const arr=ptState[ptId].attrs[attrId].values;if(!arr.includes(v)){arr.push(v);syncValChips(ptId,attrId);}inp.value='';}
function removeVal(ptId,attrId,val){const arr=ptState[ptId]?.attrs[attrId]?.values;if(!arr)return;const i=arr.indexOf(val);if(i>=0)arr.splice(i,1);syncValChips(ptId,attrId);const opt=document.querySelector(`#vopts_${ptId}_${attrId} [data-val="${CSS.escape(val)}"]`);if(opt){opt.classList.remove('sel');opt.querySelector('.val-chk').textContent='';}}
function syncValChips(ptId,attrId){const vals=ptState[ptId]?.attrs[attrId]?.values||[];const chips=document.getElementById('vchips_'+ptId+'_'+attrId);if(!chips)return;chips.innerHTML=vals.length?vals.map(v=>`<span class="v-chip">${esc(v)}<button type="button" class="v-rm" onclick="removeVal(${ptId},${attrId},'${esc(v)}')">✕</button></span>`).join(''):'<span class="val-ph">Select values…</span>';}
function filterValOpts(ptId,attrId,q){document.querySelectorAll(`#vopts_${ptId}_${attrId} .val-opt`).forEach(el=>{el.style.display=(el.dataset.val||'').toLowerCase().includes(q.toLowerCase())?'':'none';});}
function uploadPTTds(ptId,input){
  const file=input.files[0];
  if(!file)return;
  const st=document.getElementById('tds_status_'+ptId);
  st.textContent='⏳ Uploading…';
  st.style.color='#f59e0b';
  const fd=new FormData();
  fd.append('tds',file);
  fd.append('csrf_token',document.querySelector('[name=csrf_token]').value);
  fetch(BASE+'/ajax/upload-tds.php',{method:'POST',body:fd,credentials:'same-origin'})
    .then(r=>r.text().then(text=>{
      let data;
      try{ data=JSON.parse(text); }
      catch(e){ throw new Error('Server returned an invalid response (check PHP error log).'); }
      return data;
    }))
    .then(data=>{
      if(data.ok){ptState[ptId].tds=data.filename;ptState[ptId].tdsName=data.name;st.textContent='';renderPTCards();}
      else{st.textContent='❌ '+(data.msg||'Failed');st.style.color='#ef4444';}
    })
    .catch(e=>{st.textContent='❌ '+e.message;st.style.color='#ef4444';});
  input.value='';
}
function removePTTds(ptId){ptState[ptId].tds='';ptState[ptId].tdsName='';renderPTCards();}
function uploadPTImgs(ptId,input){
  const files=Array.from(input.files);
  if(!files.length)return;
  const remaining=IMG_LIMIT-(ptState[ptId].images||[]).length;
  const toUpload=files.slice(0,remaining);
  if(!toUpload.length)return;
  const st=document.getElementById('img_status_'+ptId);
  st.textContent='⏳ Uploading '+toUpload.length+' image(s)…';
  st.style.color='#f59e0b';
  // Instant local preview so the user sees something immediately,
  // even before the server round-trip completes. Each entry is marked
  // uploading:true and swapped for the real server URL once saved
  // (or removed if the upload fails).
  const localEntries=toUpload.map(f=>({filename:null,url:URL.createObjectURL(f),uploading:true}));
  localEntries.forEach(e=>ptState[ptId].images.push(e));
  renderPTCards();
  const fd=new FormData();
  toUpload.forEach(f=>fd.append('images[]',f));
  fd.append('csrf_token',document.querySelector('[name=csrf_token]').value);
  console.log('[upload-debug] sending', toUpload.length, 'file(s) for pt', ptId, toUpload.map(f=>f.name+' ('+f.size+'b, '+f.type+')'));
  fetch(BASE+'/ajax/upload-product-images.php',{method:'POST',body:fd,credentials:'same-origin'})
    .then(r=>{
      console.log('[upload-debug] http status', r.status, r.statusText);
      return r.text();
    })
    .then(text=>{
      console.log('[upload-debug] raw response body:', text);
      let data;
      try{ data=JSON.parse(text); }
      catch(e){ throw new Error('Server returned an invalid response (check PHP error log). Raw: '+text.slice(0,200)); }
      return data;
    })
    .then(data=>{
      console.log('[upload-debug] parsed JSON:', data);
      // Remove the temporary local-preview placeholders for this batch.
      ptState[ptId].images=ptState[ptId].images.filter(img=>!localEntries.includes(img));
      localEntries.forEach(e=>URL.revokeObjectURL(e.url));
      if(data.ok && data.saved && data.saved.length){
        data.saved.forEach((fn,i)=>{
          console.log('[upload-debug] adding saved image', fn, '-> url:', data.urls[i]);
          ptState[ptId].images.push({filename:fn,url:data.urls[i]});
        });
      }
      if(!data.ok){
        st.textContent='❌ '+(data.msg||'Upload failed — please try again.');
        st.style.color='#ef4444';
        console.warn('[upload-debug] server reported failure:', data.msg, data.errors);
      } else if(data.errors && data.errors.length){
        st.textContent='⚠️ '+data.errors.join(' | ');
        st.style.color='#f59e0b';
        console.warn('[upload-debug] partial errors:', data.errors);
      } else {
        st.textContent='✅ Uploaded';
        st.style.color='#10b981';
        setTimeout(()=>{ if(st) st.textContent=''; },2000);
      }
      console.log('[upload-debug] ptState images after merge:', JSON.stringify(ptState[ptId].images));
      renderPTCards();
      // After the DOM updates, verify each saved <img> actually loads.
      setTimeout(()=>{
        document.querySelectorAll('#ptimgs_'+ptId+' img').forEach(imgEl=>{
          console.log('[upload-debug] thumb <img> src=', imgEl.src, 'naturalWidth=', imgEl.naturalWidth);
          imgEl.addEventListener('error',()=>console.error('[upload-debug] IMAGE FAILED TO LOAD (404 or bad path):', imgEl.src));
        });
      },50);
    })
    .catch(e=>{
      console.error('[upload-debug] caught error:', e);
      // Remove the temporary local-preview placeholders on failure too.
      ptState[ptId].images=ptState[ptId].images.filter(img=>!localEntries.includes(img));
      localEntries.forEach(entry=>URL.revokeObjectURL(entry.url));
      st.textContent='❌ '+e.message;
      st.style.color='#ef4444';
      renderPTCards();
    });
  input.value='';
}
function removePTImg(ptId,idx){
  const img=ptState[ptId].images[idx];
  if(img && img.uploading && img.url && img.url.startsWith('blob:')) URL.revokeObjectURL(img.url);
  ptState[ptId].images.splice(idx,1);
  renderPTCards();
}

/* Step 4 */
function buildReview(){
  console.log('buildReview start, selPTs=', selPTs?.length, 'selAttrs=', selAttrs?.length);
  try{
    const n=selPTs.length;
    const gv = id => esc(document.getElementById(id)?.value||'—');
    let html=`<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;margin-bottom:16px"><strong style="color:#166534">✅ ${n} product listing${n>1?'s':''} will be created</strong></div>
  <div style="display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:12px">
    <div style="background:#f8faff;border-radius:10px;padding:14px">
      <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">🏭 Classification</div>
      <div style="font-size:11px;color:#94a3b8;margin-bottom:3px">Industries</div>
      <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:7px">${selInds.map(i=>`<span class=\"ind-pill\">${esc(i.name)}</span>`).join('')}</div>
      <div style="font-size:11px;color:#94a3b8;margin-bottom:3px">Categories</div>
      <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:7px">${selCats.map(c=>`<span class=\"cat-pill\">${esc(c.name)}</span>`).join('')}</div>
      <div style="font-size:11px;color:#94a3b8;margin-bottom:3px">Attributes selected</div>
      <div style="display:flex;flex-wrap:wrap;gap:4px">${selAttrs.map(a=>`<span class=\"attr-pill\">${esc(a.name)}</span>`).join('')}</div>
    </div>
  </div>`;
  selPTs.forEach((pt,idx)=>{
    const s=ptState[pt.id]||{};
    const activeAttrs=selAttrs.filter(a=>s.attrs?.[a.id]?.on);
    html+=`<div style="margin-bottom:18px;border:1.5px solid #e0e7ff;border-radius:12px;overflow:hidden">
      <div style="background:#eef2ff;padding:10px 16px;display:flex;align-items:center;gap:10px">
        <span style="width:24px;height:24px;border-radius:50%;background:var(--primary);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">${idx+1}</span>
        <strong style="color:#3730a3;font-size:13.5px">${esc(pt.name)}</strong>
        ${s.images?.length?`<span style="margin-left:auto;font-size:12px;color:#6366f1">🖼️ ${s.images.length} image${s.images.length>1?'s':''}</span>`:'<span style="margin-left:auto;font-size:12px;color:#f59e0b">⚠️ No images</span>'}
        ${s.tds?`<span style="font-size:12px;color:#9d174d;margin-left:8px">📄 TDS</span>`:''}
      </div>
      <div style="padding:12px 16px;overflow-x:auto">
        ${activeAttrs.length?`<table class="rv-table" style="margin-bottom:10px"><thead><tr><th>Attribute</th><th>Unit</th><th>Values</th></tr></thead><tbody>${activeAttrs.map(a=>{const vals=s.attrs[a.id]?.values||[];return`<tr><td style="font-weight:600">${esc(a.name)}</td><td style="color:#6b7280">${esc(a.unit||'—')}</td><td>${vals.map(v=>`<span style="background:#dbeafe;color:#1e40af;padding:1px 7px;border-radius:100px;font-size:11.5px;font-weight:600;display:inline-block;margin:1px">${esc(v)}</span>`).join('')||'<em style="color:#94a3b8">—</em>'}</td></tr>`;}).join('')}</tbody></table>`:'<em style="color:#94a3b8;font-size:13px">No attributes mapped.</em>'}
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:6px">
          <div style="font-size:13px"><span style="color:#6b7280">Brand:</span> <strong>${esc(s.brand||'—')}</strong></div>
          <div style="font-size:13px"><span style="color:#6b7280">Price:</span> <strong style="color:#059669">${(s.price_min||s.price_max)?'₹'+(s.price_min||'?')+' – ₹'+(s.price_max||'?'):'<em style="color:#94a3b8;font-weight:400">Not set</em>'}</strong></div>
          <div style="font-size:13px"><span style="color:#6b7280">MOQ:</span> <strong>${esc(s.moq||'—')}</strong></div>
          <div style="font-size:13px"><span style="color:#6b7280">Unit:</span> <strong>${esc(s.unit||'—')}</strong></div>
          <div style="font-size:13px"><span style="color:#6b7280">Machines:</span> <strong>${esc(s.machine||'—')}</strong></div>
          <div style="font-size:13px"><span style="color:#6b7280">Images:</span> <strong>${s.images?.length||0}</strong></div>
          <div style="font-size:13px"><span style="color:#6b7280">TDS:</span> ${s.tds?`<strong style="color:#9d174d">📄 ${esc(s.tdsName||s.tds)}</strong>`:'<em style="color:#94a3b8">None</em>'}</div>
        </div>
        ${s.short_desc?`<div style="font-size:12.5px;color:#6b7280;margin-top:6px;font-style:italic">"${esc(s.short_desc)}"</div>`:''}
        ${s.images?.length?`<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px">${s.images.map(img=>`<img src="${esc(img.url)}" style="width:50px;height:50px;object-fit:cover;border-radius:7px;border:1.5px solid #c7d2fe">`).join('')}</div>`:''}
      </div>
    </div>`;
  });
    document.getElementById('review-body').innerHTML=html;
  }catch(e){
    console.error('buildReview error', e);
    const rb=document.getElementById('review-body');
    if(rb) rb.innerHTML='<div style="color:#9d174d">Error building review: '+(e.message||e)+'</div>';
  }
}

/* Submit */
document.getElementById('main-form').addEventListener('submit',function(e){
  const missingImages=selPTs.filter(pt=>!(ptState[pt.id]?.images?.length));
  if(missingImages.length&&!confirm('Some product types have no images: '+missingImages.map(p=>p.name).join(', ')+'. Submit anyway?')){e.preventDefault();return;}
  const ptMapping={};
  selPTs.forEach(pt=>{const s=ptState[pt.id]||{};ptMapping[pt.id]={attrs:selAttrs.filter(a=>s.attrs?.[a.id]?.on).map(a=>({id:a.id,name:a.name,unit:a.unit,values:s.attrs[a.id].values})),price_min:s.price_min||'',price_max:s.price_max||'',tds:s.tds||'',images:(s.images||[]).map(img=>img.filename),brand:s.brand||'',moq:s.moq||'',machine:s.machine||'1',unit:s.unit||'',short_desc:s.short_desc||'',description:s.description||''};});
  const sharedName = (document.getElementById('f_name')?.value||'').trim();
  document.getElementById('product_json').value=JSON.stringify({name:sharedName,sel_industry_ids:selInds.map(i=>i.id),sel_category_ids:selCats.map(c=>c.id),sel_pt_ids:selPTs.map(p=>p.id),pt_mapping:ptMapping});
});

document.getElementById('hamburger').addEventListener('click',()=>{document.getElementById('sidebar').classList.add('open');document.getElementById('sidebar-overlay').classList.add('show');document.body.style.overflow='hidden';});
document.getElementById('sidebar-overlay').addEventListener('click',()=>{document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.remove('show');document.body.style.overflow='';});
</script>
</div></div>
<?php endif; ?>
</body></html>
