<?php
$stale = $ageH === null || $ageH > 26;
$lvl = $pct >= 90 ? 'p-bad' : ($pct >= 80 ? 'p-warn' : ($pct >= 70 ? 'p-info' : 'p-ok'));
?>
<div class="top"><div><h2 style="margin:0">Backups</h2><span class="muted">Read-only status. Backups are made by <code>tools/backup.php</code> (cron) and restored with <code>tools/restore.php</code>.</span></div></div>
<?php if (!$configured): ?><div class="errs">Backups are not fully configured: add the owners' public keys (<code>backup.recipients</code>) and at least one off-site destination (<code>backup.destinations</code>) in <code>app/config.php</code>.</div><?php endif; ?>
<?php if (!$status): ?><div class="card"><p class="muted">No backup has run yet.</p></div><?php else: ?>
<div class="kpi">
  <div class="card"><span class="muted">Last backup</span><b class="<?= $stale ? 'overdue' : '' ?>"><?= $ageH === null ? '—' : ($ageH < 1 ? round($ageH * 60) . ' min' : round($ageH, 1) . ' h') ?> ago</b><span class="muted"><?= $stale ? 'OVERDUE (expected every 24 h)' : e(fmt_dt($status['finished'])) ?></span></div>
  <div class="card"><span class="muted">Result</span><b><?= !empty($status['ok']) ? 'OK' : 'PROBLEM' ?></b><span class="muted"><?= (int) ($status['files_total'] ?? 0) ?> files · <?= (int) ($status['files_new'] ?? 0) ?> new</span></div>
  <div class="card"><span class="muted">Waiting to ship off-site</span><b class="<?= !empty($state['pending']) || !empty($state['unshipped']) ? 'overdue' : '' ?>"><?= count($state['pending'] ?? []) + count($state['unshipped'] ?? []) ?></b><span class="muted"><?= (int) ($status['destinations'] ?? 0) ?> destination(s)</span></div>
  <div class="card"><span class="muted">Hosting storage</span><b><?= (int) $pct ?>%</b><div class="bar"><i style="width:<?= min(100, (int) $pct) ?>%"></i></div><span class="muted"><?= e(fmt_bytes($used)) ?> of <?= e(fmt_bytes($quota)) ?> <?= badge($pct >= 70 ? 'alert ≥70%' : 'ok', $lvl) ?></span></div>
</div>
<?php if (!empty($status['errors'])): ?><div class="errs"><ul><?php foreach ($status['errors'] as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!empty($status['warnings'])): ?><div class="flash bad"><?= e(implode(' · ', $status['warnings'])) ?></div><?php endif; ?>
<div class="grid g2" style="align-items:start">
<div class="card"><h3>Recent backup runs</h3><div class="tw"><table><tr><th>Run (UTC)</th><th>Files</th><th>DB rows</th><th>Core</th><th></th></tr>
<?php foreach ($runs as $r): ?><tr><td><?= e($r['run']) ?></td><td><?= (int) $r['files_total'] ?></td><td><?= (int) array_sum(array_map(fn($d) => $d['rows'] ?? 0, $r['databases'])) ?></td><td><?= e(fmt_bytes((int) $r['core']['size'])) ?></td><td><?= $r['label'] ? badge($r['label']) : '' ?></td></tr><?php endforeach; ?></table></div></div>
<div class="card"><h3>Who can restore</h3><p class="muted">Every backup is encrypted to these key IDs. If a key ID is missing here, that person cannot restore.</p>
<?php foreach ($status['recipients'] ?? [] as $k): ?><div class="comment"><code><?= e($k) ?></code></div><?php endforeach; ?>
<h3 class="mt">Reminders</h3><ul class="muted"><li>Monthly: run a restore drill (procedure in Knowledge base).</li><li>Keep each owner's key file + passphrase in separate safe places.</li><li>The PC copy must keep pulling nightly; an alert fires if it goes stale.</li></ul></div>
</div>
<?php endif; ?>
