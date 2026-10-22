<?php $__at = ['settings' => ['/staff/admin', 'Settings'], 'team' => ['/staff/team', 'Team & roles'], 'backups' => ['/staff/backups', 'Backups']]; ?>
<div class="tabs" role="navigation" aria-label="Admin settings"><?php foreach ($__at as $k => [$href, $label]): ?><a href="<?= $href ?>" class="<?= ($atab ?? '') === $k ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?></div>
