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
    elseif ($action === 'broadcast' && is_superadmin($user)) {
        $title=$_POST['title']??''; $msg=$_POST['message']??''; $aud=$_POST['audience']??'all';
        if ($title && $msg) {
            $opts=['created_by'=>$user['id']];
            if ($aud==='region') $opts['target_region_id']=(int)$_POST['region_id'];
            elseif ($aud==='role') $opts['target_role']=$_POST['target_role']??'officer';
            notify($title,$msg,$aud,$opts);
            flash('msg','Notification sent.');
        }
    }
    header('Location:/notifications.php'); exit;
}

$notifs = notifications_for($user, false, 100);
$regions = is_superadmin($user) ? $pdo->query("SELECT * FROM regions ORDER BY name")->fetchAll() : [];
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Notifications</h1><div class="desc">System updates, approvals, and broadcasts</div></div>
  <form method="post"><input type="hidden" name="action" value="mark_all"><button class="btn btn-secondary">Mark all read</button></form>
</div>
<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

<?php if (is_superadmin($user)): ?>
<div class="card">
  <h3>Send Broadcast</h3>
  <form method="post">
    <input type="hidden" name="action" value="broadcast">
    <div class="form-row">
      <div class="form-group" style="flex:2"><label>Title</label><input type="text" name="title" required></div>
      <div class="form-group"><label>Audience</label>
        <select name="audience" id="aud-sel" onchange="var v=this.value;document.getElementById('br-fld').style.display=v==='region'?'':'none';document.getElementById('rl-fld').style.display=v==='role'?'':'none'">
          <option value="all">All users</option>
          <option value="region">A Region</option>
          <option value="role">A Role</option>
        </select>
      </div>
      <div class="form-group" id="br-fld" style="display:none"><label>Region</label>
        <select name="region_id"><?php foreach ($regions as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select>
      </div>
      <div class="form-group" id="rl-fld" style="display:none"><label>Role</label>
        <select name="target_role">
          <?php foreach (array_keys(ROLE_LABELS) as $r): ?><option value="<?= $r ?>"><?= e(role_label($r)) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:1"><label>Message</label><textarea name="message" rows="3" required></textarea></div>
      <div class="form-group" style="flex:0"><label>&nbsp;</label><button class="btn btn-gold">Send</button></div>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Inbox</h3>
  <?php if (!$notifs): ?><div class="muted" style="padding:18px 0;text-align:center">No notifications yet.</div><?php endif; ?>
  <?php foreach ($notifs as $n): $unread=empty($n['read_at_user']); ?>
  <div class="notif <?= $unread?'unread':'' ?>">
    <div class="notif-dot"></div>
    <div class="notif-body">
      <div class="notif-title"><?= e($n['title']) ?><?php if ($unread): ?><span class="badge badge-leave" style="margin-left:8px;font-size:10px">NEW</span><?php endif; ?></div>
      <div class="notif-msg"><?= e($n['message']) ?></div>
      <div class="notif-meta">
        <?= e(date('M j, Y · H:i',strtotime($n['created_at']))) ?>
        <?= $n['sender'] ? ' · from '.e($n['sender']) : '' ?>
        · <?= e($n['audience']) ?>
        <?php if ($n['link']): ?> · <a href="<?= e($n['link']) ?>">Open →</a><?php endif; ?>
        <?php if ($unread): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="mark_one"><input type="hidden" name="id" value="<?= $n['id'] ?>">
          <button class="link-btn">mark read</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
