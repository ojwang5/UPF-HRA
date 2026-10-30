<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

/**
 * Communications access rules.
 *
 * - HRD (Human Resource Development) owns the course/training registry.
 * - The Signals directorate/unit owns the communications channels.
 */
function is_hrd_user(array $user): bool {
    if (($user['role'] ?? '') === 'superadmin') return true;
    $code = strtoupper((string)($user['directorate_code'] ?? ''));
    $name = strtolower((string)($user['directorate_name'] ?? ''));
    return in_array($code, ['HRD', 'TRN'], true)
        || str_contains($name, 'human resource development')
        || str_contains($name, 'training');
}

function is_signal_user(array $user): bool {
    if (($user['role'] ?? '') === 'superadmin') return true;
    $uc = strtoupper((string)($user['unit_code'] ?? ''));
    $un = strtolower((string)($user['unit_name'] ?? ''));
    $dc = strtoupper((string)($user['directorate_code'] ?? ''));
    $dn = strtolower((string)($user['directorate_name'] ?? ''));
    return in_array($uc, ['SIG', 'SIGNAL'], true)
        || in_array($dc, ['SIG', 'SIGNAL'], true)
        || str_contains($un, 'signal')
        || str_contains($dn, 'signal');
}

/* ─── Inbound message targeting & visibility ─── */

function comm_target_label(PDO $pdo, string $type, ?int $id, ?string $role = null): string {
    if ($type === 'all') return 'All personnel';
    if ($type === 'role') return role_label((string)$role) . ' (role)';
    if ($type === 'user') {
        $st = $pdo->prepare("SELECT full_name FROM users WHERE id=?");
        $st->execute([(int)$id]);
        $n = (string)($st->fetchColumn() ?: '');
        return $n !== '' ? $n : 'Specific user';
    }
    $map = [
        'region'      => ['regions', 'Region'],
        'division'    => ['divisions', 'Division'],
        'station'     => ['stations', 'Station'],
        'post'        => ['posts', 'Post'],
        'directorate' => ['directorates', 'Directorate'],
        'unit'        => ['units', 'Unit'],
    ];
    if (!isset($map[$type]) || !$id) return ucfirst($type);
    [$table, $suffix] = $map[$type];
    $st = $pdo->prepare("SELECT name FROM {$table} WHERE id=?");
    $st->execute([(int)$id]);
    $name = (string)($st->fetchColumn() ?: '');
    return ($name !== '' ? $name : ucfirst($type)) . ' ' . $suffix;
}

/** SQL predicate + params for messages visible to a non-signal user. */
function comm_visibility_where(array $user): array {
    $parts = ["m.target_type='all'", 'm.sender_id=?'];
    $params = [(int)$user['id']];

    $scope = [
        'region'      => ['region_id',      $user['region_id']      ?? null],
        'division'    => ['division_id',    $user['division_id']    ?? null],
        'station'     => ['station_id',     $user['station_id']     ?? null],
        'post'        => ['post_id',        $user['post_id']        ?? null],
        'directorate' => ['directorate_id', $user['directorate_id'] ?? null],
        'unit'        => ['unit_id',        $user['unit_id']        ?? null],
    ];
    foreach ($scope as $type => [$col, $value]) {
        if ($value !== null && (int)$value > 0) {
            $parts[] = "(m.target_type='{$type}' AND m.target_id=?)";
            $params[] = (int)$value;
        }
    }
    $parts[] = "(m.target_type='role' AND m.target_role=?)";
    $params[] = (string)($user['role'] ?? '');
    $parts[] = "(m.target_type='user' AND m.target_id=?)";
    $params[] = (int)$user['id'];

    return ['(' . implode(' OR ', $parts) . ')', $params];
}

/** Confirm a destination exists before a message is stored. */
function comm_target_valid(PDO $pdo, string $type, ?int $id, ?string $role = null): bool {
    if ($type === 'all') return true;
    if ($type === 'role') return $role !== null && isset(ROLE_LABELS[$role]);
    if ($type === 'user') {
        if (!$id) return false;
        $st = $pdo->prepare("SELECT 1 FROM users WHERE id=?");
        $st->execute([$id]);
        return (bool)$st->fetchColumn();
    }
    $map = [
        'region'      => 'regions',
        'division'    => 'divisions',
        'station'     => 'stations',
        'post'        => 'posts',
        'directorate' => 'directorates',
        'unit'        => 'units',
    ];
    if (!isset($map[$type]) || !$id) return false;
    $st = $pdo->prepare("SELECT 1 FROM {$map[$type]} WHERE id=?");
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

/** SQL predicate selecting the users a saved message was addressed to. */
function comm_target_scope(array $message): array {
    $type = (string)($message['target_type'] ?? 'all');
    if ($type === 'all') return ['1=1', []];
    if ($type === 'role') return ['u.role=?', [(string)($message['target_role'] ?? '')]];
    if ($type === 'user') return ['u.id=?', [(int)($message['target_id'] ?? 0)]];
    $map = [
        'region'      => 'u.region_id',
        'division'    => 'u.division_id',
        'station'     => 'u.station_id',
        'post'        => 'u.post_id',
        'directorate' => 'u.directorate_id',
        'unit'        => 'u.unit_id',
    ];
    if (!isset($map[$type])) return ['1=0', []];
    return ["{$map[$type]}=?", [(int)($message['target_id'] ?? 0)]];
}

function comm_target_user_count(PDO $pdo, array $message): int {
    [$where, $params] = comm_target_scope($message);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE {$where}");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * Delivery tracking for a saved message: intended audience and who has read it.
 * @return array{audience:int,read:int,recipients:array<int,array>}
 */
function comm_message_tracking(PDO $pdo, int $messageId, array $message): array {
    [$where, $params] = comm_target_scope($message);
    $stmt = $pdo->prepare("SELECT u.id, u.full_name, u.role, cr.read_at
                           FROM users u
                           LEFT JOIN comm_reads cr ON cr.message_id=? AND cr.user_id=u.id
                           WHERE {$where}
                           ORDER BY u.full_name");
    $stmt->execute(array_merge([$messageId], $params));
    $recipients = $stmt->fetchAll();
    $read = 0;
    foreach ($recipients as $r) if (!empty($r['read_at'])) $read++;
    return ['audience' => count($recipients), 'read' => $read, 'recipients' => $recipients];
}

function comm_can_view_message(array $user, array $message): bool {
    if (is_signal_user($user)) return true;
    if ((int)($message['sender_id'] ?? 0) === (int)$user['id']) return true;
    $type = (string)($message['target_type'] ?? 'all');
    if ($type === 'all') return true;
    if ($type === 'role') return (string)($message['target_role'] ?? '') === (string)($user['role'] ?? '');
    $value = match ($type) {
        'region'      => $user['region_id']      ?? null,
        'division'    => $user['division_id']    ?? null,
        'station'     => $user['station_id']     ?? null,
        'post'        => $user['post_id']        ?? null,
        'directorate' => $user['directorate_id'] ?? null,
        'unit'        => $user['unit_id']        ?? null,
        'user'        => $user['id']             ?? null,
        default       => null,
    };
    return $value !== null && (int)$value === (int)($message['target_id'] ?? 0);
}

/**
 * Inbound messages visible to the user.
 * @param bool $allInbox Signals users may request the full dispatch log.
 */
function inbound_messages_for(array $user, int $limit = 100, bool $unreadOnly = false, bool $allInbox = false): array {
    $db = db();
    if ($allInbox && is_signal_user($user)) {
        $where = '1=1';
        $params = [];
    } else {
        [$where, $params] = comm_visibility_where($user);
    }
    if ($unreadOnly) $where .= ' AND cr.read_at IS NULL';

    $sql = "SELECT m.*, u.full_name AS sender_name,
                   cr.read_at AS read_at_user,
                   (SELECT COUNT(*) FROM comm_reads rc WHERE rc.message_id=m.id) AS read_count,
                   (SELECT COUNT(*) FROM comm_attachments a WHERE a.message_id=m.id) AS attachment_count
            FROM comm_messages m
            LEFT JOIN users u ON u.id=m.sender_id
            LEFT JOIN comm_reads cr ON cr.message_id=m.id AND cr.user_id=?
            WHERE {$where}
            ORDER BY m.created_at DESC, m.id DESC
            LIMIT " . max(1, min(500, $limit));
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([(int)$user['id']], $params));
    return $stmt->fetchAll();
}

function inbound_unread_count(array $user): int {
    [$where, $params] = comm_visibility_where($user);
    $stmt = db()->prepare("SELECT COUNT(*)
                           FROM comm_messages m
                           LEFT JOIN comm_reads cr ON cr.message_id=m.id AND cr.user_id=?
                           WHERE {$where} AND cr.read_at IS NULL");
    $stmt->execute(array_merge([(int)$user['id']], $params));
    return (int)$stmt->fetchColumn();
}

function mark_comm_read(int $messageId, int $userId): void {
    try {
        db()->prepare("INSERT OR IGNORE INTO comm_reads (message_id,user_id,read_at) VALUES (?,?,?)")
            ->execute([$messageId, $userId, date('c')]);
    } catch (\Throwable $e) { /* never block reading a message */ }
}

function mark_all_comm_read(array $user): void {
    foreach (inbound_messages_for($user, 500, true, false) as $m) {
        mark_comm_read((int)$m['id'], (int)$user['id']);
    }
}

function comm_attachments(int $messageId): array {
    $stmt = db()->prepare("SELECT * FROM comm_attachments WHERE message_id=? ORDER BY id");
    $stmt->execute([$messageId]);
    return $stmt->fetchAll();
}

/* ─── Attachment uploads (documents, images, videos < 20 MB) ─── */

const COMM_MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;

function comm_attachment_kinds(): array {
    return [
        'pdf'  => 'document', 'doc' => 'document', 'docx' => 'document',
        'xls'  => 'document', 'xlsx' => 'document', 'ppt' => 'document', 'pptx' => 'document',
        'txt'  => 'document', 'csv' => 'document', 'odt' => 'document', 'ods' => 'document',
        'jpg'  => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
        'webp' => 'image', 'bmp' => 'image',
        'mp4'  => 'video', 'mov' => 'video', 'avi' => 'video', 'mkv' => 'video',
        'webm' => 'video', 'm4v' => 'video', '3gp' => 'video',
    ];
}

function comm_upload_dir(): string {
    $dir = BASE_PATH . '/data/comm_uploads';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

function comm_attachment_path(array $attachment): string {
    return comm_upload_dir() . '/' . basename((string)$attachment['stored_name']);
}

/**
 * Stores all files posted under $field for a message.
 * @return array<int,string> human-readable errors (empty = all stored)
 */
function save_comm_uploads(int $messageId, string $field, PDO $pdo): array {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) return [];

    $files = $_FILES[$field];
    $allowed = comm_attachment_kinds();
    $dir = comm_upload_dir();
    $errors = [];
    $count = count($files['name']);

    for ($i = 0; $i < $count; $i++) {
        $err  = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $orig = (string)($files['name'][$i] ?? 'attachment');
        if ($err !== UPLOAD_ERR_OK) { $errors[] = $orig . ': upload failed.'; continue; }

        $size = (int)($files['size'][$i] ?? 0);
        if ($size <= 0) { $errors[] = $orig . ': empty file.'; continue; }
        if ($size > COMM_MAX_ATTACHMENT_BYTES) { $errors[] = $orig . ': exceeds the 20 MB limit.'; continue; }

        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) { $errors[] = $orig . ': file type is not allowed.'; continue; }

        $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $stored;
        if (!move_uploaded_file((string)$files['tmp_name'][$i], $dest)) {
            $errors[] = $orig . ': could not be saved.';
            continue;
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) { $mime = (string)@finfo_file($fi, $dest); }
        }

        $pdo->prepare("INSERT INTO comm_attachments (message_id,file_name,stored_name,mime_type,size_bytes,kind)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$messageId, $orig, $stored, $mime ?: null, $size, $allowed[$ext]]);
    }

    return $errors;
}

function comm_size_label(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

/** Nav items that are only useful to Signals / HRD users. */
function comm_nav_visible(array $user): bool {
    return true;
}
