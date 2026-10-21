<?php
function kb_load(string $slug): array
{
    $a = one('SELECT k.*, o.name oname, u.name uname FROM is_kb_articles k LEFT JOIN is_users o ON o.id=k.owner_id LEFT JOIN is_users u ON u.id=k.updated_by WHERE k.slug=?', [$slug]);
    if (!$a) { abort(404, 'Article not found.'); }
    return $a;
}

function kb_list(): void
{
    $u = require_staff();
    $s = trim((string) ($_GET['q'] ?? ''));
    $kind = (string) ($_GET['kind'] ?? '');
    $where = []; $p = [];
    if ($s !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $s) . '%';
        $where[] = '(title LIKE ? OR body LIKE ? OR category LIKE ?)'; array_push($p, $like, $like, $like);
    }
    if (in_array($kind, ['procedure', 'knowledge'], true)) { $where[] = 'kind=?'; $p[] = $kind; }
    $rows = all('SELECT id,slug,title,category,kind,version,review_due,updated_at FROM is_kb_articles' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY category, title', $p);
    render('staff/kb_list', ['title' => 'Knowledge base', 'nav' => 'kb', 'rows' => $rows, 'q' => $s, 'kind' => $kind], 'staff');
}

function kb_show(string $slug): void
{
    $u = require_staff();
    $a = kb_load($slug);
    $linked = all("SELECT t.id,t.title,t.status,a.name aname FROM is_tasks t LEFT JOIN is_users a ON a.id=t.assignee_id WHERE t.kb_id=? ORDER BY t.id DESC LIMIT 10", [$a['id']]);
    render('staff/kb_show', ['title' => $a['title'], 'nav' => 'kb', 'a' => $a, 'u' => $u, 'linked' => $linked, 'people' => staff_users()], 'staff');
}

function kb_new_form(): void
{
    $u = require_staff();
    render('staff/kb_edit', ['title' => 'New article', 'nav' => 'kb', 'a' => ['title' => '', 'category' => 'General', 'kind' => 'procedure', 'body' => '', 'review_due' => null, 'slug' => null], 'cats' => kb_categories(), 'people' => staff_users()], 'staff');
}

function kb_categories(): array { return array_column(all('SELECT DISTINCT category FROM is_kb_articles ORDER BY category'), 'category'); }

function kb_input(): array
{
    $title = trim((string) ($_POST['title'] ?? ''));
    $cat = trim((string) ($_POST['category'] ?? '')) ?: 'General';
    $kind = (string) ($_POST['kind'] ?? 'procedure');
    $body = trim((string) ($_POST['body'] ?? ''));
    $due = trim((string) ($_POST['review_due'] ?? ''));
    $errors = [];
    if ($title === '' || mb_strlen($title) > 200) { $errors[] = 'Title is required.'; }
    if (mb_strlen($cat) > 60) { $errors[] = 'Category too long.'; }
    if (!in_array($kind, ['procedure', 'knowledge'], true)) { $errors[] = 'Invalid type.'; }
    if ($body === '' || strlen($body) > 200000) { $errors[] = 'Content is required.'; }
    if ($due !== '' && !valid_date($due)) { $errors[] = 'Invalid review date.'; }
    return [$errors, ['title' => $title, 'category' => $cat, 'kind' => $kind, 'body' => $body, 'review_due' => $due ?: null]];
}

function kb_create_post(): void
{
    $u = require_staff();
    [$errors, $d] = kb_input();
    if ($errors) {
        render('staff/kb_edit', ['title' => 'New article', 'nav' => 'kb', 'a' => $d + ['slug' => null], 'cats' => kb_categories(), 'errors' => $errors, 'people' => staff_users()], 'staff');
        return;
    }
    $id = kb_create($d['title'], $d['category'], $d['kind'], $d['body'], (int) $u['id'], 'Created', $d['review_due']);
    audit('kb.create', $d['title']);
    flash('Article created.');
    redirect('/staff/kb/' . one('SELECT slug FROM is_kb_articles WHERE id=?', [$id])['slug']);
}

function kb_edit_form(string $slug): void
{
    require_staff();
    $a = kb_load($slug);
    render('staff/kb_edit', ['title' => 'Edit: ' . $a['title'], 'nav' => 'kb', 'a' => $a, 'cats' => kb_categories(), 'people' => staff_users()], 'staff');
}

function kb_save_version(array $a, array $d, string $note, int $userId): void
{
    $v = (int) $a['version'] + 1;
    q('UPDATE is_kb_articles SET title=?, category=?, kind=?, body=?, review_due=?, version=?, updated_by=?, updated_at=? WHERE id=?',
        [$d['title'], $d['category'], $d['kind'], $d['body'], $d['review_due'], $v, $userId, now(), $a['id']]);
    q('INSERT INTO is_kb_versions (article_id,version,title,body,note,edited_by,edited_at) VALUES (?,?,?,?,?,?,?)', [$a['id'], $v, $d['title'], $d['body'], mb_substr($note, 0, 200) ?: null, $userId, now()]);
}

function kb_edit_post(string $slug): void
{
    $u = require_staff();
    $a = kb_load($slug);
    [$errors, $d] = kb_input();
    if ($errors) {
        render('staff/kb_edit', ['title' => 'Edit: ' . $a['title'], 'nav' => 'kb', 'a' => $d + ['slug' => $slug], 'cats' => kb_categories(), 'errors' => $errors, 'people' => staff_users()], 'staff');
        return;
    }
    kb_save_version($a, $d, trim((string) ($_POST['note'] ?? '')) ?: 'Edited', (int) $u['id']);
    audit('kb.edit', $slug);
    flash('Saved as version ' . ($a['version'] + 1) . '.');
    redirect('/staff/kb/' . $slug);
}

function kb_history(string $slug): void
{
    require_staff();
    $a = kb_load($slug);
    $versions = all('SELECT v.*, u.name uname FROM is_kb_versions v LEFT JOIN is_users u ON u.id=v.edited_by WHERE v.article_id=? ORDER BY v.version DESC', [$a['id']]);
    $show = null;
    if (isset($_GET['v'])) { $show = one('SELECT * FROM is_kb_versions WHERE article_id=? AND version=?', [$a['id'], (int) $_GET['v']]); }
    render('staff/kb_history', ['title' => 'History: ' . $a['title'], 'nav' => 'kb', 'a' => $a, 'versions' => $versions, 'show' => $show], 'staff');
}

function kb_restore(string $slug): void
{
    $u = require_staff();
    $a = kb_load($slug);
    $v = one('SELECT * FROM is_kb_versions WHERE article_id=? AND version=?', [$a['id'], (int) ($_POST['version'] ?? 0)]);
    if (!$v) { abort(404); }
    kb_save_version($a, ['title' => $v['title'], 'category' => $a['category'], 'kind' => $a['kind'], 'body' => $v['body'], 'review_due' => $a['review_due']], 'Restored version ' . $v['version'], (int) $u['id']);
    audit('kb.restore', $slug . ' v' . $v['version']);
    flash('Restored version ' . $v['version'] . ' as a new version.');
    redirect('/staff/kb/' . $slug);
}

function kb_delete(string $slug): void
{
    require_staff('team.manage');   // owner only
    $a = kb_load($slug);
    q('UPDATE is_tasks SET kb_id=NULL WHERE kb_id=?', [$a['id']]);
    q('DELETE FROM is_kb_articles WHERE id=?', [$a['id']]);
    audit('kb.delete', $slug);
    flash('Article deleted.');
    redirect('/staff/kb');
}

/** Turns a procedure into a task: the checklist is copied into the task description so it can be followed and discussed. */
function kb_make_task(string $slug): void
{
    $u = require_staff();
    $a = kb_load($slug);
    $assignee = (int) ($_POST['assignee_id'] ?? 0) ?: null;
    if ($assignee && !one("SELECT id FROM is_users WHERE id=? AND role <> 'customer' AND active=1", [$assignee])) { abort(400, 'Invalid assignee.'); }
    $ref = trim((string) ($_POST['reference'] ?? ''));
    $title = mb_substr($a['title'], 0, 115) . ($ref !== '' ? ' - ' . mb_substr($ref, 0, 80) : '');
    $due = trim((string) ($_POST['due_date'] ?? ''));
    $due = valid_date($due) ? $due : null;
    $id = task_create($title, "Follow the procedure **" . $a['title'] . "** (v{$a['version']}):\n\n" . $a['body'], $assignee, (int) $u['id'], 'normal', $due, null, (int) $a['id']);
    flash('Task created from procedure.');
    redirect('/staff/tasks/' . $id);
}
