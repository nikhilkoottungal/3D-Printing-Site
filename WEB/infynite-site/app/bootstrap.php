<?php
define('APP_ROOT', dirname(__DIR__));
// INFYNITE_CONFIG lets tests/CI point at another config file; normal installs use app/config.php.
$__cfgFile = getenv('INFYNITE_CONFIG') ?: APP_ROOT . '/app/config.php';
if (!is_file($__cfgFile)) {
    http_response_code(500);
    exit('Missing app/config.php - copy app/config.example.php and fill it in.');
}
$GLOBALS['CFG'] = require $__cfgFile;

foreach (['core', 'auth', 'markdown', 'mail', 'upload', 'dolibarr', 'seed'] as $f) {
    require APP_ROOT . '/app/lib/' . $f . '.php';
}
foreach (['site', 'auth', 'customer', 'staff', 'requests', 'tasks', 'kb', 'team', 'inventory', 'backups'] as $f) {
    require APP_ROOT . '/app/controllers/' . $f . '.php';
}

ini_set('display_errors', cfg('debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', cfg('storage') . '/logs/php-error.log');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if (cfg('force_https') && !$https) {
    redirect(rtrim(cfg('base_url'), '/') . ($_SERVER['REQUEST_URI'] ?? '/'));
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'");
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_name('isid');
session_start();
