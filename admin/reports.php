<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

// ─── CSV Download Handler ──────────────────────────────────────
$type = $_GET['type'] ?? '';
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

if ($type && isset($_GET['download'])) {
    $filename = "report_{$type}_".date('Ymd').".csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header('Pragma: no-cache');
    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    switch($type) {
        case 'vendors':
            fputcsv($out,['ID','Name','Email','Company','Phone','City','State','Status','Joined','Products','Enquiries']);
            $rows = $pdo->query("SELECT u.id,u.name,u.email,u.company,u.phone,u.city,u.state,u.status,u.created_at,(SELECT COUNT(*) FROM products WHERE vendor_id=u.id) AS pc,(SELECT COUNT(*) FROM enquiries WHERE vendor_id=u.id) AS ec FROM users u WHERE u.role='vendor' ORDER BY u.created_at DESC")->fetchAll();
            foreach($rows as $r) fputcsv($out,[$r['id'],$r['name'],$r['email'],$r['company'],$r['phone'],$r['city'],$r['state'],$r['status'],date('d M Y',strtotime($r['created_at'])),$r['pc'],$r['ec']]);
            break;

        case 'products':
            fputcsv($out,['ID','Name','Vendor','Category','Type','Price Range','Status','Created']);
            $rows = $pdo->query("SELECT p.id,p.name,u.name AS vendor,c.name AS cat,pt.name AS ptype,p.price_range,p.status,p.created_at FROM products p JOIN users u ON u.id=p.vendor_id JOIN categories c ON c.id=p.category_id JOIN product_types pt ON pt.id=p.product_type_id ORDER BY p.created_at DESC")->fetchAll();
            foreach($rows as $r) fputcsv($out,[$r['id'],$r['name'],$r['vendor'],$r['cat'],$r['ptype'],$r['price_range'],$r['status'],date('d M Y',strtotime($r['created_at']))]);
            break;

        case 'enquiries':
            fputcsv($out,['ID','Customer','Vendor','Product','Subject','Status','Created']);
            $rows = $pdo->query("SELECT e.id,cu.name AS customer,v.name AS vendor,p.name AS product,e.subject,e.status,e.created_at FROM enquiries e JOIN users cu ON cu.id=e.customer_id JOIN users v ON v.id=e.vendor_id LEFT JOIN products p ON p.id=e.product_id ORDER BY e.created_at DESC")->fetchAll();
            foreach($rows as $r) fputcsv($out,[$r['id'],$r['customer'],$r['vendor'],$r['product']??'—',$r['subject'],$r['status'],date('d M Y',strtotime($r['created_at']))]);
            break;

        case 'payments':
            fputcsv($out,['ID','Vendor','Email','Plan','Amount','Currency','Billing','Method','Status','Paid At','Created']);
            try {
                $rows = $pdo->query("SELECT sp.id,u.name,u.email,pl.name AS plan,sp.amount,sp.currency,sp.billing_cycle,sp.payment_method,sp.status,sp.paid_at,sp.created_at FROM subscription_payments sp JOIN users u ON u.id=sp.vendor_id JOIN subscription_plans pl ON pl.id=sp.plan_id ORDER BY sp.created_at DESC")->fetchAll();
                foreach($rows as $r) fputcsv($out,[$r['id'],$r['name'],$r['email'],$r['plan'],$r['amount'],$r['currency'],$r['billing_cycle'],$r['payment_method'],$r['status'],$r['paid_at']?date('d M Y H:i',strtotime($r['paid_at'])):'—',date('d M Y',strtotime($r['created_at']))]);
            } catch(Exception $e) {}
            break;

        case 'subscriptions':
            fputcsv($out,['Vendor ID','Vendor','Email','Plan','Status','Billing','Started','Expires','Total Paid (₹)']);
            try {
                $rows = $pdo->query("SELECT u.id,u.name,u.email,pl.name AS plan,vs.status,vs.billing_cycle,vs.started_at,vs.expires_at,(SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE vendor_id=u.id AND status='paid') AS paid FROM vendor_subscriptions vs JOIN users u ON u.id=vs.vendor_id JOIN subscription_plans pl ON pl.id=vs.plan_id ORDER BY vs.created_at DESC")->fetchAll();
                foreach($rows as $r) fputcsv($out,[$r['id'],$r['name'],$r['email'],$r['plan'],$r['status'],$r['billing_cycle'],$r['started_at']?date('d M Y',strtotime($r['started_at'])):'—',$r['expires_at']?date('d M Y',strtotime($r['expires_at'])):'—',number_format($r['paid'],2)]);
            } catch(Exception $e){}
            break;

        case 'revenue_summary':
            fputcsv($out,['Month','Paid Transactions','Total Revenue (₹)']);
            try {
                $rows = $pdo->query("SELECT DATE_FORMAT(paid_at,'%Y-%m') AS mo, COUNT(*) AS cnt, SUM(amount) AS total FROM subscription_payments WHERE status='paid' GROUP BY mo ORDER BY mo DESC LIMIT 24")->fetchAll();
                foreach($rows as $r) fputcsv($out,[date('M Y',strtotime($r['mo'].'-01')),$r['cnt'],number_format($r['total'],2)]);
            } catch(Exception $e){}
            break;
    }
    fclose($out); exit;
}

// ─── Quick stats for report page ──────────────────────────────
$stats = [
    'vendors'    => $pdo->query("SELECT COUNT(*) FROM users WHERE role='vendor'")->fetchColumn(),
    'customers'  => $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn(),
    'products'   => $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
    'enquiries'  => $pdo->query("SELECT COUNT(*) FROM enquiries")->fetchColumn(),
];
try {
    $stats['revenue'] = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status='paid'")->fetchColumn();
    $stats['subs']    = $pdo->query("SELECT COUNT(*) FROM vendor_subscriptions WHERE status='active'")->fetchColumn();
} catch(Exception $e) { $stats['revenue']=$stats['subs']=0; }

$pageTitle='Reports'; $activePage='reports';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<style>
.report-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:20px; }
.report-card {
  background:#fff; border-radius:var(--radius); border:1px solid var(--border);
  box-shadow:var(--shadow-sm); overflow:hidden; transition:var(--transition);
}
.report-card:hover { box-shadow:var(--shadow); transform:translateY(-2px); }
.report-card-header { padding:18px 20px 14px; border-bottom:1px solid var(--border); }
.report-card-icon { font-size:28px; margin-bottom:8px; }
.report-card-title { font-size:15px; font-weight:700; margin-bottom:4px; }
.report-card-desc  { font-size:12.5px; color:var(--text-muted); line-height:1.5; }
.report-card-body  { padding:16px 20px; background:#fafbff; }
.report-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
.report-row:last-child { margin:0; }
.report-label { font-size:12px; color:var(--text-muted); }
.report-val   { font-size:13px; font-weight:600; }
.report-card-footer { padding:14px 20px; display:flex; gap:8px; border-top:1px solid var(--border); }

.snap-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:14px; margin-bottom:28px; }
.snap-tile { background:#fff; border:1px solid var(--border); border-radius:var(--radius-sm); padding:16px; text-align:center; }
.snap-val  { font-size:24px; font-weight:800; }
.snap-lbl  { font-size:11px; color:var(--text-muted); margin-top:4px; text-transform:uppercase; letter-spacing:.4px; }
</style>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Reports</h1>
  </div>
</div>

<div class="content">
  <div class="page-header"><h1>📄 Reports &amp; Exports</h1></div>

  <!-- Snapshot -->
  <div class="snap-grid">
    <div class="snap-tile"><div class="snap-val" style="color:var(--info)"><?=$stats['vendors']?></div><div class="snap-lbl">Vendors</div></div>
    <div class="snap-tile"><div class="snap-val" style="color:var(--success)"><?=$stats['customers']?></div><div class="snap-lbl">Customers</div></div>
    <div class="snap-tile"><div class="snap-val" style="color:var(--purple)"><?=$stats['products']?></div><div class="snap-lbl">Products</div></div>
    <div class="snap-tile"><div class="snap-val" style="color:var(--warning)"><?=$stats['enquiries']?></div><div class="snap-lbl">Enquiries</div></div>
    <div class="snap-tile"><div class="snap-val" style="color:var(--success)">₹<?=number_format($stats['revenue'])?></div><div class="snap-lbl">Total Revenue</div></div>
    <div class="snap-tile"><div class="snap-val" style="color:var(--primary)"><?=$stats['subs']?></div><div class="snap-lbl">Active Subs</div></div>
  </div>

  <h2 style="font-size:15px;font-weight:700;margin-bottom:16px">📥 Download Reports</h2>

  <div class="report-grid">

    <!-- Vendors Report -->
    <div class="report-card">
      <div class="report-card-header">
        <div class="report-card-icon">🏪</div>
        <div class="report-card-title">Vendor Report</div>
        <div class="report-card-desc">All registered vendors with contact info, product count, and enquiry count.</div>
      </div>
      <div class="report-card-body">
        <div class="report-row"><span class="report-label">Total vendors</span><span class="report-val"><?=$stats['vendors']?></span></div>
        <div class="report-row"><span class="report-label">Format</span><span class="report-val">CSV</span></div>
        <div class="report-row"><span class="report-label">Fields</span><span class="report-val">Name, Email, Company, City, Status…</span></div>
      </div>
      <div class="report-card-footer">
        <a href="?type=vendors&download=1" class="btn btn-primary btn-sm" style="flex:1;text-align:center">📥 Download CSV</a>
        <a href="vendors.php" class="btn btn-outline btn-sm">View</a>
      </div>
    </div>

    <!-- Products Report -->
    <div class="report-card">
      <div class="report-card-header">
        <div class="report-card-icon">📦</div>
        <div class="report-card-title">Products Report</div>
        <div class="report-card-desc">All products with vendor, category, type, price range, and approval status.</div>
      </div>
      <div class="report-card-body">
        <div class="report-row"><span class="report-label">Total products</span><span class="report-val"><?=$stats['products']?></span></div>
        <div class="report-row"><span class="report-label">Format</span><span class="report-val">CSV</span></div>
        <div class="report-row"><span class="report-label">Fields</span><span class="report-val">Name, Vendor, Category, Status…</span></div>
      </div>
      <div class="report-card-footer">
        <a href="?type=products&download=1" class="btn btn-primary btn-sm" style="flex:1;text-align:center">📥 Download CSV</a>
        <a href="products.php" class="btn btn-outline btn-sm">View</a>
      </div>
    </div>

    <!-- Enquiries Report -->
    <div class="report-card">
      <div class="report-card-header">
        <div class="report-card-icon">📩</div>
        <div class="report-card-title">Enquiries Report</div>
        <div class="report-card-desc">All buyer–vendor enquiries with subject, status, and timestamps.</div>
      </div>
      <div class="report-card-body">
        <div class="report-row"><span class="report-label">Total enquiries</span><span class="report-val"><?=$stats['enquiries']?></span></div>
        <div class="report-row"><span class="report-label">Format</span><span class="report-val">CSV</span></div>
        <div class="report-row"><span class="report-label">Fields</span><span class="report-val">Customer, Vendor, Product, Status…</span></div>
      </div>
      <div class="report-card-footer">
        <a href="?type=enquiries&download=1" class="btn btn-primary btn-sm" style="flex:1;text-align:center">📥 Download CSV</a>
        <a href="enquiries.php" class="btn btn-outline btn-sm">View</a>
      </div>
    </div>

    <!-- Payments Report -->
    <div class="report-card">
      <div class="report-card-header">
        <div class="report-card-icon">💰</div>
        <div class="report-card-title">Payments Report</div>
        <div class="report-card-desc">All subscription payment transactions with amounts, methods, and statuses.</div>
      </div>
      <div class="report-card-body">
        <div class="report-row"><span class="report-label">Total revenue</span><span class="report-val">₹<?=number_format($stats['revenue'])?></span></div>
        <div class="report-row"><span class="report-label">Format</span><span class="report-val">CSV</span></div>
        <div class="report-row"><span class="report-label">Fields</span><span class="report-val">Vendor, Plan, Amount, Status, Date…</span></div>
      </div>
      <div class="report-card-footer">
        <a href="?type=payments&download=1" class="btn btn-primary btn-sm" style="flex:1;text-align:center">📥 Download CSV</a>
        <a href="payments.php" class="btn btn-outline btn-sm">View</a>
      </div>
    </div>

    <!-- Subscriptions Report -->
    <div class="report-card">
      <div class="report-card-header">
        <div class="report-card-icon">💳</div>
        <div class="report-card-title">Subscriptions Report</div>
        <div class="report-card-desc">All vendor subscription records including plan, billing cycle, and expiry dates.</div>
      </div>
      <div class="report-card-body">
        <div class="report-row"><span class="report-label">Active subs</span><span class="report-val"><?=$stats['subs']?></span></div>
        <div class="report-row"><span class="report-label">Format</span><span class="report-val">CSV</span></div>
        <div class="report-row"><span class="report-label">Fields</span><span class="report-val">Vendor, Plan, Status, Expiry, Paid…</span></div>
      </div>
      <div class="report-card-footer">
        <a href="?type=subscriptions&download=1" class="btn btn-primary btn-sm" style="flex:1;text-align:center">📥 Download CSV</a>
        <a href="subscriptions.php" class="btn btn-outline btn-sm">View</a>
      </div>
    </div>

    <!-- Revenue Summary Report -->
    <div class="report-card">
      <div class="report-card-header">
        <div class="report-card-icon">📊</div>
        <div class="report-card-title">Revenue Summary</div>
        <div class="report-card-desc">Month-by-month revenue summary for the last 24 months.</div>
      </div>
      <div class="report-card-body">
        <div class="report-row"><span class="report-label">Period</span><span class="report-val">Last 24 months</span></div>
        <div class="report-row"><span class="report-label">Format</span><span class="report-val">CSV</span></div>
        <div class="report-row"><span class="report-label">Fields</span><span class="report-val">Month, Transactions, Revenue</span></div>
      </div>
      <div class="report-card-footer">
        <a href="?type=revenue_summary&download=1" class="btn btn-primary btn-sm" style="flex:1;text-align:center">📥 Download CSV</a>
        <a href="analytics.php" class="btn btn-outline btn-sm">View Chart</a>
      </div>
    </div>

  </div>

  <!-- Recent downloads notice -->
  <div style="margin-top:28px;padding:14px 18px;background:var(--primary-light);border-radius:var(--radius-sm);border:1px solid #c7d2fe;font-size:13px;color:var(--primary)">
    ℹ️ All reports download as <strong>UTF-8 CSV</strong> files that open in Excel, Google Sheets, or any spreadsheet app. For scheduled reports or automated exports, use the API.
  </div>
</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
