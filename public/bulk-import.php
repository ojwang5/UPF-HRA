<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_login();
$pdo  = db();

/* ── CSV Template download ── */
if (isset($_GET['tpl'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="personnel_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['service_no','full_name','rank','gender','directorate','unit','email','phone','post_id']);
    fputcsv($out, ['UPF-00001','SURNAME FIRSTNAME','SGT','M','Operations','General Duty','officer@upf.go.ug','+256700000000','1']);
    fputcsv($out, ['UPF-00002','SURNAME FIRSTNAME B','CPL','F','Criminal Investigations','','','','1']);
    fclose($out);
    exit;
}

/* ── Reference data ── */
$posts = $pdo->query("SELECT p.id, p.name, s.name AS sta, d.name AS div, r.name AS reg FROM posts p JOIN stations s ON s.id=p.station_id JOIN divisions d ON d.id=s.division_id JOIN regions r ON r.id=d.region_id ORDER BY reg,div,sta,p.name")->fetchAll();
$postMap = [];
foreach ($posts as $p) $postMap[$p['id']] = "{$p['reg']} › {$p['div']} › {$p['sta']} › {$p['name']}";

// Fetch valid directorates from DB; fall back gracefully if table not yet migrated
try {
    $validDirs  = $pdo->query("SELECT name FROM directorates ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $validUnits = $pdo->query("SELECT name FROM units ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    $validDirs  = ['Operations','Criminal Investigations','Special Branch','Traffic','Fire Brigade','Marine','Administration','Finance','Human Resource','Training','Logistics','Media','Legal','ICT','Other'];
    $validUnits = [];
}

$validRanks = UPF_RANKS;

/* ── Process upload ── */
$results    = [];
$imported   = 0;
$updated    = 0;
$skipped    = 0;
$errors     = 0;
$dryRun     = false;
$onConflict = 'skip'; // skip | update | abort

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['csv_file']['name'])) {
    $onConflict = $_POST['on_conflict'] ?? 'skip';
    $dryRun     = !empty($_POST['dry_run']);
    $f = $_FILES['csv_file'];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        $results[] = ['err', 'File upload failed (error code '.$f['error'].')'];
    } else {
        $handle  = fopen($f['tmp_name'], 'r');
        $headers = fgetcsv($handle); // skip header row
        $rowData = [];

        // First pass: parse & validate ALL rows
        $rowNum = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count(array_filter($data)) === 0) continue; // blank row

            $sno    = trim($data[0] ?? '');
            $name   = trim($data[1] ?? '');
            $rank   = trim($data[2] ?? '');
            $gender = strtoupper(trim($data[3] ?? 'M'));
            $dir    = trim($data[4] ?? '');
            $unit   = trim($data[5] ?? '');
            $email  = trim($data[6] ?? '');
            $phone  = trim($data[7] ?? '');
            $postId = (int)trim($data[8] ?? '0');
            $rowErrs = [];

            // Required fields
            if ($sno === '')   $rowErrs[] = 'service_no is required';
            if ($name === '')  $rowErrs[] = 'full_name is required';
            if ($rank === '')  $rowErrs[] = 'rank is required';

            // Rank validation
            if ($rank !== '' && !in_array($rank, $validRanks, true)) {
                $rowErrs[] = "Unknown rank '{$rank}' — must be one of: ".implode(', ', $validRanks);
            }

            // Gender
            if (!in_array($gender, ['M','F'])) $gender = 'M';

            // Email format
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rowErrs[] = "Invalid email '{$email}'";
            }

            // Post validation
            if (!$postId || !isset($postMap[$postId])) {
                $rowErrs[] = "post_id '{$data[8]}' not found in the system";
            }

            // Directorate (warn only)
            $dirWarn = '';
            if ($dir !== '' && !in_array($dir, $validDirs, true)) {
                $dirWarn = "Directorate '{$dir}' not in system — will be saved as entered.";
            }

            // Check for duplicate service_no
            $existing = null;
            if ($sno !== '') {
                $eStmt = $pdo->prepare("SELECT id, full_name, rank FROM employees WHERE service_no=?");
                $eStmt->execute([$sno]);
                $existing = $eStmt->fetch() ?: null;
            }

            $rowData[] = compact('rowNum','sno','name','rank','gender','dir','unit','email','phone','postId','rowErrs','dirWarn','existing');
        }
        fclose($handle);

        // Abort-on-duplicate check
        $aborted = false;
        if ($onConflict === 'abort') {
            $dupRows = array_filter($rowData, fn($r) => $r['existing'] !== null && empty($r['rowErrs']));
            if ($dupRows) {
                foreach ($dupRows as $r) {
                    $results[] = ['err', "Row {$r['rowNum']} [{$r['sno']}]: Duplicate — <strong>{$r['existing']['full_name']}</strong> already exists. Import aborted."];
                }
                $errors  = count($dupRows);
                $aborted = true;
            }
        }

        // Second pass: execute (skip if aborted)
        foreach ($aborted ? [] : $rowData as $r) {
            if (!empty($r['rowErrs'])) {
                $results[] = ['err', "Row {$r['rowNum']} [<strong>{$r['sno']}</strong>]: ".implode('; ', $r['rowErrs'])];
                $errors++; continue;
            }

            if ($r['dirWarn']) {
                $results[] = ['warn', "Row {$r['rowNum']} [{$r['sno']}]: {$r['dirWarn']}"];
            }

            $chain = $pdo->prepare("SELECT s.division_id, d.region_id, s.id AS station_id FROM posts p JOIN stations s ON s.id=p.station_id JOIN divisions d ON d.id=s.division_id WHERE p.id=?");
            $chain->execute([$r['postId']]);
            $ch = $chain->fetch();
            if (!$ch) {
                $results[] = ['err', "Row {$r['rowNum']} [{$r['sno']}]: Could not resolve post hierarchy."];
                $errors++; continue;
            }

            if ($r['existing']) {
                // Duplicate: apply on_conflict strategy
                if ($onConflict === 'skip') {
                    $results[] = ['warn', "Row {$r['rowNum']} [<strong>{$r['sno']}</strong>]: Duplicate — <strong>{$r['existing']['full_name']}</strong> already exists → skipped."];
                    $skipped++; continue;
                } elseif ($onConflict === 'update') {
                    if (!$dryRun) {
                        $pdo->prepare("UPDATE employees SET full_name=?,gender=?,rank=?,directorate=?,unit=?,region_id=?,division_id=?,station_id=?,post_id=?,email=?,phone=? WHERE id=?")
                            ->execute([$r['name'],$r['gender'],$r['rank'],$r['dir'],$r['unit'],$ch['region_id'],$ch['division_id'],$ch['station_id'],$r['postId'],$r['email'],$r['phone'],$r['existing']['id']]);
                        log_activity('Bulk Update Personnel', 'employee', "{$r['rank']} {$r['name']}", $r['existing']['id'], "via CSV import");
                    }
                    $results[] = ['updated', "Row {$r['rowNum']} [<strong>{$r['sno']}</strong>]: Updated → <strong>{$r['name']}</strong> ({$r['rank']}) @ {$postMap[$r['postId']]}".($dryRun?' <em>[dry run]</em>':'')];
                    $updated++; continue;
                }
            }

            // Insert new
            if (!$dryRun) {
                try {
                    $pdo->prepare("INSERT INTO employees (service_no,full_name,gender,rank,directorate,unit,region_id,division_id,station_id,post_id,email,phone) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$r['sno'],$r['name'],$r['gender'],$r['rank'],$r['dir'],$r['unit'],$ch['region_id'],$ch['division_id'],$ch['station_id'],$r['postId'],$r['email'],$r['phone']]);
                    log_activity('Bulk Import Personnel', 'employee', "{$r['rank']} {$r['name']}", (int)$pdo->lastInsertId(), "via CSV import");
                } catch (\PDOException $ex) {
                    $results[] = ['err', "Row {$r['rowNum']} [{$r['sno']}]: DB error — ".$ex->getMessage()];
                    $errors++; continue;
                }
            }
            $results[] = ['ok', "Row {$r['rowNum']} [<strong>{$r['sno']}</strong>]: <strong>{$r['name']}</strong> ({$r['rank']}) → {$postMap[$r['postId']]}".($dryRun?' <em>[dry run]</em>':'')];
            $imported++;
        }
    }
}

render:
$page_title = 'Bulk Import — Personnel';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Bulk Import — Personnel</h1>
    <div class="desc">Upload a CSV file to add or update multiple personnel records at once</div>
  </div>
  <div class="action-bar">
    <a href="/bulk-import.php?tpl=1" class="btn-icon bi-secondary" style="gap:6px;padding:0 14px;width:auto;font-size:12px">
      <?= ICO_DL ?> <span>Download Template</span>
    </a>
    <a href="/employees.php" class="btn-icon bi-secondary" style="gap:6px;padding:0 14px;width:auto;font-size:12px">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
      Back to Personnel
    </a>
  </div>
</div>

<!-- Upload form -->
<div class="card" style="margin-bottom:20px">
  <div class="chr"><h3>Upload CSV File</h3></div>
  <form method="post" enctype="multipart/form-data">
    <div class="form-row" style="align-items:flex-end;flex-wrap:wrap;gap:14px">

      <div class="form-group" style="flex:3;min-width:260px">
        <label>CSV File <span style="color:var(--red)">*</span></label>
        <input type="file" name="csv_file" accept=".csv,text/csv" required>
      </div>

      <div class="form-group" style="min-width:220px">
        <label>On Duplicate Service No</label>
        <select name="on_conflict">
          <option value="skip">Skip duplicate rows</option>
          <option value="update">Update existing record</option>
          <option value="abort">Abort entire import</option>
        </select>
      </div>

      <div class="form-group" style="min-width:160px;display:flex;flex-direction:column">
        <label>Mode</label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;text-transform:none;letter-spacing:0;font-weight:600;color:var(--text);margin-top:8px">
          <input type="checkbox" name="dry_run" value="1">
          Dry run (preview only — no changes saved)
        </label>
      </div>

      <div class="form-group" style="flex:0;display:flex;flex-direction:column">
        <label>&nbsp;</label>
        <button class="btn-icon bi-gold bi-lg" type="submit" style="gap:8px;padding:0 18px;width:auto;font-size:13px;font-weight:700">
          <?= ICO_DL ?> <span>Upload &amp; Process</span>
        </button>
      </div>
    </div>
  </form>

  <!-- CSV column guide -->
  <div style="margin-top:14px;padding-top:14px;border-top:1px dashed var(--border)">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:8px">CSV Column Reference</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
      <?php foreach ([
        'service_no'=>'Required · unique force/file no',
        'full_name'  =>'Required · Surname Firstname',
        'rank'       =>'Required · e.g. SGT, CPL, IP',
        'gender'     =>'M or F',
        'directorate'=>'e.g. Operations',
        'unit'       =>'e.g. Flying Squad',
        'email'      =>'Optional',
        'phone'      =>'Optional',
        'post_id'    =>'Required · numeric ID from table below',
      ] as $col=>$hint): ?>
      <div style="background:var(--navy-50);border-radius:6px;padding:5px 10px;font-size:11px">
        <code style="font-weight:700;color:var(--primary)"><?= $col ?></code>
        <span style="color:var(--muted);margin-left:4px"><?= $hint ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:10px;font-size:12px;background:var(--navy-50);padding:8px 12px;border-radius:8px;color:var(--muted)">
      <strong style="color:var(--navy-700)">Valid Ranks (in seniority order):</strong>
      <?= implode(', ', array_map(fn($r) => "<code>{$r}</code>", $validRanks)) ?>
    </div>
  </div>
</div>

<!-- Results -->
<?php if ($results || ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_FILES['csv_file']['name']))): ?>
<div class="card" style="margin-bottom:20px">
  <div class="chr">
    <h3>Import <?= $dryRun ? '<span style="color:var(--amber)">[DRY RUN — no changes saved]</span>' : 'Results' ?></h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($imported>0): ?><span style="background:#dcfce7;color:#166534;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700"><?= $imported ?> <?= $dryRun?'would import':'imported' ?></span><?php endif; ?>
      <?php if ($updated>0): ?><span style="background:#dbeafe;color:#1e40af;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700"><?= $updated ?> <?= $dryRun?'would update':'updated' ?></span><?php endif; ?>
      <?php if ($skipped>0): ?><span style="background:#fef3c7;color:#92400e;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700"><?= $skipped ?> skipped</span><?php endif; ?>
      <?php if ($errors>0): ?><span style="background:#fee2e2;color:#991b1b;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700"><?= $errors ?> errors</span><?php endif; ?>
    </div>
  </div>
  <?php if (!$results): ?>
    <div style="text-align:center;padding:20px;color:var(--muted)">No data rows found in the uploaded file.</div>
  <?php else: ?>
  <div style="max-height:500px;overflow-y:auto">
    <?php foreach ($results as [$type, $msg]):
      [$bg,$tc,$ico] = match($type) {
        'ok'      => ['#dcfce7','#166534','✓'],
        'updated' => ['#dbeafe','#1e40af','↻'],
        'warn'    => ['#fef9c3','#854d0e','⚠'],
        default   => ['#fee2e2','#991b1b','✗'],
      };
    ?>
    <div style="display:flex;align-items:baseline;gap:8px;padding:6px 14px;border-bottom:1px solid <?= $bg ?>;background:<?= $bg ?>20">
      <span style="flex-shrink:0;font-weight:700;color:<?= $tc ?>;font-size:13px"><?= $ico ?></span>
      <span style="font-size:12px;color:<?= $tc ?>"><?= $msg ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($imported>0 && !$dryRun): ?>
  <div style="padding:14px;border-top:1px solid var(--border)">
    <a href="/employees.php" class="btn-icon bi-primary bi-lg" style="gap:8px;padding:0 18px;width:auto;font-size:12px;font-weight:600;text-decoration:none">
      <?= ICO_SAVE ?> View All Personnel
    </a>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Post reference table -->
<div class="card">
  <div class="chr"><h3>Post Reference — use these IDs in the <code>post_id</code> column</h3></div>
  <div class="table-wrap"><table>
    <thead><tr><th>post_id</th><th>Post</th><th>Station</th><th>Division</th><th>Region</th></tr></thead>
    <tbody>
      <?php foreach ($posts as $p): ?>
      <tr>
        <td style="font-family:monospace;font-weight:700;color:var(--primary)"><?= $p['id'] ?></td>
        <td><?= e($p['name']) ?></td>
        <td class="muted" style="font-size:12px"><?= e($p['sta']) ?></td>
        <td class="muted" style="font-size:12px"><?= e($p['div']) ?></td>
        <td class="muted" style="font-size:12px"><?= e($p['reg']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$posts): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">No posts configured.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
