<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('customer');

$user = currentUser();
$cid  = $user['id'];

$page = max(1,(int)($_GET['page']??1)); $perPage=15; $offset=($page-1)*$perPage;

$total = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE vendor_id=? AND type='customer_subscription'");
$total->execute([$cid]); $total = $total->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM invoices WHERE vendor_id=? AND type='customer_subscription' ORDER BY issued_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$cid, $perPage, $offset]);
$invoices = $stmt->fetchAll();

$pageTitle = 'Invoices'; $activePage = 'invoices';
include __DIR__ . '/../includes/head.php';
?>
<?php // renderTopbar('Invoices'); ?>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header">
        <h1>🧾 Invoices <span style="font-size:14px;font-weight:400;color:var(--text-muted)">(<?= $total ?>)</span></h1>
    </div>
    <div class="card">
        <?php if ($invoices): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Invoice #</th><th>Description</th><th>Amount</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td><code style="font-size:12.5px"><?= sanitize($inv['invoice_number']) ?></code></td>
                    <td><?= sanitize($inv['description']) ?></td>
                    <td style="font-weight:700">₹<?= number_format($inv['amount'],2) ?></td>
                    <td class="text-muted" style="font-size:12.5px"><?= date('d M Y', strtotime($inv['issued_at'])) ?></td>
                    <td><a href="invoice.php?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-outline btn-xs">📄 View / Download</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total,$perPage,$page,'?') ?>
        <?php else: ?>
            <div class="empty-state"><div class="es-icon">🧾</div><p>No invoices yet. Upgrading to Premium will generate one here automatically.</p></div>
        <?php endif; ?>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
