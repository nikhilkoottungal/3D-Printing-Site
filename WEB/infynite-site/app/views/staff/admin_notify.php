<div class="top"><div><h2 style="margin:0">Notification rules</h2><span class="muted"><a href="/staff/admin">&larr; Admin settings</a> &middot; Tick who receives each alert by e-mail. People are never told about their own actions.</span></div></div>
<?php if (!$smtp): ?><div class="errs">E-mail is in "Log only" mode, so these alerts are written to the mail log and not sent. Switch to SMTP in Admin settings.</div><?php endif; ?>
<form method="post" action="/staff/admin/notifications"><?= csrf_field() ?>
<div class="card"><div class="tw"><table><tr><th>Alert</th><?php foreach ($people as $p): ?><th><?= e($p['name']) ?><br><span class="muted" style="text-transform:none;letter-spacing:0"><?= e(ROLES[$p['role']]) ?></span></th><?php endforeach; ?></tr>
<?php foreach (NOTIFY_EVENTS as $ev => $label): ?><tr><td><?= e($label) ?></td><?php foreach ($people as $p): ?><td><input type="checkbox" name="n[<?= e($ev) ?>][]" value="<?= (int) $p['id'] ?>" aria-label="<?= e($p['name'] . ': ' . $label) ?>" style="width:auto;margin:0" <?= !empty($rules[$ev][(int) $p['id']]) ? 'checked' : '' ?>></td><?php endforeach; ?></tr>
<?php endforeach; ?></table></div></div>
<p class="muted mt">Task assignments and comments are always sent to the person assigned. Security notices (admin key changes, admin removal requests) always go to all admins.</p>
<button class="btn mt">Save rules</button></form>
