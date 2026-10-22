<?php
/** ROLE_PERMS has no 'team.manage' entry; only the owner's '*' satisfies it. */
function team_list(): void
{
    $u = require_staff();
    if (can('team.manage')) { admin_unlocked(); }   // same admin session as the other tabs: keeps it alive, no extra key prompt
    $people = all("SELECT u.*, (SELECT COUNT(*) FROM is_tasks t WHERE t.assignee_id=u.id AND t.status <> 'done') open_tasks FROM is_users u WHERE u.role <> 'customer' ORDER BY u.active DESC, u.name");
    $customers = (int) one("SELECT COUNT(*) c FROM is_users WHERE role='customer'")['c'];
    $reqs = all("SELECT r.*, t.name tname, t.email temail, b.name bname FROM is_admin_requests r JOIN is_users t ON t.id=r.target_id JOIN is_users b ON b.id=r.requested_by WHERE r.status='pending' ORDER BY r.id");
    foreach ($reqs as &$r) { $r['votes'] = array_column(all('SELECT user_id, decision FROM is_admin_approvals WHERE request_id=?', [$r['id']]), 'decision', 'user_id'); } unset($r);
    $admins = all("SELECT id,name FROM is_users WHERE role='owner' AND active=1 ORDER BY name");
    render('staff/team', ['title' => 'Team & roles', 'nav' => can('team.manage') ? 'admin' : 'team', 'atab' => 'team', 'people' => $people, 'u' => $u, 'customers' => $customers, 'link' => $_SESSION['new_staff_link'] ?? null, 'reqs' => $reqs, 'admins' => $admins], 'staff');
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
    if ($role === 'owner') { notify_admins('New admin: ' . $name, "{$u['name']} created an admin (Owner) account for $name ($email).", (int) $u['id']); }
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
    if ($p['role'] === 'owner' && (int) $p['id'] !== (int) $u['id'] && ($role !== 'owner' || !$active)) {
        flash('An admin cannot be removed or demoted by one person. Use "Request removal": every admin must agree.', 'bad'); redirect('/staff/team');
    }
    if ($role === 'owner' && $p['role'] !== 'owner') {
        notify_admins('New admin: ' . $p['name'], "{$u['name']} gave admin (Owner) access to {$p['name']} ({$p['email']}).", (int) $u['id']);
    }
    if ((int) $p['id'] === (int) $u['id'] && ($role !== 'owner' || !$active)) {
        flash('You cannot demote or deactivate yourself.', 'bad'); redirect('/staff/team');
    }
    q('UPDATE is_users SET role=?, active=? WHERE id=?', [$role, $active, $p['id']]);
    if (!$active) { q('UPDATE is_tasks SET assignee_id=NULL WHERE assignee_id=? AND status <> ?', [$p['id'], 'done']); }
    audit('team.update', "{$p['email']} role=$role active=$active");
    flash('Team member updated.');
    redirect('/staff/team');
}

/* ---- removing an admin needs every admin's agreement ---- */
function team_removal_request(string $id): void
{
    $u = require_staff('team.manage');
    $t = one("SELECT * FROM is_users WHERE id=? AND role='owner' AND active=1", [(int) $id]);
    if (!$t || (int) $t['id'] === (int) $u['id']) { flash('Choose another admin.', 'bad'); redirect('/staff/team'); }
    if (one("SELECT id FROM is_admin_requests WHERE target_id=? AND status='pending'", [$t['id']])) { flash('A request for this admin is already open.', 'bad'); redirect('/staff/team'); }
    $rid = insert("INSERT INTO is_admin_requests (target_id, action, requested_by, status, created_at) VALUES (?,?,?,?,?)", [$t['id'], 'deactivate', $u['id'], 'pending', now()]);
    q("INSERT INTO is_admin_approvals (request_id,user_id,decision,at) VALUES (?,?,?,?)", [$rid, $u['id'], 'approve', now()]);
    audit('admin.removal_requested', $t['email']);
    notify_admins('Request to remove admin ' . $t['name'], "{$u['name']} asks to remove admin access for {$t['name']} ({$t['email']}).\nEvery admin, including {$t['name']}, must agree before anything changes.\nDecide here: " . app_url('/staff/team'));
    flash('Request opened. Every other admin has been asked by e-mail; nothing changes until all agree.');
    redirect('/staff/team');
}

function team_removal_decide(string $id): void
{
    $u = require_staff('team.manage');
    $r = one("SELECT r.*, t.name tname, t.email temail FROM is_admin_requests r JOIN is_users t ON t.id=r.target_id WHERE r.id=? AND r.status='pending'", [(int) $id]);
    if (!$r) { flash('That request is closed.', 'bad'); redirect('/staff/team'); }
    $d = (string) ($_POST['decision'] ?? '');
    if (!in_array($d, ['approve', 'reject', 'cancel'], true)) { abort(400); }
    if ($d === 'cancel') {
        if ((int) $r['requested_by'] !== (int) $u['id']) { abort(403, 'Only the person who asked can cancel.'); }
        q("UPDATE is_admin_requests SET status='cancelled', resolved_at=? WHERE id=?", [now(), $r['id']]);
        audit('admin.removal_cancelled', $r['temail']); notify_admins('Removal request cancelled: ' . $r['tname'], "{$u['name']} cancelled the request to remove {$r['tname']}.");
        flash('Request cancelled.'); redirect('/staff/team');
    }
    q('REPLACE INTO is_admin_approvals (request_id,user_id,decision,at) VALUES (?,?,?,?)', [$r['id'], $u['id'], $d, now()]);
    if ($d === 'reject') {
        q("UPDATE is_admin_requests SET status='rejected', resolved_at=? WHERE id=?", [now(), $r['id']]);
        audit('admin.removal_rejected', $r['temail']);
        notify_admins('Removal request rejected: ' . $r['tname'], "{$u['name']} did not agree to remove {$r['tname']}. Nothing was changed.");
        flash('You rejected the request. Nothing changes.'); redirect('/staff/team');
    }
    $admins = array_map('intval', array_column(all("SELECT id FROM is_users WHERE role='owner' AND active=1"), 'id'));
    $ok = array_map('intval', array_column(all("SELECT user_id FROM is_admin_approvals WHERE request_id=? AND decision='approve'", [$r['id']]), 'user_id'));
    if (!array_diff($admins, $ok)) {
        q("UPDATE is_users SET active=0 WHERE id=?", [$r['target_id']]);
        q("UPDATE is_tasks SET assignee_id=NULL WHERE assignee_id=? AND status <> 'done'", [$r['target_id']]);
        q("UPDATE is_admin_requests SET status='approved', resolved_at=? WHERE id=?", [now(), $r['id']]);
        audit('admin.removed', $r['temail']);
        notify_admins('Admin removed: ' . $r['tname'], "All admins agreed. {$r['tname']} ({$r['temail']}) no longer has access.");
        flash($r['tname'] . ' was removed: all admins agreed.');
    } else {
        audit('admin.removal_approved', $r['temail']);
        flash('Your agreement is recorded. Waiting for the remaining admins.');
    }
    redirect('/staff/team');
}

/** Owner turns off another person's two-factor login (lost phone). Admins reset each other from the server with tools/reset_2fa.php. */
function team_reset_2fa(string $id): void
{
    $u = require_staff('team.manage');
    $p = one("SELECT * FROM is_users WHERE id=? AND role <> 'customer'", [(int) $id]);
    if (!$p || $p['role'] === 'owner') { flash('Admins turn off two-factor login themselves, or an administrator runs tools/reset_2fa.php on the server.', 'bad'); redirect('/staff/team'); }
    q('UPDATE is_users SET totp_secret=NULL, totp_enabled=0, totp_last=0, recovery_codes=NULL WHERE id=?', [$p['id']]);
    audit('team.2fa_reset', $p['email']);
    send_mail($p['email'], 'Your two-factor login was turned off', "Hi {$p['name']},\n\n{$u['name']} turned off two-factor login on your account because you asked for a reset. You can set it up again under My account.\nIf you did not ask for this, tell an admin.");
    flash('Two-factor login turned off for ' . $p['name'] . '. They can set it up again under My account.'); redirect('/staff/team');
}

/** Removes a colleague: sign-in stops at once, open tasks are unassigned, alerts stop. Their name stays in the history. Admins go through "Request removal". */
function team_remove(string $id): void
{
    $u = require_staff('team.manage');
    $p = one("SELECT * FROM is_users WHERE id=? AND role <> 'customer' AND (active=1 OR frozen=1)", [(int) $id]);
    if (!$p || (int) $p['id'] === (int) $u['id']) { flash('Choose another team member.', 'bad'); redirect('/staff/team'); }
    if ($p['role'] === 'owner') { flash('An admin is removed by the request that every admin must agree to.', 'bad'); redirect('/staff/team'); }
    q("UPDATE is_users SET active=0, frozen=0, pass_hash=?, token_hash=NULL, token_kind=NULL, token_expires=NULL, totp_secret=NULL, totp_enabled=0, totp_last=0, recovery_codes=NULL WHERE id=?", [password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $p['id']]);
    q('DELETE FROM is_notify_rules WHERE user_id=?', [$p['id']]);
    $n = q("UPDATE is_tasks SET assignee_id=NULL WHERE assignee_id=? AND status <> 'done'", [$p['id']])->rowCount();
    q('UPDATE is_recurring_tasks SET assignee_id=NULL WHERE assignee_id=?', [$p['id']]);
    audit('team.remove', $p['email']);
    notify_admins('Team member removed: ' . $p['name'], "{$u['name']} removed {$p['name']} ({$p['email']}) from the team. They can no longer sign in.", (int) $u['id']);
    flash($p['name'] . ' was removed. They can no longer sign in' . ($n ? "; $n open task(s) are now unassigned" : '') . '. Their name stays on past work.'); redirect('/staff/team');
}
/** Brings a removed colleague back with a new password-setup link (shown once). */
function team_restore(string $id): void
{
    $u = require_staff('team.manage');
    $p = one("SELECT * FROM is_users WHERE id=? AND role <> 'customer' AND active=0 AND frozen=0", [(int) $id]);
    if (!$p) { flash('Nothing to restore.', 'bad'); redirect('/staff/team'); }
    q('UPDATE is_users SET active=1, frozen=0 WHERE id=?', [$p['id']]);
    $_SESSION['new_staff_link'] = send_reset_mail(one('SELECT * FROM is_users WHERE id=?', [$p['id']]));
    audit('team.restore', $p['email']); flash($p['name'] . ' is back on the team. A password-setup link was e-mailed and is shown below (valid 2 hours).'); redirect('/staff/team');
}

/** Freeze: sign-in is blocked at once but nothing else changes (tasks, alerts, password and two-factor stay), so Unfreeze restores access exactly as it was. */
function team_freeze(string $id): void
{
    $u = require_staff('team.manage');
    $p = one("SELECT * FROM is_users WHERE id=? AND role <> 'customer' AND active=1", [(int) $id]);
    if (!$p || (int) $p['id'] === (int) $u['id']) { flash('Choose another active team member.', 'bad'); redirect('/staff/team'); }
    if ($p['role'] === 'owner') { flash('An admin cannot be frozen by one person. Use "Request removal", which every admin must agree to.', 'bad'); redirect('/staff/team'); }
    q('UPDATE is_users SET active=0, frozen=1 WHERE id=?', [$p['id']]);
    audit('team.freeze', $p['email']);
    notify_admins('Team member frozen: ' . $p['name'], "{$u['name']} froze the account of {$p['name']} ({$p['email']}). They cannot sign in until it is unfrozen.", (int) $u['id']);
    flash($p['name'] . ' is frozen: they cannot sign in. Unfreeze them to give access back as it was.'); redirect('/staff/team');
}
function team_unfreeze(string $id): void
{
    $u = require_staff('team.manage');
    $p = one("SELECT * FROM is_users WHERE id=? AND role <> 'customer' AND active=0 AND frozen=1", [(int) $id]);
    if (!$p) { flash('That account is not frozen.', 'bad'); redirect('/staff/team'); }
    q('UPDATE is_users SET active=1, frozen=0 WHERE id=?', [$p['id']]);
    audit('team.unfreeze', $p['email']); notify_admins('Team member unfrozen: ' . $p['name'], "{$u['name']} unfroze {$p['name']} ({$p['email']}).", (int) $u['id']);
    flash($p['name'] . ' can sign in again.'); redirect('/staff/team');
}
/** Erases one staff account for good: details, files, picture, private notes. Their work stays without their name; shared notes and schedules pass to $successor. Cannot be undone. */
function account_erase(array $p, int $successor): void
{
    $uid = (int) $p['id']; avatar_unlink($uid);
    foreach (all('SELECT stored_name FROM is_staff_docs WHERE user_id=?', [$uid]) as $d) { @unlink(cfg('storage') . '/uploads/hr/' . $d['stored_name']); }
    foreach (all("SELECT a.stored_name FROM is_attachments a JOIN is_notes n ON n.id=a.ref_id WHERE a.kind='note' AND n.owner_id=? AND n.shared=0", [$uid]) as $a) { @unlink(cfg('storage') . '/uploads/att/' . $a['stored_name']); }
    $pdo = db(); $pdo->beginTransaction();
    try {
        q("DELETE FROM is_attachments WHERE kind='note' AND ref_id IN (SELECT id FROM is_notes WHERE owner_id=? AND shared=0)", [$uid]);
        q('DELETE FROM is_notes WHERE owner_id=? AND shared=0', [$uid]);
        q('UPDATE is_notes SET owner_id=? WHERE owner_id=?', [$successor, $uid]);
        q('DELETE FROM is_staff_docs WHERE user_id=?', [$uid]); q('DELETE FROM is_staff_profiles WHERE user_id=?', [$uid]); q('DELETE FROM is_notify_rules WHERE user_id=?', [$uid]);
        q('UPDATE is_recurring_tasks SET assignee_id=NULL WHERE assignee_id=?', [$uid]); q('UPDATE is_recurring_tasks SET created_by=? WHERE created_by=?', [$successor, $uid]);
        q('UPDATE is_tasks SET assignee_id=NULL WHERE assignee_id=?', [$uid]);
        foreach ([['is_tasks', 'created_by'], ['is_task_comments', 'user_id'], ['is_audit_log', 'user_id'], ['is_request_history', 'by_user'], ['is_requests', 'created_by'], ['is_invoices', 'created_by'], ['is_attachments', 'uploaded_by'], ['is_stock_moves', 'user_id'], ['is_kb_versions', 'edited_by'], ['is_kb_articles', 'updated_by'], ['is_purchase_orders', 'created_by'], ['is_supplier_bills', 'created_by'], ['is_invoice_payments', 'by_user'], ['is_bill_payments', 'by_user'], ['is_expenses', 'created_by'], ['is_credit_notes', 'created_by'], ['is_print_jobs', 'created_by'], ['is_order_qc', 'by_user'], ['is_order_issues', 'created_by']] as [$tb, $col]) {
            if (one('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?', [$tb, $col])) { q("UPDATE $tb SET $col=NULL WHERE $col=?", [$uid]); }
        }
        q('DELETE FROM is_login_attempts WHERE email=?', [$p['email']]);
        q('DELETE FROM is_users WHERE id=?', [$uid]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
}

/** An admin permanently deletes a colleague they choose. Needs the admin's own password and the colleague's e-mail typed exactly. Admins cannot be deleted this way. */
function team_delete(string $id): void
{
    $u = require_staff('team.manage');
    $p = one("SELECT * FROM is_users WHERE id=? AND role <> 'customer'", [(int) $id]);
    if (!$p || (int) $p['id'] === (int) $u['id']) { flash('Choose another team member. You delete your own account from My account.', 'bad'); redirect('/staff/team'); }
    if ($p['role'] === 'owner') { flash('An admin cannot be deleted by one person. Use "Request removal": every admin must agree.', 'bad'); redirect('/staff/team'); }
    if ($p['active'] || $p['frozen']) { flash('Remove the person first. Permanent deletion is only offered for removed accounts.', 'bad'); redirect('/staff/team'); }
    if (rate_limited('teamdelete', 'u' . $u['id'], 20, 5, 15)) { flash('Too many attempts. Try again in 15 minutes.', 'bad'); redirect('/staff/team'); }
    note_attempt('teamdelete', 'u' . $u['id']);
    $me = one('SELECT pass_hash FROM is_users WHERE id=?', [$u['id']]);
    if (!password_verify((string) ($_POST['current'] ?? ''), $me['pass_hash'])) { flash('Your password is not right, so nothing was deleted.', 'bad'); redirect('/staff/team'); }
    if (strcasecmp(trim((string) ($_POST['confirm'] ?? '')), $p['email']) !== 0) { flash('Type their e-mail address exactly to confirm the permanent deletion.', 'bad'); redirect('/staff/team'); }
    try { account_erase($p, (int) $u['id']); } catch (Throwable $e) { error_log('team delete failed: ' . $e->getMessage()); flash('Could not delete this person. Nothing was changed.', 'bad'); redirect('/staff/team'); }
    audit('team.delete', 'permanently deleted team member #' . $p['id'] . ' (' . $p['name'] . ')');
    notify_admins('Team member permanently deleted: ' . $p['name'], "{$u['name']} permanently deleted the account of {$p['name']}. This cannot be undone.", (int) $u['id']);
    flash($p['name'] . ' was permanently deleted. Their work stays but no longer shows their name.'); redirect('/staff/team');
}
