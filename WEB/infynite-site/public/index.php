<?php
// Front controller. Only this folder (public/) is web-accessible; app/, sql/ and storage/ sit above it.
// If your host forces everything into public_html, point this path at the folder that holds app/.
// PHP built-in dev server only: let it serve real files (CSS/JS) directly.
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($f) && substr($f, -4) !== '.php') { return false; }
}
// Maintenance mode (set by tools/restore.php --live, or by touching storage/maintenance.flag).
if (PHP_SAPI !== 'cli' && is_file(__DIR__ . '/../storage/maintenance.flag')) {
    http_response_code(503); header('Retry-After: 1800'); header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>Maintenance</title><body style="font:16px system-ui;margin:15vh auto;max-width:30em;padding:0 16px"><h1>Back soon</h1><p>We are doing maintenance and will be back shortly.</p></body>');
}
require __DIR__ . '/../app/bootstrap.php';

try {
    dispatch();
} catch (Throwable $ex) {
    error_log($ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    http_response_code(500);
    echo cfg('debug') ? '<pre>' . htmlspecialchars((string) $ex) . '</pre>' : 'Something went wrong. Please try again later.';
}
