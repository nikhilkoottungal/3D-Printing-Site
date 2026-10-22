<div class="top noprint"><div><h2 style="margin:0"><?= e($f['orig_name']) ?></h2><span class="muted">Order <?= e($f['ref']) ?> &middot; drag to rotate, scroll or pinch to zoom</span></div><div><a class="btn alt sm" href="/staff/requests/<?= (int) $f['request_id'] ?>">&larr; Order</a> <a class="btn sm" href="/files/<?= (int) $f['id'] ?>">Download</a></div></div>
<div class="card"><div id="stl" data-src="/files/<?= (int) $f['id'] ?>" style="height:min(70vh,560px);min-height:320px;background:var(--bg);border-radius:8px;position:relative"><p class="muted" id="stl-msg" style="position:absolute;left:12px;top:8px;margin:0">Loading model...</p></div>
<p class="muted mt" id="stl-info"></p></div>
<script src="/assets/vendor/three.min.js"></script><script src="/assets/stl-viewer.js" defer></script>
