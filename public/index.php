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

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>Dashboard</h1>
    <div class="desc"><?= e(date('l, j F Y', strtotime($date))) ?> · <?= e(role_label($user['role'])) ?> · <?= e(user_scope_label($user)) ?></div>
  </div>
  <form method="get" class="form-row" style="margin:0">
    <div class="form-group"><label>Date</label>
      <input type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()">
    </div>
  </form>
</div>

<!-- ── 8 Stat Cards ── -->
<div class="grid grid-4">
  <?php
  $cards = [
    ['present',      'Present',       'stat-present',  '#22c55e', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'],
    ['awol',         'AWOL',          'stat-awol',     '#ef4444', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'],
    ['on_leave',     'On Leave',      'stat-leave',    '#3b82f6', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>'],
    ['sick',         'Sick',          'stat-sick',     '#f59e0b', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>'],
    ['suspended',    'Suspended',     'stat-suspended','#7c3aed', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>'],
    ['disciplinary', 'Disciplinary',  'stat-disciplinary','#dc2626','<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'],
    ['on_duty',      'On Duty',       'stat-on_duty',  '#0891b2', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>'],
    ['on_course',    'On Course',     'stat-on_course','#059669', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>'],
  ];
  foreach ($cards as [$key, $label, $cls, $color, $icon]):
    $val = $tot[$key] ?? 0;
  ?>
  <div class="stat <?= $cls ?>">
    <div>
      <div class="label"><?= $label ?></div>
      <div class="value"><?= $val ?></div>
      <?php if ($tot['total']>0): ?><div class="hint"><?= round($val/$tot['total']*100) ?>% of total</div><?php endif; ?>
    </div>
    <div class="icon" style="color:<?= $color ?>"><?= $icon ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Summary Row ── -->
<div class="grid grid-3" style="margin-top:8px">
  <div class="card" style="padding:14px 18px">
    <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px">Strength</div>
    <div class="kv"><span>Total Personnel</span><strong><?= $tot['total'] ?></strong></div>
    <div class="kv"><span>Male</span><strong><?= $tot['male'] ?></strong></div>
    <div class="kv"><span>Female</span><strong><?= $tot['female'] ?></strong></div>
  </div>
  <div class="card" style="padding:14px 18px">
    <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px">Attendance</div>
    <?php $recorded = $tot['total'] - $tot['unrecorded']; ?>
    <div class="kv"><span>Recorded</span><strong><?= $recorded ?></strong></div>
    <div class="kv"><span>Unrecorded</span><strong><?= $tot['unrecorded'] ?></strong></div>
    <div class="kv"><span>Attendance Rate</span><strong><?= $tot['total'] ? round(($tot['present']/$tot['total'])*100) : 0 ?>%</strong></div>
  </div>
  <div class="card" style="padding:14px 18px">
    <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px">Conduct</div>
    <div class="kv"><span>Suspended</span><strong style="color:#7c3aed"><?= $tot['suspended'] ?></strong></div>
    <div class="kv"><span>Disciplinary</span><strong style="color:#dc2626"><?= $tot['disciplinary'] ?></strong></div>
    <div class="kv"><span>On Course</span><strong style="color:#059669"><?= $tot['on_course'] ?></strong></div>
  </div>
</div>

<!-- ── Per-unit breakdown ── -->
<?php if (!empty($rows)): ?>
<div class="card" style="margin-top:4px">
  <h3><?php
    echo match($user['role']) {
      'superadmin' => 'Regional Breakdown',
      'regional_commander' => 'Division Breakdown — '.e($user['region_name']),
      'division_commander' => 'Station Breakdown — '.e($user['division_name']),
      default => 'Post Breakdown — '.e($user['station_name'] ?? user_scope_label($user)),
    };
  ?></h3>
  <div class="table-wrap">
    <table>
      <thead><tr><th><?= ucfirst($rows[0]['unit_level'] ?? 'Unit') ?></th><th>Total</th><th>Present</th><th>AWOL</th><th>Leave</th><th>Sick</th><th>Suspended</th><th>Disciplinary</th><th>On Duty</th><th>On Course</th><th>Unrecorded</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['unit_name']) ?></strong></td>
          <td><?= $r['total'] ?></td>
          <td><?= $r['present'] ?></td>
          <td><?= $r['awol'] ?></td>
          <td><?= $r['on_leave'] ?></td>
          <td><?= $r['sick'] ?></td>
          <td><?php if ($r['suspended']): ?><span style="color:#7c3aed;font-weight:600"><?= $r['suspended'] ?></span><?php else: ?>0<?php endif; ?></td>
          <td><?php if ($r['disciplinary']): ?><span style="color:#dc2626;font-weight:600"><?= $r['disciplinary'] ?></span><?php else: ?>0<?php endif; ?></td>
          <td><?= $r['on_duty'] ?></td>
          <td><?= $r['on_course'] ?></td>
          <td><?php if ($r['unrecorded']): ?><span style="color:var(--muted)"><?= $r['unrecorded'] ?></span><?php else: ?>—<?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>setTimeout(()=>{ if(document.visibilityState==='visible') location.reload(); },60000);</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
