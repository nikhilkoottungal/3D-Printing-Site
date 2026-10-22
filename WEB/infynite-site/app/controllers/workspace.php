<?php
/** Recurring tasks, notes and checklists, attachments, calendar, dashboard figures, data exports. */

/* ================= recurring tasks ================= */
const REC_FREQ = ['daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month'];
const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
function rec_advance(string $date, array $r): string
{
    $n = max(1, (int) $r['every']);
    if ($r['freq'] === 'daily') { return date('Y-m-d', strtotime("$date +$n days")); }
    if ($r['freq'] === 'weekly') { return date('Y-m-d', strtotime("$date +" . (7 * $n) . ' days')); }
    $d = new DateTime($date); $dom = (int) ($r['dom'] ?: $d->format('j')); $d->modify('first day of this month')->modify("+$n months");
    return $d->setDate((int) $d->format('Y'), (int) $d->format('n'), min($dom, (int) $d->format('t')))->format('Y-m-d');
}
function rec_text(array $r): string
{
    $n = (int) $r['every'];
    return match ($r['freq']) { 'daily' => $n === 1 ? 'every day' : "every $n days", 'weekly' => ($n === 1 ? 'every week' : "every $n weeks") . ' on ' . (WEEKDAYS[(int) $r['weekday']] ?? 'the start day'), default => ($n === 1 ? 'every month' : "every $n months") . ' on day ' . (int) $r['dom'] };
}
/** Creates the tasks that have come due. One task per schedule, even after a long gap. Returns how many were made. */
function rec_generate(): int
{
    $made = 0; $today = date('Y-m-d');
    foreach (all('SELECT * FROM is_recurring_tasks WHERE active=1 AND next_run <= ? ORDER BY id', [$today]) as $r) {
        $due = $r['next_run']; $next = $r['next_run'];
        for ($i = 0; $i < 400 && $next <= $today; $i++) { $due = $next; $next = rec_advance($next, $r); }
        $assignee = $r['assignee_id'] && one('SELECT id FROM is_users WHERE id=? AND active=1', [$r['assignee_id']]) ? (int) $r['assignee_id'] : null;
        $tid = task_create($r['title'], (string) $r['description'], $assignee, (int) ($r['created_by'] ?? 0) ?: (int) one("SELECT id FROM is_users WHERE role='owner' ORDER BY id LIMIT 1")['id'], $r['priority'], date('Y-m-d', strtotime($due . ' +' . (int) $r['due_days'] . ' days')), $r['phase'] ?: 'Recurring', $r['kb_id'] ? (int) $r['kb_id'] : null);
        q('UPDATE is_recurring_tasks SET last_run=?, next_run=? WHERE id=?', [$due, $next, $r['id']]); $made++;
    }
    return $made;
}
function rec_list(): void
{
    $u = require_staff();
    render('staff/recurring', ['title' => 'Recurring tasks', 'nav' => 'tasks', 'rows' => all('SELECT r.*, a.name aname FROM is_recurring_tasks r LEFT JOIN is_users a ON a.id=r.assignee_id ORDER BY r.active DESC, r.next_run'), 'people' => staff_users(), 'u' => $u], 'staff');
}
function rec_create(): void
{
    $u = require_staff();
    $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 160); $freq = (string) ($_POST['freq'] ?? ''); $every = (int) ($_POST['every'] ?? 1); $start = (string) ($_POST['start'] ?? '');
    $prio = (string) ($_POST['priority'] ?? 'normal'); $as = (int) ($_POST['assignee_id'] ?? 0) ?: null; $due = max(0, min(365, (int) ($_POST['due_days'] ?? 0)));
    if ($title === '' || !isset(REC_FREQ[$freq]) || $every < 1 || $every > 52 || !valid_date($start) || !isset(TASK_PRIORITIES[$prio]) || ($as && !one("SELECT id FROM is_users WHERE id=? AND role<>'customer' AND active=1", [$as]))) { flash('Check the title, the repeat and the start date.', 'bad'); redirect('/staff/recurring'); }
    $wd = $freq === 'weekly' ? (int) date('N', strtotime($start)) : null; $dom = $freq === 'monthly' ? (int) date('j', strtotime($start)) : null;
    q('INSERT INTO is_recurring_tasks (title,description,priority,assignee_id,phase,freq,every,weekday,dom,due_days,next_run,active,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$title, mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 4000) ?: null, $prio, $as, mb_substr(trim((string) ($_POST['phase'] ?? '')), 0, 40) ?: null, $freq, $every, $wd, $dom, $due, $start, 1, $u['id'], now()]);
    audit('recurring.create', $title); $made = rec_generate(); flash('Recurring task saved.' . ($made ? ' The first task was created now.' : '')); redirect('/staff/recurring');
}
function rec_update(string $id): void
{
    $u = require_staff(); $r = one('SELECT * FROM is_recurring_tasks WHERE id=?', [(int) $id]); if (!$r) { abort(404); }
    if ((int) $r['created_by'] !== (int) $u['id'] && !can('team.manage')) { abort(403, 'Only its creator or the Owner can change this schedule.'); }
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'toggle') { q('UPDATE is_recurring_tasks SET active=1-active WHERE id=?', [$r['id']]); flash($r['active'] ? 'Paused.' : 'Resumed.'); }
    elseif ($a === 'delete') { q('DELETE FROM is_recurring_tasks WHERE id=?', [$r['id']]); audit('recurring.delete', $r['title']); flash('Schedule removed. Tasks already created stay.'); }
    redirect('/staff/recurring');
}

/* ================= notes ================= */
const NOTE_COLORS = ['none' => 'None', 'yellow' => 'Yellow', 'green' => 'Green', 'blue' => 'Blue', 'pink' => 'Pink', 'grey' => 'Grey'];
function note_visible(array $n, array $u): bool { return (int) $n['owner_id'] === (int) $u['id'] || (int) $n['shared'] === 1; }
function note_items(?string $json): array { $a = json_decode((string) $json, true); return is_array($a) ? $a : []; }
function notes_page(): void
{
    $u = require_staff(); $qs = trim((string) ($_GET['q'] ?? '')); $arch = !empty($_GET['archived']);
    $where = 'WHERE (n.owner_id=? OR n.shared=1) AND n.archived=?'; $p = [$u['id'], $arch ? 1 : 0];
    if ($qs !== '') { $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qs) . '%'; $where .= ' AND (n.title LIKE ? OR n.body LIKE ? OR n.items LIKE ?)'; array_push($p, $like, $like, $like); }
    $rows = all("SELECT n.*, o.name oname, (SELECT COUNT(*) FROM is_attachments a WHERE a.kind='note' AND a.ref_id=n.id) files FROM is_notes n JOIN is_users o ON o.id=n.owner_id $where ORDER BY n.pinned DESC, n.updated_at DESC LIMIT 200", $p);
    render('staff/notes', ['title' => 'Notes', 'nav' => 'notes', 'rows' => $rows, 'q' => $qs, 'arch' => $arch, 'u' => $u], 'staff');
}
function note_clean_items(string $text): array
{
    $out = []; foreach (preg_split('/\R/', $text) as $l) { $l = trim(preg_replace('/^[-*\[\] x]*\s/i', '', trim($l) . ' ')); if ($l !== '') { $out[] = ['t' => mb_substr($l, 0, 200), 'd' => false]; } if (count($out) >= 100) { break; } }
    return $out;
}
function note_create(): void
{
    $u = require_staff(); $kind = ($_POST['kind'] ?? '') === 'list' ? 'list' : 'text'; $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 160); $body = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 20000);
    if ($title === '' && $body === '') { flash('Write something first.', 'bad'); redirect('/staff/notes'); }
    $color = isset(NOTE_COLORS[(string) ($_POST['color'] ?? '')]) ? (string) $_POST['color'] : 'none';
    $items = $kind === 'list' ? note_clean_items($body) : []; if ($kind === 'list' && !$items && $title === '') { flash('Add at least one line to the checklist.', 'bad'); redirect('/staff/notes'); }
    $id = insert('INSERT INTO is_notes (owner_id,title,body,kind,items,color,pinned,archived,shared,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$u['id'], $title ?: null, $kind === 'text' ? $body : null, $kind, $kind === 'list' ? json_encode($items, JSON_UNESCAPED_UNICODE) : null, $color, 0, 0, !empty($_POST['shared']) ? 1 : 0, now(), now()]);
    redirect('/staff/notes#n' . $id);
}
function note_edit_page(string $id): void
{
    $u = require_staff(); $n = one('SELECT * FROM is_notes WHERE id=?', [(int) $id]); if (!$n || !note_visible($n, $u)) { abort(404); }
    render('staff/note_edit', ['title' => 'Note', 'nav' => 'notes', 'n' => $n, 'items' => note_items($n['items']), 'files' => all("SELECT * FROM is_attachments WHERE kind='note' AND ref_id=? ORDER BY id", [$n['id']]), 'mine' => (int) $n['owner_id'] === (int) $u['id'], 'u' => $u], 'staff');
}
function note_update(string $id): void
{
    $u = require_staff(); $n = one('SELECT * FROM is_notes WHERE id=?', [(int) $id]); if (!$n || !note_visible($n, $u)) { abort(404); }
    $a = (string) ($_POST['action'] ?? ''); $mine = (int) $n['owner_id'] === (int) $u['id']; $back = (string) ($_POST['back'] ?? '') === 'edit' ? '/staff/notes/' . $n['id'] : '/staff/notes' . ($n['archived'] ? '?archived=1' : '');
    if ($a === 'tick') {    // anyone who can see a shared checklist may tick items
        $items = note_items($n['items']); $i = (int) ($_POST['i'] ?? -1);
        if (isset($items[$i])) { $items[$i]['d'] = !$items[$i]['d']; q('UPDATE is_notes SET items=?, updated_at=? WHERE id=?', [json_encode($items, JSON_UNESCAPED_UNICODE), now(), $n['id']]); }
        redirect($back);
    }
    if (!$mine) { abort(403, 'Only the author can change this note.'); }
    if ($a === 'save') {
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 160); $body = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 20000); $color = isset(NOTE_COLORS[(string) ($_POST['color'] ?? '')]) ? (string) $_POST['color'] : 'none';
        if ($n['kind'] === 'list') { $old = note_items($n['items']); $new = note_clean_items($body); foreach ($new as &$x) { foreach ($old as $o) { if ($o['t'] === $x['t']) { $x['d'] = $o['d']; } } } unset($x); q('UPDATE is_notes SET title=?, items=?, color=?, shared=?, updated_at=? WHERE id=?', [$title ?: null, json_encode($new, JSON_UNESCAPED_UNICODE), $color, !empty($_POST['shared']) ? 1 : 0, now(), $n['id']]); }
        else { q('UPDATE is_notes SET title=?, body=?, color=?, shared=?, updated_at=? WHERE id=?', [$title ?: null, $body, $color, !empty($_POST['shared']) ? 1 : 0, now(), $n['id']]); }
        flash('Note saved.'); redirect('/staff/notes/' . $n['id']);
    }
    if ($a === 'pin') { q('UPDATE is_notes SET pinned=1-pinned WHERE id=?', [$n['id']]); }
    elseif ($a === 'archive') { q('UPDATE is_notes SET archived=1-archived WHERE id=?', [$n['id']]); redirect('/staff/notes'); }
    elseif ($a === 'delete') { foreach (all("SELECT * FROM is_attachments WHERE kind='note' AND ref_id=?", [$n['id']]) as $f) { @unlink(cfg('storage') . '/uploads/att/' . $f['stored_name']); } q("DELETE FROM is_attachments WHERE kind='note' AND ref_id=?", [$n['id']]); q('DELETE FROM is_notes WHERE id=?', [$n['id']]); flash('Note deleted.'); redirect('/staff/notes'); }
    redirect($back);
}

/* ================= attachments (tasks and notes) ================= */
const ATT_EXT = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'zip' => 'application/zip'];
function att_can(string $kind, int $ref, array $u, bool $write): bool
{
    if ($kind === 'task') { return (bool) one('SELECT id FROM is_tasks WHERE id=?', [$ref]); }
    $n = one('SELECT * FROM is_notes WHERE id=?', [$ref]); return $n && ($write ? (int) $n['owner_id'] === (int) $u['id'] : note_visible($n, $u));
}
function att_upload(): void
{
    $u = require_staff(); $kind = (string) ($_POST['kind'] ?? ''); $ref = (int) ($_POST['ref_id'] ?? 0);
    $back = $kind === 'note' ? '/staff/notes/' . $ref : '/staff/tasks/' . $ref;
    if (!in_array($kind, ['task', 'note'], true) || !att_can($kind, $ref, $u, true)) { abort(404); }
    $dir = cfg('storage') . '/uploads/att'; if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $f = $_FILES['files'] ?? null; $n = 0; $errs = [];
    if (!$f || !is_array($f['name'])) { flash('Choose a file.', 'bad'); redirect($back); }
    foreach ($f['name'] as $i => $name) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) { continue; } if (++$n > 5) { $errs[] = 'Only 5 files at a time.'; break; }
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION)); $tmp = $f['tmp_name'][$i];
        if ($f['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) { $errs[] = "$name: upload failed."; continue; }
        if (!isset(ATT_EXT[$ext])) { $errs[] = "$name: this type is not allowed (" . implode(', ', array_keys(ATT_EXT)) . ')'; continue; }
        if ($f['size'][$i] > 15 * 1024 * 1024) { $errs[] = "$name: larger than 15 MB."; continue; }
        $head = (string) file_get_contents($tmp, false, null, 0, 8);
        $ok = match (true) { in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) => @getimagesize($tmp) !== false, $ext === 'pdf' => str_starts_with($head, '%PDF'), in_array($ext, ['docx', 'xlsx', 'pptx', 'zip'], true) => str_starts_with($head, 'PK'), default => !str_contains((string) file_get_contents($tmp, false, null, 0, 4096), "\0") };
        if (!$ok) { $errs[] = "$name: the content does not look like a $ext file."; continue; }
        $stored = bin2hex(random_bytes(20)); if (!move_uploaded_file($tmp, "$dir/$stored")) { $errs[] = "$name: could not be saved."; continue; }
        q('INSERT INTO is_attachments (kind,ref_id,orig_name,stored_name,mime,size_bytes,uploaded_by,created_at) VALUES (?,?,?,?,?,?,?,?)', [$kind, $ref, mb_substr(preg_replace('/[^\w. ()-]/u', '_', basename((string) $name)), 0, 190), $stored, ATT_EXT[$ext], (int) $f['size'][$i], $u['id'], now()]);
        audit('attachment.add', "$kind $ref $name");
    }
    if ($errs) { flash(implode(' ', $errs), 'bad'); } else { flash('File attached.'); }
    redirect($back);
}
function att_get(string $id): void
{
    $u = require_staff(); $a = one('SELECT * FROM is_attachments WHERE id=?', [(int) $id]);
    if (!$a || !att_can($a['kind'], (int) $a['ref_id'], $u, false)) { abort(404); }
    $path = cfg('storage') . '/uploads/att/' . $a['stored_name']; if (!preg_match('/^[a-f0-9]{40}$/', $a['stored_name']) || !is_file($path)) { abort(404); }
    $inline = !empty($_GET['inline']) && str_starts_with($a['mime'], 'image/');
    header('Content-Type: ' . ($inline ? $a['mime'] : 'application/octet-stream')); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $a['orig_name']) . '"'); header('Content-Length: ' . filesize($path)); header('Cache-Control: private, no-store');
    session_write_close(); readfile($path); exit;
}
function att_delete(string $id): void
{
    $u = require_staff(); $a = one('SELECT * FROM is_attachments WHERE id=?', [(int) $id]); if (!$a) { abort(404); }
    if ((int) $a['uploaded_by'] !== (int) $u['id'] && !can('team.manage')) { abort(403, 'Only the person who attached it or the Owner can remove it.'); }
    @unlink(cfg('storage') . '/uploads/att/' . $a['stored_name']); q('DELETE FROM is_attachments WHERE id=?', [$a['id']]); audit('attachment.delete', $a['kind'] . ' ' . $a['ref_id']);
    flash('File removed.'); redirect(($a['kind'] === 'note' ? '/staff/notes/' : '/staff/tasks/') . $a['ref_id']);
}
function att_for(string $kind, int $ref): array { return all('SELECT a.*, u.name uname FROM is_attachments a LEFT JOIN is_users u ON u.id=a.uploaded_by WHERE a.kind=? AND a.ref_id=? ORDER BY a.id', [$kind, $ref]); }

/* ================= calendar ================= */
function calendar_page(): void
{
    $u = require_staff(); $m = (string) ($_GET['m'] ?? date('Y-m')); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) { $m = date('Y-m'); }
    $first = "$m-01"; $last = date('Y-m-t', strtotime($first)); $mine = !empty($_GET['mine']); $ev = [];
    $add = function (string $d, string $cls, string $label, string $href) use (&$ev) { $ev[$d][] = [$cls, $label, $href]; };
    foreach (all("SELECT t.id, t.title, t.due_date, a.name aname FROM is_tasks t LEFT JOIN is_users a ON a.id=t.assignee_id WHERE t.status<>'done' AND t.due_date BETWEEN ? AND ?" . ($mine ? ' AND t.assignee_id=' . (int) $u['id'] : ''), [$first, $last]) as $t) { $add($t['due_date'], 'p-info', 'Task: ' . $t['title'] . ($t['aname'] ? ' (' . $t['aname'] . ')' : ''), '/staff/tasks/' . $t['id']); }
    foreach (all("SELECT p.id, p.name, p.next_maintenance FROM is_printers p WHERE p.active=1 AND p.next_maintenance BETWEEN ? AND ?", [$first, $last]) as $p) { $add($p['next_maintenance'], 'p-warn', 'Maintenance: ' . $p['name'], '/staff/printers'); }
    foreach (all("SELECT j.id, j.scheduled_for, r.ref, r.id rid, p.name pname FROM is_print_jobs j JOIN is_requests r ON r.id=j.request_id LEFT JOIN is_printers p ON p.id=j.printer_id WHERE j.status IN ('queued','printing') AND j.scheduled_for BETWEEN ? AND ?", [$first, $last]) as $j) { $add($j['scheduled_for'], '', 'Print ' . $j['ref'] . ($j['pname'] ? ' on ' . $j['pname'] : ''), '/staff/requests/' . $j['rid']); }
    if (can('requests.view')) { foreach (all("SELECT r.id, r.ref, r.due_date, u.name cname FROM is_requests r JOIN is_users u ON u.id=r.user_id WHERE r.due_date BETWEEN ? AND ? AND r.status NOT IN ('completed','cancelled','failed')", [$first, $last]) as $r) { $add($r['due_date'], 'p-bad', 'Order due: ' . $r['ref'] . ' ' . $r['cname'], '/staff/requests/' . $r['id']); } }
    if (can('invoices.manage')) { foreach (all("SELECT id, number, bill_name, due_date FROM is_invoices WHERE kind='tax' AND status='issued' AND due_date BETWEEN ? AND ?", [$first, $last]) as $i) { $add($i['due_date'], 'p-warn', 'Payment due: ' . $i['number'] . ' ' . $i['bill_name'], '/staff/invoices/' . $i['id']); } }
    if (can('purchasing.manage')) {
        foreach (all("SELECT p.id, p.number, p.expected_date, s.name sname FROM is_purchase_orders p JOIN is_suppliers s ON s.id=p.supplier_id WHERE p.status='ordered' AND p.expected_date BETWEEN ? AND ?", [$first, $last]) as $p) { $add($p['expected_date'], 'p-ok', 'Delivery expected: ' . $p['number'] . ' ' . $p['sname'], '/staff/purchase-orders/' . $p['id']); }
        foreach (all("SELECT b.id, b.bill_no, b.due_date, s.name sname FROM is_supplier_bills b JOIN is_suppliers s ON s.id=b.supplier_id WHERE b.status='open' AND b.due_date BETWEEN ? AND ?", [$first, $last]) as $b) { $add($b['due_date'], 'p-warn', 'Pay supplier: ' . $b['sname'] . ' ' . $b['bill_no'], '/staff/bills/' . $b['id']); }
    }
    render('staff/calendar', ['title' => 'Calendar', 'nav' => 'calendar', 'm' => $m, 'first' => $first, 'last' => $last, 'ev' => $ev, 'mine' => $mine, 'u' => $u], 'staff');
}

/* ================= data export ================= */
const EXPORTS = ['customers' => 'Customers', 'orders' => 'Orders', 'inventory' => 'Inventory items', 'suppliers' => 'Suppliers', 'purchase_orders' => 'Purchase orders', 'bills' => 'Supplier bills', 'expenses' => 'Expenses', 'tasks' => 'Tasks', 'payroll' => 'Payroll sheet: staff bank details (sensitive)'];
function exports_page(): void { $u = require_staff('team.manage'); render('staff/exports', ['title' => 'Data export', 'nav' => 'reports', 'u' => $u], 'staff'); }
function export_csv(string $which): void
{
    require_staff('team.manage');
    if ($which === 'payroll') {
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="payroll-' . date('Ymd') . '.csv"');
        echo "\xEF\xBB\xBF" . implode(',', array_map('csv_cell', ['Name', 'E-mail', 'Designation', 'Account holder', 'Bank', 'Account number', 'IFSC', 'UPI', 'PAN'])) . "\r\n";
        foreach (all("SELECT id,name,email FROM is_users WHERE role<>'customer' AND active=1 ORDER BY name") as $s) {
            $pr = hr_profile((int) $s['id']);
            echo implode(',', array_map('csv_cell', [$s['name'], $s['email'], $pr['designation'], $pr['bank_holder'], $pr['bank_name'], $pr['bank_acc'], $pr['bank_ifsc'], $pr['upi_id'], $pr['pan']])) . "\r\n";
        }
        audit('export.csv', 'payroll'); exit;
    }
    $sets = [
        'customers' => ['SELECT name,email,phone,address,gstin,state,created_at FROM is_users WHERE role=\'customer\' ORDER BY name', ['Name', 'Email', 'Phone', 'Address', 'GSTIN', 'State', 'Created']],
        'orders' => ['SELECT r.ref,u.name,r.material,r.color,r.quantity,r.status,r.quote_amount,r.due_date,r.courier,r.tracking,r.created_at FROM is_requests r JOIN is_users u ON u.id=r.user_id ORDER BY r.id', ['Order', 'Customer', 'Material', 'Colour', 'Qty', 'Status', 'Quote', 'Due', 'Courier', 'Tracking', 'Created']],
        'inventory' => ['SELECT sku,name,category,material,color,unit,qty,reorder_level,location,supplier,unit_cost,grams_per_unit,active FROM is_inventory_items ORDER BY name', ['SKU', 'Name', 'Category', 'Material', 'Colour', 'Unit', 'Quantity', 'Reorder level', 'Location', 'Supplier', 'Unit cost', 'Grams per unit', 'Active']],
        'suppliers' => ['SELECT name,contact,email,phone,address,gstin,active FROM is_suppliers ORDER BY name', ['Name', 'Contact', 'Email', 'Phone', 'Address', 'GSTIN', 'Active']],
        'purchase_orders' => ['SELECT p.number,s.name,p.status,p.order_date,p.expected_date,p.total FROM is_purchase_orders p JOIN is_suppliers s ON s.id=p.supplier_id ORDER BY p.id', ['PO', 'Supplier', 'Status', 'Ordered', 'Expected', 'Total']],
        'bills' => ['SELECT s.name,b.bill_no,b.bill_date,b.due_date,b.amount,b.gst_amount,b.status FROM is_supplier_bills b JOIN is_suppliers s ON s.id=b.supplier_id ORDER BY b.id', ['Supplier', 'Bill no', 'Bill date', 'Due', 'Amount', 'GST', 'Status']],
        'expenses' => ['SELECT expense_date,category,description,amount,gst_amount,paid_via FROM is_expenses ORDER BY expense_date', ['Date', 'Category', 'Description', 'Amount', 'GST', 'Paid via']],
        'tasks' => ['SELECT t.id,t.title,t.status,t.priority,a.name,t.due_date,t.phase,t.created_at FROM is_tasks t LEFT JOIN is_users a ON a.id=t.assignee_id ORDER BY t.id', ['ID', 'Title', 'Status', 'Priority', 'Assignee', 'Due', 'Group', 'Created']],
    ];
    if (!isset($sets[$which])) { abort(404); }
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $which . '-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF" . implode(',', array_map('csv_cell', $sets[$which][1])) . "\r\n";
    foreach (all($sets[$which][0]) as $r) { echo implode(',', array_map('csv_cell', array_values($r))) . "\r\n"; }
    audit('export.csv', $which); exit;
}

/* ================= dashboard figures ================= */
function dash_data(array $u): array
{
    $d = ['stages' => [], 'week' => [], 'queue' => 0, 'inv_due' => []];
    if (can('requests.view')) {
        foreach (all("SELECT status, COUNT(*) n FROM is_requests WHERE status NOT IN ('completed','cancelled','failed') GROUP BY status") as $r) { $d['stages'][$r['status']] = (int) $r['n']; }
        $d['week'] = all("SELECT r.id, r.ref, r.due_date, r.status, c.name cname FROM is_requests r JOIN is_users c ON c.id=r.user_id WHERE r.due_date IS NOT NULL AND r.due_date <= ? AND r.status NOT IN ('completed','cancelled','failed') ORDER BY r.due_date LIMIT 10", [date('Y-m-d', strtotime('+7 days'))]);
    }
    $d['queue'] = (int) one("SELECT COUNT(*) c FROM is_print_jobs WHERE status IN ('queued','printing')")['c'];
    if (can('invoices.manage')) { $d['inv_due'] = all("SELECT id, number, bill_name, due_date, total FROM is_invoices WHERE kind='tax' AND status='issued' AND due_date <= ? ORDER BY due_date LIMIT 8", [date('Y-m-d', strtotime('+7 days'))]); }
    return $d;
}
