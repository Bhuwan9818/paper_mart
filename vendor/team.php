<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/team.php';
requireRoleStrict('vendor');
requirePermission('team');

$user = currentUser();
$vid  = effectiveVendorId();
$sub  = getVendorSubscription($pdo, $vid);
$limit = getVendorTeamLimit($pdo, $vid);
$count = getVendorTeamCount($pdo, $vid);

// A team member managing the team can never grant a permission they don't
// hold themselves, and can never delegate the 'team' permission itself —
// prevents privilege escalation via sub-accounts.
$myPerms = $_SESSION['team_permissions'] ?? null; // null = vendor owner (all allowed)
$catalog = teamPermissionCatalog();
$grantablePerms = array_keys($catalog);
if (isTeamMemberSession()) {
    $grantablePerms = array_values(array_diff(array_intersect($grantablePerms, $myPerms), ['team']));
}

$errors = [];
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name  = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $desig = trim($_POST['designation'] ?? '');
        $pass  = trim($_POST['password'] ?? '');
        $perms = array_values(array_intersect($_POST['permissions'] ?? [], $grantablePerms));

        if (!$name) $errors[] = 'Name is required.';
        if (!$username || strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
        if (!$pass || strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
        if ($count >= $limit) $errors[] = "You've reached your plan's team member limit ({$limit}). Upgrade your plan to add more.";

        if (!$errors) {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM vendor_team_members WHERE username=?");
            $exists->execute([$username]);
            $existsUser = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=?");
            $existsUser->execute([$username]);
            if ($exists->fetchColumn() > 0 || $existsUser->fetchColumn() > 0) {
                $errors[] = 'That username is already taken. Please choose another.';
            }
        }

        if (!$errors) {
            $pdo->prepare("INSERT INTO vendor_team_members (vendor_id,name,username,email,phone,password,designation,permissions,status) VALUES (?,?,?,?,?,?,?,?,'active')")
                ->execute([$vid, $name, $username, $email ?: null, $phone ?: null, password_hash($pass, PASSWORD_DEFAULT), $desig ?: null, json_encode($perms)]);
            $newId = $pdo->lastInsertId();
            logTeamActivity($pdo, $vid, $newId, 'Member added', "Added by " . actingUserLabel());
            flash('success', "Team member \"$name\" added successfully.");
            header('Location: team.php'); exit;
        }
    }

    if ($action === 'edit') {
        $mid = (int)($_POST['member_id'] ?? 0);
        $m = $pdo->prepare("SELECT * FROM vendor_team_members WHERE id=? AND vendor_id=?");
        $m->execute([$mid, $vid]); $m = $m->fetch();
        if (!$m) { $errors[] = 'Member not found.'; }
        else {
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $desig = trim($_POST['designation'] ?? '');
            $pass  = trim($_POST['password'] ?? '');
            $perms = array_values(array_intersect($_POST['permissions'] ?? [], $grantablePerms));
            // Keep any previously-granted permissions this editor can't see/manage
            $existingPerms = json_decode($m['permissions'] ?? '[]', true) ?: [];
            $untouchable = array_diff($existingPerms, $grantablePerms);
            $perms = array_values(array_unique(array_merge($perms, $untouchable)));

            if (!$name) $errors[] = 'Name is required.';

            if (!$errors) {
                if ($pass) {
                    if (strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
                    else $pdo->prepare("UPDATE vendor_team_members SET password=? WHERE id=?")->execute([password_hash($pass, PASSWORD_DEFAULT), $mid]);
                }
            }
            if (!$errors) {
                $pdo->prepare("UPDATE vendor_team_members SET name=?, email=?, phone=?, designation=?, permissions=? WHERE id=? AND vendor_id=?")
                    ->execute([$name, $email ?: null, $phone ?: null, $desig ?: null, json_encode($perms), $mid, $vid]);
                logTeamActivity($pdo, $vid, $mid, 'Member updated', "Updated by " . actingUserLabel());
                flash('success', "Team member \"$name\" updated.");
                header('Location: team.php'); exit;
            }
        }
    }

    if ($action === 'toggle_status') {
        $mid = (int)($_POST['member_id'] ?? 0);
        $m = $pdo->prepare("SELECT * FROM vendor_team_members WHERE id=? AND vendor_id=?"); $m->execute([$mid,$vid]); $m=$m->fetch();
        if ($m) {
            $new = $m['status'] === 'active' ? 'inactive' : 'active';
            $pdo->prepare("UPDATE vendor_team_members SET status=? WHERE id=?")->execute([$new, $mid]);
            logTeamActivity($pdo, $vid, $mid, $new === 'active' ? 'Member reactivated' : 'Member suspended', 'By ' . actingUserLabel());
            flash('success', 'Status updated.');
        }
        header('Location: team.php'); exit;
    }

    if ($action === 'delete') {
        $mid = (int)($_POST['member_id'] ?? 0);
        $m = $pdo->prepare("SELECT * FROM vendor_team_members WHERE id=? AND vendor_id=?"); $m->execute([$mid,$vid]); $m=$m->fetch();
        if ($m) {
            $pdo->prepare("DELETE FROM vendor_team_members WHERE id=? AND vendor_id=?")->execute([$mid, $vid]);
            flash('success', "Removed \"{$m['name']}\" from your team.");
        }
        header('Location: team.php'); exit;
    }
}

$members = $pdo->prepare("SELECT * FROM vendor_team_members WHERE vendor_id=? ORDER BY created_at DESC");
$members->execute([$vid]);
$members = $members->fetchAll();

$pageTitle = 'Team Members'; $activePage = 'team';
include __DIR__ . '/../includes/head.php';
?>
<div class="topbar">
  <div class="topbar-left"><button class="hamburger" id="hamburger"><span></span><span></span><span></span></button><h1>Team Members</h1></div>
  <div class="topbar-right"><div class="topbar-avatar"><?= avatarLetter($user['name']) ?></div></div>
</div>
<div class="content">
  <?= showFlash() ?>
  <?php foreach ($errors as $e): ?><div class="alert alert-error">⚠️ <?= sanitize($e) ?></div><?php endforeach; ?>

  <?php if ($limit <= 0): ?>
    <div class="card">
      <div class="card-body" style="text-align:center;padding:48px 24px">
        <div style="font-size:40px;margin-bottom:10px">👥</div>
        <h2 style="font-size:20px;margin-bottom:8px">Team Access Not Included in Your Plan</h2>
        <p style="color:var(--text-muted);max-width:480px;margin:0 auto 20px">
          Give your staff their own logins to help manage products, enquiries, and more.
          Upgrade to Starter, Professional, or Enterprise to unlock team member seats.
        </p>
        <a href="<?= BASE_URL ?>/vendor/subscription.php" class="btn btn-primary">Upgrade Plan</a>
      </div>
    </div>
  <?php else: ?>

  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
      <div>
        <div style="font-size:13px;color:var(--text-muted)">Team seats used</div>
        <div style="font-size:24px;font-weight:800;color:var(--primary)"><?= $count ?> <span style="color:var(--text-muted);font-weight:600;font-size:16px">/ <?= $limit ?></span></div>
        <div style="font-size:12.5px;color:var(--text-muted)">Plan: <?= sanitize($sub['plan_name'] ?? 'Free') ?></div>
      </div>
      <?php if ($count < $limit): ?>
        <button class="btn btn-primary" onclick="document.getElementById('add-modal').classList.add('open')">➕ Add Team Member</button>
      <?php else: ?>
        <div style="text-align:right">
          <div class="alert alert-error" style="margin:0">Seat limit reached</div>
          <a href="<?= BASE_URL ?>/vendor/subscription.php" style="font-size:12.5px;color:var(--primary);font-weight:600">Upgrade for more seats →</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-body" style="padding:0">
      <?php if (!$members): ?>
        <div class="empty-state" style="padding:48px">
          <div style="font-size:36px">👥</div>
          <p>No team members yet. Add your first team member to share access to this dashboard.</p>
        </div>
      <?php else: ?>
        <div class="table-wrapper">
          <table class="table">
            <thead><tr>
              <th>Name</th><th>Username</th><th>Designation</th><th>Permissions</th><th>Status</th><th>Last Login</th><th style="text-align:right">Actions</th>
            </tr></thead>
            <tbody>
              <?php foreach ($members as $m):
                $perms = json_decode($m['permissions'] ?? '[]', true) ?: []; ?>
              <tr>
                <td>
                  <strong><?= sanitize($m['name']) ?></strong>
                  <?php if ($m['email']): ?><div style="font-size:12px;color:var(--text-muted)"><?= sanitize($m['email']) ?></div><?php endif; ?>
                </td>
                <td><code><?= sanitize($m['username']) ?></code></td>
                <td><?= sanitize($m['designation'] ?: '—') ?></td>
                <td style="max-width:260px">
                  <?php if (!$perms): ?><span style="color:var(--text-muted);font-size:12px">No sections granted</span><?php endif; ?>
                  <?php foreach ($perms as $p): if (!isset($catalog[$p])) continue; ?>
                    <span class="badge" style="display:inline-block;margin:2px 3px 2px 0;padding:2px 8px;border-radius:12px;background:var(--primary-light);color:var(--primary);font-size:11px;font-weight:600"><?= $catalog[$p]['icon'] ?> <?= $catalog[$p]['label'] ?></span>
                  <?php endforeach; ?>
                </td>
                <td><?= statusBadge($m['status']) ?></td>
                <td style="font-size:12.5px;color:var(--text-muted)"><?= $m['last_login'] ? timeAgo($m['last_login']) : 'Never' ?></td>
                <td style="text-align:right;white-space:nowrap">
                  <button class="btn btn-outline btn-xs" onclick='openEdit(<?= json_encode($m, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✏️ Edit</button>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                    <button type="submit" class="btn btn-outline btn-xs"><?= $m['status']==='active' ? '⏸ Suspend' : '▶ Activate' ?></button>
                  </form>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Remove <?= sanitize($m['name']) ?> from your team? This cannot be undone.')">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-xs">🗑 Remove</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Add Member Modal -->
<div id="add-modal" class="modal-backdrop">
  <div class="modal" style="max-width:520px">
    <div class="modal-header"><h3>Add Team Member</h3><button class="modal-close" onclick="document.getElementById('add-modal').classList.remove('open')">✕</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="add">
      <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" required></div>
      <div class="form-group"><label class="form-label">Username (used to log in) *</label><input type="text" name="username" class="form-control" placeholder="e.g. raj.sales" required></div>
      <div class="form-group"><label class="form-label">Password *</label><input type="password" name="password" class="form-control" minlength="6" required></div>
      <div class="form-group"><label class="form-label">Email (optional)</label><input type="email" name="email" class="form-control"></div>
      <div class="form-group"><label class="form-label">Phone (optional)</label><input type="text" name="phone" class="form-control"></div>
      <div class="form-group"><label class="form-label">Designation (optional)</label><input type="text" name="designation" class="form-control" placeholder="e.g. Sales Executive"></div>
      <div class="form-group">
        <label class="form-label">Section Access</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
          <?php foreach ($catalog as $key => $meta): if (!in_array($key, $grantablePerms, true)) continue; ?>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:500">
              <input type="checkbox" name="permissions[]" value="<?= $key ?>"> <?= $meta['icon'] ?> <?= $meta['label'] ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div style="font-size:11.5px;color:var(--text-muted);margin-top:6px">Billing/Subscription is always restricted to the account owner.</div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Add Team Member</button>
    </form>
  </div>
</div>

<!-- Edit Member Modal -->
<div id="edit-modal" class="modal-backdrop">
  <div class="modal" style="max-width:520px">
    <div class="modal-header"><h3>Edit Team Member</h3><button class="modal-close" onclick="document.getElementById('edit-modal').classList.remove('open')">✕</button></div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="member_id" id="edit-id">
      <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" id="edit-name" class="form-control" required></div>
      <div class="form-group"><label class="form-label">Username</label><input type="text" class="form-control" id="edit-username" disabled style="background:var(--bg-2)"></div>
      <div class="form-group"><label class="form-label">New Password (leave blank to keep current)</label><input type="password" name="password" class="form-control" minlength="6"></div>
      <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="edit-email" class="form-control"></div>
      <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" id="edit-phone" class="form-control"></div>
      <div class="form-group"><label class="form-label">Designation</label><input type="text" name="designation" id="edit-designation" class="form-control"></div>
      <div class="form-group">
        <label class="form-label">Section Access</label>
        <div id="edit-perms" style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
          <?php foreach ($catalog as $key => $meta): if (!in_array($key, $grantablePerms, true)) continue; ?>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:500">
              <input type="checkbox" name="permissions[]" value="<?= $key ?>" class="edit-perm-cb" data-key="<?= $key ?>"> <?= $meta['icon'] ?> <?= $meta['label'] ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Save Changes</button>
    </form>
  </div>
</div>

<script>
function openEdit(m) {
  document.getElementById('edit-id').value = m.id;
  document.getElementById('edit-name').value = m.name;
  document.getElementById('edit-username').value = m.username;
  document.getElementById('edit-email').value = m.email || '';
  document.getElementById('edit-phone').value = m.phone || '';
  document.getElementById('edit-designation').value = m.designation || '';
  let perms = [];
  try { perms = JSON.parse(m.permissions || '[]'); } catch(e) {}
  document.querySelectorAll('.edit-perm-cb').forEach(cb => cb.checked = perms.includes(cb.dataset.key));
  document.getElementById('edit-modal').classList.add('open');
}
document.querySelectorAll('.modal-backdrop').forEach(function(el){
  el.addEventListener('click', function(e){ if (e.target === this) this.classList.remove('open'); });
});
</script>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
