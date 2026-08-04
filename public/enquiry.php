<?php
$pageTitle='Send Enquiry — PaperMart'; $currentPage='enquiry';
include __DIR__.'/includes/header.php';
include __DIR__.'/includes/chatbot-widget.php';

$success=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $name    = trim($_POST['name']??'');
    $email   = trim($_POST['email']??'');
    $phone   = trim($_POST['phone']??'');
    $company = trim($_POST['company']??'');
    $city    = trim($_POST['city']??'');
    $product = trim($_POST['product_interest']??'');
    $qty     = trim($_POST['qty_needed']??'');
    $message = trim($_POST['message']??'');
    if (!$name||!$email||!$message) { $error='Please fill in all required fields.'; }
    elseif (!filter_var($email,FILTER_VALIDATE_EMAIL)) { $error='Please enter a valid email address.'; }
    else {
        try {
            // Get first admin to notify
            $admin=$pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
            // Store as general web enquiry (vendor_id=admin)
            $pdo->prepare("INSERT INTO web_enquiries (vendor_id,name,email,phone,company,city,message,qty_needed,ip_address,source) VALUES(?,?,?,?,?,?,?,?,?,'general')")
                ->execute([$admin?:1,$name,$email,$phone,$company,$city,$message,$qty,$_SERVER['REMOTE_ADDR']??'']);
            $success='Thank you! We have received your enquiry. Our team will contact you within 24 hours.';
        } catch(Exception $e){ $error='Something went wrong. Please try again.'; }
    }
}
$categories=$pdo->query("SELECT name FROM categories WHERE status=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN);
?>
<div style="background:var(--n50);padding:14px 0;border-bottom:1px solid var(--n200)">
  <div class="container" style="font-size:13px;color:var(--n500)"><a href="<?= BASE_URL ?>/public/index.php" style="color:var(--brand-2)">Home</a> › <span>Send Enquiry</span></div>
</div>
<section>
  <div class="container" style="max-width:800px">
    <div class="section-head center">
      <div class="section-label">Get Quotes</div>
      <h1 style="font-size:30px">Send Your Requirements</h1>
      <p>Tell us what you need and we'll connect you with the right suppliers. Free service for buyers.</p>
    </div>
    <?php if($success): ?><div class="site-alert site-alert-success"><span>✅</span><?= sH($success) ?></div><?php endif; ?>
    <?php if($error): ?><div class="site-alert site-alert-error"><span>⚠️</span><?= sH($error) ?></div><?php endif; ?>
    <?php if(!$success): ?>
    <div style="background:#fff;border:1px solid var(--n200);border-radius:var(--r-lg);padding:36px;box-shadow:var(--shadow-sm)">
      <form method="POST">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-input" value="<?= sH($_POST['name']??'') ?>" required></div>
          <div class="form-group"><label class="form-label">Email Address *</label><input type="email" name="email" class="form-input" value="<?= sH($_POST['email']??'') ?>" required></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Phone Number</label><input type="tel" name="phone" class="form-input" value="<?= sH($_POST['phone']??'') ?>"></div>
          <div class="form-group"><label class="form-label">Company Name</label><input type="text" name="company" class="form-input" value="<?= sH($_POST['company']??'') ?>"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">City / Location</label><input type="text" name="city" class="form-input" value="<?= sH($_POST['city']??'') ?>"></div>
          <div class="form-group">
            <label class="form-label">Product Interest</label>
            <select name="product_interest" class="form-input">
              <option value="">Select Category</option>
              <?php foreach($categories as $cat): ?><option value="<?= sH($cat) ?>" <?= ($_POST['product_interest']??'')===$cat?'selected':'' ?>><?= sH($cat) ?></option><?php endforeach; ?>
              <option value="Other">Other</option>
            </select>
          </div>
        </div>
        <div class="form-group"><label class="form-label">Quantity Required</label><input type="text" name="qty_needed" class="form-input" value="<?= sH($_POST['qty_needed']??'') ?>" placeholder="e.g. 500 kg/month, 10,000 pieces"></div>
        <div class="form-group"><label class="form-label">Detailed Requirements *</label><textarea name="message" class="form-input" rows="5" style="resize:vertical" required placeholder="Describe your requirements: GSM, grade, size, BF, certifications needed, delivery location, timeline…"><?= sH($_POST['message']??'') ?></textarea></div>
        <button type="submit" class="btn btn-accent btn-lg btn-full">📩 Submit Enquiry</button>
        <p style="font-size:12px;color:var(--n500);text-align:center;margin-top:12px">We respect your privacy. Your details are shared only with matched suppliers.</p>
      </form>
    </div>
    <?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:32px">
      <?php foreach([['⚡','Fast Response','Suppliers respond within 24 hours'],['🔒','Secure','Your data is protected and never sold'],['🆓','Free Service','100% free for buyers, always']] as [$ic,$tt,$dd]): ?>
      <div style="text-align:center;padding:20px;border:1px solid var(--n200);border-radius:var(--r)">
        <div style="font-size:32px;margin-bottom:8px"><?= $ic ?></div>
        <div style="font-family:'Poppins',sans-serif;font-weight:700;margin-bottom:4px"><?= $tt ?></div>
        <div style="font-size:12.5px;color:var(--n500)"><?= $dd ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php include __DIR__.'/includes/footer.php'; ?>
