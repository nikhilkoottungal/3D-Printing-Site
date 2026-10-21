<?php $today = date('Y-m-d'); ?>
<div class="top"><div><h2 style="margin:0"><?= e($t['title']) ?></h2><span class="muted">#<?= (int) $t['id'] ?> · created by <?= e($t['cname'] ?? '?') ?> on <?= e(fmt_date($t['created_at'])) ?><?= $t['phase'] ? ' · ' . e($t['phase']) : '' ?></span></div><a class="btn alt" href="/staff/tasks">← Tasks</a></div>
<div class="grid g2" style="align-items:start;grid-template-columns:2fr 1fr">
<div>
  <div class="card mb"><h3>Description</h3><?= $t['description'] ? md($t['description']) : '<p class="muted">No description.</p>' ?>
    <?php if ($t['kslug']): ?><p class="muted">Procedure: <a href="/staff/kb/<?= e($t['kslug']) ?>"><?= e($t['ktitle']) ?></a></p><?php endif; ?>
    <?php if ($t['rref']): ?><p class="muted">Order: <a href="/staff/requests/<?= (int) $t['request_id'] ?>"><?= e($t['rref']) ?></a></p><?php endif; ?></div>
  <div class="card" id="comments"><h3>Comments &amp; activity</h3>
  <?php foreach ($comments as $c): ?>
    <?php if ($c['kind'] === 'event'): ?><div class="event">• <?= e($c['uname'] ?? 'System') ?> <?= e(lcfirst($c['body'])) ?> · <?= e(fmt_dt($c['created_at'])) ?></div>
    <?php else: ?><div class="comment"><div class="who"><b><?= e($c['uname'] ?? 'Unknown') ?></b> · <?= e(fmt_dt($c['created_at'])) ?></div><div><?= nl2br(e($c['body'])) ?></div></div><?php endif; ?>
  <?php endforeach; ?>
  <form method="post" action="/staff/tasks/<?= (int) $t['id'] ?>/comment" class="mt"><?= csrf_field() ?><label for="body">Add a comment</label><textarea id="body" name="body" rows="3" required maxlength="5000"></textarea><button class="btn">Post comment</button></form></div>
</div>
<div class="card"><h3>Details</h3>
<?php if ($canEdit): ?>
<form method="post" action="/staff/tasks/<?= (int) $t['id'] ?>"><?= csrf_field() ?>
<label>Status</label><select name="status"><?= options(TASK_STATUSES, $t['status']) ?></select>
<label>Assignee</label><select name="assignee_id"><option value="">Unassigned</option><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $t['assignee_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e(ROLES[$p['role']]) ?>)</option><?php endforeach; ?></select>
<label>Priority</label><select name="priority"><?= options(TASK_PRIORITIES, $t['priority']) ?></select>
<label>Due date</label><input type="date" name="due_date" value="<?= e($t['due_date']) ?>">
<label>Title</label><input name="title" value="<?= e($t['title']) ?>" required maxlength="200">
<label>Description</label><textarea name="description" rows="10"><?= e($t['description']) ?></textarea>
<button class="btn">Save changes</button></form>
<?php else: ?>
<dl class="kv"><dt>Status</dt><dd><?= task_badge($t['status']) ?></dd><dt>Assignee</dt><dd><?= e($t['aname'] ?? 'Unassigned') ?></dd><dt>Priority</dt><dd><?= prio_badge($t['priority']) ?></dd><dt>Due</dt><dd><?= e(fmt_date($t['due_date'])) ?></dd></dl>
<p class="muted" style="font-size:.85rem">Only the assignee, creator or owner can edit. You can still comment.</p><?php endif; ?></div>
</div>
