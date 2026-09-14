<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_min_rank('post_commander');
$page = 'courses';
$page_title = 'On Course Management';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    if ($action === 'create') {
        $eid = (int)$_POST['employee_id'];
        $emp = $pdo->prepare("SELECT id FROM employees e WHERE e.id=? AND e.active=1 AND $scopeW");
        $emp->execute(array_merge([$eid], $scopeP));
        if ($emp->fetch() && valid_date($_POST['start_date'] ?? '') && valid_date($_POST['end_date'] ?? '')) {
            if ($_POST['end_date'] < $_POST['start_date']) {
                flash('err', 'End date cannot be before start date.');
            } else {
                $duration = leave_days_between($_POST['start_date'], $_POST['end_date']);
                $pdo->prepare("INSERT INTO on_courses (employee_id,course_name,course_nature,school,place,start_date,end_date,duration_days,sponsor,status,notes,created_by)
                               VALUES (?,?,?,?,?,?,?,?,?,'active',?,?)")
                    ->execute([$eid, trim($_POST['course_name']), $_POST['course_nature'] ?? 'Professional',
                               trim($_POST['school'] ?? '') ?: null, trim($_POST['place'] ?? '') ?: null,
                               $_POST['start_date'], $_POST['end_date'], $duration,
                               trim($_POST['sponsor'] ?? '') ?: null,
                               trim($_POST['notes'] ?? '') ?: null, $user['id']]);
                log_activity('Recorded on-course', 'on_course', trim($_POST['course_name']), 0, 'Employee #'.$eid.' · '.$duration.' days');
                flash('msg', 'Course recorded — daily status will show "On Course" for its duration.');
            }
        }
    } elseif ($id && in_array($action, ['complete','withdraw'], true)) {
        $chk = $pdo->prepare("SELECT oc.id FROM on_courses oc JOIN employees e ON e.id=oc.employee_id
                              WHERE oc.id=? AND oc.status='active' AND $scopeW");
        $chk->execute(array_merge([$id], $scopeP));
        if ($chk->fetch()) {
            if ($action === 'complete') {
                $pdo->prepare("UPDATE on_courses SET status='completed', completed_at=datetime('now','localtime') WHERE id=?")->execute([$id]);
                flash('msg', 'Course marked completed.');
            } else {
                $pdo->prepare("UPDATE on_courses SET status='withdrawn' WHERE id=?")->execute([$id]);
                flash('msg', 'Course marked withdrawn.');
            }
        }
    }
    header('Location: /courses.php'); exit;
}

$emps = $pdo->prepare("SELECT id, service_no, full_name, rank FROM employees e WHERE e.active=1 AND $scopeW ORDER BY full_name");
$emps->execute($scopeP); $emps = $emps->fetchAll();

$rows = $pdo->prepare("SELECT oc.*, e.full_name, e.service_no, e.rank,
                              rg.name AS region_name, dv.name AS division_name,
                              st.name AS station_name, pt.name AS post_name
                       FROM on_courses oc
                       JOIN employees e ON e.id=oc.employee_id
                       LEFT JOIN regions rg ON rg.id=e.region_id
                       LEFT JOIN divisions dv ON dv.id=e.division_id
                       LEFT JOIN stations st ON st.id=e.station_id
                       LEFT JOIN posts pt ON pt.id=e.post_id
                       WHERE $scopeW
                       ORDER BY CASE oc.status WHEN 'active' THEN 1 ELSE 2 END, oc.start_date DESC
                       LIMIT 300");
$rows->execute(array_merge($scopeP)); $rows = $rows->fetchAll();

$today = date('Y-m-d');
$activeCount = count(array_filter($rows, fn($r) => $r['status'] === 'active'));

function oc_pill(string $s): string {
    [$l, $c] = match($s) {
        'active'    => ['On Course', 'badge-on_course'],
        'completed' => ['Completed', 'badge-present'],
        default     => ['Withdrawn', 'badge-awol'],
    };
    return '<span class="badge '.$c.'">'.$l.'</span>';
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>On Course Management</h1>
    <div class="desc">Training &amp; course records — auto-sets "On Course" status for the duration · <?= $activeCount ?> active</div></div>
  <div class="action-bar">
    <div class="panel-wrap">
      <button class="btn-icon bi-primary" data-panel="panel-new" title="Record course"><?= ICO_PLUS ?></button>
      <div class="panel-drop" id="panel-new" style="min-width:440px">
        <h4>Record New Course</h4>
        <form method="post" style="display:flex;flex-direction:column;gap:10px">
          <input type="hidden" name="action" value="create">
          <div class="form-group"><label>Employee</label>
            <select name="employee_id" required><option value="">— select —</option>
              <?php foreach ($emps as $emp): ?><option value="<?= $emp['id'] ?>"><?= e($emp['service_no'].' — '.$emp['rank'].' '.$emp['full_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:2"><label>Course Name</label><input type="text" name="course_name" required placeholder="e.g. Junior Staff Course"></div>
            <div class="form-group"><label>Nature of Course</label>
              <select name="course_nature">
                <option>Professional</option><option>Promotional</option><option>Refresher</option>
                <option>Specialized</option><option>Technical / Vocational</option><option>Induction</option><option>Other</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:2"><label>School / Institution</label><input type="text" name="school" placeholder="e.g. Police Training School Kabalye"></div>
            <div class="form-group"><label>Place</label><input type="text" name="place" placeholder="e.g. Masindi"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Start Date</label><input type="date" name="start_date" required value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label>End Date (duration)</label><input type="date" name="end_date" required value="<?= date('Y-m-d') ?>"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Sponsor</label><input type="text" name="sponsor" placeholder="e.g. UP / Self / Government"></div>
            <div class="form-group" style="flex:2"><label>Notes</label><input type="text" name="notes"></div>
          </div>
          <div class="action-bar" style="justify-content:flex-end">
            <button class="btn-icon bi-gold bi-lg" type="submit" title="Save course"><?= ICO_SAVE ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

<div class="card">
  <div class="chr"><h3>Courses</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>File No</th><th>Name</th><th>Rank</th><th>Course</th><th>Nature</th><th>School / Place</th>
        <th>Duration</th><th>Progress</th><th>Status</th><th style="width:90px"></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r):
          $total = max(1, (int)$r['duration_days']);
          $done  = 0;
          if ($today >= $r['end_date']) $done = $total;
          elseif ($today > $r['start_date']) $done = leave_days_between($r['start_date'], $today);
          $pct = min(100, (int)round($done / $total * 100));
          $ongoing = $r['status']==='active' && $today >= $r['start_date'] && $today <= $r['end_date'];
        ?>
        <tr>
          <td style="font-family:monospace;font-size:12px"><?= e($r['service_no']) ?></td>
          <td><strong><?= e($r['full_name']) ?></strong></td>
          <td><?= e($r['rank']) ?></td>
          <td><?= e($r['course_name']) ?><?= $r['sponsor'] ? '<br><span class="muted" style="font-size:11px">Sponsor: '.e($r['sponsor']).'</span>' : '' ?></td>
          <td><?= e($r['course_nature']) ?></td>
          <td style="font-size:12px"><?= e(($r['school'] ?? '—').' · '.($r['place'] ?? '')) ?></td>
          <td style="white-space:nowrap;font-size:12px"><?= (int)$r['duration_days'] ?> days<br><span class="muted"><?= e(date('j M y', strtotime($r['start_date']))).' → '.e(date('j M y', strtotime($r['end_date']))) ?></span></td>
          <td style="min-width:90px">
            <div class="stat-bar"><div class="stat-bar-fill" style="width:<?= $pct ?>%;background:#059669"></div></div>
            <div class="stat-pct"><?= $pct ?>%<?= $ongoing ? ' <strong style="color:#059669">· ongoing</strong>' : '' ?></div>
          </td>
          <td><?= oc_pill($r['status']) ?></td>
          <td>
            <?php if ($r['status']==='active'): ?>
            <details class="form-panel" style="position:relative">
              <summary><button type="button" class="btn-icon bi-secondary bi-sm" title="Complete / withdraw"><?= ICO_EDIT ?></button></summary>
              <div class="form-body" style="position:absolute;right:0;top:100%;min-width:200px;z-index:20;padding:12px;box-shadow:var(--shadow-md)">
                <div class="action-bar">
                  <form method="post"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <button class="btn-icon bi-green" name="action" value="complete" title="Mark completed"><?= ICO_OK ?></button>
                  </form>
                  <form method="post"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <button class="btn-icon bi-danger" name="action" value="withdraw" title="Withdraw from course"><?= ICO_REJECT ?></button>
                  </form>
                </div>
              </div>
            </details>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" style="text-align:center;color:var(--muted);padding:24px">No courses recorded.</td></tr><?php endif; ?>
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
