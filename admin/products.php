<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

/* ── Single-product actions ─────────────────────────────── */
if (isset($_GET['action'], $_GET['id'])) {
    $pid = (int)$_GET['id'];
    switch ($_GET['action']) {
        case 'approve':
            $pdo->prepare("UPDATE products SET status='active' WHERE id=?")->execute([$pid]);
            /* notify vendor */
            $vrow = $pdo->prepare("SELECT vendor_id,name FROM products WHERE id=?"); $vrow->execute([$pid]); $vrow=$vrow->fetch();
            if ($vrow) try { $pdo->prepare("INSERT INTO notifications (user_id,title,message,link) VALUES(?,?,?,?)")->execute([$vrow['vendor_id'],'✅ Product Approved','Your product "'.$vrow['name'].'" is now live.',BASE_URL.'/vendor/manage-products.php']); } catch(Exception $e){}
            flash('success','Product approved and is now live.');
            break;
        case 'reject':
            $pdo->prepare("UPDATE products SET status='inactive' WHERE id=?")->execute([$pid]);
            flash('success','Product rejected/deactivated.');
            break;
        case 'pending':
            $pdo->prepare("UPDATE products SET status='pending' WHERE id=?")->execute([$pid]);
            flash('success','Product set to pending.');
            break;
        case 'feature':
            $cur = $pdo->prepare("SELECT is_featured FROM products WHERE id=?"); $cur->execute([$pid]); $cur=$cur->fetchColumn();
            $pdo->prepare("UPDATE products SET is_featured=? WHERE id=?")->execute([$cur?0:1,$pid]);
            flash('success', $cur ? 'Product removed from featured.' : '⭐ Product featured on homepage.');
            break;
        case 'delete':
            $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$pid]);
            flash('success','Product permanently deleted.');
            break;
    }
    header('Location: '.BASE_URL.'/admin/products.php?'.http_build_query(array_filter(['search'=>$_GET['search']??'','status'=>$_GET['status']??'','featured'=>$_GET['featured']??'','vendor_id'=>$_GET['vendor_id']??'','page'=>$_GET['page']??'']))); exit;
}

/* ── Bulk actions ───────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['bulk_ids'])) {
    verifyCsrf();
    $ids  = array_map('intval', (array)$_POST['bulk_ids']);
    $act  = $_POST['bulk_action'] ?? '';
    $in   = implode(',', $ids);
    match($act) {
        'approve'  => $pdo->query("UPDATE products SET status='active'   WHERE id IN ($in)"),
        'reject'   => $pdo->query("UPDATE products SET status='inactive' WHERE id IN ($in)"),
        'feature'  => $pdo->query("UPDATE products SET is_featured=1     WHERE id IN ($in)"),
        'unfeature'=> $pdo->query("UPDATE products SET is_featured=0     WHERE id IN ($in)"),
        'delete'   => $pdo->query("DELETE FROM products WHERE id IN ($in)"),
        default    => null
    };
    flash('success', count($ids).' product(s) updated.');
    header('Location: '.BASE_URL.'/admin/products.php'); exit;
}

/* ── Filters ────────────────────────────────────────────── */
$search   = trim($_GET['search']   ?? '');
$status   = $_GET['status']        ?? '';
$featured = $_GET['featured']      ?? '';
$vendor   = (int)($_GET['vendor_id']??0);
$page     = max(1,(int)($_GET['page']??1));
$perPage  = 15; $offset = ($page-1)*$perPage;

$where = "WHERE 1=1"; $params = [];
if ($search)   { $where .= " AND (p.name LIKE ? OR u.name LIKE ? OR u.company LIKE ?)"; $params[]= "%$search%"; $params[]= "%$search%"; $params[]= "%$search%"; }
if ($status)   { $where .= " AND p.status=?";      $params[]=$status; }
if ($featured) { $where .= " AND p.is_featured=?"; $params[]=(int)$featured; }
if ($vendor)   { $where .= " AND p.vendor_id=?";   $params[]=$vendor; }

$total = $pdo->prepare("SELECT COUNT(*) FROM products p JOIN users u ON u.id=p.vendor_id $where"); $total->execute($params); $total=$total->fetchColumn();

$params[] = $perPage; $params[] = $offset;
$products = $pdo->prepare("
    SELECT p.*,u.name AS vendor_name,u.company AS vendor_company,
           i.name AS industry_name,c.name AS category_name,pt.name AS type_name
    FROM products p
    JOIN users u          ON u.id=p.vendor_id
    JOIN industries i     ON i.id=p.industry_id
    JOIN categories c     ON c.id=p.category_id
    JOIN product_types pt ON pt.id=p.product_type_id
    $where ORDER BY p.is_featured DESC,p.created_at DESC LIMIT ? OFFSET ?
");
$products->execute($params); $products=$products->fetchAll();

/* Quick counts */
$counts = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(status='pending')  AS pending,
    SUM(status='active')   AS active,
    SUM(status='inactive') AS inactive,
    SUM(is_featured=1)     AS featured
FROM products")->fetch();

$pageTitle='All Products'; $activePage='products';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
.filter-bar{display:flex;gap:8px;flex-wrap:wrap;width:100%}
.stat-pill{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:100px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;border:1.5px solid transparent;transition:all .15s}
.stat-pill.all    {background:#f1f5f9;color:#475569;border-color:#e2e8f0}
.stat-pill.pend   {background:#fef3c7;color:#92400e;border-color:#fde68a}
.stat-pill.active {background:#d1fae5;color:#065f46;border-color:#6ee7b7}
.stat-pill.inact  {background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.stat-pill.feat   {background:#fffbeb;color:#92400e;border-color:#fcd34d}
.stat-pill:hover,.stat-pill.sel{box-shadow:0 0 0 3px rgba(99,102,241,.18);border-color:var(--primary)}
.feat-star{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:8px;background:#fffbeb;border:1.5px solid #fde68a;cursor:pointer;font-size:13px;transition:all .15s;text-decoration:none}
.feat-star:hover{background:#fcd34d;border-color:#f59e0b}
.feat-star.on{background:#f59e0b;border-color:#d97706}
.bulk-bar{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#eef2ff;border-radius:10px;margin-bottom:12px;flex-wrap:wrap}
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>All Products</h1>
  </div>
  <div class="topbar-right">
    <a href="add-product.php" class="btn btn-primary btn-sm">+ Add Product</a>
  </div>
</div>

<div class="content">
  <?= showFlash() ?>

  <!-- Status pills -->
  <?php
  // Build a base query string preserving search + vendor filters but
  // always resetting to page 1 when switching status/featured tabs.
  $pillBase = array_filter(['search'=>$search,'vendor_id'=>$vendor?$vendor:'']);
  ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
    <a href="?<?= http_build_query($pillBase) ?>" class="stat-pill all <?= (!$status&&!$featured)?'sel':'' ?>">📦 All <strong><?= $counts['total'] ?></strong></a>
    <a href="?<?= http_build_query($pillBase+['status'=>'pending']) ?>"  class="stat-pill pend  <?= $status==='pending' ?'sel':'' ?>">⏳ Pending <strong><?= $counts['pending'] ?></strong></a>
    <a href="?<?= http_build_query($pillBase+['status'=>'active']) ?>"   class="stat-pill active <?= $status==='active'  ?'sel':'' ?>">✅ Active <strong><?= $counts['active'] ?></strong></a>
    <a href="?<?= http_build_query($pillBase+['status'=>'inactive']) ?>" class="stat-pill inact  <?= $status==='inactive'?'sel':'' ?>">⛔ Inactive <strong><?= $counts['inactive'] ?></strong></a>
    <a href="?<?= http_build_query($pillBase+['featured'=>'1']) ?>"      class="stat-pill feat   <?= $featured?'sel':'' ?>">⭐ Featured <strong><?= $counts['featured'] ?></strong></a>
  </div>

  <div class="card">
    <!-- Search / filter bar -->
    <div class="card-header">
      <form method="GET" class="filter-bar">
        <input type="hidden" name="status"   value="<?= sanitize($status) ?>">
        <input type="hidden" name="featured" value="<?= sanitize($featured) ?>">
        <input type="text" name="search" value="<?= sanitize($search) ?>" placeholder="Search name, vendor, company…" class="form-control" style="flex:1;min-width:180px">
        <select name="vendor_id" class="form-control" style="width:180px">
          <option value="">All Vendors</option>
          <?php $allVendors=$pdo->query("SELECT id,name,company FROM users WHERE role='vendor' ORDER BY name")->fetchAll();
          foreach($allVendors as $v): ?>
          <option value="<?= $v['id'] ?>" <?= $vendor==$v['id']?'selected':''?>><?= sanitize($v['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">🔍 Filter</button>
        <?php if ($search||$status||$featured||$vendor): ?>
        <a href="?" class="btn btn-outline btn-sm">✕ Clear</a>
        <?php endif; ?>
      </form>
    </div>

    <?php if ($products): ?>

    <!-- Bulk action form -->
    <form method="POST" id="bulk-form">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="bulk-bar">
        <input type="checkbox" id="chk-all" style="width:16px;height:16px;accent-color:var(--primary)" onchange="toggleAll(this)">
        <label for="chk-all" style="font-size:13px;font-weight:600;cursor:pointer">Select all</label>
        <span id="sel-count" style="font-size:12px;color:var(--text-muted)">0 selected</span>
        <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
          <select name="bulk_action" class="form-control" style="width:180px;font-size:13px">
            <option value="">— Bulk Action —</option>
            <option value="approve">✅ Approve All</option>
            <option value="reject">⛔ Deactivate All</option>
            <option value="feature">⭐ Feature All</option>
            <option value="unfeature">☆ Unfeature All</option>
            <option value="delete">🗑 Delete All</option>
          </select>
          <button type="submit" class="btn btn-outline btn-sm" onclick="return confirmBulk()">Apply</button>
        </div>
      </div>

      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th style="width:36px"></th>
              <th>Product</th>
              <th>Vendor</th>
              <th>Category / Type</th>
              <th>Price</th>
              <th>Views</th>
              <th>Featured</th>
              <th>Status</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($products as $i => $p): ?>
          <tr>
            <td style="text-align:center">
              <input type="checkbox" name="bulk_ids[]" value="<?= $p['id'] ?>" class="row-chk"
                     style="width:15px;height:15px;accent-color:var(--primary)" onchange="updateSelCount()">
            </td>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <?php $imgs=array_filter(explode(',',$p['images']??'')); $img=trim(reset($imgs)?:''); ?>
                <?php if ($img): ?>
                  <img src="<?= UPLOAD_URL.sanitize($img) ?>" style="width:36px;height:36px;object-fit:cover;border-radius:7px;flex-shrink:0;border:1px solid var(--border)">
                <?php else: ?>
                  <div style="width:36px;height:36px;border-radius:7px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">📦</div>
                <?php endif; ?>
                <div>
                  <div style="font-weight:600;font-size:13.5px"><?= sanitize($p['name']) ?></div>
                  <?php if (!empty($p['short_desc'])): ?>
                  <div style="font-size:11.5px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px"><?= sanitize($p['short_desc']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <a href="vendor-profile.php?id=<?= $p['vendor_id'] ?>" style="font-weight:600;font-size:13px;color:var(--primary);text-decoration:none"><?= sanitize($p['vendor_name']) ?></a>
              <?php if ($p['vendor_company']): ?><div style="font-size:11.5px;color:var(--text-muted)"><?= sanitize($p['vendor_company']) ?></div><?php endif; ?>
            </td>
            <td>
              <div style="font-size:12px;color:var(--text-muted)"><?= sanitize($p['industry_name']) ?> › <?= sanitize($p['category_name']) ?></div>
              <div style="font-size:13px;font-weight:500"><?= sanitize($p['type_name']) ?></div>
            </td>
            <td style="font-size:13px;font-weight:600;white-space:nowrap"><?= sanitize($p['price_range']?:'—') ?></td>
            <td style="font-size:13px;color:var(--text-muted)"><?= number_format((int)$p['views']) ?></td>
            <td style="text-align:center">
              <a href="?action=feature&id=<?= $p['id'] ?>&<?= http_build_query(array_filter(['search'=>$search,'status'=>$status,'featured'=>$featured,'vendor_id'=>$vendor?$vendor:'','page'=>$page>1?$page:''])) ?>"
                 class="feat-star <?= $p['is_featured']?'on':'' ?>"
                 title="<?= $p['is_featured']?'Remove from featured':'Feature this product' ?>">⭐</a>
            </td>
            <td><?= statusBadge($p['status']) ?></td>
            <td style="font-size:12px;color:var(--text-muted);white-space:nowrap"><?= date('d M Y',strtotime($p['created_at'])) ?></td>
            <td>
              <?php
              $rowCtx = http_build_query(array_filter(['search'=>$search,'status'=>$status,'featured'=>$featured,'vendor_id'=>$vendor?$vendor:'','page'=>$page>1?$page:'']));
              ?>
              <div class="td-actions">
                <a href="edit-product.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-xs" title="Edit">✏️ Edit</a>
                <a href="view-product.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-xs" title="View">👁</a>
                <?php if ($p['status']==='pending'): ?>
                  <a href="?action=approve&id=<?= $p['id'] ?>&<?= $rowCtx ?>" class="btn btn-success btn-xs" title='Approve'    onclick="return confirm('Approve?')">✅</a>
                  <a href="?action=reject&id=<?= $p['id'] ?>&<?= $rowCtx ?>"  class="btn btn-danger  btn-xs" title='Reject'     onclick="return confirm('Reject?')">❌</a>
                <?php elseif ($p['status']==='active'): ?>
                  <a href="?action=reject&id=<?= $p['id'] ?>&<?= $rowCtx ?>"  class="btn btn-warning btn-xs" title='Deactivate' onclick="return confirm('Deactivate?')">⏸</a>
                <?php else: ?>
                  <a href="?action=approve&id=<?= $p['id'] ?>&<?= $rowCtx ?>" class="btn btn-success btn-xs" title='Activate'   onclick="return confirm('Activate?')">▶</a>
                <?php endif; ?>
                <a href="?action=delete&id=<?= $p['id'] ?>&<?= $rowCtx ?>"    class="btn btn-danger  btn-xs" title='Permanently Delete' onclick="return confirm('Permanently delete?')">🗑</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>

    <?= paginate($total,$perPage,$page,'?search='.urlencode($search).'&status='.urlencode($status).'&featured='.urlencode($featured).'&vendor_id='.$vendor) ?>

    <?php else: ?>
      <div class="empty-state"><div class="es-icon">📭</div><p>No products found. <a href="?">Clear filters</a></p></div>
    <?php endif; ?>
  </div>
</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
<script>
function toggleAll(master){document.querySelectorAll('.row-chk').forEach(c=>c.checked=master.checked);updateSelCount();}
function updateSelCount(){const n=document.querySelectorAll('.row-chk:checked').length;document.getElementById('sel-count').textContent=n+' selected';}
function confirmBulk(){
  const n=document.querySelectorAll('.row-chk:checked').length;
  if(!n){alert('Select at least one product.');return false;}
  const act=document.querySelector('[name=bulk_action]').value;
  if(!act){alert('Choose a bulk action.');return false;}
  if(act==='delete') return confirm('Permanently delete '+n+' product(s)? This cannot be undone.');
  return confirm('Apply "'+act+'" to '+n+' selected product(s)?');
}
</script>
</div></div></body></html>
