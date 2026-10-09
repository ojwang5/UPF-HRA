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
    } elseif ($id) {
        // Only allow managing assignments within scope
        $chk = $pdo->prepare("SELECT sa.*, e.post_id, e.station_id, e.division_id, e.region_id FROM special_assignments sa JOIN employees e ON e.id=sa.employee_id
                              WHERE sa.id=? AND $scopeW");
        $chk->execute(array_merge([$id], $scopeP));
        $row = $chk->fetch();

        if ($action === 'end') {
            $pdo->prepare("UPDATE special_assignments SET status='completed', ended_at=datetime('now','localtime'), end_remarks=? WHERE id=?")
                ->execute([trim($_POST['remarks'] ?? '') ?: null, $id]);
            log_activity('Completed special assignment', 'special_assignment', '#'.$id);
            flash('msg', 'Assignment marked completed.');
        } elseif ($action === 'cancel') {
            $pdo->prepare("UPDATE special_assignments SET status='cancelled', ended_at=datetime('now','localtime'), end_remarks=? WHERE id=?")
                ->execute([trim($_POST['remarks'] ?? '') ?: null, $id]);
            log_activity('Cancelled special assignment', 'special_assignment', '#'.$id);
            flash('msg', 'Assignment cancelled.');
        } elseif ($action === 'delete') {
            if ($row) {
                $pdo->prepare("DELETE FROM special_assignments WHERE id=?")->execute([$id]);
                log_activity('Deleted special assignment', 'special_assignment', $row['title']);
                flash('msg', 'Assignment deleted.');
            }
        } elseif ($action === 'edit') {
            if ($row && valid_date($_POST['start_date'] ?? '')) {
                $eid = (int)$_POST['employee_id'];
                $emp = $pdo->prepare("SELECT id FROM employees e WHERE e.id=? AND e.active=1 AND $scopeW");
                $emp->execute(array_merge([$eid], $scopeP));
                if (!$emp->fetch()) { flash('err', 'Invalid employee selected.'); }
                else {
                    $endDate = ($_POST['end_date'] ?? '') !== '' ? $_POST['end_date'] : null;
                    $status  = in_array($_POST['status'] ?? '', ['active','completed','cancelled'], true) ? $_POST['status'] : 'active';
                    $pdo->prepare("UPDATE special_assignments SET employee_id=?, title=?, nature=?, place=?, start_date=?, end_date=?, status=?, notes=? WHERE id=?")
                        ->execute([$eid, trim($_POST['title']), trim($_POST['nature'] ?? '') ?: null,
                                   trim($_POST['place'] ?? '') ?: null, $_POST['start_date'], $endDate,
                                   $status, trim($_POST['notes'] ?? '') ?: null, $id]);
                    log_activity('Edited special assignment', 'special_assignment', trim($_POST['title']));
                    flash('msg', 'Assignment updated.');
                }
            }
        }
    }
    header('Location: /special-assignments.php'); exit;
}

$emps = $pdo->prepare("SELECT id, service_no, full_name, rank FROM employees e WHERE e.active=1 AND $scopeW ORDER BY full_name");
$emps->execute($scopeP); $emps = $emps->fetchAll();

$rows = $pdo->prepare("SELECT sa.*, e.full_name, e.service_no, e.rank,
                              rg.name AS region_name, dv.name AS division_name,
                              st.name AS station_name, pt.name AS post_name,
                              u.full_name AS creater
                       FROM special_assignments sa
                       JOIN employees e ON e.id=sa.employee_id
                       LEFT JOIN regions rg ON rg.id=e.region_id
                       LEFT JOIN divisions dv ON dv.id=e.division_id
                       LEFT JOIN stations st ON st.id=e.station_id
                       LEFT JOIN posts pt ON pt.id=e.post_id
                       LEFT JOIN users u ON u.id=sa.created_by
                       WHERE $scopeW
                       ORDER BY CASE sa.status WHEN 'active' THEN 1 ELSE 2 END, sa.start_date DESC
                       LIMIT 300");
$rows->execute(array_merge($scopeP)); $rows = $rows->fetchAll();

$activeCount = count(array_filter($rows, fn($r) => $r['status'] === 'active'));

/* ── View mode ── */
$viewId  = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewing = null;
if ($viewId) {
    foreach ($rows as $r) { if ((int)$r['id'] === $viewId) { $viewing = $r; break; } }
}

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
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<?php if ($viewing): ?>
<div class="card" style="border-left:4px solid var(--primary);margin-bottom:16px">
  <div class="chr">
    <div>
      <h3 style="margin:0 0 3px"><?= e($viewing['rank'].' '.$viewing['full_name']) ?></h3>
      <div style="font-size:12px;color:var(--muted)"><?= e($viewing['service_no']) ?> · <?= sa_pill($viewing['status']) ?></div>
    </div>
    <a class="btn-icon bi-secondary bi-sm" href="/special-assignments.php" title="Close"><?= ICO_CANCEL ?></a>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:16px">
    <?php foreach ([
      'Assignment'=>$viewing['title'],'Nature'=>$viewing['nature']??'—','Place / Location'=>$viewing['place']??'—',
      'Start Date'=>date('j M Y',strtotime($viewing['start_date'])),'End Date'=>$viewing['end_date']?date('j M Y',strtotime($viewing['end_date'])):'Open-ended',
      'Station'=>$viewing['station_name']??'—','Division'=>$viewing['division_name']??'—','Region'=>$viewing['region_name']??'—',
      'Recorded by'=>$viewing['creater']??'—','Status'=>ucfirst($viewing['status']),
    ] as $lbl=>$val): ?>
    <div style="padding:10px 12px;background:var(--navy-50);border-radius:8px">
      <div style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:2px"><?= $lbl ?></div>
      <div style="font-weight:600;color:var(--navy-800);font-size:13px"><?= e($val) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($viewing['notes']): ?><div class="alert" style="background:var(--navy-50);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:13px"><strong>Notes:</strong> <?= e($viewing['notes']) ?></div><?php endif; ?>
  <?php if ($viewing['end_remarks']): ?><div class="alert" style="background:var(--navy-50);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:13px"><strong>End / Closing remarks:</strong> <?= e($viewing['end_remarks']) ?></div><?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="chr"><h3>Assignments</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>File No</th><th>Name</th><th>Rank</th><th>Assignment</th><th>Nature</th><th>Place</th>
        <th>Period</th><th>Status</th><th style="width:150px">Actions</th>
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
          <td style="white-space:nowrap">
            <a class="btn-icon bi-secondary bi-sm" href="/special-assignments.php?view=<?= (int)$r['id'] ?>" title="View details"><?= ICO_EYE ?></a>
            <details class="form-panel" style="position:relative;display:inline-block">
              <summary><button type="button" class="btn-icon bi-gold bi-sm" title="Edit assignment"><?= ICO_EDIT ?></button></summary>
              <div class="form-body form-pop" style="position:absolute;right:0;top:100%;min-width:380px;z-index:20;padding:14px;box-shadow:var(--shadow-md)">
                <form method="post" style="display:flex;flex-direction:column;gap:8px">
                  <input type="hidden" name="action" value="edit">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <div class="form-group"><label>Employee</label>
                    <select name="employee_id" required><option value="">— select —</option>
                      <?php foreach ($emps as $emp): ?><option value="<?= $emp['id'] ?>" <?= (int)$emp['id']===(int)$r['employee_id']?'selected':'' ?>><?= e($emp['service_no'].' — '.$emp['rank'].' '.$emp['full_name']) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-row">
                    <div class="form-group" style="flex:2"><label>Title / Assignment</label><input type="text" name="title" required value="<?= e($r['title']) ?>"></div>
                    <div class="form-group"><label>Nature</label>
                      <select name="nature">
                        <?php foreach (['VIP Protection','Election Duty','Operation','Investigation Task Team','Peacekeeping Mission','Guard of Honour','Protocol Duties','Other'] as $n): ?>
                        <option <?= ($r['nature']??'')===$n?'selected':'' ?>><?= $n ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="form-row">
                    <div class="form-group" style="flex:2"><label>Place / Location</label><input type="text" name="place" value="<?= e($r['place']??'') ?>"></div>
                    <div class="form-group"><label>Start Date</label><input type="date" name="start_date" required value="<?= e($r['start_date']) ?>"></div>
                  </div>
                  <div class="form-row">
                    <div class="form-group"><label>End Date <span class="muted">(blank = open)</span></label><input type="date" name="end_date" value="<?= e($r['end_date']??'') ?>"></div>
                    <div class="form-group"><label>Status</label>
                      <select name="status">
                        <option value="active" <?= $r['status']==='active'?'selected':'' ?>>Active</option>
                        <option value="completed" <?= $r['status']==='completed'?'selected':'' ?>>Completed</option>
                        <option value="cancelled" <?= $r['status']==='cancelled'?'selected':'' ?>>Cancelled</option>
                      </select>
                    </div>
                  </div>
                  <input type="text" name="notes" placeholder="Notes" value="<?= e($r['notes']??'') ?>">
                  <div class="action-bar" style="justify-content:flex-end">
                    <button class="btn-icon bi-gold bi-sm" type="submit" title="Save changes"><?= ICO_SAVE ?></button>
                  </div>
                </form>
              </div>
            </details>
            <?php if ($r['status']==='active'): ?>
            <details class="form-panel" style="position:relative;display:inline-block">
              <summary><button type="button" class="btn-icon bi-green bi-sm" title="End / cancel assignment"><?= ICO_OK ?></button></summary>
              <div class="form-body form-pop" style="position:absolute;right:0;top:100%;min-width:240px;z-index:20;padding:14px;box-shadow:var(--shadow-md)">
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
            <form method="post" style="display:inline-block" onsubmit="return confirm('Delete this special assignment?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn-icon bi-danger bi-sm" type="submit" title="Delete assignment"><?= ICO_TRASH ?></button>
            </form>
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