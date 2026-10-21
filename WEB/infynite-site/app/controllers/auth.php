<?php
function auth_register_form(): void
{
    if (user()) { redirect('/account'); }
    render('register', ['title' => 'Create account', 'old' => []]);
}

function auth_register(): void
{
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    $errors = [];
    if ($name === '' || mb_strlen($name) > 120) { $errors[] = 'Please enter your name.'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) { $errors[] = 'Please enter a valid email address.'; }
    if ($p = password_problem($pass)) { $errors[] = $p; }
    if (empty($_POST['terms'])) { $errors[] = 'Please accept the Terms and Privacy Policy.'; }
    if (!preg_match('/^[0-9 +()-]{0,30}$/', $phone)) { $errors[] = 'Phone number looks invalid.'; }
    if (rate_limited('register', $email, 10, 10, 60)) { $errors[] = 'Too many attempts. Please try again later.'; }
    note_attempt('register', $email);

    if (!$errors) {
        if (one('SELECT id FROM is_users WHERE email=?', [$email])) {
            // Do not reveal whether the address exists; behave identically.
        } else {
            $id = insert('INSERT INTO is_users (email,name,phone,pass_hash,role,created_at) VALUES (?,?,?,?,?,?)',
                [$email, $name, $phone ?: null, password_hash($pass, PASSWORD_DEFAULT), 'customer', now()]);
            send_verify_mail(one('SELECT * FROM is_users WHERE id=?', [$id]));
        }
        render('message', ['title' => 'Check your email', 'heading' => 'Check your email', 'text' => 'If the address can be registered, we have sent a verification link. It is valid for 48 hours.']);
        return;
    }
    render('register', ['title' => 'Create account', 'errors' => $errors, 'old' => ['name' => $name, 'email' => $email, 'phone' => $phone]]);
}

function auth_verify(): void
{
    $u = user_by_token((string) ($_GET['token'] ?? ''), 'verify');
    if (!$u) {
        render('message', ['title' => 'Link expired', 'heading' => 'Link invalid or expired', 'text' => 'Please log in and request a new verification e-mail.']);
        return;
    }
    q('UPDATE is_users SET email_verified_at=? WHERE id=?', [now(), $u['id']]);
    clear_token((int) $u['id']);
    flash('Email verified. You can now log in.');
    redirect('/login');
}

function auth_login_form(): void
{
    if (user()) { redirect(is_staff() ? '/staff' : '/account'); }
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
    $u = one('SELECT * FROM is_users WHERE email=? AND active=1', [$email]);
    // Always run one hash verification so timing does not reveal whether the account exists.
    static $dummy = null;
    $dummy = $dummy ?? password_hash('timing-equaliser', PASSWORD_DEFAULT);
    $ok = password_verify($pass, $u['pass_hash'] ?? $dummy);
    if (!$u || !$ok) {
        note_attempt('login', $email);
        render('login', ['title' => 'Log in', 'errors' => ['Incorrect email or password.']]);
        return;
    }
    if (!$u['email_verified_at']) {
        render('login', ['title' => 'Log in', 'errors' => ['Please verify your email first (check your inbox for the link).'], 'resend' => $email]);
        return;
    }
    if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE is_users SET pass_hash=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    }
    login_user($u);
    audit('login', $u['email']);
    $to = $_SESSION['after_login'] ?? null;
    unset($_SESSION['after_login']);
    if ($to && $to[0] === '/' && ($to[1] ?? '') !== '/') { redirect($to); }
    redirect(is_staff($u) ? '/staff' : '/account');
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
    redirect('/');
}

function auth_resend(): void
{
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!rate_limited('resend', $email, 10, 3, 60)) {
        $u = one('SELECT * FROM is_users WHERE email=? AND active=1 AND email_verified_at IS NULL', [$email]);
        if ($u) { send_verify_mail($u); }
    }
    note_attempt('resend', $email);
    render('message', ['title' => 'Check your email', 'heading' => 'Check your email', 'text' => 'If that account is waiting for verification, we have sent a new link.']);
}

function auth_forgot_form(): void { render('forgot', ['title' => 'Forgot password']); }

function auth_forgot(): void
{
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!rate_limited('reset', $email, 10, 3, 60)) {
        $u = one('SELECT * FROM is_users WHERE email=? AND active=1', [$email]);
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
    audit('setup.owner', $email);
    flash('Owner account created. Log in, then change setup_token in config.php.');
    redirect('/login');
}
