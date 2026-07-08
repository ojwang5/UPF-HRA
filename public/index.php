<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
$user = require_login();
$page = 'dashboard';
$page_title = 'Dashboard';
$pdo = db();

$date   = $_GET['date']   ?? date('Y-m-d');
$detail = $_GET['detail'] ?? ''; // status to drill into

$rows = hierarchy_summary($pdo, $date, $user);
$tot  = sum_totals($rows);

/* ── Detail drill-down: list personnel with this status for the date ── */
$detailPersonnel = [];
if ($detail && in_array($detail, ALL_STATUSES, true)) {
    [$scopeW, $scopeP] = scope_where($user, 'e');
    $dsStatus = ($detail === 'on_leave') ? 'leave' : $detail;
    $stmt = $pdo->prepare(
        "SELECT e.service_no, e.full_name, e.rank, e.gender,
                e.directorate, e.unit,
                rg.name AS region_name, dv.name AS division_name,
                st.name AS station_name, pt.name AS post_name,
                ds.notes
         FROM employees e
         LEFT JOIN regions   rg ON rg.id=e.region_id
         LEFT JOIN divisions dv ON dv.id=e.division_id
         LEFT JOIN stations  st ON st.id=e.station_id
         LEFT JOIN posts     pt ON pt.id=e.post_id
         JOIN daily_status ds ON ds.employee_id=e.id AND ds.date=?
         WHERE e.active=1 AND ds.status=? AND $scopeW
         ORDER BY rg.name, dv.name, pt.name, e.full_name"
    );
    $stmt->execute(array_merge([$date, $dsStatus], $scopeP));
    $detailPersonnel = $stmt->fetchAll();
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Dashboard</h1>
    <div class="desc"><?= e(date('l, j F Y', strtotime($date))) ?> &nbsp;·&nbsp; <?= e(role_label($user['role'])) ?> &nbsp;·&nbsp; <?= e(user_scope_label($user)) ?></div>
  </div>
  <div class="action-bar">
    <div class="panel-wrap">
      <button class="btn-icon bi-secondary" data-panel="panel-date" title="Change date">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      </button>
      <div class="panel-drop" id="panel-date" style="min-width:260px">
        <h4>Select Date</h4>
        <form method="get" style="display:flex;gap:8px;align-items:flex-end">
          <div class="form-group" style="flex:1"><label>Date</label>
            <input type="date" name="date" value="<?= e($date) ?>">
          </div>
          <button class="btn-icon bi-primary bi-lg" type="submit"><?= ICO_SAVE ?></button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- 9 Stat Cards (clickable) -->
<?php
// Each entry: [status_key, label, css_class, color, bg_color, svg_icon, hint_text]
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
  ['deserted',     'Deserted',     'stat-deserted',     '#be185d', '#fce7f3',
   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="23" y1="11" x2="17" y2="11"/></svg>',
   'Left post without permission'],
];
?>
<div class="grid grid-4 dash-stats">
<?php foreach ($cards as $card):
  [$key, $label, $cls, $color, $bg, $icon, $hint] = $card;
  $val = $tot[$key] ?? 0;
  $pct = $tot['total'] > 0 ? round($val / $tot['total'] * 100) : 0;
  $isActive = ($detail === $key);
  $detailUrl = $isActive
    ? "/?date=".urlencode($date) // toggle off
    : "/?date=".urlencode($date)."&detail=".urlencode($key)."#detail-panel";
?>
<a href="<?= $detailUrl ?>" class="stat-card-link" style="text-decoration:none">
  <div class="stat <?= $cls ?> <?= $isActive?'is-active':'' ?>"
       style="cursor:pointer;transition:box-shadow .15s,transform .15s;<?= $isActive?'box-shadow:0 0 0 3px '.$color.';transform:translateY(-2px)':'' ?>">
    <div style="flex:1;min-width:0">
      <div class="label"><?= $label ?></div>
      <div class="value"><?= $val ?></div>
      <div class="hint"><?= $hint ?></div>
      <div class="stat-bar">
        <div class="stat-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
      </div>
      <div class="stat-pct"><?= $pct ?>% &nbsp;<?= $isActive ? '<strong style="color:'.$color.'">▼ details</strong>' : '<span style="color:var(--muted)">click for details</span>' ?></div>
    </div>
    <div class="icon" style="color:<?= $color ?>;background:<?= $bg ?>"><?= $icon ?></div>
  </div>
</a>
<?php endforeach; ?>
</div>

<!-- Detail Drill-down Panel -->
<?php if ($detail && in_array($detail, ALL_STATUSES, true)): ?>
<div id="detail-panel" class="card" style="border-left:4px solid var(--primary);margin-bottom:8px;scroll-margin-top:80px">
  <div class="chr">
    <div>
      <h3><?= status_badge($detail) ?> &nbsp;<?= e(status_label($detail)) ?> — <?= e(date('j F Y', strtotime($date))) ?></h3>
      <div class="muted" style="font-size:12px;margin-top:2px"><?= count($detailPersonnel) ?> personnel</div>
    </div>
    <div style="display:flex;gap:6px;align-items:center">
      <a class="btn-icon bi-secondary bi-sm" href="/export-personnel.php?format=pdf&detail_status=<?= urlencode($detail) ?>&detail_date=<?= urlencode($date) ?>" target="_blank" title="Print this list"><?= ICO_PRINT ?></a>
      <a class="btn-icon bi-secondary bi-sm" href="/?date=<?= urlencode($date) ?>" title="Close"><?= ICO_CANCEL ?></a>
    </div>
  </div>
  <?php if ($detailPersonnel): ?>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>File No</th><th>Rank</th><th>Name</th><th>G</th>
        <th>Directorate</th><th>Unit</th>
        <th>Region</th><th>Station/Post</th><th>Notes</th>
      </tr></thead>
      <tbody>
        <?php foreach ($detailPersonnel as $dp): ?>
        <tr>
          <td style="font-family:monospace;font-size:12px"><?= e($dp['service_no']) ?></td>
          <td><?= e($dp['rank']) ?></td>
          <td><strong><?= e($dp['full_name']) ?></strong></td>
          <td><?= e($dp['gender']) ?></td>
          <td style="font-size:12px"><?= e($dp['directorate']??'—') ?></td>
          <td style="font-size:12px"><?= e($dp['unit']??'—') ?></td>
          <td class="muted" style="font-size:12px"><?= e($dp['region_name']??'—') ?></td>
          <td style="font-size:12px"><?= e(($dp['station_name']??'').' / '.($dp['post_name']??'')) ?></td>
          <td style="font-size:12px;color:var(--muted)"><?= e($dp['notes']??'') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <p class="muted" style="text-align:center;padding:20px">No personnel with status <strong><?= e(status_label($detail)) ?></strong> recorded on this date.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

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
    <div class="ds-val" style="color:#22c55e"><?= $tot['total'] ? round(($tot['present']/$tot['total'])*100) : 0 ?>%</div>
  </div>
  <div class="ds-cell">
    <div class="ds-label">Recorded</div>
    <div class="ds-val"><?= $tot['total'] - $tot['unrecorded'] ?></div>
  </div>
  <div class="ds-cell">
    <div class="ds-label">Unrecorded</div>
    <div class="ds-val" style="color:#f59e0b"><?= $tot['unrecorded'] ?></div>
  </div>
  <div class="ds-cell">
    <div class="ds-label">Flags (AWOL+Susp+Disc+Des)</div>
    <div class="ds-val" style="color:#ef4444"><?= $tot['awol'] + $tot['suspended'] + $tot['disciplinary'] + $tot['deserted'] ?></div>
  </div>
  <div class="ds-cell" style="border-right:none">
    <div class="ds-label">On Course</div>
    <div class="ds-val" style="color:#059669"><?= $tot['on_course'] ?></div>
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
        <th>Sick</th><th>Susp.</th><th>Disc.</th>
        <th>On Duty</th><th>On Course</th><th>Deserted</th><th>Unrecorded</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['unit_name']) ?></strong></td>
          <td><?= $r['total'] ?></td>
          <td><span style="color:#22c55e;font-weight:600"><?= $r['present'] ?></span></td>
          <td><?= $r['awol'] ? '<span style="color:#ef4444;font-weight:600">'.$r['awol'].'</span>' : '—' ?></td>
          <td><?= $r['on_leave'] ?: '—' ?></td>
          <td><?= $r['sick'] ?: '—' ?></td>
          <td><?= $r['suspended'] ? '<span style="color:#7c3aed;font-weight:600">'.$r['suspended'].'</span>' : '—' ?></td>
          <td><?= $r['disciplinary'] ? '<span style="color:#dc2626;font-weight:600">'.$r['disciplinary'].'</span>' : '—' ?></td>
          <td><?= $r['on_duty'] ?: '—' ?></td>
          <td><?= $r['on_course'] ?: '—' ?></td>
          <td><?= $r['deserted'] ? '<span style="color:#be185d;font-weight:600">'.$r['deserted'].'</span>' : '—' ?></td>
          <td><?= $r['unrecorded'] ? '<span style="color:#94a3b8">'.$r['unrecorded'].'</span>' : '<span style="color:#22c55e">✓</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<style>
/* Dashboard stat cards */
.dash-stats{margin-bottom:12px}
.stat{min-height:110px;align-items:flex-start;transition:box-shadow .15s,transform .15s}
.stat:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
.stat-card-link{display:block;text-decoration:none;color:inherit}
.stat-bar{height:5px;background:rgba(0,0,0,.08);border-radius:3px;margin-top:8px;overflow:hidden}
.stat-bar-fill{height:100%;border-radius:3px;transition:width .4s}
.stat-pct{font-size:10px;color:var(--muted);margin-top:4px}
.stat .hint{font-size:10px;color:var(--muted);margin-top:2px;line-height:1.3}
.stat-deserted{background:#fff0f8;border-color:#fce7f3}
/* Summary strip */
.dash-summary{
  display:flex;flex-wrap:wrap;background:var(--card);
  border:1px solid var(--border);border-radius:10px;
  margin-bottom:16px;overflow:hidden;box-shadow:var(--shadow-sm);
}
.ds-cell{flex:1;min-width:110px;padding:12px 16px;border-right:1px solid var(--border);text-align:center}
.ds-label{font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);font-weight:600;margin-bottom:4px}
.ds-val{font-size:22px;font-weight:700;color:var(--navy-800);line-height:1}
@media(max-width:768px){
  .ds-cell{min-width:calc(50% - 1px);flex-basis:50%}
  .ds-cell:nth-child(2n){border-right:none}
}
</style>

<script>
setTimeout(()=>{ if(document.visibilityState==='visible') location.reload(); }, 60000);
// Scroll to detail panel if loaded via anchor
<?php if ($detail): ?>
window.addEventListener('load', function(){
  var el = document.getElementById('detail-panel');
  if(el) el.scrollIntoView({behavior:'smooth',block:'start'});
});
<?php endif; ?>
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
