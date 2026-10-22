<?php
/** Admin settings > Integrations: Google Drive (rclone), FTP/SFTP, what is copied where, test buttons, run now. Owner + admin key. */
function integ_guard(): array
{
    return admin_guard('settings', 'Integrations');
}
/** Validates and stores the FTP/FTPS/SFTP connection fields from $_POST (shared by Integrations and Backups). $enabled null = leave "Enabled" as it is. Returns the list of errors. */
function ftp_settings_save(?string $enabled): array
{
    $errors = [];
    $f = fn($k, $m = 190) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $m);
    $proto = $f('ftp_proto', 5); $host = $f('ftp_host', 120); $port = $f('ftp_port', 5); $path = trim($f('ftp_path', 190), '/');
    if (!in_array($proto, ['ftp', 'ftps', 'sftp'], true)) { $errors[] = 'Choose a protocol.'; }
    if ($host !== '' && !preg_match('/^[A-Za-z0-9.\-]{1,120}$/', $host)) { $errors[] = 'Server name is not valid.'; }
    if ($port !== '' && !(ctype_digit($port) && $port >= 1 && $port <= 65535)) { $errors[] = 'Port must be 1-65535.'; }
    if (!preg_match('~^[A-Za-z0-9 _./-]{0,190}$~', $path) || str_contains($path, '..')) { $errors[] = 'Folder path may only use letters, digits, spaces and _ . / -'; }
    if ($errors) { return $errors; }
    $vals = ['ftp_proto' => $proto, 'ftp_host' => $host, 'ftp_port' => $port, 'ftp_user' => $f('ftp_user'), 'ftp_path' => $path];
    if ($enabled !== null) { $vals['ftp_enabled'] = $enabled; }
    foreach ($vals as $k => $v) { setting_set($k, $v === '' ? null : $v); }
    if (($pw = (string) ($_POST['ftp_pass'] ?? '')) !== '') { setting_set('ftp_pass', secret_encrypt($pw)); }
    return [];
}
function integ_page(): void
{
    $u = integ_guard();
    $last = json_decode((string) setting('offsite_last', ''), true) ?: null;
    render('staff/integrations', ['title' => 'Integrations', 'nav' => 'admin', 'atab' => 'settings', 'u' => $u, 'remotes' => rclone_remotes(), 'rclone' => rclone_bin(), 'last' => $last], 'staff');
}
function integ_save(): void
{
    integ_guard();
    $part = (string) ($_POST['part'] ?? ''); $errors = [];
    $f = fn($k, $m = 190) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $m);
    if ($part === 'drive') {
        $remote = $f('drive_remote', 40); $path = trim($f('drive_path', 190), '/'); $root = $f('drive_root', 80);
        if ($remote !== '' && !in_array($remote, rclone_remotes(), true)) { $errors[] = 'Choose one of the rclone remotes listed.'; }
        if (!preg_match('~^[A-Za-z0-9 _./-]{0,190}$~', $path) || str_contains($path, '..')) { $errors[] = 'Folder path may only use letters, digits, spaces and _ . / -'; }
        if (!preg_match('/^[A-Za-z0-9_-]{0,80}$/', $root)) { $errors[] = 'Root folder ID is not valid.'; }
        if (!$errors) { foreach (['drive_enabled' => !empty($_POST['drive_enabled']) ? '1' : '0', 'drive_remote' => $remote, 'drive_path' => $path, 'drive_root' => $root] as $k => $v) { setting_set($k, $v === '' ? null : $v); } }
    } elseif ($part === 'ftp') {
        $errors = ftp_settings_save(!empty($_POST['ftp_enabled']) ? '1' : '0');
    } elseif ($part === 'matrix') {
        foreach (array_keys(OFFSITE_DATASETS) as $ds) { foreach (array_keys(OFFSITE_DESTS) as $d) { setting_set("off_{$ds}_{$d}", !empty($_POST['m'][$ds][$d]) ? '1' : '0'); } }
    } else { abort(400); }
    if ($errors) { flash(implode(' ', $errors), 'bad'); } else { audit('admin.integrations', $part); flash('Saved.'); }
    redirect('/staff/admin/integrations');
}
function integ_test(): void
{
    integ_guard();
    $d = (string) ($_POST['dest'] ?? ''); if (!isset(OFFSITE_DESTS[$d])) { abort(400); }
    [$ok, $msg] = offsite_test($d);
    audit('admin.integration_test', $d . ($ok ? ' ok' : ' failed'));
    flash(($ok ? '' : 'Test failed. ') . $msg, $ok ? 'ok' : 'bad');
    redirect('/staff/admin/integrations');
}
function integ_run(): void
{
    integ_guard();
    @set_time_limit(0);
    $res = offsite_run();
    audit('admin.offsite_run', count($res) . ' item(s)');
    flash($res ? (array_filter($res, fn($r) => !$r['ok']) ? 'Finished with errors: see the table below.' : 'All selected copies finished.') : 'Nothing selected: enable a destination and tick what to copy.', $res && array_filter($res, fn($r) => !$r['ok']) ? 'bad' : 'ok');
    redirect('/staff/admin/integrations');
}
function kb_pdf_one(string $slug): void
{
    require_staff();
    $a = one('SELECT * FROM is_kb_articles WHERE slug=?', [$slug]);
    if (!$a) { abort(404); }
    audit('kb.pdf', $slug);
    kb_pdf($a)->download($slug . '.pdf');
}
function kb_pdf_all(): void
{
    require_staff();
    $p = new Pdf('Knowledge base');
    $p->para((string) setting('app_name', cfg('app_name')), 10, true); $p->para('Knowledge base and procedures', 22, true); $p->para('Printed ' . date('d M Y'), 9); $p->rule(6, 8);
    $cat = null;
    foreach (all('SELECT * FROM is_kb_articles ORDER BY category, title') as $a) {
        if ($a['category'] !== $cat) { $cat = $a['category']; $p->gap(10); $p->ensure(60); $p->para($cat, 16, true); $p->rule(2, 4); }
        $p->gap(6); $p->ensure(80); $p->para($a['title'] . '  (v' . $a['version'] . ')', 12.5, true); pdf_markdown($p, (string) $a['body']);
    }
    audit('kb.pdf', 'all');
    $p->download('knowledge-base.pdf');
}
