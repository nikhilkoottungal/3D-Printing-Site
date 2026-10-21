<section class="block"><div class="wrap narrow"><div class="card"><h2>Set a new password</h2>
<form method="post" action="/reset"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
<label for="password">New password</label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"><button class="btn">Save password</button></form></div></div></section>
