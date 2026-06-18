<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

/* POST: add / edit */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $attrName = trim($_POST['attribute_name'] ?? '');
    $unit     = trim($_POST['attribute_unit']  ?? '');
    $type     = $_POST['attribute_type']       ?? 'text';
    $options  = trim($_POST['options_list']    ?? '');
    $required = isset($_POST['is_required'])   ? 1 : 0;
    $order    = (int)($_POST['sort_order']     ?? 0);
    $editId   = (int)($_POST['edit_id']        ?? 0);

    if ($attrName) {
        if ($editId) {
            $pdo->prepare(
                "UPDATE attribute_definitions
                    SET attribute_name=?, attribute_unit=?, attribute_type=?,
                        options_list=?, is_required=?, sort_order=?
                  WHERE id=?"
            )->execute([$attrName, $unit, $type, $options, $required, $order, $editId]);
            flash('success', 'Attribute updated.');
        } else {
            $pdo->prepare(
                "INSERT INTO attribute_definitions
                    (attribute_name, attribute_unit, attribute_type, options_list, is_required, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$attrName, $unit, $type, $options, $required, $order]);
            flash('success', 'Attribute added.');
        }
    } else {
        flash('danger', 'Attribute name is required.');
    }
    header('Location: attributes.php'); exit;
}

/* GET: delete */
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
    $pdo->prepare("DELETE FROM attribute_definitions WHERE id=?")->execute([(int)$_GET['id']]);
    flash('success', 'Attribute deleted.');
    header('Location: attributes.php'); exit;
}

/* Load editing row */
$editing = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM attribute_definitions WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $editing = $s->fetch();
}

/* Search / filter */
$search     = trim($_GET['search']      ?? '');
$typeFilter = $_GET['type_filter']      ?? '';

$where  = [];
$params = [];
if ($search !== '') { $where[] = 'attribute_name LIKE ?'; $params[] = '%'.$search.'%'; }
if ($typeFilter !== '') { $where[] = 'attribute_type = ?'; $params[] = $typeFilter; }
$whereSQL = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT * FROM attribute_definitions $whereSQL ORDER BY sort_order, id");
$stmt->execute($params);
$attributes = $stmt->fetchAll();

$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM attribute_definitions")->fetchColumn();

$pageTitle  = 'Attributes';
$activePage = 'attributes';
include __DIR__ . '/../includes/head.php';
?>
<meta name="base-url" content="<?= BASE_URL ?>">

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <h1>Attribute Definitions</h1>
  </div>
  <div class="topbar-right" style="font-size:13px;color:var(--text-muted)">
    <?= $totalCount ?> attribute<?= $totalCount !== 1 ? 's' : '' ?> total
  </div>
</div>

<div class="content">
  <?= showFlash() ?>

  <div class="alert alert-info" style="margin-bottom:20px">
    💡 Attributes are global specification fields (e.g. GSM, BF, Moisture). Vendors can pick any combination of attributes when adding a product — they are no longer tied to a specific product type.
  </div>

  <div style="display:grid;grid-template-columns:1fr 400px;gap:22px;align-items:start">

    <!-- List -->
    <div class="card" style="margin-top:0">
      <div class="card-header" style="gap:12px;flex-wrap:wrap">
        <h2 style="margin:0">📋 All Attributes</h2>
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex:1;min-width:220px">
          <input type="text" name="search" value="<?= sanitize($search) ?>"
                 class="form-control" placeholder="Search attributes…" style="flex:1">
          <select name="type_filter" class="form-control" style="width:130px">
            <option value="">All types</option>
            <option value="number" <?= $typeFilter==='number'?'selected':'' ?>>Number</option>
            <option value="text"   <?= $typeFilter==='text'  ?'selected':'' ?>>Text</option>
            <option value="select" <?= $typeFilter==='select'?'selected':'' ?>>Dropdown</option>
          </select>
          <button type="submit" class="btn btn-outline">🔍</button>
          <?php if ($search || $typeFilter): ?>
            <a href="attributes.php" class="btn btn-outline">✕ Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <?php if ($attributes): ?>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Attribute Name</th>
              <th>Unit</th>
              <th>Type</th>
              <th>Options</th>
              <th>Required</th>
              <th>Order</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($attributes as $i => $a): ?>
          <tr <?= ($editing && $editing['id']==$a['id']) ? 'style="background:var(--primary-light,#eef2ff)"' : '' ?>>
            <td style="color:var(--text-muted);font-size:12px"><?= $i+1 ?></td>
            <td>
              <strong><?= sanitize($a['attribute_name']) ?></strong>
              <?php if ($a['is_required']): ?><span style="color:#ef4444;font-size:11px"> *</span><?php endif; ?>
            </td>
            <td style="color:var(--text-muted);font-size:13px"><?= sanitize($a['attribute_unit'] ?: '—') ?></td>
            <td>
              <span class="badge badge-<?= $a['attribute_type']==='number'?'info':($a['attribute_type']==='select'?'warning':'secondary') ?>">
                <?= $a['attribute_type'] ?>
              </span>
            </td>
            <td style="font-size:11.5px;color:var(--text-muted);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= sanitize(mb_strimwidth($a['options_list']??'',0,38,'…')) ?: '—' ?>
            </td>
            <td>
              <?= $a['is_required']
                ? '<span class="badge badge-danger">Yes</span>'
                : '<span class="badge badge-secondary">No</span>' ?>
            </td>
            <td style="color:var(--text-muted);font-size:13px"><?= (int)$a['sort_order'] ?></td>
            <td>
              <div class="td-actions">
                <a href="?edit=<?= $a['id'] ?>" class="btn btn-outline btn-xs" title="Edit">✏️</a>
                <a href="?action=delete&id=<?= $a['id'] ?>"
                   class="btn btn-danger btn-xs" title="Delete"
                   onclick="return confirm('Delete attribute &quot;<?= addslashes(sanitize($a['attribute_name'])) ?>&quot;?')">🗑</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <div class="empty-state">
          <div class="es-icon">📋</div>
          <p><?= ($search||$typeFilter) ? 'No attributes match your search.' : 'No attributes defined yet. Add the first one →' ?></p>
        </div>
      <?php endif; ?>
    </div>

    <!-- Add / Edit form -->
    <div class="card" style="margin-top:0;position:sticky;top:80px">
      <div class="card-header" style="align-items:center">
        <h2 style="margin:0"><?= $editing ? '✏️ Edit Attribute' : '➕ Add Attribute' ?></h2>
        <?php if ($editing): ?>
          <a href="attributes.php" class="btn btn-outline btn-xs" style="margin-left:auto">✕ Cancel</a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <form method="POST" id="attr-form">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <?php if ($editing): ?>
            <input type="hidden" name="edit_id" value="<?= $editing['id'] ?>">
          <?php endif; ?>

          <div class="form-group">
            <label class="form-label">Attribute Name <span class="req">*</span></label>
            <input type="text" name="attribute_name" class="form-control"
                   value="<?= sanitize($editing['attribute_name'] ?? '') ?>"
                   placeholder="e.g. GSM, BF, Moisture, Cobb Top"
                   required autofocus>
            <small style="color:var(--text-muted);font-size:11.5px">Shown to vendors when adding a product.</small>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Unit</label>
              <input type="text" name="attribute_unit" class="form-control"
                     value="<?= sanitize($editing['attribute_unit'] ?? '') ?>"
                     placeholder="g/m², %, mm">
            </div>
            <div class="form-group">
              <label class="form-label">Field Type</label>
              <select name="attribute_type" class="form-control" id="attr-type" onchange="toggleOptions(this.value)">
                <option value="number" <?= ($editing['attribute_type']??'number')==='number'?'selected':'' ?>>Number</option>
                <option value="text"   <?= ($editing['attribute_type']??'')==='text'  ?'selected':'' ?>>Text</option>
                <option value="select" <?= ($editing['attribute_type']??'')==='select'?'selected':'' ?>>Dropdown</option>
              </select>
            </div>
          </div>

          <div class="form-group" id="options-wrap"
               style="<?= ($editing['attribute_type']??'')!=='select'?'display:none':'' ?>">
            <label class="form-label">Dropdown Options <span class="req">*</span></label>
            <input type="text" name="options_list" class="form-control"
                   value="<?= sanitize($editing['options_list'] ?? '') ?>"
                   placeholder="Option A, Option B, Option C">
            <small style="color:var(--text-muted);font-size:11.5px">Comma-separated list of choices.</small>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Sort Order</label>
              <input type="number" name="sort_order" class="form-control"
                     value="<?= (int)($editing['sort_order']??0) ?>" min="0">
              <small style="color:var(--text-muted);font-size:11.5px">Lower = shown first.</small>
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:22px">
              <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:13.5px;font-weight:500">
                <input type="checkbox" name="is_required" value="1"
                       <?= ($editing['is_required']??0)?'checked':'' ?>
                       style="width:16px;height:16px">
                Required field
              </label>
            </div>
          </div>

          <div style="display:flex;gap:10px;margin-top:4px">
            <button type="submit" class="btn btn-primary" style="flex:1">
              <?= $editing ? '💾 Update Attribute' : '➕ Add Attribute' ?>
            </button>
            <?php if ($editing): ?>
              <a href="attributes.php" class="btn btn-outline">Cancel</a>
            <?php endif; ?>
          </div>
        </form>

        <?php if (!$editing): ?>
          <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border-color)">
            <p style="font-size:12px;color:var(--text-muted);margin:0 0 8px"><strong>Quick add:</strong></p>
            <div style="display:flex;flex-wrap:wrap;gap:6px">
              <?php
              $suggestions = ['GSM','BF','Moisture','Cobb Top','Cobb Bottom','Caliper','Brightness',
                              'Length','Width','Height','Thickness','Tensile Strength','ECT'];
              $existing = array_column($attributes, 'attribute_name');
              foreach ($suggestions as $s):
                if (!in_array($s, $existing)):
              ?>
              <button type="button" class="btn btn-outline btn-xs"
                      onclick="document.querySelector('[name=attribute_name]').value='<?= addslashes($s) ?>';document.querySelector('[name=attribute_name]').focus()">
                + <?= $s ?>
              </button>
              <?php endif; endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
<script>
function toggleOptions(type) {
  document.getElementById('options-wrap').style.display = (type === 'select') ? '' : 'none';
}
(function(){ const t=document.getElementById('attr-type'); if(t) toggleOptions(t.value); })();
</script>
</div></div></body></html>




