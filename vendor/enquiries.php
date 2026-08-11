<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('vendor');
require_once __DIR__ . '/../includes/team.php';
requirePermission('enquiries');
require_once __DIR__ . '/../includes/subscription.php';
$user = currentUser();
$uid  = effectiveVendorId();

// Status update (mark contacted / close) — vendor can only touch their own leads
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    $s  = $_GET['action']==='close' ? 'closed' : ($_GET['action']==='contact' ? 'contacted' : 'new');
    $pdo->prepare("UPDATE web_enquiries SET status=? WHERE id=? AND vendor_id=?")->execute([$s, $id, $uid]);
    flash('success','Enquiry updated.'); header('Location: enquiries.php'); exit;
}

$status = $_GET['status'] ?? '';
$page   = max(1,(int)($_GET['page']??1)); $perPage=15; $offset=($page-1)*$perPage;
$where  = "WHERE we.vendor_id=?"; $params=[$uid];
if ($status) { $where .= " AND we.status=?"; $params[]=$status; }

$total = $pdo->prepare("SELECT COUNT(*) FROM web_enquiries we $where");
$total->execute($params); $total = $total->fetchColumn();

$p2 = $params; $p2[]=$perPage; $p2[]=$offset;
$stmt = $pdo->prepare("SELECT we.*, p.name AS product_name FROM web_enquiries we LEFT JOIN products p ON p.id=we.product_id $where ORDER BY we.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute($p2);
$enquiries = $stmt->fetchAll();

$pageTitle='Enquiries'; $activePage='enquiries';
include __DIR__ . '/../includes/head.php';
?>
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <h1>Enquiries</h1>
    </div>
    <div class="topbar-right"><?php include __DIR__ . '/../includes/topbar-user-menu.php'; ?></div>
</div>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header">
        <h1>📩 My Enquiries <span style="font-size:14px;font-weight:400;color:var(--text-muted)">(<?= $total ?>)</span></h1>
    </div>
    <div class="card">
        <div class="card-header">
            <form method="GET" style="display:flex;gap:10px">
                <select name="status" class="form-control" style="width:160px" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="new"       <?= $status==='new'?'selected':'' ?>>New</option>
                    <option value="contacted" <?= $status==='contacted'?'selected':'' ?>>Contacted</option>
                    <option value="closed"    <?= $status==='closed'?'selected':'' ?>>Closed</option>
                </select>
                <?php if ($status): ?><a href="?" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
            </form>
        </div>
        <?php if ($enquiries): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>#</th><th>Customer</th><th>Contact</th><th>Company/City</th><th>Product</th><th>Qty</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($enquiries as $i => $e):
                    $sc = ['new'=>'badge-info','contacted'=>'badge-warning','closed'=>'badge-secondary']; ?>
                <tr>
                    <td class="text-muted"><?= $offset+$i+1 ?></td>
                    <td><strong><?= sanitize($e['name']) ?></strong></td>
                    <td style="font-size:12.5px"><div><?= sanitize($e['email']) ?></div><?php if($e['phone']): ?><div class="text-muted"><?= sanitize($e['phone']) ?></div><?php endif; ?></td>
                    <td style="font-size:12.5px"><div><?= sanitize($e['company']?:'—') ?></div><div class="text-muted"><?= sanitize($e['city']?:'—') ?></div></td>
                    <td style="font-size:12.5px"><?= sanitize($e['product_name'] ?: 'General') ?></td>
                    <td style="font-size:12.5px"><?= sanitize($e['qty_needed']?:'—') ?></td>
                    <td><span class="badge <?= $sc[$e['status']]??'badge-secondary' ?>"><?= ucfirst($e['status']) ?></span></td>
                    <td class="text-muted" style="font-size:12.5px"><?= timeAgo($e['created_at']) ?></td>
                    <td>
                        <div class="td-actions">
                            <?php if ($e['status']==='new'): ?><a href="?action=contact&id=<?= $e['id'] ?>" class="btn btn-warning btn-xs">Mark Contacted</a><?php endif; ?>
                            <?php if ($e['status']!=='closed'): ?><a href="?action=close&id=<?= $e['id'] ?>" class="btn btn-outline btn-xs">Close</a><?php endif; ?>
                            <button class="btn btn-primary btn-xs" onclick="showMsg(<?= htmlspecialchars(json_encode($e['message']??''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($e['email']), ENT_QUOTES) ?>)">View</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total,$perPage,$page,'?status='.$status) ?>
        <?php else: ?>
            <div class="empty-state"><div class="es-icon">📭</div><p>No enquiries yet. When a customer sends an enquiry on one of your products, it'll show up here.</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- Message Modal -->
<div id="msg-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;max-width:500px;width:90%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <h3 style="font-size:16px;font-weight:700">Enquiry Message</h3>
      <button onclick="document.getElementById('msg-modal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;color:#6b7280">✕</button>
    </div>
    <p id="msg-content" style="font-size:14px;line-height:1.7;color:#374151;white-space:pre-wrap;margin-bottom:16px"></p>
    <a id="msg-reply-link" href="#" class="btn btn-primary btn-sm">✉️ Reply by Email</a>
  </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script>
function showMsg(msg,email){
  document.getElementById('msg-content').textContent = msg || 'No message.';
  document.getElementById('msg-reply-link').href = 'mailto:' + email;
  document.getElementById('msg-modal').style.display='flex';
}
document.getElementById('hamburger').addEventListener('click',()=>{document.getElementById('sidebar').classList.add('open');document.getElementById('sidebar-overlay').classList.add('show');});
document.getElementById('sidebar-overlay').addEventListener('click',()=>{document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.remove('show');});
</script>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
