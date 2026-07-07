<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_login();
$page = 'employees';
$page_title = 'Personnel';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['create','update'], true)) {
        $sno  = trim($_POST['service_no'] ?? '');
        $name = trim($_POST['full_name'] ?? '');
        $rank = trim($_POST['rank'] ?? '');
        $gender = $_POST['gender'] ?? 'M';
        $phone  = trim($_POST['phone'] ?? '');
        $post_id = null;
        if (role_rank($user['role']) >= role_rank('division_commander')) {
            $post_id = (int)($_POST['post_id'] ?? 0) ?: null;
        } else {
            $post_id = (int)$user['post_id'] ?: null;
        }
        if (!$post_id) { flash('err','A post assignment is required.'); header('Location:/employees.php');exit; }
        $chain = $pdo->prepare("SELECT s.division_id, d.region_id, s.id AS station_id FROM posts p JOIN stations s ON s.id=p.station_id JOIN divisions d ON d.id=s.division_id WHERE p.id=?");
        $chain->execute([$post_id]); $ch = $chain->fetch();
        if (!$ch) { flash('err','Invalid post.'); header('Location:/employees.php');exit; }
        if ($action === 'create') {
            try {
                $pdo->prepare("INSERT INTO employees (service_no,full_name,gender,rank,region_id,division_id,station_id,post_id,phone) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$sno,$name,$gender,$rank,$ch['region_id'],$ch['division_id'],$ch['station_id'],$post_id,$phone]);
                flash('msg','Personnel record created.');
            } catch (\PDOException $ex) { flash('err','Could not add: '.$ex->getMessage()); }
        } else {
            $id = (int)$_POST['id'];
            $pdo->prepare("UPDATE employees SET service_no=?,full_name=?,gender=?,rank=?,region_id=?,division_id=?,station_id=?,post_id=?,phone=? WHERE id=?")
                ->execute([$sno,$name,$gender,$rank,$ch['region_id'],$ch['division_id'],$ch['station_id'],$post_id,$phone,$id]);
            flash('msg','Record updated.');
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM employees WHERE id=? AND $scopeW")->execute(array_merge([$id], $scopeP));
        flash('msg','Record removed.');
    }
    header('Location:/employees.php'); exit;
}

$search = trim($_GET['q'] ?? '');
$where  = "e.active=1 AND $scopeW";
$params = $scopeP;
if ($search) { $where .= ' AND (e.full_name LIKE ? OR e.service_no LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$employees = $pdo->prepare("SELECT e.*, rg.name AS region_name, dv.name AS division_name, st.name AS station_name, pt.name AS post_name FROM employees e LEFT JOIN regions rg ON rg.id=e.region_id LEFT JOIN divisions dv ON dv.id=e.division_id LEFT JOIN stations st ON st.id=e.station_id LEFT JOIN posts pt ON pt.id=e.post_id WHERE $where ORDER BY rg.name,dv.name,st.name,pt.name,e.full_name");
$employees->execute($params); $employees = $employees->fetchAll();

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = null;
if ($editId) { $s=$pdo->prepare("SELECT e.* FROM employees e WHERE e.id=? AND $scopeW"); $s->execute(array_merge([$editId],$scopeP)); $editing=$s->fetch()?:null; }

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

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Personnel</h1>
    <div class="desc"><?= is_superadmin($user) ? 'All force personnel' : 'Command: '.e(user_scope_label($user)) ?></div>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<div class="card">
  <div class="chr">
    <h3><?= $editing ? 'Editing Record — '.e($editing['full_name']) : 'Personnel List' ?></h3>
    <div class="action-bar">
      <!-- Search form inline toggle -->
      <details class="form-panel" id="search-panel">
        <summary><button type="button" class="btn-icon bi-secondary" title="Search personnel"><?= ICO_SEARCH ?></button></summary>
        <div class="form-body" style="padding:14px 16px">
          <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group"><label>Search name / service no</label><input type="text" name="q" value="<?= e($search) ?>" autofocus placeholder="Type to search…"></div>
            <button class="btn-icon bi-primary" type="submit" title="Run search"><?= ICO_SEARCH ?></button>
            <?php if ($search): ?><a class="btn-icon bi-secondary" href="/employees.php" title="Clear search"><?= ICO_CANCEL ?></a><?php endif; ?>
          </form>
        </div>
      </details>
      <!-- Add / Edit form toggle -->
      <details class="form-panel" id="emp-form" <?= $editing ? 'open' : '' ?>>
        <summary><button type="button" class="btn-icon bi-primary" title="<?= $editing ? 'Edit record' : 'Add personnel' ?>"><?= $editing ? ICO_EDIT : ICO_PLUS ?></button></summary>
        <div class="form-body">
          <h4 style="margin:0 0 14px;color:var(--navy-800)"><?= $editing ? 'Edit Personnel Record' : 'Add New Personnel' ?></h4>
          <form method="post">
            <input type="hidden" name="action" value="<?= $editing?'update':'create' ?>">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
            <div class="form-row">
              <div class="form-group"><label>Service No</label><input type="text" name="service_no" required value="<?= e($editing['service_no']??'') ?>"></div>
              <div class="form-group" style="flex:2"><label>Full Name</label><input type="text" name="full_name" required value="<?= e($editing['full_name']??'') ?>"></div>
              <div class="form-group"><label>Rank</label>
                <input type="text" name="rank" required value="<?= e($editing['rank']??'') ?>" list="ranks-list">
                <datalist id="ranks-list"><?php foreach (['Constable','Corporal','Sergeant','Inspector','ASP','SP','SSP','Commissioner','AIG','DIG','IGP'] as $r): ?><option value="<?= $r ?>"><?php endforeach; ?></datalist>
              </div>
              <div class="form-group"><label>Gender</label>
                <select name="gender"><option value="M" <?= ($editing['gender']??'')==='M'?'selected':'' ?>>Male</option><option value="F" <?= ($editing['gender']??'')==='F'?'selected':'' ?>>Female</option></select>
              </div>
              <?php if ($canPickPost): ?>
              <div class="form-group" style="flex:2"><label>Assigned Post</label>
                <select name="post_id" required>
                  <option value="">— select post —</option>
                  <?php foreach ($posts as $pt): ?>
                    <option value="<?= $pt['id'] ?>" <?= ($editing['post_id']??0)==$pt['id']?'selected':'' ?>><?= e("{$pt['reg']} › {$pt['div']} › {$pt['sta']} › {$pt['name']}") ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php else: ?>
                <input type="hidden" name="post_id" value="<?= (int)$user['post_id'] ?>">
                <div class="form-group" style="flex:2"><label>Post</label><input type="text" disabled value="<?= e(user_scope_label($user)) ?>"></div>
              <?php endif; ?>
              <div class="form-group"><label>Phone</label><input type="tel" name="phone" value="<?= e($editing['phone']??'') ?>"></div>
            </div>
            <div class="action-bar" style="margin-top:10px">
              <button class="btn-icon bi-gold bi-lg" type="submit" title="<?= $editing?'Save changes':'Save record' ?>"><?= ICO_SAVE ?></button>
              <?php if ($editing): ?><a class="btn-icon bi-secondary bi-lg" href="/employees.php" title="Cancel"><?= ICO_CANCEL ?></a><?php endif; ?>
            </div>
          </form>
        </div>
      </details>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Svc No</th><th>Name</th><th>Rank</th><th>Gender</th><th>Region</th><th>Division</th><th>Station</th><th>Post</th><th>Phone</th><th style="width:72px"></th></tr></thead>
      <tbody>
        <?php foreach ($employees as $e): ?>
        <tr>
          <td><?= e($e['service_no']) ?></td>
          <td><strong><?= e($e['full_name']) ?></strong></td>
          <td><?= e($e['rank']) ?></td>
          <td><?= $e['gender']==='M'?'M':'F' ?></td>
          <td><?= e($e['region_name']??'—') ?></td>
          <td><?= e($e['division_name']??'—') ?></td>
          <td><?= e($e['station_name']??'—') ?></td>
          <td><?= e($e['post_name']??'—') ?></td>
          <td><?= e($e['phone']??'') ?></td>
          <td style="white-space:nowrap">
            <div class="action-bar">
              <a class="btn-icon bi-secondary bi-sm" href="/employees.php?edit=<?= $e['id'] ?>" title="Edit <?= e($e['full_name']) ?>"><?= ICO_EDIT ?></a>
              <form method="post" style="display:contents" onsubmit="return confirm('Remove <?= e(addslashes($e['full_name'])) ?>?')">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $e['id'] ?>">
                <button class="btn-icon bi-danger bi-sm" type="submit" title="Delete"><?= ICO_TRASH ?></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$employees): ?><tr><td colspan="10" style="text-align:center;color:var(--muted);padding:24px">No personnel found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Auto-open panels based on URL params
(function(){
  if(<?= $editId ? 'true' : 'false' ?>) {
    var fp = document.getElementById('emp-form');
    if(fp) fp.open = true;
  }
  // Close sibling panels when one opens
  document.querySelectorAll('details.form-panel').forEach(function(d){
    d.addEventListener('toggle', function(){
      if(d.open) document.querySelectorAll('details.form-panel').forEach(function(o){ if(o!==d) o.open=false; });
    });
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
