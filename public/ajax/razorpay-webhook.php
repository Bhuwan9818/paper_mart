<?php
// public/ajax/razorpay-webhook.php
// Server-to-server confirmation from Razorpay — this is the reliable path
// (unlike the browser callback, it fires even if the vendor closes the tab
// right after paying). Configure this URL in Razorpay Dashboard → Settings
// → Webhooks. See the setup guide for the exact steps.

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/razorpay.php';

header('Content-Type: application/json');

$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (!razorpayVerifyWebhookSignature($rawBody, $signature)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Invalid webhook signature']);
    exit;
}

$payload = json_decode($rawBody, true);
$event   = $payload['event'] ?? '';

if (in_array($event, ['payment.captured', 'order.paid'])) {
    $paymentEntity = $payload['payload']['payment']['entity'] ?? null;
    if ($paymentEntity) {
        $orderId   = $paymentEntity['order_id'] ?? '';
        $paymentId = $paymentEntity['id'] ?? '';

        $txn = $pdo->prepare("SELECT * FROM payment_transactions WHERE gateway_order_id=?");
        $txn->execute([$orderId]); $txn = $txn->fetch();

        if ($txn) {
            // Signature on the checkout callback isn't available here (that's a
            // browser-side field) — the webhook's own signature check above is
            // what proves authenticity for this path instead.
            fulfillPaymentTransaction($pdo, $txn, $paymentId, 'webhook-confirmed');
        }
    }
}

http_response_code(200);
echo json_encode(['ok' => true]);
