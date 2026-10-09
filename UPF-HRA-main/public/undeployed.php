<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_min_rank('post_commander');
$page = 'undeployed';
$page_title = 'Undeployed Personnel';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    if ($action === 'create') {
        $eid = (int)$_POST['employee_id'];
        $emp = $pdo->prepare("SELECT id FROM employees e WHERE e.id=? AND e.active=1 AND $scopeW");
        $emp->execute(array_merge([$eid], $scopeP));
        if ($emp->fetch() && valid_date($_POST['start_date'] ?? '')) {
            $endDate = ($_POST['end_date'] ?? '') !== '' ? $_POST['end_date'] : null;
            // Close any previous open record for this employee
            $pdo->prepare("UPDATE undeployments SET status='redeployed', redeployed_at=datetime('now','localtime')
                           WHERE employee_id=? AND status='active'")->execute([$eid]);
            $pdo->prepare("INSERT INTO undeployments (employee_id,reason,start_date,end_date,status,notes,created_by)
                           VALUES (?,?,?,?,'active',?,?)")
                ->execute([$eid, trim($_POST['reason'] ?? 'Other'), $_POST['start_date'], $endDate,
                           trim($_POST['notes'] ?? '') ?: null, $user['id']]);
            log_activity('Marked undeployed', 'undeployment', trim($_POST['reason']), 0, 'Employee #'.$eid);
            flash('msg', 'Personnel marked undeployed — daily status will show "Undeployed".');
        }
    } elseif ($id && $action === 'redeploy') {
        $chk = $pdo->prepare("SELECT u.id FROM undeployments u JOIN employees e ON e.id=u.employee_id
                              WHERE u.id=? AND u.status='active' AND $scopeW");
        $chk->execute(array_merge([$id], $scopeP));
        if ($chk->fetch()) {
            $pdo->prepare("UPDATE undeployments SET status='redeployed', end_date=COALESCE(end_date, ?), redeployed_at=datetime('now','localtime') WHERE id=?")
                ->execute([date('Y-m-d'), $id]);
            flash('msg', 'Officer marked as redeployed.');
        }
    }
    header('Location: /undeployed.php'); exit;
}

$emps = $pdo->prepare("SELECT id, service_no, full_name, rank,
                              CASE WHEN post_id IS NULL THEN 0 ELSE 1 END has_post
                       FROM employees e WHERE e.active=1 AND $scopeW
                       ORDER BY has_post ASC, full_name");
$emps->execute($scopeP); $emps = $emps->fetchAll();

$rows = $pdo->prepare("SELECT ud.*, e.full_name, e.service_no, e.rank,
                              rg.name AS region_name, dv.name AS division_name,
                              st.name AS station_name, pt.name AS post_name
                       FROM undeployments ud
                       JOIN employees e ON e.id=ud.employee_id
                       LEFT JOIN regions rg ON rg.id=e.region_id
                       LEFT JOIN divisions dv ON dv.id=e.division_id
                       LEFT JOIN stations st ON st.id=e.station_id
                       LEFT JOIN posts pt ON pt.id=e.post_id
                       WHERE $scopeW
                       ORDER BY CASE ud.status WHEN 'active' THEN 1 ELSE 2 END, ud.start_date DESC
                       LIMIT 300");
$rows->execute(array_merge($scopeP)); $rows = $rows->fetchAll();

$today = date('Y-m-d');
$activeCount = count(array_filter($rows, fn($r) => $r['status']==='active'));
$unposted = 0;

function ud_pill(string $s): string {
    [$l, $c] = match($s) {
        'active'     => ['Undeployed', 'badge-undeployed'],
        default      => ['Redeployed', 'badge-present'],
    };
    return '<span class="badge '.$c.'">'.$l.'</span>';
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Undeployed Personnel</h1>
    <div class="desc">On strength but not deployed · <?= $activeCount ?> currently undeployed</div></div>
  <div class="action-bar">
    <div class="panel-wrap">
      <button class="btn-icon bi-primary" data-panel="panel-new" title="Mark undeployed"><?= ICO_PLUS ?></button>
      <div class="panel-drop" id="panel-new" style="min-width:400px">
        <h4>Mark Undeployed</h4>
        <form method="post" style="display:flex;flex-direction:column;gap:10px">
          <input type="hidden" name="action" value="create">
          <div class="form-group"><label>Employee</label>
            <select name="employee_id" required><option value="">— select —</option>
              <?php foreach ($emps as $emp): ?><option value="<?= $emp['id'] ?>"><?= e($emp['service_no'].' — '.$emp['rank'].' '.$emp['full_name']) ?><?= !$emp['has_post'] ? ' ⚠ no post' : '' ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:2"><label>Reason</label>
              <select name="reason">
                <option>Awaiting Posting</option><option>Post Closure / Merge</option><option>Medical Review</option>
                <option>Administrative Reasons</option><option>Suspended from Deployment</option><option>Other</option>
              </select>
            </div>
            <div class="form-group"><label>From Date</label><input type="date" name="start_date" required value="<?= date('Y-m-d') ?>"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Expected End <span class="muted">(blank = open)</span></label><input type="date" name="end_date"></div>
            <div class="form-group" style="flex:2"><label>Notes</label><input type="text" name="notes"></div>
          </div>
          <div class="action-bar" style="justify-content:flex-end">
            <button class="btn-icon bi-gold bi-lg" type="submit" title="Save"><?= ICO_SAVE ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

<div class="card">
  <div class="chr"><h3>Undeployment Records</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>File No</th><th>Name</th><th>Rank</th><th>Current Post</th><th>Reason</th>
        <th>Period</th><th>Status</th><th style="width:90px"></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r):
          if ($r['status']==='active' && (!$r['post_name'])) $unposted++;
          $ongoing = $r['status']==='active' && $today >= $r['start_date'];
        ?>
        <tr class="<?= $ongoing ? 'row-undeployed' : '' ?>">
          <td style="font-family:monospace;font-size:12px"><?= e($r['service_no']) ?></td>
          <td><strong><?= e($r['full_name']) ?></strong></td>
          <td><?= e($r['rank']) ?></td>
          <td style="font-size:12px"><?= e(($r['station_name']??'').' '.($r['post_name']?'· '.($r['post_name']):'(no post)')) ?></td>
          <td><?= e($r['reason']) ?><?= $r['notes'] ? '<br><span class="muted" style="font-size:11px">'.e($r['notes']).'</span>' : '' ?></td>
          <td style="white-space:nowrap;font-size:12px"><?= e(date('j M y', strtotime($r['start_date']))) ?>
              <?= $r['end_date'] ? '<br><span class="muted">→ '.e(date('j M y', strtotime($r['end_date']))).'</span>' : '<br><span class="muted">→ open</span>' ?></td>
          <td><?= ud_pill($r['status']) ?><?= $ongoing ? '<div class="stat-pct" style="color:#64748b;font-weight:700">● now</div>' : '' ?></td>
          <td>
            <?php if ($r['status']==='active'): ?>
            <form method="post">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn-icon bi-green bi-sm" name="action" value="redeploy" title="Mark redeployed"><?= ICO_OK_ALL ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">No undeployment records.</td></tr><?php endif; ?>
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
