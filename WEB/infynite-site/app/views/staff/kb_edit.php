<?php $isNew = empty($a['slug']); ?>
<div class="top"><h2 style="margin:0"><?= $isNew ? 'New article' : 'Edit article' ?></h2><a class="btn alt" href="<?= $isNew ? '/staff/kb' : '/staff/kb/' . e($a['slug']) ?>">Cancel</a></div>
<div class="card"><form method="post" action="<?= $isNew ? '/staff/kb/new' : '/staff/kb/' . e($a['slug']) . '/edit' ?>"><?= csrf_field() ?>
<label for="title">Title</label><input id="title" name="title" required maxlength="200" value="<?= e($a['title']) ?>">
<div class="row"><div><label>Category</label><input name="category" list="cats" maxlength="60" value="<?= e($a['category']) ?>"><datalist id="cats"><?php foreach ($cats as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></div>
<div><label>Type</label><select name="kind"><option value="procedure" <?= $a['kind'] === 'procedure' ? 'selected' : '' ?>>Procedure (steps to follow)</option><option value="knowledge" <?= $a['kind'] === 'knowledge' ? 'selected' : '' ?>>Knowledge (reference)</option></select></div>
<div><label>Review by (optional)</label><input type="date" name="review_due" value="<?= e($a['review_due']) ?>"></div></div>
<label for="body">Content: # headings, **bold**, - lists, 1. steps, - [ ] checklists, [text](https://link)</label>
<textarea id="body" name="body" rows="22" required style="font-family:ui-monospace,monospace"><?= e($a['body']) ?></textarea>
<?php if (!$isNew): ?><label>What did you change? (saved in history)</label><input name="note" maxlength="200"><?php endif; ?>
<button class="btn">Save</button></form></div>
