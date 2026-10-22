<section class="block"><div class="wrap narrow"><div class="card"><h2>Two-factor code</h2>
<?php if (!empty($errors)): ?><div class="errs"><ul><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<p class="muted">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
<form method="post" action="/login/2fa"><?= csrf_field() ?>
<label for="code">Code</label><input id="code" name="code" required autocomplete="one-time-code" inputmode="numeric" autofocus>
<button class="btn" style="width:100%">Continue</button></form>
<p class="mt"><a class="muted" href="/login">Cancel</a></p></div></div></section>
