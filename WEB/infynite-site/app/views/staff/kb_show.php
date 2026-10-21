<div class="top"><div><h2 style="margin:0"><?= e($a['title']) ?></h2><span class="muted"><?= e($a['category']) ?> · <?= $a['kind'] === 'procedure' ? 'Procedure' : 'Knowledge' ?> · v<?= (int) $a['version'] ?> · updated <?= e(fmt_dt($a['updated_at'])) ?> by <?= e($a['uname'] ?? '?') ?><?= $a['review_due'] ? ' · review by ' . e(fmt_date($a['review_due'])) : '' ?></span></div>
<div><a class="btn alt sm" href="/staff/kb">← All</a> <a class="btn sm" href="/staff/kb/<?= e($a['slug']) ?>/edit">Edit</a> <a class="btn alt sm" href="/staff/kb/<?= e($a['slug']) ?>/history">History</a></div></div>
<div class="grid g2" style="align-items:start;grid-template-columns:2fr 1fr">
<div class="card"><?= md($a['body']) ?></div>
<div>
<div class="card mb"><h3>Use this procedure</h3><p class="muted" style="font-size:.9rem">Creates a task with the checklist copied in, so it can be assigned, tracked and commented.</p>
<form method="post" action="/staff/kb/<?= e($a['slug']) ?>/task"><?= csrf_field() ?>
<label>Reference (optional, e.g. IS-0142)</label><input name="reference" maxlength="80">
<label>Assign to</label><select name="assignee_id"><option value="">Unassigned</option><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
<label>Due</label><input type="date" name="due_date"><button class="btn">Create task</button></form></div>
<div class="card"><h3>Recent tasks using it</h3><?php foreach ($linked as $t): ?><div class="comment"><a href="/staff/tasks/<?= (int) $t['id'] ?>"><?= e($t['title']) ?></a> <?= task_badge($t['status']) ?><br><span class="who"><?= e($t['aname'] ?? 'unassigned') ?></span></div><?php endforeach; if (!$linked): ?><p class="muted">None yet.</p><?php endif; ?></div>
<?php if (can('team.manage')): ?><form method="post" action="/staff/kb/<?= e($a['slug']) ?>/delete" class="mt"><?= csrf_field() ?><button class="btn danger sm" data-confirm="Delete this article and its history?">Delete article</button></form><?php endif; ?>
</div></div>
