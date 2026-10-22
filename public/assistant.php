<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/groq.php';
require_once __DIR__ . '/../includes/assistant.php';
require_once __DIR__ . '/../includes/assistant-prompt.php';
$user = require_login();
$page = 'assistant';
$page_title = 'HRAS Assistant';
$page_css = '/assets/assistant.css';
$pdo = db();

$uid  = (int)$user['id'];
$json = (($_GET['format'] ?? '') === 'json');

/* ═══ POST: send a question / clear the conversation ═════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'send');

    if ($action === 'clear') {
        assistant_clear($uid);
        log_activity('Cleared AI assistant conversation', 'assistant', 'HRAS Assistant');
        if ($json) assistant_json(['ok' => true, 'cleared' => true]);
        flash('msg', 'Conversation cleared.');
        header('Location:/assistant.php'); exit;
    }

    $question = trim((string)($_POST['message'] ?? ''));
    $error    = '';

    if ($question === '') {
        $error = 'Please type a question before sending.';
    } elseif (assistant_strlen($question) > ASSISTANT_MAX_MESSAGE_LENGTH) {
        $error = 'That question is too long. Please keep it under ' . ASSISTANT_MAX_MESSAGE_LENGTH . ' characters.';
    } elseif (get_setting('assistant_enabled', '1') === '0') {
        $error = 'The HRAS Assistant is currently switched off by the administrator.';
    } elseif (!groq_configured()) {
        $error = 'The HRAS Assistant is not configured yet. Ask your administrator to add the Groq API key under Settings → AI Assistant.';
    } elseif (!assistant_rate_ok($uid)) {
        $error = 'You have asked ' . ASSISTANT_RATE_MAX . ' questions in the last '
               . ASSISTANT_RATE_WINDOW_MINUTES . ' minutes. Please wait a moment before asking more.';
    }

    if ($error === '') {
        $clean = assistant_redact($question);

        // Build the context before the question is stored, so the question is
        // sent once (as the final user message) and not replayed from history.
        $messages = assistant_build_context($user, $clean);
        assistant_store_message($uid, 'user', $clean);

        $res = groq_chat($messages);

        if ($res['ok']) {
            assistant_store_message($uid, 'assistant', $res['reply'], $res['model'] ?? null);
            log_activity('Asked the AI assistant', 'assistant', 'HRAS Assistant', 0, substr($clean, 0, 500));

            if ($json) {
                assistant_json([
                    'ok'     => true,
                    'reply'  => assistant_format($res['reply']),
                    'text'   => $res['reply'],
                    'model'  => $res['model'] ?? groq_model(),
                ]);
            }
            header('Location:/assistant.php'); exit;
        }

        $error = $res['error'] ?? 'The assistant could not answer that question.';
        log_activity('AI assistant error', 'assistant', 'HRAS Assistant', 0, substr($error, 0, 300));
    }

    if ($json) assistant_json(['ok' => false, 'error' => $error]);
    flash('err', $error);
    header('Location:/assistant.php'); exit;
}

/* ═══ GET: render the conversation ═════════════════════════════════════ */
$history   = assistant_history($uid, 60);
$enabled   = groq_enabled();
$model     = groq_model();
$roleLabel = role_label($user['role']);
$scope     = user_scope_label($user);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>HRAS Assistant</h1>
    <div class="desc">Ask how to use UPF-HRAS — answers are tailored to your role and scope</div>
  </div>
  <div class="action-bar">
    <?php if ($history): ?>
    <form method="post" id="as-clear-form">
  <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear">
      <button type="submit" class="btn btn-secondary btn-sm" title="Clear this conversation">
        <?= ICO_TRASH ?> Clear conversation
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($m = flash('msg')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

<?php if (!$enabled): ?>
  <div class="alert alert-error">
    <strong>The HRAS Assistant is not available yet.</strong>
    <?php if (get_setting('assistant_enabled', '1') === '0'): ?>
      It has been switched off by the administrator. Please contact your administrator or ICT support.
    <?php else: ?>
      No AI service key has been configured.
      <?php if (is_superadmin($user)): ?>
        You can add it under <a href="/settings.php">Settings → AI Assistant</a>.
      <?php else: ?>
        Please contact your administrator or ICT support to have it enabled.
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card as-card">
  <div class="as-top">
    <div class="as-avatar" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 8v4"/><path d="M10 10h4"/></svg>
    </div>
    <div class="as-who">
      <div class="as-name">HRAS Assistant</div>
      <div class="as-sub">
        <?= e($roleLabel) ?><?= ($scope && $scope !== 'HQ') ? ' · ' . e($scope) : '' ?>
        <?= $enabled ? ' · <span class="as-dot"></span> Online' : '' ?>
      </div>
    </div>
    <?php if ($enabled): ?>
      <div class="as-model" title="AI model in use"><?= e($model) ?></div>
    <?php endif; ?>
  </div>

  <div class="as-chat" id="as-chat">
    <?php if (!$history): ?>
      <div class="as-welcome">
        <h3>Karibu. How can I help you today?</h3>
        <p>
          Ask me how to use any part of the system — personnel, daily status, leave, reports,
          notifications, users or structure. I answer in plain English and only within your role
          (<strong><?= e($roleLabel) ?></strong>).
        </p>
        <p class="muted" style="font-size:12px">
          I cannot see personnel records, so I will always point you to the screen to open
          rather than quoting details about a named officer.
        </p>
      </div>
    <?php endif; ?>

    <?php foreach ($history as $m): $isUser = ($m['role'] === 'user'); ?>
      <div class="as-msg <?= $isUser ? 'as-me' : 'as-bot' ?>">
        <?php if (!$isUser): ?>
          <div class="as-avatar as-avatar-sm" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          </div>
        <?php endif; ?>
        <div class="as-body">
          <div class="as-bubble">
            <?php if ($isUser): ?>
              <?= nl2br(e($m['content'])) ?>
            <?php else: ?>
              <?= assistant_format($m['content']) ?>
            <?php endif; ?>
          </div>
          <div class="as-time">
            <?= e(date('H:i', strtotime($m['created_at']))) ?>
            <?= !$isUser && !empty($m['model']) ? ' · ' . e($m['model']) : '' ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (!$history && $enabled): ?>
    <div class="as-suggest" id="as-suggest">
      <?php foreach (assistant_starter_questions($user) as $q): ?>
        <button type="button" class="as-chip" data-q="<?= e($q) ?>"><?= e($q) ?></button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="as-error" id="as-error" hidden></div>

  <?php if ($enabled): ?>
  <form class="as-composer" id="as-form" method="post" action="/assistant.php">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="send">
    <textarea id="as-input" name="message" rows="2" maxlength="<?= ASSISTANT_MAX_MESSAGE_LENGTH ?>"
              placeholder="Ask a question, e.g. “How do I submit the daily strength return for my post?”"
              aria-label="Your question"></textarea>
    <button type="submit" class="btn-icon bi-gold bi-lg" id="as-send" title="Send">
      <?= ICO_SEND ?>
    </button>
  </form>
  <div class="as-foot">
    Guidance only — approvals, discipline, transfers and deployments remain with the responsible commander.
    Never type a password, OTP or PIN here.
  </div>
  <?php endif; ?>
</div>

<script>
(function () {
  var form  = document.getElementById('as-form');
  var input = document.getElementById('as-input');
  var chat  = document.getElementById('as-chat');
  var send  = document.getElementById('as-send');
  var errBox= document.getElementById('as-error');
  var sug   = document.getElementById('as-suggest');
  if (!form || !input || !chat) return;

  // The session CSRF token: the global set by the header, falling back to the
  // hidden field rendered in the composer form.
  function csrf() {
    var f = form.querySelector('input[name="csrf_token"]');
    return window.CSRF_TOKEN || (f ? f.value : '') || '';
  }

  function esc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }

  function scroll() { chat.scrollTop = chat.scrollHeight; }

  function bubble(role, html) {
    var wrap = document.createElement('div');
    wrap.className = 'as-msg ' + (role === 'user' ? 'as-me' : 'as-bot');
    if (role !== 'user') {
      var av = document.createElement('div');
      av.className = 'as-avatar as-avatar-sm';
      av.setAttribute('aria-hidden','true');
      av.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
      wrap.appendChild(av);
    }
    var body = document.createElement('div');
    body.className = 'as-body';
    var bub = document.createElement('div');
    bub.className = 'as-bubble';
    bub.innerHTML = html;
    body.appendChild(bub);
    var t = document.createElement('div');
    t.className = 'as-time';
    var d = new Date();
    t.textContent = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    body.appendChild(t);
    wrap.appendChild(body);
    chat.appendChild(wrap);
    scroll();
    return wrap;
  }

  function showError(msg) {
    if (!errBox) return;
    if (!msg) { errBox.hidden = true; errBox.textContent = ''; return; }
    errBox.hidden = false;
    errBox.textContent = msg;
  }

  function thinking(on) {
    var t = document.getElementById('as-typing');
    if (t) t.hidden = !on;
    if (send) send.disabled = on;
    if (on) scroll();
  }

  // Typing indicator
  var typing = document.createElement('div');
  typing.id = 'as-typing';
  typing.className = 'as-msg as-bot';
  typing.hidden = true;
  typing.innerHTML = '<div class="as-avatar as-avatar-sm" aria-hidden="true">' +
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' +
    '</div><div class="as-body"><div class="as-bubble as-typing"><span></span><span></span><span></span></div></div>';
  chat.appendChild(typing);

  // Starter question chips
  if (sug) {
    sug.addEventListener('click', function (e) {
      var b = e.target.closest('.as-chip');
      if (!b) return;
      input.value = b.getAttribute('data-q') || '';
      input.focus();
      form.dispatchEvent(new Event('submit', {cancelable:true}));
    });
  }

  // Enter sends, Shift+Enter makes a new line
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      form.dispatchEvent(new Event('submit', {cancelable:true}));
    }
  });

  form.addEventListener('submit', function (e) {
    if (!window.fetch || !window.URLSearchParams) return;  // fall back to a normal POST
    var q = input.value.trim();
    if (!q) { e.preventDefault(); return; }
    e.preventDefault();
    showError('');
    if (sug) sug.remove();
    bubble('user', esc(q).replace(/\n/g,'<br>'));
    input.value = '';
    thinking(true);

    fetch('/assistant.php?format=json', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-CSRF-TOKEN': csrf()
      },
      body: new URLSearchParams({action: 'send', message: q}).toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) {
          bubble('assistant', data.reply);
        } else {
          showError((data && data.error) ? data.error : 'The assistant could not answer that question.');
        }
      })
      .catch(function () {
        showError('Could not reach the assistant. Please try again.');
      })
      .then(function () {
        thinking(false);
        input.focus();
      });
  });

  scroll();
  input.focus();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
