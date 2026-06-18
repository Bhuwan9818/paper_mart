<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('vendor');
$user = currentUser();
$uid  = $user['id'];

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND vendor_id = ?");
$stmt->execute([$id, $uid]);
$product = $stmt->fetch();
if (!$product) { flash('error','Product not found.'); header('Location: ' . BASE_URL . '/vendor/manage-products.php'); exit; }

// Fetch existing attributes
$attrStmt = $pdo->prepare("SELECT * FROM product_attributes WHERE product_id = ? ORDER BY sort_order");
$attrStmt->execute([$id]);
$existingAttrs = $attrStmt->fetchAll();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name        = trim($_POST['name']           ?? '');
    $industryId  = (int)($_POST['industry_id']   ?? 0);
    $categoryId  = (int)($_POST['category_id']   ?? 0);
    $typeId      = (int)($_POST['product_type_id'] ?? 0);
    $description = trim($_POST['description']    ?? '');
    $priceRange  = trim($_POST['price_range']    ?? '');
    $minOrder    = trim($_POST['min_order_qty']  ?? '');

    if (!$name || !$industryId || !$categoryId || !$typeId) {
        $error = 'Please fill in all required fields.';
    } else {
        $imageNames = explode(',', $product['images'] ?? '');
        if (!empty($_FILES['images']['name'][0])) {
            $imageNames = [];
            foreach ($_FILES['images']['tmp_name'] as $k => $tmp) {
                $fileArr = ['tmp_name'=>$tmp,'name'=>$_FILES['images']['name'][$k],'type'=>$_FILES['images']['type'][$k]];
                $fn = uploadImage($fileArr, 'prod');
                if ($fn) $imageNames[] = $fn;
            }
        }
        $upd = $pdo->prepare("UPDATE products SET industry_id=?,category_id=?,product_type_id=?,name=?,description=?,price_range=?,min_order_qty=?,images=?,updated_at=NOW() WHERE id=? AND vendor_id=?");
        $upd->execute([$industryId,$categoryId,$typeId,$name,$description,$priceRange,$minOrder,implode(',',$imageNames),$id,$uid]);

        // Re-save attributes
        $pdo->prepare("DELETE FROM product_attributes WHERE product_id = ?")->execute([$id]);
        $attrNames=$_POST['attr_name']??[]; $attrValues=$_POST['attr_value']??[]; $attrUnits=$_POST['attr_unit']??[];
        $attrSave = $pdo->prepare("INSERT INTO product_attributes (product_id,attribute_name,attribute_value,attribute_unit,sort_order) VALUES(?,?,?,?,?)");
        foreach ($attrNames as $i => $an) {
            if ($an) $attrSave->execute([$id,$an,$attrValues[$i]??'',$attrUnits[$i]??'',$i]);
        }
        flash('success','Product updated successfully.');
        header('Location: ' . BASE_URL . '/vendor/manage-products.php'); exit;
    }
}

$industries = getAllIndustries($pdo);
$pageTitle  = 'Edit Product';
$activePage = 'manage-products';
?>
<?php include __DIR__ . '/../includes/head.php'; ?>
<meta name="base-url" content="<?= BASE_URL ?>">

<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <h1>Edit Product</h1>
    </div>
    <div class="topbar-right">
        <a href="<?= BASE_URL ?>/vendor/manage-products.php" class="btn btn-outline btn-sm">← Back</a>
    </div>
</div>

<div class="content">
    <?php if ($error): ?><div class="alert alert-error">⚠️ <?= sanitize($error) ?></div><?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

        <div class="card">
            <div class="card-header"><h2>📋 Product Classification</h2></div>
            <div class="card-body">
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Industry <span class="req">*</span></label>
                        <select name="industry_id" id="industry_id" class="form-control" required>
                            <option value="">-- Select Industry --</option>
                            <?php foreach ($industries as $ind): ?>
                                <option value="<?= $ind['id'] ?>" <?= $product['industry_id']==$ind['id']?'selected':'' ?>><?= sanitize($ind['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category <span class="req">*</span></label>
                        <select name="category_id" id="category_id" class="form-control" required>
                            <option value="">Loading...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Product Type <span class="req">*</span></label>
                        <select name="product_type_id" id="product_type_id" class="form-control" required>
                            <option value="">Loading...</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>📦 Product Details</h2></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Product Name <span class="req">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?= sanitize($product['name']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="4"><?= sanitize($product['description']) ?></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Price Range</label>
                        <input type="text" name="price_range" class="form-control" value="<?= sanitize($product['price_range']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Minimum Order Quantity</label>
                        <input type="text" name="min_order_qty" class="form-control" value="<?= sanitize($product['min_order_qty']) ?>">
                    </div>
                </div>
                <?php if ($product['images']): ?>
                    <div class="form-group">
                        <label class="form-label">Current Images</label>
                        <div class="img-previews">
                        <?php foreach (explode(',', $product['images']) as $img): ?>
                            <?php if (trim($img)): ?>
                            <div class="img-preview-item">
                                <img src="<?= BASE_URL ?>/assets/uploads/<?= sanitize(trim($img)) ?>" alt="">
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label">Replace Images (optional)</label>
                    <input type="file" name="images[]" id="images" class="form-control" multiple accept="image/*">
                    <div class="img-previews" id="img-previews"></div>
                </div>
            </div>
        </div>

        <div class="card" id="attributes-section" style="display:block">
            <div class="card-header"><h2>📐 Product Attributes / Specifications</h2></div>
            <div class="card-body" style="padding:0">
                <div id="attributes-table-wrap">
                    <?php if ($existingAttrs): ?>
                    <table>
                        <thead><tr><th>#</th><th>Attribute</th><th>Value</th><th>Unit</th></tr></thead>
                        <tbody>
                        <?php foreach ($existingAttrs as $i => $a): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><?= sanitize($a['attribute_name']) ?>
                                <input type="hidden" name="attr_name[<?= $i ?>]" value="<?= sanitize($a['attribute_name']) ?>">
                                <input type="hidden" name="attr_unit[<?= $i ?>]" value="<?= sanitize($a['attribute_unit']) ?>">
                            </td>
                            <td><input type="text" name="attr_value[<?= $i ?>]" value="<?= sanitize($a['attribute_value']) ?>"></td>
                            <td style="color:var(--text-muted)"><?= sanitize($a['attribute_unit']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <p style="padding:16px;color:var(--text-muted)">Change Product Type above to reload attributes.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:12px;justify-content:flex-end;margin-bottom:32px">
            <a href="<?= BASE_URL ?>/vendor/manage-products.php" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">💾 Save Changes</button>
        </div>
    </form>
</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
<script>
// Pre-load cascading dropdowns for edit page
window.addEventListener('DOMContentLoaded', () => {
    const savedCatId  = <?= (int)$product['category_id'] ?>;
    const savedTypeId = <?= (int)$product['product_type_id'] ?>;
    const catSel  = document.getElementById('category_id');
    const typeSel = document.getElementById('product_type_id');

    fetch(BASE_URL + '/ajax/get-categories.php?industry_id=<?= (int)$product['industry_id'] ?>')
        .then(r=>r.json()).then(data=>{
            catSel.innerHTML='<option value="">-- Select Category --</option>';
            data.forEach(c=>{
                const o=document.createElement('option');
                o.value=c.id; o.textContent=c.name;
                if(c.id==savedCatId) o.selected=true;
                catSel.appendChild(o);
            });
            catSel.disabled=false;
            return fetch(BASE_URL + '/ajax/get-product-types.php?category_id='+savedCatId);
        })
        .then(r=>r.json()).then(data=>{
            typeSel.innerHTML='<option value="">-- Select Product Type --</option>';
            data.forEach(t=>{
                const o=document.createElement('option');
                o.value=t.id; o.textContent=t.name;
                if(t.id==savedTypeId) o.selected=true;
                typeSel.appendChild(o);
            });
            typeSel.disabled=false;
        });
});
</script>
</div></div></body></html>
