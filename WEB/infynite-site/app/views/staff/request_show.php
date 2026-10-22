<div class="top"><div><h2 style="margin:0"><?= e($r['ref']) ?> <?= status_badge($r['status']) ?><?= $r['source'] === 'direct' ? ' ' . badge('Direct order') : '' ?></h2><span class="muted"><?= e($r['cname']) ?> · <?= e($r['cemail']) ?><?= $r['cphone'] ? ' · ' . e($r['cphone']) : '' ?></span></div><a class="btn alt" href="/staff/requests">← Back</a></div>
<div class="grid g2" style="align-items:start">
<div>
  <div class="card mb"><h3>Job</h3><dl class="kv"><dt>Material</dt><dd><?= e($r['material']) ?></dd><dt>Colour</dt><dd><?= e($r['color']) ?></dd><dt>Quantity</dt><dd><?= (int) $r['quantity'] ?></dd><dt>Notes</dt><dd><?= $r['notes'] ? nl2br(e($r['notes'])) : '—' ?></dd><dt>Quote</dt><dd><?= e(money($r['quote_amount'])) ?><?= $r['quote_note'] ? ' · ' . e($r['quote_note']) : '' ?></dd></dl>
  <h3>Model files</h3><div class="tw"><table><?php foreach ($files as $f): ?><tr><td><?= e($f['orig_name']) ?></td><td><?= e(fmt_bytes((int) $f['size_bytes'])) ?></td><td><a href="/files/<?= (int) $f['id'] ?>">Download</a><?= preg_match('/\.stl$/i', $f['orig_name']) ? ' &middot; <a href="/staff/files/' . (int) $f['id'] . '/view">Preview</a>' : '' ?></td></tr><?php endforeach; ?></table></div></div>
  <div class="card"><h3>History</h3><?php foreach (array_reverse($hist) as $h): ?><div class="comment"><?= status_badge($h['status']) ?> <span class="who"><?= e(fmt_dt($h['at'])) ?> · <?= e($h['uname'] ?? 'system') ?></span><?php if ($h['note']): ?><br><?= e($h['note']) ?><?php endif; ?></div><?php endforeach; ?></div>
</div>
<div>
  <?php if (can('requests.quote')): ?><div class="card mb"><h3>Record quote</h3>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="quote">
    <label>Amount (₹)</label><input name="amount" inputmode="decimal" required value="<?= e($r['quote_amount']) ?>">
    <label>Quote note (what is included, lead time)</label><textarea name="quote_note" rows="3"><?= e($r['quote_note']) ?></textarea>
    <button class="btn" data-confirm="Record this quote? Tell the customer the price yourself.">Record quote</button></form></div><?php endif; ?>
  <?php require __DIR__ . '/order_panels.php'; ?>
  <?php if (can('invoices.manage')): invoice_ensure_tables(); $invs = all('SELECT id, number, status, total FROM is_invoices WHERE request_id=? ORDER BY id', [$r['id']]); ?><div class="card mb"><h3>Invoices</h3>
    <?php foreach ($invs as $iv): ?><div class="comment"><a href="/staff/invoices/<?= (int) $iv['id'] ?>"><?= e($iv['number']) ?></a> · <?= e(money($iv['total'])) ?> · <?= e(INVOICE_STATUSES[$iv['status']]) ?></div><?php endforeach; if (!$invs): ?><p class="muted">None yet.</p><?php endif; ?>
    <a class="btn alt sm" href="/staff/invoices/new?request=<?= (int) $r['id'] ?>">Create invoice</a></div><?php endif; ?>
  <div class="card mb"><h3>Change status</h3><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status">
    <label>New status</label><select name="status"><?php foreach (STATUSES as $k => $l): if (can(STATUS_PERM[$k]) && $k !== $r['status']): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endif; endforeach; ?></select>
    <label>Note (kept in the order history)</label><input name="note" maxlength="500"><button class="btn">Update status</button></form></div>
  <div class="card"><h3>Tasks for this order</h3>
    <?php foreach ($tasks as $t): ?><div class="comment"><a href="/staff/tasks/<?= (int) $t['id'] ?>"><?= e($t['title']) ?></a> <?= task_badge($t['status']) ?><br><span class="who"><?= e($t['aname'] ?? 'unassigned') ?></span></div><?php endforeach; ?>
    <form method="post" class="mt"><?= csrf_field() ?><input type="hidden" name="action" value="task">
    <label>New task</label><input name="title" placeholder="e.g. Slice and schedule" required>
    <select name="assignee"><option value="">Unassigned</option><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select><button class="btn alt sm">Add task</button></form></div>
</div></div>
