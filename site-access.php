<?php
require_once __DIR__ . '/config.php';
// Note: enforceSiteGate() already ran inside config.php above and returned
// immediately without effect, since this file is in the gate's exemption
// list — that's what makes this page reachable at all while the gate is on.

$error = '';

if (isSiteGateUnlocked()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $userOk = hash_equals(SITE_GATE_USERNAME, $username);
    $passOk = hash_equals(SITE_GATE_PASSWORD, $password);

    if ($userOk && $passOk) {
        unlockSiteGate();
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
    $error = 'Incorrect username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Private Preview Access — PaperMart</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  *{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}
  body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f1ec;padding:20px}
  .card{background:#fff;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.1);max-width:400px;width:100%;padding:36px 32px}
  .logo{text-align:center;margin-bottom:24px}
  .logo h1{font-size:20px;font-weight:800;color:#8b241d}
  .logo p{font-size:12.5px;color:#6b7280;margin-top:4px}
  .alert{background:#fee2e2;color:#991b1b;padding:11px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
  .form-group{margin-bottom:16px}
  label{display:block;font-size:12.5px;font-weight:600;color:#374151;margin-bottom:6px}
  input{width:100%;padding:10px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:14px}
  input:focus{outline:none;border-color:#8b241d}
  button{width:100%;padding:11px;background:#8b241d;color:#fff;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;margin-top:6px}
  button:hover{background:#6b1a14}
  .foot{text-align:center;margin-top:18px;font-size:11.5px;color:#9ca3af}
</style>
</head>
<body>
  <div class="card">
    <div class="logo">
      <h1>&#9670; PaperMart</h1>
      <p>Private Preview — Not Yet Publicly Launched</p>
    </div>
    <?php if ($error): ?><div class="alert">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label>Access Username</label>
        <input type="text" name="username" required autofocus autocomplete="off">
      </div>
      <div class="form-group">
        <label>Access Password</label>
        <input type="password" name="password" required autocomplete="off">
      </div>
      <button type="submit">Continue to Site</button>
    </form>
    <div class="foot">This grants preview access to the whole site. Regular vendor/admin/customer logins are still required after this.</div>
  </div>
</body>
</html>
