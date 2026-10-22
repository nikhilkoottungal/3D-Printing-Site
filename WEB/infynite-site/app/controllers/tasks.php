<?php
function task_load(int $id): array
{
    $t = one('SELECT t.*, a.name aname, c.name cname, r.ref rref, k.slug kslug, k.title ktitle
              FROM is_tasks t
              LEFT JOIN is_users a ON a.id=t.assignee_id
              LEFT JOIN is_users c ON c.id=t.created_by
              LEFT JOIN is_requests r ON r.id=t.request_id
              LEFT JOIN is_kb_articles k ON k.id=t.kb_id
              WHERE t.id=?', [$id]);
    if (!$t) { abort(404, 'Task not found.'); }
    return $t;
}
/** Owner can edit everything; assignee/creator can edit their own tasks. Everyone on staff can comment. */
function task_can_edit(array $t, array $u): bool
{
    return can('*', $u) || (int) $t['assignee_id'] === (int) $u['id'] || (int) $t['created_by'] === (int) $u['id'];
}

function task_list(): void
{
    $u = require_staff();
    $who = (string) ($_GET['who'] ?? 'all');
    $st = (string) ($_GET['status'] ?? 'open');
    $phase = (string) ($_GET['phase'] ?? '');
    $view = (string) ($_GET['view'] ?? 'list');
    $where = []; $p = [];
    if ($who === 'me') { $where[] = 't.assignee_id=?'; $p[] = $u['id']; }
    elseif ($who === 'none') { $where[] = 't.assignee_id IS NULL'; }
    elseif (ctype_digit($who)) { $where[] = 't.assignee_id=?'; $p[] = (int) $who; }
    if ($st === 'open') { $where[] = "t.status <> 'done'"; }
    elseif (isset(TASK_STATUSES[$st])) { $where[] = 't.status=?'; $p[] = $st; }
    if ($phase !== '') { $where[] = 't.phase=?'; $p[] = $phase; }
    $sql = 'SELECT t.*, a.name aname FROM is_tasks t LEFT JOIN is_users a ON a.id=t.assignee_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " ORDER BY FIELD(t.status,'blocked','in_progress','review','todo','done'), (t.due_date IS NULL), t.due_date, FIELD(t.priority,'urgent','high','normal','low') LIMIT 500";
    $rows = all($sql, $p);
    $phases = array_column(all('SELECT DISTINCT phase FROM is_tasks WHERE phase IS NOT NULL ORDER BY phase'), 'phase');
    render('staff/tasks', ['title' => 'Tasks', 'nav' => 'tasks', 'rows' => $rows, 'who' => $who, 'st' => $st, 'phase' => $phase, 'phases' => $phases, 'view' => $view, 'people' => staff_users(), 'u' => $u], 'staff');
}

function task_new_form(): void
{
    $u = require_staff();
    render('staff/task_new', ['title' => 'New task', 'nav' => 'tasks', 'people' => staff_users(), 'u' => $u, 'old' => ['request_id' => (int) ($_GET['request'] ?? 0)]], 'staff');
}

function task_validate(): array
{
    $title = trim((string) ($_POST['title'] ?? ''));
    $desc = trim((string) ($_POST['description'] ?? ''));
    $prio = (string) ($_POST['priority'] ?? 'normal');
    $due = trim((string) ($_POST['due_date'] ?? ''));
    $assignee = (int) ($_POST['assignee_id'] ?? 0) ?: null;
    $errors = [];
    if ($title === '' || mb_strlen($title) > 200) { $errors[] = 'Title is required (max 200 characters).'; }
    if (mb_strlen(trim((string) ($_POST['phase'] ?? ''))) > 40) { $errors[] = 'Group name is too long.'; }
    if (!isset(TASK_PRIORITIES[$prio])) { $errors[] = 'Invalid priority.'; }
    if ($due !== '' && !valid_date($due)) { $errors[] = 'Invalid due date.'; }
    if ($assignee && !one("SELECT id FROM is_users WHERE id=? AND role <> 'customer' AND active=1", [$assignee])) { $errors[] = 'Assignee must be an active staff member.'; }
    return [$errors, ['title' => $title, 'description' => $desc, 'request_id' => (int) ($_POST['request_id'] ?? 0), 'phase' => trim((string) ($_POST['phase'] ?? '')), 'priority' => $prio, 'due_date' => $due ?: null, 'assignee_id' => $assignee]];
}

function task_create_post(): void
{
    $u = require_staff();
    [$errors, $d] = task_validate();
    if ($errors) {
        render('staff/task_new', ['title' => 'New task', 'nav' => 'tasks', 'people' => staff_users(), 'u' => $u, 'errors' => $errors, 'old' => $d], 'staff');
        return;
    }
    $reqId = (int) ($_POST['request_id'] ?? 0) ?: null;
    $id = task_create($d['title'], $d['description'], $d['assignee_id'], (int) $u['id'], $d['priority'], $d['due_date'], ($d['phase'] ?? '') ?: null, null, $reqId);
    flash('Task created.');
    redirect('/staff/tasks/' . $id);
}

function task_show(string $id): void
{
    $u = require_staff();
    $t = task_load((int) $id);
    $comments = all('SELECT c.*, u.name uname FROM is_task_comments c LEFT JOIN is_users u ON u.id=c.user_id WHERE c.task_id=? ORDER BY c.id', [$t['id']]);
    render('staff/task_show', ['title' => $t['title'], 'nav' => 'tasks', 't' => $t, 'comments' => $comments, 'people' => staff_users(), 'u' => $u, 'canEdit' => task_can_edit($t, $u), 'att' => att_for('task', (int) $t['id'])], 'staff');
}

function task_update(string $id): void
{
    $u = require_staff();
    $t = task_load((int) $id);
    if (!task_can_edit($t, $u)) { abort(403, 'Only the assignee, the creator or the owner can edit this task.'); }
    [$errors, $d] = task_validate();
    $status = (string) ($_POST['status'] ?? $t['status']);
    if (!isset(TASK_STATUSES[$status])) { $errors[] = 'Invalid status.'; }
    if ($errors) { flash(implode(' ', $errors), 'bad'); redirect('/staff/tasks/' . $t['id']); }

    $names = [];
    foreach (all("SELECT id,name FROM is_users WHERE role <> 'customer'") as $p) { $names[$p['id']] = $p['name']; }
    $log = [];
    if ($t['title'] !== $d['title']) { $log[] = 'renamed the task'; }
    if ((string) $t['description'] !== $d['description']) { $log[] = 'edited the description'; }
    if ($t['status'] !== $status) { $log[] = 'changed status: ' . TASK_STATUSES[$t['status']] . ' → ' . TASK_STATUSES[$status]; }
    if ($t['priority'] !== $d['priority']) { $log[] = 'changed priority: ' . TASK_PRIORITIES[$t['priority']] . ' → ' . TASK_PRIORITIES[$d['priority']]; }
    if ((int) $t['assignee_id'] !== (int) $d['assignee_id']) { $log[] = 'reassigned: ' . ($names[$t['assignee_id']] ?? 'nobody') . ' → ' . ($names[$d['assignee_id']] ?? 'nobody'); }
    if ((string) $t['due_date'] !== (string) $d['due_date']) { $log[] = 'changed due date: ' . fmt_date($t['due_date']) . ' → ' . fmt_date($d['due_date']); }

    $completed = $status === 'done' ? ($t['completed_at'] ?: now()) : null;
    q('UPDATE is_tasks SET title=?, description=?, status=?, priority=?, assignee_id=?, due_date=?, completed_at=?, updated_at=? WHERE id=?',
        [$d['title'], $d['description'], $status, $d['priority'], $d['assignee_id'], $d['due_date'], $completed, now(), $t['id']]);
    foreach ($log as $l) { task_event((int) $t['id'], (int) $u['id'], $l); }

    if ($d['assignee_id'] && (int) $d['assignee_id'] !== (int) $t['assignee_id'] && (int) $d['assignee_id'] !== (int) $u['id']) {
        $a = one('SELECT email,name FROM is_users WHERE id=?', [$d['assignee_id']]);
        send_mail($a['email'], 'Task assigned to you: ' . $d['title'], "{$u['name']} assigned you a task.\n" . cfg('base_url') . '/staff/tasks/' . $t['id']);
    }
    flash($log ? 'Task updated.' : 'No changes.');
    redirect('/staff/tasks/' . $t['id']);
}

function task_comment(string $id): void
{
    $u = require_staff();
    $t = task_load((int) $id);
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($body === '' || mb_strlen($body) > 5000) { flash('Write a comment (max 5000 characters).', 'bad'); redirect('/staff/tasks/' . $t['id']); }
    q('INSERT INTO is_task_comments (task_id,user_id,kind,body,created_at) VALUES (?,?,?,?,?)', [$t['id'], $u['id'], 'comment', $body, now()]);
    q('UPDATE is_tasks SET updated_at=? WHERE id=?', [now(), $t['id']]);
    if ($t['assignee_id'] && (int) $t['assignee_id'] !== (int) $u['id']) {
        $a = one('SELECT email FROM is_users WHERE id=?', [$t['assignee_id']]);
        send_mail($a['email'], 'New comment on: ' . $t['title'], "{$u['name']} wrote:\n\n$body\n\n" . cfg('base_url') . '/staff/tasks/' . $t['id']);
    }
    redirect('/staff/tasks/' . $t['id'] . '#comments');
}
