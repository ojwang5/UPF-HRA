<?php
declare(strict_types=1);

/* ─── SMS sender ─── */
function sms_configured(): bool {
    return get_setting('sms_api_key') !== '' && get_setting('sms_sender_id') !== '';
}

/**
 * Send an SMS via the configured provider.
 * Supported providers: africas_talking, twilio, nexmo (vonage)
 */
function send_sms(string $phone, string $message): array {
    $provider  = get_setting('sms_provider', 'africas_talking');
    $apiKey    = get_setting('sms_api_key');
    $apiSecret = get_setting('sms_api_secret');
    $username  = get_setting('sms_username');
    $senderId  = get_setting('sms_sender_id');

    if (!$apiKey || !$senderId) {
        return ['ok' => false, 'error' => 'SMS not configured. Set credentials in Settings.'];
    }

    // Normalize phone: ensure starts with country code
    $phone = preg_replace('/[^\d+]/', '', $phone);
    if (str_starts_with($phone, '0')) {
        $phone = '+256' . substr($phone, 1); // Uganda default
    }

    return match ($provider) {
        'africas_talking' => _sms_africas_talking($phone, $message, $apiKey, $username, $senderId),
        'twilio'          => _sms_twilio($phone, $message, $apiKey, $apiSecret, $senderId),
        'nexmo'           => _sms_nexmo($phone, $message, $apiKey, $apiSecret, $senderId),
        default           => ['ok' => false, 'error' => "Unknown SMS provider: {$provider}"],
    };
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
