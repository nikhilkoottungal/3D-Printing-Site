<div class="top"><h2 style="margin:0">New task</h2><a class="btn alt" href="/staff/tasks">Cancel</a></div>
<div class="card"><form method="post" action="/staff/tasks/new"><?= csrf_field() ?>
<input type="hidden" name="request_id" value="<?= (int) ($old['request_id'] ?? 0) ?>">
<label for="title">Title</label><input id="title" name="title" required maxlength="200" value="<?= e($old['title'] ?? '') ?>">
<label for="description">Description (supports **bold**, lists and - [ ] checklists)</label><textarea id="description" name="description" rows="8"><?= e($old['description'] ?? '') ?></textarea>
<div class="row"><div><label>Assignee</label><select name="assignee_id"><option value="">Unassigned</option><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($old['assignee_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e(ROLES[$p['role']]) ?>)</option><?php endforeach; ?></select></div>
<div><label>Priority</label><select name="priority"><?= options(TASK_PRIORITIES, $old['priority'] ?? 'normal') ?></select></div>
<div><label>Due date</label><input type="date" name="due_date" value="<?= e($old['due_date'] ?? '') ?>"></div>
<div><label>Group (optional)</label><input name="phase" maxlength="40" placeholder="e.g. Phase 2"></div></div>
<button class="btn">Create task</button></form></div>
