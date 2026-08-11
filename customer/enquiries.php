<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('customer');
$user = currentUser();
$uid  = $user['id'];

// Matches by account link (customer_id) first, and also catches any enquiries
// submitted with this account's email before the account-link existed or
// while not logged in at the time — so nothing "disappears" from history.
$matchWhere = "(we.customer_id = ? OR (we.customer_id IS NULL AND we.email = ?))";
$matchParams = [$uid, $user['email']];

$page = max(1,(int)($_GET['page']??1)); $perPage=15; $offset=($page-1)*$perPage;

$total = $pdo->prepare("SELECT COUNT(*) FROM web_enquiries we WHERE $matchWhere");
$total->execute($matchParams); $total = $total->fetchColumn();

$p2 = $matchParams; $p2[]=$perPage; $p2[]=$offset;
$stmt = $pdo->prepare("SELECT we.*, v.name AS vendor_name, v.company AS vendor_company, v.email AS vendor_email, v.phone AS vendor_phone, p.name AS product_name
    FROM web_enquiries we
    LEFT JOIN users v ON v.id=we.vendor_id
    LEFT JOIN products p ON p.id=we.product_id
    WHERE $matchWhere ORDER BY we.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute($p2);
$enquiries = $stmt->fetchAll();

$pageTitle='My Enquiries'; $activePage='enquiries';
include __DIR__ . '/../includes/head.php';
?>
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <h1>My Enquiries</h1>
    </div>
    <div class="topbar-right"><?php include __DIR__ . '/../includes/topbar-user-menu.php'; ?></div>
</div>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header">
        <h1>📩 My Enquiries <span style="font-size:14px;font-weight:400;color:var(--text-muted)">(<?= $total ?>)</span></h1>
    </div>
    <div class="card">
        <?php if ($enquiries): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Vendor</th><th>Product</th><th>Status</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($enquiries as $e): ?>
                <tr>
                    <td><?= sanitize($e['vendor_company'] ?: $e['vendor_name'] ?: '—') ?></td>
                    <td><?= sanitize($e['product_name'] ?? '—') ?></td>
                    <td><?= statusBadge($e['status']) ?></td>
                    <td class="text-muted"><?= timeAgo($e['created_at']) ?></td>
                    <td><button class="btn btn-outline btn-xs" onclick='showEnq(<?= json_encode($e, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>View →</button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total,$perPage,$page,'?') ?>
        <?php else: ?>
            <div class="empty-state"><div class="es-icon">📭</div><p>No enquiries yet. Enquiries you send to vendors from a product page will show up here.</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- Enquiry Detail Modal -->
<div id="enq-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:20px">
  <div style="background:#fff;border-radius:12px;max-width:520px;width:100%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.25);max-height:85vh;overflow-y:auto">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
      <h3 style="font-size:16px;font-weight:700">Enquiry Details</h3>
      <button onclick="document.getElementById('enq-modal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;color:#6b7280">✕</button>
    </div>
    <div style="font-size:13px;color:var(--text-muted);margin-bottom:4px">Sent to</div>
    <div id="enq-vendor" style="font-weight:600;margin-bottom:2px"></div>
    <div id="enq-vendor-contact" style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px"></div>
    <div style="font-size:13px;color:var(--text-muted);margin-bottom:4px">Product</div>
    <div id="enq-product" style="font-weight:600;margin-bottom:14px"></div>
    <div style="font-size:13px;color:var(--text-muted);margin-bottom:4px">Your Message</div>
    <p id="enq-message" style="font-size:14px;line-height:1.7;color:#374151;white-space:pre-wrap;margin-bottom:16px"></p>
    <a id="enq-reply-link" href="#" class="btn btn-primary btn-sm">✉️ Follow Up by Email</a>
  </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script>
function showEnq(e){
  document.getElementById('enq-vendor').textContent = e.vendor_company || e.vendor_name || 'Vendor';
  document.getElementById('enq-vendor-contact').textContent = [e.vendor_email, e.vendor_phone].filter(Boolean).join(' · ');
  document.getElementById('enq-product').textContent = e.product_name || 'General enquiry (no specific product)';
  document.getElementById('enq-message').textContent = e.message || 'No message.';
  document.getElementById('enq-reply-link').href = 'mailto:' + (e.vendor_email || '');
  document.getElementById('enq-modal').style.display = 'flex';
}
document.getElementById('hamburger').addEventListener('click',()=>{document.getElementById('sidebar').classList.add('open');document.getElementById('sidebar-overlay').classList.add('show');});
document.getElementById('sidebar-overlay').addEventListener('click',()=>{document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.remove('show');});
</script>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
