<section class="block"><div class="wrap narrow"><div class="card"><h2>Log in</h2>
<form method="post" action="/login"><?= csrf_field() ?>
  <label for="email">Email</label><input id="email" name="email" type="email" required autocomplete="username">
  <label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password">
  <button class="btn" style="width:100%">Log in</button>
</form>
<p class="mt"><a class="muted" href="/forgot">Forgot password?</a></p></div></div></section>
