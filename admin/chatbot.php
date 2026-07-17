<?php
// ============================================================
// admin/chatbot.php — Chatbot Admin Panel
// Place in: /dashv10_Fixed/admin/chatbot.php
// ============================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRoleStrict('admin');

$pageTitle  = 'Chatbot Manager';
$activePage = 'chatbot';
$tab        = $_GET['tab'] ?? 'overview';
$msg        = '';

function sH($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function cbStat(PDO $pdo, string $sql): mixed {
    try { return $pdo->query($sql)->fetchColumn(); } catch(Exception $e){ return 0; }
}

// ── Handle POST actions ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // Add new intent
    if ($act === 'add_intent') {
        $name = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($_POST['name'] ?? '')));
        $disp = trim($_POST['display_name'] ?? '');
        $prio = (int)($_POST['priority'] ?? 5);
        if ($name && $disp) {
            try {
                $pdo->prepare("INSERT INTO cb_intents (name,display_name,priority) VALUES(?,?,?)")->execute([$name,$disp,$prio]);
                $msg = "✅ Intent '$disp' added.";
            } catch(Exception $e){ $msg = "❌ ".$e->getMessage(); }
        }
    }

    // Add keyword to intent
    if ($act === 'add_keyword') {
        $iid     = (int)$_POST['intent_id'];
        $keyword = mb_strtolower(trim($_POST['keyword'] ?? ''));
        $weight  = max(1,min(10,(int)($_POST['weight']??5)));
        if ($iid && $keyword) {
            try {
                $pdo->prepare("INSERT INTO cb_keywords (intent_id,keyword,weight) VALUES(?,?,?)")->execute([$iid,$keyword,$weight]);
                $msg = "✅ Keyword added.";
            } catch(Exception $e){ $msg = "❌ Already exists or error."; }
        }
    }

    // Delete keyword
    if ($act === 'del_keyword') {
        $kid = (int)$_POST['keyword_id'];
        try { $pdo->prepare("DELETE FROM cb_keywords WHERE id=?")->execute([$kid]); $msg = "✅ Deleted."; }
        catch(Exception $e){ $msg = "❌ Error."; }
    }

    // Add response to intent
    if ($act === 'add_response') {
        $iid  = (int)$_POST['intent_id'];
        $resp = trim($_POST['response'] ?? '');
        $qr   = trim($_POST['quick_replies'] ?? '');
        if ($iid && $resp) {
            $qrJson = null;
            if ($qr) {
                $arr = array_filter(array_map('trim', explode("\n", $qr)));
                $qrJson = json_encode(array_values($arr));
            }
            try {
                $pdo->prepare("INSERT INTO cb_responses (intent_id,response,quick_replies) VALUES(?,?,?)")->execute([$iid,$resp,$qrJson]);
                $msg = "✅ Response added.";
            } catch(Exception $e){ $msg = "❌ Error adding response."; }
        }
    }

    // Toggle intent active/inactive
    if ($act === 'toggle_intent') {
        $iid = (int)$_POST['intent_id'];
        try {
            $pdo->prepare("UPDATE cb_intents SET is_active = 1 - is_active WHERE id=?")->execute([$iid]);
            $msg = "✅ Intent updated.";
        } catch(Exception $e){}
    }

    // Update ticket status
    if ($act === 'update_ticket') {
        $tid    = (int)$_POST['ticket_id'];
        $status = in_array($_POST['status'],['open','in_progress','resolved','closed'])?$_POST['status']:'open';
        $res    = trim($_POST['resolution']??'');
        try {
            $pdo->prepare("UPDATE cb_tickets SET status=?,resolution=?,updated_at=NOW() WHERE id=?")->execute([$status,$res?:null,$tid]);
            $msg = "✅ Ticket updated.";
        } catch(Exception $e){}
    }

    // Mark fallback resolved (means admin added keyword to train it)
    if ($act === 'resolve_fallback') {
        $fid = (int)$_POST['fallback_id'];
        try { $pdo->prepare("UPDATE cb_fallback_log SET resolved=1 WHERE id=?")->execute([$fid]); $msg="✅ Marked resolved."; }
        catch(Exception $e){}
    }

    // Redirect to avoid double-POST
    header("Location: ".BASE_URL."/admin/chatbot.php?tab=$tab&done=1"); exit;
}

// ── Load data per tab ──────────────────────────────────────
$intents = [];
try { $intents = $pdo->query("SELECT i.*, (SELECT COUNT(*) FROM cb_keywords k WHERE k.intent_id=i.id) AS kw_count, (SELECT COUNT(*) FROM cb_responses r WHERE r.intent_id=i.id) AS resp_count, (SELECT SUM(r2.usage_count) FROM cb_responses r2 WHERE r2.intent_id=i.id) AS uses FROM cb_intents i ORDER BY i.priority,i.name")->fetchAll(); }
catch(Exception $e){}

$stats = [
    'intents'    => cbStat($pdo,"SELECT COUNT(*) FROM cb_intents WHERE is_active=1"),
    'keywords'   => cbStat($pdo,"SELECT COUNT(*) FROM cb_keywords"),
    'responses'  => cbStat($pdo,"SELECT COUNT(*) FROM cb_responses WHERE is_active=1"),
    'sessions'   => cbStat($pdo,"SELECT COUNT(*) FROM cb_sessions"),
    'messages'   => cbStat($pdo,"SELECT COUNT(*) FROM cb_messages"),
    'tickets'    => cbStat($pdo,"SELECT COUNT(*) FROM cb_tickets WHERE status='open'"),
    'fallbacks'  => cbStat($pdo,"SELECT COUNT(*) FROM cb_fallback_log WHERE resolved=0"),
    'today_msgs' => cbStat($pdo,"SELECT COUNT(*) FROM cb_messages WHERE DATE(created_at)=CURDATE()"),
];

include __DIR__ . '/../includes/head.php';
?>
<style>
.cb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:22px}
.cb-card{background:#fff;border:1px solid #ede5d8;border-radius:10px;padding:14px;text-align:center}
.cb-val{font-size:22px;font-weight:800;color:#8b241d}.cb-lbl{font-size:11.5px;color:#888;margin-top:3px}
.cb-card.red .cb-val{color:#e74c3c}.cb-card.green .cb-val{color:#166534}
.cb-tabs{display:flex;gap:0;border-bottom:1px solid #ede5d8;margin-bottom:20px;overflow-x:auto}
.cb-tab{padding:10px 18px;border:none;background:transparent;font-size:13px;font-weight:600;color:#888;cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;font-family:inherit;transition:all .15s}
.cb-tab.on{color:#8b241d;border-bottom-color:#8b241d}
.cb-tab:hover:not(.on){color:#555;background:#faf6f1}
.cb-section{background:#fff;border:1px solid #ede5d8;border-radius:12px;margin-bottom:18px;overflow:hidden}
.cb-sec-hd{padding:12px 16px;border-bottom:1px solid #f0ebe4;font-weight:700;font-size:14px;color:#2d2520;display:flex;align-items:center;justify-content:space-between}
table.t{width:100%;border-collapse:collapse;font-size:13px}
.t th{background:#faf6f1;padding:8px 12px;text-align:left;font-weight:600;color:#8b241d;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #ede5d8}
.t td{padding:8px 12px;border-bottom:1px solid #f0ebe4;vertical-align:top}
.t tr:last-child td{border:none}.t tr:hover td{background:#faf6f1}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.b-active{background:#d1fae5;color:#065f46}.b-inactive{background:#f3f4f6;color:#6b7280}
.b-open{background:#fef3c7;color:#92400e}.b-resolved{background:#d1fae5;color:#065f46}
.b-closed{background:#f3f4f6;color:#888}.b-in_progress{background:#dbeafe;color:#1e40af}
.cb-form{padding:16px;background:#faf6f1;border-top:1px solid #ede5d8}
.cb-form h4{margin:0 0 12px;font-size:13px;color:#666;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.fi{padding:7px 10px;border:1.5px solid #ddd;border-radius:7px;font-size:13px;font-family:inherit;background:#fff;width:100%;box-sizing:border-box}
.fi:focus{outline:none;border-color:#8b241d}
.fg{margin-bottom:10px}
.fg label{font-size:12px;font-weight:600;color:#666;display:block;margin-bottom:3px}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.btn-sm{padding:6px 14px;border:none;border-radius:7px;cursor:pointer;font-size:12px;font-weight:600;font-family:inherit}
.btn-primary{background:#8b241d;color:#fff}.btn-primary:hover{background:#6b1a14}
.btn-danger{background:#fee2e2;color:#991b1b}.btn-danger:hover{background:#fecaca}
.btn-success{background:#d1fae5;color:#065f46}.btn-success:hover{background:#a7f3d0}
.btn-ghost{background:#f0ebe4;color:#444}.btn-ghost:hover{background:#e5ddd5}
.kw-pill{display:inline-flex;align-items:center;gap:5px;background:#f3e4c3;color:#6b1a14;padding:3px 8px;border-radius:10px;font-size:12px;margin:2px}
.kw-pill button{background:none;border:none;color:#8b241d;cursor:pointer;font-size:13px;line-height:1;padding:0}
.fallback-msg{background:#fff8f0;border-left:3px solid #f0c888;padding:8px 12px;border-radius:0 8px 8px 0;font-size:13px;font-family:monospace}
</style>

<div class="" style="padding:0">
<div >
  <!-- <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
    <div style="display:flex;align-items:center;gap:12px">
      <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
      
    </div>
  </div> -->
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
        <div>
      <h1 style="margin:0;font-size:22px;font-weight:800">💬 Chatbot Manager</h1>
      <p style="margin:4px 0 0;color:#888;font-size:13px">Manage intents, keywords, responses, tickets — no AI API needed.</p>
    </div>
    </div>
</div>


  <div style="padding:20px 24px">
  <?php if(isset($_GET['done'])&&$msg||true): ?>
  <?php if($msg): ?><div style="background:#d1fae5;color:#065f46;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13px"><?= sH($msg) ?></div><?php endif; ?>
  <?php endif; ?>

  <!-- Stats -->
  <div class="cb-grid">
    <div class="cb-card"><div class="cb-val"><?= $stats['intents'] ?></div><div class="cb-lbl">Active Intents</div></div>
    <div class="cb-card"><div class="cb-val"><?= $stats['keywords'] ?></div><div class="cb-lbl">Keywords</div></div>
    <div class="cb-card"><div class="cb-val"><?= $stats['responses'] ?></div><div class="cb-lbl">Responses</div></div>
    <div class="cb-card"><div class="cb-val"><?= $stats['today_msgs'] ?></div><div class="cb-lbl">Msgs Today</div></div>
    <div class="cb-card red"><div class="cb-val"><?= $stats['tickets'] ?></div><div class="cb-lbl">Open Tickets</div></div>
    <div class="cb-card red"><div class="cb-val"><?= $stats['fallbacks'] ?></div><div class="cb-lbl">Unresolved Fallbacks</div></div>
    <div class="cb-card"><div class="cb-val"><?= $stats['sessions'] ?></div><div class="cb-lbl">Total Sessions</div></div>
    <div class="cb-card"><div class="cb-val"><?= $stats['messages'] ?></div><div class="cb-lbl">Total Messages</div></div>
  </div>

  <!-- Tabs -->
  <div class="cb-tabs">
    <?php foreach([
      ['intents','🧠 Intents'],['keywords','🔑 Keywords'],
      ['responses','💬 Responses'],['tickets','🎫 Tickets'],
      ['fallbacks','⚠️ Fallbacks'],['test','🧪 Test Bot'],
    ] as [$t,$label]): ?>
      <button class="cb-tab <?= $tab===$t?'on':'' ?>" onclick="location.href='?tab=<?= $t ?>'">
        <?= $label ?>
      </button>
    <?php endforeach; ?>
  </div>

  <?php // ── INTENTS TAB ──────────────────────────────────────
  if ($tab === 'intents'): ?>

  <div class="cb-section active">
    <div class="cb-sec-hd">Intent Library <span style="font-size:12px;font-weight:400;color:#888"><?= count($intents) ?> intents</span></div>
    <div style="overflow-x:auto">
      <table class="t">
        <thead><tr><th>Intent Name</th><th>Display Name</th><th>Priority</th><th>Keywords</th><th>Responses</th><th>Uses</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach($intents as $i): ?>
          <tr>
            <td><code style="font-size:12px;color:#8b241d"><?= sH($i['name']) ?></code></td>
            <td><?= sH($i['display_name']) ?></td>
            <td style="text-align:center"><?= $i['priority'] ?></td>
            <td style="text-align:center"><strong><?= $i['kw_count'] ?></strong></td>
            <td style="text-align:center"><strong><?= $i['resp_count'] ?></strong></td>
            <td style="text-align:center;color:#888"><?= $i['uses']??0 ?></td>
            <td><span class="badge b-<?= $i['is_active']?'active':'inactive' ?>"><?= $i['is_active']?'Active':'Inactive' ?></span></td>
            <td>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="toggle_intent">
                <input type="hidden" name="intent_id" value="<?= $i['id'] ?>">
                <button type="submit" class="btn-sm btn-ghost" style="font-size:11px">
                  <?= $i['is_active']?'Disable':'Enable' ?>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <!-- Add Intent Form -->
    <div class="cb-form">
      <h4>+ Add New Intent</h4>
      <form method="POST">
        <input type="hidden" name="action" value="add_intent">
        <div class="row2">
          <div class="fg"><label>Intent Name (snake_case)</label><input class="fi" name="name" placeholder="e.g. returns_policy" required></div>
          <div class="fg"><label>Display Name</label><input class="fi" name="display_name" placeholder="e.g. Returns Policy" required></div>
        </div>
        <div class="fg" style="max-width:120px"><label>Priority (1=high, 10=low)</label><input class="fi" name="priority" type="number" min="1" max="10" value="5"></div>
        <button type="submit" class="btn-sm btn-primary">Add Intent</button>
      </form>
    </div>
  </div>

  <?php // ── KEYWORDS TAB ─────────────────────────────────────
  elseif ($tab === 'keywords'):
    $kwIntentId = (int)($_GET['intent'] ?? ($intents[0]['id'] ?? 0));
    $kwRows = [];
    if ($kwIntentId) {
        try { $kwRows = $pdo->prepare("SELECT * FROM cb_keywords WHERE intent_id=? ORDER BY weight DESC,keyword")->execute([$kwIntentId]) ? $pdo->query("SELECT * FROM cb_keywords WHERE intent_id=$kwIntentId ORDER BY weight DESC")->fetchAll() : []; }
        catch(Exception $e){}
        $kStmt = $pdo->prepare("SELECT * FROM cb_keywords WHERE intent_id=? ORDER BY weight DESC,keyword");
        $kStmt->execute([$kwIntentId]);
        $kwRows = $kStmt->fetchAll();
    }
  ?>
  <!-- Intent selector -->
  <div style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach($intents as $i): ?>
      <a href="?tab=keywords&intent=<?= $i['id'] ?>"
         style="padding:5px 12px;border-radius:16px;font-size:12px;text-decoration:none;font-weight:600;
                border:1.5px solid <?= $kwIntentId==$i['id']?'#8b241d':'#ddd' ?>;
                background:<?= $kwIntentId==$i['id']?'#8b241d':'#fff' ?>;
                color:<?= $kwIntentId==$i['id']?'#fff':'#555' ?>">
        <?= sH($i['display_name']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if($kwIntentId): $selIntent = array_values(array_filter($intents,fn($i)=>$i['id']==$kwIntentId))[0]??null; ?>
  <div class="cb-section">
    <div class="cb-sec-hd">
      Keywords for: <strong style="color:#8b241d"><?= sH($selIntent['display_name']??'') ?></strong>
      <span style="font-size:12px;font-weight:400;color:#888"><?= count($kwRows) ?> keywords</span>
    </div>
    <div style="padding:14px 16px">
      <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:14px">
        <?php foreach($kwRows as $k): ?>
        <span class="kw-pill">
          <?= sH($k['keyword']) ?>
          <span style="font-size:10px;color:#a06040;background:#ffe;padding:0 3px;border-radius:3px"><?= $k['weight'] ?></span>
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="del_keyword">
            <input type="hidden" name="keyword_id" value="<?= $k['id'] ?>">
            <input type="hidden" name="intent_id" value="<?= $kwIntentId ?>">
            <button type="submit" onclick="return confirm('Delete?')" title="Delete">×</button>
          </form>
        </span>
        <?php endforeach; ?>
        <?php if(empty($kwRows)): ?><span style="color:#999;font-size:13px">No keywords yet.</span><?php endif; ?>
      </div>
    </div>
    <div class="cb-form">
      <h4>+ Add Keyword</h4>
      <form method="POST">
        <input type="hidden" name="action" value="add_keyword">
        <input type="hidden" name="intent_id" value="<?= $kwIntentId ?>">
        <div class="row2">
          <div class="fg"><label>Keyword / Phrase (lowercase)</label><input class="fi" name="keyword" placeholder="e.g. i want to register" required></div>
          <div class="fg"><label>Weight (1-10)</label><input class="fi" name="weight" type="number" min="1" max="10" value="7"></div>
        </div>
        <button type="submit" class="btn-sm btn-primary">Add Keyword</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php // ── RESPONSES TAB ────────────────────────────────────
  elseif ($tab === 'responses'):
    $rIntentId = (int)($_GET['intent'] ?? ($intents[0]['id'] ?? 0));
    $rRows = [];
    if ($rIntentId) {
        $rs = $pdo->prepare("SELECT * FROM cb_responses WHERE intent_id=? ORDER BY id");
        $rs->execute([$rIntentId]);
        $rRows = $rs->fetchAll();
    }
  ?>
  <div style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach($intents as $i): ?>
      <a href="?tab=responses&intent=<?= $i['id'] ?>"
         style="padding:5px 12px;border-radius:16px;font-size:12px;text-decoration:none;font-weight:600;
                border:1.5px solid <?= $rIntentId==$i['id']?'#8b241d':'#ddd' ?>;
                background:<?= $rIntentId==$i['id']?'#8b241d':'#fff' ?>;
                color:<?= $rIntentId==$i['id']?'#fff':'#555' ?>">
        <?= sH($i['display_name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if($rIntentId): $selRI = array_values(array_filter($intents,fn($i)=>$i['id']==$rIntentId))[0]??null; ?>
  <div class="cb-section">
    <div class="cb-sec-hd">Responses for: <strong style="color:#8b241d"><?= sH($selRI['display_name']??'') ?></strong></div>
    <table class="t">
      <thead><tr><th>#</th><th>Response Preview</th><th>Quick Replies</th><th>Uses</th></tr></thead>
      <tbody>
        <?php foreach($rRows as $r): ?>
        <tr>
          <td><?= $r['id'] ?></td>
          <td style="max-width:280px;font-size:12.5px;color:#444"><?= substr(strip_tags($r['response']),0,100) ?>…</td>
          <td style="font-size:12px;color:#888"><?= $r['quick_replies'] ? implode(', ',json_decode($r['quick_replies'],true)??[]) : '—' ?></td>
          <td><?= $r['usage_count'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($rRows)): ?><tr><td colspan="4" style="text-align:center;color:#999;padding:20px">No responses yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <div class="cb-form">
      <h4>+ Add Response</h4>
      <form method="POST">
        <input type="hidden" name="action" value="add_response">
        <input type="hidden" name="intent_id" value="<?= $rIntentId ?>">
        <div class="fg"><label>Response HTML (supports bold, lists, links)</label>
          <textarea class="fi" name="response" rows="5" placeholder="<p>Your response here...</p>" required></textarea></div>
        <div class="fg"><label>Quick Replies (one per line, optional)</label>
          <textarea class="fi" name="quick_replies" rows="3" placeholder="Vendor Registration&#10;Contact Support"></textarea></div>
        <button type="submit" class="btn-sm btn-primary">Add Response</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php // ── TICKETS TAB ──────────────────────────────────────
  elseif ($tab === 'tickets'):
    try { $tickets = $pdo->query("SELECT t.*,s.ip_address FROM cb_tickets t LEFT JOIN cb_sessions s ON s.id=t.session_id ORDER BY t.created_at DESC LIMIT 50")->fetchAll(); }
    catch(Exception $e){ $tickets=[]; }
  ?>
  <div class="cb-section">
    <div class="cb-sec-hd">Support Tickets <span style="background:#fef3c7;color:#92400e;padding:1px 8px;border-radius:8px;font-size:12px;margin-left:8px"><?= $stats['tickets'] ?> open</span></div>
    <div style="overflow-x:auto">
      <table class="t">
        <thead><tr><th>Ref</th><th>Customer</th><th>Subject</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
        <tbody>
          <?php if(empty($tickets)): ?>
            <tr><td colspan="6" style="text-align:center;color:#999;padding:20px">No tickets yet.</td></tr>
          <?php else: foreach($tickets as $tk): ?>
          <tr>
            <td><code style="font-size:12px;color:#8b241d"><?= sH($tk['ticket_ref']) ?></code></td>
            <td><strong><?= sH($tk['user_name']) ?></strong><br><a href="mailto:<?= sH($tk['user_email']) ?>" style="font-size:12px;color:#8b241d"><?= sH($tk['user_email']) ?></a><?php if($tk['user_phone']): ?><br><span style="font-size:11.5px;color:#888"><?= sH($tk['user_phone']) ?></span><?php endif; ?></td>
            <td style="max-width:200px;font-size:13px"><?= sH(substr($tk['subject'],0,60)) ?></td>
            <td><span class="badge b-<?= $tk['status'] ?>"><?= ucfirst(str_replace('_',' ',$tk['status'])) ?></span></td>
            <td style="font-size:12px;color:#888;white-space:nowrap"><?= date('d M, H:i',strtotime($tk['created_at'])) ?></td>
            <td><button onclick="document.getElementById('tkm<?= $tk['id'] ?>').showModal()" class="btn-sm btn-primary" style="font-size:11px">Update</button></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php // ── FALLBACKS TAB ────────────────────────────────────
  elseif ($tab === 'fallbacks'):
    try { $fallbacks = $pdo->query("SELECT * FROM cb_fallback_log WHERE resolved=0 ORDER BY created_at DESC LIMIT 50")->fetchAll(); }
    catch(Exception $e){ $fallbacks=[]; }
  ?>
  <p style="font-size:13px;color:#666;margin:0 0 14px">These are messages the bot <strong>couldn't answer</strong>. Use them to add new keywords and train your chatbot!</p>
  <div class="cb-section">
    <div class="cb-sec-hd">Unresolved Fallbacks <span style="background:#fee2e2;color:#991b1b;padding:1px 8px;border-radius:8px;font-size:12px;margin-left:8px"><?= $stats['fallbacks'] ?></span></div>
    <table class="t">
      <thead><tr><th>User Said</th><th>Time</th><th>Action</th></tr></thead>
      <tbody>
        <?php if(empty($fallbacks)): ?>
          <tr><td colspan="3" style="text-align:center;color:#999;padding:20px">🎉 No unresolved fallbacks! Your bot is well-trained.</td></tr>
        <?php else: foreach($fallbacks as $fb): ?>
        <tr>
          <td><div class="fallback-msg"><?= sH($fb['message']) ?></div></td>
          <td style="font-size:12px;color:#888;white-space:nowrap"><?= date('d M, H:i',strtotime($fb['created_at'])) ?></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="resolve_fallback">
              <input type="hidden" name="fallback_id" value="<?= $fb['id'] ?>">
              <button type="submit" class="btn-sm btn-success" style="font-size:11px">✓ Resolved</button>
            </form>
            <a href="?tab=keywords" class="btn-sm btn-ghost" style="font-size:11px;text-decoration:none;display:inline-block;margin-left:4px">+ Add Keyword</a>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php // ── TEST TAB ─────────────────────────────────────────
  elseif ($tab === 'test'): ?>
  <div style="max-width:500px">
    <p style="font-size:13px;color:#666;margin:0 0 16px">Test your chatbot's intent detection without opening the widget. Type a message and see which intent it matches and the confidence score.</p>
    <div class="cb-section">
      <div class="cb-sec-hd">Intent Detection Tester</div>
      <div style="padding:16px">
        <div class="fg">
          <label style="font-size:12px;font-weight:600;color:#666;display:block;margin-bottom:4px">Test Message</label>
          <input class="fi" id="test-input" placeholder="e.g. I want to become a vendor on your platform" style="margin-bottom:10px">
          <button class="btn-sm btn-primary" onclick="testBot()">🧪 Test Intent Detection</button>
        </div>
        <div id="test-result" style="display:none;margin-top:14px"></div>
      </div>
    </div>
  </div>
  <script>
  async function testBot() {
    const inp = document.getElementById('test-input');
    const res = document.getElementById('test-result');
    const msg = inp.value.trim();
    if (!msg) return;
    res.style.display = 'none';
    res.innerHTML = '';
    try {
      const r    = await fetch('<?= BASE_URL ?>/ajax/chatbot.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'message',message:msg,token:''})});
      const data = await r.json();
      const conf = data.confidence ?? 0;
      const color= conf>=60?'#166534':conf>=30?'#92400e':'#991b1b';
      const bg   = conf>=60?'#d1fae5':conf>=30?'#fef3c7':'#fee2e2';
      res.style.display = 'block';
      res.innerHTML = `
        <div style="background:${bg};border-radius:10px;padding:14px;font-size:13px">
          <div style="font-weight:700;color:${color};margin-bottom:8px">
            Intent: ${data.intent||'(fallback / no match)'}
            ${conf?`<span style="float:right">Confidence: ${conf}%</span>`:''}
          </div>
          ${conf?`<div style="height:6px;background:rgba(0,0,0,.08);border-radius:3px;margin-bottom:10px"><div style="height:100%;width:${conf}%;background:${color};border-radius:3px"></div></div>`:''}
          <div style="background:#fff;border-radius:8px;padding:10px;font-size:12.5px;line-height:1.6">${data.reply||'(empty response)'}</div>
          ${data.quick_replies?.length?`<div style="margin-top:8px;font-size:12px;color:#666">Quick replies: ${data.quick_replies.join(' · ')}</div>`:''}
        </div>`;
    } catch(e) {
      res.style.display='block';
      res.innerHTML='<div style="color:red;font-size:13px">Error: '+e.message+'</div>';
    }
  }
  document.getElementById('test-input')?.addEventListener('keydown',e=>{ if(e.key==='Enter') testBot(); });
  </script>
  <?php endif; ?>

</div><!-- /padding -->
</div><!-- /main -->
</div>

<!-- Ticket modals -->
<?php if($tab==='tickets' && !empty($tickets)): foreach($tickets as $tk): ?>
<dialog id="tkm<?= $tk['id'] ?>" class="modal" style="border:none;border-radius:10px;padding:20px;width:400px;max-width:90vw; justify-self:center; align-self:center;">
  <h3 style="margin:0 0 14px;color:#8b241d;font-size:16px">Update: <?= sH($tk['ticket_ref']) ?></h3>
  <form method="POST">
    <input type="hidden" name="action" value="update_ticket">
    <input type="hidden" name="ticket_id" value="<?= $tk['id'] ?>">
    <input type="hidden" name="intent_id" value="<?= $kwIntentId??0 ?>">
    <div class="fg"><label style="font-size:12px;font-weight:600;color:#666">STATUS</label>
      <select name="status" class="fi">
        <?php foreach(['open','in_progress','resolved','closed'] as $s): ?>
          <option value="<?=$s?>" <?=$tk['status']===$s?'selected':''?>><?=ucfirst(str_replace('_',' ',$s))?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg"><label style="font-size:12px;font-weight:600;color:#666">RESOLUTION NOTE</label>
      <textarea name="resolution" class="fi" rows="4"><?= sH($tk['resolution']??'') ?></textarea></div>
    <div style="display:flex;gap:10px;margin-top:6px">
      <button type="submit" class="btn-sm btn-primary" style="flex:1;padding:10px">Save</button>
      <button type="button" onclick="this.closest('dialog').close()" class="btn-sm btn-ghost" style="padding:10px">Cancel</button>
    </div>
  </form>
</dialog>
<?php endforeach; endif; ?>

<script src="<?= BASE_URL ?>/assets/script.js"></script>
</div></div></body></html>
