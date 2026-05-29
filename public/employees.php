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
        // Scope: superadmin picks post, others locked to their scope
        $post_id = null;
        if (is_superadmin($user) || role_rank($user['role']) >= role_rank('regional_commander')) {
            $post_id = (int)($_POST['post_id'] ?? 0) ?: null;
        } else {
            $post_id = (int)$user['post_id'] ?: null;
        }
        if (!$post_id) { flash('err','A post assignment is required.'); header('Location:/employees.php');exit; }
        // Resolve parent chain
        $chain = $pdo->prepare("SELECT s.division_id, d.region_id, s.id AS station_id FROM posts p JOIN stations s ON s.id=p.station_id JOIN divisions d ON d.id=s.division_id WHERE p.id=?");
        $chain->execute([$post_id]); $ch = $chain->fetch();
        if (!$ch) { flash('err','Invalid post.'); header('Location:/employees.php');exit; }

        if ($action === 'create') {
            try {
                $pdo->prepare("INSERT INTO employees (service_no,full_name,gender,rank,region_id,division_id,station_id,post_id,phone) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$sno,$name,$gender,$rank,$ch['region_id'],$ch['division_id'],$ch['station_id'],$post_id,$phone]);
                flash('msg','Employee added.');
            } catch (\PDOException $ex) { flash('err','Could not add: '.$ex->getMessage()); }
        } else {
            $id = (int)$_POST['id'];
            $pdo->prepare("UPDATE employees SET service_no=?,full_name=?,gender=?,rank=?,region_id=?,division_id=?,station_id=?,post_id=?,phone=? WHERE id=?")
                ->execute([$sno,$name,$gender,$rank,$ch['region_id'],$ch['division_id'],$ch['station_id'],$post_id,$phone,$id]);
            flash('msg','Employee updated.');
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM employees WHERE id=? AND $scopeW")->execute(array_merge([$id], $scopeP));
        flash('msg','Employee removed.');
    }
    header('Location:/employees.php'); exit;
}

$search = trim($_GET['q'] ?? '');
$where  = "e.active=1 AND $scopeW";
$params = $scopeP;
if ($search) {
    $where .= ' AND (e.full_name LIKE ? OR e.service_no LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%";
}

$stmt = $pdo->prepare("
    SELECT e.*, rg.name AS region_name, dv.name AS division_name, st.name AS station_name, pt.name AS post_name
    FROM employees e
    LEFT JOIN regions   rg ON rg.id=e.region_id
    LEFT JOIN divisions dv ON dv.id=e.division_id
    LEFT JOIN stations  st ON st.id=e.station_id
    LEFT JOIN posts     pt ON pt.id=e.post_id
    WHERE $where ORDER BY rg.name,dv.name,st.name,pt.name,e.full_name
");
$stmt->execute($params);
$employees = $stmt->fetchAll();

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = null;
if ($editId) {
    $s = $pdo->prepare("SELECT e.* FROM employees e WHERE e.id=? AND $scopeW");
    $s->execute(array_merge([$editId], $scopeP)); $editing = $s->fetch() ?: null;
}

// Build post picker (scoped)
$canPickPost = role_rank($user['role']) >= role_rank('division_commander');
$posts = [];
if ($canPickPost) {
    [$pw,$pp] = scope_where($user, 'p');
    // Re-scope for posts via joins
    $psql = "SELECT p.id, p.name, s.name AS sta, d.name AS div, rg.name AS reg
             FROM posts p JOIN stations s ON s.id=p.station_id JOIN divisions d ON d.id=s.division_id JOIN regions rg ON rg.id=d.region_id";
    if (!is_superadmin($user)) {
        // Filter by user's scope joining back to employees path
        $psql .= " WHERE " . match($user['role']) {
            'regional_commander' => "d.region_id=".(int)$user['region_id'],
            'division_commander' => "s.division_id=".(int)$user['division_id'],
            'station_commander'  => "p.station_id=".(int)$user['station_id'],
            default              => "p.id=".(int)$user['post_id'],
        };
    }
    $psql .= " ORDER BY reg, div, sta, p.name";
    $posts = $pdo->query($psql)->fetchAll();
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Personnel</h1>
    <div class="desc">
      <?= is_superadmin($user) ? 'All personnel across the force' : 'Personnel under your command — '.e(user_scope_label($user)) ?>
    </div>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<div class="card">
  <h3><?= $editing ? 'Edit Personnel Record' : 'Add Personnel' ?></h3>
  <form method="post">
    <input type="hidden" name="action" value="<?= $editing?'update':'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
    <div class="form-row">
      <div class="form-group"><label>Service No</label><input type="text" name="service_no" required value="<?= e($editing['service_no']??'') ?>"></div>
      <div class="form-group" style="flex:2"><label>Full Name</label><input type="text" name="full_name" required value="<?= e($editing['full_name']??'') ?>"></div>
      <div class="form-group"><label>Rank</label><input type="text" name="rank" required value="<?= e($editing['rank']??'') ?>" list="ranks-list">
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
      <div class="form-group" style="flex:0"><label>&nbsp;</label>
        <button class="btn"><?= $editing?'Update':'Add' ?></button>
        <?php if ($editing): ?><a class="btn btn-secondary" href="/employees.php">Cancel</a><?php endif; ?>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <form method="get" class="form-row" style="margin-bottom:12px">
    <div class="form-group"><label>Search</label><input type="text" name="q" placeholder="Name or Service No" value="<?= e($search) ?>"></div>
    <div class="form-group" style="flex:0"><label>&nbsp;</label><button class="btn btn-secondary">Search</button></div>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Service No</th><th>Name</th><th>Rank</th><th>Gender</th><th>Region</th><th>Division</th><th>Station</th><th>Post</th><th>Phone</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($employees as $e): ?>
        <tr>
          <td><?= e($e['service_no']) ?></td>
          <td><?= e($e['full_name']) ?></td>
          <td><?= e($e['rank']) ?></td>
          <td><?= $e['gender']==='M'?'Male':'Female' ?></td>
          <td><?= e($e['region_name']??'—') ?></td>
          <td><?= e($e['division_name']??'—') ?></td>
          <td><?= e($e['station_name']??'—') ?></td>
          <td><?= e($e['post_name']??'—') ?></td>
          <td><?= e($e['phone']??'') ?></td>
          <td style="white-space:nowrap">
            <a class="btn btn-sm btn-secondary" href="/employees.php?edit=<?= $e['id'] ?>">Edit</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Remove this record?')">
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $e['id'] ?>">
              <button class="btn btn-sm btn-danger">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$employees): ?><tr><td colspan="10" style="text-align:center;color:var(--muted);padding:24px">No personnel found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
