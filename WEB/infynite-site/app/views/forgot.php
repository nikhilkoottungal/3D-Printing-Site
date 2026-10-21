<section class="block"><div class="wrap narrow"><div class="card"><h2>Forgot password</h2>
<form method="post" action="/forgot"><?= csrf_field() ?><label for="email">Email</label><input id="email" name="email" type="email" required><button class="btn">Send reset link</button></form></div></div></section>
