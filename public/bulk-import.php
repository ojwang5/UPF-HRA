<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_login();
$pdo = db();

/* ── CSV Template download ── */
if (isset($_GET['tpl'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="personnel_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['service_no','full_name','rank','gender','directorate','unit','email','phone','post_id']);
    fputcsv($out, ['UPF-00001','EXAMPLE OFFICER A','Constable','M','Operations','General Duty','officer@upf.go.ug','+256700000000','1']);
    fputcsv($out, ['UPF-00002','EXAMPLE OFFICER B','Sergeant','F','Criminal Investigations','Flying Squad','','','1']);
    fclose($out);
    exit;
}

/* ── Post list for display ── */
$posts = $pdo->query("SELECT p.id, p.name, s.name AS sta, d.name AS div, r.name AS reg FROM posts p JOIN stations s ON s.id=p.station_id JOIN divisions d ON d.id=s.division_id JOIN regions r ON r.id=d.region_id ORDER BY reg,div,sta,p.name")->fetchAll();
$postMap = [];
foreach ($posts as $p) $postMap[$p['id']] = "{$p['reg']} › {$p['div']} › {$p['sta']} › {$p['name']}";

$results = [];
$imported = 0;
$errors   = 0;

$validRanks = ['Constable','Special Police Constable','Corporal','Sergeant','Staff Sergeant','Inspector','Assistant Superintendent','Superintendent','Senior Superintendent','Commissioner','Assistant Inspector General','Deputy Inspector General','Inspector General'];
$validDirs  = ['Operations','Criminal Investigations','Special Branch','Traffic','Fire Brigade','Marine','Administration','Finance','Human Resource','Training','Logistics','Media','Legal','ICT','Other'];

/* ── Process upload ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['csv_file']['name'])) {
    $f = $_FILES['csv_file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $results[] = ['err', 'File upload failed (error code '.$f['error'].')'];
    } else {
        $handle = fopen($f['tmp_name'], 'r');
        $headers = fgetcsv($handle); // skip header row
        $row = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            if (count($data) < 3) continue; // skip blank lines

            $sno    = trim($data[0] ?? '');
            $name   = trim($data[1] ?? '');
            $rank   = trim($data[2] ?? '');
            $gender = strtoupper(trim($data[3] ?? 'M'));
            $dir    = trim($data[4] ?? '');
            $unit   = trim($data[5] ?? '');
            $email  = trim($data[6] ?? '');
            $phone  = trim($data[7] ?? '');
            $postId = (int)trim($data[8] ?? '0');

            // Validation
            if ($sno === '' || $name === '' || $rank === '') {
                $results[] = ['err', "Row $row: service_no, full_name and rank are required (got: '{$sno}', '{$name}', '{$rank}')"];
                $errors++; continue;
            }
            if (!in_array($gender, ['M','F'])) $gender = 'M';
            if (!in_array($rank, $validRanks)) {
                $results[] = ['err', "Row $row [{$sno}]: Unknown rank '{$rank}'. Use one of: ".implode(', ', $validRanks)];
                $errors++; continue;
            }
            if ($dir !== '' && !in_array($dir, $validDirs)) {
                $results[] = ['warn', "Row $row [{$sno}]: Unknown directorate '{$dir}' — saved as-is."];
            }
            if (!$postId || !isset($postMap[$postId])) {
                $results[] = ['err', "Row $row [{$sno}]: post_id '{$data[8]}' not found. Check the post list below."];
                $errors++; continue;
            }

            // Resolve hierarchy
            $chain = $pdo->prepare("SELECT s.division_id, d.region_id, s.id AS station_id FROM posts p JOIN stations s ON s.id=p.station_id JOIN divisions d ON d.id=s.division_id WHERE p.id=?");
            $chain->execute([$postId]);
            $ch = $chain->fetch();

            try {
                $pdo->prepare("INSERT INTO employees (service_no,full_name,gender,rank,directorate,unit,region_id,division_id,station_id,post_id,email,phone) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$sno,$name,$gender,$rank,$dir,$unit,$ch['region_id'],$ch['division_id'],$ch['station_id'],$postId,$email,$phone]);
                $results[] = ['ok', "Row $row: <strong>".htmlspecialchars($name)."</strong> ({$sno}) imported successfully → {$postMap[$postId]}"];
                $imported++;
            } catch (\PDOException $ex) {
                $msg = (str_contains($ex->getMessage(),'UNIQUE') && str_contains($ex->getMessage(),'service_no'))
                    ? "Force/File number '{$sno}' already exists — skipped."
                    : $ex->getMessage();
                $results[] = ['err', "Row $row [{$sno}]: {$msg}"];
                $errors++;
            }
        }
        fclose($handle);
    }
}

$page_title = 'Bulk Import Results';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>Bulk Import Results</h1>
    <div class="desc">CSV personnel import</div>
  </div>
  <a href="/employees.php" class="btn-icon bi-secondary" style="gap:6px;padding:0 14px;width:auto;font-size:12px;font-weight:600">
    <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    Back to Personnel
  </a>
</div>

<?php if ($results): ?>
<div class="card" style="margin-bottom:16px">
  <div class="chr">
    <h3>Import Summary</h3>
    <div style="display:flex;gap:10px">
      <span style="background:#dcfce7;color:#166534;padding:4px 14px;border-radius:20px;font-size:12px;font-weight:700"><?= $imported ?> imported</span>
      <?php if ($errors): ?><span style="background:#fee2e2;color:#991b1b;padding:4px 14px;border-radius:20px;font-size:12px;font-weight:700"><?= $errors ?> failed</span><?php endif; ?>
    </div>
  </div>
  <div style="max-height:400px;overflow-y:auto">
    <?php foreach ($results as [$type, $msg]): ?>
    <div class="import-result import-<?= $type === 'ok' ? 'ok' : 'err' ?>">
      <?php if ($type === 'ok'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      <?php endif; ?>
      <?= $msg ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($imported > 0): ?>
  <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
    <a href="/employees.php" class="btn-icon bi-primary bi-lg" style="gap:8px;padding:0 18px;width:auto;font-size:12px;font-weight:600;text-decoration:none">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      View All Personnel
    </a>
  </div>
  <?php endif; ?>
</div>
<?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<div class="alert alert-error">No data rows found in the uploaded file. Make sure it is a valid CSV with a header row.</div>
<?php endif; ?>

<!-- Post reference table -->
<div class="card">
  <div class="chr"><h3>Available Posts (for post_id column)</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>post_id</th><th>Post Name</th><th>Station</th><th>Division</th><th>Region</th></tr></thead>
      <tbody>
        <?php foreach ($posts as $p): ?>
        <tr>
          <td style="font-family:monospace;font-weight:700;color:var(--primary)"><?= $p['id'] ?></td>
          <td><?= e($p['name']) ?></td>
          <td style="font-size:12px;color:var(--muted)"><?= e($p['sta']) ?></td>
          <td style="font-size:12px;color:var(--muted)"><?= e($p['div']) ?></td>
          <td style="font-size:12px;color:var(--muted)"><?= e($p['reg']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$posts): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">No posts configured yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
