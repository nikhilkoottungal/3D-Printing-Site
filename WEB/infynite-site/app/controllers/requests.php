<?php
function sreq_list(): void
{
    $u = require_staff('requests.view');
    $f = (string) ($_GET['status'] ?? '');
    $where = isset(STATUSES[$f]) ? 'WHERE r.status=?' : '';
    $rows = all("SELECT r.*, u.name cname, u.email cemail FROM is_requests r JOIN is_users u ON u.id=r.user_id $where ORDER BY r.id DESC LIMIT 300", $where ? [$f] : []);
    $counts = [];
    foreach (all('SELECT status, COUNT(*) c FROM is_requests GROUP BY status') as $c) { $counts[$c['status']] = (int) $c['c']; }
    render('staff/requests', ['title' => 'Orders', 'nav' => 'requests', 'rows' => $rows, 'filter' => $f, 'counts' => $counts], 'staff');
}

function sreq_show(string $id): void
{
    $u = require_staff('requests.view');
    $r = one('SELECT r.*, u.name cname, u.email cemail, u.phone cphone FROM is_requests r JOIN is_users u ON u.id=r.user_id WHERE r.id=?', [(int) $id]);
    if (!$r) { abort(404); }
    $files = all('SELECT * FROM is_request_files WHERE request_id=?', [$r['id']]);
    $hist = all('SELECT h.*, u.name uname FROM is_request_history h LEFT JOIN is_users u ON u.id=h.by_user WHERE h.request_id=? ORDER BY h.id', [$r['id']]);
    $tasks = all('SELECT t.*, a.name aname FROM is_tasks t LEFT JOIN is_users a ON a.id=t.assignee_id WHERE t.request_id=? ORDER BY t.id', [$r['id']]);
    render('staff/request_show', ['title' => 'Order ' . $r['ref'], 'nav' => 'requests', 'r' => $r, 'files' => $files, 'hist' => $hist, 'tasks' => $tasks, 'u' => $u, 'people' => staff_users()], 'staff');
}

function sreq_update(string $id): void
{
    $u = require_staff('requests.view');
    $r = one('SELECT * FROM is_requests WHERE id=?', [(int) $id]);
    if (!$r) { abort(404); }
    $action = (string) ($_POST['action'] ?? '');
    $back = '/staff/requests/' . $r['id'];

    if ($action === 'quote') {
        if (!can('requests.quote', $u)) { abort(403); }
        $amount = trim((string) ($_POST['amount'] ?? ''));
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $amount) || (float) $amount <= 0) { flash('Enter a valid amount.', 'bad'); redirect($back); }
        $note = trim((string) ($_POST['quote_note'] ?? ''));
        if (mb_strlen($note) > 500) { flash('Quote note is limited to 500 characters.', 'bad'); redirect($back); }
        if (!in_array($r['status'], ['requested', 'under_review', 'quoted'], true)) { flash('This order can no longer be re-quoted.', 'bad'); redirect($back); }
        q('UPDATE is_requests SET quote_amount=?, quote_note=? WHERE id=?', [$amount, $note ?: null, $r['id']]);
        set_request_status($r, 'quoted', 'Quote ' . money($amount) . ($note ? ' - ' . $note : ''), (int) $u['id']);
        $c = one('SELECT * FROM is_users WHERE id=?', [$r['user_id']]);
        send_mail($c['email'], "Your quote for {$r['ref']}", "Hi {$c['name']},\n\nYour quote is ready: " . money($amount) . ".\n" . ($note ? "$note\n" : '') . "\nReview and approve it in your account:\n" . cfg('base_url') . "/requests/{$r['id']}");
        audit('quote.send', $r['ref'] . ' ' . $amount);
        flash('Quote sent to the customer.');
    } elseif ($action === 'status') {
        $s = (string) ($_POST['status'] ?? '');
        if (!isset(STATUSES[$s]) || $s === $r['status']) { flash('Choose a different status.', 'bad'); redirect($back); }
        if (!can(STATUS_PERM[$s], $u)) { abort(403, 'Your role cannot set that status.'); }
        if ($s === 'quoted' && $r['quote_amount'] === null) { flash('Use "Send quote" to enter the price first.', 'bad'); redirect($back); }
        $note = trim((string) ($_POST['note'] ?? ''));
        set_request_status($r, $s, $note, (int) $u['id']);
        if (in_array($s, ['dispatched', 'completed', 'failed', 'cancelled', 'scheduled', 'printing'], true)) {
            $c = one('SELECT * FROM is_users WHERE id=?', [$r['user_id']]);
            send_mail($c['email'], "Update on {$r['ref']}: " . STATUSES[$s], "Hi {$c['name']},\n\nYour order {$r['ref']} is now: " . STATUSES[$s] . ".\n" . ($note ? "$note\n" : '') . "\n" . cfg('base_url') . "/requests/{$r['id']}");
        }
        audit('request.status', $r['ref'] . ' -> ' . $s);
        flash('Status updated.');
    } elseif ($action === 'sync') {
        if (!can('requests.quote', $u)) { abort(403); }
        if ($r['quote_amount'] === null) { flash('Send a quote first.', 'bad'); redirect($back); }
        flash(dol_sync_request($r), dol_enabled() ? 'ok' : 'bad');
    } elseif ($action === 'task') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') { flash('Task title required.', 'bad'); redirect($back); }
        $assignee = (int) ($_POST['assignee'] ?? 0) ?: null;
        $tid = task_create($title, 'Related to order ' . $r['ref'], $assignee, (int) $u['id'], 'normal', null, null, null, (int) $r['id']);
        flash('Task created.');
        redirect('/staff/tasks/' . $tid);
    }
    redirect($back);
}

/* ---- Direct (walk-in / phone / e-mail) orders entered by staff ---- */
function direct_customers(): array
{
    return all("SELECT id,name,email FROM is_users WHERE role='customer' AND active=1 ORDER BY name LIMIT 1000");
}

function sreq_new_form(): void
{
    $u = require_staff('requests.create');
    render('staff/request_new', ['title' => 'New direct order', 'nav' => 'requests', 'customers' => direct_customers(), 'people' => staff_users(), 'old' => ['customer_id' => (int) ($_GET['customer'] ?? 0)], 'u' => $u], 'staff');
}

function sreq_create(): void
{
    $u = require_staff('requests.create');
    $errors = [];
    $cid = (int) ($_POST['customer_id'] ?? 0);
    $cname = trim((string) ($_POST['c_name'] ?? ''));
    $cemail = strtolower(trim((string) ($_POST['c_email'] ?? '')));
    $cphone = trim((string) ($_POST['c_phone'] ?? ''));
    $material = (string) ($_POST['material'] ?? '');
    $color = (string) ($_POST['color'] ?? '');
    $qty = (int) ($_POST['quantity'] ?? 1);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $status = (string) ($_POST['status'] ?? 'approved');
    $assignee = (int) ($_POST['assignee_id'] ?? 0) ?: null;

    $customer = null;
    if ($cid) {
        $customer = one("SELECT * FROM is_users WHERE id=? AND role='customer' AND active=1", [$cid]);
        if (!$customer) { $errors[] = 'Choose a valid customer.'; }
    } else {
        if ($cname === '' || mb_strlen($cname) > 120) { $errors[] = 'Enter the customer name, or pick an existing customer.'; }
        if ($cemail !== '' && (!filter_var($cemail, FILTER_VALIDATE_EMAIL) || strlen($cemail) > 190)) { $errors[] = 'Customer email looks invalid.'; }
        if ($cemail !== '' && one('SELECT id FROM is_users WHERE email=?', [$cemail])) { $errors[] = 'That email already has an account; pick it from the customer list.'; }
        if (!preg_match('/^[0-9 +()-]{0,30}$/', $cphone)) { $errors[] = 'Phone number looks invalid.'; }
    }
    if (!in_array($material, MATERIALS, true)) { $errors[] = 'Choose a material.'; }
    if (!in_array($color, COLORS, true)) { $errors[] = 'Choose a colour.'; }
    if ($qty < 1 || $qty > 10000) { $errors[] = 'Quantity must be between 1 and 10,000.'; }
    if (mb_strlen($notes) > 4000) { $errors[] = 'Notes are too long.'; }
    if ($amount !== '' && (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $amount) || (float) $amount <= 0)) { $errors[] = 'Enter a valid amount.'; }
    if (!isset(STATUSES[$status]) || in_array($status, ['completed', 'cancelled', 'failed'], true) || !can(STATUS_PERM[$status], $u)) { $errors[] = 'You cannot start an order in that status.'; }
    if (in_array($status, ['quoted', 'approved', 'scheduled', 'printing'], true) && $amount === '') { $errors[] = 'Enter the agreed price for that status (or start as Requested / Under review).'; }
    if ($assignee && !one("SELECT id FROM is_users WHERE id=? AND role <> 'customer' AND active=1", [$assignee])) { $errors[] = 'Invalid assignee.'; }

    $files = uploaded_list('files');
    $accepted = $customer ? check_uploads($files, (int) $customer['id'], $errors) : check_uploads($files, 0, $errors);
    if ($errors) {
        render('staff/request_new', ['title' => 'New direct order', 'nav' => 'requests', 'customers' => direct_customers(), 'people' => staff_users(), 'errors' => $errors, 'u' => $u,
            'old' => ['customer_id' => $cid, 'c_name' => $cname, 'c_email' => $cemail, 'c_phone' => $cphone, 'material' => $material, 'color' => $color, 'quantity' => $qty, 'notes' => $notes, 'amount' => $amount, 'status' => $status, 'assignee_id' => $assignee]], 'staff');
        return;
    }

    $pdo = db(); $pdo->beginTransaction(); $stored = [];
    try {
        if (!$customer) {
            // Walk-in customer: no login until they use "Forgot password" with the same email.
            $email = $cemail !== '' ? $cemail : 'walkin-' . bin2hex(random_bytes(8)) . '@walkin.invalid';
            $newId = insert('INSERT INTO is_users (email,name,phone,pass_hash,role,created_at) VALUES (?,?,?,?,?,?)',
                [$email, $cname, $cphone ?: null, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'customer', now()]);
            $customer = one('SELECT * FROM is_users WHERE id=?', [$newId]);
        }
        $id = insert('INSERT INTO is_requests (ref,user_id,material,color,quantity,notes,status,quote_amount,source,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            ['TMP' . bin2hex(random_bytes(6)), $customer['id'], $material, $color, $qty, $notes ?: null, $status, $amount !== '' ? $amount : null, 'direct', $u['id'], now(), now()]);
        $ref = 'IS-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        q('UPDATE is_requests SET ref=? WHERE id=?', [$ref, $id]);
        store_uploads($id, (int) $customer['id'], $accepted, $stored);
        q('INSERT INTO is_request_history (request_id,status,note,by_user,at) VALUES (?,?,?,?,?)',
            [$id, $status, 'Direct order entered by ' . $u['name'] . ($amount !== '' ? ', agreed price ' . money($amount) : ''), $u['id'], now()]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        foreach ($stored as $p) { @unlink($p); }
        error_log('direct order failed: ' . $ex->getMessage());
        abort(500, 'Could not save the order. Please try again.');
    }
    audit('request.direct', $ref);
    if ($assignee || !empty($_POST['make_task'])) {
        $tid = task_create('Produce ' . $ref . ': ' . $qty . ' × ' . $material . ' ' . $color, "Direct order for {$customer['name']}.\n\n" . ($notes ?: ''), $assignee, (int) $u['id'], 'normal', null, null, null, $id);
    }
    flash("Order $ref created for {$customer['name']}.");
    redirect('/staff/requests/' . $id);
}
