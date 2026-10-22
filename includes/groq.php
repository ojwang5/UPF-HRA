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
const GROQ_DEFAULT_MODEL  = 'llama-3.1-8b-instant';
const GROQ_MAX_TOKENS     = 900;
const GROQ_TEMPERATURE    = 0.2;

/** Models offered in the Settings screen. */
const GROQ_MODELS = [
    'llama-3.1-8b-instant'      => 'Llama 3.1 8B Instant',
    'gemma2-9b-it'              => 'Gemma 2 9B IT',
    'mixtral-8x7b-instruct'     => 'Mixtral 8x7B Instruct',
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
    // Try to normalize common deprecated names
    $map = [
        'llama-3.1-8b-instant' => 'llama-3.1-8b-instant',
        'llama3-8b-8192' => 'llama-3.1-8b-instant',
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
        // Try fallback model if current one doesn't exist
        if (stripos($errMsg, 'does not exist') !== false || stripos($errMsg, 'model') !== false) {
            $fallbacks = ['llama-3.1-8b-instant', 'gemma2-9b-it', 'mixtral-8x7b-instruct'];
            foreach ($fallbacks as $fb) {
                if ($fb === $model) continue;
                $payload2 = $payload;
                $payload2['model'] = $fb;
                $body2 = json_encode($payload2, JSON_UNESCAPED_UNICODE);
                if ($body2 === false) continue;
                $raw2 = groq_http_post(GROQ_API_BASE . '/chat/completions', $body2, $key);
                if ($raw2 === null) continue;
                $data2 = json_decode($raw2, true);
                if (!is_array($data2) || isset($data2['error'])) continue;
                $reply2 = (string)($data2['choices'][0]['message']['content'] ?? '');
                $reply2 = trim($reply2);
                if ($reply2 === '') continue;
                return ['ok' => true, 'reply' => $reply2, 'model' => (string)($data2['model'] ?? $fb)];
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
