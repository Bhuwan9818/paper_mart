<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

// Handle actions
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    match($_GET['action']) {
        'activate'   => $pdo->prepare("UPDATE users SET status='active'   WHERE id=? AND role='vendor'")->execute([$id]),
        'deactivate' => $pdo->prepare("UPDATE users SET status='inactive' WHERE id=? AND role='vendor'")->execute([$id]),
        'delete'     => $pdo->prepare("DELETE FROM users WHERE id=? AND role='vendor'")->execute([$id]),
        default      => null
    };
    flash('success','Vendor updated.'); header('Location: vendors.php'); exit;
}

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1,(int)($_GET['page']??1)); $perPage=12; $offset=($page-1)*$perPage;

$where="WHERE role='vendor'"; $params=[];
if ($search) { $where.=" AND (name LIKE ? OR email LIKE ? OR company LIKE ?)"; $params[]= "%$search%"; $params[]= "%$search%"; $params[]= "%$search%"; }
if ($status) { $where.=" AND status=?"; $params[]=$status; }

$total=$pdo->prepare("SELECT COUNT(*) FROM users $where"); $total->execute($params); $total=$total->fetchColumn();
$params[]=$perPage; $params[]=$offset;
$stmt=$pdo->prepare("SELECT u.*, (SELECT COUNT(*) FROM products WHERE vendor_id=u.id) AS product_count, (SELECT COUNT(*) FROM enquiries WHERE vendor_id=u.id) AS enquiry_count FROM users u $where ORDER BY u.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute($params); $vendors=$stmt->fetchAll();

$pageTitle='Manage Vendors'; $activePage='vendors';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <h1>Vendors</h1>
    </div>
</div>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header">
        <h1>🏪 All Vendors <span style="font-size:14px;font-weight:400;color:var(--text-muted)">(<?= $total ?>)</span></h1>
    </div>
    <div class="card">
        <div class="card-header">
            <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;width:100%">
                <div class="search-wrap" style="flex:1;min-width:180px">
                    <input type="text" name="search" value="<?= sanitize($search) ?>" placeholder="Search name, email, company..." class="form-control">
                </div>
                <select name="status" class="form-control" style="width:140px">
                    <option value="">All Status</option>
                    <option value="active"   <?= $status==='active'  ?'selected':''?>>Active</option>
                    <option value="inactive" <?= $status==='inactive'?'selected':''?>>Inactive</option>
                    <option value="pending"  <?= $status==='pending' ?'selected':''?>>Pending</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if ($search||$status): ?><a href="?" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
            </form>
        </div>
        <?php if ($vendors): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>#</th><th>Vendor</th><th>Contact</th><th>Company</th><th>Products</th><th>Enquiries</th><th>Verified</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($vendors as $i => $v): ?>
                <tr>
                    <td class="text-muted"><?= $offset+$i+1 ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><?= avatarLetter($v['name']) ?></div>
                            <div>
                                <div style="font-weight:500"><?= sanitize($v['name']) ?></div>
                                <div style="font-size:12px;color:var(--text-muted)"><?= sanitize($v['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?= sanitize($v['phone'] ?: '—') ?></td>
                    <td><?= sanitize($v['company'] ?: '—') ?></td>
                    <td><a href="<?= BASE_URL ?>/admin/products.php?search=<?= urlencode($v['name']) ?>" style="font-weight:600"><?= $v['product_count'] ?></a></td>
                    <td><?= $v['enquiry_count'] ?></td>
                    <td>
                        <?php
                        $vpRow = $pdo->prepare("SELECT is_verified FROM vendor_profiles WHERE vendor_id=?");
                        $vpRow->execute([$v['id']]); $vpRow=$vpRow->fetch();
                        echo ($vpRow&&$vpRow['is_verified'])
                            ? '<span style="color:#10b981;font-weight:700">✓ Yes</span>'
                            : '<span style="color:#94a3b8;font-size:12px">—</span>';
                        ?>
                    </td>
                    <td><?= statusBadge($v['status']) ?></td>
                    <td class="text-muted"><?= date('d M Y', strtotime($v['created_at'])) ?></td>
                    <td>
                        <div class="td-actions">
                            <a href="vendor-profile.php?id=<?= $v['id'] ?>" class="btn btn-outline btn-xs" title="Edit Profile">✏️ Profile</a>
                            <?php if ($v['status']==='active'): ?>
                                <a href="?action=deactivate&id=<?= $v['id'] ?>" class="btn btn-warning btn-xs" onclick="return confirm('Deactivate this vendor?')">⏸</a>
                            <?php else: ?>
                                <a href="?action=activate&id=<?= $v['id'] ?>" class="btn btn-success btn-xs" onclick="return confirm('Activate this vendor?')">▶</a>
                            <?php endif; ?>
                            <a href="?action=delete&id=<?= $v['id'] ?>" class="btn btn-danger btn-xs" onclick="return confirm('Delete vendor and all their products?')">🗑</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total,$perPage,$page,'?search='.urlencode($search).'&status='.$status) ?>
        <?php else: ?>
            <div class="empty-state"><div class="es-icon">🏪</div><p>No vendors found.</p></div>
        <?php endif; ?>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
