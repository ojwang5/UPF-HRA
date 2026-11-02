<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$f = basename((string)($_GET['f'] ?? ''));
if ($f === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $f) || str_contains($f, '..')) {
    http_response_code(400);
    exit;
}

$width = max(32, min(800, (int)($_GET['w'] ?? 160)));
$src = __DIR__ . '/uploads/photos/' . $f;
if (!is_file($src)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png'         => 'image/png',
    'webp'        => 'image/webp',
    'gif'         => 'image/gif',
    default       => '',
};
if ($mime === '') {
    http_response_code(415);
    exit;
}

function send_photo(string $file, string $mime, bool $cacheable): void {
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($file));
    header('X-Content-Type-Options: nosniff');
    header($cacheable
        ? 'Cache-Control: public, max-age=31536000, immutable'
        : 'Cache-Control: private, max-age=300');
    readfile($file);
    exit;
}

/* Without GD we cannot resize; serve the original rather than a broken image. */
if (!function_exists('imagecreatefromstring')) {
    send_photo($src, $mime, false);
}

$outExt  = (function_exists('imagewebp') && in_array($ext, ['jpg','jpeg','png','webp'], true)) ? 'webp' : 'jpg';
$outMime = $outExt === 'webp' ? 'image/webp' : 'image/jpeg';
$thumbDir = __DIR__ . '/uploads/thumbs';
if (!is_dir($thumbDir) && !@mkdir($thumbDir, 0775, true) && !is_dir($thumbDir)) {
    send_photo($src, $mime, false);
}
$base    = pathinfo($f, PATHINFO_FILENAME);
$cache   = $thumbDir . '/' . $base . '_w' . $width . '.' . $outExt;

if (is_file($cache) && filemtime($cache) >= filemtime($src)) {
    send_photo($cache, $outMime, true);
}

$data = @file_get_contents($src);
$img  = $data !== false ? @imagecreatefromstring($data) : false;
if (!$img) {
    send_photo($src, $mime, false);
}

$sw = imagesx($img);
$sh = imagesy($img);
$scale = min(1.0, $width / max(1, $sw));
$dw = max(1, (int)round($sw * $scale));
$dh = max(1, (int)round($sh * $scale));

$dst = imagecreatetruecolor($dw, $dh);
if ($outExt === 'webp' && in_array($ext, ['png','webp'], true)) {
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
} else {
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
}
imagecopyresampled($dst, $img, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
imagedestroy($img);

$ok = $outExt === 'webp'
    ? @imagewebp($dst, $cache, 82)
    : @imagejpeg($dst, $cache, 82);
imagedestroy($dst);

if (!$ok) {
    send_photo($src, $mime, false);
}
send_photo($cache, $outMime, true);
