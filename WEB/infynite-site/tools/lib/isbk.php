<?php
/**
 * Infynite backup toolkit - shared library (CLI only, PHP 8 + sodium + zip + pdo_mysql, runs on Linux and Windows).
 * Format "ISBK1": envelope encryption. A random one-time data key encrypts the payload in authenticated chunks
 * (XChaCha20-Poly1305 secretstream); the data key is sealed to every recipient public key (crypto_box_seal).
 * The server therefore holds only PUBLIC keys and can create but never read backups.
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }

const ISBK_MAGIC = "ISBK1\n";
const ISBK_CHUNK = 1048576;
const ISBK_STMT_END = '-- ISBK-STMT-END';
const ISBK_VERSION = '1.0';

function isbk_fail(string $m): never { throw new RuntimeException($m); }
function isbk_log(string $m): void { fwrite(STDERR, '[' . gmdate('H:i:s') . '] ' . $m . "\n"); }
function isbk_args(array $argv): array
{
    $o = ['_' => []];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $a, $m)) {
            $k = $m[1]; $v = $m[2] ?? true;
            if (isset($o[$k])) { $o[$k] = array_merge((array) $o[$k], [$v]); } else { $o[$k] = $v; }
        } else { $o['_'][] = $a; }
    }
    return $o;
}
function isbk_path(string ...$p): string { return preg_replace('#[\\\\/]+#', '/', implode('/', $p)); }
function isbk_mkdir(string $d): void { if (!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d)) { isbk_fail("cannot create $d"); } }

/* ------------------------------------------------------------------ config */
function isbk_config(?string $file = null): array
{
    if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__, 2)); }
    $file = $file ?: APP_ROOT . '/app/config.php';
    if (!is_file($file)) { isbk_fail("config not found: $file"); }
    $cfg = require $file;
    $b = $cfg['backup'] ?? [];
    $b += [
        'dir' => $cfg['storage'] . '/backup', 'recipients' => [], 'chunk_mb' => 500, 'keep_local' => 3,
        'databases' => [['name' => 'portal', 'host' => $cfg['db']['host'], 'port' => $cfg['db']['port'] ?? 3306, 'db' => $cfg['db']['name'], 'user' => $cfg['db']['user'], 'pass' => $cfg['db']['pass']]],
        'paths' => [['name' => 'uploads', 'path' => $cfg['storage'] . '/uploads']],
        'config_files' => [APP_ROOT . '/app/config.php'],
        'destinations' => [], 'notify_cmd' => null, 'disk_quota_bytes' => 10 * 1024 ** 3,
        'retention' => ['daily' => 14, 'weekly' => 8, 'monthly' => 12],
    ];
    $b['_storage'] = $cfg['storage'];
    return $b;
}

/* ------------------------------------------------------------------ keys */
function isbk_kid(string $pub): string { return substr(hash('sha256', $pub), 0, 16); }

function isbk_prompt_secret(string $prompt): string
{
    $env = getenv('BACKUP_PASSPHRASE');
    if ($env !== false && $env !== '') { return $env; }
    fwrite(STDERR, $prompt);
    $hide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && stream_isatty(STDIN);
    if ($hide) { @shell_exec('stty -echo'); }
    $line = fgets(STDIN);
    if ($hide) { @shell_exec('stty echo'); fwrite(STDERR, "\n"); }
    elseif (DIRECTORY_SEPARATOR === '\\') { fwrite(STDERR, "(note: input is visible on this terminal; set BACKUP_PASSPHRASE to avoid typing it)\n"); }
    return rtrim((string) $line, "\r\n");
}

function isbk_make_keyfile(string $label, string $passphrase): array
{
    $kp = sodium_crypto_box_keypair();
    $sec = sodium_crypto_box_secretkey($kp); $pub = sodium_crypto_box_publickey($kp);
    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES); $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE; $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;
    $k = sodium_crypto_pwhash(32, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    $file = ['isbk_key' => 1, 'label' => $label, 'created' => gmdate('c'), 'kid' => isbk_kid($pub), 'public' => base64_encode($pub),
        'kdf' => 'argon2id13', 'ops' => $ops, 'mem' => $mem, 'salt' => base64_encode($salt), 'nonce' => base64_encode($nonce),
        'secret_enc' => base64_encode(sodium_crypto_secretbox($sec, $nonce, $k))];
    sodium_memzero($sec); sodium_memzero($k);
    return $file;
}

function isbk_load_secret(string $keyFile, ?string $pass = null): string
{
    $j = json_decode((string) @file_get_contents($keyFile), true);
    if (!is_array($j) || empty($j['isbk_key'])) { isbk_fail("not a key file: $keyFile"); }
    $pass = $pass ?? isbk_prompt_secret('Key passphrase: ');
    $k = sodium_crypto_pwhash(32, $pass, base64_decode($j['salt']), (int) $j['ops'], (int) $j['mem'], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    $sec = sodium_crypto_secretbox_open(base64_decode($j['secret_enc']), base64_decode($j['nonce']), $k);
    sodium_memzero($k);
    if ($sec === false) { isbk_fail('wrong passphrase or damaged key file'); }
    return $sec;
}

/* ------------------------------------------------------------------ encryption */
/** Encrypts $src to $dst for all recipients. Returns ['size'=>, 'sha256'=>]. */
function isbk_encrypt_file(string $src, string $dst, array $recipients): array
{
    if (!$recipients) { isbk_fail('no recipient public keys configured (backup.recipients)'); }
    $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
    [$st, $hdr] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
    $rec = [];
    foreach ($recipients as $b64) {
        $pub = base64_decode($b64, true);
        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) { isbk_fail('invalid recipient public key'); }
        $rec[] = ['kid' => isbk_kid($pub), 'wk' => base64_encode(sodium_crypto_box_seal($key, $pub))];
    }
    sodium_memzero($key);
    $in = fopen($src, 'rb'); $out = fopen($dst, 'wb');
    if (!$in || !$out) { isbk_fail("cannot open $src / $dst"); }
    $ctx = hash_init('sha256'); $size = 0;
    $w = function (string $d) use ($out, $ctx, &$size) { if (fwrite($out, $d) !== strlen($d)) { isbk_fail('write failed (disk full?)'); } hash_update($ctx, $d); $size += strlen($d); };
    $head = json_encode(['v' => 1, 'ss' => base64_encode($hdr), 'rec' => $rec, 'chunk' => ISBK_CHUNK]);
    $w(ISBK_MAGIC . pack('N', strlen($head)) . $head);
    $cur = fread($in, ISBK_CHUNK);
    do {
        $next = ($cur === '' || strlen($cur) < ISBK_CHUNK) ? '' : fread($in, ISBK_CHUNK);
        $final = ($next === '' || $next === false);
        $ct = sodium_crypto_secretstream_xchacha20poly1305_push($st, (string) $cur, '', $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
        $w(pack('N', strlen($ct)) . $ct);
        $cur = $next;
    } while (!$final);
    fclose($in);
    if (!fclose($out)) { isbk_fail('close failed'); }
    return ['size' => $size, 'sha256' => hash_final($ctx)];
}

/** Decrypts an ISBK file to $dst using a secret key. Fails on wrong key, tampering or truncation. */
function isbk_decrypt_file(string $src, string $dst, string $secret): void
{
    $in = fopen($src, 'rb'); if (!$in) { isbk_fail("cannot open $src"); }
    if (fread($in, strlen(ISBK_MAGIC)) !== ISBK_MAGIC) { isbk_fail('not an ISBK backup file'); }
    $len = unpack('N', (string) fread($in, 4))[1];
    if ($len < 2 || $len > 1048576) { isbk_fail('corrupt header'); }
    $h = json_decode((string) fread($in, $len), true);
    if (!is_array($h)) { isbk_fail('corrupt header'); }
    $pub = sodium_crypto_box_publickey_from_secretkey($secret); $kid = isbk_kid($pub);
    $wk = null;
    foreach ($h['rec'] as $r) { if ($r['kid'] === $kid) { $wk = base64_decode($r['wk']); } }
    if ($wk === null) { isbk_fail("this key ($kid) is not a recipient of $src"); }
    $key = sodium_crypto_box_seal_open($wk, sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, $pub));
    if ($key === false) { isbk_fail('cannot unwrap data key'); }
    $st = sodium_crypto_secretstream_xchacha20poly1305_init_pull(base64_decode($h['ss']), $key);
    sodium_memzero($key);
    $out = fopen($dst, 'wb'); $sawFinal = false;
    while (!feof($in)) {
        $lb = fread($in, 4);
        if ($lb === '' || $lb === false) { break; }
        if (strlen($lb) !== 4) { isbk_fail('truncated file'); }
        if ($sawFinal) { isbk_fail('data after end of stream'); }
        $n = unpack('N', $lb)[1];
        if ($n > ISBK_CHUNK + 64) { isbk_fail('corrupt chunk length'); }
        $ct = ''; while (strlen($ct) < $n) { $p = fread($in, $n - strlen($ct)); if ($p === '' || $p === false) { isbk_fail('truncated file'); } $ct .= $p; }
        $r = sodium_crypto_secretstream_xchacha20poly1305_pull($st, $ct);
        if ($r === false) { isbk_fail('authentication failed: file is corrupted or tampered with'); }
        fwrite($out, $r[0]);
        if ($r[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) { $sawFinal = true; }
    }
    fclose($in); fclose($out);
    if (!$sawFinal) { isbk_fail('truncated file (no end marker)'); }
}

/* ------------------------------------------------------------------ database dump / import */
function isbk_pdo(array $d, ?string $dbOverride = null, bool $stream = false): PDO
{
    $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
    if ($stream) { $opt[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false; }
    $pdo = new PDO('mysql:host=' . $d['host'] . ';port=' . ($d['port'] ?? 3306) . ';dbname=' . ($dbOverride ?? $d['db']) . ';charset=utf8mb4', $d['user'], $d['pass'], $opt);
    return $pdo;
}
function isbk_q(string $id): string { return '`' . str_replace('`', '``', $id) . '`'; }
function isbk_sql_val($v, PDO $pdo): string
{
    if ($v === null) { return 'NULL'; }
    if (is_int($v)) { return (string) $v; }
    if (is_float($v)) { return sprintf('%.17g', $v); }
    $v = (string) $v;
    if ($v === '') { return "''"; }
    if (!mb_check_encoding($v, 'UTF-8')) { return '0x' . bin2hex($v); }
    return $pdo->quote($v);
}

/** Consistent (single-snapshot) logical dump to a gzip file. Returns ['tables'=>[name=>rows], 'rows'=>int]. */
function isbk_dump_db(array $d, string $outGz): array
{
    $pdo = isbk_pdo($d, null, true);
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    $gz = gzopen($outGz, 'wb6'); if (!$gz) { isbk_fail("cannot write $outGz"); }
    $stmt = function (string $s) use ($gz) { gzwrite($gz, $s . "\n" . ISBK_STMT_END . "\n"); };
    gzwrite($gz, "-- Infynite backup dump of database '" . $d['db'] . "' created " . gmdate('c') . "\n");
    foreach (["SET NAMES utf8mb4", "SET FOREIGN_KEY_CHECKS=0", "SET UNIQUE_CHECKS=0", "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'"] as $s) { $stmt($s); }
    $tables = [];
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $t) { $tables[] = $t[0]; }
    $counts = []; $total = 0;
    foreach ($tables as $t) {
        $create = $pdo->query('SHOW CREATE TABLE ' . isbk_q($t))->fetch(PDO::FETCH_NUM)[1];
        $stmt('DROP TABLE IF EXISTS ' . isbk_q($t));
        $stmt($create);
        $res = $pdo->query('SELECT * FROM ' . isbk_q($t));
        $cols = null; $batch = []; $n = 0;
        $flush = function () use (&$batch, &$cols, $t, $stmt) {
            if ($batch) { $stmt('INSERT INTO ' . isbk_q($t) . ' (' . $cols . ') VALUES ' . implode(',', $batch)); $batch = []; }
        };
        while ($row = $res->fetch(PDO::FETCH_NUM)) {
            if ($cols === null) { $cols = implode(',', array_map(fn($i) => isbk_q($res->getColumnMeta($i)['name']), array_keys($row))); }
            $batch[] = '(' . implode(',', array_map(fn($v) => isbk_sql_val($v, $pdo), $row)) . ')';
            $n++;
            if (count($batch) >= 200) { $flush(); }
        }
        $res->closeCursor(); $flush();
        $counts[$t] = $n; $total += $n;
    }
    $pdo->exec('COMMIT');
    gzwrite($gz, "-- ISBK-DUMP-COMPLETE tables=" . count($tables) . " rows=$total\n");
    gzclose($gz);
    return ['tables' => $counts, 'rows' => $total];
}

/** Imports an ISBK dump into the given database. Returns per-table row counts as found afterwards. */
function isbk_import_db(array $d, string $targetDb, string $gzFile): array
{
    $gz = gzopen($gzFile, 'rb'); if (!$gz) { isbk_fail("cannot open $gzFile"); }
    $pdo = isbk_pdo($d, $targetDb);
    $buf = ''; $complete = false; $stmts = 0;
    while (!gzeof($gz)) {
        $line = ''; do { $part = gzgets($gz, 1048576); if ($part === false) { break; } $line .= $part; } while (substr($line, -1) !== "\n" && !gzeof($gz));
        if ($line === '') { break; }
        $t = rtrim($line, "\r\n");
        if ($t === ISBK_STMT_END) { if (trim($buf) !== '') { $pdo->exec($buf); $stmts++; } $buf = ''; continue; }
        if ($buf === '' && str_starts_with($t, '--')) { if (str_starts_with($t, '-- ISBK-DUMP-COMPLETE')) { $complete = true; } continue; }
        $buf .= $line;
    }
    gzclose($gz);
    if (!$complete) { isbk_fail('dump is incomplete (end marker missing) - not trusting this backup'); }
    $out = [];
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $r) {
        $out[$r[0]] = (int) $pdo->query('SELECT COUNT(*) FROM ' . isbk_q($r[0]))->fetchColumn();
    }
    return $out;
}

/** Reads only the trailer of a dump to prove it finished. */
function isbk_dump_complete(string $gzFile): bool
{
    $gz = gzopen($gzFile, 'rb'); if (!$gz) { return false; }
    $last = '';
    while (!gzeof($gz)) { $l = gzgets($gz, 1048576); if ($l !== false && str_starts_with($l, '-- ')) { $last = $l; } }
    gzclose($gz);
    return str_starts_with($last, '-- ISBK-DUMP-COMPLETE');
}

/* ------------------------------------------------------------------ files */
function isbk_scan(string $root): array
{
    $out = [];
    if (!is_dir($root)) { return $out; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->isLink()) { continue; }
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
        if ($rel === '.htaccess') { continue; }
        $out[$rel] = ['size' => $f->getSize(), 'mtime' => $f->getMTime()];
    }
    ksort($out);
    return $out;
}

function isbk_sha_file(string $f): string { return (string) hash_file('sha256', $f); }

function isbk_zip_ok(string $zipPath): bool
{
    $z = new ZipArchive();
    if ($z->open($zipPath, ZipArchive::CHECKCONS) !== true) { return false; }
    $z->close();
    return true;
}

function isbk_rrmdir(string $d): void
{
    if (!is_dir($d)) { return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($d);
}

function isbk_dir_size(string $d): int
{
    $s = 0;
    if (!is_dir($d)) { return 0; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $f) { if ($f->isFile()) { $s += $f->getSize(); } }
    return $s;
}

/* ------------------------------------------------------------------ destinations (pluggable) */
function isbk_dest_put(array $dest, string $local, string $rel, string $sha): void
{
    if (($dest['type'] ?? '') !== 'folder') { isbk_fail("unsupported destination type '" . ($dest['type'] ?? '?') . "' (only 'folder' is implemented; Google Drive adapter comes later)"); }
    $to = isbk_path($dest['path'], $rel);
    isbk_mkdir(dirname($to));
    $tmp = $to . '.part';
    if (!copy($local, $tmp)) { isbk_fail("copy to $to failed"); }
    if (isbk_sha_file($tmp) !== $sha) { @unlink($tmp); isbk_fail("checksum mismatch after copying to $to"); }
    if (!rename($tmp, $to)) { isbk_fail("cannot finalise $to"); }
}
function isbk_dest_has(array $dest, string $rel, string $sha): bool
{
    $p = isbk_path($dest['path'], $rel);
    return is_file($p) && isbk_sha_file($p) === $sha;
}

function isbk_notify(array $b, string $msg): void
{
    isbk_log($msg);
    if (!empty($b['notify_cmd']) && function_exists('exec')) { @exec($b['notify_cmd'] . ' ' . escapeshellarg($msg) . ' 2>&1'); }
}

/* ------------------------------------------------------------------ run catalogue */
function isbk_runs(string $dir): array
{
    $runs = [];
    foreach (glob(isbk_path($dir, 'runs', '*', 'meta.json')) ?: [] as $m) {
        $j = json_decode((string) file_get_contents($m), true);
        if (is_array($j) && !empty($j['run'])) { $runs[$j['run']] = $j; }
    }
    ksort($runs);
    return $runs;
}

/** GFS retention: which run IDs to keep. */
function isbk_keep_set(array $runs, array $ret): array
{
    $ids = array_keys($runs); rsort($ids);
    $keep = [];
    $seen = ['d' => [], 'w' => [], 'm' => []];
    foreach ($ids as $id) {
        $t = strtotime(substr($id, 0, 4) . '-' . substr($id, 4, 2) . '-' . substr($id, 6, 2) . ' ' . substr($id, 9, 2) . ':' . substr($id, 11, 2) . ':' . substr($id, 13, 2) . ' UTC');
        $kd = gmdate('Y-m-d', $t); $kw = gmdate('o-W', $t); $km = gmdate('Y-m', $t);
        if (!isset($seen['d'][$kd]) && count($seen['d']) < $ret['daily']) { $seen['d'][$kd] = 1; $keep[$id] = 1; }
        if (!isset($seen['w'][$kw]) && count($seen['w']) < $ret['weekly']) { $seen['w'][$kw] = 1; $keep[$id] = 1; }
        if (!isset($seen['m'][$km]) && count($seen['m']) < $ret['monthly']) { $seen['m'][$km] = 1; $keep[$id] = 1; }
    }
    return array_keys($keep);
}

/** Removes runs outside the retention set and any part file no kept run references. Returns removed names. */
function isbk_prune(string $dir, array $ret): array
{
    $runs = isbk_runs($dir);
    $keep = isbk_keep_set($runs, $ret);
    $removed = [];
    $refd = [];
    foreach ($keep as $id) { foreach ($runs[$id]['parts_used'] ?? [] as $p) { $refd[$p] = 1; } }
    foreach ($runs as $id => $m) {
        if (!in_array($id, $keep, true)) { isbk_rrmdir(isbk_path($dir, 'runs', $id)); $removed[] = "runs/$id"; }
    }
    foreach (glob(isbk_path($dir, 'parts', '*.bak')) ?: [] as $p) {
        $rel = 'parts/' . basename($p);
        if (!isset($refd[$rel])) { @unlink($p); $removed[] = $rel; }
    }
    return $removed;
}
