<?php
/** Loads a request only if the logged-in customer owns it. Same 404 for "missing" and "not yours" (no ID probing). */
function own_request(int $id): array
{
    $u = require_customer();
    $r = one('SELECT * FROM is_requests WHERE id=? AND user_id=?', [$id, $u['id']]);
    if (!$r) { abort(404, 'Order not found.'); }
    return $r;
}

function set_request_status(array $r, string $status, string $note, ?int $byUser): void
{
    q('UPDATE is_requests SET status=?, updated_at=? WHERE id=?', [$status, now(), $r['id']]);
    q('INSERT INTO is_request_history (request_id,status,note,by_user,at) VALUES (?,?,?,?,?)', [$r['id'], $status, mb_substr($note, 0, 500), $byUser, now()]);
}

function cust_dashboard(): void
{
    $u = require_customer();
    $reqs = all('SELECT * FROM is_requests WHERE user_id=? ORDER BY id DESC', [$u['id']]);
    $usage = customer_usage((int) $u['id']);
    render('customer/dashboard', ['title' => 'My account', 'u' => $u, 'reqs' => $reqs, 'usage' => $usage, 'nav' => 'dash'], 'app');
}

function cust_request_form(): void
{
    $u = require_customer();
    render('customer/request_new', ['title' => 'New order', 'nav' => 'new', 'old' => [], 'usage' => customer_usage((int) $u['id'])], 'app');
}

function cust_request_create(): void
{
    $u = require_customer();
    $material = (string) ($_POST['material'] ?? '');
    $color = (string) ($_POST['color'] ?? '');
    $qty = (int) ($_POST['quantity'] ?? 1);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $files = uploaded_list('files');
    $errors = [];
    if (!in_array($material, MATERIALS, true)) { $errors[] = 'Choose a material.'; }
    if (!in_array($color, COLORS, true)) { $errors[] = 'Choose a colour.'; }
    if ($qty < 1 || $qty > 10000) { $errors[] = 'Quantity must be between 1 and 10,000.'; }
    if (mb_strlen($notes) > 4000) { $errors[] = 'Notes are too long.'; }
    if (!$files) { $errors[] = 'Attach at least one STL or 3MF file.'; }

    $accepted = check_uploads($files, (int) $u['id'], $errors);
    if (rate_limited('request', (string) $u['id'], 30, 30, 60)) { $errors[] = 'Too many orders. Please try again later.'; }

    if ($errors) {
        render('customer/request_new', ['title' => 'New order', 'nav' => 'new', 'errors' => $errors, 'old' => ['material' => $material, 'color' => $color, 'quantity' => $qty, 'notes' => $notes], 'usage' => customer_usage((int) $u['id'])], 'app');
        return;
    }
    note_attempt('request', (string) $u['id']);

    $pdo = db();
    $pdo->beginTransaction();
    $stored = [];
    try {
        $id = insert('INSERT INTO is_requests (ref,user_id,material,color,quantity,notes,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
            ['TMP' . bin2hex(random_bytes(6)), $u['id'], $material, $color, $qty, $notes ?: null, 'requested', now(), now()]);
        $ref = 'IS-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        q('UPDATE is_requests SET ref=? WHERE id=?', [$ref, $id]);
        store_uploads($id, (int) $u['id'], $accepted, $stored);
        q('INSERT INTO is_request_history (request_id,status,note,by_user,at) VALUES (?,?,?,?,?)', [$id, 'requested', 'Order placed by customer', $u['id'], now()]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        foreach ($stored as $p) { @unlink($p); }
        error_log('request create failed: ' . $ex->getMessage());
        render('customer/request_new', ['title' => 'New order', 'nav' => 'new', 'errors' => ['Something went wrong saving your request. Please try again.'], 'old' => [], 'usage' => 0], 'app');
        return;
    }
    audit('request.create', $ref);
    foreach (all("SELECT email FROM is_users WHERE role IN ('owner','sales') AND active=1") as $s) {
        send_mail($s['email'], "New order $ref", "{$u['name']} placed $ref ($qty x $material, $color).\n" . cfg('base_url') . "/staff/requests/$id");
    }
    flash("Order $ref placed. We will review it and send you a quote.");
    redirect("/requests/$id");
}

function cust_request_show(string $id): void
{
    $r = own_request((int) $id);
    $files = all('SELECT * FROM is_request_files WHERE request_id=?', [$r['id']]);
    $hist = all('SELECT * FROM is_request_history WHERE request_id=? ORDER BY id', [$r['id']]);
    render('customer/request_show', ['title' => 'Order ' . $r['ref'], 'nav' => 'dash', 'r' => $r, 'files' => $files, 'hist' => $hist], 'app');
}

function cust_request_approve(string $id): void
{
    $r = own_request((int) $id);
    if (($_POST['quote_amount'] ?? '') !== (string) $r['quote_amount']) { flash('The quote changed. Please review the new price.', 'bad'); redirect('/requests/' . $r['id']); }
    if ($r['status'] !== 'quoted' || $r['quote_amount'] === null) { abort(403, 'This order has no quote waiting for approval.'); }
    set_request_status($r, 'approved', 'Quote of ' . money($r['quote_amount']) . ' approved by customer', (int) user()['id']);
    audit('quote.approve', $r['ref']);
    foreach (all("SELECT email FROM is_users WHERE role IN ('owner','sales') AND active=1") as $s) {
        send_mail($s['email'], "Quote approved: {$r['ref']}", "The customer approved the quote for {$r['ref']}.\n" . cfg('base_url') . "/staff/requests/{$r['id']}");
    }
    flash('Quote approved. We will schedule your job.');
    redirect('/requests/' . $r['id']);
}

function cust_request_cancel(string $id): void
{
    $r = own_request((int) $id);
    if (!in_array($r['status'], ['requested', 'under_review', 'quoted'], true)) { abort(403, 'This order can no longer be cancelled online. Please contact us.'); }
    set_request_status($r, 'cancelled', 'Cancelled by customer', (int) user()['id']);
    audit('request.cancel', $r['ref']);
    flash('Order cancelled.');
    redirect('/account');
}

/** Authenticated, streamed download. Owner of the file, or staff with request access. */
function file_download(string $id): void
{
    $u = require_login();
    $f = one('SELECT * FROM is_request_files WHERE id=?', [(int) $id]);
    if (!$f || !(($f['user_id'] === $u['id'] || (int) $f['user_id'] === (int) $u['id']) || can('requests.view', $u))) { abort(404, 'File not found.'); }
    $path = cfg('storage') . '/uploads/' . $f['stored_name'];
    if (!preg_match('/^[a-f0-9]{40}$/', $f['stored_name']) || !is_file($path)) { abort(404, 'File not found.'); }
    audit('file.download', 'file ' . $f['id']);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $f['orig_name']) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    session_write_close();
    readfile($path);
    exit;
}

function cust_profile_form(): void
{
    $u = require_customer();
    render('customer/profile', ['title' => 'Profile', 'nav' => 'profile', 'u' => $u], 'app');
}

function cust_profile(): void
{
    $u = require_customer();
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $errors = [];
    if ($name === '' || mb_strlen($name) > 120) { $errors[] = 'Name is required.'; }
    if (!preg_match('/^[0-9 +()-]{0,30}$/', $phone)) { $errors[] = 'Phone number looks invalid.'; }
    $new = (string) ($_POST['new_password'] ?? '');
    if ($new !== '') {
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $u['pass_hash'])) { $errors[] = 'Current password is incorrect.'; }
        if ($p = password_problem($new)) { $errors[] = $p; }
    }
    if ($errors) { render('customer/profile', ['title' => 'Profile', 'nav' => 'profile', 'u' => $u, 'errors' => $errors], 'app'); return; }
    q('UPDATE is_users SET name=?, phone=? WHERE id=?', [$name, $phone ?: null, $u['id']]);
    if ($new !== '') { q('UPDATE is_users SET pass_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]); audit('password.change'); }
    flash('Profile updated.');
    redirect('/account/profile');
}

/** Data export (JSON) of everything we hold about the logged-in customer. */
function cust_export(): void
{
    $u = require_customer();
    $data = [
        'account' => ['name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'], 'registered' => $u['created_at']],
        'requests' => all('SELECT ref,material,color,quantity,notes,status,quote_amount,quote_note,created_at FROM is_requests WHERE user_id=?', [$u['id']]),
        'files' => all('SELECT orig_name,kind,size_bytes,sha256,created_at FROM is_request_files WHERE user_id=?', [$u['id']]),
    ];
    audit('account.export');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="my-data.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Creates a task for staff to process a deletion request after checking legal retention needs. */
function cust_delete_request(): void
{
    $u = require_customer();
    $owner = one("SELECT id FROM is_users WHERE role='owner' AND active=1 ORDER BY id LIMIT 1");
    $kb = one("SELECT id FROM is_kb_articles WHERE slug LIKE 'customer-data-requests%' LIMIT 1");
    if ($owner) {
        task_create('Deletion request from ' . $u['email'], "Customer asked to delete their account and data. Follow the linked procedure; reply to the customer when done.", (int) $owner['id'], (int) $owner['id'], 'high', date('Y-m-d', strtotime('+7 days')), 'Privacy', $kb['id'] ?? null);
    }
    audit('account.delete_request');
    flash('We have received your request and will confirm by email.');
    redirect('/account/profile');
}
