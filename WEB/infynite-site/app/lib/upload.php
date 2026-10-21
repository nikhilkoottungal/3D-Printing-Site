<?php
/**
 * Validates one uploaded STL/3MF. Returns [kind, null] on success or [null, 'reason'].
 * Files are only inspected structurally; they are never executed, parsed for rendering or served inline.
 */
function validate_model_file(string $path, string $origName, int $size): array
{
    $max = (int) cfg('upload.max_bytes');
    if ($size <= 0) { return [null, 'file is empty']; }
    if ($size > $max) { return [null, 'larger than ' . fmt_bytes($max)]; }

    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'stl') { return validate_stl($path, $size); }
    if ($ext === '3mf') { return validate_3mf($path); }
    return [null, 'only .stl and .3mf files are accepted'];
}

function validate_stl(string $path, int $size): array
{
    $f = fopen($path, 'rb');
    if (!$f) { return [null, 'cannot be read']; }
    $head = fread($f, 84);
    if (strlen($head) === 84) {
        $tri = unpack('V', substr($head, 80, 4))[1];
        if ($tri > 0 && 84 + 50 * $tri === $size) {   // binary STL: exact size match
            fclose($f);
            return ['stl', null];
        }
    }
    rewind($f);
    $sample = fread($f, 8192);
    fclose($f);
    if (preg_match('/^\s*solid\b/i', $sample) && stripos($sample, 'facet') !== false && strpos($sample, "\0") === false) {
        return ['stl', null];                           // ASCII STL
    }
    return [null, 'not a valid STL structure'];
}

function validate_3mf(string $path): array
{
    if (!class_exists('ZipArchive')) { return [null, '3MF checking is unavailable on this server']; }
    $f = fopen($path, 'rb');
    $sig = $f ? fread($f, 4) : '';
    if ($f) { fclose($f); }
    if ($sig !== "PK\x03\x04") { return [null, 'not a valid 3MF (zip) file']; }

    $z = new ZipArchive();
    if ($z->open($path, ZipArchive::RDONLY) !== true) { return [null, 'corrupt 3MF archive']; }
    try {
        if ($z->numFiles < 1 || $z->numFiles > (int) cfg('upload.max_zip_entries')) { return [null, '3MF has an unexpected number of entries']; }
        $total = 0; $hasModel = false; $hasTypes = false;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            if (!$st) { return [null, 'corrupt 3MF archive']; }
            $n = $st['name'];
            if ($n === '' || $n[0] === '/' || strpos($n, '\\') !== false || strpos($n, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $n)) {
                return [null, '3MF contains an unsafe path'];
            }
            $os = null; $attr = null;
            if ($z->getExternalAttributesIndex($i, $os, $attr) && $os === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000)) {
                return [null, '3MF contains a symbolic link'];
            }
            $total += $st['size'];
            if ($total > (int) cfg('upload.max_unzipped')) { return [null, '3MF expands to more than the allowed size']; }
            if ($st['comp_size'] > 0 && $st['size'] / $st['comp_size'] > 200 && $st['size'] > 1048576) { return [null, '3MF compression ratio is suspicious']; }
            if (strcasecmp($n, '3D/3dmodel.model') === 0) { $hasModel = true; }
            if (strcasecmp($n, '[Content_Types].xml') === 0) { $hasTypes = true; }
        }
        if (!$hasModel || !$hasTypes) { return [null, 'not a valid 3MF package']; }
    } finally {
        $z->close();
    }
    return ['3mf', null];
}

function customer_usage(int $userId): int
{
    return (int) (one('SELECT COALESCE(SUM(size_bytes),0) s FROM is_request_files WHERE user_id=?', [$userId])['s'] ?? 0);
}

/** Normalises $_FILES['files'] into a list of single-file arrays. */
function uploaded_list(string $field): array
{
    $out = [];
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) { return $out; }
    foreach ($_FILES[$field]['name'] as $i => $name) {
        if ($_FILES[$field]['error'][$i] === UPLOAD_ERR_NO_FILE) { continue; }
        $out[] = ['name' => (string) $name, 'tmp' => $_FILES[$field]['tmp_name'][$i], 'size' => (int) $_FILES[$field]['size'][$i], 'error' => $_FILES[$field]['error'][$i]];
    }
    return $out;
}

/** Validates uploaded files and the owner's quota; appends human-readable problems to $errors and returns the accepted files. */
function check_uploads(array $files, int $ownerId, array &$errors): array
{
    $accepted = [];
    $total = customer_usage($ownerId);
    if (count($files) > (int) cfg('upload.max_files')) { $errors[] = 'At most ' . cfg('upload.max_files') . ' files per request.'; }
    foreach ($files as $f) {
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp'])) { $errors[] = $f['name'] . ': upload failed (it may exceed the server size limit).'; continue; }
        [$kind, $why] = validate_model_file($f['tmp'], $f['name'], $f['size']);
        if (!$kind) { $errors[] = $f['name'] . ': ' . $why . '.'; continue; }
        $total += $f['size'];
        $accepted[] = $f + ['kind' => $kind];
    }
    if ($total > (int) cfg('upload.quota_bytes')) { $errors[] = 'This would exceed the storage allowance of ' . fmt_bytes((int) cfg('upload.quota_bytes')) . '.'; }
    return $accepted;
}

/** Moves accepted files into private storage and records them. Call inside a transaction; delete $stored paths on rollback. */
function store_uploads(int $requestId, int $ownerId, array $accepted, array &$stored): void
{
    foreach ($accepted as $f) {
        $name = bin2hex(random_bytes(20));
        $dest = cfg('storage') . '/uploads/' . $name;
        if (!move_uploaded_file($f['tmp'], $dest)) { throw new RuntimeException('could not store file'); }
        chmod($dest, 0640);
        $stored[] = $dest;
        $clean = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($f['name']));
        q('INSERT INTO is_request_files (request_id,user_id,orig_name,stored_name,kind,size_bytes,sha256,created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$requestId, $ownerId, mb_substr($clean, 0, 200), $name, $f['kind'], $f['size'], hash_file('sha256', $dest), now()]);
    }
}
