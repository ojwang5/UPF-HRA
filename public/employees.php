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
                log_activity('Add Personnel', 'employee', "{$rank} {$name}", (int)$pdo->lastInsertId(), "Service No: {$sno}");
                flash('msg','Personnel record created successfully.');
            } else {
                $id = (int)$_POST['id'];
                if ($photo) {
                    $pdo->prepare("UPDATE employees SET service_no=?,full_name=?,gender=?,rank=?,directorate=?,unit=?,region_id=?,division_id=?,station_id=?,post_id=?,email=?,phone=?,photo_path=? WHERE id=?")
                        ->execute([$sno,$name,$gender,$rank,$dir,$unit,$ch['region_id'],$ch['division_id'],$ch['station_id'],$post_id,$email,$phone,$photo,$id]);
                } else {
                    $pdo->prepare("UPDATE employees SET service_no=?,full_name=?,gender=?,rank=?,directorate=?,unit=?,region_id=?,division_id=?,station_id=?,post_id=?,email=?,phone=? WHERE id=?")
                        ->execute([$sno,$name,$gender,$rank,$dir,$unit,$ch['region_id'],$ch['division_id'],$ch['station_id'],$post_id,$email,$phone,$id]);
                }
                log_activity('Edit Personnel', 'employee', "{$rank} {$name}", $id);
                flash('msg','Record updated successfully.');
            }
        } catch (\PDOException $ex) {
            $msg = (str_contains($ex->getMessage(),'UNIQUE') && str_contains($ex->getMessage(),'service_no'))
                ? "Force/File number '{$sno}' is already in use."
                : 'Could not save: '.$ex->getMessage();
            flash('err', $msg);
        }

    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $emp = $pdo->prepare("SELECT full_name, rank FROM employees WHERE id=? AND $scopeW");
        $emp->execute(array_merge([$id], $scopeP)); $empRow = $emp->fetch();
        $pdo->prepare("DELETE FROM employees WHERE id=? AND $scopeW")->execute(array_merge([$id], $scopeP));
        if ($empRow) log_activity('Remove Personnel', 'employee', ($empRow['rank'].' '.$empRow['full_name']), $id);
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

$rankOrderExpr = rank_order_sql();
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
                       ORDER BY $rankOrderExpr, e.full_name");
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

$ranks = UPF_RANKS;
$directorates = ['Operations','Criminal Investigations','Special Branch','Traffic','Fire Brigade','Marine','Administration','Finance','Human Resource','Training','Logistics','Media','Legal','ICT','Other'];
$units = ['General Duty','Flying Squad','Anti-Stock Theft','Anti-Terrorism','Border Security','K9 Unit','Rapid Response','VIP Protection','Community Policing','Other'];

include __DIR__ . '/../includes/header.php';
?>

<!-- ════════════════════════════ PAGE HEADER ════════════════════════════ -->
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

    <!-- BULK UPLOAD -->
    <div class="panel-wrap">
      <button class="btn-icon bi-secondary" data-panel="panel-bulk" title="Bulk import personnel">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      </button>
      <div class="panel-drop" id="panel-bulk" style="min-width:360px;right:0">
        <h4>
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;vertical-align:-2px;margin-right:4px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Bulk Import Personnel
        </h4>
        <p style="font-size:12px;color:var(--muted);margin:0 0 14px">Upload a CSV file to add multiple personnel records at once. Download the template first to see the required format.</p>
        <a href="/bulk-import.php?tpl=1" class="btn-icon bi-secondary bi-lg" style="width:100%;justify-content:center;text-decoration:none;margin-bottom:14px;gap:8px;font-size:12px;font-weight:600">
          <?= ICO_DL ?> <span>Download CSV Template</span>
        </a>
        <form method="post" action="/bulk-import.php" enctype="multipart/form-data" target="_blank">
          <div class="drop-zone" id="bulk-drop-zone" onclick="document.getElementById('bulk-file').click()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
            <p><strong>Click to choose</strong> or drag &amp; drop your CSV file here</p>
            <p id="bulk-file-name" style="margin-top:8px;font-weight:600;color:var(--primary);display:none"></p>
          </div>
          <input type="file" id="bulk-file" name="csv_file" accept=".csv,text/csv" style="display:none">
          <button class="btn-icon bi-primary bi-lg" type="submit" style="width:100%;justify-content:center;margin-top:12px;gap:8px;font-size:12px;font-weight:600">
            <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <span>Import Personnel</span>
          </button>
        </form>
      </div>
    </div>

    <!-- ADD BUTTON — opens modal -->
    <button class="btn-icon bi-primary" id="btn-open-modal"
      title="<?= $editing ? 'Edit: '.e($editing['full_name']) : 'Add New Personnel' ?>"
      style="gap:6px;padding:0 14px;width:auto;font-size:12px;font-weight:600"
    >
      <?= $editing ? ICO_EDIT : ICO_PLUS ?>
      <span><?= $editing ? 'Edit Record' : 'Add Personnel' ?></span>
    </button>

  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>
<?php if ($search): ?><div class="alert" style="background:var(--navy-50);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:13px">Search: <strong><?= e($search) ?></strong> — <?= count($employees) ?> result(s) &nbsp;<a href="/employees.php" style="color:var(--primary)">Clear</a></div><?php endif; ?>

<!-- ════════════════════════════ DETAIL VIEW PANEL ════════════════════════════ -->
<?php if ($viewing): ?>
<div class="card" style="border-left:4px solid var(--primary);margin-bottom:16px">
  <div class="chr">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <div style="width:72px;height:72px;border-radius:10px;overflow:hidden;border:2px solid var(--border);background:var(--navy-50);flex-shrink:0;display:flex;align-items:center;justify-content:center">
        <?php if (!empty($viewing['photo_path']) && file_exists(__DIR__.'/uploads/photos/'.$viewing['photo_path'])): ?>
          <img src="/uploads/photos/<?= e($viewing['photo_path']) ?>" alt="Photo" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" width="32" height="32"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <?php endif; ?>
      </div>
      <div>
        <h3 style="margin:0 0 3px"><?= e($viewing['rank'].' '.$viewing['full_name']) ?></h3>
        <div style="font-size:12px;color:var(--muted)"><?= e($viewing['service_no']) ?> &nbsp;·&nbsp; <?= $viewing['gender']==='M'?'Male':'Female' ?></div>
      </div>
    </div>
    <a class="btn-icon bi-secondary bi-sm" href="/employees.php<?= $search?'?q='.urlencode($search):'' ?>" title="Close"><?= ICO_CANCEL ?></a>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:16px">
    <?php foreach ([
      'Directorate'=>$viewing['directorate']??'—','Unit'=>$viewing['unit']??'—',
      'Region'=>$viewing['region_name']??'—','Division'=>$viewing['division_name']??'—',
      'Station'=>$viewing['station_name']??'—','Post'=>$viewing['post_name']??'—',
      'Email'=>$viewing['email']??'—','Phone'=>$viewing['phone']??'—',
    ] as $lbl=>$val): ?>
    <div style="padding:10px 12px;background:var(--navy-50);border-radius:8px">
      <div style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:2px"><?= $lbl ?></div>
      <div style="font-weight:600;color:var(--navy-800);font-size:13px"><?= e($val) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <a class="btn-icon bi-secondary" href="/employees.php?edit=<?= $viewing['id'] ?><?= $search?'&q='.urlencode($search):'' ?>" title="Edit record"><?= ICO_EDIT ?> <span style="font-size:12px;margin-left:4px">Edit</span></a>
    <form method="post" style="display:contents" onsubmit="return confirm('Remove <?= e(addslashes($viewing['full_name'])) ?>?')">
      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $viewing['id'] ?>">
      <button class="btn-icon bi-danger" type="submit" title="Delete"><?= ICO_TRASH ?> <span style="font-size:12px;margin-left:4px">Remove</span></button>
    </form>
    <form method="post" enctype="multipart/form-data" style="display:flex;align-items:center;gap:6px;background:var(--navy-50);padding:5px 10px;border-radius:8px;border:1px solid var(--border)">
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
      <label style="font-size:11px;white-space:nowrap">Change Photo:</label>
      <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" style="font-size:11px;max-width:160px">
      <button class="btn-icon bi-gold bi-sm" type="submit" title="Upload"><?= ICO_SAVE ?></button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════ PERSONNEL TABLE ════════════════════════════ -->
<div class="card">
  <div class="chr">
    <h3>Personnel Register <span class="badge badge-admin"><?= count($employees) ?></span></h3>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:46px"></th>
          <th>File No</th><th>Rank</th><th>Full Name</th><th>G</th>
          <th>Directorate</th><th>Unit</th>
          <th>Region</th><th>Division</th><th>Station / Post</th>
          <th>Contact</th>
          <th style="width:80px;text-align:center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($employees as $emp): ?>
        <tr class="<?= $viewId===$emp['id']?'row-on_duty':'' ?>">
          <td style="padding:4px 6px">
            <?php if (!empty($emp['photo_path']) && file_exists(__DIR__.'/uploads/photos/'.$emp['photo_path'])): ?>
              <img src="/uploads/photos/<?= e($emp['photo_path']) ?>" alt="" style="width:36px;height:36px;border-radius:8px;object-fit:cover;display:block">
            <?php else: ?>
              <div style="width:36px;height:36px;border-radius:8px;background:var(--navy-50);border:1px solid var(--border);display:flex;align-items:center;justify-content:center">
                <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" width="18" height="18"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
              </div>
            <?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:12px"><?= e($emp['service_no']) ?></td>
          <td style="font-size:12px;white-space:nowrap"><?= e($emp['rank']) ?></td>
          <td><strong style="font-size:13px"><?= e($emp['full_name']) ?></strong></td>
          <td style="font-size:12px"><?= e($emp['gender']) ?></td>
          <td style="font-size:12px"><?= e($emp['directorate']??'—') ?></td>
          <td style="font-size:12px"><?= e($emp['unit']??'—') ?></td>
          <td style="font-size:12px;color:var(--muted)"><?= e($emp['region_name']??'—') ?></td>
          <td style="font-size:12px;color:var(--muted)"><?= e($emp['division_name']??'—') ?></td>
          <td style="font-size:12px">
            <div><?= e($emp['station_name']??'—') ?></div>
            <div style="font-size:11px;color:var(--muted)"><?= e($emp['post_name']??'') ?></div>
          </td>
          <td style="font-size:11px">
            <?php if ($emp['email']): ?><div><?= e($emp['email']) ?></div><?php endif; ?>
            <?php if ($emp['phone']): ?><div style="color:var(--muted)"><?= e($emp['phone']) ?></div><?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:3px;justify-content:center">
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
        <tr><td colspan="12" style="text-align:center;color:var(--muted);padding:40px 20px">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="32" height="32" style="display:block;margin:0 auto 10px;opacity:.4"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <?= $search ? 'No results for "'.e($search).'". <a href="/employees.php">Clear search</a>' : 'No personnel found. Click <strong>Add Personnel</strong> to get started.' ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ════════════════════════════ ADD / EDIT MODAL DRAWER ════════════════════════════ -->
<div class="modal-overlay" id="personnel-modal">
  <div class="modal-drawer">
    <div class="modal-head">
      <div>
        <h2 id="modal-title"><?= $editing ? 'Edit Personnel Record' : 'Add New Personnel' ?></h2>
        <div class="subtitle" id="modal-subtitle"><?= $editing ? e($editing['full_name']) : 'Fill in all required fields to register a new member' ?></div>
      </div>
      <button class="modal-close" id="btn-close-modal" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <div class="modal-body">
      <form method="post" enctype="multipart/form-data" id="personnel-form">
        <input type="hidden" name="action" value="<?= $editing?'update':'create' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>

        <!-- SECTION 1: Identity -->
        <div class="modal-section">
          <div class="modal-section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Identity &amp; Service Details
          </div>
          <div class="form-row">
            <div class="form-group" style="min-width:160px;max-width:200px">
              <label>Force / File Number <span style="color:var(--red)">*</span></label>
              <input type="text" name="service_no" required value="<?= e($editing['service_no']??'') ?>" placeholder="e.g. UPF-12345" style="font-family:monospace">
            </div>
            <div class="form-group" style="flex:2;min-width:200px">
              <label>Full Name(s) <span style="color:var(--red)">*</span></label>
              <input type="text" name="full_name" required value="<?= e($editing['full_name']??'') ?>" placeholder="Surname, Other Names">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:2;min-width:180px">
              <label>Rank <span style="color:var(--red)">*</span></label>
              <select name="rank" required>
                <option value="">— select rank —</option>
                <?php foreach ($ranks as $r): ?>
                <option value="<?= $r ?>" <?= ($editing['rank']??'')===$r?'selected':'' ?>><?= $r ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="min-width:120px;max-width:150px">
              <label>Gender <span style="color:var(--red)">*</span></label>
              <select name="gender">
                <option value="M" <?= ($editing['gender']??'')==='M'?'selected':'' ?>>Male</option>
                <option value="F" <?= ($editing['gender']??'')==='F'?'selected':'' ?>>Female</option>
              </select>
            </div>
          </div>
        </div>

        <!-- SECTION 2: Assignment -->
        <div class="modal-section">
          <div class="modal-section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Posting &amp; Assignment
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:1;min-width:180px">
              <label>Directorate</label>
              <select name="directorate">
                <option value="">— select directorate —</option>
                <?php foreach ($directorates as $d): ?><option value="<?= $d ?>" <?= ($editing['directorate']??'')===$d?'selected':'' ?>><?= $d ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:1;min-width:180px">
              <label>Functional Unit</label>
              <input type="text" name="unit" value="<?= e($editing['unit']??'') ?>" list="units-list" placeholder="e.g. General Duty">
              <datalist id="units-list"><?php foreach ($units as $u): ?><option value="<?= $u ?>"><?php endforeach; ?></datalist>
            </div>
          </div>
          <?php if ($canPickPost): ?>
          <div class="form-row">
            <div class="form-group">
              <label>Assigned Post <span style="color:var(--red)">*</span></label>
              <select name="post_id" required>
                <option value="">— select post —</option>
                <?php foreach ($posts as $pt): ?>
                  <option value="<?= $pt['id'] ?>" <?= ($editing['post_id']??0)==$pt['id']?'selected':'' ?>><?= e("{$pt['reg']} › {$pt['div']} › {$pt['sta']} › {$pt['name']}") ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <?php else: ?>
            <input type="hidden" name="post_id" value="<?= (int)$user['post_id'] ?>">
            <div style="font-size:12px;color:var(--muted);background:var(--navy-100);padding:8px 12px;border-radius:7px">
              <strong>Post:</strong> Automatically assigned to your current post.
            </div>
          <?php endif; ?>
        </div>

        <!-- SECTION 3: Contact & Photo -->
        <div class="modal-section">
          <div class="modal-section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.63 3.38 2 2 0 0 1 3.6 1.2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.88a16 16 0 0 0 6.06 6.06l1.69-1.69a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            Contact &amp; Photo
          </div>
          <div class="form-row" style="margin-bottom:16px">
            <div class="form-group" style="flex:2;min-width:180px">
              <label>Email Address</label>
              <input type="email" name="email" value="<?= e($editing['email']??'') ?>" placeholder="officer@upf.go.ug">
            </div>
            <div class="form-group" style="min-width:150px">
              <label>Phone Number</label>
              <input type="tel" name="phone" value="<?= e($editing['phone']??'') ?>" placeholder="+256 700 000000">
            </div>
          </div>
          <!-- Photo upload with preview -->
          <div>
            <label style="display:block;margin-bottom:8px">Profile Photo (optional)</label>
            <div class="photo-upload-wrap">
              <div class="photo-preview" id="photo-preview-box">
                <?php if (!empty($editing['photo_path']) && file_exists(__DIR__.'/uploads/photos/'.$editing['photo_path'])): ?>
                  <img src="/uploads/photos/<?= e($editing['photo_path']) ?>" id="photo-preview-img" alt="Current photo">
                <?php else: ?>
                  <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" width="28" height="28" id="photo-preview-placeholder"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                  <img id="photo-preview-img" src="" alt="" style="display:none;width:100%;height:100%;object-fit:cover">
                <?php endif; ?>
              </div>
              <div style="flex:1">
                <label for="photo-input" style="display:inline-flex;align-items:center;gap:7px;padding:9px 16px;background:var(--navy-50);border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:12px;font-weight:600;color:var(--navy-700);text-transform:none;letter-spacing:0;transition:background .12s">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                  Choose Photo
                </label>
                <input type="file" id="photo-input" name="photo" accept="image/jpeg,image/png,image/webp" style="display:none">
                <div id="photo-filename" style="font-size:11px;color:var(--muted);margin-top:6px">JPG, PNG or WebP — max 5MB</div>
              </div>
            </div>
          </div>
        </div>

      </form>
    </div>

    <div class="modal-foot">
      <button class="btn-icon bi-gold bi-lg" type="submit" form="personnel-form" style="gap:8px;padding:0 20px;width:auto;font-size:13px;font-weight:600">
        <?= ICO_SAVE ?> <span><?= $editing ? 'Save Changes' : 'Add Personnel' ?></span>
      </button>
      <button class="btn-icon bi-secondary bi-lg" id="btn-close-modal-foot" type="button" style="gap:8px;padding:0 16px;width:auto;font-size:13px">
        <?= ICO_CANCEL ?> <span>Cancel</span>
      </button>
      <?php if ($editing): ?>
      <div style="flex:1;text-align:right;font-size:12px;color:var(--muted)">
        Editing: <strong style="color:var(--navy-800)"><?= e($editing['full_name']) ?></strong>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ════════════════════════════ SCRIPTS ════════════════════════════ -->
<script>
(function(){
  var modal = document.getElementById('personnel-modal');
  var openBtn = document.getElementById('btn-open-modal');
  var closeBtns = [document.getElementById('btn-close-modal'), document.getElementById('btn-close-modal-foot')];

  function openModal(){ modal.classList.add('open'); document.body.style.overflow='hidden'; }
  function closeModal(){ modal.classList.remove('open'); document.body.style.overflow=''; }

  openBtn.addEventListener('click', openModal);
  closeBtns.forEach(function(b){ if(b) b.addEventListener('click', closeModal); });
  modal.addEventListener('click', function(e){ if(e.target===modal) closeModal(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModal(); });

  <?php if ($editing || isset($_GET['add'])): ?>
  openModal();
  <?php endif; ?>

  // Photo preview
  var photoInput = document.getElementById('photo-input');
  var previewImg = document.getElementById('photo-preview-img');
  var placeholder = document.getElementById('photo-preview-placeholder');
  var fileLabel = document.getElementById('photo-filename');
  if(photoInput) {
    photoInput.addEventListener('change', function(){
      var file = this.files[0];
      if(!file) return;
      fileLabel.textContent = file.name;
      var reader = new FileReader();
      reader.onload = function(e){
        previewImg.src = e.target.result;
        previewImg.style.display = 'block';
        if(placeholder) placeholder.style.display = 'none';
      };
      reader.readAsDataURL(file);
    });
  }

  // Bulk CSV drop zone
  var dropZone = document.getElementById('bulk-drop-zone');
  var bulkFile = document.getElementById('bulk-file');
  var bulkName = document.getElementById('bulk-file-name');
  if(dropZone && bulkFile){
    bulkFile.addEventListener('change', function(){
      if(this.files[0]){ bulkName.textContent = this.files[0].name; bulkName.style.display='block'; }
    });
    dropZone.addEventListener('dragover', function(e){ e.preventDefault(); this.classList.add('drag-over'); });
    dropZone.addEventListener('dragleave', function(){ this.classList.remove('drag-over'); });
    dropZone.addEventListener('drop', function(e){
      e.preventDefault(); this.classList.remove('drag-over');
      var f = e.dataTransfer.files[0];
      if(f){ bulkFile.files = e.dataTransfer.files; bulkName.textContent = f.name; bulkName.style.display='block'; }
    });
  }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
