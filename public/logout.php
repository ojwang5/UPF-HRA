<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
log_activity('Logout', 'user', current_user()['full_name'] ?? '', 0, 'Session ended');
logout();
header('Location: /login.php');
