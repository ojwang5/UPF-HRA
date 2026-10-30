<?php
declare(strict_types=1);

/* ─── SMS sender ─── */
const SMS_PROVIDERS = ['infobip', 'africas_talking', 'twilio', 'nexmo'];

/** The configured provider, always normalised to a supported value. */
function sms_provider(): string {
    $provider = strtolower(trim(get_setting('sms_provider', 'infobip')));
    return in_array($provider, SMS_PROVIDERS, true) ? $provider : 'infobip';
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
    // Infobip can use the account's default sender when none is configured.
    if (sms_provider() === 'infobip') return true;
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
 * Supported providers: infobip, africas_talking, twilio, nexmo (vonage)
 */
function send_sms(string $phone, string $message): array {
    $provider  = sms_provider();
    $apiKey    = sms_api_key();
    $apiSecret = get_setting('sms_api_secret');
    $username  = get_setting('sms_username');
    $senderId  = get_setting('sms_sender_id');

    if (!$apiKey || ($provider !== 'infobip' && !$senderId)) {
        return ['ok' => false, 'error' => 'SMS not configured. Set credentials in Settings.'];
    }

    $phone = normalize_sms_phone($phone);
    if ($phone === null) {
        return ['ok' => false, 'error' => 'Personnel phone number is invalid. Use a valid international number or a Ugandan number such as 07XXXXXXXX.'];
    }

    return match ($provider) {
        'infobip'         => _sms_infobip($phone, $message, $apiKey, $senderId),
        'africas_talking' => _sms_africas_talking($phone, $message, $apiKey, $username, $senderId),
        'twilio'          => _sms_twilio($phone, $message, $apiKey, $apiSecret, $senderId),
        'nexmo'           => _sms_nexmo($phone, $message, $apiKey, $apiSecret, $senderId),
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

function _sms_africas_talking(string $to, string $message, string $apiKey, string $username, string $from): array {
    $url  = 'https://api.africastalking.com/version1/messaging';
    $data = http_build_query([
        'username' => $username ?: 'sandbox',
        'to'       => $to,
        'message'  => $message,
        'from'     => $from,
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nApiKey: {$apiKey}\r\nAccept: application/json",
        'content' => $data,
        'timeout' => 15,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return ['ok' => false, 'error' => 'Africa\'s Talking API unreachable'];
    $json = json_decode($resp, true);
    $status = $json['SMSMessageData']['Recipients'][0]['status'] ?? '';
    return $status === 'Success' ? ['ok' => true] : ['ok' => false, 'error' => $status ?: 'Unknown AT error'];
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

function _sms_nexmo(string $to, string $message, string $apiKey, string $apiSecret, string $from): array {
    $to = ltrim($to, '+');
    $url = 'https://rest.nexmo.com/sms/json?' . http_build_query([
        'api_key' => $apiKey, 'api_secret' => $apiSecret,
        'to' => $to, 'from' => $from, 'text' => $message,
    ]);
    $resp = @file_get_contents($url);
    if ($resp === false) return ['ok' => false, 'error' => 'Nexmo API unreachable'];
    $json = json_decode($resp, true);
    $status = $json['messages'][0]['status'] ?? '1';
    return $status === '0' ? ['ok' => true] : ['ok' => false, 'error' => $json['messages'][0]['error-text'] ?? 'Nexmo error'];
}
