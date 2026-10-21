<section class="block"><div class="wrap narrow"><div class="card"><h2>Create account</h2>
<form method="post" action="/register" autocomplete="on"><?= csrf_field() ?>
  <label for="name">Full name</label><input id="name" name="name" required maxlength="120" value="<?= e($old['name'] ?? '') ?>">
  <label for="email">Email</label><input id="email" name="email" type="email" required maxlength="190" value="<?= e($old['email'] ?? '') ?>">
  <label for="phone">Phone (optional)</label><input id="phone" name="phone" maxlength="30" value="<?= e($old['phone'] ?? '') ?>">
  <label for="password">Password</label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
  <p class="muted" style="font-size:.8rem">At least 10 characters. We will email a verification link.</p>
  <label style="font-weight:400"><input type="checkbox" name="terms" value="1" style="width:auto;margin:0 6px 0 0">I accept the <a href="/terms" target="_blank">Terms</a> and <a href="/privacy" target="_blank">Privacy Policy</a></label><br>
  <button class="btn" style="width:100%">Register</button>
</form><p class="muted mt">Already registered? <a href="/login">Log in</a></p></div></div></section>
