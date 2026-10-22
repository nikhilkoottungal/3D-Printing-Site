<?php
/** Staff customer list. View: any role that can view orders. Edit/create: 'customers.manage' (Owner, Sales). */
function crm_fields(): array
{
    $f = fn($k, $m) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $m);
    return ['name' => $f('name', 120), 'email' => strtolower($f('email', 190)), 'phone' => $f('phone', 30), 'address' => $f('address', 400),
        'gstin' => strtoupper($f('gstin', 15)), 'state' => $f('state', 60)];
}
function crm_validate(array $d, ?int $selfId = null): array
{
    $e = [];
    if ($d['name'] === '') { $e[] = 'Name is required.'; }
    if ($d['email'] !== '') {
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) { $e[] = 'Email looks invalid.'; }
        elseif (one('SELECT id FROM is_users WHERE email=? AND id<>?', [$d['email'], $selfId ?? 0])) { $e[] = 'That email is already in use.'; }
    }
    if (!preg_match('/^[0-9 +()-]{0,30}$/', $d['phone'])) { $e[] = 'Phone number looks invalid.'; }
    if ($d['gstin'] !== '' && setting_clean('gstin', 15, $d['gstin'])[1]) { $e[] = 'GSTIN is not valid.'; }
    return $e;
}

function crm_list(): void
{
    $u = require_staff('requests.view');
    $s = trim((string) ($_GET['q'] ?? ''));
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
    $rows = all("SELECT u.id, u.name, u.email, u.phone, u.gstin, u.active,
        (SELECT COUNT(*) FROM is_requests r WHERE r.user_id=u.id) orders,
        (SELECT COALESCE(SUM(total),0) FROM is_invoices i WHERE i.customer_id=u.id AND i.status<>'void') invoiced,
        (SELECT COALESCE(SUM(total),0) FROM is_invoices i WHERE i.customer_id=u.id AND i.status='issued') owed
        FROM is_users u WHERE u.role='customer'" . ($s !== '' ? ' AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.gstin LIKE ?)' : '') . ' ORDER BY u.name LIMIT 500', $s !== '' ? [$like, $like, $like, $like] : []);
    render('staff/customers', ['title' => 'Customers', 'nav' => 'customers', 'rows' => $rows, 's' => $s, 'u' => $u], 'staff');
}

function crm_new_form(): void
{
    $u = require_staff('customers.manage');
    render('staff/customer_new', ['title' => 'New customer', 'nav' => 'customers', 'old' => array_fill_keys(['name', 'email', 'phone', 'address', 'gstin', 'state'], ''), 'u' => $u], 'staff');
}

function crm_create(): void
{
    $u = require_staff('customers.manage');
    $d = crm_fields(); $errs = crm_validate($d);
    if ($errs) { render('staff/customer_new', ['title' => 'New customer', 'nav' => 'customers', 'old' => $d, 'errors' => $errs, 'u' => $u], 'staff'); return; }
    $email = $d['email'] !== '' ? $d['email'] : 'walkin-' . bin2hex(random_bytes(8)) . '@walkin.invalid';
    $id = insert('INSERT INTO is_users (email,name,phone,address,gstin,state,pass_hash,role,created_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [$email, $d['name'], $d['phone'] ?: null, $d['address'] ?: null, $d['gstin'] ?: null, $d['state'] ?: null, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'customer', now()]);
    audit('customer.create', $d['name']);
    flash('Customer added.');
    redirect("/staff/customers/$id");
}

function crm_show(string $id): void
{
    $u = require_staff('requests.view');
    $c = one("SELECT * FROM is_users WHERE id=? AND role='customer'", [(int) $id]);
    if (!$c) { abort(404); }
    $orders = all('SELECT * FROM is_requests WHERE user_id=? ORDER BY id DESC LIMIT 100', [$c['id']]);
    $invoices = can('invoices.manage', $u) ? all('SELECT * FROM is_invoices WHERE customer_id=? ORDER BY id DESC LIMIT 100', [$c['id']]) : null;
    render('staff/customer_show', ['title' => $c['name'], 'nav' => 'customers', 'c' => $c, 'orders' => $orders, 'invoices' => $invoices, 'u' => $u], 'staff');
}

function crm_update(string $id): void
{
    $u = require_staff('customers.manage');
    $c = one("SELECT * FROM is_users WHERE id=? AND role='customer'", [(int) $id]);
    if (!$c) { abort(404); }
    $d = crm_fields();
    if (str_ends_with($c['email'], '@walkin.invalid') && $d['email'] === '') { $d['email'] = $c['email']; }
    $errs = crm_validate($d, (int) $c['id']);
    if ($errs) { flash(implode(' ', $errs), 'bad'); redirect("/staff/customers/{$c['id']}"); }
    q('UPDATE is_users SET name=?, email=?, phone=?, address=?, gstin=?, state=? WHERE id=?', [$d['name'], $d['email'], $d['phone'] ?: null, $d['address'] ?: null, $d['gstin'] ?: null, $d['state'] ?: null, $c['id']]);
    audit('customer.update', $d['name']);
    flash('Customer saved.');
    redirect("/staff/customers/{$c['id']}");
}
