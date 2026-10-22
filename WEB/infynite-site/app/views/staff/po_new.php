<div class="top"><h2 style="margin:0">New purchase order</h2><a class="btn alt" href="/staff/purchase-orders">Cancel</a></div>
<?php if (!$suppliers): ?><div class="errs">Add a supplier first: <a href="/staff/suppliers/new">new supplier</a>.</div><?php endif; ?>
<form method="post" action="/staff/purchase-orders/new"><?= csrf_field() ?>
<div class="card mb"><div class="row"><div><label>Supplier</label><select name="supplier_id" required><option value="">Choose…</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sel === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
<div><label>Order date</label><input type="date" name="order_date" required value="<?= e(date('Y-m-d')) ?>"></div><div><label>Expected on</label><input type="date" name="expected_date"></div></div>
<label>Notes</label><textarea name="notes" rows="2" maxlength="1000"></textarea></div>
<div class="card"><h3>Lines</h3><p class="muted">Link a line to an inventory item and receiving the order adds the quantity to stock and updates the item's unit cost.</p><div class="tw"><table><tr><th>Inventory item</th><th>Description</th><th>Qty</th><th>Unit cost (₹)</th></tr>
<?php for ($n = 0; $n < 8; $n++): ?><tr><td><select name="item[<?= $n ?>]" style="margin:0"><option value="">— none —</option><?php foreach ($items as $i): ?><option value="<?= (int) $i['id'] ?>"><?= e($i['name']) ?> (<?= e($i['unit']) ?>)</option><?php endforeach; ?></select></td>
<td><input name="desc[<?= $n ?>]" maxlength="255" style="margin:0;min-width:220px"></td><td><input name="qty[<?= $n ?>]" inputmode="decimal" style="margin:0;width:80px"></td><td><input name="cost[<?= $n ?>]" inputmode="decimal" style="margin:0;width:110px"></td></tr><?php endfor; ?></table></div>
<button class="btn mt" data-confirm="Create this purchase order?">Create purchase order</button></div></form>
