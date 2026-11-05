<?php
declare(strict_types=1);

/* ─── SMS sender ─── */
const SMS_PROVIDERS = ['infobip', 'twilio', 'yoola'];

/** The configured provider, always normalised to a supported value. */
function sms_provider(): string {
    $provider = strtolower(trim(get_setting('sms_provider', 'infobip')));
    if (in_array($provider, SMS_PROVIDERS, true)) return $provider;
    // Retired providers fall back to Yoola SMS; anything else uses the default.
    return in_array($provider, ['africas_talking', 'nexmo'], true) ? 'yoola' : 'infobip';
}

/** Human-readable name of the configured SMS provider. */
function sms_provider_label(): string {
    return match (sms_provider()) {
        'infobip' => 'Infobip',
        'twilio'  => 'Twilio',
        'yoola'   => 'Yoola SMS',
        default   => ucfirst(str_replace('_', ' ', sms_provider())),
    };
}

/**
 * Infobip has a single API key, but older saves may have stored it in the
 * "API secret" field. Accept either so an existing configuration keeps working.
 */
function sms_api_key(): string {
    $key = trim(get_setting('sms_api_key'));
    if ($key === '' && sms_provider() === 'infobip') {
        $key = trim(get_setting('sms_api_secret'));
    }
    return $key;
}

function sms_configured(): bool {
    if (sms_api_key() === '') return false;
    // Infobip and Yoola can use the account's default sender when none is configured.
    if (in_array(sms_provider(), ['infobip', 'yoola'], true)) return true;
    return trim(get_setting('sms_sender_id')) !== '';
}

/**
 * Normalize personnel phone numbers to E.164. Local Ugandan numbers may be
 * stored as 07..., 7..., 256..., or +256...; international numbers must
 * already include their country code (00... is also accepted).
 */
function normalize_sms_phone(string $phone): ?string {
    $phone = trim($phone);
    if ($phone === '' || !preg_match('/^\+?[\d\s().-]+$/', $phone)) return null;

    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') return null;

    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }

    if (preg_match('/^0\d{9}$/', $digits)) {
        $digits = '256' . substr($digits, 1);
    } elseif (preg_match('/^2560\d{9}$/', $digits)) {
        $digits = '256' . substr($digits, 4);
    } elseif (preg_match('/^[37]\d{8}$/', $digits)) {
        $digits = '256' . $digits;
    }

    if (!preg_match('/^[1-9]\d{7,14}$/', $digits)) return null;
    return '+' . $digits;
}

/**
 * Send an SMS via the configured provider.
 * Supported providers: infobip, twilio, yoola
 */
function send_sms(string $phone, string $message): array {
    $provider  = sms_provider();
    $apiKey    = sms_api_key();
    $apiSecret = get_setting('sms_api_secret');
    $senderId  = get_setting('sms_sender_id');

    if (!$apiKey || (!in_array($provider, ['infobip', 'yoola'], true) && !$senderId)) {
        return ['ok' => false, 'error' => 'SMS not configured. Set credentials in Settings.'];
    }

    $phone = normalize_sms_phone($phone);
    if ($phone === null) {
        return ['ok' => false, 'error' => 'Personnel phone number is invalid. Use a valid international number or a Ugandan number such as 07XXXXXXXX.'];
    }

    return match ($provider) {
        'infobip'         => _sms_infobip($phone, $message, $apiKey, $senderId),
        'twilio'          => _sms_twilio($phone, $message, $apiKey, $apiSecret, $senderId),
        'yoola'           => _sms_yoola($phone, $message, $apiKey, $senderId),
        default           => ['ok' => false, 'error' => "Unknown SMS provider: {$provider}"],
    };
}

/** Normalised Infobip API base URL. */
function _infobip_base_url(): string {
    $base = trim(get_setting('sms_infobip_base_url', 'https://api.infobip.com'));
    if ($base === '') $base = 'https://api.infobip.com';
    if (!preg_match('#^https?://#i', $base)) $base = 'https://' . $base;
    return rtrim($base, '/');
}

/**
 * Infobip SMS API (https://www.infobip.com/docs/api/channels/sms).
 * Uses sms_api_key (App key), sms_sender_id (optional sender) and the
 * sms_infobip_base_url setting (defaults to https://api.infobip.com).
 */
function _sms_infobip(string $to, string $message, string $apiKey, string $from): array {
    $base = _infobip_base_url();
    $msg = [
        'destinations' => [['to' => ltrim($to, '+')]],
        'text'         => $message,
    ];
    // When no sender is configured Infobip uses the account's default sender.
    if (trim($from) !== '') $msg['from'] = trim($from);

    $payload = json_encode(['messages' => [$msg]], JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: App {$apiKey}",
        'content' => $payload,
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($base . '/sms/2/text/advanced', false, $ctx);
    $http = $http_response_header[0] ?? '';
    if ($resp === false) return ['ok' => false, 'error' => 'Infobip API unreachable (' . $base . ')'];
    $json = json_decode($resp, true);
    if (!is_array($json)) return ['ok' => false, 'error' => 'Invalid response from Infobip' . ($http !== '' ? ' — ' . $http : '')];
    if (!empty($json['requestError'])) {
        $e = $json['requestError'];
        return ['ok' => false, 'error' => ($e['serviceException']['text'] ?? $e['serviceException']['messageId'] ?? 'Infobip request error')];
    }
    $sent   = $json['messages'][0] ?? [];
    $status = $sent['status'] ?? [];
    $groupId = (int)($status['groupId'] ?? 0);
    $ref     = (string)($sent['messageId'] ?? '');
    $state   = (string)($status['groupName'] ?? $status['name'] ?? '');
    // 1 = DELIVERED, 3 = PENDING (accepted for delivery)
    if (in_array($groupId, [1, 3], true)) {
        return [
            'ok'                => true,
            'provider_ref'      => $ref,
            'provider_status'   => $state,
            'provider_message'  => (string)($status['description'] ?? ''),
        ];
    }
    return [
        'ok'              => false,
        'error'           => $status['description'] ?? $status['name'] ?? 'Infobip rejected the message',
        'provider_ref'    => $ref,
        'provider_status' => $state,
    ];
}

/** Fetch the current delivery status of a sent SMS. */
function sms_delivery_status(string $messageId, string $provider = ''): array {
    $provider = $provider !== '' ? strtolower($provider) : sms_provider();
    return match ($provider) {
        'infobip' => _infobip_delivery_status($messageId),
        'yoola'   => _yoola_delivery_status($messageId),
        default   => ['ok' => false, 'error' => 'Delivery status is not supported for ' . $provider . '.'],
    };
}

function _infobip_delivery_status(string $messageId): array {
    $base = _infobip_base_url();
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "Accept: application/json\r\nAuthorization: App " . sms_api_key(),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($base . '/sms/1/reports?messageId=' . urlencode($messageId), false, $ctx);
    if ($resp === false) return ['ok' => false, 'error' => 'Infobip delivery report unreachable.'];
    $json = json_decode($resp, true);
    if (!is_array($json)) return ['ok' => false, 'error' => 'Invalid delivery report from Infobip.'];
    $result = $json['results'][0] ?? [];
    if (!$result) return ['ok' => false, 'error' => 'Delivery report not available yet.'];
    return parse_infobip_delivery_report($result);
}

/**
 * Normalize an Infobip delivery-report result while preserving its detailed
 * status name, numeric failure code, and human-readable reason.
 */
function parse_infobip_delivery_report(array $result): array {
    $status = $result['status'] ?? [];
    $error = $result['error'] ?? [];
    if (!is_array($status)) $status = [];
    if (!is_array($error)) $error = [];
    $descriptions = array_values(array_unique(array_filter([
        trim((string)($status['description'] ?? '')),
        trim((string)($error['description'] ?? '')),
    ], static fn(string $description): bool => $description !== '' && strcasecmp($description, 'No error') !== 0)));
    $code = null;
    foreach ([
        $error['id'] ?? null,
        $error['code'] ?? null,
        $status['errorCode'] ?? null,
        $result['errorCode'] ?? null,
        $status['id'] ?? null,
    ] as $candidate) {
        if (is_numeric($candidate) && (int)$candidate > 0) {
            $code = (int)$candidate;
            break;
        }
    }
    if ($code === null) {
        foreach ($descriptions as $description) {
            if (preg_match('/\bcode\s*[:#]?\s*(\d+)\b/i', $description, $matches)) {
                $code = (int)$matches[1];
                break;
            }
        }
    }

    return [
        'ok'          => true,
        'status'      => (string)($status['groupName'] ?? ''),
        'name'        => (string)($status['name'] ?? ''),
        'code'        => $code,
        'description' => implode(' — ', $descriptions),
    ];
}

/**
 * Classify a provider delivery failure so callers can decide whether a retry
 * is worthwhile and show a useful explanation to the user.
 */
function sms_failure_details(?int $code, string $statusName = '', string $description = ''): array {
    $name = strtoupper(trim($statusName));
    $desc = strtoupper(trim($description));
    $code = ($code !== null && $code > 0) ? $code : null;

    if ($code === 32 || str_contains($name, 'UNDELIVERABLE_NOT_DELIVERED')
        || (str_contains($desc, 'HANDSET') && str_contains($desc, 'ERROR'))) {
        return [
            'code'      => 32,
            'category'  => 'handset',
            'retriable' => true,
            'reason'    => 'The handset was unreachable. It may be switched off, out of network coverage, or have a full inbox.',
            'action'    => 'The system will retry once automatically. If it still fails, confirm the number with the officer or ask them to switch on the phone.',
        ];
    }
    if ($code === 9 || str_contains($name, 'ABSENT_SUBSCRIBER')) {
        return [
            'code'      => 9,
            'category'  => 'subscriber_unavailable',
            'retriable' => true,
            'reason'    => 'The subscriber was temporarily unavailable on the mobile network.',
            'action'    => 'The system will retry once automatically. If it still fails, confirm the number is active.',
        ];
    }
    if ($code === 10 || str_contains($name, 'EXPIRED')) {
        return [
            'code'      => 10,
            'category'  => 'expired',
            'retriable' => false,
            'reason'    => 'The network could not deliver the message before it expired.',
            'action'    => 'Check that the handset is reachable, then retry manually.',
        ];
    }
    if (in_array($code, [11, 12, 13], true) || str_starts_with($name, 'REJECTED')) {
        return [
            'code'      => $code,
            'category'  => 'rejected',
            'retriable' => false,
            'reason'    => 'The mobile network rejected the message. This is often caused by DND, an invalid route, or an invalid sender.',
            'action'    => 'Verify the number, sender ID and provider account permissions before retrying.',
        ];
    }
    if (str_starts_with($name, 'UNDELIVERABLE')) {
        return [
            'code'      => $code,
            'category'  => 'undeliverable',
            'retriable' => false,
            'reason'    => $description !== '' ? $description : 'The mobile network reported the message as undeliverable.',
            'action'    => 'Confirm the number with the officer before retrying.',
        ];
    }

    return [
        'code'      => $code,
        'category'  => 'unknown',
        'retriable' => false,
        'reason'    => $description !== '' ? $description : ($statusName !== '' ? $statusName : 'The provider reported a delivery failure.'),
        'action'    => 'Check the provider delivery report before retrying.',
    ];
}

/**
 * Yoola SMS API base URL. Defaults to the production REST API v1 and can be
 * overridden with the sms_yoola_base_url setting when needed.
 */
function _yoola_base_url(): string {
    $base = trim(get_setting('sms_yoola_base_url', 'https://yoolasms.com/api/v1'));
    if ($base === '') $base = 'https://yoolasms.com/api/v1';
    if (!preg_match('#^https?://#i', $base)) $base = 'https://' . $base;
    return rtrim($base, '/');
}

/**
 * POST a JSON request to a Yoola SMS endpoint. The API key travels in the
 * JSON body as required by https://yoolasms.com/api-integration.
 *
 * @return array{ok:bool,json?:array,error?:string}
 */
function _yoola_api_post(string $endpoint, array $payload, string $apiKey): array {
    $payload['api_key'] = $apiKey;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nAccept: application/json",
        'content'       => $body,
        'timeout'       => 20,
        'ignore_errors' => true,
    ]]);
    $url  = _yoola_base_url() . '/' . ltrim($endpoint, '/');
    $resp = @file_get_contents($url, false, $ctx);
    $http = $http_response_header[0] ?? '';
    if ($resp === false) return ['ok' => false, 'error' => 'Yoola SMS API unreachable (' . _yoola_base_url() . ')'];
    $json = json_decode($resp, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'Invalid response from Yoola SMS' . ($http !== '' ? ' — ' . $http : '')];
    }
    return ['ok' => true, 'json' => $json];
}

/**
 * Yoola SMS send endpoint (POST /api/v1/send).
 * `phone` is sent without the leading "+" and `sender` is optional; when it is
 * omitted Yoola uses the account's default YoolaSMS sender.
 */
function _sms_yoola(string $to, string $message, string $apiKey, string $from): array {
    $payload = [
        'phone'   => ltrim($to, '+'),
        'message' => $message,
    ];
    if (trim($from) !== '') $payload['sender'] = trim($from);

    $res = _yoola_api_post('send', $payload, $apiKey);
    if (empty($res['ok'])) return $res;

    $json   = $res['json'];
    $status = strtolower(trim((string)($json['status'] ?? '')));
    if (!in_array($status, ['success', 'ok', 'sent', 'queued', 'accepted'], true)) {
        return ['ok' => false, 'error' => (string)($json['message'] ?? $json['error'] ?? 'Yoola SMS rejected the message')];
    }

    $failed     = isset($json['failed']) && is_numeric($json['failed']) ? (int)$json['failed'] : 0;
    $successful = isset($json['successful']) && is_numeric($json['successful']) ? (int)$json['successful'] : null;
    if ($failed > 0 && ($successful === null || $successful === 0)) {
        return ['ok' => false, 'error' => (string)($json['message'] ?? 'Yoola SMS reported that the message failed.')];
    }

    return [
        'ok'               => true,
        'provider_ref'     => (string)($json['message_id'] ?? $json['messageId'] ?? ''),
        'provider_status'  => 'PENDING',
        'provider_message' => (string)($json['message'] ?? ''),
    ];
}

/**
 * Yoola SMS delivery report (POST /api/v1/delivery_report). The response shape
 * is normalised to the same status/name/code/description fields used by the
 * Infobip reporter so the leave SMS log can render either provider uniformly.
 */
function _yoola_delivery_status(string $messageId): array {
    $res = _yoola_api_post('delivery_report', ['message_id' => $messageId], sms_api_key());
    if (empty($res['ok'])) return $res;

    $json = $res['json'];
    $data = $json;
    foreach (['data', 'report', 'result', 'message'] as $key) {
        if (isset($json[$key]) && is_array($json[$key])) { $data = $json[$key]; break; }
    }

    $raw = $data['delivery_status'] ?? $data['message_status'] ?? $data['state'] ?? $data['status'] ?? '';
    if (is_array($raw)) $raw = $raw['name'] ?? $raw['status'] ?? '';
    $raw = trim((string)$raw);

    // A few responses use "success" as the envelope status; prefer an explicit
    // delivery field when one is present.
    if (strcasecmp($raw, 'success') === 0) {
        $raw = trim((string)($data['delivery_status'] ?? $data['message_status'] ?? ''));
    }
    if ($raw === '') return ['ok' => false, 'error' => 'Delivery report not available yet.'];

    $description = $data['description'] ?? $data['reason'] ?? $data['error'] ?? '';
    if (is_array($description)) $description = $description['description'] ?? $description['message'] ?? '';
    $description = trim((string)$description);

    $code = null;
    foreach ([$data['error_code'] ?? null, $data['code'] ?? null] as $candidate) {
        if (is_numeric($candidate) && (int)$candidate > 0) { $code = (int)$candidate; break; }
    }

    $key = strtolower(str_replace([' ', '-'], '_', $raw));
    if (in_array($key, ['delivered', 'success', 'delivered_to_handset', 'completed'], true)) {
        return ['ok' => true, 'status' => 'DELIVERED', 'name' => 'DELIVERED', 'code' => $code, 'description' => $description];
    }
    if (in_array($key, ['pending', 'queued', 'sent', 'submitted', 'accepted', 'processing', 'in_progress', 'scheduled'], true)) {
        return ['ok' => true, 'status' => 'PENDING', 'name' => 'PENDING', 'code' => $code, 'description' => $description];
    }
    if (in_array($key, ['failed', 'failure', 'undelivered', 'not_delivered', 'rejected', 'expired', 'error', 'cancelled', 'canceled'], true)) {
        $name = match ($key) {
            'rejected' => 'REJECTED',
            'expired'  => 'EXPIRED',
            default    => 'UNDELIVERABLE',
        };
        return ['ok' => true, 'status' => $name, 'name' => $name, 'code' => $code, 'description' => $description];
    }

    return ['ok' => true, 'status' => strtoupper($raw), 'name' => strtoupper($raw), 'code' => $code, 'description' => $description];
}

function _sms_twilio(string $to, string $message, string $accountSid, string $authToken, string $from): array {
    $url  = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
    $data = http_build_query(['To' => $to, 'From' => $from, 'Body' => $message]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nAuthorization: Basic " . base64_encode("{$accountSid}:{$authToken}"),
        'content' => $data,
        'timeout' => 15,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return ['ok' => false, 'error' => 'Twilio API unreachable'];
    $json = json_decode($resp, true);
    return isset($json['sid']) ? ['ok' => true] : ['ok' => false, 'error' => $json['message'] ?? 'Twilio error'];
}

