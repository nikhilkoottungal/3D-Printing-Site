<?php
/** Off-site copies through rclone (Google Drive and any rclone remote) and FTP/FTPS/SFTP. Configured in Admin settings > Integrations. */
const OFFSITE_DATASETS = [
    'backup' => ['Encrypted full backup (database, files, config)', 'already encrypted, safe to store anywhere'],
    'models' => ['Customer model files (STL/3MF) with readable names', 'NOT encrypted: use a private location'],
    'kb'     => ['Knowledge base and procedures as PDF', 'plain PDF files'],
    'source' => ['Application source code (no secrets)', 'zip archive'],
];
const OFFSITE_DESTS = ['drive' => 'Google Drive (rclone)', 'ftp' => 'FTP / FTPS / SFTP server'];

function rclone_bin(): ?string
{
    foreach (['/usr/bin/rclone', '/usr/local/bin/rclone', '/opt/homebrew/bin/rclone'] as $b) { if (is_executable($b)) { return $b; } }
    return null;
}
/** Runs a command without a shell. Returns [exit code, stdout, stderr]. */
function proc_capture(array $cmd, array $env = [], ?string $stdin = null, int $timeout = 900): array
{
    $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv() ?: [], ['HOME' => getenv('HOME') ?: '/root'], $env));
    if (!is_resource($p)) { return [127, '', 'could not start ' . $cmd[0]]; }
    if ($stdin !== null) { fwrite($pipes[0], $stdin); } fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = $err = ''; $t0 = time(); $exit = null;
    while (true) {
        $st = proc_get_status($p);
        $out .= (string) stream_get_contents($pipes[1]); $err .= (string) stream_get_contents($pipes[2]);
        if (!$st['running']) { $exit = $st['exitcode']; break; }
        if (time() - $t0 > $timeout) { proc_terminate($p, 9); $err .= "\ntimed out after {$timeout}s"; $exit = 124; break; }
        usleep(100000);
    }
    $out .= (string) stream_get_contents($pipes[1]); $err .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return [(int) $exit, $out, trim($err)];
}
function rclone_remotes(): array
{
    $b = rclone_bin(); if (!$b) { return []; }
    [$c, $o] = proc_capture([$b, 'listremotes'], [], null, 20);
    return $c === 0 ? array_values(array_filter(array_map(fn($l) => rtrim(trim($l), ':'), explode("\n", $o)))) : [];
}
/** Returns ['spec' => 'remote:path', 'env' => [...], 'flags' => [...]] for a destination, or null when not set up. */
function offsite_target(string $dest): ?array
{
    $path = trim((string) setting($dest . '_path', ''), '/');
    if ($dest === 'drive') {
        $remote = (string) setting('drive_remote', '');
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $remote)) { return null; }
        $flags = []; if (($root = (string) setting('drive_root', '')) !== '') { $flags[] = '--drive-root-folder-id=' . $root; }
        return ['spec' => $remote . ':' . $path, 'env' => [], 'flags' => $flags, 'label' => OFFSITE_DESTS['drive']];
    }
    if ($dest === 'ftp') {
        $host = (string) setting('ftp_host', ''); $proto = (string) setting('ftp_proto', 'sftp');
        if ($host === '' || !in_array($proto, ['ftp', 'ftps', 'sftp'], true)) { return null; }
        $pass = (string) (secret_decrypt(setting('ftp_pass')) ?? '');
        $b = rclone_bin(); if (!$b) { return null; }
        [, $obs] = proc_capture([$b, 'obscure', '-'], [], $pass, 20);
        $e = ['RCLONE_CONFIG_PORTALFTP_TYPE' => $proto === 'sftp' ? 'sftp' : 'ftp', 'RCLONE_CONFIG_PORTALFTP_HOST' => $host,
            'RCLONE_CONFIG_PORTALFTP_PORT' => (string) (int) setting('ftp_port', $proto === 'sftp' ? 22 : 21), 'RCLONE_CONFIG_PORTALFTP_USER' => (string) setting('ftp_user', ''), 'RCLONE_CONFIG_PORTALFTP_PASS' => trim($obs)];
        if ($proto === 'ftps') { $e['RCLONE_CONFIG_PORTALFTP_EXPLICIT_TLS'] = 'true'; }
        return ['spec' => 'portalftp:' . $path, 'env' => $e, 'flags' => [], 'label' => OFFSITE_DESTS['ftp'] . ' (' . strtoupper($proto) . ')'];
    }
    return null;
}
function rc(array $t, array $args, ?string $stdin = null, int $timeout = 900): array
{
    $b = rclone_bin(); if (!$b) { return [127, '', 'rclone is not installed on the server']; }
    return proc_capture(array_merge([$b], $args, $t['flags'], ['--contimeout=20s', '--timeout=120s', '--retries=2']), $t['env'], $stdin, $timeout);
}
function rc_sub(array $t, string $sub): string { $s = $t['spec']; return str_ends_with($s, ':') ? $s . $sub : $s . '/' . $sub; }

/** Connection test: creates the folder, writes, reads back and deletes a small file. Returns [ok, message]. */
function offsite_test(string $dest): array
{
    $t = offsite_target($dest);
    if (!$t) { return [false, 'Not configured yet (fill in and save the settings first).']; }
    $name = 'portal-test-' . bin2hex(random_bytes(3)) . '.txt'; $body = 'Infynite portal test ' . date('c');
    [$c, , $e] = rc($t, ['mkdir', $t['spec']], null, 60); if ($c !== 0) { return [false, 'Cannot reach or create the folder: ' . ($e ?: 'error ' . $c)]; }
    [$c, , $e] = rc($t, ['rcat', rc_sub($t, $name)], $body, 60); if ($c !== 0) { return [false, 'Cannot write a file: ' . ($e ?: 'error ' . $c)]; }
    [$c, $o, $e] = rc($t, ['cat', rc_sub($t, $name)], null, 60);
    rc($t, ['deletefile', rc_sub($t, $name)], null, 60);
    if ($c !== 0 || trim($o) !== $body) { return [false, 'File was written but could not be read back: ' . ($e ?: 'content differs')]; }
    return [true, 'Connected: created the folder, wrote, read back and deleted a test file at ' . $t['label'] . '.'];
}

function offsite_backup_dir(): string
{
    if (backup_targets() === ['local' => false, 'ftp' => true]) { return backup_staging_dir(); }
    $d = cfg('backup.destinations.0.path');
    return is_string($d) && is_dir($d) ? $d : cfg('storage') . '/backup';
}
function offsite_model_names(): array
{
    return all('SELECT f.stored_name, f.orig_name, r.ref FROM is_request_files f JOIN is_requests r ON r.id=f.request_id ORDER BY f.id');
}
function offsite_kb_dir(): string
{
    $d = sys_get_temp_dir() . '/portal-kb-' . bin2hex(random_bytes(4)); mkdir($d, 0700, true);
    $idx = [];
    foreach (all('SELECT title, slug, category, kind, version, body, updated_at FROM is_kb_articles ORDER BY category, title') as $a) {
        $pdf = kb_pdf($a); file_put_contents("$d/{$a['slug']}.pdf", $pdf->output()); $idx[] = $a['category'] . '/' . $a['title'];
    }
    return $d;
}
function kb_pdf(array $a): Pdf
{
    $p = new Pdf($a['title']);
    $p->para((string) setting('app_name', cfg('app_name')) . ' - ' . ($a['kind'] === 'procedure' ? 'Procedure' : 'Knowledge'), 9, false, null, null, 1.2);
    $p->para($a['title'], 20, true); $p->para($a['category'] . '  -  version ' . $a['version'] . '  -  updated ' . fmt_date($a['updated_at']), 9);
    $p->rule(6, 8); pdf_markdown($p, (string) $a['body']);
    return $p;
}
function offsite_source_zip(): ?string
{
    if (!class_exists('ZipArchive')) { return null; }
    $root = APP_ROOT; $zp = sys_get_temp_dir() . '/portal-src-' . bin2hex(random_bytes(4)) . '.zip';
    $z = new ZipArchive(); if ($z->open($zp, ZipArchive::CREATE) !== true) { return null; }
    $skip = ['app/config.php', 'SETUP_TOKEN.txt', 'storage/secret.key', 'storage/maintenance.flag'];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) { continue; }
        $rel = ltrim(substr($f->getPathname(), strlen($root)), '/');
        if (in_array($rel, $skip, true) || str_starts_with($rel, 'storage/uploads/') && basename($rel) !== '.htaccess' && basename($rel) !== '.gitkeep' || str_starts_with($rel, 'storage/logs/') && basename($rel) !== '.gitkeep' || str_starts_with($rel, 'storage/backup') || str_ends_with($rel, '.key') || str_starts_with($rel, '.git/')) { continue; }
        $z->addFile($f->getPathname(), 'infynite-site/' . $rel);
    }
    $z->close();
    return $zp;
}

/**
 * Copies every enabled dataset to every enabled destination. Returns the list of results and stores it as the last run.
 * $datasets + $force: copy exactly those datasets to $only even if the destination is not "Enabled" or ticked in the matrix
 * (used by the scheduled backup when the Backups page is set to "FTP"); the result is then stored as backup_ftp_last.
 */
function offsite_run(?string $only = null, ?array $datasets = null, bool $force = false): array
{
    $res = [];
    foreach (OFFSITE_DESTS as $dest => $_) {
        if (($only && $only !== $dest) || (!$force && setting($dest . '_enabled') !== '1')) { continue; }
        $t = offsite_target($dest);
        $sets = $datasets !== null ? array_values(array_intersect($datasets, array_keys(OFFSITE_DATASETS))) : array_filter(array_keys(OFFSITE_DATASETS), fn($ds) => setting("off_{$ds}_{$dest}") === '1');
        if (!$sets) { continue; }
        if (!$t) { foreach ($sets as $ds) { $res[] = ['dest' => $dest, 'set' => $ds, 'ok' => false, 'msg' => 'destination not configured']; } continue; }
        foreach ($sets as $ds) {
            try { [$ok, $msg] = offsite_copy($t, $ds); } catch (Throwable $e) { $ok = false; $msg = $e->getMessage(); }
            $res[] = ['dest' => $dest, 'set' => $ds, 'ok' => $ok, 'msg' => $msg];
        }
    }
    setting_set($force ? 'backup_ftp_last' : 'offsite_last', json_encode(['at' => now(), 'results' => $res]));
    $bad = array_filter($res, fn($r) => !$r['ok']);
    if ($bad) { notify_admins('Off-site copy failed', "These copies failed:\n" . implode("\n", array_map(fn($r) => '- ' . OFFSITE_DESTS[$r['dest']] . ' / ' . OFFSITE_DATASETS[$r['set']][0] . ': ' . $r['msg'], $bad))); }
    return $res;
}

/** Backup run folders look like 20261022-165500. */
function offsite_valid_run(string $id): bool { return (bool) preg_match('/^\d{8}-\d{6}$/', $id); }

/** Lists the backup runs stored on the FTP server (reads each run's small meta.json). Returns ['ok' => bool, 'runs' => [id => meta], 'err' => string]. */
function offsite_ftp_runs(?string $into = null): array
{
    $t = offsite_target('ftp');
    if (!$t) { return ['ok' => false, 'runs' => [], 'err' => 'FTP is not configured']; }
    $own = $into === null; $tmp = $into ?? sys_get_temp_dir() . '/portal-ftpruns-' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    [$c, , $e] = rc($t, ['copy', rc_sub($t, 'backups/runs'), $tmp . '/runs', '--include=*/meta.json'], null, 120);
    $runs = [];
    if ($c === 0) {
        foreach (glob($tmp . '/runs/*/meta.json') ?: [] as $m) {
            $j = json_decode((string) file_get_contents($m), true);
            if (is_array($j) && !empty($j['run']) && offsite_valid_run($j['run'])) { $runs[$j['run']] = $j; }
        }
        krsort($runs);
    }
    if ($own) { foreach (glob($tmp . '/runs/*/meta.json') ?: [] as $m) { @unlink($m); } foreach (glob($tmp . '/runs/*', GLOB_ONLYDIR) ?: [] as $d) { @rmdir($d); } @rmdir($tmp . '/runs'); @rmdir($tmp); }
    return ['ok' => $c === 0, 'runs' => $runs, 'err' => $c === 0 ? '' : ($e ?: "rclone exit $c")];
}

/** Downloads one run from the FTP server into $dir (same folder layout as a local backup folder): meta of all runs, the run's core.bak and the parts it uses. Returns an error string or null. */
function offsite_ftp_fetch(string $runId, string $dir): ?string
{
    if (!offsite_valid_run($runId)) { return 'invalid run id'; }
    $t = offsite_target('ftp'); if (!$t) { return 'FTP is not configured'; }
    $l = offsite_ftp_runs($dir);
    if (!$l['ok']) { return 'cannot list the FTP backups: ' . $l['err']; }
    if (!isset($l['runs'][$runId])) { return "run $runId is not on the FTP server"; }
    [$c, , $e] = rc($t, ['copy', rc_sub($t, "backups/runs/$runId"), "$dir/runs/$runId"], null, 3600);
    if ($c !== 0) { return 'download of the run failed: ' . ($e ?: "rclone exit $c"); }
    foreach ($l['runs'][$runId]['parts_used'] ?? [] as $part) {
        if (!preg_match('~^parts/[A-Za-z0-9._-]+\.bak$~', $part)) { return 'unexpected part name ' . $part; }
        [$c, , $e] = rc($t, ['copyto', rc_sub($t, 'backups/' . $part), "$dir/$part"], null, 7200);
        if ($c !== 0) { return "download of $part failed: " . ($e ?: "rclone exit $c"); }
    }
    return null;
}
function offsite_copy(array $t, string $ds): array
{
    if ($ds === 'backup') {
        $dir = offsite_backup_dir(); if (!is_dir($dir)) { return [false, 'no backup folder yet']; }
        [$c, , $e] = rc($t, ['copy', $dir, rc_sub($t, 'backups'), '--exclude=*.tmp'], null, 3600);
        return $c === 0 ? [true, 'copied ' . $dir] : [false, $e ?: "rclone exit $c"];
    }
    if ($ds === 'kb') {
        $d = offsite_kb_dir(); [$c, , $e] = rc($t, ['copy', $d, rc_sub($t, 'knowledge-base')], null, 600); array_map('unlink', glob("$d/*.pdf") ?: []); @rmdir($d);
        return $c === 0 ? [true, 'knowledge base PDFs copied'] : [false, $e ?: "rclone exit $c"];
    }
    if ($ds === 'source') {
        $z = offsite_source_zip(); if (!$z) { return [false, 'could not build the archive']; }
        [$c, , $e] = rc($t, ['copyto', $z, rc_sub($t, 'source/infynite-source-' . date('Ymd') . '.zip')], null, 600); @unlink($z);
        return $c === 0 ? [true, 'source archive copied'] : [false, $e ?: "rclone exit $c"];
    }
    if ($ds === 'models') {
        $n = 0; $fail = 0; $last = '';
        foreach (offsite_model_names() as $f) {
            $src = cfg('storage') . '/uploads/' . $f['stored_name']; if (!is_file($src)) { continue; }
            $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($f['orig_name']));
            [$c, , $e] = rc($t, ['copyto', '--ignore-existing', $src, rc_sub($t, 'models/' . $f['ref'] . '/' . $name)], null, 600);
            if ($c === 0) { $n++; } else { $fail++; $last = $e; }
        }
        return $fail ? [false, "$fail file(s) failed: $last"] : [true, "$n model file(s) checked/copied"];
    }
    return [false, 'unknown dataset'];
}
