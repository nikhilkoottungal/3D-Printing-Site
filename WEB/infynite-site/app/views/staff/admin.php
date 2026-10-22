<?php if (can('team.manage')) { require __DIR__ . '/admin_tabs.php'; } ?>
<div class="top"><div><h2 style="margin:0">Admin settings</h2><span class="muted">Unlocked; locks after 30 seconds without activity, or as soon as you switch to another browser tab.</span></div>
<form method="post" action="/staff/admin/lock" class="inline"><?= csrf_field() ?><button class="btn alt">Lock now</button></form></div>
<form method="post" action="/staff/admin/save"><?= csrf_field() ?>
<?php foreach (SETTING_GROUPS as $group => $fields): ?>
<div class="card mb settings"><h3><?= e($group) ?></h3><div class="setgrid">
<?php foreach ($fields as $k => $def): [$label, $type, $max] = $def; $v = $vals[$k] ?? ($k === 'app_name' ? cfg('app_name') : ($k === 'mail_mode' ? 'log' : ($k === 'smtp_port' ? '587' : ($k === 'smtp_enc' ? 'tls' : '')))); ?>
<div class="sf<?= $type === 'textarea' ? ' wide' : '' ?>"><label for="s_<?= e($k) ?>"><?= e($label) ?></label>
<?php if ($type === 'textarea'): ?><textarea id="s_<?= e($k) ?>" name="<?= e($k) ?>" rows="2" maxlength="<?= (int) $max ?>"><?= e($v) ?></textarea>
<?php elseif ($type === 'select'): ?><select id="s_<?= e($k) ?>" name="<?= e($k) ?>"><?= options($def[3], $v) ?></select>
<?php elseif ($type === 'secret'): ?><input id="s_<?= e($k) ?>" name="<?= e($k) ?>" type="password" autocomplete="new-password" maxlength="<?= (int) $max ?>" placeholder="<?= !empty($vals[$k]) ? '(saved; leave blank to keep)' : '' ?>">
<?php else: ?><input id="s_<?= e($k) ?>" name="<?= e($k) ?>" maxlength="<?= (int) $max ?>" value="<?= e($v) ?>"><?php endif; ?>
</div><?php endforeach; ?></div></div>
<?php endforeach; ?>
<button class="btn">Save settings</button></form>
<div class="card mt"><h3>Send a test e-mail</h3><p class="muted">Save the e-mail settings first, then send a test to check they work.</p>
<form method="post" action="/staff/admin/test-mail"><?= csrf_field() ?><div class="row"><div><label for="tm_to">Send to</label><input id="tm_to" name="to" value="<?= e($u['email']) ?>"></div></div><button class="btn alt">Send test e-mail</button></form></div>
<div class="card mt"><h3>Integrations and off-site copies</h3><p class="muted">Send backups, model files, the knowledge base and the source code to Google Drive or an FTP/SFTP server.</p><a class="btn alt" href="/staff/admin/integrations">Integrations</a></div>
<div class="card mt"><h3>Notifications</h3><p class="muted">Choose who receives which e-mail alert (new orders, low stock, overdue invoices and more).</p><a class="btn alt" href="/staff/admin/notifications">Notification rules</a></div>
<div class="card mt"><h3>Change my admin key</h3><p class="muted">Each admin has their own key. When you change yours, the other admins are told by e-mail.</p>
<form method="post" action="/staff/admin/key"><?= csrf_field() ?>
<div class="row"><div><label>Current key</label><input name="current" type="password" required autocomplete="off"></div><div><label>New key (12+ chars)</label><input name="new1" type="password" required minlength="12" autocomplete="new-password"></div><div><label>Repeat new key</label><input name="new2" type="password" required minlength="12" autocomplete="new-password"></div></div>
<button class="btn alt">Change key</button></form></div>
