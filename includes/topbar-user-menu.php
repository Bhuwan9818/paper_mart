<?php

if (!isset($user)) { $user = function_exists('currentUser') ? currentUser() : null; }
if (!$user) return;

$tumRole = $user['role'] ?? '';
$tumProfileLink = match($tumRole) {
    'vendor'   => BASE_URL . '/vendor/profile.php',
    'customer' => BASE_URL . '/customer/profile.php',
    'admin'    => BASE_URL . '/admin/profile.php',
    default    => null,
};
$tumDisplayName = $user['name'] ?? 'Account';
if (function_exists('isTeamMemberSession') && isTeamMemberSession()) {
    $tumDisplayName = $_SESSION['team_member_name'] ?? $tumDisplayName;
}
?>
<div class="tum-wrap" style="position:relative;display:inline-block">
  <button type="button" class="topbar-avatar" id="tum-trigger" onclick="tumToggle(event)" style="cursor:pointer;border:none;padding:0" aria-haspopup="true" aria-expanded="false">
    <?= function_exists('avatarLetter') ? avatarLetter($tumDisplayName) : strtoupper(substr($tumDisplayName,0,1)) ?>
  </button>
  <div class="tum-dropdown" id="tum-dropdown" style="display:none;position:absolute;right:0;top:calc(100% + 10px);min-width:200px;background:#fff;border:1px solid var(--n200,#e5e7eb);border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.14);z-index:500;overflow:hidden">
    <div style="padding:12px 16px;border-bottom:1px solid var(--n100,#f1f1f1)">
      <div style="font-weight:700;font-size:13.5px;color:var(--n900,#111);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($tumDisplayName) ?></div>
      <div style="font-size:11.5px;color:var(--n500,#6b7280);text-transform:capitalize"><?= htmlspecialchars($tumRole) ?></div>
    </div>
    <?php if ($tumProfileLink): ?>
    <a href="<?= $tumProfileLink ?>" style="display:flex;align-items:center;gap:9px;padding:10px 16px;font-size:13.5px;color:var(--n800,#1f2937);text-decoration:none">👤 My Profile</a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/public/index.php" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:9px;padding:10px 16px;font-size:13.5px;color:var(--n800,#1f2937);text-decoration:none">🌐 Visit Website</a>
    <div style="border-top:1px solid var(--n100,#f1f1f1)">
      <a href="<?= BASE_URL ?>/logout.php" style="display:flex;align-items:center;gap:9px;padding:10px 16px;font-size:13.5px;color:#dc2626;font-weight:600;text-decoration:none">🚪 Logout</a>
    </div>
  </div>
</div>
<script>
(function(){
  if (window.__tumWired) return; window.__tumWired = true;
  document.addEventListener('click', function(e){
    document.querySelectorAll('.tum-dropdown').forEach(function(dd){
      const trigger = dd.previousElementSibling;
      if (dd.contains(e.target) || (trigger && trigger.contains(e.target))) return;
      dd.style.display = 'none';
    });
  });
})();
function tumToggle(e){
  e.stopPropagation();
  const dd = document.getElementById('tum-dropdown');
  if (!dd) return;
  const open = dd.style.display === 'block';
  document.querySelectorAll('.tum-dropdown').forEach(d => d.style.display = 'none');
  dd.style.display = open ? 'none' : 'block';
}
</script>
