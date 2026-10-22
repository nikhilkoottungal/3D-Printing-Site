<?php
// Front controller. Preferred layout: public/ is the document root; app/ and storage/ are above it.
// Restricted shared-hosting fallback: app/ and storage/ may be beside this file only when .htaccess
// access denial for both directories has been verified. Keep customer uploads disabled in that layout.
$appRoot = is_file(__DIR__ . '/app/bootstrap.php') ? __DIR__ : dirname(__DIR__);
$storageRoot = $appRoot . '/storage';
// PHP built-in dev server only: let it serve real files (CSS/JS) directly.
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($f) && substr($f, -4) !== '.php') { return false; }
}
// Maintenance mode (set by tools/restore.php --live, or by touching storage/maintenance.flag).
if (PHP_SAPI !== 'cli' && is_file($storageRoot . '/maintenance.flag')) {
    http_response_code(503); header('Retry-After: 1800'); header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>Maintenance</title><body style="font:16px system-ui;margin:15vh auto;max-width:30em;padding:0 16px"><h1>Back soon</h1><p>We are doing maintenance and will be back shortly.</p></body>');
}
require $appRoot . '/app/bootstrap.php';

// Subfolder mount: prefix root-relative links/forms/assets with the base path.
if (base_path() !== '' && PHP_SAPI !== 'cli') {
    $bp = base_path();
    ob_start(function (string $html) use ($bp): string {
        return preg_replace_callback('~\b(href|src|action)="/(?!/)([^"]*)"~', function ($m) use ($bp) {
            $p = '/' . $m[2];
            return ($p === $bp || str_starts_with($p, $bp . '/')) ? $m[0] : $m[1] . '="' . $bp . $p . '"';
        }, $html);
    });
}

try {
    dispatch();
} catch (Throwable $ex) {
    error_log($ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    http_response_code(500);
    echo cfg('debug') ? '<pre>' . htmlspecialchars((string) $ex) . '</pre>' : 'Something went wrong. Please try again later.';
}
