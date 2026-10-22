<div class="top"><h2 style="margin:0">New credit note for <?= e($i['number']) ?></h2><a class="btn alt" href="/staff/invoices/<?= (int) $i['id'] ?>">Cancel</a></div>
<div class="card" style="max-width:640px"><p class="muted"><?= e($i['bill_name']) ?> &middot; invoice total <?= e(money($i['total'])) ?> &middot; GST <?= e(rtrim(rtrim(number_format((float) $i['gst_rate'], 2), '0'), '.')) ?>% &middot; can still credit <?= e(money($room)) ?> before GST.</p>
<form method="post" action="/staff/credit-notes/new"><?= csrf_field() ?><input type="hidden" name="invoice_id" value="<?= (int) $i['id'] ?>">
<div class="row"><div><label>Date</label><input type="date" name="issue_date" required value="<?= e(date('Y-m-d')) ?>"></div><div><label>Amount before GST (Rs.)</label><input name="amount" inputmode="decimal" placeholder="<?= e(number_format($room, 2, '.', '')) ?>"></div></div>
<label style="font-weight:400"><input type="checkbox" name="full" value="1" style="width:auto;margin:0 6px 0 0">Credit everything that is left on the invoice</label><br>
<label>Reason (printed on the credit note)</label><input name="reason" required maxlength="300" placeholder="e.g. Returned item, price correction, rework discount">
<button class="btn" data-confirm="Issue this credit note? It cannot be edited.">Issue credit note</button></form></div>
