<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    match($_GET['action']) {
        'activate'   => $pdo->prepare("UPDATE users SET status='active'   WHERE id=? AND role='customer'")->execute([$id]),
        'deactivate' => $pdo->prepare("UPDATE users SET status='inactive' WHERE id=? AND role='customer'")->execute([$id]),
        'delete'     => $pdo->prepare("DELETE FROM users WHERE id=? AND role='customer'")->execute([$id]),
        default      => null
    };
    flash('success','Customer updated.'); header('Location: customers.php'); exit;
}

$search = trim($_GET['search'] ?? '');
$page = max(1,(int)($_GET['page']??1)); $perPage=12; $offset=($page-1)*$perPage;
$where="WHERE role='customer'"; $params=[];
if ($search) { $where.=" AND (name LIKE ? OR email LIKE ? OR company LIKE ?)"; $params[]= "%$search%"; $params[]= "%$search%"; $params[]= "%$search%"; }
$total=$pdo->prepare("SELECT COUNT(*) FROM users $where"); $total->execute($params); $total=$total->fetchColumn();
$params[]=$perPage; $params[]=$offset;
$stmt=$pdo->prepare("SELECT u.*, (SELECT COUNT(*) FROM enquiries WHERE customer_id=u.id) AS enquiry_count FROM users u $where ORDER BY u.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute($params); $customers=$stmt->fetchAll();

$pageTitle='Customers'; $activePage='customers';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <h1>Customers</h1>
    </div>
</div>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header">
        <h1>👥 All Customers <span style="font-size:14px;font-weight:400;color:var(--text-muted)">(<?= $total ?>)</span></h1>
    </div>
    <div class="card">
        <div class="card-header">
            <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;width:100%">
                <div class="search-wrap" style="flex:1;min-width:180px">
                    <input type="text" name="search" value="<?= sanitize($search) ?>" placeholder="Search name, email, company..." class="form-control">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if ($search): ?><a href="?" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
            </form>
        </div>
        <?php if ($customers): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>#</th><th>Customer</th><th>Phone</th><th>Company</th><th>Enquiries</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($customers as $i => $c): ?>
                <tr>
                    <td class="text-muted"><?= $offset+$i+1 ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="width:32px;height:32px;border-radius:50%;background:#10b981;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><?= avatarLetter($c['name']) ?></div>
                            <div>
                                <div style="font-weight:500"><?= sanitize($c['name']) ?></div>
                                <div style="font-size:12px;color:var(--text-muted)"><?= sanitize($c['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?= sanitize($c['phone'] ?: '—') ?></td>
                    <td><?= sanitize($c['company'] ?: '—') ?></td>
                    <td style="font-weight:600"><?= $c['enquiry_count'] ?></td>
                    <td><?= statusBadge($c['status']) ?></td>
                    <td class="text-muted"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
                    <td>
                        <div class="td-actions">
                            <?php if ($c['status']==='active'): ?>
                                <a href="?action=deactivate&id=<?= $c['id'] ?>" class="btn btn-warning btn-xs" onclick="return confirm('Deactivate?')">⏸</a>
                            <?php else: ?>
                                <a href="?action=activate&id=<?= $c['id'] ?>" class="btn btn-success btn-xs" onclick="return confirm('Activate?')">▶</a>
                            <?php endif; ?>
                            <a href="?action=delete&id=<?= $c['id'] ?>" class="btn btn-danger btn-xs" onclick="return confirm('Delete this customer?')">🗑</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total,$perPage,$page,'?search='.urlencode($search)) ?>
        <?php else: ?>
            <div class="empty-state"><div class="es-icon">👥</div><p>No customers found.</p></div>
        <?php endif; ?>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
