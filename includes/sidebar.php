<?php
if (!function_exists('isTeamMemberSession')) {
    require_once __DIR__ . '/team.php';
}
$user = currentUser();
$role = $user['role'];
$activePage = $activePage ?? '';
$isTeamMember = isTeamMemberSession();

$subInfo = null;
$unreadNotifs = 0;
if ($role === 'vendor') {
    if (function_exists('getVendorSubscription')) {
        $subInfo = getVendorSubscription($pdo, $user['id']);
    }
    try {
        $nStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $nStmt->execute([$user['id']]);
        $unreadNotifs = (int)$nStmt->fetchColumn();
    } catch(Exception $e) { $unreadNotifs = 0; }
}

$vendorNav = [
    ['section' => 'Main'],
    ['icon'=>'⊞',  'label'=>'Dashboard',       'href'=>BASE_URL.'/vendor/dashboard.php',       'page'=>'dashboard'],
    ['icon'=>'🔔', 'label'=>'Notifications',    'href'=>BASE_URL.'/vendor/notifications.php',   'page'=>'notifications', 'badge'=>$unreadNotifs, 'perm'=>'notifications'],
    ['section'=>'Products'],
    ['icon'=>'➕', 'label'=>'Add Product',       'href'=>BASE_URL.'/vendor/add-product.php',    'page'=>'add-product', 'perm'=>'add-product'],
    ['icon'=>'📦', 'label'=>'My Products',       'href'=>BASE_URL.'/vendor/manage-products.php','page'=>'manage-products', 'perm'=>'manage-products'],
    ['section'=>'Sales'],
    ['icon'=>'📩', 'label'=>'Enquiries',         'href'=>BASE_URL.'/vendor/enquiries.php',      'page'=>'enquiries', 'perm'=>'enquiries'],
    ['section'=>'Catalogue'],
    ['icon'=>'🗂️', 'label'=>'Request Category',  'href'=>BASE_URL.'/vendor/catalogue-request.php','page'=>'catalogue-request', 'perm'=>'catalogue-request'],
    ['section'=>'Insights'],
    ['icon'=>'📊', 'label'=>'Analytics',         'href'=>BASE_URL.'/vendor/analytics.php',      'page'=>'analytics', 'perm'=>'analytics'],
    ['icon'=>'🚀', 'label'=>'Performance',        'href'=>BASE_URL.'/vendor/performance.php',   'page'=>'performance', 'perm'=>'performance'],
    ['section'=>'Account'],
    ['icon'=>'💳', 'label'=>'Subscription',      'href'=>BASE_URL.'/vendor/subscription.php',  'page'=>'subscription', 'ownerOnly'=>true],
    ['icon'=>'🎯', 'label'=>'Banner Ads',         'href'=>BASE_URL.'/vendor/ads.php',           'page'=>'ads', 'perm'=>'ads'],
    // ['icon'=>'👤', 'label'=>'My Profile',         'href'=>BASE_URL.'/vendor/profile.php',       'page'=>'profile'],
    ['icon'=>'🏢', 'label'=>'Business Profile',    'href'=>BASE_URL.'/vendor/business-profile.php','page'=>'business-profile', 'perm'=>'business-profile'],
    ['icon'=>'👥', 'label'=>'Team Members',       'href'=>BASE_URL.'/vendor/team.php',           'page'=>'team', 'perm'=>'team'],
];

$adminNav = [
    ['icon'=>'⊞', 'label'=>'Dashboard',      'href'=>BASE_URL.'/admin/dashboard.php',      'page'=>'dashboard'],
    ['section'=>'Users'],
    ['icon'=>'🏪','label'=>'Vendors',         'href'=>BASE_URL.'/admin/vendors.php',        'page'=>'vendors'],
    ['icon'=>'👥','label'=>'Vendor Teams',    'href'=>BASE_URL.'/admin/vendor-teams.php',   'page'=>'vendor-teams'],
    ['icon'=>'👤','label'=>'Customers',       'href'=>BASE_URL.'/admin/customers.php',      'page'=>'customers'],
    ['section'=>'Catalogue'],
    ['icon'=>'🏭','label'=>'Industries',      'href'=>BASE_URL.'/admin/industries.php',     'page'=>'industries'],
    ['icon'=>'🗂️','label'=>'Categories',      'href'=>BASE_URL.'/admin/categories.php',    'page'=>'categories'],
    ['icon'=>'🔖','label'=>'Product Types',   'href'=>BASE_URL.'/admin/product-types.php', 'page'=>'product-types'],
    ['icon'=>'📋','label'=>'Attributes',      'href'=>BASE_URL.'/admin/attributes.php',    'page'=>'attributes'],
    ['section'=>'Operations'],
    ['icon'=>'📦','label'=>'All Products',    'href'=>BASE_URL.'/admin/products.php',      'page'=>'products'],
    ['icon'=>'📩','label'=>'All Enquiries',   'href'=>BASE_URL.'/admin/enquiries.php',     'page'=>'enquiries'],
    ['icon'=>'🌐','label'=>'Web Enquiries',  'href'=>BASE_URL.'/admin/web-enquiries.php', 'page'=>'web-enquiries'],
    ['icon'=>'🗂️','label'=>'Catalogue Requests','href'=>BASE_URL.'/admin/catalogue-requests.php','page'=>'catalogue-requests'],
    ['icon'=>'➕','label'=>'Add Product', 'href'=>BASE_URL.'/admin/add-product.php',    'page'=>'admin-add-products'],
    ['section'=>'Finance'],
    ['icon'=>'💰','label'=>'Payments',        'href'=>BASE_URL.'/admin/payments.php',      'page'=>'payments'],
    ['icon'=>'💳','label'=>'Subscriptions',   'href'=>BASE_URL.'/admin/subscriptions.php', 'page'=>'subscriptions'],
    ['icon'=>'🎯','label'=>'Ad Management',   'href'=>BASE_URL.'/admin/ads.php',           'page'=>'ads'],
    ['section'=>'Intelligence'],
    ['icon'=>'💬', 'label'=>'Chatbot Manager', 'href'=>BASE_URL.'/admin/chatbot.php?tab=intents', 'page'=>'chatbot'],
    ['icon'=>'📈','label'=>'Analytics',       'href'=>BASE_URL.'/admin/analytics.php',     'page'=>'analytics'],
    ['icon'=>'📄','label'=>'Reports',         'href'=>BASE_URL.'/admin/reports.php',       'page'=>'reports'],
];

$customerNav = [
    ['icon'=>'⊞','label'=>'Dashboard',    'href'=>BASE_URL.'/customer/dashboard.php', 'page'=>'dashboard'],
    ['icon'=>'📩','label'=>'My Enquiries', 'href'=>BASE_URL.'/customer/enquiries.php', 'page'=>'enquiries'],
    ['icon'=>'👤','label'=>'My Profile',   'href'=>BASE_URL.'/customer/profile.php',   'page'=>'profile'],
];

$nav = match($role) {
    'admin'=>$adminNav, 'vendor'=>$vendorNav, 'customer'=>$customerNav, default=>[]
};

if ($role === 'vendor' && $isTeamMember) {
    $nav = array_values(array_filter($nav, function($item) {
        if (isset($item['section'])) return true; // section labels re-filtered below
        if (!empty($item['ownerOnly'])) return false; // billing never visible to team members
        if (!empty($item['perm'])) return teamMemberHasPermission($item['perm']);
        return true;
    }));
    // Drop section headers that ended up with no items under them
    $clean = [];
    foreach ($nav as $i => $item) {
        if (isset($item['section'])) {
            $hasNext = false;
            for ($j = $i+1; $j < count($nav); $j++) {
                if (isset($nav[$j]['section'])) break;
                $hasNext = true; break;
            }
            if (!$hasNext) continue;
        }
        $clean[] = $item;
    }
    $nav = $clean;
}

$planName   = $subInfo ? ucfirst($subInfo['plan_name']) : 'Free';
$planStatus = $subInfo ? $subInfo['status'] : 'trial';
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon">⟡</div>
    <div>
      <div class="brand-text">VendorHub</div>
      <div class="brand-sub"><?= $role === 'vendor' ? 'Vendor Dashboard' : ($role === 'admin' ? 'Admin Panel' : 'Customer Portal') ?></div>
    </div>
  </div>

  <div class="sidebar-user">
    <div class="su-avatar"><?= avatarLetter($isTeamMember ? $_SESSION['team_member_name'] : $user['name']) ?></div>
    <div style="min-width:0;flex:1">
      <div class="su-name"><?= sanitize($isTeamMember ? $_SESSION['team_member_name'] : $user['name']) ?></div>
      <div class="su-plan">
        <span class="su-plan-badge"><?= sanitize($planName) ?></span>
        <?php if ($planStatus === 'trial'): ?><span style="font-size:9.5px;color:#f59e0b;margin-left:2px">TRIAL</span><?php endif; ?>
      </div>
      <?php if ($isTeamMember): ?>
        <div style="font-size:10.5px;color:var(--sidebar-muted);margin-top:2px">Team access · <?= sanitize($user['name']) ?>'s account</div>
      <?php endif; ?>
    </div>
  </div>

  <nav>
    <?php foreach ($nav as $item): ?>
      <?php if (isset($item['section'])): ?>
        <div class="nav-section-label"><?= $item['section'] ?></div>
      <?php else: ?>
        <a href="<?= $item['href'] ?>" class="<?= $activePage===$item['page']?'active':'' ?>">
          <span class="nav-icon"><?= $item['icon'] ?></span>
          <?= $item['label'] ?>
          <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
            <span class="nav-badge"><?= (int)$item['badge'] ?></span>
          <?php endif; ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-bottom">
    <?php if ($role === 'vendor' && $subInfo && in_array($subInfo['slug'],['free','starter'])): ?>
    <div class="sidebar-sub-banner">
      <h4>🚀 Upgrade your plan</h4>
      <p>Unlock unlimited products &amp; analytics.</p>
      <a href="<?= BASE_URL ?>/vendor/subscription.php">View Plans →</a>
    </div>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/logout.php" class="btn-logout">🚪 Logout</a>
  </div>
</aside>
