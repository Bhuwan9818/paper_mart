<?php
// includes/subscription.php — Subscription helpers for vendor pages

function getVendorSubscription($pdo, $vendorId) {
    $stmt = $pdo->prepare("
        SELECT vs.*, sp.name AS plan_name, sp.slug, sp.product_limit, sp.enquiry_limit,
               sp.image_limit, sp.analytics, sp.priority_listing, sp.color, sp.features
        FROM vendor_subscriptions vs
        JOIN subscription_plans sp ON sp.id = vs.plan_id
        WHERE vs.vendor_id = ?
        ORDER BY vs.created_at DESC LIMIT 1
    ");
    $stmt->execute([$vendorId]);
    $sub = $stmt->fetch();
    if (!$sub) {
        // Auto-assign free plan
        $freePlan = $pdo->query("SELECT * FROM subscription_plans WHERE slug='free' LIMIT 1")->fetch();
        if ($freePlan) {
            $pdo->prepare("INSERT INTO vendor_subscriptions (vendor_id,plan_id,status,started_at,expires_at,trial_ends_at) VALUES(?,?,'trial',NOW(),DATE_ADD(NOW(),INTERVAL 14 DAY),DATE_ADD(NOW(),INTERVAL 14 DAY))")
                ->execute([$vendorId, $freePlan['id']]);
            return getVendorSubscription($pdo, $vendorId);
        }
        return null;
    }
    // Check expiry
    if ($sub['status'] === 'active' && $sub['expires_at'] && strtotime($sub['expires_at']) < time()) {
        $pdo->prepare("UPDATE vendor_subscriptions SET status='expired' WHERE id=?")->execute([$sub['id']]);
        $sub['status'] = 'expired';
    }
    if ($sub['status'] === 'trial' && $sub['trial_ends_at'] && strtotime($sub['trial_ends_at']) < time()) {
        $pdo->prepare("UPDATE vendor_subscriptions SET status='expired' WHERE id=?")->execute([$sub['id']]);
        $sub['status'] = 'expired';
    }
    return $sub;
}

function getVendorUsage($pdo, $vendorId) {
    $month = date('Y-m');
    $stmt = $pdo->prepare("SELECT * FROM vendor_usage WHERE vendor_id=? AND month_year=?");
    $stmt->execute([$vendorId, $month]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->prepare("INSERT IGNORE INTO vendor_usage (vendor_id,month_year) VALUES(?,?)")->execute([$vendorId,$month]);
        return ['products_added'=>0,'enquiries_sent'=>0];
    }
    return $row;
}

function checkProductLimit($pdo, $vendorId, $sub) {
    if ($sub['product_limit'] === -1) return ['allowed'=>true,'remaining'=>-1];
    $count = $pdo->prepare("SELECT COUNT(*) FROM products WHERE vendor_id=?");
    $count->execute([$vendorId]);
    $total = (int)$count->fetchColumn();
    $remaining = $sub['product_limit'] - $total;
    return ['allowed' => $remaining > 0, 'remaining' => $remaining, 'total' => $total, 'limit' => $sub['product_limit']];
}

function checkEnquiryLimit($pdo, $vendorId, $sub) {
    if ($sub['enquiry_limit'] === -1) return ['allowed'=>true,'remaining'=>-1];
    $usage = getVendorUsage($pdo, $vendorId);
    $remaining = $sub['enquiry_limit'] - $usage['enquiries_sent'];
    return ['allowed' => $remaining > 0, 'remaining' => $remaining, 'used' => $usage['enquiries_sent'], 'limit' => $sub['enquiry_limit']];
}

function subscriptionBanner($sub) {
    if (!$sub) return '';
    $daysLeft = 0;
    if ($sub['status'] === 'trial' && $sub['trial_ends_at']) {
        $daysLeft = max(0, ceil((strtotime($sub['trial_ends_at']) - time()) / 86400));
        return "<div class='sub-alert-banner trial'>
            <span style='font-size:22px'>⏱️</span>
            <div style='flex:1'><strong>Trial ends in {$daysLeft} day(s)</strong> — You're on the Free trial. Upgrade to unlock full features.</div>
            <a href='/dashv10_Fixed/vendor/subscription.php' class='btn btn-warning btn-sm'>Upgrade Now</a>
        </div>";
    }
    if ($sub['status'] === 'expired') {
        return "<div class='sub-alert-banner expired'>
            <span style='font-size:22px'>🔒</span>
            <div style='flex:1'><strong>Subscription Expired</strong> — Renew now to continue adding products and receiving enquiries.</div>
            <a href='/dashv10_Fixed/vendor/subscription.php' class='btn btn-danger btn-sm'>Renew Subscription</a>
        </div>";
    }
    if ($sub['status'] === 'active') {
        $expiry = $sub['expires_at'] ? date('d M Y', strtotime($sub['expires_at'])) : 'N/A';
        $daysLeft = $sub['expires_at'] ? max(0,ceil((strtotime($sub['expires_at'])-time())/86400)) : 999;
        if ($daysLeft <= 7) {
            return "<div class='sub-alert-banner trial'>
                <span style='font-size:22px'>⚠️</span>
                <div style='flex:1'><strong>Subscription expiring soon</strong> — Your {$sub['plan_name']} plan expires on {$expiry}.</div>
                <a href='/dashv10_Fixed/vendor/subscription.php' class='btn btn-warning btn-sm'>Renew</a>
            </div>";
        }
    }
    return '';
}

function getPlanFeatures($sub) {
    return json_decode($sub['features'] ?? '[]', true) ?: [];
}
