<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');
$adminId = currentUser()['id'];

// ── Handle approve / reject ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $rid    = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note   = trim($_POST['admin_note'] ?? '');

    $req = $pdo->prepare("SELECT * FROM catalogue_requests WHERE id=?");
    $req->execute([$rid]); $req = $req->fetch();

    if ($req && $action === 'approve') {
        $createdId = null;
        try {
            $pdo->beginTransaction();
            switch($req['request_type']) {
                case 'industry':
                    $pdo->prepare("INSERT INTO industries (name,description,status,sort_order) VALUES(?,?,1,0)")
                        ->execute([$req['name'], $req['description']]);
                    $createdId = $pdo->lastInsertId();
                    break;
                case 'category':
                    $pdo->prepare("INSERT INTO categories (industry_id,name,description,status,sort_order) VALUES(?,?,?,1,0)")
                        ->execute([$req['parent_id'], $req['name'], $req['description']]);
                    $createdId = $pdo->lastInsertId();
                    break;
                case 'product_type':
                    $pdo->prepare("INSERT INTO product_types (category_id,name,description,status,sort_order) VALUES(?,?,?,1,0)")
                        ->execute([$req['parent_id'], $req['name'], $req['description']]);
                    $createdId = $pdo->lastInsertId();
                    break;
            }
            $pdo->prepare("UPDATE catalogue_requests SET status='approved',admin_note=?,resolved_at=NOW(),resolved_by=?,created_id=? WHERE id=?")
                ->execute([$note, $adminId, $createdId, $rid]);
            // Notify vendor
            $pdo->prepare("INSERT INTO notifications (user_id,title,message,link) VALUES(?,?,?,?)")
                ->execute([$req['vendor_id'],
                    '✅ Catalogue Request Approved',
                    'Your request for "' . $req['name'] . '" (' . $req['request_type'] . ') has been approved and added to the catalogue.',
                    BASE_URL . '/vendor/catalogue-request.php'
                ]);
            $pdo->commit();
            flash('success', '"' . htmlspecialchars($req['name']) . '" approved and added to catalogue.');
        } catch(Exception $e) {
            $pdo->rollBack();
            flash('error', 'Could not approve: ' . $e->getMessage());
        }

    } elseif ($req && $action === 'reject') {
        $pdo->prepare("UPDATE catalogue_requests SET status='rejected',admin_note=?,resolved_at=NOW(),resolved_by=? WHERE id=?")
            ->execute([$note, $adminId, $rid]);
        $pdo->prepare("INSERT INTO notifications (user_id,title,message,link) VALUES(?,?,?,?)")
            ->execute([$req['vendor_id'],
                '❌ Catalogue Request Rejected',
                'Your request for "' . $req['name'] . '" was not approved.' . ($note ? ' Reason: ' . $note : ''),
                BASE_URL . '/vendor/catalogue-request.php'
            ]);
        flash('success', 'Request rejected.');
    }
    header('Location: catalogue-requests.php'); exit;
}

// ── Filters ────────────────────────────────────────────────────
$status = $_GET['status'] ?? 'pending';
$type   = $_GET['type']   ?? '';
$page   = max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;

$where = "WHERE 1=1"; $params=[];
if ($status) { $where.=" AND cr.status=?"; $params[]=$status; }
if ($type)   { $where.=" AND cr.request_type=?"; $params[]=$type; }

$total = $pdo->prepare("SELECT COUNT(*) FROM catalogue_requests cr $where");
$total->execute($params); $total=$total->fetchColumn();

$params[]=$perPage; $params[]=$offset;
$requests = $pdo->prepare("SELECT cr.*, u.name AS vendor_name, u.email AS vendor_email, u.company FROM catalogue_requests cr JOIN users u ON u.id=cr.vendor_id $where ORDER BY cr.created_at DESC LIMIT ? OFFSET ?");
$requests->execute($params); $requests=$requests->fetchAll();

// Summary counts
$counts = ['pending'=>0,'approved'=>0,'rejected'=>0,'all'=>0];
foreach($pdo->query("SELECT status, COUNT(*) AS c FROM catalogue_requests GROUP BY status")->fetchAll() as $r){
    $counts[$r['status']] = $r['c'];
    $counts['all'] += $r['c'];
}

$pageTitle='Catalogue Requests'; $activePage='catalogue-requests';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:var(--radius-lg);padding:28px;width:100%;max-width:480px;box-shadow:var(--shadow-lg);}
.type-pill{display:inline-block;padding:2px 9px;border-radius:100px;font-size:11px;font-weight:700;}
.type-industry   {background:#dbeafe;color:#1e40af;}
.type-category   {background:#d1fae5;color:#065f46;}
.type-product_type{background:#ede9fe;color:#5b21b6;}
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Catalogue Requests</h1>
  </div>
  <div class="topbar-right">
    <?php if($counts['pending']>0): ?>
    <span class="btn btn-warning btn-sm" style="pointer-events:none">⏳ <?=$counts['pending']?> Pending</span>
    <?php endif; ?>
  </div>
</div>

<div class="content">
  <?= showFlash() ?>
  <div class="page-header"><h1>🗂️ Vendor Catalogue Requests</h1></div>

  <!-- Status filter tabs -->
  <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
    <?php foreach([''=> 'All ('.$counts['all'].')','pending'=>'⏳ Pending ('.$counts['pending'].')','approved'=>'✅ Approved ('.$counts['approved'].')','rejected'=>'❌ Rejected ('.$counts['rejected'].')'] as $s=>$lbl): ?>
    <a href="?status=<?=$s?>&type=<?=$type?>" class="btn <?=$status===$s?'btn-primary':'btn-outline'?> btn-sm"><?=$lbl?></a>
    <?php endforeach; ?>
    <div style="margin-left:auto;display:flex;gap:6px">
      <?php foreach([''=> 'All Types','industry'=>'🏭 Industry','category'=>'🗂️ Category','product_type'=>'🔖 Product Type'] as $t=>$lbl): ?>
      <a href="?status=<?=$status?>&type=<?=$t?>" class="btn <?=$type===$t?'btn-primary':'btn-outline'?> btn-sm"><?=$lbl?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <?php if($requests): ?>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>#</th><th>Vendor</th><th>Type</th><th>Requested Name</th><th>Parent</th><th>Reason</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($requests as $i=>$r): ?>
        <?php $typeIcon = match($r['request_type']){'industry'=>'🏭','category'=>'🗂️',default=>'🔖'}; ?>
        <tr>
          <td class="text-muted"><?=$offset+$i+1?></td>
          <td>
            <div style="font-weight:500"><?=sanitize($r['vendor_name'])?></div>
            <div style="font-size:11.5px;color:var(--text-muted)"><?=sanitize($r['vendor_email'])?></div>
            <?php if($r['company']): ?><div style="font-size:11px;color:var(--text-muted)"><?=sanitize($r['company'])?></div><?php endif; ?>
          </td>
          <td><span class="type-pill type-<?=$r['request_type']?>"><?=$typeIcon?> <?=ucfirst(str_replace('_',' ',$r['request_type']))?></span></td>
          <td style="font-weight:600"><?=sanitize($r['name'])?></td>
          <td class="text-muted" style="font-size:12px"><?=sanitize($r['parent_name']??'—')?></td>
          <td style="max-width:200px;font-size:12.5px"><?=sanitize(mb_strimwidth($r['reason']??'',0,80,'…'))?></td>
          <td>
            <?php if($r['status']==='pending'): ?>
            <span class="badge badge-warning">Pending</span>
            <?php elseif($r['status']==='approved'): ?>
            <span class="badge badge-success">Approved</span>
            <?php else: ?>
            <span class="badge badge-secondary">Rejected</span>
            <?php endif; ?>
          </td>
          <td class="text-muted" style="white-space:nowrap;font-size:12px"><?=date('d M Y',strtotime($r['created_at']))?></td>
          <td>
            <?php if($r['status']==='pending'): ?>
            <div class="td-actions">
              <button class="btn btn-success btn-xs" onclick="openResolve(<?=$r['id']?>, '<?=sanitize($r['name'])?>', 'approve')">✅ Approve</button>
              <button class="btn btn-danger  btn-xs" onclick="openResolve(<?=$r['id']?>, '<?=sanitize($r['name'])?>', 'reject')">❌ Reject</button>
            </div>
            <?php else: ?>
            <span class="text-muted" style="font-size:12px"><?=$r['admin_note']?sanitize(mb_strimwidth($r['admin_note'],0,40,'…')):'—'?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginate($total,$perPage,$page,'?status='.$status.'&type='.$type) ?>
    <?php else: ?>
    <div class="empty-state"><div class="es-icon">🗂️</div><p>No requests found.</p></div>
    <?php endif; ?>
  </div>
</div>

<!-- Resolve modal -->
<div class="modal-overlay" id="resolveModal">
  <div class="modal-box">
    <div class="modal-title" id="modal-title" style="font-size:16px;font-weight:700;margin-bottom:16px"></div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?=csrfToken()?>">
      <input type="hidden" name="request_id" id="modal_rid">
      <input type="hidden" name="action"      id="modal_action">
      <div class="form-group">
        <label class="form-label">Admin Note <span style="font-weight:400;color:var(--text-muted)">(optional for approve, recommended for reject)</span></label>
        <textarea name="admin_note" id="modal_note" class="form-control" rows="3" placeholder="Reason or feedback…"></textarea>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('resolveModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="modal_submit_btn">Confirm</button>
      </div>
    </form>
  </div>
</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
<script>
function openResolve(id, name, action) {
  document.getElementById('modal_rid').value    = id;
  document.getElementById('modal_action').value = action;
  document.getElementById('modal_note').value   = '';
  const isApprove = action === 'approve';
  document.getElementById('modal-title').textContent = isApprove ? '✅ Approve: ' + name : '❌ Reject: ' + name;
  const btn = document.getElementById('modal_submit_btn');
  btn.textContent  = isApprove ? 'Approve & Add to Catalogue' : 'Reject Request';
  btn.className    = 'btn ' + (isApprove ? 'btn-success' : 'btn-danger');
  document.getElementById('resolveModal').classList.add('open');
}
document.getElementById('resolveModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
</script>
</div></div></body></html>
