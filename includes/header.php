<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/communications.php';
require_once __DIR__ . '/nav.php';
$user = current_user();
$page = $page ?? '';
$page_title = $page_title ?? '';
$page_css = $page_css ?? '';
$role = $user['role'] ?? '';

$all_nav = all_nav();

$nav_items = [];
if ($user) {
    foreach (nav_for_user($user) as $k => $n) {
        $nav_items[] = $n;
    }
}
$unreadCount = $user ? unread_notification_count($user) : 0;
$unreadMessages = $user ? inbound_unread_count($user) : 0;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ? $page_title.' — ' : '') ?><?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/style.css">
<?php if (!empty($page_css)): ?>
<link rel="stylesheet" href="<?= e($page_css) ?>">
<?php endif; ?>
<link rel="icon" type="image/jpeg" href="/assets/logo.jpg">
<script>
  // Apply saved theme before first paint to avoid flash
  (function(){
    try{
      var t = localStorage.getItem('upf-theme');
      if(t && (t==='dark' || t==='light')) document.documentElement.setAttribute('data-theme', t);
    }catch(e){}
  })();
</script>
</head>
<body>

<script>
/* CSRF: expose the session token and make sure every POST form carries it
   (covers any form added dynamically or missed in markup). */
window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('form').forEach(function (f) {
    var m = (f.getAttribute('method') || 'get').toLowerCase();
    if (m !== 'post') return;
    if (!f.querySelector('input[name="csrf_token"]')) {
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = 'csrf_token'; i.value = window.CSRF_TOKEN;
      f.appendChild(i);
    }
  });
});
</script>

<header class="topbar">
  <div class="topbar-inner">
    <?php if ($user): ?>
    <!-- Hamburger — MOBILE ONLY (hidden on desktop via CSS) -->
    <button class="hamburger" id="hamburger" type="button" aria-label="Open menu"
            aria-controls="sidebar" aria-expanded="false" onclick="openDrawer()">
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
      <a href="/communications.php" class="bell" title="Messages">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        <?php if ($unreadMessages>0): ?><span class="bell-badge"><?= min($unreadMessages,99) ?></span><?php endif; ?>
      </a>
      <button class="bell theme-toggle" id="theme-toggle" title="Change theme" aria-label="Toggle theme">
        <svg class="ico-theme theme-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        <svg class="ico-theme theme-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
      </button>
      <a href="/profile.php" class="user-info" title="My Profile" style="text-decoration:none;color:inherit">
        <div class="name"><?= e($user['full_name']) ?></div>
        <div class="meta"><?= e(role_label($role)) ?><?= ($sl=user_scope_label($user))!=='HQ' ? ' · '.e($sl) : '' ?></div>
      </a>
      <a class="btn btn-ghost btn-sm" href="/profile.php">Profile</a>
      <a class="btn btn-ghost btn-sm" href="/logout.php">Sign out</a>
    <?php endif; ?>
  </div>
</header>

<?php if ($user): ?>

<!-- Mobile overlay backdrop -->
<div class="drawer-backdrop" id="drawer-backdrop" onclick="closeDrawer()"></div>

<div class="app">
  <!-- Sidebar -->
  <nav class="sidebar sidebar-wrap" id="sidebar" aria-label="Main navigation">
    <!-- Desktop collapse toggle arrow inside sidebar -->
    <button class="sb-collapse-btn" id="sb-collapse-btn" type="button" onclick="toggleSidebar()" title="Collapse sidebar" aria-label="Collapse sidebar">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    </button>

    <!-- Mobile drawer header: identity + close. Must be first so the top of the
         list is never hidden behind the sticky topbar or below the fold. -->
    <div class="sb-drawer-head">
      <img src="/assets/logo.jpg" alt="" class="sb-drawer-logo">
      <div class="sb-drawer-id">
        <div class="n"><?= e($user['full_name']) ?></div>
        <div class="m"><?= e(role_label($role)) ?><?= ($sl=user_scope_label($user))!=='HQ' ? ' · '.e($sl) : '' ?></div>
      </div>
      <button class="sb-close-btn" type="button" onclick="closeDrawer()" title="Close menu" aria-label="Close menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <div class="nav-label">Menu</div>
    <?php foreach ($nav_items as $n): ?>
      <a href="<?= $n['href'] ?>" class="<?= $page===$n['key']?'active':'' ?>"
         title="<?= e($n['label']) ?>"<?= $page===$n['key'] ? ' aria-current="page"' : '' ?>>
        <?= $n['icon'] ?><span class="nav-label-text"><?= e($n['label']) ?></span>
        <?php if ($n['key']==='notifications' && $unreadCount>0): ?><span class="nav-badge"><?= $unreadCount ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>

    <div class="sb-footer"><span class="nav-label-text"><?= e(role_label($role)) ?> · v2.0</span></div>
  </nav>

  <main class="content">
<?php endif; ?>

<script>
/* ── Sidebar: desktop collapse + mobile drawer ─────────────────────────────
   The 768 breakpoint lives in exactly one place (matchMedia) so the JS can
   never drift out of sync with the @media block in style.css. */
(function(){
  var mq = window.matchMedia('(max-width: 768px)');
  var sb = document.getElementById('sidebar');
  var btn = document.getElementById('sb-collapse-btn');

  function getCollapsed(){
    try{ return localStorage.getItem('sb_collapsed')==='1'; }catch(e){ return false; }
  }
  function applyCollapsed(on){
    if(!sb) return;
    sb.classList.toggle('collapsed', on);
    btn?.classList.toggle('flipped', on);
  }

  // Restore the saved state, but never on mobile — a collapsed rail has no
  // place inside the drawer and would hide every label.
  applyCollapsed(!mq.matches && getCollapsed());

  mq.addEventListener('change', function(e){
    if(e.matches){ applyCollapsed(false); closeDrawer(); }
    else { applyCollapsed(getCollapsed()); }
  });
})();

function toggleSidebar(){
  var sb = document.getElementById('sidebar');
  if(!sb) return;
  var isCollapsed = sb.classList.toggle('collapsed');
  document.getElementById('sb-collapse-btn')?.classList.toggle('flipped', isCollapsed);
  try{ localStorage.setItem('sb_collapsed', isCollapsed ? '1' : '0'); }catch(e){}
}

function openDrawer(){
  var sb = document.getElementById('sidebar');
  if(!sb) return;
  sb.classList.add('open');
  document.getElementById('drawer-backdrop')?.classList.add('show');
  document.getElementById('hamburger')?.setAttribute('aria-expanded','true');
  document.body.classList.add('drawer-open');
  sb.querySelector('.sb-close-btn')?.focus();
}

function closeDrawer(){
  var sb = document.getElementById('sidebar');
  if(!sb || !sb.classList.contains('open')) return;
  sb.classList.remove('open');
  document.getElementById('drawer-backdrop')?.classList.remove('show');
  document.getElementById('hamburger')?.setAttribute('aria-expanded','false');
  document.body.classList.remove('drawer-open');
  document.getElementById('hamburger')?.focus();
}

// Tapping any destination closes the drawer (a full page load usually follows,
// but this also covers in-page nav).
document.getElementById('sidebar')?.addEventListener('click', function(e){
  if(e.target.closest('a')) closeDrawer();
});

// Escape closes the drawer from anywhere.
document.addEventListener('keydown', function(e){
  if(e.key === 'Escape') closeDrawer();
});

// ── Theme switcher: default / light / dark ──
(function(){
  var order = ['default','light','dark'];
  function current(){
    var t = '';
    try{ t = localStorage.getItem('upf-theme') || ''; }catch(e){}
    return (order.indexOf(t) >= 0) ? t : 'default';
  }
  function apply(t){
    if(t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
    else document.documentElement.removeAttribute('data-theme');
    try{ localStorage.setItem('upf-theme', t); }catch(e){}
    var btn = document.getElementById('theme-toggle');
    if(btn){ btn.style.background = (t==='dark') ? 'rgba(255,255,255,.2)' : (t==='light' ? 'rgba(255,255,255,.05)' : 'rgba(255,255,255,.1)'); }
  }
  var toggle = document.getElementById('theme-toggle');
  if(toggle){
    toggle.addEventListener('click', function(){
      var next = order[(order.indexOf(current()) + 1) % order.length];
      apply(next);
    });
  }
})();
</script>
