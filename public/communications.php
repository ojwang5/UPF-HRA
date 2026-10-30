<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/communications.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/sms.php';
$user = require_login();
$page = 'communications';
$page_title = 'Communications';
$pdo = db();
$isSignal = is_signal_user($user);
[$scopeW, $scopeP] = scope_where($user, 'e');

/* ─────────────────────────── POST actions ─────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* Inbound message (Signals only) */
    if ($action === 'inbound_create') {
        if (!$isSignal) {
            flash('err', 'Only the Signals directorate/unit can dispatch inbound messages.');
            header('Location: /communications.php'); exit;
        }
        $title      = trim($_POST['title'] ?? '');
        $body       = trim($_POST['body'] ?? '');
        $targetType = $_POST['target_type'] ?? 'all';
        $targetId   = (int)($_POST['target_id'] ?? 0);
        $targetRole = trim($_POST['target_role'] ?? '');
        $priority   = in_array($_POST['priority'] ?? '', ['normal','high','urgent'], true) ? $_POST['priority'] : 'normal';

        $validTypes = ['all','region','division','station','post','directorate','unit','role','user'];
        if (!in_array($targetType, $validTypes, true)) $targetType = 'all';

        $typeNames = [
            'region'      => 'region',
            'division'    => 'division',
            'station'     => 'station',
            'post'        => 'post',
            'directorate' => 'directorate',
            'unit'        => 'unit',
            'role'        => 'role',
            'user'        => 'user',
        ];

        $error = '';
        if ($title === '' || $body === '') {
            $error = 'Title and message body are required.';
        } elseif ($targetType === 'role' && !isset(ROLE_LABELS[$targetRole])) {
            $error = 'Choose the role this message should go to.';
        } elseif (!comm_target_valid($pdo, $targetType, $targetId ?: null, $targetType === 'role' ? $targetRole : null)) {
            $error = isset($typeNames[$targetType])
                ? 'Choose the specific ' . $typeNames[$targetType] . ' this message should go to.'
                : 'Choose a valid destination for this message.';
        }

        if ($error !== '') {
            flash('err', $error);
            header('Location: /communications.php?tab=compose'); exit;
        }

        $pdo->prepare("INSERT INTO comm_messages (sender_id,title,body,target_type,target_id,target_role,target_label,priority)
                       VALUES (?,?,?,?,?,?,?,?)")
            ->execute([
                $user['id'], $title, $body, $targetType,
                in_array($targetType, ['all','role'], true) ? null : $targetId,
                $targetType === 'role' ? $targetRole : null,
                comm_target_label($pdo, $targetType, $targetId ?: null, $targetType === 'role' ? $targetRole : null),
                $priority,
            ]);
        $msgId = (int)$pdo->lastInsertId();
        $uploadErrors = save_comm_uploads($msgId, 'attachments', $pdo);
        log_activity('Dispatched inbound message', 'communication', $title, $msgId, comm_target_label($pdo, $targetType, $targetId ?: null, $targetType === 'role' ? $targetRole : null));

        if ($uploadErrors) {
            flash('err', 'Message sent, but some attachments failed: ' . implode(' ', $uploadErrors));
        } else {
            flash('msg', 'Inbound message dispatched to ' . comm_target_label($pdo, $targetType, $targetId ?: null, $targetType === 'role' ? $targetRole : null) . '.');
        }
        header('Location: /communications.php?tab=log'); exit;
    }

    /* Mark every visible inbound message as read */
    if ($action === 'inbox_mark_all') {
        mark_all_comm_read($user);
        flash('msg', 'Inbox marked as read.');
        header('Location: /communications.php'); exit;
    }

    /* Outbound SMS / email (Signals only) */
    if ($action === 'outbound_send') {
        if (!$isSignal) {
            flash('err', 'Only the Signals directorate/unit can send SMS or email.');
            header('Location: /communications.php'); exit;
        }
        $subject   = trim($_POST['subject'] ?? '');
        $body      = trim($_POST['body']    ?? '');
        $recipType = $_POST['recipient_type'] ?? 'all';
        $recipId   = (int)($_POST['recipient_id'] ?? 0) ?: null;
        $channel   = ($_POST['channel'] ?? 'email') === 'sms' ? 'sms' : 'email';

        if (!$subject || !$body) {
            flash('err', 'Subject and message body are required.');
            header('Location: /communications.php?tab=outbound'); exit;
        }

        $recipLabel = match($recipType) {
            'all'         => 'All Personnel',
            'region'      => comm_target_label($pdo, 'region', $recipId),
            'division'    => comm_target_label($pdo, 'division', $recipId),
            'station'     => comm_target_label($pdo, 'station', $recipId),
            'post'        => comm_target_label($pdo, 'post', $recipId),
            'directorate' => comm_target_label($pdo, 'directorate', $recipId),
            'unit'        => comm_target_label($pdo, 'unit', $recipId),
            'employee'    => ($_e = $pdo->query("SELECT service_no, rank, full_name FROM employees WHERE id=" . (int)$recipId)->fetch()) ? personnel_label($_e) : 'Individual',
            default       => 'Custom',
        };

        $empWhere = "e.active=1 AND $scopeW";
        $empParams = $scopeP;
        if ($channel === 'email') $empWhere .= " AND e.email IS NOT NULL AND e.email != ''";
        if ($channel === 'sms')   $empWhere .= " AND e.phone IS NOT NULL AND e.phone != ''";

        switch ($recipType) {
            case 'region':      $empWhere .= " AND e.region_id=?";   $empParams[] = $recipId; break;
            case 'division':    $empWhere .= " AND e.division_id=?"; $empParams[] = $recipId; break;
            case 'station':     $empWhere .= " AND e.station_id=?";  $empParams[] = $recipId; break;
            case 'post':        $empWhere .= " AND e.post_id=?";     $empParams[] = $recipId; break;
            case 'employee':    $empWhere .= " AND e.id=?";          $empParams[] = $recipId; break;
            case 'directorate':
                $name = $pdo->prepare("SELECT name FROM directorates WHERE id=?");
                $name->execute([$recipId]);
                $empWhere .= " AND e.directorate=?"; $empParams[] = (string)$name->fetchColumn();
                break;
            case 'unit':
                $name = $pdo->prepare("SELECT name FROM units WHERE id=?");
                $name->execute([$recipId]);
                $empWhere .= " AND e.unit=?"; $empParams[] = (string)$name->fetchColumn();
                break;
        }
        $stmt = $pdo->prepare("SELECT id, service_no, full_name, rank, email, phone FROM employees e WHERE $empWhere");
        $stmt->execute($empParams);
        $recipients = $stmt->fetchAll();

        $pdo->prepare("INSERT INTO communications (subject,body,sender_id,recipient_type,recipient_id,recipient_label,channel,sent_at,status,total_recipients) VALUES (?,?,?,?,?,?,?,datetime('now'),?,?)")
            ->execute([$subject, $body, $user['id'], $recipType, $recipId, $recipLabel, $channel, 'pending', count($recipients)]);
        $commId = (int)$pdo->lastInsertId();

        if (empty($recipients)) {
            $pdo->prepare("UPDATE communications SET status='failed', error_log=? WHERE id=?")->execute(['No recipients found with contact details.', $commId]);
            flash('err', 'No recipients found with ' . ($channel === 'email' ? 'email addresses' : 'phone numbers') . ' in the selected scope.');
            header('Location: /communications.php?tab=outbound'); exit;
        }

        if ($channel === 'email') {
            if (!smtp_configured()) {
                $pdo->prepare("UPDATE communications SET status='failed', error_log='SMTP not configured' WHERE id=?")->execute([$commId]);
                flash('err', 'SMTP is not configured. Go to Settings to set up email credentials.');
                header('Location: /communications.php?tab=outbound'); exit;
            }
            send_communication($commId, $recipients, $pdo);
        } else {
            if (!sms_configured()) {
                $pdo->prepare("UPDATE communications SET status='failed', error_log='SMS not configured' WHERE id=?")->execute([$commId]);
                flash('err', 'SMS is not configured. Go to Settings to set up SMS credentials.');
                header('Location: /communications.php?tab=outbound'); exit;
            }
            $delivered = 0; $failed = 0; $errors = [];
            foreach ($recipients as $emp) {
                $res = send_sms($emp['phone'], $subject . "\n\n" . $body);
                if ($res['ok']) $delivered++; else { $failed++; $errors[] = personnel_label($emp) . ': ' . $res['error']; }
            }
            $status = ($failed === 0) ? 'sent' : ($delivered === 0 ? 'failed' : 'partial');
            $pdo->prepare("UPDATE communications SET status=?, delivered=?, failed=?, error_log=? WHERE id=?")
                ->execute([$status, $delivered, $failed, $errors ? implode("\n", $errors) : null, $commId]);
        }

        log_activity('Sent ' . strtoupper($channel) . ' communication', 'communication', $subject, $commId, $recipLabel);
        $comm = $pdo->query("SELECT * FROM communications WHERE id=$commId")->fetch();
        $msg = $comm['delivered'] . ' message(s) sent';
        if ($comm['failed'] > 0) $msg .= ', ' . $comm['failed'] . ' failed';
        $comm['status'] === 'sent' ? flash('msg', $msg . '.') : flash('err', $msg . '. Check the sent log for details.');
        header('Location: /communications.php?tab=history'); exit;
    }
}

/* ─────────────────────────── Message detail ─────────────────────────── */
$tab = $_GET['tab'] ?? 'inbox';
$viewId = (int)($_GET['view'] ?? 0);
$viewMessage = null;
$viewAttachments = [];
if ($viewId > 0) {
    $st = $pdo->prepare("SELECT m.*, u.full_name AS sender_name, u.role AS sender_role
                         FROM comm_messages m LEFT JOIN users u ON u.id=m.sender_id WHERE m.id=?");
    $st->execute([$viewId]);
    $candidate = $st->fetch();
    if ($candidate && comm_can_view_message($user, $candidate)) {
        $viewMessage = $candidate;
        $viewAttachments = comm_attachments($viewId);
        mark_comm_read($viewId, (int)$user['id']);
    }
}

/* ─────────────────────────── Data ─────────────────────────── */
$inbox = inbound_messages_for($user, 100, false, $tab === 'log' && $isSignal);
$dispatchLog = $isSignal ? inbound_messages_for($user, 200, false, true) : [];
foreach ($dispatchLog as &$logged) {
    $logged['audience_count'] = comm_target_user_count($pdo, $logged);
}
unset($logged);

$viewTracking = ($viewMessage && $isSignal) ? comm_message_tracking($pdo, $viewId, $viewMessage) : null;

$regions      = $pdo->query("SELECT id, name FROM regions ORDER BY name")->fetchAll();
$divisions    = $pdo->query("SELECT d.id, d.name, r.name AS region_name FROM divisions d JOIN regions r ON r.id=d.region_id ORDER BY r.name, d.name")->fetchAll();
$stations     = $pdo->query("SELECT s.id, s.name, d.name AS division_name FROM stations s JOIN divisions d ON d.id=s.division_id ORDER BY d.name, s.name")->fetchAll();
$posts        = $pdo->query("SELECT p.id, p.name, s.name AS station_name FROM posts p JOIN stations s ON s.id=p.station_id ORDER BY s.name, p.name")->fetchAll();
$directorates = $pdo->query("SELECT id, name FROM directorates WHERE active=1 ORDER BY name")->fetchAll();
$units        = $pdo->query("SELECT u.id, u.name, d.name AS directorate_name FROM units u JOIN directorates d ON d.id=u.directorate_id WHERE u.active=1 ORDER BY d.name, u.name")->fetchAll();
$allUsers     = $pdo->query("SELECT id, full_name, role FROM users ORDER BY full_name")->fetchAll();

$empStmt = $pdo->prepare("SELECT id, service_no, full_name, email, phone, rank FROM employees e WHERE e.active=1 AND $scopeW ORDER BY " . personnel_order_sql('e'));
$empStmt->execute($scopeP);
$employees = $empStmt->fetchAll();

$history = $isSignal
    ? $pdo->query("SELECT c.*, u.full_name AS sender_name FROM communications c LEFT JOIN users u ON u.id=c.sender_id ORDER BY c.id DESC LIMIT 100")->fetchAll()
    : [];

$tabs = ['inbox' => 'Inbox'];
if ($isSignal) {
    $tabs += [
        'compose'  => 'New Inbound',
        'outbound' => 'SMS / Email',
        'log'      => 'Dispatch Log',
        'history'  => 'Outbound History',
    ];
}
if (!isset($tabs[$tab])) $tab = 'inbox';

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Communications</h1>
    <div class="desc">
      <?= $isSignal ? 'Signals channel — dispatch internal messages and outbound SMS / email' : 'Messages dispatched to your unit, directorate or region by the Signals department' ?>
    </div>
  </div>
  <div class="action-bar">
    <?php if ($isSignal): ?>
      <span class="badge badge-present">Signals operator</span>
    <?php else: ?>
      <span class="badge badge-leave">Read only</span>
    <?php endif; ?>
    <?php if ($isSignal && !smtp_configured()): ?>
      <a href="/settings.php" class="btn-icon bi-secondary" style="gap:6px;padding:0 14px;width:auto;font-size:12px">Setup Email</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($m = flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid var(--border);overflow-x:auto">
  <?php foreach ($tabs as $t => $l): ?>
  <a href="?tab=<?= $t ?>" style="padding:10px 18px;font-size:13px;font-weight:600;white-space:nowrap;color:<?= $tab===$t?'var(--primary)':'var(--muted)' ?>;border-bottom:2px solid <?= $tab===$t?'var(--primary)':'transparent' ?>;margin-bottom:-2px;text-decoration:none">
    <?= e($l) ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($viewMessage): ?>
<!-- ══════════════════ MESSAGE DETAIL ══════════════════ -->
<div class="card" style="border-left:4px solid var(--primary);margin-bottom:18px">
  <div class="chr">
    <div>
      <h3 style="margin:0 0 4px"><?= e($viewMessage['title']) ?></h3>
      <div class="muted" style="font-size:12px">
        From <strong><?= e($viewMessage['sender_name'] ?? '—') ?></strong>
        · To <?= e($viewMessage['target_label'] ?? '—') ?>
        · <?= e(date('j M Y, H:i', strtotime($viewMessage['created_at']))) ?>
        · <span class="badge"><?= e(ucfirst($viewMessage['priority'])) ?></span>
      </div>
    </div>
    <a class="btn-icon bi-secondary bi-sm" href="/communications.php?tab=<?= e($tab) ?>" title="Close"><?= ICO_CANCEL ?></a>
  </div>
  <div style="font-size:14px;line-height:1.7;white-space:pre-wrap;padding:4px 0 14px"><?= e($viewMessage['body']) ?></div>
  <?php if ($viewAttachments): ?>
  <div style="border-top:1px solid var(--border);padding-top:12px">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);margin-bottom:8px">Attachments</div>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      <?php foreach ($viewAttachments as $a): ?>
      <a href="/comm-download.php?id=<?= (int)$a['id'] ?>" download="<?= e($a['file_name']) ?>" class="badge badge-leave" title="Download attachment" style="text-decoration:none;padding:6px 10px;font-size:12px">
        <?= $a['kind'] === 'image' ? '🖼' : ($a['kind'] === 'video' ? '🎞' : '📄') ?>
        <?= e($a['file_name']) ?> · <?= e(comm_size_label((int)$a['size_bytes'])) ?> · Download
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($viewTracking): ?>
  <div style="border-top:1px solid var(--border);padding-top:12px;margin-top:12px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted)">Delivery Tracking</div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <span class="badge badge-admin">Message #<?= (int)$viewMessage['id'] ?></span>
        <span class="badge <?= $viewTracking['read'] === $viewTracking['audience'] && $viewTracking['audience'] > 0 ? 'badge-present' : 'badge-sick' ?>">
          <?= (int)$viewTracking['read'] ?> of <?= (int)$viewTracking['audience'] ?> read
        </span>
      </div>
    </div>
    <details style="margin-top:10px">
      <summary style="cursor:pointer;font-size:12px;color:var(--primary);font-weight:600">View recipients</summary>
      <div class="table-wrap" style="max-height:260px;overflow:auto;margin-top:8px">
        <table>
          <thead><tr><th>Recipient</th><th>Role</th><th>Read</th></tr></thead>
          <tbody>
            <?php foreach ($viewTracking['recipients'] as $recipient): ?>
            <tr>
              <td><?= e($recipient['full_name']) ?></td>
              <td style="font-size:12px"><?= e(role_label((string)$recipient['role'])) ?></td>
              <td><?= !empty($recipient['read_at']) ? '<span class="badge badge-present">Read</span>' : '<span class="badge badge-leave">Unread</span>' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$viewTracking['recipients']): ?><tr><td colspan="3" class="muted" style="text-align:center;padding:16px">No recipients matched this destination.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </details>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'inbox' || $tab === 'log'): ?>
<!-- ══════════════════ INBOX / DISPATCH LOG ══════════════════ -->
<?php $listing = $tab === 'log' ? $dispatchLog : $inbox; ?>
<div class="card">
  <div class="chr">
    <h3><?= $tab === 'log' ? 'Dispatch Log' : 'Inbox' ?>
      <span class="badge badge-admin"><?= count($listing) ?></span>
      <?php if ($tab === 'inbox' && ($uc = count(array_filter($inbox, fn($m) => empty($m['read_at_user']))))): ?>
        <span class="badge badge-sick"><?= $uc ?> new</span>
      <?php endif; ?>
    </h3>
    <?php if ($tab === 'inbox'): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="inbox_mark_all">
      <button class="btn-icon bi-secondary bi-sm" type="submit" title="Mark all as read"><?= ICO_OK_ALL ?></button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!$listing): ?>
    <div style="text-align:center;color:var(--muted);padding:36px">No messages in this view.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <?php if ($tab === 'log'): ?>
          <th>#</th><th>Title</th><th>From</th><th>Destination</th><th>Priority</th><th>Read Tracking</th><th>Attachments</th><th>Dispatched</th><th style="width:40px"></th>
        <?php else: ?>
          <th>Status</th><th>Title</th><th>From</th><th>Destination</th><th>Priority</th><th>Attachments</th><th>Received</th><th style="width:40px"></th>
        <?php endif; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($listing as $m):
          $unread = empty($m['read_at_user']);
          $audience = (int)($m['audience_count'] ?? 0);
          $read = (int)($m['read_count'] ?? 0);
        ?>
        <tr class="<?= $tab === 'inbox' && $unread ? 'row-on_course' : '' ?>">
          <?php if ($tab === 'log'): ?>
            <td style="font-family:monospace;font-size:11px;color:var(--muted)">#<?= (int)$m['id'] ?></td>
          <?php else: ?>
            <td><?= $unread ? '<span class="badge badge-sick">New</span>' : '<span class="badge badge-admin">Read</span>' ?></td>
          <?php endif; ?>
          <td><a href="/communications.php?tab=<?= e($tab) ?>&view=<?= (int)$m['id'] ?>" style="font-weight:600;text-decoration:none"><?= e($m['title']) ?></a></td>
          <td style="font-size:12px"><?= e($m['sender_name'] ?? '—') ?></td>
          <td style="font-size:12px"><?= e($m['target_label'] ?? '—') ?></td>
          <td><span class="badge <?= $m['priority']==='urgent'?'badge-awol':($m['priority']==='high'?'badge-sick':'badge-admin') ?>"><?= e(ucfirst($m['priority'])) ?></span></td>
          <?php if ($tab === 'log'): ?>
            <td><span class="badge <?= $audience > 0 && $read >= $audience ? 'badge-present' : ($read > 0 ? 'badge-on_course' : 'badge-leave') ?>"><?= $read ?> / <?= $audience ?> read</span></td>
          <?php endif; ?>
          <td style="text-align:center;font-size:12px"><?= (int)$m['attachment_count'] ? '📎 ' . (int)$m['attachment_count'] : '—' ?></td>
          <td style="font-size:12px;white-space:nowrap;color:var(--muted)"><?= e(date('j M y, H:i', strtotime($m['created_at']))) ?></td>
          <td><a class="btn-icon bi-secondary bi-sm" href="/communications.php?tab=<?= e($tab) ?>&view=<?= (int)$m['id'] ?>" title="Open"><?= ICO_EYE ?></a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'compose'): ?>
<!-- ══════════════════ NEW INBOUND MESSAGE ══════════════════ -->
<div class="card">
  <div class="chr"><h3>Dispatch Internal Message</h3><span class="badge badge-present">Signals only</span></div>
  <form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="inbound_create">
    <div class="muted" style="font-size:12px;background:var(--navy-50);padding:9px 12px;border-radius:8px">
      Every dispatch is saved automatically with its destination, sender, timestamp and attachments. Open <strong>Dispatch Log</strong> to track delivery and read status.
    </div>

    <div class="form-row">
      <div class="form-group" style="flex:2"><label>Where should the message go? <span style="color:var(--red)">*</span></label>
        <select name="target_type" id="in-type" onchange="updateInboundTarget()">
          <option value="all">All personnel</option>
          <option value="region">A Region</option>
          <option value="division">A Division</option>
          <option value="station">A Station</option>
          <option value="post">A Post</option>
          <option value="directorate">A Directorate</option>
          <option value="unit">A Unit</option>
          <option value="role">A Role</option>
          <option value="user">A Specific User</option>
        </select>
      </div>
      <div class="form-group" id="in-target-wrap" style="display:none;flex:2">
        <label><span id="in-target-label">Destination</span> <span id="in-target-req" style="color:var(--red);display:none">*</span></label>
        <select name="target_id" id="in-target"><option value="">Choose a destination</option></select>
        <div class="muted" id="in-target-help" style="font-size:11px;margin-top:4px">Choose the exact region, division, station, post, directorate or unit.</div>
      </div>
      <div class="form-group" id="in-role-wrap" style="display:none">
        <label>Role</label>
        <select name="target_role">
          <?php foreach (ROLE_LABELS as $rk => $rl): ?><option value="<?= e($rk) ?>"><?= e($rl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="max-width:150px"><label>Priority</label>
        <select name="priority"><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select>
      </div>
    </div>

    <div class="form-group"><label>Title <span style="color:var(--red)">*</span></label>
      <input type="text" name="title" required placeholder="e.g. Operations order — weekend deployment">
    </div>
    <div class="form-group"><label>Message Body <span style="color:var(--red)">*</span></label>
      <textarea name="body" required rows="9" style="resize:vertical;line-height:1.6" placeholder="Type the message to be disseminated..."></textarea>
    </div>
    <div class="form-group">
      <label>Attachments <span class="muted">(documents, images or videos — max 20 MB each)</span></label>
      <input type="file" name="attachments[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.odt,.ods,.jpg,.jpeg,.png,.gif,.webp,.bmp,.mp4,.mov,.avi,.mkv,.webm,.m4v,.3gp">
      <div class="muted" style="font-size:11px;margin-top:4px">Allowed: PDF, Office documents, images and common video formats. Each file must be under 20 MB.</div>
    </div>
    <div class="action-bar" style="justify-content:flex-end">
      <button class="btn-icon bi-gold bi-lg" type="submit" style="gap:8px;padding:0 20px;width:auto;font-size:13px"><?= ICO_SEND ?> Dispatch Message</button>
    </div>
  </form>
</div>

<?php elseif ($tab === 'outbound'): ?>
<!-- ══════════════════ OUTBOUND SMS / EMAIL ══════════════════ -->
<div class="card">
  <div class="chr">
    <h3>Outbound Message</h3>
    <?php if (!smtp_configured()): ?><span class="badge" style="background:#fef3c7;color:#92400e">⚠ SMTP not configured</span>
    <?php else: ?><span class="badge" style="background:#dcfce7;color:#166534">✓ Email ready</span><?php endif; ?>
  </div>
  <form method="post" id="compose-form" style="display:flex;flex-direction:column;gap:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="outbound_send">

    <div class="form-group">
      <label>Channel</label>
      <div style="display:flex;gap:16px;margin-top:6px">
        <?php foreach (['email'=>'📧 Email','sms'=>'📱 SMS'] as $ch=>$cl): ?>
        <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:13px;text-transform:none;letter-spacing:0;font-weight:600;color:var(--text)">
          <input type="radio" name="channel" value="<?= $ch ?>" <?= $ch==='email'?'checked':'' ?> onchange="updateChannelUI()"><?= $cl ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="background:var(--navy-50);border-radius:10px;padding:16px;border:1px solid var(--border)">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:12px">Recipients</div>
      <div class="form-row">
        <div class="form-group" style="flex:2"><label>Send To</label>
          <select name="recipient_type" id="recip-type" onchange="updateRecipientField()">
            <option value="all">All Personnel (in my scope)</option>
            <option value="region">By Region</option>
            <option value="division">By Division</option>
            <option value="station">By Station</option>
            <option value="post">By Post</option>
            <option value="directorate">By Directorate</option>
            <option value="unit">By Unit</option>
            <option value="employee">Individual Employee</option>
          </select>
        </div>
      </div>
      <div id="recip-sub" style="display:none" class="form-group">
        <label id="recip-sub-label">Select</label>
        <select name="recipient_id" id="recip-sub-select"><option value="">— select —</option></select>
      </div>
      <div id="recip-count" style="font-size:12px;color:var(--muted);margin-top:8px">
        <span id="recip-count-text"><?= count($employees) ?> personnel in your scope have contact details</span>
      </div>
    </div>

    <div class="form-group"><label>Subject / Title <span style="color:var(--red)">*</span></label>
      <input type="text" name="subject" required placeholder="e.g. Monthly parade reminder">
    </div>
    <div class="form-group"><label>Message Body <span style="color:var(--red)">*</span></label>
      <textarea name="body" required rows="9" placeholder="Type your message here..." style="resize:vertical;line-height:1.6"></textarea>
      <div id="char-count" style="font-size:11px;color:var(--muted);text-align:right;margin-top:4px">0 characters</div>
    </div>
    <div class="action-bar" style="justify-content:flex-end">
      <button class="btn-icon bi-gold bi-lg" type="submit" style="gap:8px;padding:0 22px;width:auto;font-size:13px;font-weight:700"><?= ICO_SEND ?> <span id="send-btn-text">Send Email</span></button>
    </div>
  </form>
</div>

<?php elseif ($tab === 'history'): ?>
<!-- ══════════════════ OUTBOUND HISTORY ══════════════════ -->
<div class="card">
  <div class="chr"><h3>Sent Communications</h3><span class="badge badge-admin"><?= count($history) ?></span></div>
  <?php if (!$history): ?>
    <div style="text-align:center;color:var(--muted);padding:40px">No messages sent yet.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Channel</th><th>Subject</th><th>Recipients</th><th>Delivered</th><th>Status</th><th>Sent By</th><th>Date/Time</th><th style="width:50px"></th></tr></thead>
      <tbody>
        <?php foreach ($history as $c):
          $statusColor = match($c['status']) {
            'sent'    => ['#dcfce7','#166534'],
            'partial' => ['#fef3c7','#92400e'],
            'failed'  => ['#fee2e2','#991b1b'],
            default   => ['#f1f5f9','#64748b'],
          };
        ?>
        <tr>
          <td style="font-size:12px"><?= $c['channel']==='email' ? '📧 Email' : '📱 SMS' ?></td>
          <td><strong style="font-size:13px"><?= e($c['subject']) ?></strong>
            <div style="font-size:11px;color:var(--muted)"><?= e($c['recipient_label'] ?? 'All') ?></div></td>
          <td style="text-align:center;font-weight:600"><?= (int)$c['total_recipients'] ?></td>
          <td style="text-align:center"><?php if ((int)$c['total_recipients'] > 0): ?>
            <span style="color:#166534;font-weight:600"><?= (int)$c['delivered'] ?></span>
            <?php if ((int)$c['failed'] > 0): ?><span style="color:#991b1b"> / <?= (int)$c['failed'] ?> ✗</span><?php endif; ?>
          <?php else: ?>—<?php endif; ?></td>
          <td><span class="badge" style="background:<?= $statusColor[0] ?>;color:<?= $statusColor[1] ?>"><?= ucfirst($c['status']) ?></span></td>
          <td style="font-size:12px"><?= e($c['sender_name'] ?? '—') ?></td>
          <td style="font-size:12px;white-space:nowrap;color:var(--muted)"><?= e(date('j M Y, H:i', strtotime($c['sent_at']))) ?></td>
          <td><?php if ($c['error_log'] || $c['body']): ?><button class="btn-icon bi-secondary bi-sm" onclick="showDetail(<?= (int)$c['id'] ?>)" title="View details"><?= ICO_EYE ?></button><?php endif; ?></td>
        </tr>
        <tr id="detail-<?= (int)$c['id'] ?>" style="display:none">
          <td colspan="8" style="background:var(--navy-50);padding:12px 16px">
            <div style="font-size:12px;margin-bottom:8px;font-weight:600;color:var(--navy-700)">Message Body:</div>
            <div style="font-size:12px;white-space:pre-wrap;background:#fff;padding:10px 14px;border-radius:7px;border:1px solid var(--border);max-height:200px;overflow-y:auto"><?= e($c['body']) ?></div>
            <?php if ($c['error_log']): ?>
            <div style="font-size:11px;margin-top:8px;font-weight:600;color:var(--red)">Errors / Failed deliveries:</div>
            <div style="font-size:11px;white-space:pre-wrap;color:var(--red);background:#fff5f5;padding:8px 12px;border-radius:6px;margin-top:4px;max-height:120px;overflow-y:auto"><?= e($c['error_log']) ?></div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
var regionsData      = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'label'=>$r['name'].' Region'], $regions)) ?>;
var divisionsData    = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'label'=>$r['region_name'].' › '.$r['name']], $divisions)) ?>;
var stationsData     = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'label'=>$r['division_name'].' › '.$r['name']], $stations)) ?>;
var postsData        = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'label'=>$r['station_name'].' › '.$r['name']], $posts)) ?>;
var directoratesData = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'label'=>$r['name']], $directorates)) ?>;
var unitsData        = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'label'=>$r['directorate_name'].' › '.$r['name']], $units)) ?>;
var usersData        = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'label'=>$r['full_name'].' ('.role_label($r['role']).')'], $allUsers), JSON_UNESCAPED_UNICODE) ?>;
var employeesData    = <?= json_encode(array_map(fn($e)=>['id'=>(int)$e['id'],'label'=>personnel_label($e),'email'=>$e['email'],'phone'=>$e['phone']], $employees), JSON_UNESCAPED_UNICODE) ?>;

function fillSelect(sel, items, placeholder){
  sel.innerHTML = '<option value="">' + (placeholder || '— select —') + '</option>' + items.map(function(i){
    return '<option value="'+i.id+'">'+i.label+'</option>';
  }).join('');
}

function updateInboundTarget(){
  var type = document.getElementById('in-type')?.value || 'all';
  var wrap = document.getElementById('in-target-wrap');
  var label = document.getElementById('in-target-label');
  var req = document.getElementById('in-target-req');
  var help = document.getElementById('in-target-help');
  var sel = document.getElementById('in-target');
  var roleWrap = document.getElementById('in-role-wrap');
  var map = {
    region: [regionsData,'Region'], division: [divisionsData,'Division'],
    station: [stationsData,'Station'], post: [postsData,'Post'],
    directorate: [directoratesData,'Directorate'], unit: [unitsData,'Unit'],
    user: [usersData,'User']
  };
  roleWrap.style.display = type === 'role' ? '' : 'none';
  if (map[type]) {
    wrap.style.display = '';
    sel.required = true;
    req.style.display = '';
    label.textContent = map[type][1];
    help.textContent = 'Choose the specific ' + map[type][1].toLowerCase() + ' this message should go to.';
    fillSelect(sel, map[type][0], '— choose a ' + map[type][1].toLowerCase() + ' —');
  } else {
    wrap.style.display = 'none';
    sel.required = false;
    req.style.display = 'none';
    sel.innerHTML = '<option value="">Choose a destination</option>';
    help.textContent = 'Choose the exact region, division, station, post, directorate or unit.';
  }
}

var currentChannel = 'email';
function updateChannelUI(){
  currentChannel = document.querySelector('[name=channel]:checked')?.value || 'email';
  var t = document.getElementById('send-btn-text');
  if (t) t.textContent = currentChannel === 'email' ? 'Send Email' : 'Send SMS';
  updateRecipientField();
}

function updateRecipientField(){
  var type = document.getElementById('recip-type')?.value || 'all';
  var sub = document.getElementById('recip-sub');
  var subSel = document.getElementById('recip-sub-select');
  var label = document.getElementById('recip-sub-label');
  var countEl = document.getElementById('recip-count-text');
  var map = {
    region: [regionsData,'Region'], division: [divisionsData,'Division'],
    station: [stationsData,'Station'], post: [postsData,'Post'],
    directorate: [directoratesData,'Directorate'], unit: [unitsData,'Unit'],
    employee: [employeesData,'Employee']
  };
  if (!sub) return;
  if (map[type]) {
    sub.style.display = '';
    label.textContent = 'Select ' + map[type][1];
    fillSelect(subSel, map[type][0]);
    if (type === 'directorate') subSel.name = 'recipient_id';
  } else {
    sub.style.display = 'none';
    var field = currentChannel === 'email' ? 'email' : 'phone';
    var n = employeesData.filter(function(e){ return !!e[field]; }).length;
    countEl.textContent = n + ' personnel in your scope have ' + (currentChannel==='email'?'email addresses':'phone numbers');
  }
}

var bodyEl = document.querySelector('#compose-form [name=body]');
var countEl = document.getElementById('char-count');
if (bodyEl && countEl) {
  bodyEl.addEventListener('input', function(){
    var len = this.value.length;
    countEl.textContent = len + ' character' + (len!==1?'s':'');
    if (len > 160) countEl.innerHTML += ' <span style="color:var(--amber)">(SMS: ~'+ Math.ceil(len/160) +' parts)</span>';
  });
}

function showDetail(id){
  var row = document.getElementById('detail-'+id);
  if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
}

if (document.getElementById('in-type')) updateInboundTarget();
if (document.getElementById('recip-type')) updateRecipientField();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
