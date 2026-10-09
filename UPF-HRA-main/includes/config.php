<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Kampala');

define('APP_NAME', 'HUMAN RESOURCE ADMINISTRATION SYSTEM');
define('APP_ORG', 'UGANDA POLICE FORCE');
define('APP_MOTTO', 'PROTECT & SERVE');
define('BASE_PATH', dirname(__DIR__));
define('DB_PATH', BASE_PATH . '/data/mdd.sqlite');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
