<?php
/** Suppliers and purchase orders. Permission 'purchasing.manage' (Owner, Purchasing). Receiving a PO books stock for linked inventory items. */
const PO_STATUSES = ['ordered' => 'Ordered', 'received' => 'Received', 'cancelled' => 'Cancelled'];

function sup_fields(): array
{
    $f = fn($k, $m) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $m);
    return ['name' => $f('name', 160), 'contact' => $f('contact', 120), 'email' => $f('email', 190), 'phone' => $f('phone', 30), 'address' => $f('address', 400),
        'gstin' => strtoupper($f('gstin', 15)), 'notes' => $f('notes', 1000), 'active' => !empty($_POST['active']) ? 1 : 0];
}
function sup_errors(array $d): array
{
    $e = [];
    if ($d['name'] === '') { $e[] = 'Supplier name is required.'; }
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) { $e[] = 'Email looks invalid.'; }
    if (!preg_match('/^[0-9 +()-]{0,30}$/', $d['phone'])) { $e[] = 'Phone number looks invalid.'; }
    if ($d['gstin'] !== '' && setting_clean('gstin', 15, $d['gstin'])[1]) { $e[] = 'GSTIN is not valid.'; }
    return $e;
}

function sup_list(): void
{
    $u = require_staff('purchasing.manage');
    $rows = all("SELECT s.*, (SELECT COUNT(*) FROM is_purchase_orders p WHERE p.supplier_id=s.id) pos, (SELECT COALESCE(SUM(total),0) FROM is_purchase_orders p WHERE p.supplier_id=s.id AND p.status='received') spent FROM is_suppliers s ORDER BY s.active DESC, s.name");
    render('staff/suppliers', ['title' => 'Suppliers', 'nav' => 'purchasing', 'rows' => $rows, 'u' => $u], 'staff');
}
function sup_new_form(): void
{
    $u = require_staff('purchasing.manage');
    render('staff/supplier_edit', ['title' => 'New supplier', 'nav' => 'purchasing', 's' => array_fill_keys(['name', 'contact', 'email', 'phone', 'address', 'gstin', 'notes'], '') + ['active' => 1, 'id' => 0], 'u' => $u], 'staff');
}
function sup_create(): void
{
    $u = require_staff('purchasing.manage');
    $d = sup_fields(); $errs = sup_errors($d);
    if ($errs) { render('staff/supplier_edit', ['title' => 'New supplier', 'nav' => 'purchasing', 's' => $d + ['id' => 0], 'errors' => $errs, 'u' => $u], 'staff'); return; }
    $id = insert('INSERT INTO is_suppliers (name,contact,email,phone,address,gstin,notes,active,created_at) VALUES (?,?,?,?,?,?,?,?,?)', [$d['name'], $d['contact'] ?: null, $d['email'] ?: null, $d['phone'] ?: null, $d['address'] ?: null, $d['gstin'] ?: null, $d['notes'] ?: null, 1, now()]);
    audit('supplier.create', $d['name']); flash('Supplier added.'); redirect("/staff/suppliers/$id");
}
function sup_show(string $id): void
{
    $u = require_staff('purchasing.manage');
    $s = one('SELECT * FROM is_suppliers WHERE id=?', [(int) $id]);
    if (!$s) { abort(404); }
    render('staff/supplier_edit', ['title' => $s['name'], 'nav' => 'purchasing', 's' => $s, 'pos' => all('SELECT * FROM is_purchase_orders WHERE supplier_id=? ORDER BY id DESC LIMIT 100', [$s['id']]), 'u' => $u], 'staff');
}
function sup_update(string $id): void
{
    $u = require_staff('purchasing.manage');
    $s = one('SELECT * FROM is_suppliers WHERE id=?', [(int) $id]);
    if (!$s) { abort(404); }
    $d = sup_fields(); $errs = sup_errors($d);
    if ($errs) { flash(implode(' ', $errs), 'bad'); redirect("/staff/suppliers/{$s['id']}"); }
    q('UPDATE is_suppliers SET name=?,contact=?,email=?,phone=?,address=?,gstin=?,notes=?,active=? WHERE id=?', [$d['name'], $d['contact'] ?: null, $d['email'] ?: null, $d['phone'] ?: null, $d['address'] ?: null, $d['gstin'] ?: null, $d['notes'] ?: null, $d['active'], $s['id']]);
    audit('supplier.update', $d['name']); flash('Supplier saved.'); redirect("/staff/suppliers/{$s['id']}");
}

function po_list(): void
{
    $u = require_staff('purchasing.manage');
    $st = (string) ($_GET['status'] ?? '');
    $rows = all('SELECT p.*, s.name sname FROM is_purchase_orders p JOIN is_suppliers s ON s.id=p.supplier_id' . (isset(PO_STATUSES[$st]) ? ' WHERE p.status=?' : '') . ' ORDER BY p.id DESC LIMIT 300', isset(PO_STATUSES[$st]) ? [$st] : []);
    render('staff/purchase_orders', ['title' => 'Purchase orders', 'nav' => 'purchasing', 'rows' => $rows, 'st' => $st, 'u' => $u], 'staff');
}
function po_new_form(): void
{
    $u = require_staff('purchasing.manage');
    render('staff/po_new', ['title' => 'New purchase order', 'nav' => 'purchasing', 'suppliers' => all('SELECT id,name FROM is_suppliers WHERE active=1 ORDER BY name'),
        'items' => all('SELECT id,name,unit,unit_cost FROM is_inventory_items WHERE active=1 ORDER BY name'), 'sel' => (int) ($_GET['supplier'] ?? 0), 'u' => $u], 'staff');
}
function po_create(): void
{
    $u = require_staff('purchasing.manage');
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    if (!one('SELECT id FROM is_suppliers WHERE id=? AND active=1', [$sid])) { flash('Choose a supplier.', 'bad'); redirect('/staff/purchase-orders/new'); }
    $date = (string) ($_POST['order_date'] ?? ''); $exp = (string) ($_POST['expected_date'] ?? '');
    if (!valid_date($date) || ($exp !== '' && !valid_date($exp))) { flash('Check the dates.', 'bad'); redirect('/staff/purchase-orders/new?supplier=' . $sid); }
    $lines = []; $total = 0.0; $errors = [];
    foreach (($_POST['desc'] ?? []) as $i => $d) {
        $itemId = (int) ($_POST['item'][$i] ?? 0) ?: null; $d = mb_substr(trim((string) $d), 0, 255);
        $qty = (string) ($_POST['qty'][$i] ?? ''); $cost = (string) ($_POST['cost'][$i] ?? '');
        if ($d === '' && !$itemId) { continue; }
        $item = $itemId ? one('SELECT * FROM is_inventory_items WHERE id=?', [$itemId]) : null;
        if ($itemId && !$item) { $errors[] = 'Unknown inventory item on line ' . ($i + 1) . '.'; continue; }
        if ($d === '') { $d = $item['name']; }
        if (!is_numeric($qty) || $qty <= 0 || $qty > 9999999 || !is_numeric($cost) || $cost < 0 || $cost > 99999999) { $errors[] = 'Line ' . ($i + 1) . ': quantity and unit cost must be numbers.'; continue; }
        $amt = round((float) $qty * (float) $cost, 2); $total += $amt;
        $lines[] = [$itemId, $d, round((float) $qty, 2), round((float) $cost, 2), $amt];
    }
    if (!$lines && !$errors) { $errors[] = 'Add at least one line.'; }
    if ($errors) { flash(implode(' ', $errors), 'bad'); redirect('/staff/purchase-orders/new?supplier=' . $sid); }
    [$id, $number] = po_insert($sid, $date, $exp ?: null, mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 1000) ?: null, $lines, $u);
    audit('po.create', "$number " . round($total, 2)); flash("Purchase order $number created."); redirect("/staff/purchase-orders/$id");
}
/** Creates a purchase order with its lines. $lines: [[item_id|null, description, qty, unit_cost, amount], ...]. Returns [id, number]. */
function po_insert(int $sid, string $date, ?string $exp, ?string $notes, array $lines, array $u): array
{
    $total = round(array_sum(array_column($lines, 4)), 2);
    $pdo = db(); $pdo->beginTransaction();
    try {
        $number = next_number('po_next', (string) setting('po_prefix', 'PO-'), (int) $u['id']);
        $id = insert('INSERT INTO is_purchase_orders (number,supplier_id,status,order_date,expected_date,total,notes,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?)', [$number, $sid, 'ordered', $date, $exp, $total, $notes, $u['id'], now()]);
        foreach ($lines as $l) { q('INSERT INTO is_po_lines (po_id,item_id,description,qty,unit_cost,amount) VALUES (?,?,?,?,?,?)', array_merge([$id], $l)); }
        $pdo->commit();
    } catch (Throwable $ex) { $pdo->rollBack(); throw $ex; }
    settings_all(true);
    return [$id, $number];
}

function po_show(string $id): void
{
    $u = require_staff('purchasing.manage');
    $p = one('SELECT p.*, s.name sname, s.email semail, s.phone sphone, s.address saddress, s.gstin sgstin FROM is_purchase_orders p JOIN is_suppliers s ON s.id=p.supplier_id WHERE p.id=?', [(int) $id]);
    if (!$p) { abort(404); }
    render('staff/po_show', ['title' => 'PO ' . $p['number'], 'nav' => 'purchasing', 'p' => $p, 'lines' => all('SELECT l.*, i.name iname, i.unit FROM is_po_lines l LEFT JOIN is_inventory_items i ON i.id=l.item_id WHERE l.po_id=? ORDER BY l.id', [$p['id']]), 'u' => $u], 'staff');
}
function po_update(string $id): void
{
    $u = require_staff('purchasing.manage');
    $p = one('SELECT * FROM is_purchase_orders WHERE id=?', [(int) $id]);
    if (!$p) { abort(404); }
    $back = '/staff/purchase-orders/' . $p['id']; $action = (string) ($_POST['action'] ?? '');
    if ($action === 'email') { po_email($p, $u, $back); }
    if ($p['status'] !== 'ordered') { flash('This purchase order is already closed.', 'bad'); redirect($back); }
    if ($action === 'cancel') {
        q("UPDATE is_purchase_orders SET status='cancelled' WHERE id=?", [$p['id']]); audit('po.cancel', $p['number']); flash('Purchase order cancelled.'); redirect($back);
    }
    if ($action !== 'receive') { abort(400); }
    $pdo = db(); $pdo->beginTransaction();
    try {
        $locked = one('SELECT status FROM is_purchase_orders WHERE id=? FOR UPDATE', [$p['id']]);
        if ($locked['status'] !== 'ordered') { throw new RuntimeException('closed'); }
        foreach (all('SELECT * FROM is_po_lines WHERE po_id=?', [$p['id']]) as $l) {
            if (!$l['item_id']) { continue; }
            $i = one('SELECT * FROM is_inventory_items WHERE id=? FOR UPDATE', [$l['item_id']]);
            if (!$i) { continue; }
            $new = (float) $i['qty'] + (float) $l['qty'];
            q('UPDATE is_inventory_items SET qty=?, unit_cost=?, updated_at=? WHERE id=?', [$new, $l['unit_cost'], now(), $i['id']]);
            q("INSERT INTO is_stock_moves (item_id,user_id,delta,qty_after,reason,request_id,note,created_at) VALUES (?,?,?,?,'purchase',NULL,?,?)", [$i['id'], $u['id'], $l['qty'], $new, 'Received ' . $p['number'], now()]);
        }
        q("UPDATE is_purchase_orders SET status='received', received_at=? WHERE id=?", [now(), $p['id']]);
        $pdo->commit();
    } catch (Throwable $ex) { $pdo->rollBack(); flash('Could not receive this purchase order.', 'bad'); redirect($back); }
    audit('po.receive', $p['number']);
    notify('po.received', "Purchase order {$p['number']} received", "{$p['number']} was marked received and stock was updated.\n" . app_url('/staff/purchase-orders/' . $p['id'])); flash('Received. Stock quantities and unit costs of linked items were updated.'); redirect($back);
}

function po_pdf_build(array $p): Pdf
{
    $lines = all('SELECT l.*, i.unit FROM is_po_lines l LEFT JOIN is_inventory_items i ON i.id=l.item_id WHERE l.po_id=? ORDER BY l.id', [$p['id']]);
    $sup = one('SELECT * FROM is_suppliers WHERE id=?', [$p['supplier_id']]);
    $d = new Pdf('Purchase order ' . $p['number']);
    doc_head($d, 'PURCHASE ORDER', ['PO no.' => $p['number'], 'Date' => fmt_date($p['order_date']), 'Expected' => $p['expected_date'] ? fmt_date($p['expected_date']) : '-']);
    party($d, 'Supplier', [$sup['name'], $sup['address'], trim(($sup['phone'] ?? '') . ' ' . ($sup['email'] ?? '')), $sup['gstin'] ? 'GSTIN ' . $sup['gstin'] : '']);
    $d->gap(6);
    $d->table([['#', 26, 'L'], ['Description', 270, 'L'], ['Qty', 60, 'R'], ['Unit cost', 80, 'R'], ['Amount', 70, 'R']], array_map(fn($l, $n) => [(string) ($n + 1), $l['description'], rtrim(rtrim(number_format((float) $l['qty'], 2, '.', ''), '0'), '.') . ($l['unit'] ? ' ' . $l['unit'] : ''), money($l['unit_cost']), money($l['amount'])], $lines, array_keys($lines)));
    $d->gap(8); $d->y -= 14; $d->put('Total (excluding GST)', Pdf::W - Pdf::M - 150, 10, true); $d->put(money($p['total']), Pdf::W - Pdf::M, 10, true, 'R');
    if ($p['notes']) { $d->gap(14); $d->para('Notes', 9, true); $d->para((string) $p['notes']); }
    $d->gap(10); $d->para('Please quote this PO number on your delivery note and invoice.', 9);
    return $d;
}
function po_pdf(string $id): void
{
    require_staff('purchasing.manage');
    $p = one('SELECT * FROM is_purchase_orders WHERE id=?', [(int) $id]); if (!$p) { abort(404); }
    audit('po.pdf', $p['number']); po_pdf_build($p)->download($p['number'] . '.pdf');
}
function po_email(array $p, array $u, string $back): void
{
    $sup = one('SELECT * FROM is_suppliers WHERE id=?', [$p['supplier_id']]);
    if (!$sup || !filter_var((string) $sup['email'], FILTER_VALIDATE_EMAIL)) { flash('This supplier has no valid e-mail address. Add it on the supplier page first.', 'bad'); redirect($back); }
    $err = null; $co = (string) setting('legal_name', setting('app_name', cfg('app_name')));
    $ok = send_mail($sup['email'], "Purchase order {$p['number']} from $co", "Dear {$sup['name']},\n\nPlease find our purchase order {$p['number']} attached" . ($p['expected_date'] ? ', required by ' . fmt_date($p['expected_date']) : '') . ".\nPlease confirm receipt and quote the PO number on your invoice.\n\nThank you,\n$co", $err, ['name' => $p['number'] . '.pdf', 'type' => 'application/pdf', 'data' => po_pdf_build($p)->output()]);
    audit('po.email', $p['number'] . ' -> ' . $sup['email']);
    flash($ok ? "Purchase order e-mailed to {$sup['email']}." : 'Could not send: ' . ($err ?: 'unknown error'), $ok ? 'ok' : 'bad');
    redirect($back);
}

/* ---------- reorder suggestions ---------- */
function reorder_page(): void
{
    $u = require_staff('purchasing.manage');
    $rows = all("SELECT i.*, (SELECT s.id FROM is_suppliers s WHERE s.active=1 AND s.name=i.supplier LIMIT 1) sid FROM is_inventory_items i WHERE i.active=1 AND i.reorder_level > 0 AND i.qty <= i.reorder_level ORDER BY i.supplier, i.name");
    $open = []; foreach (all("SELECT l.item_id, SUM(l.qty) q FROM is_po_lines l JOIN is_purchase_orders p ON p.id=l.po_id WHERE p.status='ordered' AND l.item_id IS NOT NULL GROUP BY l.item_id") as $o) { $open[(int) $o['item_id']] = (float) $o['q']; }
    render('staff/reorder', ['title' => 'Reorder suggestions', 'nav' => 'purchasing', 'rows' => $rows, 'open' => $open, 'suppliers' => all('SELECT id,name FROM is_suppliers WHERE active=1 ORDER BY name'), 'u' => $u], 'staff');
}
function reorder_create(): void
{
    $u = require_staff('purchasing.manage');
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    if (!one('SELECT id FROM is_suppliers WHERE id=? AND active=1', [$sid])) { flash('Choose a supplier.', 'bad'); redirect('/staff/reorder'); }
    $lines = [];
    foreach ((array) ($_POST['qty'] ?? []) as $iid => $qty) {
        if (empty($_POST['pick'][$iid]) || !is_numeric($qty) || $qty <= 0 || $qty > 9999999) { continue; }
        $i = one('SELECT * FROM is_inventory_items WHERE id=? AND active=1', [(int) $iid]); if (!$i) { continue; }
        $cost = (float) ($i['unit_cost'] ?? 0); $lines[] = [(int) $i['id'], $i['name'], round((float) $qty, 2), $cost, round((float) $qty * $cost, 2)];
    }
    if (!$lines) { flash('Tick at least one item and give a quantity.', 'bad'); redirect('/staff/reorder'); }
    [$id, $number] = po_insert($sid, date('Y-m-d'), null, 'Created from reorder suggestions', $lines, $u);
    audit('po.create', "$number from reorder"); flash("Purchase order $number created with " . count($lines) . ' line(s). Check the unit costs.'); redirect("/staff/purchase-orders/$id");
}

/* ---------- supplier bills and payments ---------- */
const BILL_METHODS = ['Bank transfer' => 'Bank transfer', 'UPI' => 'UPI', 'Cash' => 'Cash', 'Cheque' => 'Cheque', 'Card' => 'Card'];
function bill_paid(int $id): float { return (float) one('SELECT COALESCE(SUM(amount),0) s FROM is_bill_payments WHERE bill_id=?', [$id])['s']; }
function bills_page(): void
{
    $u = require_staff('purchasing.manage');
    $st = (string) ($_GET['status'] ?? 'open');
    $rows = all("SELECT b.*, s.name sname, COALESCE((SELECT SUM(amount) FROM is_bill_payments p WHERE p.bill_id=b.id),0) paid FROM is_supplier_bills b JOIN is_suppliers s ON s.id=b.supplier_id WHERE b.status<>'void'" . ($st === 'open' ? " AND b.status='open'" : ($st === 'paid' ? " AND b.status='paid'" : '')) . ' ORDER BY b.due_date, b.id DESC LIMIT 300');
    $owed = (float) one("SELECT COALESCE(SUM(b.amount),0) - COALESCE((SELECT SUM(p.amount) FROM is_bill_payments p JOIN is_supplier_bills x ON x.id=p.bill_id WHERE x.status='open'),0) o FROM is_supplier_bills b WHERE b.status='open'")['o'];
    render('staff/bills', ['title' => 'Supplier bills', 'nav' => 'purchasing', 'rows' => $rows, 'st' => $st, 'owed' => $owed, 'u' => $u], 'staff');
}
function bill_new_form(): void
{
    $u = require_staff('purchasing.manage');
    render('staff/bill_new', ['title' => 'New supplier bill', 'nav' => 'purchasing', 'suppliers' => all('SELECT id,name FROM is_suppliers WHERE active=1 ORDER BY name'), 'pos' => all("SELECT p.id,p.number,s.name sname FROM is_purchase_orders p JOIN is_suppliers s ON s.id=p.supplier_id WHERE p.status<>'cancelled' ORDER BY p.id DESC LIMIT 100"), 'sel' => (int) ($_GET['supplier'] ?? 0), 'u' => $u], 'staff');
}
function bill_create(): void
{
    $u = require_staff('purchasing.manage');
    $sid = (int) ($_POST['supplier_id'] ?? 0); $no = mb_substr(trim((string) ($_POST['bill_no'] ?? '')), 0, 60); $bd = (string) ($_POST['bill_date'] ?? ''); $dd = (string) ($_POST['due_date'] ?? '');
    $amt = trim((string) ($_POST['amount'] ?? '')); $gst = trim((string) ($_POST['gst_amount'] ?? '')) ?: '0'; $po = (int) ($_POST['po_id'] ?? 0) ?: null;
    $err = [];
    if (!one('SELECT id FROM is_suppliers WHERE id=?', [$sid])) { $err[] = 'Choose a supplier.'; }
    if ($no === '') { $err[] = 'Enter the supplier\'s bill number.'; }
    if (!valid_date($bd) || !valid_date($dd)) { $err[] = 'Check the dates.'; }
    if (!is_numeric($amt) || $amt <= 0 || $amt > 99999999 || !is_numeric($gst) || $gst < 0 || $gst > $amt) { $err[] = 'Amounts must be numbers (GST cannot exceed the bill total).'; }
    if ($po && !one('SELECT id FROM is_purchase_orders WHERE id=?', [$po])) { $err[] = 'Unknown purchase order.'; }
    if (!$err && one('SELECT id FROM is_supplier_bills WHERE supplier_id=? AND bill_no=? AND status<>\'void\'', [$sid, $no])) { $err[] = 'This supplier bill number is already recorded.'; }
    if ($err) { flash(implode(' ', $err), 'bad'); redirect('/staff/bills/new?supplier=' . $sid); }
    $id = insert('INSERT INTO is_supplier_bills (supplier_id,po_id,bill_no,bill_date,due_date,amount,gst_amount,status,notes,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$sid, $po, $no, $bd, $dd, $amt, $gst, 'open', mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 500) ?: null, $u['id'], now()]);
    audit('bill.create', "$no $amt"); flash('Bill recorded.'); redirect("/staff/bills/$id");
}
function bill_show(string $id): void
{
    $u = require_staff('purchasing.manage');
    $b = one('SELECT b.*, s.name sname, p.number ponumber FROM is_supplier_bills b JOIN is_suppliers s ON s.id=b.supplier_id LEFT JOIN is_purchase_orders p ON p.id=b.po_id WHERE b.id=?', [(int) $id]); if (!$b) { abort(404); }
    render('staff/bill_show', ['title' => 'Bill ' . $b['bill_no'], 'nav' => 'purchasing', 'b' => $b, 'pays' => all('SELECT p.*, u.name uname FROM is_bill_payments p LEFT JOIN is_users u ON u.id=p.by_user WHERE p.bill_id=? ORDER BY p.id', [$b['id']]), 'u' => $u], 'staff');
}
function bill_update(string $id): void
{
    $u = require_staff('purchasing.manage');
    $b = one('SELECT * FROM is_supplier_bills WHERE id=?', [(int) $id]); if (!$b) { abort(404); }
    $back = ($_POST['back'] ?? '') === 'bank' ? '/staff/bank' : '/staff/bills/' . $b['id']; $a = (string) ($_POST['action'] ?? '');
    if ($a === 'pay' && $b['status'] === 'open') {
        $amt = trim((string) ($_POST['amount'] ?? '')); $d = (string) ($_POST['paid_on'] ?? ''); $m = (string) ($_POST['method'] ?? '');
        $left = round((float) $b['amount'] - bill_paid((int) $b['id']), 2);
        if (!is_numeric($amt) || $amt <= 0 || round((float) $amt, 2) > $left || !valid_date($d) || !isset(BILL_METHODS[$m])) { flash("Enter a payment of at most " . money($left) . ', with a date and method.', 'bad'); redirect($back); }
        q('INSERT INTO is_bill_payments (bill_id,amount,paid_on,method,ref,by_user,at) VALUES (?,?,?,?,?,?,?)', [$b['id'], $amt, $d, $m, mb_substr(trim((string) ($_POST['ref'] ?? '')), 0, 60) ?: null, $u['id'], now()]);
        if (round($left - (float) $amt, 2) <= 0) { q("UPDATE is_supplier_bills SET status='paid' WHERE id=?", [$b['id']]); }
        audit('bill.pay', $b['bill_no'] . ' ' . $amt); flash('Payment recorded.');
    } elseif ($a === 'void' && $b['status'] === 'open' && bill_paid((int) $b['id']) == 0) {
        q("UPDATE is_supplier_bills SET status='void' WHERE id=?", [$b['id']]); audit('bill.void', $b['bill_no']); flash('Bill voided.'); redirect('/staff/bills');
    } else { flash('That action is not available.', 'bad'); }
    redirect($back);
}
