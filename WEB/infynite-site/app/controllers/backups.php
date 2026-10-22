<?php
/** Backups page (Owner login; no extra admin-key prompt): status, where backups go (Local / FTP), the schedule, and which backups exist to restore from. tools/backup.php + tools/backup_scheduled.php make them, tools/restore.php restores. */

/** Backups are for the Owner role only. The admin key is NOT asked here (it protects Admin settings). */
function backups_guard(): array { return require_staff('team.manage'); }

function backup_last_finished(?array $status): ?int
{
    $t = $status['finished'] ?? null;
    return $t ? (strtotime($t) ?: null) : null;
}
function backups_page(): void
{
    backups_guard();
    $dir = cfg('backup.dir', cfg('storage') . '/backup');
    $status = json_decode((string) @file_get_contents($dir . '/status.json'), true) ?: null;
    $state = json_decode((string) @file_get_contents($dir . '/state.json'), true) ?: [];
    // The list shows the backups that really exist in the LOCAL backup folder (the configured destination), not the
    // server's small working folder (storage/backup keeps the last few runs while shipping). FTP only: none are kept locally.
    $targets = backup_targets();
    $listDir = $targets['local'] ? offsite_backup_dir() : null;
    $runs = [];
    foreach ($listDir ? (glob($listDir . '/runs/*/meta.json') ?: []) : [] as $m) {
        $j = json_decode((string) file_get_contents($m), true);
        if (is_array($j)) { $runs[] = $j; }
    }
    usort($runs, fn($a, $b) => strcmp($b['run'], $a['run']));
    $last = $status['finished'] ?? null;
    $ageH = $last ? (time() - strtotime($last)) / 3600 : null;
    $quota = (int) cfg('backup.disk_quota_bytes', 10 * 1024 ** 3);
    $used = (int) ($status['disk_usage_bytes'] ?? 0);
    $sched = backup_schedule_get();
    // backups on the FTP server (only asked for when FTP is a location and the user pressed "Show", i.e. ?ftp=1)
    $ftpRuns = null;
    if ($targets['ftp'] && !empty($_GET['ftp'])) { $ftpRuns = offsite_ftp_runs(); }
    $lastSecs = backup_last_finished($status);
    render('staff/backups', ['title' => 'Backups', 'nav' => 'admin', 'atab' => 'backups', 'status' => $status, 'state' => $state, 'runs' => array_slice($runs, 0, 15), 'ageH' => $ageH,
        'quota' => $quota, 'used' => $used, 'pct' => $quota ? (int) round($used / $quota * 100) : 0, 'configured' => !empty(cfg('backup.recipients')) && !empty(cfg('backup.destinations')),
        'sched' => $sched, 'targets' => $targets, 'listDir' => $listDir, 'running' => backup_running($dir), 'done' => !empty($_GET['done']), 'next' => backup_next_run($sched, $lastSecs, time()), 'ftpRuns' => $ftpRuns, 'ftpLast' => json_decode((string) setting('backup_ftp_last', ''), true) ?: null,
        'rclone' => rclone_bin()], 'staff');
}
function backups_save(): void
{
    backups_guard();
    $errors = [];
    $local = !empty($_POST['backup_local']); $ftp = !empty($_POST['backup_ftp']);
    if (!$local && !$ftp) { $errors[] = 'Choose at least one location: Local, FTP or both.'; }
    $freq = (string) ($_POST['backup_freq'] ?? 'daily');
    if (!isset(BACKUP_FREQS[$freq])) { $errors[] = 'Choose how often to back up.'; }
    $every = (int) ($_POST['backup_every_h'] ?? 6);
    if ($freq === 'hours' && !in_array($every, BACKUP_EVERY_HOURS, true)) { $errors[] = 'Choose a number of hours from the list.'; }
    $time = trim((string) ($_POST['backup_time'] ?? '22:30'));
    if (in_array($freq, ['daily', 'weekly'], true) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) { $errors[] = 'Time must look like 22:30.'; }
    $wd = (int) ($_POST['backup_weekday'] ?? 1);
    if ($freq === 'weekly' && !isset(BACKUP_WEEKDAYS[$wd])) { $errors[] = 'Choose a weekday.'; }
    if (!$errors && $ftp) { $errors = backups_ftp_fields(); }
    if ($errors) { flash(implode(' ', $errors), 'bad'); redirect('/staff/backups'); }
    // fields that do not apply to the chosen frequency keep their previous value
    $new = ['backup_local' => $local ? '1' : '0', 'backup_ftp' => $ftp ? '1' : '0', 'backup_freq' => $freq];
    if ($freq === 'hours') { $new['backup_every_h'] = (string) $every; }
    if (in_array($freq, ['daily', 'weekly'], true)) { $new['backup_time'] = $time; }
    if ($freq === 'weekly') { $new['backup_weekday'] = (string) $wd; }
    foreach ($new as $k => $v) { setting_set($k, $v); }
    audit('admin.backup_settings', backup_targets_text(backup_targets()) . ', ' . backup_schedule_text(backup_schedule_get()));
    flash('Backup settings saved.');
    redirect('/staff/backups');
}
/** Is a backup running right now? A run-now.flag is kept from the start of the run (backup + FTP upload) to its end; the backup lock covers runs started elsewhere. */
function backup_running(string $dir): bool
{
    $flag = $dir . '/run-now.flag';
    if (is_file($flag)) {   // "starting" (written by the button, valid for a minute) or the PID of the running script (a crashed run leaves a dead PID)
        $c = trim((string) @file_get_contents($flag)); $age = time() - (int) @filemtime($flag);
        if ($c === 'starting' ? $age < 90 : (ctype_digit($c) && (is_dir('/proc/' . $c) || (function_exists('posix_kill') && @posix_kill((int) $c, 0))))) { return true; }
    }
    $f = @fopen($dir . '/backup.lock', 'c'); if (!$f) { return false; }
    $free = flock($f, LOCK_EX | LOCK_NB); if ($free) { flock($f, LOCK_UN); } fclose($f);
    return !$free;
}
/** Small JSON for the page's progress loader: is it still running, and the last lines of what it is doing. */
function backups_status_json(): void
{
    $u = user();
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    if (!$u || !is_staff($u) || !can('team.manage', $u)) { http_response_code(403); echo '{"error":"forbidden"}'; return; }
    $dir = cfg('backup.dir', cfg('storage') . '/backup');
    $log = trim((string) @file_get_contents(cfg('storage') . '/logs/backup-now.log'));
    $lines = array_slice(array_values(array_filter(array_map('trim', explode("\n", $log)))), -3);
    echo json_encode(['running' => backup_running($dir), 'log' => implode(' | ', $lines)]);
}
/** "Backup now": starts tools/backup_scheduled.php --force in the background (a backup can take minutes) and returns at once. */
function backups_run_now(): void
{
    backups_guard();
    $dir = cfg('backup.dir', cfg('storage') . '/backup');
    if (backup_running($dir)) { flash('A backup is already running. Refresh this page in a minute.', 'bad'); redirect('/staff/backups'); }
    $log = cfg('storage') . '/logs/backup-now.log';
    @file_put_contents($dir . '/run-now.flag', 'starting');   // the page shows the loader from the first moment; the script removes it at the end
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = '"' . PHP_BINARY . '" "' . APP_ROOT . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'backup_scheduled.php" --force > "' . $log . '" 2>&1';
        $p = @proc_open($cmd, [], $pipes, APP_ROOT, getenv());
    } else {
        $cmd = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tools/backup_scheduled.php') . ' --force > ' . escapeshellarg($log) . ' 2>&1 &';
        $p = @proc_open(['/bin/sh', '-c', $cmd], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, APP_ROOT, array_merge(getenv() ?: [], ['HOME' => getenv('HOME') ?: '/root']));
    }
    if (!is_resource($p)) { @unlink($dir . '/run-now.flag'); flash('Could not start the backup.', 'bad'); redirect('/staff/backups'); }
    proc_close($p);
    audit('admin.backup_now', backup_targets_text(backup_targets()));
    flash('Backup started (' . backup_targets_text(backup_targets()) . ').');
    redirect('/staff/backups');
}
/** Saves just the FTP connection fields from the Backups form. Returns the errors (empty = saved). */
function backups_ftp_fields(): array
{
    $e = [];
    if (trim((string) ($_POST['ftp_host'] ?? '')) === '') { $e[] = 'Enter the FTP server address.'; }
    if (trim((string) ($_POST['ftp_user'] ?? '')) === '') { $e[] = 'Enter the FTP username.'; }
    if (!setting('ftp_pass') && (string) ($_POST['ftp_pass'] ?? '') === '') { $e[] = 'Enter the FTP password.'; }
    return $e ?: ftp_settings_save(null);
}
function backups_ftp_configure(): void
{
    backups_guard();
    $errors = backups_ftp_fields();
    if ($errors) { flash(implode(' ', $errors), 'bad'); } else { audit('admin.integrations', 'ftp (backups)'); flash('FTP details saved. Press Test to check the connection.'); }
    redirect('/staff/backups');
}
function backups_test_ftp(): void
{
    backups_guard();
    if ($errors = backups_ftp_fields()) { flash(implode(' ', $errors), 'bad'); redirect('/staff/backups'); }
    [$ok, $msg] = offsite_test('ftp');
    audit('admin.integration_test', 'ftp (backups) ' . ($ok ? 'ok' : 'failed'));
    flash(($ok ? '' : 'Test failed. ') . $msg, $ok ? 'ok' : 'bad');
    redirect('/staff/backups');
}
