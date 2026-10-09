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

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Notifications</h1><div class="desc">System alerts, approvals, and broadcasts</div></div>
  <div class="action-bar">
    <?php if (is_superadmin($user)): ?>
    <details class="form-panel" id="broadcast-form">
      <summary><button type="button" class="btn-icon bi-gold" title="Send broadcast"><?= ICO_BELL ?></button></summary>
      <div class="form-body">
        <h4 style="margin:0 0 12px;color:var(--navy-800)">Send Broadcast</h4>
        <form method="post">
          <input type="hidden" name="action" value="broadcast">
          <div class="form-row">
            <div class="form-group" style="flex:2"><label>Title</label><input type="text" name="title" required></div>
            <div class="form-group"><label>Audience</label>
              <select name="audience" id="aud-sel" onchange="var v=this.value;document.getElementById('br-fld').style.display=v==='region'?'':'none';document.getElementById('rl-fld').style.display=v==='role'?'':'none';document.getElementById('dr-fld').style.display=v==='directorate'?'':'none'">
                <option value="all">All users</option>
                <option value="region">A Region</option>
                <option value="role">A Role</option>
                <option value="directorate">A Directorate</option>
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
            <div class="form-group" id="dr-fld" style="display:none"><label>Directorate</label>
              <select name="directorate_id"><?php foreach ($directorates as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:1"><label>Message</label><textarea name="message" rows="2" required></textarea></div>
          </div>
          <div class="action-bar" style="margin-top:10px">
            <button class="btn-icon bi-gold bi-lg" type="submit" title="Send broadcast"><?= ICO_SEND ?></button>
          </div>
        </form>
      </div>
    </details>
    <?php endif; ?>
    <!-- Mark all read -->
    <form method="post" style="display:contents">
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
    <?php if ($unread): ?>
    <form method="post" style="display:contents">
      <input type="hidden" name="action" value="mark_one"><input type="hidden" name="id" value="<?= $n['id'] ?>">
      <button type="submit" class="btn-icon bi-secondary bi-sm" title="Mark as read" style="align-self:flex-start;margin-top:4px"><?= ICO_SAVE ?></button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<style>.notif{display:flex;gap:12px;padding:14px 6px;border-bottom:1px solid var(--border);align-items:flex-start}</style>
<script>
document.querySelectorAll('details.form-panel').forEach(d=>{
  d.addEventListener('toggle',()=>{ if(d.open) document.querySelectorAll('details.form-panel').forEach(o=>{ if(o!==d) o.open=false; }); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
