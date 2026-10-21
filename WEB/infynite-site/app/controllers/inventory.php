<?php
function inv_load(int $id): array
{
    $i = one('SELECT * FROM is_inventory_items WHERE id=?', [$id]);
    if (!$i) { abort(404, 'Item not found.'); }
    return $i;
}
function inv_low(array $i): bool { return (float) $i['reorder_level'] > 0 && (float) $i['qty'] <= (float) $i['reorder_level']; }
function num(string $s): ?string { $s = trim($s); return preg_match('/^-?\d{1,9}(\.\d{1,2})?$/', $s) ? $s : null; }

function inv_list(): void
{
    $u = require_staff();
    $cat = (string) ($_GET['cat'] ?? ''); $low = !empty($_GET['low']); $s = trim((string) ($_GET['q'] ?? ''));
    $where = ['active=1']; $p = [];
    if (isset(STOCK_CATEGORIES[$cat])) { $where[] = 'category=?'; $p[] = $cat; }
    if ($low) { $where[] = 'reorder_level > 0 AND qty <= reorder_level'; }
    if ($s !== '') { $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $s) . '%'; $where[] = '(name LIKE ? OR sku LIKE ? OR material LIKE ? OR color LIKE ? OR supplier LIKE ?)'; array_push($p, $like, $like, $like, $like, $like); }
    $rows = all('SELECT * FROM is_inventory_items WHERE ' . implode(' AND ', $where) . ' ORDER BY category, name LIMIT 500', $p);
    $value = (float) one('SELECT COALESCE(SUM(qty*unit_cost),0) v FROM is_inventory_items WHERE active=1')['v'];
    render('staff/inventory', ['title' => 'Inventory', 'nav' => 'inventory', 'rows' => $rows, 'cat' => $cat, 'low' => $low, 'q' => $s, 'value' => $value], 'staff');
}

function inv_new_form(): void
{
    require_staff('stock.manage');
    render('staff/inventory_edit', ['title' => 'New inventory item', 'nav' => 'inventory', 'i' => ['name' => '', 'sku' => '', 'category' => 'filament', 'material' => '', 'color' => '', 'unit' => 'spool', 'qty' => '0', 'reorder_level' => '2', 'location' => '', 'supplier' => '', 'unit_cost' => '', 'notes' => '', 'id' => null]], 'staff');
}

function inv_input(): array
{
    $d = [];
    foreach (['name' => 160, 'sku' => 40, 'material' => 40, 'color' => 40, 'unit' => 20, 'location' => 80, 'supplier' => 120] as $k => $max) { $d[$k] = trim((string) ($_POST[$k] ?? '')); if (mb_strlen($d[$k]) > $max) { $errors[] = "$k is too long."; } }
    $d['category'] = (string) ($_POST['category'] ?? 'filament'); $d['notes'] = trim((string) ($_POST['notes'] ?? ''));
    $errors = $errors ?? [];
    if ($d['name'] === '') { $errors[] = 'Name is required.'; }
    if ($d['unit'] === '') { $d['unit'] = 'pcs'; }
    if (!isset(STOCK_CATEGORIES[$d['category']])) { $errors[] = 'Invalid category.'; }
    $rl = num((string) ($_POST['reorder_level'] ?? '0')); if ($rl === null || (float) $rl < 0) { $errors[] = 'Reorder level must be a number ≥ 0.'; }
    $uc = trim((string) ($_POST['unit_cost'] ?? '')); if ($uc !== '' && (num($uc) === null || (float) $uc < 0)) { $errors[] = 'Unit cost must be a number.'; }
    $d['reorder_level'] = $rl ?? '0'; $d['unit_cost'] = $uc === '' ? null : $uc;
    return [$errors, $d];
}

function inv_create(): void
{
    $u = require_staff('stock.manage');
    [$errors, $d] = inv_input();
    $qty = num((string) ($_POST['qty'] ?? '0'));
    if ($qty === null || (float) $qty < 0) { $errors[] = 'Opening quantity must be a number ≥ 0.'; }
    if ($d['sku'] !== '' && one('SELECT id FROM is_inventory_items WHERE sku=?', [$d['sku']])) { $errors[] = 'That SKU already exists.'; }
    if ($errors) { render('staff/inventory_edit', ['title' => 'New inventory item', 'nav' => 'inventory', 'errors' => $errors, 'i' => $d + ['qty' => $_POST['qty'] ?? '0', 'id' => null]], 'staff'); return; }
    $id = insert('INSERT INTO is_inventory_items (sku,name,category,material,color,unit,qty,reorder_level,location,supplier,unit_cost,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$d['sku'] ?: null, $d['name'], $d['category'], $d['material'] ?: null, $d['color'] ?: null, $d['unit'], $qty, $d['reorder_level'], $d['location'] ?: null, $d['supplier'] ?: null, $d['unit_cost'], $d['notes'] ?: null, now(), now()]);
    if ((float) $qty > 0) { q('INSERT INTO is_stock_moves (item_id,user_id,delta,qty_after,reason,note,created_at) VALUES (?,?,?,?,?,?,?)', [$id, $u['id'], $qty, $qty, 'adjust', 'Opening stock', now()]); }
    audit('stock.create', $d['name']);
    flash('Item added.');
    redirect('/staff/inventory/' . $id);
}

function inv_show(string $id): void
{
    $u = require_staff();
    $i = inv_load((int) $id);
    $moves = all('SELECT m.*, u.name uname, r.ref rref FROM is_stock_moves m LEFT JOIN is_users u ON u.id=m.user_id LEFT JOIN is_requests r ON r.id=m.request_id WHERE m.item_id=? ORDER BY m.id DESC LIMIT 100', [$i['id']]);
    render('staff/inventory_show', ['title' => $i['name'], 'nav' => 'inventory', 'i' => $i, 'moves' => $moves, 'edit' => isset($_GET['edit']) && can('stock.manage')], 'staff');
}

function inv_update(string $id): void
{
    $u = require_staff('stock.manage');
    $i = inv_load((int) $id);
    [$errors, $d] = inv_input();
    if ($d['sku'] !== '' && one('SELECT id FROM is_inventory_items WHERE sku=? AND id<>?', [$d['sku'], $i['id']])) { $errors[] = 'That SKU already exists.'; }
    if ($errors) { flash(implode(' ', $errors), 'bad'); redirect('/staff/inventory/' . $i['id'] . '?edit=1'); }
    q('UPDATE is_inventory_items SET sku=?,name=?,category=?,material=?,color=?,unit=?,reorder_level=?,location=?,supplier=?,unit_cost=?,notes=?,active=?,updated_at=? WHERE id=?',
        [$d['sku'] ?: null, $d['name'], $d['category'], $d['material'] ?: null, $d['color'] ?: null, $d['unit'], $d['reorder_level'], $d['location'] ?: null, $d['supplier'] ?: null, $d['unit_cost'], $d['notes'] ?: null, empty($_POST['archive']) ? 1 : 0, now(), $i['id']]);
    audit('stock.edit', $d['name']);
    flash(empty($_POST['archive']) ? 'Item updated.' : 'Item archived.');
    redirect(empty($_POST['archive']) ? '/staff/inventory/' . $i['id'] : '/staff/inventory');
}

/** Records a stock movement atomically; stock can never go below zero. */
function inv_move(string $id): void
{
    $u = require_staff('stock.use');
    $reason = (string) ($_POST['reason'] ?? '');
    if (!isset(STOCK_REASONS[$reason])) { abort(400); }
    if (in_array($reason, ['purchase', 'adjust'], true) && !can('stock.manage', $u)) { abort(403, 'Your role can record usage, not purchases or corrections.'); }
    $amt = num((string) ($_POST['amount'] ?? ''));
    if ($amt === null || (float) $amt == 0.0) { flash('Enter a quantity.', 'bad'); redirect('/staff/inventory/' . (int) $id); }
    $amount = abs((float) $amt);
    // purchase/return add; use/waste remove; adjust sets the counted quantity
    $reqRef = trim((string) ($_POST['request_ref'] ?? ''));
    $reqId = null;
    if ($reqRef !== '') { $r = one('SELECT id FROM is_requests WHERE ref=?', [$reqRef]); if (!$r) { flash('Unknown order reference.', 'bad'); redirect('/staff/inventory/' . (int) $id); } $reqId = (int) $r['id']; }
    $pdo = db(); $pdo->beginTransaction();
    try {
        $i = one('SELECT * FROM is_inventory_items WHERE id=? FOR UPDATE', [(int) $id]);
        if (!$i) { throw new RuntimeException('missing'); }
        $cur = (float) $i['qty'];
        if ($reason === 'adjust') { $new = $amount; $delta = $new - $cur; }
        else { $delta = in_array($reason, ['purchase', 'return'], true) ? $amount : -$amount; $new = $cur + $delta; }
        if ($new < 0) { $pdo->rollBack(); flash('Not enough stock: only ' . $cur . ' ' . $i['unit'] . ' available.', 'bad'); redirect('/staff/inventory/' . $i['id']); }
        q('UPDATE is_inventory_items SET qty=?, updated_at=? WHERE id=?', [$new, now(), $i['id']]);
        q('INSERT INTO is_stock_moves (item_id,user_id,delta,qty_after,reason,request_id,note,created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$i['id'], $u['id'], $delta, $new, $reason, $reqId, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 300) ?: null, now()]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('stock move failed: ' . $ex->getMessage());
        abort(500, 'Could not record the movement.');
    }
    audit('stock.move', "item {$i['id']} $reason $delta");
    $msg = 'Stock updated: now ' . $new . ' ' . $i['unit'] . '.';
    if ((float) $i['reorder_level'] > 0 && $new <= (float) $i['reorder_level']) { $msg .= ' Below reorder level, time to reorder.'; }
    flash($msg);
    redirect('/staff/inventory/' . $i['id']);
}
