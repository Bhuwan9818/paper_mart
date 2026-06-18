<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('customer');
$user = currentUser();
$uid  = $user['id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?"); $stmt->execute([$uid]); $profile=$stmt->fetch();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $name=$_POST['name']??''; $phone=$_POST['phone']??''; $company=$_POST['company']??''; $address=$_POST['address']??''; $city=$_POST['city']??''; $state=$_POST['state']??'';
    if (!empty($_POST['new_password'])) {
        if (!password_verify($_POST['current_password']??'',$profile['password'])) { flash('error','Current password incorrect.'); header('Location: profile.php'); exit; }
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($_POST['new_password'],PASSWORD_DEFAULT),$uid]);
    }
    $pdo->prepare("UPDATE users SET name=?,phone=?,company=?,address=?,city=?,state=?,updated_at=NOW() WHERE id=?")->execute([$name,$phone,$company,$address,$city,$state,$uid]);
    $_SESSION['name']=$name; flash('success','Profile updated.'); header('Location: profile.php'); exit;
}
$pageTitle='My Profile'; $activePage='profile';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<div class="topbar"><div class="topbar-left"><button class="hamburger" id="hamburger"><span></span><span></span><span></span></button><h1>My Profile</h1></div></div>
<div class="content">
    <?= showFlash() ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="card">
            <div class="card-header"><h2>👤 Personal Information</h2></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Full Name</label><input type="text" name="name" class="form-control" value="<?= sanitize($profile['name']) ?>" required></div>
                    <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= sanitize($profile['phone']) ?>"></div>
                </div>
                <div class="form-group"><label class="form-label">Email</label><input type="email" class="form-control" value="<?= sanitize($profile['email']) ?>" disabled></div>
                <div class="form-group"><label class="form-label">Company</label><input type="text" name="company" class="form-control" value="<?= sanitize($profile['company']) ?>"></div>
                <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= sanitize($profile['address']) ?></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">City</label><input type="text" name="city" class="form-control" value="<?= sanitize($profile['city']) ?>"></div>
                    <div class="form-group"><label class="form-label">State</label><input type="text" name="state" class="form-control" value="<?= sanitize($profile['state']) ?>"></div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h2>🔒 Change Password</h2></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control"></div>
                    <div class="form-group"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control"></div>
                </div>
            </div>
        </div>
        <div style="margin-bottom:32px"><button type="submit" class="btn btn-primary">💾 Save Changes</button></div>
    </form>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
