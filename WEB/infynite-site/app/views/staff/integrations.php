<?php if (can('team.manage')) { require __DIR__ . '/admin_tabs.php'; } ?>
<div class="top"><div><h2 style="margin:0">Integrations and off-site copies</h2><span class="muted"><a href="/staff/admin">&larr; Admin settings</a> &middot; Where copies of your data are sent, and what is sent.</span></div></div>
<?php if (!$rclone): ?><div class="errs">rclone is not installed on the server, so Google Drive and FTP copies cannot run. Install it (for example <code>sudo apt install rclone</code>) and reload.</div><?php endif; ?>
<div class="grid g2" style="align-items:start">
<div class="card"><h3>Google Drive (rclone)</h3>
<p class="muted">Pick a connected Google Drive remote. Connect a new one once on the server with <code>rclone config</code> (a browser sign-in).</p>
<form method="post" action="/staff/admin/integrations"><?= csrf_field() ?><input type="hidden" name="part" value="drive">
<label style="font-weight:400"><input type="checkbox" name="drive_enabled" value="1" style="width:auto;margin:0 6px 0 0" <?= setting('drive_enabled') === '1' ? 'checked' : '' ?>>Enabled</label><br>
<label for="dr">rclone remote</label><select id="dr" name="drive_remote"><option value="">- none -</option><?php foreach ($remotes as $r): ?><option value="<?= e($r) ?>" <?= setting('drive_remote') === $r ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select>
<label for="dp">Folder inside the drive</label><input id="dp" name="drive_path" maxlength="190" placeholder="PROJECTS/SAIU_MSCD/INFYNITE_LOCAL_SERVER" value="<?= e((string) setting('drive_path', '')) ?>">
<label for="dx">Start from folder ID (optional; type <code>root</code> to start at the top of My Drive)</label><input id="dx" name="drive_root" maxlength="80" value="<?= e((string) setting('drive_root', '')) ?>">
<button class="btn">Save</button></form>
<form method="post" action="/staff/admin/integrations/test" class="mt"><?= csrf_field() ?><input type="hidden" name="dest" value="drive"><button class="btn alt">Test connection</button></form></div>
<div class="card"><h3>FTP / FTPS / SFTP server</h3>
<form method="post" action="/staff/admin/integrations"><?= csrf_field() ?><input type="hidden" name="part" value="ftp">
<label style="font-weight:400"><input type="checkbox" name="ftp_enabled" value="1" style="width:auto;margin:0 6px 0 0" <?= setting('ftp_enabled') === '1' ? 'checked' : '' ?>>Enabled</label><br>
<div class="row"><div><label for="fp">Protocol</label><select id="fp" name="ftp_proto"><?= options(['sftp' => 'SFTP (over SSH, port 22)', 'ftps' => 'FTPS (FTP with TLS, port 21)', 'ftp' => 'FTP (not encrypted)'], setting('ftp_proto', 'sftp')) ?></select></div>
<div><label for="fh">Server</label><input id="fh" name="ftp_host" maxlength="120" value="<?= e((string) setting('ftp_host', '')) ?>"></div><div><label for="fo">Port</label><input id="fo" name="ftp_port" maxlength="5" value="<?= e((string) setting('ftp_port', '')) ?>"></div></div>
<div class="row"><div><label for="fu">Username</label><input id="fu" name="ftp_user" maxlength="190" value="<?= e((string) setting('ftp_user', '')) ?>"></div>
<div><label for="fw">Password</label><input id="fw" name="ftp_pass" type="password" autocomplete="new-password" placeholder="<?= setting('ftp_pass') ? '(saved; leave blank to keep)' : '' ?>"></div></div>
<label for="fd">Folder on the server</label><input id="fd" name="ftp_path" maxlength="190" value="<?= e((string) setting('ftp_path', '')) ?>">
<button class="btn">Save</button></form>
<form method="post" action="/staff/admin/integrations/test" class="mt"><?= csrf_field() ?><input type="hidden" name="dest" value="ftp"><button class="btn alt">Test connection</button></form></div>
</div>
<div class="card mt"><h3>What is copied where</h3>
<form method="post" action="/staff/admin/integrations"><?= csrf_field() ?><input type="hidden" name="part" value="matrix">
<div class="tw"><table><tr><th>Data</th><?php foreach (OFFSITE_DESTS as $d => $l): ?><th><?= e($l) ?></th><?php endforeach; ?></tr>
<?php foreach (OFFSITE_DATASETS as $ds => [$label, $note]): ?><tr><td><?= e($label) ?><br><span class="muted" style="font-size:.8rem"><?= e($note) ?></span></td><?php foreach (OFFSITE_DESTS as $d => $l): ?><td><input type="checkbox" name="m[<?= e($ds) ?>][<?= e($d) ?>]" value="1" aria-label="<?= e($label . ' to ' . $l) ?>" style="width:auto;margin:0" <?= setting("off_{$ds}_{$d}") === '1' ? 'checked' : '' ?>></td><?php endforeach; ?></tr><?php endforeach; ?></table></div>
<p class="muted mt">Copies run every night at 22:45 and add new or changed files; nothing is deleted on the other side. A destination only receives data when it is Enabled above.</p>
<button class="btn">Save selection</button></form>
<form method="post" action="/staff/admin/integrations/run" class="mt"><?= csrf_field() ?><button class="btn alt" data-confirm="Copy everything selected now? This can take a few minutes.">Run now</button></form></div>
<div class="card mt"><h3>Last run</h3>
<?php if ($last): ?><p class="muted">Finished <?= e(fmt_dt($last['at'])) ?></p><div class="tw"><table><tr><th>Destination</th><th>Data</th><th>Result</th></tr>
<?php foreach ($last['results'] as $r): ?><tr><td><?= e(OFFSITE_DESTS[$r['dest']] ?? $r['dest']) ?></td><td><?= e(OFFSITE_DATASETS[$r['set']][0] ?? $r['set']) ?></td><td><?= badge($r['ok'] ? 'OK' : 'Failed', $r['ok'] ? 'p-ok' : 'p-bad') ?> <span class="muted"><?= e($r['msg']) ?></span></td></tr><?php endforeach; ?></table></div>
<?php else: ?><p class="muted">Nothing has run yet.</p><?php endif; ?></div>
