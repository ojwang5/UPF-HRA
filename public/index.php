<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
$user = require_login();
$page = 'dashboard';
$page_title = 'Dashboard';
$pdo = db();

$date = $_GET['date'] ?? date('Y-m-d');
$rows = hierarchy_summary($pdo, $date, $user);
$tot  = sum_totals($rows);

// Total personnel count
$totalPersonnel = (int)$pdo->query("SELECT COUNT(*) FROM employees e WHERE e.active=1")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Dashboard</h1>
    <div class="desc"><?= e(date('l, j F Y', strtotime($date))) ?> &nbsp;·&nbsp; <?= e(role_label($user['role'])) ?> &nbsp;·&nbsp; <?= e(user_scope_label($user)) ?></div>
  </div>
  <div class="action-bar">
    <details class="form-panel">
      <summary><button type="button" class="btn-icon bi-secondary" title="Change date">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      </button></summary>
      <div class="form-body" style="padding:12px 16px">
        <form method="get" style="display:flex;gap:8px;align-items:flex-end">
          <div class="form-group"><label>Date</label><input type="date" name="date" value="<?= e($date) ?>"></div>
          <button class="btn-icon bi-primary" type="submit" title="Go"><?= ICO_SAVE ?></button>
        </form>
      </div>
    </details>
  </div>
</div>

<!-- 8 Stat Cards -->
<div class="grid grid-4 dash-stats">
<?php
$cards = [
  ['present',      'Present',      'stat-present',      '#22c55e', '#dcfce7',
   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
   'On duty and accounted for'],
  ['awol',         'AWOL',         'stat-awol',         '#ef4444', '#fee2e2',
   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
   'Absent without official leave'],
  ['on_leave',     'On Leave',     'stat-leave',        '#3b82f6', '#dbeafe',
   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
   'Approved leave absence'],
  ['sick',         'Sick',         'stat-sick',         '#f59e0b', '#fef3c7',
   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
   'Medical / sick absence'],
  ['suspended',    'Suspended',    'stat-suspended',    '#7c3aed', '#ede9fe',
   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>',
   'Under suspension order'],
  ['disciplinary', 'Disciplinary', 'stat-disciplinary', '#dc2626', '#fee2e2',
   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
   'Facing disciplinary action'],
  ['on_duty',      'On Duty',      'stat-on_duty',      '#0891b2', '#cffafe',
   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
   'Deployed / operational duty'],
  ['on_course',    'On Course',    'stat-on_course',    '#059669', '#d1fae5',
   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
   'Attending training / course'],
];
foreach ($cards as [$key, $label, $cls, $color, $bg, $icon, $hint]):
  $val = $tot[$key] ?? 0;
  $pct = $tot['total'] > 0 ? round($val / $tot['total'] * 100) : 0;
?>
<div class="stat <?= $cls ?>">
  <div style="flex:1;min-width:0">
    <div class="label"><?= $label ?></div>
    <div class="value"><?= $val ?></div>
    <div class="hint"><?= $hint ?></div>
    <div class="stat-bar">
      <div class="stat-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
    </div>
    <div class="stat-pct"><?= $pct ?>% of strength</div>
  </div>
  <div class="icon" style="color:<?= $color ?>;background:<?= $bg ?>"><?= $icon ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- Summary strip -->
<div class="dash-summary">
  <div class="ds-cell">
    <div class="ds-label">Total Strength</div>
    <div class="ds-val"><?= $tot['total'] ?></div>
  </div>
  <div class="ds-cell">
    <div class="ds-label">Male</div>
    <div class="ds-val"><?= $tot['male'] ?></div>
  </div>
  <div class="ds-cell">
    <div class="ds-label">Female</div>
    <div class="ds-val"><?= $tot['female'] ?></div>
  </div>
  <div class="ds-cell">
    <div class="ds-label">Attendance Rate</div>
    <div class="ds-val" style="color:var(--green)"><?= $tot['total'] ? round(($tot['present']/$tot['total'])*100) : 0 ?>%</div>
  </div>
  <div class="ds-cell">
    <div class="ds-label">Recorded Today</div>
    <div class="ds-val"><?= $tot['total'] - $tot['unrecorded'] ?></div>
  </div>
  <div class="ds-cell">
    <div class="ds-label">Unrecorded</div>
    <div class="ds-val" style="color:var(--amber)"><?= $tot['unrecorded'] ?></div>
  </div>
  <div class="ds-cell">
    <div class="ds-label">Flags</div>
    <div class="ds-val" style="color:var(--red)"><?= $tot['awol'] + $tot['suspended'] + $tot['disciplinary'] ?></div>
  </div>
  <div class="ds-cell" style="border-right:none">
    <div class="ds-label">On Course</div>
    <div class="ds-val" style="color:var(--green)"><?= $tot['on_course'] ?></div>
  </div>
</div>

<!-- Per-unit breakdown -->
<?php if (!empty($rows)): ?>
<div class="card" style="margin-top:4px">
  <div class="chr">
    <h3><?= match($user['role']) {
      'superadmin'          => 'Regional Breakdown',
      'regional_commander'  => 'Division Breakdown — '.e($user['region_name']??''),
      'division_commander'  => 'Station Breakdown — '.e($user['division_name']??''),
      default               => 'Post Breakdown — '.e($user['station_name']??user_scope_label($user)),
    } ?></h3>
    <div class="action-bar">
      <a class="btn-icon bi-secondary bi-sm" href="/reports.php?date=<?= e($date) ?>" title="Generate report"><?= ICO_GEN ?></a>
      <a class="btn-icon bi-secondary bi-sm" href="/daily-status.php?date=<?= e($date) ?>" title="Record daily status"><?= ICO_EDIT ?></a>
    </div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th><?= ucfirst($rows[0]['unit_level'] ?? 'Unit') ?></th>
        <th>Total</th><th>Present</th><th>AWOL</th><th>Leave</th>
        <th>Sick</th><th>Suspended</th><th>Disciplinary</th>
        <th>On Duty</th><th>On Course</th><th>Unrecorded</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['unit_name']) ?></strong></td>
          <td><?= $r['total'] ?></td>
          <td><span style="color:var(--green);font-weight:600"><?= $r['present'] ?></span></td>
          <td><?= $r['awol'] ? '<span style="color:var(--red);font-weight:600">'.$r['awol'].'</span>' : '—' ?></td>
          <td><?= $r['on_leave'] ?: '—' ?></td>
          <td><?= $r['sick'] ?: '—' ?></td>
          <td><?= $r['suspended'] ? '<span style="color:#7c3aed;font-weight:600">'.$r['suspended'].'</span>' : '—' ?></td>
          <td><?= $r['disciplinary'] ? '<span style="color:var(--red);font-weight:600">'.$r['disciplinary'].'</span>' : '—' ?></td>
          <td><?= $r['on_duty'] ?: '—' ?></td>
          <td><?= $r['on_course'] ?: '—' ?></td>
          <td><?= $r['unrecorded'] ? '<span style="color:var(--muted)">'.$r['unrecorded'].'</span>' : '<span style="color:var(--green)">✓</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<style>
/* Dashboard stat card enhancements */
.dash-stats{margin-bottom:12px}
.stat{min-height:110px;align-items:flex-start}
.stat-bar{height:5px;background:rgba(0,0,0,.08);border-radius:3px;margin-top:8px;overflow:hidden}
.stat-bar-fill{height:100%;border-radius:3px;transition:width .4s}
.stat-pct{font-size:10px;color:var(--muted);margin-top:4px}
.stat .hint{font-size:10px;color:var(--muted);margin-top:2px;line-height:1.3}
/* Summary strip */
.dash-summary{
  display:flex;flex-wrap:wrap;background:var(--card);
  border:1px solid var(--border);border-radius:10px;
  margin-bottom:16px;overflow:hidden;box-shadow:var(--shadow-sm);
}
.ds-cell{
  flex:1;min-width:100px;padding:12px 16px;
  border-right:1px solid var(--border);text-align:center;
}
.ds-label{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);font-weight:600;margin-bottom:4px}
.ds-val{font-size:22px;font-weight:700;color:var(--navy-800);line-height:1}
@media(max-width:768px){
  .ds-cell{min-width:calc(50% - 1px);flex-basis:50%}
  .ds-cell:nth-child(2n){border-right:none}
}
@media(max-width:480px){
  .ds-cell{min-width:calc(50% - 1px);flex-basis:50%}
}
</style>

<script>
document.querySelectorAll('details.form-panel').forEach(d=>{
  d.addEventListener('toggle',()=>{ if(d.open) document.querySelectorAll('details.form-panel').forEach(o=>{ if(o!==d) o.open=false; }); });
});
setTimeout(()=>{ if(document.visibilityState==='visible') location.reload(); }, 60000);
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
