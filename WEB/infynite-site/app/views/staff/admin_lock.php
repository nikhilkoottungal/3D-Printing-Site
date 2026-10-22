<?php if (can('team.manage')) { require __DIR__ . '/admin_tabs.php'; } ?>
<div class="top"><h2 style="margin:0"><?= e($pageTitle ?? 'Admin settings') ?></h2></div>
<div class="card narrow">
<?php if ($hasKey): ?>
<h3>Enter the admin key</h3><p class="muted">Business settings are protected by your personal admin key, in addition to your login.</p>
<form method="post" action="/staff/admin/unlock"><?= csrf_field() ?>
<label for="admin_key">Admin key</label><input id="admin_key" name="admin_key" type="password" required autocomplete="off">
<button class="btn">Unlock</button></form>
<?php else: ?>
<h3>Create your admin key</h3><p class="muted">Every admin has their own key. First use: choose yours of at least 12 characters (a passphrase works well). It is stored only as a hash and cannot be recovered; if lost, an administrator resets it on the server with <code>php tools/reset_admin_key.php</code>.</p>
<form method="post" action="/staff/admin/unlock"><?= csrf_field() ?>
<label for="admin_key">New admin key</label><input id="admin_key" name="admin_key" type="password" required minlength="12" autocomplete="new-password">
<label for="admin_key2">Repeat</label><input id="admin_key2" name="admin_key2" type="password" required minlength="12" autocomplete="new-password">
<button class="btn">Set key and continue</button></form>
<?php endif; ?></div>
