<?php function tq(array $over): string { return '/staff/tasks?' . http_build_query(array_merge($_GET, $over)); } $today = date('Y-m-d'); ?>
<div class="top"><h2 style="margin:0">Tasks</h2><div><a class="btn alt" href="/staff/recurring">Recurring</a> <a class="btn alt" href="/staff/calendar">Calendar</a> <a class="btn" href="/staff/tasks/new">+ New task</a></div></div>
<form class="filters" method="get"><input type="hidden" name="view" value="<?= e($view) ?>">
  <div><label>Assignee</label><select name="who" data-autosubmit><option value="all">Everyone</option><option value="me" <?= $who === 'me' ? 'selected' : '' ?>>Me</option><option value="none" <?= $who === 'none' ? 'selected' : '' ?>>Unassigned</option><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $who === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>Status</label><select name="status" data-autosubmit><option value="open" <?= $st === 'open' ? 'selected' : '' ?>>All open</option><option value="all" <?= $st === 'all' ? 'selected' : '' ?>>Including done</option><?= options(TASK_STATUSES, $st) ?></select></div>
  <div><label>Phase / group</label><select name="phase" data-autosubmit><option value="">Any</option><?= options($phases, $phase) ?></select></div>
  <div class="tabs" style="margin:0"><a href="<?= e(tq(['view' => 'list'])) ?>" class="<?= $view === 'list' ? 'on' : '' ?>">List</a><a href="<?= e(tq(['view' => 'board'])) ?>" class="<?= $view === 'board' ? 'on' : '' ?>">Board</a></div>
</form>
<?php if ($view === 'board'): $cols = array_fill_keys(array_keys(TASK_STATUSES), []); foreach ($rows as $t) { $cols[$t['status']][] = $t; } ?>
<div class="board" style="grid-template-columns:repeat(5,minmax(210px,1fr))"><?php foreach (TASK_STATUSES as $k => $l): ?><div class="col"><h3><?= e($l) ?> (<?= count($cols[$k]) ?>)</h3>
<?php foreach ($cols[$k] as $t): ?><a class="job" style="display:block" href="/staff/tasks/<?= (int) $t['id'] ?>"><b><?= e($t['title']) ?></b><br><span class="muted"><?= e($t['aname'] ?? 'Unassigned') ?></span> <?= prio_badge($t['priority']) ?><?php if ($t['due_date']): ?><br><span class="<?= $t['due_date'] < $today && $t['status'] !== 'done' ? 'overdue' : 'muted' ?>">Due <?= e(fmt_date($t['due_date'])) ?></span><?php endif; ?></a><?php endforeach; ?></div><?php endforeach; ?></div>
<?php else: ?>
<div class="card"><div class="tw"><table><tr><th>Task</th><th>Assignee</th><th>Status</th><th>Priority</th><th>Due</th><th>Group</th></tr>
<?php foreach ($rows as $t): ?><tr><td><a href="/staff/tasks/<?= (int) $t['id'] ?>"><?= e($t['title']) ?></a></td><td><?= e($t['aname'] ?? '—') ?></td><td><?= task_badge($t['status']) ?></td><td><?= prio_badge($t['priority']) ?></td><td class="<?= $t['due_date'] && $t['due_date'] < $today && $t['status'] !== 'done' ? 'overdue' : '' ?>"><?= e(fmt_date($t['due_date'])) ?></td><td><?= e($t['phase'] ?? '') ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">No tasks match.</td></tr><?php endif; ?></table></div></div>
<?php endif; ?>
