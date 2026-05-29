<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_login();
$page = 'dashboard';
$pdo  = db();

$status = $_GET['status'] ?? 'present';
if (!in_array($status, array_merge(ALL_STATUSES,['unrecorded']), true)) $status = 'present';
$date = $_GET['date'] ?? date('Y-m-d');
[$scopeW, $scopeP] = scope_where($user, 'e');

if ($status === 'unrecorded') {
    $statusClause = 'AND ds.status IS NULL';
    $statusParams  = [];
} else {
    $statusClause = 'AND ds.status = ?';
    $statusParams  = [$status];
}

$sql = "SELECT e.*, rg.name AS region_name, dv.name AS division_name, st.name AS station_name, pt.name AS post_name, ds.status, ds.notes
        FROM employees e
        LEFT JOIN regions   rg ON rg.id=e.region_id
        LEFT JOIN divisions dv ON dv.id=e.division_id
        LEFT JOIN stations  st ON st.id=e.station_id
        LEFT JOIN posts     pt ON pt.id=e.post_id
        LEFT JOIN daily_status ds ON ds.employee_id=e.id AND ds.date=?
        WHERE e.active=1 AND $scopeW $statusClause
        ORDER BY rg.name, dv.name, st.name, e.full_name";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$date], $scopeP, $statusParams));
$rows = $stmt->fetchAll();

$page_title = status_label($status) . ' — Details';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= e(status_label($status)) ?> Personnel</h1>
    <div class="desc"><?= e(date('l, j F Y',strtotime($date))) ?> · <?= count($rows) ?> personnel</div>
  </div>
  <a class="btn btn-secondary" href="/?date=<?= urlencode($date) ?>">&larr; Dashboard</a>
</div>
<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Service No</th><th>Name</th><th>Rank</th><th>Gender</th><th>Region</th><th>Station</th><th>Post</th><th>Phone</th><th>Status</th><th>Notes</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['service_no']) ?></td><td><?= e($r['full_name']) ?></td>
          <td><?= e($r['rank']) ?></td><td><?= $r['gender']==='M'?'Male':'Female' ?></td>
          <td><?= e($r['region_name']??'—') ?></td><td><?= e($r['station_name']??'—') ?></td>
          <td><?= e($r['post_name']??'—') ?></td><td><?= e($r['phone']??'') ?></td>
          <td><?= $r['status'] ? status_badge($r['status']) : '<span class="muted">Unrecorded</span>' ?></td>
          <td><?= e($r['notes']??'') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" style="text-align:center;color:var(--muted);padding:24px">No personnel with this status on <?= e($date) ?>.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
