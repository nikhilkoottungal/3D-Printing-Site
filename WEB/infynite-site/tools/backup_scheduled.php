<?php
/**
 * Run every 15 minutes by deploy/infynite-backup.timer, and by the "Backup now" button (--force).
 * Reads the schedule and the locations (Local / FTP / both) set on the Backups page (Admin); makes a backup only when
 * one is due (or --force), keeps the local copy and/or uploads the encrypted backup to the FTP server.
 *   php tools/backup_scheduled.php [--force]
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
$_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/lib/backup_run.php';
$force = in_array('--force', $argv, true);
$sched = backup_schedule_get(); $targets = backup_targets();
$dir = cfg('backup.dir', cfg('storage') . '/backup');
$status = json_decode((string) @file_get_contents($dir . '/status.json'), true) ?: null;
if (!$force && !backup_due($sched, backup_last_finished($status), time())) { echo 'Not due (' . backup_schedule_text($sched) . ").\n"; exit(0); }
@file_put_contents($dir . '/run-now.flag', (string) getmypid());   // the Backups page shows its progress loader while this exists
register_shutdown_function(fn() => @unlink($dir . '/run-now.flag'));
echo ($force ? 'Backup requested' : 'Backup due (' . backup_schedule_text($sched) . ')') . ' - ' . backup_targets_text($targets) . ". Running...\n";
$rc = 0;
try {
    $b = isbk_config();
    if (!$targets['local']) { $b['destinations'] = [['type' => 'folder', 'path' => backup_staging_dir()]]; }
    $s = isbk_backup_run($b);
    $msg = sprintf('Backup %s: run %s, %d files (%d new), core %s MB, parts %s MB%s', $s['ok'] ? 'OK' : 'INCOMPLETE', $s['run'], $s['files_total'], $s['files_new'],
        round($s['core_bytes'] / 1048576, 1), round($s['parts_bytes'] / 1048576, 1), $s['errors'] ? '; ' . implode(' | ', array_slice($s['errors'], 0, 2)) : '');
    isbk_notify($b, $msg);
    if (!$s['ok']) { $rc = 1; }
} catch (Throwable $e) {
    isbk_notify($b ?? [], 'Backup FAILED: ' . $e->getMessage());
    exit(1);
}
if ($targets['ftp']) {
    echo "Uploading to the FTP server...\n";
    $res = offsite_run('ftp', ['backup'], true);
    foreach ($res as $r) { echo ($r['ok'] ? 'OK    ' : 'FAIL  ') . $r['msg'] . "\n"; if (!$r['ok']) { $rc = 1; } }
    if (!$res) { echo "FAIL  nothing uploaded (FTP not configured)\n"; $rc = 1; }
    elseif (!$targets['local'] && $rc === 0) {   // FTP only: the staged copy is no longer needed once it is safely on the server
        foreach (['runs', 'parts'] as $sub) { isbk_rrmdir(backup_staging_dir() . '/' . $sub); }
        echo "FTP only: staging folder cleared.\n";
    }
}
exit($rc === 0 ? 0 : 1);
