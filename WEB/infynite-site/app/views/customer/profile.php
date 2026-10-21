<div class="top"><h2 style="margin:0">Profile &amp; privacy</h2></div>
<div class="grid g2" style="align-items:start">
<div class="card"><h3>Your details</h3>
<form method="post" action="/account/profile"><?= csrf_field() ?>
  <label>Email</label><input value="<?= e($u['email']) ?>" disabled>
  <label for="name">Name</label><input id="name" name="name" value="<?= e($u['name']) ?>" required>
  <label for="phone">Phone</label><input id="phone" name="phone" value="<?= e($u['phone']) ?>">
  <h3 class="mt">Change password</h3>
  <label for="cp">Current password</label><input id="cp" type="password" name="current_password" autocomplete="current-password">
  <label for="np">New password (10+ characters)</label><input id="np" type="password" name="new_password" autocomplete="new-password">
  <button class="btn">Save</button></form></div>
<div class="card"><h3>Your data</h3><p class="muted">Download everything we hold about you, or ask us to delete your account.</p>
  <a class="btn alt" href="/account/export">Download my data (JSON)</a>
  <form method="post" action="/account/delete-request" class="mt"><?= csrf_field() ?><button class="btn danger" data-confirm="Ask us to delete your account and data?">Request account deletion</button></form>
  <p class="muted mt" style="font-size:.85rem">Some records (e.g. invoices) may need to be kept by law. We will confirm what can be removed.</p></div>
</div>
