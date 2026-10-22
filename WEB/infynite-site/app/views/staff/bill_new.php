<?php $ptab = 'bills'; require __DIR__ . '/purch_tabs.php'; ?>
<div class="top"><h2 style="margin:0">New supplier bill</h2><a class="btn alt" href="/staff/bills">Cancel</a></div>
<div class="card" style="max-width:720px"><form method="post" action="/staff/bills/new"><?= csrf_field() ?>
<div class="row"><div><label>Supplier</label><select name="supplier_id" required><option value="">Choose...</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sel === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div><div><label>Supplier's bill number</label><input name="bill_no" required maxlength="60"></div></div>
<div class="row"><div><label>Bill date</label><input type="date" name="bill_date" required value="<?= e(date('Y-m-d')) ?>"></div><div><label>Due date</label><input type="date" name="due_date" required value="<?= e(date('Y-m-d', strtotime('+30 days'))) ?>"></div></div>
<div class="row"><div><label>Bill total incl. GST (Rs.)</label><input name="amount" inputmode="decimal" required></div><div><label>of which GST (Rs., optional)</label><input name="gst_amount" inputmode="decimal"></div></div>
<label>Purchase order (optional)</label><select name="po_id"><option value="">none</option><?php foreach ($pos as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['number'] . ' - ' . $p['sname']) ?></option><?php endforeach; ?></select>
<label>Notes</label><input name="notes" maxlength="500"><button class="btn">Record bill</button></form></div>
