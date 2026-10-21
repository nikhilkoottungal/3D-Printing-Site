<?php $owner = can('team.manage'); ?>
<div class="top"><div><h2 style="margin:0">Team &amp; roles</h2><span class="muted"><?= (int) $customers ?> registered customers</span></div></div>
<?php if ($link): ?><div class="card mb"><h3>Password-setup link (shown once)</h3><p class="muted">Send this to the new person if the email does not arrive. Valid 2 hours; treat it as a secret.</p><input readonly value="<?= e($link) ?>"></div><?php endif; ?>
<div class="card mb"><h3>People</h3><div class="tw"><table><tr><th>Name</th><th>Email</th><th>Role</th><th>Open tasks</th><th>Active</th><th></th></tr>
<?php foreach ($people as $p): ?>
<?php if ($owner): $fid = 'tf' . (int) $p['id']; ?><tr><td><?= e($p['name']) ?><form id="<?= $fid ?>" method="post" action="/staff/team/<?= (int) $p['id'] ?>"><?= csrf_field() ?></form></td><td><?= e($p['email']) ?></td>
<td><select name="role" form="<?= $fid ?>" style="margin:0"><?= options(ROLES, $p['role']) ?></select></td><td><a href="/staff/tasks?who=<?= (int) $p['id'] ?>"><?= (int) $p['open_tasks'] ?></a></td>
<td><input type="checkbox" name="active" value="1" form="<?= $fid ?>" <?= $p['active'] ? 'checked' : '' ?> style="width:auto;margin:0"></td><td><button form="<?= $fid ?>" class="btn sm alt">Save</button></td></tr>
<?php else: ?><tr><td><?= e($p['name']) ?></td><td><?= e($p['email']) ?></td><td><?= e(ROLES[$p['role']]) ?></td><td><?= (int) $p['open_tasks'] ?></td><td><?= $p['active'] ? 'Yes' : 'No' ?></td><td></td></tr><?php endif; ?>
<?php endforeach; ?></table></div></div>
<div class="grid g2" style="align-items:start">
<div class="card"><h3>What each role can do</h3><dl class="kv"><?php foreach (ROLES as $k => $l): ?><dt><?= e($l) ?></dt><dd><?= e(ROLE_DESC[$k]) ?></dd><?php endforeach; ?></dl>
<p class="muted" style="font-size:.85rem">Everyone on staff can read and edit procedures, see all tasks, comment, and create tasks. Task editing is limited to the assignee, creator and owner. Keep roles least-privilege.</p></div>
<?php if ($owner): ?><div class="card"><h3>Add a staff member</h3><form method="post" action="/staff/team/new"><?= csrf_field() ?>
<label>Name</label><input name="name" required><label>Email</label><input name="email" type="email" required>
<label>Role</label><select name="role"><?= options(ROLES, 'production') ?></select><button class="btn">Create &amp; send setup link</button></form></div><?php endif; ?>
</div>
