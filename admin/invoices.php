<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    logAdminActivity($pdo, 'invoice.export_csv');
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="invoices_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Invoice #','Vendor / Customer','Company','Type','Description','Amount','Payment Ref','Date']);
    $rows = $pdo->query(
        "SELECT i.*, u.name AS vendor_name FROM invoices i JOIN users u ON u.id=i.vendor_id ORDER BY i.issued_at DESC"
    )->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, [$r['invoice_number'], $r['vendor_name'], $r['billing_company'], ucfirst($r['type']), $r['description'], $r['amount'], $r['payment_ref'], date('d M Y', strtotime($r['issued_at']))]);
    }
    exit;
}

$search = trim($_GET['search'] ?? '');
$type   = $_GET['type'] ?? '';
$page   = max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;

$where = "WHERE 1=1"; $params=[];
if ($search) { $where .= " AND (i.invoice_number LIKE ? OR u.name LIKE ? OR u.company LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; }
if ($type)   { $where .= " AND i.type=?"; $params[]=$type; }

$total = $pdo->prepare("SELECT COUNT(*) FROM invoices i JOIN users u ON u.id=i.vendor_id $where");
$total->execute($params); $total = $total->fetchColumn();

$p2 = $params; $p2[]=$perPage; $p2[]=$offset;
$stmt = $pdo->prepare(
    "SELECT i.*, u.name AS vendor_name, u.company AS vendor_company
     FROM invoices i JOIN users u ON u.id=i.vendor_id
     $where ORDER BY i.issued_at DESC LIMIT ? OFFSET ?"
);
$stmt->execute($p2);
$invoices = $stmt->fetchAll();

$totalRevenue = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices")->fetchColumn();
$monthRevenue = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE MONTH(issued_at)=MONTH(CURDATE()) AND YEAR(issued_at)=YEAR(CURDATE())")->fetchColumn();

$pageTitle = 'Invoices'; $activePage = 'invoices';
include __DIR__ . '/../includes/head.php';
?>
<?php // renderTopbar('Invoices', '<a href="?export=csv" class="btn btn-outline btn-sm">📥 Export CSV</a>'); ?>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header">
        <h1>🧾 All Invoices <span style="font-size:14px;font-weight:400;color:var(--text-muted)">(<?= $total ?>)</span></h1>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px">
        <div class="card"><div class="card-body">
            <div style="font-size:12.5px;color:var(--text-muted)">Total Invoiced Revenue</div>
            <div style="font-size:24px;font-weight:800;color:var(--primary)">₹<?= number_format($totalRevenue) ?></div>
        </div></div>
        <div class="card"><div class="card-body">
            <div style="font-size:12.5px;color:var(--text-muted)">This Month</div>
            <div style="font-size:24px;font-weight:800;color:#16a34a">₹<?= number_format($monthRevenue) ?></div>
        </div></div>
    </div>

    <div class="card">
        <div class="card-header">
            <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;width:100%">
                <div class="search-wrap" style="flex:1;min-width:180px">
                    <input type="text" name="search" value="<?= sanitize($search) ?>" placeholder="Search invoice #, vendor, or company..." class="form-control">
                </div>
                <select name="type" class="form-control" style="width:160px">
                    <option value="">All Types</option>
                    <option value="subscription"          <?= $type==='subscription'?'selected':'' ?>>Vendor Subscription</option>
                    <option value="customer_subscription" <?= $type==='customer_subscription'?'selected':'' ?>>Customer Subscription</option>
                    <option value="ad"                    <?= $type==='ad'?'selected':'' ?>>Advertising</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if ($search||$type): ?><a href="?" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
            </form>
        </div>
        <?php if ($invoices): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Invoice #</th><th>Vendor / Customer</th><th>Description</th><th>Type</th><th>Amount</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td><code style="font-size:12.5px"><?= sanitize($inv['invoice_number']) ?></code></td>
                    <td>
                        <div style="font-weight:600"><?= sanitize($inv['vendor_company'] ?: $inv['vendor_name']) ?></div>
                        <div style="font-size:11.5px;color:var(--text-muted)"><?= sanitize($inv['vendor_name']) ?></div>
                    </td>
                    <td style="font-size:13px"><?= sanitize($inv['description']) ?></td>
                    <td><span class="badge <?= $inv['type']==='ad'?'badge-warning':'badge-info' ?>"><?= match($inv['type']){'subscription'=>'Vendor Sub','customer_subscription'=>'Customer Sub','ad'=>'Advertising',default=>ucfirst($inv['type'])} ?></span></td>
                    <td style="font-weight:700">₹<?= number_format($inv['amount'],2) ?></td>
                    <td class="text-muted" style="font-size:12.5px"><?= date('d M Y', strtotime($inv['issued_at'])) ?></td>
                    <td><a href="invoice.php?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-outline btn-xs">📄 View</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total,$perPage,$page,'?search='.urlencode($search).'&type='.$type) ?>
        <?php else: ?>
            <div class="empty-state"><div class="es-icon">🧾</div><p>No invoices found.</p></div>
        <?php endif; ?>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
