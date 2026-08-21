<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('vendor');
require_once __DIR__ . '/../includes/team.php';
requireVendorOwner(); // billing documents are the account owner's business only

$user = currentUser();
$vid  = effectiveVendorId();

$type = $_GET['type'] ?? '';
$page = max(1,(int)($_GET['page']??1)); $perPage=15; $offset=($page-1)*$perPage;

$where = "WHERE vendor_id=?"; $params=[$vid];
if ($type) { $where .= " AND type=?"; $params[]=$type; }

$total = $pdo->prepare("SELECT COUNT(*) FROM invoices $where");
$total->execute($params); $total = $total->fetchColumn();

$p2 = $params; $p2[]=$perPage; $p2[]=$offset;
$stmt = $pdo->prepare("SELECT * FROM invoices $where ORDER BY issued_at DESC LIMIT ? OFFSET ?");
$stmt->execute($p2);
$invoices = $stmt->fetchAll();

$totalPaid = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE vendor_id=?");
$totalPaid->execute([$vid]); $totalPaid = $totalPaid->fetchColumn();

$pageTitle = 'Invoices'; $activePage = 'invoices';
include __DIR__ . '/../includes/head.php';
?>
<?php // renderTopbar('Invoices'); ?>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header">
        <h1>🧾 Invoices <span style="font-size:14px;font-weight:400;color:var(--text-muted)">(<?= $total ?>)</span></h1>
    </div>

    <div class="card" style="margin-bottom:20px">
        <div class="card-body">
            <div style="font-size:13px;color:var(--text-muted)">Total Paid to Date</div>
            <div style="font-size:26px;font-weight:800;color:var(--primary)">₹<?= number_format($totalPaid,2) ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <form method="GET" style="display:flex;gap:10px">
                <select name="type" class="form-control" style="width:180px" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <option value="subscription" <?= $type==='subscription'?'selected':'' ?>>Subscription</option>
                    <option value="ad"           <?= $type==='ad'?'selected':'' ?>>Advertising</option>
                </select>
                <?php if ($type): ?><a href="?" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
            </form>
        </div>
        <?php if ($invoices): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Invoice #</th><th>Description</th><th>Type</th><th>Amount</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td><code style="font-size:12.5px"><?= sanitize($inv['invoice_number']) ?></code></td>
                    <td><?= sanitize($inv['description']) ?></td>
                    <td><span class="badge <?= $inv['type']==='subscription'?'badge-info':'badge-warning' ?>"><?= ucfirst($inv['type']) ?></span></td>
                    <td style="font-weight:700">₹<?= number_format($inv['amount'],2) ?></td>
                    <td class="text-muted" style="font-size:12.5px"><?= date('d M Y', strtotime($inv['issued_at'])) ?></td>
                    <td><a href="invoice.php?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-outline btn-xs">📄 View / Download</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total,$perPage,$page,'?type='.$type) ?>
        <?php else: ?>
            <div class="empty-state"><div class="es-icon">🧾</div><p>No invoices yet. Every subscription or ad payment automatically generates one here.</p></div>
        <?php endif; ?>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
