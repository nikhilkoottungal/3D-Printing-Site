<?php
/**
 * Phase 0 hosting probe. Reports facts about the hosting account WITHOUT printing any secret (no env vars, no passwords,
 * no config contents). Use it two ways, on the real Hostinger account:
 *
 *  A) WEB (shows the web PHP, which is what the site will really use):
 *       1. copy this file to  public_html/phase0-probe.php  (the public web folder)
 *       2. edit $PROBE_TOKEN below to a long random string
 *       3. open  https://<your-domain>/phase0-probe.php?token=<that string>   and save the text
 *       4. DELETE the file from the server immediately afterwards
 *  B) CLI / CRON (shows the PHP used by cron jobs and SSH; may differ from web PHP):
 *       php phase0_probe.php            (add --mail-to=you@example.com to send one test e-mail)
 *       as a cron job every minute for a few minutes:  php /full/path/phase0_probe.php --cron-log=/full/path/probe-cron.log
 *     The cron log proves that cron works, the smallest interval, and which PHP version cron uses.
 *  Optional DB test (CLI): DB credentials come from env vars PROBE_DB_HOST / PROBE_DB_NAME / PROBE_DB_USER / PROBE_DB_PASS
 *  (set only for that command; nothing is printed except success/failure and server facts).
 */
$PROBE_TOKEN = 'CHANGE-ME';          // web mode only

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8'); header('X-Robots-Tag: noindex'); header('Cache-Control: no-store');
    if ($PROBE_TOKEN === 'CHANGE-ME' || strlen($PROBE_TOKEN) < 20) { http_response_code(403); exit("Edit \$PROBE_TOKEN in this file first (20+ random characters). Then delete the file after use.\n"); }
    if (!hash_equals($PROBE_TOKEN, (string) ($_GET['token'] ?? ''))) { http_response_code(403); exit("Forbidden\n"); }
}
$opts = [];
if ($cli) { foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) { $opts[$m[1]] = $m[2]; } } }

$problems = []; $warnings = []; $facts = [];
function line(string $s = ''): void { echo $s . "\n"; }
function head(string $s): void { echo "\n== $s\n"; }
function row(string $k, $v): void { echo str_pad($k, 40) . ' ' . (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v) . "\n"; }
function fn_ok(string $f): bool { $d = array_map('trim', explode(',', (string) ini_get('disable_functions'))); return function_exists($f) && !in_array($f, $d, true); }
function bytes($v): int { $v = trim((string) $v); if ($v === '' || $v === '-1') { return PHP_INT_MAX; } $n = (int) $v; switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; } return $n; }
function hb(float $b): string { return $b >= 1073741824 ? round($b / 1073741824, 2) . ' GB' : round($b / 1048576, 1) . ' MB'; }

line('Infynite Phase 0 hosting probe  ' . gmdate('Y-m-d H:i:s') . ' UTC  (' . ($cli ? 'CLI/cron' : 'WEB') . ' mode)');

if ($cli && isset($opts['cron-log'])) { @file_put_contents($opts['cron-log'], gmdate('c') . ' PHP ' . PHP_VERSION . ' ' . PHP_SAPI . ' user=' . (function_exists('get_current_user') ? get_current_user() : '?') . "\n", FILE_APPEND); line('cron log line appended to ' . $opts['cron-log']); }

head('PHP');
row('version', PHP_VERSION); row('SAPI', PHP_SAPI . ' / ' . php_sapi_name()); row('OS', PHP_OS_FAMILY . ' ' . php_uname('r'));
row('server software', $_SERVER['SERVER_SOFTWARE'] ?? 'n/a (cli)'); row('memory_limit', ini_get('memory_limit')); row('max_execution_time', ini_get('max_execution_time'));
row('upload_max_filesize', ini_get('upload_max_filesize')); row('post_max_size', ini_get('post_max_size')); row('max_file_uploads', ini_get('max_file_uploads')); row('max_input_vars', ini_get('max_input_vars'));
row('file_uploads', (bool) ini_get('file_uploads')); row('upload_tmp_dir writable', is_writable(ini_get('upload_tmp_dir') ?: sys_get_temp_dir()));
row('open_basedir', ini_get('open_basedir') ?: '(none)'); row('disable_functions', ini_get('disable_functions') ?: '(none)'); row('date.timezone', ini_get('date.timezone') ?: date_default_timezone_get());
row('opcache enabled', function_exists('opcache_get_status') && ini_get('opcache.enable'));
if (version_compare(PHP_VERSION, '8.1.0', '<')) { $problems[] = 'PHP is older than 8.1 (the portal needs 8.1+). Select a newer PHP in hPanel.'; }
if (bytes(ini_get('upload_max_filesize')) < 32 * 1048576 || bytes(ini_get('post_max_size')) < 40 * 1048576) { $warnings[] = 'upload_max_filesize/post_max_size below 32M/40M: the planned 25 MB upload cap needs them raised (hPanel > PHP Configuration).'; }
if (bytes(ini_get('memory_limit')) < 128 * 1048576) { $warnings[] = 'memory_limit below 128M (Dolibarr likes 256M).'; }
if (!ini_get('file_uploads')) { $problems[] = 'file_uploads is disabled.'; }

head('Extensions (needed: R = required, O = optional)');
$ext = ['pdo_mysql' => 'R', 'mysqli' => 'R(Dolibarr)', 'mbstring' => 'R', 'zip' => 'R', 'curl' => 'R', 'json' => 'R', 'zlib' => 'R', 'sodium' => 'R(backups)', 'openssl' => 'R', 'ctype' => 'R', 'gd' => 'R(Dolibarr)', 'intl' => 'R(Dolibarr)', 'xml' => 'R(Dolibarr)', 'dom' => 'O', 'fileinfo' => 'O', 'iconv' => 'O', 'calendar' => 'O(Dolibarr)', 'ftp' => 'O'];
foreach ($ext as $e => $need) { $has = extension_loaded($e); row("$e [$need]", $has ? 'present' : 'MISSING'); if (!$has && $need[0] === 'R') { $problems[] = "PHP extension '$e' missing ($need)."; } }

head('Filesystem and disk');
$docroot = (string) (($_SERVER['DOCUMENT_ROOT'] ?? '') ?: (realpath(__DIR__) ?: __DIR__));
row('document root', $docroot ?: '?'); row('this script dir', realpath(__DIR__)); row('current user', function_exists('get_current_user') ? get_current_user() : '?');
$tryDirs = ['parent of document root' => dirname($docroot), 'two levels above document root' => dirname($docroot, 2), 'system temp' => sys_get_temp_dir()];
$aboveOk = false;
foreach ($tryDirs as $label => $d) {
    $f = rtrim($d, '/\\') . '/_probe_' . bin2hex(random_bytes(4)); $ok = @file_put_contents($f, 'x') !== false; if ($ok) { @unlink($f); }
    row("can create files in $label", $ok); row('  path', $d);
    if ($ok && $label !== 'system temp') { $aboveOk = true; }
}
if (!$aboveOk) { $problems[] = 'Cannot write ABOVE the public web folder, so private upload storage outside the document root is not possible. Per the brief: STOP before accepting uploads and agree an alternative.'; }
$free = @disk_free_space($docroot ?: '.'); $tot = @disk_total_space($docroot ?: '.');
row('disk free (filesystem)', $free === false ? 'n/a' : hb($free)); row('disk total (filesystem)', $tot === false ? 'n/a' : hb($tot));
line('(The real account quota, e.g. 10 GB, may differ from the filesystem numbers: read it in hPanel and note whether DB/mail/backups count.)');
$sz = 0; $n = 0; if ($docroot && is_dir($docroot)) { try { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docroot, FilesystemIterator::SKIP_DOTS)) as $fi) { $n++; if ($fi->isFile()) { $sz += $fi->getSize(); } if ($n > 200000) { break; } } } catch (Throwable $e) {} }
row('files under document root (inodes)', $n . ($n > 200000 ? '+' : '')); row('size under document root', hb($sz));

head('Functions and tools');
foreach (['exec', 'shell_exec', 'proc_open', 'popen', 'passthru', 'mail', 'curl_init', 'gzopen', 'sodium_crypto_secretstream_xchacha20poly1305_keygen', 'ftp_connect'] as $f) { row($f . '()', fn_ok($f)); }
if (fn_ok('exec')) {
    foreach (['mysqldump --version', 'mysql --version', 'rclone version', 'tar --version', 'zip -v', 'git --version', 'php -v'] as $c) { $o = []; $rc = 1; @exec($c . ' 2>&1', $o, $rc); row("`$c`", $rc === 0 ? trim(explode("\n", implode("\n", $o))[0]) : 'not available'); }
} else { $facts[] = 'exec() unavailable: backups must use the pure-PHP tools (they do), rclone/mysqldump cannot be run from the host.'; line('exec() is disabled: tools such as mysqldump/rclone cannot run from PHP on this host.'); }

head('Outbound network (HTTPS)');
if (function_exists('curl_init')) {
    foreach (['https://www.google.com/generate_204', 'https://www.googleapis.com/discovery/v1/apis', 'https://api.github.com/', 'https://downloads.sourceforge.net/'] as $u) {
        $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => 'phase0-probe']);
        curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        row($u, $code ? "HTTP $code" : 'FAILED ' . $err);
        if (!$code && strpos($u, 'googleapis') !== false) { $warnings[] = 'No outbound HTTPS to Google APIs from PHP: a direct host->Drive upload would not work (PC-side rclone still fine).'; }
    }
}
row('can resolve DNS', gethostbyname('www.google.com') !== 'www.google.com');

head('Web / TLS');
if (!$cli) { row('HTTPS on this request', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'); row('X-Forwarded-Proto', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '(none)'); row('HTTP_HOST', $_SERVER['HTTP_HOST'] ?? '?'); row('REMOTE_ADDR seen', $_SERVER['REMOTE_ADDR'] ?? '?'); row('.htaccess processed by server (LiteSpeed/Apache)', stripos($_SERVER['SERVER_SOFTWARE'] ?? '', 'litespeed') !== false || stripos($_SERVER['SERVER_SOFTWARE'] ?? '', 'apache') !== false); if (empty($_SERVER['HTTPS']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') { $warnings[] = 'This page was NOT loaded over HTTPS: enable the SSL certificate and forced HTTPS before launch.'; } } else { line('(web-only section: run the WEB mode to see TLS facts)'); }

head('Database (optional; credentials from env vars, never printed)');
$h = getenv('PROBE_DB_HOST') ?: null; $dn = getenv('PROBE_DB_NAME') ?: null; $du = getenv('PROBE_DB_USER') ?: null; $dp = getenv('PROBE_DB_PASS');
if ($h && $dn && $du && $dp !== false && extension_loaded('pdo_mysql')) {
    try {
        $pdo = new PDO("mysql:host=$h;dbname=$dn;charset=utf8mb4", $du, $dp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
        row('connect', 'OK'); row('server version', $pdo->query('SELECT VERSION()')->fetchColumn()); row('max_connections', $pdo->query('SELECT @@max_connections')->fetchColumn()); row('max_allowed_packet', hb((float) $pdo->query('SELECT @@max_allowed_packet')->fetchColumn()));
        row('default charset / collation', $pdo->query('SELECT @@character_set_database')->fetchColumn() . ' / ' . $pdo->query('SELECT @@collation_database')->fetchColumn());
        row('InnoDB available', (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.engines WHERE engine='InnoDB' AND support IN ('YES','DEFAULT')")->fetchColumn());
        row('databases visible to this user', $pdo->query('SHOW DATABASES')->rowCount());
        try { $pdo->exec('CREATE TEMPORARY TABLE _probe (id INT)'); $pdo->exec('DROP TEMPORARY TABLE _probe'); row('can create tables', true); } catch (Throwable $e) { row('can create tables', false); $problems[] = 'DB user cannot create tables.'; }
        row('FOREIGN KEY / transactions test', (bool) $pdo->beginTransaction() && $pdo->rollBack());
    } catch (Throwable $e) { row('connect', 'FAILED: ' . preg_replace('/password[^,;]*/i', 'password=***', $e->getMessage())); $problems[] = 'Database connection failed.'; }
} else { line('(skipped: set PROBE_DB_HOST/NAME/USER/PASS env vars in CLI mode to test. In hPanel also record: how many databases the plan allows and the DB size limit.)'); }

head('E-mail');
row('mail() usable', fn_ok('mail')); row('sendmail_path', ini_get('sendmail_path') ?: '(none)'); row('SMTP / smtp_port', (ini_get('SMTP') ?: '-') . ' / ' . (ini_get('smtp_port') ?: '-'));
if ($cli && !empty($opts['mail-to']) && fn_ok('mail')) { $ok = @mail($opts['mail-to'], 'Phase 0 probe test mail', "If you read this, PHP mail() from the host works.\n", "From: no-reply@localhost\r\n"); row('test mail accepted for delivery', $ok); line('(Accepted is not the same as delivered: check the inbox AND spam, and review SPF/DKIM/DMARC in hPanel > Emails/DNS.)'); }
else { line('(add --mail-to=you@example.com in CLI mode to send one test message)'); }

head('VERDICT');
if (!$problems) { line('No blocking problems found by this probe.'); } else { line('BLOCKING PROBLEMS:'); foreach ($problems as $p) { line(' - ' . $p); } }
if ($warnings) { line('WARNINGS:'); foreach ($warnings as $w) { line(' - ' . $w); } }
foreach ($facts as $f) { line('NOTE: ' . $f); }
line("\nStill to check by hand in hPanel (not visible to PHP): plan name/limits, account disk quota and what it counts, number of databases allowed, cron availability + minimum interval, SSH/SFTP/FTPS access, backups offered by the host, SSL status, e-mail DNS records, resource usage limits.");
if (!$cli) { line("\nREMINDER: delete phase0-probe.php from the server now."); }
