<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
$user = require_login();
$page = 'leave';
$page_title = 'Leave Management';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

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
                    log_activity('Leave days adjusted', 'leave_adjustment', (string)$days, (int)$eid, 'Employee #'.$eid);
                }
                if ($newEnt !== '') {
                    $pdo->prepare("UPDATE employees SET annual_leave_days=? WHERE id=?")->execute([max(0,(int)$newEnt), $eid]);
                    log_activity('Set annual leave entitlement', 'employee', (string)(int)$newEnt, (int)$eid, 'Personnel #'.$eid);
                }
                notify_superadmins('Leave adjustment recorded',
                    'Personnel #'.$eid.' adjusted'.($days!==0?' by '.$days.' days':'').($newEnt!==''?' (entitlement '.max(0,(int)$newEnt).' days)':'').' — '.trim($_POST['reason'] ?? ''),
                    ['created_by'=>$user['id'],'kind'=>'leave']);
                flash('msg', 'Leave balance updated.');
            }
        }
    } elseif ($action === 'submit') {
        $eid = (int)$_POST['employee_id'];
        $emp = $pdo->prepare("SELECT * FROM employees e WHERE e.id=? AND e.active=1 AND $scopeW");
        $emp->execute(array_merge([$eid], $scopeP)); $e = $emp->fetch();
        if ($e) {
            $pdo->prepare("INSERT INTO leave_requests (employee_id,post_id,station_id,division_id,region_id,leave_type,start_date,end_date,reason,status,submitted_by,submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$eid,$e['post_id'],$e['station_id'],$e['division_id'],$e['region_id'],$_POST['leave_type']??'Annual',$_POST['start_date'],$_POST['end_date'],$_POST['reason']??'','pending',$user['id'],date('c')]);
            notify_superadmins('Leave request submitted',
                $e['full_name'].' ('.($_POST['leave_type']??'Annual').') — submitted by '.role_label($user['role']),
                ['created_by'=>$user['id'],'kind'=>'leave']);
            flash('msg','Leave request submitted.');
        }
    } elseif ($action === 'review') {
        $id=(int)$_POST['id']; $decision=$_POST['decision']; $notes=$_POST['notes']??'';
        $req = $pdo->prepare("SELECT lr.*, e.full_name AS emp_name FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id WHERE lr.id=?");
        $req->execute([$id]); $req=$req->fetch();
        if ($req && $req['status']==='pending' && can_approve_leave($user, $req['leave_type'])) {
            $newStatus = $decision==='approve' ? 'approved' : 'rejected';
            $pdo->prepare("UPDATE leave_requests SET status=?,reviewed_by=?,reviewed_at=?,review_notes=? WHERE id=?")->execute([$newStatus,$user['id'],date('c'),$notes,$id]);
            $pdo->prepare("INSERT INTO notifications (title,message,link,kind,audience,target_user_id,created_by,created_at) VALUES (?,?,?,?,?,?,?,?)")
                ->execute(["Leave request $newStatus",$req['emp_name']." — $newStatus by ".$user['full_name'],'/leave-requests.php#lr-'.$id,'leave','user',(int)$req['submitted_by'],$user['id'],date('c')]);
            flash('msg','Decision recorded.');
        }
    }
    header('Location:/leave-requests.php'); exit;
}

$empStmt = $pdo->prepare("SELECT id,full_name,service_no FROM employees e WHERE e.active=1 AND $scopeW ORDER BY full_name");
$empStmt->execute($scopeP); $emps = $empStmt->fetchAll();
[$lrW,$lrP] = scope_where_for($user,'lr');
$lrRows = $pdo->prepare("SELECT lr.*, e.full_name AS emp_name, e.service_no, s.full_name AS submitter, rv.full_name AS reviewer, rg.name AS region_name FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id LEFT JOIN users s ON s.id=lr.submitted_by LEFT JOIN users rv ON rv.id=lr.reviewed_by LEFT JOIN regions rg ON rg.id=lr.region_id WHERE $lrW ORDER BY CASE lr.status WHEN 'pending' THEN 1 ELSE 2 END, lr.submitted_at DESC LIMIT 200");
$lrRows->execute($lrP); $requests=$lrRows->fetchAll();

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
          <input type="hidden" name="action" value="submit">
          <div class="form-row">
            <div class="form-group" style="flex:2"><label>Employee</label>
              <select name="employee_id" required><option value="">— select —</option>
                <?php foreach ($emps as $emp): ?><option value="<?= $emp['id'] ?>"><?= e($emp['service_no'].' — '.$emp['full_name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Leave Type</label>
              <select name="leave_type"><option>Annual</option><option>Sick</option><option>Compassionate</option><option>Maternity</option><option>Study</option><option>Other</option></select>
            </div>
            <div class="form-group"><label>Start Date</label><input type="date" name="start_date" required value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label>End Date</label><input type="date" name="end_date" required value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group" style="flex:2"><label>Reason</label><input type="text" name="reason" required></div>
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
$balEmps = $pdo->prepare("SELECT e.id, e.service_no, e.full_name, e.rank, e.annual_leave_days,
                                 rg.name AS region_name, st.name AS station_name, pt.name AS post_name
                          FROM employees e
                          LEFT JOIN regions rg ON rg.id=e.region_id
                          LEFT JOIN stations st ON st.id=e.station_id
                          LEFT JOIN posts pt ON pt.id=e.post_id
                          WHERE e.active=1 AND $scopeW ORDER BY e.full_name");
$balEmps->execute($scopeP); $balEmps = $balEmps->fetchAll();
$balIds  = array_column($balEmps, 'id');
$balances = leave_balances($pdo, $balIds, (string)$balYear);
$adjSQL = "SELECT la.*, e.full_name AS emp_name FROM leave_adjustments la
                          JOIN employees e ON e.id=la.employee_id WHERE $scopeW AND la.employee_id IN (".
                          (empty($balIds) ? '0' : implode(',', array_map('intval',$balIds))).
                          ") ORDER BY la.created_at DESC LIMIT 100";
$adjRows = $pdo->prepare($adjSQL);
$adjRows->execute($scopeP);
$adjRows = $adjRows->fetchAll();
?>

<div class="card" style="margin-bottom:12px">
  <div class="chr">
    <h3>Leave Counts / Balances — <?= $balYear ?>
      <span style="font-weight:400;color:var(--muted);font-size:12px">(entitlement + adjustments − approved taken)</span></h3>
    <div class="action-bar">
      <details class="form-panel">
        <summary><button type="button" class="btn-icon bi-secondary bi-sm" title="Leave year"><?= ICO_SEARCH ?></button></summary>
        <div class="form-body" style="padding:12px 16px">
          <form method="get" style="display:flex;gap:8px;align-items:flex-end">
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
            <input type="hidden" name="action" value="adjust">
            <div class="form-group"><label>Employee</label>
              <select name="employee_id" required><option value="">— select —</option>
                <?php foreach ($balEmps as $e): ?><option value="<?= $e['id'] ?>"><?= e($e['service_no'].' — '.$e['full_name']) ?></option><?php endforeach; ?>
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
        <th>File No</th><th>Name</th><th>Rank</th>
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
          <td><strong><?= e($e['full_name']) ?></strong></td>
          <td><?= e($e['rank']) ?></td>
          <td><?= (int)$e['annual_leave_days'] ?></td>
          <td><?= $b['adjust'] ? '<span style="color:'.($b['adjust']>0?'#16a34a':'#ef4444').';font-weight:600">'.($b['adjust']>0?'+':'').$b['adjust'].'</span>' : '—' ?></td>
          <td><?= $b['taken'] ?: '—' ?></td>
          <td><?= $b['pending'] ? '<span style="color:#f59e0b;font-weight:600">'.$b['pending'].'</span>' : '—' ?></td>
          <td><strong style="color:<?= $remColor ?>"><?= $remaining ?></strong></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$balEmps): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:20px">No personnel in your command scope.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (role_rank($user['role']) > role_rank('officer') && $adjRows): ?>
<div class="card" style="margin-bottom:12px">
  <div class="chr"><h3>Recent Leave Adjustments</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Employee</th><th>Days</th><th>Reason</th><th>Adjusted By</th><th>When</th></tr></thead>
      <tbody>
        <?php foreach ($adjRows as $a): ?>
        <tr>
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
      <thead><tr><th>Employee</th><th>Region</th><th>Type</th><th>Dates</th><th>Reason</th><th>Status</th><th>Submitted</th><th style="width:80px"></th></tr></thead>
      <tbody>
        <?php foreach ($requests as $r): ?>
        <tr id="lr-<?= $r['id'] ?>">
          <td><strong><?= e($r['emp_name']) ?></strong><br><span class="muted"><?= e($r['service_no']) ?></span></td>
          <td><?= e($r['region_name']??'—') ?></td>
          <td><?= e($r['leave_type']) ?></td>
          <td style="white-space:nowrap"><?= e($r['start_date']) ?><br><span class="muted">→ <?= e($r['end_date']) ?></span></td>
          <td><?= e($r['reason']) ?></td>
          <td><?= lr_pill($r['status']) ?>
            <?php if ($r['reviewer']): ?><div class="muted" style="font-size:10px"><?= e($r['reviewer']) ?></div><?php endif; ?>
          </td>
          <td class="muted" style="font-size:11px"><?= e(date('M j, H:i',strtotime($r['submitted_at']))) ?><br><?= e($r['submitter']??'—') ?></td>
          <td>
            <?php if ($r['status']==='pending' && can_approve_leave($user, $r['leave_type'])): ?>
            <details class="form-panel" style="position:relative">
              <summary><button type="button" class="btn-icon bi-secondary bi-sm" title="Review request"><?= ICO_EYE ?></button></summary>
              <div class="form-body" style="position:absolute;right:0;top:100%;min-width:260px;z-index:20;padding:14px;box-shadow:var(--shadow-md)">
                <form method="post" style="display:flex;flex-direction:column;gap:8px">
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
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$requests): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">No leave requests found.</td></tr><?php endif; ?>
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
