<?php require __DIR__ . '/layout_head.php'; $cu = user(); ?>
<header class="nav"><div class="wrap">
  <a class="logo" href="/"><i></i><?= e(cfg('app_name')) ?></a>
  <nav>
    <a href="/services">Services</a><a href="/materials">Materials</a><a href="/gallery">Gallery</a><a href="/contact">Contact</a>
    <?php if ($cu): ?><a href="<?= is_staff($cu) ? '/staff' : '/account' ?>">My account</a>
    <?php else: ?><a href="/login">Log in</a><a class="btn sm" href="/register">Get a quote</a><?php endif; ?>
  </nav>
</div></header>
<main>
<?php if (empty($home)): ?><div class="wrap" style="padding-top:24px"><?php require __DIR__ . '/flashes.php'; ?></div><?php endif; ?>
<?= $content ?>
</main>
<footer><div class="wrap"><span>© <?= date('Y') ?> <?= e(cfg('app_name')) ?> · infynitesolutions.in</span><span><a href="/privacy">Privacy</a> · <a href="/terms">Terms</a> · <a href="/contact">Contact</a></span></div></footer>
</body></html>
