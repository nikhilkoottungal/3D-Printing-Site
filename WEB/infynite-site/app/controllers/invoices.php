<?php
/** Invoices. Permission 'invoices.manage' (Owner via '*', Sales). Seller details, GST rate, numbering and bank come from /staff/admin settings. */
const INVOICE_STATUSES = ['issued' => 'Issued', 'paid' => 'Paid', 'void' => 'Void'];
const PAY_METHODS = ['UPI' => 'UPI', 'Bank transfer' => 'Bank transfer', 'Cash' => 'Cash', 'Cheque' => 'Cheque', 'Card' => 'Card'];

function invoice_ensure_tables(): void
{
    static $done = false;
    if ($done) { return; }
    foreach (preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', file_get_contents(APP_ROOT . '/sql/schema.sql'))) as $sql) {
        if (stripos($sql, 'is_invoice') !== false && stripos($sql, 'CREATE TABLE') !== false) { q($sql); }
    }
    $done = true;
}

function invoice_seller(): array
{
    return ['name' => setting('legal_name', setting('app_name', cfg('app_name'))), 'address' => setting('address', ''), 'phone' => setting('phone', ''),
        'email' => setting('email', ''), 'gstin' => setting('gstin', ''), 'pan' => setting('pan', ''), 'state' => setting('state', ''),
        'bank' => setting('bank_details', ''), 'footer' => setting('invoice_footer', '')];
}

/** Computes totals from lines [[desc,qty,rate]], returns [lines, subtotal, cgst, sgst, igst, total]. All money rounded to 2 dp. */
function invoice_totals(array $lines, float $rate, bool $inclusive, string $kind): array
{
    $sum = 0.0; $out = [];
    foreach ($lines as $l) { $amt = round($l['qty'] * $l['rate'], 2); $sum += $amt; $out[] = $l + ['amount' => $amt]; }
    $sum = round($sum, 2);
    if ($inclusive) { $taxable = round($sum * 100 / (100 + $rate), 2); $gst = round($sum - $taxable, 2); $total = $sum; }
    else { $taxable = $sum; $gst = round($sum * $rate / 100, 2); $total = round($sum + $gst, 2); }
    if ($kind === 'igst') { $c = 0.0; $s = 0.0; $i = $gst; } else { $c = round($gst / 2, 2); $s = round($gst - $c, 2); $i = 0.0; }
    return [$out, $taxable, $c, $s, $i, $total];
}

function invoice_list(): void
{
    $u = require_staff('invoices.manage'); invoice_ensure_tables();
    $st = (string) ($_GET['status'] ?? '');
    $where = isset(INVOICE_STATUSES[$st]) ? 'WHERE i.status=?' : 'WHERE 1';
    $rows = all("SELECT i.*, r.ref, COALESCE((SELECT SUM(amount) FROM is_invoice_payments p WHERE p.invoice_id=i.id),0) paid, COALESCE((SELECT SUM(total) FROM is_credit_notes c WHERE c.invoice_id=i.id),0) credited FROM is_invoices i LEFT JOIN is_requests r ON r.id=i.request_id $where ORDER BY i.id DESC LIMIT 300", $where === 'WHERE 1' ? [] : [$st]);
    $due = one("SELECT COUNT(*) c, COALESCE(SUM(t.bal),0) s FROM (SELECT i.total - COALESCE((SELECT SUM(amount) FROM is_invoice_payments p WHERE p.invoice_id=i.id),0) - COALESCE((SELECT SUM(total) FROM is_credit_notes c WHERE c.invoice_id=i.id),0) bal FROM is_invoices i WHERE i.status='issued' AND i.kind='tax') t WHERE t.bal > 0.005");
    render('staff/invoices', ['title' => 'Invoices', 'nav' => 'invoices', 'rows' => $rows, 'st' => $st, 'due' => $due, 'u' => $u], 'staff');
}

function invoice_new_form(): void
{
    $u = require_staff('invoices.manage'); invoice_ensure_tables();
    $r = null; $old = ['lines' => [], 'tax_inclusive' => '', 'notes' => '', 'bill_name' => '', 'bill_address' => '', 'bill_email' => '', 'bill_phone' => '', 'bill_gstin' => '', 'bill_state' => '', 'customer_id' => 0];
    $rid = (int) ($_GET['request'] ?? 0); $cid = (int) ($_GET['customer'] ?? 0);
    $fill = function (array $c) use (&$old) {
        $old['customer_id'] = (int) $c['id']; $old['bill_name'] = $c['name']; $old['bill_email'] = str_ends_with($c['email'], '@walkin.invalid') ? '' : $c['email'];
        $old['bill_phone'] = (string) $c['phone']; $old['bill_address'] = (string) $c['address']; $old['bill_gstin'] = (string) $c['gstin']; $old['bill_state'] = (string) $c['state'];
    };
    if ($cid && !$rid) { $c = one("SELECT * FROM is_users WHERE id=? AND role='customer'", [$cid]); if (!$c) { abort(404); } $fill($c); }
    if ($rid) {
        $r = one('SELECT r.*, u.name cname, u.email cemail, u.phone cphone FROM is_requests r JOIN is_users u ON u.id=r.user_id WHERE r.id=?', [$rid]);
        if (!$r) { abort(404); }
        $qty = max(1, (int) $r['quantity']);
        $fill(one('SELECT * FROM is_users WHERE id=?', [$r['user_id']]));
        if ($r['quote_amount'] !== null) { $old['lines'][] = ['description' => "3D printing {$r['ref']} - {$r['material']}, {$r['color']}", 'qty' => (string) $qty, 'rate' => number_format((float) $r['quote_amount'] / $qty, 2, '.', '')]; }
    }
    render('staff/invoice_new', ['title' => 'New invoice', 'nav' => 'invoices', 'r' => $r, 'old' => $old, 'rate' => (string) setting('gst_rate', '18'), 'u' => $u, 'ready' => setting('legal_name') !== null], 'staff');
}

function invoice_create(): void
{
    $u = require_staff('invoices.manage'); invoice_ensure_tables();
    if (setting('legal_name') === null) { flash('Set the legal name and tax details in Admin settings first.', 'bad'); redirect('/staff/invoices/new'); }
    $rid = (int) ($_POST['request_id'] ?? 0) ?: null;
    $r = $rid ? one('SELECT * FROM is_requests WHERE id=?', [$rid]) : null;
    if ($rid && !$r) { abort(404); }
    $custId = $r['user_id'] ?? ((int) ($_POST['customer_id'] ?? 0) ?: null);
    if ($custId && !one("SELECT id FROM is_users WHERE id=? AND role='customer'", [$custId])) { $custId = null; }
    $f = fn($k, $max = 190) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $errors = [];
    $name = $f('bill_name', 160); if ($name === '') { $errors[] = 'Customer name is required.'; }
    $gstin = strtoupper($f('bill_gstin', 15)); if ($gstin !== '' && setting_clean('gstin', 15, $gstin)[1]) { $errors[] = 'Customer GSTIN is not valid.'; }
    $email = $f('bill_email'); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Customer email is not valid.'; }
    $rate = trim((string) ($_POST['gst_rate'] ?? '')); if (!is_numeric($rate) || $rate < 0 || $rate > 100) { $errors[] = 'GST rate must be 0-100.'; }
    $issue = $f('issue_date'); if (!valid_date($issue)) { $errors[] = 'Invalid issue date.'; }
    $lines = [];
    foreach (($_POST['desc'] ?? []) as $i => $d) {
        $d = mb_substr(trim((string) $d), 0, 255); if ($d === '') { continue; }
        $qty = (string) ($_POST['qty'][$i] ?? ''); $rt = (string) ($_POST['rate'][$i] ?? '');
        if (!is_numeric($qty) || $qty <= 0 || $qty > 999999 || !is_numeric($rt) || $rt < 0 || $rt > 99999999) { $errors[] = "Line " . ($i + 1) . ": quantity and rate must be numbers."; continue; }
        $lines[] = ['description' => $d, 'hsn' => mb_substr(trim((string) ($_POST['hsn'][$i] ?? '')), 0, 10), 'qty' => round((float) $qty, 2), 'rate' => round((float) $rt, 2)];
    }
    if (!$lines) { $errors[] = 'Add at least one line.'; }
    if ($errors) { flash(implode(' ', $errors), 'bad'); redirect('/staff/invoices/new' . ($rid ? "?request=$rid" : ($custId ? "?customer=$custId" : ''))); }

    $seller = invoice_seller();
    $bstate = $f('bill_state', 60);
    $kind = ($bstate !== '' && $seller['state'] !== '' && strcasecmp($bstate, $seller['state']) !== 0) ? 'igst' : 'cgst_sgst';
    $incl = !empty($_POST['tax_inclusive']); $doc = ($_POST['doc_kind'] ?? '') === 'proforma' ? 'proforma' : 'tax';
    [$ln, $sub, $c, $s, $ig, $total] = invoice_totals($lines, (float) $rate, $incl, $kind);
    $terms = (int) setting('payment_terms', 15);
    $due = date('Y-m-d', strtotime("$issue +$terms days"));

    [$id, $number] = invoice_insert(['doc' => $doc, 'rid' => $rid, 'cust' => $custId, 'name' => $name, 'address' => $f('bill_address', 400) ?: null, 'email' => $email ?: null, 'phone' => $f('bill_phone', 30) ?: null,
        'gstin' => $gstin ?: null, 'state' => $bstate ?: null, 'seller' => $seller, 'issue' => $issue, 'due' => $due, 'incl' => $incl, 'rate' => (float) $rate, 'kind' => $kind, 'sub' => $sub, 'c' => $c, 's' => $s, 'ig' => $ig, 'total' => $total, 'notes' => $f('notes', 1000) ?: null], $ln, $u);
    audit($doc === 'proforma' ? 'invoice.proforma' : 'invoice.create', "$number total $total");
    flash(($doc === 'proforma' ? "Pro-forma invoice $number" : "Invoice $number") . ' issued.');
    redirect("/staff/invoices/$id");
}

/** Saves an invoice (or pro-forma) with its lines under the next number of its series. Returns [id, number]. */
function invoice_insert(array $h, array $ln, array $u): array
{
    $pdo = db(); $pdo->beginTransaction();
    try {
        $number = $h['doc'] === 'proforma' ? next_number('proforma_next', (string) setting('proforma_prefix', 'PI-'), (int) $u['id']) : next_number('invoice_next', (string) setting('invoice_prefix', 'INV-'), (int) $u['id']);
        $id = insert('INSERT INTO is_invoices (number,kind,request_id,customer_id,bill_name,bill_address,bill_email,bill_phone,bill_gstin,bill_state,seller,issue_date,due_date,tax_inclusive,gst_rate,tax_kind,subtotal,cgst,sgst,igst,total,status,notes,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$number, $h['doc'], $h['rid'], $h['cust'], $h['name'], $h['address'], $h['email'], $h['phone'], $h['gstin'], $h['state'], json_encode($h['seller'], JSON_UNESCAPED_UNICODE), $h['issue'], $h['due'], $h['incl'] ? 1 : 0, $h['rate'], $h['kind'], $h['sub'], $h['c'], $h['s'], $h['ig'], $h['total'], 'issued', $h['notes'], $u['id'], now()]);
        foreach ($ln as $l) { q('INSERT INTO is_invoice_lines (invoice_id,description,hsn,qty,rate,amount) VALUES (?,?,?,?,?,?)', [$id, $l['description'], $l['hsn'] ?: null, $l['qty'], $l['rate'], $l['amount']]); }
        $pdo->commit();
    } catch (Throwable $ex) { $pdo->rollBack(); throw $ex; }
    settings_all(true);
    return [$id, $number];
}

function inv_paid(int $id): float { return (float) one('SELECT COALESCE(SUM(amount),0) s FROM is_invoice_payments WHERE invoice_id=?', [$id])['s']; }
function inv_credited(int $id): float { return (float) one('SELECT COALESCE(SUM(total),0) s FROM is_credit_notes WHERE invoice_id=?', [$id])['s']; }
function inv_balance(array $i): float { return round((float) $i['total'] - inv_paid((int) $i['id']) - inv_credited((int) $i['id']), 2); }
/** Marks a tax invoice paid once nothing is owing any more. */
function inv_settle(array $i, ?string $method = null): void
{
    if ($i['kind'] !== 'tax' || $i['status'] !== 'issued' || inv_balance($i) > 0.005) { return; }
    $last = one('SELECT paid_on, method, ref FROM is_invoice_payments WHERE invoice_id=? ORDER BY paid_on DESC, id DESC LIMIT 1', [$i['id']]);
    q("UPDATE is_invoices SET status='paid', paid_at=?, paid_method=?, paid_ref=? WHERE id=?", [$last['paid_on'] ?? date('Y-m-d'), $last['method'] ?? $method ?? 'Credit note', $last['ref'] ?? null, $i['id']]);
}

function invoice_show(string $id): void
{
    $u = require_staff('invoices.manage'); invoice_ensure_tables();
    $i = one('SELECT i.*, r.ref FROM is_invoices i LEFT JOIN is_requests r ON r.id=i.request_id WHERE i.id=?', [(int) $id]);
    if (!$i) { abort(404); }
    render('staff/invoice_show', ['title' => 'Invoice ' . $i['number'], 'nav' => 'invoices', 'i' => $i, 's' => json_decode($i['seller'], true) ?: [], 'lines' => all('SELECT * FROM is_invoice_lines WHERE invoice_id=? ORDER BY id', [$i['id']]), 'pays' => all('SELECT p.*, u.name uname FROM is_invoice_payments p LEFT JOIN is_users u ON u.id=p.by_user WHERE p.invoice_id=? ORDER BY p.paid_on, p.id', [$i['id']]), 'credits' => all('SELECT * FROM is_credit_notes WHERE invoice_id=? ORDER BY id', [$i['id']]), 'balance' => inv_balance($i), 'u' => $u], 'staff');
}

function invoice_update(string $id): void
{
    $u = require_staff('invoices.manage'); invoice_ensure_tables();
    $i = one('SELECT * FROM is_invoices WHERE id=?', [(int) $id]);
    if (!$i) { abort(404); }
    $back = ($_POST['back'] ?? '') === 'bank' ? '/staff/bank' : '/staff/invoices/' . $i['id'];
    $action = (string) ($_POST['action'] ?? '');
    $paid = inv_paid((int) $i['id']);
    if ($action === 'pay' && $i['status'] === 'issued' && $i['kind'] === 'tax') {
        $d = (string) ($_POST['paid_at'] ?? ''); $m = (string) ($_POST['method'] ?? ''); $left = inv_balance($i);
        $amt = trim((string) ($_POST['amount'] ?? '')); if ($amt === '') { $amt = number_format($left, 2, '.', ''); }
        if (!valid_date($d) || !isset(PAY_METHODS[$m]) || !is_numeric($amt) || $amt <= 0 || round((float) $amt, 2) > $left + 0.005) { flash('Enter a payment of at most ' . money($left) . ', with a date and method.', 'bad'); redirect($back); }
        q('INSERT INTO is_invoice_payments (invoice_id,amount,paid_on,method,ref,by_user,at) VALUES (?,?,?,?,?,?,?)', [$i['id'], round((float) $amt, 2), $d, $m, mb_substr(trim((string) ($_POST['ref'] ?? '')), 0, 60) ?: null, $u['id'], now()]);
        inv_settle($i); $now = one('SELECT status FROM is_invoices WHERE id=?', [$i['id']]);
        audit('invoice.payment', $i['number'] . ' ' . $amt);
        flash($now['status'] === 'paid' ? 'Payment recorded: the invoice is fully paid.' : 'Part payment recorded. Balance ' . money(inv_balance($i)) . '.');
        notify('invoice.paid', "Payment on invoice {$i['number']}", "{$i['bill_name']} paid " . money($amt) . " on $d by $m for invoice {$i['number']} (" . ($now['status'] === 'paid' ? 'fully paid' : 'balance ' . money(inv_balance($i))) . ").\n" . app_url('/staff/invoices/' . $i['id']));
    } elseif ($action === 'void' && $i['status'] === 'issued' && $paid == 0) {
        q("UPDATE is_invoices SET status='void' WHERE id=?", [$i['id']]);
        audit('invoice.void', $i['number']); flash('Voided. Its number stays used; issue a new one if needed.');
    } elseif ($action === 'void' && ($i['status'] === 'paid' || $paid > 0) && can('team.manage', $u)) {
        q("UPDATE is_invoices SET status='void' WHERE id=?", [$i['id']]);
        audit('invoice.void_paid', $i['number']); flash('Invoice with payments voided (owner).');
    } elseif ($action === 'convert' && $i['kind'] === 'proforma' && $i['status'] === 'issued') {
        $lines = all('SELECT description, hsn, qty, rate, amount FROM is_invoice_lines WHERE invoice_id=? ORDER BY id', [$i['id']]);
        [$nid, $num] = invoice_insert(['doc' => 'tax', 'rid' => $i['request_id'], 'cust' => $i['customer_id'], 'name' => $i['bill_name'], 'address' => $i['bill_address'], 'email' => $i['bill_email'], 'phone' => $i['bill_phone'], 'gstin' => $i['bill_gstin'], 'state' => $i['bill_state'], 'seller' => invoice_seller(), 'issue' => date('Y-m-d'), 'due' => date('Y-m-d', strtotime('+' . (int) setting('payment_terms', 15) . ' days')), 'incl' => $i['tax_inclusive'], 'rate' => (float) $i['gst_rate'], 'kind' => $i['tax_kind'], 'sub' => $i['subtotal'], 'c' => $i['cgst'], 's' => $i['sgst'], 'ig' => $i['igst'], 'total' => $i['total'], 'notes' => $i['notes']], $lines, $u);
        q("UPDATE is_invoices SET status='void', notes=CONCAT(COALESCE(notes,''), ?) WHERE id=?", ["\nConverted to $num", $i['id']]);
        audit('invoice.convert', $i['number'] . ' -> ' . $num); flash("Converted to tax invoice $num."); redirect("/staff/invoices/$nid");
    } elseif ($action === 'email' || $action === 'remind') {
        invoice_mail($i, $action === 'remind', $back);
    } else { flash('That action is not available for this invoice.', 'bad'); }
    redirect($back);
}

function invoice_pdf_build(array $i): Pdf
{
    $s = json_decode($i['seller'], true) ?: []; $lines = all('SELECT * FROM is_invoice_lines WHERE invoice_id=? ORDER BY id', [$i['id']]);
    $title = $i['kind'] === 'proforma' ? 'PRO-FORMA INVOICE' : ($s['gstin'] ? 'TAX INVOICE' : 'INVOICE');
    $p = new Pdf($title . ' ' . $i['number']);
    $head = $s['name'] ?? '';
    $p->y -= 4; $p->put((string) $head, Pdf::M, 15, true); $p->put($title, Pdf::W - Pdf::M, 16, true, 'R'); $p->gap(15);
    foreach (array_filter(array_merge(preg_split('/\R/', (string) ($s['address'] ?? '')) ?: [], [trim(($s['phone'] ?? '') . ' ' . ($s['email'] ?? '')), !empty($s['gstin']) ? 'GSTIN ' . $s['gstin'] : '', !empty($s['pan']) ? 'PAN ' . $s['pan'] : ''])) as $l) { $p->para($l, 9, false, null, 300, 1.3); }
    $yy = Pdf::H - Pdf::M - 22; $save = $p->y;
    foreach (['Invoice no.' => $i['number'], 'Date' => fmt_date($i['issue_date']), 'Due' => fmt_date($i['due_date'])] as $k => $v) { $p->y = $yy; $p->put($k, Pdf::W - Pdf::M - 120, 9, true); $p->put((string) $v, Pdf::W - Pdf::M, 9, false, 'R'); $yy -= 13; }
    $p->y = min($save, $yy) - 4; $p->rule(2, 6);
    $p->para('Bill to', 9, true); foreach (array_filter([$i['bill_name'], $i['bill_address'], $i['bill_gstin'] ? 'GSTIN ' . $i['bill_gstin'] : '', $i['bill_state'] ? 'State: ' . $i['bill_state'] : '']) as $l) { $p->para((string) $l, 10, false, null, 320, 1.3); }
    $p->gap(8);
    $p->table([['#', 22, 'L'], ['Description', 232, 'L'], ['HSN/SAC', 58, 'L'], ['Qty', 40, 'R'], ['Rate', 72, 'R'], ['Amount', 75, 'R']], array_map(fn($l, $n) => [(string) ($n + 1), $l['description'], (string) $l['hsn'], rtrim(rtrim(number_format((float) $l['qty'], 2, '.', ''), '0'), '.'), money($l['rate']), money($l['amount'])], $lines, array_keys($lines)));
    if ($i['tax_inclusive']) { $p->para('Line rates include GST.', 8.5); }
    $x = Pdf::W - Pdf::M; $half = rtrim(rtrim(number_format((float) $i['gst_rate'] / ($i['tax_kind'] === 'igst' ? 1 : 2), 2, '.', ''), '0'), '.');
    $rows = [['Taxable value', money($i['subtotal'])]];
    if ($i['tax_kind'] === 'igst') { $rows[] = ["IGST @ $half%", money($i['igst'])]; } else { $rows[] = ["CGST @ $half%", money($i['cgst'])]; $rows[] = ["SGST @ $half%", money($i['sgst'])]; }
    $p->gap(6); foreach ($rows as [$k, $v]) { $p->y -= 14; $p->put($k, $x - 150, 10); $p->put($v, $x, 10, false, 'R'); }
    $p->y -= 6; $p->rule(2, 4); $p->y -= 13; $p->put('Total', $x - 150, 11, true); $p->put(money($i['total']), $x, 11, true, 'R');
    if ($i['kind'] === 'tax') { $paid = inv_paid((int) $i['id']); $cr = inv_credited((int) $i['id']); if ($paid > 0 || $cr > 0) { $p->y -= 14; $p->put('Paid / credited', $x - 150, 9); $p->put(money($paid + $cr), $x, 9, false, 'R'); $p->y -= 13; $p->put('Balance due', $x - 150, 10, true); $p->put(money(inv_balance($i)), $x, 10, true, 'R'); } }
    $p->gap(14);
    if ($i['notes']) { $p->para('Notes', 9, true); $p->para((string) $i['notes'], 9); $p->gap(4); }
    if (!empty($s['bank'])) { $p->para('Bank details', 9, true); $p->para((string) $s['bank'], 9); $p->gap(4); }
    if (!empty($s['footer'])) { $p->para((string) $s['footer'], 8.5); }
    if ($i['kind'] === 'proforma') { $p->gap(6); $p->para('This is a pro-forma invoice and not a tax invoice. A tax invoice follows on payment or delivery.', 8.5); }
    return $p;
}
function invoice_pdf(string $id): void
{
    require_staff('invoices.manage'); invoice_ensure_tables();
    $i = one('SELECT * FROM is_invoices WHERE id=?', [(int) $id]); if (!$i) { abort(404); }
    audit('invoice.pdf', $i['number']); invoice_pdf_build($i)->download($i['number'] . '.pdf');
}
function invoice_mail(array $i, bool $reminder, string $back): void
{
    if (!$i['bill_email'] || !filter_var($i['bill_email'], FILTER_VALIDATE_EMAIL)) { flash('This invoice has no customer e-mail address.', 'bad'); redirect($back); }
    if ($i['status'] === 'void' || ($reminder && ($i['status'] !== 'issued' || $i['kind'] !== 'tax'))) { flash('Nothing to send for this invoice.', 'bad'); redirect($back); }
    [$ok, $err] = invoice_send($i, $reminder);
    flash($ok ? ($reminder ? 'Reminder sent to ' : 'Invoice sent to ') . $i['bill_email'] . '.' : 'Could not send: ' . ($err ?: 'unknown error'), $ok ? 'ok' : 'bad');
    redirect($back);
}
/** Sends the invoice PDF (or a payment reminder with it) to the customer. Returns [ok, error]. */
function invoice_send(array $i, bool $reminder): array
{
    $co = (string) setting('legal_name', setting('app_name', cfg('app_name'))); $err = null; $bal = inv_balance($i);
    $subject = $reminder ? "Payment reminder: invoice {$i['number']}" : (($i['kind'] === 'proforma' ? 'Pro-forma invoice ' : 'Invoice ') . $i['number'] . " from $co");
    $body = $reminder ? "Dear {$i['bill_name']},\n\nOur records show invoice {$i['number']} (due " . fmt_date($i['due_date']) . ") still has a balance of " . money($bal) . ".\nA copy is attached. If you have already paid, please ignore this message and send us the payment reference.\n\nThank you,\n$co"
        : "Dear {$i['bill_name']},\n\nPlease find " . ($i['kind'] === 'proforma' ? 'pro-forma invoice ' : 'invoice ') . "{$i['number']} for " . money($i['total']) . " attached" . ($i['kind'] === 'tax' ? ', due ' . fmt_date($i['due_date']) : '') . ".\n\nThank you,\n$co";
    $ok = send_mail($i['bill_email'], $subject, $body, $err, ['name' => $i['number'] . '.pdf', 'type' => 'application/pdf', 'data' => invoice_pdf_build($i)->output()]);
    audit($reminder ? 'invoice.reminder' : 'invoice.email', $i['number'] . ' -> ' . $i['bill_email']);
    if ($ok && $reminder) { q('UPDATE is_invoices SET last_reminder=? WHERE id=?', [date('Y-m-d'), $i['id']]); }
    return [$ok, $err];
}
