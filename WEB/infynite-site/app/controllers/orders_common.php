<?php
function set_request_status(array $r, string $status, string $note, ?int $byUser): void
{
    q('UPDATE is_requests SET status=?, updated_at=?' . ($status === 'dispatched' ? ', dispatched_at=COALESCE(dispatched_at, NOW())' : '') . ' WHERE id=?', [$status, now(), $r['id']]);
    if ($status === 'dispatched') {   // say how it goes out in the history, so the order trail shows courier / hand delivery / pickup
        $dm = (string) ($r['delivery_method'] ?? '');
        $how = $dm === 'courier' ? 'Courier' . ($r['courier'] ? ': ' . $r['courier'] : '') . ($r['tracking'] ? ' (tracking ' . $r['tracking'] . ')' : '') : (DELIVERY_METHODS[$dm] ?? '');
        if ($how !== '') { $note = trim($note . ' [' . $how . ']'); }
    }
    // A cancelled or failed order must not leave planned work in the print queue: jobs not yet started are removed; running ones are only reported.
    if (in_array($status, ['cancelled', 'failed'], true)) {
        $queued = (int) one("SELECT COUNT(*) c FROM is_print_jobs WHERE request_id=? AND status='queued'", [$r['id']])['c'];
        $running = (int) one("SELECT COUNT(*) c FROM is_print_jobs WHERE request_id=? AND status='printing'", [$r['id']])['c'];
        if ($queued) { q("DELETE FROM is_print_jobs WHERE request_id=? AND status='queued'", [$r['id']]); $note = trim($note . ' (' . $queued . ' queued print job(s) removed)'); }
        if ($running) { $note = trim($note . ' (' . $running . ' print job(s) still printing: finish or mark them failed in the Print queue)'); }
    }
    q('INSERT INTO is_request_history (request_id,status,note,by_user,at) VALUES (?,?,?,?,?)', [$r['id'], $status, mb_substr($note, 0, 500), $byUser, now()]);
    notify('order.status', "Order {$r['ref']}: " . (STATUSES[$status] ?? $status), "Order {$r['ref']} is now " . (STATUSES[$status] ?? $status) . ($note !== '' ? ".\nNote: $note" : '.') . "\n" . app_url('/staff/requests/' . $r['id']));
}

/** Authenticated, streamed download of a model file. Staff with order access only. */
function file_download(string $id): void
{
    $u = require_staff('requests.view');
    $f = one('SELECT * FROM is_request_files WHERE id=?', [(int) $id]);
    if (!$f) { abort(404, 'File not found.'); }
    $path = cfg('storage') . '/uploads/' . $f['stored_name'];
    if (!preg_match('/^[a-f0-9]{40}$/', $f['stored_name']) || !is_file($path)) { abort(404, 'File not found.'); }
    audit('file.download', 'file ' . $f['id']);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $f['orig_name']) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    session_write_close();
    readfile($path);
    exit;
}
