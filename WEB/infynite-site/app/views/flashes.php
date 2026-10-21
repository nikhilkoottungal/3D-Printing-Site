<?php foreach (take_flashes() as [$ft, $fm]): ?><div class="flash <?= e($ft) ?>" role="status"><?= e($fm) ?></div><?php endforeach; ?>
<?php if (!empty($errors)): ?><div class="errs" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
