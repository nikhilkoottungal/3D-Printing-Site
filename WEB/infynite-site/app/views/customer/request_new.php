<div class="top"><h2 style="margin:0">New order</h2></div>
<form method="post" action="/requests/new" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="grid g2" style="align-items:start">
  <div class="card"><h3>1 · Model files</h3>
    <label for="files">STL or 3MF (max <?= e(fmt_bytes((int) cfg('upload.max_bytes'))) ?> each, up to <?= (int) cfg('upload.max_files') ?> files)</label>
    <input id="files" type="file" name="files[]" multiple accept=".stl,.3mf" data-list="filelist" required>
    <p class="muted" id="filelist" style="font-size:.85rem"></p>
    <div class="note">Files are checked for type and structure, stored privately and downloadable only by you and our staff. Used: <?= e(fmt_bytes($usage)) ?> of <?= e(fmt_bytes((int) cfg('upload.quota_bytes'))) ?>.</div></div>
  <div class="card"><h3>2 · Print requirements</h3>
    <label for="material">Material</label><select id="material" name="material"><?= options(MATERIALS, $old['material'] ?? '') ?></select>
    <label for="color">Colour</label><select id="color" name="color"><?= options(COLORS, $old['color'] ?? '') ?></select>
    <label for="quantity">Quantity</label><input id="quantity" name="quantity" type="number" min="1" max="10000" value="<?= (int) ($old['quantity'] ?? 1) ?>">
    <label for="notes">Notes (tolerances, finish, deadline)</label><textarea id="notes" name="notes" rows="4" maxlength="4000"><?= e($old['notes'] ?? '') ?></textarea>
    <button class="btn">Place order / request quote</button> <span class="muted" style="font-size:.85rem">No payment now. We price it manually.</span></div>
</div></form>
