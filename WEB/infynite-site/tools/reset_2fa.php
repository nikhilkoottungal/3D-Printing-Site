<?php
/** Turns off two-factor login for a staff member who lost their phone and recovery codes. Usage: php tools/reset_2fa.php email */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
$_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/app/bootstrap.php';
$email = strtolower((string) ($argv[1] ?? ''));
$u = one("SELECT id FROM is_users WHERE email=? AND role<>'customer'", [$email]);
if (!$u) { fwrite(STDERR, "No staff member with that e-mail.\n"); exit(1); }
q('UPDATE is_users SET totp_secret=NULL, totp_enabled=0, totp_last=0, recovery_codes=NULL WHERE id=?', [$u['id']]);
q('INSERT INTO is_audit_log (user_id, action, detail, ip, at) VALUES (NULL,?,?,?,?)', ['me.2fa_reset', $email, 'cli', now()]);
echo "Two-factor login turned off for $email.\n";
