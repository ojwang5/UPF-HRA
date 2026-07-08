<?php $user = $user ?? current_user(); ?>
<?php if ($user): ?>
  </main>
</div>
<?php endif; ?>
<footer class="footer">
  &copy; <?= date('Y') ?> <?= e(APP_ORG) ?> — <?= e(APP_NAME) ?>
</footer>
<script>
/* ── Global panel-drop system ──────────────────────────────────────────────
   Usage:
     <div class="panel-wrap">
       <button class="btn-icon …" data-panel="myPanel">ICON</button>
       <div class="panel-drop" id="myPanel"> … content … </div>
     </div>
   Clicking the trigger toggles the dropdown; clicking outside closes all.
──────────────────────────────────────────────────────────────────────────── */
(function(){
  function closeAll(except){
    document.querySelectorAll('.panel-drop.is-open').forEach(function(p){
      if(p!==except) p.classList.remove('is-open');
    });
  }

  document.addEventListener('click', function(e){
    var btn = e.target.closest('[data-panel]');
    if(btn){
      e.stopPropagation();
      var id = btn.getAttribute('data-panel');
      var panel = document.getElementById(id);
      if(!panel) return;
      var isOpen = panel.classList.contains('is-open');
      closeAll(null);
      if(!isOpen){
        panel.classList.add('is-open');
        // Keep highlighted while open
        btn.setAttribute('data-panel-open','1');
      } else {
        btn.removeAttribute('data-panel-open');
      }
      return;
    }
    // Click inside an open panel — stop propagation so it stays open
    if(e.target.closest('.panel-drop')){ return; }
    // Click outside — close all
    closeAll(null);
    document.querySelectorAll('[data-panel-open]').forEach(function(b){ b.removeAttribute('data-panel-open'); });
  }, true);

  // Also update button state when panel closes externally
  document.addEventListener('panelclose', function(){
    document.querySelectorAll('[data-panel-open]').forEach(function(b){ b.removeAttribute('data-panel-open'); });
  });
})();
</script>
</body>
</html>
