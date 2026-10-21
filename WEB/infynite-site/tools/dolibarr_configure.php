<?php
/**
 * Post-install helper for Dolibarr (run on the machine that has Dolibarr; works on Linux/Windows):
 *   php tools/dolibarr_configure.php --htdocs=/path/to/dolibarr/htdocs [--key-file=/safe/place/api_key.txt | --no-key]
 * Enables the modules the portal needs (REST API, third parties, proposals, orders, invoices, products, stock, suppliers) and,
 * unless --no-key, generates a NEW random REST API key for user id 1 (the admin) and writes it ONLY to --key-file (never printed).
 * Generating a key replaces the previous key of that user: update dolibarr.api_key in app/config.php afterwards.
 * For production create a dedicated Dolibarr "api" user with limited rights instead of using the admin's key.
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
$o = []; foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $o[$m[1]] = $m[2] ?? true; } }
$htdocs = isset($o['htdocs']) && is_string($o['htdocs']) ? rtrim($o['htdocs'], '/\\') : null;
if (!$htdocs || !is_file($htdocs . '/master.inc.php')) { fwrite(STDERR, "Give --htdocs=<dolibarr>/htdocs (folder containing master.inc.php)\n"); exit(1); }
if (!isset($o['no-key']) && !is_string($o['key-file'] ?? null)) { fwrite(STDERR, "Give --key-file=PATH (where to store the new API key) or --no-key\n"); exit(1); }
define('NOSESSION', 1); define('NOCSRFCHECK', 1); define('NOLOGIN', 1);
@chdir($htdocs);
require $htdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
$user->fetch(1);
foreach (['modApi', 'modSociete', 'modPropale', 'modCommande', 'modFacture', 'modProduct', 'modStock', 'modFournisseur'] as $m) {
    $r = activateModule($m);
    echo str_pad($m, 16), empty($r['errors']) ? 'enabled' : 'ERROR ' . implode(';', $r['errors']), "\n";
}
if (!isset($o['no-key'])) {
    $key = bin2hex(random_bytes(20));
    $u = new User($db); $u->fetch(1); $u->api_key = $key;
    $res = $u->update($user, 1, 1);
    if ($res <= 0) { fwrite(STDERR, "API key update failed\n"); exit(1); }
    file_put_contents($o['key-file'], $key . "\n"); @chmod($o['key-file'], 0600);
    echo "New API key written to {$o['key-file']} (not shown). Put it in app/config.php -> dolibarr.api_key\n";
}
