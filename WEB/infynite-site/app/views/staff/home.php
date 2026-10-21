<div class="top"><div><h2 style="margin:0">Hello, <?= e($u['name']) ?></h2><span class="muted"><?= e(ROLES[$u['role']]) ?> · <?= e(ROLE_DESC[$u['role']]) ?></span></div><a class="btn" href="/staff/tasks/new">+ New task</a></div>
<div class="kpi">
  <a class="card" href="/staff/tasks?who=me"><span class="muted">My open tasks</span><b><?= $kpi['mine'] ?></b></a>
  <a class="card" href="/staff/tasks"><span class="muted">Overdue (all)</span><b class="<?= $kpi['overdue'] ? 'overdue' : '' ?>"><?= $kpi['overdue'] ?></b></a>
  <a class="card" href="/staff/tasks?status=blocked"><span class="muted">Blocked</span><b><?= $kpi['blocked'] ?></b></a>
  <a class="card" href="/staff/inventory?low=1"><span class="muted">Low stock items</span><b class="<?= $kpi['lowstock'] ? 'overdue' : '' ?>"><?= $kpi['lowstock'] ?></b></a>
  <?php if (can('requests.view')): ?><a class="card" href="/staff/requests?status=requested"><span class="muted">New orders</span><b><?= $kpi['new'] ?></b></a>
  <?php else: ?><a class="card" href="/staff/kb"><span class="muted">Procedures due for review</span><b><?= $kpi['review'] ?></b></a><?php endif; ?>
</div>
<div class="grid g2" style="align-items:start">
<div class="card"><h3>My tasks</h3><?php if (!$mine): ?><p class="muted">Nothing assigned to you.</p><?php else: ?><div class="tw"><table>
<?php foreach ($mine as $t): ?><tr><td><a href="/staff/tasks/<?= (int) $t['id'] ?>"><?= e($t['title']) ?></a></td><td><?= task_badge($t['status']) ?></td><td class="<?= $t['due_date'] && $t['due_date'] < date('Y-m-d') ? 'overdue' : '' ?>"><?= e(fmt_date($t['due_date'])) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?></div>
<?php if (can('requests.view')): ?><div class="card"><h3>Recent orders</h3><div class="tw"><table>
<?php foreach ($recent as $r): ?><tr><td><a href="/staff/requests/<?= (int) $r['id'] ?>"><?= e($r['ref']) ?></a></td><td><?= e($r['cname']) ?></td><td><?= status_badge($r['status']) ?></td></tr><?php endforeach; ?><?php if (!$recent): ?><tr><td class="muted">No orders yet.</td></tr><?php endif; ?></table></div></div><?php endif; ?>
</div>
