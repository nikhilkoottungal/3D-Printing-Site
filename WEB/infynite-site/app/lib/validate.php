<?php
/** Format checks for Indian identity and bank details (pure functions). */
function verhoeff_ok(string $digits): bool
{
    static $d = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],[5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]];
    static $p = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],[9,4,5,3,1,2,6,8,7,0],[4,2,8,6,5,7,3,9,0,1],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
    $c = 0; $rev = strrev($digits);
    for ($i = 0; $i < strlen($rev); $i++) { $c = $d[$c][$p[$i % 8][(int) $rev[$i]]]; }
    return $c === 0;
}
function aadhaar_ok(string $v): bool { $v = preg_replace('/\s+/', '', $v); return (bool) preg_match('/^[2-9]\d{11}$/', $v) && verhoeff_ok($v); }
function pan_ok(string $v): bool { return (bool) preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', strtoupper(trim($v))); }
function ifsc_ok(string $v): bool { return (bool) preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper(trim($v))); }
function bank_acc_ok(string $v): bool { return (bool) preg_match('/^\d{9,18}$/', preg_replace('/\s+/', '', $v)); }
function upi_ok(string $v): bool { return (bool) preg_match('/^[A-Za-z0-9._-]{2,40}@[A-Za-z0-9]{2,20}$/', trim($v)); }
function mask_tail(string $v, int $keep = 4): string { $v = preg_replace('/\s+/', '', $v); return $v === '' ? '' : str_repeat('X', max(0, strlen($v) - $keep)) . substr($v, -$keep); }
