<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_role(['superadmin']);
$page = 'hierarchy';
$page_title = 'Organisational Structure';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $level  = $_POST['level'] ?? '';
    $name   = trim($_POST['name'] ?? '');
    $code   = strtoupper(trim($_POST['code'] ?? ''));
    $id     = (int)($_POST['id'] ?? 0);

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
        } catch (\Throwable $e) { flash('err','Error: '.$e->getMessage()); }

    } elseif ($action === 'edit') {
        try {
            match($level) {
                'directorate' => $pdo->prepare("UPDATE directorates SET name=?,code=?,description=? WHERE id=?")->execute([$name,$code,trim($_POST['description']??''),$id]),
                'unit'        => $pdo->prepare("UPDATE units SET name=?,code=?,description=?,directorate_id=? WHERE id=?")->execute([$name,$code,trim($_POST['description']??''),(int)$_POST['parent_id'],$id]),
                default       => null,
            };
            flash('msg', ucfirst($level).' updated.');
        } catch (\Throwable $e) { flash('err','Error: '.$e->getMessage()); }

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
        } catch (\Throwable $e) { flash('err','Cannot delete — remove child entries first.'); }
    }
    header('Location:/hierarchy.php?tab='.($_POST['tab']??'geography')); exit;
}

$regions      = $pdo->query("SELECT * FROM regions ORDER BY name")->fetchAll();
$divisions    = $pdo->query("SELECT d.*,r.name AS region_name FROM divisions d JOIN regions r ON r.id=d.region_id ORDER BY r.name,d.name")->fetchAll();
$stations     = $pdo->query("SELECT s.*,d.name AS div_name,r.name AS region_name FROM stations s JOIN divisions d ON d.id=s.division_id JOIN regions r ON r.id=d.region_id ORDER BY r.name,d.name,s.name")->fetchAll();
$posts        = $pdo->query("SELECT p.*,s.name AS sta_name,d.name AS div_name,r.name AS region_name FROM posts p JOIN stations s ON s.id=p.station_id JOIN divisions d ON d.id=s.division_id JOIN regions r ON r.id=d.region_id ORDER BY r.name,d.name,s.name,p.name")->fetchAll();
$directorates = $pdo->query("SELECT d.*, (SELECT COUNT(*) FROM units u WHERE u.directorate_id=d.id) AS unit_count FROM directorates d ORDER BY d.name")->fetchAll();
$units        = $pdo->query("SELECT u.*, d.name AS dir_name FROM units u JOIN directorates d ON d.id=u.directorate_id ORDER BY d.name, u.name")->fetchAll();

$tab = $_GET['tab'] ?? 'geography';

function del_btn(string $level, int $id, string $tab='geography'): string {
    return '<form method="post" style="display:contents" onsubmit="return confirm(\'Delete this '.htmlspecialchars($level).'? This cannot be undone.\')">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="level" value="'.htmlspecialchars($level).'">
        <input type="hidden" name="id" value="'.$id.'">
        <input type="hidden" name="tab" value="'.$tab.'">
        <button type="submit" class="btn-icon bi-danger bi-sm" title="Delete">'.ICO_TRASH.'</button></form>';
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Organisational Structure</h1><div class="desc">Manage geography hierarchy and functional directorates</div></div>
</div>
<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<!-- Tabs -->
<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid var(--border)">
  <a href="?tab=geography" style="padding:10px 22px;font-size:13px;font-weight:600;color:<?= $tab==='geography'?'var(--primary)':'var(--muted)' ?>;border-bottom:2px solid <?= $tab==='geography'?'var(--primary)':'transparent' ?>;margin-bottom:-2px;text-decoration:none">
    🗺 Geographic Hierarchy
  </a>
  <a href="?tab=directorates" style="padding:10px 22px;font-size:13px;font-weight:600;color:<?= $tab==='directorates'?'var(--primary)':'var(--muted)' ?>;border-bottom:2px solid <?= $tab==='directorates'?'var(--primary)':'transparent' ?>;margin-bottom:-2px;text-decoration:none">
    🏢 Directorates &amp; Units
  </a>
</div>

<?php if ($tab === 'geography'): ?>
<!-- ══════════ GEOGRAPHY TAB ══════════ -->
<?php
$sections = [
    ['level'=>'region',   'label'=>'Regions',   'count'=>count($regions),   'parentLabel'=>null,       'parents'=>[]],
    ['level'=>'division', 'label'=>'Divisions', 'count'=>count($divisions), 'parentLabel'=>'Region',   'parents'=>$regions],
    ['level'=>'station',  'label'=>'Stations',  'count'=>count($stations),  'parentLabel'=>'Division', 'parents'=>$divisions],
    ['level'=>'post',     'label'=>'Posts',     'count'=>count($posts),     'parentLabel'=>'Station',  'parents'=>$stations],
];
$tableData = ['region'=>$regions,'division'=>$divisions,'station'=>$stations,'post'=>$posts];
foreach ($sections as $sec):
    $lvl = $sec['level'];
?>
<div class="card">
  <div class="chr">
    <h3><?= $sec['label'] ?> <span class="badge badge-admin"><?= $sec['count'] ?></span></h3>
    <details class="form-panel">
      <summary><button type="button" class="btn-icon bi-primary" title="Add"><?= ICO_PLUS ?> <span style="font-size:11px;margin-left:2px">Add <?= ucfirst($lvl) ?></span></button></summary>
      <div class="form-body">
        <h4 style="margin:0 0 12px;color:var(--navy-800)">Add <?= ucfirst($lvl) ?></h4>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="level" value="<?= $lvl ?>">
          <input type="hidden" name="tab" value="geography">
          <div class="form-group" style="flex:3;min-width:180px"><label>Name</label><input type="text" name="name" required></div>
          <div class="form-group" style="min-width:100px"><label>Code</label><input type="text" name="code" required maxlength="16" style="text-transform:uppercase"></div>
          <?php if ($lvl==='region'): ?>
            <div class="form-group" style="min-width:140px"><label>Location</label><input type="text" name="location"></div>
          <?php endif; ?>
          <?php if (!empty($sec['parents'])): ?>
            <div class="form-group" style="flex:2;min-width:180px"><label>Parent <?= $sec['parentLabel'] ?></label>
              <select name="parent_id" required><option value="">— select —</option>
                <?php foreach ($sec['parents'] as $p): ?>
                  <option value="<?= $p['id'] ?>"><?= e(trim(($p['region_name']??'').' '.($p['div_name']??'').' '.$p['name'])) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
          <div class="form-group" style="flex:0"><label>&nbsp;</label>
            <button class="btn-icon bi-gold bi-lg" type="submit"><?= ICO_SAVE ?></button>
          </div>
        </form>
      </div>
    </details>
  </div>
  <div class="table-wrap"><table>
    <thead><tr>
      <?php if ($lvl==='region'): ?>
        <th>Name</th><th>Code</th><th>Location</th><th>Divisions</th><th style="width:48px"></th>
      <?php elseif ($lvl==='division'): ?>
        <th>Region</th><th>Division</th><th>Code</th><th>Stations</th><th style="width:48px"></th>
      <?php elseif ($lvl==='station'): ?>
        <th>Region</th><th>Division</th><th>Station</th><th>Code</th><th>Posts</th><th style="width:48px"></th>
      <?php else: ?>
        <th>Region</th><th>Division</th><th>Station</th><th>Post</th><th>Code</th><th style="width:48px"></th>
      <?php endif; ?>
    </tr></thead>
    <tbody>
      <?php if ($lvl==='region'): foreach ($regions as $r):
        $dc=count(array_filter($divisions,fn($d)=>$d['region_id']===$r['id'])); ?>
        <tr><td><strong><?= e($r['name']) ?></strong></td><td style="font-family:monospace"><?= e($r['code']) ?></td><td class="muted"><?= e($r['location']) ?></td><td><?= $dc ?></td><td><?= del_btn('region',(int)$r['id'],'geography') ?></td></tr>
      <?php endforeach; elseif ($lvl==='division'): foreach ($divisions as $d):
        $sc=count(array_filter($stations,fn($s)=>$s['division_id']===$d['id'])); ?>
        <tr><td class="muted"><?= e($d['region_name']) ?></td><td><strong><?= e($d['name']) ?></strong></td><td style="font-family:monospace"><?= e($d['code']) ?></td><td><?= $sc ?></td><td><?= del_btn('division',(int)$d['id'],'geography') ?></td></tr>
      <?php endforeach; elseif ($lvl==='station'): foreach ($stations as $s):
        $pc=count(array_filter($posts,fn($p)=>$p['station_id']===$s['id'])); ?>
        <tr><td class="muted"><?= e($s['region_name']) ?></td><td class="muted"><?= e($s['div_name']) ?></td><td><strong><?= e($s['name']) ?></strong></td><td style="font-family:monospace"><?= e($s['code']) ?></td><td><?= $pc ?></td><td><?= del_btn('station',(int)$s['id'],'geography') ?></td></tr>
      <?php endforeach; elseif ($lvl==='post'): foreach ($posts as $p): ?>
        <tr><td class="muted"><?= e($p['region_name']) ?></td><td class="muted"><?= e($p['div_name']) ?></td><td class="muted"><?= e($p['sta_name']) ?></td><td><strong><?= e($p['name']) ?></strong></td><td style="font-family:monospace"><?= e($p['code']) ?></td><td><?= del_btn('post',(int)$p['id'],'geography') ?></td></tr>
      <?php endforeach; endif; ?>
      <?php if (!$tableData[$lvl]): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:18px">None yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endforeach; ?>

<?php else: ?>
<!-- ══════════ DIRECTORATES & UNITS TAB ══════════ -->

<!-- Directorates card -->
<div class="card" style="margin-bottom:20px">
  <div class="chr">
    <h3>Directorates <span class="badge badge-admin"><?= count($directorates) ?></span></h3>
    <details class="form-panel">
      <summary><button type="button" class="btn-icon bi-primary"><?= ICO_PLUS ?> <span style="font-size:11px;margin-left:2px">Add Directorate</span></button></summary>
      <div class="form-body">
        <h4 style="margin:0 0 12px">Add New Directorate</h4>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="level" value="directorate">
          <input type="hidden" name="tab" value="directorates">
          <div class="form-group" style="flex:3;min-width:180px"><label>Name <span style="color:var(--red)">*</span></label><input type="text" name="name" required placeholder="e.g. Special Operations"></div>
          <div class="form-group" style="min-width:100px"><label>Code <span style="color:var(--red)">*</span></label><input type="text" name="code" required maxlength="16" placeholder="SPECOPS" style="text-transform:uppercase"></div>
          <div class="form-group" style="flex:3;min-width:200px"><label>Description</label><input type="text" name="description" placeholder="Brief description"></div>
          <div class="form-group" style="flex:0"><label>&nbsp;</label><button class="btn-icon bi-gold bi-lg" type="submit"><?= ICO_SAVE ?></button></div>
        </form>
      </div>
    </details>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Directorate Name</th><th>Code</th><th>Description</th><th style="text-align:center">Units</th><th style="width:50px"></th></tr></thead>
    <tbody>
      <?php foreach ($directorates as $d): ?>
      <tr id="dir-row-<?= $d['id'] ?>">
        <td><strong><?= e($d['name']) ?></strong></td>
        <td style="font-family:monospace;font-size:12px"><?= e($d['code']) ?></td>
        <td style="font-size:12px;color:var(--muted)"><?= e($d['description'] ?: '—') ?></td>
        <td style="text-align:center">
          <span class="badge badge-admin"><?= $d['unit_count'] ?></span>
        </td>
        <td><?= del_btn('directorate',(int)$d['id'],'directorates') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$directorates): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:18px">No directorates yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<!-- Units card -->
<div class="card">
  <div class="chr">
    <h3>Units / Sub-sections <span class="badge badge-admin"><?= count($units) ?></span></h3>
    <details class="form-panel">
      <summary><button type="button" class="btn-icon bi-primary"><?= ICO_PLUS ?> <span style="font-size:11px;margin-left:2px">Add Unit</span></button></summary>
      <div class="form-body">
        <h4 style="margin:0 0 12px">Add New Unit</h4>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="level" value="unit">
          <input type="hidden" name="tab" value="directorates">
          <div class="form-group" style="flex:2;min-width:180px"><label>Parent Directorate <span style="color:var(--red)">*</span></label>
            <select name="parent_id" required>
              <option value="">— select directorate —</option>
              <?php foreach ($directorates as $d): ?>
              <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:3;min-width:180px"><label>Unit Name <span style="color:var(--red)">*</span></label><input type="text" name="name" required placeholder="e.g. Flying Squad"></div>
          <div class="form-group" style="min-width:100px"><label>Code <span style="color:var(--red)">*</span></label><input type="text" name="code" required maxlength="16" placeholder="FS" style="text-transform:uppercase"></div>
          <div class="form-group" style="flex:2;min-width:160px"><label>Description</label><input type="text" name="description" placeholder="Optional"></div>
          <div class="form-group" style="flex:0"><label>&nbsp;</label><button class="btn-icon bi-gold bi-lg" type="submit"><?= ICO_SAVE ?></button></div>
        </form>
      </div>
    </details>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Directorate</th><th>Unit Name</th><th>Code</th><th>Description</th><th style="width:50px"></th></tr></thead>
    <tbody>
      <?php
      $prevDir = '';
      foreach ($units as $u):
        $isNew = $u['dir_name'] !== $prevDir;
        $prevDir = $u['dir_name'];
      ?>
      <?php if ($isNew): ?>
      <tr style="background:var(--navy-50)">
        <td colspan="5" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--primary);padding:8px 14px">
          <?= e($u['dir_name']) ?>
        </td>
      </tr>
      <?php endif; ?>
      <tr>
        <td style="font-size:12px;color:var(--muted)"><?= e($u['dir_name']) ?></td>
        <td><strong><?= e($u['name']) ?></strong></td>
        <td style="font-family:monospace;font-size:12px"><?= e($u['code']) ?></td>
        <td style="font-size:12px;color:var(--muted)"><?= e($u['description'] ?: '—') ?></td>
        <td><?= del_btn('unit',(int)$u['id'],'directorates') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$units): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:18px">No units yet. Add units under a directorate above.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php endif; ?>

<script>
document.querySelectorAll('details.form-panel').forEach(d=>{
  d.addEventListener('toggle',()=>{ if(d.open) document.querySelectorAll('details.form-panel').forEach(o=>{ if(o!==d) o.open=false; }); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
