<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('customer');
$user = currentUser();
$uid  = $user['id'];

// Single enquiry view
if (isset($_GET['id'])) {
    $eid = (int)$_GET['id'];
    $s = $pdo->prepare("SELECT e.*,v.name AS vendor_name,v.email AS vendor_email,v.phone AS vendor_phone,v.company AS vendor_company,p.name AS product_name FROM enquiries e JOIN users v ON v.id=e.vendor_id LEFT JOIN products p ON p.id=e.product_id WHERE e.id=? AND e.customer_id=?");
    $s->execute([$eid,$uid]); $enq=$s->fetch();
    if (!$enq) { flash('error','Not found.'); header('Location: ?'); exit; }

    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['message'])) {
        verifyCsrf();
        $msg=trim($_POST['message']);
        if ($msg) {
            $pdo->prepare("INSERT INTO enquiry_messages (enquiry_id,sender_id,message) VALUES(?,?,?)")->execute([$eid,$uid,$msg]);
            header('Location: ?id='.$eid); exit;
        }
    }
    $msgs=$pdo->prepare("SELECT m.*,u.name AS sender_name,u.role AS sender_role FROM enquiry_messages m JOIN users u ON u.id=m.sender_id WHERE m.enquiry_id=? ORDER BY m.created_at ASC");
    $msgs->execute([$eid]); $messages=$msgs->fetchAll();

    $pageTitle='Enquiry #'.$eid; $activePage='enquiries';
    include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<div class="topbar">
    <div class="topbar-left"><button class="hamburger" id="hamburger"><span></span><span></span><span></span></button><h1>Enquiry #<?= $eid ?></h1></div>
    <div class="topbar-right"><a href="?" class="btn btn-outline btn-sm">← All Enquiries</a></div>
</div>
<div class="content">
    <?= showFlash() ?>
    <div style="display:grid;grid-template-columns:1fr 280px;gap:22px;align-items:start">
        <div class="card">
            <div class="card-header"><h2>💬 Conversation</h2><?= statusBadge($enq['status']) ?></div>
            <div class="card-body">
                <div class="enquiry-thread">
                <?php foreach ($messages as $m): ?>
                    <?php $isMine=($m['sender_role']==='customer'); ?>
                    <div style="display:flex;flex-direction:column;align-items:<?= $isMine?'flex-end':'flex-start' ?>">
                        <div class="chat-bubble <?= $isMine?'sent':'received' ?>"><?= nl2br(sanitize($m['message'])) ?></div>
                        <div class="chat-meta" style="<?= $isMine?'text-align:right':'' ?>"><?= sanitize($m['sender_name']) ?> · <?= timeAgo($m['created_at']) ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$messages): ?><p style="color:var(--text-muted);font-size:13px">No messages yet. Start the conversation!</p><?php endif; ?>
                </div>
                <?php if ($enq['status']!=='closed'): ?>
                <form method="POST" style="margin-top:20px">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <div class="form-group"><label class="form-label">Your Message</label><textarea name="message" class="form-control" rows="4" required></textarea></div>
                    <button type="submit" class="btn btn-primary">Send 📤</button>
                </form>
                <?php else: ?><p style="margin-top:16px;color:var(--text-muted);font-size:13px">This enquiry is closed.</p><?php endif; ?>
            </div>
        </div>
        <div>
            <div class="card">
                <div class="card-header"><h2>🏪 Vendor</h2></div>
                <div class="card-body" style="font-size:13px">
                    <p style="font-weight:600"><?= sanitize($enq['vendor_name']) ?></p>
                    <?php if ($enq['vendor_company']): ?><p class="text-muted">🏢 <?= sanitize($enq['vendor_company']) ?></p><?php endif; ?>
                    <p class="text-muted">📧 <?= sanitize($enq['vendor_email']) ?></p>
                    <?php if ($enq['vendor_phone']): ?><p class="text-muted">📱 <?= sanitize($enq['vendor_phone']) ?></p><?php endif; ?>
                </div>
            </div>
            <?php if ($enq['product_name']): ?>
            <div class="card"><div class="card-header"><h2>📦 Product</h2></div><div class="card-body"><p style="font-size:13px"><?= sanitize($enq['product_name']) ?></p></div></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
<?php exit; }

// List
$page=max(1,(int)($_GET['page']??1)); $perPage=12; $offset=($page-1)*$perPage;
$total=$pdo->prepare("SELECT COUNT(*) FROM enquiries WHERE customer_id=?"); $total->execute([$uid]); $total=$total->fetchColumn();
$stmt=$pdo->prepare("SELECT e.*,v.name AS vendor_name,p.name AS product_name FROM enquiries e JOIN users v ON v.id=e.vendor_id LEFT JOIN products p ON p.id=e.product_id WHERE e.customer_id=? ORDER BY e.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$uid,$perPage,$offset]); $enquiries=$stmt->fetchAll();
$pageTitle='My Enquiries'; $activePage='enquiries';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<div class="topbar">
    <div class="topbar-left"><button class="hamburger" id="hamburger"><span></span><span></span><span></span></button><h1>My Enquiries</h1></div>
</div>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header"><h1>📩 My Enquiries <span style="font-size:14px;font-weight:400;color:var(--text-muted)">(<?= $total ?>)</span></h1></div>
    <div class="card">
        <?php if ($enquiries): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Vendor</th><th>Product</th><th>Subject</th><th>Status</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($enquiries as $e): ?>
                <tr>
                    <td><?= sanitize($e['vendor_name']) ?></td>
                    <td><?= sanitize($e['product_name']??'—') ?></td>
                    <td><?= sanitize($e['subject']?:'General Enquiry') ?></td>
                    <td><?= statusBadge($e['status']) ?></td>
                    <td class="text-muted"><?= timeAgo($e['created_at']) ?></td>
                    <td><a href="?id=<?= $e['id'] ?>" class="btn btn-outline btn-xs">View →</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total,$perPage,$page,'?') ?>
        <?php else: ?>
            <div class="empty-state"><div class="es-icon">📭</div><p>No enquiries yet.</p></div>
        <?php endif; ?>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
