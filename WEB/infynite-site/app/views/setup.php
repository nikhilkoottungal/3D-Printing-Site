<section class="block"><div class="wrap narrow"><div class="card"><h2>First-time setup</h2>
<p class="muted">Creates the Owner account and loads the starter procedures and implementation tasks. This page disappears once a staff account exists.</p>
<form method="post" action="/setup"><?= csrf_field() ?>
<label>Setup token (from app/config.php)</label><input name="setup_token" type="password" required autocomplete="off">
<label>Your name</label><input name="name" required><label>Email</label><input name="email" type="email" required>
<label>Password (10+ characters)</label><input name="password" type="password" required minlength="10" autocomplete="new-password">
<button class="btn">Create owner &amp; load starter content</button></form></div></div></section>
