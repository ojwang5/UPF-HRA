<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/sms.php';
$user = require_min_rank('station_commander');
$page = 'communications';
$page_title = 'Communications';
$pdo = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

/* ── POST: send communication ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject   = trim($_POST['subject'] ?? '');
    $body      = trim($_POST['body']    ?? '');
    $recipType = $_POST['recipient_type'] ?? 'all';
    $recipId   = (int)($_POST['recipient_id'] ?? 0) ?: null;
    $channel   = $_POST['channel'] ?? 'email';

    if (!$subject || !$body) { flash('err','Subject and message body are required.'); header('Location:/communications.php'); exit; }

    // Resolve recipient label
    $recipLabel = match($recipType) {
        'all'      => 'All Personnel',
        'region'   => ($pdo->query("SELECT name FROM regions WHERE id=$recipId")->fetchColumn() ?: 'Unknown Region') . ' Region',
        'division' => ($pdo->query("SELECT name FROM divisions WHERE id=$recipId")->fetchColumn() ?: 'Unknown Division') . ' Division',
        'station'  => ($pdo->query("SELECT name FROM stations WHERE id=$recipId")->fetchColumn() ?: 'Unknown Station') . ' Station',
        'post'     => ($pdo->query("SELECT name FROM posts WHERE id=$recipId")->fetchColumn() ?: 'Unknown Post') . ' Post',
        'employee' => $pdo->query("SELECT full_name FROM employees WHERE id=$recipId")->fetchColumn() ?: 'Individual',
        default    => 'Custom',
    };

    // Build employee query
    $empWhere = "e.active=1 AND $scopeW";
    $empParams = $scopeP;
    if ($channel === 'email') $empWhere .= " AND e.email IS NOT NULL AND e.email != ''";
    if ($channel === 'sms')   $empWhere .= " AND e.phone IS NOT NULL AND e.phone != ''";

    switch ($recipType) {
        case 'region':   $empWhere .= " AND e.region_id=?";   $empParams[] = $recipId; break;
        case 'division': $empWhere .= " AND e.division_id=?"; $empParams[] = $recipId; break;
        case 'station':  $empWhere .= " AND e.station_id=?";  $empParams[] = $recipId; break;
        case 'post':     $empWhere .= " AND e.post_id=?";     $empParams[] = $recipId; break;
        case 'employee': $empWhere .= " AND e.id=?";          $empParams[] = $recipId; break;
    }
    $stmt = $pdo->prepare("SELECT id, full_name, email, phone FROM employees e WHERE $empWhere");
    $stmt->execute($empParams);
    $recipients = $stmt->fetchAll();

    // Log the communication first
    $pdo->prepare("INSERT INTO communications (subject,body,sender_id,recipient_type,recipient_id,recipient_label,channel,sent_at,status,total_recipients) VALUES (?,?,?,?,?,?,?,datetime('now'),?,?)")
        ->execute([$subject, $body, $user['id'], $recipType, $recipId, $recipLabel, $channel, 'pending', count($recipients)]);
    $commId = (int)$pdo->lastInsertId();

    if (empty($recipients)) {
        $pdo->prepare("UPDATE communications SET status='failed', error_log=? WHERE id=?")->execute(['No recipients found with contact details.', $commId]);
        flash('err', 'No recipients found with ' . ($channel === 'email' ? 'email addresses' : 'phone numbers') . ' in the selected scope.');
        header('Location:/communications.php'); exit;
    }

    if ($channel === 'email') {
        if (!smtp_configured()) {
            $pdo->prepare("UPDATE communications SET status='failed', error_log='SMTP not configured' WHERE id=?")->execute([$commId]);
            flash('err', 'SMTP is not configured. Go to Settings to set up email credentials.');
            header('Location:/communications.php'); exit;
        }
        send_communication($commId, $recipients, $pdo);
    } elseif ($channel === 'sms') {
        if (!sms_configured()) {
            $pdo->prepare("UPDATE communications SET status='failed', error_log='SMS not configured' WHERE id=?")->execute([$commId]);
            flash('err', 'SMS is not configured. Go to Settings to set up SMS credentials.');
            header('Location:/communications.php'); exit;
        }
        $delivered = 0; $failed = 0; $errors = [];
        foreach ($recipients as $emp) {
            $res = send_sms($emp['phone'], $subject . "\n\n" . $body);
            if ($res['ok']) $delivered++; else { $failed++; $errors[] = ($emp['full_name']).': '.$res['error']; }
        }
        $status = ($failed === 0) ? 'sent' : ($delivered === 0 ? 'failed' : 'partial');
        $pdo->prepare("UPDATE communications SET status=?, delivered=?, failed=?, error_log=? WHERE id=?")
            ->execute([$status, $delivered, $failed, $errors ? implode("\n", $errors) : null, $commId]);
    }

    $comm = $pdo->query("SELECT * FROM communications WHERE id=$commId")->fetch();
    $msg = $comm['delivered'] . ' message(s) sent';
    if ($comm['failed'] > 0) $msg .= ', ' . $comm['failed'] . ' failed';
    $comm['status'] === 'sent' ? flash('msg', $msg.'.') : flash('err', $msg.'. Check the sent log for details.');
    header('Location:/communications.php'); exit;
}

/* ── Fetch data for compose form ── */
$regions   = $pdo->query("SELECT id, name FROM regions ORDER BY name")->fetchAll();
$divisions = $pdo->query("SELECT d.id, d.name, r.name AS region_name FROM divisions d JOIN regions r ON r.id=d.region_id ORDER BY r.name, d.name")->fetchAll();
$stations  = $pdo->query("SELECT s.id, s.name, d.name AS division_name FROM stations s JOIN divisions d ON d.id=s.division_id ORDER BY d.name, s.name")->fetchAll();
$posts     = $pdo->query("SELECT p.id, p.name, s.name AS station_name FROM posts p JOIN stations s ON s.id=p.station_id ORDER BY s.name, p.name")->fetchAll();

// Employees with email or phone within scope
$empStmt = $pdo->prepare("SELECT id, full_name, email, phone, rank FROM employees e WHERE e.active=1 AND $scopeW ORDER BY full_name");
$empStmt->execute($scopeP);
$employees = $empStmt->fetchAll();

/* ── Sent history ── */
$history = $pdo->query("SELECT c.*, u.full_name AS sender_name FROM communications c LEFT JOIN users u ON u.id=c.sender_id ORDER BY c.id DESC LIMIT 100")->fetchAll();

$tab = $_GET['tab'] ?? 'compose';

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Communications</h1>
    <div class="desc">Send emails and SMS messages to personnel</div>
  </div>
  <div class="action-bar">
    <?php if (!smtp_configured()): ?>
    <a href="/settings.php" class="btn-icon bi-secondary" style="gap:6px;padding:0 14px;width:auto;font-size:12px">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      Setup Email
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<!-- Tab bar -->
<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid var(--border)">
  <?php foreach (['compose'=>'✉ Compose Message','history'=>'📋 Sent History ('.count($history).')'] as $t=>$l): ?>
  <a href="?tab=<?= $t ?>" style="padding:10px 20px;font-size:13px;font-weight:600;color:<?= $tab===$t?'var(--primary)':'var(--muted)' ?>;border-bottom:2px solid <?= $tab===$t?'var(--primary)':'transparent' ?>;margin-bottom:-2px;text-decoration:none;transition:color .12s">
    <?= $l ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'compose'): ?>
<!-- ══════════════════ COMPOSE ══════════════════ -->
<div style="display:grid;grid-template-columns:1fr minmax(260px,340px);gap:20px;align-items:start">

  <!-- Main compose form -->
  <div class="card">
    <div class="chr">
      <h3>Draft New Message</h3>
      <?php if (!smtp_configured()): ?>
        <span class="badge" style="background:#fef3c7;color:#92400e">⚠ SMTP not configured</span>
      <?php else: ?>
        <span class="badge" style="background:#dcfce7;color:#166534">✓ Email ready</span>
      <?php endif; ?>
    </div>
    <form method="post" id="compose-form">

      <!-- Channel -->
      <div class="form-group" style="margin-bottom:14px">
        <label>Channel</label>
        <div style="display:flex;gap:16px;margin-top:6px">
          <?php foreach (['email'=>'📧 Email','sms'=>'📱 SMS'] as $ch=>$cl): ?>
          <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:13px;text-transform:none;letter-spacing:0;font-weight:600;color:var(--text)">
            <input type="radio" name="channel" value="<?= $ch ?>" <?= $ch==='email'?'checked':'' ?> onchange="updateChannelUI()">
            <?= $cl ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Recipients -->
      <div style="background:var(--navy-50);border-radius:10px;padding:16px;margin-bottom:16px;border:1px solid var(--border)">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:12px">Recipients</div>
        <div class="form-row" style="margin-bottom:8px">
          <div class="form-group">
            <label>Send To</label>
            <select name="recipient_type" id="recip-type" onchange="updateRecipientField()">
              <option value="all">All Personnel (in my scope)</option>
              <?php if (count($regions) > 1 || is_superadmin($user)): ?>
              <option value="region">By Region</option>
              <?php endif; ?>
              <option value="division">By Division</option>
              <option value="station">By Station</option>
              <option value="post">By Post</option>
              <option value="employee">Individual Employee</option>
            </select>
          </div>
        </div>
        <!-- Dynamic secondary selector -->
        <div id="recip-sub" style="display:none" class="form-group">
          <label id="recip-sub-label">Select</label>
          <select name="recipient_id" id="recip-sub-select">
            <option value="">— select —</option>
          </select>
        </div>
        <!-- Recipient count preview -->
        <div id="recip-count" style="font-size:12px;color:var(--muted);margin-top:8px">
          <span id="recip-count-text"><?= count($employees) ?> personnel in your scope have contact details</span>
        </div>
      </div>

      <!-- Subject -->
      <div class="form-group" style="margin-bottom:14px">
        <label>Subject / Title <span style="color:var(--red)">*</span></label>
        <input type="text" name="subject" required placeholder="e.g. Monthly Parade Reminder — July 2026" style="font-size:14px">
      </div>

      <!-- Body -->
      <div class="form-group" style="margin-bottom:16px">
        <label>Message Body <span style="color:var(--red)">*</span></label>
        <textarea name="body" required rows="10" placeholder="Type your message here...&#10;&#10;The message will be sent in a professionally branded UPF email template." style="font-size:13px;resize:vertical;line-height:1.6;min-height:200px"></textarea>
        <div id="char-count" style="font-size:11px;color:var(--muted);text-align:right;margin-top:4px">0 characters</div>
      </div>

      <!-- Footer / send -->
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding-top:14px;border-top:1px solid var(--border)">
        <button class="btn-icon bi-gold bi-lg" type="submit" style="gap:8px;padding:0 22px;width:auto;font-size:13px;font-weight:700">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          <span id="send-btn-text">Send Email</span>
        </button>
        <div style="flex:1;font-size:11px;color:var(--muted);line-height:1.4">
          Messages are sent immediately and logged in Sent History.
          <?php if (!smtp_configured()): ?><strong style="color:var(--amber)"> Configure SMTP in Settings first.</strong><?php endif; ?>
        </div>
      </div>
    </form>
  </div>

  <!-- Right panel: Quick tips + stats -->
  <div style="display:flex;flex-direction:column;gap:16px">
    <!-- Scope summary -->
    <div class="card">
      <h3 style="margin:0 0 12px;font-size:14px">Your Scope</h3>
      <?php
      $withEmail = count(array_filter($employees, fn($e) => !empty($e['email'])));
      $withPhone = count(array_filter($employees, fn($e) => !empty($e['phone'])));
      $total = count($employees);
      ?>
      <?php foreach ([
        ['Total Personnel',  $total,     '#1f3559','#eef2f9'],
        ['Have Email',       $withEmail, '#166534','#dcfce7'],
        ['Have Phone (SMS)', $withPhone, '#1e40af','#dbeafe'],
      ] as [$lbl,$val,$clr,$bg]): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px dashed var(--border)">
        <span style="font-size:12px;color:var(--muted)"><?= $lbl ?></span>
        <span style="font-weight:700;font-size:15px;color:<?= $clr ?>;background:<?= $bg ?>;padding:2px 10px;border-radius:12px"><?= $val ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <!-- Integration status -->
    <div class="card">
      <h3 style="margin:0 0 12px;font-size:14px">Integration Status</h3>
      <div style="display:flex;flex-direction:column;gap:8px">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <span style="font-size:13px">📧 Email (SMTP)</span>
          <?php if (smtp_configured()): ?>
            <span class="badge" style="background:#dcfce7;color:#166534">Ready</span>
          <?php else: ?>
            <a href="/settings.php" class="badge" style="background:#fef3c7;color:#92400e;text-decoration:none">Configure →</a>
          <?php endif; ?>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center">
          <span style="font-size:13px">📱 SMS</span>
          <?php if (sms_configured()): ?>
            <span class="badge" style="background:#dcfce7;color:#166534">Ready</span>
          <?php else: ?>
            <a href="/settings.php" class="badge" style="background:#fef3c7;color:#92400e;text-decoration:none">Configure →</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <!-- Tips -->
    <div class="card" style="border-left:3px solid var(--gold)">
      <h3 style="margin:0 0 10px;font-size:13px;color:var(--navy-700)">Tips</h3>
      <ul style="margin:0;padding-left:16px;font-size:12px;color:var(--muted);line-height:1.8">
        <li>Emails are sent in a branded UPF template</li>
        <li>Only personnel with email addresses receive emails</li>
        <li>SMS supports Uganda (+256) numbers by default</li>
        <li>All messages are logged for 90 days</li>
        <li>Configure SMTP/SMS in <a href="/settings.php">Settings</a></li>
      </ul>
    </div>
  </div>

</div>

<?php else: ?>
<!-- ══════════════════ SENT HISTORY ══════════════════ -->
<div class="card">
  <div class="chr">
    <h3>Sent Communications</h3>
    <span class="badge badge-admin"><?= count($history) ?></span>
  </div>
  <?php if (!$history): ?>
    <div style="text-align:center;color:var(--muted);padding:40px">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="36" height="36" style="display:block;margin:0 auto 10px;opacity:.4"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      No messages sent yet.
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Channel</th><th>Subject</th><th>Recipients</th>
          <th>Delivered</th><th>Status</th><th>Sent By</th><th>Date/Time</th>
          <th style="width:50px"></th>
        </tr>
      </thead>
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
          <td>
            <?php if ($c['channel']==='email'): ?>
              <span style="font-size:12px">📧 Email</span>
            <?php else: ?>
              <span style="font-size:12px">📱 SMS</span>
            <?php endif; ?>
          </td>
          <td>
            <strong style="font-size:13px"><?= e($c['subject']) ?></strong>
            <div style="font-size:11px;color:var(--muted)"><?= e($c['recipient_label'] ?? 'All') ?></div>
          </td>
          <td style="text-align:center;font-weight:600"><?= $c['total_recipients'] ?></td>
          <td style="text-align:center">
            <?php if ($c['total_recipients'] > 0): ?>
              <span style="color:#166534;font-weight:600"><?= $c['delivered'] ?></span>
              <?php if ($c['failed'] > 0): ?><span style="color:#991b1b"> / <?= $c['failed'] ?> ✗</span><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <span class="badge" style="background:<?= $statusColor[0] ?>;color:<?= $statusColor[1] ?>">
              <?= ucfirst($c['status']) ?>
            </span>
          </td>
          <td style="font-size:12px"><?= e($c['sender_name'] ?? '—') ?></td>
          <td style="font-size:12px;white-space:nowrap;color:var(--muted)"><?= e(date('j M Y, H:i', strtotime($c['sent_at']))) ?></td>
          <td>
            <?php if ($c['error_log'] || $c['body']): ?>
            <button class="btn-icon bi-secondary bi-sm" onclick="showDetail(<?= $c['id'] ?>)" title="View details"><?= ICO_EYE ?></button>
            <?php endif; ?>
          </td>
        </tr>
        <!-- Detail expandable row -->
        <tr id="detail-<?= $c['id'] ?>" style="display:none">
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
// Recipient data from PHP
var regionsData   = <?= json_encode(array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['name'].' Region'], $regions)) ?>;
var divisionsData = <?= json_encode(array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['division_name'].' › '.$r['name']], $divisions)) ?>;
var stationsData  = <?= json_encode(array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['division_name'].' › '.$r['name']], $stations)) ?>;
var postsData     = <?= json_encode(array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['station_name'].' › '.$r['name']], $posts)) ?>;
var employeesData = <?= json_encode(array_map(fn($e)=>['id'=>$e['id'],'label'=>$e['rank'].' '.$e['full_name'],'email'=>$e['email'],'phone'=>$e['phone']], $employees)) ?>;

var currentChannel = 'email';

function updateChannelUI(){
  currentChannel = document.querySelector('[name=channel]:checked')?.value || 'email';
  document.getElementById('send-btn-text').textContent = currentChannel === 'email' ? 'Send Email' : 'Send SMS';
  updateRecipientField();
}

function updateRecipientField(){
  var type = document.getElementById('recip-type')?.value || 'all';
  var sub = document.getElementById('recip-sub');
  var subSel = document.getElementById('recip-sub-select');
  var label = document.getElementById('recip-sub-label');
  var countEl = document.getElementById('recip-count-text');

  var dataMap = {
    region: [regionsData,'Region'],
    division: [divisionsData,'Division'],
    station: [stationsData,'Station'],
    post: [postsData,'Post'],
    employee: [employeesData,'Employee'],
  };

  if(dataMap[type]){
    sub.style.display = '';
    label.textContent = 'Select ' + dataMap[type][1];
    var items = dataMap[type][0];
    subSel.innerHTML = '<option value="">— select —</option>' + items.map(function(i){
      return '<option value="'+i.id+'">'+i.label+'</option>';
    }).join('');
    subSel.onchange = function(){ updateCount(type, parseInt(this.value)); };
    updateCount(type, 0);
  } else {
    sub.style.display = 'none';
    // Count all personnel
    var field = currentChannel === 'email' ? 'email' : 'phone';
    var n = employeesData.filter(function(e){ return !!e[field]; }).length;
    countEl.textContent = n + ' personnel in your scope have ' + (currentChannel==='email'?'email addresses':'phone numbers');
  }
}

function updateCount(type, id){
  var countEl = document.getElementById('recip-count-text');
  var field = currentChannel === 'email' ? 'email' : 'phone';
  if(!id){ countEl.textContent = 'Select a '+type+' to see recipient count'; return; }
  // We don't have full breakdown client-side, just show the selection
  countEl.textContent = '✓ Recipient selected — final count determined on send';
}

// Character counter
var bodyEl = document.querySelector('[name=body]');
var countEl = document.getElementById('char-count');
if(bodyEl && countEl){
  bodyEl.addEventListener('input', function(){
    var len = this.value.length;
    countEl.textContent = len + ' character' + (len!==1?'s':'');
    if(len > 160) countEl.innerHTML += ' <span style="color:var(--amber)">(SMS: ~'+ Math.ceil(len/160) +' parts)</span>';
  });
}

// Toggle detail row
function showDetail(id){
  var row = document.getElementById('detail-'+id);
  if(row) row.style.display = row.style.display==='none' ? '' : 'none';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
