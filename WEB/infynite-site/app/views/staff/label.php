<div class="top noprint"><a class="btn alt sm" href="/staff/requests/<?= (int) $r['id'] ?>">&larr; Order</a><button class="btn" data-print>Print label</button></div>
<div class="inv" style="max-width:420px;text-align:center"><div style="display:inline-block"><?= $qr ?></div>
<h2 style="margin:8px 0 2px;font-size:1.8rem"><?= e($r['ref']) ?></h2><p style="margin:0 0 8px"><b><?= e($r['cname']) ?></b></p>
<p style="margin:0"><?= e($r['material']) ?> &middot; <?= e($r['color']) ?> &middot; &times;<?= (int) $r['quantity'] ?></p>
<?php if ($r['due_date']): ?><p style="margin:6px 0 0"><b>Due <?= e(fmt_date($r['due_date'])) ?></b></p><?php endif; ?>
<p style="margin:10px 0 0;font-size:.8rem;color:#555">Scan to open the order (office network)</p></div>
