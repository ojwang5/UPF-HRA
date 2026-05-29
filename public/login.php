<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login(trim($_POST['username']??''), $_POST['password']??'')) { header('Location:/'); exit; }
    $error = 'Invalid username or password.';
}
if (current_user()) { header('Location:/'); exit; }
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/style.css">
</head><body class="login-page">
<form class="login-card" method="post" action="/login.php">
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
  <button class="btn" style="width:100%" type="submit">Sign in</button>
  <details style="margin-top:14px">
    <summary class="muted" style="font-size:11px;text-align:center;cursor:pointer">Demo accounts</summary>
    <table style="font-size:11px;margin-top:8px;width:100%;border-collapse:collapse">
      <tr><td class="muted">Super Admin</td><td><code>admin / admin123</code></td></tr>
      <tr><td class="muted">Regional Cmd (KLA)</td><td><code>rcmd_kla / rcmd123</code></td></tr>
      <tr><td class="muted">Division Cmd</td><td><code>dcmd_kcd / dcmd123</code></td></tr>
      <tr><td class="muted">Station Cmd</td><td><code>scmd_cps / scmd123</code></td></tr>
      <tr><td class="muted">Post Commander</td><td><code>pcmd_cps1 / pcmd123</code></td></tr>
      <tr><td class="muted">Field Officer</td><td><code>offr_cps1 / offr123</code></td></tr>
    </table>
  </details>
</form>
</body></html>
