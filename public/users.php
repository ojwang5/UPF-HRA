<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_min_rank('post_commander');
$page = 'users';
$page_title = 'User Accounts';
$pdo = db();

/* ── POST handler ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $uname = trim($_POST['username']  ?? '');
        $fname = trim($_POST['full_name'] ?? '');
        $pw    = $_POST['password'] ?? '';
        $role  = $_POST['role']     ?? 'officer';
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (!in_array($role, creatable_roles($user), true)) {
            flash('err','You cannot assign that role.'); header('Location:/users.php'); exit;
        }
        if ($uname===''||$fname===''||strlen($pw)<4) {
            flash('err','Username, full name, and password (min 4 chars) are required.'); header('Location:/users.php'); exit;
        }

        $rid=$did=$sid=$pid=null;
        $rid = $_POST['region_id']   ? (int)$_POST['region_id']   : null;
        $did = $_POST['division_id'] ? (int)$_POST['division_id'] : null;
        $sid = $_POST['station_id']  ? (int)$_POST['station_id']  : null;
        $pid = $_POST['post_id']     ? (int)$_POST['post_id']     : null;
        if (!is_superadmin($user)) {
            $rid = $rid ?: ($user['region_id']   ? (int)$user['region_id']   : null);
            $did = $did ?: ($user['division_id'] ? (int)$user['division_id'] : null);
            $sid = $sid ?: ($user['station_id']  ? (int)$user['station_id']  : null);
            $pid = $pid ?: ($user['post_id']     ? (int)$user['post_id']     : null);
        }
        try {
            $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role,region_id,division_id,station_id,post_id,email,phone) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$uname, password_hash($pw, PASSWORD_DEFAULT), $fname, $role, $rid, $did, $sid, $pid, $email ?: null, $phone ?: null]);
            log_activity('Add User', 'user', "{$fname} ({$uname})", (int)$pdo->lastInsertId(), "Role: ".role_label($role));
            flash('msg', 'Account created for '.$fname.'.');
        } catch (\PDOException $e) {
            flash('err', str_contains($e->getMessage(),'UNIQUE') ? "Username '{$uname}' is already taken." : 'Could not create: '.$e->getMessage());
        }

    } elseif ($action === 'update') {
        $id    = (int)$_POST['id'];
        $fname = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $t = $pdo->query("SELECT * FROM users WHERE id=$id")->fetch();
        if ($t && can_manage_user($user, $t)) {
            $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?")
                ->execute([$fname ?: $t['full_name'], $email ?: null, $phone ?: null, $id]);
            log_activity('Edit User', 'user', $t['full_name'], $id);
            flash('msg', 'Account updated.');
        }

    } elseif ($action === 'reset') {
        $id = (int)$_POST['id'];
        $pw = $_POST['password'] ?? '';
        $t  = $pdo->query("SELECT * FROM users WHERE id=$id")->fetch();
        if ($t && can_manage_user($user, $t) && strlen($pw) >= 4) {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
            log_activity('Reset Password', 'user', $t['full_name'], $id);
            flash('msg', 'Password reset successfully.');
        } else {
            flash('err', 'Password must be at least 4 characters.');
        }

    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $t  = $pdo->query("SELECT * FROM users WHERE id=$id")->fetch();
        if ($t && can_manage_user($user, $t) && $id !== (int)$user['id']) {
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
            log_activity('Delete User', 'user', $t['full_name'], $id);
            flash('msg', 'Account removed.');
        }
    }

    header('Location:/users.php'); exit;
}

/* ── Fetch ── */
$viewId  = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$users = $pdo->query("SELECT u.*, rg.name AS region_name, dv.name AS division_name, st.name AS station_name, pt.name AS post_name
    FROM users u
    LEFT JOIN regions   rg ON rg.id=u.region_id
    LEFT JOIN divisions dv ON dv.id=u.division_id
    LEFT JOIN stations  st ON st.id=u.station_id
    LEFT JOIN posts     pt ON pt.id=u.post_id
    ORDER BY u.role, u.full_name")->fetchAll();
$users = array_filter($users, fn($u) => $u['id'] !== (int)$user['id'] && can_manage_user($user, $u));

$creatableRoles = creatable_roles($user);
$regions   = $pdo->query("SELECT * FROM regions ORDER BY name")->fetchAll();
$divisions = $pdo->query("SELECT d.*,r.name AS region_name FROM divisions d JOIN regions r ON r.id=d.region_id ORDER BY region_name,d.name")->fetchAll();
$stations  = $pdo->query("SELECT s.*,d.name AS division_name FROM stations s JOIN divisions d ON d.id=s.division_id ORDER BY division_name,s.name")->fetchAll();
$posts     = $pdo->query("SELECT p.*,s.name AS station_name FROM posts p JOIN stations s ON s.id=p.station_id ORDER BY station_name,p.name")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- ═══════════════════ PAGE HEADER ═══════════════════ -->
<div class="page-header">
  <div>
    <h1>User Accounts</h1>
    <div class="desc">Manage system users within your command</div>
  </div>
  <div class="action-bar">
    <!-- ADD USER button → modal -->
    <button class="btn-icon bi-primary" id="btn-open-user-modal"
      style="gap:6px;padding:0 14px;width:auto;font-size:12px;font-weight:600">
      <?= ICO_PLUS ?> <span>Add User</span>
    </button>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<!-- ═══════════════════ USERS TABLE ═══════════════════ -->
<div class="card">
  <div class="chr">
    <h3>Accounts <span class="badge badge-admin"><?= count($users) ?></span></h3>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Full Name</th>
          <th>Username</th>
          <th>Role</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Scope</th>
          <th style="width:100px;text-align:center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr class="<?= $viewId===(int)$u['id']?'row-on_duty':'' ?>">
          <td>
            <strong><?= e($u['full_name']) ?></strong>
          </td>
          <td style="font-family:monospace;font-size:12px"><?= e($u['username']) ?></td>
          <td><span class="badge badge-admin"><?= e(role_label($u['role'])) ?></span></td>
          <td style="font-size:12px"><?= e($u['email'] ?? '—') ?></td>
          <td style="font-size:12px;white-space:nowrap"><?= e($u['phone'] ?? '—') ?></td>
          <td style="font-size:12px;color:var(--muted)">
            <?= implode(' › ', array_filter([e($u['region_name']??''), e($u['division_name']??''), e($u['station_name']??''), e($u['post_name']??'')])) ?: '—' ?>
          </td>
          <td>
            <div style="display:flex;gap:4px;justify-content:center;flex-wrap:nowrap">
              <!-- Edit user contact details -->
              <button class="btn-icon bi-secondary bi-sm" title="Edit details"
                onclick="openEditModal(<?= $u['id'] ?>, '<?= e(addslashes($u['full_name'])) ?>', '<?= e(addslashes($u['email']??'')) ?>', '<?= e(addslashes($u['phone']??'')) ?>')"
              ><?= ICO_EDIT ?></button>

              <!-- Reset password inline panel -->
              <div class="panel-wrap">
                <button class="btn-icon bi-secondary bi-sm" data-panel="panel-pw-<?= $u['id'] ?>" title="Reset password"><?= ICO_KEY ?></button>
                <div class="panel-drop" id="panel-pw-<?= $u['id'] ?>" style="min-width:280px;right:0">
                  <h4><?= ICO_KEY ?> Reset Password</h4>
                  <div style="font-size:12px;color:var(--muted);margin-bottom:10px"><?= e($u['full_name']) ?></div>
                  <form method="post" style="display:flex;gap:8px;align-items:flex-end">
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <div class="form-group" style="flex:1">
                      <label>New Password</label>
                      <input type="password" name="password" placeholder="Min. 4 characters" required minlength="4">
                    </div>
                    <button class="btn-icon bi-gold" type="submit" title="Save"><?= ICO_SAVE ?></button>
                  </form>
                </div>
              </div>

              <!-- Delete -->
              <form method="post" style="display:contents" onsubmit="return confirm('Remove account for <?= e(addslashes($u['full_name'])) ?>?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn-icon bi-danger bi-sm" type="submit" title="Delete"><?= ICO_TRASH ?></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:32px">
          No subordinate accounts found. Click <strong>Add User</strong> to create one.
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══════════════════ ADD USER MODAL DRAWER ═══════════════════ -->
<div class="modal-overlay" id="user-modal">
  <div class="modal-drawer">
    <div class="modal-head">
      <div>
        <h2 id="user-modal-title">Add New User Account</h2>
        <div class="subtitle">Assign login credentials and scope of access</div>
      </div>
      <button class="modal-close" id="btn-close-user-modal" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">

      <!-- CREATE FORM -->
      <div id="form-create">
        <form method="post" id="create-user-form">
          <input type="hidden" name="action" value="create">

          <div class="modal-section">
            <div class="modal-section-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              Identity &amp; Login
            </div>
            <div class="form-row">
              <div class="form-group" style="flex:2;min-width:200px">
                <label>Full Name <span style="color:var(--red)">*</span></label>
                <input type="text" name="full_name" required placeholder="Surname, Other Names">
              </div>
              <div class="form-group" style="min-width:160px">
                <label>Username <span style="color:var(--red)">*</span></label>
                <input type="text" name="username" required placeholder="login_name" style="font-family:monospace">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group" style="min-width:160px">
                <label>Password <span style="color:var(--red)">*</span></label>
                <input type="password" name="password" required minlength="4" placeholder="Min. 4 characters">
              </div>
              <div class="form-group" style="flex:2;min-width:180px">
                <label>Role <span style="color:var(--red)">*</span></label>
                <select name="role" id="role-sel" onchange="updateScopeFields()">
                  <?php foreach ($creatableRoles as $r): ?>
                    <option value="<?= $r ?>"><?= e(role_label($r)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <div class="modal-section">
            <div class="modal-section-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.63 3.38 2 2 0 0 1 3.6 1.2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.88a16 16 0 0 0 6.06 6.06l1.69-1.69a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
              Contact Details
            </div>
            <div class="form-row">
              <div class="form-group" style="flex:2;min-width:200px">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="user@upf.go.ug">
              </div>
              <div class="form-group" style="min-width:160px">
                <label>Phone Number</label>
                <input type="tel" name="phone" placeholder="+256 700 000000">
              </div>
            </div>
          </div>

          <?php if (is_superadmin($user)): ?>
          <div class="modal-section" id="scope-section">
            <div class="modal-section-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              Command Scope
            </div>
            <div class="form-row" id="scope-fields">
              <div class="form-group" id="f-reg">
                <label>Region</label>
                <select name="region_id"><option value="">— none —</option>
                  <?php foreach ($regions as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" id="f-div">
                <label>Division</label>
                <select name="division_id"><option value="">— none —</option>
                  <?php foreach ($divisions as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" id="f-sta">
                <label>Station</label>
                <select name="station_id"><option value="">— none —</option>
                  <?php foreach ($stations as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" id="f-pst">
                <label>Post</label>
                <select name="post_id"><option value="">— none —</option>
                  <?php foreach ($posts as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
          <?php else: ?>
            <input type="hidden" name="region_id"   value="<?= (int)$user['region_id'] ?>">
            <input type="hidden" name="division_id"  value="<?= (int)$user['division_id'] ?>">
            <input type="hidden" name="station_id"   value="<?= (int)$user['station_id'] ?>">
            <input type="hidden" name="post_id"      value="<?= (int)$user['post_id'] ?>">
          <?php endif; ?>

        </form>
      </div>

      <!-- EDIT CONTACT FORM -->
      <div id="form-edit" style="display:none">
        <form method="post" id="edit-user-form">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" id="edit-uid">
          <div class="modal-section">
            <div class="modal-section-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
              Edit User Details — <span id="edit-uname" style="color:var(--primary)"></span>
            </div>
            <div class="form-row">
              <div class="form-group" style="flex:2">
                <label>Full Name</label>
                <input type="text" name="full_name" id="edit-fname">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group" style="flex:2;min-width:200px">
                <label>Email Address</label>
                <input type="email" name="email" id="edit-email" placeholder="user@upf.go.ug">
              </div>
              <div class="form-group" style="min-width:160px">
                <label>Phone Number</label>
                <input type="tel" name="phone" id="edit-phone" placeholder="+256 700 000000">
              </div>
            </div>
            <div style="font-size:12px;color:var(--muted);background:var(--navy-50);padding:8px 12px;border-radius:7px;margin-top:4px">
              To change username, role, or scope — delete and recreate the account.
            </div>
          </div>
        </form>
      </div>

    </div>
    <div class="modal-foot">
      <button class="btn-icon bi-gold bi-lg" id="modal-submit-btn" type="submit" form="create-user-form" style="gap:8px;padding:0 20px;width:auto;font-size:13px;font-weight:600">
        <?= ICO_SAVE ?> <span>Create Account</span>
      </button>
      <button class="btn-icon bi-secondary bi-lg" id="btn-close-user-modal-foot" type="button" style="gap:8px;padding:0 16px;width:auto;font-size:13px">
        <?= ICO_CANCEL ?> <span>Cancel</span>
      </button>
    </div>
  </div>
</div>

<script>
(function(){
  var modal     = document.getElementById('user-modal');
  var openBtn   = document.getElementById('btn-open-user-modal');
  var closeBtns = [document.getElementById('btn-close-user-modal'), document.getElementById('btn-close-user-modal-foot')];
  var formCreate = document.getElementById('form-create');
  var formEdit   = document.getElementById('form-edit');
  var submitBtn  = document.getElementById('modal-submit-btn');
  var titleEl    = document.getElementById('user-modal-title');

  function openModal(){ modal.classList.add('open'); document.body.style.overflow='hidden'; }
  function closeModal(){ modal.classList.remove('open'); document.body.style.overflow=''; showCreateForm(); }

  function showCreateForm(){
    formCreate.style.display = '';
    formEdit.style.display   = 'none';
    titleEl.textContent = 'Add New User Account';
    submitBtn.setAttribute('form','create-user-form');
    submitBtn.querySelector('span').textContent = 'Create Account';
  }

  function showEditForm(){
    formCreate.style.display = 'none';
    formEdit.style.display   = '';
    titleEl.textContent = 'Edit User Details';
    submitBtn.setAttribute('form','edit-user-form');
    submitBtn.querySelector('span').textContent = 'Save Changes';
  }

  openBtn.addEventListener('click', function(){ showCreateForm(); openModal(); });
  closeBtns.forEach(function(b){ if(b) b.addEventListener('click', closeModal); });
  modal.addEventListener('click', function(e){ if(e.target===modal) closeModal(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModal(); });

  // Role → scope fields visibility
  function updateScopeFields(){
    var r = document.getElementById('role-sel')?.value || '';
    var f = {
      reg: document.getElementById('f-reg'),
      div: document.getElementById('f-div'),
      sta: document.getElementById('f-sta'),
      pst: document.getElementById('f-pst'),
    };
    var show = {reg:1,div:1,sta:1,pst:1};
    if(r==='superadmin')          show={reg:0,div:0,sta:0,pst:0};
    else if(r==='regional_commander') show={reg:1,div:0,sta:0,pst:0};
    else if(r==='division_commander') show={reg:1,div:1,sta:0,pst:0};
    else if(r==='station_commander')  show={reg:1,div:1,sta:1,pst:0};
    Object.entries(show).forEach(function([k,v]){ if(f[k]) f[k].style.display=v?'':'none'; });
  }
  window.updateScopeFields = updateScopeFields;
  updateScopeFields();

  // Open edit modal for a specific user
  window.openEditModal = function(id, name, email, phone){
    document.getElementById('edit-uid').value   = id;
    document.getElementById('edit-uname').textContent = name;
    document.getElementById('edit-fname').value = name;
    document.getElementById('edit-email').value = email;
    document.getElementById('edit-phone').value = phone;
    showEditForm();
    openModal();
  };
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
