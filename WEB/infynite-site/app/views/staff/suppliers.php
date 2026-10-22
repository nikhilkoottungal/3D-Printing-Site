<?php $ptab = 'suppliers'; require __DIR__ . '/purch_tabs.php'; ?>
<div class="top"><div><h2 style="margin:0">Suppliers</h2></div><a class="btn" href="/staff/suppliers/new">+ New supplier</a></div>
<div class="card"><div class="tw"><table><tr><th>Name</th><th>Contact</th><th>Phone</th><th>GSTIN</th><th class="right">POs</th><th class="right">Received value</th><th></th></tr>
<?php foreach ($rows as $r): ?><tr><td><a href="/staff/suppliers/<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td><td><?= e((string) $r['contact']) ?></td><td><?= e((string) $r['phone']) ?></td><td><?= e((string) $r['gstin']) ?></td><td class="right"><?= (int) $r['pos'] ?></td><td class="right"><?= e(money($r['spent'])) ?></td><td><?= $r['active'] ? '' : badge('Inactive') ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="7" class="muted">No suppliers yet.</td></tr><?php endif; ?></table></div></div>
