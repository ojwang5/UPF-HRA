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
        // Resolve scope from POST
        $rid=$did=$sid=$pid=null;
        $rid = $_POST['region_id']   ? (int)$_POST['region_id']   : null;
        $did = $_POST['division_id'] ? (int)$_POST['division_id'] : null;
        $sid = $_POST['station_id']  ? (int)$_POST['station_id']  : null;
        $pid = $_POST['post_id']     ? (int)$_POST['post_id']     : null;
        // Lock scope to actor's scope for non-superadmins
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
        $t=$pdo->prepare("SELECT * FROM users WHERE id=?")->execute([$id]) ? $pdo->query("SELECT * FROM users WHERE id=$id")->fetch() : null;
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

// List users in scope
$where = '1=1'; $params = [];
if (!is_superadmin($user)) {
    $where = match($user['role']) {
        'regional_commander' => 'u.region_id=?',
        'division_commander' => 'u.division_id=?',
        'station_commander'  => 'u.station_id=?',
        default              => 'u.post_id=?',
    };
    $params[] = match($user['role']) {
        'regional_commander' => $user['region_id'],
        'division_commander' => $user['division_id'],
        'station_commander'  => $user['station_id'],
        default              => $user['post_id'],
    };
    $where .= " AND role_rank_val < ".role_rank($user['role'])." -- filtered in PHP";
}
$users = $pdo->query("
    SELECT u.*, rg.name AS region_name, dv.name AS division_name, st.name AS station_name, pt.name AS post_name
    FROM users u
    LEFT JOIN regions   rg ON rg.id=u.region_id
    LEFT JOIN divisions dv ON dv.id=u.division_id
    LEFT JOIN stations  st ON st.id=u.station_id
    LEFT JOIN posts     pt ON pt.id=u.post_id
    ORDER BY u.role, u.full_name
")->fetchAll();
// Filter in PHP
$users = array_filter($users, fn($u) => $u['id']!==$user['id'] && can_manage_user($user, $u));

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
  <h3>Create Account</h3>
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
      <div class="form-group"><label>Region</label>
        <select name="region_id" id="reg-sel"><option value="">— none —</option>
          <?php foreach ($regions as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Division</label>
        <select name="division_id" id="div-sel"><option value="">— none —</option>
          <?php foreach ($divisions as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Station</label>
        <select name="station_id" id="sta-sel"><option value="">— none —</option>
          <?php foreach ($stations as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Post</label>
        <select name="post_id" id="pst-sel"><option value="">— none —</option>
          <?php foreach ($posts as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php else: ?>
      <input type="hidden" name="region_id"   value="<?= (int)$user['region_id'] ?>">
      <input type="hidden" name="division_id"  value="<?= (int)$user['division_id'] ?>">
      <input type="hidden" name="station_id"   value="<?= (int)$user['station_id'] ?>">
      <input type="hidden" name="post_id"      value="<?= (int)$user['post_id'] ?>">
    <?php endif; ?>
    <button class="btn" style="margin-top:8px">Create Account</button>
  </form>
</div>

<div class="card">
  <h3>Accounts Under Your Command</h3>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Scope</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['full_name']) ?></td>
          <td><?= e($u['username']) ?></td>
          <td><span class="badge badge-admin"><?= e(role_label($u['role'])) ?></span></td>
          <td class="muted" style="font-size:12px">
            <?= implode(' › ', array_filter([e($u['region_name']??''), e($u['division_name']??''), e($u['station_name']??''), e($u['post_name']??'')])) ?>
          </td>
          <td style="white-space:nowrap">
            <details><summary class="btn btn-sm btn-secondary" style="display:inline">Reset pw</summary>
              <form method="post" style="margin-top:6px;display:flex;gap:6px">
                <input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <input type="password" name="password" placeholder="New password" required minlength="4">
                <button class="btn btn-sm">Save</button>
              </form>
            </details>
            <form method="post" style="display:inline" onsubmit="return confirm('Remove this account?')">
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>">
              <button class="btn btn-sm btn-danger">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px">No subordinate accounts found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
function updateScopeFields(){
  var r=document.getElementById('role-sel')?.value||'';
  var sf=document.getElementById('scope-fields');
  if(!sf) return;
  var showReg=1,showDiv=1,showSta=1,showPst=1;
  if(r==='superadmin'){showReg=showDiv=showSta=showPst=0;}
  else if(r==='regional_commander'){showDiv=showSta=showPst=0;}
  else if(r==='division_commander'){showSta=showPst=0;}
  else if(r==='station_commander'){showPst=0;}
  document.getElementById('reg-sel').closest('.form-group').style.display=showReg?'':'none';
  document.getElementById('div-sel').closest('.form-group').style.display=showDiv?'':'none';
  document.getElementById('sta-sel').closest('.form-group').style.display=showSta?'':'none';
  document.getElementById('pst-sel').closest('.form-group').style.display=showPst?'':'none';
}
document.addEventListener('DOMContentLoaded',updateScopeFields);
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
