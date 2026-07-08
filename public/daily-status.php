<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_login();
$page = 'daily';
$page_title = 'Daily Status';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

$date = $_GET['date'] ?? date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'] ?? date('Y-m-d');
    $statuses = $_POST['status'] ?? [];
    $notes    = $_POST['notes'] ?? [];
    $stmt = $pdo->prepare("INSERT INTO daily_status (employee_id,date,status,notes,recorded_by) VALUES (?,?,?,?,?)
        ON CONFLICT(employee_id,date) DO UPDATE SET status=excluded.status,notes=excluded.notes,recorded_by=excluded.recorded_by");
    $check = $pdo->prepare("SELECT COUNT(*) FROM employees e WHERE e.id=? AND e.active=1 AND $scopeW");
    foreach ($statuses as $eid => $st) {
        $eid = (int)$eid;
        if (!in_array($st, ALL_STATUSES, true)) continue;
        $check->execute(array_merge([$eid], $scopeP));
        if (!(int)$check->fetchColumn()) continue;
        $stmt->execute([$eid, $date, $st, $notes[$eid] ?? null, $user['id']]);
    }
    flash('msg', 'Status saved for '.date('j M Y', strtotime($date)));
    header('Location: /daily-status.php?date='.urlencode($date)); exit;
}

$sql = "SELECT e.id, e.service_no, e.full_name, e.rank,
               rg.name AS region_name, dv.name AS division_name, st.name AS station_name, pt.name AS post_name,
               ds.status, ds.notes
        FROM employees e
        LEFT JOIN regions   rg ON rg.id=e.region_id
        LEFT JOIN divisions dv ON dv.id=e.division_id
        LEFT JOIN stations  st ON st.id=e.station_id
        LEFT JOIN posts     pt ON pt.id=e.post_id
        LEFT JOIN daily_status ds ON ds.employee_id=e.id AND ds.date=?
        WHERE e.active=1 AND $scopeW
        ORDER BY rg.name, dv.name, st.name, pt.name, e.full_name";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$date], $scopeP));
$rows = $stmt->fetchAll();

$groups = [];
foreach ($rows as $r) {
    $key = $r['post_name'] ?? '—';
    if (!isset($groups[$key])) $groups[$key] = [
        'header' => trim("{$r['region_name']} › {$r['division_name']} › {$r['station_name']} › {$r['post_name']}", '› '),
        'rows'   => [],
    ];
    $groups[$key]['rows'][] = $r;
}

$recorded   = count(array_filter($rows, fn($r) => $r['status'] !== null));
$unrecorded = count($rows) - $recorded;

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>Daily Status</h1>
    <div class="desc"><?= e(date('l, j F Y', strtotime($date))) ?> &nbsp;·&nbsp; <?= count($rows) ?> personnel &nbsp;·&nbsp; <?= $recorded ?> recorded &nbsp;·&nbsp; <span style="color:var(--amber)"><?= $unrecorded ?> pending</span></div>
  </div>
  <div class="action-bar">

    <!-- Calendar / date picker -->
    <div class="panel-wrap">
      <button class="btn-icon bi-secondary" data-panel="panel-date" title="Change date">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      </button>
      <div class="panel-drop" id="panel-date" style="min-width:280px">
        <h4>
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;width:16px;height:16px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Select Date
        </h4>
        <form method="get" style="display:flex;gap:10px;align-items:flex-end">
          <div class="form-group" style="flex:1"><label>Date</label>
            <input type="date" name="date" value="<?= e($date) ?>">
          </div>
          <button class="btn-icon bi-primary bi-lg" type="submit" title="Go to date"><?= ICO_SAVE ?></button>
        </form>
        <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
          <?php
          $nav = [
            'Yesterday'   => date('Y-m-d', strtotime('-1 day')),
            'Today'       => date('Y-m-d'),
            'This Week ↓' => date('Y-m-d', strtotime('Monday this week')),
          ];
          foreach ($nav as $lbl => $d): ?>
          <a href="/daily-status.php?date=<?= $d ?>" class="btn-icon bi-secondary bi-sm" style="font-size:11px;padding:4px 10px;text-decoration:none;<?= $d===$date?'background:var(--primary);color:#fff':'' ?>"><?= $lbl ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

<!-- Quick stats strip -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
  <?php
  $statusCounts = [];
  foreach ($rows as $r) if ($r['status']) $statusCounts[$r['status']] = ($statusCounts[$r['status']]??0)+1;
  ?>
  <?php foreach ($statusCounts as $st=>$cnt): ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:8px;padding:6px 12px;font-size:12px;font-weight:600">
    <?= status_badge($st) ?> <?= $cnt ?>
  </div>
  <?php endforeach; ?>
  <?php if ($unrecorded): ?>
  <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:600;color:#92400e">
    Unrecorded: <?= $unrecorded ?>
  </div>
  <?php endif; ?>
</div>

<form method="post">
  <input type="hidden" name="date" value="<?= e($date) ?>">

  <?php foreach ($groups as $group): ?>
  <div class="card" style="margin-bottom:12px">
    <div class="chr">
      <div class="card-group-header" style="margin:0"><?= e($group['header']) ?></div>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>File No</th><th>Name</th><th>Rank</th><th>Status</th><th>Notes</th></tr>
        </thead>
        <tbody>
          <?php foreach ($group['rows'] as $r): ?>
          <tr class="<?= $r['status'] ? 'row-'.e($r['status']) : '' ?>">
            <td style="font-family:monospace;font-size:12px"><?= e($r['service_no']) ?></td>
            <td><strong><?= e($r['full_name']) ?></strong></td>
            <td><?= e($r['rank']) ?></td>
            <td>
              <select name="status[<?= $r['id'] ?>]" class="status-select">
                <option value="" <?= !$r['status']?'selected':'' ?>>— unrecorded —</option>
                <?php foreach (ALL_STATUSES as $s): ?>
                  <option value="<?= $s ?>" <?= $r['status']===$s?'selected':'' ?>><?= e(status_label($s)) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <input type="text" name="notes[<?= $r['id'] ?>]" value="<?= e($r['notes']??'') ?>"
                placeholder="Optional…" style="font-size:12px;min-width:140px">
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (!$rows): ?>
    <div class="card"><p class="muted" style="text-align:center;padding:24px">No personnel found in your command scope.</p></div>
  <?php else: ?>
    <!-- Floating save button -->
    <div style="position:sticky;bottom:20px;display:flex;justify-content:flex-end;pointer-events:none;z-index:30">
      <button class="btn-icon bi-gold bi-lg" type="submit" title="Save all statuses"
        style="pointer-events:all;width:52px;height:52px;border-radius:50%;box-shadow:0 4px 16px rgba(212,160,23,.5)">
        <?= ICO_SAVE ?>
      </button>
    </div>
  <?php endif; ?>
</form>
<?php include __DIR__ . '/../includes/footer.php'; ?>
