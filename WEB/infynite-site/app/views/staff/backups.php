<?php if (can('team.manage')) { require __DIR__ . '/admin_tabs.php'; } ?>
<?php
$stale = $ageH === null || $ageH > 26;
$lvl = $pct >= 90 ? 'p-bad' : ($pct >= 80 ? 'p-warn' : ($pct >= 70 ? 'p-info' : 'p-ok'));
?>
<div class="top"><div><h2 style="margin:0">Backups</h2><span class="muted">Status, where backups go, and when they run. Restored with <code>tools/restore.php</code> (needs an owner's private key).</span></div></div>
<?php if (!$configured): ?><div class="errs">Backups are not fully configured: add the owners' public keys (<code>backup.recipients</code>) and at least one off-site destination (<code>backup.destinations</code>) in <code>app/config.php</code>.</div><?php endif; ?>
<?php $ftpOn = $targets['ftp']; ?>
<?php if ($done && !$running): ?><div class="flash <?= !empty($status['ok']) ? 'ok' : 'bad' ?>">Backup finished: <?= !empty($status['ok']) ? 'OK' : 'there was a problem (see below)' ?> &middot; <?= e(backup_fmt($status['finished'] ?? null)) ?><?= !empty($status['run']) ? ' &middot; run <code>' . e($status['run']) . '</code>' : '' ?></div><?php endif; ?>
<div class="card mt"><h3>Backup now</h3>
<?php if ($running): ?><div data-backup-poll="/staff/backups/status" role="status" aria-live="polite"><p><span class="spinner" aria-hidden="true"></span> <b>Backup in progress</b> <span class="muted">(<?= e(backup_targets_text($targets)) ?>)</span></p><p class="muted" data-backup-log>Starting...</p><p class="muted">This page updates by itself when the backup is finished.</p></div>
<?php else: ?><p class="muted">Makes a backup right away to the locations chosen below (<b><?= e(backup_targets_text($targets)) ?></b>). It runs in the background and can take a few minutes.</p>
<form method="post" action="/staff/backups/run"><?= csrf_field() ?><button class="btn" data-confirm="Start a backup now?" data-loading="Starting backup...">Backup now</button></form><?php endif; ?></div>
<form method="post" action="/staff/backups" class="card mt"><?= csrf_field() ?><h3>Where and when</h3>
<div class="grid g2" style="align-items:start">
<div><label>Backup location <span class="muted">(tick one or both)</span></label>
<label style="font-weight:400"><input type="checkbox" name="backup_local" value="1" style="width:auto;margin:0 6px 0 0" <?= $targets['local'] ? 'checked' : '' ?>>Local: kept on this server</label>
<label style="font-weight:400"><input type="checkbox" name="backup_ftp" value="1" style="width:auto;margin:0 6px 0 0" <?= $targets['ftp'] ? 'checked' : '' ?>>FTP: uploaded to the FTP server on the right</label>
<p class="muted">Backups are always encrypted. With FTP only, nothing stays on this server after the upload succeeds. Tick both to keep a local copy as well.</p>
<label for="bf">How often</label><select id="bf" name="backup_freq"><?= options(BACKUP_FREQS, $sched['freq']) ?></select>
<div class="row">
<div data-showif="backup_freq=weekly"><label for="bw">Day of the week</label><select id="bw" name="backup_weekday"><?= options(BACKUP_WEEKDAYS, $sched['weekday']) ?></select></div>
<div data-showif="backup_freq=daily|weekly"><label for="bt">Time (IST)</label><input id="bt" name="backup_time" type="time" value="<?= e($sched['time']) ?>"></div>
<div data-showif="backup_freq=hours"><label for="bh">Every</label><select id="bh" name="backup_every_h"><?php foreach (BACKUP_EVERY_HOURS as $h): ?><option value="<?= $h ?>" <?= $sched['every_h'] === $h ? 'selected' : '' ?>><?= $h ?> hour<?= $h > 1 ? 's' : '' ?></option><?php endforeach; ?></select></div></div>
<p class="muted">Now: <b><?= e(backup_schedule_text($sched)) ?></b> &middot; <?= e(backup_targets_text($targets)) ?><?= $next ? ' &middot; next run about ' . e(backup_fmt_ts($next)) : '' ?>. The server checks every 15 minutes.</p></div>
<div><label>FTP server <span class="muted">(used when FTP is ticked)</span></label>
<div class="row"><div><label for="fp">Protocol</label><select id="fp" name="ftp_proto"><?= options(['sftp' => 'SFTP (port 22)', 'ftps' => 'FTPS (port 21, TLS)', 'ftp' => 'FTP (not encrypted)'], setting('ftp_proto', 'ftp')) ?></select></div>
<div><label for="fh">Server address / IP</label><input id="fh" name="ftp_host" maxlength="120" value="<?= e((string) setting('ftp_host', '')) ?>"></div><div><label for="fo">Port</label><input id="fo" name="ftp_port" maxlength="5" placeholder="21" value="<?= e((string) setting('ftp_port', '')) ?>"></div></div>
<div class="row"><div><label for="fu">Username</label><input id="fu" name="ftp_user" maxlength="190" autocomplete="off" value="<?= e((string) setting('ftp_user', '')) ?>"></div>
<div><label for="fw">Password</label><input id="fw" name="ftp_pass" type="password" autocomplete="new-password" placeholder="<?= setting('ftp_pass') ? '(saved; leave blank to keep)' : '' ?>"></div></div>
<label for="fd">Folder on the server</label><input id="fd" name="ftp_path" maxlength="190" placeholder="backups" value="<?= e((string) setting('ftp_path', '')) ?>">
<p class="muted">The same FTP connection is used under Admin settings &gt; Integrations. Backups go to <code>&lt;folder&gt;/backups</code>.</p>
<button class="btn" formaction="/staff/backups/ftp">Configure</button> <button class="btn alt" formaction="/staff/backups/test-ftp">Test</button> <?php if ($ftpOn): ?><a class="btn alt" href="/staff/backups?ftp=1#ftp-list">Show backups on the FTP server</a><?php endif; ?>
<p class="muted">Configure saves the FTP details above. Test saves them, then connects, writes, reads back and deletes a small file.</p></div></div>
<button class="btn">Save location and schedule</button></form>
<?php if (!$rclone && $ftpOn): ?><div class="errs mt">rclone is not installed on the server, so FTP uploads cannot run.</div><?php endif; ?>
<?php if ($ftpOn): ?><div class="card mt" id="ftp-list"><h3>Backups on the FTP server</h3>
<?php if ($ftpRuns === null): ?><p class="muted">Press "Show backups on the FTP server" in the FTP settings above to list what is stored there.</p>
<?php elseif (!$ftpRuns['ok']): ?><div class="errs">Cannot read the FTP server: <?= e($ftpRuns['err']) ?></div>
<?php elseif (!$ftpRuns['runs']): ?><p class="muted">No backups found on the FTP server yet.</p>
<?php else: ?><div class="tw"><table><tr><th>Backup time (IST)</th><th>Run ID</th><th>Files</th><th>DB rows</th><th>Restore with</th></tr>
<?php foreach (array_slice($ftpRuns['runs'], 0, 20, true) as $id => $r): ?><tr><td><?= e(backup_run_fmt($id)) ?></td><td><code><?= e($id) ?></code></td><td><?= (int) ($r['files_total'] ?? 0) ?></td><td><?= (int) array_sum(array_map(fn($d) => $d['rows'] ?? 0, $r['databases'] ?? [])) ?></td><td><code>--ftp --run=<?= e($id) ?></code></td></tr><?php endforeach; ?></table></div><?php endif; ?></div><?php endif; ?>
<?php if ($ftpOn && $ftpLast): ?><div class="card mt"><h3>Last FTP upload</h3><p class="muted"><?= e(backup_fmt($ftpLast['at'])) ?></p><?php foreach ($ftpLast['results'] as $r): ?><div><?= badge($r['ok'] ? 'OK' : 'Failed', $r['ok'] ? 'p-ok' : 'p-bad') ?> <span class="muted"><?= e($r['msg']) ?></span></div><?php endforeach; ?></div><?php endif; ?>
<?php if (!$status): ?><div class="card"><p class="muted">No backup has run yet.</p></div><?php else: ?>
<div class="kpi">
  <div class="card"><span class="muted">Last backup</span><b class="<?= $stale ? 'overdue' : '' ?>"><?= $ageH === null ? '—' : ($ageH < 1 ? round($ageH * 60) . ' min' : round($ageH, 1) . ' h') ?> ago</b><span class="muted"><?= $stale ? 'OVERDUE (expected every 24 h)' : e(backup_fmt($status['finished'])) ?></span></div>
  <div class="card"><span class="muted">Result</span><b><?= !empty($status['ok']) ? 'OK' : 'PROBLEM' ?></b><span class="muted"><?= (int) ($status['files_total'] ?? 0) ?> files · <?= (int) ($status['files_new'] ?? 0) ?> new</span></div>
  <div class="card"><span class="muted">Waiting to ship off-site</span><b class="<?= !empty($state['pending']) || !empty($state['unshipped']) ? 'overdue' : '' ?>"><?= count($state['pending'] ?? []) + count($state['unshipped'] ?? []) ?></b><span class="muted"><?= (int) ($status['destinations'] ?? 0) ?> destination(s)</span></div>
  <div class="card"><span class="muted">Hosting storage</span><b><?= (int) $pct ?>%</b><div class="bar"><i style="width:<?= min(100, (int) $pct) ?>%"></i></div><span class="muted"><?= e(fmt_bytes($used)) ?> of <?= e(fmt_bytes($quota)) ?> <?= badge($pct >= 70 ? 'alert ≥70%' : 'ok', $lvl) ?></span></div>
</div>
<?php if (!empty($status['errors'])): ?><div class="errs"><ul><?php foreach ($status['errors'] as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!empty($status['warnings'])): ?><div class="flash bad"><?= e(implode(' · ', $status['warnings'])) ?></div><?php endif; ?>
<div class="grid g2" style="align-items:start">
<div class="card"><h3>Recent backup runs</h3><p class="muted"><?= $listDir ? 'Read from the local backup folder <code>' . e($listDir) . '</code>.' : 'FTP only: no local copy is kept. Use "Show backups on the FTP server".' ?></p><div class="tw"><table><tr><th>Backup time (IST)</th><th>Run ID</th><th>Files</th><th>DB rows</th><th>Core</th><th></th></tr>
<?php foreach ($runs as $r): ?><tr><td><?= e(backup_run_fmt($r['run'])) ?></td><td><code><?= e($r['run']) ?></code></td><td><?= (int) $r['files_total'] ?></td><td><?= (int) array_sum(array_map(fn($d) => $d['rows'] ?? 0, $r['databases'])) ?></td><td><?= e(fmt_bytes((int) $r['core']['size'])) ?></td><td><?= $r['label'] ? badge($r['label']) : '' ?></td></tr><?php endforeach; ?></table></div></div>
<div class="card"><h3>Who can restore</h3><p class="muted">Every backup is encrypted to these key IDs. If a key ID is missing here, that person cannot restore.</p>
<?php foreach ($status['recipients'] ?? [] as $k): ?><div class="comment"><code><?= e($k) ?></code></div><?php endforeach; ?>
<h3 class="mt">Reminders</h3><ul class="muted"><li>Monthly: run a restore drill (procedure in Knowledge base).</li><li>Keep each owner's key file + passphrase in separate safe places.</li><li>The PC copy must keep pulling nightly; an alert fires if it goes stale.</li></ul></div>
</div>
<?php endif; ?>
<div class="card mt"><h3>Restore</h3>
<p class="muted">Restoring needs an owner's private key file and passphrase, so it runs on a trusted computer: <code>tools/restore.php</code>. Pick the source:</p>
<div class="grid g2" style="align-items:start"><div><b>Local backups</b><p class="muted"><?= count($runs) ? 'Newest: ' . e(backup_run_fmt($runs[0]['run'])) . ' (<code>' . e($runs[0]['run']) . '</code>) (' . count($runs) . ' kept here)' : 'None kept here yet.' ?></p>
<code>php tools/restore.php list</code><br><code>php tools/restore.php verify --key=owner.key --run=RUN_ID</code></div>
<div><b>FTP backups</b><p class="muted">Add <code>--ftp</code> and the tool downloads the chosen run from the FTP server first.</p>
<code>php tools/restore.php list --ftp</code><br><code>php tools/restore.php verify --ftp --key=owner.key --run=RUN_ID</code><br><code>php tools/restore.php restore --ftp --key=owner.key --run=RUN_ID --live --yes --confirm=RUN_ID</code></div></div>
</div>
