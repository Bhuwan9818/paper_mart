<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

$tab = $_GET['tab'] ?? 'bookings'; // bookings | packages | slots | payments

// ─── POST ACTIONS ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Booking status change ──
    if ($action === 'booking_status') {
        $id     = (int)$_POST['id'];
        $status = in_array($_POST['status'], ['pending','approved','rejected','running','paused','completed','cancelled'])
                  ? $_POST['status'] : 'pending';
        $note   = trim($_POST['admin_note'] ?? '');
        $pdo->prepare("UPDATE banner_ads SET status=?, admin_note=? WHERE id=?")->execute([$status, $note, $id]);
        // If approved and payment is paid → auto-set running if start_date <= today
        if ($status === 'approved') {
            $pdo->prepare(
                "UPDATE banner_ads SET status='running'
                 WHERE id=? AND start_date <= CURDATE() AND end_date >= CURDATE()
                   AND (SELECT COUNT(*) FROM ad_payments WHERE ad_id=? AND status='paid') > 0"
            )->execute([$id, $id]);
        }
        flash('success', 'Ad booking updated.');
        header('Location: ads.php?tab=bookings'); exit;
    }

    // ── Reorder booking within slot ──
    if ($action === 'reorder') {
        $id    = (int)$_POST['id'];
        $order = (int)$_POST['sort_order'];
        $pdo->prepare("UPDATE banner_ads SET sort_order=? WHERE id=?")->execute([$order, $id]);
        flash('success', 'Order updated.');
        header('Location: ads.php?tab=bookings'); exit;
    }

    // ── Package CRUD ──
    if ($action === 'save_package') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $days  = max(1, (int)($_POST['duration_days'] ?? 7));
        $price = max(0, (float)($_POST['price'] ?? 0));
        $slots = max(1, (int)($_POST['max_slots'] ?? 1));
        $order = (int)($_POST['sort_order'] ?? 0);
        $active= isset($_POST['is_active']) ? 1 : 0;
        if (!$name) { flash('error','Package name is required.'); header('Location: ads.php?tab=packages'); exit; }
        if ($id) {
            $pdo->prepare("UPDATE ad_packages SET name=?,description=?,duration_days=?,price=?,max_slots=?,sort_order=?,is_active=? WHERE id=?")
                ->execute([$name,$desc,$days,$price,$slots,$order,$active,$id]);
            flash('success','Package updated.');
        } else {
            $pdo->prepare("INSERT INTO ad_packages (name,description,duration_days,price,max_slots,sort_order,is_active) VALUES(?,?,?,?,?,?,?)")
                ->execute([$name,$desc,$days,$price,$slots,$order,$active]);
            flash('success','Package created.');
        }
        header('Location: ads.php?tab=packages'); exit;
    }

    if ($action === 'delete_package') {
        $pdo->prepare("DELETE FROM ad_packages WHERE id=?")->execute([(int)$_POST['id']]);
        flash('success','Package deleted.');
        header('Location: ads.php?tab=packages'); exit;
    }

    // ── Slot CRUD ──
    if ($action === 'save_slot') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $start = trim($_POST['start_time'] ?? '');
        $end   = trim($_POST['end_time'] ?? '');
        $max   = max(1, (int)($_POST['max_concurrent'] ?? 3));
        $desc  = trim($_POST['description'] ?? '');
        $order = (int)($_POST['sort_order'] ?? 0);
        $active= isset($_POST['is_active']) ? 1 : 0;
        if (!$name || !$start || !$end) { flash('error','Name and time range are required.'); header('Location: ads.php?tab=slots'); exit; }
        if ($id) {
            $pdo->prepare("UPDATE ad_slots SET name=?,start_time=?,end_time=?,max_concurrent=?,description=?,sort_order=?,is_active=? WHERE id=?")
                ->execute([$name,$start,$end,$max,$desc,$order,$active,$id]);
            flash('success','Slot updated.');
        } else {
            $pdo->prepare("INSERT INTO ad_slots (name,start_time,end_time,max_concurrent,description,sort_order,is_active) VALUES(?,?,?,?,?,?,?)")
                ->execute([$name,$start,$end,$max,$desc,$order,$active]);
            flash('success','Slot created.');
        }
        header('Location: ads.php?tab=slots'); exit;
    }

    if ($action === 'delete_slot') {
        $pdo->prepare("DELETE FROM ad_slots WHERE id=?")->execute([(int)$_POST['id']]);
        flash('success','Slot deleted.');
        header('Location: ads.php?tab=slots'); exit;
    }

    // ── Payment status change ──
    if ($action === 'payment_status') {
        $id     = (int)$_POST['id'];
        $status = in_array($_POST['status'], ['pending','paid','failed','refunded']) ? $_POST['status'] : 'pending';
        $ref    = trim($_POST['payment_ref'] ?? '');
        $pdo->prepare("UPDATE ad_payments SET status=?, payment_ref=?, paid_at=? WHERE id=?")
            ->execute([$status, $ref, $status==='paid'?date('Y-m-d H:i:s'):null, $id]);
        // Auto-approve the linked booking when payment is marked paid
        if ($status === 'paid') {
            $pdo->prepare(
                "UPDATE banner_ads ba
                 JOIN ad_payments ap ON ap.ad_id=ba.id
                 SET ba.status = IF(ba.start_date <= CURDATE() AND ba.end_date >= CURDATE(), 'running', 'approved')
                 WHERE ap.id=? AND ba.status='pending'"
            )->execute([$id]);
        }
        flash('success','Payment updated.');
        header('Location: ads.php?tab=payments'); exit;
    }
}

// ─── DAILY AUTO-STATUS (run on every admin page load) ──────────
// Moves approved+paid ads to 'running' if their date window is now,
// and completed ones past end_date. Lightweight — one query each.
try {
    $pdo->query(
        "UPDATE banner_ads ba
         SET ba.status='running'
         WHERE ba.status='approved' AND ba.start_date<=CURDATE() AND ba.end_date>=CURDATE()
           AND (SELECT COUNT(*) FROM ad_payments WHERE ad_id=ba.id AND status='paid')>0"
    );
    $pdo->query(
        "UPDATE banner_ads SET status='completed'
         WHERE status IN('running','approved') AND end_date < CURDATE()"
    );
} catch(Exception $e) {}

// ─── DATA LOAD ────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? '';
$search       = trim($_GET['search'] ?? '');
$page         = max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;

// Bookings
$bWhere = "WHERE 1=1"; $bParams = [];
if ($statusFilter) { $bWhere.=" AND ba.status=?"; $bParams[]=$statusFilter; }
if ($search) { $bWhere.=" AND (u.company LIKE ? OR u.name LIKE ? OR ba.title LIKE ?)"; $t="%$search%"; $bParams=array_merge($bParams,[$t,$t,$t]); }
$bTotal = $pdo->prepare("SELECT COUNT(*) FROM banner_ads ba JOIN users u ON u.id=ba.vendor_id $bWhere");
$bTotal->execute($bParams); $bTotal=$bTotal->fetchColumn();
$bParams[]=$perPage; $bParams[]=$offset;
$bookings = $pdo->prepare(
    "SELECT ba.*, u.name AS vendor_name, u.company, u.email AS vendor_email,
            p.name AS package_name, p.duration_days, p.price AS package_price,
            s.name AS slot_name, s.start_time, s.end_time,
            (SELECT status FROM ad_payments WHERE ad_id=ba.id ORDER BY created_at DESC LIMIT 1) AS payment_status,
            (SELECT payment_ref FROM ad_payments WHERE ad_id=ba.id AND status='paid' LIMIT 1) AS payment_ref
     FROM banner_ads ba
     JOIN users u       ON u.id=ba.vendor_id
     JOIN ad_packages p ON p.id=ba.package_id
     JOIN ad_slots s    ON s.id=ba.slot_id
     $bWhere ORDER BY ba.created_at DESC LIMIT ? OFFSET ?"
);
$bookings->execute($bParams); $bookings=$bookings->fetchAll();

// Packages
$packages = $pdo->query("SELECT * FROM ad_packages ORDER BY sort_order, id")->fetchAll();

// Slots
$slots = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM banner_ads WHERE slot_id=s.id AND status IN('running','approved')) AS active_ads FROM ad_slots s ORDER BY sort_order, start_time")->fetchAll();

// Payments
$payments = $pdo->query(
    "SELECT ap.*, u.name AS vendor_name, u.company,
            p.name AS package_name, ba.title AS ad_title, ba.start_date, ba.end_date
     FROM ad_payments ap
     JOIN users u       ON u.id=ap.vendor_id
     JOIN ad_packages p ON p.id=ap.package_id
     JOIN banner_ads ba ON ba.id=ap.ad_id
     ORDER BY ap.created_at DESC LIMIT 100"
)->fetchAll();

// Summary stats
$stats = [
    'total'     => $pdo->query("SELECT COUNT(*) FROM banner_ads")->fetchColumn(),
    'running'   => $pdo->query("SELECT COUNT(*) FROM banner_ads WHERE status='running'")->fetchColumn(),
    'pending'   => $pdo->query("SELECT COUNT(*) FROM banner_ads WHERE status='pending'")->fetchColumn(),
    'revenue'   => $pdo->query("SELECT COALESCE(SUM(amount),0) FROM ad_payments WHERE status='paid'")->fetchColumn(),
];

$pageTitle = 'Ad Management'; $activePage = 'ads';
include __DIR__ . '/../includes/head.php';
?>
<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>🎯 Ad Management</h1>
  </div>
</div>
<div class="content">
  <?= showFlash() ?>

  <!-- Summary cards -->
  <div class="stats-row" style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px">
    <div class="card" style="padding:18px;text-align:center">
      <div style="font-size:26px;font-weight:800;color:var(--brand)"><?= number_format($stats['total']) ?></div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Total Bookings</div>
    </div>
    <div class="card" style="padding:18px;text-align:center">
      <div style="font-size:26px;font-weight:800;color:#22c55e"><?= $stats['running'] ?></div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Running Now</div>
    </div>
    <div class="card" style="padding:18px;text-align:center">
      <div style="font-size:26px;font-weight:800;color:#f59e0b"><?= $stats['pending'] ?></div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Awaiting Approval</div>
    </div>
    <div class="card" style="padding:18px;text-align:center">
      <div style="font-size:26px;font-weight:800;color:var(--brand-2)">₹<?= number_format($stats['revenue'],2) ?></div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Ad Revenue</div>
    </div>
  </div>

  <!-- Tabs -->
  <div style="display:flex;gap:6px;margin-bottom:18px;border-bottom:2px solid var(--border);padding-bottom:0">
    <?php foreach(['bookings'=>'📋 Bookings','packages'=>'💳 Packages','slots'=>'⏰ Time Slots','payments'=>'💰 Payments'] as $t=>$l): ?>
    <a href="?tab=<?= $t ?>" style="padding:10px 18px;font-size:13.5px;font-weight:600;text-decoration:none;border-radius:8px 8px 0 0;<?= $tab===$t?'background:var(--brand);color:#fff':'color:var(--text-muted)' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'bookings'): ?>
  <!-- ═══════ BOOKINGS TAB ═══════ -->
  <div class="card" style="padding:0;overflow:hidden">
    <!-- Filters -->
    <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <form method="GET" style="display:flex;gap:8px;flex:1;min-width:0">
        <input type="hidden" name="tab" value="bookings">
        <input type="text" name="search" value="<?= sanitize($search) ?>" class="form-control" style="max-width:220px" placeholder="Search vendor, title…">
        <select name="status" class="form-control" style="max-width:160px">
          <option value="">All Statuses</option>
          <?php foreach(['pending','approved','running','paused','rejected','completed','cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="?tab=bookings" class="btn btn-outline btn-sm">✕ Clear</a>
      </form>
    </div>
    <div class="table-wrapper">
      <table>
        <thead><tr>
          <th>Vendor</th><th>Banner</th><th>Slot</th><th>Package</th>
          <th>Schedule</th><th>Payment</th><th>Status</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php if (!$bookings): ?>
          <tr><td colspan="8"><div class="empty-state"><div class="empty-state-icon">📭</div><p>No ad bookings yet.</p></div></td></tr>
        <?php endif; ?>
        <?php foreach($bookings as $b): ?>
        <tr>
          <td>
            <div style="font-weight:600;font-size:13px"><?= sanitize($b['company']?:$b['vendor_name']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= sanitize($b['vendor_email']) ?></div>
          </td>
          <td>
            <img src="<?= UPLOAD_URL.sanitize($b['image']) ?>" alt="" style="width:80px;height:30px;object-fit:cover;border-radius:4px;border:1px solid var(--border)">
            <?php if ($b['title']): ?><div style="font-size:11px;margin-top:3px;color:var(--text-muted)"><?= sanitize($b['title']) ?></div><?php endif; ?>
          </td>
          <td style="font-size:12.5px">
            <strong><?= sanitize($b['slot_name']) ?></strong><br>
            <span style="color:var(--text-muted)"><?= substr($b['start_time'],0,5) ?>–<?= substr($b['end_time'],0,5) ?></span>
          </td>
          <td style="font-size:12.5px">
            <?= sanitize($b['package_name']) ?><br>
            <strong style="color:var(--brand)">₹<?= number_format($b['package_price'],2) ?></strong>
          </td>
          <td style="font-size:12px">
            <?= date('d M Y', strtotime($b['start_date'])) ?><br>→ <?= date('d M Y', strtotime($b['end_date'])) ?>
            <div style="color:var(--text-muted)"><?= $b['duration_days'] ?> days</div>
          </td>
          <td>
            <?php
            $ps = $b['payment_status'] ?? 'pending';
            $psColor = match($ps) { 'paid'=>'#22c55e','failed'=>'#ef4444','refunded'=>'#6366f1',default=>'#f59e0b' };
            ?>
            <span class="badge" style="background:<?= $psColor ?>20;color:<?= $psColor ?>;font-size:10.5px"><?= ucfirst($ps) ?></span>
            <?php if ($b['payment_ref']): ?><div style="font-size:10px;color:var(--text-muted)">Ref: <?= sanitize($b['payment_ref']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php
            $sc = match($b['status']) {
              'running'=>'#22c55e','approved'=>'#3b82f6','pending'=>'#f59e0b',
              'rejected'=>'#ef4444','paused'=>'#8b5cf6','completed'=>'#6b7280','cancelled'=>'#ef4444',
              default=>'#6b7280'
            };
            ?>
            <span class="badge" style="background:<?= $sc ?>20;color:<?= $sc ?>;font-size:11px"><?= ucfirst($b['status']) ?></span>
            <div style="font-size:10.5px;color:var(--text-muted);margin-top:2px">Order #<?= $b['sort_order'] ?></div>
          </td>
          <td>
            <div class="td-actions">
              <button class="btn btn-outline btn-xs" onclick="openBookingModal(<?= htmlspecialchars(json_encode($b),ENT_QUOTES) ?>)">✏️ Edit</button>
              <a href="<?= UPLOAD_URL.sanitize($b['image']) ?>" target="_blank" class="btn btn-outline btn-xs">🖼️</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($bTotal > $perPage): ?>
    <div style="padding:12px 16px;border-top:1px solid var(--border)">
      <?= paginate($bTotal,$perPage,$page,'?tab=bookings&search='.urlencode($search).'&status='.urlencode($statusFilter)) ?>
    </div>
    <?php endif; ?>
  </div>

  <?php elseif ($tab === 'packages'): ?>
  <!-- ═══════ PACKAGES TAB ═══════ -->
  <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
    <button class="btn btn-primary btn-sm" onclick="openPackageModal()">+ New Package</button>
  </div>
  <div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Name</th><th>Duration</th><th>Price</th><th>Max Slots</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($packages as $p): ?>
        <tr>
          <td><strong><?= sanitize($p['name']) ?></strong><div style="font-size:12px;color:var(--text-muted)"><?= sanitize($p['description']) ?></div></td>
          <td><?= $p['duration_days'] ?> days</td>
          <td><strong style="color:var(--brand)">₹<?= number_format($p['price'],2) ?></strong></td>
          <td><?= $p['max_slots'] ?></td>
          <td><?= $p['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>' ?></td>
          <td><div class="td-actions">
            <button class="btn btn-outline btn-xs" onclick="openPackageModal(<?= htmlspecialchars(json_encode($p),ENT_QUOTES) ?>)">✏️ Edit</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this package?')">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
              <input type="hidden" name="action" value="delete_package">
              <input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn btn-danger btn-xs">🗑</button>
            </form>
          </div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php elseif ($tab === 'slots'): ?>
  <!-- ═══════ SLOTS TAB ═══════ -->
  <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
    <button class="btn btn-primary btn-sm" onclick="openSlotModal()">+ New Time Slot</button>
  </div>
  <div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Slot Name</th><th>Time Window</th><th>Max Concurrent Ads</th><th>Active Ads</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($slots as $s): ?>
        <tr>
          <td><strong><?= sanitize($s['name']) ?></strong><div style="font-size:12px;color:var(--text-muted)"><?= sanitize($s['description']) ?></div></td>
          <td style="font-family:monospace;font-size:13px"><?= substr($s['start_time'],0,5) ?> – <?= substr($s['end_time'],0,5) ?></td>
          <td style="text-align:center"><?= $s['max_concurrent'] ?></td>
          <td style="text-align:center">
            <span style="font-weight:700;color:<?= $s['active_ads']>=$s['max_concurrent']?'#ef4444':'#22c55e' ?>">
              <?= $s['active_ads'] ?> / <?= $s['max_concurrent'] ?>
            </span>
            <?php if ($s['active_ads'] >= $s['max_concurrent']): ?><div style="font-size:10px;color:#ef4444">FULL</div><?php endif; ?>
          </td>
          <td><?= $s['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>' ?></td>
          <td><div class="td-actions">
            <button class="btn btn-outline btn-xs" onclick="openSlotModal(<?= htmlspecialchars(json_encode($s),ENT_QUOTES) ?>)">✏️ Edit</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this slot? This will affect existing bookings.')">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
              <input type="hidden" name="action" value="delete_slot">
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-danger btn-xs">🗑</button>
            </form>
          </div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php elseif ($tab === 'payments'): ?>
  <!-- ═══════ PAYMENTS TAB ═══════ -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Vendor</th><th>Package</th><th>Amount</th><th>Ref / Method</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$payments): ?>
          <tr><td colspan="7"><div class="empty-state"><div class="empty-state-icon">💰</div><p>No payment records yet.</p></div></td></tr>
        <?php endif; ?>
        <?php foreach($payments as $p): ?>
        <tr>
          <td>
            <div style="font-weight:600;font-size:13px"><?= sanitize($p['company']?:$p['vendor_name']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= sanitize($p['ad_title']?:'(no title)') ?></div>
          </td>
          <td style="font-size:13px"><?= sanitize($p['package_name']) ?></td>
          <td style="font-weight:700;color:var(--brand)">₹<?= number_format($p['amount'],2) ?></td>
          <td style="font-size:12px"><?= sanitize($p['payment_ref']?:'—') ?><br><span style="color:var(--text-muted)"><?= sanitize($p['payment_method']) ?></span></td>
          <td>
            <?php $pc=['paid'=>'#22c55e','failed'=>'#ef4444','refunded'=>'#6366f1','pending'=>'#f59e0b'][$p['status']]??'#6b7280'; ?>
            <span class="badge" style="background:<?= $pc ?>20;color:<?= $pc ?>"><?= ucfirst($p['status']) ?></span>
          </td>
          <td style="font-size:12px"><?= $p['paid_at'] ? date('d M Y', strtotime($p['paid_at'])) : date('d M Y', strtotime($p['created_at'])) ?></td>
          <td>
            <button class="btn btn-outline btn-xs" onclick="openPaymentModal(<?= htmlspecialchars(json_encode($p),ENT_QUOTES) ?>)">✏️ Update</button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ═══════ MODALS ═══════ -->

<!-- Booking Edit Modal -->
<div id="booking-modal" class="modal-backdrop">
  <div class="modal" style="max-width:520px">
    <div class="modal-header"><h3>Edit Ad Booking</h3><button class="modal-close" onclick="document.getElementById('booking-modal').classList.remove('open')">✕</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="booking_status">
      <input type="hidden" name="id" id="bm-id">
      <div class="form-group"><label class="form-label">Booking</label><div id="bm-info" style="font-size:13px;color:var(--text-muted)"></div></div>
      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" id="bm-status" class="form-control">
          <?php foreach(['pending','approved','rejected','running','paused','completed','cancelled'] as $s): ?>
          <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Sort Order (position in carousel for this slot)</label>
        <input type="number" name="sort_order_note" id="bm-order-note" class="form-control" value="0" min="0" max="99" placeholder="0 = first">
        <p style="font-size:11.5px;color:var(--text-muted);margin-top:4px">Lower number shows first in the rotation when multiple ads run in the same slot.</p>
      </div>
      <div class="form-group">
        <label class="form-label">Admin Note (sent to vendor on rejection)</label>
        <textarea name="admin_note" id="bm-note" class="form-control" rows="3" placeholder="Optional note visible to the vendor…"></textarea>
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1">Save Changes</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('booking-modal').classList.remove('open')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Package Modal -->
<div id="package-modal" class="modal-backdrop">
  <div class="modal" style="max-width:480px">
    <div class="modal-header"><h3 id="pm-title">New Package</h3><button class="modal-close" onclick="document.getElementById('package-modal').classList.remove('open')">✕</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="save_package">
      <input type="hidden" name="id" id="pm-id" value="">
      <div class="form-group"><label class="form-label">Package Name <span class="req">*</span></label><input type="text" name="name" id="pm-name" class="form-control" maxlength="100" required></div>
      <div class="form-group"><label class="form-label">Description</label><textarea name="description" id="pm-desc" class="form-control" rows="2"></textarea></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">Duration (days) <span class="req">*</span></label><input type="number" name="duration_days" id="pm-days" class="form-control" min="1" required></div>
        <div class="form-group"><label class="form-label">Price (₹) <span class="req">*</span></label><input type="number" name="price" id="pm-price" class="form-control" min="0" step="0.01" required></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">Max Slots</label><input type="number" name="max_slots" id="pm-slots" class="form-control" min="1" value="1"></div>
        <div class="form-group"><label class="form-label">Sort Order</label><input type="number" name="sort_order" id="pm-order" class="form-control" value="0"></div>
      </div>
      <div class="form-group"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="is_active" id="pm-active" value="1"> Active (visible to vendors)</label></div>
      <button type="submit" class="btn btn-primary btn-full">Save Package</button>
    </form>
  </div>
</div>

<!-- Slot Modal -->
<div id="slot-modal" class="modal-backdrop">
  <div class="modal" style="max-width:460px">
    <div class="modal-header"><h3 id="sm-title">New Time Slot</h3><button class="modal-close" onclick="document.getElementById('slot-modal').classList.remove('open')">✕</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="save_slot">
      <input type="hidden" name="id" id="sm-id" value="">
      <div class="form-group"><label class="form-label">Slot Name <span class="req">*</span></label><input type="text" name="name" id="sm-name" class="form-control" maxlength="100" required></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">Start Time <span class="req">*</span></label><input type="time" name="start_time" id="sm-start" class="form-control" required></div>
        <div class="form-group"><label class="form-label">End Time <span class="req">*</span></label><input type="time" name="end_time" id="sm-end" class="form-control" required></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">Max Concurrent Ads</label><input type="number" name="max_concurrent" id="sm-max" class="form-control" min="1" value="3"></div>
        <div class="form-group"><label class="form-label">Sort Order</label><input type="number" name="sort_order" id="sm-order" class="form-control" value="0"></div>
      </div>
      <div class="form-group"><label class="form-label">Description</label><input type="text" name="description" id="sm-desc" class="form-control" maxlength="255"></div>
      <div class="form-group"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="is_active" id="sm-active" value="1"> Active</label></div>
      <button type="submit" class="btn btn-primary btn-full">Save Slot</button>
    </form>
  </div>
</div>

<!-- Payment Update Modal -->
<div id="payment-modal" class="modal-backdrop">
  <div class="modal" style="max-width:420px">
    <div class="modal-header"><h3>Update Payment</h3><button class="modal-close" onclick="document.getElementById('payment-modal').classList.remove('open')">✕</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="payment_status">
      <input type="hidden" name="id" id="paym-id">
      <div id="paym-info" style="font-size:13px;color:var(--text-muted);margin-bottom:14px"></div>
      <div class="form-group">
        <label class="form-label">Payment Status</label>
        <select name="status" id="paym-status" class="form-control">
          <option value="pending">Pending</option>
          <option value="paid">Paid ✅</option>
          <option value="failed">Failed</option>
          <option value="refunded">Refunded</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Payment Reference / UTR</label>
        <input type="text" name="payment_ref" id="paym-ref" class="form-control" placeholder="Transaction ID, UTR, etc.">
      </div>
      <p style="font-size:11.5px;color:var(--text-muted)">Setting status to <strong>Paid</strong> will automatically activate the ad booking if the start date has arrived.</p>
      <button type="submit" class="btn btn-primary btn-full">Update Payment</button>
    </form>
  </div>
</div>

<script>
function openBookingModal(b){
  document.getElementById('bm-id').value=b.id;
  document.getElementById('bm-status').value=b.status;
  document.getElementById('bm-note').value=b.admin_note||'';
  document.getElementById('bm-order-note').value=b.sort_order||0;
  document.getElementById('bm-info').textContent=
    (b.company||b.vendor_name)+' · '+b.package_name+' · '+b.slot_name+
    ' ('+b.start_date+' → '+b.end_date+')';
  document.getElementById('booking-modal').classList.add('open');
}
function openPackageModal(p){
  const isNew=!p;
  document.getElementById('pm-title').textContent=isNew?'New Package':'Edit Package';
  document.getElementById('pm-id').value=isNew?'':(p.id||'');
  document.getElementById('pm-name').value=isNew?'':(p.name||'');
  document.getElementById('pm-desc').value=isNew?'':(p.description||'');
  document.getElementById('pm-days').value=isNew?7:(p.duration_days||7);
  document.getElementById('pm-price').value=isNew?'':(p.price||0);
  document.getElementById('pm-slots').value=isNew?1:(p.max_slots||1);
  document.getElementById('pm-order').value=isNew?0:(p.sort_order||0);
  document.getElementById('pm-active').checked=isNew?true:(p.is_active==1);
  document.getElementById('package-modal').classList.add('open');
}
function openSlotModal(s){
  const isNew=!s;
  document.getElementById('sm-title').textContent=isNew?'New Time Slot':'Edit Slot';
  document.getElementById('sm-id').value=isNew?'':(s.id||'');
  document.getElementById('sm-name').value=isNew?'':(s.name||'');
  document.getElementById('sm-start').value=isNew?'':(s.start_time||'').substring(0,5);
  document.getElementById('sm-end').value=isNew?'':(s.end_time||'').substring(0,5);
  document.getElementById('sm-max').value=isNew?3:(s.max_concurrent||3);
  document.getElementById('sm-order').value=isNew?0:(s.sort_order||0);
  document.getElementById('sm-desc').value=isNew?'':(s.description||'');
  document.getElementById('sm-active').checked=isNew?true:(s.is_active==1);
  document.getElementById('slot-modal').classList.add('open');
}
function openPaymentModal(p){
  document.getElementById('paym-id').value=p.id;
  document.getElementById('paym-status').value=p.status;
  document.getElementById('paym-ref').value=p.payment_ref||'';
  document.getElementById('paym-info').textContent=
    (p.company||p.vendor_name)+' · '+(p.package_name)+' · ₹'+parseFloat(p.amount).toFixed(2);
  document.getElementById('payment-modal').classList.add('open');
}
// Close modals on backdrop click
['booking-modal','package-modal','slot-modal','payment-modal'].forEach(id=>{
  document.getElementById(id).addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
});
</script>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
