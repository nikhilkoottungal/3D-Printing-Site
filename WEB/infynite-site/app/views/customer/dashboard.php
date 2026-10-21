<?php $open = array_filter($reqs, fn($r) => !in_array($r['status'], ['completed', 'cancelled', 'failed'], true));
$awaiting = array_filter($reqs, fn($r) => $r['status'] === 'quoted'); $quota = (int) cfg('upload.quota_bytes'); ?>
<div class="top"><div><h2 style="margin:0">Welcome, <?= e($u['name']) ?></h2><span class="muted">Everything here is visible only to you.</span></div><a class="btn" href="/requests/new">+ New order</a></div>
<div class="kpi">
  <div class="card"><span class="muted">Awaiting your approval</span><b><?= count($awaiting) ?></b></div>
  <div class="card"><span class="muted">Open orders</span><b><?= count($open) ?></b></div>
  <div class="card"><span class="muted">All orders</span><b><?= count($reqs) ?></b></div>
  <div class="card"><span class="muted">Storage used</span><b><?= e(fmt_bytes($usage)) ?></b><div class="bar"><i style="width:<?= min(100, (int) round($usage / max(1, $quota) * 100)) ?>%"></i></div></div>
</div>
<div class="card"><h3>Your orders</h3>
<?php if (!$reqs): ?><p class="muted">No orders yet. <a href="/requests/new">Upload your first model.</a></p><?php else: ?>
<div class="tw"><table><tr><th>Ref</th><th>Material</th><th>Qty</th><th>Quote</th><th>Status</th><th>Updated</th><th></th></tr>
<?php foreach ($reqs as $r): ?><tr><td><a href="/requests/<?= (int) $r['id'] ?>"><?= e($r['ref']) ?></a></td><td><?= e($r['material']) ?> / <?= e($r['color']) ?></td><td><?= (int) $r['quantity'] ?></td><td><?= e(money($r['quote_amount'])) ?></td><td><?= status_badge($r['status']) ?></td><td><?= e(fmt_date($r['updated_at'])) ?></td><td><a class="btn sm <?= $r['status'] === 'quoted' ? '' : 'alt' ?>" href="/requests/<?= (int) $r['id'] ?>"><?= $r['status'] === 'quoted' ? 'Review quote' : 'View' ?></a></td></tr><?php endforeach; ?></table></div><?php endif; ?></div>
