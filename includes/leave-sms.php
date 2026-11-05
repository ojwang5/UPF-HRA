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
    foreach (['UNDELIVERABLE', 'EXPIRED', 'REJECTED', 'FAILED'] as $prefix) {
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
 * Retry recent handset/network delivery failures once. Reuses the existing log
 * row so the leave decision keeps a single delivery record.
 *
 * @return array{attempted:int,sent:int,failed:int}
 */
function retry_undeliverable_leave_sms(PDO $pdo, int $limit = 10): array {
    $result = ['attempted' => 0, 'sent' => 0, 'failed' => 0];
    if (!sms_configured()) return $result;

    $limit = max(1, min(50, $limit));
    $stmt = $pdo->prepare("SELECT l.*, lr.leave_type, lr.end_date, lr.status AS leave_status,
                                  e.service_no, e.rank, e.full_name, e.phone,
                                  le.status AS extension_status, le.new_end_date
                           FROM leave_sms_log l
                           JOIN leave_requests lr ON lr.id = l.leave_request_id
                           JOIN employees e ON e.id = l.employee_id
                           LEFT JOIN leave_extensions le ON l.event_type = 'extension' AND le.id = l.event_id
                           WHERE l.provider IN ('infobip', 'yoola')
                             AND l.response LIKE 'undelivered:%'
                             AND (l.provider <> 'infobip' OR l.failure_code IN (9, 32))
                             AND l.retry_count < 1
                             AND (l.last_retry_at IS NULL OR l.last_retry_at <= datetime('now','localtime','-30 minutes'))
                             AND (
                                 (l.event_type = 'leave' AND lr.status = l.status)
                                 OR (l.event_type = 'extension' AND le.status = l.status)
                             )
                             AND NOT EXISTS (
                                 SELECT 1 FROM leave_sms_log newer
                                 WHERE newer.leave_request_id = l.leave_request_id
                                   AND newer.status = l.status
                                   AND newer.event_type = l.event_type
                                   AND newer.event_id IS l.event_id
                                   AND newer.id > l.id
                                   AND newer.response = 'ok'
                             )
                           ORDER BY l.id ASC LIMIT {$limit}");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!$rows) return $result;

    $upd = $pdo->prepare("UPDATE leave_sms_log
                          SET phone = ?, message = ?, response = ?, provider_ref = ?, provider_status = ?,
                              sent_at = ?, retry_count = retry_count + 1, last_retry_at = datetime('now','localtime'),
                              failure_code = NULL, failure_reason = NULL
                          WHERE id = ?");
    $mark = $pdo->prepare("UPDATE leave_sms_log
                           SET last_retry_at = datetime('now','localtime'), failure_reason = ?
                           WHERE id = ?");

    foreach ($rows as $row) {
        $phone = trim((string)($row['phone'] ?? ''));
        $normalized = normalize_sms_phone($phone);
        if ($normalized === null) {
            $mark->execute([
                'Automatic retry could not run because no valid phone number is on record.',
                (int)$row['id'],
            ]);
            $result['failed']++;
            continue;
        }

        $extensionEnd = $row['event_type'] === 'extension' ? (string)($row['new_end_date'] ?? '') : null;
        $message = leave_sms_message($row, (string)$row['status'], (string)$row['event_type'], $extensionEnd);
        $res = send_sms($normalized, $message);
        $result['attempted']++;

        if (!empty($res['ok'])) {
            $result['sent']++;
            $response = 'ok';
        } else {
            $result['failed']++;
            $response = (string)($res['error'] ?? 'failed');
        }

        $upd->execute([
            $normalized,
            $message,
            $response,
            $res['provider_ref'] ?? null,
            $res['provider_status'] ?? null,
            date('c'),
            (int)$row['id'],
        ]);

        log_activity(
            'Automatic leave SMS retry ' . (!empty($res['ok']) ? 'sent' : 'failed'),
            'leave',
            trim((string)$row['rank'] . ' ' . (string)$row['full_name']),
            (int)$row['leave_request_id'],
            $normalized . (!empty($res['error']) ? ' — ' . $res['error'] : '')
        );
    }
    return $result;
}

/**
 * Refresh delivery status for recent leave SMS messages from the configured
 * provider (Infobip or Yoola). Returns the number of log rows updated.
 */
function refresh_leave_sms_delivery(PDO $pdo, int $limit = 25): int {
    $provider = sms_provider();
    if (!sms_configured() || !in_array($provider, ['infobip', 'yoola'], true)) return 0;

    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare("SELECT id, provider_ref, provider_status, response, failure_code, failure_reason
                           FROM leave_sms_log
                           WHERE provider = ?
                             AND provider_ref IS NOT NULL AND provider_ref <> ''
                             AND (
                                 (response = 'ok'
                                  AND COALESCE(UPPER(provider_status), '') NOT IN
                                      ('DELIVERED', 'UNDELIVERABLE', 'EXPIRED', 'REJECTED', 'FAILED')
                                  AND UPPER(provider_status) NOT LIKE 'UNDELIVERABLE%'
                                  AND UPPER(provider_status) NOT LIKE 'EXPIRED%'
                                  AND UPPER(provider_status) NOT LIKE 'REJECTED%'
                                  AND UPPER(provider_status) NOT LIKE 'FAILED%')
                                 OR (response LIKE 'undelivered:%'
                                     AND (failure_code IS NULL OR failure_reason IS NULL))
                             )
                           ORDER BY id DESC LIMIT {$limit}");
    $stmt->execute([$provider]);
    $rows = $stmt->fetchAll();
    if (!$rows) return 0;

    $upd = $pdo->prepare("UPDATE leave_sms_log
                          SET provider_status = ?, response = ?, failure_code = ?, failure_reason = ?
                          WHERE id = ?");
    $updated = 0;
    foreach ($rows as $row) {
        $state = sms_delivery_status((string)$row['provider_ref'], $provider);
        if (empty($state['ok'])) continue;
        $group = strtoupper((string)($state['status'] ?? ''));
        $desc  = trim((string)($state['description'] ?? ''));
        $name = strtoupper((string)($state['name'] ?? ''));
        $newStatus = $name !== '' ? $name : $group;
        if (!empty($state['code'])) {
            $newStatus .= ' (code ' . (int)$state['code'] . ')';
        }

        $failureCode = null;
        $failureReason = null;
        $response = 'ok';
        if (leave_sms_delivery_failed($newStatus)) {
            $failure = sms_failure_details(
                isset($state['code']) ? (int)$state['code'] : null,
                $name,
                $desc
            );
            $failureCode = $failure['code'];
            $failureReason = trim($failure['reason'] . ' ' . $failure['action']);
            $response = 'undelivered: ' . $newStatus
                . ($desc !== '' ? ' — ' . $desc : '')
                . ' — ' . $failure['reason'];
        }

        if ($newStatus !== (string)$row['provider_status']
            || $response !== (string)$row['response']
            || (string)$failureCode !== (string)$row['failure_code']
            || (string)$failureReason !== (string)$row['failure_reason']) {
            $upd->execute([$newStatus, $response, $failureCode, $failureReason, (int)$row['id']]);
            $updated++;
        }
    }
    return $updated;
}
