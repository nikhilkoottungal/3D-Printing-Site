<div class="top"><h2 style="margin:0">New inventory item</h2><a class="btn alt" href="/staff/inventory">Cancel</a></div>
<div class="card"><form method="post" action="/staff/inventory/new"><?= csrf_field() ?>
<?php require __DIR__ . '/inventory_fields.php'; ?>
<label>Opening quantity</label><input name="qty" inputmode="decimal" value="<?= e($i['qty']) ?>">
<button class="btn">Add item</button></form></div>
