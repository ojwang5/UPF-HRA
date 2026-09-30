<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/sms.php';
$user = require_role(['superadmin']);
$page = 'settings';
$page_title = 'System Settings';
$pdo = db();

/* ── POST: save settings ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_smtp') {
        $keys = ['smtp_host','smtp_port','smtp_user','smtp_from_email','smtp_from_name','smtp_encryption'];
        foreach ($keys as $k) save_setting($k, trim($_POST[$k] ?? ''));
        // Only update password if provided (prevent clearing on re-save)
        if (!empty($_POST['smtp_pass'])) save_setting('smtp_pass', $_POST['smtp_pass']);
        flash('msg', 'SMTP settings saved.');

    } elseif ($action === 'test_smtp') {
        require_once __DIR__ . '/../includes/mailer.php';
        $testTo = trim($_POST['test_email'] ?? $user['email'] ?? '');
        if (!$testTo) { flash('err','Enter a recipient email to test.'); }
        else {
            $res = smtp_send_email($testTo, 'Test Recipient', 'UPF MDD — SMTP Test', email_html_template('SMTP Test', '<p>This is a test email from the UPF MDD Management System. SMTP is working correctly.</p>'));
            $res['ok'] ? flash('msg','Test email sent to '.$testTo.' successfully.') : flash('err','SMTP error: '.$res['error']);
        }

    } elseif ($action === 'save_sms') {
        $keys = ['sms_provider','sms_api_key','sms_api_secret','sms_username','sms_sender_id'];
        foreach ($keys as $k) save_setting($k, trim($_POST[$k] ?? ''));
        flash('msg', 'SMS settings saved.');

    } elseif ($action === 'save_report_mode') {
        $mode = ($_POST['report_submission_mode'] ?? '') === 'direct' ? 'direct' : 'hierarchical';
        save_setting('report_submission_mode', $mode);
        log_activity('Changed report submission mode', 'setting', $mode);
        flash('msg', $mode === 'direct' ? 'Reports now go DIRECTLY to HQ for approval.' : 'Reports now flow hierarchically (post → station → division → region → HQ).');

    } elseif ($action === 'test_sms') {
        require_once __DIR__ . '/../includes/sms.php';
        $testPhone = trim($_POST['test_phone'] ?? $user['phone'] ?? '');
        if (!$testPhone) { flash('err','Enter a phone number to test.'); }
        else {
            $res = send_sms($testPhone, 'UPF MDD System: SMS integration test successful.');
            $res['ok'] ? flash('msg','Test SMS sent to '.$testPhone.'.') : flash('err','SMS error: '.$res['error']);
        }
    }

    header('Location:/settings.php'); exit;
}

// Load current settings
$s = function(string $key) { return htmlspecialchars(get_setting($key), ENT_QUOTES); };

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>System Settings</h1>
    <div class="desc">Report workflow, email (SMTP) and SMS integration configuration</div>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<!-- ══════════════════ REPORT SUBMISSION MODE ══════════════════ -->
<?php $rmode = get_setting('report_submission_mode', 'hierarchical') === 'direct' ? 'direct' : 'hierarchical'; ?>
<div class="card" style="margin-bottom:20px">
  <div class="chr">
    <h3>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;vertical-align:-3px;margin-right:6px;color:var(--primary)"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
      Report Submission Mode
    </h3>
    <span class="badge" style="<?= $rmode==='direct' ? 'background:#fef3c7;color:#92400e' : 'background:#dcfce7;color:#166534' ?>">
      <?= $rmode==='direct' ? 'Direct to HQ' : 'Hierarchical (post → station → division → region → HQ)' ?>
    </span>
  </div>
  <p style="margin:0 0 14px;font-size:13px;color:var(--muted);max-width:720px">
    In <strong>Hierarchical</strong> mode every generated report is reviewed level by level up the command chain
    (each reviewer forwards or returns it for correction). In <strong>Direct to HQ</strong> mode, generated reports
    skip the intermediate levels and wait only for the Super Admin's approval.
  </p>
  <form method="post">
    <input type="hidden" name="action" value="save_report_mode">
    <label class="switch">
      <input type="checkbox" name="report_submission_mode" value="direct" <?= $rmode==='direct' ? 'checked' : '' ?> onchange="this.form.submit()">
      <span class="slider"></span>
      <span class="switch-label">
        <strong><?= $rmode==='direct' ? 'Direct submission to HQ is ON' : 'Follow the command chain (hierarchical)' ?></strong>
        <br><small><?= $rmode==='direct' ? 'Reports go straight to HQ for approval.' : 'Reports flow post → station → division → region → HQ.' ?></small>
      </span>
    </label>
    <noscript><button class="btn-icon bi-gold bi-lg" type="submit" style="gap:8px;padding:0 18px;width:auto;font-size:12px;font-weight:600;margin-top:8px"><?= ICO_SAVE ?> <span>Save Mode</span></button></noscript>
  </form>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(540px,100%),1fr));gap:20px">

<!-- ══════════════════ SMTP CARD ══════════════════ -->
<details class="cfg-tile" open>
  <summary class="cfg-sum">
    <div class="chr" style="flex:1;border:none;padding:0">
      <h3>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;vertical-align:-3px;margin-right:6px;color:var(--primary)"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        Email (SMTP) Configuration
      </h3>
      <?php if (smtp_configured()): ?>
        <span class="badge" style="background:#dcfce7;color:#166534">● Connected</span>
      <?php else: ?>
        <span class="badge" style="background:#fef3c7;color:#92400e">● Not Configured</span>
      <?php endif; ?>
    </div>
    <span class="cfg-chev"></span>
  </summary>
  <div class="cfg-body">
  <form method="post">
    <input type="hidden" name="action" value="save_smtp">
    <div class="form-row">
      <div class="form-group" style="flex:3;min-width:200px">
        <label>SMTP Host / Server</label>
        <input type="text" name="smtp_host" value="<?= $s('smtp_host') ?>" placeholder="smtp.gmail.com">
      </div>
      <div class="form-group" style="max-width:110px">
        <label>Port</label>
        <input type="number" name="smtp_port" value="<?= $s('smtp_port') ?: '587' ?>" placeholder="587">
      </div>
      <div class="form-group" style="max-width:120px">
        <label>Encryption</label>
        <select name="smtp_encryption">
          <?php foreach (['tls'=>'TLS (STARTTLS)','ssl'=>'SSL','none'=>'None'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= get_setting('smtp_encryption','tls')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:1;min-width:180px">
        <label>SMTP Username</label>
        <input type="text" name="smtp_user" value="<?= $s('smtp_user') ?>" placeholder="your-email@domain.com" autocomplete="off">
      </div>
      <div class="form-group" style="flex:1;min-width:180px">
        <label>SMTP Password <?= get_setting('smtp_pass') ? '<span style="font-size:10px;color:var(--green)">✓ Saved</span>' : '' ?></label>
        <input type="password" name="smtp_pass" placeholder="<?= get_setting('smtp_pass') ? 'Leave blank to keep current' : 'Enter password' ?>" autocomplete="new-password">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:2;min-width:180px">
        <label>From Email Address</label>
        <input type="email" name="smtp_from_email" value="<?= $s('smtp_from_email') ?>" placeholder="noreply@upf.go.ug">
      </div>
      <div class="form-group" style="flex:2;min-width:180px">
        <label>From Display Name</label>
        <input type="text" name="smtp_from_name" value="<?= $s('smtp_from_name') ?>" placeholder="UPF MDD System">
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:4px;align-items:flex-end;flex-wrap:wrap">
      <button class="btn-icon bi-gold bi-lg" type="submit" style="gap:8px;padding:0 18px;width:auto;font-size:12px;font-weight:600">
        <?= ICO_SAVE ?> <span>Save SMTP Settings</span>
      </button>
    </div>
  </form>
  <!-- Test SMTP -->
  <div style="margin-top:16px;padding-top:14px;border-top:1px dashed var(--border)">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:10px">Send Test Email</div>
    <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="action" value="test_smtp">
      <div class="form-group" style="flex:1;min-width:200px">
        <label>Recipient Email</label>
        <input type="email" name="test_email" value="<?= e($user['email'] ?? '') ?>" placeholder="test@domain.com">
      </div>
      <button class="btn-icon bi-primary" type="submit" style="gap:8px;padding:0 14px;width:auto;font-size:12px">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        Send Test
      </button>
    </form>
  </div>
  <!-- Provider hints -->
  <div style="margin-top:14px;background:var(--navy-50);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--muted)">
    <strong style="color:var(--navy-700)">Common providers:</strong>
    <span style="margin-left:8px">Gmail: smtp.gmail.com:587/TLS &nbsp;·&nbsp; Outlook: smtp.office365.com:587/TLS &nbsp;·&nbsp; Yahoo: smtp.mail.yahoo.com:587/TLS &nbsp;·&nbsp; Custom SMTP: use your server details</span>
  </div>
  </div>
</details>

<!-- ══════════════════ SMS CARD ══════════════════ -->
<details class="cfg-tile" open>
  <summary class="cfg-sum">
    <div class="chr" style="flex:1;border:none;padding:0">
      <h3>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;vertical-align:-3px;margin-right:6px;color:var(--primary)"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        SMS Configuration
      </h3>
      <?php if (sms_configured()): ?>
        <span class="badge" style="background:#dcfce7;color:#166534">● Connected</span>
      <?php else: ?>
        <span class="badge" style="background:#fef3c7;color:#92400e">● Not Configured</span>
      <?php endif; ?>
    </div>
    <span class="cfg-chev"></span>
  </summary>
  <div class="cfg-body">
  <form method="post">
    <input type="hidden" name="action" value="save_sms">
    <div class="form-row">
      <div class="form-group">
        <label>SMS Provider</label>
        <select name="sms_provider">
          <?php foreach (['africas_talking'=>"Africa's Talking",'twilio'=>'Twilio','nexmo'=>'Vonage (Nexmo)'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= get_setting('sms_provider','africas_talking')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:2">
        <label>API Key</label>
        <input type="text" name="sms_api_key" value="<?= $s('sms_api_key') ?>" placeholder="Your API key" autocomplete="off">
      </div>
      <div class="form-group" style="flex:2">
        <label>API Secret / Auth Token</label>
        <input type="password" name="sms_api_secret" placeholder="<?= get_setting('sms_api_secret') ? '••••••••' : 'API secret or token' ?>" autocomplete="new-password">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Username <span style="font-size:10px;color:var(--muted)">(Africa's Talking)</span></label>
        <input type="text" name="sms_username" value="<?= $s('sms_username') ?>" placeholder="sandbox or your AT username">
      </div>
      <div class="form-group">
        <label>Sender ID / From Number</label>
        <input type="text" name="sms_sender_id" value="<?= $s('sms_sender_id') ?>" placeholder="UPF-MDD or +256...">
      </div>
    </div>
    <button class="btn-icon bi-gold bi-lg" type="submit" style="gap:8px;padding:0 18px;width:auto;font-size:12px;font-weight:600;margin-top:4px">
      <?= ICO_SAVE ?> <span>Save SMS Settings</span>
    </button>
  </form>
  <!-- Test SMS -->
  <div style="margin-top:16px;padding-top:14px;border-top:1px dashed var(--border)">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:10px">Send Test SMS</div>
    <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="action" value="test_sms">
      <div class="form-group" style="flex:1;min-width:180px">
        <label>Recipient Phone</label>
        <input type="tel" name="test_phone" value="<?= e($user['phone'] ?? '') ?>" placeholder="+256 700 000000">
      </div>
      <button class="btn-icon bi-primary" type="submit" style="gap:8px;padding:0 14px;width:auto;font-size:12px">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        Send Test
      </button>
    </form>
  </div>
  <div style="margin-top:14px;background:var(--navy-50);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--muted)">
    <strong style="color:var(--navy-700)">Africa's Talking:</strong> Use <em>sandbox</em> as username to test for free. Get keys at <strong>africastalking.com</strong>.
    <br><strong style="color:var(--navy-700)">OTP / 2FA:</strong> SMS can be wired to login verification once credentials are set.
  </div>
  </div>
</details>

</div><!-- end grid -->

<?php include __DIR__ . '/../includes/footer.php'; ?>
