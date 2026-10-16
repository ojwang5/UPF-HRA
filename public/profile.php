<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_login();
$page = 'profile';
$page_title = 'My Profile';
$pdo = db();

/* ── POST handlers ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $fname = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($fname === '') {
            flash('err', 'Full name is required.');
        } else {
            $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?")
                ->execute([$fname, $email ?: null, $phone ?: null, (int)$user['id']]);
            log_activity('Edit Profile', 'user', $fname, (int)$user['id'], 'Self-service profile update');
            flash('msg', 'Profile updated successfully.');
        }
        header('Location:/profile.php'); exit;
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($current === '' || $new === '' || $confirm === '') {
            flash('err', 'All password fields are required.');
        } elseif (!password_verify($current, $user['password_hash'])) {
            flash('err', 'Your current password is incorrect.');
        } elseif ($new !== $confirm) {
            flash('err', 'New password and confirmation do not match.');
        } elseif ($new === $current) {
            flash('err', 'The new password must be different from your current password.');
        } elseif (!password_policy_ok($new)) {
            flash('err', 'Weak password — ' . password_policy_hint());
        } else {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
            record_login_attempt($user['username'], true); // clear any lockout
            log_activity('Change Password', 'user', $user['full_name'], (int)$user['id'], 'Self-service password change');
            flash('msg', 'Your password has been changed successfully.');
        }
        header('Location:/profile.php'); exit;
    }

    header('Location:/profile.php'); exit;
}

/* ── Account details ── */
$detail = $pdo->query("SELECT rg.name AS region_name, dv.name AS division_name, st.name AS station_name,
        pt.name AS post_name, dir.name AS directorate_name, un.name AS unit_name
    FROM users u
    LEFT JOIN regions      rg  ON rg.id=u.region_id
    LEFT JOIN divisions    dv  ON dv.id=u.division_id
    LEFT JOIN stations     st  ON st.id=u.station_id
    LEFT JOIN posts        pt  ON pt.id=u.post_id
    LEFT JOIN directorates dir ON dir.id=u.directorate_id
    LEFT JOIN units        un  ON un.id=u.unit_id
    WHERE u.id=" . (int)$user['id'])->fetch() ?: [];

$lastLogin = null;
try {
    $lastLogin = $pdo->query("SELECT created_at FROM activity_log
        WHERE user_id=" . (int)$user['id'] . " AND action='Login'
        ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null;
} catch (\Throwable $e) {}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>My Profile</h1>
    <div class="desc">Your account details and password</div>
  </div>
</div>

<?php if ($m=flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m=flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;align-items:start">

  <!-- ═══════════════════ ACCOUNT DETAILS ═══════════════════ -->
  <div class="card">
    <div class="chr">
      <h3>Account Details</h3>
      <button class="btn-icon bi-secondary bi-sm" title="Edit profile" onclick="openProfileModal()"><?= ICO_EDIT ?></button>
    </div>
    <table style="width:100%">
      <tbody>
        <tr><td class="muted" style="width:40%">Full Name</td><td><strong><?= e($user['full_name']) ?></strong></td></tr>
        <tr><td class="muted">Username</td><td style="font-family:monospace"><?= e($user['username']) ?></td></tr>
        <tr><td class="muted">Role</td><td><span class="badge badge-admin"><?= e(role_label($user['role'])) ?></span></td></tr>
        <tr><td class="muted">Email</td><td><?= e($user['email'] ?? '—') ?></td></tr>
        <tr><td class="muted">Phone</td><td><?= e($user['phone'] ?? '—') ?></td></tr>
        <?php if (!empty($detail['directorate_name'])): ?>
        <tr><td class="muted">Directorate</td><td><?= e($detail['directorate_name']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($detail['unit_name'])): ?>
        <tr><td class="muted">Unit</td><td><?= e($detail['unit_name']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($detail['region_name'])): ?>
        <tr><td class="muted">Region</td><td><?= e($detail['region_name']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($detail['division_name'])): ?>
        <tr><td class="muted">Division</td><td><?= e($detail['division_name']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($detail['station_name'])): ?>
        <tr><td class="muted">Station</td><td><?= e($detail['station_name']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($detail['post_name'])): ?>
        <tr><td class="muted">Post</td><td><?= e($detail['post_name']) ?></td></tr>
        <?php endif; ?>
        <?php if ($lastLogin): ?>
        <tr><td class="muted">Last Login</td><td style="font-size:12px"><?= e($lastLogin) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ═══════════════════ CHANGE PASSWORD ═══════════════════ -->
  <div class="card">
    <div class="chr"><h3><?= ICO_KEY ?> Change Password</h3></div>
    <form method="post" action="/profile.php" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">
      <div class="form-group" style="margin-bottom:12px">
        <label>Current Password</label>
        <input type="password" name="current_password" required autocomplete="current-password">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>New Password</label>
        <input type="password" name="new_password" id="new_password" required autocomplete="new-password"
               minlength="<?= PASSWORD_MIN_LENGTH ?>">
        <div class="pw-strength" aria-live="polite">
          <div class="pw-strength-track"><div class="pw-strength-fill" id="pw-strength-fill"></div></div>
          <div class="pw-strength-label" id="pw-strength-label"></div>
        </div>
        <div class="muted" style="font-size:11px;margin-top:4px"><?= e(password_policy_hint()) ?></div>
      </div>
      <div class="form-group" style="margin-bottom:18px">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" required autocomplete="new-password"
               minlength="<?= PASSWORD_MIN_LENGTH ?>">
      </div>
      <button class="btn" type="submit"><?= ICO_SAVE ?> Update Password</button>
    </form>
  </div>

</div>

<!-- ═══════════════════ EDIT PROFILE MODAL DRAWER ═══════════════════ -->
<div class="modal-overlay" id="profile-modal">
  <div class="modal-drawer">
    <div class="modal-head">
      <div>
        <h2>Edit Profile</h2>
        <div class="subtitle">Update your personal contact details</div>
      </div>
      <button class="modal-close" type="button" onclick="closeProfileModal()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <form method="post" action="/profile.php" id="profile-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">

        <div class="modal-section">
          <div class="modal-section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Identity
          </div>
          <div class="form-group" style="margin-bottom:12px">
            <label>Username</label>
            <input type="text" value="<?= e($user['username']) ?>" disabled style="font-family:monospace">
            <small style="color:var(--muted);font-size:11px">Username and role are managed by your administrator.</small>
          </div>
          <div class="form-group" style="margin-bottom:12px">
            <label>Full Name <span style="color:var(--red)">*</span></label>
            <input type="text" name="full_name" required value="<?= e($user['full_name']) ?>" placeholder="Surname, Other Names">
          </div>
        </div>

        <div class="modal-section">
          <div class="modal-section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.63 3.38 2 2 0 0 1 3.6 1.2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.88a16 16 0 0 0 6.06 6.06l1.69-1.69a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            Contact Details
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:2;min-width:200px">
              <label>Email Address</label>
              <input type="email" name="email" value="<?= e($user['email'] ?? '') ?>" placeholder="user@upf.go.ug">
            </div>
            <div class="form-group" style="min-width:160px">
              <label>Phone Number</label>
              <input type="tel" name="phone" value="<?= e($user['phone'] ?? '') ?>" placeholder="+256 700 000000">
            </div>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" type="button" onclick="closeProfileModal()">Cancel</button>
      <button class="btn" type="submit" form="profile-form"><?= ICO_SAVE ?> Save Changes</button>
    </div>
  </div>
</div>

<script>
function openProfileModal(){
  var m = document.getElementById('profile-modal');
  m.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeProfileModal(){
  var m = document.getElementById('profile-modal');
  m.classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e){
  if(e.key === 'Escape') closeProfileModal();
});
document.getElementById('profile-modal').addEventListener('click', function(e){
  if(e.target === this) closeProfileModal();
});

/* ── Live password strength meter ───────────────────────────────────────────
   Weak = fails the policy (needs capital, small, special, >6 chars).
   Medium = policy met but short (<10) or missing a digit.
   Strong = policy met, 10+ chars and contains a digit. */
(function(){
  var input = document.getElementById('new_password');
  var fill  = document.getElementById('pw-strength-fill');
  var label = document.getElementById('pw-strength-label');
  if(!input || !fill || !label) return;

  function strength(pw){
    var upper = /[A-Z]/.test(pw), lower = /[a-z]/.test(pw);
    var digit = /[0-9]/.test(pw), special = /[^A-Za-z0-9]/.test(pw);
    if(!(upper && lower && special && pw.length > 6)) return 'weak';
    if(pw.length >= 10 && digit) return 'strong';
    return 'medium';
  }

  function render(){
    var pw = input.value;
    fill.classList.remove('is-weak','is-medium','is-strong');
    label.classList.remove('is-weak','is-medium','is-strong');
    if(!pw){ label.textContent = ''; return; }
    var level = strength(pw);
    fill.classList.add('is-' + level);
    label.classList.add('is-' + level);
    label.textContent = level.charAt(0).toUpperCase() + level.slice(1) + ' password';
  }

  input.addEventListener('input', render);
  render();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
