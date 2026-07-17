<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) { header('Location: ' . BASE_URL . '/index.php'); exit; }

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');
    $role     = $_POST['role'] === 'vendor' ? 'vendor' : 'customer';
    $company  = trim($_POST['company']  ?? '');
    $phone    = trim($_POST['phone']    ?? '');

    if (!$name || !$email || !$password) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            $status = ($role === 'vendor') ? 'active' : 'active';
            $hash   = password_hash($password, PASSWORD_DEFAULT);
            $stmt   = $pdo->prepare("INSERT INTO users (name, email, password, role, status, company, phone) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $email, $hash, $role, $status, $company, $phone]);
            flash('success', 'Account created! You can now log in.');
            header('Location: ' . BASE_URL . '/login.php'); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — Product Enquiry</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body>
<div class="login-page">
    <div class="login-card" style="max-width:500px">
        <div class="login-logo">
            <h1>&#9670; Create Account</h1>
            <p>Join as a Vendor or Customer</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">⚠️ <?= sanitize($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <!-- Role selection -->
            <div class="form-group">
                <label class="form-label">I want to register as <span class="req">*</span></label>
                <div class="role-select">
                    <label class="role-option <?= ($_POST['role'] ?? 'customer') === 'customer' ? 'selected' : '' ?>" onclick="selectRole(this, 'customer')">
                        <input type="radio" name="role" value="customer" <?= ($_POST['role'] ?? 'customer') === 'customer' ? 'checked' : '' ?>>
                        <span class="role-icon">🛍️</span> Customer
                    </label>
                    <label class="role-option <?= ($_POST['role'] ?? '') === 'vendor' ? 'selected' : '' ?>" onclick="selectRole(this, 'vendor')">
                        <input type="radio" name="role" value="vendor" <?= ($_POST['role'] ?? '') === 'vendor' ? 'checked' : '' ?>>
                        <span class="role-icon">🏪</span> Vendor
                    </label>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Full Name <span class="req">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?= sanitize($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="tel" name="phone" class="form-control" value="<?= sanitize($_POST['phone'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Company / Business Name</label>
                <input type="text" name="company" class="form-control" value="<?= sanitize($_POST['company'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Email Address <span class="req">*</span></label>
                <input type="email" name="email" class="form-control" value="<?= sanitize($_POST['email'] ?? '') ?>" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Password <span class="req">*</span></label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password <span class="req">*</span></label>
                    <input type="password" name="confirm" class="form-control" required>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:10px">
                Create Account
            </button>
        </form>

        <p style="text-align:center;margin-top:18px;font-size:13px;color:var(--text-muted)">
            Already have an account?
            <a href="<?= BASE_URL ?>/login.php" style="color:var(--primary);font-weight:600">Sign in</a>
        </p>
    </div>
</div>
<script>
function selectRole(el, role) {
    document.querySelectorAll('.role-option').forEach(r => r.classList.remove('selected'));
    el.classList.add('selected');
    el.querySelector('input').checked = true;
}
</script>
</body>
</html>
