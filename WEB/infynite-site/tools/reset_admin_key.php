<?php
/** Server-side reset of admin-settings keys (lost key). Usage: php tools/reset_admin_key.php [email]   (no e-mail = every admin must set a new key) */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
$_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/app/bootstrap.php';
$email = $argv[1] ?? null;
if ($email) {
    $u = one("SELECT id FROM is_users WHERE email=? AND role='owner'", [strtolower($email)]);
    if (!$u) { fwrite(STDERR, "No admin with that e-mail.\n"); exit(1); }
    q('DELETE FROM is_settings WHERE skey=?', ['admin_key:' . $u['id']]);
} else { q("DELETE FROM is_settings WHERE skey LIKE 'admin\\_key:%' OR skey='admin_key_hash'"); }
q('INSERT INTO is_audit_log (user_id, action, detail, ip, at) VALUES (NULL,?,?,?,?)', ['admin.key_reset', $email ?: 'all admins', 'cli', now()]);
echo "Admin key cleared for " . ($email ?: 'all admins') . ". They set a new one at /staff/admin.\n";
