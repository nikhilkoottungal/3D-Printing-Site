<?php $idx = array_search($r['status'], STATUS_FLOW, true); ?>
<div class="top"><div><h2 style="margin:0"><?= e($r['ref']) ?> <?= status_badge($r['status']) ?></h2><span class="muted"><?= e($r['material']) ?> · <?= e($r['color']) ?> · ×<?= (int) $r['quantity'] ?> · submitted <?= e(fmt_date($r['created_at'])) ?></span></div><a class="btn alt" href="/account">← Back</a></div>
<?php if ($idx !== false): ?><div class="card mb"><div class="flow"><?php foreach (STATUS_FLOW as $i => $s): ?><div class="<?= $i < $idx ? 'done' : ($i === $idx ? 'now' : '') ?>"><?= e(STATUSES[$s]) ?></div><?php endforeach; ?></div></div><?php endif; ?>
<?php if ($r['status'] === 'quoted'): ?>
<div class="card mb"><h3>Your quote: <?= e(money($r['quote_amount'])) ?></h3><?php if ($r['quote_note']): ?><p><?= nl2br(e($r['quote_note'])) ?></p><?php endif; ?>
<form class="inline" method="post" action="/requests/<?= (int) $r['id'] ?>/approve"><?= csrf_field() ?><input type="hidden" name="quote_amount" value="<?= e($r['quote_amount']) ?>"><button class="btn" data-confirm="Approve this quote and start the job?">Approve quote</button></form>
<form class="inline" method="post" action="/requests/<?= (int) $r['id'] ?>/cancel"><?= csrf_field() ?><button class="btn alt" data-confirm="Cancel this request?">Decline / cancel order</button></form></div>
<?php elseif (in_array($r['status'], ['requested', 'under_review'], true)): ?>
<form method="post" action="/requests/<?= (int) $r['id'] ?>/cancel" class="mb"><?= csrf_field() ?><button class="btn alt sm" data-confirm="Cancel this request?">Cancel order</button></form><?php endif; ?>
<div class="grid g2" style="align-items:start">
  <div class="card"><h3>Files</h3><div class="tw"><table><?php foreach ($files as $f): ?><tr><td><?= e($f['orig_name']) ?></td><td><?= e(fmt_bytes((int) $f['size_bytes'])) ?></td><td><a href="/files/<?= (int) $f['id'] ?>">Download</a></td></tr><?php endforeach; ?></table></div>
    <?php if ($r['notes']): ?><h3 class="mt">Your notes</h3><p><?= nl2br(e($r['notes'])) ?></p><?php endif; ?></div>
  <div class="card"><h3>History</h3><?php foreach (array_reverse($hist) as $h): ?><div class="comment"><?= status_badge($h['status']) ?> <span class="who"><?= e(fmt_dt($h['at'])) ?></span><?php if ($h['note']): ?><br><?= e($h['note']) ?><?php endif; ?></div><?php endforeach; ?></div>
</div>
