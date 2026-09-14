<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
$user = require_min_rank('post_commander');
$page = 'transfers';
$page_title = 'Transfer Management';
$pdo  = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

// ── Reference data for the forms ─────────────────────────────────────
$regions  = $pdo->query("SELECT id, name FROM regions ORDER BY name")->fetchAll();
$divisions = $pdo->query("SELECT id, region_id, name FROM divisions ORDER BY name")->fetchAll();
$stations = $pdo->query("SELECT id, division_id, name FROM stations ORDER BY name")->fetchAll();
$posts    = $pdo->query("SELECT id, station_id, name FROM posts ORDER BY name")->fetchAll();
$directorates = $pdo->query("SELECT id, name FROM directorates WHERE active=1 ORDER BY name")->fetchAll();
$units        = $pdo->query("SELECT u.id, u.directorate_id, u.name FROM units u JOIN directorates d ON d.id=u.directorate_id WHERE u.active=1 ORDER BY u.name")->fetchAll();

/* ── Transfer helpers ─────────────────────────────────────────────────── */
function transfer_emp_label(PDO $pdo, int $eid): string {
    $r = $pdo->query("SELECT full_name, service_no, rank FROM employees WHERE id=".(int)$eid)->fetch();
    return trim((string)($r['rank'] ?? '').' '.($r['full_name'] ?? '').' ('.($r['service_no'] ?? '').')');
}

function transfer_from_label(PDO $pdo, array $t): string {
    if (($t['transfer_scope'] ?? 'hierarchy') === 'hierarchy') {
        $parts = [];
        foreach ([['regions','from_region_id'],['divisions','from_division_id'],['stations','from_station_id'],['posts','from_post_id']] as [$tbl,$col]) {
            if (!empty($t[$col])) $parts[] = $pdo->query("SELECT name FROM $tbl WHERE id=".(int)$t[$col])->fetchColumn();
        }
        return implode(' › ', $parts) ?: '—';
    }
    $dir  = !empty($t['from_directorate_id']) ? $pdo->query("SELECT name FROM directorates WHERE id=".(int)$t['from_directorate_id'])->fetchColumn() : null;
    $unit = !empty($t['from_unit_id'])        ? $pdo->query("SELECT name FROM units WHERE id=".(int)$t['from_unit_id'])->fetchColumn()        : null;
    return trim(($dir ?: 'Directorate').' › '.($unit ?: ''), ' ›') ?: '—';
}

function transfer_to_label(PDO $pdo, array $t): string {
    if (($t['transfer_scope'] ?? 'hierarchy') === 'hierarchy') {
        $parts = [];
        foreach ([['regions','to_region_id'],['divisions','to_division_id'],['stations','to_station_id'],['posts','to_post_id']] as [$tbl,$col]) {
            if (!empty($t[$col])) $parts[] = $pdo->query("SELECT name FROM $tbl WHERE id=".(int)$t[$col])->fetchColumn();
        }
        return implode(' › ', $parts) ?: '—';
    }
    $dir  = !empty($t['to_directorate_id']) ? $pdo->query("SELECT name FROM directorates WHERE id=".(int)$t['to_directorate_id'])->fetchColumn() : null;
    $unit = !empty($t['to_unit_id'])        ? $pdo->query("SELECT name FROM units WHERE id=".(int)$t['to_unit_id'])->fetchColumn()        : null;
    return trim(($dir ?: 'Directorate').' › '.($unit ?: ''), ' ›') ?: '—';
}

/**
 * Fan out a transfer notification to the selected parties:
 * the requesting user, the receiving unit (region for geographic transfers,
 * directorate for functional transfers) and super admins.
 */
function transfer_notify(PDO $pdo, array $t, string $title, string $msg, string $kind, ?int $createdBy = null): void {
    $opts = ['kind'=>$kind, 'link'=>'/transfers.php', 'created_by'=>$createdBy];
    if (!empty($t['requested_by'])) {
        notify($title, $msg, 'user', $opts + ['target_user_id'=>(int)$t['requested_by']]);
    }
    if (($t['transfer_scope'] ?? 'hierarchy') === 'hierarchy') {
        if (!empty($t['to_region_id'])) notify_region((int)$t['to_region_id'], $title, $msg, $opts);
    } else {
        if (!empty($t['to_directorate_id'])) notify_directorate((int)$t['to_directorate_id'], $title, $msg, $opts);
    }
    notify_superadmins($title, $msg, $opts);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'create') {
        $eid    = (int)$_POST['employee_id'];
        $scope  = (int)$_POST['transfer_scope'] === 2 ? 'directorate' : 'hierarchy';
        $emp = $pdo->prepare("SELECT * FROM employees e WHERE e.id=? AND e.active=1 AND $scopeW");
        $emp->execute(array_merge([$eid], $scopeP)); $e = $emp->fetch();
        $tDate = $_POST['transfer_date'] ?? date('Y-m-d');
        $effDate = $_POST['effective_date'] ?? $tDate;

        if ($e && valid_date($effDate)) {
            if ($scope === 'hierarchy') {
                $toPost     = (int)($_POST['to_post_id'] ?? 0);
                $toPostRow  = null;
                if ($toPost) {
                    foreach ($posts as $p) if ((int)$p['id'] === $toPost) { $toPostRow = $p; break; }
                }
                $toStation  = $toPostRow ? (int)$toPostRow['station_id'] : (int)($_POST['to_station_id'] ?? 0);
                $toDivRow   = null;
                foreach ($stations as $s) if ((int)$s['id'] === $toStation) { $toDivRow = $s; break; }
                $toDivision = $toDivRow ? (int)$toDivRow['division_id'] : (int)($_POST['to_division_id'] ?? 0);
                $toRegionId = (int)($_POST['to_region_id'] ?? 0);
                if ($toDivision) { foreach ($divisions as $d) if ((int)$d['id'] === $toDivision) { $toRegionId = (int)$d['region_id']; break; } }

                if (!$toPost || !$toStation || !$toDivision || !$toRegionId) {
                    flash('err', 'Please select a valid destination post.');
                } else {
                    $pdo->prepare("INSERT INTO transfers
                            (employee_id, transfer_scope, from_region_id, from_division_id, from_station_id, from_post_id,
                             to_region_id, to_division_id, to_station_id, to_post_id, reason, transfer_date, effective_date,
                             status, requested_by) VALUES (?,? ,?,?,?,?, ?,?,?,?, ?,?,?, 'pending', ?)")
                        ->execute([
                            $eid, $scope,
                            $e['region_id'], $e['division_id'], $e['station_id'], $e['post_id'],
                            $toRegionId, $toDivision, $toStation, $toPost,
                            trim($_POST['reason'] ?? '') ?: null, $tDate, $effDate, $user['id']
                        ]);
                    $tid = (int)$pdo->lastInsertId();
                    $rec = [
                        'transfer_scope'=>'hierarchy', 'requested_by'=>$user['id'],
                        'to_region_id'=>$toRegionId, 'to_division_id'=>$toDivision,
                        'to_station_id'=>$toStation, 'to_post_id'=>$toPost,
                    ];
                    log_activity('Requested transfer', 'transfer', 'To post #'.$toPost, $tid, 'Employee #'.$eid);
                    $empLabel = trim(e($e['rank']).' '.e($e['full_name']).' ('.e($e['service_no']).')');
                    transfer_notify($pdo, $rec, 'New transfer request — '.e($e['full_name']),
                        $empLabel.' posted to '.transfer_to_label($pdo, $rec).' (status: pending).', 'transfer', $user['id']);
                    flash('msg', 'Transfer request submitted (hierarchy). Selected users notified.');
                }
            } else {
                $toDirId = (int)($_POST['to_directorate_id'] ?? 0);
                $toUnitId = (int)($_POST['to_unit_id'] ?? 0);
                if (!$toDirId && !$toUnitId) {
                    flash('err', 'Please select a destination directorate or unit.');
                } else {
                    // Resolve the employee's current directorate/unit ids from their text values
                    $fromDirId = null; $fromUnitId = null;
                    if ($e['directorate']) {
                        $fd = $pdo->prepare("SELECT id FROM directorates WHERE name=?");
                        $fd->execute([$e['directorate']]); $fromDirId = (int)($fd->fetchColumn() ?: 0) ?: null;
                    }
                    if ($e['unit']) {
                        $fu = $pdo->prepare("SELECT id FROM units WHERE name=?");
                        $fu->execute([$e['unit']]); $fromUnitId = (int)($fu->fetchColumn() ?: 0) ?: null;
                    }
                    $pdo->prepare("INSERT INTO transfers
                            (employee_id, transfer_scope, from_directorate_id, from_unit_id,
                             to_directorate_id, to_unit_id, reason, transfer_date, effective_date,
                             status, requested_by) VALUES (?,?, ?,?, ?,?, ?,?,?, 'pending', ?)")
                        ->execute([
                            $eid, $scope,
                            $fromDirId, $fromUnitId,
                            $toDirId ?: null, $toUnitId ?: null,
                            trim($_POST['reason'] ?? '') ?: null, $tDate, $effDate, $user['id']
                        ]);
                    $tid = (int)$pdo->lastInsertId();
                    $rec = [
                        'transfer_scope'=>'directorate', 'requested_by'=>$user['id'],
                        'to_directorate_id'=>$toDirId ?: null, 'to_unit_id'=>$toUnitId ?: null,
                    ];
                    log_activity('Requested transfer', 'transfer', 'To unit #'.$toUnitId, $tid, 'Employee #'.$eid);
                    $empLabel = trim(e($e['rank']).' '.e($e['full_name']).' ('.e($e['service_no']).')');
                    transfer_notify($pdo, $rec, 'New transfer request — '.e($e['full_name']),
                        $empLabel.' posted to '.transfer_to_label($pdo, $rec).' (status: pending).', 'transfer', $user['id']);
                    flash('msg', 'Transfer request submitted (directorate). Selected users notified.');
                }
            }
        }
    } elseif ($id && in_array($action, ['approve','reject'], true) && role_rank($user['role']) > role_rank('post_commander')) {
        $t = $pdo->query("SELECT * FROM transfers WHERE id=$id AND status='pending'")->fetch();
        if ($t) {
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $pdo->prepare("UPDATE transfers SET status=?, reviewed_by=?, reviewed_at=?, review_notes=? WHERE id=?")
                ->execute([$newStatus, $user['id'], date('c'), trim($_POST['notes'] ?? '') ?: null, $id]);
            log_activity(ucfirst($action).'d transfer', 'transfer', (string)$id, $id, 'Employee #'.$t['employee_id']);
            $empLabel = transfer_emp_label($pdo, (int)$t['employee_id']);
            transfer_notify($pdo, $t, 'Transfer '.$newStatus.' — '.$empLabel,
                $empLabel.' transfer is now '.$newStatus.' (to '.transfer_to_label($pdo, $t).'). Review notes: '.((trim($_POST['notes'] ?? '') !== '') ? trim($_POST['notes']) : '—'),
                $newStatus === 'approved' ? 'success' : 'warning', $user['id']);
            flash('msg', 'Transfer '.($action==='approve'?'approved':'rejected').'. Parties notified.');
        }
    } elseif ($id && in_array($action, ['execute','cancel'], true) && role_rank($user['role']) > role_rank('post_commander')) {
        $t = $pdo->query("SELECT * FROM transfers WHERE id=$id")->fetch();
        if ($t && ($t['status']==='approved' || $t['status']==='pending' || $t['status']==='executed')) {
            if ($action === 'execute') {
                // Update the employee's denormalized scope columns
                if ($t['transfer_scope'] === 'hierarchy') {
                    $pdo->prepare("UPDATE employees SET region_id=?, division_id=?, station_id=?, post_id=? WHERE id=?")
                        ->execute([$t['to_region_id'], $t['to_division_id'], $t['to_station_id'], $t['to_post_id'], $t['employee_id']]);
                } else {
                    if ($t['to_directorate_id']) {
                        $dname = $pdo->query("SELECT name FROM directorates WHERE id=".(int)$t['to_directorate_id'])->fetchColumn();
                        $pdo->prepare("UPDATE employees SET directorate=? WHERE id=?")->execute([$dname ?: 'Other', $t['employee_id']]);
                    }
                    if ($t['to_unit_id']) {
                        $uname = $pdo->query("SELECT name FROM units WHERE id=".(int)$t['to_unit_id'])->fetchColumn();
                        $pdo->prepare("UPDATE employees SET unit=? WHERE id=?")->execute([$uname ?: 'General Duty', $t['employee_id']]);
                    }
                }
                $pdo->prepare("UPDATE transfers SET status='executed', executed_by=?, executed_at=datetime('now','localtime') WHERE id=?")
                    ->execute([$user['id'], $id]);
                $t = $pdo->query("SELECT * FROM transfers WHERE id=$id")->fetch();
                log_activity('Executed transfer', 'transfer', (string)$id, $id, 'Employee #'.$t['employee_id']);
                $empLabel = transfer_emp_label($pdo, (int)$t['employee_id']);
                transfer_notify($pdo, $t, 'Transfer executed — '.$empLabel,
                    $empLabel.' transferred to '.transfer_to_label($pdo, $t).'. Status: executed (normal). Reporting to the new place of work is expected.', 'success', $user['id']);
                flash('msg', 'Transfer executed — personnel records updated. Parties notified.');
            } else {
                $pdo->prepare("UPDATE transfers SET status='cancelled' WHERE id=?")->execute([$id]);
                $t = $pdo->query("SELECT * FROM transfers WHERE id=$id")->fetch();
                log_activity('Cancelled transfer', 'transfer', (string)$id, $id, 'Employee #'.$t['employee_id']);
                $empLabel = transfer_emp_label($pdo, (int)$t['employee_id']);
                transfer_notify($pdo, $t, 'Transfer cancelled — '.$empLabel,
                    $empLabel.' transfer to '.transfer_to_label($pdo, $t).' has been cancelled. Status: cancelled.', 'warning', $user['id']);
                flash('msg', 'Transfer cancelled. Parties notified.');
            }
        }
    } elseif ($id && $action === 'report') {
        $t = $pdo->query("SELECT * FROM transfers WHERE id=$id")->fetch();
        if ($t && $t['status'] === 'executed' && ($t['report_status'] ?? 'pending') === 'pending') {
            $pdo->prepare("UPDATE transfers SET report_status='reported', reported_by=?, reported_at=datetime('now','localtime'), report_notes=? WHERE id=?")
                ->execute([$user['id'], trim($_POST['notes'] ?? '') ?: null, $id]);
            $t = $pdo->query("SELECT * FROM transfers WHERE id=$id")->fetch();
            log_activity('Recorded transfer report', 'transfer', (string)$id, $id, 'Employee #'.$t['employee_id']);
            $empLabel = transfer_emp_label($pdo, (int)$t['employee_id']);
            transfer_notify($pdo, $t, 'Reporting confirmed — '.$empLabel,
                $empLabel.' has reported to '.transfer_to_label($pdo, $t).'. Transfer status: executed (reported).', 'success', $user['id']);
            flash('msg', 'Reporting confirmed — '.$empLabel.' has reported to the new place of work.');
        }
    }
    header('Location: /transfers.php'); exit;
}

// ── Data for listing ─────────────────────────────────────────────────
$empStmt = $pdo->prepare("SELECT id, service_no, full_name, rank, directorate, unit,
                                 region_id, division_id, station_id, post_id
                          FROM employees e WHERE e.active=1 AND $scopeW ORDER BY full_name");
$empStmt->execute($scopeP); $selectEmps = $empStmt->fetchAll();

$scopeFilter = $_GET['scope'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$extra = '';
if (in_array($scopeFilter, ['hierarchy','directorate'], true)) $extra .= " AND t.transfer_scope='$scopeFilter'";
if ($statusFilter !== '' && preg_match('/^(pending|approved|rejected|executed|cancelled)$/', $statusFilter)) $extra .= " AND t.status='$statusFilter'";

$rows = $pdo->prepare("
    SELECT t.*, e.full_name AS emp_name, e.service_no, e.rank,
           fr.name AS from_region, fdv.name AS from_division, fst.name AS from_station, fpt.name AS from_post,
           tr.name AS to_region,  tdv.name AS to_division,  tst.name AS to_station,  tpt.name AS to_post,
           fd.name AS from_dir, fu.name AS from_unit, td.name AS to_dir, tu.name AS to_unit,
           req.full_name AS requested_by_name
    FROM transfers t
    JOIN employees e ON e.id=t.employee_id
    LEFT JOIN regions   fr ON fr.id=t.from_region_id
    LEFT JOIN divisions fdv ON fdv.id=t.from_division_id
    LEFT JOIN stations  fst ON fst.id=t.from_station_id
    LEFT JOIN posts     fpt ON fpt.id=t.from_post_id
    LEFT JOIN regions   tr  ON tr.id=t.to_region_id
    LEFT JOIN divisions tdv ON tdv.id=t.to_division_id
    LEFT JOIN stations  tst ON tst.id=t.to_station_id
    LEFT JOIN posts     tpt ON tpt.id=t.to_post_id
    LEFT JOIN directorates fd ON fd.id=t.from_directorate_id
    LEFT JOIN units     fu  ON fu.id=t.from_unit_id
    LEFT JOIN directorates td ON td.id=t.to_directorate_id
    LEFT JOIN units     tu  ON tu.id=t.to_unit_id
    LEFT JOIN users     req ON req.id=t.requested_by
    WHERE $scopeW$extra
    ORDER BY CASE t.status WHEN 'pending' THEN 1 WHEN 'approved' THEN 2 ELSE 3 END, t.requested_at DESC
    LIMIT 300
");
$rows->execute($scopeP); $transfers = $rows->fetchAll();

$pendingCount = count(array_filter($transfers, fn($t)=>$t['status']==='pending'));
$approvedCount = count(array_filter($transfers, fn($t)=>$t['status']==='approved'));
$executedCount = count(array_filter($transfers, fn($t)=>$t['status']==='executed'));

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><h1>Transfer Management</h1>
    <div class="desc">Post personnel between regions, divisions, stations &amp; posts, or between directorates &amp; units · <?= $pendingCount ?> pending · <?= $approvedCount ?> approved · <?= $executedCount ?> executed · affected parties are notified on every update</div></div>
  <div class="action-bar">
    <!-- Scope filter -->
    <details class="form-panel">
      <summary><button type="button" class="btn-icon bi-secondary" title="Filter"><?= ICO_SEARCH ?></button></summary>
      <div class="form-body" style="padding:12px 16px">
        <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
          <div class="form-group"><label>Scope</label>
            <select name="scope"><option value="">All</option>
              <option value="hierarchy" <?= $scopeFilter==='hierarchy'?'selected':'' ?>>Geographic (Region/Station/Post)</option>
              <option value="directorate" <?= $scopeFilter==='directorate'?'selected':'' ?>>Directorate / Unit</option>
            </select>
          </div>
          <div class="form-group"><label>Status</label>
            <select name="status"><option value="">All</option>
              <?php foreach (['pending','approved','rejected','executed','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn-icon bi-primary" type="submit" title="Apply"><?= ICO_SAVE ?></button>
        </form>
      </div>
    </details>
    <div class="panel-wrap">
      <button class="btn-icon bi-primary" data-panel="panel-new" title="New transfer request"><?= ICO_PLUS ?></button>
      <div class="panel-drop" id="panel-new" style="min-width:640px">
        <h4>New Transfer Request</h4>
        <form method="post" style="display:flex;flex-direction:column;gap:10px">
          <input type="hidden" name="action" value="create">
          <div class="form-row">
            <div class="form-group" style="flex:2"><label>Employee</label>
              <select name="employee_id" required id="tr-employee"><option value="">— select —</option>
                <?php foreach ($selectEmps as $emp): ?><option value="<?= $emp['id'] ?>"
                  data-region="<?= (int)$emp['region_id'] ?>" data-division="<?= (int)$emp['division_id'] ?>"
                  data-station="<?= (int)$emp['station_id'] ?>" data-post="<?= (int)$emp['post_id'] ?>"
                  data-dir="<?= e($emp['directorate']??'') ?>" data-unit="<?= e($emp['unit']??'') ?>"><?= e($emp['service_no'].' — '.$emp['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Transfer Scope</label>
              <select name="transfer_scope" id="tr-scope">
                <option value="1">Geographic (Region / Station / Post)</option>
                <option value="2">Directorate / Unit</option>
              </select>
            </div>
          </div>

          <div id="tr-hierarchy" class="transfer-block">
            <div class="block-title">From (current posting)</div>
            <div class="form-row">
              <div class="form-group"><label>Region</label><input type="text" id="tr-from-region" disabled></div>
              <div class="form-group"><label>Division</label><input type="text" id="tr-from-division" disabled></div>
              <div class="form-group"><label>Station</label><input type="text" id="tr-from-station" disabled></div>
              <div class="form-group"><label>Post</label><input type="text" id="tr-from-post" disabled></div>
            </div>
            <div class="block-title" style="margin-top:8px">To (destination)</div>
            <div class="form-row">
              <div class="form-group"><label>Region</label>
                <select name="to_region_id" id="tr-to-region"><option value="">— select —</option>
                  <?php foreach ($regions as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="form-group"><label>Division</label>
                <select name="to_division_id" id="tr-to-division"><option value="">— select —</option></select>
              </div>
              <div class="form-group"><label>Station</label>
                <select name="to_station_id" id="tr-to-station"><option value="">— select —</option></select>
              </div>
              <div class="form-group"><label>Post</label>
                <select name="to_post_id" id="tr-to-post"><option value="">— select —</option></select>
              </div>
            </div>
          </div>

          <div id="tr-directorate" class="transfer-block" style="display:none">
            <div class="block-title">From (current)</div>
            <div class="form-row">
              <div class="form-group"><label>Directorate</label><input type="text" id="tr-from-dir" disabled></div>
              <div class="form-group"><label>Unit</label><input type="text" id="tr-from-unit" disabled></div>
            </div>
            <div class="block-title" style="margin-top:8px">To (destination)</div>
            <div class="form-row">
              <div class="form-group"><label>Directorate</label>
                <select name="to_directorate_id" id="tr-to-dir"><option value="">— none —</option>
                  <?php foreach ($directorates as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="form-group"><label>Unit</label>
                <select name="to_unit_id" id="tr-to-unit"><option value="">— none —</option>
                  <?php foreach ($directorates as $d): ?>
                    <optgroup label="<?= e($d['name']) ?>">
                      <?php foreach ($units as $u) if ((int)$u['directorate_id']===(int)$d['id']): ?>
                        <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                      <?php endif; ?>
                    </optgroup>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group"><label>Transfer Date</label><input type="date" name="transfer_date" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label>Effective Date</label><input type="date" name="effective_date" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group" style="flex:2"><label>Reason</label><input type="text" name="reason" placeholder="e.g. Operational need, promotion, transfer request"></div>
          </div>
          <div class="action-bar" style="justify-content:flex-end">
            <button class="btn-icon bi-gold bi-lg" type="submit" title="Submit transfer"><?= ICO_SEND ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($e=flash('err')): ?><div class="alert alert-danger"><?= e($e) ?></div><?php endif; ?>

<div class="card">
  <div class="chr"><h3>Transfer Records</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>File No</th><th>Name</th><th>Rank</th><th>Type</th><th>From</th><th>To</th>
        <th>Effective</th><th>Reason</th><th>Status</th><th>Reporting</th><th style="width:150px"></th>
      </tr></thead>
      <tbody>
        <?php foreach ($transfers as $r):
          if ($r['transfer_scope']==='hierarchy') {
            $from = trim(($r['from_region']??'').' › '.($r['from_division']??'').' › '.($r['from_station']??'').' › '.($r['from_post']??''),' › ');
            $to   = trim(($r['to_region']??'').' › '.($r['to_division']??'').' › '.($r['to_station']??'').' › '.($r['to_post']??''),' › ');
            $type = 'Geographic';
          } else {
            $from = trim(($r['from_dir']??'—').' › '.($r['from_unit']??'—'),' ›');
            $to   = trim(($r['to_dir']??'—').' › '.($r['to_unit']??'—'),' ›');
            $type = 'Directorate';
          }
        ?>
        <tr>
          <td style="font-family:monospace;font-size:12px"><?= e($r['service_no']) ?></td>
          <td><strong><?= e($r['emp_name']) ?></strong></td>
          <td><?= e($r['rank']) ?></td>
          <td><span class="badge <?= $r['transfer_scope']==='hierarchy'?'badge-special_assignment':'badge-undeployed' ?>"><?= $type ?></span></td>
          <td style="font-size:12px;max-width:150px"><?= e($from) ?></td>
          <td style="font-size:12px;max-width:150px"><strong><?= e($to) ?></strong></td>
          <td style="white-space:nowrap;font-size:12px"><?= e(date('j M y', strtotime($r['effective_date']))) ?><br>
            <span class="muted" style="font-size:10px">req <?= e(date('j M y', strtotime($r['requested_at']))) ?></span></td>
          <td style="font-size:12px;max-width:180px"><?= e($r['reason']??'—') ?></td>
          <td><?= transfer_status_badge($r['status']) ?>
            <?php if ($r['review_notes']): ?><div class="muted" style="font-size:10px"><?= e($r['review_notes']) ?></div><?php endif; ?></td>
          <td style="font-size:11px">
            <?= transfer_report_badge($r['report_status'] ?? 'pending') ?>
            <?php if (($r['report_status'] ?? 'pending')==='reported'): ?>
              <div class="muted" style="font-size:10px;margin-top:2px"><?= e(date('j M y, H:i', strtotime($r['reported_at'] ?? $r['requested_at']))) ?>
                <?php if ($r['report_notes']): ?> · <?= e($r['report_notes']) ?><?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['status']==='pending' && role_rank($user['role']) > role_rank('post_commander')): ?>
              <details class="form-panel" style="position:relative">
                <summary><button type="button" class="btn-icon bi-secondary bi-sm" title="Review"><?= ICO_EYE ?></button></summary>
                <div class="form-body" style="position:absolute;right:0;top:100%;min-width:250px;z-index:20;padding:14px;box-shadow:var(--shadow-md)">
                  <form method="post" style="display:flex;flex-direction:column;gap:8px">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <input type="text" name="notes" placeholder="Review notes (optional)">
                    <div class="action-bar">
                      <button class="btn-icon bi-green" name="action" value="approve" title="Approve"><?= ICO_OK ?></button>
                      <button class="btn-icon bi-danger" name="action" value="reject" title="Reject"><?= ICO_REJECT ?></button>
                    </div>
                  </form>
                </div>
              </details>
            <?php elseif (($r['status']==='approved' || $r['status']==='pending') && !$r['executed_at'] && role_rank($user['role']) > role_rank('post_commander')): ?>
              <details class="form-panel" style="position:relative">
                <summary><button type="button" class="btn-icon bi-secondary bi-sm" title="Execute / cancel"><?= ICO_EDIT ?></button></summary>
                <div class="form-body" style="position:absolute;right:0;top:100%;min-width:200px;z-index:20;padding:12px;box-shadow:var(--shadow-md)">
                  <form method="post" style="display:flex;flex-direction:column;gap:8px">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <div class="action-bar">
                      <button class="btn-icon bi-green" name="action" value="execute" title="Execute transfer"><?= ICO_OK ?></button>
                      <button class="btn-icon bi-danger" name="action" value="cancel" title="Cancel transfer"><?= ICO_REJECT ?></button>
                    </div>
                  </form>
                </div>
              </details>
            <?php elseif ($r['status']==='executed' && ($r['report_status'] ?? 'pending')==='pending'): ?>
              <details class="form-panel" style="position:relative">
                <summary><button type="button" class="btn-icon bi-green bi-sm" title="Confirm reporting at new workplace"><?= ICO_OK ?></button></summary>
                <div class="form-body" style="position:absolute;right:0;top:100%;min-width:230px;z-index:20;padding:12px;box-shadow:var(--shadow-md)">
                  <form method="post" style="display:flex;flex-direction:column;gap:8px">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <div style="font-size:12px;color:var(--navy-700)">Has <strong><?= e($r['emp_name']) ?></strong> reported at the new place of work?</div>
                    <input type="text" name="notes" placeholder="Reporting notes (optional)">
                    <button class="btn-icon bi-green bi-lg" name="action" value="report" title="Confirm reported"><?= ICO_OK ?> <span style="font-size:12px;margin-left:4px">Reported</span></button>
                  </form>
                </div>
              </details>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$transfers): ?><tr><td colspan="11" style="text-align:center;color:var(--muted);padding:24px">No transfer records found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<style>
.transfer-block{border:1px dashed var(--border);border-radius:8px;padding:10px 12px}
.block-title{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:600;margin-bottom:6px}
</style>

<script>
(function(){
  var regions = <?= json_encode(array_map(fn($r)=>[(int)$r['id'],$r['name']],$regions)) ?>;
  var divisions = <?= json_encode(array_map(fn($d)=>[(int)$d['id'],(int)$d['region_id'],$d['name']],$divisions)) ?>;
  var stations = <?= json_encode(array_map(fn($s)=>[(int)$s['id'],(int)$s['division_id'],$s['name']],$stations)) ?>;
  var posts = <?= json_encode(array_map(fn($p)=>[(int)$p['id'],(int)$p['station_id'],$p['name']],$posts)) ?>;

  function fill(sel, data, label){
    var el = document.getElementById(sel);
    el.innerHTML = '<option value="">— select —</option>' + data.map(function(x){ return '<option value="'+x[0]+'">'+label(x)+'</option>'; }).join('');
  }
  function divsOfRegion(rid){ return divisions.filter(function(d){ return d[1]===rid; }); }
  function stationsOfDiv(did){ return stations.filter(function(s){ return s[1]===did; }); }
  function postsOfStation(sid){ return posts.filter(function(p){ return p[1]===sid; }); }

  var $region = document.getElementById('tr-to-region');
  var $division = document.getElementById('tr-to-division');
  var $station = document.getElementById('tr-to-station');
  var $post = document.getElementById('tr-to-post');

  $region.addEventListener('change', function(){
    var rid = parseInt($region.value,10);
    $division.innerHTML = '<option value="">— select —</option>';
    $station.innerHTML = '<option value="">— select —</option>';
    $post.innerHTML = '<option value="">— select —</option>';
    divsOfRegion(rid).forEach(function(d){ $division.add(new Option(d[2], d[0])); });
  });
  $division.addEventListener('change', function(){
    $station.innerHTML = '<option value="">— select —</option>';
    $post.innerHTML = '<option value="">— select —</option>';
    stationsOfDiv(parseInt($division.value,10)).forEach(function(s){ $station.add(new Option(s[2], s[0])); });
  });
  $station.addEventListener('change', function(){
    $post.innerHTML = '<option value="">— select —</option>';
    postsOfStation(parseInt($station.value,10)).forEach(function(p){ $post.add(new Option(p[2], p[0])); });
  });

  var $scope = document.getElementById('tr-scope');
  function toggleScope(){
    var h = $scope.value === '1';
    document.getElementById('tr-hierarchy').style.display = h ? '' : 'none';
    document.getElementById('tr-directorate').style.display = h ? 'none' : '';
  }
  $scope.addEventListener('change', toggleScope);

  // Auto-fill "from" when employee selected
  var $emp = document.getElementById('tr-employee');
  function setText(id, v){ document.getElementById(id).value = v || ''; }
  var dirMap = {};
  <?php foreach ($directorates as $d): ?>dirMap[<?= (int)$d['id'] ?>] = <?= json_encode($d['name']) ?>;<?php endforeach; ?>
  var unitMap = {};
  <?php foreach ($units as $u): ?>unitMap[<?= (int)$u['id'] ?>] = <?= json_encode($u['name']) ?>;<?php endforeach; ?>
  $emp.addEventListener('change', function(){
    var opt = $emp.options[$emp.selectedIndex];
    if(!opt || opt.value==='') return;
    setText('tr-from-region', lookupName(regions, opt.dataset.region));
    setText('tr-from-division', lookupName(divisions, opt.dataset.division));
    setText('tr-from-station', lookupName(stations, opt.dataset.station));
    setText('tr-from-post', lookupName(posts, opt.dataset.post));
    setText('tr-from-dir', opt.dataset.dir);
    setText('tr-from-unit', opt.dataset.unit);
  });
  // Map name list -> label lookup helper
  function lookupName(list, id){
    id = parseInt(id,10);
    for(var i=0;i<list.length;i++) if(list[i][0]===id) return list[i][list[i].length-1];
    return '';
  }
})();
</script>

<script>
document.querySelectorAll('details.form-panel').forEach(d=>{
  d.addEventListener('toggle',()=>{ if(d.open) document.querySelectorAll('details.form-panel').forEach(o=>{ if(o!==d) o.open=false; }); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
