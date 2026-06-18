<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

error_reporting(E_ALL);
ini_set('display_errors', 1);


if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $name       = trim($_POST['name'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $desc       = trim($_POST['description'] ?? '');
    $order      = (int)($_POST['sort_order'] ?? 0);
    $editId     = (int)($_POST['edit_id'] ?? 0);
    if ($name && $categoryId) {
        if ($editId) {
            $pdo->prepare("UPDATE product_types SET name=?,category_id=?,description=?,sort_order=? WHERE id=?")->execute([$name,$categoryId,$desc,$order,$editId]);
            flash('success','Product Type updated.');
        } else {
            $pdo->prepare("INSERT INTO product_types (name,category_id,description,sort_order) VALUES(?,?,?,?)")->execute([$name,$categoryId,$desc,$order]);
            flash('success','Product Type added.');
        }
    }
    header('Location: product-types.php'); exit;
}
if (isset($_GET['action'],$_GET['id'])) {
    $id=(int)$_GET['id'];
    if ($_GET['action']==='delete') { $pdo->prepare("DELETE FROM product_types WHERE id=?")->execute([$id]); flash('success','Product Type deleted.'); }
    elseif ($_GET['action']==='toggle') { $pdo->prepare("UPDATE product_types SET status=1-status WHERE id=?")->execute([$id]); }
    header('Location: product-types.php'); exit;
}

$editing=null;
if (isset($_GET['edit'])) { $s=$pdo->prepare("SELECT * FROM product_types WHERE id=?"); $s->execute([(int)$_GET['edit']]); $editing=$s->fetch(); }

$industries = getAllIndustries($pdo, false);
$types = $pdo->query("SELECT pt.*, c.name AS category_name, i.name AS industry_name, (SELECT COUNT(*) FROM attribute_definitions) AS attr_count FROM product_types pt JOIN categories c ON c.id=pt.category_id JOIN industries i ON i.id=c.industry_id ORDER BY i.name,c.name,pt.sort_order,pt.name")->fetchAll();

// Pre-load categories for editing
$editCategories = [];
if ($editing) {
    $s2=$pdo->prepare("SELECT c.* FROM categories c JOIN industries i ON i.id=c.industry_id WHERE c.industry_id=(SELECT industry_id FROM categories WHERE id=?)"); 
    $s2->execute([$editing['category_id']]); $editCategories=$s2->fetchAll();
}

$pageTitle='Product Types'; $activePage='product-types';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <h1>Product Types</h1>
    </div>
</div>
<div class="content">
    <?= showFlash() ?>
    <div style="display:grid;grid-template-columns:1fr 400px;gap:22px;align-items:start">
        <div class="card">
            <div class="card-header"><h2>🔖 All Product Types (<?= count($types) ?>)</h2></div>
            <?php if ($types): ?>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>#</th><th>Type Name</th><th>Category</th><th>Industry</th><th>Attributes</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($types as $i => $t): ?>
                    <tr>
                        <td><?= $i+1 ?></td>
                        <td><strong><?= sanitize($t['name']) ?></strong></td>
                        <td><?= sanitize($t['category_name']) ?></td>
                        <td class="text-muted"><?= sanitize($t['industry_name']) ?></td>
                        <td><a href="attributes.php?product_type_id=<?= $t['id'] ?>" style="font-weight:600"><?= $t['attr_count'] ?> attrs</a></td>
                        <td><?= statusBadge($t['status']?'active':'inactive') ?></td>
                        <td>
                            <div class="td-actions">
                                <a href="attributes.php?product_type_id=<?= $t['id'] ?>" class="btn btn-primary btn-xs" title="Manage Attributes">📋</a>
                                <a href="?edit=<?= $t['id'] ?>" class="btn btn-outline btn-xs">✏️</a>
                                <a href="?action=toggle&id=<?= $t['id'] ?>" class="btn btn-warning btn-xs">⏯</a>
                                <a href="?action=delete&id=<?= $t['id'] ?>" class="btn btn-danger btn-xs" onclick="return confirm('Delete this product type and all its attribute definitions?')">🗑</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?><div class="empty-state"><div class="es-icon">🔖</div><p>No product types yet.</p></div><?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header"><h2><?= $editing?'✏️ Edit Product Type':'➕ Add Product Type' ?></h2></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <?php if ($editing): ?><input type="hidden" name="edit_id" value="<?= $editing['id'] ?>"><?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">Industry <span class="req">*</span></label>
                        <select id="form_industry" class="form-control" onchange="filterByIndustry(this.value,'form_category')">
                            <option value="">-- Select Industry --</option>
                            <?php foreach ($industries as $ind): ?>
                                <option value="<?= $ind['id'] ?>"><?= sanitize($ind['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category <span class="req">*</span></label>
                        <select name="category_id" id="form_category" class="form-control" required>
                            <option value="">-- Select Category first --</option>
                            <?php if ($editing && $editCategories): ?>
                                <?php foreach ($editCategories as $ec): ?>
                                    <option value="<?= $ec['id'] ?>" <?= $editing['category_id']==$ec['id']?'selected':'' ?>><?= sanitize($ec['name']) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Product Type Name <span class="req">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= sanitize($editing['name'] ?? '') ?>" placeholder="e.g. Single Wall Box" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= sanitize($editing['description'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="<?= $editing['sort_order'] ?? 0 ?>">
                    </div>
                    <div style="display:flex;gap:10px">
                        <button type="submit" class="btn btn-primary"><?= $editing?'💾 Update':'➕ Add' ?></button>
                        <?php if ($editing): ?><a href="product-types.php" class="btn btn-outline">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
