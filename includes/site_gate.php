<?php
// ============================================================
// includes/site_gate.php — Pre-launch "Coming Soon" gate
//
// Runs at the very top of config.php, before the database even connects,
// so a blocked visitor never triggers a DB connection at all. If the gate
// is active and the visitor hasn't unlocked it, this shows the public
// coming-soon.php page and stops execution of whatever page was requested.
// ============================================================

// Files that must always work regardless of gate state:
//   - site-access.php / coming-soon.php: the gate mechanism itself
//   - cron-backup.php: hit by your hosting panel's cron, not a browser
//   - razorpay-webhook.php: hit by Razorpay's servers, not a browser
// Matched by filename only (not full path), so this works regardless of
// which folder depth a file lives at.
function siteGateExemptFiles() {
    return [
        'site-access.php',
        'coming-soon.php',
        'cron-backup.php',
        'razorpay-webhook.php',
    ];
}

function isSiteGateUnlocked() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['site_gate_unlocked'])) return true;

    if (!empty($_COOKIE['site_gate_token'])) {
        $expected = hash_hmac('sha256', 'unlocked', SITE_GATE_COOKIE_SECRET);
        if (hash_equals($expected, $_COOKIE['site_gate_token'])) {
            $_SESSION['site_gate_unlocked'] = true; // cache for the rest of this session too
            return true;
        }
    }
    return false;
}

// Called by site-access.php after a correct username/password submission.
function unlockSiteGate() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['site_gate_unlocked'] = true;

    $token = hash_hmac('sha256', 'unlocked', SITE_GATE_COOKIE_SECRET);
    setcookie('site_gate_token', $token, [
        'expires'  => time() + 60 * 60 * 24 * 90, // 90 days
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

function enforceSiteGate() {
    if (!defined('SITE_LAUNCH_MODE') || !SITE_LAUNCH_MODE) return; // gate turned off — normal operation

    $currentFile = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if (in_array($currentFile, siteGateExemptFiles(), true)) return;

    if (isSiteGateUnlocked()) return;

    // Not unlocked — show the public coming-soon page instead of whatever
    // was actually requested, and stop here. coming-soon.php is deliberately
    // self-contained (no config.php dependency) so this can never recurse.
    http_response_code(200);
    require __DIR__ . '/../coming-soon.php';
    exit;
}
