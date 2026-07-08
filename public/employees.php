<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_login();
$page = 'employees';
$page_title = 'Personnel';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

/* ── Photo upload helper ── */
function handle_photo_upload(): ?string {
    if (empty($_FILES['photo']['name'])) return null;
    $f = $_FILES['photo'];
    if ($f['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) return null;
    $dir = __DIR__ . '/uploads/photos/';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = 'photo_' . uniqid() . '.' . $ext;
    move_uploaded_file($f['tmp_name'], $dir . $name);
    return $name;
}

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

        $photo = handle_photo_upload();

        try {
            if ($action === 'create') {
                $pdo->prepare("INSERT INTO employees (service_no,full_name,gender,rank,directorate,unit,region_id,division_id,station_id,post_id,email,phone,photo_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$sno,$name,$gender,$rank,$dir,$unit,$ch['region_id'],$ch['division_id'],$ch['station_id'],$post_id,$email,$phone,$photo]);
                flash('msg','Personnel record created.');
            } else {
                $id = (int)$_POST['id'];
                if ($photo) {
                    $pdo->prepare("UPDATE employees SET service_no=?,full_name=?,gender=?,rank=?,directorate=?,unit=?,region_id=?,division_id=?,station_id=?,post_id=?,email=?,phone=?,photo_path=? WHERE id=?")
                        ->execute([$sno,$name,$gender,$rank,$dir,$unit,$ch['region_id'],$ch['division_id'],$ch['station_id'],$post_id,$email,$phone,$photo,$id]);
                } else {
                    $pdo->prepare("UPDATE employees SET service_no=?,full_name=?,gender=?,rank=?,directorate=?,unit=?,region_id=?,division_id=?,station_id=?,post_id=?,email=?,phone=? WHERE id=?")
                        ->execute([$sno,$name,$gender,$rank,$dir,$unit,$ch['region_id'],$ch['division_id'],$ch['station_id'],$post_id,$email,$phone,$id]);
                }
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

    header('Location:/employees.php'); exit;
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

$stmt = $pdo->prepare("SELECT e.id, e.service_no, e.full_name, e.gender, e.rank, e.directorate, e.unit,
                              e.email, e.phone, e.photo_path,
                              rg.name AS region_name, dv.name AS division_name,
                              st.name AS station_name, pt.name AS post_name,
                              e.region_id, e.division_id, e.station_id, e.post_id
                       FROM employees e
                       LEFT JOIN regions   rg ON rg.id=e.region_id
                       LEFT JOIN divisions dv ON dv.id=e.division_id
                       LEFT JOIN stations  st ON st.id=e.station_id
                       LEFT JOIN posts     pt ON pt.id=e.post_id
                       WHERE $where
                       ORDER BY rg.name, dv.name, st.name, pt.name, e.full_name");
$stmt->execute($params);
$employees = $stmt->fetchAll();

/* ── Edit / View mode ── */
$editId  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = null;
if ($editId) {
    $s = $pdo->prepare("SELECT e.* FROM employees e WHERE e.id=? AND $scopeW");
    $s->execute(array_merge([$editId], $scopeP));
    $editing = $s->fetch() ?: null;
}

$viewId  = isset($_GET['view']) ? (int)$_GET['view'] : 0;
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
    <div class="desc"><?= is_superadmin($user) ? 'All force personnel' : e(user_scope_label($user)) ?> — <?= count($employees) ?> record(s)</div>
  </div>
  <div class="action-bar">

    <!-- SEARCH -->
    <div class="panel-wrap">
      <button class="btn-icon bi-secondary" data-panel="panel-search" title="Search personnel"><?= ICO_SEARCH ?></button>
      <div class="panel-drop" id="panel-search">
        <h4><?= ICO_SEARCH ?> Search Personnel</h4>
        <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
          <div class="form-group" style="flex:1;min-width:200px"><label>Keyword</label>
            <input type="text" name="q" value="<?= e($search) ?>" autofocus placeholder="Name, file no, rank, directorate…">
          </div>
          <button class="btn-icon bi-primary" type="submit" title="Search"><?= ICO_SEARCH ?></button>
          <?php if ($search): ?>
          <a class="btn-icon bi-secondary" href="/employees.php" title="Clear"><?= ICO_CANCEL ?></a>
          <?php endif; ?>
        </form>
        <?php if ($search): ?><div style="margin-top:8px;font-size:12px;color:var(--muted)"><?= count($employees) ?> result(s) for "<strong><?= e($search) ?></strong>"</div><?php endif; ?>
      </div>
    </div>

    <!-- EXPORT -->
    <div class="panel-wrap">
      <button class="btn-icon bi-secondary" data-panel="panel-export" title="Export personnel"><?= ICO_DL ?></button>
      <div class="panel-drop" id="panel-export" style="min-width:340px">
        <h4><?= ICO_DL ?> Export Personnel</h4>
        <form method="post" action="/export-personnel.php" target="_blank">
          <input type="hidden" name="q" value="<?= e($search) ?>">
          <div class="form-group" style="margin-bottom:12px"><label>Format</label>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px">
              <?php foreach (['csv'=>'CSV','excel'=>'Excel','pdf'=>'Print / PDF'] as $k=>$lbl): ?>
              <label style="display:flex;align-items:center;gap:5px;font-size:13px;text-transform:none;letter-spacing:0;font-weight:500;cursor:pointer">
                <input type="radio" name="format" value="<?= $k ?>" <?= $k==='csv'?'checked':'' ?>> <?= $lbl ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:14px"><label>Columns to export</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 14px;margin-top:6px">
              <?php foreach ([
                'service_no'=>'File/Force No','rank'=>'Rank','full_name'=>'Full Name',
                'gender'=>'Gender','directorate'=>'Directorate','unit'=>'Unit',
                'region_name'=>'Region','division_name'=>'Division','station_name'=>'Station',
                'post_name'=>'Post','email'=>'Email','phone'=>'Phone',
              ] as $col=>$colLabel): ?>
              <label style="display:flex;align-items:center;gap:5px;font-size:12px;text-transform:none;letter-spacing:0;font-weight:400;cursor:pointer">
                <input type="checkbox" name="cols[]" value="<?= $col ?>" checked> <?= $colLabel ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <button class="btn-icon bi-gold bi-lg" type="submit"><?= ICO_DL ?> <span style="font-size:12px;margin-left:4px">Export</span></button>
        </form>
      </div>
    </div>

    <!-- ADD / EDIT -->
    <div class="panel-wrap">
      <button class="btn-icon <?= $editing ? 'bi-gold' : 'bi-primary' ?>" data-panel="panel-form"
        title="<?= $editing ? 'Edit: '.e($editing['full_name']) : 'Add New Personnel' ?>"
        <?= $editing ? 'data-panel-open="1"' : '' ?>
      ><?= $editing ? ICO_EDIT : ICO_PLUS ?></button>
      <div class="panel-drop" id="panel-form" style="min-width:min(700px,90vw);right:0;<?= $editing?'display:block':'' ?>">
        <h4><?= $editing ? ICO_EDIT.' Edit — '.e($editing['full_name']) : ICO_PLUS.' Add New Personnel' ?></h4>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="<?= $editing?'update':'create' ?>">
          <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
          <div class="form-row">
            <div class="form-group" style="min-width:140px"><label>Force/File Number *</label>
              <input type="text" name="service_no" required value="<?= e($editing['service_no']??'') ?>" placeholder="e.g. UPF-12345">
            </div>
            <div class="form-group" style="min-width:120px"><label>Rank *</label>
              <input type="text" name="rank" required value="<?= e($editing['rank']??'') ?>" list="ranks-list" placeholder="Constable…">
              <datalist id="ranks-list"><?php foreach (['Constable','Corporal','Sergeant','Inspector','ASP','SP','SSP','Commissioner','AIG','DIG','IGP'] as $r): ?><option value="<?= $r ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-group" style="flex:2;min-width:180px"><label>Full Name(s) *</label>
              <input type="text" name="full_name" required value="<?= e($editing['full_name']??'') ?>">
            </div>
            <div class="form-group" style="min-width:90px"><label>Gender *</label>
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
              <input type="email" name="email" value="<?= e($editing['email']??'') ?>" placeholder="officer@upf.go.ug">
            </div>
            <div class="form-group" style="min-width:140px"><label>Phone</label>
              <input type="tel" name="phone" value="<?= e($editing['phone']??'') ?>" placeholder="+256…">
            </div>
            <div class="form-group" style="min-width:160px"><label>Photo (optional)</label>
              <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" style="font-size:12px;padding:5px">
            </div>
          </div>
          <div style="display:flex;gap:8px;margin-top:12px">
            <button class="btn-icon bi-gold bi-lg" type="submit"><?= ICO_SAVE ?> <span style="font-size:12px;margin-left:4px">Save</span></button>
            <?php if ($editing): ?><a class="btn-icon bi-secondary bi-lg" href="/employees.php"><?= ICO_CANCEL ?> <span style="font-size:12px;margin-left:4px">Cancel</span></a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>

  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>
<?php if ($search): ?><div class="alert" style="background:var(--navy-50);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:13px">Search: <strong><?= e($search) ?></strong> — <?= count($employees) ?> result(s) &nbsp;<a href="/employees.php" style="color:var(--primary)">Clear</a></div><?php endif; ?>

<!-- DETAIL VIEW PANEL -->
<?php if ($viewing): ?>
<div class="card" style="border-left:4px solid var(--primary);margin-bottom:8px">
  <div class="chr">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
      <!-- Photo frame -->
      <div style="width:80px;height:80px;border-radius:10px;overflow:hidden;border:2px solid var(--border);background:var(--navy-50);flex-shrink:0;display:flex;align-items:center;justify-content:center">
        <?php if (!empty($viewing['photo_path']) && file_exists(__DIR__.'/uploads/photos/'.$viewing['photo_path'])): ?>
          <img src="/uploads/photos/<?= e($viewing['photo_path']) ?>" alt="Photo" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" width="36" height="36"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <?php endif; ?>
      </div>
      <div>
        <h3 style="margin:0"><?= e($viewing['rank'].' '.$viewing['full_name']) ?></h3>
        <div class="muted" style="font-size:12px"><?= e($viewing['service_no']) ?> &nbsp;·&nbsp; <?= $viewing['gender']==='M'?'Male':'Female' ?></div>
      </div>
    </div>
    <a class="btn-icon bi-secondary bi-sm" href="/employees.php<?= $search?'?q='.urlencode($search):'' ?>" title="Close"><?= ICO_CANCEL ?></a>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px">
    <?php foreach ([
      'Directorate'=>$viewing['directorate']??'—','Unit'=>$viewing['unit']??'—',
      'Region'=>$viewing['region_name']??'—','Division'=>$viewing['division_name']??'—',
      'Station'=>$viewing['station_name']??'—','Post'=>$viewing['post_name']??'—',
      'Email'=>$viewing['email']??'—','Phone'=>$viewing['phone']??'—',
    ] as $lbl=>$val): ?>
    <div style="padding:8px 12px;background:var(--navy-50);border-radius:8px">
      <div class="muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:2px"><?= $lbl ?></div>
      <div style="font-weight:600;color:var(--navy-800);font-size:13px"><?= e($val) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;gap:6px;margin-top:14px">
    <a class="btn-icon bi-secondary" href="/employees.php?edit=<?= $viewing['id'] ?><?= $search?'&q='.urlencode($search):'' ?>" title="Edit"><?= ICO_EDIT ?></a>
    <form method="post" style="display:contents" onsubmit="return confirm('Remove <?= e(addslashes($viewing['full_name'])) ?>?')">
      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $viewing['id'] ?>">
      <button class="btn-icon bi-danger" type="submit" title="Delete"><?= ICO_TRASH ?></button>
    </form>
    <!-- Upload photo for this record -->
    <form method="post" enctype="multipart/form-data" style="display:contents">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= $viewing['id'] ?>">
      <input type="hidden" name="service_no" value="<?= e($viewing['service_no']) ?>">
      <input type="hidden" name="full_name" value="<?= e($viewing['full_name']) ?>">
      <input type="hidden" name="gender" value="<?= e($viewing['gender']) ?>">
      <input type="hidden" name="rank" value="<?= e($viewing['rank']) ?>">
      <input type="hidden" name="directorate" value="<?= e($viewing['directorate']??'') ?>">
      <input type="hidden" name="unit" value="<?= e($viewing['unit']??'') ?>">
      <input type="hidden" name="post_id" value="<?= (int)$viewing['post_id'] ?>">
      <input type="hidden" name="email" value="<?= e($viewing['email']??'') ?>">
      <input type="hidden" name="phone" value="<?= e($viewing['phone']??'') ?>">
      <div style="display:flex;align-items:center;gap:6px;background:var(--navy-50);padding:4px 10px 4px 6px;border-radius:8px;border:1px solid var(--border)">
        <label style="font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin:0;white-space:nowrap">Change Photo:</label>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" style="font-size:11px;max-width:180px">
        <button class="btn-icon bi-gold bi-sm" type="submit" title="Upload photo"><?= ICO_SAVE ?></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- PERSONNEL TABLE -->
<div class="card">
  <div class="chr">
    <h3>Personnel Register <span class="badge badge-admin"><?= count($employees) ?></span></h3>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Photo</th><th>File No</th><th>Rank</th><th>Name</th><th>G</th>
          <th>Directorate</th><th>Unit</th>
          <th>Region</th><th>Division</th><th>Station</th><th>Post</th>
          <th>Email</th><th>Phone</th>
          <th style="width:88px;text-align:center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($employees as $emp): ?>
        <tr class="<?= $viewId===$emp['id']?'row-on_duty':'' ?>">
          <td style="padding:4px 6px;width:40px">
            <?php if (!empty($emp['photo_path']) && file_exists(__DIR__.'/uploads/photos/'.$emp['photo_path'])): ?>
              <img src="/uploads/photos/<?= e($emp['photo_path']) ?>" alt="" style="width:36px;height:36px;border-radius:6px;object-fit:cover">
            <?php else: ?>
              <div style="width:36px;height:36px;border-radius:6px;background:var(--navy-50);border:1px solid var(--border);display:flex;align-items:center;justify-content:center">
                <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" width="18" height="18"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
              </div>
            <?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:12px"><?= e($emp['service_no']) ?></td>
          <td><?= e($emp['rank']) ?></td>
          <td><strong><?= e($emp['full_name']) ?></strong></td>
          <td><?= e($emp['gender']) ?></td>
          <td style="font-size:12px"><?= e($emp['directorate']??'—') ?></td>
          <td style="font-size:12px"><?= e($emp['unit']??'—') ?></td>
          <td class="muted" style="font-size:12px"><?= e($emp['region_name']??'—') ?></td>
          <td class="muted" style="font-size:12px"><?= e($emp['division_name']??'—') ?></td>
          <td class="muted" style="font-size:12px"><?= e($emp['station_name']??'—') ?></td>
          <td style="font-size:12px"><?= e($emp['post_name']??'—') ?></td>
          <td style="font-size:11px"><?= e($emp['email']??'') ?></td>
          <td style="font-size:11px;white-space:nowrap"><?= e($emp['phone']??'') ?></td>
          <td>
            <div style="display:flex;gap:3px;justify-content:center;flex-wrap:nowrap">
              <a class="btn-icon bi-secondary bi-sm" href="/employees.php?view=<?= $emp['id'] ?><?= $search?'&q='.urlencode($search):'' ?>" title="View details"><?= ICO_EYE ?></a>
              <a class="btn-icon bi-secondary bi-sm" href="/employees.php?edit=<?= $emp['id'] ?><?= $search?'&q='.urlencode($search):'' ?>" title="Edit"><?= ICO_EDIT ?></a>
              <form method="post" style="display:contents" onsubmit="return confirm('Remove <?= e(addslashes($emp['full_name'])) ?>?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                <button class="btn-icon bi-danger bi-sm" type="submit" title="Delete"><?= ICO_TRASH ?></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$employees): ?>
        <tr><td colspan="14" style="text-align:center;color:var(--muted);padding:32px">
          <?= $search ? 'No results for "'.e($search).'". ' : 'No personnel found. ' ?>
          <?php if ($search): ?><a href="/employees.php">Clear search</a><?php endif; ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($editId): ?>
<script>
// Auto-open the form panel if we arrived via ?edit=
document.getElementById('panel-form').style.display='block';
document.querySelector('[data-panel="panel-form"]').setAttribute('data-panel-open','1');
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
