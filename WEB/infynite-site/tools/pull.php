<?php
/**
 * Runs on the OWNER'S PC (cron / Task Scheduler): copies new backup runs from SOURCE to a local folder, verifies
 * every file by sha256, and raises an alert when no fresh backup arrived (dead-man switch).
 *   php tools/pull.php --from=DIR --to=DIR [--max-age-hours=26] [--prune] [--config=FILE]
 * SOURCE is a folder (mounted SFTP/Drive folder, or the host's backup destination). No private key is needed here.
 * --prune applies the retention policy (14 daily / 8 weekly / 12 monthly) to the PC copy; the host can never do this.
 * Exit: 0 ok, 1 error, 2 stale (no fresh backup).
 */
require __DIR__ . '/lib/isbk.php';
$b = [];
try {
    $a = isbk_args($argv);
    $cfgFile = is_string($a['config'] ?? null) ? $a['config'] : null;
    try { $b = isbk_config($cfgFile); } catch (Throwable $e) { $b = ['retention' => ['daily' => 14, 'weekly' => 8, 'monthly' => 12], 'notify_cmd' => null]; }
    $from = $a['from'] ?? null; $to = $a['to'] ?? null;
    if (!is_string($from) || !is_string($to)) { isbk_fail('usage: pull.php --from=DIR --to=DIR [--max-age-hours=26] [--prune]'); }
    $maxAge = (float) ($a['max-age-hours'] ?? 26);
    isbk_mkdir($to);
    $src = isbk_runs($from); $have = isbk_runs($to);
    $copied = 0; $problems = [];
    // part checksum index across all source runs
    $partSha = [];
    foreach ($src as $m) { foreach ($m['parts'] ?? [] as $n => $p) { $partSha[$n] = $p['sha256']; } }
    foreach ($src as $id => $m) {
        if (isset($have[$id])) { continue; }
        try {
            foreach ($m['parts_used'] ?? [] as $part) {
                $sha = $partSha[$part] ?? null;
                if (!$sha) { foreach ($have as $hm) { if (isset($hm['parts'][$part])) { $sha = $hm['parts'][$part]['sha256']; } } }
                if (!$sha) { throw new RuntimeException("no checksum known for $part"); }
                if (!is_file("$to/$part") || isbk_sha_file("$to/$part") !== $sha) { isbk_dest_put(['type' => 'folder', 'path' => $to], "$from/$part", $part, $sha); }
            }
            isbk_dest_put(['type' => 'folder', 'path' => $to], "$from/runs/$id/core.bak", "runs/$id/core.bak", $m['core']['sha256']);
            isbk_dest_put(['type' => 'folder', 'path' => $to], "$from/runs/$id/meta.json", "runs/$id/meta.json", isbk_sha_file("$from/runs/$id/meta.json"));
            $copied++; isbk_log("pulled run $id");
        } catch (Throwable $e) { $problems[] = "run $id: " . $e->getMessage(); }
    }
    // re-verify everything the newest run needs
    $mine = isbk_runs($to);
    if ($mine) {
        $lastId = array_key_last($mine); $lm = $mine[$lastId];
        if (isbk_sha_file("$to/runs/$lastId/core.bak") !== $lm['core']['sha256']) { $problems[] = "run $lastId core checksum mismatch"; }
        foreach ($lm['parts_used'] ?? [] as $part) {
            $sha = null; foreach ($mine as $mm) { if (isset($mm['parts'][$part])) { $sha = $mm['parts'][$part]['sha256']; } }
            if (!is_file("$to/$part") || ($sha && isbk_sha_file("$to/$part") !== $sha)) { $problems[] = "missing/corrupt $part"; }
        }
    }
    if (isset($a['prune']) && !$problems) { $rm = isbk_prune($to, $b['retention']); if ($rm) { isbk_log('pruned: ' . implode(', ', $rm)); } }
    $stale = false;
    if ($mine) { $age = (time() - strtotime($mine[array_key_last($mine)]['created'])) / 3600; $stale = $age > $maxAge; }
    else { $stale = true; $age = INF; }
    if ($problems) { isbk_notify($b, 'Backup PULL PROBLEMS: ' . implode(' | ', $problems)); exit(1); }
    if ($stale) { isbk_notify($b, sprintf('Backup STALE: newest backup is %s hours old (limit %s)', is_finite($age) ? round($age, 1) : 'n/a', $maxAge)); exit(2); }
    isbk_log("pull ok: $copied new run(s), " . count($mine) . ' total, newest ' . round($age, 1) . ' h old');
} catch (Throwable $e) { isbk_notify($b, 'Backup PULL FAILED: ' . $e->getMessage()); exit(1); }
