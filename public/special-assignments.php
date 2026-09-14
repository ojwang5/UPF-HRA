<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_min_rank('post_commander');
$page = 'assignments';
$page_title = 'Special Assignments';
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
            $pdo->prepare("INSERT INTO special_assignments (employee_id,title,nature,place,start_date,end_date,status,notes,created_by)
                           VALUES (?,?,?,?,?,?, 'active', ?, ?)")
                ->execute([$eid, trim($_POST['title']), trim($_POST['nature'] ?? '') ?: null,
                           trim($_POST['place'] ?? '') ?: null, $_POST['start_date'], $endDate,
                           trim($_POST['notes'] ?? '') ?: null, $user['id']]);
            log_activity('Created special assignment', 'special_assignment', trim($_POST['title']), 0, 'Employee #'.$eid.' from '.$_POST['start_date']);
            flash('msg', 'Special assignment recorded.');
        }
    } elseif ($id && in_array($action, ['end','cancel'], true)) {
        // Only allow managing assignments within scope
        $chk = $pdo->prepare("SELECT sa.id FROM special_assignments sa JOIN employees e ON e.id=sa.employee_id
                              WHERE sa.id=? AND sa.status='active' AND $scopeW");
        $chk->execute(array_merge([$id], $scopeP));
        if ($chk->fetch()) {
            if ($action === 'end') {
                $pdo->prepare("UPDATE special_assignments SET status='completed', ended_at=datetime('now','localtime'), end_remarks=? WHERE id=?")
                    ->execute([trim($_POST['remarks'] ?? '') ?: null, $id]);
                flash('msg', 'Assignment marked completed.');
            } else {
                $pdo->prepare("UPDATE special_assignments SET status='cancelled', ended_at=datetime('now','localtime'), end_remarks=? WHERE id=?")
                    ->execute([trim($_POST['remarks'] ?? '') ?: null, $id]);
                flash('msg', 'Assignment cancelled.');
            }
        }
    }
    header('Location: /special-assignments.php'); exit;
}

$emps = $pdo->prepare("SELECT id, service_no, full_name, rank FROM employees e WHERE e.active=1 AND $scopeW ORDER BY full_name");
$emps->execute($scopeP); $emps = $emps->fetchAll();

$rows = $pdo->prepare("SELECT sa.*, e.full_name, e.service_no, e.rank,
                              rg.name AS region_name, dv.name AS division_name,
                              st.name AS station_name, pt.name AS post_name
                       FROM special_assignments sa
                       JOIN employees e ON e.id=sa.employee_id
                       LEFT JOIN regions rg ON rg.id=e.region_id
                       LEFT JOIN divisions dv ON dv.id=e.division_id
                       LEFT JOIN stations st ON st.id=e.station_id
                       LEFT JOIN posts pt ON pt.id=e.post_id
                       WHERE $scopeW
                       ORDER BY CASE sa.status WHEN 'active' THEN 1 ELSE 2 END, sa.start_date DESC
                       LIMIT 300");
$rows->execute(array_merge($scopeP)); $rows = $rows->fetchAll();

$activeCount = count(array_filter($rows, fn($r) => $r['status'] === 'active'));

function sa_pill(string $s): string {
    [$l, $c] = match($s) {
        'active'    => ['Active', 'badge-special_assignment'],
        'completed' => ['Completed', 'badge-present'],
        default     => ['Cancelled', 'badge-awol'],
    };
    return '<span class="badge '.$c.'">'.$l.'</span>';
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Special Assignments</h1>
    <div class="desc">Officers detached on special duty · <?= $activeCount ?> active</div></div>
  <div class="action-bar">
    <div class="panel-wrap">
      <button class="btn-icon bi-primary" data-panel="panel-new" title="New special assignment"><?= ICO_PLUS ?></button>
      <div class="panel-drop" id="panel-new" style="min-width:420px">
        <h4>New Special Assignment</h4>
        <form method="post" style="display:flex;flex-direction:column;gap:10px">
          <input type="hidden" name="action" value="create">
          <div class="form-group"><label>Employee</label>
            <select name="employee_id" required><option value="">— select —</option>
              <?php foreach ($emps as $emp): ?><option value="<?= $emp['id'] ?>"><?= e($emp['service_no'].' — '.$emp['rank'].' '.$emp['full_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:2"><label>Title / Assignment</label><input type="text" name="title" required placeholder="e.g. Election Duty Task Team"></div>
            <div class="form-group"><label>Nature</label>
              <select name="nature">
                <option>VIP Protection</option><option>Election Duty</option><option>Operation</option>
                <option>Investigation Task Team</option><option>Peacekeeping Mission</option>
                <option>Guard of Honour</option><option>Protocol Duties</option><option>Other</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:2"><label>Place / Location</label><input type="text" name="place" placeholder="e.g. State House, Entebbe"></div>
            <div class="form-group"><label>Start Date</label><input type="date" name="start_date" required value="<?= date('Y-m-d') ?>"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>End Date <span class="muted">(blank = open-ended)</span></label><input type="date" name="end_date"></div>
            <div class="form-group" style="flex:2"><label>Notes</label><input type="text" name="notes"></div>
          </div>
          <div class="action-bar" style="justify-content:flex-end">
            <button class="btn-icon bi-gold bi-lg" type="submit" title="Save assignment"><?= ICO_SAVE ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

<div class="card">
  <div class="chr"><h3>Assignments</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>File No</th><th>Name</th><th>Rank</th><th>Assignment</th><th>Nature</th><th>Place</th>
        <th>Period</th><th>Status</th><th style="width:90px"></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td style="font-family:monospace;font-size:12px"><?= e($r['service_no']) ?></td>
          <td><strong><?= e($r['full_name']) ?></strong></td>
          <td><?= e($r['rank']) ?></td>
          <td><?= e($r['title']) ?><?= $r['notes'] ? '<br><span class="muted" style="font-size:11px">'.e($r['notes']).'</span>' : '' ?></td>
          <td><?= e($r['nature'] ?? '—') ?></td>
          <td><?= e($r['place'] ?? '—') ?></td>
          <td style="white-space:nowrap;font-size:12px"><?= e(date('j M y', strtotime($r['start_date']))) ?>
            <?= $r['end_date'] ? '<br><span class="muted">→ '.e(date('j M y', strtotime($r['end_date']))).'</span>' : '<br><span class="muted">→ open</span>' ?></td>
          <td><?= sa_pill($r['status']) ?></td>
          <td>
            <?php if ($r['status']==='active'): ?>
            <details class="form-panel" style="position:relative">
              <summary><button type="button" class="btn-icon bi-secondary bi-sm" title="End / cancel assignment"><?= ICO_EDIT ?></button></summary>
              <div class="form-body" style="position:absolute;right:0;top:100%;min-width:240px;z-index:20;padding:14px;box-shadow:var(--shadow-md)">
                <form method="post" style="display:flex;flex-direction:column;gap:8px">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <input type="text" name="remarks" placeholder="Remarks (optional)">
                  <div class="action-bar">
                    <button class="btn-icon bi-green" name="action" value="end" title="Mark completed"><?= ICO_OK ?></button>
                    <button class="btn-icon bi-danger" name="action" value="cancel" title="Cancel assignment"><?= ICO_REJECT ?></button>
                  </div>
                </form>
              </div>
            </details>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">No special assignments recorded.</td></tr><?php endif; ?>
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
