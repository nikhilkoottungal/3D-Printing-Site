<?php
/** Owner-only read-only status page for the backup tool (tools/backup.php writes the status files). */
function backups_page(): void
{
    $u = require_staff('team.manage');
    $dir = cfg('backup.dir', cfg('storage') . '/backup');
    $status = json_decode((string) @file_get_contents($dir . '/status.json'), true) ?: null;
    $state = json_decode((string) @file_get_contents($dir . '/state.json'), true) ?: [];
    $runs = [];
    foreach (glob($dir . '/runs/*/meta.json') ?: [] as $m) {
        $j = json_decode((string) file_get_contents($m), true);
        if (is_array($j)) { $runs[] = $j; }
    }
    usort($runs, fn($a, $b) => strcmp($b['run'], $a['run']));
    $last = $status['finished'] ?? null;
    $ageH = $last ? (time() - strtotime($last)) / 3600 : null;
    $quota = (int) cfg('backup.disk_quota_bytes', 10 * 1024 ** 3);
    $used = (int) ($status['disk_usage_bytes'] ?? 0);
    render('staff/backups', ['title' => 'Backups', 'nav' => 'backups', 'status' => $status, 'state' => $state, 'runs' => array_slice($runs, 0, 15), 'ageH' => $ageH,
        'quota' => $quota, 'used' => $used, 'pct' => $quota ? (int) round($used / $quota * 100) : 0, 'configured' => !empty(cfg('backup.recipients')) && !empty(cfg('backup.destinations'))], 'staff');
}
