<?php
function auth_login_form(): void
{
    if (user()) { redirect('/staff'); }
    render('login', ['title' => 'Log in']);
}

function auth_login(): void
{
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass = (string) ($_POST['password'] ?? '');
    if (rate_limited('login', $email)) {
        render('login', ['title' => 'Log in', 'errors' => ['Too many attempts. Please wait 15 minutes and try again.']]);
        return;
    }
    // Staff accounts only: customers are records kept by staff and can never log in.
    $u = one("SELECT * FROM is_users WHERE email=? AND active=1 AND role <> 'customer'", [$email]);
    // Always run one hash verification so timing does not reveal whether the account exists.
    static $dummy = null;
    $dummy = $dummy ?? password_hash('timing-equaliser', PASSWORD_DEFAULT);
    $ok = password_verify($pass, $u['pass_hash'] ?? $dummy);
    if (!$u && $ok === false) {
        $fz = one("SELECT pass_hash FROM is_users WHERE email=? AND active=0 AND frozen=1 AND role <> 'customer'", [$email]);
        if ($fz && password_verify($pass, $fz['pass_hash'])) { render('login', ['title' => 'Log in', 'errors' => ['This account is frozen. Ask the Owner to unfreeze it.']]); return; }
    }
    if (!$u || !$ok) {
        note_attempt('login', $email);
        render('login', ['title' => 'Log in', 'errors' => ['Incorrect email or password.']]);
        return;
    }
    if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE is_users SET pass_hash=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    }
    if (!empty($u['totp_enabled'])) {
        session_regenerate_id(true);
        $_SESSION['pending2fa'] = ['uid' => (int) $u['id'], 'at' => time()];
        redirect('/login/2fa');
    }
    login_user($u);
    audit('login', $u['email']);
    $to = $_SESSION['after_login'] ?? null;
    unset($_SESSION['after_login']);
    if ($to && $to[0] === '/' && ($to[1] ?? '') !== '/') { redirect($to); }
    redirect('/staff');
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
    redirect('/login');
}

function auth_forgot_form(): void { render('forgot', ['title' => 'Forgot password']); }

function auth_forgot(): void
{
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!rate_limited('reset', $email, 10, 3, 60)) {
        $u = one("SELECT * FROM is_users WHERE email=? AND active=1 AND role <> 'customer'", [$email]);
        if ($u) { send_reset_mail($u); }
    }
    note_attempt('reset', $email);
    render('message', ['title' => 'Check your email', 'heading' => 'Check your email', 'text' => 'If an account exists for that address, we have sent a link to reset the password.']);
}

function auth_reset_form(): void
{
    $t = (string) ($_GET['token'] ?? '');
    if (!user_by_token($t, 'reset')) {
        render('message', ['title' => 'Link expired', 'heading' => 'Link invalid or expired', 'text' => 'Request a new reset link from the login page.']);
        return;
    }
    render('reset', ['title' => 'Set a new password', 'token' => $t]);
}

function auth_reset(): void
{
    $t = (string) ($_POST['token'] ?? '');
    $u = user_by_token($t, 'reset');
    if (!$u) { abort(403, 'Link invalid or expired.'); }
    $pass = (string) ($_POST['password'] ?? '');
    if ($p = password_problem($pass)) {
        render('reset', ['title' => 'Set a new password', 'token' => $t, 'errors' => [$p]]);
        return;
    }
    q('UPDATE is_users SET pass_hash=?, email_verified_at=COALESCE(email_verified_at, ?) WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), now(), $u['id']]);
    clear_token((int) $u['id']);
    audit('password.reset', $u['email']);
    flash('Password updated. Please log in.');
    redirect('/login');
}

/* One-time owner creation. Disabled as soon as any staff account exists. */
function setup_available(): bool
{
    return (int) one("SELECT COUNT(*) c FROM is_users WHERE role <> 'customer'")['c'] === 0;
}
function setup_form(): void
{
    if (!setup_available()) { abort(404); }
    render('setup', ['title' => 'Setup']);
}
function setup_run(): void
{
    if (!setup_available()) { abort(404); }
    $tok = (string) ($_POST['setup_token'] ?? '');
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass = (string) ($_POST['password'] ?? '');
    note_attempt('setup', 'setup');
    $errors = [];
    if (rate_limited('setup', 'setup', 10, 10, 60) || (string) cfg('setup_token') === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING' || !hash_equals((string) cfg('setup_token'), $tok) || strlen((string) cfg('setup_token')) < 20) { $errors[] = 'Setup token is wrong (or config setup_token is too short).'; }
    if ($name === '') { $errors[] = 'Name is required.'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Valid email required.'; }
    if ($p = password_problem($pass)) { $errors[] = $p; }
    if ($errors) { render('setup', ['title' => 'Setup', 'errors' => $errors]); return; }

    $id = insert('INSERT INTO is_users (email,name,pass_hash,role,email_verified_at,created_at) VALUES (?,?,?,?,?,?)',
        [$email, $name, password_hash($pass, PASSWORD_DEFAULT), 'owner', now(), now()]);
    seed_starter($id);
    notify_defaults($id);
    audit('setup.owner', $email);
    flash('Owner account created. Log in, then change setup_token in config.php.');
    redirect('/login');
}

/** Ends the session and shows the login page with a message. */
function auth_force_logout(string $why): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    flash($why);
    redirect('/login');
}

/** Keep-alive used by the idle sign-out script; the session clock is reset by dispatch() itself. */
function staff_ping(): void { require_login(); http_response_code(204); exit; }
