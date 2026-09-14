<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function notify(string $title, string $message, string $audience, array $opts = []): int {
    $stmt = db()->prepare("INSERT INTO notifications
        (title, message, link, kind, audience, target_user_id, target_role, target_region_id, target_directorate_id, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $title, $message,
        $opts['link'] ?? null,
        $opts['kind'] ?? 'info',
        $audience,
        $opts['target_user_id'] ?? null,
        $opts['target_role'] ?? null,
        $opts['target_region_id'] ?? null,
        $opts['target_directorate_id'] ?? null,
        $opts['created_by'] ?? null,
        date('c'),
    ]);
    return (int)db()->lastInsertId();
}

function notifications_for(array $user, bool $unreadOnly = false, int $limit = 80): array {
    $uid  = (int)$user['id'];
    $role = $user['role'];
    $rid  = $user['region_id'] ? (int)$user['region_id'] : -1;
    $udir = $user['directorate_id'] ? (int)$user['directorate_id'] : -1;

    $sql = "SELECT n.*, u.full_name AS sender, r.read_at AS read_at_user
            FROM notifications n
            LEFT JOIN users u ON u.id=n.created_by
            LEFT JOIN notification_reads r ON r.notification_id=n.id AND r.user_id=:uid
            WHERE (
                n.audience='all'
                OR (n.audience='user' AND n.target_user_id=:uid)
                OR (n.audience='role' AND n.target_role=:role)
                OR (n.audience='region' AND n.target_region_id=:rid)
                OR (n.audience='directorate' AND n.target_directorate_id=:udir)
            )";
    if ($unreadOnly) $sql .= " AND r.read_at IS NULL";
    $sql .= " ORDER BY n.created_at DESC LIMIT ".(int)$limit;
    $stmt = db()->prepare($sql);
    $stmt->execute([':uid'=>$uid,':role'=>$role,':rid'=>$rid,':udir'=>$udir]);
    return $stmt->fetchAll();
}

function unread_notification_count(array $user): int {
    return count(notifications_for($user, true, 200));
}

function mark_notification_read(int $notificationId, int $userId): void {
    db()->prepare("INSERT OR IGNORE INTO notification_reads (notification_id,user_id,read_at) VALUES (?,?,?)")
       ->execute([$notificationId, $userId, date('c')]);
}

function mark_all_read(array $user): void {
    foreach (notifications_for($user, true, 500) as $n) {
        mark_notification_read((int)$n['id'], (int)$user['id']);
    }
}

function notify_superadmins(string $title, string $msg, array $opts = []): void {
    notify($title, $msg, 'role', array_merge($opts, ['target_role'=>'superadmin']));
}

function notify_region(int $regionId, string $title, string $msg, array $opts = []): void {
    notify($title, $msg, 'region', array_merge($opts, ['target_region_id'=>$regionId]));
}

/** Notify every user account attached to a directorate (transfer alerts, etc). */
function notify_directorate(int $directorateId, string $title, string $msg, array $opts = []): void {
    notify($title, $msg, 'directorate', array_merge($opts, ['target_directorate_id'=>$directorateId]));
}
