<?php require __DIR__ . '/layout_head.php'; $cu = user(); $nav = $nav ?? '';
$groups = [   // sidebar boxes; empty ones (no permission) are skipped
    'home' => [['home','/staff','Overview']],
    'sales' => can('requests.view') ? [['requests','/staff/requests','Orders'], ['customers','/staff/customers','Customers'], ['calc','/staff/calculator','Price calculator']] : [],
    'work' => [['calendar','/staff/calendar','Calendar'], ['tasks','/staff/tasks','Tasks'], ['notes','/staff/notes','Notes'], ['kb','/staff/kb','Knowledge & procedures']],
    'stock' => array_merge([['inventory','/staff/inventory','Inventory']], can('purchasing.manage') ? [['purchasing','/staff/purchase-orders','Suppliers & POs']] : []),
    'print' => [['printers','/staff/printers','Printers'], ['queue','/staff/queue','Print queue']],
    'money' => array_merge(can('invoices.manage') ? [['invoices','/staff/invoices','Invoices']] : [], can('reports.view') ? [['reports','/staff/reports','Reports']] : []),
    'admin' => can('team.manage') ? [['admin','/staff/admin','Admin settings']] : [['team','/staff/team','Team & roles']],   // owner: Settings, Team & roles and Backups are tabs inside it
];
$groups = array_filter($groups);
$items = array_merge(...array_values($groups)); ?>
<div class="app" data-idle-minutes="<?= (int) setting('idle_minutes', 0) ?>">
  <aside class="side">
    <a class="logo" href="/staff"><i></i><?= e((string) setting('app_name', cfg('app_name'))) ?></a>
    <form method="get" action="/staff/search" class="sidesearch"><input name="q" placeholder="Search..." aria-label="Search" maxlength="80"></form>
    <?php foreach ($groups as $g): ?><div class="sidebox"><?php foreach ($g as [$k, $href, $label]): ?><a href="<?= $href ?>" class="<?= $nav === $k ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?></div>
    <?php endforeach; ?>
    <div class="sidesep" role="separator"></div>
    <a href="/staff/me" class="<?= $nav === 'me' ? 'on' : '' ?>">My account</a>
    <form method="post" action="/logout"><?= csrf_field() ?><button class="btn link" type="submit">Log out</button></form>
    <div class="userbar" style="display:flex;gap:8px;align-items:center"><?= avatar_tag($cu ?? ['id' => 0, 'name' => '?'], 34) ?><span><?= e($cu['name'] ?? '') ?><br><?= e(ROLES[$cu['role'] ?? ''] ?? '') ?></span></div>
  </aside>
  <div>
    <nav class="mnav"><?php foreach ($items as [$k, $href, $label]): ?><a href="<?= $href ?>"><?= e($label) ?></a><?php endforeach; ?></nav>
    <div class="main"><?php require __DIR__ . '/flashes.php'; ?><?= $content ?></div>
  </div>
<?php if (!empty($GLOBALS['admin_page'])): ?><i data-admin-autolock data-csrf="<?= e(csrf_token()) ?>" data-seconds="30" hidden></i><?php endif; ?>
</div></body></html>
