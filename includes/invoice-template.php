<?php
// ============================================================
// includes/invoice-template.php
// Renders a single invoice as a clean, standalone printable document.
// Expects $invoice (a row from the invoices table) to already be set by
// the including page (admin/invoice.php or vendor/invoice.php), which is
// also responsible for verifying the viewer is allowed to see it.
//
// "Download as PDF" uses the browser's native Print-to-PDF (window.print())
// rather than a server-side PDF library — deliberately, given this project
// has repeatedly hit shared-hosting restrictions (InfinityFree blocking
// CREATE VIEW, restricted MySQL privileges, etc.). Browser print-to-PDF
// needs zero server dependencies and works identically everywhere, and print
// stylesheets give a genuinely clean result for a simple document like this.
// ============================================================
if (!isset($invoice)) { http_response_code(404); exit('Invoice not found.'); }

$statusLabel = 'PAID'; // invoices are only ever created for successful payments
$issuedDate  = date('d M Y', strtotime($invoice['issued_at']));
$typeLabel   = $invoice['type'] === 'subscription' ? 'Subscription' : 'Advertising';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= htmlspecialchars($invoice['invoice_number']) ?> — PaperMart</title>
<style>
  :root{ --brand:#8b241d; --brand-2:#6b1a14; --gold:#f0c060; --n900:#1f2937; --n500:#6b7280; --n200:#e5e7eb; --n50:#f9fafb; }
  *{box-sizing:border-box}
  body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:var(--n900);margin:0;background:#f4f1ec}
  .page{max-width:800px;margin:32px auto;background:#fff;border-radius:12px;box-shadow:0 2px 20px rgba(0,0,0,.08);overflow:hidden}
  .toolbar{max-width:800px;margin:0 auto 12px;padding:0 4px;display:flex;justify-content:flex-end;gap:10px}
  .btn{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border-radius:8px;font-size:13.5px;font-weight:700;text-decoration:none;cursor:pointer;border:none}
  .btn-primary{background:var(--brand);color:#fff}
  .btn-outline{background:#fff;border:1.5px solid var(--n200);color:var(--n900)}
  .header{background:linear-gradient(135deg,var(--brand),var(--brand-2));padding:32px 40px;color:#fff;display:flex;justify-content:space-between;align-items:flex-start}
  .header .brand{font-size:20px;font-weight:800;letter-spacing:.02em}
  .header .brand-sub{font-size:12px;color:var(--gold);text-transform:uppercase;letter-spacing:.08em;margin-top:2px}
  .header .invoice-tag{text-align:right}
  .header .invoice-tag .label{font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.85}
  .header .invoice-tag .num{font-size:18px;font-weight:800;margin-top:2px}
  .status-badge{display:inline-block;margin-top:8px;padding:4px 12px;border-radius:100px;background:rgba(255,255,255,.18);font-size:11.5px;font-weight:700;letter-spacing:.05em}
  .body{padding:32px 40px}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:28px}
  .block-label{font-size:11px;font-weight:700;color:var(--n500);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px}
  .block-content{font-size:13.5px;line-height:1.7;color:var(--n900)}
  .block-content strong{font-size:14.5px}
  table{width:100%;border-collapse:collapse;margin-bottom:24px}
  th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--n500);padding:10px 0;border-bottom:2px solid var(--n200)}
  td{padding:16px 0;border-bottom:1px solid var(--n200);font-size:14px}
  .text-right{text-align:right}
  .total-row td{border-bottom:none;padding-top:16px}
  .total-row .total-label{font-size:13px;color:var(--n500);text-transform:uppercase;letter-spacing:.05em}
  .total-row .total-amount{font-size:22px;font-weight:800;color:var(--brand)}
  .meta-row{display:flex;justify-content:space-between;font-size:12.5px;color:var(--n500);padding:10px 0;border-top:1px solid var(--n200)}
  .footer{background:var(--n50);padding:20px 40px;text-align:center;font-size:11.5px;color:var(--n500)}

  @media print {
    body{background:#fff}
    .toolbar{display:none}
    .page{margin:0;box-shadow:none;border-radius:0;max-width:100%}
  }
</style>
</head>
<body>

<div class="toolbar">
  <button class="btn btn-outline" onclick="window.close()">✕ Close</button>
  <button class="btn btn-primary" onclick="window.print()">⬇️ Download as PDF</button>
</div>

<div class="page">
  <div class="header">
    <div>
      <div class="brand"><?= htmlspecialchars(PLATFORM_LEGAL_NAME) ?></div>
      <div class="brand-sub"><?= htmlspecialchars($typeLabel) ?> Invoice</div>
      <div class="status-badge">✓ <?= $statusLabel ?></div>
    </div>
    <div class="invoice-tag">
      <div class="label">Invoice Number</div>
      <div class="num"><?= htmlspecialchars($invoice['invoice_number']) ?></div>
    </div>
  </div>

  <div class="body">
    <div class="grid-2">
      <div>
        <div class="block-label">Billed From</div>
        <div class="block-content">
          <strong><?= htmlspecialchars(PLATFORM_LEGAL_NAME) ?></strong><br>
          <?= nl2br(htmlspecialchars(PLATFORM_ADDRESS)) ?>
          <?php if (PLATFORM_GSTIN): ?><br>GSTIN: <?= htmlspecialchars(PLATFORM_GSTIN) ?><?php endif; ?>
        </div>
      </div>
      <div>
        <div class="block-label">Billed To</div>
        <div class="block-content">
          <strong><?= htmlspecialchars($invoice['billing_company'] ?: $invoice['billing_name']) ?></strong><br>
          <?php if ($invoice['billing_company']): ?><?= htmlspecialchars($invoice['billing_name']) ?><br><?php endif; ?>
          <?php if ($invoice['billing_address']): ?><?= nl2br(htmlspecialchars($invoice['billing_address'])) ?><br><?php endif; ?>
          <?= htmlspecialchars(trim(implode(', ', array_filter([$invoice['billing_city'], $invoice['billing_state'], $invoice['billing_country']])))) ?>
          <?php if ($invoice['billing_gstin']): ?><br>GSTIN: <?= htmlspecialchars($invoice['billing_gstin']) ?><?php endif; ?>
        </div>
      </div>
    </div>

    <table>
      <thead><tr><th>Description</th><th class="text-right">Amount</th></tr></thead>
      <tbody>
        <tr>
          <td><?= htmlspecialchars($invoice['description']) ?></td>
          <td class="text-right">₹<?= number_format($invoice['amount'], 2) ?></td>
        </tr>
        <tr class="total-row">
          <td class="total-label">Total Paid</td>
          <td class="text-right total-amount">₹<?= number_format($invoice['amount'], 2) ?></td>
        </tr>
      </tbody>
    </table>

    <div class="meta-row"><span>Invoice Date</span><span><?= $issuedDate ?></span></div>
    <?php if ($invoice['payment_ref']): ?>
    <div class="meta-row"><span>Payment Reference</span><span><?= htmlspecialchars($invoice['payment_ref']) ?></span></div>
    <?php endif; ?>
    <div class="meta-row"><span>Payment Method</span><span><?= $invoice['payment_ref'] && str_starts_with($invoice['payment_ref'], 'pay_') ? 'Razorpay' : 'Bank Transfer' ?></span></div>
  </div>

  <div class="footer">
    This is a computer-generated invoice and does not require a signature.<br>
    Questions? Contact us at <?= htmlspecialchars(PLATFORM_SUPPORT_EMAIL) ?>
  </div>
</div>

</body>
</html>
