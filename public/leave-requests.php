<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/leave-sms.php';
$user = require_login();
$page = 'leave';
$page_title = 'Leave Management';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

// Alert commanders once when approved leave is about to run out / has expired.
run_leave_countdown_notifications($pdo);

// Configurable leave types (Super Admin manages these in Settings).
$leaveTypes = leave_types_list($pdo);
if (!$leaveTypes) $leaveTypes = [['id'=>0,'name'=>'Annual','min_role'=>'station_commander']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'adjust' && role_rank($user['role']) > role_rank('officer')) {
        $eid = (int)$_POST['employee_id'];
        $days = (int)($_POST['days'] ?? 0);
        $newEnt = trim($_POST['annual_leave_days'] ?? '');
        if ($eid && ($days !== 0 || $newEnt !== '')) {
            $chk = $pdo->prepare("SELECT id FROM employees e WHERE e.id=? AND e.active=1 AND $scopeW");
            $chk->execute(array_merge([$eid], $scopeP));
            if ($chk->fetch()) {
                if ($days !== 0) {
                    $pdo->prepare("INSERT INTO leave_adjustments (employee_id,days,reason,created_by) VALUES (?,?,?,?)")
                        ->execute([$eid, $days, trim($_POST['reason'] ?? '') ?: 'Manual adjustment', $user['id']]);
                    log_activity('Leave days adjusted', 'leave_adjustment', (string)$days, (int)$eid, personnel_label_by_id($pdo, $eid));
                }
                if ($newEnt !== '') {
                    $pdo->prepare("UPDATE employees SET annual_leave_days=? WHERE id=?")->execute([max(0,(int)$newEnt), $eid]);
                    log_activity('Set annual leave entitlement', 'employee', personnel_label_by_id($pdo, $eid), (int)$eid, 'Entitlement set to '.max(0,(int)$newEnt).' days');
                }
                notify_superadmins('Leave adjustment recorded',
                    personnel_label_by_id($pdo, $eid).' adjusted'.($days!==0?' by '.$days.' days':'').($newEnt!==''?' (entitlement '.max(0,(int)$newEnt).' days)':'').' — '.trim($_POST['reason'] ?? ''),
                    ['created_by'=>$user['id'],'kind'=>'leave']);
                flash('msg', 'Leave balance updated.');
            }
        }
    } elseif ($action === 'submit') {
        $eid = (int)$_POST['employee_id'];
        $emp = $pdo->prepare("SELECT * FROM employees e WHERE e.id=? AND e.active=1 AND $scopeW");
        $emp->execute(array_merge([$eid], $scopeP)); $e = $emp->fetch();
        if ($e) {
            $dirId  = $e['directorate'] ? (int)$pdo->query("SELECT id FROM directorates WHERE name=".$pdo->quote((string)$e['directorate'])." LIMIT 1")->fetchColumn() : null;
            $dirId  = $dirId ?: null;
            $unitId = $e['unit'] ? (int)$pdo->query("SELECT id FROM units WHERE name=".$pdo->quote((string)$e['unit'])." LIMIT 1")->fetchColumn() : null;
            $unitId = $unitId ?: null;
            $pdo->prepare("INSERT INTO leave_requests (employee_id,post_id,station_id,division_id,region_id,directorate_id,unit_id,leave_type,start_date,end_date,destination,reason,status,submitted_by,submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$eid,$e['post_id'],$e['station_id'],$e['division_id'],$e['region_id'],$dirId,$unitId,$_POST['leave_type']??'Annual',$_POST['start_date'],$_POST['end_date'],trim($_POST['destination']??'')?:null,$_POST['reason']??'','pending',$user['id'],date('c')]);
            notify_superadmins('Leave request submitted',
                personnel_label($e).' ('.($_POST['leave_type']??'Annual').') — submitted by '.role_label($user['role']),
                ['created_by'=>$user['id'],'kind'=>'leave']);
            flash('msg','Leave request submitted.');
        }
    } elseif ($action === 'review') {
        $id=(int)$_POST['id']; $decision=$_POST['decision']; $notes=$_POST['notes']??'';
        $req = $pdo->prepare("SELECT lr.*, e.service_no, e.rank, e.full_name AS emp_name, e.phone FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id WHERE lr.id=?");
        $req->execute([$id]); $req=$req->fetch();
        if ($req && $req['status']==='pending' && can_approve_leave($user, $req['leave_type'])) {
            $newStatus = $decision==='approve' ? 'approved' : 'rejected';
            $pdo->prepare("UPDATE leave_requests SET status=?,reviewed_by=?,reviewed_at=?,review_notes=? WHERE id=?")->execute([$newStatus,$user['id'],date('c'),$notes,$id]);
            $pdo->prepare("INSERT INTO notifications (title,message,link,kind,audience,target_user_id,created_by,created_at) VALUES (?,?,?,?,?,?,?,?)")
                ->execute(["Leave request $newStatus",personnel_label(['service_no'=>$req['service_no'],'rank'=>$req['rank'],'full_name'=>$req['emp_name']])." — $newStatus by ".$user['full_name'],'/leave-requests.php#lr-'.$id,'leave','user',(int)$req['submitted_by'],$user['id'],date('c')]);
            $sms = send_leave_status_sms($pdo, $id, $newStatus);
            $flash = 'Decision recorded.';
            if (!empty($sms['ok'])) {
                $flash .= !empty($sms['skipped'])
                    ? ' SMS notification already sent.'
                    : ' SMS notification submitted to the provider.';
            } else {
                $flash .= ' SMS not sent: ' . ($sms['error'] ?? 'unknown error') . '.';
            }
            flash('msg', $flash);
        }
    } elseif ($action === 'submit_extension') {
        $lrId   = (int)($_POST['leave_request_id'] ?? 0);
        $newEnd = trim($_POST['new_end_date'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $req = $pdo->prepare("SELECT lr.*, e.service_no, e.rank, e.full_name AS emp_name FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id WHERE lr.id=?");
        $req->execute([$lrId]); $lr = $req->fetch();
        $inScope = false;
        if ($lr) {
            $chk = $pdo->prepare("SELECT 1 FROM employees e WHERE e.id=? AND e.active=1 AND $scopeW");
            $chk->execute(array_merge([(int)$lr['employee_id']], $scopeP));
            $inScope = (bool)$chk->fetch();
        }
        if ($lr && $inScope && $lr['status']==='approved' && valid_date($newEnd) && $newEnd > $lr['end_date']) {
            $pdo->prepare("INSERT INTO leave_extensions (leave_request_id,employee_id,old_end_date,new_end_date,reason,status,requested_by) VALUES (?,?,?,?,?, 'pending', ?)")
                ->execute([$lrId, (int)$lr['employee_id'], $lr['end_date'], $newEnd, $reason ?: null, $user['id']]);
            notify_superadmins('Leave extension requested',
                personnel_label(['service_no'=>$lr['service_no'],'rank'=>$lr['rank'],'full_name'=>$lr['emp_name']]).' — extension to '.$newEnd.' requested by '.role_label($user['role']),
                ['created_by'=>$user['id'],'kind'=>'leave','link'=>'/leave-requests.php']);
            flash('msg', 'Leave extension request submitted.');
        } else {
            flash('err', 'Extension must target an approved leave and a later end date.');
        }
    } elseif ($action === 'review_extension') {
        $id = (int)$_POST['id']; $decision = $_POST['decision'] ?? ''; $notes = trim($_POST['notes'] ?? '');
        $xq = $pdo->prepare("SELECT le.*, lr.leave_type, lr.submitted_by, e.service_no, e.rank, e.full_name AS emp_name
                             FROM leave_extensions le
                             JOIN leave_requests lr ON lr.id=le.leave_request_id
                             JOIN employees e ON e.id=le.employee_id WHERE le.id=?");
        $xq->execute([$id]); $x = $xq->fetch();
        if ($x && $x['status']==='pending' && can_approve_leave($user, $x['leave_type'])) {
            $newStatus = $decision==='approve' ? 'approved' : 'rejected';
            $pdo->prepare("UPDATE leave_extensions SET status=?,reviewed_by=?,reviewed_at=?,review_notes=? WHERE id=?")
                ->execute([$newStatus,$user['id'],date('c'),$notes ?: null,$id]);
            if ($newStatus==='approved') {
                // Extend the underlying leave and restart the countdown notifications.
                $pdo->prepare("UPDATE leave_requests SET end_date=?, expiry_notified=0 WHERE id=?")
                    ->execute([$x['new_end_date'], (int)$x['leave_request_id']]);
            }
            $label = personnel_label(['service_no'=>$x['service_no'],'rank'=>$x['rank'],'full_name'=>$x['emp_name']]);
            if (!empty($x['submitted_by'])) {
                $pdo->prepare("INSERT INTO notifications (title,message,link,kind,audience,target_user_id,created_by,created_at) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute(["Leave extension $newStatus", $label." — extension to ".$x['new_end_date']." $newStatus by ".$user['full_name'],'/leave-requests.php#le-'.$id,'leave','user',(int)$x['submitted_by'],$user['id'],date('c')]);
            }
            notify_superadmins('Leave extension '.$newStatus, $label.' — '.$x['new_end_date'].' ('.$newStatus.')', ['created_by'=>$user['id'],'kind'=>'leave','link'=>'/leave-requests.php']);
            $sms = send_leave_status_sms($pdo, (int)$x['leave_request_id'], $newStatus, 'extension', $id);
            $flash = 'Extension decision recorded.';
            if (!empty($sms['ok'])) {
                $flash .= !empty($sms['skipped'])
                    ? ' SMS notification already sent.'
                    : ' SMS notification submitted to the provider.';
            } else {
                $flash .= ' SMS not sent: ' . ($sms['error'] ?? 'unknown error') . '.';
            }
            flash('msg', $flash);
        }
    } elseif ($action === 'refresh_sms_status') {
        $n = refresh_leave_sms_delivery($pdo, 50);
        flash('msg', $n
            ? "Delivery status updated for {$n} SMS message".($n === 1 ? '' : 's').'.'
            : 'No pending SMS delivery status to update.');
    } elseif ($action === 'retry_leave_sms') {
        $logId = (int)($_POST['id'] ?? 0);
        $retryStmt = $pdo->prepare("SELECT l.leave_request_id, l.status, l.event_type, l.event_id,
                                           lr.status AS leave_status, lr.leave_type,
                                           le.status AS extension_status
                                    FROM leave_sms_log l
                                    JOIN leave_requests lr ON lr.id = l.leave_request_id
                                    JOIN employees e ON e.id = l.employee_id
                                    LEFT JOIN leave_extensions le ON l.event_type = 'extension' AND le.id = l.event_id
                                    WHERE l.id = ? AND $scopeW");
        $retryStmt->execute(array_merge([$logId], $scopeP));
        $retry = $retryStmt->fetch();
        $eventType = $retry && $retry['event_type'] === 'extension' ? 'extension' : 'leave';
        $currentStatus = $retry
            ? ($eventType === 'extension' ? $retry['extension_status'] : $retry['leave_status'])
            : null;
        if ($retry && can_approve_leave($user, $retry['leave_type']) && $currentStatus === $retry['status']) {
            $sms = send_leave_status_sms(
                $pdo,
                (int)$retry['leave_request_id'],
                (string)$retry['status'],
                $eventType,
                $eventType === 'extension' ? (int)$retry['event_id'] : null,
                true
            );
            flash('msg', !empty($sms['ok'])
                ? 'SMS resend submitted to the provider.'
                : 'SMS resend failed: ' . ($sms['error'] ?? 'unknown error') . '.');
        } else {
            flash('err', 'You are not authorized to resend this SMS, or its leave decision has changed.');
        }
    }
    header('Location:/leave-requests.php'); exit;
}

$empStmt = $pdo->prepare("SELECT id,service_no,full_name,rank FROM employees e WHERE e.active=1 AND $scopeW ORDER BY " . personnel_order_sql('e'));
$empStmt->execute($scopeP); $emps = $empStmt->fetchAll();
[$lrW,$lrP] = scope_where_for($user,'lr');
$today = date('Y-m-d');
$statStmt = $pdo->prepare("SELECT
        COUNT(DISTINCT CASE WHEN lr.status='approved' AND lr.start_date <= ? AND lr.end_date >= ? THEN lr.employee_id END) AS on_leave,
        SUM(CASE WHEN lr.status='pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN lr.status='rejected' THEN 1 ELSE 0 END) AS rejected
    FROM leave_requests lr WHERE $lrW");
$statStmt->execute(array_merge([$today, $today], $lrP));
$leaveStats = $statStmt->fetch() ?: [];
$leaveStats = [
    'on_leave' => (int)($leaveStats['on_leave'] ?? 0),
    'pending'  => (int)($leaveStats['pending'] ?? 0),
    'rejected' => (int)($leaveStats['rejected'] ?? 0),
];
$lrRows = $pdo->prepare("SELECT lr.*, e.service_no, e.rank, e.full_name AS emp_name, s.full_name AS submitter, rv.full_name AS reviewer, rg.name AS region_name FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id LEFT JOIN users s ON s.id=lr.submitted_by LEFT JOIN users rv ON rv.id=lr.reviewed_by LEFT JOIN regions rg ON rg.id=lr.region_id WHERE $lrW ORDER BY CASE lr.status WHEN 'pending' THEN 1 ELSE 2 END, lr.submitted_at DESC, " . personnel_order_sql('e') . " LIMIT 200");
$lrRows->execute($lrP); $requests=$lrRows->fetchAll();

$extRows = $pdo->prepare("SELECT le.*, e.service_no, e.rank, e.full_name AS emp_name, lr.leave_type,
                                 rv.full_name AS reviewer, rq.full_name AS requester
                          FROM leave_extensions le
                          JOIN employees e ON e.id=le.employee_id
                          JOIN leave_requests lr ON lr.id=le.leave_request_id
                          LEFT JOIN users rv ON rv.id=le.reviewed_by
                          LEFT JOIN users rq ON rq.id=le.requested_by
                          WHERE $scopeW
                          ORDER BY CASE le.status WHEN 'pending' THEN 1 ELSE 2 END, le.requested_at DESC
                          LIMIT 100");
$extRows->execute($scopeP); $extensions = $extRows->fetchAll();

$smsLogStmt = $pdo->prepare("SELECT l.*, e.service_no, e.rank, e.full_name AS emp_name, lr.leave_type
                            FROM leave_sms_log l
                            JOIN leave_requests lr ON lr.id = l.leave_request_id
                            JOIN employees e ON e.id = l.employee_id
                            WHERE $scopeW
                            ORDER BY l.id DESC LIMIT 100");
$smsLogStmt->execute($scopeP); $smsLog = $smsLogStmt->fetchAll();

function lr_pill(string $s): string {
    $map=['pending'=>['Pending','badge-sick'],'approved'=>['Approved','badge-present'],'rejected'=>['Rejected','badge-awol']];
    [$l,$c]=$map[$s]??[$s,'badge-admin'];
    return '<span class="badge '.$c.'">'.htmlspecialchars($l).'</span>';
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Leave Management</h1><div class="desc">Leave requests, adjustments &amp; per-personnel leave counts</div></div>
  <div class="action-bar">
    <details class="form-panel" id="leave-form">
      <summary><button type="button" class="btn-icon bi-primary" title="Submit leave request"><?= ICO_PLUS ?></button></summary>
      <div class="form-body">
        <h4 style="margin:0 0 14px;color:var(--navy-800)">New Leave Request</h4>
        <form method="post">
  <?= csrf_field() ?>
          <input type="hidden" name="action" value="submit">
          <div class="form-row">
            <div class="form-group" style="flex:2"><label>Employee</label>
              <select name="employee_id" required><option value="">— select —</option>
                <?php foreach ($emps as $emp): ?><option value="<?= $emp['id'] ?>"><?= e(personnel_label($emp)) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Leave Type</label>
              <select name="leave_type">
                <?php foreach ($leaveTypes as $lt): ?><option value="<?= e($lt['name']) ?>"><?= e($lt['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Start Date</label><input type="date" name="start_date" required value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label>End Date</label><input type="date" name="end_date" required value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group" style="flex:2"><label>Reason</label><input type="text" name="reason" required></div>
            <div class="form-group" style="flex:2"><label>Destination (where going)</label><input type="text" name="destination" placeholder="e.g. Kampala / home district"></div>
          </div>
          <div class="action-bar" style="margin-top:10px">
            <button class="btn-icon bi-gold bi-lg" type="submit" title="Submit request"><?= ICO_SEND ?></button>
          </div>
        </form>
      </div>
    </details>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

<?php
/* ═══ Leave Counts & Adjustments ═══════════════════════════════════ */
$balYear = (int)($_GET['byear'] ?? date('Y'));
if ($balYear < 2000 || $balYear > 2100) $balYear = (int)date('Y');
$balQ = trim((string)($_GET['q'] ?? ''));
$balRank = trim((string)($_GET['rank'] ?? ''));
$balPerPage = 10;
$pageNum = max(1, (int)($_GET['pg'] ?? 1));

$balFilterSql = '';
$balFilterParams = [];
if ($balQ !== '') {
    $balFilterSql .= " AND (e.full_name LIKE ? OR e.service_no LIKE ?)";
    $like = '%' . $balQ . '%';
    $balFilterParams[] = $like;
    $balFilterParams[] = $like;
}
if ($balRank !== '') {
    $balFilterSql .= " AND e.rank = ?";
    $balFilterParams[] = $balRank;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM employees e WHERE e.active=1 AND $scopeW $balFilterSql");
$countStmt->execute(array_merge($scopeP, $balFilterParams));
$totalFiltered = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($totalFiltered / $balPerPage));
$pageNum = min($pageNum, $pages);
$offset = ($pageNum - 1) * $balPerPage;

$balEmpsStmt = $pdo->prepare("SELECT e.id, e.service_no, e.full_name, e.rank, e.annual_leave_days,
                                 rg.name AS region_name, st.name AS station_name, pt.name AS post_name
                          FROM employees e
                          LEFT JOIN regions rg ON rg.id=e.region_id
                          LEFT JOIN stations st ON st.id=e.station_id
                          LEFT JOIN posts pt ON pt.id=e.post_id
                          WHERE e.active=1 AND $scopeW $balFilterSql
                          ORDER BY " . personnel_order_sql('e') . " LIMIT $balPerPage OFFSET $offset");
$balEmpsStmt->execute(array_merge($scopeP, $balFilterParams));
$balEmps = $balEmpsStmt->fetchAll();

$allIdsStmt = $pdo->prepare("SELECT e.id FROM employees e WHERE e.active=1 AND $scopeW");
$allIdsStmt->execute($scopeP);
$allIds = array_map('intval', $allIdsStmt->fetchAll(PDO::FETCH_COLUMN));
$balances = leave_balances($pdo, $allIds, (string)$balYear);
$avgRemaining = $allIds
    ? round(array_sum(array_column($balances, 'remaining')) / count($allIds), 1)
    : 0;

$adjIdsSql = empty($allIds) ? '0' : implode(',', $allIds);
$adjSQL = "SELECT la.*, e.service_no, e.rank, e.full_name AS emp_name FROM leave_adjustments la
                          JOIN employees e ON e.id=la.employee_id WHERE $scopeW AND la.employee_id IN ($adjIdsSql)
                          ORDER BY la.created_at DESC LIMIT 100";
$adjRows = $pdo->prepare($adjSQL);
$adjRows->execute($scopeP);
$adjRows = $adjRows->fetchAll();

$rankStmt = $pdo->prepare("SELECT DISTINCT e.rank FROM employees e
                           WHERE e.active=1 AND $scopeW AND e.rank IS NOT NULL AND e.rank <> ''
                           ORDER BY " . rank_order_sql('e.rank'));
$rankStmt->execute($scopeP);
$rankChoices = $rankStmt->fetchAll(PDO::FETCH_COLUMN);

$balQs = ['byear' => $balYear];
if ($balQ !== '') $balQs['q'] = $balQ;
if ($balRank !== '') $balQs['rank'] = $balRank;
$balBaseUrl = '/leave-requests.php?' . http_build_query($balQs);
$pageUrl = function (int $n) use ($balBaseUrl): string {
    return $balBaseUrl . '&pg=' . max(1, $n);
};
$resetUrl = '/leave-requests.php?byear=' . $balYear;
$hasBalFilters = $balQ !== '' || $balRank !== '';
$start = $totalFiltered ? $offset + 1 : 0;
$end = min($offset + $balPerPage, $totalFiltered);

$pgNums = [1];
$winFrom = max(2, $pageNum - 2);
$winTo = min($pages - 1, $pageNum + 2);
for ($i = $winFrom; $i <= $winTo; $i++) $pgNums[] = $i;
if ($pages > 1) $pgNums[] = $pages;
$pgNums = array_values(array_unique($pgNums));
sort($pgNums);
?>

<div class="grid grid-4" style="margin-bottom:12px">
  <div class="stat stat-leave">
    <div>
      <div class="label">Total officers on leave</div>
      <div class="value"><?= number_format($leaveStats['on_leave']) ?></div>
      <div class="hint">Approved and active today</div>
    </div>
    <div class="icon" style="color:#2563eb">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    </div>
  </div>
  <div class="stat stat-sick">
    <div>
      <div class="label">Pending approval</div>
      <div class="value"><?= number_format($leaveStats['pending']) ?></div>
      <div class="hint">Requests awaiting a decision</div>
    </div>
    <div class="icon" style="color:#d97706">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/></svg>
    </div>
  </div>
  <div class="stat stat-on_duty">
    <div>
      <div class="label">Avg remaining</div>
      <div class="value"><?= number_format($avgRemaining, 1) ?></div>
      <div class="hint">Average leave days · <?= $balYear ?></div>
    </div>
    <div class="icon" style="color:#0891b2">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
    </div>
  </div>
  <div class="stat stat-awol">
    <div>
      <div class="label">Rejected</div>
      <div class="value"><?= number_format($leaveStats['rejected']) ?></div>
      <div class="hint">Rejected leave requests</div>
    </div>
    <div class="icon" style="color:#dc2626"><?= ICO_REJECT ?></div>
  </div>
</div>

<div class="card filter-card" style="margin-bottom:12px">
  <div class="filter-head">
    <div class="fh-title"><?= ICO_SEARCH ?> Find Personnel</div>
    <div class="fh-meta"><?= $hasBalFilters ? 'Filters applied' : 'All staff in your command scope' ?> — <?= number_format($totalFiltered) ?> record(s)</div>
  </div>
  <form method="get" action="/leave-requests.php" class="filter-bar">
    <input type="hidden" name="byear" value="<?= $balYear ?>">
    <div class="filter-field search">
      <label for="lr-q">Name or File/Force No</label>
      <div class="filter-search">
        <?= ICO_SEARCH ?>
        <input type="search" id="lr-q" name="q" value="<?= e($balQ) ?>" placeholder="Search by name or file/force no...">
      </div>
    </div>
    <div class="filter-field">
      <label for="lr-rank">Rank</label>
      <select name="rank" id="lr-rank">
        <option value="">All ranks</option>
        <?php foreach ($rankChoices as $rank): ?>
        <option value="<?= e($rank) ?>" <?= $balRank === $rank ? 'selected' : '' ?>><?= e($rank) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-actions">
      <button class="btn-icon bi-primary btn-labeled" type="submit" title="Apply filters"><?= ICO_SEARCH ?><span>Apply</span></button>
      <?php if ($hasBalFilters): ?>
      <a class="btn-icon bi-secondary btn-labeled" href="<?= e($resetUrl) ?>" title="Clear filters"><?= ICO_CANCEL ?><span>Reset</span></a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="card" style="margin-bottom:12px">
  <div class="chr">
    <h3>Leave Counts / Balances — <?= $balYear ?>
      <span style="font-weight:400;color:var(--muted);font-size:12px">(entitlement + adjustments − approved taken)</span></h3>
    <div class="action-bar">
      <details class="form-panel">
        <summary><button type="button" class="btn-icon bi-secondary bi-sm" title="Leave year"><?= ICO_SEARCH ?></button></summary>
        <div class="form-body" style="padding:12px 16px">
          <form method="get" style="display:flex;gap:8px;align-items:flex-end">
            <input type="hidden" name="q" value="<?= e($balQ) ?>">
            <input type="hidden" name="rank" value="<?= e($balRank) ?>">
            <div class="form-group"><label>Leave Year</label>
              <input type="number" name="byear" value="<?= $balYear ?>" min="2000" max="2100">
            </div>
            <button class="btn-icon bi-primary" type="submit" title="Apply"><?= ICO_SAVE ?></button>
          </form>
        </div>
      </details>
      <?php if (role_rank($user['role']) > role_rank('officer')): ?>
      <div class="panel-wrap">
        <button class="btn-icon bi-primary bi-sm" data-panel="panel-adjust" title="Adjust leave days"><?= ICO_EDIT ?></button>
        <div class="panel-drop" id="panel-adjust" style="min-width:360px">
          <h4>Adjust Leave Days</h4>
          <form method="post" style="display:flex;flex-direction:column;gap:10px">
  <?= csrf_field() ?>
            <input type="hidden" name="action" value="adjust">
            <div class="form-group"><label>Employee</label>
              <select name="employee_id" required><option value="">— select —</option>
                <?php foreach ($emps as $e): ?><option value="<?= $e['id'] ?>"><?= e(personnel_label($e)) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-row">
              <div class="form-group"><label>Days (use + or −, e.g. -2 or +5)</label>
                <input type="number" name="days" step="1">
              </div>
              <div class="form-group" style="flex:2"><label>New Entitlement (optional — replaces annual days)</label>
                <input type="number" name="annual_leave_days" step="1" placeholder="Leave blank unless overriding entitlement">
              </div>
            </div>
            <div class="form-group"><label>Reason</label><input type="text" name="reason" required placeholder="e.g. Prior-year carry-over, forfeiture, correction"></div>
            <div class="action-bar" style="justify-content:flex-end">
              <button class="btn-icon bi-gold bi-lg" type="submit" title="Apply"><?= ICO_SAVE ?></button>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>File/Force No</th><th>Rank</th><th>Full Name</th>
        <th>Entitlement</th><th>Adjustments</th><th>Taken</th><th>Pending</th><th>Remaining</th>
      </tr></thead>
      <tbody>
        <?php foreach ($balEmps as $e):
          $b = $balances[$e['id']] ?? ['entitlement'=>0,'adjust'=>0,'taken'=>0,'pending'=>0,'remaining'=>0];
          $remaining = $b['remaining'];
          $remColor = $remaining < 0 ? '#ef4444' : ($remaining <= 5 ? '#f59e0b' : '#16a34a');
        ?>
        <tr>
          <td style="font-family:monospace;font-size:12px"><?= e($e['service_no']) ?></td>
          <td style="white-space:nowrap"><?= e($e['rank']) ?></td>
          <td><strong><?= e($e['full_name']) ?></strong></td>
          <td><?= (int)$e['annual_leave_days'] ?></td>
          <td><?= $b['adjust'] ? '<span style="color:'.($b['adjust']>0?'#16a34a':'#ef4444').';font-weight:600">'.($b['adjust']>0?'+':'').$b['adjust'].'</span>' : '—' ?></td>
          <td><?= $b['taken'] ?: '—' ?></td>
          <td><?= $b['pending'] ? '<span style="color:#f59e0b;font-weight:600">'.$b['pending'].'</span>' : '—' ?></td>
          <td><strong style="color:<?= $remColor ?>"><?= $remaining ?></strong></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$balEmps): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:20px"><?= $hasBalFilters ? 'No personnel match your filters.' : 'No personnel in your command scope.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="pager">
    <div class="pager-info">Showing <strong><?= $start ?>-<?= $end ?></strong> of <strong><?= number_format($totalFiltered) ?></strong> — 10 per page</div>
    <div class="pager-controls">
      <?php if ($pageNum > 1): ?>
      <a class="btn-icon bi-secondary pager-btn" href="<?= e($pageUrl($pageNum - 1)) ?>">&larr; Previous</a>
      <?php else: ?>
      <span class="btn-icon bi-secondary pager-btn is-disabled" aria-disabled="true">&larr; Previous</span>
      <?php endif; ?>
      <?php $lastPg = 0; foreach ($pgNums as $n): ?>
        <?php if ($lastPg && $n - $lastPg > 1): ?><span class="pager-gap">&hellip;</span><?php endif; ?>
        <?php if ($n === $pageNum): ?>
        <span class="pager-num is-active" aria-current="page"><?= $n ?></span>
        <?php else: ?>
        <a class="pager-num" href="<?= e($pageUrl($n)) ?>"><?= $n ?></a>
        <?php endif; ?>
      <?php $lastPg = $n; endforeach; ?>
      <?php if ($pageNum < $pages): ?>
      <a class="btn-icon bi-secondary pager-btn" href="<?= e($pageUrl($pageNum + 1)) ?>">Next &rarr;</a>
      <?php else: ?>
      <span class="btn-icon bi-secondary pager-btn is-disabled" aria-disabled="true">Next &rarr;</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (role_rank($user['role']) > role_rank('officer') && $adjRows): ?>
<div class="card" style="margin-bottom:12px">
  <div class="chr"><h3>Recent Leave Adjustments</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>File/Force No</th><th>Rank</th><th>Full Name</th><th>Days</th><th>Reason</th><th>Adjusted By</th><th>When</th></tr></thead>
      <tbody>
        <?php foreach ($adjRows as $a): ?>
        <tr>
          <td style="font-family:monospace;font-size:12px"><?= e($a['service_no']) ?></td>
          <td style="white-space:nowrap"><?= e($a['rank']) ?></td>
          <td><strong><?= e($a['emp_name']) ?></strong></td>
          <td><span style="color:<?= $a['days']>0?'#16a34a':'#ef4444' ?>;font-weight:600"><?= $a['days']>0?'+':'' ?><?= (int)$a['days'] ?></span></td>
          <td><?= e($a['reason']) ?></td>
          <td><?= e($a['created_by'] ? ($pdo->query("SELECT full_name FROM users WHERE id=".(int)$a['created_by'])->fetchColumn() ?: '—') : '—') ?></td>
          <td class="muted" style="font-size:12px"><?= e(date('M j, Y H:i', strtotime($a['created_at']))) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="chr"><h3>Leave Requests</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>File/Force No</th><th>Rank</th><th>Full Name</th><th>Region</th><th>Type</th><th>Dates</th><th>Destination</th><th>Remaining</th><th>Reason</th><th>Status</th><th>Submitted</th><th style="width:80px"></th></tr></thead>
      <tbody>
        <?php foreach ($requests as $r): ?>
        <tr id="lr-<?= $r['id'] ?>">
          <td style="font-family:monospace;font-size:12px"><?= e($r['service_no']) ?></td>
          <td style="white-space:nowrap"><?= e($r['rank']) ?></td>
          <td><strong><?= e($r['emp_name']) ?></strong></td>
          <td><?= e($r['region_name']??'—') ?></td>
          <td><?= e($r['leave_type']) ?></td>
          <td style="white-space:nowrap"><?= e($r['start_date']) ?><br><span class="muted">→ <?= e($r['end_date']) ?></span></td>
          <td><?= e($r['destination'] ?? '—') ?></td>
          <td style="white-space:nowrap">
            <?php if ($r['status']==='approved'):
              $cd = leave_countdown($r);
              if (date('Y-m-d') > $r['end_date']) {
                echo '<span class="badge badge-awol">Expired</span>';
              } elseif ($cd <= 2) {
                echo '<span class="badge badge-awol">'.$cd.' day'.($cd===1?'':'s').' left</span>';
              } else {
                echo '<span class="badge badge-present">'.$cd.' days left</span>';
              }
            else: ?><span class="muted" style="font-size:12px">—</span><?php endif; ?>
          </td>
          <td><?= e($r['reason']) ?></td>
          <td><?= lr_pill($r['status']) ?>
            <?php if ($r['reviewer']): ?><div class="muted" style="font-size:10px"><?= e($r['reviewer']) ?></div><?php endif; ?>
          </td>
          <td class="muted" style="font-size:11px"><?= e(date('M j, H:i',strtotime($r['submitted_at']))) ?><br><?= e($r['submitter']??'—') ?></td>
          <td>
            <?php if ($r['status']==='pending' && can_approve_leave($user, $r['leave_type'])): ?>
            <details class="form-panel" style="position:relative">
              <summary><button type="button" class="btn-icon bi-secondary bi-sm" title="Review request"><?= ICO_EYE ?></button></summary>
              <div class="form-body form-pop" style="position:absolute;right:0;top:100%;min-width:260px;z-index:20;padding:14px;box-shadow:var(--shadow-md)">
                <form method="post" style="display:flex;flex-direction:column;gap:8px">
  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <input type="text" name="notes" placeholder="Review notes (optional)">
                  <div class="action-bar">
                    <button class="btn-icon bi-green" name="decision" value="approve" title="Approve"><?= ICO_OK ?></button>
                    <button class="btn-icon bi-danger" name="decision" value="reject" title="Reject"><?= ICO_REJECT ?></button>
                  </div>
                </form>
              </div>
            </details>
            <?php endif; ?>
            <?php if ($r['status']==='approved'): ?>
            <details class="form-panel" style="position:relative">
              <summary><button type="button" class="btn-icon bi-gold bi-sm" title="Request leave extension"><?= ICO_EDIT ?></button></summary>
              <div class="form-body form-pop" style="position:absolute;right:0;top:100%;min-width:270px;z-index:20;padding:14px;box-shadow:var(--shadow-md)">
                <form method="post" style="display:flex;flex-direction:column;gap:8px">
  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="submit_extension">
                  <input type="hidden" name="leave_request_id" value="<?= $r['id'] ?>">
                  <div style="font-size:12px;color:var(--muted)">Current end date: <strong><?= e($r['end_date']) ?></strong></div>
                  <input type="date" name="new_end_date" required value="<?= e(date('Y-m-d', strtotime($r['end_date'].' +7 days'))) ?>">
                  <input type="text" name="reason" placeholder="Reason for extension (optional)">
                  <div class="action-bar" style="justify-content:flex-end">
                    <button class="btn-icon bi-gold bi-sm" type="submit" title="Submit extension request"><?= ICO_SEND ?></button>
                  </div>
                </form>
              </div>
            </details>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$requests): ?><tr><td colspan="12" style="text-align:center;color:var(--muted);padding:24px">No leave requests found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="chr"><h3>Leave Extensions <span class="badge badge-admin"><?= count($extensions) ?></span></h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>File/Force No</th><th>Rank</th><th>Full Name</th><th>Type</th><th>Extension</th><th>Reason</th><th>Requested By</th><th>Status</th><th style="width:80px"></th></tr></thead>
      <tbody>
        <?php foreach ($extensions as $x): ?>
        <tr id="le-<?= (int)$x['id'] ?>">
          <td style="font-family:monospace;font-size:12px"><?= e($x['service_no']) ?></td>
          <td style="white-space:nowrap"><?= e($x['rank']) ?></td>
          <td><strong><?= e($x['emp_name']) ?></strong></td>
          <td><?= e($x['leave_type']) ?></td>
          <td style="white-space:nowrap;font-size:12px"><?= e($x['old_end_date']) ?><br><span class="muted">→ <?= e($x['new_end_date']) ?></span></td>
          <td style="font-size:12px"><?= e($x['reason'] ?? '—') ?></td>
          <td class="muted" style="font-size:11px"><?= e($x['requester'] ?? '—') ?><br><?= e(date('M j, H:i', strtotime($x['requested_at']))) ?></td>
          <td>
            <?php if ($x['status']==='pending'): ?><span class="badge badge-sick">Pending</span>
            <?php elseif ($x['status']==='approved'): ?><span class="badge badge-present">Approved</span>
              <?php if ($x['reviewer']): ?><div class="muted" style="font-size:10px"><?= e($x['reviewer']) ?></div><?php endif; ?>
            <?php else: ?><span class="badge badge-awol">Rejected</span><?php endif; ?>
          </td>
          <td>
            <?php if ($x['status']==='pending' && can_approve_leave($user, $x['leave_type'])): ?>
            <details class="form-panel" style="position:relative">
              <summary><button type="button" class="btn-icon bi-secondary bi-sm" title="Review extension"><?= ICO_EYE ?></button></summary>
              <div class="form-body form-pop" style="position:absolute;right:0;top:100%;min-width:260px;z-index:20;padding:14px;box-shadow:var(--shadow-md)">
                <form method="post" style="display:flex;flex-direction:column;gap:8px">
  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="review_extension"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
                  <input type="text" name="notes" placeholder="Review notes (optional)">
                  <div class="action-bar">
                    <button class="btn-icon bi-green" name="decision" value="approve" title="Approve extension"><?= ICO_OK ?></button>
                    <button class="btn-icon bi-danger" name="decision" value="reject" title="Reject extension"><?= ICO_REJECT ?></button>
                  </div>
                </form>
              </div>
            </details>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$extensions): ?><tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">No leave extension requests.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="chr">
    <h3>Leave SMS Notifications <span class="badge badge-admin"><?= count($smsLog) ?></span></h3>
    <form method="post" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="refresh_sms_status">
      <button class="btn-icon bi-secondary bi-sm" type="submit" title="Refresh delivery status from provider" style="width:auto;padding:0 12px;gap:6px;font-size:11px">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        <span>Refresh status</span>
      </button>
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>File/Force No</th><th>Rank</th><th>Full Name</th><th>Phone</th><th>Decision</th><th>Event</th><th>Delivery</th><th>Sent</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($smsLog as $s): ?>
        <tr>
          <td style="font-family:monospace;font-size:12px"><?= e($s['service_no'] ?? '—') ?></td>
          <td style="white-space:nowrap"><?= e($s['rank'] ?? '—') ?></td>
          <td><strong><?= e($s['emp_name'] ?? '—') ?></strong></td>
          <td style="font-size:12px"><?= e($s['phone'] ?? '—') ?></td>
          <td><?= lr_pill((string)$s['status']) ?></td>
          <td style="font-size:12px;text-transform:capitalize"><?= e($s['event_type']) ?></td>
          <td style="font-size:12px">
            <?php
              $resp  = (string)$s['response'];
              $pstat = strtoupper((string)($s['provider_status'] ?? ''));
              if (leave_sms_delivery_failed($pstat)):
            ?>
              <span class="badge badge-awol" title="<?= e($resp !== 'ok' ? $resp : $pstat) ?>">Undeliverable</span>
              <div class="muted" style="font-size:10px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($resp) ?>"><?= e($resp !== 'ok' ? $resp : $pstat) ?></div>
            <?php elseif ($resp !== 'ok'): ?>
              <span class="badge badge-awol" title="<?= e($resp) ?>">Not delivered</span>
              <div class="muted" style="font-size:10px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($resp) ?>"><?= e($resp) ?></div>
            <?php elseif ($pstat === 'DELIVERED'): ?>
              <span class="badge badge-present" title="<?= e($pstat) ?>">Delivered</span>
            <?php elseif (str_starts_with($pstat, 'PENDING')): ?>
              <span class="badge badge-sick" title="<?= e($pstat) ?>">Pending</span>
            <?php else: ?>
              <span class="badge badge-sick" title="<?= e($pstat !== '' ? $pstat : 'No delivery report received') ?>">Accepted; delivery unconfirmed</span>
            <?php endif; ?>
            <?php if (!empty($s['provider_ref'])): ?>
              <div class="muted" style="font-size:10px" title="Provider message ID">ID: <?= e($s['provider_ref']) ?></div>
            <?php endif; ?>
          </td>
          <td class="muted" style="font-size:11px"><?= e(date('M j, Y H:i', strtotime($s['sent_at']))) ?></td>
          <td>
            <?php if (can_approve_leave($user, $s['leave_type'])): ?>
            <form method="post" style="margin:0" onsubmit="return confirm('Resend this SMS to the current personnel phone number?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="retry_leave_sms">
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <button class="btn-icon bi-secondary bi-sm" type="submit" title="Resend SMS to the current personnel phone number"><?= ICO_SEND ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$smsLog): ?><tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">No leave SMS notifications sent yet.</td></tr><?php endif; ?>
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
