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

if (valid_date($date)) { run_auto_status($pdo, $date, $user); }
run_leave_countdown_notifications($pdo);

$rows = hierarchy_summary($pdo, $date, $user);
$tot  = sum_totals($rows);

/* ── Detail drill-down: list personnel with this status for the date ── */
$detailPersonnel = [];
if ($detail && (in_array($detail, ALL_STATUSES, true) || $detail === 'on_leave')) {
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
<?php if ($detail && (in_array($detail, ALL_STATUSES, true) || $detail === 'on_leave')): ?>
<div id="detail-panel" class="card" style="border-left:4px solid var(--primary);margin-bottom:8px;scroll-margin-top:80px">
  <div class="chr">
    <div>
      <h3><?= status_badge($detail) ?> &nbsp;<?= e(status_label($detail)) ?> — <?= e(date('j F Y', strtotime($date))) ?></h3>
      <div class="muted" style="font-size:12px;margin-top:2px"><?= count($detailPersonnel) ?> personnel</div>
    </div>
    <div style="display:flex;gap:6px;align-items:center">
      <?php $exportStatus = ($detail === 'on_leave') ? 'leave' : $detail; ?>
      <a class="btn-icon bi-secondary bi-sm" href="/export-personnel.php?format=csv&status=<?= urlencode($exportStatus) ?>&date=<?= urlencode($date) ?>" target="_blank" title="Export this list (CSV)"><?= ICO_DL ?></a>
      <a class="btn-icon bi-secondary bi-sm" href="/export-personnel.php?format=excel&status=<?= urlencode($exportStatus) ?>&date=<?= urlencode($date) ?>" target="_blank" title="Export this list (Excel)"><?= ICO_DL ?></a>
      <a class="btn-icon bi-secondary bi-sm" href="/export-personnel.php?format=pdf&status=<?= urlencode($exportStatus) ?>&date=<?= urlencode($date) ?>" target="_blank" title="Print this list"><?= ICO_PRINT ?></a>
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

<!-- Status Distribution Charts -->
<?php
$chartData = [
    ['present',      'Present',      '#22c55e'],
    ['on_duty',      'On Duty',      '#0891b2'],
    ['on_leave',     'On Leave',     '#3b82f6'],
    ['sick',         'Sick',         '#f59e0b'],
    ['on_course',    'On Course',    '#059669'],
    ['awol',         'AWOL',         '#ef4444'],
    ['suspended',    'Suspended',    '#7c3aed'],
    ['disciplinary', 'Disciplinary', '#dc2626'],
    ['deserted',     'Deserted',     '#be185d'],
    ['unrecorded',   'Unrecorded',   '#94a3b8'],
];
$cht = [];
$chartTotal = 0;
foreach ($chartData as [$ck, $cl, $cc]) { $v = $tot[$ck] ?? 0; $chartTotal += $v; }
$pieSegs = [];
$acc = 0; // cumulative percent for SVG arcs
$R = 42; $C = 2 * M_PI * $R; $cx = 60; $cy = 52;
foreach ($chartData as [$ck, $cl, $cc]) {
    $v = $tot[$ck] ?? 0;
    $pct = $chartTotal > 0 ? ($v / $chartTotal * 100) : 0;
    $pctShow = $chartTotal > 0 ? round($pct) : 0;
    // Bar width relative to largest bar
    $barW = $v;
    $cht[] = ['label'=>$cl,'val'=>$v,'pct'=>$pctShow,'color'=>$cc,'rawPct'=>$pct];
    if ($chartTotal > 0 && $v > 0) {
        $segLen = $pct / 100 * $C;
        $dash = max($segLen - 0.8, 0.3);
        $rot = $acc / 100 * 360;
        $pieSegs[] = "<circle r=\"$R\" cx=\"$cx\" cy=\"$cy\" fill=\"none\" stroke=\"$cc\" stroke-width=\"13\" stroke-dasharray=\"$dash ".($C-$dash)."\" stroke-dashoffset=\"".(-$rot/360*$C- ($C*0.25))."\" />";
        $acc += $pct;
    }
}
?>
<div class="card" style="margin-top:4px">
  <div class="chr">
    <h3>Status Distribution — <?= e(date('j F Y', strtotime($date))) ?></h3>
    <div class="action-bar">
      <a class="btn-icon bi-secondary bi-sm" href="/export-charts.php?format=csv&date=<?= e($date) ?>" title="Export CSV"><?= ICO_DL ?></a>
      <a class="btn-icon bi-secondary bi-sm" href="/export-charts.php?format=print&date=<?= e($date) ?>" target="_blank" title="Print / PDF"><?= ICO_PRINT ?></a>
    </div>
  </div>
  <div class="charts-grid">
    <!-- Bar chart -->
    <div class="chart-box bar-box">
      <div class="chart-title"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg> Distribution by Status</div>
      <div class="hbar-list">
        <?php $maxBar = $chartTotal > 0 ? $chartTotal : 1; foreach ($cht as $b):
          $w = $b['val'] > 0 ? max(2, round($b['val'] / $maxBar * 100)) : 0; ?>
        <div class="hbar-row">
          <div class="hbar-label"><?= e($b['label']) ?> <span class="hbar-num"><?= $b['val'] ?></span></div>
          <div class="hbar-track"><div class="hbar-fill" style="width:<?= $w ?>%;background:<?= $b['color'] ?>"></div></div>
          <div class="hbar-pct"><?= $b['pct'] ?>%</div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <!-- Pie chart -->
    <div class="chart-box pie-box">
      <div class="chart-title"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg> Percentage Share</div>
      <div class="pie-wrap">
        <svg viewBox="0 0 120 120" width="150" height="150" <?= $chartTotal>0 ? '' : 'data-empty="1"' ?>>
          <circle r="<?= $R ?>" cx="<?= $cx ?>" cy="<?= $cy ?>" fill="none" stroke="rgba(0,0,0,.08)" stroke-width="13" />
          <?php if ($chartTotal > 0): foreach ($pieSegs as $ps) echo $ps; ?>
          <text x="60" y="56" text-anchor="middle" style="font-size:11px;font-weight:700;fill:currentColor"><?= $chartTotal ?></text>
          <text x="60" y="68" text-anchor="middle" style="font-size:5px;fill:var(--muted)">TOTAL</text>
          <?php else: ?>
          <text x="60" y="56" text-anchor="middle" style="font-size:9px;fill:var(--muted)">No data</text>
          <?php endif; ?>
        </svg>
        <div class="pie-legend">
          <?php foreach ($cht as $b): if ($b['val']<=0) continue; ?>
          <div class="lg-item"><span class="lg-dot" style="background:<?= $b['color'] ?>"></span><?= e($b['label']) ?><span class="lg-val"><?= $b['val'] ?> · <?= $b['pct'] ?>%</span></div>
          <?php endforeach; ?>
          <?php if ($chartTotal<=0): ?><div class="muted" style="padding:8px 0">No status data for this date.</div><?php endif; ?>
        </div>
      </div>
    </div>
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

<!-- Regional / unit strength comparison graph -->
<?php if (count($rows) > 1):
  $maxTotal = 0; $maxPres = 0;
  foreach ($rows as $r) { $maxTotal = max($maxTotal, (int)$r['total']); $maxPres = max($maxPres, (int)$r['present']); }
  $cmpTitle = match($user['role']) {
    'superadmin'         => 'Regional Strength Comparison',
    'regional_commander' => 'Division Strength Comparison — '.e($user['region_name']??''),
    'division_commander' => 'Station Strength Comparison — '.e($user['division_name']??''),
    default              => 'Post Strength Comparison — '.e($user['station_name']??''),
  };
?>
<div class="card" style="margin-top:4px">
  <div class="chr">
    <h3><?= $cmpTitle ?> — <?= e(date('j F Y', strtotime($date))) ?></h3>
    <div class="action-bar">
      <a class="btn-icon bi-secondary bi-sm" href="/export-charts.php?format=csv&date=<?= e($date) ?>" title="Export CSV"><?= ICO_DL ?></a>
      <a class="btn-icon bi-secondary bi-sm" href="/export-charts.php?format=print&date=<?= e($date) ?>" target="_blank" title="Print / PDF"><?= ICO_PRINT ?></a>
    </div>
  </div>
  <div class="cmp-legend">
    <span><i style="background:#22c55e"></i> Present</span>
    <span><i style="background:var(--navy-500, #334155)"></i> Total strength</span>
    <span><i style="background:var(--gold)"></i> Attendance rate</span>
  </div>
  <div class="table-wrap">
    <div class="cmp-list">
      <?php foreach ($rows as $r): $att = (int)$r['total'] ? round((int)$r['present'] / (int)$r['total'] * 100) : 0; ?>
      <div class="cmp-item">
        <div class="cmp-head">
          <div class="cmp-name"><?= e($r['unit_name']) ?></div>
          <div class="cmp-nums"><?= (int)$r['present'] ?> present · <?= (int)$r['total'] ?> total · <strong><?= $att ?>%</strong></div>
        </div>
        <div class="cmp-bars">
          <div class="cmp-row">
            <span class="cmp-row-label">Present</span>
            <div class="cmp-track"><div class="cmp-fill" style="width:<?= $maxPres ? round((int)$r['present'] / $maxPres * 100) : 0 ?>%;background:#22c55e"></div></div>
            <span class="cmp-row-val"><?= (int)$r['present'] ?></span>
          </div>
          <div class="cmp-row">
            <span class="cmp-row-label">Total</span>
            <div class="cmp-track"><div class="cmp-fill" style="width:<?= $maxTotal ? round((int)$r['total'] / $maxTotal * 100) : 0 ?>%;background:var(--navy-500, #334155)"></div></div>
            <span class="cmp-row-val"><?= (int)$r['total'] ?></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
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
/* Status distribution charts */
.charts-grid{display:grid;grid-template-columns:1.4fr 1fr;gap:24px;align-items:stretch}
@media(max-width:900px){.charts-grid{grid-template-columns:1fr}}
.chart-title{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:var(--navy-800);text-transform:uppercase;letter-spacing:.6px;margin-bottom:14px}
.chart-title .ico{width:15px;height:15px;color:var(--gold)}
.bar-box{padding-right:20px;border-right:1px solid var(--border)}
@media(max-width:900px){.bar-box{padding-right:0;border-right:none;border-bottom:1px solid var(--border);padding-bottom:18px}}
.hbar-list{display:flex;flex-direction:column;gap:9px}
.hbar-row{display:flex;align-items:center;gap:10px}
.hbar-label{width:92px;flex-shrink:0;font-size:12px;color:var(--navy-700);display:flex;align-items:center;justify-content:space-between;gap:6px}
.hbar-num{font-weight:700;font-size:12px;color:var(--navy-800)}
.hbar-track{flex:1;height:12px;background:rgba(0,0,0,.07);border-radius:6px;overflow:hidden}
.hbar-fill{height:100%;border-radius:6px;min-width:0;transition:width .5s}
.hbar-pct{width:38px;flex-shrink:0;text-align:right;font-size:11px;font-weight:600;color:var(--muted)}
.pie-wrap{display:flex;align-items:center;gap:22px;justify-content:center;flex-wrap:wrap}
.pie-legend{display:flex;flex-direction:column;gap:6px;min-width:160px}
.lg-item{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--navy-700)}
.lg-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
.lg-val{margin-left:auto;color:var(--muted);font-size:11px}
/* Regional strength comparison */
.cmp-legend{display:flex;gap:18px;flex-wrap:wrap;font-size:11px;color:var(--navy-700);margin:2px 2px 12px}
.cmp-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px}
.cmp-list{display:flex;flex-direction:column;gap:14px}
.cmp-item{border:1px solid var(--border);border-radius:10px;padding:10px 14px;background:var(--navy-50)}
.cmp-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.cmp-name{font-weight:700;color:var(--navy-800);font-size:13px}
.cmp-nums{font-size:11px;color:var(--muted)}
.cmp-bars{display:flex;flex-direction:column;gap:6px}
.cmp-row{display:flex;align-items:center;gap:10px}
.cmp-row-label{width:62px;flex-shrink:0;font-size:11px;color:var(--muted);text-align:right}
.cmp-track{flex:1;height:11px;background:rgba(0,0,0,.07);border-radius:6px;overflow:hidden}
.cmp-fill{height:100%;border-radius:6px;min-width:0;transition:width .5s}
.cmp-row-val{width:34px;flex-shrink:0;font-weight:700;font-size:12px;color:var(--navy-800);text-align:right}
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
