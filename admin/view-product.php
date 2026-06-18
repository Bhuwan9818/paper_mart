<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT p.*, u.name AS vendor_name, u.email AS vendor_email, u.phone AS vendor_phone, u.company AS vendor_company,
           i.name AS industry_name, c.name AS category_name, pt.name AS type_name
    FROM products p
    JOIN users u          ON u.id  = p.vendor_id
    JOIN industries i     ON i.id  = p.industry_id
    JOIN categories c     ON c.id  = p.category_id
    JOIN product_types pt ON pt.id = p.product_type_id
    WHERE p.id = ?
");
$stmt->execute([$id]); $product = $stmt->fetch();
if (!$product) { flash('error','Product not found.'); header('Location: products.php'); exit; }

$attrs = $pdo->prepare("SELECT * FROM product_attributes WHERE product_id = ? ORDER BY sort_order");
$attrs->execute([$id]); $attributes = $attrs->fetchAll();

$pageTitle = 'Product Detail'; $activePage = 'products';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <h1>Product Detail</h1>
    </div>
    <div class="topbar-right">
        <a href="products.php" class="btn btn-outline btn-sm">← Back</a>
        <?php if ($product['status']==='pending'): ?>
            <a href="products.php?action=approve&id=<?= $id ?>" class="btn btn-success btn-sm" onclick="return confirm('Approve?')">✅ Approve</a>
            <a href="products.php?action=reject&id=<?= $id ?>"  class="btn btn-danger  btn-sm" onclick="return confirm('Reject?')">❌ Reject</a>
        <?php endif; ?>
    </div>
</div>
<div class="content">
    <?= showFlash() ?>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:22px;align-items:start">
        <div>
            <div class="card">
                <div class="card-header">
                    <h2><?= sanitize($product['name']) ?></h2>
                    <?= statusBadge($product['status']) ?>
                </div>
                <div class="card-body">
                    <?php if ($product['images']): ?>
                    <div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap">
                        <?php foreach (explode(',', $product['images']) as $img): ?>
                            <?php if (trim($img)): ?>
                            <img src="<?= BASE_URL ?>/assets/uploads/<?= sanitize(trim($img)) ?>"
                                 style="width:100px;height:100px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:18px">
                        <div style="background:#f8fafc;padding:12px;border-radius:8px">
                            <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px">INDUSTRY</div>
                            <strong><?= sanitize($product['industry_name']) ?></strong>
                        </div>
                        <div style="background:#f8fafc;padding:12px;border-radius:8px">
                            <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px">CATEGORY</div>
                            <strong><?= sanitize($product['category_name']) ?></strong>
                        </div>
                        <div style="background:#f8fafc;padding:12px;border-radius:8px">
                            <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px">PRODUCT TYPE</div>
                            <strong><?= sanitize($product['type_name']) ?></strong>
                        </div>
                    </div>

                    <?php if ($product['description']): ?>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <p style="font-size:13.5px;line-height:1.7"><?= nl2br(sanitize($product['description'])) ?></p>
                    </div>
                    <?php endif; ?>

                    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px">
                        <?php if ($product['price_range']): ?>
                        <div><span style="font-size:12px;color:var(--text-muted)">Price Range:</span> <strong><?= sanitize($product['price_range']) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($product['min_order_qty']): ?>
                        <div><span style="font-size:12px;color:var(--text-muted)">Min Order:</span> <strong><?= sanitize($product['min_order_qty']) ?></strong></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($attributes): ?>
            <div class="card">
                <div class="card-header"><h2>📐 Technical Specifications</h2></div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>#</th><th>Attribute</th><th>Value</th><th>Unit</th></tr></thead>
                        <tbody>
                        <?php foreach ($attributes as $i => $a): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><strong><?= sanitize($a['attribute_name']) ?></strong></td>
                            <td><?= sanitize($a['attribute_value'] ?: '—') ?></td>
                            <td class="text-muted"><?= sanitize($a['attribute_unit']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="card">
                <div class="card-header"><h2>🏪 Vendor Info</h2></div>
                <div class="card-body" style="font-size:13px">
                    <p style="font-weight:600;font-size:14px;margin-bottom:8px"><?= sanitize($product['vendor_name']) ?></p>
                    <?php if ($product['vendor_company']): ?><p class="text-muted">🏢 <?= sanitize($product['vendor_company']) ?></p><?php endif; ?>
                    <p class="text-muted">📧 <?= sanitize($product['vendor_email']) ?></p>
                    <?php if ($product['vendor_phone']): ?><p class="text-muted">📱 <?= sanitize($product['vendor_phone']) ?></p><?php endif; ?>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h2>ℹ️ Meta</h2></div>
                <div class="card-body" style="font-size:12px;color:var(--text-muted)">
                    <p>Added: <?= formatDate($product['created_at']) ?></p>
                    <p>Updated: <?= formatDate($product['updated_at']) ?></p>
                    <p>Views: <?= $product['views'] ?></p>
                    <p>Status: <?= statusBadge($product['status']) ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
