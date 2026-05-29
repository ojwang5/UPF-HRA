<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
$user = require_min_rank('post_commander');
$page = 'reports';
$page_title = 'Reports';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

$date = $_GET['date'] ?? date('Y-m-d');

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='generate') {
    $rows   = hierarchy_summary($pdo, $date, $user);
    $status = is_superadmin($user) ? 'approved' : (role_rank($user['role']) >= role_rank('regional_commander') ? 'pending_superadmin' : 'pending_commander');
    // Determine scope IDs
    $rId=$dId=$sId=$pId=null;
    $scopeLevel = match($user['role']) {
        'superadmin' => 'force', 'regional_commander' => 'region',
        'division_commander' => 'division', 'station_commander' => 'station',
        default => 'post',
    };
    if ($user['region_id'])   $rId=(int)$user['region_id'];
    if ($user['division_id']) $dId=(int)$user['division_id'];
    if ($user['station_id'])  $sId=(int)$user['station_id'];
    if ($user['post_id'])     $pId=(int)$user['post_id'];

    $pdo->prepare("INSERT INTO reports (post_id,station_id,division_id,region_id,scope_level,date,generated_by,generated_at,summary_json,status) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$pId,$sId,$dId,$rId,$scopeLevel,$date,$user['id'],date('c'),json_encode($rows),$status]);
    $rid = (int)$pdo->lastInsertId();

    if ($status==='pending_superadmin' || $status==='pending_commander') {
        notify_superadmins('New report awaiting approval',
            role_label($user['role']).' '.e(user_scope_label($user)).' — '.date('j M Y',strtotime($date)),
            ['link'=>'/history.php?id='.$rid,'created_by'=>$user['id'],'kind'=>'report']);
    }
    flash('msg', $status==='approved' ? 'Report saved.' : 'Report submitted for review.');
    header('Location:/reports.php?date='.urlencode($date)); exit;
}

$rows = hierarchy_summary($pdo, $date, $user);
$tot  = sum_totals($rows);
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Reports</h1><div class="desc">Generate and submit attendance reports</div></div>
  <form method="get" class="form-row" style="margin:0">
    <div class="form-group"><label>Date</label><input type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()"></div>
  </form>
</div>
<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h3 style="margin:0">Attendance Report — <?= e(date('j F Y',strtotime($date))) ?> · <?= e(user_scope_label($user)) ?></h3>
    <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="generate">
        <button class="btn"><?= is_superadmin($user) ? 'Save to History' : 'Submit Report' ?></button>
      </form>
      <a class="btn btn-secondary" href="/export.php?type=csv&date=<?= e($date) ?>" target="_blank">Export CSV</a>
      <a class="btn btn-secondary" href="/export.php?type=print&date=<?= e($date) ?>" target="_blank">Print / PDF</a>
    </div>
  </div>
  <?php if (!is_superadmin($user)): ?>
  <div class="muted" style="margin-top:6px;font-size:12px">Reports are submitted to higher command for review and approval.</div>
  <?php endif; ?>
  <div class="table-wrap" style="margin-top:14px">
    <table>
      <thead><tr><th>Unit</th><th>Total</th><th>Present</th><th>AWOL</th><th>Leave</th><th>Sick</th><th>Suspended</th><th>Disciplinary</th><th>On Duty</th><th>On Course</th><th>Unrecorded</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['unit_name']) ?></td><td><?= $r['total'] ?></td><td><?= $r['present'] ?></td>
          <td><?= $r['awol'] ?></td><td><?= $r['on_leave'] ?></td><td><?= $r['sick'] ?></td>
          <td><?= $r['suspended'] ?></td><td><?= $r['disciplinary'] ?></td>
          <td><?= $r['on_duty'] ?></td><td><?= $r['on_course'] ?></td><td><?= $r['unrecorded'] ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight:700;background:var(--navy-50)">
          <td>TOTAL</td><td><?= $tot['total'] ?></td><td><?= $tot['present'] ?></td>
          <td><?= $tot['awol'] ?></td><td><?= $tot['on_leave'] ?></td><td><?= $tot['sick'] ?></td>
          <td><?= $tot['suspended'] ?></td><td><?= $tot['disciplinary'] ?></td>
          <td><?= $tot['on_duty'] ?></td><td><?= $tot['on_course'] ?></td><td><?= $tot['unrecorded'] ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
