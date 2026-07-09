<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('vendor');
$user = currentUser();
$uid  = $user['id'];

// ─── POST: Submit a new ad booking ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'book_ad') {
        $packageId  = (int)($_POST['package_id'] ?? 0);
        $slotId     = (int)($_POST['slot_id'] ?? 0);
        $startDate  = trim($_POST['start_date'] ?? '');
        $title      = trim($_POST['title'] ?? '');
        $subtitle   = trim($_POST['subtitle'] ?? '');
        $linkUrl    = trim($_POST['link_url'] ?? '');
        $buttonText = trim($_POST['button_text'] ?? '');
        $payRef     = trim($_POST['payment_ref'] ?? '');

        // Validate
        $errors = [];
        if (!$packageId) $errors[] = 'Please select a package.';
        if (!$slotId)    $errors[] = 'Please select a time slot.';
        if (!$startDate || strtotime($startDate) < strtotime('today'))
            $errors[] = 'Start date must be today or a future date.';
        if (empty($_FILES['image']['name']))
            $errors[] = 'Please upload a banner image.';

        if (!$errors) {
            // Fetch package for end_date calculation
            $pkg = $pdo->prepare("SELECT * FROM ad_packages WHERE id=? AND is_active=1");
            $pkg->execute([$packageId]); $pkg = $pkg->fetch();
            if (!$pkg) $errors[] = 'Invalid package selected.';
        }

        if (!$errors) {
            // Check slot capacity
            $slotRow = $pdo->prepare("SELECT * FROM ad_slots WHERE id=? AND is_active=1");
            $slotRow->execute([$slotId]); $slotRow = $slotRow->fetch();
            if (!$slotRow) {
                $errors[] = 'Invalid time slot selected.';
            } else {
                // Count ads already approved/running in this slot for this date range
                $endDate = date('Y-m-d', strtotime($startDate . ' +' . ($pkg['duration_days']-1) . ' days'));
                $overlap = $pdo->prepare(
                    "SELECT COUNT(*) FROM banner_ads
                     WHERE slot_id=? AND status IN('approved','running','pending')
                       AND start_date <= ? AND end_date >= ?"
                );
                $overlap->execute([$slotId, $endDate, $startDate]);
                if ($overlap->fetchColumn() >= $slotRow['max_concurrent']) {
                    $errors[] = 'This slot is fully booked for your selected dates. Please choose a different slot or start date.';
                }
            }
        }

        if (!$errors) {
            // Upload banner image
            $imageName = uploadImage($_FILES['image'], 'ad');
            if (!$imageName) {
                $errors[] = 'Image upload failed. Use JPG/PNG/WebP, max 5MB. Recommended size: 1600×600px.';
            }
        }

        if (!$errors) {
            $endDate = date('Y-m-d', strtotime($startDate . ' +' . ($pkg['duration_days']-1) . ' days'));
            $pdo->prepare(
                "INSERT INTO banner_ads (vendor_id,package_id,slot_id,image,title,subtitle,link_url,button_text,start_date,end_date,status)
                 VALUES (?,?,?,?,?,?,?,?,'$startDate','$endDate','pending')"
            )->execute([$uid,$packageId,$slotId,$imageName,$title,$subtitle,$linkUrl,$buttonText]);
            $adId = $pdo->lastInsertId();

            // Create pending payment record
            $pdo->prepare(
                "INSERT INTO ad_payments (ad_id,vendor_id,package_id,amount,currency,payment_method,payment_ref,status)
                 VALUES (?,?,?,?,'INR','manual',?,'pending')"
            )->execute([$adId,$uid,$packageId,$pkg['price'],$payRef]);

            flash('success','Ad booking submitted! Complete your payment to activate the ad. Our team will review and approve it shortly.');
            header('Location: ads.php'); exit;
        }

        // Store errors to show below form
        foreach ($errors as $e) flash('error', $e);
        header('Location: ads.php?tab=book'); exit;
    }

    if ($action === 'update_pay_ref') {
        $adId  = (int)$_POST['ad_id'];
        $ref   = trim($_POST['payment_ref'] ?? '');
        if ($ref) {
            // Only update pending payments for this vendor's ad
            $pdo->prepare(
                "UPDATE ad_payments SET payment_ref=?
                 WHERE ad_id=? AND vendor_id=? AND status='pending'"
            )->execute([$ref, $adId, $uid]);
            flash('success', 'Payment reference submitted. Our team will verify within 24 hours.');
        } else {
            flash('error', 'Please enter a valid transaction reference.');
        }
        header('Location: ads.php'); exit;
    }

    if ($action === 'cancel_ad') {
        $adId = (int)$_POST['ad_id'];
        // Only allow cancel if status is pending/approved (not running)
        $pdo->prepare(
            "UPDATE banner_ads SET status='cancelled'
             WHERE id=? AND vendor_id=? AND status IN('pending','approved')"
        )->execute([$adId, $uid]);
        flash('success', 'Ad booking cancelled.');
        header('Location: ads.php'); exit;
    }
}

$tab = $_GET['tab'] ?? 'my-ads'; // my-ads | book

// ─── DATA ────────────────────────────────────────────────────
$packages = $pdo->query("SELECT * FROM ad_packages WHERE is_active=1 ORDER BY sort_order, price")->fetchAll();
$slots    = $pdo->query("SELECT * FROM ad_slots WHERE is_active=1 ORDER BY sort_order, start_time")->fetchAll();

// Vendor's own ad bookings with payment info
$myAds = $pdo->prepare(
    "SELECT ba.*, p.name AS package_name, p.duration_days, p.price AS package_price,
            s.name AS slot_name, s.start_time, s.end_time,
            (SELECT status     FROM ad_payments WHERE ad_id=ba.id ORDER BY created_at DESC LIMIT 1) AS pay_status,
            (SELECT payment_ref FROM ad_payments WHERE ad_id=ba.id ORDER BY created_at DESC LIMIT 1) AS pay_ref,
            (SELECT id          FROM ad_payments WHERE ad_id=ba.id ORDER BY created_at DESC LIMIT 1) AS pay_id
     FROM banner_ads ba
     JOIN ad_packages p ON p.id=ba.package_id
     JOIN ad_slots s    ON s.id=ba.slot_id
     WHERE ba.vendor_id=?
     ORDER BY ba.created_at DESC"
);
$myAds->execute([$uid]); $myAds = $myAds->fetchAll();

// Slot availability helper
function slotAvailability($pdo, $slotId, $startDate, $endDate, $maxConcurrent) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM banner_ads
         WHERE slot_id=? AND status IN('approved','running','pending')
           AND start_date <= ? AND end_date >= ?"
    );
    $stmt->execute([$slotId, $endDate, $startDate]);
    $used = (int)$stmt->fetchColumn();
    return $maxConcurrent - $used;
}

$pageTitle = 'My Ads'; $activePage = 'ads';
include __DIR__ . '/../includes/head.php';
?>
<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>🎯 Banner Ads</h1>
  </div>
  <div class="topbar-right">
    <a href="?tab=book" class="btn btn-primary btn-sm">+ Book New Ad</a>
  </div>
</div>
<div class="content">
  <?= showFlash() ?>

  <!-- Tabs -->
  <div style="display:flex;gap:6px;margin-bottom:20px;border-bottom:2px solid var(--border)">
    <a href="?tab=my-ads" style="padding:10px 18px;font-size:13.5px;font-weight:600;text-decoration:none;border-radius:8px 8px 0 0;<?= $tab==='my-ads'?'background:var(--brand);color:#fff':'color:var(--text-muted)' ?>">📋 My Ads</a>
    <a href="?tab=book"   style="padding:10px 18px;font-size:13.5px;font-weight:600;text-decoration:none;border-radius:8px 8px 0 0;<?= $tab==='book'  ?'background:var(--brand);color:#fff':'color:var(--text-muted)' ?>">➕ Book New Ad</a>
  </div>

  <?php if ($tab === 'my-ads'): ?>
  <!-- ═══════ MY ADS TAB ═══════ -->
  <?php if (!$myAds): ?>
    <div class="empty-state">
      <div class="empty-state-icon">🖼️</div>
      <h3>No Ad Bookings Yet</h3>
      <p>Book your first banner ad to promote your products on the homepage carousel.</p>
      <a href="?tab=book" class="btn btn-primary" style="margin-top:12px">+ Book a Banner Ad</a>
    </div>
  <?php else: ?>
  <div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrapper">
      <table>
        <thead><tr>
          <th>Banner</th><th>Slot</th><th>Schedule</th>
          <th>Package</th><th>Payment</th><th>Status</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach($myAds as $ad): ?>
        <?php
        $statusColor = match($ad['status']) {
          'running'   => '#22c55e', 'approved'  => '#3b82f6',
          'pending'   => '#f59e0b', 'rejected'  => '#ef4444',
          'paused'    => '#8b5cf6', 'completed' => '#6b7280',
          'cancelled' => '#ef4444', default     => '#6b7280'
        };
        $payColor = match($ad['pay_status']) {
          'paid'=>'#22c55e','failed'=>'#ef4444','refunded'=>'#6366f1',default=>'#f59e0b'
        };
        $daysLeft = max(0, (int)((strtotime($ad['end_date']) - time()) / 86400));
        ?>
        <tr>
          <td>
            <img src="<?= UPLOAD_URL.sanitize($ad['image']) ?>"
                 style="width:90px;height:34px;object-fit:cover;border-radius:4px;border:1px solid var(--border)">
            <?php if ($ad['title']): ?><div style="font-size:11px;margin-top:3px;color:var(--text-muted)"><?= sanitize($ad['title']) ?></div><?php endif; ?>
          </td>
          <td style="font-size:12.5px">
            <strong><?= sanitize($ad['slot_name']) ?></strong><br>
            <span style="color:var(--text-muted)"><?= substr($ad['start_time'],0,5) ?>–<?= substr($ad['end_time'],0,5) ?></span>
          </td>
          <td style="font-size:12px">
            <?= date('d M', strtotime($ad['start_date'])) ?> – <?= date('d M Y', strtotime($ad['end_date'])) ?>
            <br><?= $ad['duration_days'] ?> days
            <?php if ($ad['status']==='running'): ?>
              <div style="color:#22c55e;font-weight:600;font-size:11px"><?= $daysLeft ?> days left</div>
            <?php endif; ?>
          </td>
          <td style="font-size:12.5px">
            <?= sanitize($ad['package_name']) ?><br>
            <strong style="color:var(--brand)">₹<?= number_format($ad['package_price'],2) ?></strong>
          </td>
          <td>
            <span class="badge" style="background:<?= $payColor ?>20;color:<?= $payColor ?>;font-size:10.5px">
              <?= ucfirst($ad['pay_status'] ?? 'pending') ?>
            </span>
            <?php if ($ad['pay_ref']): ?><div style="font-size:10px;color:var(--text-muted)">Ref: <?= sanitize($ad['pay_ref']) ?></div><?php endif; ?>
            <?php if (($ad['pay_status']??'pending') === 'pending'): ?>
              <button class="btn btn-xs btn-outline" style="margin-top:4px" onclick="openPayModal(<?= $ad['id'] ?>,<?= $ad['pay_id']?:0 ?>,<?= $ad['package_price'] ?>,'<?= addslashes($ad['package_name']) ?>')">Add Ref</button>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge" style="background:<?= $statusColor ?>20;color:<?= $statusColor ?>;font-size:11px">
              <?= ucfirst($ad['status']) ?>
            </span>
            <?php if ($ad['admin_note'] && in_array($ad['status'],['rejected','paused'])): ?>
              <div style="font-size:10.5px;color:#ef4444;margin-top:3px">Note: <?= sanitize($ad['admin_note']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <div class="td-actions">
              <a href="<?= UPLOAD_URL.sanitize($ad['image']) ?>" target="_blank" class="btn btn-outline btn-xs">🖼️ Preview</a>
              <?php if (in_array($ad['status'],['pending','approved'])): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Cancel this ad booking?')">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="cancel_ad">
                <input type="hidden" name="ad_id" value="<?= $ad['id'] ?>">
                <button type="submit" class="btn btn-danger btn-xs">Cancel</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php elseif ($tab === 'book'): ?>
  <!-- ═══════ BOOK NEW AD TAB ═══════ -->
  <div style="display:grid;grid-template-columns:1fr 380px;gap:22px;align-items:start">

    <!-- Booking form -->
    <div class="card">
      <div class="card-header"><h2>Book a Banner Ad</h2></div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="book_ad">

          <!-- Step 1: Package -->
          <div style="margin-bottom:22px">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;color:var(--brand-2)">STEP 1 — Choose a Package</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px">
              <?php foreach($packages as $p): ?>
              <label style="cursor:pointer">
                <input type="radio" name="package_id" value="<?= $p['id'] ?>" required
                       onchange="updateEndDate()" style="display:none" class="pkg-radio">
                <div class="pkg-card" data-days="<?= $p['duration_days'] ?>" data-price="<?= $p['price'] ?>"
                     style="border:2px solid var(--border);border-radius:10px;padding:14px 12px;text-align:center;transition:.2s">
                  <div style="font-size:15px;font-weight:800;color:var(--brand)">₹<?= number_format($p['price'],0) ?></div>
                  <div style="font-size:12.5px;font-weight:600;margin:4px 0"><?= sanitize($p['name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted)"><?= $p['duration_days'] ?> days</div>
                  <?php if ($p['description']): ?>
                  <div style="font-size:10.5px;color:var(--text-muted);margin-top:5px;line-height:1.4"><?= sanitize($p['description']) ?></div>
                  <?php endif; ?>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Step 2: Slot + Date -->
          <div style="margin-bottom:22px">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;color:var(--brand-2)">STEP 2 — Choose a Time Slot &amp; Start Date</h3>
            <div class="form-group">
              <label class="form-label">Time Slot <span class="req">*</span></label>
              <select name="slot_id" class="form-control" required id="slot-select" onchange="updateAvailability()">
                <option value="">Select a slot…</option>
                <?php foreach($slots as $s): ?>
                <option value="<?= $s['id'] ?>" data-max="<?= $s['max_concurrent'] ?>">
                  <?= sanitize($s['name']) ?> (<?= substr($s['start_time'],0,5) ?>–<?= substr($s['end_time'],0,5) ?>)
                  <?php if ($s['description']): ?>– <?= sanitize($s['description']) ?><?php endif; ?>
                </option>
                <?php endforeach; ?>
              </select>
              <div id="slot-availability" style="font-size:12px;margin-top:5px;color:var(--text-muted)"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
              <div class="form-group">
                <label class="form-label">Start Date <span class="req">*</span></label>
                <input type="date" name="start_date" id="start-date" class="form-control"
                       min="<?= date('Y-m-d') ?>" required onchange="updateEndDate()">
              </div>
              <div class="form-group">
                <label class="form-label">End Date (auto-calculated)</label>
                <input type="text" id="end-date-display" class="form-control" readonly
                       style="background:var(--n50);color:var(--text-muted)" placeholder="Select package + date">
              </div>
            </div>
          </div>

          <!-- Step 3: Banner image upload -->
          <div style="margin-bottom:22px">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;color:var(--brand-2)">STEP 3 — Upload Banner Image</h3>
            <div class="form-group">
              <label class="form-label">Banner Image <span class="req">*</span></label>
              <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp"
                     required onchange="previewBanner(this)">
              <p style="font-size:11.5px;color:var(--text-muted);margin-top:6px">
                📐 Required dimensions: <strong>1600 × 600 px</strong> (8:3 landscape ratio)<br>
                Formats: JPG, PNG, WebP &nbsp;|&nbsp; Max size: 5 MB<br>
                Keep important content within the centre 80% of the image width.
              </p>
            </div>
            <!-- Live preview -->
            <div id="banner-preview" style="display:none;margin-top:10px">
              <div style="font-size:12px;font-weight:600;margin-bottom:6px">Preview (actual display ratio):</div>
              <div style="width:100%;aspect-ratio:8/3;border-radius:8px;overflow:hidden;border:1px solid var(--border);background:var(--n50)">
                <img id="banner-preview-img" src="" alt="" style="width:100%;height:100%;object-fit:cover">
              </div>
              <div id="banner-dim-warning" style="display:none;margin-top:6px;font-size:11.5px;color:#f59e0b">
                ⚠️ Image dimensions don't match 1600×600. It will still display but may appear cropped.
              </div>
            </div>
          </div>

          <!-- Step 4: Optional overlay content -->
          <div style="margin-bottom:22px">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:4px;color:var(--brand-2)">STEP 4 — Optional Content Overlay</h3>
            <p style="font-size:11.5px;color:var(--text-muted);margin-bottom:12px">Leave blank if your banner image already contains the message.</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
              <div class="form-group"><label class="form-label">Overlay Title</label><input type="text" name="title" class="form-control" maxlength="150" placeholder="e.g. Premium Kraft Paper"></div>
              <div class="form-group"><label class="form-label">CTA Button Text</label><input type="text" name="button_text" class="form-control" maxlength="60" placeholder="e.g. Shop Now"></div>
            </div>
            <div class="form-group"><label class="form-label">Subtitle</label><input type="text" name="subtitle" class="form-control" maxlength="255" placeholder="e.g. Flat 10% off on bulk orders"></div>
            <div class="form-group"><label class="form-label">Click-through URL</label><input type="url" name="link_url" class="form-control" placeholder="https://…"></div>
          </div>

          <!-- Step 5: Payment -->
          <div style="margin-bottom:22px;padding:16px;background:#fef9ec;border:1px solid #f5deab;border-radius:8px">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:10px;color:var(--brand-2)">STEP 5 — Payment</h3>
            <div id="amount-display" style="font-size:22px;font-weight:800;color:var(--brand);margin-bottom:10px">Select a package above</div>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
              Transfer the amount to our bank account or UPI and paste the transaction reference below.
              Your ad will go live once payment is verified by our team.
            </p>
            <div style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:12px;font-size:12.5px;margin-bottom:12px">
              <strong>Payment Details:</strong><br>
              Bank: PaperMart India Pvt Ltd &nbsp;|&nbsp; IFSC: HDFC0001234<br>
              A/C No: 5010 0123 4567 &nbsp;|&nbsp; UPI: papermart@hdfcbank
            </div>
            <div class="form-group">
              <label class="form-label">Transaction / UTR Reference (optional now, required to activate)</label>
              <input type="text" name="payment_ref" class="form-control" placeholder="e.g. UTR123456789">
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-lg btn-full">Submit Ad Booking →</button>
          <p style="font-size:11.5px;color:var(--text-muted);text-align:center;margin-top:8px">
            Our team reviews all submissions within 24 hours. You'll see the status update in "My Ads".
          </p>
        </form>
      </div>
    </div>

    <!-- Right: info sidebar -->
    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="card">
        <div class="card-body">
          <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">📋 How It Works</h3>
          <?php foreach([
            ['1','Choose a package','Pick the duration and pricing that fits your campaign.'],
            ['2','Select a slot','Each slot runs your banner during a specific time window every day.'],
            ['3','Upload your banner','Recommended 1600×600px. Your actual product photo works great.'],
            ['4','Pay & submit','Transfer payment and paste the reference. We verify within 24h.'],
            ['5','Go live','Once approved, your banner rotates automatically in the homepage carousel.'],
          ] as [$n,$title,$desc]): ?>
          <div style="display:flex;gap:10px;margin-bottom:12px">
            <div style="width:24px;height:24px;border-radius:50%;background:var(--brand);color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0"><?= $n ?></div>
            <div><div style="font-weight:600;font-size:12.5px"><?= $title ?></div><div style="font-size:11.5px;color:var(--text-muted);margin-top:2px"><?= $desc ?></div></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">⏰ Available Slots Today</h3>
          <?php foreach($slots as $s): ?>
          <?php
          $today   = date('Y-m-d');
          $nextWeek= date('Y-m-d', strtotime('+7 days'));
          $slotStmt= $pdo->prepare("SELECT COUNT(*) FROM banner_ads WHERE slot_id=? AND status IN('approved','running','pending') AND start_date<=? AND end_date>=?");
          $slotStmt->execute([$s['id'],$today,$today]);
          $used = (int)$slotStmt->fetchColumn();
          $free = $s['max_concurrent'] - $used;
          ?>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;padding:8px 10px;border:1px solid var(--border);border-radius:8px">
            <div>
              <div style="font-size:13px;font-weight:600"><?= sanitize($s['name']) ?></div>
              <div style="font-size:11px;color:var(--text-muted)"><?= substr($s['start_time'],0,5) ?>–<?= substr($s['end_time'],0,5) ?></div>
            </div>
            <div style="text-align:right">
              <div style="font-size:12px;font-weight:700;color:<?= $free>0?'#22c55e':'#ef4444' ?>"><?= $free ?> free</div>
              <div style="font-size:10px;color:var(--text-muted)"><?= $used ?>/<?= $s['max_concurrent'] ?> used</div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <h3 style="font-size:14px;font-weight:700;margin-bottom:10px">📐 Banner Requirements</h3>
          <ul style="font-size:12px;color:var(--text-muted);line-height:2;padding-left:16px;margin:0">
            <li>Dimensions: <strong>1600 × 600 px</strong></li>
            <li>Format: JPG, PNG, or WebP</li>
            <li>Max file size: 5 MB</li>
            <li>Keep text &amp; logos in centre 80%</li>
            <li>No obscene or competitor content</li>
            <li>Plain background works best</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Payment reference update modal -->
<div id="pay-modal" class="modal-backdrop">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><h3>Add Payment Reference</h3><button class="modal-close" onclick="document.getElementById('pay-modal').classList.remove('open')">✕</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="update_pay_ref">
      <input type="hidden" name="ad_id" id="paymod-adid">
      <div id="paymod-info" style="font-size:13px;color:var(--text-muted);margin-bottom:12px"></div>
      <div class="form-group">
        <label class="form-label">Transaction / UTR Reference</label>
        <input type="text" name="payment_ref" id="paymod-ref" class="form-control" placeholder="e.g. UTR123456789" required>
      </div>
      <p style="font-size:11.5px;color:var(--text-muted)">Our team will verify the payment and activate your ad within 24 hours.</p>
      <button type="submit" class="btn btn-primary btn-full">Submit Reference</button>
    </form>
  </div>
</div>

<script>
// Package card selection highlight
document.querySelectorAll('.pkg-radio').forEach(r=>{
  r.addEventListener('change',function(){
    document.querySelectorAll('.pkg-card').forEach(c=>c.style.borderColor='var(--border)');
    this.closest('label').querySelector('.pkg-card').style.borderColor='var(--brand)';
    updateEndDate();
  });
});

// Auto-calculate end date from start date + selected package duration
function updateEndDate(){
  const radio   = document.querySelector('.pkg-radio:checked');
  const startEl = document.getElementById('start-date');
  const endEl   = document.getElementById('end-date-display');
  const amtEl   = document.getElementById('amount-display');
  if (!radio || !startEl.value){ endEl.value=''; return; }
  const days  = parseInt(radio.closest('label').querySelector('.pkg-card').dataset.days);
  const price = parseFloat(radio.closest('label').querySelector('.pkg-card').dataset.price);
  const start = new Date(startEl.value);
  start.setDate(start.getDate() + days - 1);
  endEl.value = start.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
  amtEl.textContent = '₹' + price.toLocaleString('en-IN',{minimumFractionDigits:2});
  updateAvailability();
}

// Check slot availability for selected dates
function updateAvailability(){
  const slotEl  = document.getElementById('slot-select');
  const startEl = document.getElementById('start-date');
  const infoEl  = document.getElementById('slot-availability');
  const opt     = slotEl.options[slotEl.selectedIndex];
  if (!opt || !opt.value || !startEl.value){ infoEl.textContent=''; return; }
  const radio = document.querySelector('.pkg-radio:checked');
  if (!radio){ infoEl.textContent='Select a package to check availability.'; return; }
  const days    = parseInt(radio.closest('label').querySelector('.pkg-card').dataset.days);
  const maxConc = parseInt(opt.dataset.max||3);
  // AJAX check
  fetch('<?= BASE_URL ?>/public/ajax/check-ad-slot.php?slot_id='+opt.value+'&start='+startEl.value+'&days='+days)
    .then(r=>r.json())
    .then(d=>{
      const free = maxConc - (d.used||0);
      infoEl.style.color = free>0?'#22c55e':'#ef4444';
      infoEl.textContent = free>0
        ? '✅ '+free+' slot(s) available for these dates.'
        : '❌ This slot is fully booked for your chosen dates. Please pick different dates or a different slot.';
    })
    .catch(()=>{ infoEl.textContent=''; });
}

// Live banner preview + dimension check
function previewBanner(input){
  const wrap  = document.getElementById('banner-preview');
  const img   = document.getElementById('banner-preview-img');
  const warn  = document.getElementById('banner-dim-warning');
  if (!input.files[0]){ wrap.style.display='none'; return; }
  const url = URL.createObjectURL(input.files[0]);
  img.onload = function(){
    // Check if dimensions are close to 1600×600 (allow ±10%)
    const rOk = Math.abs(this.naturalWidth/this.naturalHeight - 8/3) < 0.3;
    warn.style.display = rOk ? 'none' : 'block';
    URL.revokeObjectURL(url);
  };
  img.src = url;
  wrap.style.display = 'block';
}

function openPayModal(adId, payId, price, pkgName){
  document.getElementById('paymod-adid').value = adId;
  document.getElementById('paymod-info').textContent = pkgName + ' — ₹' + parseFloat(price).toFixed(2);
  document.getElementById('pay-modal').classList.add('open');
}
document.getElementById('pay-modal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
</script>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
