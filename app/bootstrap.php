<?php
declare(strict_types=1);

define('SG_ROOT', dirname(__DIR__));
define('SG_APP',  __DIR__);
define('SG_PUBLIC', SG_ROOT . '/public');

if (!file_exists(SG_APP . '/config.php')) {
    http_response_code(500);
    exit('ShiftGuard: missing app/config.php. Copy .env.example and fill it in.');
}

/** @var array $CONFIG */
$CONFIG = require SG_APP . '/config.php';

date_default_timezone_set('UTC');
ini_set('display_errors', ($CONFIG['env'] ?? 'production') === 'development' ? '1' : '0');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => str_starts_with($CONFIG['url'] ?? '', 'https://'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('sg_session');
    session_start();
}

// Composer autoload — provides PHPMailer, web-push, ramsey/uuid, etc.
if (is_file(SG_ROOT . '/vendor/autoload.php')) {
    require SG_ROOT . '/vendor/autoload.php';
}

// App code is procedural — load explicitly in dependency order.
require SG_APP . '/db.php';
require SG_APP . '/helpers.php';
require SG_APP . '/mailer.php';
require SG_APP . '/push.php';
require SG_APP . '/auth.php';
require SG_APP . '/shifts.php';
require SG_APP . '/reminders.php';