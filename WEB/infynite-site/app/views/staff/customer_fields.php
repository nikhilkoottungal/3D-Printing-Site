<label>Name</label><input name="name" required maxlength="120" value="<?= e($old['name']) ?>">
<div class="row"><div><label>Email (optional)</label><input name="email" maxlength="190" value="<?= e($old['email']) ?>"></div><div><label>Phone</label><input name="phone" maxlength="30" value="<?= e((string) $old['phone']) ?>"></div></div>
<label>Address</label><textarea name="address" rows="3" maxlength="400"><?= e((string) $old['address']) ?></textarea>
<div class="row"><div><label>GSTIN (optional)</label><input name="gstin" maxlength="15" value="<?= e((string) $old['gstin']) ?>"></div><div><label>State (for CGST+SGST / IGST)</label><input name="state" maxlength="60" value="<?= e((string) $old['state']) ?>"></div></div>
