<?php
// ajax/create-payment-order.php
// Called by vendor/subscription.php and vendor/ads.php right before opening
// the Razorpay Checkout widget. NEVER trusts a client-supplied amount — it
// always looks up the real price server-side from the plan/package.

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/team.php';
require_once __DIR__ . '/../includes/razorpay.php';

ob_end_clean();
header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['role'] !== 'vendor') {
    echo json_encode(['ok' => false, 'msg' => 'Please log in again and retry.']); exit;
}
$token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid session, please reload and try again.']); exit;
}
// Billing/payments are the account owner's responsibility only — never a delegated team member.
if (isTeamMemberSession()) {
    echo json_encode(['ok' => false, 'msg' => 'Only the account owner can make payments.']); exit;
}

$vendorId = effectiveVendorId();
$purpose  = $_POST['purpose'] ?? '';

if ($purpose === 'subscription') {
    $planId = (int)($_POST['plan_id'] ?? 0);
    $cycle  = ($_POST['billing_cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';

    $plan = $pdo->prepare("SELECT * FROM subscription_plans WHERE id=? AND is_active=1");
    $plan->execute([$planId]); $plan = $plan->fetch();
    if (!$plan) { echo json_encode(['ok' => false, 'msg' => 'Plan not found.']); exit; }

    $amount = (float)($cycle === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly']);
    if ($amount <= 0) { echo json_encode(['ok' => false, 'msg' => 'This plan is free — no payment needed.']); exit; }

    $referenceId = $planId;
    $extra = json_encode(['billing_cycle' => $cycle]);
    $description = $plan['name'] . ' plan (' . $cycle . ')';

} elseif ($purpose === 'ad') {
    $adId = (int)($_POST['ad_id'] ?? 0);
    $ad = $pdo->prepare("SELECT ba.*, p.price, p.name AS package_name FROM banner_ads ba JOIN ad_packages p ON p.id=ba.package_id WHERE ba.id=? AND ba.vendor_id=?");
    $ad->execute([$adId, $vendorId]); $ad = $ad->fetch();
    if (!$ad) { echo json_encode(['ok' => false, 'msg' => 'Ad booking not found.']); exit; }

    // Already paid? Don't let them pay twice.
    $already = $pdo->prepare("SELECT COUNT(*) FROM ad_payments WHERE ad_id=? AND status='paid'");
    $already->execute([$adId]);
    if ($already->fetchColumn() > 0) { echo json_encode(['ok' => false, 'msg' => 'This ad has already been paid for.']); exit; }

    $amount = (float)$ad['price'];
    $referenceId = $adId;
    $extra = json_encode(['package_id' => $ad['package_id']]);
    $description = 'Banner ad — ' . $ad['package_name'];

} else {
    echo json_encode(['ok' => false, 'msg' => 'Unknown payment purpose.']); exit;
}

$receipt = strtoupper($purpose) . '_' . $referenceId . '_' . time();
$order = razorpayCreateOrder($amount, $receipt, ['vendor_id' => $vendorId, 'purpose' => $purpose]);

if (!$order || empty($order['id'])) {
    echo json_encode(['ok' => false, 'msg' => 'Could not start payment. Please try again in a moment.']); exit;
}

$pdo->prepare("INSERT INTO payment_transactions (vendor_id,purpose,reference_id,extra_data,amount,gateway_order_id,status) VALUES (?,?,?,?,?,?,'created')")
    ->execute([$vendorId, $purpose, $referenceId, $extra, $amount, $order['id']]);
$txnId = $pdo->lastInsertId();

$vendor = $pdo->prepare("SELECT name,email,phone FROM users WHERE id=?"); $vendor->execute([$vendorId]); $vendor = $vendor->fetch();

echo json_encode([
    'ok'          => true,
    'key_id'      => RAZORPAY_KEY_ID,
    'order_id'    => $order['id'],
    'amount'      => $order['amount'], // paise, echoed back to Checkout as-is
    'currency'    => 'INR',
    'txn_id'      => $txnId,
    'name'        => 'PaperMart',
    'description' => $description,
    'prefill'     => ['name' => $vendor['name'] ?? '', 'email' => $vendor['email'] ?? '', 'contact' => $vendor['phone'] ?? ''],
]);
