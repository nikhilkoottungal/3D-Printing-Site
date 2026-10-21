<?php
function user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = one('SELECT * FROM is_users WHERE id=? AND active=1', [$_SESSION['uid']]);
            if (!$u) {
                unset($_SESSION['uid']);
            }
        }
    }
    return $u;
}
function is_staff(?array $u = null): bool { $u = $u ?? user(); return $u && $u['role'] !== 'customer'; }
function can(string $perm, ?array $u = null): bool
{
    $u = $u ?? user();
    if (!$u || $u['role'] === 'customer') { return false; }
    $p = ROLE_PERMS[$u['role']] ?? [];
    return in_array('*', $p, true) || in_array($perm, $p, true);
}
function require_login(): array
{
    $u = user();
    if (!$u) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/account';
        redirect('/login');
    }
    return $u;
}
function require_customer(): array { $u = require_login(); if (is_staff($u)) { redirect('/staff'); } return $u; }
function require_staff(?string $perm = null): array
{
    $u = require_login();
    if (!is_staff($u)) { abort(403); }
    if ($perm && !can($perm, $u)) { abort(403, 'Your role does not allow this.'); }
    return $u;
}

function password_problem(string $p): ?string
{
    if (strlen($p) < 10) { return 'Password must be at least 10 characters.'; }
    if (strlen($p) > 200) { return 'Password is too long.'; }
    return null;
}

function make_token(int $userId, string $kind, int $hours): string
{
    $raw = bin2hex(random_bytes(32));
    q('UPDATE is_users SET token_hash=?, token_kind=?, token_expires=? WHERE id=?',
        [hash('sha256', $raw), $kind, date('Y-m-d H:i:s', time() + $hours * 3600), $userId]);
    return $raw;
}
function user_by_token(string $raw, string $kind): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) { return null; }
    return one('SELECT * FROM is_users WHERE token_hash=? AND token_kind=? AND token_expires>? AND active=1', [hash('sha256', $raw), $kind, now()]);
}
function clear_token(int $userId): void { q('UPDATE is_users SET token_hash=NULL, token_kind=NULL, token_expires=NULL WHERE id=?', [$userId]); }

function login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    q('UPDATE is_users SET last_login_at=? WHERE id=?', [now(), $u['id']]);
}
function send_verify_mail(array $u): void
{
    $t = make_token((int) $u['id'], 'verify', 48);
    send_mail($u['email'], 'Verify your email', "Hi {$u['name']},\n\nConfirm your email address to activate your account:\n" . cfg('base_url') . "/verify?token=$t\n\nThis link expires in 48 hours. If you did not register, ignore this message.");
}
function send_reset_mail(array $u): string
{
    $t = make_token((int) $u['id'], 'reset', 2);
    $link = cfg('base_url') . "/reset?token=$t";
    send_mail($u['email'], 'Reset your password', "Hi {$u['name']},\n\nSet a new password here (valid for 2 hours):\n$link\n\nIf you did not ask for this, ignore this message.");
    return $link;
}
