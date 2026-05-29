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
                'region'   => $pdo->prepare("INSERT INTO regions (name,code,location) VALUES (?,?,?)")->execute([$name,$code,$_POST['location']??'']),
                'division' => $pdo->prepare("INSERT INTO divisions (region_id,name,code) VALUES (?,?,?)")->execute([(int)$_POST['parent_id'],$name,$code]),
                'station'  => $pdo->prepare("INSERT INTO stations (division_id,name,code) VALUES (?,?,?)")->execute([(int)$_POST['parent_id'],$name,$code]),
                'post'     => $pdo->prepare("INSERT INTO posts (station_id,name,code) VALUES (?,?,?)")->execute([(int)$_POST['parent_id'],$name,$code]),
                default    => null,
            };
            flash('msg', ucfirst($level).' added successfully.');
        } catch (\Throwable $e) { flash('err','Error: '.$e->getMessage()); }
    } elseif ($action === 'delete') {
        try {
            match($level) {
                'region'   => $pdo->prepare("DELETE FROM regions WHERE id=?")->execute([$id]),
                'division' => $pdo->prepare("DELETE FROM divisions WHERE id=?")->execute([$id]),
                'station'  => $pdo->prepare("DELETE FROM stations WHERE id=?")->execute([$id]),
                'post'     => $pdo->prepare("DELETE FROM posts WHERE id=?")->execute([$id]),
                default    => null,
            };
            flash('msg', ucfirst($level).' removed.');
        } catch (\Throwable $e) { flash('err','Cannot delete — remove child units first.'); }
    }
    header('Location:/hierarchy.php'); exit;
}

$regions   = $pdo->query("SELECT * FROM regions ORDER BY name")->fetchAll();
$divisions = $pdo->query("SELECT d.*,r.name AS region_name FROM divisions d JOIN regions r ON r.id=d.region_id ORDER BY r.name,d.name")->fetchAll();
$stations  = $pdo->query("SELECT s.*,d.name AS div_name,r.name AS region_name FROM stations s JOIN divisions d ON d.id=s.division_id JOIN regions r ON r.id=d.region_id ORDER BY r.name,d.name,s.name")->fetchAll();
$posts     = $pdo->query("SELECT p.*,s.name AS sta_name,d.name AS div_name,r.name AS region_name FROM posts p JOIN stations s ON s.id=p.station_id JOIN divisions d ON d.id=s.division_id JOIN regions r ON r.id=d.region_id ORDER BY r.name,d.name,s.name,p.name")->fetchAll();

function del_form(string $level, int $id): string {
    return '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this '.htmlspecialchars($level).'?\')">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="level" value="'.htmlspecialchars($level).'">
        <input type="hidden" name="id" value="'.$id.'">
        <button class="btn btn-sm btn-danger">Delete</button></form>';
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Organisational Structure</h1><div class="desc">Manage the police hierarchy: Region → Division → Station → Post</div></div>
</div>
<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<!-- Regions -->
<div class="card">
  <h3>Regions <span class="badge badge-admin"><?= count($regions) ?></span></h3>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
    <input type="hidden" name="action" value="add"><input type="hidden" name="level" value="region">
    <div class="form-group" style="flex:2"><label>Region Name</label><input type="text" name="name" required placeholder="e.g. Kampala Metropolitan"></div>
    <div class="form-group"><label>Code</label><input type="text" name="code" required placeholder="KLA" maxlength="10"></div>
    <div class="form-group"><label>Location</label><input type="text" name="location" placeholder="Kampala"></div>
    <div class="form-group" style="flex:0"><label>&nbsp;</label><button class="btn btn-sm">Add Region</button></div>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Name</th><th>Code</th><th>Location</th><th>Divisions</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($regions as $r): $divCount=count(array_filter($divisions,fn($d)=>$d['region_id']===$r['id'])); ?>
      <tr><td><strong><?= e($r['name']) ?></strong></td><td><?= e($r['code']) ?></td><td><?= e($r['location']) ?></td><td><?= $divCount ?></td>
          <td><?= del_form('region',(int)$r['id']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<!-- Divisions -->
<div class="card">
  <h3>Divisions / Districts <span class="badge badge-admin"><?= count($divisions) ?></span></h3>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
    <input type="hidden" name="action" value="add"><input type="hidden" name="level" value="division">
    <div class="form-group" style="flex:2"><label>Division Name</label><input type="text" name="name" required></div>
    <div class="form-group"><label>Code</label><input type="text" name="code" required maxlength="12"></div>
    <div class="form-group" style="flex:2"><label>Parent Region</label>
      <select name="parent_id" required><option value="">— select —</option>
        <?php foreach ($regions as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:0"><label>&nbsp;</label><button class="btn btn-sm">Add Division</button></div>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Region</th><th>Division</th><th>Code</th><th>Stations</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($divisions as $d): $staCount=count(array_filter($stations,fn($s)=>$s['division_id']===$d['id'])); ?>
      <tr><td class="muted"><?= e($d['region_name']) ?></td><td><?= e($d['name']) ?></td><td><?= e($d['code']) ?></td><td><?= $staCount ?></td>
          <td><?= del_form('division',(int)$d['id']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<!-- Stations -->
<div class="card">
  <h3>Stations <span class="badge badge-admin"><?= count($stations) ?></span></h3>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
    <input type="hidden" name="action" value="add"><input type="hidden" name="level" value="station">
    <div class="form-group" style="flex:2"><label>Station Name</label><input type="text" name="name" required></div>
    <div class="form-group"><label>Code</label><input type="text" name="code" required maxlength="14"></div>
    <div class="form-group" style="flex:2"><label>Parent Division</label>
      <select name="parent_id" required><option value="">— select —</option>
        <?php foreach ($divisions as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['region_name'].' › '.$d['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:0"><label>&nbsp;</label><button class="btn btn-sm">Add Station</button></div>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Region</th><th>Division</th><th>Station</th><th>Code</th><th>Posts</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($stations as $s): $pCount=count(array_filter($posts,fn($p)=>$p['station_id']===$s['id'])); ?>
      <tr><td class="muted"><?= e($s['region_name']) ?></td><td class="muted"><?= e($s['div_name']) ?></td>
          <td><?= e($s['name']) ?></td><td><?= e($s['code']) ?></td><td><?= $pCount ?></td>
          <td><?= del_form('station',(int)$s['id']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<!-- Posts -->
<div class="card">
  <h3>Posts <span class="badge badge-admin"><?= count($posts) ?></span></h3>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
    <input type="hidden" name="action" value="add"><input type="hidden" name="level" value="post">
    <div class="form-group" style="flex:2"><label>Post Name</label><input type="text" name="name" required></div>
    <div class="form-group"><label>Code</label><input type="text" name="code" required maxlength="16"></div>
    <div class="form-group" style="flex:2"><label>Parent Station</label>
      <select name="parent_id" required><option value="">— select —</option>
        <?php foreach ($stations as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['region_name'].' › '.$s['div_name'].' › '.$s['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:0"><label>&nbsp;</label><button class="btn btn-sm">Add Post</button></div>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Region</th><th>Division</th><th>Station</th><th>Post</th><th>Code</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($posts as $p): ?>
      <tr><td class="muted"><?= e($p['region_name']) ?></td><td class="muted"><?= e($p['div_name']) ?></td>
          <td class="muted"><?= e($p['sta_name']) ?></td><td><?= e($p['name']) ?></td><td><?= e($p['code']) ?></td>
          <td><?= del_form('post',(int)$p['id']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
