<?php
// public/download-tds.php
// Every TDS download on the site routes through here now (instead of a
// direct link to the static file) so free-plan customers' monthly download
// limit can actually be enforced. Vendors, admins, and non-customer/guest
// visitors are unaffected — the limit is specifically a customer-plan
// feature, matching how compare/enquiry limits are scoped elsewhere.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/customer_subscription.php';

$file = basename(trim($_GET['file'] ?? ''));
$path = __DIR__ . '/../assets/tds/' . $file;

if (!$file || !file_exists($path)) {
    http_response_code(404);
    exit('TDS report not found.');
}

$isCustomer = isset($_SESSION['role']) && $_SESSION['role'] === 'customer';

if ($isCustomer) {
    $customerId = $_SESSION['user_id'];
    $sub = getCustomerSubscription($pdo, $customerId);
    if ($sub) {
        $check = checkTdsLimit($pdo, $customerId, $sub);
        if (!$check['allowed']) {
            // Show a friendly upgrade page rather than a raw 403 — this is
            // the only download path, so a plain error here would just
            // look like the site is broken.
            http_response_code(200);
            ?>
            <!DOCTYPE html><html><head><meta charset="UTF-8"><title>Download Limit Reached</title>
            <style>body{font-family:-apple-system,Segoe UI,sans-serif;background:#f4f1ec;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}
            .card{background:#fff;border-radius:16px;padding:40px;max-width:420px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.1)}
            h2{color:#1f2937;margin-bottom:10px}p{color:#6b7280;font-size:14px;line-height:1.6;margin-bottom:22px}
            a{display:inline-block;background:#8b241d;color:#fff;text-decoration:none;padding:11px 24px;border-radius:8px;font-weight:700}</style>
            </head><body><div class="card">
            <div style="font-size:36px;margin-bottom:10px">📄</div>
            <h2>Download Limit Reached</h2>
            <p>You've used all <?= (int)$check['limit'] ?> free TDS report downloads this month. Upgrade to Premium for unlimited downloads.</p>
            <a href="<?= BASE_URL ?>/customer/subscription.php">Upgrade to Premium</a>
            </div></body></html>
            <?php
            exit;
        }
    }
    incrementCustomerUsage($pdo, $customerId, 'tds_downloaded');
}

$mime = 'application/pdf';
$finfo = @finfo_open(FILEINFO_MIME_TYPE);
if ($finfo) { $detected = @finfo_file($finfo, $path); if ($detected) $mime = $detected; finfo_close($finfo); }

$disposition = ($_GET['mode'] ?? '') === 'view' ? 'inline' : 'attachment';
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
