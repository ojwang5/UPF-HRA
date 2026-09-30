<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
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
        $emp = $pdo->prepare("SELECT id, full_name FROM employees e WHERE e.id=? AND e.active=1 AND $scopeW");
        $emp->execute(array_merge([$eid], $scopeP));
        $empRow = $emp->fetch();
        if ($empRow && valid_date($_POST['start_date'] ?? '') && valid_date($_POST['end_date'] ?? '')) {
            if ($_POST['end_date'] < $_POST['start_date']) {
                flash('err', 'End date cannot be before start date.');
            } else {
                // Duplicate prevention: officers who already completed (or are currently on)
                // the same course are flagged so the course is not repeated by mistake.
                $rawCourse = trim($_POST['course_name']);
                $dup = $pdo->prepare("SELECT oc.course_name, oc.status FROM on_courses oc
                                      JOIN employees e ON e.id=oc.employee_id
                                      WHERE e.id=? AND LOWER(oc.course_name)=LOWER(?) AND oc.status IN ('completed','active')");
                $dup->execute([$eid, $rawCourse]);
                $already = $dup->fetchAll();
                if ($already) {
                    $notes = [];
                    foreach ($already as $a) {
                        if ($a['status'] === 'completed') {
                            $notes[] = '"'.$a['course_name'].'" already COMPLETED — do not repeat.';
                        } else {
                            $notes[] = '"'.$a['course_name'].'" currently ON COURSE / not yet completed.';
                        }
                    }
                    notify($empRow['full_name'].' — duplicate course', implode(' ', $notes), 'user',
                        ['target_user_id'=>(int)$user['id'], 'kind'=>'course', 'link'=>'/courses.php']);
                    flash('err', 'Duplicate course not recorded: '.implode(' ', $notes));
                } else {
                    $duration = leave_days_between($_POST['start_date'], $_POST['end_date']);
                    $pdo->prepare("INSERT INTO on_courses (employee_id,course_name,course_nature,school,place,start_date,end_date,duration_days,sponsor,status,notes,created_by)
                                   VALUES (?,?,?,?,?,?,?,?,?,'active',?,?)")
                        ->execute([$eid, $rawCourse, $_POST['course_nature'] ?? 'Professional',
                                   trim($_POST['school'] ?? '') ?: null, trim($_POST['place'] ?? '') ?: null,
                                   $_POST['start_date'], $_POST['end_date'], $duration,
                                   trim($_POST['sponsor'] ?? '') ?: null,
                                   trim($_POST['notes'] ?? '') ?: null, $user['id']]);
                    log_activity('Recorded on-course', 'on_course', $rawCourse, 0, 'Employee #'.$eid.' · '.$duration.' days');
                    flash('msg', 'Course recorded — daily status will show "On Course" for its duration.');
                }
            }
        }
    } elseif ($action === 'add_course') {
        $cname = trim($_POST['name'] ?? '');
        $nature = trim($_POST['nature'] ?? 'Professional') ?: 'Professional';
        $durRaw = trim($_POST['duration_days'] ?? '');
        $dur = $durRaw !== '' ? max(0, (int)$durRaw) : null;
        if ($cname === '') { flash('err', 'Enter a course name.'); }
        else {
            try {
                $pdo->prepare("INSERT INTO courses (name, nature, duration_days, created_by) VALUES (?,?,?,?)")
                    ->execute([$cname, $nature, $dur, $user['id']]);
                log_activity('Added course to registry', 'course', $cname);
                flash('msg', 'Course added to registry.');
            } catch (\PDOException $ex) { flash('err', 'That course already exists in the registry.'); }
        }
    } elseif ($action === 'add_school') {
        $sname = trim($_POST['name'] ?? '');
        $place = trim($_POST['place'] ?? '') ?: null;
        if ($sname === '') { flash('err', 'Enter a school name.'); }
        else {
            try {
                $pdo->prepare("INSERT INTO training_schools (name, place, created_by) VALUES (?,?,?)")
                    ->execute([$sname, $place, $user['id']]);
                log_activity('Added training school', 'training_school', $sname);
                flash('msg', 'Training school added.');
            } catch (\PDOException $ex) { flash('err', 'That training school already exists.'); }
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

/* ── Registries (created courses & training schools) ── */
try {
    $courseReg = $pdo->query("SELECT * FROM courses ORDER BY name")->fetchAll();
    $schoolReg = $pdo->query("SELECT * FROM training_schools ORDER BY name")->fetchAll();
} catch (\Throwable $e) { $courseReg = []; $schoolReg = []; }

/* ── Completed course records (training history per officer) ── */
$doneSearch = trim($_GET['done'] ?? '');
$doneWhere  = "$scopeW AND oc.status='completed'";
$doneParams = $scopeP;
if ($doneSearch !== '') {
    $doneWhere .= ' AND (oc.course_name LIKE ? OR e.full_name LIKE ? OR e.service_no LIKE ? OR oc.school LIKE ?)';
    $ds = "%$doneSearch%";
    array_push($doneParams, $ds, $ds, $ds, $ds);
}
$doneStmt = $pdo->prepare("SELECT oc.*, e.full_name, e.service_no, e.rank,
                                  st.name AS station_name, pt.name AS post_name
                           FROM on_courses oc
                           JOIN employees e ON e.id=oc.employee_id
                           LEFT JOIN stations st ON st.id=e.station_id
                           LEFT JOIN posts pt ON pt.id=e.post_id
                           WHERE $doneWhere
                           ORDER BY oc.completed_at DESC, oc.end_date DESC
                           LIMIT 300");
$doneStmt->execute($doneParams);
$completedRows = $doneStmt->fetchAll();

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
            <div class="form-group" style="flex:2"><label>Course Name</label><input type="text" name="course_name" required list="course-list" placeholder="e.g. Junior Staff Course">
              <datalist id="course-list"><?php foreach ($courseReg as $c): ?><option value="<?= e($c['name']) ?>"><?php endforeach; ?></datalist>
              <span class="muted" style="font-size:11px">Pick from the registry below or type a new course.</span></div>
            <div class="form-group"><label>Nature of Course</label>
              <select name="course_nature">
                <option>Professional</option><option>Promotional</option><option>Refresher</option>
                <option>Specialized</option><option>Technical / Vocational</option><option>Induction</option><option>Other</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:2"><label>School / Training School</label><input type="text" name="school" list="school-list" placeholder="e.g. Police Training School Kabalye">
              <datalist id="school-list"><?php foreach ($schoolReg as $sc): ?>
                <option value="<?= e($sc['name']) ?><?= $sc['place'] ? ' — '.e($sc['place']) : '' ?>"><?php endforeach; ?></datalist>
              <span class="muted" style="font-size:11px">Pick from the training schools registry below or type a new one.</span></div>
            <div class="form-group"><label>Place</label><input type="text" name="place" placeholder="e.g. Masindi"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Start Date</label><input type="date" name="start_date" required value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label>End Date (duration)</label><input type="date" name="end_date" required value="<?= date('Y-m-d') ?>"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Sponsor</label><input type="text" name="sponsor" placeholder="e.g. UPF / Self / Government"></div>
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
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

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

<!-- ════════════════════════════ COURSE & SCHOOL REGISTRIES ════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:16px">
  <div class="card">
    <div class="chr">
      <h3>Course Registry <span class="badge badge-admin"><?= count($courseReg) ?></span></h3>
    </div>
    <p class="muted" style="margin:0 0 10px;font-size:12px">Register Course name here.</p>
    <?php if ($courseReg): ?>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">
      <?php foreach ($courseReg as $c): ?>
      <span class="badge badge-appointments" title="<?= e((string)($c['nature']??'Professional')).($c['duration_days']!==null?' · '.(int)$c['duration_days'].' days':'') ?>"><?= e($c['name']) ?></span>
      <?php endforeach; ?>
    </div>
    <?php else: ?><p class="muted" style="font-size:12px">No courses yet — add the first one below.</p><?php endif; ?>
    <form method="post" class="form-row" style="margin:0">
      <input type="hidden" name="action" value="add_course">
      <div class="form-group" style="flex:2"><label>Course Name</label><input type="text" name="name" required placeholder="e.g. Inspectorate Course"></div>
      <div class="form-group"><label>Nature</label>
        <select name="nature"><option>Professional</option><option>Promotional</option><option>Refresher</option><option>Specialized</option><option>Technical / Vocational</option><option>Induction</option><option>Other</option></select>
      </div>
      <div class="form-group" style="max-width:100px"><label>Days</label><input type="number" name="duration_days" min="0" placeholder="0"></div>
      <button class="btn-icon bi-gold" type="submit" title="Add course"><?= ICO_PLUS ?></button>
    </form>
  </div>

  <div class="card">
    <div class="chr">
      <h3>Training Schools <span class="badge badge-admin"><?= count($schoolReg) ?></span></h3>
    </div>
    <p class="muted" style="margin:0 0 10px;font-size:12px">Register Training schools/centers here.</p>
    <?php if ($schoolReg): ?>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">
      <?php foreach ($schoolReg as $sc): ?>
      <span class="badge badge-leave_approved" title="<?= e((string)($sc['place']??'')) ?>"><?= e($sc['name']) ?><?= $sc['place']?' <span class="muted">· '.e($sc['place']).'</span>':'' ?></span>
      <?php endforeach; ?>
    </div>
    <?php else: ?><p class="muted" style="font-size:12px">No training schools yet — add the first one below.</p><?php endif; ?>
    <form method="post" class="form-row" style="margin:0">
      <input type="hidden" name="action" value="add_school">
      <div class="form-group" style="flex:2"><label>School / Institution</label><input type="text" name="name" required placeholder="e.g. Police Training School Kabalye"></div>
      <div class="form-group"><label>Place</label><input type="text" name="place" placeholder="e.g. Masindi"></div>
      <button class="btn-icon bi-gold" type="submit" title="Add school"><?= ICO_PLUS ?></button>
    </form>
  </div>
</div>

<!-- ════════════════════════════ COMPLETED COURSE RECORDS ════════════════════════════ -->
<div class="card">
  <div class="chr">
    <h3>Completed Course Records <span class="badge badge-present"><?= count($completedRows) ?></span></h3>
    <form method="get" class="panel-drop-search" style="display:flex;gap:8px;align-items:center">
      <input type="text" name="done" value="<?= e($doneSearch) ?>" placeholder="Search completed courses…" style="min-width:220px">
      <button class="btn-icon bi-secondary bi-sm" type="submit" title="Search"><?= ICO_SEARCH ?></button>
      <?php if ($doneSearch): ?><a class="btn-icon bi-secondary bi-sm" href="/courses.php" title="Clear"><?= ICO_CANCEL ?></a><?php endif; ?>
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>File No</th><th>Name</th><th>Rank</th><th>Course</th><th>Nature</th><th>School / Place</th>
        <th>Duration</th><th>Completed</th><th>Station / Post</th>
      </tr></thead>
      <tbody>
        <?php foreach ($completedRows as $r): ?>
        <tr>
          <td style="font-family:monospace;font-size:12px"><?= e($r['service_no']) ?></td>
          <td><strong><?= e($r['full_name']) ?></strong></td>
          <td><?= e($r['rank']) ?></td>
          <td><?= e($r['course_name']) ?><?= $r['sponsor'] ? '<br><span class="muted" style="font-size:11px">Sponsor: '.e($r['sponsor']).'</span>' : '' ?></td>
          <td><?= e($r['course_nature']) ?></td>
          <td style="font-size:12px"><?= e(($r['school'] ?? '—').' · '.($r['place'] ?? '')) ?></td>
          <td style="font-size:12px"><?= (int)$r['duration_days'] ?> days</td>
          <td style="font-size:12px"><?= $r['completed_at'] ? e(date('j M Y', strtotime($r['completed_at']))) : '—' ?></td>
          <td style="font-size:12px"><?= e(trim(($r['station_name']??'').' › '.($r['post_name']??''),' ›') ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$completedRows): ?><tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">No completed courses<?= $doneSearch ? ' matching "'.e($doneSearch).'"' : '' ?>.</td></tr><?php endif; ?>
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
