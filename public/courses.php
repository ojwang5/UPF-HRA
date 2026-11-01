<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/communications.php';
$user = require_login();
$page = 'courses';
$page_title = 'On Course Management';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');
$canWrite = is_hrd_user($user);
$recordWhere  = $canWrite ? '1=1' : $scopeW;
$recordParams = $canWrite ? [] : $scopeP;
$courseStatuses = ['pending' => 'Pending', 'ongoing' => 'Ongoing', 'halted' => 'Halted', 'completed' => 'Completed'];
$sortOptions = [
    'course'    => 'Course name',
    'intake'    => 'Intake',
    'school'    => 'School / training centre',
    'nature'    => 'Type / nature of course',
    'start'     => 'Start date',
    'completed' => 'Completed officers',
    'pending'   => 'Pending officers',
];
$sortKey = strtolower(trim((string)($_GET['sort'] ?? 'course')));
if (!isset($sortOptions[$sortKey])) $sortKey = 'course';
$sortDir = strtolower(trim((string)($_GET['dir'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
$dirSql = strtoupper($sortDir);
$focusCourse = trim((string)($_GET['course'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
if (!in_array($statusFilter, ['pending', 'ongoing', 'halted', 'completed'], true)) $statusFilter = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canWrite) { flash('err', 'Only Human Resource Development (HRD) can manage course records. You have view-only access.'); header('Location: /courses.php'); exit; }
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    if ($action === 'create') {
        $eid = (int)($_POST['employee_id'] ?? 0);
        $emp = $pdo->prepare("SELECT e.id, e.service_no, e.rank, e.full_name FROM employees e WHERE e.id=? AND e.active=1");
        $emp->execute([$eid]);
        $empRow = $emp->fetch();
        $rawCourse = trim($_POST['course_name'] ?? '');
        $rawIntake = trim($_POST['intake'] ?? '');
        $defaultStatus = valid_date($_POST['start_date'] ?? '') && $_POST['start_date'] > date('Y-m-d') ? 'pending' : 'ongoing';
        $courseStatus = strtolower(trim($_POST['status'] ?? $defaultStatus));
        if (!isset($courseStatuses[$courseStatus])) {
            flash('err', 'Choose a valid course status.');
        } elseif ($rawCourse === '') {
            flash('err', 'Enter a course name.');
        } elseif ($rawIntake === '') {
            flash('err', 'Enter the course intake.');
        } elseif (!$empRow) {
            flash('err', 'Select an active employee.');
        } elseif (!valid_date($_POST['start_date'] ?? '') || !valid_date($_POST['end_date'] ?? '')) {
            flash('err', 'Enter valid course start and end dates.');
        } elseif ($_POST['end_date'] < $_POST['start_date']) {
            flash('err', 'End date cannot be before start date.');
        } else {
            $natureRaw = (string)($_POST['course_nature'] ?? 'Professional');

            // A pending or ongoing enrollment must be resolved before another begins.
            $dup = $pdo->prepare("SELECT oc.course_name FROM on_courses oc
                                  WHERE oc.employee_id=? AND LOWER(oc.course_name)=LOWER(?) AND oc.status IN ('pending','ongoing')");
            $dup->execute([$eid, $rawCourse]);
            $activeDup = $dup->fetch();
            if ($activeDup) {
                notify(personnel_label($empRow).' — duplicate course', '"'.$activeDup['course_name'].'" is already pending or ongoing for this officer.', 'user',
                    ['target_user_id'=>(int)$user['id'], 'kind'=>'course', 'link'=>'/courses.php']);
                flash('err', 'This officer already has a pending or ongoing "' . $activeDup['course_name'] . '" course. Resolve it before recording another.');
            } else {
                $prior = $pdo->prepare("SELECT COUNT(*) FROM on_courses
                                        WHERE employee_id=? AND LOWER(course_name)=LOWER(?) AND status='completed'");
                $prior->execute([$eid, $rawCourse]);
                $priorCount = (int)$prior->fetchColumn();

                $duration = leave_days_between($_POST['start_date'], $_POST['end_date']);
                $pdo->prepare("INSERT INTO on_courses (employee_id,course_name,course_nature,school,place,intake,start_date,end_date,duration_days,sponsor,status,notes,created_by,completed_at)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,CASE WHEN ?='completed' THEN datetime('now','localtime') ELSE NULL END)")
                    ->execute([$eid, $rawCourse, $natureRaw,
                               trim($_POST['school'] ?? '') ?: null, trim($_POST['place'] ?? '') ?: null,
                               $rawIntake,
                               $_POST['start_date'], $_POST['end_date'], $duration,
                               trim($_POST['sponsor'] ?? '') ?: null, $courseStatus,
                               trim($_POST['notes'] ?? '') ?: null, $user['id'], $courseStatus]);
                log_activity('Recorded on-course', 'on_course', $rawCourse, 0,
                    personnel_label($empRow).' · '.$duration.' days · '.$courseStatuses[$courseStatus]);
                flash('msg', $priorCount > 0
                    ? 'Repeat/refresher course recorded — this officer has completed "' . $rawCourse . '" ' . $priorCount . ' time(s) before.'
                    : 'Course recorded as '.$courseStatuses[$courseStatus].'.');
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
    } elseif ($id && in_array($action, ['update_status','complete','withdraw'], true)) {
        $newStatus = match ($action) {
            'complete' => 'completed',
            'withdraw' => 'halted',
            default => strtolower(trim($_POST['status'] ?? '')),
        };
        if (!isset($courseStatuses[$newStatus])) {
            flash('err', 'Choose a valid course status.');
        } else {
            $intake = trim($_POST['intake'] ?? '');
            $chk = $pdo->prepare("SELECT oc.status, oc.course_name, oc.intake, e.full_name, e.service_no
                                  FROM on_courses oc JOIN employees e ON e.id=oc.employee_id
                                  WHERE oc.id=?");
            $chk->execute([$id]);
            $courseRow = $chk->fetch();
            if ($intake === '') {
                flash('err', 'Enter the course intake. Older records without an intake must be updated before saving.');
            } elseif ($courseRow && ($courseRow['status'] !== $newStatus || trim((string)$courseRow['intake']) !== $intake)) {
                $intakeChanged = trim((string)$courseRow['intake']) !== $intake;
                $pdo->prepare("UPDATE on_courses
                               SET status=?, intake=?,
                                   completed_at=CASE WHEN ?='completed' THEN COALESCE(completed_at, datetime('now','localtime')) ELSE NULL END
                               WHERE id=?")
                    ->execute([$newStatus, $intake, $newStatus, $id]);
                log_activity('Updated course status', 'on_course', $courseRow['course_name'], $id,
                    $courseRow['service_no'].' '.$courseRow['full_name'].': '.$courseRow['status'].' -> '.$newStatus
                    .($intakeChanged ? '; intake: '.($courseRow['intake'] ?: 'not recorded').' -> '.$intake : ''));
                flash('msg', 'Course intake and status updated.');
            } elseif ($courseRow) {
                flash('msg', 'Course intake and status are unchanged.');
            }
        }
    }
    header('Location: /courses.php'); exit;
}


$empWhere  = $canWrite ? '1=1' : $scopeW;
$empParams = $canWrite ? [] : $scopeP;
$emps = $pdo->prepare("SELECT e.id, e.service_no, e.full_name, e.rank, e.directorate, e.unit, rg.name AS region_name
                       FROM employees e LEFT JOIN regions rg ON rg.id=e.region_id
                       WHERE e.active=1 AND $empWhere ORDER BY " . personnel_order_sql('e'));
$emps->execute($empParams); $emps = $emps->fetchAll();

$recordOrder = match ($sortKey) {
    'intake'    => "COALESCE(NULLIF(TRIM(oc.intake),''), oc.start_date) $dirSql, oc.start_date DESC, oc.course_name ASC",
    'school'    => "COALESCE(NULLIF(TRIM(oc.school),''), oc.place) $dirSql, oc.course_name ASC",
    'nature'    => "oc.course_nature $dirSql, oc.course_name ASC, oc.start_date DESC",
    'start'     => "oc.start_date $dirSql, oc.course_name ASC",
    'completed' => "(oc.status='completed') $dirSql, oc.course_name ASC, oc.start_date DESC",
    'pending'   => "(oc.status='pending') $dirSql, oc.course_name ASC, oc.start_date ASC",
    default     => "oc.course_name $dirSql, oc.start_date DESC, " . personnel_order_sql('e'),
};

$listWhere = $recordWhere;
$listParams = $recordParams;
if ($focusCourse !== '') {
    $listWhere .= " AND LOWER(TRIM(oc.course_name))=LOWER(TRIM(?))";
    $listParams[] = $focusCourse;
}
if ($statusFilter !== '') {
    $listWhere .= " AND oc.status=?";
    $listParams[] = $statusFilter;
}

$rows = $pdo->prepare("SELECT oc.*, e.full_name, e.service_no, e.rank,
                              e.directorate, e.unit,
                              rg.name AS region_name, dv.name AS division_name,
                              st.name AS station_name, pt.name AS post_name,
                              (SELECT COUNT(*) FROM on_courses p
                                WHERE p.employee_id=oc.employee_id
                                  AND LOWER(p.course_name)=LOWER(oc.course_name)
                                  AND p.status='completed' AND p.id<>oc.id) AS prior_completed
                       FROM on_courses oc
                       JOIN employees e ON e.id=oc.employee_id
                       LEFT JOIN regions rg ON rg.id=e.region_id
                       LEFT JOIN divisions dv ON dv.id=e.division_id
                       LEFT JOIN stations st ON st.id=e.station_id
                       LEFT JOIN posts pt ON pt.id=e.post_id
                       WHERE $listWhere
                       ORDER BY $recordOrder
                       LIMIT 300");
$rows->execute($listParams); $rows = $rows->fetchAll();

$today = date('Y-m-d');
$statusCounts = array_fill_keys(array_keys($courseStatuses), 0);
$countStmt = $pdo->prepare("SELECT oc.status, COUNT(*) AS total
                            FROM on_courses oc JOIN employees e ON e.id=oc.employee_id
                            WHERE $recordWhere GROUP BY oc.status");
$countStmt->execute($recordParams);
foreach ($countStmt as $countRow) {
    if (isset($statusCounts[$countRow['status']])) $statusCounts[$countRow['status']] = (int)$countRow['total'];
}

$overviewOrder = match ($sortKey) {
    'intake' => "LOWER(COALESCE(MIN(NULLIF(TRIM(oc.intake),'')), MIN(oc.start_date), '')) $dirSql, LOWER(MIN(oc.course_name)) ASC",
    'school' => "LOWER(COALESCE(MIN(NULLIF(TRIM(oc.school),'')), MIN(NULLIF(TRIM(oc.place),'')), '')) $dirSql, LOWER(MIN(oc.course_name)) ASC",
    'nature' => "LOWER(MIN(oc.course_nature)) $dirSql, LOWER(MIN(oc.course_name)) ASC",
    default  => "LOWER(MIN(oc.course_name)) $dirSql",
};
$overviewStmt = $pdo->prepare("SELECT MIN(oc.course_name) AS course_name,
                                      COUNT(*) AS total,
                                      SUM(CASE WHEN oc.status='completed' THEN 1 ELSE 0 END) AS completed,
                                      SUM(CASE WHEN oc.status='ongoing' THEN 1 ELSE 0 END) AS ongoing,
                                      SUM(CASE WHEN oc.status='pending' THEN 1 ELSE 0 END) AS pending,
                                      SUM(CASE WHEN oc.status='halted' THEN 1 ELSE 0 END) AS halted,
                                      GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(oc.intake),''), 'Not recorded (legacy)')) AS intakes,
                                      GROUP_CONCAT(DISTINCT NULLIF(TRIM(oc.school),'')) AS schools,
                                      GROUP_CONCAT(DISTINCT NULLIF(TRIM(oc.course_nature),'')) AS natures
                               FROM on_courses oc
                               JOIN employees e ON e.id=oc.employee_id
                               WHERE $recordWhere
                               GROUP BY LOWER(TRIM(oc.course_name))
                               ORDER BY $overviewOrder");
$overviewStmt->execute($recordParams);
$courseOverview = $overviewStmt->fetchAll();
$totalEnrollments = array_sum($statusCounts);

/* ── Registries (created courses & training schools) ── */
try {
    $courseReg = $pdo->query("SELECT * FROM courses ORDER BY name")->fetchAll();
    $schoolReg = $pdo->query("SELECT * FROM training_schools ORDER BY name")->fetchAll();
} catch (\Throwable $e) { $courseReg = []; $schoolReg = []; }

/* ── Completed course records (training history per officer) ── */
$doneSearch = trim($_GET['done'] ?? '');
$doneWhere  = "$recordWhere AND oc.status='completed'";
$doneParams = $recordParams;
if ($doneSearch !== '') {
    $doneWhere .= ' AND (oc.course_name LIKE ? OR e.full_name LIKE ? OR e.service_no LIKE ? OR oc.school LIKE ? OR oc.intake LIKE ?)';
    $ds = "%$doneSearch%";
    array_push($doneParams, $ds, $ds, $ds, $ds, $ds);
}
$doneStmt = $pdo->prepare("SELECT oc.*, e.full_name, e.service_no, e.rank,
                                  e.directorate, e.unit, rg.name AS region_name,
                                  st.name AS station_name, pt.name AS post_name
                           FROM on_courses oc
                           JOIN employees e ON e.id=oc.employee_id
                           LEFT JOIN regions rg ON rg.id=e.region_id
                           LEFT JOIN stations st ON st.id=e.station_id
                           LEFT JOIN posts pt ON pt.id=e.post_id
                           WHERE $doneWhere
                           ORDER BY oc.completed_at DESC, oc.end_date DESC, " . personnel_order_sql('e') . "
                           LIMIT 300");
$doneStmt->execute($doneParams);
$completedRows = $doneStmt->fetchAll();

function oc_pill(string $s): string {
    [$l, $c] = match($s) {
        'pending'   => ['Pending', 'badge-leave'],
        'ongoing'   => ['Ongoing', 'badge-on_course'],
        'halted'    => ['Halted', 'badge-awol'],
        'completed' => ['Completed', 'badge-present'],
        default     => ['Unknown', 'badge-admin'],
    };
    return '<span class="badge '.$c.'">'.$l.'</span>';
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>On Course Management</h1>
    <div class="desc">HRD training registry · <?= $statusCounts['ongoing'] ?> ongoing · <?= $statusCounts['pending'] ?> pending · <?= $statusCounts['halted'] ?> halted · <?= $statusCounts['completed'] ?> completed</div></div>
  <div class="action-bar">
    <span class="badge <?= $canWrite ? 'badge-present' : 'badge-leave' ?>"><?= $canWrite ? 'HRD — can manage' : 'View only' ?></span>
    <a class="btn-icon bi-secondary" href="/records-export.php?module=courses" title="Export courses to CSV"><?= ICO_DL ?></a>
    <?php if ($canWrite): ?>
    <a class="btn-icon bi-secondary" href="/records-import.php?module=courses" title="Bulk import courses (CSV)"><?= ICO_GEN ?></a>
    <?php endif; ?>
    <?php if ($canWrite): ?>
    <button class="btn-icon btn-labeled bi-primary" id="open-course-dialog" type="button" title="Record a police course">
      <?= ICO_PLUS ?><span>Record Course</span>
    </button>
    <?php endif; ?>
  </div>
</div>

<?php if ($canWrite): ?>
<dialog class="course-dialog" id="course-dialog" aria-labelledby="course-dialog-title">
  <div class="course-dialog-head">
    <div>
      <h2 id="course-dialog-title">Record New Course</h2>
      <p>Enter the officer, course, intake and training details.</p>
    </div>
    <button class="course-dialog-close" type="button" data-close-course-dialog aria-label="Close course form">&times;</button>
  </div>
  <form method="post" class="course-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="course-form-grid">
      <div class="form-group course-form-wide">
        <label for="course-employee">Officer <span class="course-required">Required</span></label>
        <select id="course-employee" name="employee_id" required>
          <option value="">— select officer —</option>
          <?php foreach ($emps as $emp):
            $workArea = implode(' / ', array_filter([$emp['region_name'] ?? '', $emp['directorate'] ?? '', $emp['unit'] ?? '']));
          ?><option value="<?= (int)$emp['id'] ?>"><?= e(personnel_label($emp)) ?><?= $workArea !== '' ? ' — '.e($workArea) : '' ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="course-name">Course name <span class="course-required">Required</span></label>
        <input id="course-name" type="text" name="course_name" required list="course-list" placeholder="e.g. Junior Staff Course">
        <datalist id="course-list"><?php foreach ($courseReg as $c): ?><option value="<?= e($c['name']) ?>"><?php endforeach; ?></datalist>
      </div>
      <div class="form-group">
        <label for="course-nature">Course type</label>
        <select id="course-nature" name="course_nature">
          <option>Professional</option><option>Promotional</option><option>Refresher</option>
          <option>Specialized</option><option>Technical / Vocational</option><option>Induction</option><option>Other</option>
        </select>
      </div>
      <div class="form-group">
        <label for="course-school">School / training centre</label>
        <input id="course-school" type="text" name="school" list="school-list" placeholder="e.g. Police Training School Kabalye">
        <datalist id="school-list"><?php foreach ($schoolReg as $sc): ?>
          <option value="<?= e($sc['name']) ?><?= $sc['place'] ? ' — '.e($sc['place']) : '' ?>"><?php endforeach; ?></datalist>
      </div>
      <div class="form-group">
        <label for="course-place">Place</label>
        <input id="course-place" type="text" name="place" placeholder="e.g. Masindi">
      </div>
      <div class="form-group">
        <label for="course-intake">Course intake <span class="course-required">Required</span></label>
        <input id="course-intake" type="text" name="intake" required placeholder="e.g. 2026 Intake 1">
        <span class="course-field-hint">Every course record must be assigned to an intake.</span>
      </div>
      <div class="form-group">
        <label for="course-status">Status</label>
        <select id="course-status" name="status"><?php foreach ($courseStatuses as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $value === 'ongoing' ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?></select>
      </div>
      <div class="form-group">
        <label for="course-start">Start date</label>
        <input id="course-start" type="date" name="start_date" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="form-group">
        <label for="course-end">End date</label>
        <input id="course-end" type="date" name="end_date" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="form-group">
        <label for="course-sponsor">Sponsor</label>
        <input id="course-sponsor" type="text" name="sponsor" placeholder="e.g. UPF / Government">
      </div>
      <div class="form-group">
        <label for="course-notes">Notes</label>
        <input id="course-notes" type="text" name="notes" placeholder="Additional course information">
      </div>
    </div>
    <div class="course-dialog-foot">
      <button class="btn-icon btn-labeled bi-secondary" type="button" data-close-course-dialog><?= ICO_CANCEL ?><span>Cancel</span></button>
      <button class="btn-icon btn-labeled bi-gold" type="submit"><?= ICO_SAVE ?><span>Save Course</span></button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<div class="course-summary-grid">
  <?php
  $summaryCards = [
      ['label'=>'Total courses', 'value'=>count($courseOverview), 'status'=>'', 'tone'=>'all', 'hint'=>'Distinct course names'],
      ['label'=>'Course enrolments', 'value'=>$totalEnrollments, 'status'=>'', 'tone'=>'all', 'hint'=>'All recorded officers'],
      ['label'=>'Completed', 'value'=>$statusCounts['completed'], 'status'=>'completed', 'tone'=>'completed', 'hint'=>'Officers who completed'],
      ['label'=>'Ongoing', 'value'=>$statusCounts['ongoing'], 'status'=>'ongoing', 'tone'=>'ongoing', 'hint'=>'Officers currently attending'],
      ['label'=>'Pending', 'value'=>$statusCounts['pending'], 'status'=>'pending', 'tone'=>'pending', 'hint'=>'Officers awaiting course'],
      ['label'=>'Halted', 'value'=>$statusCounts['halted'], 'status'=>'halted', 'tone'=>'halted', 'hint'=>'Withdrawn or halted records'],
  ];
  foreach ($summaryCards as $summary):
      $summaryHref = $summary['status'] !== ''
          ? '/courses.php?status='.urlencode($summary['status']).'#course-records'
          : '/courses.php#course-records';
      $summaryActive = $summary['status'] !== '' && $statusFilter === $summary['status'];
  ?>
  <a class="course-summary-card course-summary-<?= e($summary['tone']) ?><?= $summaryActive ? ' is-active' : '' ?>" href="<?= e($summaryHref) ?>"<?= $summaryActive ? ' aria-current="true"' : '' ?>>
    <span class="course-summary-label"><?= e($summary['label']) ?></span>
    <strong class="course-summary-value"><?= (int)$summary['value'] ?></strong>
    <span class="course-summary-hint"><?= e($summary['hint']) ?></span>
  </a>
  <?php endforeach; ?>
</div>

<section class="course-overview-section">
  <div class="course-section-heading">
    <div>
      <h2>Courses by name</h2>
      <p class="muted">Select a course to view its intakes, schools, training type, status and participating officers.</p>
    </div>
    <span class="badge badge-admin"><?= count($courseOverview) ?> courses</span>
  </div>
  <?php if ($courseOverview): ?>
  <div class="course-overview-grid">
    <?php foreach ($courseOverview as $course):
        $courseName = (string)$course['course_name'];
        $courseHref = '/courses.php?'.http_build_query(['course'=>$courseName, 'sort'=>$sortKey, 'dir'=>$sortDir]).'#course-records';
        $isCourseFocus = $focusCourse !== '' && strcasecmp(trim($focusCourse), trim($courseName)) === 0;
    ?>
    <a class="course-overview-card<?= $isCourseFocus ? ' is-active' : '' ?>" href="<?= e($courseHref) ?>"<?= $isCourseFocus ? ' aria-current="true"' : '' ?>>
      <span class="course-overview-title"><?= e($courseName) ?></span>
      <span class="course-overview-total"><?= (int)$course['total'] ?> officer<?= (int)$course['total'] === 1 ? '' : 's' ?></span>
      <span class="course-overview-breakdown">
        <span><?= (int)$course['completed'] ?> completed</span>
        <span><?= (int)$course['ongoing'] ?> ongoing</span>
        <span><?= (int)$course['pending'] ?> pending</span>
        <?php if ((int)$course['halted'] > 0): ?><span><?= (int)$course['halted'] ?> halted</span><?php endif; ?>
      </span>
      <span class="course-overview-meta">
        <strong>Type:</strong> <?= e($course['natures'] ?: 'Not specified') ?><br>
        <strong>School(s):</strong> <?= e($course['schools'] ?: 'Not specified') ?><br>
        <strong>Intake(s):</strong> <?= e($course['intakes'] ?: 'Not recorded (legacy)') ?>
      </span>
      <span class="course-overview-link">View officer details &rarr;</span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="card"><p class="muted" style="margin:0">No course records are available in your scope yet.</p></div>
  <?php endif; ?>
</section>

<div class="card">
  <div class="chr" id="course-records">
    <div>
      <h3>
        <?= $focusCourse !== '' ? 'Course details — '.e($focusCourse) : ($statusFilter !== '' ? e($courseStatuses[$statusFilter]).' course details' : 'Course records') ?>
      </h3>
      <span class="muted" style="font-size:12px">
        <?= $statusFilter !== '' ? e($courseStatuses[$statusFilter]).' officers' : 'All course statuses' ?>
        <?= $focusCourse !== '' ? ' · Selected course' : '' ?>
      </span>
    </div>
    <form method="get" class="course-sort-form">
      <?php if ($focusCourse !== ''): ?><input type="hidden" name="course" value="<?= e($focusCourse) ?>"><?php endif; ?>
      <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
      <label for="course-sort">Sort by</label>
      <select id="course-sort" name="sort">
        <?php foreach ($sortOptions as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $sortKey === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="dir" aria-label="Sort direction">
        <option value="asc" <?= $sortDir === 'asc' ? 'selected' : '' ?>>Ascending</option>
        <option value="desc" <?= $sortDir === 'desc' ? 'selected' : '' ?>>Descending</option>
      </select>
      <button class="btn-icon bi-secondary bi-sm" type="submit" title="Apply sorting"><?= ICO_SEARCH ?></button>
      <?php if ($focusCourse !== '' || $statusFilter !== ''): ?><a class="btn-icon bi-secondary bi-sm" href="/courses.php#course-records" title="Clear filters"><?= ICO_CANCEL ?></a><?php endif; ?>
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>File/Force No</th><th>Rank</th><th>Full Name</th><th>Course</th><th>Type</th><th>School / Place</th>
        <th>Intake</th><th>Work Region / Directorate</th><th>Duration</th><th>Progress</th><th>Status</th><th style="width:90px"></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r):
          $total = max(1, (int)$r['duration_days']);
          $done = $r['status'] === 'completed' ? $total : 0;
          if ($r['status'] === 'ongoing') {
            if ($today >= $r['end_date']) $done = $total;
            elseif ($today > $r['start_date']) $done = leave_days_between($r['start_date'], $today);
          }
          $pct = min(100, (int)round($done / $total * 100));
          $ongoing = $r['status']==='ongoing' && $today >= $r['start_date'] && $today <= $r['end_date'];
          $workArea = implode(' / ', array_filter([$r['region_name'] ?? '', $r['directorate'] ?? '', $r['unit'] ?? '']));
        ?>
        <tr>
          <td style="font-family:monospace;font-size:12px"><?= e($r['service_no']) ?></td>
          <td style="white-space:nowrap"><?= e($r['rank']) ?></td>
          <td><a href="/employees.php?view=<?= (int)$r['employee_id'] ?>"><strong><?= e($r['full_name']) ?></strong></a></td>
          <td><?= e($r['course_name']) ?><?= $r['sponsor'] ? '<br><span class="muted" style="font-size:11px">Sponsor: '.e($r['sponsor']).'</span>' : '' ?><?= $r['notes'] ? '<br><span class="muted" style="font-size:11px">Notes: '.e($r['notes']).'</span>' : '' ?></td>
          <td><?= e($r['course_nature']) ?></td>
          <td style="font-size:12px"><?= e(($r['school'] ?? '—').' · '.($r['place'] ?? '')) ?></td>
          <td><?= e($r['intake'] ?: 'Not recorded (legacy)') ?></td>
          <td style="font-size:12px"><?= e($workArea ?: '—') ?></td>
          <td style="white-space:nowrap;font-size:12px"><?= (int)$r['duration_days'] ?> days<br><span class="muted"><?= e(date('j M y', strtotime($r['start_date']))).' → '.e(date('j M y', strtotime($r['end_date']))) ?></span></td>
          <td style="min-width:90px">
            <div class="stat-bar"><div class="stat-bar-fill" style="width:<?= $pct ?>%;background:#059669"></div></div>
            <div class="stat-pct"><?= $pct ?>%<?= $ongoing ? ' <strong style="color:#059669">· in progress</strong>' : '' ?></div>
          </td>
          <td><?= oc_pill($r['status']) ?></td>
          <td>
            <?php if ($canWrite): ?>
            <details class="form-panel" style="position:relative">
              <summary><button type="button" class="btn-icon bi-secondary bi-sm" title="Update course intake or status"><?= ICO_EDIT ?></button></summary>
              <div class="form-body form-pop" style="position:absolute;right:0;top:100%;min-width:240px;z-index:20;padding:12px;box-shadow:var(--shadow-md)">
                <form method="post" style="display:flex;flex-direction:column;gap:8px;align-items:stretch">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <input type="hidden" name="action" value="update_status">
                  <div class="form-group"><label>Course intake</label><input type="text" name="intake" required value="<?= e((string)($r['intake'] ?? '')) ?>" placeholder="Required for every course"></div>
                  <div class="form-group"><label>Status</label><select name="status">
                    <?php foreach ($courseStatuses as $value => $label): ?>
                      <option value="<?= e($value) ?>" <?= $r['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                  </select></div>
                  <button class="btn-icon btn-labeled bi-green" type="submit" title="Save intake and status"><?= ICO_SAVE ?><span>Save changes</span></button>
                </form>
              </div>
            </details>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="12" style="text-align:center;color:var(--muted);padding:24px">No courses recorded.</td></tr><?php endif; ?>
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
    <?php if ($canWrite): ?>
    <form method="post" class="form-row" style="margin:0">
  <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_course">
      <div class="form-group" style="flex:2"><label>Course Name</label><input type="text" name="name" required placeholder="e.g. Inspectorate Course"></div>
      <div class="form-group"><label>Course Type</label>
        <select name="nature"><option>Professional</option><option>Promotional</option><option>Refresher</option><option>Specialized</option><option>Technical / Vocational</option><option>Induction</option><option>Other</option></select>
      </div>
      <div class="form-group" style="max-width:100px"><label>Days</label><input type="number" name="duration_days" min="0" placeholder="0"></div>
      <button class="btn-icon bi-gold" type="submit" title="Add course"><?= ICO_PLUS ?></button>
    </form>
    <?php endif; ?>
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
    <?php if ($canWrite): ?>
    <form method="post" class="form-row" style="margin:0">
  <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_school">
      <div class="form-group" style="flex:2"><label>School / Institution</label><input type="text" name="name" required placeholder="e.g. Police Training School Kabalye"></div>
      <div class="form-group"><label>Place</label><input type="text" name="place" placeholder="e.g. Masindi"></div>
      <button class="btn-icon bi-gold" type="submit" title="Add school"><?= ICO_PLUS ?></button>
    </form>
    <?php endif; ?>
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
        <th>File/Force No</th><th>Rank</th><th>Full Name</th><th>Course</th><th>Type</th><th>School / Place</th>
        <th>Intake</th><th>Duration</th><th>Completed</th><th>Work Region / Directorate</th><th>Station / Post</th>
      </tr></thead>
      <tbody>
        <?php foreach ($completedRows as $r):
          $workArea = implode(' / ', array_filter([$r['region_name'] ?? '', $r['directorate'] ?? '', $r['unit'] ?? '']));
        ?>
        <tr>
          <td style="font-family:monospace;font-size:12px"><?= e($r['service_no']) ?></td>
          <td style="white-space:nowrap"><?= e($r['rank']) ?></td>
          <td><a href="/employees.php?view=<?= (int)$r['employee_id'] ?>"><strong><?= e($r['full_name']) ?></strong></a></td>
          <td><?= e($r['course_name']) ?><?= $r['sponsor'] ? '<br><span class="muted" style="font-size:11px">Sponsor: '.e($r['sponsor']).'</span>' : '' ?></td>
          <td><?= e($r['course_nature']) ?></td>
          <td style="font-size:12px"><?= e(($r['school'] ?? '—').' · '.($r['place'] ?? '')) ?></td>
          <td><?= e($r['intake'] ?: 'Not recorded (legacy)') ?></td>
          <td style="font-size:12px"><?= (int)$r['duration_days'] ?> days</td>
          <td style="font-size:12px"><?= $r['completed_at'] ? e(date('j M Y', strtotime($r['completed_at']))) : '—' ?></td>
          <td style="font-size:12px"><?= e($workArea ?: '—') ?></td>
          <td style="font-size:12px"><?= e(trim(($r['station_name']??'').' › '.($r['post_name']??''),' ›') ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$completedRows): ?><tr><td colspan="11" style="text-align:center;color:var(--muted);padding:24px">No completed courses<?= $doneSearch ? ' matching "'.e($doneSearch).'"' : '' ?>.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const courseDialog = document.getElementById('course-dialog');
const openCourseDialog = document.getElementById('open-course-dialog');
if (courseDialog && openCourseDialog) {
  openCourseDialog.addEventListener('click', () => courseDialog.showModal());
  courseDialog.querySelectorAll('[data-close-course-dialog]').forEach(button => {
    button.addEventListener('click', () => courseDialog.close());
  });
  courseDialog.addEventListener('click', event => {
    if (event.target === courseDialog) courseDialog.close();
  });
}
document.querySelectorAll('details.form-panel').forEach(d=>{
  d.addEventListener('toggle',()=>{ if(d.open) document.querySelectorAll('details.form-panel').forEach(o=>{ if(o!==d) o.open=false; }); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
