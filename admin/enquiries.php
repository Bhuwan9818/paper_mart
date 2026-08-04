<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$page   = max(1,(int)($_GET['page']??1)); $perPage=15; $offset=($page-1)*$perPage;

$where = "WHERE 1=1"; $params=[];
if ($search) { $where.=" AND (cu.name LIKE ? OR v.name LIKE ?)"; $params[]= "%$search%"; $params[]= "%$search%"; }
if ($status) { $where.=" AND e.status=?"; $params[]=$status; }

$total=$pdo->prepare("SELECT COUNT(*) FROM enquiries e JOIN users cu ON cu.id=e.customer_id JOIN users v ON v.id=e.vendor_id $where");
$total->execute($params); $total=$total->fetchColumn();
$params[]=$perPage; $params[]=$offset;
$stmt=$pdo->prepare("SELECT e.*, cu.name AS customer_name, v.name AS vendor_name, p.name AS product_name,
    (SELECT COUNT(*) FROM enquiry_messages WHERE enquiry_id=e.id) AS msg_count
    FROM enquiries e
    JOIN users cu ON cu.id=e.customer_id
    JOIN users v  ON v.id=e.vendor_id
    LEFT JOIN products p ON p.id=e.product_id
    $where ORDER BY e.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute($params); $enquiries=$stmt->fetchAll();

$pageTitle='All Enquiries'; $activePage='enquiries';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <h1>All Enquiries</h1>
    </div>
</div>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header">
        <h1>📩 All Enquiries <span style="font-size:14px;font-weight:400;color:var(--text-muted)">(<?= $total ?>)</span></h1>
    </div>
    <div class="card">
        <div class="card-header">
            <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;width:100%">
                <div class="search-wrap" style="flex:1;min-width:180px">
                    <input type="text" name="search" value="<?= sanitize($search) ?>" placeholder="Search customer or vendor..." class="form-control">
                </div>
                <select name="status" class="form-control" style="width:160px">
                    <option value="">All Status</option>
                    <option value="open"        <?= $status==='open'?'selected':''?>>Open</option>
                    <option value="in_progress" <?= $status==='in_progress'?'selected':''?>>In Progress</option>
                    <option value="closed"      <?= $status==='closed'?'selected':''?>>Closed</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if ($search||$status): ?><a href="?" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
            </form>
        </div>
        <?php if ($enquiries): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>#</th><th>Customer</th><th>Vendor</th><th>Product</th><th>Subject</th><th>Messages</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ($enquiries as $i => $e): ?>
                <tr>
                    <td class="text-muted"><?= $offset+$i+1 ?></td>
                    <td><?= sanitize($e['customer_name']) ?></td>
                    <td><?= sanitize($e['vendor_name']) ?></td>
                    <td><?= sanitize($e['product_name'] ?? '—') ?></td>
                    <td><?= sanitize($e['subject'] ?: 'General Enquiry') ?></td>
                    <td style="font-weight:600"><?= $e['msg_count'] ?></td>
                    <td><?= statusBadge($e['status']) ?></td>
                    <td class="text-muted"><?= timeAgo($e['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total,$perPage,$page,'?search='.urlencode($search).'&status='.$status) ?>
        <?php else: ?>
            <div class="empty-state"><div class="es-icon">📭</div><p>No enquiries found.</p></div>
        <?php endif; ?>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
