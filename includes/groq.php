<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';

/* ═══ Groq API client ═══════════════════════════════════════════════════
 * The HRAS Assistant talks to Groq's OpenAI-compatible chat endpoint.
 * The API key is read from the environment variable GROQ_API_KEY first
 * (recommended for servers / containers) and falls back to the
 * `groq_api_key` row in the settings table (set by the Super Admin in
 * Settings → AI Assistant). The key is never rendered back to a page
 * and never leaves the server.
 * ═══════════════════════════════════════════════════════════════════ */

    const GROQ_API_BASE       = 'https://api.groq.com/openai/v1';
const GROQ_DEFAULT_MODEL  = 'llama-3.3-70b-versatile';
const GROQ_MAX_TOKENS     = 900;
const GROQ_TEMPERATURE    = 0.2;

/** Models offered in the Settings screen. */
const GROQ_MODELS = [
    'llama-3.3-70b-versatile'   => 'Llama 3.3 70B Versatile',
    'openai/gpt-oss-20b'        => 'GPT OSS 20B',
    'openai/gpt-oss-120b'       => 'GPT OSS 120B',
    'qwen/qwen3-32b'            => 'Qwen3 32B',
];

/**
 * The configured API key (environment wins over the database).
 * Never log or echo the return value.
 */
function groq_api_key(): string {
    $env = trim((string)(getenv('GROQ_API_KEY') ?: ''));
    if ($env !== '') return $env;
    return trim((string)get_setting('groq_api_key'));
}

/** True when an API key is available. */
function groq_configured(): bool {
    return groq_api_key() !== '';
}

/** True when the assistant is switched on and usable. */
function groq_enabled(): bool {
    if (get_setting('assistant_enabled', '1') === '0') return false;
    return groq_configured();
}

/** The model the assistant should use. */
function groq_model(): string {
    $m = trim((string)get_setting('groq_model', GROQ_DEFAULT_MODEL));
    if ($m === '') $m = GROQ_DEFAULT_MODEL;
    // Move saved selections for retired models to the supported default.
    $map = [
        'llama-3.1-8b-instant' => GROQ_DEFAULT_MODEL,
        'llama3-8b-8192' => GROQ_DEFAULT_MODEL,
        'gemma2-9b-it' => GROQ_DEFAULT_MODEL,
        'mixtral-8x7b-instruct' => GROQ_DEFAULT_MODEL,
    ];
    if (isset($map[$m])) $m = $map[$m];
    return $m;
}

/**
 * Sends a chat completion request to Groq.
 *
 * @param array $messages  OpenAI-style messages: [['role'=>'system|user|assistant','content'=>'…'], …]
 * @param array $opts      'model', 'temperature', 'max_tokens'
 * @return array{ok:bool, reply?:string, model?:string, error?:string}
 */
function groq_chat(array $messages, array $opts = []): array {
    $key = groq_api_key();
    if ($key === '') {
        return ['ok' => false, 'error' => 'The HRAS Assistant is not configured yet. Ask your administrator to add the Groq API key under Settings → AI Assistant.'];
    }

    $model = (string)($opts['model'] ?? groq_model());
    $payload = [
        'model'    => $model,
        'messages' => array_values($messages),
        'temperature' => (float)($opts['temperature'] ?? GROQ_TEMPERATURE),
        'max_tokens'  => (int)($opts['max_tokens'] ?? GROQ_MAX_TOKENS),
        'top_p'    => 0.9,
    ];

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return ['ok' => false, 'error' => 'Could not encode the request for the AI service.'];
    }

    $raw = groq_http_post(GROQ_API_BASE . '/chat/completions', $body, $key);
    if ($raw === null) {
        return ['ok' => false, 'error' => 'The AI service could not be reached. Check the server internet connection, then try again.'];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'The AI service returned an unreadable response.'];
    }

    if (isset($data['error'])) {
        $msg = is_array($data['error']) ? ($data['error']['message'] ?? '') : (string)$data['error'];
        $errMsg = groq_sanitize_error((string)$msg);
        if (groq_is_model_error($data['error'])) {
            foreach (groq_model_fallbacks($model) as $fallback) {
                $payload['model'] = $fallback;
                $fallbackBody = json_encode($payload, JSON_UNESCAPED_UNICODE);
                if ($fallbackBody === false) continue;
                $fallbackRaw = groq_http_post(GROQ_API_BASE . '/chat/completions', $fallbackBody, $key);
                if ($fallbackRaw === null) continue;
                $fallbackData = json_decode($fallbackRaw, true);
                if (!is_array($fallbackData) || isset($fallbackData['error'])) continue;
                $fallbackReply = trim((string)($fallbackData['choices'][0]['message']['content'] ?? ''));
                if ($fallbackReply === '') continue;
                return ['ok' => true, 'reply' => $fallbackReply, 'model' => (string)($fallbackData['model'] ?? $fallback)];
            }
        }
        return ['ok' => false, 'error' => 'AI service error: ' . $errMsg];
    }

    $reply = (string)($data['choices'][0]['message']['content'] ?? '');
    $reply = trim($reply);
    if ($reply === '') {
        return ['ok' => false, 'error' => 'The AI service returned an empty answer. Please rephrase the question and try again.'];
    }

    return [
        'ok'    => true,
        'reply' => $reply,
        'model' => (string)($data['model'] ?? $model),
    ];
}

/** True only for errors that can be resolved by selecting another model. */
function groq_is_model_error($error): bool {
    $code = is_array($error) ? strtolower((string)($error['code'] ?? '')) : '';
    $message = strtolower(is_array($error) ? (string)($error['message'] ?? '') : (string)$error);
    if (in_array($code, ['model_not_found', 'model_decommissioned', 'model_not_available'], true)) return true;
    return str_contains($message, 'does not exist')
        || str_contains($message, 'do not have access')
        || str_contains($message, 'model not found')
        || str_contains($message, 'model is not available');
}

/**
 * Prefer known chat models that the configured key can access. If model
 * discovery is unavailable, try the maintained production-model list.
 *
 * @return string[]
 */
function groq_model_fallbacks(string $current): array {
    return groq_order_model_fallbacks($current, groq_list_models());
}

/**
 * Orders chat-capable models without relying on the API being reachable.
 *
 * @param string[] $available
 * @return string[]
 */
function groq_order_model_fallbacks(string $current, array $available): array {
    $preferred = array_keys(GROQ_MODELS);
    $fallbacks = [];

    foreach ($preferred as $candidate) {
        if ($candidate !== $current && (!$available || in_array($candidate, $available, true))) {
            $fallbacks[] = $candidate;
        }
    }

    if ($available) {
        foreach ($available as $candidate) {
            if ($candidate === $current || in_array($candidate, $fallbacks, true)) continue;
            if (preg_match('/(?:whisper|audio|speech|transcri|tts|embedding|moderation|guard|safety)/i', $candidate)) continue;
            $fallbacks[] = $candidate;
            if (count($fallbacks) >= 3) break;
        }
    }

    return $fallbacks;
}

/**
 * Light sanity check that the key and model work — used by the
 * "Test connection" button in Settings.
 * @return array{ok:bool, error?:string, model?:string}
 */
function groq_test_connection(): array {
    $res = groq_chat([
        ['role' => 'system', 'content' => 'Reply with the single word: OK'],
        ['role' => 'user',   'content' => 'Ping.'],
    ], ['max_tokens' => 10, 'temperature' => 0]);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error'] ?? 'Unknown error'];
    return ['ok' => true, 'model' => groq_model(), 'served' => (string)($res['model'] ?? '')];
}

/** Lists the model IDs the API key can use (used by Settings). */
function groq_list_models(): array {
    $key = groq_api_key();
    if ($key === '') return [];
    $raw = groq_http_get(GROQ_API_BASE . '/models', $key);
    if ($raw === null) return [];
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['data'])) return [];
    $out = [];
    foreach ($data['data'] as $m) {
        if (!empty($m['id'])) $out[] = (string)$m['id'];
    }
    sort($out);
    return $out;
}

/**
 * POSTs JSON to the Groq API. Prefers cURL, falls back to a stream context.
 * @return string|null raw response body, or null when the API is unreachable
 */
function groq_http_post(string $url, string $body, string $key): ?string {
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch) {
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_CONNECTTIMEOUT => 15,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($resp === false) return null;
            if ($code >= 400) return (string)$resp;   // let the caller read the error body
            return (string)$resp;
        }
    }

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => implode("\r\n", $headers),
        'content' => $body,
        'timeout' => 45,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    return $resp === false ? null : (string)$resp;
}

/** GETs a JSON resource from the Groq API (models list). */
function groq_http_get(string $url, string $key): ?string {
    $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json'];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch) {
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            return $resp === false ? null : (string)$resp;
        }
    }

    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => implode("\r\n", $headers),
        'timeout' => 20,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    return $resp === false ? null : (string)$resp;
}

/**
 * Trims an upstream error message so it can be shown in the UI.
 * Keeps it short and free of anything that looks like a credential.
 */
function groq_sanitize_error(string $msg): string {
    $msg = preg_replace('/\b(gsk_[A-Za-z0-9_\-]{6,}|sk-[A-Za-z0-9_\-]{6,})\b/u', '[redacted]', $msg) ?? $msg;
    $msg = trim(preg_replace('/\s+/', ' ', $msg) ?? $msg);
    if ($msg === '') return 'Unknown error.';
    return function_exists('mb_substr') ? mb_substr($msg, 0, 300) : substr($msg, 0, 300);
}
