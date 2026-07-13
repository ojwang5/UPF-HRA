<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_role(['superadmin']);
$page = 'hierarchy';
$page_title = 'Organisational Structure';
$pdo = db();

/* ══════════════════ POST HANDLER ══════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $level  = $_POST['level'] ?? '';
    $name   = trim($_POST['name'] ?? '');
    $code   = strtoupper(trim($_POST['code'] ?? ''));
    $id     = (int)($_POST['id'] ?? 0);
    $tab    = $_POST['tab'] ?? 'geography';

    if ($action === 'add') {
        try {
            match($level) {
                'region'      => $pdo->prepare("INSERT INTO regions (name,code,location) VALUES (?,?,?)")->execute([$name,$code,$_POST['location']??'']),
                'division'    => $pdo->prepare("INSERT INTO divisions (region_id,name,code) VALUES (?,?,?)")->execute([(int)$_POST['parent_id'],$name,$code]),
                'station'     => $pdo->prepare("INSERT INTO stations (division_id,name,code) VALUES (?,?,?)")->execute([(int)$_POST['parent_id'],$name,$code]),
                'post'        => $pdo->prepare("INSERT INTO posts (station_id,name,code) VALUES (?,?,?)")->execute([(int)$_POST['parent_id'],$name,$code]),
                'directorate' => $pdo->prepare("INSERT INTO directorates (name,code,description) VALUES (?,?,?)")->execute([$name,$code,trim($_POST['description']??'')]),
                'unit'        => $pdo->prepare("INSERT INTO units (directorate_id,name,code,description) VALUES (?,?,?,?)")->execute([(int)$_POST['parent_id'],$name,$code,trim($_POST['description']??'')]),
                default       => null,
            };
            log_activity('Add '.ucfirst($level), $level, $name, 0);
            flash('msg', ucfirst($level).' added successfully.');
        } catch (\Throwable $e) { flash('err', 'Error: '.$e->getMessage()); }

    } elseif ($action === 'edit') {
        try {
            match($level) {
                'region'      => $pdo->prepare("UPDATE regions SET name=?,code=?,location=? WHERE id=?")->execute([$name,$code,trim($_POST['location']??''),$id]),
                'division'    => $pdo->prepare("UPDATE divisions SET name=?,code=?,region_id=? WHERE id=?")->execute([$name,$code,(int)$_POST['parent_id'],$id]),
                'station'     => $pdo->prepare("UPDATE stations SET name=?,code=?,division_id=? WHERE id=?")->execute([$name,$code,(int)$_POST['parent_id'],$id]),
                'post'        => $pdo->prepare("UPDATE posts SET name=?,code=?,station_id=? WHERE id=?")->execute([$name,$code,(int)$_POST['parent_id'],$id]),
                'directorate' => $pdo->prepare("UPDATE directorates SET name=?,code=?,description=? WHERE id=?")->execute([$name,$code,trim($_POST['description']??''),$id]),
                'unit'        => $pdo->prepare("UPDATE units SET name=?,code=?,description=?,directorate_id=? WHERE id=?")->execute([$name,$code,trim($_POST['description']??''),(int)$_POST['parent_id'],$id]),
                default       => null,
            };
            log_activity('Edit '.ucfirst($level), $level, $name, $id);
            flash('msg', ucfirst($level).' updated.');
        } catch (\Throwable $e) { flash('err', 'Error: '.$e->getMessage()); }

    } elseif ($action === 'delete') {
        try {
            match($level) {
                'region'      => $pdo->prepare("DELETE FROM regions WHERE id=?")->execute([$id]),
                'division'    => $pdo->prepare("DELETE FROM divisions WHERE id=?")->execute([$id]),
                'station'     => $pdo->prepare("DELETE FROM stations WHERE id=?")->execute([$id]),
                'post'        => $pdo->prepare("DELETE FROM posts WHERE id=?")->execute([$id]),
                'directorate' => $pdo->prepare("DELETE FROM directorates WHERE id=?")->execute([$id]),
                'unit'        => $pdo->prepare("DELETE FROM units WHERE id=?")->execute([$id]),
                default       => null,
            };
            log_activity('Delete '.ucfirst($level), $level, '', $id);
            flash('msg', ucfirst($level).' removed.');
        } catch (\Throwable $e) { flash('err', 'Cannot delete — remove child entries first.'); }
    }
    header('Location:/hierarchy.php?tab='.$tab); exit;
}

/* ══════════════════ DATA QUERIES ══════════════════ */
// Personnel counts per geography level
$pCount = ['region'=>[],'division'=>[],'station'=>[],'post'=>[]];
foreach ($pdo->query("SELECT region_id, COUNT(*) c FROM employees WHERE active=1 AND region_id IS NOT NULL GROUP BY region_id")->fetchAll()   as $r) $pCount['region'][$r['region_id']]     = $r['c'];
foreach ($pdo->query("SELECT division_id, COUNT(*) c FROM employees WHERE active=1 AND division_id IS NOT NULL GROUP BY division_id")->fetchAll() as $r) $pCount['division'][$r['division_id']] = $r['c'];
foreach ($pdo->query("SELECT station_id, COUNT(*) c FROM employees WHERE active=1 AND station_id IS NOT NULL GROUP BY station_id")->fetchAll()   as $r) $pCount['station'][$r['station_id']]   = $r['c'];
foreach ($pdo->query("SELECT post_id, COUNT(*) c FROM employees WHERE active=1 AND post_id IS NOT NULL GROUP BY post_id")->fetchAll()             as $r) $pCount['post'][$r['post_id']]         = $r['c'];

$regions      = $pdo->query("SELECT * FROM regions ORDER BY name")->fetchAll();
$divisions    = $pdo->query("SELECT d.*,r.name AS region_name FROM divisions d JOIN regions r ON r.id=d.region_id ORDER BY r.name,d.name")->fetchAll();
$stations     = $pdo->query("SELECT s.*,d.name AS div_name,r.name AS region_name FROM stations s JOIN divisions d ON d.id=s.division_id JOIN regions r ON r.id=d.region_id ORDER BY r.name,d.name,s.name")->fetchAll();
$posts        = $pdo->query("SELECT p.*,s.name AS sta_name,d.name AS div_name,r.name AS region_name FROM posts p JOIN stations s ON s.id=p.station_id JOIN divisions d ON d.id=s.division_id JOIN regions r ON r.id=d.region_id ORDER BY r.name,d.name,s.name,p.name")->fetchAll();
$directorates = $pdo->query("SELECT d.*, (SELECT COUNT(*) FROM units u WHERE u.directorate_id=d.id) AS unit_count, (SELECT COUNT(*) FROM employees e WHERE e.directorate=d.name AND e.active=1) AS personnel_count FROM directorates d ORDER BY d.name")->fetchAll();
$units        = $pdo->query("SELECT u.*, d.name AS dir_name, (SELECT COUNT(*) FROM employees e WHERE e.unit=u.name AND e.active=1) AS personnel_count FROM units u JOIN directorates d ON d.id=u.directorate_id ORDER BY d.name,u.name")->fetchAll();

$tab = $_GET['tab'] ?? 'geography';

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Pill action buttons ── */
.px { display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;border:none;cursor:pointer;text-decoration:none;line-height:1.4;white-space:nowrap;transition:opacity .15s }
.px:hover { opacity:.82 }
.px .ico { width:12px;height:12px }
.px-view { background:#dbeafe;color:#1d4ed8 }
.px-edit { background:#fef3c7;color:#92400e }
.px-del  { background:#fee2e2;color:#b91c1c }
.px-user { background:#f3e8ff;color:#6d28d9 }
.px-add  { background:#dcfce7;color:#166534 }
.btn-grp { display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end }
/* ── Dialog ── */
dialog { border:none;border-radius:12px;padding:0;box-shadow:0 20px 60px rgba(0,0,0,.25);max-width:520px;width:95vw }
dialog::backdrop { background:rgba(0,0,0,.45) }
.dlg-head { background:var(--navy-800);color:#fff;padding:16px 20px;border-radius:12px 12px 0 0;display:flex;align-items:center;justify-content:space-between }
.dlg-head h4 { margin:0;font-size:14px }
.dlg-body { padding:20px }
.dlg-foot { padding:12px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;background:var(--navy-50);border-radius:0 0 12px 12px }
.dlg-close { background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1 }
/* ── Count pill ── */
.cnt { display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px }
.cnt-grey { background:var(--navy-50);color:var(--muted) }
.cnt-blue { background:#dbeafe;color:#1d4ed8 }
.cnt-green { background:#dcfce7;color:#166534 }
</style>

<div class="page-header">
  <div><h1>Organisational Structure</h1><div class="desc">Manage UPF geographic hierarchy, directorates and functional units</div></div>
</div>
<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<!-- Tabs -->
<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid var(--border)">
  <a href="?tab=geography"   style="padding:10px 22px;font-size:13px;font-weight:600;color:<?= $tab==='geography'?'var(--primary)':'var(--muted)' ?>;border-bottom:2px solid <?= $tab==='geography'?'var(--primary)':'transparent' ?>;margin-bottom:-2px;text-decoration:none">🗺 Geographic Hierarchy</a>
  <a href="?tab=directorates" style="padding:10px 22px;font-size:13px;font-weight:600;color:<?= $tab==='directorates'?'var(--primary)':'var(--muted)' ?>;border-bottom:2px solid <?= $tab==='directorates'?'var(--primary)':'transparent' ?>;margin-bottom:-2px;text-decoration:none">🏢 Directorates &amp; Units</a>
</div>

<?php if ($tab === 'geography'): ?>
<!-- ═══════════════ GEOGRAPHY TAB ═══════════════ -->
<?php
$geoSections = [
    ['lvl'=>'region',   'label'=>'Regions',   'items'=>$regions,   'parentLabel'=>null,       'parents'=>[]],
    ['lvl'=>'division', 'label'=>'Divisions', 'items'=>$divisions, 'parentLabel'=>'Region',   'parents'=>$regions],
    ['lvl'=>'station',  'label'=>'Stations',  'items'=>$stations,  'parentLabel'=>'Division', 'parents'=>$divisions],
    ['lvl'=>'post',     'label'=>'Posts',     'items'=>$posts,     'parentLabel'=>'Station',  'parents'=>$stations],
];
foreach ($geoSections as $sec):
    $lvl = $sec['lvl'];
    $hasLoc = $lvl === 'region';
    $hasParent = !empty($sec['parents']);
?>
<div class="card" style="margin-bottom:18px">
  <div class="chr" style="flex-wrap:wrap;gap:10px">
    <h3><?= $sec['label'] ?> <span class="badge badge-admin"><?= count($sec['items']) ?></span></h3>
    <button class="px px-add" onclick="document.getElementById('dlg-add-<?= $lvl ?>').showModal()">
      <?= ICO_PLUS ?> Add <?= rtrim($sec['label'],'s') ?>
    </button>
  </div>
  <div class="table-wrap"><table>
    <thead><tr>
      <?php if ($lvl==='region'): ?>
        <th>Name</th><th>Code</th><th>Location</th><th style="text-align:center">Divisions</th><th style="text-align:center">Personnel</th><th style="text-align:right;width:130px">Actions</th>
      <?php elseif ($lvl==='division'): ?>
        <th>Region</th><th>Division</th><th>Code</th><th style="text-align:center">Stations</th><th style="text-align:center">Personnel</th><th style="text-align:right;width:130px">Actions</th>
      <?php elseif ($lvl==='station'): ?>
        <th>Region</th><th>Division</th><th>Station</th><th>Code</th><th style="text-align:center">Posts</th><th style="text-align:center">Personnel</th><th style="text-align:right;width:130px">Actions</th>
      <?php else: ?>
        <th>Division</th><th>Station</th><th>Post</th><th>Code</th><th style="text-align:center">Personnel</th><th style="text-align:right;width:130px">Actions</th>
      <?php endif; ?>
    </tr></thead>
    <tbody>
      <?php foreach ($sec['items'] as $row):
        $pid  = (int)$row['id'];
        $pc   = $pCount[$lvl][$pid] ?? 0;
        $jEnc = htmlspecialchars(json_encode($row), ENT_QUOTES);
      ?>
      <tr>
        <?php if ($lvl==='region'): ?>
          <td><strong><?= e($row['name']) ?></strong></td>
          <td style="font-family:monospace;font-size:12px"><?= e($row['code']) ?></td>
          <td class="muted"><?= e($row['location']) ?></td>
          <td style="text-align:center"><?= count(array_filter($divisions,fn($d)=>$d['region_id']===$pid)) ?></td>
          <td style="text-align:center"><span class="cnt cnt-<?= $pc>0?'green':'grey' ?>"><?= $pc ?></span></td>
        <?php elseif ($lvl==='division'): ?>
          <td class="muted" style="font-size:12px"><?= e($row['region_name']) ?></td>
          <td><strong><?= e($row['name']) ?></strong></td>
          <td style="font-family:monospace;font-size:12px"><?= e($row['code']) ?></td>
          <td style="text-align:center"><?= count(array_filter($stations,fn($s)=>$s['division_id']===$pid)) ?></td>
          <td style="text-align:center"><span class="cnt cnt-<?= $pc>0?'green':'grey' ?>"><?= $pc ?></span></td>
        <?php elseif ($lvl==='station'): ?>
          <td class="muted" style="font-size:12px"><?= e($row['region_name']) ?></td>
          <td class="muted" style="font-size:12px"><?= e($row['div_name']) ?></td>
          <td><strong><?= e($row['name']) ?></strong></td>
          <td style="font-family:monospace;font-size:12px"><?= e($row['code']) ?></td>
          <td style="text-align:center"><?= count(array_filter($posts,fn($p)=>$p['station_id']===$pid)) ?></td>
          <td style="text-align:center"><span class="cnt cnt-<?= $pc>0?'green':'grey' ?>"><?= $pc ?></span></td>
        <?php else: ?>
          <td class="muted" style="font-size:12px"><?= e($row['div_name']) ?></td>
          <td class="muted" style="font-size:12px"><?= e($row['sta_name']) ?></td>
          <td><strong><?= e($row['name']) ?></strong></td>
          <td style="font-family:monospace;font-size:12px"><?= e($row['code']) ?></td>
          <td style="text-align:center"><span class="cnt cnt-<?= $pc>0?'green':'grey' ?>"><?= $pc ?></span></td>
        <?php endif; ?>
        <td>
          <div class="btn-grp">
            <button class="px px-edit" onclick='openGeoEdit("<?= $lvl ?>",<?= $jEnc ?>)'><?= ICO_EDIT ?> Edit</button>
            <form method="post" style="display:contents" onsubmit="return confirm('Delete this <?= $lvl ?>? This cannot be undone.')">
              <input type="hidden" name="action" value="delete"><input type="hidden" name="level" value="<?= $lvl ?>">
              <input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="tab" value="geography">
              <button type="submit" class="px px-del"><?= ICO_TRASH ?></button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$sec['items']): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:18px">None yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<!-- ADD dialog -->
<dialog id="dlg-add-<?= $lvl ?>">
  <div class="dlg-head"><h4>Add <?= rtrim($sec['label'],'s') ?></h4><button class="dlg-close" onclick="this.closest('dialog').close()">×</button></div>
  <form method="post">
    <div class="dlg-body" style="display:flex;flex-direction:column;gap:12px">
      <input type="hidden" name="action" value="add"><input type="hidden" name="level" value="<?= $lvl ?>"><input type="hidden" name="tab" value="geography">
      <?php if ($hasParent): ?>
      <div class="form-group"><label>Parent <?= $sec['parentLabel'] ?> <span style="color:var(--red)">*</span></label>
        <select name="parent_id" required><option value="">— select —</option>
          <?php foreach ($sec['parents'] as $p): ?>
          <option value="<?= $p['id'] ?>"><?= e(trim(($p['region_name']??'').' '.($p['div_name']??'').' '.$p['name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group"><label>Name <span style="color:var(--red)">*</span></label><input type="text" name="name" required></div>
      <div class="form-group"><label>Code <span style="color:var(--red)">*</span></label><input type="text" name="code" required maxlength="16" style="text-transform:uppercase"></div>
      <?php if ($hasLoc): ?><div class="form-group"><label>Location</label><input type="text" name="location"></div><?php endif; ?>
    </div>
    <div class="dlg-foot">
      <button type="button" class="btn-icon bi-secondary" onclick="this.closest('dialog').close()" style="width:auto;padding:0 14px;font-size:12px"><?= ICO_CANCEL ?> Cancel</button>
      <button type="submit" class="btn-icon bi-gold bi-lg" style="width:auto;padding:0 16px;font-size:12px"><?= ICO_SAVE ?> Save</button>
    </div>
  </form>
</dialog>

<!-- EDIT dialog -->
<dialog id="dlg-edit-<?= $lvl ?>">
  <div class="dlg-head"><h4>Edit <?= rtrim($sec['label'],'s') ?></h4><button class="dlg-close" onclick="this.closest('dialog').close()">×</button></div>
  <form method="post" id="form-edit-<?= $lvl ?>">
    <div class="dlg-body" style="display:flex;flex-direction:column;gap:12px">
      <input type="hidden" name="action" value="edit"><input type="hidden" name="level" value="<?= $lvl ?>"><input type="hidden" name="tab" value="geography">
      <input type="hidden" name="id" id="edit-<?= $lvl ?>-id">
      <?php if ($hasParent): ?>
      <div class="form-group"><label>Parent <?= $sec['parentLabel'] ?> <span style="color:var(--red)">*</span></label>
        <select name="parent_id" id="edit-<?= $lvl ?>-parent" required><option value="">— select —</option>
          <?php foreach ($sec['parents'] as $p): ?>
          <option value="<?= $p['id'] ?>"><?= e(trim(($p['region_name']??'').' '.($p['div_name']??'').' '.$p['name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group"><label>Name <span style="color:var(--red)">*</span></label><input type="text" name="name" id="edit-<?= $lvl ?>-name" required></div>
      <div class="form-group"><label>Code <span style="color:var(--red)">*</span></label><input type="text" name="code" id="edit-<?= $lvl ?>-code" required maxlength="16" style="text-transform:uppercase"></div>
      <?php if ($hasLoc): ?><div class="form-group"><label>Location</label><input type="text" name="location" id="edit-<?= $lvl ?>-location"></div><?php endif; ?>
    </div>
    <div class="dlg-foot">
      <button type="button" class="btn-icon bi-secondary" onclick="this.closest('dialog').close()" style="width:auto;padding:0 14px;font-size:12px"><?= ICO_CANCEL ?> Cancel</button>
      <button type="submit" class="btn-icon bi-gold bi-lg" style="width:auto;padding:0 16px;font-size:12px"><?= ICO_SAVE ?> Save Changes</button>
    </div>
  </form>
</dialog>
<?php endforeach; ?>

<?php else: ?>
<!-- ═══════════════ DIRECTORATES & UNITS TAB ═══════════════ -->

<!-- Directorates -->
<div class="card" style="margin-bottom:18px">
  <div class="chr" style="flex-wrap:wrap;gap:10px">
    <h3>Directorates <span class="badge badge-admin"><?= count($directorates) ?></span></h3>
    <button class="px px-add" onclick="document.getElementById('dlg-add-directorate').showModal()">
      <?= ICO_PLUS ?> Add Directorate
    </button>
  </div>
  <div class="table-wrap"><table>
    <thead><tr>
      <th>Directorate Name</th><th>Code</th><th>Description</th>
      <th style="text-align:center">Units</th><th style="text-align:center">Personnel</th>
      <th style="text-align:right;width:190px">Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($directorates as $d): ?>
    <tr>
      <td><strong><?= e($d['name']) ?></strong></td>
      <td style="font-family:monospace;font-size:12px"><?= e($d['code']) ?></td>
      <td style="font-size:12px;color:var(--muted)"><?= e($d['description']?:'—') ?></td>
      <td style="text-align:center"><span class="cnt cnt-blue"><?= $d['unit_count'] ?></span></td>
      <td style="text-align:center"><span class="cnt cnt-<?= $d['personnel_count']>0?'green':'grey' ?>"><?= $d['personnel_count'] ?></span></td>
      <td>
        <div class="btn-grp">
          <a class="px px-view" href="/structure-view.php?type=directorate&id=<?= $d['id'] ?>"><?= ICO_EYE ?> View</a>
          <button class="px px-edit" onclick='openDirEdit(<?= htmlspecialchars(json_encode($d),ENT_QUOTES) ?>)'><?= ICO_EDIT ?> Edit</button>
          <form method="post" style="display:contents" onsubmit="return confirm('Delete this directorate? Units inside it will also be removed.')">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="level" value="directorate">
            <input type="hidden" name="id" value="<?= $d['id'] ?>"><input type="hidden" name="tab" value="directorates">
            <button type="submit" class="px px-del"><?= ICO_TRASH ?></button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$directorates): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:18px">No directorates yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<!-- Units -->
<div class="card">
  <div class="chr" style="flex-wrap:wrap;gap:10px">
    <h3>Units / Sub-sections <span class="badge badge-admin"><?= count($units) ?></span></h3>
    <button class="px px-add" onclick="document.getElementById('dlg-add-unit').showModal()">
      <?= ICO_PLUS ?> Add Unit
    </button>
  </div>
  <div class="table-wrap"><table>
    <thead><tr>
      <th>Directorate</th><th>Unit Name</th><th>Code</th><th>Description</th>
      <th style="text-align:center">Personnel</th><th style="text-align:right;width:190px">Actions</th>
    </tr></thead>
    <tbody>
    <?php $prevDir=''; foreach ($units as $u): ?>
    <?php if ($u['dir_name']!==$prevDir): $prevDir=$u['dir_name']; ?>
    <tr style="background:var(--navy-50)"><td colspan="6" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--primary);padding:7px 14px"><?= e($u['dir_name']) ?></td></tr>
    <?php endif; ?>
    <tr>
      <td style="font-size:12px;color:var(--muted)"><?= e($u['dir_name']) ?></td>
      <td><strong><?= e($u['name']) ?></strong></td>
      <td style="font-family:monospace;font-size:12px"><?= e($u['code']) ?></td>
      <td style="font-size:12px;color:var(--muted)"><?= e($u['description']?:'—') ?></td>
      <td style="text-align:center"><span class="cnt cnt-<?= $u['personnel_count']>0?'green':'grey' ?>"><?= $u['personnel_count'] ?></span></td>
      <td>
        <div class="btn-grp">
          <a class="px px-view" href="/structure-view.php?type=unit&id=<?= $u['id'] ?>"><?= ICO_EYE ?> View</a>
          <button class="px px-edit" onclick='openUnitEdit(<?= htmlspecialchars(json_encode($u),ENT_QUOTES) ?>)'><?= ICO_EDIT ?> Edit</button>
          <form method="post" style="display:contents" onsubmit="return confirm('Delete this unit?')">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="level" value="unit">
            <input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="hidden" name="tab" value="directorates">
            <button type="submit" class="px px-del"><?= ICO_TRASH ?></button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$units): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:18px">No units yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<!-- ADD Directorate dialog -->
<dialog id="dlg-add-directorate">
  <div class="dlg-head"><h4>Add New Directorate</h4><button class="dlg-close" onclick="this.closest('dialog').close()">×</button></div>
  <form method="post">
    <div class="dlg-body" style="display:flex;flex-direction:column;gap:12px">
      <input type="hidden" name="action" value="add"><input type="hidden" name="level" value="directorate"><input type="hidden" name="tab" value="directorates">
      <div class="form-group"><label>Name <span style="color:var(--red)">*</span></label><input type="text" name="name" required placeholder="e.g. Special Operations"></div>
      <div class="form-group"><label>Code <span style="color:var(--red)">*</span></label><input type="text" name="code" required maxlength="16" placeholder="SPECOPS" style="text-transform:uppercase"></div>
      <div class="form-group"><label>Description</label><input type="text" name="description" placeholder="Brief description"></div>
    </div>
    <div class="dlg-foot">
      <button type="button" class="btn-icon bi-secondary" onclick="this.closest('dialog').close()" style="width:auto;padding:0 14px;font-size:12px"><?= ICO_CANCEL ?> Cancel</button>
      <button type="submit" class="btn-icon bi-gold bi-lg" style="width:auto;padding:0 16px;font-size:12px"><?= ICO_SAVE ?> Save</button>
    </div>
  </form>
</dialog>

<!-- EDIT Directorate dialog -->
<dialog id="dlg-edit-directorate">
  <div class="dlg-head"><h4>Edit Directorate</h4><button class="dlg-close" onclick="this.closest('dialog').close()">×</button></div>
  <form method="post">
    <div class="dlg-body" style="display:flex;flex-direction:column;gap:12px">
      <input type="hidden" name="action" value="edit"><input type="hidden" name="level" value="directorate"><input type="hidden" name="tab" value="directorates">
      <input type="hidden" name="id" id="edit-dir-id">
      <div class="form-group"><label>Name <span style="color:var(--red)">*</span></label><input type="text" name="name" id="edit-dir-name" required></div>
      <div class="form-group"><label>Code <span style="color:var(--red)">*</span></label><input type="text" name="code" id="edit-dir-code" required maxlength="16" style="text-transform:uppercase"></div>
      <div class="form-group"><label>Description</label><input type="text" name="description" id="edit-dir-desc"></div>
    </div>
    <div class="dlg-foot">
      <button type="button" class="btn-icon bi-secondary" onclick="this.closest('dialog').close()" style="width:auto;padding:0 14px;font-size:12px"><?= ICO_CANCEL ?> Cancel</button>
      <button type="submit" class="btn-icon bi-gold bi-lg" style="width:auto;padding:0 16px;font-size:12px"><?= ICO_SAVE ?> Save Changes</button>
    </div>
  </form>
</dialog>

<!-- ADD Unit dialog -->
<dialog id="dlg-add-unit">
  <div class="dlg-head"><h4>Add New Unit</h4><button class="dlg-close" onclick="this.closest('dialog').close()">×</button></div>
  <form method="post">
    <div class="dlg-body" style="display:flex;flex-direction:column;gap:12px">
      <input type="hidden" name="action" value="add"><input type="hidden" name="level" value="unit"><input type="hidden" name="tab" value="directorates">
      <div class="form-group"><label>Parent Directorate <span style="color:var(--red)">*</span></label>
        <select name="parent_id" required><option value="">— select directorate —</option>
          <?php foreach ($directorates as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Unit Name <span style="color:var(--red)">*</span></label><input type="text" name="name" required></div>
      <div class="form-group"><label>Code <span style="color:var(--red)">*</span></label><input type="text" name="code" required maxlength="16" style="text-transform:uppercase"></div>
      <div class="form-group"><label>Description</label><input type="text" name="description"></div>
    </div>
    <div class="dlg-foot">
      <button type="button" class="btn-icon bi-secondary" onclick="this.closest('dialog').close()" style="width:auto;padding:0 14px;font-size:12px"><?= ICO_CANCEL ?> Cancel</button>
      <button type="submit" class="btn-icon bi-gold bi-lg" style="width:auto;padding:0 16px;font-size:12px"><?= ICO_SAVE ?> Save</button>
    </div>
  </form>
</dialog>

<!-- EDIT Unit dialog -->
<dialog id="dlg-edit-unit">
  <div class="dlg-head"><h4>Edit Unit</h4><button class="dlg-close" onclick="this.closest('dialog').close()">×</button></div>
  <form method="post">
    <div class="dlg-body" style="display:flex;flex-direction:column;gap:12px">
      <input type="hidden" name="action" value="edit"><input type="hidden" name="level" value="unit"><input type="hidden" name="tab" value="directorates">
      <input type="hidden" name="id" id="edit-unit-id">
      <div class="form-group"><label>Parent Directorate <span style="color:var(--red)">*</span></label>
        <select name="parent_id" id="edit-unit-parent" required><option value="">— select directorate —</option>
          <?php foreach ($directorates as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Unit Name <span style="color:var(--red)">*</span></label><input type="text" name="name" id="edit-unit-name" required></div>
      <div class="form-group"><label>Code <span style="color:var(--red)">*</span></label><input type="text" name="code" id="edit-unit-code" required maxlength="16" style="text-transform:uppercase"></div>
      <div class="form-group"><label>Description</label><input type="text" name="description" id="edit-unit-desc"></div>
    </div>
    <div class="dlg-foot">
      <button type="button" class="btn-icon bi-secondary" onclick="this.closest('dialog').close()" style="width:auto;padding:0 14px;font-size:12px"><?= ICO_CANCEL ?> Cancel</button>
      <button type="submit" class="btn-icon bi-gold bi-lg" style="width:auto;padding:0 16px;font-size:12px"><?= ICO_SAVE ?> Save Changes</button>
    </div>
  </form>
</dialog>

<?php endif; ?>

<script>
// ── Geography edit dialogs ──
function openGeoEdit(lvl, row) {
  const set = (id,v) => { const el=document.getElementById(id); if(el) el.value=v||''; };
  set(`edit-${lvl}-id`,   row.id);
  set(`edit-${lvl}-name`, row.name);
  set(`edit-${lvl}-code`, row.code);
  if (lvl==='region')   set('edit-region-location', row.location);
  if (lvl==='division') set('edit-division-parent', row.region_id);
  if (lvl==='station')  set('edit-station-parent',  row.division_id);
  if (lvl==='post')     set('edit-post-parent',      row.station_id);
  document.getElementById(`dlg-edit-${lvl}`).showModal();
}

// ── Directorate edit dialog ──
function openDirEdit(d) {
  document.getElementById('edit-dir-id').value   = d.id;
  document.getElementById('edit-dir-name').value  = d.name;
  document.getElementById('edit-dir-code').value  = d.code;
  document.getElementById('edit-dir-desc').value  = d.description||'';
  document.getElementById('dlg-edit-directorate').showModal();
}

// ── Unit edit dialog ──
function openUnitEdit(u) {
  document.getElementById('edit-unit-id').value     = u.id;
  document.getElementById('edit-unit-name').value   = u.name;
  document.getElementById('edit-unit-code').value   = u.code;
  document.getElementById('edit-unit-desc').value   = u.description||'';
  document.getElementById('edit-unit-parent').value = u.directorate_id;
  document.getElementById('dlg-edit-unit').showModal();
}

// Close dialog on backdrop click
document.querySelectorAll('dialog').forEach(d=>{
  d.addEventListener('click', e=>{ if(e.target===d) d.close(); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
