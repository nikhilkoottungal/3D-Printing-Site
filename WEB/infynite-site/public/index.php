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

// Rewrite root-relative app links/assets/forms when the app is mounted in a subfolder.
$mountPath = rtrim((string) parse_url((string) cfg('base_url'), PHP_URL_PATH), '/');
if ($mountPath !== '') {
    ob_start(static function (string $html) use ($mountPath): string {
        return preg_replace_callback(
            '/\b(href|src|action)=(\"|\')\/(?!\/)([^\"\']*)\2/i',
            static function (array $m) use ($mountPath): string {
                $path = '/' . ltrim($m[3], '/');
                if ($path === $mountPath || str_starts_with($path, $mountPath . '/')) {
                    return $m[0];
                }
                return $m[1] . '=' . $m[2] . $mountPath . $path . $m[2];
            },
            $html
        );
    });
}

try {
    dispatch();
} catch (Throwable $ex) {
    error_log($ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    http_response_code(500);
    echo cfg('debug') ? '<pre>' . htmlspecialchars((string) $ex) . '</pre>' : 'Something went wrong. Please try again later.';
}
