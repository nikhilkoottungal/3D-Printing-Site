<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(($title ?? '') . ' · ' . setting('app_name', cfg('app_name'))) ?></title>
<link rel="stylesheet" href="/assets/style.css?v=<?= (int) @filemtime(APP_ROOT . '/public/assets/style.css') ?>">
<script src="/assets/app.js?v=<?= (int) @filemtime(APP_ROOT . '/public/assets/app.js') ?>" defer></script>
</head>
<body>
