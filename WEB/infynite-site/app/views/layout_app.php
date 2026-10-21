<?php require __DIR__ . '/layout_head.php'; $cu = user(); $nav = $nav ?? ''; ?>
<div class="app">
  <aside class="side">
    <a class="logo" href="/" style="padding:0 8px 16px"><i></i>Infynite</a>
    <a href="/account" class="<?= $nav === 'dash' ? 'on' : '' ?>">Dashboard</a>
    <a href="/requests/new" class="<?= $nav === 'new' ? 'on' : '' ?>">New order</a>
    <small>Account</small>
    <a href="/account/profile" class="<?= $nav === 'profile' ? 'on' : '' ?>">Profile &amp; privacy</a>
    <form method="post" action="/logout"><?= csrf_field() ?><button class="btn link" type="submit">Log out</button></form>
    <div class="userbar"><?= e($cu['name'] ?? '') ?></div>
  </aside>
  <div>
    <nav class="mnav"><a href="/account">Dashboard</a><a href="/requests/new">New order</a><a href="/account/profile">Profile</a></nav>
    <div class="main"><?php require __DIR__ . '/flashes.php'; ?><?= $content ?></div>
  </div>
</div></body></html>
