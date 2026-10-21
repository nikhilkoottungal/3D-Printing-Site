<?php
require_once __DIR__ . '/isbk.php';

function isbk_state_load(string $dir): array
{
    $j = json_decode((string) @file_get_contents($dir . '/state.json'), true);
    return is_array($j) ? $j + ['files' => [], 'pending' => [], 'unshipped' => []] : ['files' => [], 'pending' => [], 'unshipped' => []];
}
function isbk_state_save(string $dir, array $s): void
{
    $tmp = $dir . '/state.json.tmp';
    file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_SLASHES));
    rename($tmp, $dir . '/state.json');
}

/**
 * One backup run. $opts: files(bool, default true), label(string), destinations(array override), recipients(array override).
 * Order matters: databases are dumped FIRST, files are copied afterwards, so a file may exist without its DB row
 * (harmless) but never the other way round.
 */
function isbk_backup_run(array $b, array $opts = []): array
{
    $dir = $b['dir'];
    foreach (['', '/runs', '/parts', '/work'] as $s) { isbk_mkdir($dir . $s); }
    $lock = fopen($dir . '/backup.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { isbk_fail('another backup is already running'); }

    $withFiles = $opts['files'] ?? true;
    $dests = $opts['destinations'] ?? $b['destinations'];
    $recips = $opts['recipients'] ?? $b['recipients'];
    $runId = gmdate('Ymd-His');
    $t0 = microtime(true);
    $work = $dir . '/work/' . $runId;
    isbk_mkdir($work);
    $state = isbk_state_load($dir);
    $warnings = [];

    try {
        /* 1. databases */
        $dbMeta = []; $seen = [];
        foreach ($b['databases'] as $d) {
            $k = $d['host'] . '|' . ($d['port'] ?? 3306) . '|' . $d['db'];
            if (isset($seen[$k])) { $dbMeta[$d['name']] = ['same_as' => $seen[$k]]; continue; }
            $seen[$k] = $d['name'];
            isbk_log("dumping database {$d['name']} ({$d['db']})");
            $r = isbk_dump_db($d, "$work/db-{$d['name']}.sql.gz");
            if (!isbk_dump_complete("$work/db-{$d['name']}.sql.gz")) { isbk_fail("dump of {$d['name']} did not complete"); }
            $dbMeta[$d['name']] = ['db' => $d['db'], 'tables' => count($r['tables']), 'rows' => $r['rows'], 'row_counts' => $r['tables']];
        }

        /* 2. file manifest (reuse known hashes when size+mtime unchanged) */
        $manifest = []; $queue = []; $filesTotal = 0; $bytesTotal = 0;
        if ($withFiles) {
            foreach ($b['paths'] as $p) {
                if (empty($p['path']) || !is_dir($p['path'])) { $warnings[] = "path '{$p['name']}' missing, skipped"; continue; }
                foreach (isbk_scan($p['path']) as $rel => $f) {
                    $key = $p['name'] . '/' . $rel;
                    $prev = $state['files'][$key] ?? null;
                    if ($prev && $prev['size'] === $f['size'] && $prev['mtime'] === $f['mtime'] && !empty($prev['part'])) { $e = $prev; }
                    else {
                        $e = ['size' => $f['size'], 'mtime' => $f['mtime'], 'sha' => isbk_sha_file($p['path'] . '/' . $rel), 'abs' => $p['path'] . '/' . $rel];
                        if ($prev && $prev['sha'] === $e['sha'] && !empty($prev['part'])) { $e['part'] = $prev['part']; } else { $queue[$key] = $e; }
                    }
                    $manifest[$key] = $e; $filesTotal++; $bytesTotal += $f['size'];
                }
            }
        }

        /* 3. new/changed files -> encrypted parts */
        $partsMeta = []; $n = 0; $limit = (int) $b['chunk_mb'] * 1048576;
        $keys = array_keys($queue);
        for ($i = 0; $i < count($keys);) {
            $n++; $zipPath = "$work/files-" . sprintf('%03d', $n) . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { isbk_fail('cannot create zip'); }
            $size = 0; $in = [];
            while ($i < count($keys) && ($size === 0 || $size + $queue[$keys[$i]]['size'] <= $limit)) {
                $k = $keys[$i++]; $zip->addFile($queue[$k]['abs'], $k); $size += $queue[$k]['size']; $in[] = $k;
            }
            if (!$zip->close()) { isbk_fail('zip close failed (disk full?)'); }
            $name = 'parts/' . $runId . '-files-' . sprintf('%03d', $n) . '.bak';
            $enc = isbk_encrypt_file($zipPath, $dir . '/' . $name, $recips);
            unlink($zipPath);
            $partsMeta[$name] = ['size' => $enc['size'], 'sha256' => $enc['sha256'], 'files' => count($in)];
            foreach ($in as $k) { $manifest[$k]['part'] = $name; }
            isbk_log("part $name: " . count($in) . ' files');
        }
        foreach ($manifest as &$m) { unset($m['abs']); } unset($m);
        $partsUsed = array_values(array_unique(array_filter(array_column($manifest, 'part'))));
        sort($partsUsed);

        /* 4. core archive: DB dumps + manifest + config files */
        $core = new ZipArchive();
        if ($core->open("$work/core.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { isbk_fail('cannot create core zip'); }
        foreach (glob("$work/db-*.sql.gz") as $f) { $core->addFile($f, 'db/' . basename($f)); }
        $core->addFromString('manifest.json', json_encode(['run' => $runId, 'files' => $manifest, 'databases' => $dbMeta], JSON_UNESCAPED_SLASHES));
        foreach ($b['config_files'] as $i => $cf) { if (is_file($cf)) { $core->addFile($cf, 'config/' . ($i + 1) . '-' . basename($cf)); } else { $warnings[] = "config file missing: $cf"; } }
        if (!$core->close()) { isbk_fail('core zip close failed'); }
        $runDir = $dir . '/runs/' . $runId; isbk_mkdir($runDir);
        $encCore = isbk_encrypt_file("$work/core.zip", "$runDir/core.bak", $recips);

        /* 5. meta (plain, no secrets: counts and hashes only) */
        $meta = [
            'run' => $runId, 'label' => $opts['label'] ?? null, 'created' => gmdate('c'), 'tool' => ISBK_VERSION, 'php' => PHP_VERSION,
            'recipients' => array_map(fn($r) => isbk_kid((string) base64_decode($r)), $recips),
            'core' => ['file' => 'core.bak', 'size' => $encCore['size'], 'sha256' => $encCore['sha256']],
            'parts' => $partsMeta, 'parts_used' => $partsUsed,
            'databases' => array_map(fn($d) => isset($d['same_as']) ? $d : ['db' => $d['db'], 'tables' => $d['tables'], 'rows' => $d['rows'], 'row_counts' => $d['row_counts']], $dbMeta),
            'files_total' => $filesTotal, 'bytes_total' => $bytesTotal, 'files_new' => count($queue),
            'includes_files' => $withFiles, 'warnings' => $warnings,
        ];
        file_put_contents("$runDir/meta.json.tmp", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename("$runDir/meta.json.tmp", "$runDir/meta.json");

        /* 6. commit state (the run exists locally; shipping can be retried) */
        foreach ($manifest as $k => $e) { $state['files'][$k] = $e; }
        foreach (array_keys($state['files']) as $k) { if (!isset($manifest[$k]) && $withFiles) { unset($state['files'][$k]); } }
        $state['pending'] = array_values(array_unique(array_merge($state['pending'], array_keys($partsMeta))));
        $state['unshipped'] = array_values(array_unique(array_merge($state['unshipped'], [$runId])));
        isbk_state_save($dir, $state);
        isbk_rrmdir($work);

        /* 7. ship to destinations */
        $shipErr = isbk_ship($b, $dests, $state);
        isbk_state_save($dir, $state);

        /* 8. local retention (host keeps only a few small runs; parts leave once shipped) */
        $local = array_keys(isbk_runs($dir));
        rsort($local);
        foreach (array_slice($local, max(1, (int) $b['keep_local'])) as $old) {
            if (!in_array($old, $state['unshipped'], true)) { isbk_rrmdir($dir . '/runs/' . $old); }
        }

        $status = [
            'ok' => !$shipErr, 'run' => $runId, 'finished' => gmdate('c'), 'seconds' => round(microtime(true) - $t0, 1),
            'files_total' => $filesTotal, 'files_new' => count($queue), 'core_bytes' => $encCore['size'],
            'parts_bytes' => array_sum(array_column($partsMeta, 'size')), 'databases' => array_map(fn($d) => $d['rows'] ?? 0, $dbMeta),
            'recipients' => $meta['recipients'], 'destinations' => count($dests), 'pending_parts' => count($state['pending']),
            'unshipped_runs' => count($state['unshipped']), 'errors' => $shipErr, 'warnings' => $warnings,
            'disk_usage_bytes' => isbk_dir_size($b['_storage']), 'disk_quota_bytes' => (int) $b['disk_quota_bytes'],
        ];
        if (empty($opts['no_status'])) { file_put_contents($dir . '/status.json', json_encode($status, JSON_PRETTY_PRINT)); }
        return $status;
    } catch (Throwable $e) {
        isbk_rrmdir($work);
        $status = ['ok' => false, 'run' => $runId, 'finished' => gmdate('c'), 'errors' => [$e->getMessage()]];
        file_put_contents($dir . '/status.json', json_encode($status, JSON_PRETTY_PRINT));
        throw $e;
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

/** Copies pending parts and unshipped runs to every destination (verified by sha256). Never deletes from destinations. */
function isbk_ship(array $b, array $dests, array &$state): array
{
    $dir = $b['dir']; $errors = [];
    if (!$dests) {
        isbk_log('WARNING: no destinations configured - backups exist only on this host');
        return ['no destination configured'];
    }
    // parts first, then core, then meta (meta is the commit marker at the destination)
    foreach ($state['pending'] as $part) {
        $src = $dir . '/' . $part;
        if (!is_file($src)) { continue; }
        $sha = isbk_sha_file($src); $all = true;
        foreach ($dests as $i => $d) {
            try { if (!isbk_dest_has($d, $part, $sha)) { isbk_dest_put($d, $src, $part, $sha); } }
            catch (Throwable $e) { $all = false; $errors[] = "dest #$i $part: " . $e->getMessage(); }
        }
        if ($all) { unlink($src); $state['pending'] = array_values(array_diff($state['pending'], [$part])); }
    }
    foreach ($state['unshipped'] as $run) {
        $rd = "$dir/runs/$run";
        if (!is_file("$rd/meta.json")) { $state['unshipped'] = array_values(array_diff($state['unshipped'], [$run])); continue; }
        $meta = json_decode(file_get_contents("$rd/meta.json"), true); $all = true;
        if (array_intersect($meta['parts_used'] ?? [], $state['pending'])) { $all = false; $errors[] = "run $run waits for unshipped parts"; }
        else foreach ($dests as $i => $d) {
            try {
                $c = isbk_sha_file("$rd/core.bak");
                if (!isbk_dest_has($d, "runs/$run/core.bak", $c)) { isbk_dest_put($d, "$rd/core.bak", "runs/$run/core.bak", $c); }
                $m = isbk_sha_file("$rd/meta.json");
                if (!isbk_dest_has($d, "runs/$run/meta.json", $m)) { isbk_dest_put($d, "$rd/meta.json", "runs/$run/meta.json", $m); }
            } catch (Throwable $e) { $all = false; $errors[] = "dest #$i run $run: " . $e->getMessage(); }
        }
        if ($all) { $state['unshipped'] = array_values(array_diff($state['unshipped'], [$run])); }
    }
    return $errors;
}
