<?php
// ajax/verify-payment.php
// Called by the browser immediately after Razorpay Checkout reports success.
// Verifies the cryptographic signature (never trust the browser alone) then
// runs the shared fulfillment logic in includes/razorpay.php.

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

if (!isLoggedIn() || $_SESSION['role'] !== 'vendor' || isTeamMemberSession()) {
    echo json_encode(['ok' => false, 'msg' => 'Please log in again and retry.']); exit;
}
$token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid session, please reload and try again.']); exit;
}

$vendorId  = effectiveVendorId();
$orderId   = $_POST['razorpay_order_id'] ?? '';
$paymentId = $_POST['razorpay_payment_id'] ?? '';
$signature = $_POST['razorpay_signature'] ?? '';
$txnId     = (int)($_POST['txn_id'] ?? 0);

if (!$orderId || !$paymentId || !$signature || !$txnId) {
    echo json_encode(['ok' => false, 'msg' => 'Missing payment details.']); exit;
}

$txn = $pdo->prepare("SELECT * FROM payment_transactions WHERE id=? AND vendor_id=? AND gateway_order_id=?");
$txn->execute([$txnId, $vendorId, $orderId]); $txn = $txn->fetch();
if (!$txn) { echo json_encode(['ok' => false, 'msg' => 'Transaction not found.']); exit; }

if (!razorpayVerifySignature($orderId, $paymentId, $signature)) {
    echo json_encode(['ok' => false, 'msg' => 'Payment could not be verified. If money was deducted, it will be auto-refunded — contact support if it is not reflected within a few days.']); exit;
}

// Extra safety: confirm directly with Razorpay that this payment was actually captured.
$paymentDetails = razorpayFetchPayment($paymentId);
if (!$paymentDetails || !in_array($paymentDetails['status'] ?? '', ['captured', 'authorized'])) {
    echo json_encode(['ok' => false, 'msg' => 'Payment not yet confirmed by the gateway. Please wait a moment and refresh.']); exit;
}

$result = fulfillPaymentTransaction($pdo, $txn, $paymentId, $signature);
echo json_encode(['ok' => true, 'purpose' => $txn['purpose']]);
