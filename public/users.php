<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_min_rank('post_commander');
$page = 'users';
$page_title = 'User Accounts';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $uname = trim($_POST['username'] ?? '');
        $fname = trim($_POST['full_name'] ?? '');
        $pw    = $_POST['password'] ?? '';
        $role  = $_POST['role'] ?? 'officer';
        if (!in_array($role, creatable_roles($user), true)) { flash('err','You cannot assign that role.'); header('Location:/users.php');exit; }
        if ($uname===''||$fname===''||strlen($pw)<4) { flash('err','Username, name, and password (min 4 chars) required.'); header('Location:/users.php');exit; }
        $rid=$did=$sid=$pid=null;
        $rid = $_POST['region_id']   ? (int)$_POST['region_id']   : null;
        $did = $_POST['division_id'] ? (int)$_POST['division_id'] : null;
        $sid = $_POST['station_id']  ? (int)$_POST['station_id']  : null;
        $pid = $_POST['post_id']     ? (int)$_POST['post_id']     : null;
        if (!is_superadmin($user)) {
            $rid = $rid ?: ($user['region_id'] ? (int)$user['region_id'] : null);
            $did = $did ?: ($user['division_id'] ? (int)$user['division_id'] : null);
            $sid = $sid ?: ($user['station_id'] ? (int)$user['station_id'] : null);
            $pid = $pid ?: ($user['post_id'] ? (int)$user['post_id'] : null);
        }
        try {
            $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role,region_id,division_id,station_id,post_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$uname,password_hash($pw,PASSWORD_DEFAULT),$fname,$role,$rid,$did,$sid,$pid]);
            flash('msg','Account created for '.$fname.'.');
        } catch (\PDOException $e) { flash('err','Could not create: '.$e->getMessage()); }
    } elseif ($action === 'reset') {
        $id=(int)$_POST['id']; $pw=$_POST['password']??'';
        $t=$pdo->query("SELECT * FROM users WHERE id=$id")->fetch();
        if ($t && can_manage_user($user,$t) && strlen($pw)>=4) {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($pw,PASSWORD_DEFAULT),$id]);
            flash('msg','Password reset.');
        }
    } elseif ($action === 'delete') {
        $id=(int)$_POST['id'];
        $t=$pdo->query("SELECT * FROM users WHERE id=$id")->fetch();
        if ($t && can_manage_user($user,$t) && $id!==(int)$user['id']) {
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
            flash('msg','Account removed.');
        }
    }
    header('Location:/users.php'); exit;
}

$users = $pdo->query("SELECT u.*, rg.name AS region_name, dv.name AS division_name, st.name AS station_name, pt.name AS post_name FROM users u LEFT JOIN regions rg ON rg.id=u.region_id LEFT JOIN divisions dv ON dv.id=u.division_id LEFT JOIN stations st ON st.id=u.station_id LEFT JOIN posts pt ON pt.id=u.post_id ORDER BY u.role, u.full_name")->fetchAll();
$users = array_filter($users, fn($u) => $u['id']!==(int)$user['id'] && can_manage_user($user, $u));
$creatableRoles = creatable_roles($user);
$regions   = $pdo->query("SELECT * FROM regions ORDER BY name")->fetchAll();
$divisions = $pdo->query("SELECT d.*,r.name AS region_name FROM divisions d JOIN regions r ON r.id=d.region_id ORDER BY region_name,d.name")->fetchAll();
$stations  = $pdo->query("SELECT s.*,d.name AS division_name FROM stations s JOIN divisions d ON d.id=s.division_id ORDER BY division_name,s.name")->fetchAll();
$posts     = $pdo->query("SELECT p.*,s.name AS station_name FROM posts p JOIN stations s ON s.id=p.station_id ORDER BY station_name,p.name")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>User Accounts</h1><div class="desc">Manage system users within your command</div></div>
</div>
<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<div class="card">
  <div class="chr">
    <h3>Accounts</h3>
    <div class="action-bar">
      <details class="form-panel" id="create-form">
        <summary><button type="button" class="btn-icon bi-primary" title="Create new account"><?= ICO_PLUS ?></button></summary>
        <div class="form-body">
          <h4 style="margin:0 0 14px;color:var(--navy-800)">Create Account</h4>
          <form method="post">
            <input type="hidden" name="action" value="create">
            <div class="form-row">
              <div class="form-group" style="flex:2"><label>Full Name</label><input type="text" name="full_name" required></div>
              <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
              <div class="form-group"><label>Password</label><input type="password" name="password" required minlength="4"></div>
              <div class="form-group"><label>Role</label>
                <select name="role" id="role-sel" onchange="updateScopeFields()">
                  <?php foreach ($creatableRoles as $r): ?>
                    <option value="<?= $r ?>"><?= e(role_label($r)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <?php if (is_superadmin($user)): ?>
            <div class="form-row" id="scope-fields">
              <div class="form-group" id="f-reg"><label>Region</label>
                <select name="region_id"><option value="">— none —</option>
                  <?php foreach ($regions as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" id="f-div"><label>Division</label>
                <select name="division_id"><option value="">— none —</option>
                  <?php foreach ($divisions as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" id="f-sta"><label>Station</label>
                <select name="station_id"><option value="">— none —</option>
                  <?php foreach ($stations as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" id="f-pst"><label>Post</label>
                <select name="post_id"><option value="">— none —</option>
                  <?php foreach ($posts as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
            <?php else: ?>
              <input type="hidden" name="region_id"  value="<?= (int)$user['region_id'] ?>">
              <input type="hidden" name="division_id" value="<?= (int)$user['division_id'] ?>">
              <input type="hidden" name="station_id"  value="<?= (int)$user['station_id'] ?>">
              <input type="hidden" name="post_id"     value="<?= (int)$user['post_id'] ?>">
            <?php endif; ?>
            <div class="action-bar" style="margin-top:10px">
              <button class="btn-icon bi-gold bi-lg" type="submit" title="Create account"><?= ICO_SAVE ?></button>
            </div>
          </form>
        </div>
      </details>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Scope</th><th style="width:80px"></th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><strong><?= e($u['full_name']) ?></strong></td>
          <td><?= e($u['username']) ?></td>
          <td><span class="badge badge-admin"><?= e(role_label($u['role'])) ?></span></td>
          <td class="muted" style="font-size:12px">
            <?= implode(' › ', array_filter([e($u['region_name']??''), e($u['division_name']??''), e($u['station_name']??''), e($u['post_name']??'')])) ?>
          </td>
          <td>
            <div class="action-bar">
              <!-- Reset password -->
              <details class="form-panel">
                <summary><button type="button" class="btn-icon bi-secondary bi-sm" title="Reset password"><?= ICO_KEY ?></button></summary>
                <div class="form-body" style="padding:12px;min-width:220px;position:absolute;z-index:10;right:0">
                  <form method="post" style="display:flex;gap:6px">
                    <input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <input type="password" name="password" placeholder="New password" required minlength="4" style="flex:1;min-width:130px">
                    <button class="btn-icon bi-gold" type="submit" title="Save new password"><?= ICO_SAVE ?></button>
                  </form>
                </div>
              </details>
              <!-- Delete -->
              <form method="post" style="display:contents" onsubmit="return confirm('Remove account <?= e(addslashes($u['full_name'])) ?>?')">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn-icon bi-danger bi-sm" type="submit" title="Delete account"><?= ICO_TRASH ?></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px">No subordinate accounts found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<style>
details.form-panel{position:relative}
details.form-panel .form-body{position:absolute;right:0;top:100%;min-width:280px;z-index:20;box-shadow:var(--shadow-md)}
/* But only for inline (table cell) panels; card-level panels stay block */
.chr .form-panel .form-body, .card > .form-panel .form-body{position:static;box-shadow:none}
</style>
<script>
function updateScopeFields(){
  var r=document.getElementById('role-sel')?.value||'';
  var fields={reg:document.getElementById('f-reg'),div:document.getElementById('f-div'),sta:document.getElementById('f-sta'),pst:document.getElementById('f-pst')};
  if(!fields.reg)return;
  var show={reg:1,div:1,sta:1,pst:1};
  if(r==='superadmin'){show={reg:0,div:0,sta:0,pst:0};}
  else if(r==='regional_commander'){show={reg:1,div:0,sta:0,pst:0};}
  else if(r==='division_commander'){show={reg:1,div:1,sta:0,pst:0};}
  else if(r==='station_commander'){show={reg:1,div:1,sta:1,pst:0};}
  Object.entries(show).forEach(([k,v])=>{ if(fields[k]) fields[k].style.display=v?'':'none'; });
}
document.addEventListener('DOMContentLoaded',updateScopeFields);
document.querySelectorAll('details.form-panel').forEach(d=>{
  d.addEventListener('toggle',()=>{ if(d.open) document.querySelectorAll('details.form-panel').forEach(o=>{ if(o!==d) o.open=false; }); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
