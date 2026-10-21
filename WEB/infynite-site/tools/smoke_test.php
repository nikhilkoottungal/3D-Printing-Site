<?php
/**
 * End-to-end smoke test of the whole portal. Portable: needs only PHP (curl + pdo_mysql + zip) and a MySQL/MariaDB user
 * that may create databases named infynite_smoke*. It builds a THROW-AWAY database and storage folder, starts the PHP
 * built-in server on 127.0.0.1:8099 with INFYNITE_CONFIG pointing at a temp config, runs the scenarios, then cleans up.
 *   php tools/smoke_test.php [--keep]        (uses the db credentials from app/config.php)
 * Exit code 0 = everything passed.
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
require __DIR__ . '/lib/isbk.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $i = ''): void { global $pass, $fail; echo ($ok ? '  PASS  ' : '  FAIL  ') . $n . ($i !== '' ? "  ($i)" : '') . "\n"; $ok ? $pass++ : $fail++; }

class Client
{
    public string $jar; public string $base;
    function __construct(string $base) { $this->base = $base; $this->jar = tempnam(sys_get_temp_dir(), 'ck'); }
    function req(string $method, string $path, array $post = [], bool $follow = false): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_TIMEOUT => 60]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
        $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        $hdr = substr((string) $raw, 0, $hs); $body = substr((string) $raw, $hs);
        preg_match('/^Location:\s*(\S+)/mi', $hdr, $m);
        return ['code' => $code, 'body' => $body, 'loc' => $m[1] ?? null];
    }
    function get(string $p): array { return $this->req('GET', $p); }
    function token(string $page): string { return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $this->get($page)['body'], $m) ? $m[1] : ''; }
    function post(string $p, array $d, ?string $page = null): array { $d['_csrf'] = $this->token($page ?? $p); return $this->req('POST', $p, $d); }
}
function file_part(string $path, string $name): CURLFile { return new CURLFile($path, 'application/octet-stream', $name); }

/* ---------- environment ---------- */
$cfgReal = isbk_config(); $base = $cfgReal['databases'][0];
$dbName = 'infynite_smoke'; $tmp = sys_get_temp_dir() . '/infynite-smoke-' . bin2hex(random_bytes(3)); isbk_mkdir("$tmp/storage/uploads"); isbk_mkdir("$tmp/storage/logs");
$adm = isbk_pdo($base, 'information_schema');
$adm->exec("DROP DATABASE IF EXISTS `$dbName`"); $adm->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4");
$pdo = isbk_pdo($base, $dbName); $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$schema = file_get_contents(dirname(__DIR__) . '/sql/schema.sql');
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $schema)))) as $sql) { $pdo->exec($sql); }
$app = require dirname(__DIR__) . '/app/config.php';
$cfg = $app; $cfg['db']['name'] = $dbName; $cfg['storage'] = "$tmp/storage"; $cfg['force_https'] = false; $cfg['mail']['log_only'] = true;
$cfg['setup_token'] = 'smoke-test-setup-token-123456'; $cfg['base_url'] = 'http://127.0.0.1:8099'; $cfg['dolibarr'] = ['url' => '', 'api_key' => '', 'ui_url' => '', 'vat_rate' => 0]; unset($cfg['backup']);
file_put_contents("$tmp/config.php", "<?php\nreturn " . var_export($cfg, true) . ";\n");
$mailLog = "$tmp/storage/logs/mail.log";

/* ---------- start server ---------- */
if ($c = @fsockopen('127.0.0.1', 8099, $en0, $es0, 0.3)) { fclose($c); echo "port 8099 is already in use - stop that program first (a leftover test server?)\n"; exit(1); }
$env = array_merge(getenv(), ['INFYNITE_CONFIG' => "$tmp/config.php"]);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:8099', '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'], [0 => ['pipe', 'r'], 1 => ['file', "$tmp/server.log", 'w'], 2 => ['file', "$tmp/server.log", 'a']], $pipes, dirname(__DIR__), $env);
$up = false; for ($i = 0; $i < 50 && !$up; $i++) { usleep(100000); $c = @fsockopen('127.0.0.1', 8099, $en, $es, 0.2); if ($c) { fclose($c); $up = true; } }
if (!$up) { echo "server did not start\n"; exit(1); }
$B = 'http://127.0.0.1:8099';

try {
    $anon = new Client($B);
    echo "== public pages\n";
    foreach (['/', '/services', '/materials', '/gallery', '/contact', '/privacy', '/terms', '/login', '/register'] as $p) { t("GET $p", $anon->get($p)['code'] === 200); }
    t('static CSS served', $anon->get('/assets/style.css')['code'] === 200);
    t('storage/app not web reachable', in_array($anon->get('/storage/uploads/')['code'], [404, 403], true) && in_array($anon->get('/app/config.php')['code'], [404, 403], true));
    t('POST without CSRF token rejected', $anon->req('POST', '/login', ['email' => 'x'])['code'] === 419);

    echo "== setup\n";
    $o = new Client($B);
    t('setup rejects wrong token', str_contains($o->post('/setup', ['setup_token' => 'wrong-token-wrong-token-1', 'name' => 'O', 'email' => 'owner@smoke.test', 'password' => 'ownerpassword1'])['body'], 'Setup token is wrong'));
    $r = $o->post('/setup', ['setup_token' => 'smoke-test-setup-token-123456', 'name' => 'Owner', 'email' => 'owner@smoke.test', 'password' => 'ownerpassword1']);
    t('owner created', $r['code'] === 302);
    t('setup page now gone', $anon->get('/setup')['code'] === 404);
    $cnt = fn($sql) => (int) $pdo->query($sql)->fetchColumn();
    t('starter content seeded', $cnt('SELECT COUNT(*) FROM is_kb_articles') >= 10 && $cnt('SELECT COUNT(*) FROM is_tasks') >= 10 && $cnt('SELECT COUNT(*) FROM is_inventory_items') >= 5);
    t('owner login', ($x = $o->post('/login', ['email' => 'owner@smoke.test', 'password' => 'ownerpassword1']))['code'] === 302 && $x['loc'] === '/staff');

    echo "== customers\n";
    $cust = [];
    foreach (['a', 'b'] as $k) {
        $c = new Client($B); $cust[$k] = $c;
        $c->post('/register', ['name' => "Cust $k", 'email' => "$k@smoke.test", 'password' => "custpassword$k", 'terms' => '1']);
        $log = (string) @file_get_contents($mailLog); $parts = explode("To: $k@smoke.test", $log);
        preg_match('#/verify\?token=([a-f0-9]{64})#', end($parts), $m);
        $c->get('/verify?token=' . ($m[1] ?? 'x'));
        $r = $c->post('/login', ['email' => "$k@smoke.test", 'password' => "custpassword$k"]);
        t("customer $k registers, verifies, logs in", $r['code'] === 302 && $r['loc'] === '/account');
    }
    t('customer cannot open staff area', $cust['a']->get('/staff')['code'] === 403);
    t('wrong password rejected', str_contains((new Client($B))->post('/login', ['email' => 'a@smoke.test', 'password' => 'nope'])['body'], 'Incorrect'));

    echo "== uploads\n";
    $good = "$tmp/good.stl"; file_put_contents($good, str_repeat("\0", 80) . pack('V', 1) . str_repeat("\0", 50));
    $z = new ZipArchive(); $z->open("$tmp/good.3mf", ZipArchive::CREATE); $z->addFromString('[Content_Types].xml', '<x/>'); $z->addFromString('3D/3dmodel.model', '<m/>'); $z->close();
    $z = new ZipArchive(); $z->open("$tmp/trav.3mf", ZipArchive::CREATE); $z->addFromString('[Content_Types].xml', '<x/>'); $z->addFromString('3D/3dmodel.model', '<m/>'); $z->addFromString('../evil', 'x'); $z->close();
    file_put_contents("$tmp/bad.stl", 'hello, not an stl'); file_put_contents("$tmp/mz.stl", 'MZ' . str_repeat("\0", 100));
    $a = $cust['a'];
    $tok = $a->token('/requests/new');
    $r = $a->req('POST', '/requests/new', ['_csrf' => $tok, 'material' => 'PLA', 'color' => 'Black', 'quantity' => 2, 'notes' => 'n', 'files[0]' => file_part($good, 'good.stl'), 'files[1]' => file_part("$tmp/good.3mf", 'good.3mf')]);
    t('valid STL + 3MF accepted', $r['code'] === 302 && preg_match('#/requests/(\d+)#', (string) $r['loc'], $m), (string) $r['loc']);
    $rid = (int) ($m[1] ?? 0);
    $before = $cnt('SELECT COUNT(*) FROM is_requests');
    $tok = $a->token('/requests/new');
    $r = $a->req('POST', '/requests/new', ['_csrf' => $tok, 'material' => 'PLA', 'color' => 'Black', 'quantity' => 1, 'files[0]' => file_part("$tmp/bad.stl", 'bad.stl'), 'files[1]' => file_part("$tmp/mz.stl", 'x.stl'), 'files[2]' => file_part("$tmp/trav.3mf", 'trav.3mf'), 'files[3]' => file_part($good, 'virus.exe')]);
    t('4 invalid files rejected, nothing created', $r['code'] === 200 && substr_count($r['body'], '<li>') >= 4 && $cnt('SELECT COUNT(*) FROM is_requests') === $before);
    $fid = $cnt('SELECT id FROM is_request_files ORDER BY id LIMIT 1');
    t('stored file name is random hex, not the original', (bool) preg_match('/^[a-f0-9]{40}$/', (string) $pdo->query('SELECT stored_name FROM is_request_files LIMIT 1')->fetchColumn()));

    echo "== isolation\n";
    $b = $cust['b'];
    t('B cannot view A order', $b->get("/requests/$rid")['code'] === 404);
    t('B cannot download A file', $b->get("/files/$fid")['code'] === 404);
    t('A can download own file', $a->get("/files/$fid")['code'] === 200);
    t('anonymous download redirected to login', (new Client($B))->get("/files/$fid")['code'] === 302);
    t('B cannot approve A quote', $b->post("/requests/$rid/approve", ['quote_amount' => '1'], '/account')['code'] === 404);

    echo "== quote -> order flow\n";
    $r = $o->post("/staff/requests/$rid", ['action' => 'quote', 'amount' => '1850', 'quote_note' => 'incl. supports ₹'], "/staff/requests/$rid");
    t('staff sends quote', $r['code'] === 302 && $pdo->query("SELECT status FROM is_requests WHERE id=$rid")->fetchColumn() === 'quoted');
    t('customer approves (stale price blocked, right price accepted)', $a->post("/requests/$rid/approve", ['quote_amount' => '9'], "/requests/$rid")['code'] === 302 && $pdo->query("SELECT status FROM is_requests WHERE id=$rid")->fetchColumn() === 'quoted'
        && $a->post("/requests/$rid/approve", ['quote_amount' => '1850.00'], "/requests/$rid")['code'] === 302 && $pdo->query("SELECT status FROM is_requests WHERE id=$rid")->fetchColumn() === 'approved');
    foreach (['scheduled', 'printing', 'quality_check', 'dispatched', 'completed'] as $s) { $o->post("/staff/requests/$rid", ['action' => 'status', 'status' => $s, 'note' => ''], "/staff/requests/$rid"); }
    t('staff moves order to completed', $pdo->query("SELECT status FROM is_requests WHERE id=$rid")->fetchColumn() === 'completed');

    echo "== staff workspace\n";
    foreach (['/staff', '/staff/requests', "/staff/requests/$rid", '/staff/tasks', '/staff/tasks?view=board', '/staff/kb', '/staff/team', '/staff/inventory', '/staff/backups', '/staff/requests/new'] as $p) { t("owner GET $p", $o->get($p)['code'] === 200); }
    $r = $o->post('/staff/tasks/new', ['title' => 'Smoke task', 'description' => '- [ ] one', 'priority' => 'high', 'due_date' => '2026-12-01', 'assignee_id' => (string) $cnt("SELECT id FROM is_users WHERE email='owner@smoke.test'")], '/staff/tasks/new');
    $tid = (int) basename((string) $r['loc']); t('task created', $tid > 0);
    $o->post("/staff/tasks/$tid/comment", ['body' => 'hello <b>x</b>'], "/staff/tasks/$tid");
    $o->post("/staff/tasks/$tid", ['title' => 'Smoke task', 'description' => 'd', 'priority' => 'high', 'due_date' => '2026-12-01', 'status' => 'in_progress', 'assignee_id' => ''], "/staff/tasks/$tid");
    $page = $o->get("/staff/tasks/$tid")['body'];
    t('comment HTML-escaped', str_contains($page, '&lt;b&gt;x') && !str_contains($page, '<b>x</b>'));
    t('activity log records status change and reassignment', str_contains($page, 'changed status') && str_contains($page, 'reassigned'));
    $r = $o->post('/staff/kb/new', ['title' => 'Smoke proc', 'category' => 'Test', 'kind' => 'procedure', 'body' => "# H\n- [ ] a\n[x](javascript:alert(1))"], '/staff/kb/new');
    $slug = basename((string) $r['loc']); t('procedure created', $r['code'] === 302);
    $o->post("/staff/kb/$slug/edit", ['title' => 'Smoke proc', 'category' => 'Test', 'kind' => 'procedure', 'body' => 'v2', 'note' => 'n'], "/staff/kb/$slug/edit");
    t('procedure versioned', $cnt("SELECT version FROM is_kb_articles WHERE slug='$slug'") === 2 && $o->get("/staff/kb/$slug/history?v=1")['code'] === 200);
    t('javascript: link not rendered', !str_contains($o->get("/staff/kb/$slug/history?v=1")['body'], 'href="javascript'));
    t('task from procedure', $o->post("/staff/kb/$slug/task", ['reference' => 'IS-1', 'assignee_id' => ''], "/staff/kb/$slug")['code'] === 302);
    $r = $o->post('/staff/team/new', ['name' => 'Sam', 'email' => 'sam@smoke.test', 'role' => 'production'], '/staff/team'); t('staff member added', $r['code'] === 302 && str_contains($o->get('/staff/team')['body'], '/reset?token='));
    $log = (string) @file_get_contents($mailLog); $parts = explode('To: sam@smoke.test', $log); preg_match('#/reset\?token=([a-f0-9]{64})#', end($parts), $m);
    $sam = new Client($B); $sam->post('/reset', ['token' => $m[1] ?? 'x', 'password' => 'sampassword12'], '/reset?token=' . ($m[1] ?? 'x'));
    t('staff sets password via link and logs in', $sam->post('/login', ['email' => 'sam@smoke.test', 'password' => 'sampassword12'])['loc'] === '/staff');
    t('production role cannot see Backups or create items', $sam->get('/staff/backups')['code'] === 403 && $sam->get('/staff/inventory/new')['code'] === 403);

    echo "== inventory\n";
    $r = $o->post('/staff/inventory/new', ['name' => 'Nozzle 0.4', 'category' => 'spare', 'unit' => 'pcs', 'qty' => '10', 'reorder_level' => '3'], '/staff/inventory/new'); $iid = (int) basename((string) $r['loc']);
    $qty = fn() => (string) $pdo->query("SELECT qty FROM is_inventory_items WHERE id=$iid")->fetchColumn();
    $mv = fn($c, $reason, $amt) => $c->post("/staff/inventory/$iid/move", ['reason' => $reason, 'amount' => $amt], "/staff/inventory/$iid");
    $mv($o, 'use', '4'); t('use 4 -> 6', $qty() === '6.00');
    $mv($o, 'use', '100'); t('cannot go negative', $qty() === '6.00');
    $mv($o, 'purchase', '10'); $mv($o, 'adjust', '15'); t('purchase and stock-take', $qty() === '15.00');
    $mv($sam, 'use', '1'); t('production can use stock', $qty() === '14.00');
    t('production cannot purchase', $mv($sam, 'purchase', '5')['code'] === 403);

    echo "== direct order\n";
    $r = $o->req('POST', '/staff/requests/new', ['_csrf' => $o->token('/staff/requests/new'), 'customer_id' => '', 'c_name' => 'Ravi Walkin', 'c_phone' => '98450 12345', 'c_email' => '', 'material' => 'PETG', 'color' => 'Grey', 'quantity' => 5, 'notes' => 'phone order', 'amount' => '2500', 'status' => 'approved', 'assignee_id' => (string) $cnt("SELECT id FROM is_users WHERE email='sam@smoke.test'"), 'files[0]' => file_part($good, 'part.stl')]);
    $did = (int) basename((string) $r['loc']);
    t('walk-in direct order created with file and task', $r['code'] === 302 && $cnt("SELECT COUNT(*) FROM is_request_files WHERE request_id=$did") === 1 && $cnt("SELECT COUNT(*) FROM is_tasks WHERE request_id=$did") === 1
        && $pdo->query("SELECT source FROM is_requests WHERE id=$did")->fetchColumn() === 'direct');
    t('quoted without price rejected', str_contains($o->post('/staff/requests/new', ['customer_id' => (string) $cnt("SELECT id FROM is_users WHERE email='b@smoke.test'"), 'material' => 'PLA', 'color' => 'Black', 'quantity' => 1, 'status' => 'quoted', 'amount' => ''])['body'], 'agreed price'));
    t('production cannot start order as Approved', str_contains($sam->post('/staff/requests/new', ['customer_id' => '', 'c_name' => 'X', 'material' => 'PLA', 'color' => 'Black', 'quantity' => 1, 'amount' => '10', 'status' => 'approved'])['body'], 'cannot start'));
    t('customer cannot reach direct-order form', $cust['a']->get('/staff/requests/new')['code'] === 403);
    t('customer data export works', $cust['a']->get('/account/export')['code'] === 200);
} finally {
    proc_terminate($proc); proc_close($proc);
    if (!isset($argv) || !in_array('--keep', $argv, true)) { $adm->exec("DROP DATABASE IF EXISTS `$dbName`"); isbk_rrmdir($tmp); } else { echo "kept: $tmp and database $dbName\n"; }
}
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
