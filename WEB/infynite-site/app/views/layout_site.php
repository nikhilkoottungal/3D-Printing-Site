<?php require __DIR__ . '/layout_head.php'; $cu = user(); ?>
<header class="nav"><div class="wrap">
  <a class="logo" href="/"><i></i><?= e(setting('app_name', cfg('app_name'))) ?></a>
  <nav><?php if ($cu): ?><a href="/staff">Workspace</a><?php else: ?><a href="/login">Log in</a><?php endif; ?></nav>
</div></header>
<main>
<div class="wrap" style="padding-top:24px"><?php require __DIR__ . '/flashes.php'; ?></div>
<?= $content ?>
</main>
<footer><div class="wrap"><span>© <?= date('Y') ?> <?= e(setting('app_name', cfg('app_name'))) ?> · internal system</span></div></footer>
</body></html>
