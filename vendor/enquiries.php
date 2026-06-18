<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('vendor');
require_once __DIR__ . '/../includes/subscription.php';
$user = currentUser();
$uid  = $user['id'];

// View single enquiry
if (isset($_GET['id'])) {
    $eid  = (int)$_GET['id'];
    $enqS = $pdo->prepare("SELECT e.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone, u.company AS customer_company, p.name AS product_name FROM enquiries e JOIN users u ON u.id=e.customer_id LEFT JOIN products p ON p.id=e.product_id WHERE e.id=? AND e.vendor_id=?");
    $enqS->execute([$eid, $uid]);
    $enq = $enqS->fetch();
    if (!$enq) { flash('error','Enquiry not found.'); header('Location: ?'); exit; }

    // Handle reply
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['message'])) {
        verifyCsrf();
        $msg = trim($_POST['message']);
        if ($msg) {
            $pdo->prepare("INSERT INTO enquiry_messages (enquiry_id,sender_id,message) VALUES(?,?,?)")->execute([$eid,$uid,$msg]);
            $pdo->prepare("UPDATE enquiries SET status='in_progress',updated_at=NOW() WHERE id=?")->execute([$eid]);
            header('Location: ?id='.$eid); exit;
        }
    }
    // Close enquiry
    if (isset($_GET['close'])) {
        $pdo->prepare("UPDATE enquiries SET status='closed' WHERE id=? AND vendor_id=?")->execute([$eid,$uid]);
        flash('success','Enquiry closed.'); header('Location: ?id='.$eid); exit;
    }

    $msgs = $pdo->prepare("SELECT m.*,u.name AS sender_name,u.role AS sender_role FROM enquiry_messages m JOIN users u ON u.id=m.sender_id WHERE m.enquiry_id=? ORDER BY m.created_at ASC");
    $msgs->execute([$eid]);
    $messages = $msgs->fetchAll();

    $pageTitle = 'Enquiry #' . $eid;
    $activePage = 'enquiries';
    include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <h1>Enquiry #<?= $eid ?></h1>
    </div>
    <div class="topbar-right">
        <a href="?" class="btn btn-outline btn-sm">← All Enquiries</a>
        <?php if ($enq['status']!=='closed'): ?>
            <a href="?id=<?= $eid ?>&close=1" class="btn btn-warning btn-sm" onclick="return confirm('Close this enquiry?')">✅ Close Enquiry</a>
        <?php endif; ?>
    </div>
</div>
<div class="content">
    <?= showFlash() ?>
    <div style="display:grid;grid-template-columns:1fr 300px;gap:22px;align-items:start">
        <div>
            <div class="card">
                <div class="card-header">
                    <h2>💬 Conversation</h2>
                    <?= statusBadge($enq['status']) ?>
                </div>
                <div class="card-body">
                    <div class="enquiry-thread">
                    <?php foreach ($messages as $m): ?>
                        <?php $isMine = ($m['sender_role'] === 'vendor'); ?>
                        <div style="display:flex;flex-direction:column;align-items:<?= $isMine?'flex-end':'flex-start' ?>">
                            <div class="chat-bubble <?= $isMine?'sent':'received' ?>">
                                <?= nl2br(sanitize($m['message'])) ?>
                            </div>
                            <div class="chat-meta" style="<?= $isMine?'text-align:right':'' ?>">
                                <?= sanitize($m['sender_name']) ?> · <?= timeAgo($m['created_at']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$messages): ?>
                        <p style="color:var(--text-muted);font-size:13px">No messages yet. Be the first to reply!</p>
                    <?php endif; ?>
                    </div>

                    <?php if ($enq['status']!=='closed'): ?>
                    <form method="POST" style="margin-top:20px">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <div class="form-group">
                            <label class="form-label">Your Reply</label>
                            <textarea name="message" class="form-control chat-form" rows="4" placeholder="Type your response..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Send Reply 📤</button>
                    </form>
                    <?php else: ?>
                        <p style="margin-top:16px;color:var(--text-muted);font-size:13px">This enquiry is closed.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card-header"><h2>👤 Customer Info</h2></div>
                <div class="card-body">
                    <p style="font-size:13px;margin-bottom:8px"><strong><?= sanitize($enq['customer_name']) ?></strong></p>
                    <p style="font-size:12px;color:var(--text-muted);margin-bottom:4px">📧 <?= sanitize($enq['customer_email']) ?></p>
                    <?php if ($enq['customer_phone']): ?><p style="font-size:12px;color:var(--text-muted);margin-bottom:4px">📱 <?= sanitize($enq['customer_phone']) ?></p><?php endif; ?>
                    <?php if ($enq['customer_company']): ?><p style="font-size:12px;color:var(--text-muted)">🏢 <?= sanitize($enq['customer_company']) ?></p><?php endif; ?>
                </div>
            </div>
            <?php if ($enq['product_name']): ?>
            <div class="card">
                <div class="card-header"><h2>📦 About Product</h2></div>
                <div class="card-body">
                    <p style="font-size:13px"><?= sanitize($enq['product_name']) ?></p>
                </div>
            </div>
            <?php endif; ?>
            <div class="card">
                <div class="card-header"><h2>ℹ️ Enquiry Details</h2></div>
                <div class="card-body" style="font-size:12px;color:var(--text-muted)">
                    <p>Subject: <?= sanitize($enq['subject'] ?: 'General Enquiry') ?></p>
                    <p>Received: <?= formatDate($enq['created_at']) ?></p>
                    <p>Status: <?= statusBadge($enq['status']) ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
<?php
    exit;
}

// Enquiry list
$status = $_GET['status'] ?? '';
$page   = max(1,(int)($_GET['page']??1)); $perPage=12; $offset=($page-1)*$perPage;
$where  = "WHERE e.vendor_id=?"; $params=[$uid];
if ($status) { $where .= " AND e.status=?"; $params[]=$status; }
$total = $pdo->prepare("SELECT COUNT(*) FROM enquiries e $where");
$total->execute($params); $total=$total->fetchColumn();
$params[]=$perPage; $params[]=$offset;
$stmt=$pdo->prepare("SELECT e.*,u.name AS customer_name,p.name AS product_name FROM enquiries e JOIN users u ON u.id=e.customer_id LEFT JOIN products p ON p.id=e.product_id $where ORDER BY e.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute($params);
$enquiries=$stmt->fetchAll();
$pageTitle='Enquiries'; $activePage='enquiries';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <h1>Enquiries</h1>
    </div>
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
                    <option value="open"        <?= $status==='open'?'selected':'' ?>>Open</option>
                    <option value="in_progress" <?= $status==='in_progress'?'selected':'' ?>>In Progress</option>
                    <option value="closed"      <?= $status==='closed'?'selected':'' ?>>Closed</option>
                </select>
                <?php if ($status): ?><a href="?" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
            </form>
        </div>
        <?php if ($enquiries): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>#</th><th>Customer</th><th>Product</th><th>Subject</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($enquiries as $i => $e): ?>
                <tr>
                    <td class="text-muted"><?= $offset+$i+1 ?></td>
                    <td><?= sanitize($e['customer_name']) ?></td>
                    <td><?= sanitize($e['product_name'] ?? '—') ?></td>
                    <td><?= sanitize($e['subject'] ?: 'General Enquiry') ?></td>
                    <td><?= statusBadge($e['status']) ?></td>
                    <td class="text-muted"><?= timeAgo($e['created_at']) ?></td>
                    <td><a href="?id=<?= $e['id'] ?>" class="btn btn-outline btn-xs">View →</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total,$perPage,$page,'?status='.$status) ?>
        <?php else: ?>
            <div class="empty-state"><div class="es-icon">📭</div><p>No enquiries found.</p></div>
        <?php endif; ?>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
