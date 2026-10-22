<?php
define('APP_ROOT', dirname(__DIR__));
// INFYNITE_CONFIG lets tests/CI point at another config file; normal installs use app/config.php.
$__cfgFile = getenv('INFYNITE_CONFIG') ?: APP_ROOT . '/app/config.php';
if (!is_file($__cfgFile)) {
    http_response_code(500);
    exit('Missing app/config.php - copy app/config.example.php and fill it in.');
}
$GLOBALS['CFG'] = require $__cfgFile;

foreach (['core', 'auth', 'markdown', 'mail', 'upload', 'seed', 'settings', 'secret', 'pdf', 'offsite', 'smtp', 'notify', 'totp', 'qr', 'session_rules', 'validate', 'migrate'] as $f) {
    require APP_ROOT . '/app/lib/' . $f . '.php';
}
foreach (['site', 'auth', 'orders_common', 'staff', 'requests', 'tasks', 'kb', 'team', 'inventory', 'backups', 'admin', 'integrations', 'invoices', 'account', 'profile', 'production', 'search', 'workspace', 'customers', 'purchasing', 'billing', 'reports'] as $f) {
    require APP_ROOT . '/app/controllers/' . $f . '.php';
}

ini_set('display_errors', cfg('debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', cfg('storage') . '/logs/php-error.log');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if (cfg('force_https') && !$https) {
    // base_url may already include the subfolder; avoid doubling it.
    $origin = rtrim(cfg('base_url'), '/');
    $bp = base_path();
    if ($bp !== '' && str_ends_with($origin, $bp)) { $origin = substr($origin, 0, -strlen($bp)); }
    header('Location: ' . $origin . ($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'");
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}

session_set_cookie_params(['lifetime' => 0, 'path' => base_path() ?: '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_name('isid');
migrate();
session_start();
