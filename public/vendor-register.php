<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/auth.php';
require_once dirname(__DIR__).'/includes/functions.php';
require_once __DIR__.'/includes/site_functions.php';

if (isLoggedIn()) { header('Location: '.BASE_URL.'/vendor/dashboard.php'); exit; }

$pageTitle='Become a Vendor — PaperMart'; $currentPage='';
$error=''; $success='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $name    = trim($_POST['name']??'');
    $email   = trim($_POST['email']??'');
    $password= trim($_POST['password']??'');
    $company = trim($_POST['company']??'');
    $phone   = trim($_POST['phone']??'');
    $city    = trim($_POST['city']??'');
    $gst     = trim($_POST['gst_number']??'');
    if (!$name||!$email||!$password||!$company) { $error='Please fill in all required fields.'; }
    elseif (!filter_var($email,FILTER_VALIDATE_EMAIL)) { $error='Invalid email address.'; }
    elseif (strlen($password)<6) { $error='Password must be at least 6 characters.'; }
    else {
        $check=$pdo->prepare("SELECT id FROM users WHERE email=?");$check->execute([$email]);
        if ($check->fetch()) { $error='An account with this email already exists. <a href="'.BASE_URL.'/login.php">Sign in instead</a>.'; }
        else {
            $hash=password_hash($password,PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (name,email,password,role,status,company,phone,city) VALUES(?,?,?,'vendor','active',?,?,?)")
                ->execute([$name,$email,$hash,$company,$phone,$city]);
            $uid=$pdo->lastInsertId();
            if ($gst) $pdo->prepare("UPDATE users SET gst_number=? WHERE id=?")->execute([$gst,$uid]);
            // Create vendor profile
            $pdo->prepare("INSERT INTO vendor_profiles (vendor_id) VALUES(?)")->execute([$uid]);
            // Assign free plan
            try {
                $freePlan=$pdo->query("SELECT id FROM subscription_plans WHERE slug='free' LIMIT 1")->fetchColumn();
                if ($freePlan) $pdo->prepare("INSERT INTO vendor_subscriptions (vendor_id,plan_id,status,started_at,expires_at,trial_ends_at) VALUES(?,?,'trial',NOW(),DATE_ADD(NOW(),INTERVAL 14 DAY),DATE_ADD(NOW(),INTERVAL 14 DAY))")->execute([$uid,$freePlan]);
            }catch(Exception $e){}
            // Welcome notification
            try { $pdo->prepare("INSERT INTO notifications (user_id,title,message,link) VALUES(?,?,?,?)")->execute([$uid,'Welcome to PaperMart! 🎉','Your vendor account is ready. Start by adding your first product.',BASE_URL.'/vendor/add-product.php']); }catch(Exception $e){}
            // Auto login
            $_SESSION['user_id']=$uid; $_SESSION['name']=$name; $_SESSION['email']=$email; $_SESSION['role']='vendor';
            header('Location:'.BASE_URL.'/vendor/dashboard.php'); exit;
        }
    }
}
include __DIR__.'/includes/header.php';
?>
<section style="background:var(--n50);padding:48px 0">
  <div class="container" style="max-width:580px">
    <div class="section-head center" style="margin-bottom:28px">
      <div class="section-label">Join Free</div>
      <h1 style="font-size:28px">Become a Vendor on PaperMart</h1>
      <p>List your products for free and reach thousands of B2B buyers across India.</p>
    </div>
    <?php if($error): ?><div class="site-alert site-alert-error"><span>⚠️</span><span><?= $error ?></span></div><?php endif; ?>
    <div style="background:#fff;border:1px solid var(--n200);border-radius:var(--r-lg);padding:32px;box-shadow:var(--shadow-sm)">
      <form method="POST">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-input" value="<?= sH($_POST['name']??'') ?>" required></div>
          <div class="form-group"><label class="form-label">Email Address *</label><input type="email" name="email" class="form-input" value="<?= sH($_POST['email']??'') ?>" required></div>
        </div>
        <div class="form-group"><label class="form-label">Company / Business Name *</label><input type="text" name="company" class="form-input" value="<?= sH($_POST['company']??'') ?>" required></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Phone Number</label><input type="tel" name="phone" class="form-input" value="<?= sH($_POST['phone']??'') ?>"></div>
          <div class="form-group"><label class="form-label">City</label><input type="text" name="city" class="form-input" value="<?= sH($_POST['city']??'') ?>"></div>
        </div>
        <div class="form-group"><label class="form-label">GST Number (optional)</label><input type="text" name="gst_number" class="form-input" value="<?= sH($_POST['gst_number']??'') ?>" placeholder="22AAAAA0000A1Z5"></div>
        <div class="form-group"><label class="form-label">Password *</label><input type="password" name="password" class="form-input" required minlength="6"></div>
        <button type="submit" class="btn btn-accent btn-full btn-lg" style="margin-top:8px">🏪 Create Vendor Account — Free</button>
        <p style="text-align:center;font-size:13px;color:var(--n500);margin-top:12px">Already have an account? <a href="<?= BASE_URL ?>/login.php" style="color:var(--brand-2);font-weight:600">Sign In</a></p>
      </form>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:24px;text-align:center">
      <?php foreach([['🆓','Free to Start','List 3 products at no cost'],['📩','Get Enquiries','Receive direct buyer leads'],['📊','Track Performance','Analytics dashboard']] as [$i,$t,$d]): ?>
      <div style="padding:16px;border:1px solid var(--n200);border-radius:var(--r);background:#fff">
        <div style="font-size:26px;margin-bottom:6px"><?= $i ?></div>
        <div style="font-family:'Poppins',sans-serif;font-weight:700;font-size:13px;margin-bottom:3px"><?= $t ?></div>
        <div style="font-size:11.5px;color:var(--n500)"><?= $d ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php include __DIR__.'/includes/footer.php'; ?>
