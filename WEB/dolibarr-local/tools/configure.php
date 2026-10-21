<?php
// Local TEST setup helper: enables modules and sets an API key for the admin user. Run: php tools/configure.php
define('NOSESSION', 1); define('NOCSRFCHECK', 1); define('NOLOGIN', 1);
$htdocs = __DIR__ . '/../src/dolibarr-23.0.4/htdocs';
chdir($htdocs . '/scripts');
require $htdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
$user->fetch(1);
foreach (['modApi', 'modSociete', 'modPropale', 'modCommande', 'modFacture', 'modProduct', 'modStock', 'modFournisseur'] as $m) {
    $r = activateModule($m);
    echo $m, ': ', empty($r['errors']) ? 'ok' : implode(';', $r['errors']), "\n";
}
$key = bin2hex(random_bytes(20));
$u = new User($db); $u->fetch(1); $u->api_key = $key;
$res = $u->update($user, 1, 1);
echo "api key update result: $res\n";
file_put_contents(__DIR__ . '/../API_KEY.txt', $key . "\n"); chmod(__DIR__ . '/../API_KEY.txt', 0600);
