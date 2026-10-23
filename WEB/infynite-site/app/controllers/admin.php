<?php
/**
 * Owner-only business settings, behind a second secret: each admin has their OWN admin key.
 * The unlock lasts 30 seconds of inactivity (config 'admin_unlock_seconds'). The page script (data-admin-autolock) also locks at once when
 * the browser tab is switched away or the person is idle for 30 s, and sends a heartbeat (/staff/admin/ping) while they are active.
 */
function admin_unlock_seconds(): int { return max(5, (int) cfg('admin_unlock_seconds', 30)); }

function admin_unlocked(): bool
{
    $t = $_SESSION['admin_until'] ?? 0;
    if ($t > time()) { $_SESSION['admin_until'] = time() + admin_unlock_seconds(); $GLOBALS['admin_page'] = true; return true; }
    unset($_SESSION['admin_until']);
    return false;
}
function admin_key_name(?array $u = null): string { return 'admin_key:' . (int) (($u ?? user())['id'] ?? 0); }
function admin_key_hash(): ?string { return setting(admin_key_name()); }

/** Guard for the sensitive admin pages (Backups, Integrations, exports...): Owner role AND this admin's personal key unlocked. Others get 403 / the lock screen. */
function admin_guard(string $tab = 'settings', string $title = 'Admin settings'): array
{
    $u = require_staff('team.manage');
    $hasKey = admin_key_hash() !== null;
    if ($hasKey && admin_unlocked()) { return $u; }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { flash('Enter your admin key first.', 'bad'); redirect('/staff/admin'); }
    // Show the key prompt in place (the sidebar keeps highlighting the page that was opened); unlocking returns here.
    $r = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'); $bp = base_path();
    $_SESSION['admin_return'] = $bp !== '' && str_starts_with($r, $bp) ? substr($r, strlen($bp)) : $r;
    render('staff/admin_lock', ['title' => $title, 'nav' => 'admin', 'atab' => $tab, 'hasKey' => $hasKey, 'u' => $u, 'pageTitle' => $title], 'staff');
    exit;
}

/** Where to go after unlocking: the protected page the user was trying to open (only our own /staff pages), else the Admin settings. */
function admin_return_url(): string
{
    $r = (string) ($_SESSION['admin_return'] ?? ''); unset($_SESSION['admin_return']);
    return preg_match('~^/staff/[A-Za-z0-9/_.-]*$~', $r) && !str_contains($r, '//') ? $r : '/staff/admin';
}

function admin_page(): void
{
    $u = require_staff('team.manage');
    $hasKey = admin_key_hash() !== null;
    if (!$hasKey || !admin_unlocked()) {
        render('staff/admin_lock', ['title' => 'Admin settings', 'nav' => 'admin', 'atab' => 'settings', 'hasKey' => $hasKey, 'u' => $u], 'staff');
        return;
    }
    render('staff/admin', ['title' => 'Admin settings', 'nav' => 'admin', 'atab' => 'settings', 'u' => $u, 'vals' => settings_all()], 'staff');
}

function admin_unlock(): void
{
    $u = require_staff('team.manage');
    $key = (string) ($_POST['admin_key'] ?? '');
    $hash = admin_key_hash();
    if ($hash === null) {
        $key2 = (string) ($_POST['admin_key2'] ?? '');
        if (mb_strlen($key) < 12) { flash('The admin key must be at least 12 characters.', 'bad'); redirect('/staff/admin'); }
        if ($key !== $key2) { flash('The two keys do not match.', 'bad'); redirect('/staff/admin'); }
        setting_set(admin_key_name($u), password_hash($key, PASSWORD_DEFAULT));
        audit('admin.key_set');
        notify_admins('Admin key created by ' . $u['name'], "{$u['name']} ({$u['email']}) created their personal admin key for the settings page.\nIf this was not expected, speak to them and to the other admins.", (int) $u['id']);
        $_SESSION['admin_until'] = time() + admin_unlock_seconds();
        flash('Your admin key is set. Keep it safe: it is needed every time and cannot be recovered, only reset from the server.');
        redirect(admin_return_url());
    }
    $id = 'u' . $u['id'];
    if (rate_limited('adminkey', $id, 20, 5, 15)) { flash('Too many attempts. Try again in 15 minutes.', 'bad'); redirect('/staff/admin'); }
    note_attempt('adminkey', $id);
    if (!password_verify($key, $hash)) { audit('admin.unlock_failed'); flash('Wrong admin key.', 'bad'); redirect('/staff/admin'); }
    q('DELETE FROM is_login_attempts WHERE kind=? AND email=?', ['adminkey', $id]);
    session_regenerate_id(true);
    $_SESSION['admin_until'] = time() + admin_unlock_seconds();
    audit('admin.unlock');
    redirect(admin_return_url());
}

function admin_lock(): void
{
    require_staff('team.manage');
    unset($_SESSION['admin_until']);
    $_SESSION['reload_ok'] = time();   // the page script reloads right after locking (maybe much later, when the tab is shown again): that reload must not sign the person out
    flash('Admin settings locked.');
    redirect('/staff/admin');
}
/** Heartbeat from the page while the person is active: keeps the short unlock alive. 204 = still unlocked, 403 = locked. */
function admin_ping(): void
{
    $u = user();
    if (!$u || !is_staff($u) || !can('team.manage', $u) || !admin_unlocked()) { if ($u) { $_SESSION['reload_ok'] = time(); } http_response_code(403); return; }   // 403 makes the page script reload to show the key prompt
    header('Cache-Control: no-store'); http_response_code(204);
}

function admin_save(): void
{
    require_staff('team.manage');
    if (!admin_unlocked()) { flash('Enter the admin key first.', 'bad'); redirect('/staff/admin'); }
    $errors = []; $new = []; $secrets = [];
    foreach (SETTING_GROUPS as $fields) {
        foreach ($fields as $k => $def) {
            [$label, $type, $max] = $def;
            $raw = (string) ($_POST[$k] ?? '');
            if ($type === 'select' && $raw !== '' && !isset($def[3][$raw])) { $errors[] = "$label: choose one of the options"; continue; }
            [$v, $err] = setting_clean($type, $max, $raw);
            if ($err) { $errors[] = "$label: $err"; }
            if ($type === 'secret') { if ($v !== '') { $secrets[$k] = $k === 'smtp_pass' ? preg_replace('/\s+/', '', $v) : $v; } continue; }
            $new[$k] = $v;
        }
    }
    if (($new['mail_mode'] ?? '') === 'smtp' && (($new['smtp_host'] ?? '') === '' || ($new['mail_from'] ?? '') === '')) { $errors[] = 'SMTP mode needs the server and the sender address.'; }
    $port = (int) ($new['smtp_port'] ?? 0); $enc = $new['smtp_enc'] ?? '';
    if (($new['mail_mode'] ?? '') === 'smtp') {
        if ($enc === 'ssl' && $port === 587) { $errors[] = 'Port 587 uses STARTTLS: set Encryption to "STARTTLS" (or use SSL with port 465).'; }
        if ($enc === 'tls' && $port === 465) { $errors[] = 'Port 465 uses SSL/TLS: set Encryption to "SSL/TLS" (or use STARTTLS with port 587).'; }
        if (stripos((string) ($new['smtp_host'] ?? ''), 'gmail.com') !== false && ($new['smtp_user'] ?? '') !== '' && strcasecmp((string) $new['smtp_user'], (string) ($new['mail_from'] ?? '')) !== 0) { $errors[] = 'For Gmail, the sender address should be the same as the SMTP username (or a verified alias of it).'; }
    }
    if ($errors) { flash(implode(' · ', $errors), 'bad'); redirect('/staff/admin'); }
    $changed = [];
    foreach ($new as $k => $v) {
        if ((string) setting($k, '') !== $v) { setting_set($k, $v === '' ? null : $v); $changed[] = $k; }
    }
    foreach ($secrets as $k => $v) { setting_set($k, secret_encrypt($v)); $changed[] = $k; }
    audit('admin.settings', $changed ? implode(',', $changed) : 'no change');
    flash($changed ? 'Settings saved.' : 'Nothing changed.');
    redirect('/staff/admin');
}

function admin_test_mail(): void
{
    $u = require_staff('team.manage');
    if (!admin_unlocked()) { flash('Enter the admin key first.', 'bad'); redirect('/staff/admin'); }
    $to = strtolower(trim((string) ($_POST['to'] ?? $u['email'])));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { flash('Enter a valid address to send the test to.', 'bad'); redirect('/staff/admin'); }
    $err = null;
    $c = mail_config();
    $ok = send_mail($to, 'Test e-mail from ' . setting('app_name', cfg('app_name')), "This is a test message sent by {$u['name']} from the Admin settings page.\nIf you can read it, e-mail is working.", $err);
    audit('admin.test_mail', $to);
    if ($c['mode'] !== 'smtp') { flash('Mode is "Log only", so nothing was sent; the message is in storage/logs/mail.log. Switch to SMTP and save to send real e-mail.', 'bad'); }
    elseif ($ok) { flash("Test e-mail sent to $to. Check the inbox (and spam)."); }
    else { flash('The test failed: ' . ($err ?: 'unknown error') . '. The message was written to storage/logs/mail.log.', 'bad'); }
    redirect('/staff/admin');
}

function admin_change_key(): void
{
    $u = require_staff('team.manage');
    if (!admin_unlocked()) { redirect('/staff/admin'); }
    $cur = (string) ($_POST['current'] ?? ''); $n1 = (string) ($_POST['new1'] ?? ''); $n2 = (string) ($_POST['new2'] ?? '');
    if (!password_verify($cur, (string) admin_key_hash())) { flash('Current admin key is wrong.', 'bad'); redirect('/staff/admin'); }
    if (mb_strlen($n1) < 12 || $n1 !== $n2) { flash('New key must be at least 12 characters and typed twice the same.', 'bad'); redirect('/staff/admin'); }
    setting_set(admin_key_name($u), password_hash($n1, PASSWORD_DEFAULT));
    audit('admin.key_changed');
    notify_admins('Admin key changed by ' . $u['name'], "{$u['name']} ({$u['email']}) changed their personal admin key on " . date('d M Y H:i') . ".\nIf this was not expected, speak to them and to the other admins.", (int) $u['id']);
    flash('Your admin key was changed. The other admins have been told by e-mail.');
    redirect('/staff/admin');
}

/* ---- who gets which notification ---- */
function admin_notify_page(): void
{
    $u = require_staff('team.manage');
    if (!admin_key_hash() || !admin_unlocked()) { redirect('/staff/admin'); }
    $rules = [];
    foreach (all('SELECT event,user_id FROM is_notify_rules') as $r) { $rules[$r['event']][(int) $r['user_id']] = true; }
    render('staff/admin_notify', ['title' => 'Notifications', 'nav' => 'admin', 'atab' => 'settings', 'u' => $u, 'people' => all("SELECT id,name,email,role FROM is_users WHERE role<>'customer' AND active=1 ORDER BY name"), 'rules' => $rules, 'smtp' => mail_config()['mode'] === 'smtp'], 'staff');
}
function admin_notify_save(): void
{
    require_staff('team.manage');
    if (!admin_unlocked()) { redirect('/staff/admin'); }
    $ids = array_map('intval', array_column(all("SELECT id FROM is_users WHERE role<>'customer' AND active=1"), 'id'));
    $pdo = db(); $pdo->beginTransaction();
    q('DELETE FROM is_notify_rules');
    foreach (NOTIFY_EVENTS as $ev => $_) {
        foreach ((array) ($_POST['n'][$ev] ?? []) as $uid) {
            if (in_array((int) $uid, $ids, true)) { q('INSERT IGNORE INTO is_notify_rules (event,user_id) VALUES (?,?)', [$ev, (int) $uid]); }
        }
    }
    $pdo->commit();
    audit('admin.notify_rules');
    flash('Notification rules saved.');
    redirect('/staff/admin/notifications');
}
