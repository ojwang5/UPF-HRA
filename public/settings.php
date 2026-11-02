<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/groq.php';
$user = require_role(['superadmin']);
$page = 'settings';
$page_title = 'System Settings';
$pdo = db();
$configuredGroqModel = groq_model();

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
            $res = smtp_send_email($testTo, 'Test Recipient', 'UPF — SMTP Test', email_html_template('SMTP Test', '<p>This is a test email from the UPF HRA System. SMTP is working correctly.</p>'));
            $res['ok'] ? flash('msg','Test email sent to '.$testTo.' successfully.') : flash('err','SMTP error: '.$res['error']);
        }

    } elseif ($action === 'save_sms') {
        $provider = strtolower(trim($_POST['sms_provider'] ?? 'infobip'));
        if (!in_array($provider, SMS_PROVIDERS, true)) $provider = 'infobip';
        save_setting('sms_provider', $provider);

        $field = fn(string $k): string => trim($_POST[$k] ?? '');

        if ($provider === 'infobip') {
            save_setting('sms_api_key', $field('sms_infobip_api_key'));
            save_setting('sms_sender_id', $field('sms_infobip_sender_id'));
            // Infobip base URLs are often copied without a scheme.
            $baseUrl = $field('sms_infobip_base_url');
            if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
                $baseUrl = 'https://' . $baseUrl;
            }
            save_setting('sms_infobip_base_url', $baseUrl);
        } elseif ($provider === 'africas_talking') {
            save_setting('sms_api_key', $field('sms_at_api_key'));
            save_setting('sms_username', $field('sms_at_username'));
            save_setting('sms_sender_id', $field('sms_at_sender_id'));
        } elseif ($provider === 'twilio') {
            save_setting('sms_api_key', $field('sms_twilio_account_sid'));
            if ($field('sms_twilio_auth_token') !== '') {
                save_setting('sms_api_secret', $field('sms_twilio_auth_token'));
            }
            save_setting('sms_sender_id', $field('sms_twilio_from'));
        } elseif ($provider === 'nexmo') {
            save_setting('sms_api_key', $field('sms_vonage_api_key'));
            if ($field('sms_vonage_api_secret') !== '') {
                save_setting('sms_api_secret', $field('sms_vonage_api_secret'));
            }
            save_setting('sms_sender_id', $field('sms_vonage_from'));
        }

        flash('msg', 'SMS settings saved.');

    } elseif ($action === 'save_report_mode') {
        $mode = ($_POST['report_submission_mode'] ?? '') === 'direct' ? 'direct' : 'hierarchical';
        save_setting('report_submission_mode', $mode);
        log_activity('Changed report submission mode', 'setting', $mode);
        flash('msg', $mode === 'direct' ? 'Reports now go DIRECTLY to HQ for approval.' : 'Reports now flow hierarchically (post → station → division → region → HQ).');

    } elseif ($action === 'save_slideshow') {
        $interval = (int)($_POST['slideshow_interval'] ?? 10);
        if ($interval < 5) $interval = 5;
        if ($interval > 300) $interval = 300;
        save_setting('slideshow_enabled', isset($_POST['slideshow_enabled']) ? '1' : '0');
        save_setting('slideshow_autoplay', isset($_POST['slideshow_autoplay']) ? '1' : '0');
        save_setting('slideshow_interval', (string)$interval);
        foreach (['overview', 'distribution', 'breakdown'] as $slide) {
            save_setting('slideshow_slide_' . $slide, isset($_POST['slideshow_slide_' . $slide]) ? '1' : '0');
        }
        log_activity('Updated dashboard slideshow settings', 'setting', 'interval='.$interval);
        flash('msg', 'Slideshow settings saved.');

    } elseif ($action === 'save_groq') {
        $model = trim($_POST['groq_model'] ?? '');
        if ($model === '') $model = groq_model();
        save_setting('groq_model', $model);
        // Only update the key when a new one is typed (never clear it on re-save).
        if (trim($_POST['groq_api_key'] ?? '') !== '') {
            save_setting('groq_api_key', trim($_POST['groq_api_key']));
        }
        save_setting('assistant_enabled', isset($_POST['assistant_enabled']) ? '1' : '0');
        log_activity('Updated AI assistant settings', 'setting', 'groq_model='.$model);
        flash('msg', 'AI assistant settings saved.');

    } elseif ($action === 'clear_groq_key') {
        save_setting('groq_api_key', '');
        log_activity('Cleared the AI assistant API key', 'setting', 'groq_api_key');
        flash('msg', 'The AI assistant API key has been removed.');

    } elseif ($action === 'test_groq') {
        $res = groq_test_connection();
        if ($res['ok']) {
            $msg = 'Connection successful — the AI assistant is answering as model ' . $res['model'] . '.';
            if (!empty($res['served']) && $res['served'] !== $res['model']) {
                $msg .= ' The service reported the model as ' . $res['served'] . '.';
            }
            flash('msg', $msg);
        } else {
            flash('err', 'Connection failed: ' . $res['error']);
        }

    } elseif ($action === 'add_leave_type') {
        $name = trim($_POST['name'] ?? '');
        $minRole = $_POST['min_role'] ?? 'station_commander';
        if (!isset(ROLE_RANKS[$minRole])) $minRole = 'station_commander';
        if ($name === '') {
            flash('err', 'Enter a leave type name.');
        } else {
            try {
                $pdo->prepare("INSERT INTO leave_types (name,min_role) VALUES (?,?)")->execute([$name, $minRole]);
                log_activity('Added leave type', 'setting', $name);
                flash('msg', 'Leave type "'.$name.'" added.');
            } catch (\PDOException $ex) {
                flash('err', 'That leave type already exists.');
            }
        }

    } elseif ($action === 'toggle_leave_type') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE leave_types SET active = 1 - active WHERE id=?")->execute([$id]);
        flash('msg', 'Leave type updated.');

    } elseif ($action === 'delete_leave_type') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM leave_types WHERE id=?")->execute([$id]);
        log_activity('Deleted leave type', 'setting', (string)$id);
        flash('msg', 'Leave type removed.');
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

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(540px,100%),1fr));gap:20px">

<!-- ══════════════════ REPORT SUBMISSION MODE ══════════════════ -->
<?php $rmode = get_setting('report_submission_mode', 'hierarchical') === 'direct' ? 'direct' : 'hierarchical'; ?>
<details class="cfg-tile">
  <summary class="cfg-sum">
    <div class="chr" style="flex:1;border:none;padding:0">
      <h3>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;vertical-align:-3px;margin-right:6px;color:var(--primary)"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
        Report Submission Mode
      </h3>
      <span class="badge" style="<?= $rmode==='direct' ? 'background:#fef3c7;color:#92400e' : 'background:#dcfce7;color:#166534' ?>">
        <?= $rmode==='direct' ? 'Direct to HQ' : 'Hierarchical' ?>
      </span>
    </div>
    <span class="cfg-chev"></span>
  </summary>
  <div class="cfg-body">
  <p style="margin:0 0 14px;font-size:13px;color:var(--muted);max-width:720px">
    In <strong>Hierarchical</strong> mode every generated report is reviewed level by level up the command chain
    (each reviewer forwards or returns it for correction). In <strong>Direct to HQ</strong> mode, generated reports
    skip the intermediate levels and wait only for the Super Admin's approval.
  </p>
  <form method="post">
  <?= csrf_field() ?>
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
</details>

<!-- ══════════════════ SMTP CARD ══════════════════ -->
<details class="cfg-tile">
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
  <?= csrf_field() ?>
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
        <input type="text" name="smtp_from_name" value="<?= $s('smtp_from_name') ?>" placeholder="UPF HRA System">
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
  <?= csrf_field() ?>
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
<details class="cfg-tile">
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
  <?php
    $smsActive = sms_provider();
    $sv = fn(string $key, string $provider): string => $smsActive === $provider ? $s($key) : '';
  ?>
  <form method="post">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_sms">
    <div class="form-row">
      <div class="form-group">
        <label>SMS Provider</label>
        <select name="sms_provider">
          <?php foreach (['infobip'=>'Infobip','africas_talking'=>"Africa's Talking",'twilio'=>'Twilio','nexmo'=>'Vonage (Nexmo)'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= $smsActive===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Infobip -->
    <div class="form-row sms-provider-block" data-provider="infobip" style="<?= $smsActive === 'infobip' ? '' : 'display:none' ?>">
      <div class="form-group" style="flex:2">
        <label>API Key <span style="font-size:10px;color:var(--muted)">(Infobip App key)</span></label>
        <input type="text" name="sms_infobip_api_key" value="<?= $sv('sms_api_key','infobip') ?>" placeholder="Your Infobip App key" autocomplete="off">
      </div>
      <div class="form-group" style="flex:2">
        <label>Sender ID <span style="font-size:10px;color:var(--muted)">(optional)</span></label>
        <input type="text" name="sms_infobip_sender_id" value="<?= $sv('sms_sender_id','infobip') ?>" placeholder="UPF-HRAS">
      </div>
    </div>
    <div class="form-row sms-provider-block" data-provider="infobip" style="<?= $smsActive === 'infobip' ? '' : 'display:none' ?>">
      <div class="form-group" style="flex:2">
        <label>Base URL</label>
        <input type="text" name="sms_infobip_base_url" value="<?= $sv('sms_infobip_base_url','infobip') ?>" placeholder="https://xxxxx.api.infobip.com">
      </div>
    </div>

    <!-- Africa's Talking -->
    <div class="form-row sms-provider-block" data-provider="africas_talking" style="<?= $smsActive === 'africas_talking' ? '' : 'display:none' ?>">
      <div class="form-group" style="flex:2">
        <label>API Key <span style="font-size:10px;color:var(--muted)">(Africa's Talking API key)</span></label>
        <input type="text" name="sms_at_api_key" value="<?= $sv('sms_api_key','africas_talking') ?>" placeholder="Your Africa's Talking API key" autocomplete="off">
      </div>
      <div class="form-group" style="flex:2">
        <label>Username <span style="font-size:10px;color:var(--muted)">(sandbox or your AT username)</span></label>
        <input type="text" name="sms_at_username" value="<?= $sv('sms_username','africas_talking') ?>" placeholder="sandbox or your AT username">
      </div>
    </div>
    <div class="form-row sms-provider-block" data-provider="africas_talking" style="<?= $smsActive === 'africas_talking' ? '' : 'display:none' ?>">
      <div class="form-group" style="flex:2">
        <label>Sender ID / Short Code <span style="font-size:10px;color:var(--muted)">(registered with Africa's Talking)</span></label>
        <input type="text" name="sms_at_sender_id" value="<?= $sv('sms_sender_id','africas_talking') ?>" placeholder="UPF-HRAS">
      </div>
    </div>

    <!-- Twilio -->
    <div class="form-row sms-provider-block" data-provider="twilio" style="<?= $smsActive === 'twilio' ? '' : 'display:none' ?>">
      <div class="form-group" style="flex:2">
        <label>Account SID</label>
        <input type="text" name="sms_twilio_account_sid" value="<?= $sv('sms_api_key','twilio') ?>" placeholder="ACxxxxxxxxxxxxxxxx" autocomplete="off">
      </div>
      <div class="form-group" style="flex:2">
        <label>Auth Token</label>
        <input type="password" name="sms_twilio_auth_token" placeholder="<?= $smsActive === 'twilio' && get_setting('sms_api_secret') ? '•••••••• (leave blank to keep)' : 'Your Twilio auth token' ?>" autocomplete="new-password">
      </div>
    </div>
    <div class="form-row sms-provider-block" data-provider="twilio" style="<?= $smsActive === 'twilio' ? '' : 'display:none' ?>">
      <div class="form-group" style="flex:2">
        <label>From Number <span style="font-size:10px;color:var(--muted)">(Twilio phone number or verified sender)</span></label>
        <input type="text" name="sms_twilio_from" value="<?= $sv('sms_sender_id','twilio') ?>" placeholder="+1234567890">
      </div>
    </div>

    <!-- Vonage (Nexmo) -->
    <div class="form-row sms-provider-block" data-provider="nexmo" style="<?= $smsActive === 'nexmo' ? '' : 'display:none' ?>">
      <div class="form-group" style="flex:2">
        <label>API Key</label>
        <input type="text" name="sms_vonage_api_key" value="<?= $sv('sms_api_key','nexmo') ?>" placeholder="Your Vonage API key" autocomplete="off">
      </div>
      <div class="form-group" style="flex:2">
        <label>API Secret</label>
        <input type="password" name="sms_vonage_api_secret" placeholder="<?= $smsActive === 'nexmo' && get_setting('sms_api_secret') ? '•••••••• (leave blank to keep)' : 'Your Vonage API secret' ?>" autocomplete="new-password">
      </div>
    </div>
    <div class="form-row sms-provider-block" data-provider="nexmo" style="<?= $smsActive === 'nexmo' ? '' : 'display:none' ?>">
      <div class="form-group" style="flex:2">
        <label>From / Sender</label>
        <input type="text" name="sms_vonage_from" value="<?= $sv('sms_sender_id','nexmo') ?>" placeholder="UPF-HRAS or +1234567890">
      </div>
    </div>

    <button class="btn-icon bi-gold bi-lg" type="submit" style="gap:8px;padding:0 18px;width:auto;font-size:12px;font-weight:600;margin-top:4px">
      <?= ICO_SAVE ?> <span>Save SMS Settings</span>
    </button>
  </form>
  <div class="sms-provider-block" data-provider="infobip" style="margin-top:14px;background:var(--navy-50);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--muted);<?= $smsActive === 'infobip' ? '' : 'display:none' ?>">
    <strong style="color:var(--navy-700)">Infobip:</strong> Enter your API key and your personal base URL (e.g. <em>https://xxxxx.api.infobip.com</em>) from the Infobip portal.
    <br><strong style="color:var(--navy-700)">Sender ID:</strong> Use a sender registered on the Infobip account. Leave it blank to use the account default sender.
    <br><strong style="color:var(--navy-700)">Credit balance:</strong> Infobip must have sufficient SMS credit; messages are accepted but not delivered when the balance is zero.
  </div>
  <div class="sms-provider-block" data-provider="africas_talking" style="margin-top:14px;background:var(--navy-50);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--muted);<?= $smsActive === 'africas_talking' ? '' : 'display:none' ?>">
    <strong style="color:var(--navy-700)">Africa's Talking:</strong> Enter your API key and username (use <em>sandbox</em> while testing).
    <br><strong style="color:var(--navy-700)">Sender ID / Short Code:</strong> Must be registered and approved on your Africa's Talking account.
  </div>
  <div class="sms-provider-block" data-provider="twilio" style="margin-top:14px;background:var(--navy-50);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--muted);<?= $smsActive === 'twilio' ? '' : 'display:none' ?>">
    <strong style="color:var(--navy-700)">Twilio:</strong> Enter the Account SID and Auth Token from your Twilio console.
    <br><strong style="color:var(--navy-700)">From Number:</strong> Use a Twilio phone number or a verified sender.
  </div>
  <div class="sms-provider-block" data-provider="nexmo" style="margin-top:14px;background:var(--navy-50);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--muted);<?= $smsActive === 'nexmo' ? '' : 'display:none' ?>">
    <strong style="color:var(--navy-700)">Vonage (Nexmo):</strong> Enter the API key and API secret from your Vonage dashboard.
    <br><strong style="color:var(--navy-700)">From / Sender:</strong> Use an approved sender ID or a purchased number.
  </div>
  <div style="margin-top:14px;background:var(--navy-50);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--muted)">
    <strong style="color:var(--navy-700)">Leave SMS:</strong> Personnel are notified by SMS as soon as a leave request or extension is approved or rejected. Approved leave messages include the effective end date.
  </div>
  </div>
</details>

<!-- ══════════════════ AI ASSISTANT CARD ══════════════════ -->
<details class="cfg-tile">
  <summary class="cfg-sum">
    <div class="chr" style="flex:1;border:none;padding:0">
      <h3>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;vertical-align:-3px;margin-right:6px;color:var(--primary)"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 8v4"/><path d="M10 10h4"/></svg>
        AI Assistant (Groq)
      </h3>
      <?php if (groq_enabled()): ?>
        <span class="badge" style="background:#dcfce7;color:#166534">● Enabled</span>
      <?php elseif (get_setting('assistant_enabled','1') === '0'): ?>
        <span class="badge" style="background:#fee2e2;color:#991b1b">● Switched off</span>
      <?php else: ?>
        <span class="badge" style="background:#fef3c7;color:#92400e">● Not Configured</span>
      <?php endif; ?>
    </div>
    <span class="cfg-chev"></span>
  </summary>
  <div class="cfg-body">
    <p style="margin:0 0 14px;font-size:13px;color:var(--muted);max-width:760px">
      The <strong>HRAS Assistant</strong> answers officers' questions about how to use the system.
      It runs on Groq's API. The key is stored on the server only — it is never shown back to
      users and never sent to the browser.
    </p>
    <form method="post">
  <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_groq">
      <div class="form-row">
        <div class="form-group" style="flex:2;min-width:220px">
          <label>Groq API Key <?= get_setting('groq_api_key') ? '<span style="font-size:10px;color:var(--green)">✓ Saved</span>' : '' ?></label>
          <input type="password" name="groq_api_key" placeholder="<?= get_setting('groq_api_key') ? 'Leave blank to keep the current key' : 'gsk_…' ?>" autocomplete="new-password">
        </div>
        <div class="form-group" style="flex:2;min-width:220px">
          <label>Model</label>
          <select name="groq_model">
            <?php foreach (GROQ_MODELS as $mid => $mlabel): ?>
              <option value="<?= e($mid) ?>" <?= $configuredGroqModel === $mid ? 'selected' : '' ?>><?= e($mlabel) ?></option>
            <?php endforeach; ?>
            <?php $custom = $configuredGroqModel; if (!isset(GROQ_MODELS[$custom])): ?>
              <option value="<?= e($custom) ?>" selected><?= e($custom) ?> (custom)</option>
            <?php endif; ?>
          </select>
          <small class="muted">If this model is unavailable to your Groq key, the assistant checks available chat models and retries with a supported option.</small>
        </div>
      </div>
      <div style="margin:4px 0 12px">
        <label class="switch">
          <input type="checkbox" name="assistant_enabled" value="1" <?= get_setting('assistant_enabled','1') !== '0' ? 'checked' : '' ?>>
          <span class="slider"></span>
          <span class="switch-label"><strong>HRAS Assistant available to all users</strong><br><small>When off, the assistant screen shows a notice and no questions are sent.</small></span>
        </label>
      </div>
      <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <button class="btn-icon bi-gold bi-lg" type="submit" style="gap:8px;padding:0 18px;width:auto;font-size:12px;font-weight:600">
          <?= ICO_SAVE ?> <span>Save AI Settings</span>
        </button>
      </div>
    </form>
    <!-- Test connection -->
    <div style="margin-top:16px;padding-top:14px;border-top:1px dashed var(--border)">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:10px">Test Connection</div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <form method="post" style="display:inline">
  <?= csrf_field() ?>
          <input type="hidden" name="action" value="test_groq">
          <button class="btn-icon bi-primary" type="submit" style="gap:8px;padding:0 16px;width:auto;font-size:12px">
            <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Test Connection
          </button>
        </form>
        <?php if (get_setting('groq_api_key')): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Remove the stored AI assistant API key?')">
  <?= csrf_field() ?>
          <input type="hidden" name="action" value="clear_groq_key">
          <button class="btn-icon bi-danger" type="submit" title="Remove the stored key"><?= ICO_TRASH ?></button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <div style="margin-top:14px;background:var(--navy-50);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--muted)">
      <strong style="color:var(--navy-700)">Get a key:</strong> create a free account at
      <strong>console.groq.com</strong> → API Keys → Create API Key, then paste it above.
      <br><strong style="color:var(--navy-700)">Server-side alternative:</strong> set the
      <code>GROQ_API_KEY</code> environment variable, which takes precedence over the stored key.
    </div>
  </div>
</details>

<!-- ══════════════════ DASHBOARD SLIDESHOW CARD ══════════════════ -->
<?php
  $ssEnabled  = get_setting('slideshow_enabled', '1') !== '0';
  $ssAutoplay = get_setting('slideshow_autoplay', '0') === '1';
  $ssInterval = (int)get_setting('slideshow_interval', '10');
  if ($ssInterval < 5) $ssInterval = 10;
  $ssSlides = [
    'overview'     => 'Personnel status overview',
    'distribution' => 'Status distribution charts',
    'breakdown'    => 'Top 10 unit breakdown',
  ];
?>
<details class="cfg-tile">
  <summary class="cfg-sum">
    <div class="chr" style="flex:1;border:none;padding:0">
      <h3>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;vertical-align:-3px;margin-right:6px;color:var(--primary)"><polygon points="5 3 19 12 5 21 5 3"/><rect x="2" y="2" width="20" height="20" rx="2" opacity=".25"/></svg>
        Dashboard Slideshow
      </h3>
      <?php if ($ssEnabled): ?>
        <span class="badge" style="background:#dcfce7;color:#166534">● Enabled</span>
      <?php else: ?>
        <span class="badge" style="background:#fee2e2;color:#991b1b">● Switched off</span>
      <?php endif; ?>
    </div>
    <span class="cfg-chev"></span>
  </summary>
  <div class="cfg-body">
    <p style="margin:0 0 14px;font-size:13px;color:var(--muted);max-width:760px">
      Control the presentation mode available on the dashboard. The slideshow rotates through the
      selected slides without reloading the page.
    </p>
    <form method="post">
  <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_slideshow">
      <div style="margin:0 0 12px">
        <label class="switch">
          <input type="checkbox" name="slideshow_enabled" value="1" <?= $ssEnabled ? 'checked' : '' ?>>
          <span class="slider"></span>
          <span class="switch-label"><strong>Show slideshow controls on the dashboard</strong><br><small>When off, the Start slideshow button is hidden for everyone.</small></span>
        </label>
      </div>
      <div style="margin:0 0 12px">
        <label class="switch">
          <input type="checkbox" name="slideshow_autoplay" value="1" <?= $ssAutoplay ? 'checked' : '' ?>>
          <span class="slider"></span>
          <span class="switch-label"><strong>Start the slideshow automatically</strong><br><small>Begins rotating as soon as the dashboard loads.</small></span>
        </label>
      </div>
      <div class="form-row">
        <div class="form-group" style="max-width:220px">
          <label>Seconds per slide</label>
          <input type="number" name="slideshow_interval" min="5" max="300" step="1" value="<?= $ssInterval ?>">
          <small class="muted">Between 5 and 300 seconds.</small>
        </div>
      </div>
      <div style="margin:4px 0 12px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:8px">Slides to include</div>
        <?php foreach ($ssSlides as $ssKey => $ssLabel): ?>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--navy-700);margin-bottom:8px">
          <input type="checkbox" name="slideshow_slide_<?= $ssKey ?>" value="1" <?= get_setting('slideshow_slide_'.$ssKey, '1') !== '0' ? 'checked' : '' ?>>
          <?= e($ssLabel) ?>
        </label>
        <?php endforeach; ?>
      </div>
      <button class="btn-icon bi-gold bi-lg" type="submit" style="gap:8px;padding:0 18px;width:auto;font-size:12px;font-weight:600">
        <?= ICO_SAVE ?> <span>Save Slideshow Settings</span>
      </button>
    </form>
  </div>
</details>

</div><!-- end grid -->

<!-- ══════════════════ LEAVE TYPES ══════════════════ -->
<?php $leaveTypes = leave_types_list($pdo, false); ?>
<details class="cfg-tile" style="margin-top:20px">
  <summary class="cfg-sum">
    <div class="chr" style="flex:1;border:none;padding:0">
      <h3>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;vertical-align:-3px;margin-right:6px;color:var(--primary)"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Leave Types
      </h3>
      <span class="badge badge-admin"><?= count($leaveTypes) ?></span>
    </div>
    <span class="cfg-chev"></span>
  </summary>
  <div class="cfg-body">
  <p style="margin:0 0 14px;font-size:13px;color:var(--muted);max-width:760px">
    Configure the leave types available to personnel and the minimum rank required to approve each type.
    Approvers of a type must hold the selected rank or higher.
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Leave Type</th><th>Minimum Approver Rank</th><th>Status</th><th style="width:120px"></th></tr></thead>
      <tbody>
        <?php foreach ($leaveTypes as $lt): ?>
        <tr>
          <td><strong><?= e($lt['name']) ?></strong></td>
          <td><?= e(role_label($lt['min_role'])) ?></td>
          <td><?= $lt['active'] ? '<span class="badge badge-present">Active</span>' : '<span class="badge badge-awol">Disabled</span>' ?></td>
          <td style="white-space:nowrap">
            <form method="post" style="display:inline">
  <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_leave_type">
              <input type="hidden" name="id" value="<?= (int)$lt['id'] ?>">
              <button class="btn-icon bi-secondary bi-sm" title="<?= $lt['active']?'Disable':'Enable' ?>"><?= $lt['active'] ? ICO_CANCEL : ICO_OK ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Remove this leave type?')">
  <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_leave_type">
              <input type="hidden" name="id" value="<?= (int)$lt['id'] ?>">
              <button class="btn-icon bi-danger bi-sm" title="Delete"><?= ICO_TRASH ?></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$leaveTypes): ?><tr><td colspan="4" style="text-align:center;color:var(--muted);padding:20px">No leave types configured.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <form method="post" class="form-row" style="margin-top:14px;align-items:flex-end">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_leave_type">
    <div class="form-group" style="flex:2"><label>New Leave Type</label><input type="text" name="name" required placeholder="e.g. Paternity Leave"></div>
    <div class="form-group" style="flex:2"><label>Minimum Approver Rank</label>
      <select name="min_role">
        <?php foreach (['station_commander','division_commander','regional_commander'] as $rv): ?>
        <option value="<?= $rv ?>"><?= e(role_label($rv)) ?> and above</option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn-icon bi-gold" type="submit" title="Add leave type"><?= ICO_PLUS ?></button>
  </form>
  </div>
</details>

<script>
(function () {
  var provider = document.querySelector('select[name="sms_provider"]');
  if (!provider) return;
  var blocks = document.querySelectorAll('.sms-provider-block');
  function sync() {
    blocks.forEach(function (block) {
      block.style.display = block.getAttribute('data-provider') === provider.value ? '' : 'none';
    });
  }
  provider.addEventListener('change', sync);
  sync();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
