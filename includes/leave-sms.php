<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/sms.php';

/**
 * Build the SMS body sent to a personnel member when their leave is decided.
 *
 * Includes the decision and, when approved, the effective leave end date.
 */
function leave_sms_message(array $req, string $status, string $eventType = 'leave', ?string $extensionEndDate = null): string {
    $no   = trim((string)($req['service_no'] ?? ''));
    $rank = trim((string)($req['rank'] ?? ''));
    $name = trim((string)($req['full_name'] ?? ($req['emp_name'] ?? '')));
    $type = trim((string)($req['leave_type'] ?? 'leave'));

    $person = trim($rank . ' ' . $name);
    if ($no !== '' && $person !== '') $who = 'No. ' . $no . ', ' . $person;
    elseif ($no !== '')                $who = 'No. ' . $no;
    else                               $who = $person !== '' ? $person : 'Officer';

    if ($type === '') $type = 'leave';
    if (!preg_match('/\bleave\b/i', $type)) $type .= ' leave';

    if ($eventType === 'extension') {
        if ($status === 'approved') {
            $end = $extensionEndDate ?? (string)($req['end_date'] ?? '');
            if ($end !== '' && strtotime($end) !== false) {
                $end = date('j M Y', strtotime($end));
            }
            return "Dear {$who}, your {$type} extension has been approved. Your new end date is {$end}.";
        }
        return "Dear {$who}, your {$type} extension has been rejected.";
    }

    if (strtolower($status) === 'approved') {
        $end = (string)($req['end_date'] ?? '');
        if ($end !== '' && strtotime($end) !== false) {
            $end = date('j M Y', strtotime($end));
        }
        return "Dear {$who}, your {$type} has been approved. It ends on {$end}.";
    }

    return "Dear {$who}, your {$type} has been rejected.";
}

function leave_sms_delivery_failed(string $status): bool {
    $status = strtoupper(trim($status));
    foreach (['UNDELIVERABLE', 'EXPIRED', 'REJECTED'] as $prefix) {
        if (str_starts_with($status, $prefix)) return true;
    }
    return false;
}

/**
 * Send the leave decision SMS to the personnel member's phone number and log
 * the attempt. A successful notification is sent at most once per leave
 * leave or extension decision. Extension IDs keep separate requests distinct.
 *
 * @return array{ok:bool,error?:string,skipped?:bool}
 */
function send_leave_status_sms(PDO $pdo, int $leaveRequestId, string $status, string $eventType = 'leave', ?int $extensionId = null, bool $force = false): array {
    $status = strtolower(trim($status));
    $eventType = $eventType === 'extension' ? 'extension' : 'leave';
    if (!in_array($status, ['approved', 'rejected'], true)) {
        return ['ok' => false, 'error' => 'Invalid leave status.'];
    }
    if ($eventType === 'extension' && (!$extensionId || $extensionId <= 0)) {
        return ['ok' => false, 'error' => 'Leave extension not found.'];
    }

    $stmt = $pdo->prepare("SELECT lr.*, e.id AS emp_id, e.service_no, e.rank, e.full_name, e.phone
                           FROM leave_requests lr
                           JOIN employees e ON e.id = lr.employee_id
                           WHERE lr.id = ?");
    $stmt->execute([$leaveRequestId]);
    $req = $stmt->fetch();
    if (!$req) return ['ok' => false, 'error' => 'Leave request not found.'];
    if ($eventType === 'leave' && strtolower((string)$req['status']) !== $status) {
        return ['ok' => false, 'error' => 'Leave decision no longer matches this notification.'];
    }

    $extensionEndDate = null;
    if ($eventType === 'extension') {
        $extension = $pdo->prepare("SELECT new_end_date, status FROM leave_extensions
                                    WHERE id = ? AND leave_request_id = ? AND employee_id = ? AND status = ?
                                    LIMIT 1");
        $extension->execute([$extensionId, $leaveRequestId, (int)$req['emp_id'], $status]);
        $extensionEndDate = $extension->fetchColumn();
        if ($extensionEndDate === false) {
            return ['ok' => false, 'error' => 'Leave extension decision not found.'];
        }
    }

    $eventId = $eventType === 'extension' ? $extensionId : null;
    // Never resend a successful notification for the same decision.
    $already = $pdo->prepare("SELECT 1 FROM leave_sms_log
                              WHERE leave_request_id = ? AND status = ? AND event_type = ?
                                AND event_id IS ? AND response = 'ok'
                              LIMIT 1");
    $already->execute([$leaveRequestId, $status, $eventType, $eventId]);
    if (!$force && $already->fetchColumn()) {
        return ['ok' => true, 'skipped' => true];
    }

    $phone   = trim((string)($req['phone'] ?? ''));
    $normalizedPhone = normalize_sms_phone($phone);
    $message = leave_sms_message($req, $status, $eventType, $extensionEndDate === false ? null : $extensionEndDate);
    $provider = sms_provider();

    if ($phone === '') {
        $result = ['ok' => false, 'error' => 'No phone number on record.'];
    } elseif ($normalizedPhone === null) {
        $result = ['ok' => false, 'error' => 'Personnel phone number is invalid. Use a valid international number or a Ugandan number such as 07XXXXXXXX.'];
    } elseif (!sms_configured()) {
        $result = ['ok' => false, 'error' => 'SMS is not configured.'];
    } else {
        $result = send_sms($normalizedPhone, $message);
    }

    try {
        $log = $pdo->prepare("INSERT INTO leave_sms_log
            (leave_request_id, employee_id, phone, status, event_type, event_id, message, provider, response, provider_ref, provider_status, sent_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $log->execute([
            $leaveRequestId,
            (int)$req['emp_id'],
            $normalizedPhone ?? ($phone !== '' ? $phone : null),
            $status,
            $eventType,
            $eventId,
            $message,
            $provider,
            $result['ok'] ? 'ok' : (string)($result['error'] ?? 'failed'),
            $result['provider_ref'] ?? null,
            $result['provider_status'] ?? null,
            date('c'),
        ]);
    } catch (\Throwable $e) {
        // Logging must never stop a leave decision from being recorded.
    }

    log_activity(
        'Leave ' . $status . ' SMS ' . (!empty($result['ok']) ? 'sent' : 'failed'),
        'leave',
        personnel_label($req),
        $leaveRequestId,
        ($normalizedPhone ?? ($phone !== '' ? $phone : 'no phone')) . (!empty($result['error']) ? ' — ' . $result['error'] : '')
    );

    return $result;
}

/**
 * Refresh delivery status for recent Infobip leave SMS messages.
 * Returns the number of log rows whose status was updated.
 */
function refresh_leave_sms_delivery(PDO $pdo, int $limit = 25): int {
    if (!sms_configured() || sms_provider() !== 'infobip') return 0;

    $limit = max(1, min(100, $limit));
    $pdo->exec("UPDATE leave_sms_log
                SET response = 'undelivered: ' || provider_status
                WHERE provider = 'infobip' AND response = 'ok'
                  AND (UPPER(provider_status) LIKE 'UNDELIVERABLE%'
                    OR UPPER(provider_status) LIKE 'EXPIRED%'
                    OR UPPER(provider_status) LIKE 'REJECTED%')");
    $stmt = $pdo->prepare("SELECT id, provider_ref, provider_status, response
                           FROM leave_sms_log
                           WHERE provider = 'infobip'
                             AND provider_ref IS NOT NULL AND provider_ref <> ''
                             AND (
                                 (response = 'ok'
                                  AND COALESCE(UPPER(provider_status), '') NOT IN
                                      ('DELIVERED', 'UNDELIVERABLE', 'EXPIRED', 'REJECTED')
                                  AND UPPER(provider_status) NOT LIKE 'UNDELIVERABLE%'
                                  AND UPPER(provider_status) NOT LIKE 'EXPIRED%'
                                  AND UPPER(provider_status) NOT LIKE 'REJECTED%')
                                 OR (response LIKE 'undelivered:%'
                                     AND UPPER(provider_status) LIKE 'UNDELIVERABLE%'
                                     AND UPPER(provider_status) NOT LIKE '%(CODE %')
                             )
                           ORDER BY id DESC LIMIT {$limit}");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!$rows) return 0;

    $upd = $pdo->prepare("UPDATE leave_sms_log SET provider_status = ?, response = ? WHERE id = ?");
    $updated = 0;
    foreach ($rows as $row) {
        $state = sms_delivery_status((string)$row['provider_ref'], 'infobip');
        if (empty($state['ok'])) continue;
        $group = strtoupper((string)($state['status'] ?? ''));
        $desc  = trim((string)($state['description'] ?? ''));
        $name = strtoupper((string)($state['name'] ?? ''));
        $newStatus = $name !== '' ? $name : $group;
        if (!empty($state['code'])) {
            $newStatus .= ' (code ' . (int)$state['code'] . ')';
        }
        $response  = 'ok';
        if (leave_sms_delivery_failed($newStatus)) {
            $response = 'undelivered: ' . $newStatus . ($desc !== '' ? ' — ' . $desc : '');
        }
        if ($newStatus !== (string)$row['provider_status'] || $response !== (string)$row['response']) {
            $upd->execute([$newStatus, $response, (int)$row['id']]);
            $updated++;
        }
    }
    return $updated;
}
