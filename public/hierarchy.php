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
            flash('msg', ucfirst($level).' added.');
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

// Helper: delete button
function del_ico(string $level, int $id): string {
    return '<form method="post" style="display:contents" onsubmit="return confirm(\'Delete this '.htmlspecialchars($level).'?\')">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="level" value="'.htmlspecialchars($level).'">
        <input type="hidden" name="id" value="'.$id.'">
        <button type="submit" class="btn-icon bi-danger bi-sm" title="Delete">'.ICO_TRASH.'</button></form>';
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Organisational Structure</h1><div class="desc">Region → Division → Station → Post hierarchy management</div></div>
</div>
<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<?php
$sections = [
    ['level'=>'region',   'label'=>'Regions',    'count'=>count($regions),   'parentLabel'=>null,        'parents'=>[]],
    ['level'=>'division', 'label'=>'Divisions',  'count'=>count($divisions), 'parentLabel'=>'Region',    'parents'=>$regions],
    ['level'=>'station',  'label'=>'Stations',   'count'=>count($stations),  'parentLabel'=>'Division',  'parents'=>$divisions],
    ['level'=>'post',     'label'=>'Posts',       'count'=>count($posts),     'parentLabel'=>'Station',   'parents'=>$stations],
];

$tableData = [
    'region'   => $regions,
    'division' => $divisions,
    'station'  => $stations,
    'post'     => $posts,
];
$tableHeaders = [
    'region'   => ['Name','Code','Location','Divisions'],
    'division' => ['Region','Division','Code','Stations'],
    'station'  => ['Region','Division','Station','Code','Posts'],
    'post'     => ['Region','Division','Station','Post','Code'],
];
foreach ($sections as $sec):
    $lvl = $sec['level'];
?>
<div class="card">
  <div class="chr">
    <h3><?= $sec['label'] ?> <span class="badge badge-admin"><?= $sec['count'] ?></span></h3>
    <details class="form-panel">
      <summary><button type="button" class="btn-icon bi-primary" title="Add <?= strtolower($sec['label']) ?>"><?= ICO_PLUS ?></button></summary>
      <div class="form-body">
        <h4 style="margin:0 0 12px;color:var(--navy-800)">Add <?= ucfirst($lvl) ?></h4>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="level" value="<?= $lvl ?>">
          <div class="form-group" style="flex:2"><label>Name</label><input type="text" name="name" required placeholder="e.g. <?= $sec['label'] ?> name"></div>
          <div class="form-group"><label>Code</label><input type="text" name="code" required placeholder="CODE" maxlength="16"></div>
          <?php if ($lvl==='region'): ?>
            <div class="form-group"><label>Location</label><input type="text" name="location" placeholder="City/Town"></div>
          <?php endif; ?>
          <?php if (!empty($sec['parents'])): ?>
            <div class="form-group" style="flex:2"><label>Parent <?= $sec['parentLabel'] ?></label>
              <select name="parent_id" required><option value="">— select —</option>
                <?php foreach ($sec['parents'] as $p): ?>
                  <option value="<?= $p['id'] ?>"><?= e(($p['region_name']??'').' '.($p['div_name']??'').' '.$p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
          <div class="form-group" style="flex:0">
            <label>&nbsp;</label>
            <button class="btn-icon bi-gold bi-lg" type="submit" title="Save"><?= ICO_SAVE ?></button>
          </div>
        </form>
      </div>
    </details>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr><?php foreach ($tableHeaders[$lvl] as $h): ?><th><?= $h ?></th><?php endforeach; ?><th style="width:48px"></th></tr></thead>
      <tbody>
        <?php if ($lvl==='region'): foreach ($regions as $r): $dc=count(array_filter($divisions,fn($d)=>$d['region_id']===$r['id'])); ?>
        <tr><td><strong><?= e($r['name']) ?></strong></td><td><?= e($r['code']) ?></td><td><?= e($r['location']) ?></td><td><?= $dc ?></td><td><?= del_ico('region',(int)$r['id']) ?></td></tr>
        <?php endforeach; elseif ($lvl==='division'): foreach ($divisions as $d): $sc=count(array_filter($stations,fn($s)=>$s['division_id']===$d['id'])); ?>
        <tr><td class="muted"><?= e($d['region_name']) ?></td><td><strong><?= e($d['name']) ?></strong></td><td><?= e($d['code']) ?></td><td><?= $sc ?></td><td><?= del_ico('division',(int)$d['id']) ?></td></tr>
        <?php endforeach; elseif ($lvl==='station'): foreach ($stations as $s): $pc=count(array_filter($posts,fn($p)=>$p['station_id']===$s['id'])); ?>
        <tr><td class="muted"><?= e($s['region_name']) ?></td><td class="muted"><?= e($s['div_name']) ?></td><td><strong><?= e($s['name']) ?></strong></td><td><?= e($s['code']) ?></td><td><?= $pc ?></td><td><?= del_ico('station',(int)$s['id']) ?></td></tr>
        <?php endforeach; elseif ($lvl==='post'): foreach ($posts as $p): ?>
        <tr><td class="muted"><?= e($p['region_name']) ?></td><td class="muted"><?= e($p['div_name']) ?></td><td class="muted"><?= e($p['sta_name']) ?></td><td><strong><?= e($p['name']) ?></strong></td><td><?= e($p['code']) ?></td><td><?= del_ico('post',(int)$p['id']) ?></td></tr>
        <?php endforeach; endif; ?>
        <?php if (!$tableData[$lvl]): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:18px">None yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<script>
document.querySelectorAll('details.form-panel').forEach(d=>{
  d.addEventListener('toggle',()=>{ if(d.open) document.querySelectorAll('details.form-panel').forEach(o=>{ if(o!==d) o.open=false; }); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
