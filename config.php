<?php
// ============================================================
// config.php — Database connection & global constants
// Edit DB_USER / DB_PASS to match your XAMPP/WAMP settings
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');          // change if you have a password
define('DB_NAME', 'product_enquiry');

// Base URL — change to match your setup
define('BASE_URL', '/dashv10_Fixed');

// Upload directory (create this folder and give write permission)
define('UPLOAD_DIR', __DIR__ . '/assets/uploads/');
define('UPLOAD_URL', BASE_URL . '/assets/uploads/');

// ---- Site-wide launch gate ("Coming Soon" mode) ----
// While true, every page on the entire site shows a public "Coming Soon"
// message instead of its normal content — EXCEPT for people who've entered
// the gate credentials below at site-access.php, whose access then persists
// for 90 days via a browser cookie. This is a lightweight, temporary gate
// for a pre-launch period — NOT a replacement for the real vendor/admin/
// customer login system underneath it, which still applies as normal once
// someone is through this outer gate.
//
// When you're ready to go fully public, just change this to false — no
// other code changes needed anywhere.
define('SITE_LAUNCH_MODE', true);
define('SITE_GATE_USERNAME', 'superadmin');
define('SITE_GATE_PASSWORD', 'superadmin');       // change before sharing the URL
define('SITE_GATE_COOKIE_SECRET', 'change-this-to-a-long-random-string-before-launch');

require_once __DIR__ . '/includes/site_gate.php';
enforceSiteGate();

// Hard cap on how many vendor ad banners can be pending/approved/running
// at once for any overlapping date range — keeps the homepage hero to a
// maximum of this many rotating banners at any given time.
define('MAX_ACTIVE_HERO_BANNERS', 4);

// ---- Razorpay payment gateway ----
// Get these from https://dashboard.razorpay.com/app/keys (Test mode first).
// Test keys start with "rzp_test_", live keys with "rzp_live_".
define('RAZORPAY_KEY_ID',     'rzp_test_TJGlHQe100WEgc');
define('RAZORPAY_KEY_SECRET', 'rHvpkoCypuRpaj6VwagCCgRc');
// From Dashboard → Settings → Webhooks, after you create a webhook (see setup guide).
define('RAZORPAY_WEBHOOK_SECRET', 'YOUR_WEBHOOK_SECRET_HERE');

// ---- Automated database backups ----
// Random secret used to authorize the cron-triggered backup URL (see
// cron-backup.php and admin/backup.php for setup instructions). Change this
// to your own random string before going live.
define('BACKUP_CRON_SECRET', 'change-this-to-a-random-string-abc123xyz');

// ---- Outgoing email (enquiry notifications via Gmail SMTP) ----
// Use a Gmail App Password, NOT your normal Gmail password — Gmail blocks
// plain password SMTP login. See the setup guide for how to generate one.
define('SMTP_HOST',       'smtp.gmail.com');
define('SMTP_PORT',       587);
define('SMTP_USERNAME',   'bhuwansingh8860@gmail.com');   // the Gmail account emails are sent FROM
define('SMTP_PASSWORD',   'metu ghcw azds jvip'); // Gmail App Password (no spaces)
define('SMTP_FROM_EMAIL', 'bhuwansingh9818@gmail.com');
define('SMTP_FROM_NAME',  'paperKart');
// Every enquiry is also emailed here in addition to the vendor. Can be a
// separate admin inbox or the same Gmail account above.
define('ADMIN_NOTIFY_EMAIL', 'admin@example.com');

// ---- Platform billing identity (shown as the "seller" on every invoice) ----
// Update these with your actual registered business details before going live.
define('PLATFORM_LEGAL_NAME',    'PaperKart');
define('PLATFORM_ADDRESS',       'punjabi bagh, Delhi, Delhi, 110084');
define('PLATFORM_GSTIN',         '');   // leave blank to hide GSTIN line on invoices
define('PLATFORM_SUPPORT_EMAIL', SMTP_FROM_EMAIL);
define('INVOICE_PREFIX',         'INV');

// Connect via PDO
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('<div style="font-family:monospace;padding:20px;background:#fee2e2;color:#991b1b;border-radius:8px;margin:20px">
        <strong>Database connection failed:</strong> ' . htmlspecialchars($e->getMessage()) . '<br><br>
        Please check your <code>config.php</code> settings and make sure MySQL is running.
    </div>');
}

// require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/invoice.php';
