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

<!-- HRAS Assistant Floating Chatbot -->
<?php if (isset($user) && $user): ?>
<div id="hras-fab" class="hras-fab" title="HRAS Assistant" role="button" tabindex="0" aria-label="Open HRAS Assistant">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
    <path d="M12 8v4"/><path d="M10 10h4"/>
  </svg>
</div>

<div id="hras-chatbox" class="hras-chatbox" aria-live="polite">
  <div class="hras-chatbox-header">
    <div class="hras-chatbox-title">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
      <span>HRAS Assistant</span>
    </div>
    <button class="hras-chatbox-close" id="hras-chatbox-close" aria-label="Close assistant">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
  </div>
  <div class="hras-chatbox-body" id="hras-chatbox-body">
    <div class="hras-msg hras-msg-bot">
      <div class="hras-msg-avatar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </div>
      <div class="hras-msg-bubble">Hello! How can I help you use UPF-HRAS today?</div>
    </div>
  </div>
  <form class="hras-chatbox-form" id="hras-chatbox-form" novalidate>
    <input type="text" id="hras-chatbox-input" placeholder="Ask a question..." autocomplete="off" required>
    <button type="submit" aria-label="Send">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
      </svg>
    </button>
  </form>
</div>

<style>
.hras-fab {
  position: fixed;
  bottom: 24px;
  right: 24px;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--primary) 0%, var(--navy-700) 100%);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
  z-index: 9999;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
  border: 2px solid var(--gold);
}

.hras-fab:hover, .hras-fab:focus {
  transform: scale(1.05);
  box-shadow: 0 6px 24px rgba(0, 0, 0, 0.2);
  outline: 2px solid var(--gold);
  outline-offset: 2px;
}

.hras-fab svg {
  width: 28px;
  height: 28px;
}

.hras-chatbox {
  position: fixed;
  bottom: 90px;
  right: 24px;
  width: 340px;
  height: 450px;
  background: var(--card);
  border-radius: 12px;
  box-shadow: 0 8px 40px rgba(0, 0, 0, 0.2);
  display: none;
  flex-direction: column;
  z-index: 10000;
  border: 1px solid var(--border);
  overflow: hidden;
}

.hras-chatbox.open {
  display: flex;
}

.hras-chatbox-header {
  background: linear-gradient(135deg, var(--primary) 0%, var(--navy-700) 100%);
  color: #fff;
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 2px solid var(--gold);
}

.hras-chatbox-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  font-size: 14px;
}

.hras-chatbox-title svg {
  width: 20px;
  height: 20px;
}

.hras-chatbox-close {
  background: transparent;
  border: none;
  color: #fff;
  cursor: pointer;
  padding: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.hras-chatbox-close svg {
  width: 18px;
  height: 18px;
}

.hras-chatbox-body {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: var(--bg);
}

.hras-msg {
  display: flex;
  gap: 8px;
  max-width: 85%;
  align-items: flex-start;
}

.hras-msg-bot {
  align-self: flex-start;
}

.hras-msg-user {
  align-self: flex-end;
  flex-direction: row-reverse;
}

.hras-msg-avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--primary);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.hras-msg-avatar svg {
  width: 16px;
  height: 16px;
}

.hras-msg-bubble {
  background: var(--card);
  padding: 10px 12px;
  border-radius: 10px;
  font-size: 13px;
  line-height: 1.4;
  border: 1px solid var(--border);
  color: var(--text);
  white-space: pre-wrap;
  word-break: break-word;
}

.hras-msg-user .hras-msg-bubble {
  background: var(--primary);
  color: #fff;
  border-color: var(--primary);
}

.hras-chatbox-form {
  display: flex;
  gap: 8px;
  padding: 12px;
  border-top: 1px solid var(--border);
  background: var(--card);
}

.hras-chatbox-form input {
  flex: 1;
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 13px;
  background: var(--bg);
  color: var(--text);
  font-family: inherit;
}

.hras-chatbox-form button {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: var(--primary);
  color: #fff;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
}

.hras-chatbox-form button svg {
  width: 18px;
  height: 18px;
}

@media (max-width: 640px) {
  .hras-fab {
    bottom: 16px;
    right: 16px;
    width: 52px;
    height: 52px;
  }
  .hras-chatbox {
    width: calc(100vw - 32px);
    right: 16px;
    bottom: 78px;
    height: 380px;
  }
}
</style>

<script>
(function() {
  const fab = document.getElementById('hras-fab');
  const chatbox = document.getElementById('hras-chatbox');
  const closeBtn = document.getElementById('hras-chatbox-close');
  const form = document.getElementById('hras-chatbox-form');
  const input = document.getElementById('hras-chatbox-input');
  const body = document.getElementById('hras-chatbox-body');
  
  if (!fab || !chatbox) return;
  
  function toggleChat() {
    chatbox.classList.toggle('open');
    if (chatbox.classList.contains('open')) {
      setTimeout(function() { input.focus(); }, 100);
    }
  }
  
  fab.addEventListener('click', toggleChat);
  fab.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      toggleChat();
    }
  });
  
  if (closeBtn) {
    closeBtn.addEventListener('click', function() {
      chatbox.classList.remove('open');
    });
  }
  
  function addMessage(text, isUser, isHtml) {
    const msgDiv = document.createElement('div');
    msgDiv.className = 'hras-msg ' + (isUser ? 'hras-msg-user' : 'hras-msg-bot');
    
    if (!isUser) {
      const avatar = document.createElement('div');
      avatar.className = 'hras-msg-avatar';
      avatar.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
      msgDiv.appendChild(avatar);
    }
    
    const bubble = document.createElement('div');
    bubble.className = 'hras-msg-bubble';
    if (isHtml) {
      bubble.innerHTML = text;
    } else {
      bubble.textContent = text;
    }
    msgDiv.appendChild(bubble);
    
    body.appendChild(msgDiv);
    body.scrollTop = body.scrollHeight;
  }
  
  function csrf() {
    var token = '';
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) token = meta.getAttribute('content') || '';
    if (!token && window.CSRF_TOKEN) token = window.CSRF_TOKEN;
    return token;
  }
  
  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      const message = input.value.trim();
      if (!message) return;
      
      addMessage(message, true, false);
      input.value = '';
      
      // Show typing indicator
      const typingDiv = document.createElement('div');
      typingDiv.className = 'hras-msg hras-msg-bot';
      typingDiv.id = 'hras-typing';
      typingDiv.innerHTML = '<div class="hras-msg-avatar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div><div class="hras-msg-bubble">...</div>';
      body.appendChild(typingDiv);
      body.scrollTop = body.scrollHeight;
      
      fetch('/assistant.php?format=json', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-CSRF-TOKEN': csrf()
        },
        body: new URLSearchParams({action: 'send', message: message}).toString()
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        const typing = document.getElementById('hras-typing');
        if (typing) typing.remove();
        if (data && data.ok && data.reply) {
          addMessage(data.reply, false, true);
        } else {
          addMessage((data && data.error) ? data.error : 'Sorry, I could not answer that question.', false, false);
        }
      })
      .catch(function() {
        const typing = document.getElementById('hras-typing');
        if (typing) typing.remove();
        addMessage('Could not reach the assistant. Please try again.', false, false);
      });
    });
  }
})();
</script>
<?php endif; ?>
</body>
</html>
