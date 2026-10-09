<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_role(['directorate_commander','unit_commander']);
$page = 'directorate';
$page_title = ($user['role']==='unit_commander' ? ($user['unit_name'] ?? 'Unit') : ($user['directorate_name'] ?? 'Directorate')).' — Personnel';
$pdo = db();

[$scopeW, $scopeP] = scope_where($user, 'e');

/* ── Personnel (with full geographic attachment) ── */
$stmt = $pdo->prepare("SELECT e.id, e.service_no, e.full_name, e.gender, e.rank, e.unit,
                              rg.name AS region_name, dv.name AS division_name,
                              st.name AS station_name, pt.name AS post_name
                       FROM employees e
                       LEFT JOIN regions   rg ON rg.id=e.region_id
                       LEFT JOIN divisions dv ON dv.id=e.division_id
                       LEFT JOIN stations  st ON st.id=e.station_id
                       LEFT JOIN posts     pt ON pt.id=e.post_id
                       WHERE e.active=1 AND $scopeW
                       ORDER BY rg.name, dv.name, st.name, pt.name, e.rank, e.full_name");
$stmt->execute($scopeP);
$personnel = $stmt->fetchAll();

$total  = count($personnel);
$male   = count(array_filter($personnel, fn($e)=>$e['gender']==='M'));
$female = $total - $male;
$unposted = count(array_filter($personnel, fn($e)=>!$e['region_name'] || !$e['post_name']));

/* ── Geographic attachment breakdown (region → division → station → post) ── */
$gb = $pdo->prepare("SELECT COALESCE(rg.name,'Unassigned') AS region, COALESCE(dv.name,'—') AS division,
                            COALESCE(st.name,'—') AS station, COALESCE(pt.name,'—') AS post, COUNT(*) AS cnt
                     FROM employees e
                     LEFT JOIN regions   rg ON rg.id=e.region_id
                     LEFT JOIN divisions dv ON dv.id=e.division_id
                     LEFT JOIN stations  st ON st.id=e.station_id
                     LEFT JOIN posts     pt ON pt.id=e.post_id
                     WHERE e.active=1 AND $scopeW
                     GROUP BY e.region_id, e.division_id, e.station_id, e.post_id
                     ORDER BY region, division, station, post
                     LIMIT 2000");
$gb->execute($scopeP);

$groups = [];
foreach ($gb->fetchAll() as $g) {
    $reg = $g['region']; $div = $g['division']; $sta = $g['station']; $pos = $g['post']; $c = (int)$g['cnt'];
    $groups[$reg]['total']              = ($groups[$reg]['total'] ?? 0) + $c;
    $groups[$reg]['divs'][$div]['total']= ($groups[$reg]['divs'][$div]['total'] ?? 0) + $c;
    $groups[$reg]['divs'][$div]['stas'][$sta]['total'] = ($groups[$reg]['divs'][$div]['stas'][$sta]['total'] ?? 0) + $c;
    $groups[$reg]['divs'][$div]['stas'][$sta]['posts'][$pos] = ($groups[$reg]['divs'][$div]['stas'][$sta]['posts'][$pos] ?? 0) + $c;
}
$regionCount = count($groups);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1><?= e($user['role']==='unit_commander' ? ($user['unit_name'] ?? 'My Unit') : ($user['directorate_name'] ?? 'My Directorate')) ?></h1>
    <div class="desc">
      <?= e(role_label($user['role'])) ?> — personnel and their attachment
      <?php if ($user['role']==='unit_commander'): ?> <span class="badge badge-leave" style="font-size:10px"><?= e($user['directorate_name'] ?? '') ?> › <?= e($user['unit_name'] ?? '') ?></span><?php endif; ?>
    </div>
  </div>
  <div class="action-bar">
    <a class="btn-icon bi-secondary" href="/employees.php" title="All personnel"><?= ICO_EYE ?> Personnel</a>
    <a class="btn-icon bi-secondary" href="/reports.php" title="Generate report"><?= ICO_GEN ?> Reports</a>
    <a class="btn-icon bi-secondary" href="/users.php" title="Manage accounts"><?= ICO_KEY ?> Users</a>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<!-- Stat boxes -->
<div class="grid grid-4 dash-stats">
  <div class="stat stat-present"><div style="flex:1"><div class="label">Total Personnel</div><div class="value"><?= $total ?></div><div class="hint">Within your command</div></div></div>
  <div class="stat stat-on_duty"><div style="flex:1"><div class="label">Male</div><div class="value"><?= $male ?></div><div class="hint"><?= $total ? round($male/$total*100) : 0 ?>% of strength</div></div></div>
  <div class="stat stat-leave"><div style="flex:1"><div class="label">Female</div><div class="value"><?= $female ?></div><div class="hint"><?= $total ? round($female/$total*100) : 0 ?>% of strength</div></div></div>
  <div class="stat stat-awol"><div style="flex:1"><div class="label">Where posted</div><div class="value"><?= $regionCount ?></div><div class="hint">Regions covered<?= $unposted ? ' · '.$unposted.' unposted' : '' ?></div></div></div>
</div>

<!-- Geographic attachment breakdown -->
<div class="card" style="margin-top:14px">
  <div class="chr"><h3>Attachment by Region / Division / Station / Post</h3></div>
  <?php if (!$groups): ?><div class="muted" style="padding:22px 0;text-align:center">No personnel attached to your command yet.</div><?php endif; ?>
  <?php foreach ($groups as $region => $rg): ?>
  <div style="padding:14px 0 4px;border-bottom:1px solid var(--border)"><strong style="color:var(--navy-800)"><?= e($region) ?></strong>
    <span class="badge badge-admin" style="margin-left:8px"><?= $rg['total'] ?></span></div>
    <?php foreach ($rg['divs'] as $div => $dg): ?>
    <div style="padding:10px 4px 2px 22px;color:var(--navy-800);font-size:13px;font-weight:600"><?= e($div) ?>
      <span class="badge badge-leave" style="font-size:10px"><?= $dg['total'] ?></span></div>
      <?php foreach ($dg['stas'] as $sta => $sg): ?>
      <div style="padding:6px 4px 2px 46px;font-size:13px"><?= e($sta) ?>
        <span class="badge badge-on_course" style="font-size:10px"><?= $sg['total'] ?></span></div>
        <div style="padding:2px 4px 2px 74px;font-size:12px;color:var(--muted);display:flex;flex-wrap:wrap;gap:4px 14px">
          <?php foreach ($sg['posts'] as $pos => $pc): ?>
          <span><?= e($pos) ?> <span class="muted" style="font-size:11px">(<?= $pc ?>)</span></span>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>

<!-- Personnel table -->
<div class="card" style="margin-top:14px">
  <div class="chr">
    <h3>Personnel <span class="badge badge-admin"><?= $total ?></span></h3>
    <div class="form-group" style="max-width:300px;min-width:200px">
      <input type="search" id="filter-pers" placeholder="Filter by name, rank, file no, station…" style="font-size:12px">
    </div>
  </div>
  <div class="table-wrap">
    <table id="pers-table">
      <thead><tr>
        <th>File No</th><th>Rank</th><th>Full Name</th><th>G</th><th>Unit</th>
        <th>Region</th><th>Division</th><th>Station</th><th>Post</th>
      </tr></thead>
      <tbody>
        <?php foreach ($personnel as $e): ?>
        <tr>
          <td style="font-family:monospace;font-size:12px"><?= e($e['service_no']) ?></td>
          <td><span class="badge badge-admin" style="font-size:10px;padding:2px 7px"><?= e($e['rank']) ?></span></td>
          <td><strong><?= e($e['full_name']) ?></strong></td>
          <td><?= e($e['gender']) ?></td>
          <td style="font-size:12px"><?= e($e['unit'] ?? '—') ?></td>
          <td style="font-size:12px"><?= e($e['region_name'] ?? '—') ?></td>
          <td style="font-size:12px"><?= e($e['division_name'] ?? '—') ?></td>
          <td style="font-size:12px"><?= e($e['station_name'] ?? '—') ?></td>
          <td style="font-size:12px"><?= e($e['post_name'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$personnel): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:30px">No personnel under your directorate yet. Add them via the Personnel page.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  var box = document.getElementById('filter-pers');
  if(!box) return;
  box.addEventListener('input', function(){
    var q = box.value.toLowerCase();
    document.querySelectorAll('#pers-table tbody tr').forEach(function(tr){
      tr.style.display = q==='' || tr.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
    });
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>