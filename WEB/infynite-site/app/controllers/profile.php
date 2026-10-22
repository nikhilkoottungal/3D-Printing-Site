<?php
/** Staff personal and employment details. Visible to the person and to the Owner. Aadhaar, PAN, bank account and ID copies are encrypted at rest. */
const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
const DOC_KINDS = ['aadhaar' => 'Aadhaar card', 'pan' => 'PAN card', 'address' => 'Address proof', 'id' => 'Passport / driving licence / voter ID', 'other' => 'Other'];
const DOC_EXT = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

function hr_profile(int $uid): array
{
    $r = one('SELECT * FROM is_staff_profiles WHERE user_id=?', [$uid]) ?: [];
    $r += array_fill_keys(['blood_group', 'dob', 'joined_on', 'designation', 'address', 'emergency_name', 'emergency_phone', 'prev_experience', 'qualifications', 'bank_holder', 'bank_name', 'bank_ifsc', 'upi_id'], null);
    $r['aadhaar'] = (string) (secret_decrypt($r['aadhaar_enc'] ?? null) ?? ''); $r['pan'] = (string) (secret_decrypt($r['pan_enc'] ?? null) ?? ''); $r['bank_acc'] = (string) (secret_decrypt($r['bank_acc_enc'] ?? null) ?? '');
    return $r;
}
function hr_docs(int $uid): array { return all('SELECT * FROM is_staff_docs WHERE user_id=? ORDER BY id', [$uid]); }

/** Saves the details form. Returns a list of error messages (empty = saved). */
function hr_save(int $uid): array
{
    $old = hr_profile($uid); $err = [];
    $f = fn($k, $m) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $m);
    $bg = $f('blood_group', 4); if ($bg !== '' && !in_array($bg, BLOOD_GROUPS, true)) { $err[] = 'Choose a blood group from the list.'; }
    $dob = $f('dob', 10); if ($dob !== '' && (!valid_date($dob) || $dob > date('Y-m-d', strtotime('-14 years')) || $dob < '1930-01-01')) { $err[] = 'Check the date of birth.'; }
    $join = $f('joined_on', 10); if ($join !== '' && !valid_date($join)) { $err[] = 'Check the joining date.'; }
    $ephone = $f('emergency_phone', 30); if (!preg_match('/^[0-9 +()-]{0,30}$/', $ephone)) { $err[] = 'Emergency phone number looks invalid.'; }
    $phone = $f('phone', 30); if (!preg_match('/^[0-9 +()-]{0,30}$/', $phone)) { $err[] = 'Phone number looks invalid.'; }
    $ifsc = strtoupper($f('bank_ifsc', 11)); if ($ifsc !== '' && !ifsc_ok($ifsc)) { $err[] = 'IFSC code is not valid (4 letters, 0, then 6 letters or digits).'; }
    $upi = $f('upi_id', 80); if ($upi !== '' && !upi_ok($upi)) { $err[] = 'UPI ID looks invalid (name@bank).'; }
    $enc = [];
    foreach (['aadhaar' => ['Aadhaar number', 'aadhaar_ok', 'aadhaar_enc'], 'pan' => ['PAN', 'pan_ok', 'pan_enc'], 'bank_acc' => ['Bank account number', 'bank_acc_ok', 'bank_acc_enc']] as $k => [$label, $check, $col]) {
        $v = trim((string) ($_POST[$k] ?? ''));
        if (!empty($_POST['clear_' . $k])) { $enc[$col] = null; }
        elseif ($v !== '') { if (!$check($v)) { $err[] = "$label is not valid."; } else { $enc[$col] = secret_encrypt($k === 'pan' ? strtoupper($v) : preg_replace('/\s+/', '', $v)); } }
        else { $enc[$col] = $old[$col] ?? null; }
    }
    if ($err) { return $err; }
    q('INSERT INTO is_staff_profiles (user_id,blood_group,dob,joined_on,designation,address,emergency_name,emergency_phone,prev_experience,qualifications,aadhaar_enc,pan_enc,bank_holder,bank_name,bank_acc_enc,bank_ifsc,upi_id,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE blood_group=VALUES(blood_group), dob=VALUES(dob), joined_on=VALUES(joined_on), designation=VALUES(designation), address=VALUES(address), emergency_name=VALUES(emergency_name), emergency_phone=VALUES(emergency_phone), prev_experience=VALUES(prev_experience), qualifications=VALUES(qualifications), aadhaar_enc=VALUES(aadhaar_enc), pan_enc=VALUES(pan_enc), bank_holder=VALUES(bank_holder), bank_name=VALUES(bank_name), bank_acc_enc=VALUES(bank_acc_enc), bank_ifsc=VALUES(bank_ifsc), upi_id=VALUES(upi_id), updated_at=VALUES(updated_at)',
        [$uid, $bg ?: null, $dob ?: null, $join ?: null, $f('designation', 80) ?: null, $f('address', 400) ?: null, $f('emergency_name', 120) ?: null, $ephone ?: null, $f('prev_experience', 2000) ?: null, $f('qualifications', 1000) ?: null,
            $enc['aadhaar_enc'], $enc['pan_enc'], $f('bank_holder', 120) ?: null, $f('bank_name', 120) ?: null, $enc['bank_acc_enc'], $ifsc ?: null, $upi ?: null, now()]);
    q('UPDATE is_users SET phone=? WHERE id=?', [$phone ?: null, $uid]);
    return [];
}

function hr_doc_upload(int $uid): array
{
    $f = $_FILES['doc'] ?? null; $kind = (string) ($_POST['doc_kind'] ?? '');
    if (!isset(DOC_KINDS[$kind])) { return ['Choose what kind of document it is.']; }
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { return ['Choose a file to upload.']; }
    if ($f['size'] > 8 * 1024 * 1024) { return ['The file is larger than 8 MB.']; }
    if ((int) one('SELECT COUNT(*) c FROM is_staff_docs WHERE user_id=?', [$uid])['c'] >= 8) { return ['You can keep up to 8 documents. Remove one first.']; }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)); if (!isset(DOC_EXT[$ext])) { return ['Only PDF, JPG or PNG files are accepted.']; }
    $data = (string) file_get_contents($f['tmp_name']); $head = substr($data, 0, 8);
    $ok = $ext === 'pdf' ? str_starts_with($head, '%PDF') : (@getimagesizefromstring($data) !== false && (($ext === 'png') === str_starts_with($head, "\x89PNG")));
    if (!$ok) { return ['The file content does not look like a ' . strtoupper($ext) . ' file.']; }
    $dir = cfg('storage') . '/uploads/hr'; if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $stored = bin2hex(random_bytes(20)); file_put_contents("$dir/$stored", secret_encrypt($data), LOCK_EX); @chmod("$dir/$stored", 0640);
    q('INSERT INTO is_staff_docs (user_id,kind,orig_name,stored_name,mime,size_bytes,created_at) VALUES (?,?,?,?,?,?,?)', [$uid, $kind, mb_substr(preg_replace('/[^\w. ()-]/u', '_', basename((string) $f['name'])), 0, 190), $stored, DOC_EXT[$ext], strlen($data), now()]);
    audit('hr.doc_add', DOC_KINDS[$kind]);
    return [];
}
function hr_doc_get(string $id): void
{
    $u = require_staff(); $d = one('SELECT * FROM is_staff_docs WHERE id=?', [(int) $id]);
    if (!$d || ((int) $d['user_id'] !== (int) $u['id'] && !can('team.manage', $u))) { abort(404); }
    $path = cfg('storage') . '/uploads/hr/' . $d['stored_name'];
    $plain = preg_match('/^[a-f0-9]{40}$/', $d['stored_name']) && is_file($path) ? secret_decrypt((string) file_get_contents($path)) : null;
    if ($plain === null) { abort(404, 'The file cannot be read (was the server key restored with the backup?).'); }
    if ((int) $d['user_id'] !== (int) $u['id']) { audit('hr.doc_view', 'doc ' . $d['id'] . ' of user ' . $d['user_id']); }
    header('Content-Type: ' . $d['mime']); header('X-Content-Type-Options: nosniff'); header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $d['orig_name']) . '"');
    header('Content-Length: ' . strlen($plain)); header('Cache-Control: private, no-store'); session_write_close(); echo $plain; exit;
}
function hr_doc_delete(string $id): void
{
    $u = require_staff(); $d = one('SELECT * FROM is_staff_docs WHERE id=?', [(int) $id]);
    if (!$d || (int) $d['user_id'] !== (int) $u['id']) { abort(404); }
    @unlink(cfg('storage') . '/uploads/hr/' . $d['stored_name']); q('DELETE FROM is_staff_docs WHERE id=?', [$d['id']]); audit('hr.doc_delete', DOC_KINDS[$d['kind']] ?? $d['kind']);
    flash('Document removed.'); redirect('/staff/me');
}
/** Owner's read-only view of a colleague's details. */
function team_profile(string $id): void
{
    $u = require_staff('team.manage'); $p = one("SELECT id,name,email,phone,role,active FROM is_users WHERE id=? AND role<>'customer'", [(int) $id]); if (!$p) { abort(404); }
    audit('hr.view', $p['email']);
    render('staff/team_profile', ['title' => $p['name'], 'nav' => 'team', 'p' => $p, 'pr' => hr_profile((int) $p['id']), 'docs' => hr_docs((int) $p['id']), 'u' => $u], 'staff');
}

/* ---------------- profile picture ---------------- */
/** Small round picture for a user row (needs id, name, avatar); falls back to initials. */
function avatar_tag(array $r, int $px = 32): string
{
    $s = 'width:' . $px . 'px;height:' . $px . 'px;border-radius:50%;flex:none;';
    if (!empty($r['avatar'])) { return '<img src="/staff/avatar/' . (int) $r['id'] . '?v=' . e($r['avatar']) . '" alt="" style="' . $s . 'object-fit:cover;vertical-align:middle">'; }
    $ini = ''; foreach (array_slice(preg_split('/\s+/', trim((string) $r['name'])) ?: [], 0, 2) as $w) { $ini .= mb_strtoupper(mb_substr($w, 0, 1)); }
    return '<span aria-hidden="true" style="' . $s . 'display:inline-grid;place-items:center;background:var(--brand-soft);color:var(--brand);font-weight:700;font-size:' . round($px * 0.4) . 'px;vertical-align:middle">' . e($ini ?: '?') . '</span>';
}
function avatar_dir(): string { $d = cfg('storage') . '/uploads/avatars'; if (!is_dir($d)) { @mkdir($d, 0750, true); } return $d; }
/** Crops to a centred square, resizes to 256 px and saves as JPEG (this also strips camera data such as location). Returns an error message or null. */
function avatar_set(int $uid): ?string
{
    $f = $_FILES['photo'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { return 'Choose a picture.'; }
    if ($f['size'] > 6 * 1024 * 1024) { return 'The picture is larger than 6 MB.'; }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) { return 'Use a JPG, PNG, GIF or WebP picture.'; }
    if ($info[0] > 8000 || $info[1] > 8000 || $info[0] < 32 && $info[1] < 32 && false) { return 'The picture is too large in pixels (max 8000 x 8000).'; }
    $src = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']), IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name']), IMAGETYPE_GIF => @imagecreatefromgif($f['tmp_name']), default => @imagecreatefromwebp($f['tmp_name']) };
    if (!$src) { return 'The picture could not be read.'; }
    $w = imagesx($src); $h = imagesy($src); $side = min($w, $h);
    $dst = imagecreatetruecolor(256, 256); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 256, 256, $side, $side);
    $name = bin2hex(random_bytes(20)); $path = avatar_dir() . "/$name";
    $ok = imagejpeg($dst, $path, 85); imagedestroy($src); imagedestroy($dst);
    if (!$ok) { return 'The picture could not be saved.'; }
    @chmod($path, 0640); avatar_unlink($uid);
    q('UPDATE is_users SET avatar=? WHERE id=?', [$name, $uid]); audit('me.avatar');
    return null;
}
function avatar_unlink(int $uid): void { $o = one('SELECT avatar FROM is_users WHERE id=?', [$uid]); if ($o && $o['avatar'] && preg_match('/^[a-f0-9]{40}$/', $o['avatar'])) { @unlink(avatar_dir() . '/' . $o['avatar']); } }
function avatar_get(string $id): void
{
    require_staff(); $r = one("SELECT avatar FROM is_users WHERE id=? AND role<>'customer' AND avatar IS NOT NULL", [(int) $id]);
    $path = $r && preg_match('/^[a-f0-9]{40}$/', $r['avatar']) ? avatar_dir() . '/' . $r['avatar'] : '';
    if (!$path || !is_file($path)) { abort(404); }
    header('Content-Type: image/jpeg'); header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . filesize($path)); header('Cache-Control: private, max-age=86400');
    session_write_close(); readfile($path); exit;
}
