<?php
/** Copies the selected data to the enabled destinations (Google Drive / FTP). Run nightly by deploy/infynite-offsite.timer, or press "Run now" in Admin settings > Integrations. */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
$_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/app/bootstrap.php';
$res = offsite_run($argv[1] ?? null);
foreach ($res as $r) { echo ($r['ok'] ? 'OK    ' : 'FAIL  ') . $r['dest'] . ' / ' . $r['set'] . ': ' . $r['msg'] . "\n"; }
if (!$res) { echo "Nothing to do (no destination enabled with data selected).\n"; }
exit(array_filter($res, fn($r) => !$r['ok']) ? 1 : 0);
