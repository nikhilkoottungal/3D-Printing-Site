<?php
/**
 * Nightly backup (run by cron / Task Scheduler on the HOST):  php tools/backup.php [--config=FILE] [--no-files]
 * Dumps all configured databases, archives new files, encrypts to the configured public keys, ships to destinations.
 * Exit codes: 0 ok, 1 failed or shipping incomplete.
 */
require __DIR__ . '/lib/backup_run.php';
$b = null;
try {
    $a = isbk_args($argv);
    $b = isbk_config(is_string($a['config'] ?? null) ? $a['config'] : null);
    $s = isbk_backup_run($b, ['files' => !isset($a['no-files'])]);
    $msg = sprintf('Backup %s: run %s, %d files (%d new), core %s, parts %s, disk %d%% of quota%s',
        $s['ok'] ? 'OK' : 'INCOMPLETE', $s['run'], $s['files_total'], $s['files_new'], round($s['core_bytes'] / 1048576, 1) . ' MB',
        round($s['parts_bytes'] / 1048576, 1) . ' MB', $s['disk_quota_bytes'] ? round($s['disk_usage_bytes'] / $s['disk_quota_bytes'] * 100) : 0,
        $s['errors'] ? '; ' . implode(' | ', array_slice($s['errors'], 0, 2)) : '');
    isbk_notify($b, $msg);
    exit($s['ok'] ? 0 : 1);
} catch (Throwable $e) {
    isbk_notify($b ?? [], 'Backup FAILED: ' . $e->getMessage());
    exit(1);
}
