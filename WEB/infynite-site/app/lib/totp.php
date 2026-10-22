<?php
/** RFC 6238 time-based one-time passwords (Google Authenticator, Authy, 1Password ...). */
function b32_encode(string $bin): string
{
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) { $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); }
    foreach (str_split($bits, 5) as $chunk) { $out .= $a[bindec(str_pad($chunk, 5, '0'))]; }
    return $out;
}
function b32_decode(string $s): string
{
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) { $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT); }
    foreach (str_split($bits, 8) as $byte) { if (strlen($byte) === 8) { $out .= chr(bindec($byte)); } }
    return $out;
}
function totp_new_secret(): string { return b32_encode(random_bytes(20)); }
function totp_code(string $b32, int $counter): string
{
    $h = hash_hmac('sha1', pack('N2', 0, $counter), b32_decode($b32), true);
    $o = ord($h[19]) & 15;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}
/** Returns the accepted counter (to store against replay) or null. Accepts the previous, current and next 30 s step. */
function totp_verify(string $b32, string $code, int $lastCounter = 0): ?int
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) { return null; }
    $now = intdiv(time(), 30);
    for ($c = $now - 1; $c <= $now + 1; $c++) {
        if ($c > $lastCounter && hash_equals(totp_code($b32, $c), $code)) { return $c; }
    }
    return null;
}
function totp_uri(string $b32, string $email, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email) . '?secret=' . $b32 . '&issuer=' . rawurlencode($issuer);
}
