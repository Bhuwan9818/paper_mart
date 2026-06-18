<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Already logged in → go to dashboard
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/index.php'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $role     = trim($_POST['role']     ?? 'customer');

    if (!$email || !$password) {
        $error = 'Please enter email and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = ? LIMIT 1");
        $stmt->execute([$email, $role]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] === 'inactive') {
                $error = 'Your account has been deactivated. Contact admin.';
            } elseif ($user['status'] === 'pending' && $role === 'vendor') {
                $error = 'Your vendor account is pending admin approval.';
            } else {
                loginUser($user);
                header('Location: ' . BASE_URL . '/index.php'); exit;
            }
        } else {
            $error = 'Invalid email, password, or role selection.';
        }
    }
}
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — Product Enquiry</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <div class="login-logo">
            <h1>&#9670; Product Enquiry</h1>
            <p>Sign in to your dashboard</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">⚠️ <?= sanitize($error) ?></div>
        <?php endif; ?>
        <?php if (!empty($flash['success'])): ?>
            <div class="alert alert-success">✅ <?= sanitize($flash['success']) ?></div>
        <?php endif; ?>

        <!-- Role tabs -->
        <div class="login-tabs">
            <button class="login-tab active" onclick="setRole('customer', this)" id="tab-customer">🛍️ Customer</button>
            <button class="login-tab" onclick="setRole('vendor', this)"   id="tab-vendor">🏪 Vendor</button>
            <button class="login-tab" onclick="setRole('admin', this)"    id="tab-admin">⚙️ Admin</button>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="role" id="role-input" value="customer">

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="you@example.com"
                       value="<?= sanitize($_POST['email'] ?? '') ?>" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:10px">
                Sign In
            </button>
        </form>

        <div class="divider" style="margin:18px 0">or</div>
        <p style="text-align:center;font-size:13px;color:var(--text-muted)">
            Don't have an account?
            <a href="<?= BASE_URL ?>/register.php" style="color:var(--primary);font-weight:600">Register here</a>
        </p>

        <!-- Demo credentials hint -->
        <div style="margin-top:20px;background:#f8fafc;border-radius:8px;padding:14px;font-size:12px;color:var(--text-muted)">
            <strong>Demo Accounts:</strong><br>
            Admin: admin@example.com / admin123<br>
            Vendor: vendor@example.com / vendor123<br>
            Customer: customer@example.com / admin123
        </div>
    </div>
</div>
<script>
function setRole(role, tab) {
    document.getElementById('role-input').value = role;
    document.querySelectorAll('.login-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
}
// Pre-select tab based on last POST
const lastRole = '<?= htmlspecialchars($_POST['role'] ?? 'customer') ?>';
const tab = document.getElementById('tab-' + lastRole);
if (tab) setRole(lastRole, tab);
</script>
</body>
</html>
