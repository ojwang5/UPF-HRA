<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/notifications.php';
$user = current_user();
$page = $page ?? '';
$page_title = $page_title ?? '';
$role = $user['role'] ?? '';

$all_nav = [
  'dashboard'    => ['href'=>'/','label'=>'Dashboard','min_rank'=>1,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>'],
  'employees'    => ['href'=>'/employees.php','label'=>'Personnel','min_rank'=>1,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
  'daily'        => ['href'=>'/daily-status.php','label'=>'Daily Status','min_rank'=>1,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M9 16l2 2 4-4"/></svg>'],
  'reports'      => ['href'=>'/reports.php','label'=>'Reports','min_rank'=>2,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>'],
  'leave'        => ['href'=>'/leave-requests.php','label'=>'Leave Requests','min_rank'=>1,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>'],
  'history'      => ['href'=>'/history.php','label'=>'History','min_rank'=>3,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><polyline points="3 3 3 8 8 8"/><polyline points="12 7 12 12 15 15"/></svg>'],
  'users'        => ['href'=>'/users.php','label'=>'Users','min_rank'=>2,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>'],
  'hierarchy'    => ['href'=>'/hierarchy.php','label'=>'Structure','min_rank'=>6,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="2"/><path d="M12 7v4m0 0H8a2 2 0 0 0-2 2v1m6-3h4a2 2 0 0 1 2 2v1"/><circle cx="6" cy="17" r="2"/><circle cx="12" cy="17" r="2"/><circle cx="18" cy="17" r="2"/></svg>'],
  'notifications'=> ['href'=>'/notifications.php','label'=>'Notifications','min_rank'=>1,
    'icon'=>'<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'],
];

$nav_items = [];
$userRank = role_rank($role);
foreach ($all_nav as $k => $n) {
    if ($user && $userRank >= $n['min_rank']) { $n['key']=$k; $nav_items[]=$n; }
}
$unreadCount = $user ? unread_notification_count($user) : 0;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ? $page_title.' — ' : '') ?><?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="icon" type="image/jpeg" href="/assets/logo.jpg">
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <?php if ($user): ?>
    <!-- Hamburger — MOBILE ONLY (hidden on desktop via CSS) -->
    <button class="hamburger" id="hamburger" aria-label="Open menu" onclick="openDrawer()">
      <span></span><span></span><span></span>
    </button>
    <?php endif; ?>
    <img src="/assets/logo.jpg" alt="UPF" class="logo">
    <div class="brand">
      <div class="org"><?= e(APP_ORG) ?></div>
      <div class="sys"><?= e(APP_NAME) ?></div>
      <div class="motto"><?= e(APP_MOTTO) ?></div>
    </div>
    <?php if ($user): ?>
      <a href="/notifications.php" class="bell" title="Notifications">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <?php if ($unreadCount>0): ?><span class="bell-badge"><?= min($unreadCount,99) ?></span><?php endif; ?>
      </a>
      <div class="user-info">
        <div class="name"><?= e($user['full_name']) ?></div>
        <div class="meta"><?= e(role_label($role)) ?><?= ($sl=user_scope_label($user))!=='HQ' ? ' · '.e($sl) : '' ?></div>
      </div>
      <a class="btn btn-ghost btn-sm" href="/logout.php">Sign out</a>
    <?php endif; ?>
  </div>
</header>

<?php if ($user): ?>

<!-- Mobile overlay backdrop -->
<div class="drawer-backdrop" id="drawer-backdrop" onclick="closeDrawer()"></div>

<div class="app">
  <!-- Sidebar -->
  <nav class="sidebar sidebar-wrap" id="sidebar">
    <!-- Desktop collapse toggle arrow inside sidebar -->
    <button class="sb-collapse-btn" id="sb-collapse-btn" onclick="toggleSidebar()" title="Collapse sidebar">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    </button>

    <div class="nav-label">Menu</div>
    <?php foreach ($nav_items as $n): ?>
      <a href="<?= $n['href'] ?>" class="<?= $page===$n['key']?'active':'' ?>" title="<?= e($n['label']) ?>">
        <?= $n['icon'] ?><span class="nav-label-text"><?= e($n['label']) ?></span>
        <?php if ($n['key']==='notifications' && $unreadCount>0): ?><span class="nav-badge"><?= $unreadCount ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>

    <!-- Mobile close button -->
    <button class="sb-close-btn" onclick="closeDrawer()" title="Close menu">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>

    <div class="sb-footer"><span class="nav-label-text"><?= e(role_label($role)) ?> · v2.0</span></div>
  </nav>

  <main class="content">
<?php endif; ?>

<script>
(function(){
  // Restore sidebar state (desktop only)
  if(window.innerWidth > 768) {
    if(localStorage.getItem('sb_collapsed')==='1') {
      document.getElementById('sidebar')?.classList.add('collapsed');
      document.getElementById('sb-collapse-btn')?.classList.add('flipped');
    }
  }
})();

function toggleSidebar(){
  var sb = document.getElementById('sidebar');
  var btn = document.getElementById('sb-collapse-btn');
  if(!sb) return;
  var isCollapsed = sb.classList.toggle('collapsed');
  btn?.classList.toggle('flipped', isCollapsed);
  localStorage.setItem('sb_collapsed', isCollapsed ? '1' : '0');
}

function openDrawer(){
  document.getElementById('sidebar')?.classList.add('open');
  document.getElementById('drawer-backdrop')?.classList.add('show');
  document.body.style.overflow='hidden';
}

function closeDrawer(){
  document.getElementById('sidebar')?.classList.remove('open');
  document.getElementById('drawer-backdrop')?.classList.remove('show');
  document.body.style.overflow='';
}

// Close drawer on resize to desktop
window.addEventListener('resize', function(){
  if(window.innerWidth > 768) closeDrawer();
});
</script>
