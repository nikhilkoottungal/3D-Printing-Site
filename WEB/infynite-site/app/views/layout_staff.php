<?php require __DIR__ . '/layout_head.php'; $cu = user(); $nav = $nav ?? '';
$items = [['home','/staff','Overview'],['tasks','/staff/tasks','Tasks'],['inventory','/staff/inventory','Inventory'],['kb','/staff/kb','Knowledge & procedures']];
if (can('requests.view')) { array_splice($items, 1, 0, [['requests','/staff/requests','Orders']]); }
$items[] = ['notes','/staff/notes','Notes']; $items[] = ['calendar','/staff/calendar','Calendar'];
$items[] = ['queue','/staff/queue','Print queue']; $items[] = ['printers','/staff/printers','Printers'];
if (can('requests.view')) { $items[] = ['calc','/staff/calculator','Price calculator']; }
if (can('requests.view')) { $items[] = ['customers','/staff/customers','Customers']; }
if (can('invoices.manage')) { $items[] = ['invoices','/staff/invoices','Invoices']; }
if (can('purchasing.manage')) { $items[] = ['purchasing','/staff/purchase-orders','Suppliers & POs']; }
if (can('reports.view')) { $items[] = ['reports','/staff/reports','Reports']; }
$items[] = ['team','/staff/team','Team & roles'];
if (can('team.manage')) { $items[] = ['backups','/staff/backups','Backups']; $items[] = ['admin','/staff/admin','Admin settings']; } ?>
<div class="app" data-idle-minutes="<?= (int) setting('idle_minutes', 0) ?>">
  <aside class="side">
    <a class="logo" href="/staff" style="padding:0 8px 16px"><i></i>Staff</a>
    <form method="get" action="/staff/search" class="sidesearch"><input name="q" placeholder="Search..." aria-label="Search" maxlength="80"></form>
    <?php foreach ($items as [$k, $href, $label]): ?><a href="<?= $href ?>" class="<?= $nav === $k ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
    <small>You</small>
    <a href="/staff/me" class="<?= $nav === 'me' ? 'on' : '' ?>">My account</a>
    <form method="post" action="/logout"><?= csrf_field() ?><button class="btn link" type="submit">Log out</button></form>
    <div class="userbar" style="display:flex;gap:8px;align-items:center"><?= avatar_tag($cu ?? ['id' => 0, 'name' => '?'], 34) ?><span><?= e($cu['name'] ?? '') ?><br><?= e(ROLES[$cu['role'] ?? ''] ?? '') ?></span></div>
  </aside>
  <div>
    <nav class="mnav"><?php foreach ($items as [$k, $href, $label]): ?><a href="<?= $href ?>"><?= e($label) ?></a><?php endforeach; ?></nav>
    <div class="main"><?php require __DIR__ . '/flashes.php'; ?><?= $content ?></div>
  </div>
</div></body></html>
