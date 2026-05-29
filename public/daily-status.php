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
    flash('msg', 'Daily status saved for '.$date);
    header('Location: /daily-status.php?date='.urlencode($date)); exit;
}

$sql = "SELECT e.*, rg.name AS region_name, dv.name AS division_name, st.name AS station_name, pt.name AS post_name,
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

// Group by post for display
$groups = [];
foreach ($rows as $r) {
    $key = ($r['post_name'] ?? '—');
    if (!isset($groups[$key])) $groups[$key] = ['header' => "{$r['region_name']} › {$r['division_name']} › {$r['station_name']} › {$r['post_name']}", 'rows' => []];
    $groups[$key]['rows'][] = $r;
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Daily Status</h1><div class="desc">Record attendance for <?= e(date('l, j F Y', strtotime($date))) ?></div></div>
  <form method="get" class="form-row" style="margin:0">
    <div class="form-group"><label>Date</label><input type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()"></div>
  </form>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

<form method="post">
  <input type="hidden" name="date" value="<?= e($date) ?>">
  <?php foreach ($groups as $gKey => $group): ?>
  <div class="card" style="margin-bottom:12px">
    <div class="card-group-header"><?= e($group['header']) ?></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Service No</th><th>Name</th><th>Rank</th><th>Status</th><th>Notes</th></tr></thead>
        <tbody>
          <?php foreach ($group['rows'] as $r): ?>
          <tr class="<?= $r['status'] ? 'row-'.e($r['status']) : '' ?>">
            <td><?= e($r['service_no']) ?></td>
            <td><?= e($r['full_name']) ?></td>
            <td><?= e($r['rank']) ?></td>
            <td>
              <select name="status[<?= $r['id'] ?>]" class="status-select" data-emp="<?= $r['id'] ?>">
                <option value="" <?= !$r['status']?'selected':'' ?>>— unrecorded —</option>
                <?php foreach (ALL_STATUSES as $s): ?>
                  <option value="<?= $s ?>" <?= $r['status']===$s?'selected':'' ?>><?= e(status_label($s)) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="text" name="notes[<?= $r['id'] ?>]" value="<?= e($r['notes']??'') ?>" placeholder="Optional notes"></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="card"><p class="muted" style="text-align:center;padding:24px">No personnel found in your command.</p></div><?php endif; ?>
  <?php if ($rows): ?><div style="margin-bottom:18px"><button class="btn" type="submit">Save All Status</button></div><?php endif; ?>
</form>
<?php include __DIR__ . '/../includes/footer.php'; ?>
