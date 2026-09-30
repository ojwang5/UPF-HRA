<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/mailer.php';
$user = require_min_rank('post_commander');
$page = 'reports';
$page_title = 'Reports';
$pdo = db();

$date = $_GET['date'] ?? date('Y-m-d');
$mode = get_setting('report_submission_mode', 'hierarchical');
$isDirect = $mode === 'direct';
$repStatusInTray = "r.status IN ('submitted','reverted','pending_superadmin','pending_commander')";

/* ── Helpers ── */
function rep_status_pill(string $s): string {
    [$l, $c] = match($s) {
        'submitted'          => ['In Review', 'badge-on_course'],
        'reverted'           => ['Returned for Correction', 'badge-awol'],
        'approved'           => ['Approved', 'badge-present'],
        'pending_superadmin' => ['Pending — HQ', 'badge-leave_approved'],
        'pending_commander'  => ['Pending — Commander', 'badge-leave_approved'],
        'rejected'           => ['Rejected', 'badge-awol'],
        default              => [ucfirst($s) ?: '—', 'badge'],
    };
    return '<span class="badge '.$c.'">'.$l.'</span>';
}
function rep_level_chip(?string $l): string {
    $label = REPORT_LEVEL_LABELS[$l] ?? '—';
    return '<span class="report-chip chip-'.e($l ?? 'na').'">'.e($label).'</span>';
}
function lock_report(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM reports WHERE id=?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/* ── Action handling ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $notes  = trim($_POST['notes'] ?? '');
    $ok     = false;

    if ($action === 'generate') {
        $rows   = hierarchy_summary($pdo, $date, $user);
        $scope  = report_scope_for_user($user);
        $rId = $user['region_id']   ? (int)$user['region_id']   : null;
        $dId = $user['division_id'] ? (int)$user['division_id'] : null;
        $sId = $user['station_id']  ? (int)$user['station_id']  : null;
        $pId = $user['post_id']     ? (int)$user['post_id']     : null;
        if ($scope === 'hq') {
            $current = 'hq';
            $status  = 'approved';
        } else {
            $current = $isDirect ? 'hq' : report_level_next($scope);
            $status  = 'submitted';
        }
        $dirId = (int)($user['directorate_id'] ?? 0) ?: null;
        $untId = (int)($user['unit_id'] ?? 0) ?: null;
        $pdo->prepare("INSERT INTO reports (post_id,station_id,division_id,region_id,directorate_id,unit_id,scope_level,date,generated_by,generated_at,summary_json,status,current_level,revision)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)")
            ->execute([$pId,$sId,$dId,$rId,$dirId,$untId,$scope,$date,$user['id'],date('c'),json_encode($rows),$status,$current]);
        $rid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO report_actions (report_id,action,from_level,to_level,user_id,notes) VALUES (?,?,?,?,?,?)")
            ->execute([$rid,'submit',$scope,$current,$user['id'],'Report generated']);
        $unitLabel = report_unit_label($pdo, ['scope_level'=>$scope,'directorate_id'=>$dirId,'unit_id'=>$untId,'region_id'=>$rId,'division_id'=>$dId,'station_id'=>$sId,'post_id'=>$pId]);
        $dayLabel  = date('j F Y', strtotime($date));
        if ($status === 'submitted') {
            if ($current === 'hq') {
                notify_superadmins('New report for review', $unitLabel.' — '.$dayLabel.' · '.ucfirst($scope).' level', ['kind'=>'report','link'=>'/reports.php']);
            } else {
                notify_report_level($pdo, $current, ['scope_level'=>$scope,'region_id'=>$rId,'division_id'=>$dId,'station_id'=>$sId,'post_id'=>$pId],
                    'New report for review', $unitLabel.' — '.$dayLabel, ['kind'=>'report','link'=>'/reports.php']);
            }
        }
        log_activity('Generated report', 'report', $unitLabel, $rid, $dayLabel);
        flash('msg', $status === 'approved' ? 'Report saved (force level).' : 'Report submitted — now awaiting review at '.REPORT_LEVEL_LABELS[$current].'.');
        $ok = true;
    }

    elseif ($id && in_array($action, ['forward','revert','approve'], true)) {
        $rep = lock_report($pdo, $id);
        if ($rep && can_act_on_report($user, $rep)) {
            if ($action === 'forward') {
                if (($rep['current_level'] ?? '') === 'hq') {
                    flash('err', 'This report is at HQ — it must be approved, not forwarded.');
                } else {
                    $from = $rep['current_level'];
                    $resubmit = ($from === ($rep['scope_level'] ?? 'post'));
                    $to   = $isDirect ? 'hq' : report_level_next($from);
                    $summary = $rep['summary_json'];
                    if ($resubmit) { // re-generate the summary so corrections are reflected
                        $gen = $pdo->query("SELECT * FROM users WHERE id=".(int)$rep['generated_by'])->fetch();
                        if ($gen) $summary = json_encode(hierarchy_summary($pdo, $rep['date'], $gen));
                    }
                    $revision = $resubmit ? ((int)$rep['revision'] + 1) : (int)$rep['revision'];
                    $pdo->prepare("UPDATE reports SET current_level=?, status='submitted', summary_json=?, revision=?, reviewed_by=?, reviewed_at=?, review_notes=? WHERE id=?")
                        ->execute([$to, $summary, $revision, $user['id'], date('c'), $notes ?: null, $id]);
                    $pdo->prepare("INSERT INTO report_actions (report_id,action,from_level,to_level,user_id,notes) VALUES (?,?,?,?,?,?)")
                        ->execute([$id, $resubmit ? 'resubmit' : 'forward', $from, $to, $user['id'], $notes ?: null]);
                    $reportArr = $rep; $reportArr['current_level'] = $to;
                    if ($to === 'hq') {
                        notify_superadmins('Report forwarded to HQ', report_unit_label($pdo, $rep).' — '.date('j F Y',strtotime($rep['date'])), ['kind'=>'report','link'=>'/reports.php']);
                    } else {
                        notify_report_level($pdo, $to, $rep, 'Report awaiting your review', report_unit_label($pdo, $rep).' — '.date('j F Y',strtotime($rep['date'])), ['kind'=>'report','link'=>'/reports.php']);
                    }
                    flash('msg', 'Report '.($resubmit ? 're-submitted after correction' : 'forwarded').' to '.REPORT_LEVEL_LABELS[$to].'.');
                    $ok = true;
                }
            }
            elseif ($action === 'revert') {
                $from = $rep['current_level'];
                $to   = $rep['scope_level'] ?? 'post';
                $pdo->prepare("UPDATE reports SET current_level=?, status='reverted', reviewed_by=?, reviewed_at=?, review_notes=? WHERE id=?")
                    ->execute([$to, $user['id'], date('c'), $notes ?: null, $id]);
                $pdo->prepare("INSERT INTO report_actions (report_id,action,from_level,to_level,user_id,notes) VALUES (?,?,?,?,?,?)")
                    ->execute([$id, 'revert', $from, $to, $user['id'], $notes ?: null]);
                notify('Report returned for correction', report_unit_label($pdo, $rep).' — '.date('j F Y',strtotime($rep['date'])), 'user',
                    ['target_user_id'=>(int)$rep['generated_by'], 'kind'=>'report', 'link'=>'/reports.php']);
                flash('msg', 'Report returned to the originator for correction.');
                $ok = true;
            }
            elseif ($action === 'approve') {
                $pdo->prepare("UPDATE reports SET status='approved', reviewed_by=?, reviewed_at=?, review_notes=?, current_level='hq' WHERE id=?")
                    ->execute([$user['id'], date('c'), $notes ?: null, $id]);
                $pdo->prepare("INSERT INTO report_actions (report_id,action,from_level,to_level,user_id,notes) VALUES (?,?,?,?,?,?)")
                    ->execute([$id, 'approve', $rep['current_level'], null, $user['id'], $notes ?: null]);
                notify('Report approved', report_unit_label($pdo, $rep).' — '.date('j F Y',strtotime($rep['date'])).' approved.', 'user',
                    ['target_user_id'=>(int)$rep['generated_by'], 'kind'=>'report', 'link'=>'/reports.php']);
                flash('msg', 'Report approved.');
                $ok = true;
            }
        } else {
            flash('err', 'You no longer have permission to act on that report.');
        }
    }

    if ($ok) header('Location:/reports.php?date='.urlencode($date));
    else header('Location:/reports.php?date='.urlencode($date));
    exit;
}

/* ── Current-day live summary ── */
$rows = hierarchy_summary($pdo, $date, $user);
$tot  = sum_totals($rows);

/* ── In-tray: reports pending at MY level, within my scope ── */
$tray = [];
$myLevel = report_level_for_user($user);
if ($myLevel) {
    $w = "r.current_level='{$myLevel}' AND $repStatusInTray";
    if (!is_superadmin($user)) {
        $w .= match($myLevel) {
            'region'   => " AND r.region_id=".(int)$user['region_id'],
            'division' => " AND r.division_id=".(int)$user['division_id'],
            'station'  => " AND r.station_id=".(int)$user['station_id'],
            'post'     => " AND r.post_id=".(int)$user['post_id'],
            default    => '',
        };
    }
    $tray = $pdo->query("SELECT r.*, u.full_name AS gen_name
                         FROM reports r LEFT JOIN users u ON u.id=r.generated_by
                         WHERE $w ORDER BY r.date DESC, r.id DESC LIMIT 100")->fetchAll();
}

/* ── My-command reports (all in scope) with search ── */
$search = trim($_GET['q'] ?? '');
[$scpW, $scpP] = scope_where_for($user, 'r');
$myW  = $scpW;
$myP  = $scpP;
if ($search !== '') {
    $myW .= ' AND (r.date LIKE ? OR r.status LIKE ? OR r.current_level LIKE ? OR u.full_name LIKE ? OR pt.name LIKE ? OR st.name LIKE ? OR dv.name LIKE ? OR rg.name LIKE ?)';
    $q = "%$search%";
    array_push($myP, $q, $q, $q, $q, $q, $q, $q, $q);
}
$stmt = $pdo->prepare("SELECT r.*, u.full_name AS gen_name,
                             pt.name AS post_unit, st.name AS station_unit, dv.name AS division_unit, rg.name AS region_unit
                      FROM reports r
                      LEFT JOIN users u ON u.id=r.generated_by
                      LEFT JOIN posts   pt ON pt.id=r.post_id
                      LEFT JOIN stations st ON st.id=r.station_id
                      LEFT JOIN divisions dv ON dv.id=r.division_id
                      LEFT JOIN regions rg ON rg.id=r.region_id
                      WHERE $myW
                      ORDER BY r.date DESC, r.id DESC LIMIT 200");
$stmt->execute($myP);
$myReports = $stmt->fetchAll();

/* ── View mode ── */
$viewId  = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewing = null;
if ($viewId) $viewing = lock_report($pdo, $viewId);
if ($viewing) {
    // gate: only if in scope
    $g = $pdo->prepare("SELECT 1 FROM reports r WHERE r.id=? AND $scpW");
    $g->execute(array_merge([$viewId], $scpP));
    if (!$g->fetch()) $viewing = null;
}
$viewActions = [];
if ($viewing) {
    $viewActions = $pdo->query("SELECT * FROM report_actions WHERE report_id=".(int)$viewing['id']." ORDER BY id DESC")->fetchAll();
}
$viewSummary = $viewing ? (json_decode($viewing['summary_json'] ?? '[]', true) ?: []) : [];

include __DIR__ . '/../includes/header.php';
?>
<style>
  .report-chip{padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
  .chip-hq{background:var(--gold-soft,#fce8b3);color:#8a6500}
  .chip-region{background:#e3e8ff;color:#3b4ea1}
  .chip-division{background:#e6f0ff;color:#1d5fad}
  .chip-station{background:#e7f6f1;color:#0f7a5a}
  .chip-post{background:#f2f2f2;color:#555}
  .chip-forced,.chip-na{background:#eee;color:#777}
  .flow-dots{display:inline-flex;align-items:center;gap:2px;white-space:nowrap}
  .flow-dots i{width:8px;height:8px;border-radius:50%;background:var(--border,#d7dde5);display:inline-block}
  .flow-dots i.on{background:#059669}
  .flow-dots i.half{background:linear-gradient(90deg,#059669 50%,#d7dde5 50%)}
</style>
<div class="page-header">
  <div>
    <h1>Reports</h1>
    <div class="desc">Attendance summary for <?= e(date('j F Y',strtotime($date))) ?> · <?= e(user_scope_label($user)) ?>
      · <span class="report-chip chip-<?= $isDirect ? 'region' : 'division' ?>"><?= $isDirect ? 'Direct to HQ (ON)' : 'Hierarchical flow (post → station → division → region → HQ)' ?></span></div>
  </div>
  <div class="action-bar">
    <details class="form-panel">
      <summary><button type="button" class="btn-icon bi-secondary" title="Select date"><?= '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' ?></button></summary>
      <div class="form-body" style="padding:12px 16px">
        <form method="get" style="display:flex;gap:8px;align-items:flex-end">
          <div class="form-group"><label>Date</label><input type="date" name="date" value="<?= e($date) ?>"></div>
          <button class="btn-icon bi-primary" type="submit" title="Go"><?= ICO_SAVE ?></button>
        </form>
      </div>
    </details>
    <form method="post" style="display:contents">
      <input type="hidden" name="action" value="generate">
      <button type="submit" class="btn-icon bi-primary" title="<?= is_superadmin($user) ? 'Save report' : 'Submit report for review' ?>"><?= ICO_GEN ?></button>
    </form>
    <a class="btn-icon bi-secondary" href="/export.php?type=csv&date=<?= e($date) ?>" title="Export CSV" target="_blank"><?= ICO_DL ?></a>
    <a class="btn-icon bi-secondary" href="/export.php?type=print&date=<?= e($date) ?>" title="Print / PDF" target="_blank"><?= ICO_PRINT ?></a>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<?php if (!is_superadmin($user)): ?>
<div class="alert" style="background:var(--navy-50);border:1px solid var(--border);color:var(--muted)">
  <?= ICO_GEN ?> Reports flow up the command chain <?= $isDirect ? 'directly to HQ' : 'from post → station → division → region → HQ' ?>. Each reviewer may forward with comments or return for correction.
</div>
<?php endif; ?>

<?php if ($viewing): ?>
<!-- ═══ REPORT DETAIL ═══ -->
<div class="card" style="border-left:4px solid var(--primary);margin-bottom:16px">
  <div class="chr">
    <div>
      <h3 style="margin:0 0 3px">Report — <?= e(date('j F Y',strtotime($viewing['date']))) ?> · <?= e(report_unit_label($pdo,$viewing)) ?></h3>
      <div style="font-size:12px;color:var(--muted);display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?= rep_status_pill($viewing['status']) ?> <?= rep_level_chip($viewing['current_level']) ?>
        <span>Generated by <?= e($viewing['gen_name'] ?? '—') ?> · <?= e(date('d M Y H:i', strtotime($viewing['generated_at']))) ?></span>
        <span>Revision <?= (int)$viewing['revision'] ?></span>
      </div>
    </div>
    <a class="btn-icon bi-secondary bi-sm" href="/reports.php?date=<?= e($date) ?>#myreports" title="Close"><?= ICO_CANCEL ?></a>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:16px">
    <?php foreach ([
      'Origin Unit'=>report_unit_label($pdo,$viewing),'Scope'=>ucfirst((string)($viewing['scope_level']??'post')),
      'Generated By'=>$viewing['gen_name']??'—','Reviewed By'=>$viewing['reviewed_by']?e($pdo->query("SELECT full_name FROM users WHERE id=".(int)$viewing['reviewed_by'])->fetchColumn()):'—',
      'Review Notes'=>$viewing['review_notes'] ?? '—','Reviewed At'=>$viewing['reviewed_at']?e(date('d M Y H:i',strtotime($viewing['reviewed_at']))):'—',
    ] as $lbl=>$val): ?>
    <div style="padding:10px 12px;background:var(--navy-50);border-radius:8px">
      <div style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:2px"><?= $lbl ?></div>
      <div style="font-weight:600;color:var(--navy-800);font-size:13px"><?= e($val) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="table-wrap" style="margin-bottom:16px">
    <table>
      <thead><tr><th>Unit</th><th>Total</th><th>Present</th><th>AWOL</th><th>Leave</th><th>Sick</th><th>Suspended</th><th>Disciplinary</th><th>On Duty</th><th>On Course</th><th>Unrecorded</th></tr></thead>
      <tbody>
        <?php foreach ($viewSummary as $vu): ?>
        <tr><td><?= e($vu['unit_name']??'') ?></td><td><?= (int)($vu['total']??0) ?></td><td><?= (int)($vu['present']??0) ?></td>
          <td><?= (int)($vu['awol']??0) ?></td><td><?= (int)($vu['on_leave']??0) ?></td><td><?= (int)($vu['sick']??0) ?></td>
          <td><?= (int)($vu['suspended']??0) ?></td><td><?= (int)($vu['disciplinary']??0) ?></td>
          <td><?= (int)($vu['on_duty']??0) ?></td><td><?= (int)($vu['on_course']??0) ?></td><td><?= (int)($vu['unrecorded']??0) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$viewSummary): ?><tr><td colspan="11" style="text-align:center;color:var(--muted);padding:18px">No summary rows.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($viewActions): ?>
  <h4 style="font-size:12px;color:var(--navy-800);margin:0 0 8px">Workflow trail</h4>
  <div class="table-wrap">
    <table>
      <thead><tr><th>When</th><th>Action</th><th>From</th><th>To</th><th>By</th><th>Comments</th></tr></thead>
      <tbody>
        <?php foreach ($viewActions as $ac):
          $actor = $ac['user_id'] ? ($pdo->query("SELECT full_name FROM users WHERE id=".(int)$ac['user_id'])->fetchColumn() ?: '—') : '—';
        ?>
        <tr>
          <td style="white-space:nowrap;font-size:12px"><?= e(date('d M Y H:i',strtotime($ac['created_at']))) ?></td>
          <td><span class="report-chip" style="text-transform:capitalize;background:#eef2f7"><?= e($ac['action']) ?></span></td>
          <td><?= $ac['from_level'] ? rep_level_chip($ac['from_level']) : '—' ?></td>
          <td><?= $ac['to_level']  ? rep_level_chip($ac['to_level'])  : '—' ?></td>
          <td style="font-size:12px"><?= e($actor) ?></td>
          <td style="font-size:12px;max-width:200px"><?= e($ac['notes'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ═══ LIVE SUMMARY ═══ -->
<div class="card">
  <div class="chr"><h3>Attendance Report — <?= e(date('j F Y',strtotime($date))) ?></h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Unit</th><th>Total</th><th>Present</th><th>AWOL</th><th>Leave</th><th>Sick</th><th>Suspended</th><th>Disciplinary</th><th>On Duty</th><th>On Course</th><th>Unrecorded</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr><td><?= e($r['unit_name']) ?></td><td><?= $r['total'] ?></td><td><?= $r['present'] ?></td>
          <td><?= $r['awol'] ?></td><td><?= $r['on_leave'] ?></td><td><?= $r['sick'] ?></td>
          <td><?= $r['suspended'] ?></td><td><?= $r['disciplinary'] ?></td>
          <td><?= $r['on_duty'] ?></td><td><?= $r['on_course'] ?></td><td><?= $r['unrecorded'] ?></td></tr>
        <?php endforeach; ?>
        <tr style="font-weight:700;background:var(--navy-50)">
          <td>TOTAL</td><td><?= $tot['total'] ?></td><td><?= $tot['present'] ?></td>
          <td><?= $tot['awol'] ?></td><td><?= $tot['on_leave'] ?></td><td><?= $tot['sick'] ?></td>
          <td><?= $tot['suspended'] ?></td><td><?= $tot['disciplinary'] ?></td>
          <td><?= $tot['on_duty'] ?></td><td><?= $tot['on_course'] ?></td><td><?= $tot['unrecorded'] ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ IN-TRAY ═══ -->
<div class="card" style="border-left:4px solid #059669">
  <div class="chr">
    <h3>Reports Requiring My Action <?php if ($myLevel): ?><span class="badge badge-on_course"><?= count($tray) ?></span><?php endif; ?></h3>
    <div class="desc" style="margin-right:auto;font-size:12px"><?= $myLevel ? 'Reviewing at '.e(REPORT_LEVEL_LABELS[$myLevel]).' level' : 'Only command roles review reports' ?></div>
  </div>
  <?php if (!$myLevel || !$tray): ?>
  <p style="color:var(--muted);font-size:13px;margin:0;padding:12px 0">No reports pending your action. Reports flow: post → station → division → region → HQ.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Origin Unit</th><th>Scope</th><th>Generated By</th><th>Status</th><th>Current Level</th><th style="width:250px">Action</th></tr></thead>
      <tbody>
        <?php foreach ($tray as $r):
          $ready = in_array($r['status'], ['submitted','pending_superadmin','pending_commander'], true) && $r['current_level'] !== ($r['scope_level'] ?? 'post');
          $isReverted = $r['status'] === 'reverted' || $r['current_level'] === ($r['scope_level'] ?? 'post');
        ?>
        <tr>
          <td style="white-space:nowrap;font-size:12px"><?= e(date('j M y', strtotime($r['date']))) ?></td>
          <td><strong><?= e(report_unit_label($pdo,$r)) ?></strong></td>
          <td><span class="report-chip chip-<?= e($r['scope_level'] ?? 'post') ?>"><?= e(ucfirst((string)($r['scope_level'] ?? 'post'))) ?></span></td>
          <td style="font-size:12px"><?= e($r['gen_name'] ?? '—') ?></td>
          <td><?= rep_status_pill($r['status']) ?></td>
          <td><?= rep_level_chip($r['current_level']) ?></td>
          <td style="white-space:nowrap">
            <?php if ($isReverted && $ready === false): ?>
              <a class="btn-icon bi-amber bi-sm" href="/reports.php?view=<?= (int)$r['id'] ?>#myreports" title="Report returned for correction"><?= ICO_EYE ?></a>
            <?php endif; ?>
            <a class="btn-icon bi-secondary bi-sm" href="/reports.php?view=<?= (int)$r['id'] ?>" title="View report details"><?= ICO_EYE ?></a>
            <details class="form-panel" style="position:relative;display:inline-block">
              <summary><button type="button" class="btn-icon bi-gold bi-sm" title="Take action"><?= $r['status']==='approved' ? ICO_OK : ICO_SEND ?></button></summary>
              <div class="form-body" style="position:absolute;right:0;top:100%;min-width:320px;z-index:20;padding:14px;box-shadow:var(--shadow-md)">
                <form method="post" style="display:flex;flex-direction:column;gap:8px">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <?php if ($isReverted): ?>
                  <div class="alert" style="background:#fff4e5;border:1px solid #f0d9a8;border-radius:8px;padding:8px 10px;font-size:12px"><strong>Returned for correction</strong><?= $r['review_notes'] ? '<br>Note: '.e($r['review_notes']) : '' ?><br>Correct the figures then re-submit.</div>
                  <?php elseif ($r['current_level']==='hq' && is_superadmin($user)): ?>
                  <div class="alert" style="background:var(--navy-50);border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:12px">Report awaiting final approval.</div>
                  <?php elseif ($r['status']==='approved'): ?>
                  <div class="alert" style="background:var(--navy-50);border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:12px">Report already approved.</div>
                  <?php else: ?>
                  <div style="font-size:12px;color:var(--muted)">Review <?= e(report_unit_label($pdo,$r)) ?> · <?= e(date('j M Y',strtotime($r['date']))) ?>. You may forward with comments or return it for correction.</div>
                  <?php endif; ?>
                  <textarea name="notes" rows="2" placeholder="Comments / correction instructions (optional)" style="resize:vertical"></textarea>
                  <div class="action-bar" style="justify-content:flex-end;gap:6px">
                    <button class="btn-icon bi-danger bi-sm" name="action" value="revert" title="Return for correction"><?= ICO_REJECT ?> <span style="font-size:11px;margin-left:4px">Revert</span></button>
                    <?php if ($r['current_level']==='hq' && is_superadmin($user)): ?>
                      <button class="btn-icon bi-green bi-sm" name="action" value="approve" title="Approve report"><?= ICO_OK ?> <span style="font-size:11px;margin-left:4px">Approve</span></button>
                    <?php else: ?>
                      <button class="btn-icon bi-primary bi-sm" name="action" value="forward" title="<?= $isReverted ? 'Re-submit after correction' : 'Continue & forward' ?>"><?= ICO_SEND ?> <span style="font-size:11px;margin-left:4px"><?= $isReverted ? 'Re-submit' : 'Forward' ?></span></button>
                    <?php endif; ?>
                  </div>
                </form>
              </div>
            </details>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ═══ MY COMMAND REPORTS ═══ -->
<div class="card" id="myreports">
  <div class="chr">
    <h3>Reports in My Command <span class="badge badge-admin"><?= count($myReports) ?></span></h3>
    <form method="get" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="date" value="<?= e($date) ?>">
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search unit, date, status…" style="min-width:220px" data-report-search>
      <button class="btn-icon bi-secondary bi-sm" type="submit" title="Search"><?= ICO_SEARCH ?></button>
      <?php if ($search): ?><a class="btn-icon bi-secondary bi-sm" href="/reports.php?date=<?= e($date) ?>" title="Clear"><?= ICO_CANCEL ?></a><?php endif; ?>
    </form>
  </div>
  <?php if ($search): ?><div class="alert" style="background:var(--navy-50);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:12px;margin-bottom:10px">Search: <strong><?= e($search) ?></strong> — <?= count($myReports) ?> result(s)</div><?php endif; ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Origin Unit</th><th>Scope</th><th>Generated By</th><th>Status</th><th>Position</th><th>Reviewed By</th><th>Notes</th><th style="width:45px"></th></tr></thead>
      <tbody>
        <?php foreach ($myReports as $r):
          $posKey = $r['status']==='approved' ? 4 : (REPORT_LEVELS[$r['current_level'] ?? 'post'] ?? 0);
          $posIdx = $posKey; ?>
        <tr>
          <td style="white-space:nowrap;font-size:12px"><?= e(date('j M y', strtotime($r['date']))) ?></td>
          <td><strong><?= e(report_unit_label($pdo,$r)) ?></strong><?= $r['current_level']!==null ? '<br><span class="flow-dots" title="Command chain progress">'.str_repeat('<i class="on"></i>', max(0,min(4,$posIdx))).str_repeat('<i></i>', 4 - max(0,min(4,$posIdx))).'</span>' : '' ?></td>
          <td><span class="report-chip chip-<?= e($r['scope_level'] ?? 'post') ?>"><?= e(ucfirst((string)($r['scope_level'] ?? 'post'))) ?></span></td>
          <td style="font-size:12px"><?= e($r['gen_name'] ?? '—') ?></td>
          <td><?= rep_status_pill($r['status']) ?></td>
          <td><?= rep_level_chip($r['current_level']) ?></td>
          <td style="font-size:12px"><?= $r['reviewed_by'] ? e($pdo->query("SELECT full_name FROM users WHERE id=".(int)$r['reviewed_by'])->fetchColumn() ?: '—') : '—' ?></td>
          <td style="font-size:12px;max-width:160px"><?= e(($r['review_notes'] ?? '') ?: '—') ?></td>
          <td><a class="btn-icon bi-secondary bi-sm" href="/reports.php?view=<?= (int)$r['id'] ?>" title="View report details"><?= ICO_EYE ?></a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$myReports): ?><tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">No reports<?= $search ? ' matching "'.e($search).'"' : '' ?>. Click the generate button to submit today's report.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.querySelectorAll('details.form-panel').forEach(d=>{
  d.addEventListener('toggle',()=>{ if(d.open) document.querySelectorAll('details.form-panel').forEach(o=>{ if(o!==d) o.open=false; }); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>