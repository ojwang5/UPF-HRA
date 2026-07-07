<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_login();
$page = 'employees';
$page_title = 'Personnel';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

/* ── POST handler ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['create','update'], true)) {
        $sno   = trim($_POST['service_no'] ?? '');
        $name  = trim($_POST['full_name']  ?? '');
        $rank  = trim($_POST['rank']       ?? '');
        $gender= $_POST['gender'] ?? 'M';
        $phone = trim($_POST['phone']      ?? '');
        $email = trim($_POST['email']      ?? '');
        $dir   = trim($_POST['directorate']?? '');
        $unit  = trim($_POST['unit']       ?? '');

        $post_id = null;
        if (role_rank($user['role']) >= role_rank('division_commander')) {
            $post_id = (int)($_POST['post_id'] ?? 0) ?: null;
        } else {
            $post_id = (int)$user['post_id'] ?: null;
        }
        if (!$post_id) { flash('err','A post assignment is required.'); header('Location:/employees.php'); exit; }

        $chain = $pdo->prepare("SELECT s.division_id, d.region_id, s.id AS station_id FROM posts p JOIN stations s ON s.id=p.station_id JOIN divisions d ON d.id=s.division_id WHERE p.id=?");
        $chain->execute([$post_id]); $ch = $chain->fetch();
        if (!$ch) { flash('err','Invalid post selected.'); header('Location:/employees.php'); exit; }

        try {
            if ($action === 'create') {
                $pdo->prepare("INSERT INTO employees (service_no,full_name,gender,rank,directorate,unit,region_id,division_id,station_id,post_id,email,phone) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$sno,$name,$gender,$rank,$dir,$unit,$ch['region_id'],$ch['division_id'],$ch['station_id'],$post_id,$email,$phone]);
                flash('msg','Personnel record created.');
            } else {
                $id = (int)$_POST['id'];
                $pdo->prepare("UPDATE employees SET service_no=?,full_name=?,gender=?,rank=?,directorate=?,unit=?,region_id=?,division_id=?,station_id=?,post_id=?,email=?,phone=? WHERE id=?")
                    ->execute([$sno,$name,$gender,$rank,$dir,$unit,$ch['region_id'],$ch['division_id'],$ch['station_id'],$post_id,$email,$phone,$id]);
                flash('msg','Record updated successfully.');
            }
        } catch (\PDOException $ex) {
            $msg = (str_contains($ex->getMessage(),'UNIQUE') && str_contains($ex->getMessage(),'service_no'))
                ? "Force/File number '{$sno}' is already in use. Choose a different number."
                : 'Could not save: '.$ex->getMessage();
            flash('err', $msg);
        }

    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM employees WHERE id=? AND $scopeW")->execute(array_merge([$id], $scopeP));
        flash('msg','Personnel record removed.');
    }

    header('Location:/employees.php' . ($editId??null ? '?edit='.(int)($_POST['id']??0) : '')); exit;
}

/* ── Fetch ── */
$search = trim($_GET['q'] ?? '');
$where  = "e.active=1 AND $scopeW";
$params = $scopeP;
if ($search !== '') {
    $where .= ' AND (e.full_name LIKE ? OR e.service_no LIKE ? OR e.rank LIKE ? OR e.directorate LIKE ? OR e.unit LIKE ?)';
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s, $s);
}

$baseSelect = "SELECT e.id, e.service_no, e.full_name, e.gender, e.rank, e.directorate, e.unit,
                      e.email, e.phone,
                      rg.name AS region_name, dv.name AS division_name,
                      st.name AS station_name, pt.name AS post_name,
                      e.region_id, e.division_id, e.station_id, e.post_id
               FROM employees e
               LEFT JOIN regions   rg ON rg.id=e.region_id
               LEFT JOIN divisions dv ON dv.id=e.division_id
               LEFT JOIN stations  st ON st.id=e.station_id
               LEFT JOIN posts     pt ON pt.id=e.post_id
               WHERE $where
               ORDER BY rg.name, dv.name, st.name, pt.name, e.full_name";

$stmt = $pdo->prepare($baseSelect);
$stmt->execute($params);
$employees = $stmt->fetchAll();

/* ── Edit mode ── */
$editId  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = null;
if ($editId) {
    $s = $pdo->prepare("SELECT e.* FROM employees e WHERE e.id=? AND $scopeW");
    $s->execute(array_merge([$editId], $scopeP));
    $editing = $s->fetch() ?: null;
}

/* ── View detail ── */
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewing = null;
if ($viewId) {
    $s = $pdo->prepare("SELECT e.*, rg.name AS region_name, dv.name AS division_name, st.name AS station_name, pt.name AS post_name FROM employees e LEFT JOIN regions rg ON rg.id=e.region_id LEFT JOIN divisions dv ON dv.id=e.division_id LEFT JOIN stations st ON st.id=e.station_id LEFT JOIN posts pt ON pt.id=e.post_id WHERE e.id=? AND $scopeW");
    $s->execute(array_merge([$viewId], $scopeP));
    $viewing = $s->fetch() ?: null;
}

/* ── Posts list ── */
$canPickPost = role_rank($user['role']) >= role_rank('division_commander');
$posts = [];
if ($canPickPost) {
    $psql = "SELECT p.id, p.name, s.name AS sta, d.name AS div, rg.name AS reg FROM posts p JOIN stations s ON s.id=p.station_id JOIN divisions d ON d.id=s.division_id JOIN regions rg ON rg.id=d.region_id";
    if (!is_superadmin($user)) {
        $psql .= " WHERE " . match($user['role']) {
            'regional_commander' => "d.region_id=".(int)$user['region_id'],
            'division_commander' => "s.division_id=".(int)$user['division_id'],
            'station_commander'  => "p.station_id=".(int)$user['station_id'],
            default              => "p.id=".(int)$user['post_id'],
        };
    }
    $posts = $pdo->query($psql." ORDER BY reg,div,sta,p.name")->fetchAll();
}

$directorates = ['Operations','Criminal Investigations','Special Branch','Traffic','Fire Brigade','Marine','Administration','Finance','Human Resource','Training','Logistics','Media','Legal','ICT','Other'];
$units = ['General Duty','Flying Squad','Anti-Stock Theft','Anti-Terrorism','Border Security','K9 Unit','Rapid Response','VIP Protection','Community Policing','Other'];

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>Personnel</h1>
    <div class="desc"><?= is_superadmin($user) ? 'All force personnel — '.count($employees).' record(s)' : e(user_scope_label($user)).' — '.count($employees).' record(s)' ?></div>
  </div>
  <div class="action-bar">
    <!-- SEARCH -->
    <details class="form-panel" id="search-panel" <?= $search ? 'open' : '' ?>>
      <summary><button type="button" class="btn-icon bi-secondary" title="Search personnel"><?= ICO_SEARCH ?></button></summary>
      <div class="form-body" style="padding:14px 16px">
        <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
          <div class="form-group"><label>Search</label><input type="text" name="q" value="<?= e($search) ?>" autofocus placeholder="Name, file no, rank, directorate…" style="min-width:220px"></div>
          <button class="btn-icon bi-primary" type="submit" title="Search"><?= ICO_SEARCH ?></button>
          <?php if ($search): ?><a class="btn-icon bi-secondary" href="/employees.php" title="Clear"><?= ICO_CANCEL ?></a><?php endif; ?>
        </form>
      </div>
    </details>
    <!-- EXPORT -->
    <details class="form-panel" id="export-panel">
      <summary><button type="button" class="btn-icon bi-secondary" title="Export personnel"><?= ICO_DL ?></button></summary>
      <div class="form-body" style="min-width:340px">
        <h4 style="margin:0 0 12px">Export Personnel</h4>
        <form method="post" action="/export-personnel.php" target="_blank">
          <input type="hidden" name="q" value="<?= e($search) ?>">
          <?php foreach ($scopeP as $i => $v): ?><input type="hidden" name="scope[<?= $i ?>]" value="<?= e($v) ?>"><?php endforeach; ?>
          <div class="form-group" style="margin-bottom:12px"><label>Format</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px">
              <?php foreach (['csv'=>'CSV','excel'=>'Excel','pdf'=>'Print/PDF'] as $k=>$lbl): ?>
              <label style="display:flex;align-items:center;gap:5px;font-size:13px;text-transform:none;letter-spacing:0;font-weight:500">
                <input type="radio" name="format" value="<?= $k ?>" <?= $k==='csv'?'checked':'' ?>> <?= $lbl ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:12px"><label>Columns to export</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;margin-top:6px">
              <?php
              $allCols = [
                'service_no'=>'File/Force No','rank'=>'Rank','full_name'=>'Full Name',
                'gender'=>'Gender','directorate'=>'Directorate','unit'=>'Unit',
                'region_name'=>'Region','division_name'=>'Division','station_name'=>'Station',
                'post_name'=>'Post','email'=>'Email','phone'=>'Phone',
              ];
              foreach ($allCols as $col => $colLabel): ?>
              <label style="display:flex;align-items:center;gap:5px;font-size:12px;text-transform:none;letter-spacing:0;font-weight:400;cursor:pointer">
                <input type="checkbox" name="cols[]" value="<?= $col ?>" checked> <?= $colLabel ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <button class="btn-icon bi-gold bi-lg" type="submit" title="Export"><?= ICO_DL ?></button>
        </form>
      </div>
    </details>
    <!-- CREATE -->
    <details class="form-panel" id="emp-form" <?= $editing ? 'open' : '' ?>>
      <summary><button type="button" class="btn-icon bi-primary" title="<?= $editing ? 'Edit record' : 'Add personnel' ?>"><?= $editing ? ICO_EDIT : ICO_PLUS ?></button></summary>
      <div class="form-body">
        <h4 style="margin:0 0 14px;color:var(--navy-800)"><?= $editing ? 'Edit — '.e($editing['full_name']) : 'Add New Personnel' ?></h4>
        <form method="post">
          <input type="hidden" name="action" value="<?= $editing?'update':'create' ?>">
          <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
          <div class="form-row">
            <div class="form-group" style="min-width:140px"><label>Force/File Number *</label>
              <input type="text" name="service_no" required value="<?= e($editing['service_no']??'') ?>" placeholder="e.g. UPF-12345"></div>
            <div class="form-group" style="min-width:130px"><label>Rank *</label>
              <input type="text" name="rank" required value="<?= e($editing['rank']??'') ?>" list="ranks-list">
              <datalist id="ranks-list"><?php foreach (['Constable','Corporal','Sergeant','Inspector','ASP','SP','SSP','Commissioner','AIG','DIG','IGP'] as $r): ?><option value="<?= $r ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-group" style="flex:2;min-width:180px"><label>Full Name(s) *</label>
              <input type="text" name="full_name" required value="<?= e($editing['full_name']??'') ?>"></div>
            <div class="form-group" style="min-width:100px"><label>Gender *</label>
              <select name="gender">
                <option value="M" <?= ($editing['gender']??'')==='M'?'selected':'' ?>>Male</option>
                <option value="F" <?= ($editing['gender']??'')==='F'?'selected':'' ?>>Female</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:2;min-width:160px"><label>Directorate</label>
              <select name="directorate">
                <option value="">— select —</option>
                <?php foreach ($directorates as $d): ?><option value="<?= $d ?>" <?= ($editing['directorate']??'')===$d?'selected':'' ?>><?= $d ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:2;min-width:160px"><label>Functional Unit</label>
              <input type="text" name="unit" value="<?= e($editing['unit']??'') ?>" list="units-list" placeholder="e.g. General Duty">
              <datalist id="units-list"><?php foreach ($units as $u): ?><option value="<?= $u ?>"><?php endforeach; ?></datalist>
            </div>
            <?php if ($canPickPost): ?>
            <div class="form-group" style="flex:3;min-width:200px"><label>Assigned Post *</label>
              <select name="post_id" required>
                <option value="">— select post —</option>
                <?php foreach ($posts as $pt): ?>
                  <option value="<?= $pt['id'] ?>" <?= ($editing['post_id']??0)==$pt['id']?'selected':'' ?>><?= e("{$pt['reg']} › {$pt['div']} › {$pt['sta']} › {$pt['name']}") ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php else: ?>
              <input type="hidden" name="post_id" value="<?= (int)$user['post_id'] ?>">
            <?php endif; ?>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:2;min-width:180px"><label>Email</label>
              <input type="email" name="email" value="<?= e($editing['email']??'') ?>" placeholder="officer@upf.go.ug"></div>
            <div class="form-group" style="min-width:140px"><label>Phone</label>
              <input type="tel" name="phone" value="<?= e($editing['phone']??'') ?>" placeholder="+256…"></div>
          </div>
          <div class="action-bar" style="margin-top:12px">
            <button class="btn-icon bi-gold bi-lg" type="submit" title="<?= $editing?'Save changes':'Save record' ?>"><?= ICO_SAVE ?></button>
            <?php if ($editing): ?><a class="btn-icon bi-secondary bi-lg" href="/employees.php" title="Cancel"><?= ICO_CANCEL ?></a><?php endif; ?>
          </div>
        </form>
      </div>
    </details>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<!-- VIEW DETAIL PANEL -->
<?php if ($viewing): ?>
<div class="card" style="border-left:4px solid var(--primary)">
  <div class="chr">
    <h3><?= ICO_EYE ?> &nbsp;<?= e($viewing['rank'].' '.$viewing['full_name']) ?></h3>
    <a class="btn-icon bi-secondary bi-sm" href="/employees.php" title="Close detail"><?= ICO_CANCEL ?></a>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px">
    <?php
    $details = [
      'Force/File No'  => $viewing['service_no'],
      'Rank'           => $viewing['rank'],
      'Full Name'      => $viewing['full_name'],
      'Gender'         => $viewing['gender']==='M'?'Male':'Female',
      'Directorate'    => $viewing['directorate']??'—',
      'Unit'           => $viewing['unit']??'—',
      'Region'         => $viewing['region_name']??'—',
      'Division'       => $viewing['division_name']??'—',
      'Station'        => $viewing['station_name']??'—',
      'Post'           => $viewing['post_name']??'—',
      'Email'          => $viewing['email']??'—',
      'Phone'          => $viewing['phone']??'—',
    ];
    foreach ($details as $lbl => $val): ?>
    <div style="padding:8px 12px;background:var(--navy-50);border-radius:8px">
      <div class="muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:3px"><?= $lbl ?></div>
      <div style="font-weight:600;color:var(--navy-800)"><?= e($val) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="action-bar" style="margin-top:14px">
    <a class="btn-icon bi-secondary" href="/employees.php?edit=<?= $viewing['id'] ?>" title="Edit this record"><?= ICO_EDIT ?></a>
    <form method="post" style="display:contents" onsubmit="return confirm('Remove this record?')">
      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $viewing['id'] ?>">
      <button class="btn-icon bi-danger" type="submit" title="Delete"><?= ICO_TRASH ?></button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- PERSONNEL TABLE -->
<div class="card">
  <div class="chr">
    <h3>Personnel <?php if ($search): ?><span class="badge badge-admin"><?= count($employees) ?> results for "<?= e($search) ?>"</span><?php else: ?><span class="badge badge-admin"><?= count($employees) ?></span><?php endif; ?></h3>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>File No</th><th>Rank</th><th>Name</th><th>G</th>
          <th>Directorate</th><th>Unit</th>
          <th>Region</th><th>Division</th><th>Station</th><th>Post</th>
          <th>Email</th><th>Phone</th>
          <th style="width:90px;text-align:center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($employees as $emp): ?>
        <tr class="<?= $viewId===$emp['id']?'row-highlight':'' ?>">
          <td style="font-family:monospace;font-size:12px"><?= e($emp['service_no']) ?></td>
          <td><?= e($emp['rank']) ?></td>
          <td><strong><?= e($emp['full_name']) ?></strong></td>
          <td><?= e($emp['gender']) ?></td>
          <td><?= e($emp['directorate']??'—') ?></td>
          <td><?= e($emp['unit']??'—') ?></td>
          <td class="muted"><?= e($emp['region_name']??'—') ?></td>
          <td class="muted"><?= e($emp['division_name']??'—') ?></td>
          <td class="muted"><?= e($emp['station_name']??'—') ?></td>
          <td><?= e($emp['post_name']??'—') ?></td>
          <td style="font-size:12px"><?= e($emp['email']??'') ?></td>
          <td style="font-size:12px;white-space:nowrap"><?= e($emp['phone']??'') ?></td>
          <td>
            <div class="action-bar" style="justify-content:center;flex-wrap:nowrap">
              <!-- READ/VIEW -->
              <a class="btn-icon bi-secondary bi-sm" href="/employees.php?view=<?= $emp['id'] ?><?= $search?'&q='.urlencode($search):'' ?>" title="View details"><?= ICO_EYE ?></a>
              <!-- EDIT/UPDATE -->
              <a class="btn-icon bi-secondary bi-sm" href="/employees.php?edit=<?= $emp['id'] ?><?= $search?'&q='.urlencode($search):'' ?>" title="Edit"><?= ICO_EDIT ?></a>
              <!-- DELETE -->
              <form method="post" style="display:contents" onsubmit="return confirm('Remove <?= e(addslashes($emp['full_name'])) ?>?')">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $emp['id'] ?>">
                <button class="btn-icon bi-danger bi-sm" type="submit" title="Delete"><?= ICO_TRASH ?></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$employees): ?>
        <tr><td colspan="13" style="text-align:center;color:var(--muted);padding:32px">
          <?= $search ? 'No results for "'.e($search).'". ' : 'No personnel found. ' ?>
          <?php if ($search): ?><a href="/employees.php">Clear search</a><?php endif; ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<style>
.row-highlight td{background:var(--navy-50) !important}
</style>
<script>
(function(){
  if(<?= $editId ? 'true':'false' ?>) {
    var fp=document.getElementById('emp-form'); if(fp) fp.open=true;
  }
  document.querySelectorAll('details.form-panel').forEach(function(d){
    d.addEventListener('toggle',function(){
      if(d.open) document.querySelectorAll('details.form-panel').forEach(function(o){ if(o!==d) o.open=false; });
    });
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
