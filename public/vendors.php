<?php
$pageTitle='All Vendors — PaperMart'; $currentPage='vendors';
include __DIR__.'/includes/header.php';

$page=max(1,(int)($_GET['page']??1)); $perPage=18; $offset=($page-1)*$perPage;
$q=trim($_GET['q']??'');
$where="WHERE u.role='vendor' AND u.status='active'"; $params=[];
if($q){$where.=" AND (u.name LIKE ? OR u.company LIKE ? OR u.city LIKE ?)";$params=["%$q%","%$q%","%$q%"];}
$total=$pdo->prepare("SELECT COUNT(*) FROM users u $where");$total->execute($params);$total=$total->fetchColumn();
$p2=$params;$p2[]=$perPage;$p2[]=$offset;
$stmt=$pdo->prepare("SELECT u.*,vp.is_verified,vp.tagline,vp.established_yr,vp.logo,vp.rating,(SELECT COUNT(*) FROM products WHERE vendor_id=u.id AND status='active') AS prod_count FROM users u LEFT JOIN vendor_profiles vp ON vp.vendor_id=u.id $where ORDER BY vp.is_verified DESC,u.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute($p2); $vendors=$stmt->fetchAll();
?>
<div style="background:var(--n50);padding:14px 0;border-bottom:1px solid var(--n200)">
  <div class="container" style="font-size:13px;color:var(--n500)">
    <a href="<?= BASE_URL ?>/public/index.php" style="color:var(--brand-2)">Home</a> › <span>All Vendors</span>
  </div>
</div>
<section class="compact">
  <div class="container">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:14px">
      <div>
        <div class="section-label">Suppliers</div>
        <h1 style="font-size:28px">All Verified Vendors</h1>
        <p style="color:var(--n500);margin-top:6px"><?= number_format($total) ?> manufacturers and suppliers listed</p>
      </div>
      <form method="GET" style="display:flex;gap:8px">
        <input type="text" name="q" value="<?= sH($q) ?>" placeholder="Search vendors, companies…" class="form-input" style="width:260px">
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if($q): ?><a href="?" class="btn btn-outline">Clear</a><?php endif; ?>
      </form>
    </div>
    <?php if($vendors): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px">
      <?php foreach($vendors as $v): ?>
      <div class="vendor-card">
        <div class="vc-header">
          <div class="vc-logo"><?= strtoupper(substr($v['company']?:$v['name'],0,1)) ?></div>
          <div style="min-width:0;flex:1">
            <div class="vc-name"><?= sH($v['company']?:$v['name']) ?></div>
            <?php if($v['tagline']): ?><div class="vc-tag"><?= sH($v['tagline']) ?></div><?php endif; ?>
            <?php if($v['is_verified']): ?><span class="badge badge-green" style="margin-top:5px">✓ Verified</span><?php endif; ?>
          </div>
        </div>
        <div style="display:flex;gap:16px;margin:12px 0;font-size:12.5px;color:var(--n500)">
          <div><strong style="display:block;font-size:18px;font-weight:700;color:var(--n900)"><?= $v['prod_count'] ?></strong>Products</div>
          <?php if($v['city']): ?><div><strong style="display:block;font-size:13px;font-weight:600;color:var(--n700)"><?= sH($v['city']) ?></strong>Location</div><?php endif; ?>
          <?php if($v['established_yr']): ?><div><strong style="display:block;font-size:13px;font-weight:600;color:var(--n700)"><?= $v['established_yr'] ?></strong>Est. Year</div><?php endif; ?>
        </div>
        <a href="<?= BASE_URL ?>/public/vendor-profile.php?id=<?= $v['id'] ?>" class="btn btn-outline btn-full btn-sm">View Products →</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php $pages=ceil($total/$perPage); if($pages>1): ?>
    <div class="pagination">
      <?php if($page>1): ?><a href="?q=<?= urlencode($q) ?>&page=<?= $page-1 ?>" class="page-btn">‹</a><?php endif; ?>
      <?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a href="?q=<?= urlencode($q) ?>&page=<?= $i ?>" class="page-btn <?= $i==$page?'active':'' ?>"><?= $i ?></a><?php endfor; ?>
      <?php if($page<$pages): ?><a href="?q=<?= urlencode($q) ?>&page=<?= $page+1 ?>" class="page-btn">›</a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">🏭</div><h3>No vendors found</h3><p>Try a different search.</p></div>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__.'/includes/footer.php'; ?>
