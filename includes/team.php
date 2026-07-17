<?php
// ============================================================
// includes/team.php — Vendor team member helpers
// ============================================================

// All permission keys that can be delegated to a team member.
// 'dashboard' is always available and not shown as a toggle.
// 'subscription' (billing) is intentionally NEVER delegable.
function teamPermissionCatalog() {
    return [
        'notifications'     => ['label' => 'Notifications',        'icon' => '🔔'],
        'add-product'       => ['label' => 'Add Product',           'icon' => '➕'],
        'manage-products'   => ['label' => 'Manage Products',       'icon' => '📦'],
        'enquiries'         => ['label' => 'Enquiries',              'icon' => '📩'],
        'catalogue-request' => ['label' => 'Request Category',      'icon' => '🗂️'],
        'analytics'         => ['label' => 'Analytics',              'icon' => '📊'],
        'performance'       => ['label' => 'Performance',            'icon' => '🚀'],
        'ads'               => ['label' => 'Banner Ads',             'icon' => '🎯'],
        'business-profile'  => ['label' => 'Business Profile',      'icon' => '🏢'],
        'team'              => ['label' => 'Manage Team',            'icon' => '👥'],
    ];
}

function isTeamMemberSession() {
    return !empty($_SESSION['is_team_member']);
}

// Returns the vendor id whose data is being worked on — same value whether
// the vendor themself or one of their team members is logged in.
function effectiveVendorId() {
    return $_SESSION['user_id'] ?? null;
}

// Human label for who is actually driving the session (for headers/logs)
function actingUserLabel() {
    if (isTeamMemberSession()) {
        return $_SESSION['team_member_name'] . ' (Team Member)';
    }
    return $_SESSION['name'] ?? '';
}

function teamMemberHasPermission($key) {
    if (!isTeamMemberSession()) return true; // vendor owner: full access
    $perms = $_SESSION['team_permissions'] ?? [];
    return in_array($key, $perms, true);
}

// Call at the top of any vendor page (after requireRoleStrict('vendor')) to
// block team members who haven't been granted access to that section.
function requirePermission($key) {
    if (isTeamMemberSession() && !teamMemberHasPermission($key)) {
        flash('error', 'You do not have permission to access that section. Contact your account owner.');
        header('Location: ' . BASE_URL . '/vendor/dashboard.php');
        exit;
    }
}

// Billing/subscription/team-account-deletion must always be vendor-owner only,
// regardless of granted permissions.
function requireVendorOwner() {
    if (isTeamMemberSession()) {
        flash('error', 'Only the account owner can access this section.');
        header('Location: ' . BASE_URL . '/vendor/dashboard.php');
        exit;
    }
}

function getVendorTeamLimit($pdo, $vendorId) {
    $sub = getVendorSubscription($pdo, $vendorId);
    if (!$sub) return 0;
    return (int)($sub['team_member_limit'] ?? 0);
}

function getVendorTeamCount($pdo, $vendorId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendor_team_members WHERE vendor_id=?");
    $stmt->execute([$vendorId]);
    return (int)$stmt->fetchColumn();
}

function logTeamActivity($pdo, $vendorId, $teamMemberId, $action, $details = '') {
    try {
        $pdo->prepare("INSERT INTO vendor_team_activity (vendor_id, team_member_id, action, details) VALUES (?,?,?,?)")
            ->execute([$vendorId, $teamMemberId, $action, $details]);
    } catch (Exception $e) { /* non-critical */ }
}
