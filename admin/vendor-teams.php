<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/team.php';
requireRoleStrict('admin');

// Handle actions
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    $m = $pdo->prepare("SELECT * FROM vendor_team_members WHERE id=?"); $m->execute([$id]); $m = $m->fetch();
    if ($m) {
        match($_GET['action']) {
            'activate'   => $pdo->prepare("UPDATE vendor_team_members SET status='active' WHERE id=?")->execute([$id]),
            'deactivate' => $pdo->prepare("UPDATE vendor_team_members SET status='inactive' WHERE id=?")->execute([$id]),
            'delete'     => $pdo->prepare("DELETE FROM vendor_team_members WHERE id=?")->execute([$id]),
            default      => null
        };
        if (in_array($_GET['action'], ['activate','deactivate'])) {
            logTeamActivity($pdo, $m['vendor_id'], $id, $_GET['action']==='activate' ? 'Member reactivated' : 'Member suspended', 'By Admin');
        }
    }
    flash('success','Team member updated.');
    $returnTo = $_GET['return'] ?? '';
    header('Location: ' . ($returnTo === 'profile' && $m ? "vendor-profile.php?id={$m['vendor_id']}" : 'vendor-teams.php'));
    exit;
}

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1,(int)($_GET['page']??1)); $perPage=15; $offset=($page-1)*$perPage;

$where = "WHERE 1=1"; $params = [];
if ($search) { $where .= " AND (tm.name LIKE ? OR tm.username LIKE ? OR tm.email LIKE ? OR u.name LIKE ? OR u.company LIKE ?)";
    for ($i=0;$i<5;$i++) $params[] = "%$search%"; }
if ($status) { $where .= " AND tm.status=?"; $params[] = $status; }

$total = $pdo->prepare("SELECT COUNT(*) FROM vendor_team_members tm JOIN users u ON u.id=tm.vendor_id $where");
$total->execute($params); $total = $total->fetchColumn();

$params2 = $params; $params2[] = $perPage; $params2[] = $offset;
$stmt = $pdo->prepare("SELECT tm.*, u.name AS vendor_name, u.email AS vendor_email, u.company AS vendor_company
    FROM vendor_team_members tm JOIN users u ON u.id=tm.vendor_id $where
    ORDER BY tm.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute($params2); $members = $stmt->fetchAll();

// Summary stats
$statTotal   = (int)$pdo->query("SELECT COUNT(*) FROM vendor_team_members")->fetchColumn();
$statActive  = (int)$pdo->query("SELECT COUNT(*) FROM vendor_team_members WHERE status='active'")->fetchColumn();
$statVendors = (int)$pdo->query("SELECT COUNT(DISTINCT vendor_id) FROM vendor_team_members")->fetchColumn();

$catalog = teamPermissionCatalog();

$pageTitle='Vendor Teams'; $activePage='vendor-teams';
include __DIR__ . '/../includes/head.php';
?>
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <h1>Vendor Teams</h1>
    </div>
</div>
<div class="content">
    <?= showFlash() ?>
    <div class="page-header">
        <h1>👥 Vendor Team Members <span style="font-size:14px;font-weight:400;color:var(--text-muted)">(<?= $total ?>)</span></h1>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px">
        <div class="card"><div class="card-body">
            <div style="font-size:12.5px;color:var(--text-muted)">Total Team Members</div>
            <div style="font-size:24px;font-weight:800;color:var(--primary)"><?= $statTotal ?></div>
        </div></div>
        <div class="card"><div class="card-body">
            <div style="font-size:12.5px;color:var(--text-muted)">Active</div>
            <div style="font-size:24px;font-weight:800;color:#16a34a"><?= $statActive ?></div>
        </div></div>
        <div class="card"><div class="card-body">
            <div style="font-size:12.5px;color:var(--text-muted)">Vendors Using Team Feature</div>
            <div style="font-size:24px;font-weight:800;color:var(--primary)"><?= $statVendors ?></div>
        </div></div>
    </div>

    <div class="card">
        <div class="card-header">
            <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;width:100%">
                <div class="search-wrap" style="flex:1;min-width:180px">
                    <input type="text" name="search" value="<?= sanitize($search) ?>" placeholder="Search member, vendor, or company..." class="form-control">
                </div>
                <select name="status" class="form-control" style="width:140px">
                    <option value="">All Status</option>
                    <option value="active"   <?= $status==='active'  ?'selected':''?>>Active</option>
                    <option value="inactive" <?= $status==='inactive'?'selected':''?>>Inactive</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if ($search||$status): ?><a href="?" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
            </form>
        </div>
        <?php if ($members): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Vendor</th><th>Team Member</th><th>Designation</th><th>Access Granted</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($members as $m):
                    $perms = json_decode($m['permissions'] ?? '[]', true) ?: []; ?>
                <tr>
                    <td>
                        <div style="font-weight:600"><?= sanitize($m['vendor_name']) ?></div>
                        <div style="font-size:12px;color:var(--text-muted)"><?= sanitize($m['vendor_company'] ?: $m['vendor_email']) ?></div>
                        <a href="vendor-profile.php?id=<?= $m['vendor_id'] ?>" style="font-size:11.5px;color:var(--primary);font-weight:600">View Vendor →</a>
                    </td>
                    <td>
                        <div style="font-weight:500"><?= sanitize($m['name']) ?></div>
                        <div style="font-size:12px;color:var(--text-muted)"><code><?= sanitize($m['username']) ?></code></div>
                    </td>
                    <td><?= sanitize($m['designation'] ?: '—') ?></td>
                    <td style="max-width:240px">
                        <?php if (!$perms): ?><span style="color:var(--text-muted);font-size:12px">None</span><?php endif; ?>
                        <?php foreach ($perms as $p): if (!isset($catalog[$p])) continue; ?>
                            <span style="display:inline-block;margin:2px 3px 2px 0;padding:2px 8px;border-radius:12px;background:var(--primary-light);color:var(--primary);font-size:11px;font-weight:600"><?= $catalog[$p]['icon'] ?> <?= $catalog[$p]['label'] ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td><?= statusBadge($m['status']) ?></td>
                    <td class="text-muted"><?= $m['last_login'] ? timeAgo($m['last_login']) : 'Never' ?></td>
                    <td>
                        <div class="td-actions">
                            <?php if ($m['status']==='active'): ?>
                                <a href="?action=deactivate&id=<?= $m['id'] ?>" class="btn btn-warning btn-xs" onclick="return confirm('Suspend this team member?')">⏸ Suspend</a>
                            <?php else: ?>
                                <a href="?action=activate&id=<?= $m['id'] ?>" class="btn btn-success btn-xs" onclick="return confirm('Reactivate this team member?')">▶ Activate</a>
                            <?php endif; ?>
                            <a href="?action=delete&id=<?= $m['id'] ?>" class="btn btn-danger btn-xs" onclick="return confirm('Permanently remove this team member?')">🗑</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total,$perPage,$page,'?search='.urlencode($search).'&status='.$status) ?>
        <?php else: ?>
            <div class="empty-state"><div class="es-icon">👥</div><p>No team members found.</p></div>
        <?php endif; ?>
    </div>
</div>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
