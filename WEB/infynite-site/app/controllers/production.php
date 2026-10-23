<?php
/** Price calculator, quotation / delivery documents, job labels (QR), quality checks, rework and returns, delivery, printers, print queue and job costing. */
const MATERIAL_KEYS = ['PLA' => 'rate_pla', 'PETG' => 'rate_petg', 'ABS / ASA' => 'rate_abs', 'TPU' => 'rate_tpu', 'Nylon / CF' => 'rate_nylon'];
const QC_DEFAULT = "Dimensions match the model within tolerance\nNo warping, stringing or layer shifts\nSurface and colour acceptable\nSupports fully removed\nCorrect quantity counted\nPhoto of the batch attached or linked";

function num_in($v, float $max = 99999999): float { $v = trim((string) $v); return is_numeric($v) ? max(0.0, min($max, (float) $v)) : 0.0; }

/* ---------- price calculator ---------- */
function calc_quote(array $in): array
{
    $rate = (float) setting(MATERIAL_KEYS[$in['material']] ?? '', 0); $machine = (float) setting('calc_machine', 0);
    $waste = (float) setting('calc_waste', 0); $margin = (float) setting('calc_margin', 0); $rush = (float) setting('calc_rush', 0);
    $setup = (float) setting('calc_setup', 0); $min = (float) setting('calc_min', 0);
    $mat = $in['grams'] * $rate * (1 + $waste / 100); $mach = $in['hours'] * $machine; $post = $in['post'];
    $perItem = $mat + $mach + $post; $sub = $perItem * $in['qty'] + $setup;
    $withMargin = $sub * (1 + $margin / 100); $price = $in['rush'] ? $withMargin * (1 + $rush / 100) : $withMargin;
    $minApplied = $price < $min; if ($minApplied) { $price = $min; }
    return ['rate' => $rate, 'material' => $mat, 'machine' => $mach, 'post' => $post, 'per_item' => $perItem, 'setup' => $setup, 'subtotal' => $sub, 'margin_pct' => $margin, 'margin' => $withMargin - $sub,
        'rush_pct' => $rush, 'rush' => $in['rush'] ? $price - $withMargin : 0, 'min' => $min, 'min_applied' => $minApplied, 'price' => round($price, 2), 'unit' => $in['qty'] > 0 ? round($price / $in['qty'], 2) : 0, 'rate_missing' => $rate <= 0];
}
function calc_page(): void
{
    $u = require_staff('requests.view');
    $order = null; if ($id = (int) ($_GET['order'] ?? 0)) { $order = one('SELECT r.*, u.name cname FROM is_requests r JOIN is_users u ON u.id=r.user_id WHERE r.id=?', [$id]); }
    $in = ['material' => (string) ($_GET['material'] ?? ($order['material'] ?? 'PLA')), 'grams' => num_in($_GET['grams'] ?? 0, 1000000), 'hours' => num_in($_GET['hours'] ?? 0, 100000),
        'qty' => max(1, (int) num_in($_GET['qty'] ?? ($order['quantity'] ?? 1), 100000)), 'post' => num_in($_GET['post'] ?? 0), 'rush' => !empty($_GET['rush'])];
    if (!isset(MATERIAL_KEYS[$in['material']])) { $in['material'] = 'PLA'; }
    $res = isset($_GET['go']) ? calc_quote($in) : null;
    render('staff/calculator', ['title' => 'Price calculator', 'nav' => 'calc', 'u' => $u, 'in' => $in, 'res' => $res, 'order' => $order], 'staff');
}

/* ---------- documents ---------- */
function doc_head(Pdf $p, string $title, array $meta): void
{
    $s = invoice_seller();
    $p->y -= 4; $p->put((string) $s['name'], Pdf::M, 15, true); $p->put($title, Pdf::W - Pdf::M, 17, true, 'R');
    $p->gap(15); foreach (array_filter(array_merge(preg_split('/\R/', (string) $s['address']) ?: [], [trim($s['phone'] . ' ' . $s['email']), $s['gstin'] ? 'GSTIN ' . $s['gstin'] : ''])) as $l) { $p->para($l, 9, false, null, 300, 1.3); }
    $yy = Pdf::H - Pdf::M - 22; $save = $p->y;
    foreach ($meta as $k => $v) { $p->y = $yy; $p->put($k, Pdf::W - Pdf::M - 120, 9, true); $p->put((string) $v, Pdf::W - Pdf::M, 9, false, 'R'); $yy -= 13; }
    $p->y = min($save, $yy) - 4; $p->rule(2, 6);
}
function pdf_order(string $id): array
{
    $r = one('SELECT r.*, u.name cname, u.email cemail, u.phone cphone, u.address caddress, u.gstin cgstin FROM is_requests r JOIN is_users u ON u.id=r.user_id WHERE r.id=?', [(int) $id]);
    if (!$r) { abort(404); } return $r;
}
function party(Pdf $p, string $label, array $lines): void
{
    $p->para($label, 9, true); foreach (array_filter($lines) as $l) { $p->para((string) $l, 10, false, null, 320, 1.3); } $p->gap(4);
}
function quote_pdf(string $id): void
{
    require_staff('requests.view'); $r = pdf_order($id);
    if ($r['quote_amount'] === null) { flash('Record a quote first.', 'bad'); redirect('/staff/requests/' . $r['id']); }
    $rate = (float) setting('gst_rate', 0); $amt = (float) $r['quote_amount']; $qty = max(1, (int) $r['quantity']); $gst = round($amt * $rate / 100, 2);
    $days = (int) setting('quote_valid', 14) ?: 14;
    $p = new Pdf('Quotation ' . $r['ref']);
    doc_head($p, 'QUOTATION', ['Quotation no.' => $r['ref'], 'Date' => date('d M Y'), 'Valid until' => date('d M Y', strtotime("+$days days"))]);
    party($p, 'Quotation for', [$r['cname'], $r['caddress'], $r['cgstin'] ? 'GSTIN ' . $r['cgstin'] : '', trim($r['cphone'] . ' ' . (str_ends_with($r['cemail'], '@walkin.invalid') ? '' : $r['cemail']))]);
    $p->gap(6);
    $p->table([['#', 26, 'L'], ['Description', 290, 'L'], ['Qty', 50, 'R'], ['Rate', 70, 'R'], ['Amount', 70, 'R']], [['1', '3D printing: ' . $r['material'] . ', ' . $r['color'] . ($r['notes'] ? ' - ' . mb_substr(preg_replace('/\s+/', ' ', $r['notes']), 0, 160) : ''), (string) $qty, money($amt / $qty), money($amt)]]);
    $p->gap(8); $x = Pdf::W - Pdf::M;
    foreach ([['Amount', money($amt)], ['GST @ ' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%', money($gst)]] as [$k, $v]) { $p->y -= 14; $p->put($k, $x - 120, 10); $p->put($v, $x, 10, false, 'R'); }
    $p->y -= 6; $p->rule(2, 4); $p->y -= 13; $p->put('Total', $x - 120, 11, true); $p->put(money($amt + $gst), $x, 11, true, 'R'); $p->gap(10);
    if ($r['quote_note']) { $p->gap(8); $p->para('Notes', 9, true); $p->para((string) $r['quote_note']); }
    if (($t = (string) setting('quote_terms', '')) !== '') { $p->gap(8); $p->para('Terms', 9, true); $p->para($t, 9); }
    $p->gap(10); $p->para('This quotation is valid for ' . $days . ' days from the date above.', 9);
    audit('quote.pdf', $r['ref']); $p->download('Quotation-' . $r['ref'] . '.pdf');
}
function challan_pdf(string $id): void
{
    require_staff('requests.view'); $r = pdf_order($id);
    $p = new Pdf('Delivery challan ' . $r['ref']);
    doc_head($p, 'DELIVERY CHALLAN', ['Challan no.' => 'DC-' . $r['ref'], 'Date' => date('d M Y'), 'Order' => $r['ref'], 'Delivery' => (string) (DELIVERY_METHODS[$r['delivery_method'] ?? ''] ?? '-'), 'Courier' => (string) ($r['courier'] ?: '-'), 'Tracking' => (string) ($r['tracking'] ?: '-')]);
    party($p, 'Deliver to', [$r['cname'], $r['caddress'], trim($r['cphone'])]);
    $p->gap(6);
    $p->table([['#', 26, 'L'], ['Description', 370, 'L'], ['Qty', 110, 'R']], [['1', '3D printed part(s): ' . $r['material'] . ', ' . $r['color'], (string) (int) $r['quantity']]]);
    if ($r['notes']) { $p->gap(8); $p->para('Order notes', 9, true); $p->para((string) $r['notes'], 9); }
    $p->gap(40); $p->put('Prepared by: ______________________', Pdf::M, 10); $p->put('Received by: ______________________', Pdf::W - Pdf::M, 10, false, 'R');
    $p->gap(14); $p->put('Goods received in good condition. Date: ____________', Pdf::M, 9);
    audit('challan.pdf', $r['ref']); $p->download('Challan-' . $r['ref'] . '.pdf');
}
function label_page(string $id): void
{
    $u = require_staff('requests.view'); $r = pdf_order($id);
    render('staff/label', ['title' => 'Label ' . $r['ref'], 'nav' => 'requests', 'r' => $r, 'u' => $u, 'qr' => Qr::svg(app_url('/staff/requests/' . $r['id']), 6)], 'staff');
}

/* ---------- quality check, issues, delivery ---------- */
function qc_items(): array
{
    $l = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) setting('qc_items', QC_DEFAULT)) ?: [])));
    return array_slice(array_map(fn($x) => mb_substr($x, 0, 120), $l), 0, 30);
}
function order_extras(array $r): array
{
    $done = []; foreach (all('SELECT q.item, q.done, q.at, u.name uname FROM is_order_qc q LEFT JOIN is_users u ON u.id=q.by_user WHERE q.request_id=?', [$r['id']]) as $q) { $done[$q['item']] = $q; }
    $jobs = all('SELECT j.*, p.name pname, i.name iname FROM is_print_jobs j LEFT JOIN is_printers p ON p.id=j.printer_id LEFT JOIN is_inventory_items i ON i.id=j.item_id WHERE j.request_id=? ORDER BY j.id', [$r['id']]);
    $cost = 0.0; foreach ($jobs as $j) { if (in_array($j['status'], ['done', 'failed'], true)) { $cost += (float) $j['material_cost'] + (float) $j['machine_cost']; } }
    return ['qc' => $done, 'qc_items' => qc_items(), 'issues' => all('SELECT i.*, u.name uname FROM is_order_issues i LEFT JOIN is_users u ON u.id=i.created_by WHERE i.request_id=? ORDER BY i.id DESC', [$r['id']]),
        'jobs' => $jobs, 'job_cost' => $cost, 'printers' => all("SELECT id,name FROM is_printers WHERE active=1 AND status<>'offline' ORDER BY name"),
        'filaments' => all("SELECT id,name,unit,qty FROM is_inventory_items WHERE active=1 AND category='filament' ORDER BY name")];
}
function order_action(string $action, array $r, array $u, string $back): void
{
    $prod = can('requests.production', $u) || can('requests.quote', $u);
    if ($action === 'qc') {
        if (!$prod) { abort(403, 'Your role cannot record quality checks.'); }
        $items = qc_items(); $ticked = (array) ($_POST['qc'] ?? []);
        foreach ($items as $it) {
            $on = in_array($it, $ticked, true) ? 1 : 0;
            $old = one('SELECT done FROM is_order_qc WHERE request_id=? AND item=?', [$r['id'], $it]);
            if (!$old || (int) $old['done'] !== $on) { q('REPLACE INTO is_order_qc (request_id,item,done,by_user,at) VALUES (?,?,?,?,?)', [$r['id'], $it, $on, $u['id'], now()]); }
        }
        audit('qc.save', $r['ref']); flash('Quality check saved.');
    } elseif ($action === 'issue') {
        $kind = (string) ($_POST['kind'] ?? ''); $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!in_array($kind, ['rework', 'return'], true) || $reason === '' || mb_strlen($reason) > 500) { flash('Choose rework or return and describe the reason.', 'bad'); redirect($back); }
        $cost = trim((string) ($_POST['cost'] ?? ''));
        q('INSERT INTO is_order_issues (request_id,kind,reason,cost,status,created_by,created_at) VALUES (?,?,?,?,?,?,?)', [$r['id'], $kind, $reason, $cost !== '' && is_numeric($cost) ? $cost : null, 'open', $u['id'], now()]);
        audit('order.issue', $r['ref'] . ' ' . $kind); flash(ucfirst($kind) . ' recorded.');
    } elseif ($action === 'issue_resolve') {
        $res = trim((string) ($_POST['resolution'] ?? ''));
        q("UPDATE is_order_issues SET status='resolved', resolution=?, resolved_at=? WHERE id=? AND request_id=? AND status='open'", [mb_substr($res, 0, 500) ?: null, now(), (int) ($_POST['issue_id'] ?? 0), $r['id']]);
        audit('order.issue_resolved', $r['ref']); flash('Marked resolved.');
    } elseif ($action === 'delivery') {
        if (!$prod) { abort(403); }
        $due = trim((string) ($_POST['due_date'] ?? ''));
        if ($due !== '' && !valid_date($due)) { flash('Check the promised date.', 'bad'); redirect($back); }
        $dm = (string) ($_POST['delivery_method'] ?? ''); if ($dm !== '' && !isset(DELIVERY_METHODS[$dm])) { flash('Choose courier, hand delivery or customer pickup.', 'bad'); redirect($back); }
        $courier = $dm === 'courier' || $dm === '' ? (mb_substr(trim((string) ($_POST['courier'] ?? '')), 0, 80) ?: null) : null; $track = $dm === 'courier' || $dm === '' ? (mb_substr(trim((string) ($_POST['tracking'] ?? '')), 0, 80) ?: null) : null;
        q('UPDATE is_requests SET delivery_method=?, courier=?, tracking=?, due_date=? WHERE id=?', [$dm ?: null, $courier, $track, $due ?: null, $r['id']]);
        audit('order.delivery', $r['ref']); flash('Delivery details saved.');
    } elseif ($action === 'job_add') {
        if (!can('printers.manage', $u)) { abort(403, 'Your role cannot plan print jobs.'); }
        if (in_array($r['status'], ['completed', 'cancelled', 'failed'], true)) { flash('This order is ' . strtolower(STATUSES[$r['status']]) . ': print jobs cannot be planned on it.', 'bad'); redirect($back); }
        $pr = (int) ($_POST['printer_id'] ?? 0) ?: null; $it = (int) ($_POST['item_id'] ?? 0) ?: null; $d = trim((string) ($_POST['scheduled_for'] ?? ''));
        if ($pr && !one('SELECT id FROM is_printers WHERE id=? AND active=1', [$pr])) { flash('Unknown printer.', 'bad'); redirect($back); }
        if ($it && !one("SELECT id FROM is_inventory_items WHERE id=? AND category='filament'", [$it])) { flash('Choose a filament item.', 'bad'); redirect($back); }
        if ($d !== '' && !valid_date($d)) { flash('Check the date.', 'bad'); redirect($back); }
        q('INSERT INTO is_print_jobs (request_id,printer_id,item_id,status,scheduled_for,est_hours,est_grams,notes,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$r['id'], $pr, $it, 'queued', $d ?: null, num_in($_POST['est_hours'] ?? 0, 100000) ?: null, num_in($_POST['est_grams'] ?? 0, 1000000) ?: null, mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 500) ?: null, $u['id'], now()]);
        audit('job.add', $r['ref']); flash('Print job queued.');
    }
    redirect($back);
}

/* ---------- printers, queue, jobs ---------- */
/** How a finished order reaches the customer. */
const DELIVERY_METHODS = ['courier' => 'Courier', 'hand' => 'Hand delivery by our staff', 'pickup' => 'Customer pickup'];
const PRINTER_STATUSES = ['active' => 'Active', 'maintenance' => 'In maintenance', 'offline' => 'Offline'];
function printers_page(): void
{
    $u = require_staff();
    $rows = all("SELECT p.*, (SELECT COUNT(*) FROM is_print_jobs j WHERE j.printer_id=p.id AND j.status IN ('queued','printing')) open_jobs, (SELECT COALESCE(SUM(actual_hours),0) FROM is_print_jobs j WHERE j.printer_id=p.id AND j.status='done') hours FROM is_printers p ORDER BY p.active DESC, p.name");
    render('staff/printers', ['title' => 'Printers', 'nav' => 'printers', 'rows' => $rows, 'u' => $u], 'staff');
}
function printer_save(): void
{
    $u = require_staff('printers.manage');
    $id = (int) ($_POST['id'] ?? 0); $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80); $st = (string) ($_POST['status'] ?? 'active'); $nm = trim((string) ($_POST['next_maintenance'] ?? ''));
    if ($name === '' || !isset(PRINTER_STATUSES[$st]) || ($nm !== '' && !valid_date($nm))) { flash('Enter a name, a status and a valid date.', 'bad'); redirect('/staff/printers'); }
    $vals = [$name, mb_substr(trim((string) ($_POST['model'] ?? '')), 0, 120) ?: null, $st, $nm ?: null, mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 500) ?: null, !empty($_POST['active']) || !$id ? 1 : 0];
    if ($id) { q('UPDATE is_printers SET name=?, model=?, status=?, next_maintenance=?, notes=?, active=? WHERE id=?', array_merge($vals, [$id])); }
    else { q('INSERT INTO is_printers (name,model,status,next_maintenance,notes,active,created_at) VALUES (?,?,?,?,?,?,?)', array_merge($vals, [now()])); }
    audit('printer.save', $name); flash('Printer saved.'); redirect('/staff/printers');
}
function queue_page(): void
{
    $u = require_staff();
    $jobs = all("SELECT j.*, r.ref, r.material, r.color, r.quantity, r.due_date, p.name pname, c.name cname FROM is_print_jobs j JOIN is_requests r ON r.id=j.request_id JOIN is_users c ON c.id=r.user_id LEFT JOIN is_printers p ON p.id=j.printer_id WHERE j.status IN ('queued','printing') ORDER BY (j.status='printing') DESC, j.scheduled_for IS NULL, j.scheduled_for, j.id");
    $printers = all("SELECT id,name,status FROM is_printers WHERE active=1 ORDER BY name");
    $days = []; for ($i = 0; $i < 14; $i++) { $days[] = date('Y-m-d', strtotime("+$i days")); }
    $cal = []; foreach (all("SELECT j.id, j.printer_id, j.scheduled_for, j.status, r.ref FROM is_print_jobs j JOIN is_requests r ON r.id=j.request_id WHERE j.scheduled_for BETWEEN ? AND ? AND j.status IN ('queued','printing','done')", [$days[0], $days[13]]) as $j) { $cal[(int) $j['printer_id']][$j['scheduled_for']][] = $j; }
    $recent = all("SELECT j.*, r.ref, p.name pname FROM is_print_jobs j JOIN is_requests r ON r.id=j.request_id LEFT JOIN is_printers p ON p.id=j.printer_id WHERE j.status IN ('done','failed') ORDER BY j.finished_at DESC LIMIT 15");
    render('staff/queue', ['title' => 'Print queue', 'nav' => 'queue', 'jobs' => $jobs, 'printers' => $printers, 'days' => $days, 'cal' => $cal, 'recent' => $recent, 'u' => $u], 'staff');
}
function job_update(string $id): void
{
    $u = require_staff('printers.manage');
    $j = one('SELECT j.*, r.ref, r.id rid FROM is_print_jobs j JOIN is_requests r ON r.id=j.request_id WHERE j.id=?', [(int) $id]);
    if (!$j) { abort(404); }
    $a = (string) ($_POST['action'] ?? ''); $back = (string) ($_POST['back'] ?? '') === 'order' ? '/staff/requests/' . $j['rid'] : '/staff/queue';
    if ($a === 'start' && $j['status'] === 'queued') {
        q("UPDATE is_print_jobs SET status='printing', started_at=? WHERE id=?", [now(), $j['id']]);
        $r = one('SELECT * FROM is_requests WHERE id=?', [$j['rid']]);
        if (in_array($r['status'], ['approved', 'scheduled'], true)) { set_request_status($r, 'printing', 'Print job #' . $j['id'] . ' started', (int) $u['id']); }
        flash('Job started.');
    } elseif ($a === 'finish' && in_array($j['status'], ['queued', 'printing'], true)) {
        $hours = num_in($_POST['actual_hours'] ?? ($j['est_hours'] ?? 0), 100000); $grams = num_in($_POST['actual_grams'] ?? ($j['est_grams'] ?? 0), 1000000);
        $matCost = null; $pdo = db(); $pdo->beginTransaction();
        try {
            $cur = one('SELECT status FROM is_print_jobs WHERE id=? FOR UPDATE', [$j['id']]);   // two clicks / two people at once must not deduct twice
            if (!$cur || !in_array($cur['status'], ['queued', 'printing'], true)) { $pdo->rollBack(); flash('That job was already finished or removed.', 'bad'); redirect($back); }
            if ($j['item_id'] && $grams > 0) {
                $i = one('SELECT * FROM is_inventory_items WHERE id=? FOR UPDATE', [$j['item_id']]);
                $per = max(0.01, (float) $i['grams_per_unit']); $use = round($grams / $per, 2); $new = (float) $i['qty'] - $use;
                if ($new < 0) { $pdo->rollBack(); flash("Not enough stock of {$i['name']}: {$i['qty']} {$i['unit']} left, this job needs $use. Correct the stock or use another filament.", 'bad'); redirect($back); }
                q('UPDATE is_inventory_items SET qty=?, updated_at=? WHERE id=?', [$new, now(), $i['id']]);
                q("INSERT INTO is_stock_moves (item_id,user_id,delta,qty_after,reason,request_id,note,created_at) VALUES (?,?,?,?,'use',?,?,?)", [$i['id'], $u['id'], -$use, $new, $j['rid'], 'Print job #' . $j['id'] . ' (' . $grams . ' g)', now()]);
                $matCost = round($use * (float) $i['unit_cost'], 2);
                if ((float) $i['reorder_level'] > 0 && $new <= (float) $i['reorder_level']) { $low = $i; }
            }
            q("UPDATE is_print_jobs SET status='done', actual_hours=?, actual_grams=?, material_cost=?, machine_cost=?, finished_at=?, started_at=COALESCE(started_at,?) WHERE id=?", [$hours, $grams, $matCost, round($hours * (float) setting('calc_machine', 0), 2), now(), now(), $j['id']]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } error_log('job finish failed: ' . $e->getMessage()); abort(500, 'Could not finish the job.'); }
        if (!empty($low)) { notify('stock.low', "Low stock: {$low['name']}", "{$low['name']} is low after print job #{$j['id']}.\n" . app_url('/staff/inventory/' . $low['id'])); }
        audit('job.finish', '#' . $j['id'] . ' ' . $grams . ' g'); flash('Job finished' . ($matCost !== null ? ': filament deducted from stock and cost recorded.' : '.'));
        // Last open job done and the order is on Printing -> it is ready for the quality check (nothing else is changed automatically).
        $ro = one('SELECT * FROM is_requests WHERE id=?', [$j['rid']]);
        if ($ro && $ro['status'] === 'printing' && !(int) one("SELECT COUNT(*) c FROM is_print_jobs WHERE request_id=? AND status IN ('queued','printing')", [$j['rid']])['c']) {
            set_request_status($ro, 'quality_check', 'All print jobs finished (job #' . $j['id'] . ')', (int) $u['id']);
            flash('Order ' . $j['ref'] . ' moved to Quality check: all its print jobs are finished.');
        }
    } elseif ($a === 'fail' && in_array($j['status'], ['queued', 'printing'], true)) {
        // Optional: filament already wasted on the failed print (grams). Blank = nothing deducted. Recorded as a 'waste' stock move and as the job's material cost.
        $w = num_in($_POST['wasted_grams'] ?? 0, 1000000); $matCost = null; $low = null;
        $pdo = db(); $pdo->beginTransaction();
        try {
            $cur = one('SELECT status FROM is_print_jobs WHERE id=? FOR UPDATE', [$j['id']]);
            if (!$cur || !in_array($cur['status'], ['queued', 'printing'], true)) { $pdo->rollBack(); flash('That job was already finished or removed.', 'bad'); redirect($back); }
            if ($j['item_id'] && $w > 0) {
                $i = one('SELECT * FROM is_inventory_items WHERE id=? FOR UPDATE', [$j['item_id']]);
                $per = max(0.01, (float) $i['grams_per_unit']); $use = round($w / $per, 2); $new = (float) $i['qty'] - $use;
                if ($new < 0) { $pdo->rollBack(); flash("Not enough stock of {$i['name']}: {$i['qty']} {$i['unit']} left, the wasted filament is $use. Correct the stock first.", 'bad'); redirect($back); }
                q('UPDATE is_inventory_items SET qty=?, updated_at=? WHERE id=?', [$new, now(), $i['id']]);
                q("INSERT INTO is_stock_moves (item_id,user_id,delta,qty_after,reason,request_id,note,created_at) VALUES (?,?,?,?,'waste',?,?,?)", [$i['id'], $u['id'], -$use, $new, $j['rid'], 'Failed print job #' . $j['id'] . ' (' . $w . ' g wasted)', now()]);
                $matCost = round($use * (float) $i['unit_cost'], 2);
                if ((float) $i['reorder_level'] > 0 && $new <= (float) $i['reorder_level']) { $low = $i; }
            }
            q("UPDATE is_print_jobs SET status='failed', actual_grams=?, material_cost=?, finished_at=? WHERE id=?", [$w > 0 ? $w : null, $matCost, now(), $j['id']]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } error_log('job fail failed: ' . $e->getMessage()); abort(500, 'Could not mark the job as failed.'); }
        if ($low) { notify('stock.low', "Low stock: {$low['name']}", "{$low['name']} is low after failed print job #{$j['id']}.\n" . app_url('/staff/inventory/' . $low['id'])); }
        audit('job.fail', '#' . $j['id'] . ($w > 0 ? ' ' . $w . ' g wasted' : '')); flash($matCost !== null ? 'Job marked failed: ' . $w . ' g of filament deducted from stock as waste and counted in the order cost.' : 'Job marked failed. No stock was deducted.');
    } elseif ($a === 'cancel' && $j['status'] === 'queued') {
        q('DELETE FROM is_print_jobs WHERE id=?', [$j['id']]); flash('Job removed.');
    } else { flash('That action is not available for this job.', 'bad'); }
    redirect($back);
}
