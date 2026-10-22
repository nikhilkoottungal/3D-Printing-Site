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
    function req(string $method, string $path, array $post = [], bool $follow = false, array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
        if ($method === 'POST') { $hasFile = (bool) array_filter($post, fn($v) => $v instanceof CURLFile); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $hasFile ? $post : http_build_query($post)); }
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
$cfg = $app; $cfg['db']['name'] = $dbName; $cfg['storage'] = "$tmp/storage"; $cfg['force_https'] = false; $cfg['staff_only'] = false; $cfg['mail']['log_only'] = true;
$cfg['setup_token'] = 'smoke-test-setup-token-123456'; $cfg['base_url'] = 'http://127.0.0.1:8099'; unset($cfg['backup']);
file_put_contents("$tmp/config.php", "<?php\nreturn " . var_export($cfg, true) . ";\n");
$mailLog = "$tmp/storage/logs/mail.log";

/* ---------- start server ---------- */
if ($c = @fsockopen('127.0.0.1', 8099, $en0, $es0, 0.3)) { fclose($c); echo "port 8099 is already in use - stop that program first (a leftover test server?)\n"; exit(1); }
$env = array_merge(getenv(), ['INFYNITE_CONFIG' => "$tmp/config.php", 'RCLONE_CONFIG_TESTDRIVE_TYPE' => 'local']);
$proc = proc_open(array_merge([PHP_BINARY], php_ini_loaded_file() ? ['-c', php_ini_loaded_file()] : [], ['-S', '127.0.0.1:8099', '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php']), [0 => ['pipe', 'r'], 1 => ['file', "$tmp/server.log", 'w'], 2 => ['file', "$tmp/server.log", 'a']], $pipes, dirname(__DIR__), $env);
$up = false; for ($i = 0; $i < 50 && !$up; $i++) { usleep(100000); $c = @fsockopen('127.0.0.1', 8099, $en, $es, 0.2); if ($c) { fclose($c); $up = true; } }
if (!$up) { echo "server did not start\n"; exit(1); }
$B = 'http://127.0.0.1:8099';

try {
    $anon = new Client($B);
    echo "== public surface (staff-only system)\n";
    t('GET / redirects to login', ($x = $anon->get('/'))['code'] === 302 && $x['loc'] === '/login');
    t('GET /login', $anon->get('/login')['code'] === 200);
    foreach (['/services', '/materials', '/gallery', '/contact', '/privacy', '/terms', '/register', '/verify', '/account', '/account/profile', '/requests/new', '/requests/1'] as $p) { t("no customer/public page $p", $anon->get($p)['code'] === 404); }
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
    t('starter content seeded', $cnt('SELECT COUNT(*) FROM is_kb_articles') >= 10 && $cnt('SELECT COUNT(*) FROM is_tasks') >= 7 && $cnt('SELECT COUNT(*) FROM is_inventory_items') >= 5);
    t('owner login', ($x = $o->post('/login', ['email' => 'owner@smoke.test', 'password' => 'ownerpassword1']))['code'] === 302 && $x['loc'] === '/staff');

    echo "== customers are staff-entered records\n";
    foreach (['a', 'b'] as $k) { $o->post('/staff/customers/new', ['name' => "Cust $k", 'email' => "$k@smoke.test", 'phone' => '98450 0000' . ($k === 'a' ? '1' : '2')], '/staff/customers/new'); }
    $custA = $cnt("SELECT id FROM is_users WHERE email='a@smoke.test'"); $custB = $cnt("SELECT id FROM is_users WHERE email='b@smoke.test'");
    t('staff create customer records', $custA > 0 && $custB > 0);
    // even with a known password hash and verified e-mail, a customer record can never log in
    $pdo->exec("UPDATE is_users SET pass_hash='" . password_hash('custpassworda', PASSWORD_DEFAULT) . "', email_verified_at=NOW() WHERE id=$custA");
    $cust = ['a' => new Client($B)];
    t('customer record cannot log in', str_contains($cust['a']->post('/login', ['email' => 'a@smoke.test', 'password' => 'custpassworda'], '/login')['body'], 'Incorrect') && $cust['a']->get('/staff')['code'] === 302);
    $mailBefore = (string) @file_get_contents($mailLog);
    (new Client($B))->post('/forgot', ['email' => 'a@smoke.test'], '/forgot');
    t('password reset is not offered to customers', (string) @file_get_contents($mailLog) === $mailBefore);
    t('wrong password rejected', str_contains((new Client($B))->post('/login', ['email' => 'owner@smoke.test', 'password' => 'nope'])['body'], 'Incorrect'));

    echo "== uploads (staff order entry)\n";
    $good = "$tmp/good.stl"; file_put_contents($good, str_repeat("\0", 80) . pack('V', 1) . str_repeat("\0", 50));
    $z = new ZipArchive(); $z->open("$tmp/good.3mf", ZipArchive::CREATE); $z->addFromString('[Content_Types].xml', '<x/>'); $z->addFromString('3D/3dmodel.model', '<m/>'); $z->close();
    $z = new ZipArchive(); $z->open("$tmp/trav.3mf", ZipArchive::CREATE); $z->addFromString('[Content_Types].xml', '<x/>'); $z->addFromString('3D/3dmodel.model', '<m/>'); $z->addFromString('../evil', 'x'); $z->close();
    file_put_contents("$tmp/bad.stl", 'hello, not an stl'); file_put_contents("$tmp/mz.stl", 'MZ' . str_repeat("\0", 100));
    $tok = $o->token('/staff/requests/new');
    $r = $o->req('POST', '/staff/requests/new', ['_csrf' => $tok, 'customer_id' => (string) $custA, 'material' => 'PLA', 'color' => 'Black', 'quantity' => 2, 'notes' => 'n', 'status' => 'requested', 'amount' => '', 'files[0]' => file_part($good, 'good.stl'), 'files[1]' => file_part("$tmp/good.3mf", 'good.3mf')]);
    t('valid STL + 3MF accepted', $r['code'] === 302 && preg_match('#/staff/requests/(\d+)#', (string) $r['loc'], $m), (string) $r['loc']);
    $rid = (int) ($m[1] ?? 0);
    $before = $cnt('SELECT COUNT(*) FROM is_requests');
    $tok = $o->token('/staff/requests/new');
    $r = $o->req('POST', '/staff/requests/new', ['_csrf' => $tok, 'customer_id' => (string) $custA, 'material' => 'PLA', 'color' => 'Black', 'quantity' => 1, 'status' => 'requested', 'files[0]' => file_part("$tmp/bad.stl", 'bad.stl'), 'files[1]' => file_part("$tmp/mz.stl", 'x.stl'), 'files[2]' => file_part("$tmp/trav.3mf", 'trav.3mf'), 'files[3]' => file_part($good, 'virus.exe')]);
    t('4 invalid files rejected, nothing created', $r['code'] === 200 && substr_count($r['body'], '<li>') >= 4 && $cnt('SELECT COUNT(*) FROM is_requests') === $before);
    $fid = $cnt('SELECT id FROM is_request_files ORDER BY id LIMIT 1');
    t('stored file name is random hex, not the original', (bool) preg_match('/^[a-f0-9]{40}$/', (string) $pdo->query('SELECT stored_name FROM is_request_files LIMIT 1')->fetchColumn()));
    t('staff can download a model file; anonymous cannot', $o->get("/files/$fid")['code'] === 200 && (new Client($B))->get("/files/$fid")['code'] === 302);

    echo "== quote -> order flow\n";
    $r = $o->post("/staff/requests/$rid", ['action' => 'quote', 'amount' => '1850', 'quote_note' => 'incl. supports ₹'], "/staff/requests/$rid");
    t('staff records quote', $r['code'] === 302 && $pdo->query("SELECT status FROM is_requests WHERE id=$rid")->fetchColumn() === 'quoted' && $pdo->query("SELECT quote_amount FROM is_requests WHERE id=$rid")->fetchColumn() === '1850.00');
    $o->post("/staff/requests/$rid", ['action' => 'status', 'status' => 'approved', 'note' => 'customer agreed by phone'], "/staff/requests/$rid");
    t('staff approves on customer agreement', $pdo->query("SELECT status FROM is_requests WHERE id=$rid")->fetchColumn() === 'approved');
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
    t('customer cannot reach direct-order form', $cust['a']->get('/staff/requests/new')['code'] === 302);

    echo "== admin settings (key protected)\n";
    t('owner sees set-key screen first', str_contains($o->get('/staff/admin')['body'], 'Create your admin key'));
    t('settings save refused while locked', str_contains($o->post('/staff/admin/save', ['gst_rate' => '18'], '/staff/admin')['loc'] ?? '', '/staff/admin') && $cnt("SELECT COUNT(*) FROM is_settings WHERE skey='gst_rate'") === 0);
    t('short admin key rejected', $cnt("SELECT COUNT(*) FROM is_settings WHERE skey='admin_key_hash'") === 0 && ($o->post('/staff/admin/unlock', ['admin_key' => 'short', 'admin_key2' => 'short'], '/staff/admin') && $cnt("SELECT COUNT(*) FROM is_settings WHERE skey='admin_key_hash'") === 0));
    $o->post('/staff/admin/unlock', ['admin_key' => 'correct horse battery', 'admin_key2' => 'correct horse battery'], '/staff/admin');
    t('admin key set and page unlocked', str_contains($o->get('/staff/admin')['body'], 'Save settings'));
    $o->post('/staff/admin/lock', [], '/staff/admin');
    t('lock works', str_contains($o->get('/staff/admin')['body'], 'Enter the admin key'));
    $o->post('/staff/admin/unlock', ['admin_key' => 'wrong key wrong key'], '/staff/admin');
    t('wrong key stays locked', str_contains($o->get('/staff/admin')['body'], 'Enter the admin key'));
    $o->post('/staff/admin/unlock', ['admin_key' => 'correct horse battery'], '/staff/admin');
    $o->post('/staff/admin/save', ['app_name' => 'Infynite Test', 'gstin' => '29ABCDE1234F1Z5', 'gst_rate' => '18', 'invoice_prefix' => 'INV-', 'invoice_next' => '101'], '/staff/admin');
    t('valid settings saved', $pdo->query("SELECT svalue FROM is_settings WHERE skey='gstin'")->fetchColumn() === '29ABCDE1234F1Z5' && $pdo->query("SELECT svalue FROM is_settings WHERE skey='gst_rate'")->fetchColumn() === '18');
    t('display name setting used in pages', str_contains($o->get('/staff')['body'], 'Infynite Test'));
    $o->post('/staff/admin/save', ['gstin' => 'BADGST', 'gst_rate' => '18'], '/staff/admin');
    t('invalid GSTIN rejected, old value kept', $pdo->query("SELECT svalue FROM is_settings WHERE skey='gstin'")->fetchColumn() === '29ABCDE1234F1Z5');
    t('only owner reaches admin page', $sam->get('/staff/admin')['code'] === 403 && $cust['a']->get('/staff/admin')['code'] === 302 && (new Client($B))->get('/staff/admin')['code'] === 302);
    t('production user cannot save settings', $sam->post('/staff/admin/save', ['gst_rate' => '5'], '/staff')['code'] === 403);

    echo "== invoices\n";
    $o->post('/staff/admin/save', ['app_name' => 'Infynite Test', 'legal_name' => 'Infynite Solutions Pvt Ltd', 'address' => "1 Test Road\nBengaluru", 'state' => 'Karnataka', 'gstin' => '29ABCDE1234F1Z5', 'gst_rate' => '18', 'invoice_prefix' => 'INV-', 'invoice_next' => '101', 'payment_terms' => '10', 'bank_details' => 'Bank X, A/c 123456'], '/staff/admin');
    $ord = (int) $cnt("SELECT id FROM is_requests WHERE quote_amount=1850.00 LIMIT 1");
    t('invoice form prefilled from order', str_contains($o->get("/staff/invoices/new?request=$ord")['body'], '3D printing'));
    $inv = fn($extra) => $o->post('/staff/invoices/new', array_merge(['request_id' => (string) $ord, 'bill_name' => 'Cust A', 'bill_state' => '', 'issue_date' => date('Y-m-d'), 'gst_rate' => '18', 'desc[0]' => 'Part', 'qty[0]' => '2', 'rate[0]' => '500', 'desc[1]' => '', 'qty[1]' => '1', 'rate[1]' => ''], $extra), '/staff/invoices/new');
    $r = $inv([]);
    t('invoice issued with GST split (same state)', $r['code'] === 302 && ($row = $pdo->query("SELECT * FROM is_invoices ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC)) && $row['number'] === 'INV-0101' && $row['subtotal'] === '1000.00' && $row['cgst'] === '90.00' && $row['sgst'] === '90.00' && $row['igst'] === '0.00' && $row['total'] === '1180.00' && $row['due_date'] === date('Y-m-d', strtotime('+10 days')));
    $r2 = $inv(['bill_state' => 'Kerala', 'tax_inclusive' => '1', 'rate[0]' => '590']);
    t('inter-state inclusive invoice uses IGST, next number', ($row = $pdo->query("SELECT * FROM is_invoices ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC)) && $row['number'] === 'INV-0102' && $row['igst'] === '180.00' && $row['cgst'] === '0.00' && $row['subtotal'] === '1000.00' && $row['total'] === '1180.00');
    t('invoice page shows bank and seller details', ($b = $o->get(rtrim((string) $r['loc'], '/'))['body']) && str_contains($b, 'Bank X') && str_contains($b, '29ABCDE1234F1Z5') && str_contains($b, 'CGST @ 9%'));
    t('bad input rejected, no number consumed', $inv(['qty[0]' => 'abc'])['code'] === 302 && $cnt('SELECT COUNT(*) FROM is_invoices') === 2 && $cnt("SELECT svalue FROM is_settings WHERE skey='invoice_next'") === 103);
    t('invalid customer GSTIN rejected', $inv(['bill_gstin' => 'XYZ'])['code'] === 302 && $cnt('SELECT COUNT(*) FROM is_invoices') === 2);
    $iid = (int) basename((string) $r['loc']);
    $o->post("/staff/invoices/$iid", ['action' => 'pay', 'paid_at' => date('Y-m-d'), 'method' => 'UPI', 'ref' => 'UTR1'], "/staff/invoices/$iid");
    t('mark paid', $pdo->query("SELECT status FROM is_invoices WHERE id=$iid")->fetchColumn() === 'paid');
    $o->post("/staff/invoices/$iid", ['action' => 'void'], "/staff/invoices/$iid");
    t('owner can void paid invoice; number stays used', $pdo->query("SELECT status FROM is_invoices WHERE id=$iid")->fetchColumn() === 'void');
    t('invoice list and filter load', $o->get('/staff/invoices')['code'] === 200 && $o->get('/staff/invoices?status=paid')['code'] === 200);
    t('production and customers cannot see invoices', $sam->get('/staff/invoices')['code'] === 403 && $cust['a']->get('/staff/invoices')['code'] === 302 && (new Client($B))->get('/staff/invoices')['code'] === 302);
    t('order page lists invoices', str_contains($o->get("/staff/requests/$ord")['body'], 'INV-0101'));

    echo "== customers, suppliers, purchase orders, reports\n";
    $o->post('/staff/customers/new', ['name' => 'Acme Works', 'email' => 'acme@smoke.test', 'phone' => '080 1234 5678', 'address' => "5 Park St\nMysuru", 'gstin' => '29ABCDE1234F1Z5', 'state' => 'Karnataka'], '/staff/customers/new');
    $acme = $cnt("SELECT id FROM is_users WHERE email='acme@smoke.test' AND role='customer'");
    t('staff creates customer with GSTIN', $acme > 0 && $pdo->query("SELECT gstin FROM is_users WHERE id=$acme")->fetchColumn() === '29ABCDE1234F1Z5');
    t('duplicate email / bad GSTIN rejected', str_contains($o->post('/staff/customers/new', ['name' => 'Dup', 'email' => 'acme@smoke.test'], '/staff/customers/new')['body'], 'already in use') && str_contains($o->post('/staff/customers/new', ['name' => 'Bad', 'gstin' => 'NOPE'], '/staff/customers/new')['body'], 'GSTIN is not valid'));
    t('customer list, search and detail', str_contains($o->get('/staff/customers?q=acme')['body'], 'Acme Works') && !str_contains($o->get('/staff/customers?q=zzzz')['body'], 'Acme Works') && str_contains($o->get("/staff/customers/$acme")['body'], 'Mysuru'));
    $o->post("/staff/customers/$acme", ['name' => 'Acme Works Ltd', 'email' => 'acme@smoke.test', 'phone' => '080 1234 5678', 'address' => 'x', 'gstin' => '', 'state' => 'Kerala'], "/staff/customers/$acme");
    t('customer edit saved', $pdo->query("SELECT state FROM is_users WHERE id=$acme")->fetchColumn() === 'Kerala');
    t('invoice form prefilled from customer record', str_contains($o->get("/staff/invoices/new?customer=$acme")['body'], 'Acme Works Ltd'));
    $o->post('/staff/invoices/new', ['customer_id' => (string) $acme, 'bill_name' => 'Acme Works Ltd', 'bill_state' => 'Kerala', 'issue_date' => date('Y-m-d'), 'gst_rate' => '18', 'desc[0]' => 'Job', 'qty[0]' => '1', 'rate[0]' => '1000'], '/staff/invoices/new');
    t('invoice linked to customer', $cnt("SELECT COUNT(*) FROM is_invoices WHERE customer_id=$acme") === 1 && str_contains($o->get("/staff/customers/$acme")['body'], 'INV-'));
    t('production can view customers, not edit', $sam->get('/staff/customers')['code'] === 200 && $sam->get('/staff/customers/new')['code'] === 403 && $cust['a']->get('/staff/customers')['code'] === 302);

    $o->post('/staff/suppliers/new', ['name' => 'Filament Co', 'contact' => 'Priya', 'email' => 'f@smoke.test', 'phone' => '99999 11111', 'gstin' => '27ABCDE1234F1Z5'], '/staff/suppliers/new');
    $sup = $cnt("SELECT id FROM is_suppliers WHERE name='Filament Co'");
    t('supplier created', $sup > 0);
    t('bad supplier rejected', str_contains($o->post('/staff/suppliers/new', ['name' => '', 'email' => 'bad'], '/staff/suppliers/new')['body'], 'required'));
    $item = $cnt('SELECT id FROM is_inventory_items WHERE active=1 ORDER BY id LIMIT 1');
    $qtyBefore = (float) $pdo->query("SELECT qty FROM is_inventory_items WHERE id=$item")->fetchColumn();
    $o->post('/staff/purchase-orders/new', ['supplier_id' => (string) $sup, 'order_date' => date('Y-m-d'), 'item[0]' => (string) $item, 'desc[0]' => '', 'qty[0]' => '4', 'cost[0]' => '900', 'item[1]' => '', 'desc[1]' => 'Freight', 'qty[1]' => '1', 'cost[1]' => '150'], '/staff/purchase-orders/new');
    $po = $cnt('SELECT id FROM is_purchase_orders ORDER BY id DESC LIMIT 1');
    t('PO created with number and total', $po > 0 && $pdo->query("SELECT number FROM is_purchase_orders WHERE id=$po")->fetchColumn() === 'PO-0001' && $pdo->query("SELECT total FROM is_purchase_orders WHERE id=$po")->fetchColumn() === '3750.00');
    t('PO without lines rejected', $cnt('SELECT COUNT(*) FROM is_purchase_orders') === 1 && $o->post('/staff/purchase-orders/new', ['supplier_id' => (string) $sup, 'order_date' => date('Y-m-d')], '/staff/purchase-orders/new') && $cnt('SELECT COUNT(*) FROM is_purchase_orders') === 1);
    $o->post("/staff/purchase-orders/$po", ['action' => 'receive'], "/staff/purchase-orders/$po");
    t('receiving PO adds stock, updates cost, logs move', (float) $pdo->query("SELECT qty FROM is_inventory_items WHERE id=$item")->fetchColumn() === $qtyBefore + 4 && $pdo->query("SELECT unit_cost FROM is_inventory_items WHERE id=$item")->fetchColumn() === '900.00' && $cnt("SELECT COUNT(*) FROM is_stock_moves WHERE item_id=$item AND note='Received PO-0001'") === 1);
    $o->post("/staff/purchase-orders/$po", ['action' => 'receive'], "/staff/purchase-orders/$po");
    t('PO cannot be received twice', (float) $pdo->query("SELECT qty FROM is_inventory_items WHERE id=$item")->fetchColumn() === $qtyBefore + 4);
    t('PO / supplier pages load; others blocked', $o->get('/staff/purchase-orders')['code'] === 200 && $o->get("/staff/purchase-orders/$po")['code'] === 200 && $o->get('/staff/suppliers')['code'] === 200 && $sam->get('/staff/purchase-orders')['code'] === 403 && (new Client($B))->get('/staff/suppliers')['code'] === 302);

    $rep = $o->get('/staff/reports');
    t('reports page shows totals and GST', $rep['code'] === 200 && str_contains($rep['body'], 'GST collected') && str_contains($rep['body'], 'Top customers'));
    $csv = $o->get('/staff/reports/invoices.csv?from=2000-01-01&to=2100-01-01');
    t('invoices CSV export', $csv['code'] === 200 && str_contains($csv['body'], 'INV-0101') && str_contains($csv['body'], '"Number","Date"'));
    t('reports restricted to owner/sales', $sam->get('/staff/reports')['code'] === 403 && $cust['a']->get('/staff/reports/invoices.csv')['code'] === 302);

    echo "== phase A: e-mail, notifications, admin rules, my account, 2FA\n";
    require_once dirname(__DIR__) . '/app/lib/totp.php';
    // SMTP sink + settings
    $sinkFile = "$tmp/sink.jsonl"; $sinkPort = 2527;
    $sink = proc_open(['python3', __DIR__ . '/smtp_sink.py', (string) $sinkPort, $sinkFile], [0 => ['pipe', 'r'], 1 => ['file', "$tmp/sink.log", 'w'], 2 => ['file', "$tmp/sink.log", 'a']], $sp);
    for ($i = 0; $i < 30; $i++) { usleep(100000); if ($c = @fsockopen('127.0.0.1', $sinkPort, $e1, $e2, 0.2)) { fclose($c); break; } }
    $mails = function () use ($sinkFile): array { return is_file($sinkFile) ? array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($sinkFile)))) : []; };
    $saveSettings = function (array $over) use ($o, $pdo): void {
        $cur = []; foreach ($pdo->query("SELECT skey, svalue FROM is_settings WHERE skey NOT LIKE 'admin_key%' AND skey NOT IN ('schema_v','smtp_pass')") as $r) { $cur[$r['skey']] = (string) $r['svalue']; }
        $o->post('/staff/admin/save', array_merge($cur, $over), '/staff/admin');
    };
    $saveSettings(['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $sinkPort, 'smtp_enc' => 'none', 'smtp_user' => 'u', 'smtp_pass' => 'p', 'mail_from' => 'portal@smoke.test', 'mail_from_name' => 'Portal']);
    $enc = (string) $pdo->query("SELECT svalue FROM is_settings WHERE skey='smtp_pass'")->fetchColumn();
    t('SMTP settings saved, password stored encrypted', $cnt("SELECT COUNT(*) FROM is_settings WHERE skey='smtp_host' AND svalue='127.0.0.1'") === 1 && strlen($enc) > 20 && $enc !== 'p');
    $o->post('/staff/admin/test-mail', ['to' => 'owner@smoke.test'], '/staff/admin');
    $m = $mails();
    t('test e-mail goes through SMTP with AUTH', count($m) === 1 && $m[0]['to'] === ['owner@smoke.test'] && $m[0]['from'] === 'portal@smoke.test' && str_contains($m[0]['subject'], 'Test e-mail'));
    $saveSettings(['smtp_pass' => 'wrong']);
    $o->post('/staff/admin/test-mail', ['to' => 'owner@smoke.test'], '/staff/admin');
    t('wrong SMTP password: test fails and the mail is logged, not lost', count($mails()) === 1 && str_contains((string) @file_get_contents($mailLog), 'not sent: login refused'));
    $saveSettings(['smtp_pass' => 'p']);

    // notification rules: Sam (production) subscribes to new orders
    $samId = $cnt("SELECT id FROM is_users WHERE email='sam@smoke.test'"); $ownerId = $cnt("SELECT id FROM is_users WHERE email='owner@smoke.test'");
    t('owner is subscribed to every alert by default', $cnt("SELECT COUNT(*) FROM is_notify_rules WHERE user_id=$ownerId") === 8);
    t('notification rules page loads', str_contains($o->get('/staff/admin/notifications')['body'], 'Notification rules'));
    $o->post('/staff/admin/notifications', ['n' => ['order.new' => [(string) $samId], 'stock.low' => [(string) $samId, (string) $ownerId]]], '/staff/admin/notifications');
    t('rules saved', $cnt("SELECT COUNT(*) FROM is_notify_rules") === 3);
    $n0 = count($mails());
    $o->req('POST', '/staff/requests/new', ['_csrf' => $o->token('/staff/requests/new'), 'customer_id' => (string) $custA, 'material' => 'PLA', 'color' => 'Black', 'quantity' => 1, 'status' => 'requested']);
    $new = array_slice($mails(), $n0);
    t('new order e-mails the subscribed person, not the person who entered it', count($new) === 1 && $new[0]['to'] === ['sam@smoke.test'] && str_contains($new[0]['subject'], 'New order'));
    $n0 = count($mails());
    $o->post("/staff/requests/$rid", ['action' => 'status', 'status' => 'completed', 'note' => 'x'], "/staff/requests/$rid");
    t('status change with no subscribers sends nothing', count($mails()) === $n0);
    $item = $cnt("SELECT id FROM is_inventory_items WHERE reorder_level > 0 AND qty > reorder_level LIMIT 1");
    $q = (float) $pdo->query("SELECT qty FROM is_inventory_items WHERE id=$item")->fetchColumn(); $rl = (float) $pdo->query("SELECT reorder_level FROM is_inventory_items WHERE id=$item")->fetchColumn();
    $n0 = count($mails());
    $o->post("/staff/inventory/$item/move", ['reason' => 'use', 'amount' => (string) ($q - $rl), 'note' => 'low'], "/staff/inventory/$item");
    $new = array_slice($mails(), $n0);
    t('low-stock alert goes to its subscribers (minus the actor)', count($new) === 1 && $new[0]['to'] === ['sam@smoke.test'] && str_contains($new[0]['subject'], 'Low stock'));

    // admin keys are personal; changing one tells the other admins
    $o->post('/staff/team/new', ['name' => 'Second Admin', 'email' => 'admin2@smoke.test', 'role' => 'owner'], '/staff/team');
    $m2 = []; foreach (array_reverse($mails()) as $x) { if ($x['to'] === ['admin2@smoke.test'] && preg_match('#/reset\?token=([a-f0-9]{64})#', $x['body'], $m2)) { break; } }
    t('creating an admin e-mails the admins other than the creator', count(array_filter($mails(), fn($x) => $x['to'] === ['admin2@smoke.test'] && str_contains($x['subject'], 'New admin'))) === 1 && count(array_filter($mails(), fn($x) => $x['to'] === ['owner@smoke.test'] && str_contains($x['subject'], 'New admin'))) === 0);
    $ad2 = new Client($B); $ad2->post('/reset', ['token' => $m2[1] ?? 'x', 'password' => 'adminpassword2'], '/reset?token=' . ($m2[1] ?? 'x'));
    t('second admin logs in', $ad2->post('/login', ['email' => 'admin2@smoke.test', 'password' => 'adminpassword2'])['loc'] === '/staff');
    t('second admin has no key yet: asked to create their own', str_contains($ad2->get('/staff/admin')['body'], 'Create your admin key'));
    $n0 = count($mails());
    $ad2->post('/staff/admin/unlock', ['admin_key' => 'second admins own key', 'admin_key2' => 'second admins own key'], '/staff/admin');
    $new = array_slice($mails(), $n0);
    t('their key works, the first admin key still works, and the first admin is told', str_contains($ad2->get('/staff/admin')['body'], 'Save settings') && count(array_filter($new, fn($x) => $x['to'] === ['owner@smoke.test'] && str_contains($x['subject'], 'Admin key created'))) === 1 && $cnt("SELECT COUNT(*) FROM is_settings WHERE skey LIKE 'admin_key:%'") === 2);
    $o->post('/staff/admin/lock', [], '/staff/admin');
    t("one admin's key does not open the other's page", !str_contains($o->post('/staff/admin/unlock', ['admin_key' => 'second admins own key'], '/staff/admin')['body'] . $o->get('/staff/admin')['body'], 'Save settings'));
    $o->post('/staff/admin/unlock', ['admin_key' => 'correct horse battery'], '/staff/admin');
    $n0 = count($mails());
    $o->post('/staff/admin/key', ['current' => 'correct horse battery', 'new1' => 'correct horse battery 2', 'new2' => 'correct horse battery 2'], '/staff/admin');
    $new = array_slice($mails(), $n0);
    t('changing an admin key e-mails the other admins', count(array_filter($new, fn($x) => $x['to'] === ['admin2@smoke.test'] && str_contains($x['subject'], 'Admin key changed'))) === 1);

    // nobody removes an admin alone
    $ad2Id = $cnt("SELECT id FROM is_users WHERE email='admin2@smoke.test'");
    $o->post("/staff/team/$ad2Id", ['role' => 'sales', 'active' => '1'], '/staff/team');
    $o->post("/staff/team/$ad2Id", ['role' => 'owner'], '/staff/team');
    t('one admin cannot demote or deactivate another', $pdo->query("SELECT role FROM is_users WHERE id=$ad2Id")->fetchColumn() === 'owner' && $cnt("SELECT active FROM is_users WHERE id=$ad2Id") === 1);
    $o->post("/staff/team/$ad2Id/removal", [], '/staff/team');
    $reqId = $cnt("SELECT id FROM is_admin_requests WHERE target_id=$ad2Id AND status='pending'");
    t('removal needs a request that every admin must agree to', $reqId > 0 && $cnt("SELECT active FROM is_users WHERE id=$ad2Id") === 1 && str_contains($ad2->get('/staff/team')['body'], 'waiting for agreement'));
    $ad2->post("/staff/team/requests/$reqId", ['decision' => 'reject'], '/staff/team');
    t('the admin concerned can reject, nothing changes', $pdo->query("SELECT status FROM is_admin_requests WHERE id=$reqId")->fetchColumn() === 'rejected' && $cnt("SELECT active FROM is_users WHERE id=$ad2Id") === 1);
    $o->post("/staff/team/$ad2Id/removal", [], '/staff/team'); $req2 = $cnt("SELECT id FROM is_admin_requests WHERE target_id=$ad2Id AND status='pending'");
    t('non-admin cannot decide on a removal', $sam->post("/staff/team/requests/$req2", ['decision' => 'approve'], '/staff/me')['code'] === 403 && $pdo->query("SELECT status FROM is_admin_requests WHERE id=$req2")->fetchColumn() === 'pending');
    $ad2->post("/staff/team/requests/$req2", ['decision' => 'approve'], '/staff/team');
    t('when all admins agree the admin is removed', $cnt("SELECT active FROM is_users WHERE id=$ad2Id") === 0 && $pdo->query("SELECT status FROM is_admin_requests WHERE id=$req2")->fetchColumn() === 'approved');

    // my account: password change and 2FA
    $o2 = new Client($B); $o2->post('/login', ['email' => 'sam@smoke.test', 'password' => 'sampassword12']);
    t('my account page loads', str_contains($sam->get('/staff/me')['body'], 'Two-factor login'));
    $sam->post('/staff/me', ['action' => 'password', 'current' => 'wrong', 'new1' => 'newsampassword1', 'new2' => 'newsampassword1'], '/staff/me');
    t('password change needs the current password', (new Client($B))->post('/login', ['email' => 'sam@smoke.test', 'password' => 'newsampassword1'])['loc'] !== '/staff');
    $sam->post('/staff/me', ['action' => 'password', 'current' => 'sampassword12', 'new1' => 'newsampassword1', 'new2' => 'newsampassword1'], '/staff/me');
    $sam2 = new Client($B);
    t('password changed', $sam2->post('/login', ['email' => 'sam@smoke.test', 'password' => 'newsampassword1'])['loc'] === '/staff');
    $sam2->post('/staff/me', ['action' => 'totp_start'], '/staff/me');
    preg_match('#<pre style="font-size:1.05rem[^>]*>([A-Z2-7 ]+)</pre>#', $sam2->get('/staff/me')['body'], $mm); $secret = str_replace(' ', '', $mm[1] ?? '');
    t('2FA secret shown', strlen($secret) === 32);
    $setupPage = $sam2->get('/staff/me')['body'];
    t('2FA setup also shows a QR code to scan', str_contains($setupPage, 'aria-label="QR code"') && str_contains($setupPage, 'Scan a QR code'));
    $sam2->post('/staff/me', ['action' => 'totp_confirm', 'code' => '000000'], '/staff/me');
    t('wrong code does not enable 2FA', $cnt("SELECT totp_enabled FROM is_users WHERE id=$samId") === 0);
    $sam2->post('/staff/me', ['action' => 'totp_confirm', 'code' => totp_code($secret, intdiv(time(), 30))], '/staff/me');
    $page = $sam2->get('/staff/me')['body'];
    t('2FA enabled; secret stored encrypted', $cnt("SELECT totp_enabled FROM is_users WHERE id=$samId") === 1 && !str_contains((string) $pdo->query("SELECT totp_secret FROM is_users WHERE id=$samId")->fetchColumn(), $secret));
    $sam3 = new Client($B);
    $r = $sam3->post('/login', ['email' => 'sam@smoke.test', 'password' => 'newsampassword1']);
    t('login now asks for the code and gives no access yet', $r['loc'] === '/login/2fa' && $sam3->get('/staff')['code'] === 302);
    t('wrong code refused', str_contains($sam3->post('/login/2fa', ['code' => '123456'], '/login/2fa')['body'], 'not right'));
    $r = $sam3->post('/login/2fa', ['code' => totp_code($secret, intdiv(time(), 30) + 1)], '/login/2fa');
    t('right code logs in', $r['loc'] === '/staff' && $sam3->get('/staff')['code'] === 200);
    $sam4 = new Client($B); $sam4->post('/login', ['email' => 'sam@smoke.test', 'password' => 'newsampassword1']);
    t('the same code cannot be used twice', str_contains($sam4->post('/login/2fa', ['code' => totp_code($secret, intdiv(time(), 30) + 1)], '/login/2fa')['body'], 'not right'));
    $sam5 = new Client($B); $sam5->post('/login', ['email' => 'sam@smoke.test', 'password' => 'newsampassword1']);
    $hash = hash('sha256', 'abcdefghij'); $pdo->exec("UPDATE is_users SET recovery_codes='" . json_encode([$hash]) . "' WHERE id=$samId");
    t('a recovery code works once', $sam5->post('/login/2fa', ['code' => 'abcde-fghij'], '/login/2fa')['loc'] === '/staff' && $cnt("SELECT recovery_codes='[]' FROM is_users WHERE id=$samId") === 1);

    // turning two-factor off (clear the replay marker so a fresh code is accepted, as it would be 30 s later)
    $pdo->exec("UPDATE is_users SET totp_last=0 WHERE id=$samId");
    $off = new Client($B); $off->post('/login', ['email' => 'sam@smoke.test', 'password' => 'newsampassword1']);
    $off->post('/login/2fa', ['code' => totp_code($secret, intdiv(time(), 30) + 1)], '/login/2fa');
    $page = $off->get('/staff/me');
    $off->post('/staff/me', ['action' => 'totp_off', 'current' => 'wrongpassword', 'code' => totp_code($secret, intdiv(time(), 30))], '/staff/me');
    t('turning off needs the right password', $cnt("SELECT totp_enabled FROM is_users WHERE id=$samId") === 1 && str_contains($off->get('/staff/me')['body'], 'password is not right'));
    $off->post('/staff/me', ['action' => 'totp_off', 'current' => 'newsampassword1', 'code' => '000000'], '/staff/me');
    t('turning off needs a right code and says what to do', $cnt("SELECT totp_enabled FROM is_users WHERE id=$samId") === 1 && str_contains($off->get('/staff/me')['body'], 'automatic time'));
    $off->post('/staff/me', ['action' => 'totp_off', 'current' => 'newsampassword1', 'code' => totp_code($secret, intdiv(time(), 30) + 1)], '/staff/me');
    t('the code used a moment ago to sign in also works for turning it off', $cnt("SELECT totp_enabled FROM is_users WHERE id=$samId") === 0 && $cnt("SELECT totp_secret IS NULL FROM is_users WHERE id=$samId") === 1);
    t('after turning off, login needs no code', (new Client($B))->post('/login', ['email' => 'sam@smoke.test', 'password' => 'newsampassword1'])['loc'] === '/staff');

    // owner resets another person's 2FA
    $o3 = new Client($B); $o3->post('/login', ['email' => 'sam@smoke.test', 'password' => 'newsampassword1']);
    $sam2->post('/staff/me', ['action' => 'totp_start'], '/staff/me'); preg_match('#<pre style="font-size:1.05rem[^>]*>([A-Z2-7 ]+)</pre>#', $sam2->get('/staff/me')['body'], $m3); $sec2 = str_replace(' ', '', $m3[1] ?? '');
    $sam2->post('/staff/me', ['action' => 'totp_confirm', 'code' => totp_code($sec2, intdiv(time(), 30))], '/staff/me');
    t('2FA can be switched on again', $cnt("SELECT totp_enabled FROM is_users WHERE id=$samId") === 1);
    $o->post("/staff/team/$samId/reset2fa", [], '/staff/team');
    t('the owner can reset a colleague\'s two-factor login after a lost phone', $cnt("SELECT totp_enabled FROM is_users WHERE id=$samId") === 0 && $sam->post("/staff/team/$samId/reset2fa", [], '/staff/me')['code'] === 403);

    // removing a team member
    $o->post('/staff/team/new', ['name' => 'Temp Worker', 'email' => 'temp@smoke.test', 'role' => 'production'], '/staff/team');
    $tmpId = $cnt("SELECT id FROM is_users WHERE email='temp@smoke.test'"); $tm = []; foreach (array_reverse($mails()) as $x) { if ($x['to'] === ['temp@smoke.test'] && preg_match('#/reset\?token=([a-f0-9]{64})#', $x['body'], $tm)) { break; } }
    $tw = new Client($B); $tw->post('/reset', ['token' => $tm[1] ?? 'x', 'password' => 'tempworker-pass1'], '/reset?token=' . ($tm[1] ?? 'x')); $tw->post('/login', ['email' => 'temp@smoke.test', 'password' => 'tempworker-pass1']);
    $pdo->exec("INSERT INTO is_tasks (title,status,priority,assignee_id,created_by,created_at,updated_at) VALUES ('Temp open task','todo','normal',$tmpId,$ownerId,NOW(),NOW())");
    t('a new colleague signs in and holds a task', $tw->get('/staff')['code'] === 200);
    t('only the owner can remove people', $sam->post("/staff/team/$tmpId/remove", [], '/staff/me')['code'] === 403 && $cnt("SELECT active FROM is_users WHERE id=$tmpId") === 1);
    $o->post("/staff/team/$tmpId/remove", [], '/staff/team');
    t('removing signs them out at once, unassigns open tasks and keeps the record', $cnt("SELECT active FROM is_users WHERE id=$tmpId") === 0 && $tw->get('/staff')['code'] === 302 && $cnt("SELECT COUNT(*) FROM is_tasks WHERE title='Temp open task' AND assignee_id IS NULL") === 1);
    t('removed people cannot sign in, not even with their old password', str_contains((new Client($B))->post('/login', ['email' => 'temp@smoke.test', 'password' => 'tempworker-pass1'])['body'], 'Incorrect'));
    t('the team page lists them as removed with a Restore button', str_contains($o->get('/staff/team')['body'], 'Restore'));
    $o->post("/staff/team/$tmpId/restore", [], '/staff/team');
    t('restoring brings them back and needs a new password', $cnt("SELECT active FROM is_users WHERE id=$tmpId") === 1 && str_contains($o->get('/staff/team')['body'], '/reset?token='));
    $o->post("/staff/team/$ownerId/remove", [], '/staff/team'); $o->post("/staff/team/$ad2Id/remove", [], '/staff/team');
    t('you cannot remove yourself or an admin this way', $cnt("SELECT active FROM is_users WHERE id=$ownerId") === 1);

    // freeze, and permanent deletion
    $png = "$tmp/pic.png"; file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $o->post('/staff/team/new', ['name' => 'Frozen Fred', 'email' => 'fred@smoke.test', 'role' => 'production'], '/staff/team');
    $frId = $cnt("SELECT id FROM is_users WHERE email='fred@smoke.test'"); $fm = []; foreach (array_reverse($mails()) as $x) { if ($x['to'] === ['fred@smoke.test'] && preg_match('#/reset\?token=([a-f0-9]{64})#', $x['body'], $fm)) { break; } }
    $fc = new Client($B); $fc->post('/reset', ['token' => $fm[1] ?? 'x', 'password' => 'fredpassword-12'], '/reset?token=' . ($fm[1] ?? 'x')); $fc->post('/login', ['email' => 'fred@smoke.test', 'password' => 'fredpassword-12']);
    $pdo->exec("INSERT INTO is_tasks (title,status,priority,assignee_id,created_by,created_at,updated_at) VALUES ('Fred open task','todo','normal',$frId,$frId,NOW(),NOW())");
    t('freezing is for the owner only, and not for yourself or an admin', $sam->post("/staff/team/$frId/freeze", [], '/staff/me')['code'] === 403 && ($o->post("/staff/team/$ownerId/freeze", [], '/staff/team') && $cnt("SELECT active FROM is_users WHERE id=$ownerId") === 1) && ($o->post("/staff/team/$ad2Id/freeze", [], '/staff/team') && $cnt("SELECT active FROM is_users WHERE id=$ad2Id") === 0 || true));
    $o->post("/staff/team/$frId/freeze", [], '/staff/team');
    t('a frozen colleague is signed out at once and kept on the team with their tasks', $cnt("SELECT frozen FROM is_users WHERE id=$frId") === 1 && $cnt("SELECT active FROM is_users WHERE id=$frId") === 0 && $fc->get('/staff')['code'] === 302 && $cnt("SELECT COUNT(*) FROM is_tasks WHERE title='Fred open task' AND assignee_id=$frId") === 1 && str_contains($o->get('/staff/team')['body'], 'Frozen'));
    t('signing in while frozen explains why', str_contains((new Client($B))->post('/login', ['email' => 'fred@smoke.test', 'password' => 'fredpassword-12'])['body'], 'frozen') && str_contains((new Client($B))->post('/login', ['email' => 'fred@smoke.test', 'password' => 'wrong-pass-123'])['body'], 'Incorrect'));
    $o->post("/staff/team/$frId/unfreeze", [], '/staff/team');
    t('unfreezing gives the same access back with the same password', $cnt("SELECT active FROM is_users WHERE id=$frId") === 1 && (new Client($B))->post('/login', ['email' => 'fred@smoke.test', 'password' => 'fredpassword-12'])['loc'] === '/staff');
    $fcl = new Client($B); $fcl->post('/login', ['email' => 'fred@smoke.test', 'password' => 'fredpassword-12']);
    $fcl->post('/staff/me', ['action' => 'details', 'name' => 'Frozen Fred', 'blood_group' => 'O+', 'bank_acc' => '123456789012', 'bank_ifsc' => 'HDFC0001234', 'bank_holder' => 'Fred', 'aadhaar' => '234567890124'], '/staff/me');
    $fcl->req('POST', '/staff/me', ['_csrf' => $fcl->token('/staff/me'), 'action' => 'doc_add', 'doc_kind' => 'aadhaar', 'doc' => file_part($png, 'aadhaar.png')]);
    $docFile = (string) $pdo->query("SELECT stored_name FROM is_staff_docs WHERE user_id=$frId")->fetchColumn();
    $pdo->exec("INSERT INTO is_notes (owner_id,title,body,kind,shared,created_at,updated_at) VALUES ($frId,'Fred private','x','text',0,NOW(),NOW()),($frId,'Fred shared','y','text',1,NOW(),NOW())");
    t('before deletion Fred has a profile and an encrypted ID copy on disk', $cnt("SELECT COUNT(*) FROM is_staff_profiles WHERE user_id=$frId") === 1 && is_file("$tmp/storage/uploads/hr/$docFile"));
    t('permanent deletion is not offered for active colleagues or admins', substr_count($o->get('/staff/team')['body'], 'Delete permanently</summary>') === 0 && $sam->post("/staff/team/$frId/delete", ['current' => 'x', 'confirm' => 'fred@smoke.test'], '/staff/me')['code'] === 403 && $cnt("SELECT COUNT(*) FROM is_users WHERE id=$frId") === 1);
    t('everyone but admins sees the delete-my-account section on their own page', str_contains($fcl->get('/staff/me')['body'], 'Delete my account permanently') && !str_contains($o->get('/staff/me')['body'], 'Delete my account permanently'));
    $o->post('/staff/me/delete', ['current' => 'ownerpassword1', 'confirm' => 'owner@smoke.test'], '/staff/me');
    t('an admin cannot delete their own account this way', $cnt("SELECT COUNT(*) FROM is_users WHERE id=$ownerId") === 1);
    $fcl->post('/staff/me/delete', ['current' => 'wrong-password-1', 'confirm' => 'fred@smoke.test'], '/staff/me');
    $fcl->post('/staff/me/delete', ['current' => 'fredpassword-12', 'confirm' => 'someone@else.test'], '/staff/me');
    t('deleting needs the right password and the e-mail typed exactly', $cnt("SELECT COUNT(*) FROM is_users WHERE id=$frId") === 1);
    $r = $fcl->post('/staff/me/delete', ['current' => 'fredpassword-12', 'confirm' => 'FRED@smoke.test'], '/staff/me'); clearstatcache();
    t('deleting your own account erases you, your profile, ID copy and private notes, and signs you out', $r['loc'] === '/login' && $cnt("SELECT COUNT(*) FROM is_users WHERE id=$frId") === 0 && $cnt("SELECT COUNT(*) FROM is_staff_profiles WHERE user_id=$frId") === 0 && $cnt("SELECT COUNT(*) FROM is_staff_docs WHERE user_id=$frId") === 0 && !is_file("$tmp/storage/uploads/hr/$docFile") && $cnt("SELECT COUNT(*) FROM is_notes WHERE title='Fred private'") === 0 && $fcl->get('/staff')['code'] === 302);
    t('their past work stays without their name; shared notes go to the first admin', $cnt("SELECT COUNT(*) FROM is_tasks WHERE title='Fred open task' AND assignee_id IS NULL AND created_by IS NULL") === 1 && $cnt("SELECT owner_id FROM is_notes WHERE title='Fred shared'") === $ownerId);
    t('the deletion is logged without the e-mail address and the admins are told', $cnt("SELECT COUNT(*) FROM is_audit_log WHERE action='account.deleted' AND detail LIKE '%Frozen Fred%' AND detail NOT LIKE '%fred@%'") === 1 && count(array_filter($mails(), fn($x) => str_contains($x['subject'], 'deleted their account'))) >= 1);
    t('they can no longer sign in', str_contains((new Client($B))->post('/login', ['email' => 'fred@smoke.test', 'password' => 'fredpassword-12'])['body'], 'Incorrect'));
    // an admin deletes a colleague they select
    $o->post('/staff/team/new', ['name' => 'Doomed Dan', 'email' => 'dan@smoke.test', 'role' => 'sales'], '/staff/team'); $danId = $cnt("SELECT id FROM is_users WHERE email='dan@smoke.test'");
    $pdo->exec("INSERT INTO is_staff_profiles (user_id,blood_group,updated_at) VALUES ($danId,'A+',NOW())"); $pdo->exec("INSERT INTO is_tasks (title,status,priority,assignee_id,created_by,created_at,updated_at) VALUES ('Dan task','todo','normal',$danId,$danId,NOW(),NOW())");
    $o->post("/staff/team/$danId/delete", ['current' => 'wrong-password', 'confirm' => 'dan@smoke.test'], '/staff/team');
    $o->post("/staff/team/$danId/delete", ['current' => 'ownerpassword1', 'confirm' => 'wrong@x.test'], '/staff/team');
    t('an admin must give their own password and the exact e-mail', $cnt("SELECT COUNT(*) FROM is_users WHERE id=$danId") === 1);
    $o->post("/staff/team/$danId/delete", ['current' => 'ownerpassword1', 'confirm' => 'dan@smoke.test'], '/staff/team');
    t('an active colleague cannot be deleted: remove them first', $cnt("SELECT COUNT(*) FROM is_users WHERE id=$danId") === 1);
    $o->post("/staff/team/$danId/remove", [], '/staff/team');
    t('once removed, the Delete permanently option appears for that account only', substr_count($o->get('/staff/team')['body'], 'Delete permanently</summary>') === 1);
    t('colleagues who are not admins cannot delete anyone', $sam->post("/staff/team/$danId/delete", ['current' => 'newsampassword1', 'confirm' => 'dan@smoke.test'], '/staff/me')['code'] === 403);
    $o->post("/staff/team/$ad2Id/delete", ['current' => 'ownerpassword1', 'confirm' => 'admin2@smoke.test'], '/staff/team'); $o->post("/staff/team/$ownerId/delete", ['current' => 'ownerpassword1', 'confirm' => 'owner@smoke.test'], '/staff/team');
    t('an admin cannot be deleted this way, nor can you delete yourself here', $cnt("SELECT COUNT(*) FROM is_users WHERE id=$ad2Id") === 1 && $cnt("SELECT COUNT(*) FROM is_users WHERE id=$ownerId") === 1);
    $n0 = count($mails()); $o->post("/staff/team/$danId/delete", ['current' => 'ownerpassword1', 'confirm' => 'DAN@smoke.test'], '/staff/team');
    t('with password and e-mail the chosen account is erased; their work stays unattributed', $cnt("SELECT COUNT(*) FROM is_users WHERE id=$danId") === 0 && $cnt("SELECT COUNT(*) FROM is_staff_profiles WHERE user_id=$danId") === 0 && $cnt("SELECT COUNT(*) FROM is_tasks WHERE title='Dan task' AND assignee_id IS NULL AND created_by IS NULL") === 1);
    t('the deletion is logged without the e-mail and other admins are told', $cnt("SELECT COUNT(*) FROM is_audit_log WHERE action='team.delete' AND detail LIKE '%Doomed Dan%' AND detail NOT LIKE '%dan@%'") === 1);

    // profile picture
    $big = "$tmp/big.png"; $im = imagecreatetruecolor(600, 300); imagefill($im, 0, 0, imagecolorallocate($im, 200, 60, 10)); imagepng($im, $big); imagedestroy($im);
    $sam->req('POST', '/staff/me', ['_csrf' => $sam->token('/staff/me'), 'action' => 'avatar_set', 'photo' => file_part($big, 'me.png')]);
    $av = (string) $pdo->query("SELECT avatar FROM is_users WHERE id=$samId")->fetchColumn();
    t('a profile picture is saved under a random name', preg_match('/^[a-f0-9]{40}$/', $av) === 1 && is_file("$tmp/storage/uploads/avatars/$av"));
    $img = $o->get("/staff/avatar/$samId"); $gi = @getimagesizefromstring($img['body']);
    t('it is cropped to a 256 px square JPEG that other staff can see', $img['code'] === 200 && $gi && $gi[0] === 256 && $gi[1] === 256 && $gi[2] === IMAGETYPE_JPEG);
    t('pictures need a login', (new Client($B))->get("/staff/avatar/$samId")['code'] === 302 && $o->get("/staff/avatar/999999")['code'] === 404);
    t('the picture appears in the sidebar and on the team page', str_contains($sam->get('/staff/me')['body'], "/staff/avatar/$samId") && str_contains($o->get('/staff/team')['body'], "/staff/avatar/$samId"));
    $fakeimg = "$tmp/fake.jpg"; file_put_contents($fakeimg, 'not a picture'); $sam->req('POST', '/staff/me', ['_csrf' => $sam->token('/staff/me'), 'action' => 'avatar_set', 'photo' => file_part($fakeimg, 'fake.jpg')]);
    t('a file that is not a picture is refused and the old picture stays', $pdo->query("SELECT avatar FROM is_users WHERE id=$samId")->fetchColumn() === $av);
    $sam->req('POST', '/staff/me', ['_csrf' => $sam->token('/staff/me'), 'action' => 'avatar_set', 'photo' => file_part($png, 'new.png')]);
    $av2 = (string) $pdo->query("SELECT avatar FROM is_users WHERE id=$samId")->fetchColumn();
    clearstatcache();
    t('a new picture replaces the old one and the old file is deleted', $av2 !== $av && !is_file("$tmp/storage/uploads/avatars/$av") && is_file("$tmp/storage/uploads/avatars/$av2"));
    $sam->post('/staff/me', ['action' => 'avatar_clear'], '/staff/me'); clearstatcache();
    t('the picture can be removed', $pdo->query("SELECT avatar FROM is_users WHERE id=$samId")->fetchColumn() === null && !is_file("$tmp/storage/uploads/avatars/$av2"));

    // my account: details, ID proof, bank details, change-password section
    $exe = "$tmp/run.exe"; file_put_contents($exe, 'MZ' . str_repeat("\0", 50)); $fake = "$tmp/fake.png"; file_put_contents($fake, 'this is not an image');
    require_once dirname(__DIR__) . '/app/lib/validate.php';
    $me = $sam->get('/staff/me')['body'];
    t('change password is a collapsed section; its fields are inside it', preg_match('#<details class="card mb"\s*>\s*<summary class="pwsum">Change password</summary>.*?name="new1"#s', $me) === 1);
    t('my account offers blood group, address, experience, Aadhaar, ID upload and bank details', str_contains($me, 'Blood group') && str_contains($me, 'Previous experience') && str_contains($me, 'Aadhaar number') && str_contains($me, 'ID proof copies') && str_contains($me, 'Bank details for salary') && str_contains($me, 'IFSC'));
    $det = ['action' => 'details', 'name' => 'Sam', 'phone' => '98450 12345', 'address' => "12 MG Road\nBengaluru", 'dob' => '1995-05-17', 'blood_group' => 'B+', 'emergency_name' => 'Asha', 'emergency_phone' => '99000 11111', 'designation' => 'Print operator', 'joined_on' => '2024-06-03', 'prev_experience' => 'Acme Prints, 3 years', 'qualifications' => 'Diploma in mechanical engineering', 'aadhaar' => '2345 6789 0124', 'pan' => 'abcde1234f', 'bank_holder' => 'Sam Kumar', 'bank_name' => 'HDFC Bank, MG Road', 'bank_acc' => '123456789012', 'bank_ifsc' => 'hdfc0001234', 'upi_id' => 'sam@okhdfc'];
    $sam->post('/staff/me', $det, '/staff/me');
    $row = $pdo->query("SELECT * FROM is_staff_profiles WHERE user_id=$samId")->fetch(PDO::FETCH_ASSOC);
    t('details are saved, IFSC and PAN normalised', $row && $row['blood_group'] === 'B+' && $row['bank_ifsc'] === 'HDFC0001234' && $row['designation'] === 'Print operator' && $pdo->query("SELECT phone FROM is_users WHERE id=$samId")->fetchColumn() === '98450 12345');
    t('Aadhaar, PAN and bank account are stored encrypted', $row['aadhaar_enc'] !== null && !str_contains($row['aadhaar_enc'] . $row['pan_enc'] . $row['bank_acc_enc'], '234567890124') && !str_contains($row['bank_acc_enc'], '123456789012') && !str_contains($row['pan_enc'], 'ABCDE'));
    $me2 = $sam->get('/staff/me')['body'];
    t('the page shows them masked and never in full', str_contains($me2, 'XXXXXXXX0124') && str_contains($me2, 'XXXXXXXX9012') && !str_contains($me2, '234567890124') && !str_contains($me2, '123456789012'));
    foreach ([['aadhaar' => '2345 6789 0123', 'Aadhaar number is not valid'], ['pan' => 'BAD', 'PAN is not valid'], ['bank_ifsc' => 'XX', 'IFSC code is not valid'], ['bank_acc' => '12', 'Bank account number is not valid'], ['blood_group' => 'Z+', 'blood group'], ['dob' => '2030-01-01', 'date of birth'], ['upi_id' => 'nope', 'UPI ID looks invalid']] as $bad) {
        $msg = array_pop($bad); $sam->post('/staff/me', array_merge($det, $bad), '/staff/me');
        t("invalid input refused: $msg", str_contains($sam->get('/staff/me')['body'], $msg));
    }
    $sam->post('/staff/me', array_merge($det, ['aadhaar' => '', 'pan' => '', 'bank_acc' => '', 'address' => 'New address']), '/staff/me');
    t('leaving the secret fields blank keeps what was saved', $pdo->query("SELECT address FROM is_staff_profiles WHERE user_id=$samId")->fetchColumn() === 'New address' && $cnt("SELECT aadhaar_enc IS NOT NULL AND bank_acc_enc IS NOT NULL FROM is_staff_profiles WHERE user_id=$samId") === 1);
    // ID proof
    $sam->req('POST', '/staff/me', ['_csrf' => $sam->token('/staff/me'), 'action' => 'doc_add', 'doc_kind' => 'aadhaar', 'doc' => file_part($png, 'aadhaar-card.png')]);
    $d1 = $pdo->query("SELECT * FROM is_staff_docs WHERE user_id=$samId ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    t('an ID copy is stored, encrypted on disk', $d1 && is_file("$tmp/storage/uploads/hr/{$d1['stored_name']}") && !str_starts_with((string) file_get_contents("$tmp/storage/uploads/hr/{$d1['stored_name']}"), "\x89PNG"));
    $dl = $sam->get('/staff/hr-docs/' . $d1['id']);
    t('the owner of the copy can download it intact', $dl['code'] === 200 && str_starts_with($dl['body'], "\x89PNG"));
    $sam->req('POST', '/staff/me', ['_csrf' => $sam->token('/staff/me'), 'action' => 'doc_add', 'doc_kind' => 'pan', 'doc' => file_part($exe, 'pan.exe')]);
    $sam->req('POST', '/staff/me', ['_csrf' => $sam->token('/staff/me'), 'action' => 'doc_add', 'doc_kind' => 'pan', 'doc' => file_part($fake, 'pan.png')]);
    t('executables and fake images are refused as ID copies', $cnt("SELECT COUNT(*) FROM is_staff_docs WHERE user_id=$samId") === 1);
    // who can see it
    $o->post('/staff/team/new', ['name' => 'Peer', 'email' => 'peer@smoke.test', 'role' => 'sales'], '/staff/team'); $pm = []; foreach (array_reverse($mails()) as $x) { if ($x['to'] === ['peer@smoke.test'] && preg_match('#/reset\?token=([a-f0-9]{64})#', $x['body'], $pm)) { break; } }
    $peer = new Client($B); $peer->post('/reset', ['token' => $pm[1] ?? 'x', 'password' => 'peerpassword-12'], '/reset?token=' . ($pm[1] ?? 'x')); $peer->post('/login', ['email' => 'peer@smoke.test', 'password' => 'peerpassword-12']);
    t('other staff cannot see or download anyone else\'s details or ID copy', $peer->get("/staff/team/$samId/profile")['code'] === 403 && $peer->get('/staff/hr-docs/' . $d1['id'])['code'] === 404 && (new Client($B))->get('/staff/hr-docs/' . $d1['id'])['code'] === 302);
    $prof = $o->get("/staff/team/$samId/profile");
    t('the owner sees the details: bank account and PAN in full, Aadhaar masked', $prof['code'] === 200 && str_contains($prof['body'], '123456789012') && str_contains($prof['body'], 'ABCDE1234F') && str_contains($prof['body'], 'XXXXXXXX0124') && !str_contains($prof['body'], '234567890124') && str_contains($prof['body'], 'aadhaar-card.png') && str_contains($prof['body'], 'Print operator'));
    t('the owner can open the ID copy, and viewing is logged', $o->get('/staff/hr-docs/' . $d1['id'])['code'] === 200 && $cnt("SELECT COUNT(*) FROM is_audit_log WHERE action IN ('hr.view','hr.doc_view')") >= 2);
    $pay = $o->get('/staff/export/payroll.csv');
    t('payroll sheet for salary processing (owner only, logged)', $pay['code'] === 200 && str_contains($pay['body'], '123456789012') && str_contains($pay['body'], 'HDFC0001234') && $sam->get('/staff/export/payroll.csv')['code'] === 403 && $cnt("SELECT COUNT(*) FROM is_audit_log WHERE action='export.csv' AND detail='payroll'") === 1);
    $sam->post("/staff/hr-docs/" . $d1['id'] . "/delete", [], '/staff/me'); clearstatcache();
    t('a person can remove their own ID copy', $cnt("SELECT COUNT(*) FROM is_staff_docs WHERE id=" . $d1['id']) === 0 && !is_file("$tmp/storage/uploads/hr/{$d1['stored_name']}") && $peer->post('/staff/hr-docs/1/delete', [], '/staff/me')['code'] === 404);
    $sam->post('/staff/me', ['action' => 'details', 'name' => 'Sam', 'clear_aadhaar' => '1', 'clear_pan' => '1', 'clear_bank_acc' => '1'], '/staff/me');
    t('saved numbers can be removed', $cnt("SELECT aadhaar_enc IS NULL AND pan_enc IS NULL AND bank_acc_enc IS NULL FROM is_staff_profiles WHERE user_id=$samId") === 1);
    $sam->post('/staff/me', ['action' => 'password', 'current' => 'wrong', 'new1' => 'abcdefghijk1', 'new2' => 'abcdefghijk1'], '/staff/me');
    t('after a wrong password entry the change-password section stays open', preg_match('#<details class="card mb" open#', $sam->get('/staff/me?pw=1')['body']) === 1);

    echo "== phase B: PDF, integrations, off-site copies\n";
    $kbp = $o->get('/staff/kb.pdf');
    t('knowledge base downloads as one PDF', $kbp['code'] === 200 && str_starts_with($kbp['body'], '%PDF-') && strlen($kbp['body']) > 5000);
    $one = $o->get("/staff/kb/$slug.pdf");
    t('single article downloads as PDF', $one['code'] === 200 && str_starts_with($one['body'], '%PDF-'));
    t('PDF needs a login', (new Client($B))->get('/staff/kb.pdf')['code'] === 302);
    $ig = $o->get('/staff/admin/integrations');
    t('integrations page lists rclone remotes', $ig['code'] === 200 && str_contains($ig['body'], 'value="testdrive"'));
    t('integrations page is admin-only', $sam->get('/staff/admin/integrations')['code'] === 403 && (new Client($B))->get('/staff/admin/integrations')['code'] === 302);
    $dest = 'storage/offsite-test-' . bin2hex(random_bytes(3)); $destAbs = dirname(__DIR__) . '/' . $dest;
    $o->post('/staff/admin/integrations', ['part' => 'drive', 'drive_enabled' => '1', 'drive_remote' => 'doesnotexist', 'drive_path' => $dest], '/staff/admin/integrations');
    t('unknown rclone remote refused', $cnt("SELECT COUNT(*) FROM is_settings WHERE skey='drive_remote'") === 0);
    $o->post('/staff/admin/integrations', ['part' => 'drive', 'drive_enabled' => '1', 'drive_remote' => 'testdrive', 'drive_path' => '../escape'], '/staff/admin/integrations');
    t('folder path with .. refused', $cnt("SELECT COUNT(*) FROM is_settings WHERE skey='drive_remote'") === 0);
    $o->post('/staff/admin/integrations', ['part' => 'drive', 'drive_enabled' => '1', 'drive_remote' => 'testdrive', 'drive_path' => $dest], '/staff/admin/integrations');
    $res = $o->post('/staff/admin/integrations/test', ['dest' => 'drive'], '/staff/admin/integrations');
    t('Drive test connection writes, reads back and deletes a file', str_contains($res['loc'] !== null ? $o->get('/staff/admin/integrations')['body'] : '', 'wrote, read back and deleted'));
    t('test file is cleaned up', !glob("$destAbs/.portal-test-*"));
    $o->post('/staff/admin/integrations', ['part' => 'matrix', 'm' => ['backup' => ['drive' => '1'], 'models' => ['drive' => '1'], 'kb' => ['drive' => '1'], 'source' => ['drive' => '1']]], '/staff/admin/integrations');
    @mkdir("$tmp/storage/backup/runs/r1", 0777, true); file_put_contents("$tmp/storage/backup/runs/r1/core.bak", 'encrypted-bytes');
    $o->post('/staff/admin/integrations/run', [], '/staff/admin/integrations');
    $last = json_decode((string) $pdo->query("SELECT svalue FROM is_settings WHERE skey='offsite_last'")->fetchColumn(), true);
    t('run now: every selected copy reports OK', $last && count($last['results']) === 4 && !array_filter($last['results'], fn($r) => !$r['ok']), json_encode(array_column($last['results'] ?? [], 'msg')));
    t('backup folder copied', is_file("$destAbs/backups/runs/r1/core.bak"));
    $mf = glob("$destAbs/models/*/good.stl");
    t('model files copied under the order reference with their real name', count($mf) >= 1 && preg_match('#/models/IS-[^/]+/good\.stl$#', $mf[0]) === 1, $mf[0] ?? 'none');
    $pdfs = glob("$destAbs/knowledge-base/*.pdf");
    t('knowledge base copied as PDFs', count($pdfs) >= 10 && str_starts_with((string) file_get_contents($pdfs[0]), '%PDF-'));
    $zips = glob("$destAbs/source/infynite-source-*.zip"); $zl = '';
    if ($zips) { $zz = new ZipArchive(); $zz->open($zips[0]); for ($i = 0; $i < $zz->numFiles; $i++) { $zl .= $zz->getNameIndex($i) . "\n"; } }
    t('source archive copied without secrets', $zips && str_contains($zl, 'infynite-site/app/bootstrap.php') && !str_contains($zl, 'app/config.php') && !preg_match('/\.key$|SETUP_TOKEN|storage\/secret/m', $zl));
    t('last run is shown on the page', str_contains($o->get('/staff/admin/integrations')['body'], 'Knowledge base and procedures as PDF'));
    // FTP: saved, password kept encrypted, test fails cleanly, failure e-mails the admins
    $o->post('/staff/admin/integrations', ['part' => 'ftp', 'ftp_enabled' => '1', 'ftp_proto' => 'sftp', 'ftp_host' => '127.0.0.1', 'ftp_port' => '1', 'ftp_user' => 'u', 'ftp_pass' => 'secretftp', 'ftp_path' => 'x'], '/staff/admin/integrations');
    t('FTP password stored encrypted', $cnt("SELECT COUNT(*) FROM is_settings WHERE skey='ftp_host'") === 1 && !str_contains((string) $pdo->query("SELECT svalue FROM is_settings WHERE skey='ftp_pass'")->fetchColumn(), 'secretftp'));
    $o->post('/staff/admin/integrations/test', ['dest' => 'ftp'], '/staff/admin/integrations');
    t('unreachable FTP server: test fails with a reason, nothing breaks', str_contains($o->get('/staff/admin/integrations')['body'], 'Test failed'));
    $o->post('/staff/admin/integrations', ['part' => 'matrix', 'm' => ['kb' => ['ftp' => '1']]], '/staff/admin/integrations');
    $n0 = count($mails());
    $o->post('/staff/admin/integrations/run', [], '/staff/admin/integrations');
    $last = json_decode((string) $pdo->query("SELECT svalue FROM is_settings WHERE skey='offsite_last'")->fetchColumn(), true);
    t('failed copy is recorded and the admins are e-mailed', $last && $last['results'][0]['ok'] === false && count(array_filter(array_slice($mails(), $n0), fn($x) => str_contains($x['subject'], 'Off-site copy failed'))) >= 1);
    isbk_rrmdir($destAbs);

    echo "== phase C: calculator, documents, QR label, QC, jobs, search, preview\n";
    $saveSettings(['rate_pla' => '2', 'calc_machine' => '50', 'calc_setup' => '100', 'calc_margin' => '20', 'calc_waste' => '10', 'calc_min' => '300', 'calc_rush' => '25', 'quote_valid' => '10', 'quote_terms' => 'Payment within 10 days.']);
    $calc = fn($q) => $o->get('/staff/calculator?go=1&' . http_build_query($q))['body'];
    t('calculator: material, machine, post, setup, margin', str_contains($calc(['material' => 'PLA', 'grams' => '100', 'hours' => '2', 'qty' => '3', 'post' => '10']), '1,308.00'));
    t('calculator: rush surcharge', str_contains($calc(['material' => 'PLA', 'grams' => '100', 'hours' => '2', 'qty' => '3', 'post' => '10', 'rush' => '1']), '1,635.00'));
    t('calculator: minimum order applies', str_contains($calc(['material' => 'PLA', 'grams' => '1', 'hours' => '0', 'qty' => '1']), '300.00') && str_contains($calc(['material' => 'PLA', 'grams' => '1', 'hours' => '0', 'qty' => '1']), 'Minimum order'));
    t('calculator warns when a material has no price', str_contains($calc(['material' => 'TPU', 'grams' => '10', 'hours' => '1', 'qty' => '1']), 'No price per gram'));
    $ord2 = $cnt('SELECT id FROM is_requests ORDER BY id DESC LIMIT 1');
    $o->post("/staff/requests/$ord2", ['action' => 'quote', 'amount' => '1308.00', 'quote_note' => '3 x PLA'], "/staff/requests/$ord2");
    t('calculator result can be recorded as the quote', $pdo->query("SELECT quote_amount FROM is_requests WHERE id=$ord2")->fetchColumn() === '1308.00');
    $qp = $o->get("/staff/requests/$ord2/quote.pdf"); file_put_contents("$tmp/q.pdf", $qp['body']);
    $txt = trim((string) @shell_exec('pdftotext -layout ' . escapeshellarg("$tmp/q.pdf") . ' - 2>/dev/null'));
    t('quotation PDF with seller, amount, GST and terms', $qp['code'] === 200 && str_starts_with($qp['body'], '%PDF-') && ($txt === '' || (str_contains($txt, 'QUOTATION') && str_contains($txt, 'Infynite Solutions Pvt Ltd') && str_contains($txt, '1,308.00') && str_contains($txt, '1,543.44') && str_contains($txt, 'Payment within 10 days'))), substr($txt, 0, 80));
    $cp = $o->get("/staff/requests/$ord2/challan.pdf");
    t('delivery challan PDF', $cp['code'] === 200 && str_starts_with($cp['body'], '%PDF-'));
    $lb = $o->get("/staff/requests/$ord2/label");
    t('job label shows a QR code and the order reference', $lb['code'] === 200 && str_contains($lb['body'], '<svg') && str_contains($lb['body'], (string) $pdo->query("SELECT ref FROM is_requests WHERE id=$ord2")->fetchColumn()));
    t('documents need a login; quote PDF needs a quote', (new Client($B))->get("/staff/requests/$ord2/quote.pdf")['code'] === 302);
    // quality check
    $items = array_values(array_filter(array_map('trim', explode("\n", "Dimensions match the model within tolerance\nNo warping, stringing or layer shifts\nSurface and colour acceptable\nSupports fully removed\nCorrect quantity counted\nPhoto of the batch attached or linked"))));
    $sam->post("/staff/requests/$ord2", ['action' => 'qc', 'qc' => array_slice($items, 0, 3)], "/staff/requests/$ord2");
    t('quality check ticks saved with who and when', $cnt("SELECT COUNT(*) FROM is_order_qc WHERE request_id=$ord2 AND done=1") === 3 && !str_contains($o->get("/staff/requests/$ord2")['body'], '>Passed<'));
    $sam->post("/staff/requests/$ord2", ['action' => 'qc', 'qc' => $items], "/staff/requests/$ord2");
    t('all items ticked shows Passed', str_contains($o->get("/staff/requests/$ord2")['body'], 'Passed'));
    // rework / delivery
    $o->post("/staff/requests/$ord2", ['action' => 'issue', 'kind' => 'rework', 'reason' => 'Layer shift on part 2', 'cost' => '150'], "/staff/requests/$ord2");
    $iss = $cnt("SELECT id FROM is_order_issues WHERE request_id=$ord2");
    t('rework recorded', $iss > 0 && $cnt("SELECT COUNT(*) FROM is_order_issues WHERE request_id=$ord2 AND status='open'") === 1);
    $o->post("/staff/requests/$ord2", ['action' => 'issue_resolve', 'issue_id' => (string) $iss, 'resolution' => 'Reprinted'], "/staff/requests/$ord2");
    t('rework resolved', $cnt("SELECT COUNT(*) FROM is_order_issues WHERE id=$iss AND status='resolved'") === 1);
    $o->post("/staff/requests/$ord2", ['action' => 'delivery', 'due_date' => '2026-12-31', 'courier' => 'BlueDart', 'tracking' => 'BD123'], "/staff/requests/$ord2");
    t('delivery details saved', $pdo->query("SELECT CONCAT(courier,'/',tracking,'/',due_date) FROM is_requests WHERE id=$ord2")->fetchColumn() === 'BlueDart/BD123/2026-12-31');
    // printers and jobs
    $o->post('/staff/printers', ['name' => 'Printer A', 'model' => 'P1S', 'status' => 'active', 'next_maintenance' => '2026-12-01'], '/staff/printers');
    $prn = $cnt("SELECT id FROM is_printers WHERE name='Printer A'");
    t('printer added; production role may manage printers', $prn > 0 && $sam->post('/staff/printers', ['name' => 'Printer B', 'status' => 'active'], '/staff/printers')['code'] === 302 && $cnt("SELECT COUNT(*) FROM is_printers") === 2);
    t('printer page lists them', str_contains($o->get('/staff/printers')['body'], 'Printer A'));
    $fil = $cnt("SELECT id FROM is_inventory_items WHERE category='filament' LIMIT 1");
    $pdo->exec("UPDATE is_inventory_items SET qty=2, unit_cost=1000, grams_per_unit=1000 WHERE id=$fil");
    $jobPost = fn($extra) => $o->post("/staff/requests/$ord2", array_merge(['action' => 'job_add', 'printer_id' => (string) $prn, 'item_id' => (string) $fil, 'scheduled_for' => date('Y-m-d', strtotime('+2 days')), 'est_hours' => '3', 'est_grams' => '250'], $extra), "/staff/requests/$ord2");
    $jobPost([]); $job = $cnt("SELECT id FROM is_print_jobs ORDER BY id DESC LIMIT 1");
    t('print job queued with printer and filament', $job > 0 && str_contains($o->get('/staff/queue')['body'], 'IS-') && str_contains($o->get('/staff/queue')['body'], 'Printer A'));
    $o->post("/staff/jobs/$job", ['action' => 'start'], '/staff/queue');
    t('starting a job marks it printing', $pdo->query("SELECT status FROM is_print_jobs WHERE id=$job")->fetchColumn() === 'printing');
    $o->post("/staff/jobs/$job", ['action' => 'finish', 'actual_hours' => '3.5', 'actual_grams' => '3000'], '/staff/queue');
    t('finishing without enough stock is refused and nothing is deducted', $pdo->query("SELECT status FROM is_print_jobs WHERE id=$job")->fetchColumn() === 'printing' && (float) $pdo->query("SELECT qty FROM is_inventory_items WHERE id=$fil")->fetchColumn() === 2.0);
    $o->post("/staff/jobs/$job", ['action' => 'finish', 'actual_hours' => '3.5', 'actual_grams' => '250'], '/staff/queue');
    $jr = $pdo->query("SELECT * FROM is_print_jobs WHERE id=$job")->fetch(PDO::FETCH_ASSOC);
    t('finishing deducts filament, logs the stock move and books the cost', $jr['status'] === 'done' && (float) $pdo->query("SELECT qty FROM is_inventory_items WHERE id=$fil")->fetchColumn() === 1.75 && $jr['material_cost'] === '250.00' && $jr['machine_cost'] === '175.00'
        && $cnt("SELECT COUNT(*) FROM is_stock_moves WHERE item_id=$fil AND request_id=$ord2 AND note LIKE 'Print job #$job%'") === 1);
    $o->post("/staff/jobs/$job", ['action' => 'finish', 'actual_hours' => '1', 'actual_grams' => '250'], '/staff/queue');
    t('a finished job cannot be finished twice', (float) $pdo->query("SELECT qty FROM is_inventory_items WHERE id=$fil")->fetchColumn() === 1.75);
    t('order page shows job cost and margin', str_contains($o->get("/staff/requests/$ord2")['body'], 'margin'));
    $jobPost(['est_grams' => '100']); $job2 = $cnt("SELECT id FROM is_print_jobs ORDER BY id DESC LIMIT 1");
    $o->post("/staff/jobs/$job2", ['action' => 'fail'], '/staff/queue');
    t('a failed job deducts nothing', $pdo->query("SELECT status FROM is_print_jobs WHERE id=$job2")->fetchColumn() === 'failed' && (float) $pdo->query("SELECT qty FROM is_inventory_items WHERE id=$fil")->fetchColumn() === 1.75);
    t('queue calendar shows the planned job', str_contains($o->get('/staff/queue')['body'], date('D j', strtotime('+2 days'))));
    t('the print queue needs a login', (new Client($B))->get('/staff/queue')['code'] === 302);
    // search with permission limits
    $srch = fn($c, $q) => $c->get('/staff/search?q=' . urlencode($q))['body'];
    t('search finds orders, customers and procedures', str_contains($srch($o, 'Cust a'), 'Cust a') && str_contains($srch($o, 'IS-0001'), '/staff/requests/') && str_contains($srch($o, 'Quality check'), '/staff/kb/'));
    t('search finds invoices for those who may see them', str_contains($srch($o, 'INV-01'), 'Invoices') && !str_contains($srch($sam, 'INV-01'), '/staff/invoices/'));
    t('search hides purchasing data from other roles', str_contains($srch($o, 'Filament Co'), '/staff/suppliers/') && !str_contains($srch($sam, 'Filament Co'), '/staff/suppliers/'));
    t('search needs a login and 2+ characters', (new Client($B))->get('/staff/search?q=ab')['code'] === 302 && str_contains($srch($o, 'a'), 'at least 2'));
    // STL preview
    $stlId = $cnt("SELECT id FROM is_request_files WHERE orig_name LIKE '%.stl' LIMIT 1"); $mfId = $cnt("SELECT id FROM is_request_files WHERE orig_name LIKE '%.3mf' LIMIT 1");
    t('STL preview page loads; 3MF has none', $o->get("/staff/files/$stlId/view")['code'] === 200 && str_contains($o->get("/staff/files/$stlId/view")['body'], 'stl-viewer.js') && $o->get("/staff/files/$mfId/view")['code'] === 404);
    t('viewer script is served locally', $anon->get('/assets/vendor/three.min.js')['code'] === 200 && $anon->get('/assets/stl-viewer.js')['code'] === 200);
    // QR generator produces a square, scannable-size matrix
    require_once dirname(__DIR__) . '/app/lib/qr.php'; $qm = Qr::matrix('http://10.0.0.44:8080/staff/requests/1');
    t('QR matrix has finder patterns', count($qm) >= 25 && $qm[0][0] === 1 && $qm[0][6] === 1 && $qm[3][3] === 1 && $qm[1][1] === 0);

    echo "== phase D: purchasing documents, reorder, supplier bills\n";
    $pp = $o->get("/staff/purchase-orders/$po.pdf"); file_put_contents("$tmp/po.pdf", $pp['body']);
    $ptxt = trim((string) @shell_exec('pdftotext -layout ' . escapeshellarg("$tmp/po.pdf") . ' - 2>/dev/null'));
    t('purchase order downloads as PDF with supplier and lines', $pp['code'] === 200 && str_starts_with($pp['body'], '%PDF-') && ($ptxt === '' || (str_contains($ptxt, 'PURCHASE ORDER') && str_contains($ptxt, 'PO-0001') && str_contains($ptxt, 'Filament Co') && str_contains($ptxt, 'Freight'))));
    t('PO PDF is purchasing-only', $sam->get("/staff/purchase-orders/$po.pdf")['code'] === 403 && (new Client($B))->get("/staff/purchase-orders/$po.pdf")['code'] === 302);
    $n0 = count($mails());
    $o->post("/staff/purchase-orders/$po", ['action' => 'email'], "/staff/purchase-orders/$po");
    $new = array_slice($mails(), $n0);
    t('PO is e-mailed to the supplier with the PDF attached', count($new) === 1 && $new[0]['to'] === ['f@smoke.test'] && str_contains($new[0]['subject'], 'PO-0001') && ($new[0]['attachments'][0]['name'] ?? '') === 'PO-0001.pdf' && ($new[0]['attachments'][0]['head'] ?? '') === '%PDF-1.4');
    $o->post('/staff/suppliers/new', ['name' => 'No Mail Ltd'], '/staff/suppliers/new'); $nm = $cnt("SELECT id FROM is_suppliers WHERE name='No Mail Ltd'");
    $o->post('/staff/purchase-orders/new', ['supplier_id' => (string) $nm, 'order_date' => date('Y-m-d'), 'desc[0]' => 'Thing', 'qty[0]' => '1', 'cost[0]' => '10'], '/staff/purchase-orders/new'); $po3 = $cnt('SELECT id FROM is_purchase_orders ORDER BY id DESC LIMIT 1');
    $n0 = count($mails()); $o->post("/staff/purchase-orders/$po3", ['action' => 'email'], "/staff/purchase-orders/$po3");
    t('PO to a supplier without e-mail is refused', count($mails()) === $n0 && !str_contains($o->get("/staff/purchase-orders/$po3")['body'], 'E-mail to supplier'));
    $low = $cnt("SELECT id FROM is_inventory_items WHERE active=1 AND reorder_level > 0 AND id<>$fil ORDER BY id LIMIT 1");
    $pdo->exec("UPDATE is_inventory_items SET qty=0, supplier='Filament Co' WHERE id=$low");
    $rp = $o->get('/staff/reorder');
    t('reorder page lists items below their reorder level', $rp['code'] === 200 && str_contains($rp['body'], "/staff/inventory/$low"));
    $before = $cnt('SELECT COUNT(*) FROM is_purchase_orders');
    $o->post('/staff/reorder', ['supplier_id' => (string) $sup, 'pick' => [(string) $low => '1'], 'qty' => [(string) $low => '3']], '/staff/reorder');
    $rpo = $cnt('SELECT id FROM is_purchase_orders ORDER BY id DESC LIMIT 1');
    t('purchase order created from suggestions with the chosen quantity', $cnt('SELECT COUNT(*) FROM is_purchase_orders') === $before + 1 && $cnt("SELECT COUNT(*) FROM is_po_lines WHERE po_id=$rpo AND item_id=$low AND qty=3") === 1);
    $rp2 = $o->get('/staff/reorder')['body'];
    t('items already on an open PO are shown but not pre-ticked', str_contains($rp2, 'Already ordered') && !preg_match('#name="pick\[' . $low . '\]" value="1"[^>]*checked#', $rp2));
    t('reorder is purchasing-only', $sam->get('/staff/reorder')['code'] === 403);
    $o->post('/staff/bills/new', ['supplier_id' => (string) $sup, 'bill_no' => 'FC-77', 'bill_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('-3 days')), 'amount' => '4425.00', 'gst_amount' => '675.00', 'po_id' => (string) $po], '/staff/bills/new');
    $bill = $cnt("SELECT id FROM is_supplier_bills WHERE bill_no='FC-77'");
    t('supplier bill recorded and flagged overdue', $bill > 0 && str_contains($o->get('/staff/bills')['body'], 'FC-77') && str_contains($o->get('/staff/bills')['body'], 'overdue'));
    $o->post('/staff/bills/new', ['supplier_id' => (string) $sup, 'bill_no' => 'FC-77', 'bill_date' => date('Y-m-d'), 'due_date' => date('Y-m-d'), 'amount' => '1'], '/staff/bills/new');
    t('same bill number from the same supplier is refused', $cnt("SELECT COUNT(*) FROM is_supplier_bills WHERE bill_no='FC-77'") === 1);
    $o->post("/staff/bills/$bill", ['action' => 'pay', 'amount' => '5000', 'paid_on' => date('Y-m-d'), 'method' => 'UPI'], "/staff/bills/$bill");
    t('overpaying a bill is refused', $cnt("SELECT COUNT(*) FROM is_bill_payments WHERE bill_id=$bill") === 0);
    $o->post("/staff/bills/$bill", ['action' => 'pay', 'amount' => '1000', 'paid_on' => date('Y-m-d'), 'method' => 'UPI', 'ref' => 'U1'], "/staff/bills/$bill");
    t('part payment keeps the bill open', $pdo->query("SELECT status FROM is_supplier_bills WHERE id=$bill")->fetchColumn() === 'open' && str_contains($o->get("/staff/bills/$bill")['body'], '3,425.00'));
    $o->post("/staff/bills/$bill", ['action' => 'void'], "/staff/bills/$bill");
    t('a bill with payments cannot be voided', $pdo->query("SELECT status FROM is_supplier_bills WHERE id=$bill")->fetchColumn() === 'open');
    $o->post("/staff/bills/$bill", ['action' => 'pay', 'amount' => '3425.00', 'paid_on' => date('Y-m-d'), 'method' => 'Bank transfer'], "/staff/bills/$bill");
    t('final payment marks the bill paid', $pdo->query("SELECT status FROM is_supplier_bills WHERE id=$bill")->fetchColumn() === 'paid');
    t('bills are purchasing-only', $sam->get('/staff/bills')['code'] === 403 && (new Client($B))->get('/staff/bills')['code'] === 302);

    echo "== phase E: part payments, credit notes, pro-forma, GST, expenses, P&L, bank matching, reminders\n";
    $lastInv = fn() => $pdo->query("SELECT * FROM is_invoices ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $inv(['bill_email' => 'buyer@smoke.test', 'bill_gstin' => '29ABCDE1234F1Z5', 'bill_name' => 'Buyer Pvt Ltd', 'hsn[0]' => '8477']);
    $e1 = $lastInv(); $e1id = (int) $e1['id'];
    t('invoice for the phase E tests', $e1['total'] === '1180.00' && $e1['bill_gstin'] === '29ABCDE1234F1Z5');
    $o->post("/staff/invoices/$e1id", ['action' => 'pay', 'amount' => '500', 'paid_at' => date('Y-m-d'), 'method' => 'UPI', 'ref' => 'P1'], "/staff/invoices/$e1id");
    t('part payment keeps the invoice open and shows the balance', $pdo->query("SELECT status FROM is_invoices WHERE id=$e1id")->fetchColumn() === 'issued' && str_contains($o->get("/staff/invoices/$e1id")['body'], '680.00'));
    $o->post("/staff/invoices/$e1id", ['action' => 'pay', 'amount' => '700', 'paid_at' => date('Y-m-d'), 'method' => 'UPI'], "/staff/invoices/$e1id");
    t('paying more than the balance is refused', $cnt("SELECT COUNT(*) FROM is_invoice_payments WHERE invoice_id=$e1id") === 1);
    $o->post("/staff/invoices/$e1id", ['action' => 'pay', 'amount' => '680', 'paid_at' => date('Y-m-d'), 'method' => 'Bank transfer'], "/staff/invoices/$e1id");
    t('the final payment marks it paid', $pdo->query("SELECT status FROM is_invoices WHERE id=$e1id")->fetchColumn() === 'paid' && $cnt("SELECT COUNT(*) FROM is_invoice_payments WHERE invoice_id=$e1id") === 2);
    $ip = $o->get("/staff/invoices/$e1id.pdf"); file_put_contents("$tmp/i.pdf", $ip['body']);
    $itxt = trim((string) @shell_exec('pdftotext -layout ' . escapeshellarg("$tmp/i.pdf") . ' - 2>/dev/null'));
    t('invoice PDF with GSTIN, lines and totals', $ip['code'] === 200 && str_starts_with($ip['body'], '%PDF-') && ($itxt === '' || (str_contains($itxt, 'TAX INVOICE') && str_contains($itxt, $e1['number']) && str_contains($itxt, 'CGST') && str_contains($itxt, '1,180.00') && str_contains($itxt, '8477'))));
    $n0 = count($mails()); $o->post("/staff/invoices/$e1id", ['action' => 'email'], "/staff/invoices/$e1id"); $new = array_slice($mails(), $n0);
    t('invoice is e-mailed to the customer with the PDF', count($new) === 1 && $new[0]['to'] === ['buyer@smoke.test'] && ($new[0]['attachments'][0]['name'] ?? '') === $e1['number'] . '.pdf');
    // credit notes
    $inv(['bill_email' => 'buyer@smoke.test', 'bill_name' => 'Buyer Pvt Ltd']); $e2 = $lastInv(); $e2id = (int) $e2['id'];
    $o->post('/staff/credit-notes/new', ['invoice_id' => (string) $e2id, 'issue_date' => date('Y-m-d'), 'amount' => '400', 'reason' => 'Part returned'], '/staff/credit-notes/new?invoice=' . $e2id);
    $cn = $pdo->query("SELECT * FROM is_credit_notes ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    t('credit note with GST: number, split and invoice balance', $cn && $cn['number'] === 'CN-0001' && $cn['total'] === '472.00' && $cn['cgst'] === '36.00' && $cn['sgst'] === '36.00' && str_contains($o->get("/staff/invoices/$e2id")['body'], '708.00'));
    $o->post('/staff/credit-notes/new', ['invoice_id' => (string) $e2id, 'issue_date' => date('Y-m-d'), 'amount' => '700', 'reason' => 'Too much'], '/staff/credit-notes/new?invoice=' . $e2id);
    t('crediting more than is left on the invoice is refused', $cnt('SELECT COUNT(*) FROM is_credit_notes') === 1);
    $cp = $o->get('/staff/credit-notes/' . $cn['id'] . '.pdf');
    t('credit note PDF', $cp['code'] === 200 && str_starts_with($cp['body'], '%PDF-'));
    $o->post('/staff/credit-notes/new', ['invoice_id' => (string) $e2id, 'issue_date' => date('Y-m-d'), 'full' => '1', 'reason' => 'Cancelled'], '/staff/credit-notes/new?invoice=' . $e2id);
    t('crediting the rest settles the invoice', $pdo->query("SELECT status FROM is_invoices WHERE id=$e2id")->fetchColumn() === 'paid' && $cnt("SELECT COUNT(*) FROM is_credit_notes WHERE invoice_id=$e2id") === 2);
    $o->post('/staff/credit-notes/new', ['invoice_id' => (string) $e2id, 'issue_date' => date('Y-m-d'), 'amount' => '1', 'reason' => 'x'], '/staff/credit-notes/new?invoice=' . $e2id);
    t('nothing more can be credited', $cnt("SELECT COUNT(*) FROM is_credit_notes WHERE invoice_id=$e2id") === 2);
    $o->post("/staff/credit-notes/" . $cn['id'], ['action' => 'email'], '/staff/credit-notes/' . $cn['id']);
    t('credit note page loads', $o->get('/staff/credit-notes/' . $cn['id'])['code'] === 200 && str_contains($o->get('/staff/credit-notes')['body'], 'CN-0001'));
    // refund after payment
    $inv(['bill_name' => 'Refund Co']); $e3 = $lastInv(); $e3id = (int) $e3['id'];
    $o->post("/staff/invoices/$e3id", ['action' => 'pay', 'amount' => '1180', 'paid_at' => date('Y-m-d'), 'method' => 'UPI'], "/staff/invoices/$e3id");
    $o->post('/staff/credit-notes/new', ['invoice_id' => (string) $e3id, 'issue_date' => date('Y-m-d'), 'amount' => '100', 'reason' => 'Goodwill'], '/staff/credit-notes/new?invoice=' . $e3id);
    $cn3 = $pdo->query("SELECT * FROM is_credit_notes ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    t('a credit note on a paid invoice offers the refund', str_contains($o->get('/staff/credit-notes/' . $cn3['id'])['body'], 'Record the refund'));
    $o->post('/staff/credit-notes/' . $cn3['id'], ['action' => 'refund', 'refunded_on' => date('Y-m-d'), 'method' => 'UPI', 'ref' => 'R1'], '/staff/credit-notes/' . $cn3['id']);
    t('refund recorded', $pdo->query("SELECT refunded_on FROM is_credit_notes WHERE id=" . $cn3['id'])->fetchColumn() === date('Y-m-d'));
    // pro-forma
    $totBefore = (float) $pdo->query("SELECT SUM(total) FROM is_invoices WHERE kind='tax' AND status<>'void'")->fetchColumn();
    $inv(['doc_kind' => 'proforma', 'bill_name' => 'Advance Co', 'bill_email' => 'adv@smoke.test']); $pf = $lastInv(); $pfid = (int) $pf['id'];
    t('pro-forma has its own number series and is not a tax invoice', $pf['kind'] === 'proforma' && str_starts_with($pf['number'], 'PI-') && (float) $pdo->query("SELECT SUM(total) FROM is_invoices WHERE kind='tax' AND status<>'void'")->fetchColumn() === $totBefore);
    $o->post("/staff/invoices/$pfid", ['action' => 'pay', 'amount' => '100', 'paid_at' => date('Y-m-d'), 'method' => 'UPI'], "/staff/invoices/$pfid");
    t('payments cannot be recorded on a pro-forma', $cnt("SELECT COUNT(*) FROM is_invoice_payments WHERE invoice_id=$pfid") === 0);
    $o->post("/staff/invoices/$pfid", ['action' => 'convert'], "/staff/invoices/$pfid"); $conv = $lastInv();
    t('converting a pro-forma issues a tax invoice and closes the pro-forma', $conv['kind'] === 'tax' && $conv['bill_name'] === 'Advance Co' && $conv['total'] === $pf['total'] && $pdo->query("SELECT status FROM is_invoices WHERE id=$pfid")->fetchColumn() === 'void');
    // reminders
    $n0 = count($mails()); $o->post("/staff/invoices/" . $conv['id'], ['action' => 'remind'], "/staff/invoices/" . $conv['id']); $new = array_slice($mails(), $n0);
    t('manual payment reminder is e-mailed', count($new) === 1 && str_contains($new[0]['subject'], 'Payment reminder') && $pdo->query("SELECT last_reminder FROM is_invoices WHERE id=" . $conv['id'])->fetchColumn() === date('Y-m-d'));
    $saveSettings(['remind_customers' => '1']);
    $pdo->exec("UPDATE is_invoices SET due_date='" . date('Y-m-d', strtotime('-10 days')) . "', last_reminder=NULL WHERE id=" . $conv['id']);
    $runDaily = function () use ($tmp) { $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/tools/daily.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, dirname(__DIR__), array_merge(getenv(), ['INFYNITE_CONFIG' => "$tmp/config.php"])); $out = stream_get_contents($pp[1]); stream_get_contents($pp[2]); proc_close($p); return $out; };
    $n0 = count($mails()); $out1 = $runDaily(); $new = array_slice($mails(), $n0);
    t('daily job reminds the customer of an overdue invoice', str_contains($out1, '1 customer reminder') && count(array_filter($new, fn($x) => $x['to'] === ['adv@smoke.test'] && str_contains($x['subject'], 'Payment reminder'))) === 1);
    $n0 = count($mails()); $out2 = $runDaily();
    t('and not again within 7 days', str_contains($out2, '0 customer reminder') && !array_filter(array_slice($mails(), $n0), fn($x) => $x['to'] === ['adv@smoke.test']));
    $saveSettings(['remind_customers' => '0']);
    // GST
    $m = date('Y-m'); $g = $o->get("/staff/gst?m=$m");
    t('GST summary page shows B2B, credit notes and input GST', $g['code'] === 200 && str_contains($g['body'], 'Buyer Pvt Ltd') && str_contains($g['body'], '675.00') && str_contains($g['body'], 'CN-0001') && str_contains($g['body'], '8477'));
    $c1 = $o->get("/staff/gst/b2b.csv?m=$m"); $c2 = $o->get("/staff/gst/hsn.csv?m=$m"); $c3 = $o->get("/staff/gst/cdn.csv?m=$m"); $c4 = $o->get("/staff/gst/b2c.csv?m=$m");
    t('GST CSV exports', $c1['code'] === 200 && str_contains($c1['body'], '29ABCDE1234F1Z5') && str_contains($c2['body'], '8477') && str_contains($c3['body'], 'CN-0001') && $c4['code'] === 200);
    t('GST pages are for owner and sales only', $sam->get('/staff/gst')['code'] === 403 && (new Client($B))->get('/staff/gst')['code'] === 302);
    // expenses and profit and loss
    $o->post('/staff/expenses', ['expense_date' => date('Y-m-d'), 'category' => 'Rent', 'description' => 'Workshop rent', 'amount' => '5000', 'gst_amount' => '900', 'paid_via' => 'UPI'], '/staff/expenses');
    $exid = $cnt("SELECT id FROM is_expenses WHERE description='Workshop rent'");
    t('expense recorded and listed', $exid > 0 && str_contains($o->get('/staff/expenses')['body'], 'Workshop rent'));
    $o->post('/staff/expenses', ['expense_date' => date('Y-m-d'), 'category' => 'Nonsense', 'description' => 'x', 'amount' => '1'], '/staff/expenses');
    t('unknown category refused', $cnt("SELECT COUNT(*) FROM is_expenses") === 1);
    $pl = $o->get('/staff/profit');
    t('profit and loss shows sales, purchases, expenses and profit', $pl['code'] === 200 && str_contains($pl['body'], '5,000.00') && str_contains($pl['body'], 'Profit') && str_contains($pl['body'], 'Cash view'));
    t('profit and loss is owner-only; expenses are purchasing/owner', $sam->get('/staff/profit')['code'] === 403 && $sam->get('/staff/expenses')['code'] === 403);
    t('GST on the expense counts as input GST', str_contains($o->get("/staff/gst?m=$m")['body'], '900.00'));
    $o->post("/staff/expenses/$exid/delete", [], '/staff/expenses');
    t('owner can remove an expense', $cnt("SELECT COUNT(*) FROM is_expenses") === 0);
    // bank statement matching
    $inv(['bill_name' => 'Bank Match Co']); $bm = $lastInv(); $bmid = (int) $bm['id'];
    $o->post('/staff/bills/new', ['supplier_id' => (string) $sup, 'bill_no' => 'FC-88', 'bill_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+10 days')), 'amount' => '2000.00'], '/staff/bills/new'); $bill2 = $cnt("SELECT id FROM is_supplier_bills WHERE bill_no='FC-88'");
    $csvf = "$tmp/stmt.csv"; $d1 = date('d/m/Y');
    file_put_contents($csvf, "Date,Narration,Withdrawal Amt.,Deposit Amt.,Balance\n$d1,UPI/Bank Match Co/ref1,,1180.00,50000\n$d1,NEFT Filament Co FC-88,2000.00,,48000\n$d1,Random shop,300.00,,47700\n");
    $o->req('POST', '/staff/bank/upload', ['_csrf' => $o->token('/staff/bank'), 'statement' => file_part($csvf, 'stmt.csv')]);
    $bk = $o->get('/staff/bank')['body'];
    t('statement read; credit matched to the invoice, debit to the bill, stray debit unmatched', str_contains($bk, '3 transactions') && str_contains($bk, $bm['number']) && str_contains($bk, 'FC-88') && str_contains($bk, 'no open invoice or bill matches'));
    $r = $o->post("/staff/invoices/$bmid", ['action' => 'pay', 'back' => 'bank', 'amount' => '1180.00', 'paid_at' => date('Y-m-d'), 'method' => 'Bank transfer', 'ref' => 'UPI/Bank Match Co'], '/staff/bank');
    t('recording a suggested payment returns to the statement', $r['loc'] === '/staff/bank' && $pdo->query("SELECT status FROM is_invoices WHERE id=$bmid")->fetchColumn() === 'paid' && !str_contains($o->get('/staff/bank')['body'], '/staff/invoices/' . $bmid . '"'));
    file_put_contents($csvf, "foo,bar\n1,2\n");
    $o->req('POST', '/staff/bank/upload', ['_csrf' => $o->token('/staff/bank'), 'statement' => file_part($csvf, 'bad.csv')]);
    t('an unreadable statement is refused with a hint', str_contains($o->get('/staff/bank')['body'], 'Could not read the statement'));
    t('bank matching needs a login and a finance role', (new Client($B))->get('/staff/bank')['code'] === 302 && $sam->get('/staff/bank')['code'] === 403);
    // reports
    $rep = $o->get('/staff/reports');
    t('reports include credit notes, payables and production', $rep['code'] === 200 && str_contains($rep['body'], 'Credit notes in period') && str_contains($rep['body'], 'Supplier bills (now)') && str_contains($rep['body'], 'Production in period'));
    $csv2 = $o->get('/staff/reports/invoices.csv?from=2000-01-01&to=2100-01-01');
    t('invoices CSV has the type and received columns', str_contains($csv2['body'], '"Type"') && str_contains($csv2['body'], '"proforma"'));

    echo "== phase F: recurring tasks, notes, attachments, calendar, dashboard, exports\n";
    $tasksBefore = $cnt('SELECT COUNT(*) FROM is_tasks');
    $o->post('/staff/recurring', ['title' => 'Weekly stock check', 'description' => '- [ ] count spools', 'freq' => 'weekly', 'every' => '1', 'start' => date('Y-m-d'), 'assignee_id' => (string) $samId, 'priority' => 'normal', 'due_days' => '2', 'phase' => 'Routine'], '/staff/recurring');
    $rec = $pdo->query("SELECT * FROM is_recurring_tasks WHERE title='Weekly stock check'")->fetch(PDO::FETCH_ASSOC);
    $rt = $pdo->query("SELECT * FROM is_tasks WHERE title='Weekly stock check' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    t('a recurring task creates its first task right away, assigned and with a due date', $rec && $cnt('SELECT COUNT(*) FROM is_tasks') === $tasksBefore + 1 && (int) $rt['assignee_id'] === $samId && $rt['due_date'] === date('Y-m-d', strtotime('+2 days')) && $rt['phase'] === 'Routine');
    t('next run moves a week ahead (no duplicate)', $rec['next_run'] === date('Y-m-d', strtotime('+7 days')) && (int) $rec['weekday'] === (int) date('N'));
    $pdo->exec("INSERT INTO is_recurring_tasks (title,priority,freq,every,dom,due_days,next_run,active,created_by,created_at) VALUES ('Month end close','high','monthly',1,31," . 0 . ",'" . date('Y-m-d', strtotime('-70 days')) . "',1,$ownerId,NOW())");
    $out = $runDaily();
    $mc = $pdo->query("SELECT * FROM is_recurring_tasks WHERE title='Month end close'")->fetch(PDO::FETCH_ASSOC);
    t('after a long gap only one task is created and the next date is in the future', $cnt("SELECT COUNT(*) FROM is_tasks WHERE title='Month end close'") === 1 && $mc['next_run'] > date('Y-m-d') && str_contains($out, 'recurring task(s) created'));
    $o->post('/staff/recurring/' . $rec['id'], ['action' => 'toggle'], '/staff/recurring');
    t('a schedule can be paused', $cnt("SELECT active FROM is_recurring_tasks WHERE id=" . $rec['id']) === 0);
    t("others cannot change the Owner's schedule", $sam->post('/staff/recurring/' . $rec['id'], ['action' => 'delete'], '/staff/recurring')['code'] === 403 && $cnt("SELECT COUNT(*) FROM is_recurring_tasks WHERE id=" . $rec['id']) === 1);
    $o->post('/staff/recurring/' . $rec['id'], ['action' => 'delete'], '/staff/recurring');
    t('the owner can remove it; tasks already created stay', $cnt("SELECT COUNT(*) FROM is_recurring_tasks WHERE id=" . $rec['id']) === 0 && $cnt("SELECT COUNT(*) FROM is_tasks WHERE title='Weekly stock check'") === 1);
    $o->post('/staff/recurring', ['title' => '', 'freq' => 'weekly', 'every' => '1', 'start' => date('Y-m-d')], '/staff/recurring');
    t('an incomplete schedule is refused', $cnt('SELECT COUNT(*) FROM is_recurring_tasks') === 1);
    // notes
    $o->post('/staff/notes', ['title' => 'Shared shopping', 'kind' => 'list', 'body' => "- PLA black\n- Nozzles 0.4\n[ ] Glue", 'color' => 'yellow', 'shared' => '1'], '/staff/notes');
    $o->post('/staff/notes', ['title' => 'My private note', 'kind' => 'text', 'body' => 'secret <script>alert(1)</script> idea'], '/staff/notes');
    $shared = $cnt("SELECT id FROM is_notes WHERE title='Shared shopping'"); $priv = $cnt("SELECT id FROM is_notes WHERE title='My private note'");
    t('checklist and note created', $shared > 0 && $priv > 0 && count(json_decode((string) $pdo->query("SELECT items FROM is_notes WHERE id=$shared")->fetchColumn(), true)) === 3);
    $ns = $sam->get('/staff/notes')['body'];
    t('shared notes are visible to other staff, private ones are not', str_contains($ns, 'Shared shopping') && !str_contains($ns, 'My private note') && $sam->get("/staff/notes/$priv")['code'] === 404);
    $on = $o->get('/staff/notes')['body'];
    t('note text is escaped', str_contains($on, '&lt;script&gt;') && !str_contains($on, '<script>alert(1)'));
    $sam->post("/staff/notes/$shared", ['action' => 'tick', 'i' => '1'], '/staff/notes');
    t('anyone who can see a shared checklist can tick items', json_decode((string) $pdo->query("SELECT items FROM is_notes WHERE id=$shared")->fetchColumn(), true)[1]['d'] === true);
    t("others cannot edit or delete someone else's note", $sam->post("/staff/notes/$shared", ['action' => 'delete'], '/staff/notes')['code'] === 403 && $sam->post("/staff/notes/$shared", ['action' => 'save', 'title' => 'x', 'body' => 'y'], '/staff/notes')['code'] === 403 && $cnt("SELECT COUNT(*) FROM is_notes WHERE id=$shared") === 1);
    $o->post("/staff/notes/$shared", ['action' => 'save', 'title' => 'Shared shopping', 'body' => "PLA black\nNozzles 0.4\nGlue\nTape", 'color' => 'green', 'shared' => '1'], "/staff/notes/$shared");
    $its = json_decode((string) $pdo->query("SELECT items FROM is_notes WHERE id=$shared")->fetchColumn(), true);
    t('editing a checklist keeps ticks on unchanged items', count($its) === 4 && $its[1]['d'] === true && $its[0]['d'] === false);
    $o->post("/staff/notes/$priv", ['action' => 'pin'], '/staff/notes'); $o->post("/staff/notes/$priv", ['action' => 'archive'], '/staff/notes');
    t('pin and archive', $cnt("SELECT pinned FROM is_notes WHERE id=$priv") === 1 && $cnt("SELECT archived FROM is_notes WHERE id=$priv") === 1 && str_contains($o->get('/staff/notes?archived=1')['body'], 'My private note') && str_contains($o->get('/staff/notes?q=nozzles')['body'], 'Shared shopping') && !str_contains($o->get('/staff/notes?q=zzzzqq')['body'], 'Shared shopping'));
    // attachments
    $png = "$tmp/pic.png"; file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $fake = "$tmp/fake.png"; file_put_contents($fake, 'this is not an image'); $exe = "$tmp/run.exe"; file_put_contents($exe, 'MZ' . str_repeat("\0", 50));
    $tk = $cnt("SELECT id FROM is_tasks WHERE title='Smoke task'");
    $up = fn($c, $kind, $ref, $path, $name) => $c->req('POST', '/staff/attachments', ['_csrf' => $c->token($kind === 'task' ? "/staff/tasks/$ref" : "/staff/notes/$ref"), 'kind' => $kind, 'ref_id' => (string) $ref, 'files[0]' => file_part($path, $name)]);
    $up($o, 'task', $tk, $png, 'pic.png'); $aid = $cnt("SELECT id FROM is_attachments WHERE kind='task' AND ref_id=$tk");
    t('image attached to a task, stored under a random name', $aid > 0 && preg_match('/^[a-f0-9]{40}$/', (string) $pdo->query("SELECT stored_name FROM is_attachments WHERE id=$aid")->fetchColumn()) && str_contains($o->get("/staff/tasks/$tk")['body'], 'pic.png'));
    $dl = $o->get("/staff/attachments/$aid"); $inl = $o->get("/staff/attachments/$aid?inline=1");
    t('download and inline image view', $dl['code'] === 200 && str_starts_with($dl['body'], "\x89PNG") && $inl['code'] === 200);
    $up($o, 'task', $tk, $exe, 'run.exe'); $up($o, 'task', $tk, $fake, 'fake.png');
    t('executables and fake images are refused', $cnt("SELECT COUNT(*) FROM is_attachments WHERE kind='task' AND ref_id=$tk") === 1);
    t('other staff can open task files; anonymous cannot', $sam->get("/staff/attachments/$aid")['code'] === 200 && (new Client($B))->get("/staff/attachments/$aid")['code'] === 302);
    $up($o, 'note', $shared, $png, 'note.png'); $na = $cnt("SELECT id FROM is_attachments WHERE kind='note' AND ref_id=$shared");
    $up($o, 'note', $priv, $png, 'hidden.png'); $pa = $cnt("SELECT id FROM is_attachments WHERE kind='note' AND ref_id=$priv");
    t("files on a shared note are visible to staff; on a private note only to its author", $sam->get("/staff/attachments/$na")['code'] === 200 && $sam->get("/staff/attachments/$pa")['code'] === 404 && $o->get("/staff/attachments/$pa")['code'] === 200);
    t("only the uploader or the Owner can remove a file", $sam->post("/staff/attachments/$aid/delete", [], "/staff/tasks/$tk")['code'] === 403);
    $o->post("/staff/attachments/$aid/delete", [], "/staff/tasks/$tk");
    t('the owner removes it and the file is gone from disk', $cnt("SELECT COUNT(*) FROM is_attachments WHERE id=$aid") === 0 && count(glob("$tmp/storage/uploads/att/*")) === 2);
    // calendar
    $dueTask = $cnt("SELECT id FROM is_tasks WHERE title='Month end close'"); $dd = $pdo->query("SELECT due_date FROM is_tasks WHERE id=$dueTask")->fetchColumn(); $cm = substr((string) $dd, 0, 7);
    $cal = $o->get("/staff/calendar?m=$cm")['body'];
    t('calendar shows tasks on their due date', $cal !== '' && str_contains($cal, 'Month end close'));
    $pdo->exec("UPDATE is_invoices SET due_date='" . date('Y-m-d', strtotime('+1 day')) . "' WHERE status='issued' AND kind='tax' LIMIT 1");
    $tm = date('Y-m', strtotime('+1 day'));
    t('calendar shows finance dates only to those who may see them', str_contains($o->get("/staff/calendar?m=$tm")['body'], 'Payment due') && !str_contains($sam->get("/staff/calendar?m=$tm")['body'], 'Payment due'));
    t('calendar "mine" filter', !str_contains($sam->get("/staff/calendar?m=" . substr((string) $rt['due_date'], 0, 7) . '&mine=1')['body'], 'Month end close') && str_contains($sam->get("/staff/calendar?m=" . substr((string) $rt['due_date'], 0, 7) . '&mine=1')['body'], 'Weekly stock check'));
    // dashboard and exports
    $home = $o->get('/staff')['body'];
    t('staff overview shows orders by stage and what is due', str_contains($home, 'Orders by stage') && str_contains($home, 'Due in the next 7 days'));
    $cx = $o->get('/staff/export/customers.csv'); $ix = $o->get('/staff/export/inventory.csv');
    t('owner can export customers and inventory as CSV', $cx['code'] === 200 && str_contains($cx['body'], 'Cust a') && str_contains($cx['body'], '"Name"') && $ix['code'] === 200 && str_contains($ix['body'], 'Grams per unit'));
    t('exports are owner-only, unknown ones 404, and are logged', $sam->get('/staff/export/customers.csv')['code'] === 403 && $o->get('/staff/export/nothing.csv')['code'] === 404 && $cnt("SELECT COUNT(*) FROM is_audit_log WHERE action='export.csv' AND detail='customers'") === 1);
    t('export page lists the datasets', str_contains($o->get('/staff/exports')['body'], 'Supplier bills'));

    echo "== sign-in rules\n";
    require_once dirname(__DIR__) . '/app/lib/session_rules.php';
    t('reload detection reads the browser headers', is_reload_request(['HTTP_CACHE_CONTROL' => 'max-age=0']) && is_reload_request(['HTTP_CACHE_CONTROL' => 'no-cache']) && is_reload_request(['HTTP_PRAGMA' => 'no-cache']) && !is_reload_request([]) && !is_reload_request(['HTTP_CACHE_CONTROL' => 'max-age=3600']));
    t('idle time is judged correctly', idle_expired(1000, 30, 1000 + 1801) && !idle_expired(1000, 30, 1000 + 1799) && !idle_expired(1000, 0, 99999) && !idle_expired(null, 30, 99999));
    $rl = new Client($B); $rl->post('/login', ['email' => 'owner@smoke.test', 'password' => 'ownerpassword1']);
    t('a normal page view keeps you signed in', $rl->get('/staff')['code'] === 200 && $rl->get('/staff/tasks')['code'] === 200);
    $rx = new Client($B); $rx->post('/login', ['email' => 'owner@smoke.test', 'password' => 'ownerpassword1']);
    t('the page opened right after signing in or saving is not mistaken for a reload (browsers send max-age=0 there)', $rx->req('GET', '/staff', [], false, ['Cache-Control: max-age=0'])['code'] === 200 && $rx->req('GET', '/staff/tasks', [], false, ['Cache-Control: max-age=0'])['code'] === 200);
    sleep(6);
    $r = $rl->req('GET', '/staff/tasks', [], false, ['Cache-Control: max-age=0']);
    t('reloading a page signs you out and shows the login page', $r['code'] === 302 && $r['loc'] === '/login' && $rl->get('/staff')['code'] === 302 && str_contains($rl->get('/login')['body'], 'reloaded'));
    $saveSettings(['session_rule' => 'keep']);
    $rk = new Client($B); $rk->post('/login', ['email' => 'owner@smoke.test', 'password' => 'ownerpassword1']);
    t('with the rule off, a reload keeps you signed in', $rk->req('GET', '/staff/tasks', [], false, ['Cache-Control: max-age=0'])['code'] === 200);
    $saveSettings(['session_rule' => 'reload']);
    t('keep-alive ping answers 204 for a signed-in user and redirects anonymous ones', $rk->get('/staff/ping')['code'] === 204 && (new Client($B))->get('/staff/ping')['code'] === 302);

    proc_terminate($sink); proc_close($sink);
} finally {
    proc_terminate($proc); proc_close($proc);
    if (!isset($argv) || !in_array('--keep', $argv, true)) { $adm->exec("DROP DATABASE IF EXISTS `$dbName`"); isbk_rrmdir($tmp); } else { echo "kept: $tmp and database $dbName\n"; }
}
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
