<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Kampala');

define('APP_NAME', 'HUMAN RESOURCE ADMINISTRATION SYSTEM');
define('APP_ORG', 'UGANDA POLICE FORCE');
define('APP_MOTTO', 'PROTECT & SERVE');
define('BASE_PATH', dirname(__DIR__));
define('DB_PATH', BASE_PATH . '/data/mdd.sqlite');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ═══ Security: sessions, CSRF, idle timeout ═════════════════════════════ */
/* Idle (inactivity) timeout in seconds before the user is signed out and
 * asked to log in again. Override with UPF_SESSION_TIMEOUT for testing. */
define('SESSION_IDLE_TIMEOUT', max(1, (int)(getenv('UPF_SESSION_TIMEOUT') ?: 600))); // 10 minutes

/** Per-session CSRF token (created lazily, rotated on login). */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

/** Hidden form field carrying the CSRF token. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/* Idle timeout — sign the user out after too long without activity. */
if (!empty($_SESSION['user_id'])) {
    $__last = (int)($_SESSION['last_activity'] ?? time());
    if ((time() - $__last) > SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = time();
        $_SESSION['_flash']['err'] = 'Your session expired after 10 minutes of inactivity. Please sign in again.';
        if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'login.php') {
            header('Location: /login.php?timeout=1');
            exit;
        }
    } else {
        $_SESSION['last_activity'] = time();
    }
}

/* CSRF enforcement — every state-changing POST must carry the session token. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $__sent  = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $__known = (string)($_SESSION['csrf_token'] ?? '');
    if ($__known === '' || $__sent === '' || !hash_equals($__known, $__sent)) {
        if (empty($_SESSION['user_id'])) {
            // Session had already lapsed (or a stale page was re-submitted).
            header('Location: /login.php?timeout=1');
            exit;
        }
        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        exit('<!doctype html><html><head><meta charset="utf-8"><title>Security check failed</title></head>'
           . '<body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;padding:48px;color:#334155">'
           . '<h2 style="color:#b91c1c">Security check failed</h2>'
           . '<p>The request could not be verified (invalid or missing CSRF token). '
           . 'Please go back, refresh the page and try again.</p></body></html>');
    }
}
