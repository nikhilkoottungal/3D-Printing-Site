<?php $new = !(int) $s['id']; ?>
<div class="top"><h2 style="margin:0"><?= $new ? 'New supplier' : e($s['name']) ?></h2><div><?php if (!$new): ?><a class="btn" href="/staff/purchase-orders/new?supplier=<?= (int) $s['id'] ?>">+ Purchase order</a> <?php endif; ?><a class="btn alt" href="/staff/suppliers">Back</a></div></div>
<?php if (!empty($errors)): ?><div class="errs"><ul><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="grid g2" style="align-items:start"><div class="card"><form method="post" action="/staff/suppliers/<?= $new ? 'new' : (int) $s['id'] ?>"><?= csrf_field() ?>
<label>Name</label><input name="name" required maxlength="160" value="<?= e($s['name']) ?>">
<div class="row"><div><label>Contact person</label><input name="contact" maxlength="120" value="<?= e((string) $s['contact']) ?>"></div><div><label>Phone</label><input name="phone" maxlength="30" value="<?= e((string) $s['phone']) ?>"></div></div>
<div class="row"><div><label>Email</label><input name="email" maxlength="190" value="<?= e((string) $s['email']) ?>"></div><div><label>GSTIN</label><input name="gstin" maxlength="15" value="<?= e((string) $s['gstin']) ?>"></div></div>
<label>Address</label><textarea name="address" rows="3" maxlength="400"><?= e((string) $s['address']) ?></textarea>
<label>Notes</label><textarea name="notes" rows="3" maxlength="1000"><?= e((string) $s['notes']) ?></textarea>
<?php if (!$new): ?><label style="font-weight:400"><input type="checkbox" name="active" value="1" style="width:auto;margin:0 6px 0 0" <?= $s['active'] ? 'checked' : '' ?>>Active</label><br><?php endif; ?>
<button class="btn"><?= $new ? 'Add supplier' : 'Save' ?></button></form></div>
<?php if (!$new): ?><div class="card"><h3>Purchase orders</h3><div class="tw"><table><?php foreach ($pos as $p): ?><tr><td><a href="/staff/purchase-orders/<?= (int) $p['id'] ?>"><?= e($p['number']) ?></a></td><td><?= e(fmt_date($p['order_date'])) ?></td><td class="right"><?= e(money($p['total'])) ?></td><td><?= e(PO_STATUSES[$p['status']]) ?></td></tr><?php endforeach; if (!$pos): ?><tr><td class="muted">None yet.</td></tr><?php endif; ?></table></div></div><?php endif; ?></div>
