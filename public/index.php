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
         ORDER BY rg.name, dv.name, pt.name, " . personnel_order_sql('e')
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
    <button class="btn-icon btn-labeled bi-secondary" id="dashboard-slideshow-toggle" type="button" aria-pressed="false">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
      <span>Start slideshow</span>
    </button>
    <span id="dashboard-slideshow-status" class="dashboard-slideshow-status" aria-live="polite">Slideshow off</span>
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

<div class="dashboard-presentation" id="dashboard-presentation">
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
  ['disciplinary', 'Under Disciplinary', 'stat-disciplinary', '#dc2626', '#fee2e2',
   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
   'Facing disciplinary action · counted in working strength'],
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
<section class="dashboard-slide" data-slide-title="Personnel status overview" aria-label="Personnel status overview">
<div class="slide-heading">
  <h2>Personnel Status Overview</h2>
  <span><?= e(date('j F Y', strtotime($date))) ?></span>
</div>
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
</section>

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
        <th>File/Force No</th><th>Rank</th><th>Full Name</th><th>G</th>
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
    <div class="ds-label">Working Strength</div>
    <div class="ds-val" style="color:#0891b2"><?= working_strength($tot) ?></div>
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
  <div class="ds-cell">
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
<section class="dashboard-slide" data-slide-title="Status distribution" aria-label="Status distribution chart">
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
</section>

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
        <th>Sick</th><th>Susp.</th><th>Under Disc.</th><th>Working</th>
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
          <td><span style="color:#0891b2;font-weight:600"><?= working_strength($r) ?></span></td>
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

<?php if (count($rows) > 1):
  $stackData = $chartData;
  $stackData[] = ['special_assignment', 'Special Assignment', '#0ea5e9'];
  $stackData[] = ['undeployed', 'Undeployed', '#64748b'];
  $ranked = [];
  foreach ($rows as $r) {
      $total = (int)$r['total'];
      $stackTotal = 0;
      foreach ($stackData as [$ck]) { $stackTotal += (int)($r[$ck] ?? 0); }
      if ($total <= 0 || $stackTotal <= 0 || trim((string)$r['unit_name']) === '') continue;
      $r['_stack'] = $stackTotal;
      $r['_att'] = (int)$r['present'] / $total * 100;
      $ranked[] = $r;
  }
  usort($ranked, function (array $a, array $b): int {
      if ($a['_att'] != $b['_att']) return $a['_att'] < $b['_att'] ? 1 : -1;
      if ((int)$a['present'] !== (int)$b['present']) return (int)$a['present'] < (int)$b['present'] ? 1 : -1;
      return strcmp($a['unit_name'], $b['unit_name']);
  });
  $topRows = array_slice($ranked, 0, 10);
  $maxStack = 0;
  foreach ($topRows as $r) { $maxStack = max($maxStack, $r['_stack']); }
  $yStep = max(1, (int)ceil($maxStack / 4));
  $yMax = $yStep * 4;
  $visibleStatuses = [];
  foreach ($stackData as [$ck, $cl, $cc]) {
      foreach ($topRows as $r) { if ((int)($r[$ck] ?? 0) > 0) { $visibleStatuses[] = [$ck, $cl, $cc]; break; } }
  }
  $topTitle = match($user['role']) {
    'superadmin'         => 'Top 10 Regional Personnel Status',
    'regional_commander' => 'Top 10 Division Personnel Status — '.e($user['region_name']??''),
    'division_commander' => 'Top 10 Station Personnel Status — '.e($user['division_name']??''),
    default              => 'Top 10 Post Personnel Status — '.e($user['station_name']??''),
  };
?>
<section class="dashboard-slide" data-slide-title="<?= $topTitle ?>" aria-label="<?= $topTitle ?>">
<div class="card" style="margin-top:4px">
  <div class="chr">
    <h3><?= $topTitle ?> — <?= e(date('j F Y', strtotime($date))) ?></h3>
    <div class="action-bar">
      <a class="btn-icon bi-secondary bi-sm" href="/export-charts.php?format=csv&date=<?= e($date) ?>" title="Export CSV"><?= ICO_DL ?></a>
      <a class="btn-icon bi-secondary bi-sm" href="/export-charts.php?format=print&date=<?= e($date) ?>" target="_blank" title="Print / PDF"><?= ICO_PRINT ?></a>
    </div>
  </div>
  <div class="vchart-caption">Ranked by attendance rate (present ÷ total strength) · top <?= count($topRows) ?></div>
  <div class="vchart-legend">
    <?php foreach ($visibleStatuses as [$ck, $cl, $cc]): ?>
    <span><i style="background:<?= $cc ?>"></i><?= e($cl) ?></span>
    <?php endforeach; ?>
  </div>
  <?php if ($topRows): ?>
  <div class="vchart-scroll">
    <div class="vchart" style="min-width:<?= max(440, count($topRows) * 70) ?>px">
      <div class="vchart-yaxis">
        <?php for ($t = $yMax; $t >= 0; $t -= $yStep): ?>
        <div class="vchart-tick" style="bottom:<?= $yMax ? round($t / $yMax * 100, 2) : 0 ?>%"><?= $t ?></div>
        <?php endfor; ?>
      </div>
      <div class="vchart-plot">
        <div class="vchart-grid">
          <?php for ($t = $yMax; $t > 0; $t -= $yStep): ?>
          <div class="vchart-gridline" style="bottom:<?= round($t / $yMax * 100, 2) ?>%"></div>
          <?php endfor; ?>
        </div>
        <div class="vchart-bars">
          <?php foreach ($topRows as $i => $r): $att = round($r['_att']); ?>
          <div class="vchart-col">
            <div class="vchart-stack" style="height:<?= $yMax ? round($r['_stack'] / $yMax * 100, 2) : 0 ?>%">
              <?php foreach ($visibleStatuses as [$ck, $cl, $cc]): $v = (int)($r[$ck] ?? 0); if ($v <= 0) continue; ?>
              <div class="vchart-seg" style="flex:<?= $v ?> 1 0;background:<?= $cc ?>" title="<?= e($r['unit_name']) ?> — <?= e($cl) ?>: <?= $v ?>"></div>
              <?php endforeach; ?>
            </div>
            <div class="vchart-label">
              <strong><?= e($r['unit_name']) ?></strong>
              <span><?= (int)$r['total'] ?> total · <?= $att ?>%</span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="muted" style="padding:12px 2px">No personnel status data for this date.</div>
  <?php endif; ?>
</div>
</section>
<?php endif; ?>
</div>

<style>
/* Dashboard stat cards */
.dash-stats{margin-bottom:12px}
.dashboard-slideshow-status{font-size:11px;color:var(--muted);white-space:nowrap}
.dashboard-presentation.is-slideshow > :not(.dashboard-slide){display:none!important}
.dashboard-slide[hidden]{display:none!important}
.dashboard-presentation.is-slideshow > .dashboard-slide:not([hidden]){animation:dashboardSlideIn .35s ease-out}
.slide-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px}
.slide-heading h2{margin:0;color:var(--navy-800);font-size:18px}
.slide-heading span{color:var(--muted);font-size:12px}
@keyframes dashboardSlideIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.stat{min-height:110px;align-items:flex-start;transition:box-shadow .15s,transform .15s}
.stat:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
.stat-card-link{display:block;text-decoration:none;color:inherit}
.stat-bar{height:5px;background:rgba(0,0,0,.08);border-radius:3px;margin-top:8px;overflow:hidden}
.stat-bar-fill{height:100%;border-radius:3px;transition:width .4s}
.stat-pct{font-size:10px;color:var(--muted);margin-top:4px}
.stat .hint{font-size:10px;color:var(--muted);margin-top:2px;line-height:1.3}
.stat-deserted{border-color:#be185d}
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
.vchart-caption{font-size:11px;color:var(--muted);margin:0 2px 10px}
.vchart-legend{display:flex;gap:14px;flex-wrap:wrap;font-size:11px;color:var(--navy-700);margin:0 2px 16px}
.vchart-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px}
.vchart-scroll{overflow-x:auto;padding-bottom:4px}
.vchart{display:flex;gap:10px;padding:16px 4px 0}
.vchart-yaxis{position:relative;width:34px;height:260px;flex-shrink:0}
.vchart-tick{position:absolute;right:0;transform:translateY(50%);font-size:10px;color:var(--muted);line-height:1}
.vchart-plot{position:relative;flex:1;height:260px;border-bottom:1px solid var(--border);margin-bottom:46px}
.vchart-grid{position:absolute;inset:0;pointer-events:none}
.vchart-gridline{position:absolute;left:0;right:0;border-top:1px dashed rgba(0,0,0,.08)}
.vchart-bars{position:absolute;inset:0;display:flex;align-items:flex-end;gap:8px}
.vchart-col{position:relative;flex:1;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;min-width:0}
.vchart-stack{display:flex;flex-direction:column-reverse;width:100%;max-width:58px;min-height:2px;border-radius:4px 4px 0 0;overflow:hidden;transition:height .5s}
.vchart-seg{width:100%;min-height:0;transition:flex-grow .5s}
.vchart-label{position:absolute;top:calc(100% + 8px);left:50%;transform:translateX(-50%);width:94px;text-align:center;font-size:10px;line-height:1.3;color:var(--navy-700)}
.vchart-label strong{display:block;font-size:10px;font-weight:700;color:var(--navy-800);overflow-wrap:anywhere}
.vchart-label span{display:block;font-size:10px;color:var(--muted);margin-top:1px}
/* Summary strip */
.dash-summary{
  display:grid;grid-template-columns:repeat(5,minmax(0,1fr));background:var(--card);
  border:1px solid var(--border);border-radius:10px;
  margin-bottom:16px;overflow:hidden;box-shadow:var(--shadow-sm);
}
.ds-cell{min-width:0;padding:12px 10px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);text-align:center}
.ds-cell:nth-child(5n){border-right:none}
.ds-cell:nth-child(n+6){border-bottom:none}
.ds-label{font-size:10px;text-transform:uppercase;letter-spacing:.5px;line-height:1.3;color:var(--muted);font-weight:600;margin-bottom:5px;overflow-wrap:anywhere}
.ds-val{font-size:22px;font-weight:700;color:var(--navy-800);line-height:1.1}
@media(max-width:1000px){
  .dash-summary{grid-template-columns:repeat(3,minmax(0,1fr))}
  .ds-cell:nth-child(5n){border-right:1px solid var(--border)}
  .ds-cell:nth-child(3n){border-right:none}
  .ds-cell:nth-child(n+6){border-bottom:1px solid var(--border)}
  .ds-cell:nth-child(n+7){border-bottom:none}
}
@media(max-width:600px){
  .dash-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
  .ds-cell:nth-child(3n){border-right:1px solid var(--border)}
  .ds-cell:nth-child(2n){border-right:none}
  .ds-cell:nth-child(n+7){border-bottom:1px solid var(--border)}
  .ds-cell:nth-child(n+9){border-bottom:none}
}
@media(max-width:600px){
  .dashboard-slideshow-status{width:100%;text-align:right}
  .slide-heading{align-items:flex-start}
}
@media(prefers-reduced-motion:reduce){
  .dashboard-presentation.is-slideshow > .dashboard-slide:not([hidden]){animation:none}
}
</style>

<script>
(function(){
  const stage = document.getElementById('dashboard-presentation');
  const toggle = document.getElementById('dashboard-slideshow-toggle');
  const status = document.getElementById('dashboard-slideshow-status');
  if (!stage || !toggle || !status) return;

  const slides = Array.from(stage.querySelectorAll('.dashboard-slide'));
  const label = toggle.querySelector('span');
  const icon = toggle.querySelector('svg');
  const storageKey = 'hras-dashboard-slideshow';
  const indexKey = 'hras-dashboard-slideshow-index';
  let current = 0;
  let timer = null;
  let active = false;

  function showSlide(index) {
    current = (index + slides.length) % slides.length;
    slides.forEach((slide, i) => {
      slide.hidden = i !== current;
      slide.setAttribute('aria-hidden', i === current ? 'false' : 'true');
    });
    status.textContent = 'Slide ' + (current + 1) + ' of ' + slides.length + ' · ' + slides[current].dataset.slideTitle;
    sessionStorage.setItem(indexKey, String(current));
  }

  function startSlideshow(index) {
    if (!slides.length || active) return;
    active = true;
    stage.classList.add('is-slideshow');
    toggle.setAttribute('aria-pressed', 'true');
    label.textContent = 'Stop slideshow';
    icon.innerHTML = '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>';
    sessionStorage.setItem(storageKey, 'on');
    showSlide(index);
    timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') showSlide(current + 1);
    }, 10000);
  }

  function stopSlideshow() {
    active = false;
    window.clearInterval(timer);
    timer = null;
    stage.classList.remove('is-slideshow');
    slides.forEach(slide => {
      slide.hidden = false;
      slide.setAttribute('aria-hidden', 'false');
    });
    toggle.setAttribute('aria-pressed', 'false');
    label.textContent = 'Start slideshow';
    icon.innerHTML = '<polygon points="5 3 19 12 5 21 5 3"/>';
    status.textContent = 'Slideshow off';
    sessionStorage.removeItem(storageKey);
    sessionStorage.removeItem(indexKey);
  }

  toggle.addEventListener('click', () => {
    if (active) stopSlideshow();
    else startSlideshow(0);
  });

  if (sessionStorage.getItem(storageKey) === 'on') {
    const savedIndex = Number.parseInt(sessionStorage.getItem(indexKey) || '0', 10);
    startSlideshow(Number.isNaN(savedIndex) ? 0 : savedIndex);
  }
})();
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
