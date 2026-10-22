<div class="top"><h2 style="margin:0">New customer</h2><a class="btn alt" href="/staff/customers">Cancel</a></div>
<?php if (!empty($errors)): ?><div class="errs"><ul><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card narrow" style="max-width:640px;margin:0"><form method="post" action="/staff/customers/new"><?= csrf_field() ?><?php require __DIR__ . '/customer_fields.php'; ?><button class="btn">Add customer</button></form></div>
