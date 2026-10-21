<div class="top"><div><h2 style="margin:0">New direct order</h2><span class="muted">For orders received by phone, e-mail, WhatsApp or in person.</span></div><a class="btn alt" href="/staff/requests">Cancel</a></div>
<form method="post" action="/staff/requests/new" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="grid g2" style="align-items:start">
  <div class="card"><h3>1 · Customer</h3>
    <label for="customer_id">Existing customer</label>
    <select id="customer_id" name="customer_id"><option value="">-- new / walk-in customer --</option><?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($old['customer_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> · <?= e(str_ends_with($c['email'], '@walkin.invalid') ? 'walk-in' : $c['email']) ?></option><?php endforeach; ?></select>
    <p class="muted" style="font-size:.85rem">Or fill in a new customer (ignored if one is selected above):</p>
    <label>Name</label><input name="c_name" maxlength="120" value="<?= e($old['c_name'] ?? '') ?>">
    <div class="row"><div><label>Phone</label><input name="c_phone" maxlength="30" value="<?= e($old['c_phone'] ?? '') ?>"></div><div><label>Email (optional)</label><input name="c_email" type="email" maxlength="190" value="<?= e($old['c_email'] ?? '') ?>"></div></div>
    <div class="note">New customers get a record but cannot log in. If they give an email, they can claim the account later with "Forgot password".</div>
    <h3 class="mt">Model files (optional)</h3>
    <input type="file" name="files[]" multiple accept=".stl,.3mf" data-list="filelist"><p class="muted" id="filelist" style="font-size:.85rem"></p></div>
  <div class="card"><h3>2 · Order</h3>
    <div class="row"><div><label>Material</label><select name="material"><?= options(MATERIALS, $old['material'] ?? '') ?></select></div><div><label>Colour</label><select name="color"><?= options(COLORS, $old['color'] ?? '') ?></select></div><div><label>Quantity</label><input name="quantity" type="number" min="1" max="10000" value="<?= (int) ($old['quantity'] ?? 1) ?>"></div></div>
    <label>Notes (what was agreed, customer's reference/PO, deadline)</label><textarea name="notes" rows="4" maxlength="4000"><?= e($old['notes'] ?? '') ?></textarea>
    <div class="row"><div><label>Agreed price ₹ (if known)</label><input name="amount" inputmode="decimal" value="<?= e($old['amount'] ?? '') ?>"></div>
    <div><label>Start in status</label><select name="status"><?php foreach (['requested', 'under_review', 'quoted', 'approved', 'scheduled', 'printing'] as $k): if (can(STATUS_PERM[$k])): ?><option value="<?= $k ?>" <?= ($old['status'] ?? 'approved') === $k ? 'selected' : '' ?>><?= e(STATUSES[$k]) ?></option><?php endif; endforeach; ?></select></div></div>
    <label>Assign production task to (optional)</label><select name="assignee_id"><option value="">Don't create a task</option><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($old['assignee_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e(ROLES[$p['role']]) ?>)</option><?php endforeach; ?></select>
    <button class="btn">Create order</button> <span class="muted" style="font-size:.85rem">No email is sent to the customer.</span></div>
</div></form>
