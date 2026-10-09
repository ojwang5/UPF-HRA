<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_login();
$pdo  = db();

$type = $_GET['type'] ?? '';  // directorate | unit
$id   = (int)($_GET['id']  ?? 0);

if (!in_array($type, ['directorate','unit']) || !$id) {
    header('Location:/hierarchy.php'); exit;
}

/* ══════ Create User — POST handler ══════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'create_user') {
    if (!is_commander($user)) { flash('err','Insufficient permissions.'); header("Location:/structure-view.php?type={$type}&id={$id}"); exit; }

    $uname = trim($_POST['username']  ?? '');
    $fname = trim($_POST['full_name'] ?? '');
    $pw    = $_POST['password'] ?? '';
    $role  = $_POST['role']     ?? 'officer';
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $dirId  = isset($_POST['directorate_id']) && $_POST['directorate_id'] !== '' ? (int)$_POST['directorate_id'] : null;
    $unitId = isset($_POST['unit_id']) && $_POST['unit_id'] !== '' ? (int)$_POST['unit_id'] : null;

    if (!in_array($role, creatable_roles($user), true)) {
        flash('err','You cannot assign that role.');
    } elseif ($uname===''||$fname===''||strlen($pw)<4) {
        flash('err','Username, full name, and password (min 4 chars) are required.');
    } else {
        $rid = $_POST['region_id']   ? (int)$_POST['region_id']   : null;
        $did = $_POST['division_id'] ? (int)$_POST['division_id'] : null;
        $sid = $_POST['station_id']  ? (int)$_POST['station_id']  : null;
        $pid = $_POST['post_id']     ? (int)$_POST['post_id']     : null;
        $functional = in_array($role, ['directorate_commander','unit_commander'], true);
        if ($functional) {
            // Anchor a functional account to the directorate (or unit being viewed).
            $viewDirId = ($type === 'directorate') ? (int)$entity['id'] : (int)($entity['directorate_id'] ?? 0);
            $dirId = $dirId ?: ($viewDirId ?: null);
            if ($role === 'unit_commander' && $type === 'unit' && !$unitId) $unitId = (int)$entity['id'];
            if ($unitId) {
                $ownDir = (int)$pdo->query("SELECT directorate_id FROM units WHERE id=$unitId")->fetchColumn();
                if ((int)$ownDir !== (int)$dirId) $unitId = null;
            }
            if (!$dirId || ($role === 'unit_commander' && !$unitId)) {
                flash('err','Functional command accounts need a directorate and (for unit commanders) a unit.');
                header("Location:/structure-view.php?type={$type}&id={$id}"); exit;
            }
        }
        if (!is_superadmin($user)) {
            $rid = $rid ?: ($user['region_id']   ? (int)$user['region_id']   : null);
            $did = $did ?: ($user['division_id'] ? (int)$user['division_id'] : null);
            $sid = $sid ?: ($user['station_id']  ? (int)$user['station_id']  : null);
            $pid = $pid ?: ($user['post_id']     ? (int)$user['post_id']     : null);
        }
        try {
            $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role,region_id,division_id,station_id,post_id,directorate_id,unit_id,email,phone) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$uname, password_hash($pw, PASSWORD_DEFAULT), $fname, $role, $rid, $did, $sid, $pid, $dirId, $unitId, $email ?: null, $phone ?: null]);
            log_activity('Add User', 'user', "{$fname} ({$uname})", (int)$pdo->lastInsertId(), "Created from {$type} view");
            flash('msg', "Account created for {$fname}.");
        } catch (\PDOException $e) {
            flash('err', str_contains($e->getMessage(),'UNIQUE') ? "Username '{$uname}' is already taken." : 'Could not create: '.$e->getMessage());
        }
    }
    header("Location:/structure-view.php?type={$type}&id={$id}"); exit;
}

/* ══════ Load entity ══════ */
if ($type === 'directorate') {
    $entity = $pdo->prepare("SELECT d.*, (SELECT COUNT(*) FROM units u WHERE u.directorate_id=d.id) AS unit_count, (SELECT COUNT(*) FROM employees e WHERE e.directorate=d.name AND e.active=1) AS personnel_count FROM directorates d WHERE d.id=?")->execute([$id]) ? null : null;
    $stmt = $pdo->prepare("SELECT d.*, (SELECT COUNT(*) FROM units u WHERE u.directorate_id=d.id) AS unit_count, (SELECT COUNT(*) FROM employees e WHERE e.directorate=d.name AND e.active=1) AS personnel_count FROM directorates d WHERE d.id=?");
    $stmt->execute([$id]); $entity = $stmt->fetch();
    if (!$entity) { header('Location:/hierarchy.php?tab=directorates'); exit; }

    $subUnits = $pdo->prepare("SELECT u.*, (SELECT COUNT(*) FROM employees e WHERE e.unit=u.name AND e.active=1) AS personnel_count FROM units u WHERE u.directorate_id=? ORDER BY u.name");
    $subUnits->execute([$id]); $subUnits = $subUnits->fetchAll();

    $personnel = $pdo->prepare("SELECT e.*, p.name AS post_name, s.name AS sta_name, d2.name AS div_name, r.name AS reg_name FROM employees e LEFT JOIN posts p ON p.id=e.post_id LEFT JOIN stations s ON s.id=e.station_id LEFT JOIN divisions d2 ON d2.id=e.division_id LEFT JOIN regions r ON r.id=e.region_id WHERE e.directorate=? AND e.active=1 ORDER BY ".\rank_order_sql('e.rank').", e.full_name");
    $personnel->execute([$entity['name']]); $personnel = $personnel->fetchAll();

    $backLabel = 'Back to Directorates';
    $backUrl   = '/hierarchy.php?tab=directorates';
    $entityLabel = 'Directorate';
} else {
    $stmt = $pdo->prepare("SELECT u.*, d.name AS dir_name, (SELECT COUNT(*) FROM employees e WHERE e.unit=u.name AND e.active=1) AS personnel_count FROM units u JOIN directorates d ON d.id=u.directorate_id WHERE u.id=?");
    $stmt->execute([$id]); $entity = $stmt->fetch();
    if (!$entity) { header('Location:/hierarchy.php?tab=directorates'); exit; }

    $subUnits  = [];
    $personnel = $pdo->prepare("SELECT e.*, p.name AS post_name, s.name AS sta_name, d2.name AS div_name, r.name AS reg_name FROM employees e LEFT JOIN posts p ON p.id=e.post_id LEFT JOIN stations s ON s.id=e.station_id LEFT JOIN divisions d2 ON d2.id=e.division_id LEFT JOIN regions r ON r.id=e.region_id WHERE e.unit=? AND e.active=1 ORDER BY ".\rank_order_sql('e.rank').", e.full_name");
    $personnel->execute([$entity['name']]); $personnel = $personnel->fetchAll();

    $backLabel = 'Back to Structure';
    $backUrl   = '/hierarchy.php?tab=directorates';
    $entityLabel = 'Unit';
}

// User accounts linked geographically (by region/division/station/post scope)
$existingUsers = $pdo->query("SELECT u.id,u.username,u.full_name,u.role,u.email FROM users u ORDER BY u.full_name")->fetchAll();

// Reference data for user creation form
$regions   = $pdo->query("SELECT * FROM regions ORDER BY name")->fetchAll();
$divisions = $pdo->query("SELECT d.*,r.name AS rname FROM divisions d JOIN regions r ON r.id=d.region_id ORDER BY rname,d.name")->fetchAll();
$stations  = $pdo->query("SELECT s.*,d.name AS dname FROM stations s JOIN divisions d ON d.id=s.division_id ORDER BY dname,s.name")->fetchAll();
$posts     = $pdo->query("SELECT p.*,s.name AS sname FROM posts p JOIN stations s ON s.id=p.station_id ORDER BY sname,p.name")->fetchAll();
$creatableRoles = creatable_roles($user);
$directorates = $pdo->query("SELECT * FROM directorates WHERE active=1 ORDER BY name")->fetchAll();
$units        = $pdo->query("SELECT u.id, u.directorate_id, u.name, d.name AS dir_name FROM units u JOIN directorates d ON d.id=u.directorate_id WHERE u.active=1 ORDER BY d.name, u.name")->fetchAll();

// Gender breakdown
$male   = count(array_filter($personnel, fn($e)=>$e['gender']==='M'));
$female = count(array_filter($personnel, fn($e)=>$e['gender']==='F'));

$page_title = ($type==='directorate'?'Directorate':'Unit').': '.$entity['name'];
include __DIR__ . '/../includes/header.php';
?>

<style>
.px { display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;border:none;cursor:pointer;text-decoration:none;line-height:1.4;white-space:nowrap;transition:opacity .15s }
.px:hover { opacity:.82 }
.px .ico { width:12px;height:12px }
.px-view { background:#dbeafe;color:#1d4ed8 }
.px-user { background:#f3e8ff;color:#6d28d9 }
dialog { border:none;border-radius:12px;padding:0;box-shadow:0 20px 60px rgba(0,0,0,.25);max-width:540px;width:95vw }
dialog::backdrop { background:rgba(0,0,0,.45) }
.dlg-head { background:var(--navy-800);color:#fff;padding:16px 20px;border-radius:12px 12px 0 0;display:flex;align-items:center;justify-content:space-between }
.dlg-head h4 { margin:0;font-size:14px }
.dlg-body { padding:20px;display:flex;flex-direction:column;gap:12px }
.dlg-foot { padding:12px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;background:var(--navy-50);border-radius:0 0 12px 12px }
.dlg-close { background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1 }
.stat-box { background:var(--navy-50);border-radius:10px;padding:14px 18px;text-align:center }
.stat-box .n { font-size:28px;font-weight:800;color:var(--navy-800);line-height:1 }
.stat-box .l { font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-top:4px }
</style>

<!-- Breadcrumb -->
<div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted);margin-bottom:12px">
  <a href="/hierarchy.php" style="color:var(--primary);text-decoration:none">Structure</a>
  <span>›</span>
  <a href="<?= e($backUrl) ?>" style="color:var(--primary);text-decoration:none"><?= e($entityLabel).'s' ?></a>
  <span>›</span>
  <span style="color:var(--text);font-weight:600"><?= e($entity['name']) ?></span>
</div>

<div class="page-header" style="margin-bottom:16px">
  <div>
    <h1><?= e($entity['name']) ?></h1>
    <div class="desc"><?= $entityLabel ?> · Code: <strong><?= e($entity['code']) ?></strong><?php if (!empty($entity['description'])): ?> · <?= e($entity['description']) ?><?php endif; ?></div>
  </div>
  <div class="action-bar">
    <?php if (is_commander($user)): ?>
    <button class="btn-icon bi-primary" onclick="document.getElementById('dlg-create-user').showModal()"
      style="gap:6px;padding:0 14px;width:auto;font-size:12px;font-weight:600">
      <?= ICO_PLUS ?> Create User Account
    </button>
    <?php endif; ?>
    <a href="<?= e($backUrl) ?>" class="btn-icon bi-secondary" style="gap:6px;padding:0 14px;width:auto;font-size:12px">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      <?= e($backLabel) ?>
    </a>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<!-- Summary Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;margin-bottom:20px">
  <div class="stat-box"><div class="n"><?= count($personnel) ?></div><div class="l">Total Personnel</div></div>
  <div class="stat-box"><div class="n"><?= $male ?></div><div class="l">Male</div></div>
  <div class="stat-box"><div class="n"><?= $female ?></div><div class="l">Female</div></div>
  <?php if ($type==='directorate'): ?>
  <div class="stat-box"><div class="n"><?= count($subUnits) ?></div><div class="l">Sub-units</div></div>
  <?php endif; ?>
</div>

<?php if ($type==='directorate' && $subUnits): ?>
<!-- Sub-units table -->
<div class="card" style="margin-bottom:18px">
  <div class="chr"><h3>Units within <?= e($entity['name']) ?></h3></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Unit Name</th><th>Code</th><th>Description</th><th style="text-align:center">Personnel</th><th style="text-align:right">Action</th></tr></thead>
    <tbody>
      <?php foreach ($subUnits as $u): ?>
      <tr>
        <td><strong><?= e($u['name']) ?></strong></td>
        <td style="font-family:monospace;font-size:12px"><?= e($u['code']) ?></td>
        <td class="muted" style="font-size:12px"><?= e($u['description']?:'—') ?></td>
        <td style="text-align:center">
          <span style="background:<?= $u['personnel_count']>0?'#dcfce7':'var(--navy-50)' ?>;color:<?= $u['personnel_count']>0?'#166534':'var(--muted)' ?>;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700">
            <?= $u['personnel_count'] ?>
          </span>
        </td>
        <td style="text-align:right">
          <a class="px px-view" href="/structure-view.php?type=unit&id=<?= $u['id'] ?>"><?= ICO_EYE ?> View</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<!-- Personnel List -->
<div class="card">
  <div class="chr">
    <h3>Personnel Attached <span class="badge badge-admin"><?= count($personnel) ?></span></h3>
    <div style="font-size:12px;color:var(--muted)"><?= $male ?>M / <?= $female ?>F</div>
  </div>
  <?php if (!$personnel): ?>
  <div style="text-align:center;padding:36px;color:var(--muted)">
    No active personnel attached to this <?= strtolower($entityLabel) ?>.
  </div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr>
      <th>#</th><th>Rank</th><th>Full Name</th><th>Service No</th><th>Gender</th>
      <th>Unit</th><th>Post / Station</th><th style="text-align:right">Action</th>
    </tr></thead>
    <tbody>
      <?php foreach ($personnel as $i=>$e): ?>
      <tr>
        <td class="muted" style="font-size:11px"><?= $i+1 ?></td>
        <td><span class="badge badge-admin" style="font-size:10px;padding:2px 7px"><?= e($e['rank']) ?></span></td>
        <td><strong><?= e($e['full_name']) ?></strong></td>
        <td style="font-family:monospace;font-size:12px;color:var(--muted)"><?= e($e['service_no']) ?></td>
        <td style="font-size:12px"><?= $e['gender']==='F'?'Female':'Male' ?></td>
        <td style="font-size:12px;color:var(--muted)"><?= e($e['unit']?:'—') ?></td>
        <td style="font-size:12px;color:var(--muted)"><?= e(implode(' / ', array_filter([$e['post_name']??null, $e['sta_name']??null]))) ?></td>
        <td style="text-align:right">
          <a class="px px-view" href="/employees.php?search=<?= urlencode($e['service_no']) ?>"><?= ICO_EYE ?> Profile</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if (is_commander($user)): ?>
<!-- ══════ Create User Account Dialog ══════ -->
<dialog id="dlg-create-user">
  <div class="dlg-head">
    <h4><?= ICO_KEY ?> Create User Account — <?= e($entity['name']) ?></h4>
    <button class="dlg-close" onclick="this.closest('dialog').close()">×</button>
  </div>
  <form method="post">
    <div class="dlg-body">
      <input type="hidden" name="action" value="create_user">
      <div style="background:#fef3c7;border-radius:8px;padding:10px 14px;font-size:12px;color:#92400e">
        Creating a login account for a staff member of <strong><?= e($entity['name']) ?></strong>. Set their geographic scope below.
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label>Username <span style="color:var(--red)">*</span></label><input type="text" name="username" required autocomplete="off"></div>
        <div class="form-group"><label>Password <span style="color:var(--red)">*</span></label><input type="password" name="password" required minlength="4" autocomplete="new-password"></div>
      </div>
      <div class="form-group"><label>Full Name <span style="color:var(--red)">*</span></label><input type="text" name="full_name" required></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label>Email</label><input type="email" name="email"></div>
        <div class="form-group"><label>Phone</label><input type="tel" name="phone"></div>
      </div>
      <div class="form-group"><label>Role <span style="color:var(--red)">*</span></label>
        <select name="role" required id="sv-role-sel" onchange="svToggleScope()">
          <?php foreach ($creatableRoles as $r): ?><option value="<?= $r ?>"><?= role_label($r) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div id="sv-func-scope" style="display:none">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);margin-top:4px">Functional Command Scope</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group"><label>Directorate</label>
            <select name="directorate_id" id="sv-dir-sel">
              <?php foreach ($directorates as $d): ?><option value="<?= $d['id'] ?>" <?= ($type==='directorate' && (int)$d['id']===(int)$entity['id']) ? 'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" id="sv-unit-fld" style="display:none"><label>Unit</label>
            <select name="unit_id" id="sv-unit-sel">
              <option value="">— select unit —</option>
              <?php foreach ($units as $u): ?><option value="<?= $u['id'] ?>" data-dir="<?= (int)$u['directorate_id'] ?>" <?= ($type==='unit' && (int)$u['id']===(int)$entity['id']) ? 'selected':'' ?>><?= e($u['dir_name'].' › '.$u['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <script>
        function svToggleScope(){
          var r = document.getElementById('sv-role-sel')?.value || '';
          var f = document.getElementById('sv-func-scope');
          var u = document.getElementById('sv-unit-fld');
          if(f) f.style.display = (r==='directorate_commander' || r==='unit_commander') ? '' : 'none';
          if(u) u.style.display  = (r==='unit_commander') ? '' : 'none';
          if(r!=='unit_commander'){ var s=document.getElementById('sv-unit-sel'); if(s) s.value=''; }
        }
        var svDir = document.getElementById('sv-dir-sel');
        if(svDir) svDir.addEventListener('change', function(){
          var d = svDir.value;
          var s = document.getElementById('sv-unit-sel');
          if(!s) return;
          var first = [];
          Array.from(s.options).forEach(function(o){
            var show = o.value==='' || o.dataset.dir === d;
            o.style.display = show ? '' : 'none';
            if(show && o.value) first.push(o.value);
          });
          s.value = (s.value && Array.from(s.options).some(function(o){return o.value===s.value && o.dataset.dir===d;})) ? s.value : (first[0] || '');
        });
        svToggleScope();
      </script>
      <?php if (is_superadmin($user)): ?>
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted)">Geographic Scope</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label>Region</label>
          <select name="region_id"><option value="">Any</option>
            <?php foreach ($regions as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Division</label>
          <select name="division_id"><option value="">Any</option>
            <?php foreach ($divisions as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['rname'].' › '.$d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Station</label>
          <select name="station_id"><option value="">Any</option>
            <?php foreach ($stations as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['dname'].' › '.$s['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Post</label>
          <select name="post_id"><option value="">Any</option>
            <?php foreach ($posts as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['sname'].' › '.$p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php else: ?>
        <input type="hidden" name="region_id"   value="<?= (int)$user['region_id'] ?>">
        <input type="hidden" name="division_id" value="<?= (int)$user['division_id'] ?>">
        <input type="hidden" name="station_id"  value="<?= (int)$user['station_id'] ?>">
        <input type="hidden" name="post_id"     value="<?= (int)$user['post_id'] ?>">
      <?php endif; ?>
    </div>
    <div class="dlg-foot">
      <button type="button" class="btn-icon bi-secondary" onclick="this.closest('dialog').close()" style="width:auto;padding:0 14px;font-size:12px"><?= ICO_CANCEL ?> Cancel</button>
      <button type="submit" class="btn-icon bi-primary bi-lg" style="width:auto;padding:0 16px;font-size:12px"><?= ICO_SAVE ?> Create Account</button>
    </div>
  </form>
</dialog>

<script>
document.querySelectorAll('dialog').forEach(d=>{
  d.addEventListener('click', e=>{ if(e.target===d) d.close(); });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
