<?php
/** Credit notes, GST summary, expenses, profit and loss, bank statement matching. */

/* ================= credit notes ================= */
function cn_list(): void
{
    $u = require_staff('invoices.manage'); invoice_ensure_tables();
    render('staff/credit_notes', ['title' => 'Credit notes', 'nav' => 'invoices', 'rows' => all('SELECT c.*, i.number inumber, i.bill_name FROM is_credit_notes c JOIN is_invoices i ON i.id=c.invoice_id ORDER BY c.id DESC LIMIT 300'), 'u' => $u], 'staff');
}
function cn_new_form(): void
{
    $u = require_staff('invoices.manage'); invoice_ensure_tables();
    $i = one("SELECT * FROM is_invoices WHERE id=? AND kind='tax' AND status<>'void'", [(int) ($_GET['invoice'] ?? 0)]); if (!$i) { abort(404, 'Choose an invoice to credit.'); }
    $credited = (float) one('SELECT COALESCE(SUM(subtotal),0) s FROM is_credit_notes WHERE invoice_id=?', [$i['id']])['s'];
    render('staff/credit_note_new', ['title' => 'New credit note', 'nav' => 'invoices', 'i' => $i, 'room' => round((float) $i['subtotal'] - $credited, 2), 'u' => $u], 'staff');
}
function cn_create(): void
{
    $u = require_staff('invoices.manage'); invoice_ensure_tables();
    $i = one("SELECT * FROM is_invoices WHERE id=? AND kind='tax' AND status<>'void'", [(int) ($_POST['invoice_id'] ?? 0)]); if (!$i) { abort(404); }
    $back = '/staff/credit-notes/new?invoice=' . $i['id'];
    $credited = (float) one('SELECT COALESCE(SUM(subtotal),0) s FROM is_credit_notes WHERE invoice_id=?', [$i['id']])['s']; $room = round((float) $i['subtotal'] - $credited, 2);
    $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 300); $d = (string) ($_POST['issue_date'] ?? '');
    $amt = !empty($_POST['full']) ? $room : (float) trim((string) ($_POST['amount'] ?? '0'));
    if ($reason === '' || !valid_date($d) || !is_numeric((string) $amt) || $amt <= 0 || round($amt, 2) > $room + 0.005) { flash('Give a reason, a date and an amount of at most ' . money($room) . ' (before GST).', 'bad'); redirect($back); }
    $amt = round($amt, 2); $rate = (float) $i['gst_rate']; $gst = round($amt * $rate / 100, 2);
    if ($i['tax_kind'] === 'igst') { $c = 0.0; $s = 0.0; $ig = $gst; } else { $c = round($gst / 2, 2); $s = round($gst - $c, 2); $ig = 0.0; }
    $pdo = db(); $pdo->beginTransaction();
    try {
        $number = next_number('cn_next', (string) setting('cn_prefix', 'CN-'), (int) $u['id']);
        $id = insert('INSERT INTO is_credit_notes (number,invoice_id,issue_date,reason,gst_rate,tax_kind,subtotal,cgst,sgst,igst,total,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [$number, $i['id'], $d, $reason, $rate, $i['tax_kind'], $amt, $c, $s, $ig, round($amt + $gst, 2), $u['id'], now()]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    settings_all(true); inv_settle($i); audit('creditnote.create', "$number " . ($amt + $gst) . " on {$i['number']}");
    flash("Credit note $number issued."); redirect("/staff/credit-notes/$id");
}
function cn_load(string $id): array
{
    $c = one('SELECT c.*, i.number inumber, i.bill_name, i.bill_address, i.bill_gstin, i.bill_email, i.seller, i.issue_date idate FROM is_credit_notes c JOIN is_invoices i ON i.id=c.invoice_id WHERE c.id=?', [(int) $id]); if (!$c) { abort(404); } return $c;
}
function cn_show(string $id): void
{
    $u = require_staff('invoices.manage'); $c = cn_load($id);
    $inv = one('SELECT * FROM is_invoices WHERE id=?', [$c['invoice_id']]);
    render('staff/credit_note_show', ['title' => $c['number'], 'nav' => 'invoices', 'c' => $c, 's' => json_decode($c['seller'], true) ?: [], 'invoice' => $inv, 'balance' => inv_balance($inv), 'paid' => inv_paid((int) $inv['id']), 'u' => $u], 'staff');
}
function cn_update(string $id): void
{
    $u = require_staff('invoices.manage'); $c = cn_load($id); $back = '/staff/credit-notes/' . $c['id'];
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'refund' && !$c['refunded_on']) {
        $d = (string) ($_POST['refunded_on'] ?? ''); $m = (string) ($_POST['method'] ?? '');
        if (!valid_date($d) || !isset(PAY_METHODS[$m])) { flash('Enter the refund date and method.', 'bad'); redirect($back); }
        q('UPDATE is_credit_notes SET refunded_on=?, refund_method=?, refund_ref=? WHERE id=?', [$d, $m, mb_substr(trim((string) ($_POST['ref'] ?? '')), 0, 60) ?: null, $c['id']]);
        audit('creditnote.refund', $c['number']); flash('Refund recorded.');
    } elseif ($a === 'email') {
        if (!filter_var((string) $c['bill_email'], FILTER_VALIDATE_EMAIL)) { flash('No customer e-mail on the invoice.', 'bad'); redirect($back); }
        $err = null; $co = (string) setting('legal_name', cfg('app_name'));
        $ok = send_mail($c['bill_email'], "Credit note {$c['number']} from $co", "Dear {$c['bill_name']},\n\nPlease find credit note {$c['number']} for " . money($c['total']) . " against invoice {$c['inumber']} attached.\n\nThank you,\n$co", $err, ['name' => $c['number'] . '.pdf', 'type' => 'application/pdf', 'data' => cn_pdf_build($c)->output()]);
        audit('creditnote.email', $c['number']); flash($ok ? 'Sent to ' . $c['bill_email'] . '.' : 'Could not send: ' . ($err ?: 'unknown error'), $ok ? 'ok' : 'bad');
    } else { flash('Not available.', 'bad'); }
    redirect($back);
}
function cn_pdf_build(array $c): Pdf
{
    $s = json_decode($c['seller'], true) ?: []; $p = new Pdf('Credit note ' . $c['number']);
    $p->y -= 4; $p->put((string) ($s['name'] ?? ''), Pdf::M, 15, true); $p->put('CREDIT NOTE', Pdf::W - Pdf::M, 16, true, 'R'); $p->gap(15);
    foreach (array_filter(array_merge(preg_split('/\R/', (string) ($s['address'] ?? '')) ?: [], [!empty($s['gstin']) ? 'GSTIN ' . $s['gstin'] : ''])) as $l) { $p->para($l, 9, false, null, 300, 1.3); }
    $yy = Pdf::H - Pdf::M - 22; $save = $p->y;
    foreach (['Credit note no.' => $c['number'], 'Date' => fmt_date($c['issue_date']), 'Against invoice' => $c['inumber'], 'Invoice date' => fmt_date($c['idate'])] as $k => $v) { $p->y = $yy; $p->put($k, Pdf::W - Pdf::M - 130, 9, true); $p->put((string) $v, Pdf::W - Pdf::M, 9, false, 'R'); $yy -= 13; }
    $p->y = min($save, $yy) - 4; $p->rule(2, 6);
    $p->para('Credited to', 9, true); foreach (array_filter([$c['bill_name'], $c['bill_address'], $c['bill_gstin'] ? 'GSTIN ' . $c['bill_gstin'] : '']) as $l) { $p->para((string) $l, 10, false, null, 320, 1.3); }
    $p->gap(8); $p->table([['Reason', 420, 'L'], ['Amount', 90, 'R']], [[$c['reason'], money($c['subtotal'])]]);
    $x = Pdf::W - Pdf::M; $half = rtrim(rtrim(number_format((float) $c['gst_rate'] / ($c['tax_kind'] === 'igst' ? 1 : 2), 2, '.', ''), '0'), '.');
    $rows = $c['tax_kind'] === 'igst' ? [["IGST @ $half%", money($c['igst'])]] : [["CGST @ $half%", money($c['cgst'])], ["SGST @ $half%", money($c['sgst'])]];
    $p->gap(6); foreach ($rows as [$k, $v]) { $p->y -= 14; $p->put($k, $x - 150, 10); $p->put($v, $x, 10, false, 'R'); }
    $p->y -= 6; $p->rule(2, 4); $p->y -= 13; $p->put('Total credit', $x - 150, 11, true); $p->put(money($c['total']), $x, 11, true, 'R');
    $p->gap(14); $p->para('This credit note reduces the amount due on the invoice named above.', 9);
    return $p;
}
function cn_pdf(string $id): void { require_staff('invoices.manage'); $c = cn_load($id); audit('creditnote.pdf', $c['number']); cn_pdf_build($c)->download($c['number'] . '.pdf'); }

/* ================= GST summary ================= */
function gst_month(): array
{
    $m = (string) ($_GET['m'] ?? date('Y-m')); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) { $m = date('Y-m'); }
    return [$m, "$m-01", date('Y-m-t', strtotime("$m-01"))];
}
function gst_data(string $from, string $to): array
{
    $inv = all("SELECT i.* FROM is_invoices i WHERE i.kind='tax' AND i.status<>'void' AND i.issue_date BETWEEN ? AND ? ORDER BY i.issue_date, i.id", [$from, $to]);
    $b2b = array_values(array_filter($inv, fn($i) => $i['bill_gstin'])); $b2c = [];
    foreach ($inv as $i) { if ($i['bill_gstin']) { continue; } $k = $i['tax_kind'] . '|' . $i['gst_rate']; $b2c[$k] ??= ['kind' => $i['tax_kind'], 'rate' => $i['gst_rate'], 'n' => 0, 'taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0]; $b2c[$k]['n']++; foreach (['taxable' => 'subtotal', 'cgst' => 'cgst', 'sgst' => 'sgst', 'igst' => 'igst'] as $a => $b) { $b2c[$k][$a] += (float) $i[$b]; } }
    $cdn = all("SELECT c.*, i.number inumber, i.bill_name, i.bill_gstin FROM is_credit_notes c JOIN is_invoices i ON i.id=c.invoice_id WHERE c.issue_date BETWEEN ? AND ? ORDER BY c.issue_date, c.id", [$from, $to]);
    $hsn = [];
    foreach ($inv as $i) {
        foreach (all('SELECT * FROM is_invoice_lines WHERE invoice_id=?', [$i['id']]) as $l) {
            $taxable = $i['tax_inclusive'] ? round((float) $l['amount'] * 100 / (100 + (float) $i['gst_rate']), 2) : (float) $l['amount'];
            $tax = round($taxable * (float) $i['gst_rate'] / 100, 2); $k = ($l['hsn'] ?: '-') . '|' . $i['gst_rate'];
            $hsn[$k] ??= ['hsn' => $l['hsn'] ?: '-', 'rate' => $i['gst_rate'], 'qty' => 0, 'taxable' => 0, 'tax' => 0]; $hsn[$k]['qty'] += (float) $l['qty']; $hsn[$k]['taxable'] += $taxable; $hsn[$k]['tax'] += $tax;
        }
    }
    $out = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
    foreach ($inv as $i) { $out['taxable'] += $i['subtotal']; $out['cgst'] += $i['cgst']; $out['sgst'] += $i['sgst']; $out['igst'] += $i['igst']; }
    foreach ($cdn as $c) { $out['taxable'] -= $c['subtotal']; $out['cgst'] -= $c['cgst']; $out['sgst'] -= $c['sgst']; $out['igst'] -= $c['igst']; }
    $itc = (float) one("SELECT COALESCE(SUM(gst_amount),0) s FROM is_supplier_bills WHERE status<>'void' AND bill_date BETWEEN ? AND ?", [$from, $to])['s'];
    $itcExp = (float) one('SELECT COALESCE(SUM(gst_amount),0) s FROM is_expenses WHERE expense_date BETWEEN ? AND ?', [$from, $to])['s'];
    return ['b2b' => $b2b, 'b2c' => array_values($b2c), 'cdn' => $cdn, 'hsn' => array_values($hsn), 'out' => $out, 'itc_bills' => $itc, 'itc_exp' => $itcExp, 'count' => count($inv)];
}
function gst_page(): void
{
    $u = require_staff('reports.view'); invoice_ensure_tables(); [$m, $from, $to] = gst_month();
    render('staff/gst', ['title' => 'GST summary', 'nav' => 'reports', 'm' => $m, 'd' => gst_data($from, $to), 'u' => $u], 'staff');
}
function gst_csv(string $which): void
{
    require_staff('reports.view'); [$m, $from, $to] = gst_month(); $d = gst_data($from, $to);
    $rows = match ($which) {
        'b2b' => array_merge([['GSTIN', 'Customer', 'Invoice no', 'Date', 'State', 'Taxable', 'Rate %', 'CGST', 'SGST', 'IGST', 'Invoice value']], array_map(fn($i) => [$i['bill_gstin'], $i['bill_name'], $i['number'], $i['issue_date'], $i['bill_state'], $i['subtotal'], $i['gst_rate'], $i['cgst'], $i['sgst'], $i['igst'], $i['total']], $d['b2b'])),
        'b2c' => array_merge([['Supply', 'Rate %', 'Invoices', 'Taxable', 'CGST', 'SGST', 'IGST']], array_map(fn($r) => [$r['kind'] === 'igst' ? 'Inter-state' : 'Intra-state', $r['rate'], $r['n'], round($r['taxable'], 2), round($r['cgst'], 2), round($r['sgst'], 2), round($r['igst'], 2)], $d['b2c'])),
        'hsn' => array_merge([['HSN/SAC', 'Rate %', 'Quantity', 'Taxable value', 'Tax']], array_map(fn($r) => [$r['hsn'], $r['rate'], round($r['qty'], 2), round($r['taxable'], 2), round($r['tax'], 2)], $d['hsn'])),
        'cdn' => array_merge([['Credit note', 'Date', 'Against invoice', 'Customer', 'GSTIN', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total']], array_map(fn($c) => [$c['number'], $c['issue_date'], $c['inumber'], $c['bill_name'], $c['bill_gstin'], $c['subtotal'], $c['cgst'], $c['sgst'], $c['igst'], $c['total']], $d['cdn'])),
        default => abort(404) };
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="gst-' . $which . '-' . $m . '.csv"');
    echo "\xEF\xBB\xBF"; foreach ($rows as $r) { echo implode(',', array_map('csv_cell', $r)) . "\r\n"; }
    audit('gst.csv', "$which $m"); exit;
}

/* ================= expenses and profit and loss ================= */
const EXPENSE_CATS = ['Rent', 'Electricity', 'Salaries', 'Marketing', 'Shipping and courier', 'Maintenance and spares', 'Software and subscriptions', 'Packaging', 'Bank and fees', 'Other'];
function exp_list(): void
{
    $u = require_staff('purchasing.manage');
    $m = (string) ($_GET['m'] ?? date('Y-m')); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) { $m = date('Y-m'); }
    $rows = all('SELECT e.*, u.name uname FROM is_expenses e LEFT JOIN is_users u ON u.id=e.created_by WHERE e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date DESC, e.id DESC', ["$m-01", date('Y-m-t', strtotime("$m-01"))]);
    render('staff/expenses', ['title' => 'Expenses', 'nav' => 'purchasing', 'rows' => $rows, 'm' => $m, 'u' => $u], 'staff');
}
function exp_create(): void
{
    $u = require_staff('purchasing.manage');
    $d = (string) ($_POST['expense_date'] ?? ''); $cat = (string) ($_POST['category'] ?? ''); $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 200);
    $amt = trim((string) ($_POST['amount'] ?? '')); $gst = trim((string) ($_POST['gst_amount'] ?? '')) ?: '0';
    if (!valid_date($d) || !in_array($cat, EXPENSE_CATS, true) || $desc === '' || !is_numeric($amt) || $amt <= 0 || $amt > 99999999 || !is_numeric($gst) || $gst < 0) { flash('Enter a date, category, description and amount.', 'bad'); redirect('/staff/expenses'); }
    q('INSERT INTO is_expenses (expense_date,category,description,amount,gst_amount,paid_via,created_by,created_at) VALUES (?,?,?,?,?,?,?,?)', [$d, $cat, $desc, $amt, $gst, mb_substr(trim((string) ($_POST['paid_via'] ?? '')), 0, 30) ?: null, $u['id'], now()]);
    audit('expense.create', "$cat $amt"); flash('Expense recorded.'); redirect('/staff/expenses?m=' . substr($d, 0, 7));
}
function exp_delete(string $id): void
{
    $u = require_staff('team.manage');
    $e = one('SELECT * FROM is_expenses WHERE id=?', [(int) $id]); if (!$e) { abort(404); }
    q('DELETE FROM is_expenses WHERE id=?', [$e['id']]); audit('expense.delete', $e['category'] . ' ' . $e['amount']); flash('Expense removed.'); redirect('/staff/expenses?m=' . substr($e['expense_date'], 0, 7));
}
function profit_page(): void
{
    $u = require_staff('team.manage'); invoice_ensure_tables();
    $y = (int) date('n') < 4 ? 1 : 0; $from = (string) ($_GET['from'] ?? ''); $to = (string) ($_GET['to'] ?? '');
    if (!valid_date($from)) { $from = ((int) date('Y') - $y) . '-04-01'; } if (!valid_date($to)) { $to = date('Y-m-d'); }
    $p = [$from, $to];
    $sales = (float) one("SELECT COALESCE(SUM(subtotal),0) s FROM is_invoices WHERE kind='tax' AND status<>'void' AND issue_date BETWEEN ? AND ?", $p)['s'];
    $credits = (float) one('SELECT COALESCE(SUM(subtotal),0) s FROM is_credit_notes WHERE issue_date BETWEEN ? AND ?', $p)['s'];
    $bills = (float) one("SELECT COALESCE(SUM(amount - gst_amount),0) s FROM is_supplier_bills WHERE status<>'void' AND bill_date BETWEEN ? AND ?", $p)['s'];
    $exp = all('SELECT category, SUM(amount) a FROM is_expenses WHERE expense_date BETWEEN ? AND ? GROUP BY category ORDER BY a DESC', $p);
    $expTotal = array_sum(array_column($exp, 'a'));
    $months = all("SELECT m, SUM(inc) inc, SUM(cost) cost FROM (SELECT DATE_FORMAT(issue_date,'%Y-%m') m, subtotal inc, 0 cost FROM is_invoices WHERE kind='tax' AND status<>'void' AND issue_date BETWEEN ? AND ? UNION ALL SELECT DATE_FORMAT(issue_date,'%Y-%m'), -subtotal, 0 FROM is_credit_notes WHERE issue_date BETWEEN ? AND ? UNION ALL SELECT DATE_FORMAT(bill_date,'%Y-%m'), 0, amount - gst_amount FROM is_supplier_bills WHERE status<>'void' AND bill_date BETWEEN ? AND ? UNION ALL SELECT DATE_FORMAT(expense_date,'%Y-%m'), 0, amount FROM is_expenses WHERE expense_date BETWEEN ? AND ?) t GROUP BY m ORDER BY m", array_merge($p, $p, $p, $p));
    $cashIn = (float) one('SELECT COALESCE(SUM(amount),0) s FROM is_invoice_payments WHERE paid_on BETWEEN ? AND ?', $p)['s'];
    $cashOut = (float) one('SELECT COALESCE(SUM(amount),0) s FROM is_bill_payments WHERE paid_on BETWEEN ? AND ?', $p)['s'] + $expTotal;
    render('staff/profit', ['title' => 'Profit and loss', 'nav' => 'reports', 'from' => $from, 'to' => $to, 'sales' => $sales, 'credits' => $credits, 'bills' => $bills, 'exp' => $exp, 'expTotal' => $expTotal, 'months' => $months, 'cashIn' => $cashIn, 'cashOut' => $cashOut, 'u' => $u], 'staff');
}

/* ================= bank statement matching ================= */
function bank_parse(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); $lines = preg_split('/\R/', trim($csv)); if (count($lines) < 2) { return []; }
    $delim = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : (substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',');
    $head = array_map(fn($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), $delim, '"', '\\'));
    $find = function (array $names) use ($head) { foreach ($head as $i => $h) { foreach ($names as $n) { if (str_contains($h, $n)) { return $i; } } } return null; };
    $cd = $find(['date']); $cn = $find(['narration', 'description', 'particulars', 'details', 'remarks']); $cdeb = $find(['debit', 'withdrawal', 'paid out']); $ccr = $find(['credit', 'deposit', 'paid in']); $cam = $find(['amount']);
    if ($cd === null || ($cdeb === null && $ccr === null && $cam === null)) { return []; }
    $num = fn($v) => is_numeric($x = str_replace([',', ' ', '₹'], '', (string) $v)) ? (float) $x : 0.0; $rows = [];
    foreach ($lines as $l) {
        if (trim($l) === '') { continue; } $c = str_getcsv($l, $delim, '"', '\\');
        $date = date_create_from_format('d/m/Y', trim($c[$cd] ?? '')) ?: date_create_from_format('d-m-Y', trim($c[$cd] ?? '')) ?: date_create_from_format('Y-m-d', trim($c[$cd] ?? '')) ?: date_create_from_format('d/m/y', trim($c[$cd] ?? ''));
        if (!$date) { continue; }
        $deb = $cdeb !== null ? $num($c[$cdeb] ?? 0) : 0; $cr = $ccr !== null ? $num($c[$ccr] ?? 0) : 0;
        if ($cam !== null && !$deb && !$cr) { $a = $num($c[$cam] ?? 0); if ($a < 0) { $deb = -$a; } else { $cr = $a; } }
        if ($deb <= 0 && $cr <= 0) { continue; }
        $rows[] = ['date' => $date->format('Y-m-d'), 'text' => mb_substr(trim((string) ($c[$cn] ?? '')), 0, 160), 'debit' => round($deb, 2), 'credit' => round($cr, 2)];
        if (count($rows) >= 500) { break; }
    }
    return $rows;
}
function bank_match(array $rows, bool $inv, bool $bills): array
{
    $invs = []; $bl = [];
    if ($inv) { foreach (all("SELECT * FROM is_invoices WHERE kind='tax' AND status='issued'") as $i) { $i['bal'] = inv_balance($i); if ($i['bal'] > 0.005) { $invs[] = $i; } } }
    if ($bills) { foreach (all("SELECT b.*, s.name sname FROM is_supplier_bills b JOIN is_suppliers s ON s.id=b.supplier_id WHERE b.status='open'") as $b) { $b['bal'] = round((float) $b['amount'] - bill_paid((int) $b['id']), 2); if ($b['bal'] > 0.005) { $bl[] = $b; } } }
    foreach ($rows as &$r) {
        $r['match'] = null; $t = strtolower($r['text']);
        if ($r['credit'] > 0) {
            $c = []; foreach ($invs as $i) { $score = (abs($i['bal'] - $r['credit']) < 0.01 ? 2 : 0) + (str_contains($t, strtolower($i['number'])) ? 3 : 0) + (mb_strlen($i['bill_name']) > 3 && str_contains($t, strtolower(explode(' ', trim($i['bill_name']))[0])) ? 1 : 0); if ($score >= 2 && $r['credit'] <= $i['bal'] + 0.01) { $c[] = [$score, $i]; } }
            usort($c, fn($a, $b) => $b[0] <=> $a[0]); if ($c) { $r['match'] = ['type' => 'invoice', 'row' => $c[0][1], 'sure' => $c[0][0] >= 3 || count($c) === 1, 'n' => count($c)]; }
        } else {
            $c = []; foreach ($bl as $b) { $score = (abs($b['bal'] - $r['debit']) < 0.01 ? 2 : 0) + (str_contains($t, strtolower($b['bill_no'])) ? 3 : 0) + (mb_strlen($b['sname']) > 3 && str_contains($t, strtolower(explode(' ', trim($b['sname']))[0])) ? 1 : 0); if ($score >= 2 && $r['debit'] <= $b['bal'] + 0.01) { $c[] = [$score, $b]; } }
            usort($c, fn($a, $b) => $b[0] <=> $a[0]); if ($c) { $r['match'] = ['type' => 'bill', 'row' => $c[0][1], 'sure' => $c[0][0] >= 3 || count($c) === 1, 'n' => count($c)]; }
        }
    } unset($r);
    return $rows;
}
function bank_page(): void
{
    $u = require_staff(); if (!can('invoices.manage') && !can('purchasing.manage')) { abort(403); }
    $rows = $_SESSION['bank_rows'] ?? [];
    render('staff/bank', ['title' => 'Bank statement matching', 'nav' => 'reports', 'rows' => $rows ? bank_match($rows, can('invoices.manage'), can('purchasing.manage')) : [], 'u' => $u], 'staff');
}
function bank_upload(): void
{
    $u = require_staff(); if (!can('invoices.manage') && !can('purchasing.manage')) { abort(403); }
    $f = $_FILES['statement'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) { flash('Choose a CSV file under 2 MB.', 'bad'); redirect('/staff/bank'); }
    $rows = bank_parse((string) file_get_contents($f['tmp_name']));
    if (!$rows) { flash('Could not read the statement. It needs a header row with a Date column and Debit/Credit (or Withdrawal/Deposit, or Amount) columns.', 'bad'); redirect('/staff/bank'); }
    $_SESSION['bank_rows'] = $rows; audit('bank.upload', count($rows) . ' rows'); flash(count($rows) . ' transactions read. The file itself is not stored.'); redirect('/staff/bank');
}
function bank_clear(): void { require_staff(); unset($_SESSION['bank_rows']); redirect('/staff/bank'); }
