<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/* ═══ HRAS Assistant — conversation store, safety and rendering ═══════════
 * Conversations are stored per user so the assistant can keep context
 * between turns and so an administrator can review assistant usage in the
 * Activity Log. Nothing sensitive is stored: questions are scrubbed of
 * anything that looks like a credential before they are written.
 * ═══════════════════════════════════════════════════════════════════ */

/** Longest question the assistant accepts. */
const ASSISTANT_MAX_MESSAGE_LENGTH = 2000;

/** How many past messages are replayed to the model for context. */
const ASSISTANT_HISTORY_LIMIT = 12;

/** Rate limit: maximum questions per user per window. */
const ASSISTANT_RATE_WINDOW_MINUTES = 10;
const ASSISTANT_RATE_MAX          = 20;

/** Emits a JSON response and stops. */
function assistant_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Character count that works with or without the mbstring extension. */
function assistant_strlen(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

/**
 * Scrubs anything that looks like a credential out of a user message before
 * it is stored or forwarded to the AI service.
 */
function assistant_redact(string $text): string {
    // "password: hunter2" / "my otp is 123456" / "api_key = gsk_..."
    $out = preg_replace(
        '/\b(password|passcode|passwd|pwd|otp|one[\s-]?time[\s-]?password|pin|secret|api[\s_-]?key|auth[\s_-]?token|bearer\s+token)\b(\s*(?:is|are|=|:)\s*)(\S+)/iu',
        '$1$2[redacted]',
        $text
    );
    // Bare provider keys (Groq gsk_…, OpenAI sk-…)
    $out = preg_replace('/\b(gsk_[A-Za-z0-9_\-]{8,}|sk-[A-Za-z0-9_\-]{8,})\b/u', '[redacted]', $out);
    return $out ?? $text;
}

/** Stores one message in the conversation. */
function assistant_store_message(int $userId, string $role, string $content, ?string $model = null): int {
    $st = db()->prepare("INSERT INTO assistant_messages (user_id, role, content, model, created_at)
                         VALUES (?,?,?,?,datetime('now','localtime'))");
    $st->execute([$userId, $role, $content, $model]);
    return (int)db()->lastInsertId();
}

/**
 * The conversation for one user, oldest first.
 * @return array<int,array{role:string,content:string,model:?string,created_at:string}>
 */
function assistant_history(int $userId, int $limit = 50): array {
    $st = db()->prepare("SELECT role, content, model, created_at FROM assistant_messages
                         WHERE user_id=? ORDER BY id DESC LIMIT ?");
    $st->execute([$userId, max(1, $limit)]);
    $rows = array_reverse($st->fetchAll());
    return $rows;
}

/** Deletes the whole conversation for one user. */
function assistant_clear(int $userId): void {
    db()->prepare("DELETE FROM assistant_messages WHERE user_id=?")->execute([$userId]);
}

/** True when the user is still within the question rate limit. */
function assistant_rate_ok(int $userId): bool {
    $st = db()->prepare("SELECT COUNT(*) FROM assistant_messages
                         WHERE user_id=? AND role='user'
                           AND created_at >= datetime('now','localtime',?)");
    $st->execute([$userId, '-' . ASSISTANT_RATE_WINDOW_MINUTES . ' minutes']);
    return (int)$st->fetchColumn() < ASSISTANT_RATE_MAX;
}

/**
 * Builds the message array sent to the model: the system prompt followed by
 * the tail of the conversation.
 */
function assistant_build_context(array $user, string $question, int $historyLimit = ASSISTANT_HISTORY_LIMIT): array {
    require_once __DIR__ . '/assistant-prompt.php';

    $messages = [[
        'role'    => 'system',
        'content' => assistant_system_prompt($user),
    ]];

    $history = assistant_history((int)$user['id'], $historyLimit);
    foreach ($history as $m) {
        $messages[] = [
            'role'    => $m['role'] === 'assistant' ? 'assistant' : 'user',
            'content' => (string)$m['content'],
        ];
    }

    $messages[] = ['role' => 'user', 'content' => $question];

    return $messages;
}

/**
 * Renders plain-text model output as safe HTML.
 *
 * The text is HTML-escaped first, then a deliberately small subset of
 * markdown is applied (headings, bold, italics, inline code, bullet and
 * numbered lists, fenced code blocks). No raw HTML from the model is ever
 * passed through.
 */
function assistant_format(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    $lines   = explode("\n", $text);
    $html    = '';
    $listTag = '';
    $para    = [];
    $inCode  = false;

    $flushPara = function () use (&$para, &$html) {
        if ($para) {
            $html .= '<p>' . assistant_inline(implode('<br>', $para)) . '</p>';
            $para = [];
        }
    };
    $closeList = function () use (&$listTag, &$html) {
        if ($listTag !== '') {
            $html .= '</' . $listTag . '>';
            $listTag = '';
        }
    };

    foreach ($lines as $raw) {
        $line = trim($raw);

        // Fenced code block
        if (str_starts_with($line, '```')) {
            $flushPara();
            $closeList();
            if ($inCode) {
                $html .= '</code></pre>';
                $inCode = false;
            } else {
                $html .= '<pre class="as-code"><code>';
                $inCode = true;
            }
            continue;
        }
        if ($inCode) {
            // The text was escaped once, up front — append it as-is.
            $html .= rtrim($raw, "\n") . "\n";
            continue;
        }

        if ($line === '') {
            $flushPara();
            $closeList();
            continue;
        }

        // Heading
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            $flushPara();
            $closeList();
            $html .= '<h4 class="as-h">' . assistant_inline($m[2]) . '</h4>';
            continue;
        }

        // Bullet list
        if (preg_match('/^[-*•]\s+(.*)$/u', $line, $m)) {
            $flushPara();
            if ($listTag !== 'ul') {
                $closeList();
                $html .= '<ul>';
                $listTag = 'ul';
            }
            $html .= '<li>' . assistant_inline($m[1]) . '</li>';
            continue;
        }

        // Numbered list
        if (preg_match('/^\d+[.)]\s+(.*)$/u', $line, $m)) {
            $flushPara();
            if ($listTag !== 'ol') {
                $closeList();
                $html .= '<ol>';
                $listTag = 'ol';
            }
            $html .= '<li>' . assistant_inline($m[1]) . '</li>';
            continue;
        }

        $para[] = $line;
    }

    $flushPara();
    $closeList();
    if ($inCode) $html .= '</code></pre>';

    return $html !== '' ? $html : '<p class="muted">(no answer)</p>';
}

/** Applies the inline markdown subset to already-escaped text. */
function assistant_inline(string $s): string {
    $s = preg_replace('/`([^`\n]+)`/u', '<code>$1</code>', $s) ?? $s;
    $s = preg_replace('/\*\*([^*\n]+)\*\*/u', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '<em>$1</em>', $s) ?? $s;
    return $s;
}

/**
 * Starting questions offered on an empty conversation, tailored to the role.
 */
function assistant_starter_questions(array $user): array {
    $role = (string)($user['role'] ?? '');
    $common = [
        'How do I submit the daily status for my unit?',
        'What is a strength return and how do I generate one?',
        'How do I apply for leave?',
    ];
    return match ($role) {
        'officer' => array_merge($common, ['How do I check my leave balance?']),
        'post_commander' => array_merge($common, ['How do I create a user account for a new officer?']),
        'station_commander' => array_merge($common, ['How do I send a command message to my posts?']),
        'division_commander' => array_merge($common, ['How do I read the activity log for my division?']),
        'regional_commander' => array_merge($common, ['How do I review strength returns from my divisions?']),
        'superadmin' => array_merge($common, ['How do I add a new station to the organisation structure?']),
        'directorate_commander' => array_merge($common, ['How do I view my directorate’s strength?']),
        'unit_commander' => array_merge($common, ['How do I view my unit’s personnel?']),
        default => $common,
    };
}
