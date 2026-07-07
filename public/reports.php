<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
$user = require_min_rank('post_commander');
$page = 'reports';
$page_title = 'Reports';
$pdo = db();

$date = $_GET['date'] ?? date('Y-m-d');

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='generate') {
    $rows = hierarchy_summary($pdo, $date, $user);
    $status = is_superadmin($user) ? 'approved' : 'pending_superadmin';
    $scopeLevel = match($user['role']) {
        'superadmin' => 'force', 'regional_commander' => 'region',
        'division_commander' => 'division', 'station_commander' => 'station', default => 'post',
    };
    $rId=$dId=$sId=$pId=null;
    if ($user['region_id'])   $rId=(int)$user['region_id'];
    if ($user['division_id']) $dId=(int)$user['division_id'];
    if ($user['station_id'])  $sId=(int)$user['station_id'];
    if ($user['post_id'])     $pId=(int)$user['post_id'];
    $pdo->prepare("INSERT INTO reports (post_id,station_id,division_id,region_id,scope_level,date,generated_by,generated_at,summary_json,status) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$pId,$sId,$dId,$rId,$scopeLevel,$date,$user['id'],date('c'),json_encode($rows),$status]);
    if ($status!=='approved') {
        notify_superadmins('New report awaiting approval', role_label($user['role']).' — '.date('j M Y',strtotime($date)),
            ['created_by'=>$user['id'],'kind'=>'report']);
    }
    flash('msg', $status==='approved' ? 'Report saved.' : 'Report submitted for review.');
    header('Location:/reports.php?date='.urlencode($date)); exit;
}

$rows = hierarchy_summary($pdo, $date, $user);
$tot  = sum_totals($rows);

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Reports</h1><div class="desc">Attendance summary for <?= e(date('j F Y',strtotime($date))) ?> · <?= e(user_scope_label($user)) ?></div></div>
  <div class="action-bar">
    <!-- Date picker -->
    <details class="form-panel">
      <summary><button type="button" class="btn-icon bi-secondary" title="Select date"><?= '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' ?></button></summary>
      <div class="form-body" style="padding:12px 16px">
        <form method="get" style="display:flex;gap:8px;align-items:flex-end">
          <div class="form-group"><label>Date</label><input type="date" name="date" value="<?= e($date) ?>"></div>
          <button class="btn-icon bi-primary" type="submit" title="Go"><?= ICO_SAVE ?></button>
        </form>
      </div>
    </details>
    <!-- Generate report -->
    <form method="post" style="display:contents">
      <input type="hidden" name="action" value="generate">
      <button type="submit" class="btn-icon bi-primary" title="<?= is_superadmin($user) ? 'Save report' : 'Submit report for approval' ?>"><?= ICO_GEN ?></button>
    </form>
    <!-- Export CSV -->
    <a class="btn-icon bi-secondary" href="/export.php?type=csv&date=<?= e($date) ?>" title="Export CSV" target="_blank"><?= ICO_DL ?></a>
    <!-- Print PDF -->
    <a class="btn-icon bi-secondary" href="/export.php?type=print&date=<?= e($date) ?>" title="Print / PDF" target="_blank"><?= ICO_PRINT ?></a>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if (!is_superadmin($user)): ?>
<div class="alert" style="background:var(--navy-50);border:1px solid var(--border);color:var(--muted)">
  <?= ICO_GEN ?> Reports are submitted to higher command for review and approval.
</div>
<?php endif; ?>

<div class="card">
  <div class="chr"><h3>Attendance Report — <?= e(date('j F Y',strtotime($date))) ?></h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Unit</th><th>Total</th><th>Present</th><th>AWOL</th><th>Leave</th><th>Sick</th><th>Suspended</th><th>Disciplinary</th><th>On Duty</th><th>On Course</th><th>Unrecorded</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr><td><?= e($r['unit_name']) ?></td><td><?= $r['total'] ?></td><td><?= $r['present'] ?></td>
          <td><?= $r['awol'] ?></td><td><?= $r['on_leave'] ?></td><td><?= $r['sick'] ?></td>
          <td><?= $r['suspended'] ?></td><td><?= $r['disciplinary'] ?></td>
          <td><?= $r['on_duty'] ?></td><td><?= $r['on_course'] ?></td><td><?= $r['unrecorded'] ?></td></tr>
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

<script>
document.querySelectorAll('details.form-panel').forEach(d=>{
  d.addEventListener('toggle',()=>{ if(d.open) document.querySelectorAll('details.form-panel').forEach(o=>{ if(o!==d) o.open=false; }); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
