<?php
require_once __DIR__ . '/../includes/config.php';
$_SESSION['user_id'] = 1;
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
header('Location: /');
exit;
