<div class="top"><div><h2 style="margin:0">Price calculator</h2><span class="muted">Rates are set by the Owner in Admin settings &gt; Pricing<?= $order ? ' &middot; for order ' . e($order['ref']) . ' (' . e($order['cname']) . ')' : '' ?>.</span></div><?php if ($order): ?><a class="btn alt" href="/staff/requests/<?= (int) $order['id'] ?>">&larr; Order</a><?php endif; ?></div>
<div class="grid g2" style="align-items:start">
<div class="card"><form method="get" action="/staff/calculator"><input type="hidden" name="go" value="1"><?php if ($order): ?><input type="hidden" name="order" value="<?= (int) $order['id'] ?>"><?php endif; ?>
<label for="cm">Material</label><select id="cm" name="material"><?= options(array_combine(array_keys(MATERIAL_KEYS), array_keys(MATERIAL_KEYS)), $in['material']) ?></select>
<div class="row"><div><label for="cg">Grams of material per item</label><input id="cg" name="grams" inputmode="decimal" value="<?= e($in['grams'] ?: '') ?>"></div><div><label for="ch">Print hours per item</label><input id="ch" name="hours" inputmode="decimal" value="<?= e($in['hours'] ?: '') ?>"></div></div>
<div class="row"><div><label for="cq">Quantity</label><input id="cq" name="qty" inputmode="numeric" value="<?= (int) $in['qty'] ?>"></div><div><label for="cp">Post-processing per item (Rs.)</label><input id="cp" name="post" inputmode="decimal" value="<?= e($in['post'] ?: '') ?>"></div></div>
<label style="font-weight:400"><input type="checkbox" name="rush" value="1" style="width:auto;margin:0 6px 0 0" <?= $in['rush'] ? 'checked' : '' ?>>Rush order (adds the rush surcharge)</label><br>
<button class="btn">Calculate</button></form></div>
<div class="card"><h3>Result</h3>
<?php if (!$res): ?><p class="muted">Enter the job and press Calculate.</p><?php else: ?>
<?php if ($res['rate_missing']): ?><div class="errs">No price per gram is set for <?= e($in['material']) ?>. Ask the Owner to fill in Admin settings &gt; Pricing.</div><?php endif; ?>
<dl class="kv"><dt>Material</dt><dd><?= e(money($res['material'])) ?> <span class="muted">per item (<?= e(number_format($res['rate'], 2)) ?>/g incl. wastage)</span></dd>
<dt>Machine time</dt><dd><?= e(money($res['machine'])) ?> per item</dd><dt>Post-processing</dt><dd><?= e(money($res['post'])) ?> per item</dd>
<dt>Per item cost</dt><dd><?= e(money($res['per_item'])) ?></dd><dt>Setup fee</dt><dd><?= e(money($res['setup'])) ?></dd><dt>Cost for <?= (int) $in['qty'] ?></dt><dd><?= e(money($res['subtotal'])) ?></dd>
<dt>Margin <?= e(rtrim(rtrim(number_format($res['margin_pct'], 2), '0'), '.')) ?>%</dt><dd><?= e(money($res['margin'])) ?></dd><?php if ($in['rush']): ?><dt>Rush <?= e(rtrim(rtrim(number_format($res['rush_pct'], 2), '0'), '.')) ?>%</dt><dd><?= e(money($res['rush'])) ?></dd><?php endif; ?>
<?php if ($res['min_applied']): ?><dt>Minimum order</dt><dd>raised to <?= e(money($res['min'])) ?></dd><?php endif; ?></dl>
<p style="font-size:1.4rem;margin:8px 0"><b><?= e(money($res['price'])) ?></b> <span class="muted" style="font-size:.9rem">before GST &middot; <?= e(money($res['unit'])) ?> each</span></p>
<?php if ($order && can('requests.quote')): ?><form method="post" action="/staff/requests/<?= (int) $order['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="quote"><input type="hidden" name="amount" value="<?= e(number_format($res['price'], 2, '.', '')) ?>"><input type="hidden" name="quote_note" value="<?= e((int) $in['qty'] . ' x ' . $in['material'] . ', about ' . $in['grams'] . ' g and ' . $in['hours'] . ' h each') ?>">
<button class="btn" data-confirm="Record this price as the quote for the order?">Use as quote for <?= e($order['ref']) ?></button></form><?php endif; ?>
<?php endif; ?></div></div>
