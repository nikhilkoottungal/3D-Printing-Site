<?php
/** ROLE_PERMS has no 'team.manage' entry; only the owner's '*' satisfies it. */
function team_list(): void
{
    $u = require_staff();
    $people = all("SELECT u.*, (SELECT COUNT(*) FROM is_tasks t WHERE t.assignee_id=u.id AND t.status <> 'done') open_tasks FROM is_users u WHERE u.role <> 'customer' ORDER BY u.active DESC, u.name");
    $customers = (int) one("SELECT COUNT(*) c FROM is_users WHERE role='customer'")['c'];
    render('staff/team', ['title' => 'Team & roles', 'nav' => 'team', 'people' => $people, 'u' => $u, 'customers' => $customers, 'link' => $_SESSION['new_staff_link'] ?? null], 'staff');
    unset($_SESSION['new_staff_link']);
}

function team_create(): void
{
    $u = require_staff('team.manage');
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $role = (string) ($_POST['role'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset(ROLES[$role])) { flash('Name, a valid email and a role are required.', 'bad'); redirect('/staff/team'); }
    if (one('SELECT id FROM is_users WHERE email=?', [$email])) { flash('That email is already registered.', 'bad'); redirect('/staff/team'); }
    $id = insert('INSERT INTO is_users (email,name,pass_hash,role,email_verified_at,created_at) VALUES (?,?,?,?,?,?)',
        [$email, $name, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $role, now(), now()]);
    $link = send_reset_mail(one('SELECT * FROM is_users WHERE id=?', [$id]));
    $_SESSION['new_staff_link'] = $link;   // shown once so the owner can hand it over if e-mail is not delivered
    audit('team.create', "$email as $role");
    flash("Account created for $name. A password-setup link was e-mailed and is shown below (valid 2 hours).");
    redirect('/staff/team');
}

function team_update(string $id): void
{
    $u = require_staff('team.manage');
    $p = one("SELECT * FROM is_users WHERE id=? AND role <> 'customer'", [(int) $id]);
    if (!$p) { abort(404); }
    $role = (string) ($_POST['role'] ?? $p['role']);
    $active = !empty($_POST['active']) ? 1 : 0;
    if (!isset(ROLES[$role])) { abort(400); }
    if ((int) $p['id'] === (int) $u['id'] && ($role !== 'owner' || !$active)) {
        flash('You cannot demote or deactivate yourself.', 'bad'); redirect('/staff/team');
    }
    q('UPDATE is_users SET role=?, active=? WHERE id=?', [$role, $active, $p['id']]);
    if (!$active) { q('UPDATE is_tasks SET assignee_id=NULL WHERE assignee_id=? AND status <> ?', [$p['id'], 'done']); }
    audit('team.update', "{$p['email']} role=$role active=$active");
    flash('Team member updated.');
    redirect('/staff/team');
}
