<?php
// ============================================================
// includes/razorpay.php — Razorpay payment gateway integration
// Uses plain cURL against the REST API — no Composer/SDK required.
// Docs: https://razorpay.com/docs/api/orders/ and /payments/
// ============================================================

// Low-level authenticated request to the Razorpay REST API.
function razorpayApiRequest($method, $endpoint, $payload = null) {
    $ch = curl_init("https://api.razorpay.com/v1" . $endpoint);
    curl_setopt($ch, CURLOPT_USERPWD, RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('Razorpay cURL error: ' . $curlErr);
        return ['ok' => false, 'error' => $curlErr];
    }
    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['ok' => true, 'data' => $data];
    }
    error_log('Razorpay API error (' . $httpCode . '): ' . $response);
    return ['ok' => false, 'error' => $data['error']['description'] ?? 'Payment gateway error', 'raw' => $data];
}

// Creates a Razorpay Order. Amount must be in the smallest currency unit
// (paise for INR, i.e. ₹499 => 49900). Returns the decoded order or null.
function razorpayCreateOrder($amountRupees, $receipt, $notes = []) {
    $result = razorpayApiRequest('POST', '/orders', [
        'amount'   => (int) round($amountRupees * 100),
        'currency' => 'INR',
        'receipt'  => $receipt,
        'notes'    => $notes,
    ]);
    return $result['ok'] ? $result['data'] : null;
}

// Verifies the signature returned by Razorpay Checkout after a successful
// payment. This is the standard HMAC-SHA256 check Razorpay documents —
// NEVER trust a "payment succeeded" message from the browser without this.
function razorpayVerifySignature($orderId, $paymentId, $signature) {
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, RAZORPAY_KEY_SECRET);
    return hash_equals($expected, $signature);
}

// Verifies an incoming webhook's signature (different secret from the API
// key — the one you set when creating the webhook in the dashboard).
function razorpayVerifyWebhookSignature($rawBody, $signatureHeader) {
    if (!RAZORPAY_WEBHOOK_SECRET || RAZORPAY_WEBHOOK_SECRET === 'YOUR_WEBHOOK_SECRET_HERE') return false;
    $expected = hash_hmac('sha256', $rawBody, RAZORPAY_WEBHOOK_SECRET);
    return hash_equals($expected, $signatureHeader ?? '');
}

// Double-checks a payment's actual status directly with Razorpay (belt and
// braces alongside the signature check) before we activate anything.
function razorpayFetchPayment($paymentId) {
    $result = razorpayApiRequest('GET', '/payments/' . $paymentId);
    return $result['ok'] ? $result['data'] : null;
}

// ------------------------------------------------------------------
// Shared "payment succeeded" handler — called from BOTH the browser-side
// verify-payment endpoint AND the server-side webhook, so activation logic
// lives in exactly one place regardless of which path confirms it first.
// Safe to call twice for the same transaction (idempotent via status check).
// ------------------------------------------------------------------
require_once __DIR__ . '/invoice.php';

function fulfillPaymentTransaction($pdo, $txn, $paymentId, $signature) {
    if ($txn['status'] === 'paid') {
        return ['ok' => true, 'already' => true]; // already processed (e.g. webhook beat the browser callback)
    }

    $pdo->prepare("UPDATE payment_transactions SET status='paid', gateway_payment_id=?, gateway_signature=?, paid_at=NOW() WHERE id=?")
        ->execute([$paymentId, $signature, $txn['id']]);

    $extra = json_decode($txn['extra_data'] ?? '{}', true) ?: [];

    if ($txn['purpose'] === 'subscription') {
        $planId = (int)$txn['reference_id'];
        $cycle  = $extra['billing_cycle'] ?? 'monthly';
        $vendorId = (int)$txn['vendor_id'];

        $pdo->prepare("UPDATE vendor_subscriptions SET status='cancelled' WHERE vendor_id=? AND status IN('active','trial')")
            ->execute([$vendorId]);
        $expires = $cycle === 'yearly' ? date('Y-m-d H:i:s', strtotime('+1 year')) : date('Y-m-d H:i:s', strtotime('+1 month'));
        $pdo->prepare("INSERT INTO vendor_subscriptions (vendor_id,plan_id,billing_cycle,status,started_at,expires_at) VALUES(?,?,?,'active',NOW(),?)")
            ->execute([$vendorId, $planId, $cycle, $expires]);
        $pdo->prepare("INSERT INTO subscription_payments (vendor_id,plan_id,amount,billing_cycle,status,paid_at) VALUES(?,?,?,?,'paid',NOW())")
            ->execute([$vendorId, $planId, $txn['amount'], $cycle]);
        $subPaymentId = (int)$pdo->lastInsertId();

        $planName = $pdo->prepare("SELECT name FROM subscription_plans WHERE id=?");
        $planName->execute([$planId]); $planName = $planName->fetchColumn() ?: 'Subscription';
        createInvoice($pdo, 'subscription', $vendorId, $subPaymentId, $txn['id'], $txn['amount'],
            $planName . ' Plan — ' . ucfirst($cycle) . ' Subscription', $paymentId);
    }

    if ($txn['purpose'] === 'ad') {
        $adId = (int)$txn['reference_id'];
        $vendorId = (int)$txn['vendor_id'];
        $packageId = (int)($extra['package_id'] ?? 0);

        $pdo->prepare("INSERT INTO ad_payments (ad_id,vendor_id,package_id,amount,currency,payment_method,payment_ref,status) VALUES (?,?,?,?,'INR','razorpay',?,'paid')")
            ->execute([$adId, $vendorId, $packageId, $txn['amount'], $paymentId]);
        $adPaymentId = (int)$pdo->lastInsertId();

        $pkgName = $pdo->prepare("SELECT name FROM ad_packages WHERE id=?");
        $pkgName->execute([$packageId]); $pkgName = $pkgName->fetchColumn() ?: 'Banner Ad';
        createInvoice($pdo, 'ad', $vendorId, $adPaymentId, $txn['id'], $txn['amount'],
            'Banner Ad Booking — ' . $pkgName, $paymentId);

        // Auto-approve, but only if THIS SLOT still has room for these dates —
        // same rule that governs manual admin approval, applied automatically here.
        // Scoped per time-slot: a full 6am-12pm slot must never block a booking
        // in the 12pm-6pm slot, since they never share screen time.
        $ad = $pdo->prepare("SELECT ba.*, s.max_concurrent FROM banner_ads ba JOIN ad_slots s ON s.id=ba.slot_id WHERE ba.id=?");
        $ad->execute([$adId]); $ad = $ad->fetch();
        if ($ad && isSlotCapacityAvailable($pdo, $ad['slot_id'], $ad['max_concurrent'], $ad['start_date'], $ad['end_date'], $adId)) {
            $newStatus = ($ad['start_date'] <= date('Y-m-d') && $ad['end_date'] >= date('Y-m-d')) ? 'running' : 'approved';
            $pdo->prepare("UPDATE banner_ads SET status=? WHERE id=?")->execute([$newStatus, $adId]);
        }
        // If that slot is full, the ad stays 'pending' — admin will see it in the queue
        // and can manage timing manually, same as today.
    }

    return ['ok' => true, 'already' => false];
}
