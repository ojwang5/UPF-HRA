<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/communications.php';

$user = require_login();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Attachment not found.'); }

$pdo = db();
$stmt = $pdo->prepare("SELECT a.*, m.sender_id, m.target_type, m.target_id, m.target_role, m.title
                       FROM comm_attachments a
                       JOIN comm_messages m ON m.id=a.message_id
                       WHERE a.id=?");
$stmt->execute([$id]);
$att = $stmt->fetch();
if (!$att) { http_response_code(404); exit('Attachment not found.'); }

$message = [
    'sender_id'   => $att['sender_id'],
    'target_type' => $att['target_type'],
    'target_id'   => $att['target_id'],
    'target_role' => $att['target_role'],
];
if (!comm_can_view_message($user, $message)) {
    http_response_code(403);
    exit('You do not have access to this attachment.');
}

$path = comm_attachment_path($att);
if (!is_file($path)) { http_response_code(404); exit('File is no longer available.'); }

$name = (string)$att['file_name'];
$fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'attachment';
$mime = (string)($att['mime_type'] ?: 'application/octet-stream');
$inline = isset($_GET['inline']) && $_GET['inline'] === '1'
    && in_array((string)$att['kind'], ['image', 'video'], true);

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Cache-Control: private, no-store');
readfile($path);
exit;
