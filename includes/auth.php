<?php
// ============================================================
// includes/auth.php — Authentication & session helpers
// ============================================================

if (session_status() === PHP_SESSION_NONE) session_start();

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function requireRole($role) {
    requireLogin();
    $r = $_SESSION['role'] ?? '';
    if ($r !== $role && $r !== 'admin') {
        flash('error', 'You do not have permission to access that page.');
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function requireRoleStrict($role) {
    requireLogin();
    $r = $_SESSION['role'] ?? '';
    if ($r !== $role) {
        flash('error', 'Access denied.');
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function currentUser() {
    return [
        'id'    => $_SESSION['user_id'] ?? null,
        'name'  => $_SESSION['name']    ?? '',
        'email' => $_SESSION['email']   ?? '',
        'role'  => $_SESSION['role']    ?? '',
    ];
}

function loginUser($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['role']    = $user['role'] ?? '';
    $_SESSION['is_team_member'] = false;
}

// Logs a team member in "as" the vendor account they belong to, so every
// existing vendor-scoped query (WHERE vendor_id = $_SESSION['user_id']) keeps
// working unchanged. The real identity + permissions are tracked separately.
function loginTeamMember($member, $vendorUser) {
    $_SESSION['user_id'] = $vendorUser['id'];   // vendor account being operated on
    $_SESSION['name']    = $vendorUser['name'];
    $_SESSION['email']   = $vendorUser['email'];
    $_SESSION['role']    = 'vendor';

    $_SESSION['is_team_member']     = true;
    $_SESSION['team_member_id']     = $member['id'];
    $_SESSION['team_member_name']   = $member['name'];
    $_SESSION['team_member_email']  = $member['email'];
    $_SESSION['team_permissions']   = json_decode($member['permissions'] ?? '[]', true) ?: [];
}

function logoutUser() {
    // Clear session data and destroy session cookie for a clean logout
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

// Flash messages
function flash($type, $msg) {
    $_SESSION['flash'][$type] = $msg;
}

function getFlash() {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// CSRF
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        die('Invalid CSRF token.');
    }
}
