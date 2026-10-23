<?php
/** A staff member's own page: profile, password, two-factor login. */
function me_page(): void
{
    $u = require_staff();
    $codes = $_SESSION['new_recovery_codes'] ?? null; unset($_SESSION['new_recovery_codes']);
    $secret = $_SESSION['totp_pending'] ?? null;
    render('staff/me', ['title' => 'My account', 'nav' => 'me', 'u' => $u, 'codes' => $codes, 'secret' => $secret,
        'uri' => $secret ? totp_uri($secret, $u['email'], (string) setting('app_name', cfg('app_name'))) : null, 'qr' => $secret ? me_qr(totp_uri($secret, $u['email'], (string) setting('app_name', cfg('app_name')))) : null, 'full' => one('SELECT totp_enabled, phone, avatar FROM is_users WHERE id=?', [$u['id']]), 'pr' => hr_profile((int) $u['id']), 'docs' => hr_docs((int) $u['id']), 'openpw' => !empty($_GET['pw'])], 'staff');
}

function me_update(): void
{
    $u = require_staff();
    $a = (string) ($_POST['action'] ?? '');
    $fresh = one('SELECT * FROM is_users WHERE id=?', [$u['id']]);
    if ($a === 'profile') {
        $name = trim((string) ($_POST['name'] ?? '')); $phone = trim((string) ($_POST['phone'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120 || !preg_match('/^[0-9 +()-]{0,30}$/', $phone)) { flash('Enter your name and a valid phone number.', 'bad'); redirect('/staff/me'); }
        q('UPDATE is_users SET name=?, phone=? WHERE id=?', [$name, $phone ?: null, $u['id']]);
        audit('me.profile'); flash('Profile saved.');
    } elseif ($a === 'password') {
        if (rate_limited('pwchange', 'u' . $u['id'], 20, 5, 15)) { flash('Too many attempts. Try again later.', 'bad'); redirect('/staff/me?pw=1'); }
        note_attempt('pwchange', 'u' . $u['id']);
        $n = (string) ($_POST['new1'] ?? '');
        if (!password_verify((string) ($_POST['current'] ?? ''), $fresh['pass_hash'])) { flash('Current password is wrong.', 'bad'); redirect('/staff/me?pw=1'); }
        if ($n !== (string) ($_POST['new2'] ?? '')) { flash('The new passwords do not match.', 'bad'); redirect('/staff/me?pw=1'); }
        if ($p = password_problem($n)) { flash($p, 'bad'); redirect('/staff/me?pw=1'); }
        q('UPDATE is_users SET pass_hash=? WHERE id=?', [password_hash($n, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true); audit('me.password');
        send_mail($fresh['email'], 'Your password was changed', "Hi {$fresh['name']},\n\nYour password was changed on " . date('d M Y H:i') . ".\nIf this was not you, tell an admin at once.");
        flash('Password changed.');
        redirect('/staff/me');
    } elseif ($a === 'details') {
        $nm = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        $errs = $nm === '' ? ['Your name cannot be empty.'] : hr_save((int) $u['id']);
        if (!$errs) { q('UPDATE is_users SET name=? WHERE id=?', [$nm, $u['id']]); }
        if ($errs) { flash(implode(' ', $errs), 'bad'); redirect('/staff/me#details'); }
        audit('me.details'); flash('Your details were saved.'); redirect('/staff/me#details');
    } elseif ($a === 'avatar_set') {
        $err = avatar_set((int) $u['id']); flash($err ?? 'Profile picture updated.', $err ? 'bad' : 'ok'); redirect('/staff/me');
    } elseif ($a === 'avatar_clear') {
        avatar_unlink((int) $u['id']); q('UPDATE is_users SET avatar=NULL WHERE id=?', [$u['id']]); audit('me.avatar_clear'); flash('Profile picture removed.'); redirect('/staff/me');
    } elseif ($a === 'doc_add') {
        $errs = hr_doc_upload((int) $u['id']);
        flash($errs ? implode(' ', $errs) : 'Document saved (stored encrypted).', $errs ? 'bad' : 'ok'); redirect('/staff/me#details');
    } elseif ($a === 'totp_start') {
        if ($fresh['totp_enabled']) { twofa_done('Two-factor login is already on.', 'bad'); }
        $_SESSION['totp_pending'] = totp_new_secret(); twofa_done();
    } elseif ($a === 'totp_confirm') {
        $secret = $_SESSION['totp_pending'] ?? null;
        $c = $secret ? totp_verify($secret, (string) ($_POST['code'] ?? '')) : null;
        if (!$c) { twofa_done('That code is not right. Check the authenticator app and try again.', 'bad'); }
        $codes = []; $hashes = [];
        for ($i = 0; $i < 8; $i++) { $raw = strtolower(substr(b32_encode(random_bytes(7)), 0, 10)); $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5); $hashes[] = hash('sha256', $raw); }
        q('UPDATE is_users SET totp_secret=?, totp_enabled=1, totp_last=?, recovery_codes=? WHERE id=?', [secret_encrypt($secret), $c, json_encode($hashes), $u['id']]);
        unset($_SESSION['totp_pending']); $_SESSION['new_recovery_codes'] = $codes;
        audit('me.2fa_on'); twofa_done('Two-factor login is on. Save the recovery codes below.');
    } elseif ($a === 'totp_off') {
        if (rate_limited('totpoff', 'u' . $u['id'], 20, 5, 15)) { twofa_done('Too many attempts. Try again in 15 minutes.', 'bad'); }
        note_attempt('totpoff', 'u' . $u['id']);
        if (!$fresh['totp_enabled']) { twofa_done('Two-factor login is already off.', 'bad'); }
        if (!password_verify((string) ($_POST['current'] ?? ''), $fresh['pass_hash'])) { twofa_done('That password is not right.', 'bad'); }
        $code = trim((string) ($_POST['code'] ?? '')); $ok = totp_verify((string) secret_decrypt($fresh['totp_secret']), $code) !== null;   // the password is also required, so a code from the same 30 s as the login is fine
        if (!$ok) {
            $h = hash('sha256', strtolower(str_replace(['-', ' '], '', $code))); $list = json_decode((string) $fresh['recovery_codes'], true) ?: [];
            $ok = in_array($h, $list, true);
        }
        if (!$ok) { twofa_done('That code is not right. Use the current 6-digit code from the app (check the phone clock is on automatic time), or one of your recovery codes.', 'bad'); }
        q('UPDATE is_users SET totp_secret=NULL, totp_enabled=0, totp_last=0, recovery_codes=NULL WHERE id=?', [$u['id']]);
        q('DELETE FROM is_login_attempts WHERE kind=? AND email=?', ['totpoff', 'u' . $u['id']]);
        audit('me.2fa_off'); twofa_done('Two-factor login is off.');
    } elseif ($a === 'totp_cancel') { unset($_SESSION['totp_pending']); twofa_done(); }
    redirect('/staff/me');
}

/* ---- second step at login ---- */
function login2fa_form(): void
{
    if (empty($_SESSION['pending2fa']) || $_SESSION['pending2fa']['at'] < time() - 300) { unset($_SESSION['pending2fa']); redirect('/login'); }
    render('login_2fa', ['title' => 'Two-factor code']);
}
function login2fa_check(): void
{
    $p = $_SESSION['pending2fa'] ?? null;
    if (!$p || $p['at'] < time() - 300) { unset($_SESSION['pending2fa']); redirect('/login'); }
    $key = 'u' . $p['uid'];
    if (rate_limited('totp', $key, 20, 5, 15)) { render('login_2fa', ['title' => 'Two-factor code', 'errors' => ['Too many attempts. Wait 15 minutes.']]); return; }
    note_attempt('totp', $key);
    $u = one("SELECT * FROM is_users WHERE id=? AND active=1 AND totp_enabled=1", [(int) $p['uid']]);
    $code = trim((string) ($_POST['code'] ?? ''));
    $ok = false;
    if ($u) {
        $c = totp_verify((string) secret_decrypt($u['totp_secret']), $code, (int) $u['totp_last']);
        if ($c) { q('UPDATE is_users SET totp_last=? WHERE id=?', [$c, $u['id']]); $ok = true; }
        else {
            $h = hash('sha256', strtolower(str_replace(['-', ' '], '', $code)));
            $list = json_decode((string) $u['recovery_codes'], true) ?: [];
            if (($i = array_search($h, $list, true)) !== false) { unset($list[$i]); q('UPDATE is_users SET recovery_codes=? WHERE id=?', [json_encode(array_values($list)), $u['id']]); $ok = true; audit('login.recovery_code', $u['email']); }
        }
    }
    if (!$ok) { render('login_2fa', ['title' => 'Two-factor code', 'errors' => ['That code is not right.']]); return; }
    unset($_SESSION['pending2fa']);
    q('DELETE FROM is_login_attempts WHERE kind=? AND email=?', ['totp', $key]);
    login_user($u); audit('login', $u['email']);
    $to = $_SESSION['after_login'] ?? null; unset($_SESSION['after_login']);
    if ($to && $to[0] === '/' && ($to[1] ?? '') !== '/') { redirect($to); }
    redirect('/staff');
}

/** QR code for the authenticator setup link; null when it cannot be drawn (the typed key still works). */
function me_qr(string $uri): ?string { try { return Qr::svg($uri, 5); } catch (Throwable $e) { return null; } }

/** A person deletes their own account for good. Nobody else can do this for them. Admins cannot: they leave by the agreement of all admins. */
function me_delete(): void
{
    $u = require_staff();
    $p = one('SELECT * FROM is_users WHERE id=?', [$u['id']]);
    if ($p['role'] === 'owner') { flash('Admin accounts cannot be deleted here: an admin leaves when every admin agrees (Team & roles, Request removal).', 'bad'); redirect('/staff/me'); }
    if (rate_limited('selfdelete', 'u' . $u['id'], 20, 5, 15)) { flash('Too many attempts. Try again in 15 minutes.', 'bad'); redirect('/staff/me'); }
    note_attempt('selfdelete', 'u' . $u['id']);
    if (!password_verify((string) ($_POST['current'] ?? ''), $p['pass_hash'])) { flash('That password is not right.', 'bad'); redirect('/staff/me?del=1'); }
    if (strcasecmp(trim((string) ($_POST['confirm'] ?? '')), $p['email']) !== 0) { flash('Type your e-mail address exactly to confirm.', 'bad'); redirect('/staff/me?del=1'); }
    $succ = (int) (one("SELECT id FROM is_users WHERE role='owner' AND active=1 ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$succ) { flash('There is no admin to hand shared items to, so the account cannot be deleted.', 'bad'); redirect('/staff/me'); }
    try { account_erase($p, $succ); } catch (Throwable $e) { error_log('self delete failed: ' . $e->getMessage()); flash('Your account could not be deleted. Nothing was changed. Tell an admin.', 'bad'); redirect('/staff/me'); }
    q('INSERT INTO is_audit_log (user_id, action, detail, ip, at) VALUES (NULL,?,?,?,?)', ['account.deleted', 'a team member deleted their own account permanently (#' . $p['id'] . ', ' . $p['name'] . ')', client_ip(), now()]);
    notify_admins('Team member deleted their account: ' . $p['name'], "{$p['name']} permanently deleted their own account. Their work stays without their name; shared notes passed to the first admin.");
    $_SESSION = []; session_regenerate_id(true); flash('Your account and personal details were permanently deleted.'); redirect('/login');
}

/** Two-factor actions end on the Two-factor card itself (#twofa), with their message shown inside it, so the QR code and any error are in view without scrolling. */
function twofa_done(?string $msg = null, string $type = 'ok'): void
{
    if ($msg !== null) { $_SESSION['twofa_msg'] = [$type, $msg]; }
    redirect('/staff/me#twofa');
}
