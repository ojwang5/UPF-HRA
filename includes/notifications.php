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

    $db = db();
    // Ensure deletion table exists
    try { $db->exec("CREATE TABLE IF NOT EXISTS notification_deletions (notification_id INTEGER NOT NULL, user_id INTEGER NOT NULL, deleted_at TEXT NOT NULL, PRIMARY KEY(notification_id,user_id))"); } catch (Exception $e) {}

    $sql = "SELECT n.*, u.full_name AS sender, r.read_at AS read_at_user
            FROM notifications n
            LEFT JOIN users u ON u.id=n.created_by
            LEFT JOIN notification_reads r ON r.notification_id=n.id AND r.user_id=:uid
            LEFT JOIN notification_deletions d ON d.notification_id=n.id AND d.user_id=:uid
            WHERE d.notification_id IS NULL AND (
                n.audience='all'
                OR (n.audience='user' AND n.target_user_id=:uid)
                OR (n.audience='role' AND n.target_role=:role)
                OR (n.audience='region' AND n.target_region_id=:rid)
                OR (n.audience='directorate' AND n.target_directorate_id=:udir)
            )";
    if ($unreadOnly) $sql .= " AND r.read_at IS NULL";
    $sql .= " ORDER BY n.created_at DESC LIMIT ".(int)$limit;
    $stmt = $db->prepare($sql);
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

function delete_notification(int $notificationId, int $userId): void {
    $db = db();
    // Remove read marker if exists
    $db->prepare("DELETE FROM notification_reads WHERE notification_id=? AND user_id=?")->execute([$notificationId, $userId]);
    // Mark as deleted for this user by adding to a deletion table if it exists, else just rely on read state
    // Try to create/delete marker
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS notification_deletions (notification_id INTEGER NOT NULL, user_id INTEGER NOT NULL, deleted_at TEXT NOT NULL, PRIMARY KEY(notification_id,user_id))");
        $db->prepare("INSERT OR REPLACE INTO notification_deletions (notification_id,user_id,deleted_at) VALUES (?,?,?)")
           ->execute([$notificationId, $userId, date('c')]);
    } catch (Exception $e) {
        // ignore
    }
}

function delete_all_notifications(array $user): void {
    $uid = (int)$user['id'];
    $db = db();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS notification_deletions (notification_id INTEGER NOT NULL, user_id INTEGER NOT NULL, deleted_at TEXT NOT NULL, PRIMARY KEY(notification_id,user_id))");
        foreach (notifications_for($user, false, 1000) as $n) {
            $db->prepare("INSERT OR IGNORE INTO notification_deletions (notification_id,user_id,deleted_at) VALUES (?,?,?)")
               ->execute([(int)$n['id'], $uid, date('c')]);
        }
    } catch (Exception $e) {
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
