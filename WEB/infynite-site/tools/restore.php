<?php
/**
 * Restore tool (run on a trusted machine; needs the PRIVATE key file + passphrase).
 *   php tools/restore.php list    [--src=DIR]
 *   php tools/restore.php verify  --key=FILE [--src=DIR] [--run=ID]
 *   php tools/restore.php restore --key=FILE [--src=DIR] [--run=ID] --db=NAME=TARGETDB [--db=...] [--files-to=DIR]
 *   php tools/restore.php restore --key=FILE --run=ID --live --yes --confirm=ID      (overwrites the LIVE site; see below)
 * Default is STAGING: nothing live is touched. --live first takes an encrypted pre-restore snapshot, switches the
 * portal to maintenance mode, restores, checks, and only then lifts maintenance mode.
 * Passphrase: prompted, or env BACKUP_PASSPHRASE.
 */
require __DIR__ . '/lib/backup_run.php';

function rs_load(string $src, ?string $run): array
{
    $runs = isbk_runs($src);
    if (!$runs) { isbk_fail("no backup runs found in $src"); }
    $run = $run ?: array_key_last($runs);
    if (!isset($runs[$run])) { isbk_fail("run $run not found in $src"); }
    return [$runs, $run, $runs[$run]];
}
function rs_part_sha(array $runs, string $part): ?string
{
    foreach ($runs as $m) { if (isset($m['parts'][$part])) { return $m['parts'][$part]['sha256']; } }
    return null;
}
function rs_tmp(): string { $d = sys_get_temp_dir() . '/isbk-' . bin2hex(random_bytes(6)); isbk_mkdir($d); return $d; }

/** Decrypts + validates the core archive; returns [tmpdir, manifest]. */
function rs_open_core(string $src, array $meta, string $secret): array
{
    $core = "$src/runs/{$meta['run']}/core.bak";
    if (!is_file($core)) { isbk_fail("core.bak missing for run {$meta['run']}"); }
    if (isbk_sha_file($core) !== $meta['core']['sha256']) { isbk_fail('core.bak checksum does not match meta.json (file damaged)'); }
    $tmp = rs_tmp();
    isbk_decrypt_file($core, "$tmp/core.zip", $secret);
    $z = new ZipArchive();
    if ($z->open("$tmp/core.zip", ZipArchive::CHECKCONS) !== true) { isbk_fail('core archive is damaged'); }
    $z->extractTo($tmp); $z->close();
    $man = json_decode((string) file_get_contents("$tmp/manifest.json"), true);
    if (!is_array($man) || ($man['run'] ?? '') !== $meta['run']) { isbk_fail('manifest does not belong to this run'); }
    return [$tmp, $man];
}

/** Streams every file of one part to $dest (callable ($key,$tmpPath) or null = verify only). Returns [ok, bad[]]. */
function rs_each_part(string $src, array $runs, array $man, string $secret, ?callable $sink): array
{
    $byPart = [];
    foreach ($man['files'] as $k => $e) { if (!empty($e['part'])) { $byPart[$e['part']][$k] = $e; } }
    $ok = 0; $bad = [];
    foreach ($byPart as $part => $files) {
        $p = "$src/$part"; $sha = rs_part_sha($runs, $part);
        if (!is_file($p)) { foreach ($files as $k => $e) { $bad[] = "$k: part $part missing"; } continue; }
        if ($sha && isbk_sha_file($p) !== $sha) { foreach ($files as $k => $e) { $bad[] = "$k: part $part checksum mismatch"; } continue; }
        $tmp = rs_tmp();
        try {
            isbk_decrypt_file($p, "$tmp/part.zip", $secret);
            $z = new ZipArchive();
            if ($z->open("$tmp/part.zip", ZipArchive::CHECKCONS) !== true) { throw new RuntimeException('zip damaged'); }
            foreach ($files as $k => $e) {
                $st = $z->getStream($k);
                if (!$st) { $bad[] = "$k: not in $part"; continue; }
                $out = "$tmp/f.bin"; $o = fopen($out, 'wb'); $h = hash_init('sha256');
                while (!feof($st)) { $d = fread($st, 1048576); if ($d === false) { break; } hash_update($h, $d); fwrite($o, $d); }
                fclose($st); fclose($o);
                if (hash_final($h) !== $e['sha']) { $bad[] = "$k: sha256 mismatch"; continue; }
                if ($sink) { $sink($k, $out, $e); }
                $ok++;
            }
            $z->close();
        } catch (Throwable $ex) { foreach ($files as $k => $e) { $bad[] = "$k: " . $ex->getMessage(); } }
        isbk_rrmdir($tmp);
    }
    return [$ok, $bad];
}

function rs_report(string $label, bool $pass, string $detail = ''): bool
{
    echo ($pass ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
    return $pass;
}

try {
    $a = isbk_args($argv);
    $cmd = $a['_'][0] ?? 'help';
    $cfgFile = is_string($a['config'] ?? null) ? $a['config'] : null;
    $b = null;
    try { $b = isbk_config($cfgFile); } catch (Throwable $e) { if ($cmd === 'restore' && isset($a['live'])) { throw $e; } }
    $src = is_string($a['src'] ?? null) ? $a['src'] : ($b['dir'] ?? null);
    if (!$src && $cmd !== 'help') { isbk_fail('give --src=DIR'); }

    if ($cmd === 'list') {
        foreach (isbk_runs($src) as $id => $m) {
            printf("%s  %s  files=%d  db_rows=%s  parts_used=%d%s\n", $id, $m['created'], $m['files_total'],
                implode('/', array_map(fn($d) => $d['rows'] ?? '=', $m['databases'])), count($m['parts_used'] ?? []), $m['label'] ? "  [{$m['label']}]" : '');
        }
        exit(0);
    }
    if ($cmd !== 'verify' && $cmd !== 'restore') { echo "Usage: see header of tools/restore.php\n"; exit($cmd === 'help' ? 0 : 1); }
    if (!is_string($a['key'] ?? null)) { isbk_fail('--key=FILE (private key file) is required'); }
    [$runs, $runId, $meta] = rs_load($src, is_string($a['run'] ?? null) ? $a['run'] : null);
    $secret = isbk_load_secret($a['key']);
    echo "Run $runId ({$meta['created']}), key " . isbk_kid(sodium_crypto_box_publickey_from_secretkey($secret)) . "\n";
    [$tmp, $man] = rs_open_core($src, $meta, $secret);
    $allOk = rs_report('core archive decrypts, checksum and manifest valid', true);

    // database dumps
    $dumps = [];
    foreach ($meta['databases'] as $name => $d) {
        if (isset($d['same_as'])) { continue; }
        $f = "$tmp/db/db-$name.sql.gz";
        $okd = is_file($f) && isbk_dump_complete($f);
        $allOk = rs_report("database dump '$name' complete", $okd, ($d['tables'] ?? '?') . ' tables, ' . ($d['rows'] ?? '?') . ' rows') && $allOk;
        $dumps[$name] = $f;
    }

    if ($cmd === 'verify') {
        [$n, $bad] = rs_each_part($src, $runs, $man, $secret, null);
        $allOk = rs_report("files verified against manifest", !$bad && $n === count(array_filter($man['files'], fn($e) => !empty($e['part']))), "$n ok, " . count($bad) . ' bad') && $allOk;
        foreach (array_slice($bad, 0, 20) as $x) { echo "      $x\n"; }
        isbk_rrmdir($tmp);
        echo $allOk ? "VERIFY PASSED\n" : "VERIFY FAILED\n";
        exit($allOk ? 0 : 1);
    }

    /* ---------------- restore ---------------- */
    $live = isset($a['live']);
    $maint = null;
    if ($live) {
        if (!isset($a['yes']) || ($a['confirm'] ?? '') !== $runId) { isbk_fail("LIVE restore overwrites the running site. Re-run with --yes --confirm=$runId to proceed."); }
        $maint = $b['_storage'] . '/maintenance.flag';
        isbk_log('taking pre-restore snapshot of the current live state (databases + config)...');
        $snap = isbk_backup_run($b, ['files' => false, 'label' => 'pre-restore', 'destinations' => [], 'no_status' => true]);
        isbk_log('snapshot run ' . $snap['run'] . ' saved in ' . $b['dir'] . ' (keep it until you are sure)');
        file_put_contents($maint, gmdate('c') . " restore of $runId\n");
    }
    $maps = [];
    foreach ((array) ($a['db'] ?? []) as $m) { if (is_string($m) && strpos($m, '=') > 0) { [$n, $t] = explode('=', $m, 2); $maps[$n] = $t; } }
    $dbCreds = [];
    foreach (($b['databases'] ?? []) as $d) { $dbCreds[$d['name']] = $d; }

    foreach ($dumps as $name => $f) {
        $cred = $dbCreds[$name] ?? null;
        if (!$cred) { isbk_fail("no connection settings for database '$name' in config (backup.databases)"); }
        $target = $live ? $cred['db'] : ($maps[$name] ?? null);
        if (!$target) { isbk_fail("staging restore needs --db=$name=TARGET_DB_NAME (a separate, empty database)"); }
        if (!$live) {
            $adm = isbk_pdo($cred, 'information_schema');
            if (!preg_match('/^[A-Za-z0-9_]+$/', $target)) { isbk_fail('invalid target database name'); }
            if ($target === $cred['db']) { isbk_fail('refusing to restore over the live database without --live'); }
            $adm->exec('CREATE DATABASE IF NOT EXISTS ' . isbk_q($target) . ' CHARACTER SET utf8mb4');
        }
        isbk_log("importing '$name' into database '$target'...");
        $found = isbk_import_db($cred, $target, $f);
        $expect = $meta['databases'][$name]['row_counts'] ?? [];
        $diff = [];
        foreach ($expect as $t => $n) { if (($found[$t] ?? -1) !== $n) { $diff[] = "$t expected $n got " . ($found[$t] ?? 'missing'); } }
        $allOk = rs_report("database '$name' restored into '$target': row counts match backup", !$diff, count($found) . ' tables') && $allOk;
        foreach (array_slice($diff, 0, 10) as $x) { echo "      $x\n"; }
    }

    $filesTo = is_string($a['files-to'] ?? null) ? $a['files-to'] : null;
    if ($live || $filesTo) {
        $paths = [];
        foreach (($b['paths'] ?? []) as $p) { $paths[$p['name']] = $p['path']; }
        $written = 0; $skipped = 0;
        [$n, $bad] = rs_each_part($src, $runs, $man, $secret, function ($k, $tmpf, $e) use ($live, $filesTo, $paths, &$written, &$skipped) {
            [$grp, $rel] = explode('/', $k, 2);
            $base = $live ? ($paths[$grp] ?? null) : $filesTo . '/' . $grp;
            if (!$base) { return; }
            if (preg_match('#(^|/)\.\.(/|$)#', $rel)) { throw new RuntimeException("unsafe path $rel"); }
            $dst = $base . '/' . $rel;
            if ($live && is_file($dst) && isbk_sha_file($dst) === $e['sha']) { $skipped++; return; }   // already identical
            isbk_mkdir(dirname($dst));
            copy($tmpf, $dst); @chmod($dst, 0640); $written++;
        });
        $allOk = rs_report('files restored and verified (sha256)', !$bad, "$written written, $skipped already present, " . count($bad) . ' bad') && $allOk;
        foreach (array_slice($bad, 0, 20) as $x) { echo "      $x\n"; }
    }
    isbk_rrmdir($tmp);
    if ($live) {
        if ($allOk) { @unlink($maint); echo "Live restore finished; maintenance mode lifted. Now run the post-restore checklist (docs/BACKUP_RESTORE_PLAN.md 5.7).\n"; }
        else { echo "LIVE RESTORE HAD FAILURES - maintenance mode left ON ($maint). Investigate; the pre-restore snapshot can be restored the same way.\n"; }
    }
    echo $allOk ? "RESTORE OK\n" : "RESTORE FAILED\n";
    exit($allOk ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
