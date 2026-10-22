<div class="top"><h2 style="margin:0">New invoice<?= $r ? ' for ' . e($r['ref']) : '' ?></h2><a class="btn alt" href="/staff/invoices">Cancel</a></div>
<?php if (!$ready): ?><div class="errs">Company details are not set. The Owner must fill in the legal name, GSTIN and invoice settings in <a href="/staff/admin">Admin settings</a> first.</div><?php endif; ?>
<form method="post" action="/staff/invoices/new"><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) ($r['id'] ?? 0) ?>"><input type="hidden" name="customer_id" value="<?= (int) $old['customer_id'] ?>">
<div class="grid g2" style="align-items:start">
<div class="card"><h3>Bill to</h3>
<label>Name</label><input name="bill_name" required maxlength="160" value="<?= e($old['bill_name']) ?>">
<label>Address</label><textarea name="bill_address" rows="3" maxlength="400"><?= e($old['bill_address']) ?></textarea>
<div class="row"><div><label>Email</label><input name="bill_email" maxlength="190" value="<?= e($old['bill_email']) ?>"></div><div><label>Phone</label><input name="bill_phone" maxlength="30" value="<?= e($old['bill_phone']) ?>"></div></div>
<div class="row"><div><label>Customer GSTIN (optional)</label><input name="bill_gstin" maxlength="15" value="<?= e($old['bill_gstin']) ?>"></div><div><label>Customer state (decides CGST+SGST or IGST)</label><input name="bill_state" maxlength="60" value="<?= e($old['bill_state']) ?>" placeholder="blank = same state as us"></div></div></div>
<div class="card"><h3>Invoice</h3>
<div class="row"><div><label>Issue date</label><input name="issue_date" type="date" required value="<?= e(date('Y-m-d')) ?>"></div><div><label>GST rate (%)</label><input name="gst_rate" inputmode="decimal" required value="<?= e($rate) ?>"></div></div>
<label style="font-weight:400"><input type="checkbox" name="doc_kind" value="proforma" style="width:auto;margin:0 6px 0 0">Pro-forma invoice (advance request for payment, not a tax invoice)</label><br>
<label style="font-weight:400"><input type="checkbox" name="tax_inclusive" value="1" style="width:auto;margin:0 6px 0 0">Line rates already include GST</label><br>
<label>Notes (printed on invoice)</label><textarea name="notes" rows="3" maxlength="1000"><?= e($old['notes']) ?></textarea></div></div>
<div class="card mt"><h3>Lines</h3><div class="tw"><table><tr><th>Description</th><th>HSN/SAC</th><th>Qty</th><th>Rate (₹)</th></tr>
<?php for ($n = 0; $n < 8; $n++): $l = $old['lines'][$n] ?? ['description' => '', 'qty' => '1', 'rate' => '']; ?>
<tr><td><input name="desc[<?= $n ?>]" maxlength="255" value="<?= e($l['description']) ?>" style="margin:0;min-width:260px"></td><td><input name="hsn[<?= $n ?>]" maxlength="10" value="" style="margin:0;width:90px"></td><td><input name="qty[<?= $n ?>]" inputmode="decimal" value="<?= e($l['qty']) ?>" style="margin:0;width:80px"></td><td><input name="rate[<?= $n ?>]" inputmode="decimal" value="<?= e($l['rate']) ?>" style="margin:0;width:110px"></td></tr>
<?php endfor; ?></table></div><p class="muted mt">Empty rows are ignored. An invoice cannot be edited after it is issued; void it and issue a new one if something is wrong.</p>
<button class="btn" data-confirm="Issue this invoice? It gets the next number and cannot be edited."<?= $ready ? '' : ' disabled' ?>>Issue invoice</button></div>
</form>
