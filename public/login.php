<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$error = flash('err');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $locked   = login_lockout_minutes($username);
    if ($locked > 0) {
        $error = "Too many failed attempts. This account is locked. Please try again in {$locked} minute".($locked === 1 ? '' : 's').'.';
        try {
            db()->prepare("INSERT INTO activity_log (user_name,action,entity_type,entity_label,details,ip_address,created_at) VALUES (?,?,?,?,?,?,datetime('now','localtime'))")
                ->execute([$username, 'Blocked Login', 'user', $username, 'Account locked (too many attempts)', $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (\Throwable $e) {}
    } elseif (login($username, $_POST['password'] ?? '')) {
        record_login_attempt($username, true);
        log_activity('Login', 'user', $username, 0, 'Successful login');
        header('Location:/'); exit;
    } else {
        record_login_attempt($username, false);
        $locked = login_lockout_minutes($username);
        $error = $locked > 0
            ? "Too many failed attempts. This account is locked for {$locked} minute".($locked === 1 ? '' : 's').'.'
            : 'Invalid username or password.';
        try {
            db()->prepare("INSERT INTO activity_log (user_name,action,entity_type,entity_label,details,ip_address,created_at) VALUES (?,?,?,?,?,?,datetime('now','localtime'))")
                ->execute([$username, 'Failed Login', 'user', $username, 'Invalid credentials', $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (\Throwable $e) {}
    }
}
if (current_user()) { header('Location:/'); exit; }
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/style.css">
</head><body class="login-page">
<form class="login-card" method="post" action="/login.php">
  <?= csrf_field() ?>
  <div class="brand-block">
    <img src="/assets/logo.jpg" alt="UPF">
    <div class="org"><?= e(APP_ORG) ?></div>
    <div class="sys"><?= e(APP_NAME) ?></div>
    <div class="motto"><?= e(APP_MOTTO) ?></div>
  </div>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <div class="form-group" style="margin-bottom:12px">
    <label>Username</label>
    <input type="text" name="username" required autofocus value="<?= e($_POST['username']??'') ?>" autocomplete="username">
  </div>
  <div class="form-group" style="margin-bottom:18px">
    <label>Password</label>
    <input type="password" name="password" required autocomplete="current-password">
  </div>
  <button class="btn btn-block" type="submit">Sign in</button>
  <details style="margin-top:14px">
    <summary class="muted" style="font-size:11px;text-align:center;cursor:pointer">Demo accounts</summary>
    <table style="font-size:11px;margin-top:8px;width:100%;border-collapse:collapse">
      <tr><td class="muted">Super Admin</td><td><code>admin / admin123</code></td></tr>
      <tr><td class="muted">Regional Cmd (KLA)</td><td><code>rcmd_kla / rcmd123</code></td></tr>
      <tr><td class="muted">Directorate Cmd (Ops)</td><td><code>dir_ops / dir123</code></td></tr>
      <tr><td class="muted">Unit Cmd (Gen. Duty)</td><td><code>unit_gd / unit123</code></td></tr>
      <tr><td class="muted">Division Cmd</td><td><code>dcmd_kcd / dcmd123</code></td></tr>
      <tr><td class="muted">Station Cmd</td><td><code>scmd_cps / scmd123</code></td></tr>
      <tr><td class="muted">Post Commander</td><td><code>pcmd_cps1 / pcmd123</code></td></tr>
      <tr><td class="muted">Field Officer</td><td><code>offr_cps1 / offr123</code></td></tr>
    </table>
  </details>
</form>
</body></html>
