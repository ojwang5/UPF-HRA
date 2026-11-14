<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
$user = require_login();
$page = 'notifications';
$page_title = 'Notifications';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_all') { mark_all_read($user); }
    elseif ($action === 'mark_one') { mark_notification_read((int)$_POST['id'],(int)$user['id']); }
    elseif ($action === 'delete_one') { delete_notification((int)$_POST['id'],(int)$user['id']); }
    elseif ($action === 'delete_all') { delete_all_notifications($user); }
    elseif ($action === 'broadcast' && is_superadmin($user)) {
        $title=$_POST['title']??''; $msg=$_POST['message']??''; $aud=$_POST['audience']??'all';
        if ($title && $msg) {
            $opts=['created_by'=>$user['id']];
            if ($aud==='region') $opts['target_region_id']=(int)$_POST['region_id'];
            elseif ($aud==='role') $opts['target_role']=$_POST['target_role']??'officer';
            elseif ($aud==='directorate') $opts['target_directorate_id']=(int)$_POST['directorate_id'];
            notify($title,$msg,$aud,$opts);
            flash('msg','Notification sent.');
        }
    }
    header('Location:/notifications.php'); exit;
}

$notifs = notifications_for($user, false, 100);
$regions   = is_superadmin($user) ? $pdo->query("SELECT * FROM regions ORDER BY name")->fetchAll() : [];
$directorates = is_superadmin($user) ? $pdo->query("SELECT * FROM directorates WHERE active=1 ORDER BY name")->fetchAll() : [];

$page_style = '.notif{display:flex;gap:12px;padding:14px 6px;border-bottom:1px solid var(--border);align-items:flex-start}';

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Notifications</h1><div class="desc">System alerts, approvals, and broadcasts</div></div>
  <div class="action-bar">
    <?php if (is_superadmin($user)): ?>
    <button type="button" class="btn-icon bi-gold btn-labeled" data-open-dialog="broadcast-dialog" title="Send broadcast">
      <?= ICO_BELL ?><span>Send Broadcast</span>
    </button>
    <?php endif; ?>
    <!-- Delete all -->
    <form method="post" style="display:contents" data-confirm="Delete all notifications? This cannot be undone." data-confirm-title="Delete all notifications" data-confirm-ok="Delete all">
  <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_all">
      <button type="submit" class="btn-icon bi-danger" title="Delete all notifications"><?= ICO_REJECT ?></button>
    </form>
    <!-- Mark all read -->
    <form method="post" style="display:contents">
  <?= csrf_field() ?>
      <input type="hidden" name="action" value="mark_all">
      <button type="submit" class="btn-icon bi-secondary" title="Mark all as read"><?= ICO_OK_ALL ?></button>
    </form>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

<div class="card">
  <div class="chr"><h3>Inbox <?php $unread=count(array_filter($notifs,fn($n)=>empty($n['read_at_user']))); if($unread): ?><span class="badge badge-sick"><?= $unread ?> new</span><?php endif; ?></h3></div>
  <?php if (!$notifs): ?><div class="muted" style="padding:18px 0;text-align:center">Inbox is empty.</div><?php endif; ?>
  <?php foreach ($notifs as $n): $unread=empty($n['read_at_user']); ?>
  <div class="notif <?= $unread?'unread':'' ?>">
    <div class="notif-dot"></div>
    <div class="notif-body" style="flex:1">
      <div class="notif-title"><?= e($n['title']) ?><?php if ($unread): ?><span class="badge badge-leave" style="margin-left:8px;font-size:10px">NEW</span><?php endif; ?></div>
      <div class="notif-msg"><?= e($n['message']) ?></div>
      <div class="notif-meta">
        <?= e(date('M j, Y · H:i',strtotime($n['created_at']))) ?>
        <?= $n['sender'] ? ' · '.e($n['sender']) : '' ?>
        <?php if ($n['link']): ?> · <a href="<?= e($n['link']) ?>">Open →</a><?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:6px;align-items:flex-start;margin-top:4px">
      <?php if ($unread): ?>
      <form method="post" style="display:contents">
    <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_one"><input type="hidden" name="id" value="<?= $n['id'] ?>">
        <button type="submit" class="btn-icon bi-secondary bi-sm" title="Mark as read"><?= ICO_SAVE ?></button>
      </form>
      <?php endif; ?>
      <form method="post" style="display:contents" data-confirm="Delete this notification?" data-confirm-title="Delete notification" data-confirm-ok="Delete">
    <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_one"><input type="hidden" name="id" value="<?= $n['id'] ?>">
        <button type="submit" class="btn-icon bi-danger bi-sm" title="Delete notification"><?= ICO_REJECT ?></button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (is_superadmin($user)): ?>
<dialog id="broadcast-dialog" class="app-dialog" aria-labelledby="broadcast-title">
  <div class="app-dialog-head">
    <div>
      <h2 id="broadcast-title">Send Broadcast</h2>
      <p>Deliver an announcement to everyone or to a targeted group.</p>
    </div>
    <button type="button" class="app-dialog-close" data-close-dialog aria-label="Close">&times;</button>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="broadcast">
    <div class="app-dialog-body">
      <div class="form-group">
        <label for="broadcast-title-field">Title</label>
        <input id="broadcast-title-field" type="text" name="title" maxlength="120" placeholder="e.g. Monthly parade change" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="broadcast-audience">Audience</label>
          <select name="audience" id="broadcast-audience">
            <option value="all">All users</option>
            <option value="region">A Region</option>
            <option value="role">A Role</option>
            <option value="directorate">A Directorate</option>
          </select>
        </div>
        <div class="form-group" data-audience-panel="region" hidden>
          <label for="broadcast-region">Region</label>
          <select name="region_id" id="broadcast-region">
            <?php foreach ($regions as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" data-audience-panel="role" hidden>
          <label for="broadcast-role">Role</label>
          <select name="target_role" id="broadcast-role">
            <?php foreach (array_keys(ROLE_LABELS) as $r): ?><option value="<?= $r ?>"><?= e(role_label($r)) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" data-audience-panel="directorate" hidden>
          <label for="broadcast-directorate">Directorate</label>
          <select name="directorate_id" id="broadcast-directorate">
            <?php foreach ($directorates as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label for="broadcast-message">Message</label>
        <textarea id="broadcast-message" name="message" rows="5" placeholder="Write the announcement…" required></textarea>
        <p class="app-dialog-hint" data-audience-hint></p>
      </div>
    </div>
    <div class="app-dialog-foot">
      <button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button>
      <button type="submit" class="btn btn-gold"><?= ICO_SEND ?><span>Send broadcast</span></button>
    </div>
  </form>
</dialog>

<script>
(function(){
  var dlg = document.getElementById('broadcast-dialog');
  if(!dlg) return;

  var audience = dlg.querySelector('#broadcast-audience');
  var hint = dlg.querySelector('[data-audience-hint]');
  var hints = {
    all: 'Every active user will receive this notification.',
    region: 'Only users attached to the selected region will receive this notification.',
    role: 'Only users with the selected role will receive this notification.',
    directorate: 'Only users in the selected directorate will receive this notification.'
  };

  function syncAudience(){
    var value = audience.value;
    dlg.querySelectorAll('[data-audience-panel]').forEach(function(panel){
      panel.hidden = panel.getAttribute('data-audience-panel') !== value;
    });
    if(hint) hint.textContent = hints[value] || '';
  }
  audience.addEventListener('change', syncAudience);
  syncAudience();

  document.addEventListener('click', function(e){
    var opener = e.target.closest('[data-open-dialog="broadcast-dialog"]');
    if(opener){
      dlg.showModal();
      var title = dlg.querySelector('#broadcast-title-field');
      if(title) title.focus();
      return;
    }
    var closer = e.target.closest('[data-close-dialog]');
    if(closer && closer.closest('dialog') === dlg) dlg.close();
  });

  dlg.addEventListener('click', function(e){
    if(e.target === dlg) dlg.close();
  });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
