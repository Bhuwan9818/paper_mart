<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('vendor');
require_once __DIR__ . '/../includes/subscription.php';
$user = currentUser();
$uid  = $user['id'];
$sub  = getVendorSubscription($pdo, $uid);

// Handle delete
if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $pdo->prepare("DELETE FROM products WHERE id=? AND vendor_id=?")->execute([(int)$_GET['id'], $uid]);
    flash('success','Product deleted.');
    header('Location: manage-products.php'); exit;
}

// Filters
$search   = trim($_GET['search'] ?? '');
$status   = $_GET['status']   ?? '';
$industry = (int)($_GET['industry'] ?? 0);
$category = (int)($_GET['category'] ?? 0);
$sort     = in_array($_GET['sort']??'', ['newest','oldest','name_az','name_za','status']) ? $_GET['sort'] : 'newest';
$view     = in_array($_GET['view']??'', ['table','cards']) ? $_GET['view'] : 'table';
$page     = max(1,(int)($_GET['page']??1));
$perPage  = ($view === 'cards') ? 12 : 10;
$offset   = ($page-1)*$perPage;

$where  = "WHERE p.vendor_id=?"; $params=[$uid];
if ($search)   { $where.=" AND (p.name LIKE ? OR p.description LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }
if ($status)   { $where.=" AND p.status=?";       $params[]=$status; }
if ($industry) { $where.=" AND p.industry_id=?";  $params[]=$industry; }
if ($category) { $where.=" AND p.category_id=?";  $params[]=$category; }

$orderMap = ['newest'=>'p.created_at DESC','oldest'=>'p.created_at ASC','name_az'=>'p.name ASC','name_za'=>'p.name DESC','status'=>'p.status ASC, p.created_at DESC'];
$order = $orderMap[$sort];

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p $where");
$countStmt->execute($params); $total=$countStmt->fetchColumn();

$params[]=$perPage; $params[]=$offset;
$stmt = $pdo->prepare("SELECT p.*, i.name AS industry_name, c.name AS category_name, pt.name AS type_name FROM products p JOIN industries i ON i.id=p.industry_id JOIN categories c ON c.id=p.category_id JOIN product_types pt ON pt.id=p.product_type_id $where ORDER BY $order LIMIT ? OFFSET ?");
$stmt->execute($params); $products=$stmt->fetchAll();

// For filter dropdowns (vendor's own)
$myIndustries = $pdo->prepare("SELECT DISTINCT i.id, i.name FROM products p JOIN industries i ON i.id=p.industry_id WHERE p.vendor_id=? ORDER BY i.name");
$myIndustries->execute([$uid]); $myIndustries=$myIndustries->fetchAll();
$myCategories = $pdo->prepare("SELECT DISTINCT c.id, c.name FROM products p JOIN categories c ON c.id=p.category_id WHERE p.vendor_id=?" . ($industry?" AND p.industry_id=$industry":"") . " ORDER BY c.name");
$myCategories->execute([$uid]); $myCategories=$myCategories->fetchAll();

// Stats for top bar
$statsQ = $pdo->prepare("SELECT status, COUNT(*) AS c FROM products WHERE vendor_id=? GROUP BY status");
$statsQ->execute([$uid]); $pStats=[];
foreach($statsQ->fetchAll() as $r) $pStats[$r['status']]=$r['c'];

$buildQuery = fn($extra=[]) => http_build_query(array_merge(
    compact('search','status','industry','category','sort','view'), $extra, ['page'=>1]
));

$pageTitle='My Products'; $activePage='manage-products';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
/* ── Stat strip ── */
.pstat-strip { display:flex; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
.pstat-item { background:#fff; text-decoration: none; text-align: center; border:1px solid var(--border); border-radius:var(--radius-sm); padding:10px 18px; display:flex; align-items:center; gap:10px; cursor:pointer; transition:var(--transition); }
.pstat-item:hover,.pstat-item.active { border-color:var(--primary); background:var(--primary-light); }
.pstat-item .ps-val { font-size:22px; font-weight:800; }
.pstat-item .ps-lbl { font-size:11.5px; color:var(--text-muted); font-weight:500; }
.pstat-active  .ps-val { color:var(--success); }
.pstat-pending .ps-val { color:var(--warning); }
.pstat-inactive.ps-val { color:var(--danger); }

/* ── Filter bar ── */
.filter-bar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:0; padding:14px; border-bottom:1px solid var(--border); }

/* ── View toggle ── */
.view-toggle { display:flex; border:1px solid var(--border); border-radius:var(--radius-sm); overflow:hidden; }
.view-btn { padding:6px 11px; font-size:13px; border:none; background:#fff; cursor:pointer; color:var(--text-muted); transition:all .15s; }
.view-btn.active { background:var(--primary); color:#fff; }

/* ── Product Cards ── */
.product-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(270px,1fr)); gap:16px; padding:16px; }
.product-card { background:#fff; border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; transition:var(--transition); }
.product-card:hover { box-shadow:var(--shadow-md); transform:translateY(-2px); }
.product-card-img { height:140px; background:var(--bg-2); display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative; }
.product-card-img img { width:100%; height:100%; object-fit:cover; }
.product-card-img .no-img { font-size:36px; color:var(--border); }
.product-card-img .status-badge-abs { position:absolute; display: flex;  top:8px; right:8px; }
.product-card-body { padding:14px; }
.product-card-title { font-weight:700; font-size:14px; margin-bottom:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.product-card-meta { font-size:12px; color:var(--text-muted); margin-bottom:10px; }
.product-card-footer { display:flex; gap:6px; padding:10px 14px; border-top:1px solid var(--border-light); background:#fafbff; }
.product-card-footer .btn { flex:1; text-align:center; }

/* ── Active filter pills ── */
.active-filters { display:flex; gap:6px; flex-wrap:wrap; padding:10px 14px; background:#fafbff; border-bottom:1px solid var(--border); }
.af-pill { display:inline-flex; align-items:center; gap:5px; background:var(--primary-light); color:var(--primary); border-radius:100px; padding:3px 10px; font-size:12px; font-weight:600; }
.af-pill a { color:var(--primary); text-decoration:none; font-size:13px; opacity:.7; }
.af-pill a:hover { opacity:1; }
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>My Products</h1>
  </div>
  <div class="topbar-right">
    <a href="<?= BASE_URL ?>/vendor/catalogue-request.php" class="btn btn-outline btn-sm">🗂️ Request Category</a>
    <a href="<?= BASE_URL ?>/vendor/add-product.php" class="btn btn-primary btn-sm">➕ Add Product</a>
  </div>
</div>

<div class="content">
  <?= showFlash() ?>

  <!-- Stat strip -->
  <div class="pstat-strip">
    <a href="?<?=$buildQuery(['status'=>''])?>" class="pstat-item <?=$status===''?'active':''?>">
      <div><div class="ps-val" style="color:var(--primary)"><?=$total?></div><div class="ps-lbl">Showing</div></div>
    </a>
    <a href="?<?=$buildQuery(['status'=>'active'])?>" class="pstat-item pstat-active <?=$status==='active'?'active':''?>">
      <div><div class="ps-val" style="color:var(--success)"><?=$pStats['active']??0?></div><div class="ps-lbl">Active</div></div>
    </a>
    <a href="?<?=$buildQuery(['status'=>'pending'])?>" class="pstat-item pstat-pending <?=$status==='pending'?'active':''?>">
      <div><div class="ps-val" style="color:var(--warning)"><?=$pStats['pending']??0?></div><div class="ps-lbl">Pending</div></div>
    </a>
    <a href="?<?=$buildQuery(['status'=>'inactive'])?>" class="pstat-item <?=$status==='inactive'?'active':''?>">
      <div><div class="ps-val" style="color:var(--danger)"><?=$pStats['inactive']??0?></div><div class="ps-lbl">Inactive</div></div>
    </a>
  </div>

  <div class="card" style="overflow:visible">
    <!-- Filter bar -->
    <form method="GET" class="filter-bar" id="filter-form">
      <input type="hidden" name="view" value="<?=sanitize($view)?>">
      <input type="hidden" name="status" value="<?=sanitize($status)?>">
      <div class="search-wrap" style="flex:1;min-width:180px">
        <input type="text" name="search" value="<?=sanitize($search)?>" placeholder="🔍 Search products…" class="form-control">
      </div>
      <select name="industry" class="form-control" style="width:150px" onchange="this.form.submit()">
        <option value="0">All Industries</option>
        <?php foreach($myIndustries as $ind): ?>
        <option value="<?=$ind['id']?>" <?=$industry==$ind['id']?'selected':''?>><?=sanitize($ind['name'])?></option>
        <?php endforeach; ?>
      </select>
      <?php if($myCategories): ?>
      <select name="category" class="form-control" style="width:150px" onchange="this.form.submit()">
        <option value="0">All Categories</option>
        <?php foreach($myCategories as $cat): ?>
        <option value="<?=$cat['id']?>" <?=$category==$cat['id']?'selected':''?>><?=sanitize($cat['name'])?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <select name="sort" class="form-control" style="width:140px" onchange="this.form.submit()">
        <option value="newest"  <?=$sort==='newest' ?'selected':''?>>Newest First</option>
        <option value="oldest"  <?=$sort==='oldest' ?'selected':''?>>Oldest First</option>
        <option value="name_az" <?=$sort==='name_az'?'selected':''?>>Name A→Z</option>
        <option value="name_za" <?=$sort==='name_za'?'selected':''?>>Name Z→A</option>
        <option value="status"  <?=$sort==='status' ?'selected':''?>>By Status</option>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Search</button>
      <?php if($search||$status||$industry||$category): ?><a href="?view=<?=$view?>" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>

      <!-- View toggle -->
      <div class="view-toggle">
        <a href="?<?=$buildQuery(['view'=>'table'])?>" class="view-btn <?=$view==='table'?'active':''?>" title="Table view">☰</a>
        <a href="?<?=$buildQuery(['view'=>'cards'])?>" class="view-btn <?=$view==='cards'?'active':''?>" title="Card view">⊞</a>
      </div>
    </form>

    <!-- Active filter pills -->
    <?php $hasFilter = $search||$status||$industry||$category; ?>
    <?php if($hasFilter): ?>
    <div class="active-filters">
      <span style="font-size:12px;color:var(--text-muted);font-weight:600">Filters:</span>
      <?php if($search):   ?><span class="af-pill">Search: <?=sanitize($search)?> <a href="?<?=$buildQuery(['search'=>''])?>">✕</a></span><?php endif; ?>
      <?php if($status):   ?><span class="af-pill">Status: <?=ucfirst($status)?> <a href="?<?=$buildQuery(['status'=>''])?>">✕</a></span><?php endif; ?>
      <?php if($industry): ?><span class="af-pill">Industry: <?=sanitize($myIndustries[array_search($industry,array_column($myIndustries,'id'))]['name']??$industry)?> <a href="?<?=$buildQuery(['industry'=>0])?>">✕</a></span><?php endif; ?>
      <?php if($category): $cname=current(array_filter($myCategories,fn($c)=>$c['id']==$category))['name']??$category; ?><span class="af-pill">Category: <?=sanitize($cname)?> <a href="?<?=$buildQuery(['category'=>0])?>">✕</a></span><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Results header -->
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:13px;color:var(--text-muted)">
      <span>Showing <strong><?=count($products)?></strong> of <strong><?=$total?></strong> products</span>
    </div>

    <?php if($products): ?>

    <?php if($view === 'cards'): ?>
    <!-- ═══ CARD VIEW ════════════════════════════════════════ -->
    <div class="product-cards">
      <?php foreach($products as $p): ?>
      <?php $imgs = $p['images'] ? explode(',', $p['images']) : []; ?>
      <div class="product-card">
        <div class="product-card-img">
          <?php if($imgs): ?>
          <img src="<?=BASE_URL?>/assets/uploads/<?=sanitize($imgs[0])?>" alt="<?=sanitize($p['name'])?>">
          <?php else: ?>
          <span class="no-img">📦</span>
          <?php endif; ?>
          <div class="status-badge-abs"><?=statusBadge($p['status'])?></div>
        </div>
        <div class="product-card-body">
          <div class="product-card-title" title="<?=sanitize($p['name'])?>"><?=sanitize($p['name'])?></div>
          <div class="product-card-meta">
            <?=sanitize($p['industry_name'])?> · <?=sanitize($p['category_name'])?>
            <br><?=sanitize($p['type_name'])?>
            <?php if($p['price_range']): ?><br><strong style="color:var(--success)"><?=sanitize($p['price_range'])?></strong><?php endif; ?>
          </div>
          <div style="font-size:11.5px;color:var(--text-muted)"><?=date('d M Y',strtotime($p['created_at']))?></div>
        </div>
        <div class="product-card-footer">
          <a href="<?=BASE_URL?>/vendor/edit-product.php?id=<?=$p['id']?>" class="btn btn-outline btn-xs">✏️ Edit</a>
          <a href="?action=delete&id=<?=$p['id']?>" class="btn btn-danger btn-xs" onclick="return confirm('Delete \'<?=sanitize($p['name'])?>\'?')">🗑 Delete</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php else: ?>
    <!-- ═══ TABLE VIEW ═══════════════════════════════════════ -->
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Product</th>
            <th>Industry / Category</th>
            <th>Type</th>
            <th>Price Range</th>
            <th>Status</th>
            <th>Added</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($products as $i=>$p): ?>
        <?php $imgs=$p['images']?explode(',',$p['images']):[]; ?>
        <tr>
          <td class="text-muted"><?=$offset+$i+1?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <?php if($imgs): ?>
              <img src="<?=BASE_URL?>/assets/uploads/<?=sanitize($imgs[0])?>" style="width:34px;height:34px;object-fit:cover;border-radius:6px;flex-shrink:0">
              <?php else: ?>
              <div style="width:34px;height:34px;border-radius:6px;background:var(--bg-2);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">📦</div>
              <?php endif; ?>
              <div>
                <div style="font-weight:600"><?=sanitize($p['name'])?></div>
                <?php if($p['min_order_qty']): ?><div style="font-size:11.5px;color:var(--text-muted)">MOQ: <?=sanitize($p['min_order_qty'])?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td>
            <div style="font-size:12px;color:var(--text-muted)"><?=sanitize($p['industry_name'])?></div>
            <div style="font-size:13px"><?=sanitize($p['category_name'])?></div>
          </td>
          <td><?=sanitize($p['type_name'])?></td>
          <td><?=$p['price_range']?"<strong style='color:var(--success)'>".sanitize($p['price_range'])."</strong>":'<span class="text-muted">—</span>'?></td>
          <td><?=statusBadge($p['status'])?></td>
          <td class="text-muted"><?=date('d M Y',strtotime($p['created_at']))?></td>
          <td>
            <div class="td-actions">
              <a href="<?=BASE_URL?>/vendor/edit-product.php?id=<?=$p['id']?>" class="btn btn-outline btn-xs" title="Edit">✏️</a>
              <a href="?action=delete&id=<?=$p['id']?>" class="btn btn-danger btn-xs" onclick="return confirm('Delete \'<?=sanitize($p['name'])?>\'? This cannot be undone.')" title="Delete">🗑️</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?= paginate($total, $perPage, $page, '?'.$buildQuery()) ?>

    <?php else: ?>
    <div class="empty-state" style="padding:48px">
      <div class="es-icon">📦</div>
      <p><?= $hasFilter ? 'No products match your filters.' : 'No products yet.' ?></p>
      <?php if($hasFilter): ?>
      <a href="?view=<?=$view?>" class="btn btn-outline btn-sm" style="margin-top:10px">Clear Filters</a>
      <?php else: ?>
      <a href="<?=BASE_URL?>/vendor/add-product.php" class="btn btn-primary btn-sm" style="margin-top:10px">➕ Add Your First Product</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
