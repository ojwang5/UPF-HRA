<?php
// Built-in PHP server router.
// Serves static (non-PHP) files directly; routes PHP files; falls back to index.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

// Serve existing non-PHP static files as-is
if ($path !== '/' && file_exists($file) && !is_dir($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false;
}

// Route to a specific .php file if it exists in public/
$phpFile = __DIR__ . rtrim($path, '/') . (str_ends_with($path, '.php') ? '' : '.php');
if ($path !== '/' && file_exists($phpFile) && is_file($phpFile)) {
    require $phpFile;
    exit;
}

// Default to index.php
require __DIR__ . '/index.php';
