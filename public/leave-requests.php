<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
$user = require_login();
$page = 'leave';
$page_title = 'Leave Requests';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'submit') {
        $eid = (int)$_POST['employee_id'];
        // Verify employee in scope
        $emp = $pdo->prepare("SELECT * FROM employees e WHERE e.id=? AND e.active=1 AND $scopeW");
        $emp->execute(array_merge([$eid], $scopeP)); $e = $emp->fetch();
        if ($e) {
            $pdo->prepare("INSERT INTO leave_requests (employee_id,post_id,station_id,division_id,region_id,leave_type,start_date,end_date,reason,status,submitted_by,submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$eid,$e['post_id'],$e['station_id'],$e['division_id'],$e['region_id'],$_POST['leave_type']??'Annual',$_POST['start_date'],$_POST['end_date'],$_POST['reason']??'','pending',$user['id'],date('c')]);
            $lrid=(int)$pdo->lastInsertId();
            // Notify superadmins + next-level commander
            notify_superadmins('Leave request submitted',
                $e['full_name'].' ('.($_POST['leave_type']??'Annual').') from '.role_label($user['role']),
                ['link'=>'/leave-requests.php#lr-'.$lrid,'created_by'=>$user['id'],'kind'=>'leave']);
            flash('msg','Leave request submitted.');
        }
    } elseif ($action === 'review' && role_rank($user['role']) > role_rank('officer')) {
        $id=(int)$_POST['id']; $decision=$_POST['decision']; $notes=$_POST['notes']??'';
        $req=$pdo->query("SELECT lr.*,e.full_name AS emp_name FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id WHERE lr.id=$id")->fetch();
        if ($req && $req['status']==='pending') {
            $newStatus = $decision==='approve' ? 'approved' : 'rejected';
            $pdo->prepare("UPDATE leave_requests SET status=?,reviewed_by=?,reviewed_at=?,review_notes=? WHERE id=?")
                ->execute([$newStatus,$user['id'],date('c'),$notes,$id]);
            // Notify submitter
            $pdo->prepare("INSERT INTO notifications (title,message,link,kind,audience,target_user_id,created_by,created_at) VALUES (?,?,?,?,?,?,?,?)")
                ->execute(["Leave request $newStatus",$req['emp_name']." — $newStatus by ".e($user['full_name']),'/leave-requests.php#lr-'.$id,'leave','user',(int)$req['submitted_by'],$user['id'],date('c')]);
            flash('msg','Decision recorded.');
        }
    }
    header('Location:/leave-requests.php'); exit;
}

// Employees in scope for submission
$empStmt = $pdo->prepare("SELECT id,full_name,service_no FROM employees e WHERE e.active=1 AND $scopeW ORDER BY full_name");
$empStmt->execute($scopeP); $emps = $empStmt->fetchAll();

// Leave requests in scope
[$lrW,$lrP] = scope_where_for($user,'lr');
$lrRows = $pdo->prepare("
    SELECT lr.*, e.full_name AS emp_name, e.service_no,
           s.full_name AS submitter, rv.full_name AS reviewer,
           rg.name AS region_name
    FROM leave_requests lr
    JOIN employees e ON e.id=lr.employee_id
    LEFT JOIN users s ON s.id=lr.submitted_by
    LEFT JOIN users rv ON rv.id=lr.reviewed_by
    LEFT JOIN regions rg ON rg.id=lr.region_id
    WHERE $lrW
    ORDER BY CASE lr.status WHEN 'pending' THEN 1 ELSE 2 END, lr.submitted_at DESC
    LIMIT 200
");
$lrRows->execute($lrP); $requests=$lrRows->fetchAll();

function lr_pill(string $s): string {
    $map=['pending'=>['Pending','badge-sick'],'approved'=>['Approved','badge-present'],'rejected'=>['Rejected','badge-awol']];
    [$l,$c]=$map[$s]??[$s,'badge-admin'];
    return '<span class="badge '.$c.'">'.htmlspecialchars($l).'</span>';
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Leave Requests</h1><div class="desc">Submit and review personnel leave applications</div></div>
</div>
<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

<div class="card">
  <h3>Submit New Leave Request</h3>
  <form method="post">
    <input type="hidden" name="action" value="submit">
    <div class="form-row">
      <div class="form-group" style="flex:2"><label>Employee</label>
        <select name="employee_id" required><option value="">— select —</option>
          <?php foreach ($emps as $emp): ?><option value="<?= $emp['id'] ?>"><?= e($emp['service_no'].' — '.$emp['full_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Type</label>
        <select name="leave_type"><option>Annual</option><option>Sick</option><option>Compassionate</option><option>Maternity</option><option>Study</option><option>Other</option></select>
      </div>
      <div class="form-group"><label>Start Date</label><input type="date" name="start_date" required value="<?= date('Y-m-d') ?>"></div>
      <div class="form-group"><label>End Date</label><input type="date" name="end_date" required value="<?= date('Y-m-d') ?>"></div>
      <div class="form-group" style="flex:2"><label>Reason</label><input type="text" name="reason" required></div>
      <div class="form-group" style="flex:0"><label>&nbsp;</label><button class="btn">Submit</button></div>
    </div>
  </form>
</div>

<div class="card">
  <h3>Leave Requests</h3>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Employee</th><th>Region</th><th>Type</th><th>Dates</th><th>Reason</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($requests as $r): ?>
        <tr id="lr-<?= $r['id'] ?>">
          <td><strong><?= e($r['emp_name']) ?></strong><br><span class="muted"><?= e($r['service_no']) ?></span></td>
          <td><?= e($r['region_name']??'—') ?></td>
          <td><?= e($r['leave_type']) ?></td>
          <td><?= e($r['start_date']) ?> → <?= e($r['end_date']) ?></td>
          <td><?= e($r['reason']) ?></td>
          <td><?= lr_pill($r['status']) ?>
            <?php if ($r['reviewer']): ?><div class="muted" style="font-size:11px"><?= e($r['reviewer']) ?></div><?php endif; ?>
          </td>
          <td class="muted" style="font-size:12px"><?= e(date('M j, H:i',strtotime($r['submitted_at']))) ?><br><?= e($r['submitter']??'—') ?></td>
          <td>
            <?php if ($r['status']==='pending' && role_rank($user['role']) > role_rank('officer')): ?>
            <details><summary class="btn btn-sm btn-secondary" style="display:inline-block">Review</summary>
              <form method="post" style="margin-top:8px;display:flex;flex-direction:column;gap:6px;min-width:200px">
                <input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <input type="text" name="notes" placeholder="Notes (optional)">
                <div style="display:flex;gap:6px">
                  <button class="btn btn-sm" name="decision" value="approve">Approve</button>
                  <button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button>
                </div>
              </form>
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
<?php include __DIR__ . '/../includes/footer.php'; ?>
