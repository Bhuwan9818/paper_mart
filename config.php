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
