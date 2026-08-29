<?php
// ============================================================
// includes/customer_subscription.php — Customer plan helpers
// Mirrors includes/subscription.php (the vendor version) but for the
// customer-facing Free/Premium plan: compare limits, TDS download limits,
// enquiry limits, full-attribute access, and full-analytics access.
// ============================================================

function getCustomerSubscription($pdo, $customerId) {
    $stmt = $pdo->prepare("
        SELECT cs.*, cp.name AS plan_name, cp.slug, cp.compare_limit, cp.tds_limit,
               cp.enquiry_limit, cp.full_attributes, cp.full_analytics, cp.color, cp.badge
        FROM customer_subscriptions cs
        JOIN customer_plans cp ON cp.id = cs.plan_id
        WHERE cs.customer_id = ?
        ORDER BY cs.created_at DESC LIMIT 1
    ");
    $stmt->execute([$customerId]);
    $sub = $stmt->fetch();

    if (!$sub) {
        // Auto-assign the Free plan on first access — every customer always
        // has a plan row, so every gate check below can assume $sub exists.
        $freePlan = $pdo->query("SELECT * FROM customer_plans WHERE slug='free' LIMIT 1")->fetch();
        if (!$freePlan) return null; // migration not run yet
        $pdo->prepare("INSERT INTO customer_subscriptions (customer_id,plan_id,status,started_at) VALUES(?,?,'active',NOW())")
            ->execute([$customerId, $freePlan['id']]);
        return getCustomerSubscription($pdo, $customerId);
    }

    if ($sub['status'] === 'active' && $sub['expires_at'] && strtotime($sub['expires_at']) < time()) {
        $pdo->prepare("UPDATE customer_subscriptions SET status='expired' WHERE id=?")->execute([$sub['id']]);
        // Fall back to Free immediately so the customer isn't left with no
        // usable plan between expiry and their next page load.
        $freePlan = $pdo->query("SELECT * FROM customer_plans WHERE slug='free' LIMIT 1")->fetch();
        if ($freePlan) {
            $pdo->prepare("INSERT INTO customer_subscriptions (customer_id,plan_id,status,started_at) VALUES(?,?,'active',NOW())")
                ->execute([$customerId, $freePlan['id']]);
            return getCustomerSubscription($pdo, $customerId);
        }
    }
    return $sub;
}

function getCustomerUsage($pdo, $customerId) {
    $month = date('Y-m');
    $stmt = $pdo->prepare("SELECT * FROM customer_usage WHERE customer_id=? AND month_year=?");
    $stmt->execute([$customerId, $month]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->prepare("INSERT IGNORE INTO customer_usage (customer_id,month_year) VALUES(?,?)")->execute([$customerId,$month]);
        return ['compares_used'=>0,'tds_downloaded'=>0,'enquiries_sent'=>0];
    }
    return $row;
}

function incrementCustomerUsage($pdo, $customerId, $column) {
    if (!in_array($column, ['compares_used','tds_downloaded','enquiries_sent'], true)) return;
    $month = date('Y-m');
    $pdo->prepare("INSERT INTO customer_usage (customer_id,month_year,$column) VALUES (?,?,1)
                    ON DUPLICATE KEY UPDATE $column = $column + 1")
        ->execute([$customerId, $month]);
}

// Generic limit checker — used by compare/TDS/enquiry gates below.
function checkCustomerLimit($pdo, $customerId, $sub, $limitField, $usageField) {
    $limit = (int)($sub[$limitField] ?? 0);
    if ($limit === -1) return ['allowed'=>true, 'remaining'=>-1, 'limit'=>-1, 'used'=>0];
    $usage = getCustomerUsage($pdo, $customerId);
    $used = (int)($usage[$usageField] ?? 0);
    $remaining = $limit - $used;
    return ['allowed'=>$remaining > 0, 'remaining'=>max(0,$remaining), 'limit'=>$limit, 'used'=>$used];
}

function checkCompareLimit($pdo, $customerId, $sub) { return checkCustomerLimit($pdo, $customerId, $sub, 'compare_limit', 'compares_used'); }
function checkTdsLimit($pdo, $customerId, $sub)     { return checkCustomerLimit($pdo, $customerId, $sub, 'tds_limit', 'tds_downloaded'); }
function checkCustomerEnquiryLimit($pdo, $customerId, $sub) { return checkCustomerLimit($pdo, $customerId, $sub, 'enquiry_limit', 'enquiries_sent'); }

function customerHasFullAttributes($sub) { return !empty($sub['full_attributes']); }
function customerHasFullAnalytics($sub)  { return !empty($sub['full_analytics']); }

// Small reusable upgrade-prompt banner, shown wherever a free-plan customer
// hits a limit — consistent styling/copy everywhere it appears.
function customerUpgradeBanner($message) {
    return '<div style="background:#fff8e6;border:1px solid #f0d896;border-radius:10px;padding:14px 18px;margin:14px 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <span style="font-size:20px">⭐</span>
        <div style="flex:1;font-size:13.5px;color:#7a5c00">' . $message . '</div>
        <a href="' . BASE_URL . '/customer/subscription.php" class="btn btn-primary btn-sm" style="white-space:nowrap">Upgrade to Premium</a>
    </div>';
}
