<?php
/**
 * Self-contained backup/restore test. Needs the DB user from app/config.php to be able to create databases named
 * infynite_bktest_* (GRANT ... ON `infynite\_%`.*). Uses temp folders only; never touches real data.
 *   php tools/test_roundtrip.php
 */
require __DIR__ . '/lib/backup_run.php';
$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $info = ''): void { global $pass, $fail; echo ($ok ? '  PASS  ' : '  FAIL  ') . $name . ($info ? "  ($info)" : '') . "\n"; $ok ? $pass++ : $fail++; }
function throws(callable $f): ?string { try { $f(); return null; } catch (Throwable $e) { return $e->getMessage(); } }

$cfg = isbk_config();
$base = $cfg['databases'][0];
$tmp = sys_get_temp_dir() . '/isbk-test-' . bin2hex(random_bytes(4)); isbk_mkdir($tmp);
$srcDb = 'infynite_bktest_src'; $dstDb = 'infynite_bktest_dst';
$adm = isbk_pdo($base, 'information_schema');
foreach ([$srcDb, $dstDb] as $d) { $adm->exec('DROP DATABASE IF EXISTS ' . isbk_q($d)); $adm->exec('CREATE DATABASE ' . isbk_q($d) . ' CHARACTER SET utf8mb4'); }
$src = ['name' => 'portal'] + array_merge($base, ['db' => $srcDb]);
$p = isbk_pdo($src);
$p->exec('CREATE TABLE a (id INT AUTO_INCREMENT PRIMARY KEY, txt TEXT, bin BLOB, d DECIMAL(10,2), n INT NULL, ts DATETIME) ENGINE=InnoDB');
$p->exec('CREATE TABLE b (id INT PRIMARY KEY, a_id INT, FOREIGN KEY (a_id) REFERENCES a(id)) ENGINE=InnoDB');
$ins = $p->prepare('INSERT INTO a (txt,bin,d,n,ts) VALUES (?,?,?,?,?)');
$nasty = "O'Brien \"quoted\" \\ back\\slash\nnewline\r\n; -- ISBK-STMT-END? ₹ ünï😀";
for ($i = 0; $i < 450; $i++) { $ins->execute([$i === 7 ? $nasty : "row $i", $i === 3 ? random_bytes(200) : null, $i * 1.25, $i % 5 ? $i : null, '2026-01-01 10:00:00']); }
$p->exec('INSERT INTO b VALUES (1,1),(2,2)');

$store = "$tmp/storage"; isbk_mkdir("$store/uploads");
$mk = function (string $name, int $size) use ($store) { $f = "$store/uploads/$name"; file_put_contents($f, $size ? random_bytes($size) : ""); return $f; };
$f1 = $mk(bin2hex(random_bytes(20)), 5000); $f2 = $mk(bin2hex(random_bytes(20)), 3 * 1048576 + 17); $f3 = $mk(bin2hex(random_bytes(20)), 0);
$kA = isbk_make_keyfile('A', 'passphrase-A-123'); $kB = isbk_make_keyfile('B', 'passphrase-B-456'); $kC = isbk_make_keyfile('C', 'passphrase-C-789');
file_put_contents("$tmp/A.key", json_encode($kA)); file_put_contents("$tmp/B.key", json_encode($kB)); file_put_contents("$tmp/C.key", json_encode($kC));
$off = "$tmp/offsite";
$b = ['dir' => "$store/backup", 'recipients' => [$kA['public'], $kB['public']], 'chunk_mb' => 1, 'keep_local' => 2,
    'databases' => [$src], 'paths' => [['name' => 'uploads', 'path' => "$store/uploads"]], 'config_files' => [], 'destinations' => [['type' => 'folder', 'path' => $off]],
    'notify_cmd' => null, 'disk_quota_bytes' => 10 ** 9, 'retention' => ['daily' => 3, 'weekly' => 1, 'monthly' => 1], '_storage' => $store];

echo "== crypto\n";
file_put_contents("$tmp/plain", random_bytes(2500000)); $e = isbk_encrypt_file("$tmp/plain", "$tmp/enc", [$kA['public']]);
$sa = isbk_load_secret("$tmp/A.key", 'passphrase-A-123');
isbk_decrypt_file("$tmp/enc", "$tmp/dec", $sa); t('encrypt/decrypt round trip (multi-chunk)', hash_file('sha256', "$tmp/plain") === hash_file('sha256', "$tmp/dec"));
file_put_contents("$tmp/empty", ''); isbk_encrypt_file("$tmp/empty", "$tmp/enc0", [$kA['public']]); isbk_decrypt_file("$tmp/enc0", "$tmp/dec0", $sa); t('empty file round trip', filesize("$tmp/dec0") === 0);
$raw = file_get_contents("$tmp/enc"); $x = $raw; $x[strlen($x) - 100] = chr(ord($x[strlen($x) - 100]) ^ 1); file_put_contents("$tmp/enc_t", $x);
t('bit flip detected', (bool) throws(fn() => isbk_decrypt_file("$tmp/enc_t", "$tmp/x", $sa)));
file_put_contents("$tmp/enc_trunc", substr($raw, 0, strlen($raw) - 5000)); t('truncation detected', (bool) throws(fn() => isbk_decrypt_file("$tmp/enc_trunc", "$tmp/x", $sa)));
file_put_contents("$tmp/enc_cut", substr($raw, 0, 1048600)); t('cut at chunk boundary detected', (bool) throws(fn() => isbk_decrypt_file("$tmp/enc_cut", "$tmp/x", $sa)));
t('non-recipient key rejected', (bool) throws(fn() => isbk_decrypt_file("$tmp/enc", "$tmp/x", isbk_load_secret("$tmp/C.key", 'passphrase-C-789'))));
t('wrong passphrase rejected', (bool) throws(fn() => isbk_load_secret("$tmp/A.key", 'bad')));
$kBsecret = isbk_load_secret("$tmp/B.key", 'passphrase-B-456'); $two = isbk_encrypt_file("$tmp/plain", "$tmp/enc2", [$kA['public'], $kB['public']]); isbk_decrypt_file("$tmp/enc2", "$tmp/dec2", $kBsecret); t('second owner can decrypt', hash_file('sha256', "$tmp/plain") === hash_file('sha256', "$tmp/dec2"));

echo "== backup run 1 (full)\n";
$r1 = isbk_backup_run($b); t('run ok and shipped', $r1['ok'] && !$r1['pending_parts'] && !$r1['unshipped_runs'], "files {$r1['files_new']} new");
t('local parts removed after shipping', !glob("$store/backup/parts/*.bak"));
t('multiple parts created (1 MB chunking)', count(glob("$off/parts/*.bak")) >= 2, count(glob("$off/parts/*.bak")) . ' parts');
echo "== staging restore\n";
$runs = isbk_runs($off); $run = array_key_last($runs);
$core = "$off/runs/$run/core.bak"; isbk_decrypt_file($core, "$tmp/core.zip", $sa);
$z = new ZipArchive(); $z->open("$tmp/core.zip"); $z->extractTo("$tmp/core"); $z->close();
$dst = array_merge($base, ['db' => $dstDb]);
$found = isbk_import_db($dst, $dstDb, "$tmp/core/db/db-portal.sql.gz");
$q = fn($db, $t) => isbk_pdo($base, $db)->query("CHECKSUM TABLE $t")->fetch(PDO::FETCH_NUM)[1];
t('table a identical (checksum) incl. quotes/newlines/binary/NULL/unicode', $q($srcDb, 'a') === $q($dstDb, 'a'));
t('table b identical with foreign keys', $q($srcDb, 'b') === $q($dstDb, 'b'));
t('row counts match meta', $found['a'] === 450 && $found['b'] === 2);
$row = isbk_pdo($base, $dstDb)->query('SELECT txt FROM a WHERE id=8')->fetchColumn(); t('nasty string restored exactly', $row === $nasty);

echo "== incremental run 2\n";
$f4 = $mk(bin2hex(random_bytes(20)), 70000); unlink($f1); sleep(1);
$p->exec("INSERT INTO a (txt) VALUES ('after run 1')");
$r2 = isbk_backup_run($b); t('only the new file was archived', $r2['files_new'] === 1 && $r2['files_total'] === 3, "files_total {$r2['files_total']}");
$m2 = isbk_runs($off); $run2 = array_key_last($m2);
t('run 2 still references run 1 parts (kept for restore)', count(array_intersect($m2[$run2]['parts_used'], $m2[$run]['parts_used'])) >= 1);

echo "== destination outage and recovery\n";
$bad = $b; $bad['destinations'] = [['type' => 'folder', 'path' => "$tmp/readonly/off"]]; mkdir("$tmp/readonly", 0555);
$f5 = $mk(bin2hex(random_bytes(20)), 1000); $r3 = isbk_backup_run($bad);
if (DIRECTORY_SEPARATOR === '\\' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) { t('(skipped outage test: needs a read-only folder, not possible on Windows/root)', true); }
else {
    t('failed shipping reported, nothing lost', !$r3['ok'] && $r3['unshipped_runs'] >= 1 && (bool) glob("$store/backup/parts/*.bak"));
    chmod("$tmp/readonly", 0755); $r4 = isbk_backup_run($bad); t('next run ships the backlog', $r4['ok'] && !$r4['pending_parts'] && !$r4['unshipped_runs']);
}

echo "== retention\n";
$all = ['20260101-000000', '20260102-000000', '20260103-000000', '20260104-000000', '20260105-000000', '20260201-000000', '20250101-000000'];
$fake = array_fill_keys($all, []); $keep = isbk_keep_set($fake, ['daily' => 3, 'weekly' => 1, 'monthly' => 1]);
t('GFS keeps newest 3 days + latest week + latest month', in_array('20260201-000000', $keep, true) && in_array('20260105-000000', $keep, true) && !in_array('20250101-000000', $keep, true), implode(',', $keep));

echo "== dump trust\n";
$gz = "$tmp/core/db/db-portal.sql.gz"; $raw = file_get_contents($gz); file_put_contents("$tmp/trunc.sql.gz", substr($raw, 0, (int) (strlen($raw) * 0.6)));
t('truncated dump is not trusted', !@isbk_dump_complete("$tmp/trunc.sql.gz"));
t('importing truncated dump refuses', (bool) @throws(fn() => isbk_import_db($dst, $dstDb, "$tmp/trunc.sql.gz")));

foreach ([$srcDb, $dstDb] as $d) { $adm->exec('DROP DATABASE IF EXISTS ' . isbk_q($d)); }
@chmod("$tmp/readonly", 0755); isbk_rrmdir($tmp);
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
