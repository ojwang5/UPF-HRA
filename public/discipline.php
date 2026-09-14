<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_min_rank('post_commander');
$page = 'discipline';
$page_title = 'Discipline Management';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    if ($action === 'create') {
        $eid = (int)$_POST['employee_id'];
        $emp = $pdo->prepare("SELECT id FROM employees e WHERE e.id=? AND e.active=1 AND $scopeW");
        $emp->execute(array_merge([$eid], $scopeP));
        $type = ($_POST['case_type'] ?? '') === 'disciplinary' ? 'disciplinary' : 'suspension';
        if ($emp->fetch() && valid_date($_POST['start_date'] ?? '')) {
            $endDate = ($_POST['end_date'] ?? '') !== '' ? $_POST['end_date'] : null;
            $pdo->prepare("INSERT INTO discipline_cases (employee_id,case_type,case_ref,offence,start_date,end_date,status,notes,created_by)
                           VALUES (?,?,?,?,?,?, 'open', ?, ?)")
                ->execute([$eid, $type, trim($_POST['case_ref'] ?? '') ?: null,
                           trim($_POST['offence']), $_POST['start_date'], $endDate,
                           trim($_POST['notes'] ?? '') ?: null, $user['id']]);
            log_activity('Opened '.ucfirst($type).' case', 'discipline_case', trim($_POST['case_ref']) ?: '', 0, 'Employee #'.$eid);
            flash('msg', ucfirst($type).' case opened — daily status will show "'.($type==='disciplinary'?'Disciplinary':'Suspended').'" while open.');
        }
    } elseif ($id && in_array($action, ['close','reinstate'], true)) {
        $chk = $pdo->prepare("SELECT dc.id FROM discipline_cases dc JOIN employees e ON e.id=dc.employee_id
                              WHERE dc.id=? AND dc.status='open' AND $scopeW");
        $chk->execute(array_merge([$id], $scopeP));
        if ($chk->fetch()) {
            if ($action === 'close') {
                $pdo->prepare("UPDATE discipline_cases SET status='closed', outcome=?, closed_at=datetime('now','localtime') WHERE id=?")
                    ->execute([trim($_POST['outcome'] ?? '') ?: null, $id]);
                flash('msg', 'Case closed.');
            } else {
                $pdo->prepare("UPDATE discipline_cases SET status='reinstated', outcome=?, closed_at=datetime('now','localtime') WHERE id=?")
                    ->execute([trim($_POST['outcome'] ?? '') ?: 'Reinstated to duty', $id]);
                flash('msg', 'Officer reinstated.');
            }
        }
    }
    header('Location: /discipline.php'); exit;
}

$emps = $pdo->prepare("SELECT id, service_no, full_name, rank FROM employees e WHERE e.active=1 AND $scopeW ORDER BY full_name");
$emps->execute($scopeP); $emps = $emps->fetchAll();

$typeFilter = ($_GET['type'] ?? '') === 'suspension' ? 'suspension' : ((($_GET['type'] ?? '') === 'disciplinary') ? 'disciplinary' : '');
$addW = $typeFilter ? " AND dc.case_type='$typeFilter'" : '';

$rows = $pdo->prepare("SELECT dc.*, e.full_name, e.service_no, e.rank,
                              rg.name AS region_name, dv.name AS division_name,
                              st.name AS station_name, pt.name AS post_name
                       FROM discipline_cases dc
                       JOIN employees e ON e.id=dc.employee_id
                       LEFT JOIN regions rg ON rg.id=e.region_id
                       LEFT JOIN divisions dv ON dv.id=e.division_id
                       LEFT JOIN stations st ON st.id=e.station_id
                       LEFT JOIN posts pt ON pt.id=e.post_id
                       WHERE $scopeW$addW
                       ORDER BY CASE dc.status WHEN 'open' THEN 1 ELSE 2 END, dc.start_date DESC
                       LIMIT 300");
$rows->execute(array_merge($scopeP)); $rows = $rows->fetchAll();

$today = date('Y-m-d');
$openSusp = count(array_filter($rows, fn($r) => $r['status']==='open' && $r['case_type']==='suspension'));
$openDisc = count(array_filter($rows, fn($r) => $r['status']==='open' && $r['case_type']==='disciplinary'));

function dc_pill(string $s): string {
    [$l, $c] = match($s) {
        'open'       => ['Open', 'badge-disciplinary'],
        'closed'     => ['Closed', 'badge-admin'],
        default      => ['Reinstated', 'badge-present'],
    };
    return '<span class="badge '.$c.'">'.$l.'</span>';
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Suspension &amp; Disciplinary Cases</h1>
    <div class="desc"><?= $openSusp ?> open suspensions · <?= $openDisc ?> open disciplinary cases — auto-sets daily status while open</div></div>
  <div class="action-bar">
    <!-- Type filter -->
    <details class="form-panel">
      <summary><button type="button" class="btn-icon bi-secondary" title="Filter by type"><?= ICO_SEARCH ?></button></summary>
      <div class="form-body" style="padding:12px 16px">
        <form method="get" style="display:flex;gap:8px;align-items:flex-end">
          <div class="form-group"><label>Case Type</label>
            <select name="type">
              <option value="">All</option>
              <option value="suspension" <?= $typeFilter==='suspension'?'selected':'' ?>>Suspension</option>
              <option value="disciplinary" <?= $typeFilter==='disciplinary'?'selected':'' ?>>Disciplinary</option>
            </select>
          </div>
          <button class="btn-icon bi-primary" type="submit" title="Apply"><?= ICO_SAVE ?></button>
        </form>
      </div>
    </details>
    <div class="panel-wrap">
      <button class="btn-icon bi-primary" data-panel="panel-new" title="Open case"><?= ICO_PLUS ?></button>
      <div class="panel-drop" id="panel-new" style="min-width:420px">
        <h4>Open New Case</h4>
        <form method="post" style="display:flex;flex-direction:column;gap:10px">
          <input type="hidden" name="action" value="create">
          <div class="form-group"><label>Employee</label>
            <select name="employee_id" required><option value="">— select —</option>
              <?php foreach ($emps as $emp): ?><option value="<?= $emp['id'] ?>"><?= e($emp['service_no'].' — '.$emp['rank'].' '.$emp['full_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Case Type</label>
              <select name="case_type"><option value="suspension">Suspension</option><option value="disciplinary">Disciplinary Action</option></select>
            </div>
            <div class="form-group" style="flex:2"><label>Case Reference</label><input type="text" name="case_ref" placeholder="e.g. UPF/DISC/2026/041"></div>
          </div>
          <div class="form-group"><label>Offence / Allegation</label>
            <textarea name="offence" required rows="2" placeholder="Describe the offence or allegation…"></textarea>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Start Date</label><input type="date" name="start_date" required value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label>End Date <span class="muted">(blank = until closed)</span></label><input type="date" name="end_date"></div>
          </div>
          <div class="form-group"><label>Notes</label><input type="text" name="notes"></div>
          <div class="action-bar" style="justify-content:flex-end">
            <button class="btn-icon bi-gold bi-lg" type="submit" title="Open case"><?= ICO_SAVE ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

<div class="card">
  <div class="chr"><h3>Cases</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>File No</th><th>Name</th><th>Rank</th><th>Type</th><th>Ref</th><th>Offence / Allegation</th>
        <th>Period</th><th>Status</th><th style="width:90px"></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r):
          $ongoing = $r['status']==='open' && $today >= $r['start_date'] && ($r['end_date'] === null || $today <= $r['end_date']);
        ?>
        <tr class="<?= $ongoing ? 'row-'.e($r['case_type']==='suspension'?'suspended':'disciplinary') : '' ?>">
          <td style="font-family:monospace;font-size:12px"><?= e($r['service_no']) ?></td>
          <td><strong><?= e($r['full_name']) ?></strong></td>
          <td><?= e($r['rank']) ?></td>
          <td><span class="badge <?= $r['case_type']==='suspension' ? 'badge-suspended' : 'badge-disciplinary' ?>"><?= $r['case_type']==='suspension' ? 'Suspension' : 'Disciplinary' ?></span></td>
          <td style="font-family:monospace;font-size:11px"><?= e($r['case_ref'] ?? '—') ?></td>
          <td style="max-width:260px;font-size:12px"><?= e($r['offence']) ?><?= $r['notes'] ? '<br><span class="muted">'.e($r['notes']).'</span>' : '' ?>
              <?php if ($r['outcome']): ?><br><span style="color:#166534">Outcome: <?= e($r['outcome']) ?></span><?php endif; ?></td>
          <td style="white-space:nowrap;font-size:12px"><?= e(date('j M y', strtotime($r['start_date']))) ?>
              <?= $r['end_date'] ? '<br><span class="muted">→ '.e(date('j M y', strtotime($r['end_date']))).'</span>' : '<br><span class="muted">→ until closed</span>' ?></td>
          <td><?= dc_pill($r['status']) ?><?= $ongoing ? '<div class="stat-pct" style="color:#dc2626;font-weight:700">● active now</div>' : '' ?></td>
          <td>
            <?php if ($r['status']==='open'): ?>
            <details class="form-panel" style="position:relative">
              <summary><button type="button" class="btn-icon bi-secondary bi-sm" title="Close / reinstate"><?= ICO_EDIT ?></button></summary>
              <div class="form-body" style="position:absolute;right:0;top:100%;min-width:250px;z-index:20;padding:14px;box-shadow:var(--shadow-md)">
                <form method="post" style="display:flex;flex-direction:column;gap:8px">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <input type="text" name="outcome" placeholder="Outcome (optional)">
                  <div class="action-bar">
                    <button class="btn-icon bi-green" name="action" value="reinstate" title="Reinstate officer"><?= ICO_OK ?></button>
                    <button class="btn-icon bi-danger" name="action" value="close" title="Close case"><?= ICO_REJECT ?></button>
                  </div>
                </form>
              </div>
            </details>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">No cases recorded.</td></tr><?php endif; ?>
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
