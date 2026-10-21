<?php require __DIR__ . '/layout_head.php'; $cu = user(); $nav = $nav ?? '';
$items = [['home','/staff','Overview'],['tasks','/staff/tasks','Tasks'],['inventory','/staff/inventory','Inventory'],['kb','/staff/kb','Knowledge & procedures']];
if (can('requests.view')) { array_splice($items, 1, 0, [['requests','/staff/requests','Orders']]); }
$items[] = ['team','/staff/team','Team & roles'];
if (can('team.manage')) { $items[] = ['backups','/staff/backups','Backups']; } ?>
<div class="app">
  <aside class="side">
    <a class="logo" href="/staff" style="padding:0 8px 16px"><i></i>Staff</a>
    <?php foreach ($items as [$k, $href, $label]): ?><a href="<?= $href ?>" class="<?= $nav === $k ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
    <?php if (cfg('dolibarr.ui_url')): ?><small>ERP</small><a href="<?= e(cfg('dolibarr.ui_url')) ?>" target="_blank" rel="noopener">Open Dolibarr ↗</a><?php endif; ?>
    <small>You</small>
    <form method="post" action="/logout"><?= csrf_field() ?><button class="btn link" type="submit">Log out</button></form>
    <div class="userbar"><?= e($cu['name'] ?? '') ?><br><?= e(ROLES[$cu['role'] ?? ''] ?? '') ?></div>
  </aside>
  <div>
    <nav class="mnav"><?php foreach ($items as [$k, $href, $label]): ?><a href="<?= $href ?>"><?= e($label) ?></a><?php endforeach; ?></nav>
    <div class="main"><?php require __DIR__ . '/flashes.php'; ?><?= $content ?></div>
  </div>
</div></body></html>
