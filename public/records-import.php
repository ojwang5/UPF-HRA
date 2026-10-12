<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_min_rank('station_commander');
$pdo  = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

/* ── Module configuration ─────────────────────────────────────────── */
$MODULES = [
    'courses' => [
        'title'    => 'On Courses',
        'back'     => '/courses.php',
        'required' => ['service_no','course_name','start_date','end_date'],
        'columns'  => [
            'service_no'    => 'Required · File/Force No of an active officer in your scope',
            'course_name'   => 'Required · e.g. Junior Staff Course',
            'course_nature' => 'e.g. Professional, Promotional, Refresher',
            'school'        => 'School / training school',
            'place'         => 'Place / town',
            'start_date'    => 'YYYY-MM-DD',
            'end_date'      => 'YYYY-MM-DD',
            'sponsor'       => 'e.g. UPF / Self / Government',
            'notes'         => 'Optional free text',
        ],
    ],
    'assignments' => [
        'title'    => 'Special Assignments',
        'back'     => '/special-assignments.php',
        'required' => ['service_no','title','start_date'],
        'columns'  => [
            'service_no' => 'Required · File/Force No of an active officer in your scope',
            'title'      => 'Required · e.g. Election Duty Task Team',
            'nature'     => 'e.g. VIP Protection, Election Duty, Operation',
            'place'      => 'Place / location',
            'start_date' => 'YYYY-MM-DD',
            'end_date'   => 'YYYY-MM-DD (blank = open-ended)',
            'notes'      => 'Optional free text',
        ],
    ],
    'transfers' => [
        'title'    => 'Transfers',
        'back'     => '/transfers.php',
        'required' => ['service_no'],
        'columns'  => [
            'service_no'        => 'Required · File/Force No of an active officer in your scope',
            'transfer_scope'    => 'hierarchy (default) or directorate',
            'to_post_id'        => 'For hierarchy · numeric post id (see reference below)',
            'to_directorate'    => 'For directorate · destination directorate name',
            'to_unit'           => 'For directorate · destination unit name',
            'reason'            => 'Reason for transfer',
            'transfer_date'     => 'YYYY-MM-DD (defaults to today)',
            'effective_date'    => 'YYYY-MM-DD (defaults to transfer date)',
        ],
    ],
];

$module = $_GET['module'] ?? $_POST['module'] ?? '';
if (!isset($MODULES[$module])) {
    flash('err', 'Unknown import module.');
    header('Location: /index.php'); exit;
}
$cfg = $MODULES[$module];

/* ── Reference data ───────────────────────────────────────────────── */
$allPosts = $pdo->query("SELECT p.id, p.name, s.name AS sta, d.name AS div, r.name AS reg
                         FROM posts p
                         JOIN stations s ON s.id=p.station_id
                         JOIN divisions d ON d.id=s.division_id
                         JOIN regions r ON r.id=d.region_id
                         ORDER BY reg, div, sta, p.name")->fetchAll();
$postMap = [];
foreach ($allPosts as $p) $postMap[(int)$p['id']] = "{$p['reg']} › {$p['div']} › {$p['sta']} › {$p['name']}";
$validDirs  = $pdo->query("SELECT name FROM directorates WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$validUnits = $pdo->query("SELECT u.name FROM units u JOIN directorates d ON d.id=u.directorate_id WHERE u.active=1 ORDER BY u.name")->fetchAll(PDO::FETCH_COLUMN);

/* ── CSV template download ────────────────────────────────────────── */
if (isset($_GET['tpl'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$module.'_import_template.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, array_keys($cfg['columns']));
    if ($module === 'courses') {
        fputcsv($out, ['UPF-00001','Junior Staff Course','Promotional','Police Training School Kabalye','Masindi','2026-02-01','2026-05-30','UPF','']);
    } elseif ($module === 'assignments') {
        fputcsv($out, ['UPF-00001','Election Duty Task Team','Election Duty','Mbale','2026-01-10','2026-01-25','']);
    } else {
        fputcsv($out, ['UPF-00001','hierarchy','12','','','Operational need','2026-01-05','2026-01-10']);
    }
    fclose($out);
    exit;
}

/** Map a CSV header row to column-name => index. */
function rc_col_map(array $headers): array {
    $map = [];
    foreach ($headers as $i => $h) {
        $key = strtolower(trim((string)$h, " \t\"'"));
        if ($key !== '') $map[$key] = $i;
    }
    return $map;
}

/* ── Process upload ───────────────────────────────────────────────── */
$results = []; $imported = 0; $errors = 0; $dryRun = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['csv_file']['name'])) {
    $dryRun = !empty($_POST['dry_run']);
    $f = $_FILES['csv_file'];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        $results[] = ['err', 'File upload failed (error code '.$f['error'].')'];
    } else {
        $handle  = fopen($f['tmp_name'], 'r');
        $headers = fgetcsv($handle);
        $cm = rc_col_map(is_array($headers) ? $headers : []);
        $missing = array_values(array_filter($cfg['required'], fn($c) => !isset($cm[$c])));
        if ($missing) {
            $results[] = ['err', 'CSV header row is missing required column'.(count($missing) > 1 ? 's' : '').': '.implode(', ', $missing).'. Download the template for the correct format.'];
            $handle = null;
        }

        $rowNum = 1;
        while ($handle !== null && ($data = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) continue;
            $cell = fn(string $c, string $d = ''): string => isset($cm[$c]) ? trim((string)($data[$cm[$c]] ?? '')) : $d;

            $sno = $cell('service_no');
            $rowErrs = [];

            // Resolve employee within scope
            $emp = null;
            if ($sno === '') {
                $rowErrs[] = 'service_no is required';
            } else {
                $es = $pdo->prepare("SELECT id, service_no, rank, full_name, directorate, unit,
                                            region_id, division_id, station_id, post_id
                                     FROM employees e WHERE e.service_no=? AND e.active=1 AND $scopeW");
                $es->execute(array_merge([$sno], $scopeP));
                $emp = $es->fetch() ?: null;
                if (!$emp) $rowErrs[] = "No active officer with service_no '{$sno}' found in your scope";
            }

            if ($module === 'courses') {
                $cname = $cell('course_name');
                $start = $cell('start_date');
                $end   = $cell('end_date');
                if ($cname === '')        $rowErrs[] = 'course_name is required';
                if (!valid_date($start))  $rowErrs[] = "invalid start_date '{$start}' (use YYYY-MM-DD)";
                if (!valid_date($end))    $rowErrs[] = "invalid end_date '{$end}' (use YYYY-MM-DD)";
                if (valid_date($start) && valid_date($end) && $end < $start) $rowErrs[] = 'end_date cannot be before start_date';

                if ($rowErrs) { $results[] = ['err', "Row {$rowNum} [{$sno}]: ".implode('; ', $rowErrs)]; $errors++; continue; }

                $duration = leave_days_between($start, $end);
                if (!$dryRun) {
                    $pdo->prepare("INSERT INTO on_courses (employee_id,course_name,course_nature,school,place,start_date,end_date,duration_days,sponsor,status,notes,created_by)
                                   VALUES (?,?,?,?,?,?,?,?,?,'active',?,?)")
                        ->execute([(int)$emp['id'], $cname, $cell('course_nature') ?: 'Professional',
                                   $cell('school') ?: null, $cell('place') ?: null, $start, $end, $duration,
                                   $cell('sponsor') ?: null, $cell('notes') ?: null, $user['id']]);
                    log_activity('Bulk Import On-Course', 'on_course', $cname, (int)$pdo->lastInsertId(), personnel_label($emp));
                }
                $results[] = ['ok', "Row {$rowNum} [{$sno}]: ".e(personnel_label($emp))." → {$cname} ({$duration} days)".($dryRun ? ' <em>[dry run]</em>' : '')];
                $imported++; continue;
            }

            if ($module === 'assignments') {
                $title = $cell('title');
                $start = $cell('start_date');
                $end   = $cell('end_date');
                if ($title === '')       $rowErrs[] = 'title is required';
                if (!valid_date($start)) $rowErrs[] = "invalid start_date '{$start}' (use YYYY-MM-DD)";
                if ($end !== '' && !valid_date($end)) $rowErrs[] = "invalid end_date '{$end}' (use YYYY-MM-DD)";

                if ($rowErrs) { $results[] = ['err', "Row {$rowNum} [{$sno}]: ".implode('; ', $rowErrs)]; $errors++; continue; }

                if (!$dryRun) {
                    $pdo->prepare("INSERT INTO special_assignments (employee_id,title,nature,place,start_date,end_date,status,notes,created_by)
                                   VALUES (?,?,?,?,?,?, 'active', ?, ?)")
                        ->execute([(int)$emp['id'], $title, $cell('nature') ?: null, $cell('place') ?: null,
                                   $start, $end !== '' ? $end : null, $cell('notes') ?: null, $user['id']]);
                    log_activity('Bulk Import Special Assignment', 'special_assignment', $title, (int)$pdo->lastInsertId(), personnel_label($emp));
                }
                $results[] = ['ok', "Row {$rowNum} [{$sno}]: ".e(personnel_label($emp))." → {$title}".($dryRun ? ' <em>[dry run]</em>' : '')];
                $imported++; continue;
            }

            // transfers
            $scopeVal = strtolower($cell('transfer_scope', 'hierarchy'));
            if ($scopeVal !== 'directorate') $scopeVal = 'hierarchy';
            $tDate = $cell('transfer_date') ?: date('Y-m-d');
            $eff   = $cell('effective_date') ?: $tDate;
            if (!valid_date($tDate)) $rowErrs[] = "invalid transfer_date '{$tDate}'";
            if (!valid_date($eff))   $rowErrs[] = "invalid effective_date '{$eff}'";

            $toPost = 0; $toDirId = null; $toUnitId = null;
            if ($scopeVal === 'hierarchy') {
                $toPost = (int)$cell('to_post_id', '0');
                if (!$toPost || !isset($postMap[$toPost])) $rowErrs[] = "to_post_id '".$cell('to_post_id')."' is not a valid post id (see reference)";
            } else {
                $dirName  = $cell('to_directorate');
                $unitName = $cell('to_unit');
                if ($dirName === '' && $unitName === '') {
                    $rowErrs[] = 'for directorate transfers provide to_directorate and/or to_unit';
                }
                if ($dirName !== '') {
                    $st = $pdo->prepare("SELECT id FROM directorates WHERE name=?");
                    $st->execute([$dirName]); $toDirId = (int)($st->fetchColumn() ?: 0) ?: null;
                    if (!$toDirId) $rowErrs[] = "unknown directorate '{$dirName}'";
                }
                if ($unitName !== '') {
                    $st = $pdo->prepare("SELECT id FROM units WHERE name=?");
                    $st->execute([$unitName]); $toUnitId = (int)($st->fetchColumn() ?: 0) ?: null;
                    if (!$toUnitId) $rowErrs[] = "unknown unit '{$unitName}'";
                }
            }

            if ($rowErrs) { $results[] = ['err', "Row {$rowNum} [{$sno}]: ".implode('; ', $rowErrs)]; $errors++; continue; }

            if (!$dryRun) {
                if ($scopeVal === 'hierarchy') {
                    $chain = $pdo->prepare("SELECT s.id AS station_id, s.division_id, d.region_id
                                            FROM posts p JOIN stations s ON s.id=p.station_id JOIN divisions d ON d.id=s.division_id
                                            WHERE p.id=?");
                    $chain->execute([$toPost]); $ch = $chain->fetch();
                    if (!$ch) { $results[] = ['err', "Row {$rowNum} [{$sno}]: could not resolve post hierarchy"]; $errors++; continue; }
                    $pdo->prepare("INSERT INTO transfers
                            (employee_id, transfer_scope, from_region_id, from_division_id, from_station_id, from_post_id,
                             to_region_id, to_division_id, to_station_id, to_post_id, reason, transfer_date, effective_date,
                             status, requested_by) VALUES (?, 'hierarchy', ?,?,?,?, ?,?,?,?, ?,?,?, 'pending', ?)")
                        ->execute([(int)$emp['id'], $emp['region_id'], $emp['division_id'], $emp['station_id'], $emp['post_id'],
                                   $ch['region_id'], $ch['division_id'], $ch['station_id'], $toPost,
                                   $cell('reason') ?: null, $tDate, $eff, $user['id']]);
                    $desc = "to post #{$toPost}";
                } else {
                    $fromDirId = null; $fromUnitId = null;
                    if (!empty($emp['directorate'])) {
                        $q = $pdo->prepare("SELECT id FROM directorates WHERE name=?"); $q->execute([$emp['directorate']]);
                        $fromDirId = (int)($q->fetchColumn() ?: 0) ?: null;
                    }
                    if (!empty($emp['unit'])) {
                        $q = $pdo->prepare("SELECT id FROM units WHERE name=?"); $q->execute([$emp['unit']]);
                        $fromUnitId = (int)($q->fetchColumn() ?: 0) ?: null;
                    }
                    $pdo->prepare("INSERT INTO transfers
                            (employee_id, transfer_scope, from_directorate_id, from_unit_id,
                             to_directorate_id, to_unit_id, reason, transfer_date, effective_date,
                             status, requested_by) VALUES (?, 'directorate', ?,?, ?,?, ?,?,?, 'pending', ?)")
                        ->execute([(int)$emp['id'], $fromDirId, $fromUnitId, $toDirId, $toUnitId,
                                   $cell('reason') ?: null, $tDate, $eff, $user['id']]);
                    $desc = 'to directorate/unit';
                }
                log_activity('Bulk Import Transfer', 'transfer', $desc, (int)$pdo->lastInsertId(), personnel_label($emp));
            }
            $results[] = ['ok', "Row {$rowNum} [{$sno}]: ".e(personnel_label($emp))." → transfer request queued (pending)".($dryRun ? ' <em>[dry run]</em>' : '')];
            $imported++;
        }
        if ($handle !== null) fclose($handle);
    }
}

$page_title = 'Bulk Import — '.$cfg['title'];
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Bulk Import — <?= e($cfg['title']) ?></h1>
    <div class="desc">Upload a CSV file to create multiple <?= e(strtolower($cfg['title'])) ?> records at once</div></div>
  <div class="action-bar">
    <a href="/records-import.php?module=<?= e($module) ?>&tpl=1" class="btn-icon bi-secondary" style="gap:6px;padding:0 14px;width:auto;font-size:12px"><?= ICO_DL ?> <span>Download Template</span></a>
    <a href="<?= e($cfg['back']) ?>" class="btn-icon bi-secondary" style="gap:6px;padding:0 14px;width:auto;font-size:12px">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
      Back
    </a>
  </div>
</div>

<div class="card" style="margin-bottom:20px">
  <div class="chr"><h3>Upload CSV File</h3></div>
  <form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
    <input type="hidden" name="module" value="<?= e($module) ?>">
    <div class="form-row" style="align-items:flex-end;flex-wrap:wrap;gap:14px">
      <div class="form-group" style="flex:3;min-width:260px">
        <label>CSV File <span style="color:var(--red)">*</span></label>
        <input type="file" name="csv_file" accept=".csv,text/csv" required>
      </div>
      <div class="form-group" style="min-width:160px;display:flex;flex-direction:column">
        <label>Mode</label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;text-transform:none;letter-spacing:0;font-weight:600;color:var(--text);margin-top:8px">
          <input type="checkbox" name="dry_run" value="1"> Dry run (preview only — no changes saved)
        </label>
      </div>
      <div class="form-group" style="flex:0;display:flex;flex-direction:column">
        <label>&nbsp;</label>
        <button class="btn-icon bi-gold bi-lg" type="submit" style="gap:8px;padding:0 18px;width:auto;font-size:13px;font-weight:700"><?= ICO_DL ?> <span>Upload &amp; Process</span></button>
      </div>
    </div>
  </form>

  <div style="margin-top:14px;padding-top:14px;border-top:1px dashed var(--border)">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:8px">CSV Column Reference</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
      <?php foreach ($cfg['columns'] as $col => $hint): ?>
      <div style="background:var(--navy-50);border-radius:6px;padding:5px 10px;font-size:11px">
        <code style="font-weight:700;color:var(--primary)"><?= e($col) ?></code>
        <span style="color:var(--muted);margin-left:4px"><?= e($hint) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($results || ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_FILES['csv_file']['name']))): ?>
<div class="card" style="margin-bottom:20px">
  <div class="chr">
    <h3>Import <?= $dryRun ? '<span style="color:var(--amber)">[DRY RUN — no changes saved]</span>' : 'Results' ?></h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($imported>0): ?><span style="background:#dcfce7;color:#166534;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700"><?= $imported ?> <?= $dryRun?'would import':'imported' ?></span><?php endif; ?>
      <?php if ($errors>0): ?><span style="background:#fee2e2;color:#991b1b;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700"><?= $errors ?> errors</span><?php endif; ?>
    </div>
  </div>
  <?php if (!$results): ?>
    <div style="text-align:center;padding:20px;color:var(--muted)">No data rows found in the uploaded file.</div>
  <?php else: ?>
  <div style="max-height:500px;overflow-y:auto">
    <?php foreach ($results as [$type, $msg]):
      [$bg,$tc,$ico] = $type === 'ok' ? ['#dcfce7','#166534','✓'] : ['#fee2e2','#991b1b','✗'];
    ?>
    <div style="display:flex;align-items:baseline;gap:8px;padding:6px 14px;border-bottom:1px solid <?= $bg ?>;background:<?= $bg ?>20">
      <span style="flex-shrink:0;font-weight:700;color:<?= $tc ?>;font-size:13px"><?= $ico ?></span>
      <span style="font-size:12px;color:<?= $tc ?>"><?= $msg ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($module === 'transfers'): ?>
<div class="card">
  <div class="chr"><h3>Post Reference — use these IDs in the <code>to_post_id</code> column</h3></div>
  <div class="table-wrap"><table>
    <thead><tr><th>post_id</th><th>Post</th><th>Station</th><th>Division</th><th>Region</th></tr></thead>
    <tbody>
      <?php foreach ($allPosts as $p): ?>
      <tr>
        <td style="font-family:monospace;font-weight:700;color:var(--primary)"><?= (int)$p['id'] ?></td>
        <td><?= e($p['name']) ?></td>
        <td class="muted" style="font-size:12px"><?= e($p['sta']) ?></td>
        <td class="muted" style="font-size:12px"><?= e($p['div']) ?></td>
        <td class="muted" style="font-size:12px"><?= e($p['reg']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$allPosts): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">No posts configured.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
