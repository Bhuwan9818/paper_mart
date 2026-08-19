<?php
// ============================================================
// includes/invoice.php — Invoice generation for subscription & ad payments
//
// This platform doesn't sell products directly (it's enquiry-based), so
// there's no invoice system for the public site — only for the two real
// money-changing-hands flows in the dashboards: subscription plan
// purchases and banner ad bookings. Both go through Razorpay, and both
// funnel through the single fulfillPaymentTransaction() function in
// includes/razorpay.php, which is where invoice creation is hooked in.
// ============================================================

// Creates an invoice row, snapshotting the vendor's current billing details
// so the invoice stays accurate even if their profile changes later.
// Returns the new invoice's id, or null on failure (never throws — a
// failed invoice must never block the payment itself from completing).
function createInvoice($pdo, $type, $vendorId, $referenceId, $paymentTransactionId, $amount, $description, $paymentRef = null) {
    try {
        $vendor = $pdo->prepare("SELECT name, company, address, city, state, country, gst_number FROM users WHERE id=?");
        $vendor->execute([$vendorId]);
        $v = $vendor->fetch();
        if (!$v) return null;

        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_number, type, vendor_id, reference_id, payment_transaction_id, description, amount, payment_ref,
                 billing_name, billing_company, billing_address, billing_city, billing_state, billing_country, billing_gstin, issued_at)
             VALUES ('', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $type, $vendorId, $referenceId, $paymentTransactionId, $description, $amount, $paymentRef,
            $v['name'], $v['company'], $v['address'], $v['city'], $v['state'], $v['country'], $v['gst_number'],
        ]);

        $invoiceId = (int)$pdo->lastInsertId();
        // Invoice number is generated from the row's own id so it's always
        // unique with zero risk of a race condition between two concurrent
        // payments — no separate counter table needed.
        $invoiceNumber = INVOICE_PREFIX . '-' . date('Y') . '-' . str_pad($invoiceId, 6, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE invoices SET invoice_number=? WHERE id=?")->execute([$invoiceNumber, $invoiceId]);

        return $invoiceId;
    } catch (Exception $e) {
        error_log('createInvoice failed: ' . $e->getMessage());
        return null;
    }
}

function getInvoiceById($pdo, $invoiceId) {
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id=?");
    $stmt->execute([$invoiceId]);
    return $stmt->fetch();
}
